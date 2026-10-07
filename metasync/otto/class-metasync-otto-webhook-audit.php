<?php
/**
 * Bounded audit log for OTTO crawl-notify webhook deliveries.
 *
 * The crawl-notify webhook stores two things today, and neither answers
 * "what did the crawler send us, and when did it land?": the crawl data
 * option is a living snapshot whose URL list is merged on every delivery,
 * and the per-URL status store recycles terminal rows after 48 hours. This
 * store fills that gap: one append-only entry per webhook delivery, carrying
 * the WordPress receipt timestamp, the domain, and the complete URL list
 * exactly as delivered.
 *
 * Entries are keyed per delivery, NOT per URL and NOT derived from the
 * payload — the crawler may legitimately re-deliver an identical batch, and
 * every delivery must stay separately auditable. Each entry also carries a
 * reserved 'extra' map so future webhook metadata can ride along without a
 * storage redesign.
 *
 * Storage is option-backed and deliberately bounded, mirroring
 * Metasync_Otto_Job_Status: entries older than RETENTION_TTL are pruned on
 * every write, and the store is hard-capped at MAX_ENTRIES with oldest-first
 * eviction, so a chatty crawler can never grow it without limit. The option
 * is never autoloaded.
 *
 * As with the other option-backed stores, a write is a read-modify-write of
 * the whole option: two deliveries landing in the exact same instant on
 * parallel requests can race, with one delivery's entry surviving. The
 * crawler delivers per page-update, so this is vanishingly rare in practice
 * and the trade is the same one the status store already makes.
 *
 * The log is strictly additive to the crawl-notify pipeline: the caller wraps
 * every write in a failure guard, so a logging failure must never turn a
 * webhook delivery into an error response.
 *
 * @package Search Atlas SEO
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Metasync_Otto_Webhook_Audit {

    const OPTION = 'metasync_otto_webhook_audit_log';

    /**
     * Retention window: 7 days (604800s). Entries older than this are pruned
     * on every write. The per-URL status store keeps history for only 48
     * hours, so webhook receipts deliberately outlive per-URL state: a
     * support or compliance lookback a week later can still reach the
     * original payload after the working state has long since been recycled.
     */
    const RETENTION_TTL = 604800;

    /**
     * Hard size cap so retention alone can never grow storage without limit
     * (e.g. a crawler retry storm). Eviction is oldest-received-first.
     * Roughly a thousand deliveries of a realistic batch (a domain plus a
     * few dozen URLs) is a few hundred KB of option data, and the cap keeps
     * the worst case — a thousand full-site resync batches — from trending
     * toward megabytes on every read/write.
     */
    const MAX_ENTRIES = 1000;

    /**
     * Record one webhook delivery.
     *
     * Appends an entry keyed uniquely per delivery and prunes the store, so a
     * single call both records and enforces the retention/size bounds. The
     * caller owns the failure guard; a false return here means the delivery
     * was not auditable (blank domain or empty URL list), never an error.
     *
     * @param int   $received_at Unix timestamp captured when WordPress
     *                            received the webhook.
     * @param mixed $domain      Domain exactly as delivered in the payload.
     * @param array $urls        Complete URL list exactly as delivered.
     * @param array $extra       Optional extra fields, kept under the
     *                           reserved 'extra' key so future metadata needs
     *                           no schema change.
     * @return string|false The new entry's key, or false when nothing was
     *                      recorded.
     */
    public static function record($received_at, $domain, array $urls = [], array $extra = []) {
        if (!self::is_nonempty_string($domain)) {
            return false;
        }

        if ($urls === []) {
            return false;
        }

        $store = get_option(self::OPTION, []);
        if (!is_array($store)) {
            // A corrupt store is re-initialised, not propagated: the current
            // delivery is still auditable even if history was lost.
            $store = [];
        }

        $received_at = (int) $received_at;
        $key = self::make_key($received_at);

        // One slot per delivery: two processes can land deliveries in the
        // same second with sequences that start at the same offset, so re-key
        // until the slot is free rather than overwrite a stored delivery.
        while (isset($store[$key])) {
            $key = self::make_key($received_at);
        }

        $store[$key] = [
            'received_at' => $received_at,
            'domain'      => (string) $domain,
            'urls'        => array_values($urls),
            'extra'       => $extra,
        ];

        $store = self::prune($store);

        update_option(self::OPTION, $store, false);
        return $key;
    }

    /**
     * All audit entries, keyed by delivery key (empty array when none).
     *
     * Reads are side-effect free: pruning happens on write only, so a read
     * can never rewrite the option under a concurrent delivery.
     *
     * @return array
     */
    public static function all() {
        $store = get_option(self::OPTION, []);
        return is_array($store) ? $store : [];
    }

    /**
     * Entries received at or after $since.
     *
     * @param int $since Unix timestamp lower bound.
     * @return array
     */
    public static function since($since) {
        $matching = [];
        foreach (self::all() as $key => $entry) {
            if (is_array($entry) && (int) ($entry['received_at'] ?? 0) >= $since) {
                $matching[$key] = $entry;
            }
        }
        return $matching;
    }

    /**
     * Remove every audit entry. For tooling and uninstall only — uninstall
     * also sweeps the option via the plugin's metasync_% pattern.
     *
     * @return bool
     */
    public static function flush() {
        return delete_option(self::OPTION);
    }

    /**
     * Enforce the retention and size bounds.
     *
     * Entries past the retention cutoff are dropped first; survivors are
     * ordered newest-first so the array_slice() head keeps the newest
     * MAX_ENTRIES deliveries and the tail (oldest) is what overflow evicts.
     *
     * @param array $store
     * @return array
     */
    private static function prune($store) {
        $cutoff = time() - self::RETENTION_TTL;

        $retained = [];
        foreach ($store as $key => $entry) {
            if (is_array($entry) && (int) ($entry['received_at'] ?? 0) > $cutoff) {
                $retained[$key] = $entry;
            }
        }

        uasort($retained, function ($a, $b) {
            // Entries in $retained passed the is_array() filter above.
            $ta = (int) ($a['received_at'] ?? 0);
            $tb = (int) ($b['received_at'] ?? 0);
            return $tb <=> $ta;
        });

        if (count($retained) > self::MAX_ENTRIES) {
            $retained = array_slice($retained, 0, self::MAX_ENTRIES, true);
        }

        return $retained;
    }

    /**
     * Monotonic per-process counter appended to every key: two deliveries in
     * the same second never collide within a request, and each webhook is one
     * request, so per-process uniqueness is all the key needs beyond the
     * collision re-key loop in record().
     *
     * @var int
     */
    private static $sequence = 0;

    /**
     * Unique per-delivery key: receipt time plus the per-process sequence.
     * Deliberately not derived from the payload — a re-delivered batch must
     * not collide with the delivery before it.
     *
     * @param int $received_at
     * @return string
     */
    private static function make_key($received_at) {
        return (string) $received_at . '-' . (string) (self::$sequence++);
    }

    /**
     * Runtime guard for the string-only part of the public API. Webhook data
     * is not trusted, and the untyped parameter keeps the check from being
     * statically redundant (same reasoning as
     * Metasync_Otto_Job_Status::is_nonempty_string()).
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_nonempty_string($value) {
        return is_string($value) && $value !== '';
    }
}
