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
 * @since      2.8.0
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
     * @param array $urls Absolute URLs that were modified by OTTO.
     */
    public static function purge(array $urls) {
        if (empty($urls)) {
            return;
        }

        try {
            self::get_instance()->purge_urls($urls);
        } catch (Exception $e) {
            self::log_error('purge', $e->getMessage());
        }
    }

    /**
     * Purge edge caches for a list of URLs.
     *
     * Resolves URLs to post IDs where possible for tag-based purging.
     * Falls back to URL-based purging when IDs can't be resolved.
     *
     * @param array $urls Absolute URLs modified by OTTO.
     */
    public function purge_urls(array $urls) {
        $settings = $this->get_settings();

        // Resolve URLs → post IDs for tag-based providers
        $post_ids = array();
        $unresolved_urls = array();

        foreach ($urls as $url) {
            $post_id = url_to_postid($url);
            if ($post_id > 0) {
                $post_ids[] = $post_id;
            } else {
                $unresolved_urls[] = $url;
            }
        }

        $post_ids = array_unique($post_ids);

        // Tag-based CDN providers (prefer tags, fall back to URLs)
        if (!empty($settings['cloudflare_enabled']) && $this->has_credentials('cloudflare')) {
            $this->purge_cloudflare($post_ids, $unresolved_urls, $settings);
        }

        if (!empty($settings['fastly_enabled']) && $this->has_credentials('fastly')) {
            $this->purge_fastly($post_ids, $settings);
        }

        if (!empty($settings['akamai_enabled']) && $this->has_credentials('akamai')) {
            $this->purge_akamai($post_ids, $settings);
        }

        // Full-flush providers (fire once per batch, not per URL)
        if (!empty($settings['sucuri_enabled']) && $this->has_credentials('sucuri')) {
            $this->purge_sucuri($settings);
        }

        if (!empty($settings['sevalla_enabled']) && $this->has_credentials('sevalla')) {
            // Only if KinstaCache mu-plugin is NOT available
            if (!class_exists('KinstaCache')) {
                $this->purge_sevalla($settings);
            }
        }

        // Hosting-level providers
        if (!empty($settings['cloudways_enabled'])) {
            $this->purge_cloudways($urls);
        }

        if (!empty($settings['flywheel_enabled']) && defined('FLYWHEEL_CONFIG_DIR')) {
            $this->purge_flywheel();
        }
    }

    /**
     * Purge edge caches by post IDs (tag-based).
     *
     * @param array $post_ids WordPress post IDs.
     */
    public function purge_by_post_ids(array $post_ids) {
        if (empty($post_ids)) {
            return;
        }

        $settings = $this->get_settings();

        if (!empty($settings['cloudflare_enabled']) && $this->has_credentials('cloudflare')) {
            $this->purge_cloudflare($post_ids, array(), $settings);
        }

        if (!empty($settings['fastly_enabled']) && $this->has_credentials('fastly')) {
            $this->purge_fastly($post_ids, $settings);
        }

        if (!empty($settings['akamai_enabled']) && $this->has_credentials('akamai')) {
            $this->purge_akamai($post_ids, $settings);
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  CDN Provider Implementations
    // ──────────────────────────────────────────────────────────────

    /**
     * Purge Cloudflare via Cache-Tag API.
     *
     * Uses tag-based purge for resolved post IDs (up to 30 tags per request).
     * Falls back to URL-based purge for unresolved URLs.
     *
     * @see https://developers.cloudflare.com/api/resources/cache/methods/purge/
     *
     * @param array $post_ids       Resolved post IDs.
     * @param array $fallback_urls  URLs that couldn't be resolved to post IDs.
     * @param array $settings       Edge cache settings.
     */
    private function purge_cloudflare(array $post_ids, array $fallback_urls, array $settings) {
        $zone_id   = $settings['cloudflare_zone_id'];
        $api_token = $settings['cloudflare_api_token'];
        $endpoint  = 'https://api.cloudflare.com/client/v4/zones/' . urlencode($zone_id) . '/purge_cache';

        $headers = array(
            'Authorization' => 'Bearer ' . $api_token,
            'Content-Type'  => 'application/json',
        );

        // Tag-based purge (chunked to 30 per request)
        if (!empty($post_ids)) {
            $tags = array_map(function ($id) {
                return 'metasync-post-' . $id;
            }, $post_ids);

            foreach (array_chunk($tags, self::CF_MAX_TAGS_PER_REQUEST) as $chunk) {
                $response = wp_remote_post($endpoint, array(
                    'headers' => $headers,
                    'body'    => wp_json_encode(array('tags' => $chunk)),
                    'timeout' => self::API_TIMEOUT,
                ));

                if (is_wp_error($response)) {
                    self::log_error('Cloudflare tag purge', $response->get_error_message());
                } else {
                    $code = wp_remote_retrieve_response_code($response);
                    if ($code < 200 || $code >= 300) {
                        self::log_error('Cloudflare tag purge', 'HTTP ' . $code);
                    }
                }
            }
        }

        // URL-based fallback for unresolved URLs
        if (!empty($fallback_urls)) {
            foreach (array_chunk($fallback_urls, self::CF_MAX_TAGS_PER_REQUEST) as $chunk) {
                $response = wp_remote_post($endpoint, array(
                    'headers' => $headers,
                    'body'    => wp_json_encode(array('files' => $chunk)),
                    'timeout' => self::API_TIMEOUT,
                ));

                if (is_wp_error($response)) {
                    self::log_error('Cloudflare URL purge', $response->get_error_message());
                } else {
                    $code = wp_remote_retrieve_response_code($response);
                    if ($code < 200 || $code >= 300) {
                        self::log_error('Cloudflare URL purge', 'HTTP ' . $code);
                    }
                }
            }
        }
    }

    /**
     * Purge Fastly via Surrogate-Key API.
     *
     * Uses soft purge (marks stale) for graceful invalidation.
     *
     * @see https://www.fastly.com/documentation/reference/api/purging/
     *
     * @param array $post_ids Resolved post IDs.
     * @param array $settings Edge cache settings.
     */
    private function purge_fastly(array $post_ids, array $settings) {
        if (empty($post_ids)) {
            return;
        }

        $service_id = $settings['fastly_service_id'];
        $api_token  = $settings['fastly_api_token'];
        $endpoint   = 'https://api.fastly.com/service/' . urlencode($service_id) . '/purge';

        $keys = array_map(function ($id) {
            return 'metasync-post-' . $id;
        }, $post_ids);

        $response = wp_remote_post($endpoint, array(
            'headers' => array(
                'Fastly-Key'        => $api_token,
                'Content-Type'      => 'application/json',
                'Fastly-Soft-Purge' => '1',
            ),
            'body'    => wp_json_encode(array('surrogate_keys' => $keys)),
            'timeout' => self::API_TIMEOUT,
        ));

        if (is_wp_error($response)) {
            self::log_error('Fastly purge', $response->get_error_message());
        } else {
            $code = wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                self::log_error('Fastly purge', 'HTTP ' . $code);
            }
        }
    }

    /**
     * Purge Akamai via CCU v3 Fast Purge API (tag-based invalidation).
     *
     * Uses EdgeGrid HMAC signing for authentication.
     *
     * @see https://techdocs.akamai.com/purge-cache/reference/invalidate-tag
     *
     * @param array $post_ids Resolved post IDs.
     * @param array $settings Edge cache settings.
     */
    private function purge_akamai(array $post_ids, array $settings) {
        if (empty($post_ids)) {
            return;
        }

        $tags = array_map(function ($id) {
            return 'metasync-post-' . $id;
        }, $post_ids);

        $host         = rtrim($settings['akamai_host'], '/');
        $path         = '/ccu/v3/invalidate/tag/production';
        $url          = 'https://' . $host . $path;
        $body         = wp_json_encode(array('objects' => $tags));
        $content_type = 'application/json';

        $auth_header = $this->sign_akamai_request(
            'POST',
            'https',
            $host,
            $path,
            $body,
            $content_type,
            $settings['akamai_client_token'],
            $settings['akamai_client_secret'],
            $settings['akamai_access_token']
        );

        if (empty($auth_header)) {
            self::log_error('Akamai purge', 'Failed to generate EdgeGrid signature');
            return;
        }

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => $auth_header,
                'Content-Type'  => $content_type,
            ),
            'body'    => $body,
            'timeout' => self::API_TIMEOUT,
        ));

        if (is_wp_error($response)) {
            self::log_error('Akamai purge', $response->get_error_message());
        } else {
            $code = wp_remote_retrieve_response_code($response);
            // Akamai returns 201 on success
            if ($code < 200 || $code >= 300) {
                self::log_error('Akamai purge', 'HTTP ' . $code);
            }
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
            array('timeout' => self::API_TIMEOUT)
        );

        if (is_wp_error($response)) {
            self::log_error('Sucuri purge', $response->get_error_message());
        } else {
            $code = wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                self::log_error('Sucuri purge', 'HTTP ' . $code);
            }
        }
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
        $app_id  = $settings['sevalla_application_id'];
        $headers = array(
            'Authorization' => 'Bearer ' . $settings['sevalla_api_key'],
        );

        // v3: Edge-cache-specific endpoint (preferred)
        $v3_endpoint = 'https://api.sevalla.com/v3/applications/' . urlencode($app_id) . '/purge-cache';

        $response = wp_remote_post($v3_endpoint, array(
            'headers' => $headers,
            'timeout' => self::API_TIMEOUT,
        ));

        if (is_wp_error($response)) {
            self::log_error('Sevalla v3 purge', $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);

        // v3 succeeded
        if ($code >= 200 && $code < 300) {
            return;
        }

        // v3 not available — fall back to v2 general cache clear
        if ($code === 404) {
            self::log_error('Sevalla purge', 'v3 endpoint not found, falling back to v2');
            $v2_endpoint = 'https://api.sevalla.com/v2/applications/' . urlencode($app_id) . '/clear-cache';

            $response = wp_remote_post($v2_endpoint, array(
                'headers' => $headers,
                'timeout' => self::API_TIMEOUT,
            ));

            if (is_wp_error($response)) {
                self::log_error('Sevalla v2 purge', $response->get_error_message());
            } else {
                $code = wp_remote_retrieve_response_code($response);
                if ($code < 200 || $code >= 300) {
                    self::log_error('Sevalla v2 purge', 'HTTP ' . $code);
                }
            }
            return;
        }

        // Other error on v3
        self::log_error('Sevalla purge', 'HTTP ' . $code);
    }

    /**
     * Purge Cloudways Varnish cache via HTTP PURGE per URL.
     *
     * @param array $urls URLs to purge.
     */
    private function purge_cloudways(array $urls) {
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

            if (is_wp_error($response)) {
                self::log_error('Cloudways purge', $response->get_error_message());
            }
        }
    }

    /**
     * Purge Flywheel cache (full flush).
     *
     * Fires once per batch via Flywheel's native action hook.
     */
    private function purge_flywheel() {
        if (has_action('fl_clear_all_cache')) {
            do_action('fl_clear_all_cache');
        }
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
                '', // query string (empty for this endpoint)
                $content_hash,
                $auth_header,
            ));

            // Signing key = HMAC-SHA256(client_secret, timestamp)
            $signing_key = base64_encode(
                hash_hmac('sha256', $timestamp, base64_decode($client_secret), true)
            );

            // Signature = HMAC-SHA256(signing_key, data_to_sign)
            $signature = base64_encode(
                hash_hmac('sha256', $data_to_sign, base64_decode($signing_key), true)
            );

            return $auth_header . 'signature=' . $signature;
        } catch (Exception $e) {
            self::log_error('Akamai EdgeGrid signing', $e->getMessage());
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
            error_log(sprintf('[MetaSync Edge Cache] %s failed: %s', $context, $message));
        }
    }
}
