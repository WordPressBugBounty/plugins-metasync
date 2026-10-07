<?php
/**
 * Consent-gated PostHog product analytics for MetaSync.
 *
 * Only explicit server-side events are sent. Autocapture and session recording
 * are intentionally not used. High-frequency activity (admin page views,
 * platform polling, automated purges/submits, backoff, editor micro-actions)
 * is not tracked at all. Activity that can repeat or burst (MCP write tools,
 * Content Genius syncs, platform imports, editor saves) is counted and rolled
 * up instead of sent per-event, and recurring failure signals are deduped.
 * Every event carries a `site` group so user-0 (cron/platform) events and
 * admin events can be joined per site.
 *
 * @package Metasync
 */

if (!defined('WPINC')) {
    die;
}

class Metasync_PostHog {
    private const OPT_IN_OPTION = 'metasync_analytics_opt_in';
    private const EXPLICIT_CONSENT_OPTION = 'metasync_analytics_consent_explicit';
    private const VERSION_OPTION = 'metasync_analytics_version';
    private const DEFAULT_HOST = 'https://posthog.internal.searchatlas.com';
    private const BATCH_PATH = '/batch/';
    private const GROUP_TYPE = 'site';
    private const GROUP_IDENTIFIED_OPTION = 'metasync_ph_group_identified';
    private const ROLLUP_PREFIX = 'metasync_ph_rollup_';
    private const ROLLUP_FLUSH_HOOK = 'metasync_ph_flush_rollups';
    private const ONCE_PREFIX = 'metasync_ph_once_';
    // Must not share ROLLUP_PREFIX: flush_rollups() scans that prefix.
    private const ROLLUP_LOCK_PREFIX = 'metasync_ph_lock_rollup_';
    private const ROLLUP_LOCK_STALE_AFTER = 30;
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('metasync_analytics_event', array($this, 'handle_event'), 10, 2);
        add_action('metasync_analytics_event_once', array($this, 'handle_event_once'), 10, 4);
        add_action('metasync_analytics_rollup', array($this, 'track_rollup'), 10, 3);
        add_action('metasync_analytics_import_batch', array($this, 'track_import_batch'), 10, 5);
        add_action(self::ROLLUP_FLUSH_HOOK, array($this, 'flush_rollups'));
        add_action('metasync_mcp_tool_executed', array($this, 'track_mcp_tool'), 10, 4);
        add_action('metasync_content_genius_sync', array($this, 'track_content_genius_sync'), 10, 3);
        add_filter('rest_request_after_callbacks', array($this, 'track_rest_request'), 10, 3);
        add_action('init', array($this, 'run_version_check'), 2);
    }

    /**
     * One-time-per-version checks: version adoption and consent migration.
     *
     * Legacy GA4 builds force-wrote the opt-in option to 'yes' whenever it was
     * missing, so an existing 'yes' was never a deliberate user choice. When
     * this plugin version first runs, reset an inherited 'yes' unless consent
     * was given explicitly through the wizard or settings toggle afterwards.
     *
     * A version change also reports plugin_updated (adoption signal);
     * plugin_uninstalled is fired from uninstall.php.
     */
    public function run_version_check() {
        $version = defined('METASYNC_VERSION') ? METASYNC_VERSION : '0';
        $previous = get_option(self::VERSION_OPTION, '');
        if ($previous === $version) {
            return;
        }
        update_option(self::VERSION_OPTION, $version, false);

        if ($previous !== '' && $previous !== $version) {
            $this->track('plugin_updated', array(
                'from_version' => substr((string) $previous, 0, 20),
                'to_version' => substr((string) $version, 0, 20),
            ));
        }

        if (get_option(self::OPT_IN_OPTION) === 'yes' && get_option(self::EXPLICIT_CONSENT_OPTION) !== 'yes') {
            update_option(self::OPT_IN_OPTION, 'no');
        }
    }

    /**
     * Action handler for metasync_analytics_event. Actions discard return
     * values, so this wraps track() without one.
     *
     * @param mixed $event      Event name.
     * @param array $properties Event properties.
     * @return void
     */
    public function handle_event($event, $properties = array()) {
        $this->track($event, $properties);
    }

    /**
     * Action handler for metasync_analytics_event_once; see handle_event().
     *
     * @param mixed  $event      Event name.
     * @param array  $properties Event properties.
     * @param string $dedupe_key Distinguishes variants of the same event.
     * @param int    $ttl        Window in seconds.
     * @return void
     */
    public function handle_event_once($event, $properties = array(), $dedupe_key = '', $ttl = DAY_IN_SECONDS) {
        $this->track_once($event, $properties, $dedupe_key, $ttl);
    }

    public function is_opted_in() {
        return get_option(self::OPT_IN_OPTION, 'no') === 'yes';
    }

    /**
     * Send one event.
     *
     * @param mixed    $event      Event name.
     * @param array    $properties Event properties.
     * @param int|null $timestamp  Unix time the event happened; defaults to now.
     *                             Rollups flushed late pass the time their
     *                             period started so counts land in that period.
     * @return bool Whether the event was handed to the transport.
     */
    public function track($event, $properties = array(), $timestamp = null) {
        if (!$this->is_opted_in() || !$this->is_configured() || !is_string($event) || $event === '') {
            return false;
        }

        $batch = array(
            array(
                'event' => sanitize_key($event),
                'properties' => $this->build_properties($properties),
                'distinct_id' => $this->get_distinct_id(),
                'timestamp' => gmdate('c', is_int($timestamp) && $timestamp > 0 ? $timestamp : time()),
            ),
        );
        $group_identify = $this->get_due_group_identify();
        if ($group_identify !== null) {
            $batch[] = $group_identify;
        }

        $payload = array(
            'api_key' => $this->get_api_key(),
            'batch' => $batch,
        );

        wp_remote_post($this->get_host() . self::BATCH_PATH, array(
            'body' => wp_json_encode($payload),
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 2,
            'blocking' => false,
            'data_format' => 'body',
        ));

        return true;
    }

    /**
     * Return the event payload for tests without sending it.
     *
     * @param mixed $event Event name.
     * @param array $properties Event properties.
     * @return array|null
     */
    public function get_event_payload($event, $properties = array()) {
        if (!$this->is_opted_in() || !$this->is_configured() || !is_string($event) || $event === '') {
            return null;
        }
        return array(
            'event' => sanitize_key($event),
            'properties' => $this->build_properties($properties),
            'distinct_id' => $this->get_distinct_id(),
        );
    }

    /**
     * Send an event at most once per $ttl seconds for the same dedupe key.
     *
     * For signals that can repeat on every cron tick or retry (connection
     * lost, the same import error over and over) where one event per window
     * is all the analysis needs.
     *
     * @param mixed  $event      Event name.
     * @param array  $properties Event properties.
     * @param string $dedupe_key Distinguishes variants of the same event.
     * @param int    $ttl        Window in seconds.
     * @return bool Whether the event was sent.
     */
    public function track_once($event, $properties = array(), $dedupe_key = '', $ttl = DAY_IN_SECONDS) {
        if (!$this->is_opted_in() || !is_string($event) || $event === '') {
            return false;
        }
        $key = self::ONCE_PREFIX . md5($event . '|' . (string) $dedupe_key);
        if (false !== get_transient($key)) {
            return false;
        }
        set_transient($key, 1, max(MINUTE_IN_SECONDS, (int) $ttl));
        return $this->track($event, $properties);
    }

    /**
     * Shared rollup: add counters to the current day/hour bucket of an event
     * instead of sending one event per occurrence.
     *
     * When a bucket closes, its counters go out as one event — on the next
     * increment, or from the flush cron if nothing else happens — with the
     * counter names as properties plus `period`. Use this for anything that
     * can fire from bulk work, platform pushes or repeated saves.
     *
     * @param mixed  $event    Event name.
     * @param mixed  $counters Counter name => amount to add.
     * @param string $period   'day' or 'hour'.
     * @return void
     */
    public function track_rollup($event, $counters, $period = 'day') {
        if (!$this->is_opted_in() || !is_string($event) || $event === '' || !is_array($counters)) {
            return;
        }

        $event = sanitize_key($event);
        $period = $period === 'hour' ? 'hour' : 'day';
        $bucket = $this->get_rollup_bucket($period);
        $option = self::ROLLUP_PREFIX . $event;

        // Platform pushes and the flush cron can hit the same row at once;
        // without the lock one writer's counts overwrite the other's, or a
        // closed bucket goes out twice. If the lock stays busy the increment
        // is dropped: analytics must never stall the request it observes.
        if (!$this->acquire_rollup_lock($event)) {
            return;
        }

        $state = get_option($option, array());
        if (!is_array($state)) {
            $state = array();
        }
        if (!empty($state['bucket']) && $state['bucket'] !== $bucket) {
            // Send first: resetting before a failed/no-op send would lose the counts.
            $this->send_rollup($event, $state);
            $state = array();
        }

        $counts = isset($state['counts']) && is_array($state['counts']) ? $state['counts'] : array();
        foreach ($counters as $name => $amount) {
            $name = sanitize_key((string) $name);
            if ($name === '' || !is_numeric($amount)) {
                continue;
            }
            $counts[$name] = ($counts[$name] ?? 0) + (int) $amount;
        }

        update_option($option, array(
            'bucket' => $bucket,
            'period' => $period,
            'started_at' => (int) ($state['started_at'] ?? time()),
            'counts' => $counts,
        ), false);
        $this->release_rollup_lock($event);

        if (!wp_next_scheduled(self::ROLLUP_FLUSH_HOOK)) {
            wp_schedule_single_event(time() + HOUR_IN_SECONDS, self::ROLLUP_FLUSH_HOOK);
        }
    }

    /**
     * Flush cron: send every rollup whose bucket has closed, so the last
     * bucket of a quiet site is not held back until the next occurrence.
     * Re-arms itself only while open buckets remain.
     */
    public function flush_rollups() {
        global $wpdb;
        if (empty($wpdb) || empty($wpdb->options)) {
            return;
        }

        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- core table name; prefix scan of our own rollup rows.
            $wpdb->esc_like(self::ROLLUP_PREFIX) . '%'
        ));

        $pending = false;
        foreach ((array) $names as $option) {
            $state = get_option($option, array());
            $period = is_array($state) && ($state['period'] ?? '') === 'hour' ? 'hour' : 'day';
            if (!$this->is_opted_in() || !is_array($state)) {
                // Consent withdrawn (or a corrupt row): drop it unsent.
                delete_option($option);
                continue;
            }
            if (($state['bucket'] ?? '') === $this->get_rollup_bucket($period)) {
                $pending = true;
                continue;
            }
            $event = substr($option, strlen(self::ROLLUP_PREFIX));
            if (!$this->acquire_rollup_lock($event)) {
                $pending = true; // Busy: retry on the next flush.
                continue;
            }
            // Re-read under the lock: an increment may have rolled the bucket
            // (and sent it) since the read above.
            wp_cache_delete($option, 'options');
            $state = get_option($option, array());
            if (is_array($state) && !empty($state['bucket'])) {
                if ($state['bucket'] !== $this->get_rollup_bucket($period)) {
                    $this->send_rollup($event, $state);
                    delete_option($option);
                } else {
                    $pending = true;
                }
            }
            $this->release_rollup_lock($event);
        }

        if ($pending && !wp_next_scheduled(self::ROLLUP_FLUSH_HOOK)) {
            wp_schedule_single_event(time() + HOUR_IN_SECONDS, self::ROLLUP_FLUSH_HOOK);
        }
    }

    private function send_rollup($event, array $state) {
        $counts = isset($state['counts']) && is_array($state['counts']) ? $state['counts'] : array();
        if ($counts === array()) {
            return;
        }
        $counts['period'] = ($state['period'] ?? '') === 'hour' ? 'hour' : 'day';
        $started_at = (int) ($state['started_at'] ?? 0);
        $this->track($event, $counts, $started_at > 0 ? $started_at : null);
    }

    /**
     * Per-event mutex for rollup rows. INSERT IGNORE is atomic, unlike
     * add_option() (which upserts); the same approach core uses for
     * WP_Upgrader::create_lock(). A lock left by a dead request expires.
     */
    private function acquire_rollup_lock($event) {
        global $wpdb;
        if (empty($wpdb) || empty($wpdb->options)) {
            return true;
        }
        $name = self::ROLLUP_LOCK_PREFIX . $event;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- core table name; atomic lock row.
                $name,
                (string) time()
            ));
            if ($inserted) {
                return true;
            }
            $since = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- core table name; lock row read.
                $name
            ));
            if ($since !== null && time() - (int) $since > self::ROLLUP_LOCK_STALE_AFTER) {
                // Delete only the stale value seen, so a fresh lock taken in
                // between is not removed.
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- core table name; stale lock cleanup.
                    $name,
                    (string) $since
                ));
                continue;
            }
            usleep(50000);
        }
        return false;
    }

    private function release_rollup_lock($event) {
        global $wpdb;
        if (empty($wpdb) || empty($wpdb->options)) {
            return;
        }
        $wpdb->delete($wpdb->options, array('option_name' => self::ROLLUP_LOCK_PREFIX . $event)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- lock row release.
    }

    private function get_rollup_bucket($period) {
        return gmdate($period === 'hour' ? 'YmdH' : 'Ymd');
    }

    /**
     * MCP write tools: daily counts only, never per-event. An AI agent can do
     * 100+ writes in one session, so per-event tracking would be pure noise.
     * On the first write of a new day, yesterday's counts are flushed as one
     * summary event and the option row is cleaned up.
     */
    public function track_mcp_tool($name, $params, $result, $execution_time) {
        if (!$this->is_opted_in() || !$this->is_write_tool($name)) {
            return;
        }

        $tool = sanitize_key(str_replace('wordpress_', '', (string) $name));
        $day = gmdate('Ymd');

        $count = get_option('metasync_ph_mcp_count_' . $day, array());
        if (!is_array($count)) {
            $count = array();
        }
        if ($count === array()) {
            $this->flush_mcp_daily_summary(gmdate('Ymd', time() - 86400));
        }

        $count[$tool] = ($count[$tool] ?? 0) + 1;
        update_option('metasync_ph_mcp_count_' . $day, $count, false);
    }

    private function flush_mcp_daily_summary($day) {
        $counts = get_option('metasync_ph_mcp_count_' . $day, array());
        // Send first: deleting before a failed/no-op send would lose the counts.
        if (is_array($counts) && $counts !== array()) {
            $this->track('mcp_daily_summary', array(
                'tools_used' => count($counts),
                'total_calls' => array_sum($counts),
            ));
        }
        delete_option('metasync_ph_mcp_count_' . $day);
        $this->sweep_stale_mcp_days(gmdate('Ymd'));
    }

    /**
     * The rollover flush only targets yesterday. If the site skipped one or
     * more days in between, those day rows would linger forever — drop every
     * count row that is not today's.
     */
    private function sweep_stale_mcp_days($today) {
        global $wpdb;
        if (empty($wpdb) || empty($wpdb->options)) {
            return;
        }
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name != %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table name.
            'metasync_ph_mcp_count_%',
            'metasync_ph_mcp_count_' . $today
        ));
    }

    /**
     * Content Genius syncs: at most one event per action per hour, carrying
     * the article counts accumulated since the previous send — so bulk syncs
     * of dozens of posts stay at one event per hour with real counts.
     */
    public function track_content_genius_sync($content_type, $action, $is_landing_page) {
        if (!$this->is_opted_in()) {
            return;
        }

        $action = sanitize_key((string) $action);
        $type = sanitize_key((string) $content_type);
        $key = 'metasync_ph_cg_' . $action;

        $state = get_option($key, array());
        if (!is_array($state)) {
            $state = array();
        }
        $counts = isset($state['counts']) && is_array($state['counts']) ? $state['counts'] : array();
        $sent_at = (int) ($state['sent_at'] ?? 0);
        $counts[$type] = ($counts[$type] ?? 0) + 1;

        if (time() - $sent_at >= HOUR_IN_SECONDS) {
            $this->track('content_genius_article_' . $action, array(
                'count' => array_sum($counts),
                'content_type' => $type,
                'is_landing_page' => (bool) $is_landing_page,
            ));
            update_option($key, array('counts' => array(), 'sent_at' => time()), false);
        } else {
            update_option($key, array('counts' => $counts, 'sent_at' => $sent_at), false);
        }
    }

    /**
     * SEO data imports from other plugins: one event per finished import.
     *
     * Post-level imports run as 50-item AJAX batches, so per-batch counts are
     * summed in a short-lived transient (reset by the offset-0 batch) and the
     * event goes out only on the final batch or the first failure. Single-shot
     * imports (redirects, sitemap, robots, schema, CSV) send immediately.
     *
     * @param string $source_plugin Plugin imported from (yoast, rankmath, csv, ...).
     * @param string $data_type     What was imported.
     * @param mixed  $result        The importer's result array.
     * @param int    $offset        Batch offset; 0 starts a new import.
     * @param mixed  $extra         Extra properties (e.g. overwrite_existing).
     */
    public function track_import_batch($source_plugin, $data_type, $result, $offset = 0, $extra = array()) {
        if (!$this->is_opted_in() || !is_array($result)) {
            return;
        }

        $source_plugin = sanitize_key((string) $source_plugin);
        $data_type = sanitize_key((string) $data_type);
        $success = !empty($result['success']);
        $breakdown = isset($result['breakdown']) && is_array($result['breakdown']) ? $result['breakdown'] : array();
        $imported = (int) ($result['imported'] ?? ($breakdown['imported'] ?? 0));
        $skipped = (int) ($result['skipped'] ?? 0);

        if (array_key_exists('is_complete', $result) || array_key_exists('has_more', $result)) {
            $key = 'metasync_ph_import_' . md5(get_current_user_id() . '|' . $source_plugin . '|' . $data_type);
            $sum = (int) $offset === 0 ? array() : get_transient($key);
            if (!is_array($sum)) {
                $sum = array();
            }
            $imported += (int) ($sum['imported'] ?? 0);
            $skipped += (int) ($sum['skipped'] ?? 0);
            $complete = array_key_exists('is_complete', $result) ? !empty($result['is_complete']) : empty($result['has_more']);
            if ($success && !$complete) {
                // A day, not an hour: big sites can run imports for longer, and the
                // offset-0 batch resets the sum anyway.
                set_transient($key, array('imported' => $imported, 'skipped' => $skipped), DAY_IN_SECONDS);
                return;
            }
            delete_transient($key);
        }

        $this->track('seo_data_import_completed', array_merge(is_array($extra) ? $extra : array(), array(
            'source_plugin' => $source_plugin,
            'data_type' => $data_type,
            'success' => $success,
            'imported' => $imported,
            'skipped' => $skipped,
            'total' => isset($result['total']) ? (int) $result['total'] : $imported + $skipped,
        )));
    }

    /**
     * REST: single deliberate write actions only. Reads are not tracked, and
     * Content Genius create/update syncs are covered by the batched
     * content_genius event instead. Platform-side actions can burst, so each
     * endpoint reports at most once per hour.
     *
     * The filter receives the raw callback return value (mixed) before core
     * normalizes it, so anything that is not a WP_REST_Response is skipped —
     * several handlers return false, arrays, WP_Error, or die via
     * wp_send_json*, and none of those shapes can be read safely.
     */
    public function track_rest_request($response, $handler, $request) {
        if (!$this->is_opted_in() || is_wp_error($response) || !$response instanceof WP_REST_Response) {
            return $response;
        }

        $method = strtoupper((string) $request->get_method());
        if ($method === 'GET') {
            return $response;
        }

        $endpoint = $this->get_tracked_rest_endpoint((string) $request->get_route());
        if ($endpoint !== null) {
            $dedupe_key = 'metasync_ph_rest_' . $endpoint;
            if (false === get_transient($dedupe_key)) {
                $this->track('rest_endpoint_called', array(
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'status' => (int) $response->get_status(),
                ));
                set_transient($dedupe_key, 1, HOUR_IN_SECONDS);
            }
        }

        return $response;
    }

    /**
     * Helper for explicit feature events from anywhere in the plugin.
     *
     * @param string $event Event name.
     * @param array  $properties Event properties.
     * @return void
     */
    public static function feature($event, $properties = array()) {
        self::dispatch('metasync_analytics_event', $event, $properties);
    }

    /**
     * Helper for deduplicated events: at most one per $ttl per dedupe key.
     */
    public static function feature_once($event, $properties = array(), $dedupe_key = '', $ttl = DAY_IN_SECONDS) {
        self::dispatch('metasync_analytics_event_once', $event, $properties, $dedupe_key, $ttl);
    }

    /**
     * Helper for rollups: counters summed per day/hour, sent once per period.
     */
    public static function rollup($event, $counters, $period = 'day') {
        self::dispatch('metasync_analytics_rollup', $event, $counters, $period);
    }

    /**
     * Helper for SEO data import batches; sends once per finished import.
     */
    public static function import_batch($source_plugin, $data_type, $result, $offset = 0, $extra = array()) {
        self::dispatch('metasync_analytics_import_batch', $source_plugin, $data_type, $result, $offset, $extra);
    }

    /**
     * Helper for Content Genius sync rollups.
     */
    public static function content_genius_sync($content_type, $action, $is_landing_page) {
        self::dispatch('metasync_content_genius_sync', $content_type, $action, $is_landing_page);
    }

    /**
     * Fire a helper hook. Analytics must never break the feature it
     * observes, so a context without the hook API (CLI tools, unit harnesses)
     * simply drops the event.
     */
    private static function dispatch($hook, ...$args) {
        if (function_exists('do_action')) {
            do_action($hook, ...$args);
        }
    }

    private function is_configured() {
        return $this->get_api_key() !== '';
    }

    private function get_api_key() {
        return defined('METASYNC_POSTHOG_API_KEY') ? trim((string) METASYNC_POSTHOG_API_KEY) : '';
    }

    private function get_host() {
        $host = defined('METASYNC_POSTHOG_HOST') ? (string) constant('METASYNC_POSTHOG_HOST') : '';
        if ($host === '') {
            $host = self::DEFAULT_HOST;
        }
        return untrailingslashit(esc_url_raw($host));
    }

    private function get_distinct_id() {
        $user_id = get_current_user_id();
        $site_url = (string) get_option('siteurl', home_url('/'));
        return 'user_' . wp_hash($user_id . '|' . $site_url);
    }

    /**
     * Site identity shared by every event, whoever sent it.
     *
     * Platform pushes and cron run as user 0, so their distinct_id differs
     * from the admin's and person-level funnels cannot join them. Every event
     * therefore also carries a PostHog `site` group, keyed by a hash of the
     * site URL (stable across salt rotation, unlike wp_hash()).
     */
    private function get_site_group_key() {
        $site_url = strtolower(untrailingslashit((string) get_option('siteurl', home_url('/'))));
        return 'site_' . substr(hash('sha256', $site_url), 0, 32);
    }

    private function build_properties($properties) {
        $built = array_merge($this->get_super_properties(), $this->sanitize_properties($properties));
        // Added after sanitizing: sanitize_key() would strip the `$`.
        $built['$groups'] = array(self::GROUP_TYPE => $this->get_site_group_key());
        return $built;
    }

    /**
     * A `$groupidentify` entry setting the site group's properties, at most
     * once per day and again whenever the plugin version changes; null when
     * one has already gone out.
     */
    private function get_due_group_identify() {
        $stamp = (defined('METASYNC_VERSION') ? METASYNC_VERSION : 'unknown') . '|' . gmdate('Ymd');
        if (get_option(self::GROUP_IDENTIFIED_OPTION, '') === $stamp) {
            return null;
        }
        update_option(self::GROUP_IDENTIFIED_OPTION, $stamp, false);

        $super = $this->get_super_properties();
        unset($super['user_role']);
        $group_key = $this->get_site_group_key();
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);

        return array(
            'event' => '$groupidentify',
            'properties' => array(
                '$group_type' => self::GROUP_TYPE,
                '$group_key' => $group_key,
                '$group_set' => array_merge($super, array('name' => is_string($host) ? $host : $group_key)),
            ),
            'distinct_id' => $group_key,
            'timestamp' => gmdate('c'),
        );
    }

    private function get_super_properties() {
        global $wp_version;
        return array(
            'site_url' => esc_url_raw(home_url('/')),
            'plugin_version' => defined('METASYNC_VERSION') ? METASYNC_VERSION : 'unknown',
            'wp_version' => (string) $wp_version,
            'php_version' => PHP_VERSION,
            'is_multisite' => is_multisite(),
            'user_role' => $this->get_user_role(),
        );
    }

    private function get_user_role() {
        $user = wp_get_current_user();
        return !empty($user->roles) ? sanitize_key((string) reset($user->roles)) : 'guest';
    }

    private function sanitize_properties($properties) {
        if (!is_array($properties)) {
            return array();
        }

        $safe = array();
        foreach ($properties as $key => $value) {
            $key = sanitize_key($key);
            if ($key === '' || in_array($key, array('site_url', 'api_key', 'password', 'email', 'name', 'content', 'url', 'token', 'uuid'), true)) {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $safe[$key] = $value;
            } elseif (is_string($value) && strlen($value) <= 100) {
                $safe[$key] = sanitize_text_field($value);
            }
        }
        return $safe;
    }

    private function is_write_tool($name) {
        $name = strtolower((string) $name);
        if ($name === '') {
            return false;
        }
        foreach (array('get_', 'list_', 'search_', 'analyze_', 'check_', 'validate_', 'audit_', 'db_', 'system_') as $prefix) {
            if (strpos($name, $prefix) !== false) {
                return false;
            }
        }
        return (bool) preg_match('/(^|_)(create|update|delete|set|add|remove|bulk|import|convert|regenerate|trigger|purge|sync|upload|generate|restore|publish|exclude|clear)/', $name);
    }

    private function get_tracked_rest_endpoint($route) {
        $write_routes = array('createKeyFile', 'syncHeartbeatData');
        foreach ($write_routes as $endpoint) {
            if (stripos($route, $endpoint) !== false) {
                return sanitize_key($endpoint);
            }
        }
        return null;
    }
}
