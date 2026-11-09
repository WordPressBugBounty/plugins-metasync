<?php
/**
 * Remembers which OTTO payload was last processed for a URL.
 *
 * Every OTTO dashboard deploy fires the crawl webhook for the whole batch of
 * URLs, including pages whose suggestions did not change. The crawl job hashes
 * the payload it fetched and skips the purge when the hash matches the one
 * stored for that URL. The record tracks OTTO's data only: page and edge
 * cache purges are best-effort and their results never change it. A missing
 * or expired record means the next deploy purges as before.
 *
 * @package    Metasync
 * @subpackage Metasync/otto
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Metasync_Otto_Purge_Fingerprint {

    /** Transient prefix. Keys are scoped by blog id like the OTTO caches. */
    const PREFIX = 'metasync_otto_pfp_';

    /** How long a remembered hash is trusted. */
    const TTL = 30 * DAY_IN_SECONDS;

    /**
     * Stable hash of an OTTO suggestions payload. Associative arrays are
     * key-sorted so a different key order from the API is not a change.
     *
     * @param mixed $payload Suggestions payload as returned by the API.
     * @return string 32-char hex digest.
     */
    public static function of($payload) {
        $json = wp_json_encode(self::canonical($payload));
        return md5(is_string($json) ? $json : serialize($payload)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- hashing input only, never unserialized
    }

    /**
     * True when $fingerprint equals the hash stored for $url.
     *
     * @param string $url         Page URL.
     * @param string $fingerprint Value from of().
     * @return bool
     */
    public static function is_unchanged($url, $fingerprint) {
        $stored = get_transient(self::key($url));
        return is_string($stored) && $stored !== '' && hash_equals($stored, (string) $fingerprint);
    }

    /**
     * Store the hash of the payload just processed for $url.
     *
     * @param string $url         Page URL.
     * @param string $fingerprint Value from of().
     * @return void
     */
    public static function remember($url, $fingerprint) {
        set_transient(self::key($url), (string) $fingerprint, self::TTL);
    }

    /**
     * Drop the stored hash so the next deploy purges.
     *
     * @param string $url Page URL.
     * @return void
     */
    public static function forget($url) {
        delete_transient(self::key($url));
    }

    /**
     * Transient name for a URL. Trailing-slash and scheme/host case variants
     * share it. The path keeps its case: on most servers /Page and /page are
     * different pages.
     *
     * @param string $url Page URL.
     * @return string
     */
    private static function key($url) {
        $site_id = is_multisite() ? get_current_blog_id() : 0;
        $url = rtrim(trim((string) $url), '/');
        $url = preg_replace_callback('#^[a-z][a-z0-9+.-]*://[^/?\#]*#i', function ($m) {
            return strtolower($m[0]);
        }, $url);
        return self::PREFIX . $site_id . '_' . md5((string) $url);
    }

    /**
     * Recursively key-sort associative arrays (lists keep their order, since
     * order can change the rendered page). A sorted map is returned as an
     * object so it never hashes the same as a list.
     *
     * @param mixed $value Any payload value.
     * @return mixed
     */
    private static function canonical($value) {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value) || $value === array()) {
            return $value;
        }
        foreach ($value as $k => $v) {
            $value[$k] = self::canonical($v);
        }
        if (array_keys($value) === range(0, count($value) - 1)) {
            return $value;
        }
        ksort($value, SORT_STRING);
        return (object) $value;
    }
}
