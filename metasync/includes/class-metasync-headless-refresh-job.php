<?php
/**
 * Headless stale-while-revalidate OTTO refresh job.
 *
 * Headless mode serves GraphQL SEO data straight out of stored WordPress meta
 * (see Metasync_Headless_Seo_Surface) so a GraphQL resolver never has to wait
 * on OTTO. That is correct and fast, but it means a missed or delayed OTTO
 * deployment webhook leaves the stored values stale with no page-load path
 * that would ever refresh them — there is no render pass to piggyback on the
 * way normal mode's transient cache does.
 *
 * This job is the safety net, not a replacement for the webhook. It runs in
 * the background (WP-Cron, or the REST/WP-CLI fallback when WP-Cron is
 * disabled), and for any headless-visible post or term whose stored OTTO data
 * is missing or older than the configured interval, it:
 *
 *  1. Resolves the object's frontend URL explicitly, via the same
 *     Metasync_Headless_Url_Builder + WordPress object ID every other
 *     headless code path uses. It never calls url_to_postid() against a
 *     frontend host — the whole reason a URL-based lookup does not work here
 *     is that the crawled URL and the WordPress URL are different hosts.
 *  2. Calls the existing OTTO URL-details fetch (metasync_fetch_otto_seo_data())
 *     directly for that URL.
 *  3. Writes through the existing compare-before-write update functions
 *     (metasync_update_comprehensive_seo_fields() /
 *     metasync_update_comprehensive_taxonomy_seo_fields()) — the same ones the
 *     webhook path uses — so unchanged results are a no-op and the meta keys
 *     stay identical to what the webhook writes.
 *
 * Deliberately out of scope, per the approved design: no synchronous OTTO call
 * from a GraphQL resolver, no re-enabling OTTO SSR/rendering, and no frontend
 * cache management of any kind — this class owns WordPress storage only.
 *
 * Every WordPress-touching operation is a protected static "read seam" (the
 * same pattern used throughout the headless surface) so tests can override
 * just the seam being exercised without needing a live WordPress environment
 * or colliding with unrelated ambient stubs.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

if (!defined('ABSPATH')) {
    exit; # Exit if accessed directly
}

require_once dirname(__DIR__) . '/headless-refresh-history/class-metasync-headless-refresh-history-database.php';

class Metasync_Headless_Refresh_Job
{
    /**
     * WP-Cron hook name.
     */
    const CRON_HOOK = 'metasync_headless_refresh_cron';

    /**
     * Custom WP-Cron recurrence name registered via the cron_schedules filter.
     */
    const CRON_RECURRENCE = 'metasync_headless_refresh';

    /**
     * Option remembering the interval (seconds) the currently-scheduled cron
     * event was created with, so a later settings change can be detected and
     * the event rescheduled — WP-Cron bakes the interval in at schedule time.
     */
    const SCHEDULED_INTERVAL_OPTION = 'metasync_headless_refresh_scheduled_interval';

    /**
     * Post/term meta key stamped every time this job checks an object,
     * whether or not the check produced a change. Distinct from
     * `_metasync_otto_last_update` (which the webhook path also writes),
     * because that key only moves when OTTO data actually changes — using it
     * for staleness would mean an object whose OTTO data never changes gets
     * re-checked on every single batch forever, burning API budget on a URL
     * that will keep answering "nothing changed".
     */
    const CHECKED_META_KEY = '_metasync_headless_refresh_checked';

    /**
     * Per-object lock TTL (seconds). Short: a lock only needs to outlive one
     * OTTO API round trip, not a full refresh interval.
     */
    const LOCK_TTL = 60;

    /**
     * Batch size bounds and default.
     */
    const DEFAULT_BATCH_SIZE = 50;
    const MIN_BATCH_SIZE     = 1;
    const MAX_BATCH_SIZE     = 500;

    /**
     * API timeout bounds and default (seconds). Deliberately short: this job
     * must not let one slow URL stall a whole batch.
     */
    const DEFAULT_API_TIMEOUT = 2;
    const MIN_API_TIMEOUT     = 1;
    const MAX_API_TIMEOUT     = 10;

    /**
     * Outcomes of one refresh attempt.
     *
     * run_batch() only ever needed three buckets, and refresh_object() still
     * answers in those three. A request-driven caller needs more than that: it
     * has to tell "OTTO answered, there was nothing to write" (a success — the
     * cooldown has moved and the stored values are current) apart from "we never
     * got to ask" (lock held, budget spent, breaker open), because only the
     * second kind means the stored values may still be stale. Collapsing both
     * into one value, as the batch contract does, would make that undecidable.
     */
    const OUTCOME_FRESH        = 'fresh';
    const OUTCOME_REFRESHED    = 'refreshed';
    const OUTCOME_UNCHANGED    = 'unchanged';
    const OUTCOME_EMPTY        = 'empty';
    const OUTCOME_NO_URL       = 'no_url';
    const OUTCOME_LOCKED       = 'locked';
    const OUTCOME_RATE_LIMITED = 'rate_limited';
    const OUTCOME_BREAKER_OPEN = 'breaker_open';
    const OUTCOME_FAILED       = 'failed';
    const OUTCOME_DISABLED     = 'disabled';

    /**
     * Wire the job into WordPress. Safe to call unconditionally — every branch
     * either checks Metasync_Headless_Config::is_active() itself or is cheap
     * enough (a filter registration, a WP-CLI command registration) to cost
     * nothing when headless mode is off.
     *
     * @return void
     */
    public static function init()
    {
        add_filter('cron_schedules', array(__CLASS__, 'register_cron_schedule'));
        add_action('init', array(__CLASS__, 'maybe_reschedule'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_scheduled_batch'));
        add_action('rest_api_init', array(__CLASS__, 'register_rest_route'));

        # Same shape as cli_command() below: class_exists() both narrows for
        # static analysis (the WP_CLI constant stub resolves to always-true,
        # so && WP_CLI trips it) and guards the WP_CLI:: call.
        if (defined('WP_CLI') && class_exists('WP_CLI')) {
            WP_CLI::add_command('metasync headless-refresh', array(__CLASS__, 'cli_command'));
        }
    }

    // ------------------------------------------------------------------
    //  Scheduling
    // ------------------------------------------------------------------

    /**
     * Register the custom recurrence WP-Cron needs to schedule this job at
     * the currently-configured interval.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public static function register_cron_schedule($schedules)
    {
        $schedules[self::CRON_RECURRENCE] = array(
            'interval' => static::get_interval_seconds(),
            'display'  => __('MetaSync Headless Refresh Interval', 'metasync'),
        );

        return $schedules;
    }

    /**
     * Ensure the cron event matches the current on/off state and interval.
     *
     * Headless-gated so an inactive install carries zero cron overhead — not
     * merely a job that runs and exits, but no scheduled event at all. Also
     * reschedules when the configured interval has changed since the event
     * was created, because WP-Cron bakes the interval into the event at
     * schedule time; changing the option alone does not move an already
     * -scheduled event.
     *
     * @return void
     */
    public static function maybe_reschedule()
    {
        if (!static::is_enabled()) {
            if (static::next_scheduled()) {
                static::clear_scheduled();
            }
            if (get_option(self::SCHEDULED_INTERVAL_OPTION, false) !== false) {
                delete_option(self::SCHEDULED_INTERVAL_OPTION);
            }
            return;
        }

        $desired = static::get_interval_seconds();
        $applied = (int) get_option(self::SCHEDULED_INTERVAL_OPTION, 0);

        if (static::next_scheduled() && $applied === $desired) {
            return;
        }

        if (static::next_scheduled()) {
            static::clear_scheduled();
        }

        static::schedule_event(time() + $desired);
        update_option(self::SCHEDULED_INTERVAL_OPTION, $desired, false);
    }

    /**
     * @return int|false
     */
    protected static function next_scheduled()
    {
        return wp_next_scheduled(self::CRON_HOOK);
    }

    /**
     * @return void
     */
    protected static function clear_scheduled()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * @param int $timestamp
     * @return void
     */
    protected static function schedule_event($timestamp)
    {
        wp_schedule_event($timestamp, self::CRON_RECURRENCE, self::CRON_HOOK);
    }

    /**
     * Cron entry point. Never lets an exception escape — a background job
     * failing loudly would, at best, spam the WordPress error log every
     * interval forever, and at worst take down whatever WP-Cron run
     * triggered it alongside unrelated scheduled hooks.
     *
     * @return void
     */
    public static function run_scheduled_batch()
    {
        try {
            static::run_batch(0);
        } catch (\Throwable $e) {
            self::log_failure('cron', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    //  Configuration
    // ------------------------------------------------------------------

    /**
     * Is the job allowed to do anything right now?
     *
     * Headless-gated first: every other check is pointless if headless mode
     * itself is off or unusable (no frontend domain configured).
     *
     * @return bool
     */
    public static function is_enabled()
    {
        if (!class_exists('Metasync_Headless_Config') || !Metasync_Headless_Config::is_active()) {
            return false;
        }

        return (bool) apply_filters('metasync_headless_refresh_enabled', true);
    }

    /**
     * Refresh interval, in seconds.
     *
     * Base value comes from the Headless settings UI (safe-bounded there);
     * `metasync_headless_refresh_interval` lets a developer override it
     * per-filter (e.g. for a staging site), but the result is re-clamped to
     * the same safe bounds either way — a filter is not a way to disable the
     * bounds, only to move within them faster than editing settings.
     *
     * @return int Seconds.
     */
    public static function get_interval_seconds()
    {
        $minutes = Metasync_Headless_Config::get_refresh_interval_minutes();

        $seconds = $minutes * MINUTE_IN_SECONDS;
        $seconds = (int) apply_filters('metasync_headless_refresh_interval', $seconds);

        $min = Metasync_Headless_Config::REFRESH_INTERVAL_MIN_MINUTES * MINUTE_IN_SECONDS;
        $max = Metasync_Headless_Config::REFRESH_INTERVAL_MAX_MINUTES * MINUTE_IN_SECONDS;

        return max($min, min($max, $seconds));
    }

    /**
     * @return int
     */
    public static function get_batch_size()
    {
        $size = (int) apply_filters('metasync_headless_refresh_batch_size', self::DEFAULT_BATCH_SIZE);

        return max(self::MIN_BATCH_SIZE, min(self::MAX_BATCH_SIZE, $size));
    }

    /**
     * @return int Seconds.
     */
    public static function get_api_timeout()
    {
        $timeout = (int) apply_filters('metasync_headless_refresh_api_timeout', self::DEFAULT_API_TIMEOUT);

        return max(self::MIN_API_TIMEOUT, min(self::MAX_API_TIMEOUT, $timeout));
    }

    /**
     * Shared per-minute OTTO API call budget for this job.
     *
     * Filterable, but defaults to the same "10 calls/minute" figure the
     * page-render OTTO cache enforces. A separate counter namespace on
     * purpose: a background refresh burning the same budget page loads use
     * would make an unlucky visitor pay for a batch job's API calls.
     *
     * @return int
     */
    public static function get_rate_limit()
    {
        $default = class_exists('Metasync_Otto_Transient_Cache')
            ? Metasync_Otto_Transient_Cache::MAX_API_CALLS_PER_MINUTE
            : 10;

        return max(1, (int) apply_filters('metasync_headless_refresh_rate_limit', $default));
    }

    // ------------------------------------------------------------------
    //  Batch execution
    // ------------------------------------------------------------------

    /**
     * Run one batch of refresh checks.
     *
     * Safe to call directly (REST endpoint, WP-CLI, tests) as well as from
     * cron. Always returns a result array; never throws for a per-object
     * failure — an OTTO failure for one URL must not stop the rest of the
     * batch, and stored GraphQL values keep serving from whatever was last
     * successfully written.
     *
     * @param int      $offset     Pagination offset into the stale-object list.
     * @param int|null $batch_size Objects to process. Defaults to get_batch_size().
     * @return array{processed:int,refreshed:int,skipped:int,failed:int,next_offset:int|null}
     */
    public static function run_batch($offset = 0, $batch_size = null)
    {
        $result = array(
            'processed'   => 0,
            'refreshed'   => 0,
            'skipped'     => 0,
            'failed'      => 0,
            'next_offset' => null,
        );

        if (!static::is_enabled()) {
            return $result;
        }

        if (!static::cpu_load_safe()) {
            return $result;
        }

        $otto_uuid = static::otto_uuid();
        if (empty($otto_uuid)) {
            return $result;
        }

        $offset     = max(0, (int) $offset);
        $batch_size = $batch_size !== null ? max(self::MIN_BATCH_SIZE, min(self::MAX_BATCH_SIZE, (int) $batch_size)) : static::get_batch_size();

        $candidates = static::collect_candidates($batch_size, $offset);

        foreach ($candidates as $candidate) {
            $result['processed']++;

            try {
                $outcome = static::refresh_object($candidate['type'], $candidate['id'], $candidate['taxonomy'], $otto_uuid);
            } catch (\Throwable $e) {
                $outcome = 'failed';
                static::log_failure($candidate['type'] . ':' . $candidate['id'], $e->getMessage());
            }

            if (!isset($result[$outcome])) {
                $outcome = 'failed';
            }

            $result[$outcome]++;
        }

        $result['next_offset'] = count($candidates) < $batch_size ? null : ($offset + count($candidates));

        return $result;
    }

    /**
     * Refresh one known object for a request-driven caller.
     *
     * Unlike run_batch(), this method never discovers or processes other
     * objects: it acts on the object identity the caller already resolved, and
     * on nothing else. That is the whole reason it exists — a GraphQL resolver
     * must never be able to trigger a site-wide sweep.
     *
     * Answers with one of the OUTCOME_* constants rather than the batch's
     * three-way result, because the caller has to decide whether to re-read
     * stored values, and only OUTCOME_REFRESHED means they changed.
     *
     * @param string      $type      'post' or 'term'.
     * @param int         $id        WordPress object ID.
     * @param string|null $taxonomy  Taxonomy name, for terms only.
     * @param int|null    $timeout   Transport timeout override, seconds.
     * @return string One of the OUTCOME_* constants.
     */
    public static function refresh_single_object($type, $id, $taxonomy = null, $timeout = null)
    {
        if (!static::is_enabled() || !static::cpu_load_safe()) {
            return self::OUTCOME_DISABLED;
        }

        $otto_uuid = static::otto_uuid();
        if ($otto_uuid === '') {
            return self::OUTCOME_DISABLED;
        }

        if (!static::is_stale($type, (int) $id, $taxonomy)) {
            return self::OUTCOME_FRESH;
        }

        try {
            return static::attempt_refresh(
                $type,
                (int) $id,
                $taxonomy,
                $otto_uuid,
                $timeout === null ? static::get_api_timeout() : max(self::MIN_API_TIMEOUT, (int) $timeout)
            );
        } catch (\Throwable $e) {
            # Caught here rather than left to the caller: a resolver that let
            # this escape would fail the whole GraphQL field, which is exactly
            # the outcome a refresh is not allowed to cause.
            static::log_failure($type . ':' . (int) $id, $e->getMessage());

            return self::OUTCOME_FAILED;
        }
    }

    /**
     * Is this object's checked marker missing or outside the cooldown?
     *
     * Deliberately reads `_metasync_headless_refresh_checked` and not
     * `_metasync_otto_last_update`: the second only moves when OTTO data
     * actually changes, so an object whose suggestions never change would read
     * as permanently stale and be re-fetched on every single request.
     *
     * @param string      $type
     * @param int         $id
     * @param string|null $taxonomy
     * @return bool
     */
    protected static function is_stale($type, $id, $taxonomy = null)
    {
        $checked = $type === 'post'
            ? static::read_checked_post_meta((int) $id)
            : static::read_checked_term_meta((int) $id);

        if ($checked === '' || $checked === null || $checked === false) {
            return true;
        }

        return (int) $checked < (time() - static::get_interval_seconds());
    }

    /**
     * @param int $id
     * @return mixed Stored checked-at value, or '' when there is none.
     */
    protected static function read_checked_post_meta($id)
    {
        return get_post_meta((int) $id, self::CHECKED_META_KEY, true);
    }

    /**
     * @param int $id
     * @return mixed Stored checked-at value, or '' when there is none.
     */
    protected static function read_checked_term_meta($id)
    {
        return get_term_meta((int) $id, self::CHECKED_META_KEY, true);
    }

    /**
     * Refresh one object: fetch, compare, write only what changed.
     *
     * The batch's three-value contract, unchanged. The detail lives in
     * attempt_refresh(); this maps it back down, so a per-object failure still
     * counts the way run_batch()'s result array has always counted it.
     *
     * @param string      $type      'post' or 'term'.
     * @param int         $id        WordPress object ID.
     * @param string|null $taxonomy  Taxonomy name, for terms only.
     * @param string      $otto_uuid OTTO UUID.
     * @return string One of 'refreshed', 'skipped', 'failed'.
     */
    protected static function refresh_object($type, $id, $taxonomy, $otto_uuid)
    {
        $outcome = static::attempt_refresh($type, $id, $taxonomy, $otto_uuid, static::get_api_timeout());

        if ($outcome === self::OUTCOME_REFRESHED) {
            return 'refreshed';
        }

        if ($outcome === self::OUTCOME_FAILED) {
            return 'failed';
        }

        # Everything else — no URL, lock held, budget spent, breaker open, an
        # empty answer, an answer that changed nothing — is a skip for the
        # batch: nothing about the object itself failed, and the next batch
        # will pick it up if it is still stale.
        return 'skipped';
    }

    /**
     * One refresh attempt, reporting exactly why it ended the way it did.
     *
     * @param string      $type
     * @param int         $id
     * @param string|null $taxonomy
     * @param string      $otto_uuid
     * @param int         $timeout
     * @return string One of the OUTCOME_* constants.
     */
    protected static function attempt_refresh($type, $id, $taxonomy, $otto_uuid, $timeout)
    {
        $url = static::resolve_frontend_url($type, $id, $taxonomy);
        if ($url === '') {
            # No frontend URL could be built — headless misconfigured, object
            # deleted between query and processing, or an unmapped type.
            # Nothing to fetch and nothing to compromise.
            #
            # Stamped checked all the same. Without it the object stays stale
            # for ever, and since the batch query always starts from the oldest
            # stale objects, a handful of permanently URL-less ones would sit at
            # the head of every batch and crowd out the objects that can
            # actually be refreshed.
            static::mark_checked($type, $id, $taxonomy);

            return self::OUTCOME_NO_URL;
        }

        if (static::breaker_open()) {
            # The shared OTTO breaker is open or the endpoint is in backoff.
            # Asked before the lock on purpose: it is a cheap read, and taking
            # a lock only to discover we may not call is pure contention.
            return self::OUTCOME_BREAKER_OPEN;
        }

        $lock_key = static::lock_key($type, $id, $taxonomy);
        if (!static::acquire_lock($lock_key)) {
            # Another worker — a concurrent request for the same URL, a batch,
            # WP-Cron overlap — is already refreshing this exact object. This
            # is what stops a burst of requests for one page becoming a burst
            # of identical OTTO calls.
            return self::OUTCOME_LOCKED;
        }

        try {
            if (!static::rate_limit_ok()) {
                # Per-minute budget exhausted. Leave the object stale rather
                # than failing it; nothing about the object itself failed.
                return self::OUTCOME_RATE_LIMITED;
            }

            $seo_data = static::fetch_seo_data($url, $otto_uuid, $timeout);

            # Stamp checked-at regardless of outcome. A URL that fails every
            # time must still age out of "needs refresh" on the same cooldown
            # as everything else, or it would monopolise every future batch —
            # the API budget being spent retrying a URL that will keep failing
            # is a worse outcome than waiting one more interval to retry it.
            static::mark_checked($type, $id, $taxonomy);

            if ($seo_data === false) {
                # Transport error, timeout, or an HTTP status the fetch helper
                # treats as failure (401, 429, 5xx). Stored values are
                # untouched and keep serving as-is — this job never has a
                # reason to clear or degrade what is stored.
                return self::OUTCOME_FAILED;
            }

            if (empty($seo_data)) {
                # A definitive-but-empty answer (204, or 404 "no OTTO
                # optimizations available for this URL"): the API answered,
                # there is simply nothing to write. A successful check with no
                # changes, not a failure — otherwise a site OTTO has not
                # optimized yet would read as permanently broken.
                return self::OUTCOME_EMPTY;
            }

            if ($type === 'post') {
                $update = static::update_post_fields((int) $id, $seo_data);
            } else {
                $update = static::update_term_fields((int) $id, (string) $taxonomy, $seo_data);
            }

            return !empty($update['updated']) ? self::OUTCOME_REFRESHED : self::OUTCOME_UNCHANGED;
        } finally {
            static::release_lock($lock_key);
        }
    }

    /**
     * @param string      $type
     * @param int         $id
     * @param string|null $taxonomy
     * @return string Frontend URL, or '' when one cannot be built.
     */
    protected static function resolve_frontend_url($type, $id, $taxonomy)
    {
        if (!class_exists('Metasync_Headless_Url_Builder')) {
            return '';
        }

        if ($type === 'post') {
            $post = static::read_post($id);
            if (!$post) {
                return '';
            }

            return (string) Metasync_Headless_Url_Builder::from_wp_url(static::read_permalink($post), (string) $post->post_type);
        }

        if ($type === 'term') {
            $term = static::read_term($id, $taxonomy);
            if (!$term) {
                return '';
            }

            return (string) Metasync_Headless_Url_Builder::from_wp_url(static::read_term_link($term));
        }

        return '';
    }

    /**
     * @param string      $type
     * @param int         $id
     * @param string|null $taxonomy
     * @return string
     */
    protected static function lock_key($type, $id, $taxonomy)
    {
        $suffix = $type === 'term' ? '_' . sanitize_key((string) $taxonomy) : '';

        return 'metasync_headless_refresh_lock_' . $type . '_' . (int) $id . $suffix;
    }

    // ------------------------------------------------------------------
    //  Candidate selection
    // ------------------------------------------------------------------

    /**
     * Collect up to $limit stale/missing objects starting at $offset, posts
     * first then terms — a single virtual list spanning both, so REST/WP-CLI
     * pagination can walk the whole site with one offset counter.
     *
     * @param int $limit
     * @param int $offset
     * @return array<int,array{type:string,id:int,taxonomy:?string}>
     */
    public static function collect_candidates($limit, $offset)
    {
        $threshold  = time() - static::get_interval_seconds();
        $candidates = array();

        $post_total = static::count_stale_posts($threshold);

        if ($offset < $post_total) {
            $post_limit = min($limit, $post_total - $offset);
            foreach (static::query_stale_post_ids($post_limit, $offset, $threshold) as $post_id) {
                $candidates[] = array('type' => 'post', 'id' => (int) $post_id, 'taxonomy' => null);
            }
            $remaining   = $limit - count($candidates);
            $term_offset = 0;
        } else {
            $remaining   = $limit;
            $term_offset = $offset - $post_total;
        }

        if ($remaining > 0) {
            foreach (static::query_stale_term_ids($remaining, $term_offset, $threshold) as $row) {
                $candidates[] = array('type' => 'term', 'id' => (int) $row['id'], 'taxonomy' => (string) $row['taxonomy']);
            }
        }

        return $candidates;
    }

    /**
     * Meta query matching "never checked" OR "checked before $threshold".
     *
     * @param int $threshold Unix timestamp.
     * @return array
     */
    protected static function stale_meta_query($threshold)
    {
        return array(
            'relation' => 'OR',
            array(
                'key'     => self::CHECKED_META_KEY,
                'compare' => 'NOT EXISTS',
            ),
            array(
                'key'     => self::CHECKED_META_KEY,
                'value'   => (string) $threshold,
                'compare' => '<',
                'type'    => 'NUMERIC',
            ),
        );
    }

    /**
     * @return string[] Public post type names.
     */
    protected static function eligible_post_types()
    {
        return array_values(get_post_types(array('public' => true), 'names'));
    }

    /**
     * @return string[] Public taxonomy names.
     */
    protected static function eligible_taxonomies()
    {
        return array_values(get_taxonomies(array('public' => true), 'names'));
    }

    /**
     * @param int $threshold
     * @return int
     */
    protected static function count_stale_posts($threshold)
    {
        $post_types = static::eligible_post_types();
        if (empty($post_types)) {
            return 0;
        }

        $query = new WP_Query(array(
            'post_type'           => $post_types,
            'post_status'         => 'publish',
            'posts_per_page'      => 1,
            'fields'              => 'ids',
            'ignore_sticky_posts' => true,
            'meta_query'          => static::stale_meta_query($threshold),
        ));

        return (int) $query->found_posts;
    }

    /**
     * @param int $limit
     * @param int $offset
     * @param int $threshold
     * @return int[]
     */
    protected static function query_stale_post_ids($limit, $offset, $threshold)
    {
        $post_types = static::eligible_post_types();
        if (empty($post_types) || $limit <= 0) {
            return array();
        }

        $query = new WP_Query(array(
            'post_type'           => $post_types,
            'post_status'         => 'publish',
            'posts_per_page'      => $limit,
            'offset'              => $offset,
            'orderby'             => 'ID',
            'order'               => 'ASC',
            'fields'              => 'ids',
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'meta_query'          => static::stale_meta_query($threshold),
        ));

        return array_map('intval', $query->posts);
    }

    /**
     * @param int $limit
     * @param int $offset
     * @param int $threshold
     * @return array<int,array{id:int,taxonomy:string}>
     */
    protected static function query_stale_term_ids($limit, $offset, $threshold)
    {
        $taxonomies = static::eligible_taxonomies();
        if (empty($taxonomies) || $limit <= 0) {
            return array();
        }

        $terms = get_terms(array(
            'taxonomy'   => $taxonomies,
            'hide_empty' => false,
            'number'     => $limit,
            'offset'     => $offset,
            'orderby'    => 'id',
            'order'      => 'ASC',
            'fields'     => 'all',
            'meta_query' => static::stale_meta_query($threshold),
        ));

        if (is_wp_error($terms)) {
            return array();
        }

        $rows = array();
        foreach ($terms as $term) {
            $rows[] = array('id' => (int) $term->term_id, 'taxonomy' => (string) $term->taxonomy);
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    //  Read/write seams — overridden by tests, real WordPress/OTTO calls
    //  otherwise. Kept intentionally thin: each wraps exactly one WordPress
    //  or existing-OTTO-function call, so a test double can stand in for one
    //  seam without dragging in unrelated behaviour.
    // ------------------------------------------------------------------

    /**
     * @return bool
     */
    protected static function cpu_load_safe()
    {
        return !class_exists('Metasync_CPU_Monitor') || Metasync_CPU_Monitor::is_load_safe();
    }

    /**
     * @return string
     */
    protected static function otto_uuid()
    {
        return class_exists('Metasync_Otto_Config') ? (string) Metasync_Otto_Config::get_otto_uuid() : '';
    }

    /**
     * Is there room in the per-minute OTTO budget?
     *
     * Deliberately one bucket for both callers. The limit exists to cap what
     * this site asks of OTTO per minute, and that ceiling does not rise because
     * the calls arrived from GraphQL rather than from cron. Requests can
     * therefore crowd the batch out of a given minute — which is the right way
     * round: a minute spent refreshing the objects someone is actually asking
     * for is better spent than a minute of speculative maintenance, and the
     * batch simply picks those objects up as already-fresh next time.
     *
     * @return bool
     */
    protected static function rate_limit_ok()
    {
        if (!class_exists('Metasync_Rate_Limiter')) {
            return true;
        }

        $result = Metasync_Rate_Limiter::get_instance()->check_rate_limit(
            'batch',
            self::get_rate_limit(),
            MINUTE_IN_SECONDS,
            'metasync_headless_refresh_'
        );

        return $result === true;
    }

    /**
     * @param string $url
     * @param string $otto_uuid
     * @param int    $timeout
     * @return array|false
     */
    protected static function fetch_seo_data($url, $otto_uuid, $timeout)
    {
        if (!function_exists('metasync_fetch_otto_seo_data')) {
            return false;
        }

        // The fetcher's third parameter is its by-reference failure
        // classification, which this job does not consume — a local throwaway
        // is passed so the timeout lands in the fourth slot.
        $failure = null;

        return metasync_fetch_otto_seo_data($url, $otto_uuid, $failure, $timeout);
    }

    /**
     * Is the shared OTTO protection currently refusing outbound calls?
     *
     * Bridged explicitly rather than assumed: metasync_fetch_otto_seo_data()
     * makes the request directly and consults neither the circuit breaker nor
     * the backoff manager — both of those live around the render path's
     * transient cache. Without this check a job calling the fetch helper would
     * keep hammering a host the rest of the plugin has already given up on.
     *
     * Read-only on both: this never opens, closes or probes the breaker, so a
     * refresh cannot disturb the render path's state machine.
     *
     * @return bool True when no call should be attempted right now.
     */
    protected static function breaker_open()
    {
        $endpoint = static::otto_endpoint_url();
        if ($endpoint === '') {
            return false;
        }

        if (class_exists('Metasync_Otto_Transient_Cache')
            && Metasync_Otto_Transient_Cache::is_host_breaker_open($endpoint)) {
            return true;
        }

        if (class_exists('Metasync_API_Backoff_Manager')
            && Metasync_API_Backoff_Manager::get_instance()->is_endpoint_in_backoff($endpoint)) {
            return true;
        }

        return false;
    }

    /**
     * The OTTO URL-details endpoint, for breaker/backoff lookups only.
     *
     * @return string
     */
    protected static function otto_endpoint_url()
    {
        if (class_exists('Metasync_Endpoint_Manager')) {
            return (string) Metasync_Endpoint_Manager::get_endpoint('OTTO_URL_DETAILS');
        }

        return '';
    }

    /**
     * @param int   $post_id
     * @param array $seo_data
     * @return array{updated:bool,fields_updated:array}
     */
    protected static function update_post_fields($post_id, $seo_data)
    {
        if (!function_exists('metasync_update_comprehensive_seo_fields')) {
            return array('updated' => false, 'fields_updated' => array());
        }

        return metasync_update_comprehensive_seo_fields($post_id, $seo_data);
    }

    /**
     * @param int    $term_id
     * @param string $taxonomy
     * @param array  $seo_data
     * @return array{updated:bool,fields_updated:array}
     */
    protected static function update_term_fields($term_id, $taxonomy, $seo_data)
    {
        if (!function_exists('metasync_update_comprehensive_taxonomy_seo_fields')) {
            return array('updated' => false, 'fields_updated' => array());
        }

        return metasync_update_comprehensive_taxonomy_seo_fields($term_id, $taxonomy, $seo_data);
    }

    /**
     * @param int $id
     * @return WP_Post|null
     */
    protected static function read_post($id)
    {
        $post = get_post($id);
        return $post instanceof WP_Post ? $post : null;
    }

    /**
     * @param WP_Post $post
     * @return string
     */
    protected static function read_permalink($post)
    {
        $permalink = get_permalink($post);
        return $permalink ?: '';
    }

    /**
     * @param int    $id
     * @param string $taxonomy
     * @return WP_Term|null
     */
    protected static function read_term($id, $taxonomy)
    {
        $term = get_term($id, (string) $taxonomy);
        return ($term instanceof WP_Term) ? $term : null;
    }

    /**
     * @param WP_Term $term
     * @return string
     */
    protected static function read_term_link($term)
    {
        $link = get_term_link($term);
        return is_string($link) ? $link : '';
    }

    /**
     * Acquire the per-object lock, atomically.
     *
     * A read-then-write pair is not good enough here. Two concurrent GraphQL
     * requests for the same page would both find no lock, both set one, and
     * both call OTTO — precisely the duplicate call the lock exists to prevent,
     * and the case most likely to happen, since a burst of traffic to one URL
     * is what makes an object interesting in the first place.
     *
     * On an external object cache wp_cache_add() is SET NX and the backend
     * decides the winner. On the MySQL transient backend the unique index on
     * option_name makes INSERT IGNORE the equivalent; an expired-but-not-yet
     * -deleted row is claimed by a compare-and-swap so only one racer takes it.
     * This mirrors Metasync_Otto_Transient_Cache::acquire_lock_atomic(), which
     * is the render path's answer to the same race.
     *
     * @param string $lock_key
     * @return bool True if this caller acquired the lock.
     */
    protected static function acquire_lock($lock_key)
    {
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            return (bool) wp_cache_add($lock_key, '1', 'transient', self::LOCK_TTL);
        }

        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'query')) {
            # No database handle to be atomic against (unit context, or an
            # install mid-teardown). Fall back to the check-then-set pair: it
            # is weaker, but a lock that cannot be taken at all would mean
            # never refreshing rather than refreshing twice.
            if (get_transient($lock_key) !== false) {
                return false;
            }

            set_transient($lock_key, 1, self::LOCK_TTL);

            return true;
        }

        $now            = time();
        $new_expires    = $now + self::LOCK_TTL;
        $timeout_option = '_transient_timeout_' . $lock_key;
        $value_option   = '_transient_' . $lock_key;

        # claim_lock_row() is private (not a test seam), so bind it statically.
        if (self::claim_lock_row($timeout_option, $value_option, $new_expires)) {
            return true;
        }

        $existing_expires = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $timeout_option
            )
        );

        if ($existing_expires === null) {
            # The row was there when the insert raced and gone by the time we
            # read it: the previous holder released between the two statements.
            # There is nothing to compare against, so claim it the same way the
            # first caller would have. One retry only — a second failure means
            # someone else won, which is the lock doing its job.
            return self::claim_lock_row($timeout_option, $value_option, $new_expires);
        }

        $existing_expires = (int) $existing_expires;

        if ($existing_expires > $now) {
            return false;
        }

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) = %d",
                (string) $new_expires,
                $timeout_option,
                $existing_expires
            )
        );

        if ($updated === 1) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                    $value_option,
                    '1'
                )
            );

            return true;
        }

        return false;
    }

    /**
     * Insert the lock row, winning only if no row was there.
     *
     * The unique index on option_name is what makes this atomic: exactly one
     * concurrent INSERT IGNORE reports a row inserted.
     *
     * @param string $timeout_option
     * @param string $value_option
     * @param int    $expires
     * @return bool
     */
    private static function claim_lock_row($timeout_option, $value_option, $expires)
    {
        global $wpdb;

        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $timeout_option,
                (string) $expires
            )
        );

        if ($inserted !== 1) {
            return false;
        }

        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $value_option,
                '1'
            )
        );

        return true;
    }

    /**
     * @param string $lock_key
     * @return void
     */
    protected static function release_lock($lock_key)
    {
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            wp_cache_delete($lock_key, 'transient');

            return;
        }

        delete_transient($lock_key);
    }

    /**
     * @param string      $type
     * @param int         $id
     * @param string|null $taxonomy Unused; kept for signature symmetry with lock_key().
     * @return void
     */
    protected static function mark_checked($type, $id, $taxonomy = null)
    {
        if ($type === 'post') {
            update_post_meta($id, self::CHECKED_META_KEY, time());
            return;
        }

        update_term_meta($id, self::CHECKED_META_KEY, time());
    }

    /**
     * Record a failure for diagnostics without exposing internal details
     * publicly — this is a plain error_log() call plus the plugin's own
     * failed-action counter, the same one metasync_process_otto_seo_data()
     * feeds, so Site Health's existing "failed actions" surface picks these
     * up too rather than needing a second diagnostic to check.
     *
     * @param string $context
     * @param string $message
     * @return void
     */
    protected static function log_failure($context, $message)
    {
        if (function_exists('metasync_record_failed_action')) {
            metasync_record_failed_action('metasync_headless_refresh:' . $context);
        }

        error_log('MetaSync Headless Refresh: ' . $context . ' - ' . $message);
    }

    // ------------------------------------------------------------------
    //  REST + WP-CLI fallbacks for hosts where WP-Cron is disabled
    // ------------------------------------------------------------------

    /**
     * Registered directly here rather than from the REST API class, so the
     * whole fallback path (route + handler + the job itself) lives in one
     * file.
     *
     * @return void
     */
    public static function register_rest_route()
    {
        register_rest_route('metasync/v1', 'headless-refresh-batch', array(
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'rest_run_batch'),
                'permission_callback' => array(__CLASS__, 'rest_permission_check'),
                'args'                => array(
                    'offset' => array(
                        'default'           => 0,
                        'sanitize_callback' => 'absint',
                    ),
                    'batch_size' => array(
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            ),
        ));
    }

    /**
     * Reuses the plugin's own API-key/nonce authorization middleware so this
     * endpoint is protected the same way every other MetaSync REST route is,
     * rather than inventing a second auth path.
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public static function rest_permission_check($request)
    {
        if (!class_exists('Metasync_Rest_Api')) {
            return false;
        }

        $rest_api = new Metasync_Rest_Api('metasync', defined('METASYNC_VERSION') ? METASYNC_VERSION : '0.0.0');
        return $rest_api->rest_authorization_middleware($request);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function rest_run_batch($request)
    {
        $offset     = (int) $request->get_param('offset');
        $batch_size = $request->get_param('batch_size');
        $batch_size = ($batch_size === null || $batch_size === '') ? null : (int) $batch_size;

        $result = static::run_batch($offset, $batch_size);

        return new WP_REST_Response($result, 200);
    }

    /**
     * WP-CLI command: `wp metasync headless-refresh [--batch-size=<n>] [--offset=<n>]`
     *
     * @param array $args
     * @param array $assoc_args
     * @return void
     */
    public static function cli_command($args, $assoc_args)
    {
        $offset     = isset($assoc_args['offset']) ? (int) $assoc_args['offset'] : 0;
        $batch_size = isset($assoc_args['batch-size']) ? (int) $assoc_args['batch-size'] : null;

        $result = static::run_batch($offset, $batch_size);

        if (class_exists('WP_CLI')) {
            WP_CLI::log(wp_json_encode($result));
            WP_CLI::success(sprintf(
                'Processed %d, refreshed %d, skipped %d, failed %d.',
                $result['processed'],
                $result['refreshed'],
                $result['skipped'],
                $result['failed']
            ));
        }
    }
}
