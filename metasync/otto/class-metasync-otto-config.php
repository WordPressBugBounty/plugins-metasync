<?php
/**
 * MetaSync Otto Configuration Cache
 *
 * PERFORMANCE OPTIMIZATION: This class provides static caching of plugin options
 * to avoid repeated get_option() calls within the same request.
 *
 * Expected improvement: 75% reduction in option loading queries
 *
 * @package    Metasync
 * @subpackage Otto
 * @since      2.5.16
 */

if (!defined('ABSPATH')) {
    exit; # Exit if accessed directly
}

class Metasync_Otto_Config {
    /**
     * Cached options array
     * @var array|null
     */
    private static $options = null;

    /**
     * Flag to track if options have been loaded
     * @var bool
     */
    private static $loaded = false;

    /**
     * Get all MetaSync options (cached)
     *
     * @return array The cached options array
     */
    public static function get_options() {
        if (!self::$loaded) {
            self::$options = get_option('metasync_options');
            self::$loaded = true;
        }
        return self::$options;
    }

    /**
     * Get Otto Pixel UUID
     *
     * @return string The Otto UUID or empty string
     */
    public static function get_otto_uuid() {
        $options = self::get_options();
        return $options['general']['otto_pixel_uuid'] ?? '';
    }

    /**
     * Check if Otto is enabled
     *
     * @return bool True if Otto UUID is configured
     */
    public static function is_otto_enabled() {
        return !empty(self::get_otto_uuid());
    }

    /**
     * Check if Otto is disabled for logged-in users
     *
     * @return bool True if Otto should be disabled for logged-in users
     */
    public static function is_disabled_for_loggedin() {
        $options = self::get_options();
        return !empty($options['general']['otto_disable_on_loggedin']) &&
               $options['general']['otto_disable_on_loggedin'] === 'true';
    }

    /**
     * Get WP Rocket compatibility mode setting
     *
     * @return string Compatibility mode: 'auto', 'disabled', or 'forced'
     */
    public static function get_wp_rocket_compat_mode() {
        $options = self::get_options();
        return $options['general']['otto_wp_rocket_compat'] ?? 'auto';
    }

    /**
     * Check if meta descriptions are enabled
     *
     * @return bool True if meta descriptions are enabled
     */
    public static function is_meta_description_enabled() {
        $options = self::get_options();
        $enable_metadesc = $options['general']['enable_metadesc'] ?? '';
        return $enable_metadesc === 'true' || $enable_metadesc === true;
    }

    /**
     * Get a specific option value by path
     *
     * @param string $path Dot-notation path to option (e.g., 'general.otto_pixel_uuid')
     * @param mixed $default Default value if not found
     * @return mixed The option value or default
     */
    public static function get($path, $default = null) {
        $options = self::get_options();
        $keys = explode('.', $path);

        $value = $options;
        foreach ($keys as $key) {
            if (!isset($value[$key])) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Clear the cached options (useful for testing or when options change)
     *
     * @return void
     */
    public static function clear_cache() {
        self::$options = null;
        self::$loaded = false;
    }
}
