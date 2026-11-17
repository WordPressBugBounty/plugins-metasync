<?php
/**
 * MetaSync Edge Cache / CDN Purge Handler
 *
 * Purges external CDN caches (Cloudflare, Fastly, Akamai, Sucuri, Sevalla)
 * and hosting-level caches (Cloudways Varnish, Flywheel) when OTTO updates pages.
 *
 * Mirrors the singleton structure of Metasync_Cache_Purge but handles only
 * edge/CDN providers that require external API calls.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 * @since      2.5.23
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Edge_Cache_Purge {

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * Cached settings from metasync_edge_cache_options.
     *
     * @var array|null
     */
    private $settings = null;

    /**
     * HTTP timeout for all external API calls (seconds).
     */
    const API_TIMEOUT = 5;

    /**
     * Cloudflare max tags per purge request.
     */
    const CF_MAX_TAGS_PER_REQUEST = 30;

    /**
     * Get singleton instance.
     *
     * @return self
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {}

    /**
     * Detect and persist Cloudways Varnish presence.
     *
     * Called once on `init` so the settings UI can show the toggle
     * even on admin pages where X-Varnish header isn't present.
     */
    public static function detect_cloudways() {
        if (!empty($_SERVER['HTTP_X_VARNISH']) && !get_option('metasync_cloudways_detected')) {
            update_option('metasync_cloudways_detected', true, true);
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Public API
    // ──────────────────────────────────────────────────────────────

    /**
     * Static wrapper: purge edge caches for the given URLs.
     *
     * Safe to call from anywhere — failures are logged, never thrown.
     *
     * Accepts either a single URL string or an array of URLs. The argument is
     * intentionally untyped: a strict `array` hint would raise a TypeError at
     * the call boundary (before the body runs), which the try/catch below could
     * never intercept — contradicting the "never thrown" contract above. Any
     * non-string, non-array input is normalised away and ignored.
     *
     * @param mixed $urls Absolute URL(s) that were modified by OTTO — a string
     *                    or an array of strings; anything else is ignored.
     */
    public static function purge($urls) {
        try {
            if (is_string($urls)) {
                $urls = array($urls);
            }
            if (!is_array($urls) || !$urls) {
                return self::summary(array(), 'skipped');
            }
            return self::get_instance()->purge_urls($urls);
        } catch (\Throwable $e) {
            // Exception messages can contain request URLs/credentials.
            self::terminal_failure('purge', 'unexpected_' . get_class($e));
            return self::summary(array(array('accepted' => 0, 'deferred' => 0, 'failed' => 1, 'skipped' => 0)));
        }
    }

    const PENDING_OPTION = 'metasync_edge_cache_pending_urls';
    const LOCK_OPTION = 'metasync_edge_cache_purge_lock';
    const DRAINER_HOOK = 'metasync_edge_cache_purge_drainer';
    const MAX_PENDING = 500;
    const MAX_ATTEMPTS = 4;

    /** Register recovery on every runtime, including WP-Cron. */
    public static function init_hooks() {
        add_action(self::DRAINER_HOOK, array(__CLASS__, 'handle_pending_drainer'));
        // Recover work left behind by a terminated PHP worker or a failed cron write.
        self::schedule_pending();
    }

    public static function handle_pending_drainer() {
        self::purge_pending();
    }

    private static function purge_pending() {
        try {
            self::get_instance()->purge_urls(array());
        } catch (\Throwable $e) {
            self::terminal_failure('drainer', 'unexpected_' . get_class($e));
        } finally {
            self::schedule_pending();
        }
    }

    /** API acceptance is NOT confirmation of cache eviction. Counts are URL work items. */
    private static function summary(array $providers, $empty_status = 'no_providers') {
        $counts = array('accepted' => 0, 'deferred' => 0, 'failed' => 0, 'skipped' => 0);
        foreach ($providers as &$outcome) {
            foreach ($counts as $key => $unused) {
                $counts[$key] += $outcome[$key];
            }
            $outcome['status'] = self::outcome_status($outcome, 'skipped');
        }
        unset($outcome);
        $status = self::outcome_status($counts, $empty_status);
        return array('status' => $status, 'ok' => $counts['failed'] === 0,
            'counts' => $counts, 'providers' => $providers, 'eviction_confirmed' => false);
    }

    private static function outcome_status(array $counts, $empty) {
        if ($counts['failed']) {
            return ($counts['accepted'] || $counts['deferred']) ? 'partial' : 'failed';
        }
        if ($counts['deferred']) {
            return 'deferred';
        }
        return $counts['accepted'] ? 'accepted' : ($counts['skipped'] ? 'skipped' : $empty);
    }

    /** Only site-owned HTTP(S) origins may receive the Fastly credential. No redirects. */
    private function normalize_urls(array $urls) {
        $origins = array(home_url('/'), site_url('/'));
        if (class_exists('Metasync_Headless_Config') && Metasync_Headless_Config::is_active()) {
            $origins[] = Metasync_Headless_Config::get_frontend_domain();
        }
        $hosts = array();
        foreach ($origins as $origin) {
            $hosts[] = strtolower((string) wp_parse_url($origin, PHP_URL_HOST));
        }
        $valid = array();
        $rejected = 0;
        foreach ($urls as $url) {
            if (!is_string($url) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
                $rejected++;
                continue;
            }
            $parts = wp_parse_url($url);
            if (!$parts || empty($parts['host']) || empty($parts['scheme'])
                || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)
                || !in_array(strtolower($parts['host']), $hosts, true)
                || isset($parts['user']) || isset($parts['pass'])
                || (isset($parts['port']) && $parts['port'] !== (strtolower($parts['scheme']) === 'https' ? 443 : 80))) {
                $rejected++;
                continue;
            }
            $normalized = strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
                . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            // One URL must fit within the Akamai JSON byte budget.
            if (strlen(wp_json_encode(array('objects' => array($normalized)))) > 45000) {
                $rejected++;
                continue;
            }
            $valid[$normalized] = $normalized;
        }
        return array(array_values($valid), $rejected);
    }

    /** Serialize queue and rate-budget mutations across requests using an option lock. */
    public function purge_urls(array $urls) {
        try {
            return $this->execute_urls($urls);
        } catch (\Throwable $e) {
            self::terminal_failure('purge_urls', 'unexpected_' . get_class($e));
            return self::summary(array('internal' => array('accepted' => 0, 'deferred' => 0, 'failed' => 1, 'skipped' => 0)));
        }
    }

    private function execute_urls(array $urls) {
        list($urls, $rejected) = $this->normalize_urls($urls);
        $settings = $this->get_settings();
        $providers = array();
        if ($rejected) {
            $providers['input'] = array('accepted' => 0, 'deferred' => 0, 'failed' => $rejected, 'skipped' => 0);
        }
        $lock = get_option(self::LOCK_OPTION, 0);
        if ($lock && (int) $lock < time() - 600) {
            delete_option(self::LOCK_OPTION);
        }
        if (!add_option(self::LOCK_OPTION, time(), '', false)) {
            self::terminal_failure('queue', 'busy_retry_required');
            $providers['queue'] = array('accepted' => 0, 'deferred' => 0, 'failed' => max(1, count($urls)), 'skipped' => 0);
            return self::summary($providers);
        }
        try {
            $pending = get_option(self::PENDING_OPTION, array());
            if (!is_array($pending)) {
                $pending = array();
            }
            foreach (array('fastly', 'akamai', 'cloudflare') as $provider) {
                $entries = $pending[$provider] ?? array();
                if (empty($settings[$provider . '_enabled']) && !$entries) {
                    continue;
                }
                $out = array('accepted' => 0, 'deferred' => 0, 'failed' => 0, 'skipped' => 0);
                if (empty($settings[$provider . '_enabled']) || !$this->has_credentials($provider)) {
                    $out['failed'] = count($urls) + count($entries);
                    if ($out['failed']) {
                        self::terminal_failure($provider, 'disabled_or_missing_credentials');
                    }
                    unset($pending[$provider]);
                    $providers[$provider] = $out;
                    continue;
                }
                foreach ($urls as $url) {
                    $key = md5($url);
                    if (isset($entries[$key])) {
                        continue; // Preserve attempt count and Retry-After on repeated purges.
                    }
                    if (count($entries) >= self::MAX_PENDING) {
                        $out['failed']++;
                        self::terminal_failure($provider, 'queue_capacity');
                    } else {
                        $entries[$key] = array('url' => $url, 'attempts' => 0, 'next' => time());
                    }
                }
                $pending[$provider] = $entries;
                self::save_pending($pending); // Write ahead: process death cannot lose overflow.
                $providers[$provider] = $this->process_provider($provider, $pending, $settings, $out);
            }
            self::save_pending($pending);
            // Legacy full-flush/hosting providers retain their native behavior.
            foreach (array('sucuri', 'sevalla', 'cloudways', 'flywheel') as $provider) {
                if (!$urls || empty($settings[$provider . '_enabled'])) {
                    continue;
                }
                $out = array('accepted' => 0, 'deferred' => 0, 'failed' => 0, 'skipped' => count($urls));
                $result = null;
                if ($provider === 'cloudways') {
                    $result = $this->purge_cloudways($urls);
                } elseif ($provider === 'flywheel' && defined('FLYWHEEL_CONFIG_DIR')) {
                    $result = $this->purge_flywheel();
                } elseif ($provider === 'sucuri' && $this->has_credentials('sucuri')) {
                    $result = $this->purge_sucuri($settings);
                } elseif ($provider === 'sevalla' && $this->has_credentials('sevalla') && !class_exists('KinstaCache')) {
                    $result = $this->purge_sevalla($settings);
                } elseif (in_array($provider, array('sucuri', 'sevalla'), true) && !$this->has_credentials($provider)) {
                    $result = false;
                }
                if ($result !== null) {
                    $out['skipped'] = 0;
                    $out[$result ? 'accepted' : 'failed'] = count($urls);
                    if (!$result) {
                        self::terminal_failure($provider, 'provider_rejected');
                    }
                } else {
                    $out['reason'] = 'native_provider_unavailable';
                }
                $providers[$provider] = $out;
            }
            return self::summary($providers, $urls ? 'no_providers' : 'skipped');
        } finally {
            delete_option(self::LOCK_OPTION);
            self::schedule_pending();
        }
    }

    private static function save_pending(array $pending) {
        if (get_option(self::PENDING_OPTION, null) === $pending) {
            return;
        }
        if (!update_option(self::PENDING_OPTION, $pending, false)) {
            throw new \RuntimeException('edge_queue_write_failed');
        }
    }

    private static function schedule_pending() {
        $pending = get_option(self::PENDING_OPTION, array());
        $next = null;
        foreach ((array) $pending as $entries) {
            foreach ($entries as $entry) {
                $next = $next === null ? $entry['next'] : min($next, $entry['next']);
            }
        }
        if ($next !== null && !wp_next_scheduled(self::DRAINER_HOOK)) {
            $scheduled = wp_schedule_single_event(max(time() + 60, $next), self::DRAINER_HOOK, array(), true);
            if (is_wp_error($scheduled)) {
                self::terminal_failure('queue', 'cron_schedule_failed');
            }
        }
    }

    /** Conservative shared budgets, persisted across PHP workers (including tag calls).
     * Fastly documents 1,000 URL purges/sec; Akamai URL invalidation 6,000/min,
     * 50,000-byte bodies and 100 URLs/request. Stay well below those limits:
     * 20 Fastly requests/min; 2 Akamai requests/min; <=50 URLs / 45KB JSON.
     * https://www.fastly.com/documentation/reference/api/purging/
     * https://techdocs.akamai.com/purge-cache/docs/fast-purge-limits
     */
    private function process_provider($provider, array &$pending, array $settings, array $out) {
        $budget_key = 'metasync_edge_cache_budget_' . $provider;
        $budget = get_option($budget_key, array());
        if (empty($budget['until']) || $budget['until'] <= time()) {
            $budget = array('until' => time() + 60, 'used' => 0);
        }
        $limit = $provider === 'fastly' ? 20 : 2;
        foreach ($pending[$provider] as $key => $entry) {
            list($valid) = $this->normalize_urls(array($entry['url']));
            if (!$valid) {
                unset($pending[$provider][$key]);
                $out['failed']++;
                self::terminal_failure($provider, 'queued_url_no_longer_allowed');
            }
        }
        $entries = $pending[$provider];
        $due = array_filter($entries, function ($entry) { return $entry['next'] <= time(); });
        $ids = array();
        if (!empty($settings['cache_tags_enabled'])) {
            foreach ($due as $entry) {
                if ($entry['attempts'] > 0 || !empty($entry['tag_attempted'])) {
                    continue;
                }
                $id = url_to_postid($entry['url']);
                if ($id > 0 && !(get_option('show_on_front') === 'page' && $id === (int) get_option('page_for_posts'))) {
                    $ids[$id] = 'metasync-post-' . $id;
                }
            }
        }
        // Supplemental tags never substitute for URL coverage (historical objects may be untagged).
        $tag_size = $provider === 'fastly' ? 256 : ($provider === 'akamai' ? 128 : 30);
        if ($ids) {
            foreach ($due as $key => $entry) {
                $pending[$provider][$key]['tag_attempted'] = true;
            }
            self::save_pending($pending);
        }
        foreach (array_chunk(array_values($ids), $tag_size) as $tags) {
            if ($budget['used'] >= $limit - 1) {
                break; // Reserve at least one request for URLs.
            }
            $budget['used']++;
            self::save_budget($budget_key, $budget);
            $response = $this->send_provider_request($provider, $tags, true, $settings);
            $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            if ($code === 0 || $code === 429 || $code >= 500) {
                $delay = self::retry_delay($response, $code);
                $budget['until'] = time() + max(60, $delay);
                $budget['used'] = $limit;
                self::save_budget($budget_key, $budget);
                break; // URL work remains durable; tags are supplemental, not retried alone.
            }
        }
        foreach ($due as $key => $unused) {
            $due[$key] = $pending[$provider][$key];
        }
        while ($due && $budget['used'] < $limit) {
            $chunk = array();
            $size = $provider === 'fastly' ? 1 : ($provider === 'akamai' ? 50 : 30);
            foreach ($due as $key => $entry) {
                $candidate = array_merge(array_column($chunk, 'url'), array($entry['url']));
                if ($chunk && (count($chunk) >= $size || strlen(wp_json_encode(array('objects' => $candidate))) > 45000)) {
                    break;
                }
                $chunk[$key] = $entry;
            }
            $budget['used']++;
            self::save_budget($budget_key, $budget);
            $response = $this->send_provider_request($provider, array_column($chunk, 'url'), false, $settings);
            $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            $accepted = $code >= 200 && $code < 300;
            // Cloudflare may return HTTP 200 with success:false.
            if ($accepted && $provider === 'cloudflare') {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $accepted = is_array($body) && !empty($body['success']);
            }
            $retryable = $code === 0 || $code === 429 || $code >= 500;
            $delay = self::retry_delay($response, $code);
            foreach ($chunk as $key => $entry) {
                unset($due[$key]);
                if ($accepted) {
                    unset($pending[$provider][$key]);
                    $out['accepted']++;
                } elseif ($retryable && ++$entry['attempts'] < self::MAX_ATTEMPTS) {
                    $entry['next'] = time() + max($delay, 60 * (2 ** $entry['attempts']));
                    $pending[$provider][$key] = $entry;
                } else {
                    unset($pending[$provider][$key]);
                    $out['failed']++;
                    self::terminal_failure($provider, 'http_' . $code . '_or_retry_exhausted');
                }
            }
            self::save_pending($pending);
            if (!$accepted && $retryable) {
                // A throttled batch must stop ALL work for this provider until Retry-After.
                $budget['until'] = time() + max(60, $delay);
                $budget['used'] = $limit;
                self::save_budget($budget_key, $budget);
                break;
            }
        }
        foreach ($pending[$provider] as &$entry) {
            $entry['next'] = max($entry['next'], $budget['until']);
        }
        unset($entry);
        $out['deferred'] += count($pending[$provider]);
        self::save_pending($pending);
        return $out;
    }

    private static function retry_delay($response, $code) {
        if ($code !== 429 && $code < 500) {
            return 0;
        }
        $header = wp_remote_retrieve_header($response, 'retry-after');
        return is_numeric($header) ? max(0, (int) $header) : max(0, (int) strtotime((string) $header) - time());
    }

    private static function save_budget($key, array $budget) {
        if (!update_option($key, $budget, false) && get_option($key) !== $budget) {
            throw new \RuntimeException('edge_budget_write_failed');
        }
    }

    private function send_provider_request($provider, array $objects, $tags, array $settings) {
        $args = array('timeout' => self::API_TIMEOUT, 'redirection' => 0);
        if ($provider === 'fastly') {
            $args['headers'] = array('Fastly-Key' => $settings['fastly_api_token'], 'Fastly-Soft-Purge' => '1');
            if (!$tags) {
                $args['method'] = 'PURGE';
                return wp_remote_request($objects[0], $args);
            }
            $url = 'https://api.fastly.com/service/' . urlencode($settings['fastly_service_id']) . '/purge';
            $args['body'] = wp_json_encode(array('surrogate_keys' => $objects));
        } elseif ($provider === 'akamai') {
            $host = rtrim($settings['akamai_host'], '/');
            $path = $tags ? '/ccu/v3/invalidate/tag/production' : '/ccu/v3/invalidate/url/production';
            $url = 'https://' . $host . $path;
            $args['body'] = wp_json_encode(array('objects' => $objects));
            $args['headers'] = array('Authorization' => $this->sign_akamai_request('POST', 'https', $host, $path,
                $args['body'], 'application/json', $settings['akamai_client_token'],
                $settings['akamai_client_secret'], $settings['akamai_access_token']));
        } else {
            $url = 'https://api.cloudflare.com/client/v4/zones/' . urlencode($settings['cloudflare_zone_id']) . '/purge_cache';
            $args['headers'] = array('Authorization' => 'Bearer ' . $settings['cloudflare_api_token']);
            $args['body'] = wp_json_encode(array($tags ? 'tags' : 'files' => $objects));
        }
        $args['headers']['Content-Type'] = 'application/json';
        return wp_remote_post($url, $args);
    }

    private static function terminal_failure($provider, $reason) {
        static $reported = array();
        $key = $provider . ':' . $reason;
        if (isset($reported[$key])) {
            return;
        }
        $reported[$key] = true;
        try {
            self::log_error($provider, $reason);
            if (function_exists('metasync_record_failed_action')) {
                metasync_record_failed_action(self::DRAINER_HOOK);
            }
        } catch (\Throwable $ignored) {
            // Diagnostics must never break the non-throwing public boundary.
        }
    }

    /**
     * Purge Sucuri WAF cache (full flush).
     *
     * Sucuri does not support tag-based or URL-based selective purging.
     * Fires once per batch, not per URL.
     *
     * @see https://docs.sucuri.net/website-firewall/api/
     *
     * @param array $settings Edge cache settings.
     */
    private function purge_sucuri(array $settings) {
        $response = wp_remote_get(
            add_query_arg(array(
                'k' => $settings['sucuri_api_key'],
                's' => $settings['sucuri_api_secret'],
                'a' => 'clear_cache',
            ), 'https://waf.sucuri.net/api'),
            array('timeout' => self::API_TIMEOUT, 'redirection' => 0)
        );
        if (is_wp_error($response)) {
            return false;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return self::http_accepted($response) && is_array($body) && !empty($body['status']);
    }

    private static function http_accepted($response) {
        if (is_wp_error($response)) {
            return false;
        }
        $code = wp_remote_retrieve_response_code($response);
        return $code >= 200 && $code < 300;
    }

    /**
     * Purge Sevalla / Kinsta edge cache via API.
     *
     * Tries the v3 edge-cache-specific endpoint first (purge-cache),
     * then falls back to v2 general cache clear if v3 returns 404.
     *
     * Only called when KinstaCache mu-plugin is NOT available.
     *
     * @see https://api-docs.sevalla.com/v3/applications/purge-edge-cache
     * @see https://docs.sevalla.com/applications/edge-caching
     *
     * @param array $settings Edge cache settings.
     */
    private function purge_sevalla(array $settings) {
        $args = array('headers' => array('Authorization' => 'Bearer ' . $settings['sevalla_api_key']),
            'timeout' => self::API_TIMEOUT, 'redirection' => 0);
        $base = 'https://api.sevalla.com/';
        $app = urlencode($settings['sevalla_application_id']);
        $response = wp_remote_post($base . 'v3/applications/' . $app . '/purge-cache', $args);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 404) {
            $response = wp_remote_post($base . 'v2/applications/' . $app . '/clear-cache', $args);
        }
        return self::http_accepted($response);
    }

    /**
     * Purge Cloudways Varnish cache via HTTP PURGE per URL.
     *
     * @param array $urls URLs to purge.
     */
    private function purge_cloudways(array $urls) {
        $ok = true;
        foreach ($urls as $url) {
            $parsed = wp_parse_url($url);
            if (empty($parsed['path'])) {
                continue;
            }

            // PURGE request to localhost with the URL path
            $response = wp_remote_request('http://127.0.0.1:80/', array(
                'method'  => 'PURGE',
                'headers' => array(
                    'Host'        => $parsed['host'] ?? wp_parse_url(home_url(), PHP_URL_HOST),
                    'X-Purge-URL' => $parsed['path'] . (isset($parsed['query']) ? '?' . $parsed['query'] : ''),
                ),
                'timeout' => self::API_TIMEOUT,
            ));

            $ok = self::http_accepted($response) && $ok;
        }
        return $ok;
    }

    /**
     * Purge Flywheel cache (full flush).
     *
     * Fires once per batch via Flywheel's native action hook.
     */
    private function purge_flywheel() {
        if (has_action('fl_clear_all_cache')) {
            do_action('fl_clear_all_cache');
            return true;
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────────
    //  Akamai EdgeGrid HMAC Signing
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate Akamai EdgeGrid Authorization header.
     *
     * @see https://techdocs.akamai.com/developer/docs/authenticate-with-edgegrid
     *
     * @param string $method        HTTP method.
     * @param string $scheme        URL scheme (https).
     * @param string $host          EdgeGrid host.
     * @param string $path          Request path.
     * @param string $body          Request body.
     * @param string $content_type  Content-Type header.
     * @param string $client_token  Client token.
     * @param string $client_secret Client secret.
     * @param string $access_token  Access token.
     * @return string Authorization header value, or empty string on failure.
     */
    private function sign_akamai_request($method, $scheme, $host, $path, $body, $content_type, $client_token, $client_secret, $access_token) {
        try {
            $timestamp = gmdate('Ymd\TH:i:s+0000');
            $nonce     = wp_generate_uuid4();

            // Auth header prefix (unsigned)
            $auth_header = sprintf(
                'EG1-HMAC-SHA256 client_token=%s;access_token=%s;timestamp=%s;nonce=%s;',
                $client_token,
                $access_token,
                $timestamp,
                $nonce
            );

            // Content hash (POST body, max 131072 bytes)
            $content_hash = '';
            if ($method === 'POST' && !empty($body)) {
                $body_to_hash = substr($body, 0, 131072);
                $content_hash = base64_encode(hash('sha256', $body_to_hash, true));
            }

            // Data to sign
            $data_to_sign = implode("\t", array(
                $method,
                $scheme,
                $host,
                $path,
                '', // canonical signed headers (none); path includes any query string
                $content_hash,
                $auth_header,
            ));

            // Signing key = HMAC-SHA256(client_secret, timestamp)
            $signing_key = base64_encode(
                hash_hmac('sha256', $timestamp, $client_secret, true)
            );

            // Signature = HMAC-SHA256(signing_key, data_to_sign)
            $signature = base64_encode(
                hash_hmac('sha256', $data_to_sign, $signing_key, true)
            );

            return $auth_header . 'signature=' . $signature;
        } catch (Exception $e) {
            self::log_error('Akamai EdgeGrid signing', 'signature_failed');
            return '';
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Get edge cache settings (cached per request).
     *
     * @return array
     */
    private function get_settings() {
        if (null === $this->settings) {
            $this->settings = class_exists('Metasync_Edge_Cache_Settings')
                ? Metasync_Edge_Cache_Settings::get_settings()
                : wp_parse_args(get_option('metasync_edge_cache_options', array()), array());
        }
        return $this->settings;
    }

    /**
     * Check if required credentials are present for a provider.
     *
     * @param string $provider Provider key.
     * @return bool
     */
    private function has_credentials($provider) {
        $settings = $this->get_settings();

        switch ($provider) {
            case 'cloudflare':
                return !empty($settings['cloudflare_zone_id']) && !empty($settings['cloudflare_api_token']);
            case 'fastly':
                return !empty($settings['fastly_service_id']) && !empty($settings['fastly_api_token']);
            case 'akamai':
                return !empty($settings['akamai_client_token'])
                    && !empty($settings['akamai_access_token'])
                    && !empty($settings['akamai_client_secret'])
                    && !empty($settings['akamai_host']);
            case 'sucuri':
                return !empty($settings['sucuri_api_key']) && !empty($settings['sucuri_api_secret']);
            case 'sevalla':
                return !empty($settings['sevalla_api_key']) && !empty($settings['sevalla_application_id']);
            default:
                return false;
        }
    }

    /**
     * Log an error without exposing credentials.
     *
     * @param string $context Provider/operation name.
     * @param string $message Error message.
     */
    private static function log_error($context, $message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[MetaSync Edge Cache] %s failed: %s', $context, $message)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-gated, no secrets
        }
    }
}
