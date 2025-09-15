<?php
/**
 * OTTO SEO Integration Functions
 * 
 * This file contains all the supporting functions for OTTO SEO data processing
 * and SEO plugin integration.
 * 
 * @package MetaSync
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fetch SEO data from OTTO API
 * 
 * @param string $route The URL route
 * @param string $uuid The OTTO UUID
 * @return array|false SEO data array or false on failure
 */
function metasync_fetch_otto_seo_data($route, $uuid) {
    # Construct OTTO API endpoint URL
    $api_url = add_query_arg(
        array(
            'url' => $route,
            'uuid' => $uuid
        ),
        'https://sa.searchatlas.com/api/v2/otto-url-details'
    );

    # Make API request with timeout and error handling
    $response = wp_remote_get($api_url, array(
        'timeout' => 30,
        'sslverify' => true,
        'headers' => array(
            'User-Agent' => 'MetaSync-WordPress-Plugin/1.0'
        )
    ));

    # Check for HTTP errors
    if (is_wp_error($response)) {
        error_log("MetaSync OTTO: API request failed: " . $response->get_error_message());
        return false;
    }

    # Check response code
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        error_log("MetaSync OTTO: API returned status code: {$response_code}");
        return false;
    }

    # Parse response body
    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        error_log("MetaSync OTTO: Empty response body from API");
        return false;
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("MetaSync OTTO: Invalid JSON response from API");
        return false;
    }

    return $data;
}

/**
 * Extract meta title from OTTO response data
 * 
 * @param array $seo_data The SEO data from OTTO API
 * @return string|null The meta title or null if not found
 */
function metasync_extract_meta_title($seo_data) {
    # First check header replacements for title
    if (!empty($seo_data['header_replacements']) && is_array($seo_data['header_replacements'])) {
        foreach ($seo_data['header_replacements'] as $replacement) {
            if (isset($replacement['type']) && $replacement['type'] === 'title' 
                && !empty($replacement['recommended_value'])) {
                return sanitize_text_field($replacement['recommended_value']);
            }
        }
    }
    
    # Check header_html_insertion for title tag
    if (!empty($seo_data['header_html_insertion'])) {
        $html_content = $seo_data['header_html_insertion'];
        
        # Look for title tag with data-otto-pixel attribute
        if (preg_match('/<title[^>]*data-otto-pixel=["\']dynamic-seo["\'][^>]*>([^<]+)<\/title>/i', $html_content, $matches)) {
            return sanitize_text_field(trim($matches[1]));
        }
        
        # Fallback: look for any title tag
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html_content, $matches)) {
            return sanitize_text_field(trim($matches[1]));
        }
    }
    
    return null;
}

/**
 * Extract meta description from OTTO response data
 * 
 * @param array $seo_data The SEO data from OTTO API
 * @return string|null The meta description or null if not found
 */
function metasync_extract_meta_description($seo_data) {
    # First check header replacements for meta description
    if (!empty($seo_data['header_replacements']) && is_array($seo_data['header_replacements'])) {
        foreach ($seo_data['header_replacements'] as $replacement) {
            if (isset($replacement['type']) && $replacement['type'] === 'meta'
                && (
                    (isset($replacement['name']) && $replacement['name'] === 'description') ||
                    (isset($replacement['property']) && $replacement['property'] === 'og:description')
                )
                && !empty($replacement['recommended_value'])) {
                return sanitize_textarea_field($replacement['recommended_value']);
            }
        }
    }
    
    # Check header_html_insertion for meta description tag
    if (!empty($seo_data['header_html_insertion'])) {
        $html_content = $seo_data['header_html_insertion'];
        
        # Look for meta description tag with data-otto-pixel attribute
        if (preg_match('/<meta\s+name=["\']description["\']\s+[^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html_content, $matches)) {
            return sanitize_textarea_field($matches[1]);
        }
        
        # Fallback: look for any meta description tag
        if (preg_match('/<meta\s+name=["\']description["\']\s+[^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html_content, $matches)) {
            return sanitize_textarea_field($matches[1]);
        }
    }
    
    return null;
}

/**
 * Update SEO meta fields for WordPress and popular SEO plugins
 * 
 * @param int $post_id WordPress post ID
 * @param string|null $meta_title Meta title to set
 * @param string|null $meta_description Meta description to set
 * @return bool Success status
 */
function metasync_update_seo_meta_fields($post_id, $meta_title, $meta_description) {
    if (!$post_id || ($meta_title === null && $meta_description === null)) {
        return false;
    }

    $updated = false;

    try {
        # Update WordPress native meta fields
        if ($meta_title !== null) {
            update_post_meta($post_id, '_metasync_otto_title', $meta_title);
            $updated = true;
        }
        
        if ($meta_description !== null) {
            update_post_meta($post_id, '_metasync_otto_description', $meta_description);
            $updated = true;
        }

        # Update RankMath SEO plugin meta fields
        if (metasync_is_plugin_active('seo-by-rank-math/rank-math.php') || metasync_is_plugin_active('seo-by-rankmath/rank-math.php')) {
            if ($meta_title !== null) {
                update_post_meta($post_id, 'rank_math_title', $meta_title);
                error_log("MetaSync OTTO: Updated RankMath title for post {$post_id}");
            }
            
            if ($meta_description !== null) {
                update_post_meta($post_id, 'rank_math_description', $meta_description);
                error_log("MetaSync OTTO: Updated RankMath description for post {$post_id}");
            }
        }

        # Update Yoast SEO plugin meta fields
        if (metasync_is_plugin_active('wordpress-seo/wp-seo.php')) {
            if ($meta_title !== null) {
                update_post_meta($post_id, '_yoast_wpseo_title', $meta_title);
                error_log("MetaSync OTTO: Updated Yoast title for post {$post_id}");
            }
            
            if ($meta_description !== null) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_description);
                error_log("MetaSync OTTO: Updated Yoast description for post {$post_id}");
            }
        }

        # Update All in One SEO (AIOSEO) plugin meta fields
        if (metasync_is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php') ||
            metasync_is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php')) {
            
            # AIOSEO uses custom database tables, so we use their API if available
            if (class_exists('AIOSEO\\Plugin\\Common\\Models\\Post')) {
                try {
                    // @phpstan-ignore-next-line
                    $aioseo_post = \AIOSEO\Plugin\Common\Models\Post::getPost($post_id);
                    if ($aioseo_post) {
                        if ($meta_title !== null) {
                            $aioseo_post->title = $meta_title;
                        }
                        if ($meta_description !== null) {
                            $aioseo_post->description = $meta_description;
                        }
                        $aioseo_post->save();
                        error_log("MetaSync OTTO: Updated AIOSEO meta for post {$post_id}");
                    }
                } catch (Exception $e) {
                    error_log("MetaSync OTTO: AIOSEO update failed: " . $e->getMessage());
                    
                    # Fallback: Use post meta (for older AIOSEO versions)
                    if ($meta_title !== null) {
                        update_post_meta($post_id, '_aioseo_title', $meta_title);
                    }
                    if ($meta_description !== null) {
                        update_post_meta($post_id, '_aioseo_description', $meta_description);
                    }
                }
            } else {
                # Fallback: Use post meta for AIOSEO
                if ($meta_title !== null) {
                    update_post_meta($post_id, '_aioseo_title', $meta_title);
                }
                if ($meta_description !== null) {
                    update_post_meta($post_id, '_aioseo_description', $meta_description);
                }
            }
        }

        # Store timestamp of last OTTO SEO update
        update_post_meta($post_id, '_metasync_otto_last_update', current_time('timestamp'));

        return $updated;

    } catch (Exception $e) {
        error_log("MetaSync OTTO: Exception updating SEO meta fields: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if a specific plugin is active
 * 
 * @param string $plugin_path Plugin path (folder/file.php)
 * @return bool True if plugin is active
 */
function metasync_is_plugin_active($plugin_path) {
    if (!function_exists('is_plugin_active')) {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
    
    return is_plugin_active($plugin_path);
}

/**
 * Clear SEO-related caches after updating meta fields
 * 
 * @param int $post_id WordPress post ID
 */
function metasync_clear_post_seo_caches($post_id) {
    # Clear WordPress object cache for the post
    clean_post_cache($post_id);
    
    # Clear RankMath cache if available
    if (class_exists('RankMath\\Helper') && method_exists('RankMath\\Helper', 'clear_cache')) {
        // @phpstan-ignore-next-line
        \RankMath\Helper::clear_cache();
    }
    
    # Clear Yoast SEO cache if available  
    if (class_exists('WPSEO_Utils') && method_exists('WPSEO_Utils', 'clear_cache')) {
        // @phpstan-ignore-next-line
        WPSEO_Utils::clear_cache();
    }
    
    # Clear AIOSEO cache if available
    if (class_exists('AIOSEO\\Plugin\\Common\\Utils\\Cache')) {
        // @phpstan-ignore-next-line
        $cache = new \AIOSEO\Plugin\Common\Utils\Cache();
        $cache->clear();
    }
    
    # Avoid global cache flush; 'clean_post_cache' already clears post-specific caches.
}

/**
 * Register meta fields for REST API access (called on init)
 * This ensures SEO plugin meta fields are accessible via REST API
 */
function metasync_register_seo_meta_fields() {
    $post_types = array('post', 'page');
    
    foreach ($post_types as $post_type) {
        # MetaSync OTTO fields
        register_post_meta($post_type, '_metasync_otto_title', array(
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
        
        register_post_meta($post_type, '_metasync_otto_description', array(
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
        
        # RankMath fields
        register_post_meta($post_type, 'rank_math_title', array(
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
        
        register_post_meta($post_type, 'rank_math_description', array(
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
        
        # Yoast SEO fields
        register_post_meta($post_type, '_yoast_wpseo_title', array(
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
        
        register_post_meta($post_type, '_yoast_wpseo_metadesc', array(
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
    }
}

/**
 * Admin notice to inform about OTTO SEO integration
 */
// function metasync_otto_seo_admin_notice() {
//     if (!current_user_can('manage_options')) {
//         return;
//     }

//     $screen = get_current_screen();
//     if ($screen && $screen->id === 'plugins') {
//         echo '<div class="notice notice-info is-dismissible">';
//         echo '<p><strong>MetaSync OTTO SEO Integration:</strong> OTTO now automatically updates meta titles and descriptions for RankMath, Yoast SEO, and All in One SEO plugins when pages are crawled.</p>';
//         echo '</div>';
//     }
// }

# Register meta fields on WordPress init
add_action('init', 'metasync_register_seo_meta_fields');

# Show admin notice about new feature
// add_action('admin_notices', 'metasync_otto_seo_admin_notice');
