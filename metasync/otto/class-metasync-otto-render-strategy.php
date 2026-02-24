<?php
/**
 * OTTO Render Strategy Manager
 * Implements hybrid approach: Output Buffer (fast) with wp_remote_get fallback
 * 
 * Features:
 * - Automatic method selection based on environment
 * - Output buffer approach (eliminates internal HTTP request)
 * - Fallback to wp_remote_get for problematic environments
 * - Response headers indicating method used
 * - Detection of caching plugins and managed hosts
 * 
 * @since 2.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Otto_Render_Strategy {

    /**
     * Render method constants
     */
    const METHOD_BUFFER = 'buffer';
    const METHOD_HTTP = 'http';
    const METHOD_NONE = 'none';

    /**
     * Current render method being used
     */
    private static $current_method = null;

    /**
     * Buffer level when OTTO started
     */
    private static $buffer_start_level = null;

    /**
     * Flag to track if buffer is active
     */
    private static $buffer_active = false;

    /**
     * OTTO suggestions data (stored for buffer approach)
     */
    private static $pending_suggestions = null;

    /**
     * Current route (stored for buffer approach)
     */
    private static $pending_route = null;

    /**
     * OTTO HTML processor instance
     */
    private static $otto_html = null;

    /**
     * Blocking flags for SEO plugins
     */
    private static $blocking_flags = null;

    /**
     * Determine the best render method for current environment
     * 
     * @return string METHOD_BUFFER or METHOD_HTTP
     */
    public static function determine_method() {
        # Skip buffer for internal fetch requests (avoid infinite loop)
        if (!empty($_GET['is_otto_page_fetch'])) {
            return self::METHOD_NONE;
        }

        # Skip for admin, AJAX, REST API
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return self::METHOD_NONE;
        }

        # Check for known problematic configurations that need HTTP method
        if (self::should_use_http_method()) {
            return self::METHOD_HTTP;
        }

        # Check if output buffering is available and safe to use
        if (self::is_buffer_available()) {
            return self::METHOD_BUFFER;
        }

        # Fallback to HTTP method
        return self::METHOD_HTTP;
    }

    /**
     * Check if output buffering is available and safe
     * 
     * @return bool
     */
    private static function is_buffer_available() {
        # Check if output buffering is enabled
        if (!function_exists('ob_start') || !function_exists('ob_get_clean')) {
            return false;
        }

        # Check buffer nesting level (too many nested buffers = risky)
        $current_level = ob_get_level();
        if ($current_level > 5) {
            return false;
        }

        # Check if headers already sent (can't use buffer properly)
        if (headers_sent($file, $line)) {
            return false;
        }

        # Check memory limit (need enough memory to buffer page)
        $memory_limit = self::get_memory_limit_bytes();
        $memory_used = memory_get_usage(true);
        $memory_available = $memory_limit - $memory_used;
        
        # Need at least 16MB available for buffering
        if ($memory_available < 16 * 1024 * 1024) {
            return false;
        }

        return true;
    }

    /**
     * Check if HTTP method should be forced
     * 
     * @return bool True if HTTP method should be used
     */
    private static function should_use_http_method() {
        # Force HTTP method via constant (for debugging/testing)
        if (defined('METASYNC_OTTO_FORCE_HTTP') && METASYNC_OTTO_FORCE_HTTP) {
            return true;
        }

        # Check for known caching plugins that may conflict with output buffering
        $conflicting_plugins = [
            # Heavy output buffering plugins
            'WP_Rocket' => class_exists('WP_Rocket'),
            'W3TC' => defined('W3TC'),
            'LiteSpeed_Cache' => defined('LSCWP_V'),

            # Page builders with aggressive buffering
            'Brizy_Editor' => class_exists('Brizy_Editor'),
            'Oxygen_Builder' => defined('CT_VERSION'), # Oxygen manipulates wp_current_filter causing CSS loading issues in PHP 8.3+

            # Optimization plugins
            'Autoptimize' => class_exists('autoptimizeMain'),
        ];

        foreach ($conflicting_plugins as $name => $is_active) {
            if ($is_active) {
                # OXYGEN BUILDER: Always use HTTP method due to wp_current_filter manipulation
                # Oxygen manipulates $wp_current_filter during wp_head which breaks CSS loading in PHP 8.3+
                # HTTP method bypasses the buffer and avoids the conflict entirely
                if ($name === 'Oxygen_Builder') {
                    return true;
                }

                # For logged-in users, buffer is usually safe even with these plugins
                if (is_user_logged_in()) {
                    continue;
                }

                # For non-logged-in users with caching plugins, check if page caching is active
                if ($name === 'WP_Rocket') {
                    # Get WP Rocket compatibility configuration
                    global $metasync_options;
                    $wp_rocket_compat_mode = $metasync_options['general']['otto_wp_rocket_compat'] ?? 'auto';

                    # Force HTTP if user selected it
                    if ($wp_rocket_compat_mode === 'http') {
                        return true; # Use HTTP method for maximum compatibility
                    }

                    # Force buffer if user selected it
                    if ($wp_rocket_compat_mode === 'buffer') {
                        continue; # Use buffer method for speed
                    }

                    # For auto mode, use buffer if DONOTCACHEPAGE is not set
                    # This allows WP Rocket to continue optimizing the page
                    if ($wp_rocket_compat_mode === 'auto') {
                        # If DONOTCACHEPAGE is not set, WP Rocket can optimize freely
                        # Buffer is safe in this case
                        continue;
                    }

                    # If DONOTCACHEPAGE is set (Brizy/SG Optimizer), buffer is also safe
                    if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
                        continue; # Cache disabled for this page, buffer is safe
                    }

                    # Default fallback: continue to use buffer
                    continue;
                }

                # Default: use HTTP method for safety with caching plugins
                // Removed logging to reduce noise - uncomment for debugging

                # Actually, let's try buffer first for most cases
                # Only force HTTP for specific known issues
            }
        }

        # Check for managed hosts that may have issues
        # WP Engine detection
        if (defined('WPE_APIKEY') || getenv('IS_WPE')) {
            # WP Engine serves cached pages at edge, but for uncached requests, buffer works
            if (!is_user_logged_in() && !defined('DONOTCACHEPAGE')) {
                # This might be a cached request that WP Engine serves from edge
                # However, if we're here, PHP is executing, so buffer should work
            }
        }

        # Kinsta detection
        if (defined('KINSTAMU_VERSION') || getenv('KINSTA_CACHE_ZONE')) {
            # Similar to WP Engine - if PHP is executing, buffer should work
        }

        # Cloudways Varnish
        if (isset($_SERVER['HTTP_X_VARNISH'])) {
            # Varnish is in front, but if we're here, request reached PHP
        }

        # Flywheel detection
        if (defined('FLYWHEEL_CONFIG_DIR')) {
            # Flywheel caching similar to WP Engine
        }

        # No known conflicts detected
        return false;
    }

    /**
     * Get memory limit in bytes
     * 
     * @return int Memory limit in bytes
     */
    private static function get_memory_limit_bytes() {
        $limit = ini_get('memory_limit');
        
        if ($limit === '-1') {
            return PHP_INT_MAX; # Unlimited
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }

    /**
     * Start output buffer for OTTO processing
     * Called early in WordPress lifecycle (template_redirect)
     * 
     * @param array $suggestions OTTO suggestions data
     * @param string $route Current page route
     * @param Metasync_otto_html $otto_html OTTO HTML processor
     * @param array $blocking_flags SEO plugin blocking flags
     * @return bool True if buffer started successfully
     */
    public static function start_buffer($suggestions, $route, $otto_html, $blocking_flags = []) {
        if (self::$buffer_active) {
            return false; # Already buffering
        }

        # Store data for later processing
        self::$pending_suggestions = $suggestions;
        self::$pending_route = $route;
        self::$otto_html = $otto_html;
        self::$blocking_flags = $blocking_flags;
        self::$buffer_start_level = ob_get_level();
        self::$current_method = self::METHOD_BUFFER;

        # Start output buffering with our callback
        $started = ob_start([__CLASS__, 'process_buffer']);

        if ($started) {
            self::$buffer_active = true;
            
            # Register shutdown handler to ensure buffer is processed
            register_shutdown_function([__CLASS__, 'shutdown_handler']);
            
            return true;
        }

        # Buffer failed to start, mark for HTTP fallback
        self::$current_method = self::METHOD_HTTP;
        return false;
    }

    /**
     * Process the captured output buffer
     * This is the callback for ob_start()
     * 
     * CRITICAL: This callback MUST return the HTML string (original or modified)
     * If this fails, WordPress will show a blank page. Always return $html as fallback.
     * 
     * @param string $html The captured HTML
     * @return string Modified HTML
     */
    public static function process_buffer($html) {
        # SAFETY FIRST: If anything goes wrong, always return original HTML
        # Never throw or cause errors - this would break the page
        
        
        try {
            # Skip processing if no suggestions or empty HTML
            if (empty(self::$pending_suggestions) || empty($html)) {
                return $html;
            }

            # Skip if this doesn't look like HTML (error page, JSON response, etc.)
            if (stripos($html, '<html') === false && stripos($html, '<!DOCTYPE') === false) {
                return $html;
            }

            # Skip if this is a partial/AJAX response or error message
            if (strlen($html) < 100) {
                return $html;
            }

            # Skip if this looks like an error page (WordPress fatal error)
            if (stripos($html, 'Fatal error') !== false || stripos($html, 'Parse error') !== false) {
                return $html;
            }


            # Process the HTML with OTTO modifications
            $modified_html = self::apply_otto_modifications($html);
            
            
            if ($modified_html !== false && !empty($modified_html)) {
                # Validate the modified HTML isn't corrupted
                if (strlen($modified_html) > strlen($html) * 0.5) {
                    # Modified HTML is at least 50% of original size - seems valid

                    # DEBUG: Check if title was actually changed in the HTML
                    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $modified_html, $matches)) {
                    }

                    return $modified_html;
                } else {
                }
            } else {
            }
        } catch (Exception $e) {
        } catch (Error $e) {
            # PHP 7+ Error (like TypeError, ArgumentCountError, etc.)
        }

        # Return original HTML on any failure - NEVER return empty string
        return $html;
    }

    /**
     * Apply OTTO modifications to HTML
     * 
     * @param string $html Original HTML
     * @return string|false Modified HTML or false on failure
     */
    private static function apply_otto_modifications($html) {
        # Validate prerequisites
        if (!self::$otto_html || !self::$pending_suggestions) {
            return false;
        }

        # Verify the OTTO HTML processor has the required method
        if (!method_exists(self::$otto_html, 'process_html_directly')) {
            return false;
        }

        try {
            # Add blocking flags to suggestions
            $suggestions = self::$pending_suggestions;
            if (!empty(self::$blocking_flags)) {
                $suggestions['_otto_blocking'] = self::$blocking_flags;
            }

            # Process HTML directly (no HTTP request needed!)
            $result = self::$otto_html->process_html_directly($html, $suggestions);

            if ($result) {
                # Result is now an HTML string (not DOM object)
                # Check if it's a string or still a DOM object for backwards compatibility
                $result_string = is_string($result) ? $result : $result->__toString();

                # Apply any post-processing fixes
                $result_string = self::apply_post_processing($result_string);

                return $result_string;
            }
        } catch (Exception $e) {
        } catch (Error $e) {
        }

        return false;
    }

    /**
     * Apply post-processing fixes to modified HTML
     * 
     * @param string $html Modified HTML
     * @return string Processed HTML
     */
    private static function apply_post_processing($html) {
        # Fix for sliding headline layouts
        if (strpos($html, 'pix-sliding-headline-2') !== false || strpos($html, 'pix-intro-sliding-text') !== false) {
            $html = preg_replace(
                '#(</span></span>)(<span\s+class=["\'][^"\']*slide-in-container[^"\']*["\'][^>]*>)#i',
                '$1 $2',
                $html
            );
        }
        
        # Fix for divi-pixel Timeline compatibility
        # jQuery's .data() method auto-parses JSON, but Timeline script tries to JSON.parse it again
        # This causes "[object Object]" is not valid JSON errors
        # Solution: Inject a script that patches jQuery's .data() to return strings for data-config
        if (strpos($html, 'dipi_timeline_item_custom_classes') !== false && strpos($html, 'Timeline.min.js') !== false) {
            # Create a script that ensures data-config is read as a string, not auto-parsed object
            $fix_script = '<script type="text/javascript">' . "\n" .
                '(function() {' . "\n" .
                '    if (typeof jQuery !== "undefined") {' . "\n" .
                '        // Patch jQuery.data() to return string for data-config on timeline items' . "\n" .
                '        var originalData = jQuery.fn.data;' . "\n" .
                '        jQuery.fn.data = function(key, value) {' . "\n" .
                '            // If reading "config" from timeline items, return raw string from attribute' . "\n" .
                '            if (key === "config" && value === undefined && this.length > 0) {' . "\n" .
                '                var $first = jQuery(this[0]);' . "\n" .
                '                if ($first.hasClass("dipi_timeline_item_custom_classes")) {' . "\n" .
                '                    var attrValue = $first.attr("data-config");' . "\n" .
                '                    if (attrValue) {' . "\n" .
                '                        // Decode HTML entities and return as string' . "\n" .
                '                        var tempDiv = document.createElement("div");' . "\n" .
                '                        tempDiv.innerHTML = attrValue;' . "\n" .
                '                        return tempDiv.textContent || tempDiv.innerText || attrValue;' . "\n" .
                '                    }' . "\n" .
                '                }' . "\n" .
                '            }' . "\n" .
                '            // For all other cases, use original jQuery.data()' . "\n" .
                '            return originalData.apply(this, arguments);' . "\n" .
                '        };' . "\n" .
                '    }' . "\n" .
                '})();' . "\n" .
                '</script>';
            
            # Insert before closing body tag (before Timeline script runs)
            $html = preg_replace(
                '/(<\/body>)/i',
                $fix_script . "\n" . '$1',
                $html,
                1
            );
        }

        return $html;
    }

    /**
     * Shutdown handler to ensure buffer is properly processed
     */
    public static function shutdown_handler() {
        if (!self::$buffer_active) {
            return;
        }

        # Get any remaining buffered content
        $remaining_levels = ob_get_level() - self::$buffer_start_level;
        
        while ($remaining_levels > 0) {
            ob_end_flush();
            $remaining_levels--;
        }

        self::$buffer_active = false;
    }

    /**
     * Get the current render method being used
     * 
     * @return string Current method (buffer, http, none)
     */
    public static function get_current_method() {
        return self::$current_method ?: self::METHOD_NONE;
    }

    /**
     * Set the current render method (for HTTP fallback)
     * 
     * @param string $method The method being used
     */
    public static function set_current_method($method) {
        self::$current_method = $method;
    }

    /**
     * Send response headers indicating render method and status
     * 
     * @param string $cache_status Cache status (HIT, MISS, etc.)
     */
    public static function send_headers($cache_status = '') {
        if (headers_sent()) {
            return;
        }

        # OTTO Render Method header
        $method = self::get_current_method();
        $method_label = $method === self::METHOD_BUFFER ? 'BUFFER' : 'HTTP';
        header('X-MetaSync-OTTO-Method: ' . $method_label);

        # OTTO Cache Status header
        if (!empty($cache_status)) {
            header('X-MetaSync-OTTO-Cache: ' . $cache_status);
        }

        # OTTO Processed indicator
        header('X-MetaSync-OTTO-Processed: true');

        # Check if WP Rocket is active
        $wp_rocket_active = class_exists('WP_Rocket');

        # Add compatibility indicator
        if ($wp_rocket_active) {
            header('X-MetaSync-OTTO-WPRocket: Compatible');
        }

        # Performance optimization: Cache headers
        # Only set if WP Rocket is NOT active (let WP Rocket control cache headers)
        if (!is_user_logged_in() && !$wp_rocket_active) {
            $cache_duration = 3600; // 1 hour for browsers
            $cdn_cache_duration = 86400; // 24 hours for CDNs
            header('Cache-Control: public, max-age=' . $cache_duration . ', s-maxage=' . $cdn_cache_duration);
            header('Vary: Accept-Encoding');
        }
    }

    /**
     * Check if buffer method is currently active
     * 
     * @return bool
     */
    public static function is_buffer_active() {
        return self::$buffer_active;
    }

    /**
     * Cancel buffer processing (for fallback to HTTP)
     */
    public static function cancel_buffer() {
        if (self::$buffer_active) {
            # End our buffer without processing
            if (ob_get_level() > self::$buffer_start_level) {
                ob_end_flush();
            }
            self::$buffer_active = false;
            self::$current_method = self::METHOD_HTTP;
        }
    }

    /**
     * Get diagnostic information about current environment
     * 
     * @return array Diagnostic data
     */
    public static function get_diagnostics() {
        return [
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'memory_limit' => ini_get('memory_limit'),
            'memory_used' => size_format(memory_get_usage(true)),
            'buffer_level' => ob_get_level(),
            'headers_sent' => headers_sent(),
            'is_admin' => is_admin(),
            'is_ajax' => wp_doing_ajax(),
            'is_logged_in' => is_user_logged_in(),
            'current_method' => self::get_current_method(),
            'buffer_active' => self::$buffer_active,
            'detected_plugins' => [
                'wp_rocket' => class_exists('WP_Rocket'),
                'w3tc' => defined('W3TC'),
                'litespeed' => defined('LSCWP_V'),
                'autoptimize' => class_exists('autoptimizeMain'),
                'brizy' => class_exists('Brizy_Editor'),
            ],
            'detected_hosts' => [
                'wp_engine' => defined('WPE_APIKEY') || getenv('IS_WPE'),
                'kinsta' => defined('KINSTAMU_VERSION') || getenv('KINSTA_CACHE_ZONE'),
                'cloudways' => isset($_SERVER['HTTP_X_VARNISH']),
                'flywheel' => defined('FLYWHEEL_CONFIG_DIR'),
            ],
        ];
    }
}

