<?php
/**
 * Bounded per-URL status store for the OTTO crawl-notify pipeline.
 *
 * The crawl-notify webhook acknowledges *scheduling*, not execution, so the
 * only way to answer "what actually happened to this URL" is to record it as
 * the background jobs run. This store keeps one entry per URL (keyed by md5),
 * a bounded overflow queue for webhook batches that exceed the inline cap,
 * and helpers the batched cache job uses to wait for stragglers.
 *
 * Storage is option-backed and deliberately capped so a chatty crawler can
 * never grow it without bound: at most MAX_ENTRIES status rows and at most
 * MAX_PENDING queued URLs, with terminal rows pruned after TERMINAL_TTL.
 *
 * @package Search Atlas SEO
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Metasync_Otto_Job_Status {

    /** Per-URL lifecycle states. */
    const STATE_ACCEPTED  = 'accepted';
    const STATE_RETRYING  = 'retrying';
    const STATE_COMPLETED = 'completed';
    const STATE_FAILED    = 'failed';

    /** Processing outcomes returned by the SEO sync. */
    const OUTCOME_SUCCESS   = 'success';
    const OUTCOME_NO_CHANGE = 'no_change';
    const OUTCOME_RETRYABLE = 'retryable';
    const OUTCOME_PERMANENT = 'permanent';
    const OUTCOME_DEFERRED  = 'deferred';

    const OPTION        = 'metasync_otto_job_status';
    const PENDING_OPTION = 'metasync_otto_pending_urls';

    /** Must cover the peak in-flight set — MAX_PENDING queued URLs plus the
     *  webhook's inline batch cap (METASYNC_MAX_JOBS_PER_BATCH, default 25)
     *  = 525 — so prune() can never evict a URL whose work is still live;
     *  terminal history is evicted first when the cap is hit. */
    const MAX_ENTRIES  = 525;
    const MAX_PENDING  = 500;
    const TERMINAL_TTL = 172800; // 48 hours.

    /** States that mean a URL still has live work in flight. */
    const ACTIVE_STATES = [self::STATE_ACCEPTED, self::STATE_RETRYING];

    /**
     * Record a state transition for a URL.
     *
     * @param string $url      Normalized absolute URL.
     * @param string $state    One of the STATE_* constants.
     * @param string $stage    Pipeline stage that produced the transition.
     * @param int    $attempts Attempt number (0 = first try).
     * @param string $reason  Short machine-readable reason.
     * @param array  $extra   Optional extra fields merged into the entry.
     * @return bool
     */
    public static function record($url, $state, $stage = '', $attempts = 0, $reason = '', $extra = []) {
        if (!self::is_nonempty_string($url)) {
            return false;
        }

        $store = get_option(self::OPTION, []);
        if (!is_array($store)) {
            $store = [];
        }

        $key = md5($url);
        $entry = [
            'url'      => $url,
            'state'    => $state,
            'stage'    => $stage,
            'attempts' => (int) $attempts,
            'reason'   => (string) $reason,
            'updated'  => time(),
            'first_seen' => isset($store[$key]['first_seen']) ? $store[$key]['first_seen'] : time(),
        ];
        if (!empty($extra)) {
            $entry = array_merge($entry, $extra);
        }

        $store[$key] = $entry;
        $store = self::prune($store);

        update_option(self::OPTION, $store, false);
        return true;
    }

    /**
     * Get the recorded entry for a URL (null when unknown).
     *
     * @param string $url
     * @return array|null
     */
    public static function get($url) {
        $store = get_option(self::OPTION, []);
        if (!is_array($store)) {
            return null;
        }
        $key = md5((string) $url);
        return isset($store[$key]) && is_array($store[$key]) ? $store[$key] : null;
    }

    /**
     * Whether a URL has live (non-terminal, fresh) work in flight.
     *
     * @param string $url
     * @return bool
     */
    public static function has_active($url) {
        $entry = self::get($url);
        if ($entry === null) {
            return false;
        }
        if (!in_array($entry['state'], self::ACTIVE_STATES, true)) {
            return false;
        }
        // Active entries older than 15 minutes are considered stale so a
        // crashed worker cannot wedge a URL out of future scheduling forever.
        return (time() - (int) $entry['updated']) < 900;
    }

    /**
     * State tallies plus pending-queue depth, for Site Health and debugging.
     *
     * @return array{accepted:int,retrying:int,completed:int,failed:int,pending:int}
     */
    public static function counts() {
        $store = get_option(self::OPTION, []);
        $counts = [
            'accepted'  => 0,
            'retrying'  => 0,
            'completed' => 0,
            'failed'    => 0,
            'pending'   => 0,
        ];
        if (is_array($store)) {
            foreach ($store as $entry) {
                if (is_array($entry) && isset($counts[$entry['state']])) {
                    $counts[$entry['state']]++;
                }
            }
        }
        $counts['pending'] = self::pending_count();
        return $counts;
    }

    /**
     * Number of URLs waiting in the overflow queue.
     *
     * @return int
     */
    public static function pending_count() {
        $pending = get_option(self::PENDING_OPTION, []);
        return is_array($pending) ? count($pending) : 0;
    }

    /**
     * Durably queue a URL that could not be scheduled inline. Returns false
     * when the URL is already queued or the queue is at its cap.
     *
     * @param string $url
     * @return bool
     */
    public static function enqueue_pending($url) {
        $pending = get_option(self::PENDING_OPTION, []);
        if (!is_array($pending)) {
            $pending = [];
        }
        if (in_array($url, $pending, true)) {
            return false;
        }
        if (count($pending) >= self::MAX_PENDING) {
            return false;
        }
        $pending[] = $url;
        update_option(self::PENDING_OPTION, $pending, false);
        return true;
    }

    /**
     * Take (and remove) up to $max URLs from the head of the overflow queue.
     *
     * @param int $max
     * @return array List of URLs taken.
     */
    public static function take_pending($max = 25) {
        $pending = get_option(self::PENDING_OPTION, []);
        if (!is_array($pending) || $pending === []) {
            return [];
        }
        $taken = array_slice($pending, 0, $max);
        $rest  = array_slice($pending, $max);
        update_option(self::PENDING_OPTION, array_values($rest), false);
        return $taken;
    }

    /**
     * Re-queue a URL for processing by scheduling a fresh job.
     *
     * @param string $url
     * @return bool
     */
    public static function replay($url) {
        if (!self::is_nonempty_string($url)) {
            return false;
        }
        self::record($url, self::STATE_ACCEPTED, 'replay', 0, '');
        if (function_exists('metasync_otto_schedule_single_event')) {
            return (bool) metasync_otto_schedule_single_event(time() + 5, 'metasync_process_otto_crawl_url_job', [$url]);
        }
        if (function_exists('wp_schedule_single_event')) {
            return wp_schedule_single_event(time() + 5, 'metasync_process_otto_crawl_url_job', [$url]) === true;
        }
        return false;
    }

    /**
     * Enforce the size and retention bounds. Evicts oldest-updated terminal
     * entries first, then oldest-updated anything.
     *
     * @param array $store
     * @return array
     */
    private static function prune($store) {
        $now = time();

        // Retention: drop terminal entries past their TTL.
        foreach ($store as $key => $entry) {
            if (is_array($entry)
                && !in_array($entry['state'], self::ACTIVE_STATES, true)
                && ($now - (int) $entry['updated']) > self::TERMINAL_TTL) {
                unset($store[$key]);
            }
        }

        // Size cap: sort into keep order (the slice keeps the head), evicting
        // oldest-updated terminal entries first, then oldest-updated anything.
        // Active rows are live work in flight and outrank terminal rows for
        // retention; within the same class, newer updates outrank older ones.
        if (count($store) > self::MAX_ENTRIES) {
            uasort($store, function ($a, $b) {
                $ta = is_array($a) ? (int) $a['updated'] : 0;
                $tb = is_array($b) ? (int) $b['updated'] : 0;
                // Active entries keep before terminal ones; terminal evict first.
                $sa = is_array($a) && !in_array($a['state'], self::ACTIVE_STATES, true) ? 1 : 0;
                $sb = is_array($b) && !in_array($b['state'], self::ACTIVE_STATES, true) ? 1 : 0;
                if ($sa !== $sb) {
                    return $sa - $sb;
                }
                return $tb <=> $ta;
            });
            $store = array_slice($store, 0, self::MAX_ENTRIES, true);
        }

        return $store;
    }

    /**
     * Runtime guard for the string-only public API. Callers come from cron
     * payloads and webhook data, so a docblock contract is not enough; the
     * untyped parameter keeps the check from being statically redundant.
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_nonempty_string($value) {
        return is_string($value) && $value !== '';
    }
}
