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
            $post_id = url_to_postid($url);
        }

        // WP Rocket - Clear specific URL
        if ($this->is_plugin_active('wp-rocket/wp-rocket.php') && function_exists('rocket_clean_post')) {
            if ($post_id) {
                rocket_clean_post($post_id);
                $success = true;
            }
        }

        // LiteSpeed Cache - Purge specific URL
        if ($this->is_plugin_active('litespeed-cache/litespeed-cache.php') && class_exists('LiteSpeed\Purge')) {
            if ($post_id) {
                do_action('litespeed_purge_post', $post_id);
                $success = true;
            }
        }

        // W3 Total Cache - Flush specific post
        if ($this->is_plugin_active('w3-total-cache/w3-total-cache.php') && function_exists('w3tc_flush_post')) {
            if ($post_id) {
                w3tc_flush_post($post_id);
                $success = true;
            }
        }

        # NitroPack - Purge specific URL cache (both local and remote cache)
        if ($this->is_plugin_active('nitropack/main.php') && function_exists('nitropack_sdk_purge')) {
            try {
                if ($url) {
                    if (nitropack_sdk_purge($url, NULL, 'MetaSync OTTO cache clear', \NitroPack\SDK\PurgeType::COMPLETE)) {
                        $success = true;
                    }
                }
            } catch (Exception $e) {
                # Silently fail - NitroPack may not be available
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
            error_log('MetaSync: Failed to clear WordPress cache - ' . $e->getMessage());
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
            error_log('MetaSync: Failed to clear WP Rocket cache - ' . $e->getMessage());
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
            error_log('MetaSync: Failed to clear LiteSpeed cache - ' . $e->getMessage());
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
            error_log('MetaSync: Failed to clear W3 Total Cache - ' . $e->getMessage());
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
            error_log('MetaSync: Failed to clear WP Super Cache - ' . $e->getMessage());
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
            error_log('MetaSync: Failed to clear WP Fastest Cache - ' . $e->getMessage());
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
            error_log('MetaSync: Failed to clear Cache Enabler - ' . $e->getMessage());
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
            error_log('MetaSync: Failed to clear Hummingbird cache - ' . $e->getMessage());
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
            error_log('MetaSync: Failed to clear Autoptimize cache - ' . $e->getMessage());
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
            error_log('MetaSync: Failed to clear SG Optimizer cache - ' . $e->getMessage());
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
            error_log('MetaSync: Failed to clear Comet Cache - ' . $e->getMessage());
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
            error_log('MetaSync: Failed to clear Swift Performance cache - ' . $e->getMessage());
            return false;
        }
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



