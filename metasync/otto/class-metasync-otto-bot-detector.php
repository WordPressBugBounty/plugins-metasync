<?php
/**
 * Bot Detection Service for OTTO
 *
 * Detects and identifies search engine crawlers and bots to allow
 * selective processing of OTTO requests based on traffic source.
 *
 * @package    Metasync
 * @subpackage Metasync/otto
 * @since      1.0.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bot Detection Service Class
 *
 * Provides bot detection functionality with whitelisting and blacklisting support.
 * Implements singleton pattern for efficient memory usage.
 *
 * @since 1.0.0
 */
class Metasync_Otto_Bot_Detector {

    /**
     * Singleton instance
     *
     * @var Metasync_Otto_Bot_Detector|null
     */
    private static $instance = null;

    /**
     * Common search engine bot signatures
     *
     * @var array
     */
    private $common_bots = array(
        'Googlebot',
        'Googlebot-Image',
        'Googlebot-News',
        'Googlebot-Video',
        'Google-InspectionTool',
        'Storebot-Google',
        'GoogleOther',
        'Bingbot',
        'BingPreview',
        'Slurp',                // Yahoo
        'DuckDuckBot',          // DuckDuckGo
        'Baiduspider',          // Baidu
        'YandexBot',            // Yandex
        'Sogou',                // Sogou
        'Exabot',               // Exalead
        'facebot',              // Facebook
        'ia_archiver',          // Alexa
        'AdsBot-Google',
        'Mediapartners-Google',
        'APIs-Google',
        'AhrefsBot',
        'SemrushBot',
        'MJ12bot',              // Majestic
        'DotBot',               // Moz
        'Applebot',             // Apple
        'LinkedInBot',
        'Twitterbot',
        'PinterestBot',
        'rogerbot',             // Moz
        'Screaming Frog',
        'SiteAuditBot',
        'ArchiveBot',
        'archive.org_bot',
        'CCBot',                // Common Crawl
        'BLEXBot',
        'SEOkicks',
        'Qwantify',
        'PetalBot',             // Huawei
        'MauiBot',
        'AlphaBot',
        'SiteImprove',
        'serpstatbot',
        'WhatWeb',
        'ZoominfoBot',
        'SeekportBot',
        'DataForSeoBot'
    );

    /**
     * Generic bot patterns
     *
     * @var array
     */
    private $bot_patterns = array(
        '/bot/i',
        '/crawl/i',
        '/spider/i',
        '/slurp/i',
        '/mediapartners/i',
        '/scanner/i',
        '/scraper/i',
        '/checker/i',
        '/validator/i',
        '/fetcher/i',
        '/archiver/i',
        '/lighthouse/i',
        '/pagespeed/i'
    );

    /**
     * IP-based bot detection patterns
     * Common IP ranges for known bots
     *
     * @var array
     */
    private $bot_ip_ranges = array(
        // Google IP ranges (partial list - full list is extensive)
        '66.249.',
        '66.102.',
        '64.233.',
        '72.14.',
        '74.125.',
        '209.85.',
        '216.239.',
        // Bing IP ranges
        '40.77.',
        '157.55.',
        '207.46.',
        // Yandex
        '5.255.',
        '77.88.',
        '95.108.',
        '100.43.',
        '130.193.',
        '141.8.',
        '178.154.'
    );

    /**
     * Current detection result cache
     *
     * @var array|null
     */
    private $detection_cache = null;

    /**
     * Get singleton instance
     *
     * @return Metasync_Otto_Bot_Detector
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        // Private constructor for singleton
    }

    /**
     * Prevent cloning of the instance
     */
    private function __clone() {
        // Prevent cloning
    }

    /**
     * Prevent unserialization of the instance
     */
    public function __wakeup() {
        // Prevent unserialization
    }

    /**
     * Detect if current request is from a bot
     *
     * @param string|null $user_agent Optional user agent to check (defaults to current request)
     * @param string|null $ip Optional IP address to check (defaults to current request)
     * @return array Detection result with bot status and details
     */
    public function detect($user_agent = null, $ip = null) {
        // Use cache if available and no custom parameters provided
        if ($this->detection_cache !== null && $user_agent === null && $ip === null) {
            return $this->detection_cache;
        }

        // Get user agent from request if not provided
        if ($user_agent === null) {
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        }

        // Get IP from request if not provided
        if ($ip === null) {
            $ip = $this->get_client_ip();
        }

        // Initialize result
        $result = array(
            'is_bot' => false,
            'bot_name' => null,
            'bot_type' => null,
            'user_agent' => $user_agent,
            'ip_address' => $ip,
            'detection_method' => null
        );

        // Check if user agent is empty (likely a bot)
        if (empty($user_agent)) {
            $result['is_bot'] = true;
            $result['bot_name'] = 'Unknown Bot';
            $result['bot_type'] = 'unknown';
            $result['detection_method'] = 'empty_user_agent';

            // Cache and return
            $this->detection_cache = $result;
            return $result;
        }

        // Check against common bots list
        foreach ($this->common_bots as $bot) {
            if (stripos($user_agent, $bot) !== false) {
                $result['is_bot'] = true;
                $result['bot_name'] = $bot;
                $result['bot_type'] = $this->categorize_bot($bot);
                $result['detection_method'] = 'user_agent_match';

                // Cache and return
                $this->detection_cache = $result;
                return $result;
            }
        }

        // Check against bot patterns
        foreach ($this->bot_patterns as $pattern) {
            if (preg_match($pattern, $user_agent)) {
                $result['is_bot'] = true;
                $result['bot_name'] = $this->extract_bot_name($user_agent);
                $result['bot_type'] = 'generic';
                $result['detection_method'] = 'pattern_match';

                // Cache and return
                $this->detection_cache = $result;
                return $result;
            }
        }

        // Check IP-based detection
        if ($this->is_bot_ip($ip)) {
            $result['is_bot'] = true;
            $result['bot_name'] = 'Bot (IP-based detection)';
            $result['bot_type'] = 'ip_based';
            $result['detection_method'] = 'ip_range_match';

            // Cache and return
            $this->detection_cache = $result;
            return $result;
        }

        // Cache and return
        $this->detection_cache = $result;
        return $result;
    }

    /**
     * Check if current request should skip OTTO processing
     * Takes into account settings, whitelist, and blacklist
     *
     * @return bool True if OTTO should be skipped, false otherwise
     */
    public function should_skip_otto() {
        // Get plugin settings
        $options = get_option('metasync_options', array());
        $general = isset($options['general']) ? $options['general'] : array();

        // Check if bot filtering is enabled
        $disable_for_bots = isset($general['otto_disable_for_bots']) ? (bool)$general['otto_disable_for_bots'] : false;

        if (!$disable_for_bots) {
            return false; // Feature is disabled, don't skip
        }

        // Detect bot
        $detection = $this->detect();

        if (!$detection['is_bot']) {
            return false; // Not a bot, don't skip
        }

        // Check whitelist
        $whitelist = isset($general['otto_bot_whitelist']) ? $general['otto_bot_whitelist'] : '';
        if (!empty($whitelist)) {
            $whitelist_array = array_map('trim', explode("\n", $whitelist));
            foreach ($whitelist_array as $whitelisted_bot) {
                if (empty($whitelisted_bot)) continue;

                if (stripos($detection['user_agent'], $whitelisted_bot) !== false ||
                    stripos($detection['bot_name'], $whitelisted_bot) !== false) {
                    return false; // Bot is whitelisted, don't skip
                }
            }
        }

        // Check blacklist
        $blacklist = isset($general['otto_bot_blacklist']) ? $general['otto_bot_blacklist'] : '';
        if (!empty($blacklist)) {
            $blacklist_array = array_map('trim', explode("\n", $blacklist));
            foreach ($blacklist_array as $blacklisted_pattern) {
                if (empty($blacklisted_pattern)) continue;

                if (stripos($detection['user_agent'], $blacklisted_pattern) !== false ||
                    stripos($detection['bot_name'], $blacklisted_pattern) !== false) {
                    return true; // Bot is explicitly blacklisted, skip OTTO
                }
            }
        }

        // Bot detected and no whitelist override, skip OTTO
        return true;
    }

    /**
     * Categorize bot type
     *
     * @param string $bot_name Bot name
     * @return string Bot category
     */
    private function categorize_bot($bot_name) {
        $bot_lower = strtolower($bot_name);

        // Search engines
        if (stripos($bot_lower, 'google') !== false) return 'search_engine';
        if (stripos($bot_lower, 'bing') !== false) return 'search_engine';
        if (stripos($bot_lower, 'yahoo') !== false) return 'search_engine';
        if (stripos($bot_lower, 'yandex') !== false) return 'search_engine';
        if (stripos($bot_lower, 'baidu') !== false) return 'search_engine';
        if (stripos($bot_lower, 'duckduck') !== false) return 'search_engine';

        // SEO tools
        if (stripos($bot_lower, 'ahrefs') !== false) return 'seo_tool';
        if (stripos($bot_lower, 'semrush') !== false) return 'seo_tool';
        if (stripos($bot_lower, 'moz') !== false) return 'seo_tool';
        if (stripos($bot_lower, 'majestic') !== false) return 'seo_tool';
        if (stripos($bot_lower, 'screaming') !== false) return 'seo_tool';

        // Social media
        if (stripos($bot_lower, 'facebook') !== false) return 'social_media';
        if (stripos($bot_lower, 'twitter') !== false) return 'social_media';
        if (stripos($bot_lower, 'linkedin') !== false) return 'social_media';
        if (stripos($bot_lower, 'pinterest') !== false) return 'social_media';

        // Archive
        if (stripos($bot_lower, 'archive') !== false) return 'archiver';

        return 'other';
    }

    /**
     * Extract bot name from user agent string
     *
     * @param string $user_agent User agent string
     * @return string Extracted bot name
     */
    private function extract_bot_name($user_agent) {
        // Try to extract a meaningful name from the user agent
        if (preg_match('/([a-zA-Z]+[bB]ot[a-zA-Z]*)/i', $user_agent, $matches)) {
            return $matches[1];
        }

        if (preg_match('/([a-zA-Z]+[sS]pider)/i', $user_agent, $matches)) {
            return $matches[1];
        }

        if (preg_match('/([a-zA-Z]+[cC]rawler)/i', $user_agent, $matches)) {
            return $matches[1];
        }

        return 'Unknown Bot';
    }

    /**
     * Check if IP belongs to a known bot
     *
     * @param string $ip IP address
     * @return bool True if IP is from a known bot
     */
    private function is_bot_ip($ip) {
        if (empty($ip)) {
            return false;
        }

        foreach ($this->bot_ip_ranges as $range) {
            if (strpos($ip, $range) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get client IP address.
     *
     * Resolves the visitor's IP using a Cloudflare-first priority order so that
     * attacker-controlled headers cannot be used to spoof crawler IPs and bypass
     * the IP-range step of bot detection:
     *
     *   1. HTTP_CF_CONNECTING_IP — trusted, but only when HTTP_CF_RAY confirms
     *      the request actually traversed Cloudflare.
     *   2. HTTP_X_FORWARDED_FOR — fallback for non-Cloudflare reverse proxies.
     *      The chain is walked right-to-left and the first public IP is used;
     *      the leftmost value is attacker-controlled and must not be trusted.
     *   3. REMOTE_ADDR — TCP peer; final fallback for direct (non-proxied) hits.
     *
     * Non-standard, attacker-controllable headers are intentionally not
     * consulted at any point in this resolution chain.
     *
     * @return string Client IP address, or '' if none could be validated.
     */
    private function get_client_ip() {
        // 1. Cloudflare-proxied request: HTTP_CF_RAY is the canonical marker
        //    that Cloudflare actually handled the connection, which means
        //    HTTP_CF_CONNECTING_IP was set by Cloudflare and is trustworthy.
        if (!empty($_SERVER['HTTP_CF_RAY']) && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cf_ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
            if (filter_var($cf_ip, FILTER_VALIDATE_IP)) {
                return $cf_ip;
            }
        }

        // 2. Non-Cloudflare reverse proxy: walk X-Forwarded-For right-to-left
        //    and return the first public IP. The trusted proxy appends to the
        //    right; everything to the left of that may have been supplied by
        //    the client and cannot be relied on.
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = array_reverse(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            foreach ($ips as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '') {
                    continue;
                }
                if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $candidate;
                }
            }
        }

        // 3. Direct connection: TCP peer.
        if (!empty($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
            return $_SERVER['REMOTE_ADDR'];
        }

        return '';
    }

    /**
     * Log bot detection event
     *
     * @param array $detection Detection result
     * @return void
     */
    public function log_detection($detection) {
        // Only log if bot was detected
        if (!$detection['is_bot']) {
            return;
        }

        // Get database instance
        require_once plugin_dir_path(__FILE__) . 'class-metasync-otto-bot-statistics-database.php';
        $db = Metasync_Otto_Bot_Statistics_Database::get_instance();

        // Log the detection
        $db->add_detection(
            $detection['bot_name'],
            $detection['bot_type'],
            $detection['user_agent'],
            $detection['ip_address'],
            $detection['detection_method']
        );
    }

    /**
     * Push bot crawl log to SearchAtlas backend API (non-blocking fire-and-forget).
     *
     * Real search engine bots do not execute JavaScript, so the JS tracker
     * (otto-tracker.js) never captures them. This method sends crawl monitoring
     * data server-side via a non-blocking wp_remote_post so that the SA Crawl
     * Monitoring dashboard is populated regardless of whether the bot runs JS.
     *
     * @param array  $detection Detection result from detect()
     * @param string $url       The full URL being crawled
     * @return void
     */
    public function push_crawl_log_to_sa( $detection, $url ) {
        if ( ! $detection['is_bot'] ) {
            return;
        }

        // Require a configured OTTO UUID
        if ( ! class_exists( 'Metasync_Otto_Config' ) ) {
            return;
        }
        $otto_uuid = Metasync_Otto_Config::get_otto_uuid();
        if ( empty( $otto_uuid ) ) {
            return;
        }

        // Throttle outbound POSTs to at most one per URL+bot every 30 minutes.
        // Bot bursts can otherwise produce thousands of redundant requests to
        // the crawl-log endpoint. Key includes bot_name so different crawlers
        // hitting the same URL are each recorded once per window.
        //
        // Query string is stripped before hashing so a spoofed-bot scanner
        // hitting /page?x=1, /page?x=2, ... cannot generate unbounded unique
        // throttle keys (which would flood wp_options or evict legitimate
        // entries from a persistent object cache).
        $normalized_url = strtok( $url, '?' );
        if ( $normalized_url === false ) {
            $normalized_url = $url;
        }
        // Lowercase + length cap so attacker-controlled bot_name variations
        // collapse to a bounded key space and cannot flood wp_options.
        $bot_name     = substr( strtolower( $detection['bot_name'] ?? 'unknown' ), 0, 32 );
        $throttle_key = 'metasync_crawl_log_' . md5( $normalized_url . '|' . $bot_name );
        if ( get_transient( $throttle_key ) ) {
            return;
        }
        set_transient( $throttle_key, 1, 30 * MINUTE_IN_SECONDS );

        // Resolve endpoint — respects production/staging mode toggle
        if ( class_exists( 'Metasync_Endpoint_Manager' ) ) {
            $endpoint = Metasync_Endpoint_Manager::get_endpoint( 'OTTO_CRAWL_LOGS' );
        } else {
            $endpoint = 'https://sa.searchatlas.com/api/v2/otto-page-crawl-logs';
        }
        if ( empty( $endpoint ) ) {
            return;
        }

        $payload = array(
            'otto_uuid'  => $otto_uuid,
            'url'        => $url,
            'user_agent' => $detection['user_agent'],
            'context'    => null,
        );

        // Non-blocking: timeout=0.01 + blocking=false so we never wait for a response.
        // This prevents any latency impact on the bot's page request.
        wp_remote_post(
            trailingslashit( $endpoint ),
            array(
                'method'      => 'POST',
                'timeout'     => 0.01,
                'blocking'    => false,
                'headers'     => array( 'Content-Type' => 'application/json' ),
                'body'        => wp_json_encode( $payload ),
                'data_format' => 'body',
            )
        );
    }

    /**
     * Clear detection cache
     *
     * @return void
     */
    public function clear_cache() {
        $this->detection_cache = null;
    }
}
