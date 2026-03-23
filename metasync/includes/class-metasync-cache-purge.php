<?php
/**
 * MetaSync Universal Cache Purge Handler
 * 
 * Integrates with all major WordPress cache plugins to ensure
 * changes made by MetaSync and OTTO are immediately visible
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 * @since      1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Cache_Purge
{
    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - private to enforce singleton
     */
    private function __construct()
    {
        // Constructor is private
    }

    /**
     * Clear ALL cache from ALL detected cache plugins
     * 
     * @param string $source Optional source identifier for logging
     * @return array Results of cache clearing attempts
     */
    public function clear_all_caches($source = 'metasync')
    {
        $results = array(
            'cleared' => array(),
            'failed' => array(),
            'not_active' => array()
        );

        // Clear WordPress built-in cache
        if ($this->clear_wordpress_cache()) {
            $results['cleared'][] = 'WordPress Object Cache';
        }

        // Clear WP Rocket
        if ($this->is_plugin_active('wp-rocket/wp-rocket.php')) {
            if ($this->clear_wp_rocket_cache()) {
                $results['cleared'][] = 'WP Rocket';
            } else {
                $results['failed'][] = 'WP Rocket';
            }
        } else {
            $results['not_active'][] = 'WP Rocket';
        }

        // Clear LiteSpeed Cache
        if ($this->is_plugin_active('litespeed-cache/litespeed-cache.php')) {
            if ($this->clear_litespeed_cache()) {
                $results['cleared'][] = 'LiteSpeed Cache';
            } else {
                $results['failed'][] = 'LiteSpeed Cache';
            }
        } else {
            $results['not_active'][] = 'LiteSpeed Cache';
        }

        // Clear W3 Total Cache
        if ($this->is_plugin_active('w3-total-cache/w3-total-cache.php')) {
            if ($this->clear_w3_total_cache()) {
                $results['cleared'][] = 'W3 Total Cache';
            } else {
                $results['failed'][] = 'W3 Total Cache';
            }
        } else {
            $results['not_active'][] = 'W3 Total Cache';
        }

        // Clear WP Super Cache
        if ($this->is_plugin_active('wp-super-cache/wp-cache.php')) {
            if ($this->clear_wp_super_cache()) {
                $results['cleared'][] = 'WP Super Cache';
            } else {
                $results['failed'][] = 'WP Super Cache';
            }
        } else {
            $results['not_active'][] = 'WP Super Cache';
        }

        // Clear WP Fastest Cache
        if ($this->is_plugin_active('wp-fastest-cache/wpFastestCache.php')) {
            if ($this->clear_wp_fastest_cache()) {
                $results['cleared'][] = 'WP Fastest Cache';
            } else {
                $results['failed'][] = 'WP Fastest Cache';
            }
        } else {
            $results['not_active'][] = 'WP Fastest Cache';
        }

        // Clear Cache Enabler
        if ($this->is_plugin_active('cache-enabler/cache-enabler.php')) {
            if ($this->clear_cache_enabler()) {
                $results['cleared'][] = 'Cache Enabler';
            } else {
                $results['failed'][] = 'Cache Enabler';
            }
        } else {
            $results['not_active'][] = 'Cache Enabler';
        }

        // Clear Hummingbird Cache
        if ($this->is_plugin_active('hummingbird-performance/wp-hummingbird.php')) {
            if ($this->clear_hummingbird_cache()) {
                $results['cleared'][] = 'Hummingbird';
            } else {
                $results['failed'][] = 'Hummingbird';
            }
        } else {
            $results['not_active'][] = 'Hummingbird';
        }

        // Clear Autoptimize Cache
        if ($this->is_plugin_active('autoptimize/autoptimize.php')) {
            if ($this->clear_autoptimize_cache()) {
                $results['cleared'][] = 'Autoptimize';
            } else {
                $results['failed'][] = 'Autoptimize';
            }
        } else {
            $results['not_active'][] = 'Autoptimize';
        }

        // Clear SG Optimizer (SiteGround)
        if ($this->is_plugin_active('sg-cachepress/sg-cachepress.php')) {
            if ($this->clear_sg_optimizer_cache()) {
                $results['cleared'][] = 'SG Optimizer';
            } else {
                $results['failed'][] = 'SG Optimizer';
            }
        } else {
            $results['not_active'][] = 'SG Optimizer';
        }

        // Clear Comet Cache
        if ($this->is_plugin_active('comet-cache/comet-cache.php')) {
            if ($this->clear_comet_cache()) {
                $results['cleared'][] = 'Comet Cache';
            } else {
                $results['failed'][] = 'Comet Cache';
            }
        } else {
            $results['not_active'][] = 'Comet Cache';
        }

        // Clear Swift Performance
        if ($this->is_plugin_active('swift-performance-lite/performance.php') || 
            $this->is_plugin_active('swift-performance/performance.php')) {
            if ($this->clear_swift_performance_cache()) {
                $results['cleared'][] = 'Swift Performance';
            } else {
                $results['failed'][] = 'Swift Performance';
            }
        } else {
            $results['not_active'][] = 'Swift Performance';
        }

        // Clear Kinsta Cache (hosting-level) — respects the "Hosting Cache Integration" setting
        $hosting_settings = get_option('metasync_hosting_cache_options', array('kinsta_enabled' => true, 'wpengine_enabled' => true));
        if (!isset($hosting_settings['kinsta_enabled'])) {
            $hosting_settings['kinsta_enabled'] = true;
        }
        if (!isset($hosting_settings['wpengine_enabled'])) {
            $hosting_settings['wpengine_enabled'] = true;
        }

        if (!empty($hosting_settings['kinsta_enabled'])) {
            if (class_exists('KinstaCache')) {
                if ($this->clear_kinsta_cache()) {
                    $results['cleared'][] = 'Kinsta Cache';
                } else {
                    $results['failed'][] = 'Kinsta Cache';
                }
            } else {
                $results['not_active'][] = 'Kinsta Cache';
            }
        }

        // Clear WP Engine Cache (hosting-level) — respects the "Hosting Cache Integration" setting
        if (!empty($hosting_settings['wpengine_enabled'])) {
            if (class_exists('WpeCommon')) {
                if ($this->clear_wpengine_cache()) {
                    $results['cleared'][] = 'WP Engine Cache';
                } else {
                    $results['failed'][] = 'WP Engine Cache';
                }
            } else {
                $results['not_active'][] = 'WP Engine Cache';
            }
        }

        // Log results
        if (!empty($results['cleared'])) {
            error_log('MetaSync Cache Purge (' . $source . '): Cleared ' . implode(', ', $results['cleared']));
        }

        return $results;
    }

    /**
     * Clear cache for a specific URL or post
     * 
     * @param string|int $url_or_post_id URL or Post ID
     * @return bool Success status
     */
    public function clear_url_cache($url_or_post_id)
    {
        $success = false;

        // Determine if it's a URL or Post ID
        if (is_numeric($url_or_post_id)) {
            $post_id = intval($url_or_post_id);
            $url = get_permalink($post_id);
        } else {
            $url = $url_or_post_id;
            // Strip tracking query parameters before resolving post ID so
            // url_to_postid() receives a clean canonical URL
            $url = self::normalize_url($url);
            $post_id = url_to_postid($url);
        }

        // WP Rocket - Clear specific URL.
        // Prefer rocket_clean_url() (URL-based, WP Rocket 3+) so custom post types
        // and URLs that url_to_postid() can't resolve are still purged correctly.
        if ($this->is_plugin_active('wp-rocket/wp-rocket.php')) {
            try {
                if ($url && function_exists('rocket_clean_url')) {
                    rocket_clean_url($url);
                    $success = true;
                } elseif ($post_id && function_exists('rocket_clean_post')) {
                    rocket_clean_post($post_id);
                    $success = true;
                }
            } catch (Exception $e) {
                error_log('MetaSync: WP Rocket per-URL purge failed - ' . $e->getMessage());
            }
        }

        // LiteSpeed Cache - Purge specific URL
        if ($this->is_plugin_active('litespeed-cache/litespeed-cache.php')) {
            try {
                if ($post_id && class_exists('LiteSpeed\\Purge')) {
                    do_action('litespeed_purge_post', $post_id);
                    $success = true;
                }
            } catch (Exception $e) {
                error_log('MetaSync: LiteSpeed per-URL purge failed - ' . $e->getMessage());
            }
        }

        // W3 Total Cache - Flush specific post
        if ($this->is_plugin_active('w3-total-cache/w3-total-cache.php')) {
            try {
                if ($post_id && function_exists('w3tc_flush_post')) {
                    w3tc_flush_post($post_id);
                    $success = true;
                }
            } catch (Exception $e) {
                error_log('MetaSync: W3 Total Cache per-URL purge failed - ' . $e->getMessage());
            }
        }

        // NitroPack - Purge specific URL cache (both local and remote cache)
        if ($this->is_plugin_active('nitropack/main.php')) {
            try {
                if ($url && function_exists('nitropack_sdk_purge')) {
                    if (nitropack_sdk_purge($url, NULL, 'MetaSync OTTO cache clear', \NitroPack\SDK\PurgeType::COMPLETE)) {
                        $success = true;
                    }
                }
            } catch (Exception $e) {
                error_log('MetaSync: NitroPack per-URL purge failed - ' . $e->getMessage());
            }
        }

        // WP Super Cache - Purge specific URL
        if ($this->is_plugin_active('wp-super-cache/wp-cache.php')) {
            try {
                if ($url && function_exists('wpsc_delete_url_cache')) {
                    wpsc_delete_url_cache($url);
                    $success = true;
                } elseif ($url && function_exists('prune_super_cache')) {
                    // Fallback: derive cache file path from the URL
                    $url_path = wp_parse_url($url, PHP_URL_PATH);
                    if ($url_path) {
                        $cache_path = trailingslashit(WP_CONTENT_DIR . '/cache/supercache/' . wp_parse_url($url, PHP_URL_HOST) . $url_path);
                        prune_super_cache($cache_path, true);
                        $success = true;
                    }
                }
            } catch (Exception $e) {
                error_log('MetaSync: WP Super Cache per-URL purge failed - ' . $e->getMessage());
            }
        }

        // WP Fastest Cache - Purge specific post cache
        // singleDeleteCache($comment_id, $post_id) requires a post ID, not URL
        if ($this->is_plugin_active('wp-fastest-cache/wpFastestCache.php')) {
            try {
                $wpfc_purged = false;
                if ($post_id && class_exists('WpFastestCache')) {
                    $wpfc = new \WpFastestCache();
                    if (method_exists($wpfc, 'singleDeleteCache')) {
                        $wpfc->singleDeleteCache(false, $post_id);
                        $wpfc_purged = true;
                        $success = true;
                    }
                }
                if (!$wpfc_purged && $url) {
                    do_action('wpfc_clear_post_cache_by_url', $url);
                    $success = true;
                }
            } catch (Exception $e) {
                error_log('MetaSync: WP Fastest Cache per-URL purge failed - ' . $e->getMessage());
            }
        }

        // Cache Enabler - Purge specific URL
        if ($this->is_plugin_active('cache-enabler/cache-enabler.php')) {
            try {
                if ($url) {
                    do_action('cache_enabler_clear_page_cache_by_url', $url);
                    $success = true;
                }
            } catch (Exception $e) {
                error_log('MetaSync: Cache Enabler per-URL purge failed - ' . $e->getMessage());
            }
        }

        // Hummingbird - Purge specific URL/post
        if ($this->is_plugin_active('hummingbird-performance/wp-hummingbird.php')) {
            try {
                $hb_purged = false;
                if ($post_id) {
                    do_action('wphb_clear_page_cache', $post_id);
                    $hb_purged = true;
                    $success = true;
                }
                if (!$hb_purged && class_exists('Hummingbird\\WP_Hummingbird')) {
                    $modules = \Hummingbird\WP_Hummingbird::get_instance()->core->modules;
                    if (isset($modules['page_cache']) && method_exists($modules['page_cache'], 'clear_cache')) {
                        $modules['page_cache']->clear_cache();
                        $success = true;
                    }
                }
            } catch (Exception $e) {
                error_log('MetaSync: Hummingbird per-URL purge failed - ' . $e->getMessage());
            }
        }

        // SG Optimizer - Purge specific URL
        if ($this->is_plugin_active('sg-cachepress/sg-cachepress.php')) {
            try {
                if ($url && function_exists('sg_cachepress_purge_cache')) {
                    sg_cachepress_purge_cache($url);
                    $success = true;
                } else {
                    do_action('sg_cachepress_purge_cache');
                    $success = true;
                }
            } catch (Exception $e) {
                error_log('MetaSync: SG Optimizer per-URL purge failed - ' . $e->getMessage());
            }
        }

        // Comet Cache - Purge specific URL/post cache
        if ($this->is_plugin_active('comet-cache/comet-cache.php') && class_exists('comet_cache')) {
            try {
                if ($url && method_exists('comet_cache', 'clearUrl')) {
                    comet_cache::clearUrl($url);
                    $success = true;
                } elseif ($post_id && method_exists('comet_cache', 'clearPost')) {
                    comet_cache::clearPost($post_id);
                    $success = true;
                }
            } catch (Exception $e) {
                error_log('MetaSync: Comet Cache per-URL purge failed - ' . $e->getMessage());
            }
        }

        // Swift Performance - Purge specific post cache
        if ($this->is_plugin_active('swift-performance-lite/performance.php') ||
            $this->is_plugin_active('swift-performance/performance.php')) {
            try {
                if ($post_id && class_exists('Swift_Performance_Cache')) {
                    Swift_Performance_Cache::clear_post_cache($post_id);
                    $success = true;
                }
            } catch (Exception $e) {
                error_log('MetaSync: Swift Performance per-URL purge failed - ' . $e->getMessage());
            }
        }

        // Autoptimize - Skipped for per-URL purge (only caches JS/CSS assets, not page HTML)

        // Kinsta - Purge specific URL (hosting-level cache, no plugin check needed)
        if ($url && class_exists('KinstaCache')) {
            try {
                KinstaCache::get_instance()->kinsta_cache_purge_single_url($url);
                $success = true;
            } catch (Exception $e) {
                error_log('MetaSync: Kinsta per-URL purge failed - ' . $e->getMessage());
            }
        }

        // WP Engine - Purge specific URL (hosting-level cache, no plugin check needed)
        if ($url && class_exists('WpeCommon')) {
            try {
                WpeCommon::purge_url($url);
                $success = true;
            } catch (Exception $e) {
                error_log('MetaSync: WP Engine per-URL purge failed - ' . $e->getMessage());
            }
        }

        return $success;
    }

    /**
     * Clear WordPress built-in object cache
     */
    private function clear_wordpress_cache()
    {
        try {
            wp_cache_flush();
            return true;
        } catch (Exception $e) {
            $this->log_cache_error('WordPress', $e);
            return false;
        }
    }

    /**
     * Clear WP Rocket cache
     */
    private function clear_wp_rocket_cache()
    {
        try {
            // Clear all cache
            if (function_exists('rocket_clean_domain')) {
                rocket_clean_domain();
                return true;
            }

            // Fallback: Clear minified CSS/JS
            if (function_exists('rocket_clean_minify')) {
                rocket_clean_minify();
            }

            // Clear cache via action hook
            do_action('rocket_clean_domain');
            
            return true;
        } catch (Exception $e) {
            $this->log_cache_error('WP Rocket', $e);
            return false;
        }
    }

    /**
     * Clear LiteSpeed Cache
     */
    private function clear_litespeed_cache()
    {
        try {
            // Method 1: Use LiteSpeed purge action
            do_action('litespeed_purge_all');

            // Method 2: Use LiteSpeed class if available
            if (class_exists('LiteSpeed\Purge')) {
                \LiteSpeed\Purge::purge_all();
            }

            // Method 3: Set header for LiteSpeed server
            if (!headers_sent()) {
                header('X-LiteSpeed-Purge: *');
            }

            return true;
        } catch (Exception $e) {
            $this->log_cache_error('LiteSpeed', $e);
            return false;
        }
    }

    /**
     * Clear W3 Total Cache
     */
    private function clear_w3_total_cache()
    {
        try {
            // Method 1: Use W3TC functions
            if (function_exists('w3tc_flush_all')) {
                w3tc_flush_all();
                return true;
            }

            // Method 2: Use W3TC class
            if (class_exists('W3_Plugin_TotalCache')) {
                $w3_plugin_totalcache = w3_instance('W3_Plugin_TotalCache');
                if (method_exists($w3_plugin_totalcache, 'flush_all')) {
                    $w3_plugin_totalcache->flush_all();
                    return true;
                }
            }

            // Method 3: Direct cache directory deletion
            if (function_exists('w3tc_pgcache_flush')) {
                w3tc_pgcache_flush();
            }
            if (function_exists('w3tc_dbcache_flush')) {
                w3tc_dbcache_flush();
            }
            if (function_exists('w3tc_minify_flush')) {
                w3tc_minify_flush();
            }
            if (function_exists('w3tc_objectcache_flush')) {
                w3tc_objectcache_flush();
            }

            return true;
        } catch (Exception $e) {
            $this->log_cache_error('W3 Total Cache', $e);
            return false;
        }
    }

    /**
     * Clear WP Super Cache
     */
    private function clear_wp_super_cache()
    {
        try {
            global $file_prefix;

            if (function_exists('wp_cache_clean_cache')) {
                wp_cache_clean_cache($file_prefix, true);
                return true;
            }

            // Alternative method
            if (function_exists('wp_cache_clear_cache')) {
                wp_cache_clear_cache();
                return true;
            }

            // Manual deletion
            if (function_exists('prune_super_cache')) {
                prune_super_cache(WP_CONTENT_DIR . '/cache/', true);
                return true;
            }

            return false;
        } catch (Exception $e) {
            $this->log_cache_error('WP Super Cache', $e);
            return false;
        }
    }

    /**
     * Clear WP Fastest Cache
     */
    private function clear_wp_fastest_cache()
    {
        try {
            if (class_exists('WpFastestCache')) {
                $wpfc = new WpFastestCache();
                if (method_exists($wpfc, 'deleteCache')) {
                    $wpfc->deleteCache(true);
                    return true;
                }
            }

            // Try action hook
            do_action('wpfc_clear_all_cache');

            return true;
        } catch (Exception $e) {
            $this->log_cache_error('WP Fastest Cache', $e);
            return false;
        }
    }

    /**
     * Clear Cache Enabler
     */
    private function clear_cache_enabler()
    {
        try {
            if (class_exists('Cache_Enabler')) {
                if (method_exists('Cache_Enabler', 'clear_complete_cache')) {
                    \Cache_Enabler::clear_complete_cache();
                    return true;
                }
            }

            // Try action hook
            do_action('cache_enabler_clear_complete_cache');

            return true;
        } catch (Exception $e) {
            $this->log_cache_error('Cache Enabler', $e);
            return false;
        }
    }

    /**
     * Clear Hummingbird Cache
     */
    private function clear_hummingbird_cache()
    {
        try {
            if (class_exists('Hummingbird\\WP_Hummingbird')) {
                $modules = \Hummingbird\WP_Hummingbird::get_instance()->core->modules;
                if (isset($modules['page_cache'])) {
                    $modules['page_cache']->clear_cache();
                    return true;
                }
            }

            // Try action hook
            do_action('wphb_clear_page_cache');

            return true;
        } catch (Exception $e) {
            $this->log_cache_error('Hummingbird', $e);
            return false;
        }
    }

    /**
     * Clear Autoptimize Cache
     */
    private function clear_autoptimize_cache()
    {
        try {
            if (class_exists('autoptimizeCache')) {
                autoptimizeCache::clearall();
                return true;
            }

            // Try action hook
            do_action('autoptimize_action_cachepurged');

            return true;
        } catch (Exception $e) {
            $this->log_cache_error('Autoptimize', $e);
            return false;
        }
    }

    /**
     * Clear SG Optimizer (SiteGround) Cache
     */
    private function clear_sg_optimizer_cache()
    {
        try {
            if (function_exists('sg_cachepress_purge_cache')) {
                sg_cachepress_purge_cache();
                return true;
            }

            // Try action hook
            do_action('sg_cachepress_purge_cache');

            return true;
        } catch (Exception $e) {
            $this->log_cache_error('SG Optimizer', $e);
            return false;
        }
    }

    /**
     * Clear Comet Cache
     */
    private function clear_comet_cache()
    {
        try {
            if (class_exists('comet_cache')) {
                comet_cache::clear();
                return true;
            }

            // Try action hook
            do_action('comet_cache_clear');

            return true;
        } catch (Exception $e) {
            $this->log_cache_error('Comet Cache', $e);
            return false;
        }
    }

    /**
     * Clear Swift Performance Cache
     */
    private function clear_swift_performance_cache()
    {
        try {
            if (class_exists('Swift_Performance_Cache')) {
                Swift_Performance_Cache::clear_all_cache();
                return true;
            }

            // Try action hook
            do_action('swift_performance_clear_all_cache');

            return true;
        } catch (Exception $e) {
            $this->log_cache_error('Swift Performance', $e);
            return false;
        }
    }

    /**
     * Clear Kinsta hosting-level full-page cache (entire site)
     */
    private function clear_kinsta_cache()
    {
        try {
            KinstaCache::get_instance()->kinsta_cache_purge_full();
            return true;
        } catch (Exception $e) {
            $this->log_cache_error('Kinsta', $e);
            return false;
        }
    }

    /**
     * Clear WP Engine hosting-level cache (Varnish + memcached)
     */
    private function clear_wpengine_cache()
    {
        try {
            WpeCommon::purge_varnish_cache();
            WpeCommon::purge_memcached();
            return true;
        } catch (Exception $e) {
            $this->log_cache_error('WP Engine', $e);
            return false;
        }
    }

    /**
     * Static wrapper: clear all caches with try-catch
     *
     * @param string $source Source identifier for logging
     */
    public static function purge_all($source = 'metasync')
    {
        try {
            self::get_instance()->clear_all_caches($source);
        } catch (Exception $e) {
            error_log('MetaSync: Cache purge failed (' . $source . ') - ' . $e->getMessage());
        }
    }

    /**
     * Static wrapper: clear cache for a single URL with try-catch
     *
     * @param string $url URL to purge
     */
    public static function purge_single_url($url)
    {
        try {
            self::get_instance()->clear_url_cache($url);
        } catch (Exception $e) {
            error_log('MetaSync: URL cache purge failed - ' . $e->getMessage());
        }
    }

    /**
     * Warm cache for multiple URLs after a purge.
     *
     * Sends anonymous fire-and-forget requests so that our code — with fresh OTTO
     * data already in transients and post meta — is the first to populate each URL
     * in the hosting cache. Without this, the first external visitor (or the host's
     * own cache warmer) could re-cache a stale version before OTTO SSR has run.
     *
     * Capped at $max URLs per call to prevent excessive server load on large batches.
     *
     * @param array $urls List of URLs to warm
     * @param int   $max  Maximum URLs to warm per invocation (default 10)
     */
    public static function warm_urls(array $urls, $max = 10)
    {
        $count = 0;
        foreach ($urls as $url) {
            if (++$count > $max) {
                break;
            }
            self::warm_url($url);
        }
    }

    /**
     * Send a single anonymous, non-blocking HTTP GET to a URL to prime the cache.
     *
     * The request carries no auth cookies, so the hosting layer (Kinsta, WP Engine, etc.)
     * treats it as an anonymous visitor and caches the PHP-rendered response.
     * OTTO SSR runs during this request and modifies the HTML before it is stored.
     *
     * Uses blocking=false so the pixel webhook returns immediately; the host
     * continues processing the request asynchronously.
     *
     * @param string $url URL to warm
     */
    public static function warm_url($url)
    {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return;
        }

        // Strip tracking query parameters so cache warming targets the canonical URL
        $url = self::normalize_url($url);

        wp_remote_get($url, array(
            'blocking'    => false,     // Fire-and-forget: don't wait for response
            'timeout'     => 3,         // 3s to establish connection + send headers reliably
                                        // 0.01s was too short: TCP handshake could fail on loaded servers,
                                        // causing warm requests to silently drop before the request was sent.
                                        // blocking=false means we still don't wait for the response body.
            'redirection' => 0,         // No redirect following needed
            'headers'     => array(
                'X-MetaSync-Cache-Warm' => '1',
                'User-Agent'            => 'MetaSync-Cache-Warmer/1.0',
            ),
            'cookies'     => array(),   // No cookies = anonymous visitor = host will cache result
            'sslverify'   => false,     // Avoid SSL issues on staging/local environments
        ));
    }

    /**
     * Strip known tracking/analytics query parameters from a URL.
     *
     * CDNs treat `/page/` and `/page/?utm_source=email` as separate cache entries.
     * Normalizing before purge/warm ensures we always target the canonical URL.
     * Non-tracking parameters (e.g. `?tab=pricing`) are preserved.
     *
     * @param string $url The URL to normalize
     * @return string The URL with tracking parameters removed
     */
    private static function normalize_url($url)
    {
        if (empty($url) || strpos($url, '?') === false) {
            return $url;
        }

        $tracking_params = array(
            // Google Analytics / Ads
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'gclid', 'gbraid', 'wbraid', 'dclid',
            // Google Analytics client IDs
            '_ga', '_gl',
            // Meta (Facebook / Instagram)
            'fbclid', 'igshid',
            // Microsoft Ads
            'msclkid',
            // TikTok
            'ttclid',
            // Twitter / X
            'twclid',
            // LinkedIn
            'li_fat_id',
            // HubSpot
            'mc_eid', '_hsenc', '_hsmi',
            // Yandex
            'yclid',
            // Zanox / Awin
            'zanpid',
            // Adobe Analytics
            's_kwcid',
        );

        return remove_query_arg($tracking_params, $url);
    }

    /**
     * Log a cache clear failure consistently
     *
     * @param string    $plugin_name Human-readable plugin name
     * @param Exception $e           The caught exception
     */
    private function log_cache_error($plugin_name, $e)
    {
        error_log('MetaSync: Failed to clear ' . $plugin_name . ' cache - ' . $e->getMessage());
    }

    /**
     * Check if a plugin is active
     *
     * @param string $plugin_path Plugin path (folder/file.php)
     * @return bool
     */
    private function is_plugin_active($plugin_path)
    {
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        return is_plugin_active($plugin_path);
    }

    /**
     * Get list of active cache plugins
     * 
     * @return array List of active cache plugin names
     */
    public function get_active_cache_plugins()
    {
        $cache_plugins = array(
            'wp-rocket/wp-rocket.php' => 'WP Rocket',
            'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
            'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
            'wp-super-cache/wp-cache.php' => 'WP Super Cache',
            'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
            'cache-enabler/cache-enabler.php' => 'Cache Enabler',
            'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
            'autoptimize/autoptimize.php' => 'Autoptimize',
            'sg-cachepress/sg-cachepress.php' => 'SG Optimizer',
            'comet-cache/comet-cache.php' => 'Comet Cache',
            'swift-performance-lite/performance.php' => 'Swift Performance Lite',
            'swift-performance/performance.php' => 'Swift Performance',
        );

        $active = array();
        foreach ($cache_plugins as $plugin_path => $plugin_name) {
            if ($this->is_plugin_active($plugin_path)) {
                $active[$plugin_path] = $plugin_name;
            }
        }

        return $active;
    }
}



