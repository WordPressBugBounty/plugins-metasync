<?php

/**
 * Google Index API - Initialization & Entry Point
 * 
 * This file initializes and provides the main API for the Google Index Direct
 * implementation. It serves as the primary entry point for the functionality.
 * 
 * @package GoogleIndexDirect
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define constants for this module
define('GOOGLE_INDEX_DIRECT_VERSION', '1.0.0');
define('GOOGLE_INDEX_DIRECT_PATH', __DIR__);
define('GOOGLE_INDEX_DIRECT_URL', plugin_dir_url(__FILE__));

/**
 * Initialize Google Index API
 * 
 * Call this function to load and initialize the Google Index functionality
 */
function google_index_direct_init() 
{
    // Load the main class
    require_once GOOGLE_INDEX_DIRECT_PATH . '/class-google-index-direct.php';
    
    // Load admin interface if in admin area
    if (is_admin()) {
        require_once GOOGLE_INDEX_DIRECT_PATH . '/class-google-index-admin.php';
    }
}

/**
 * Get Google Index Direct instance
 * 
 * @return Google_Index_Direct
 */
function google_index_direct() 
{
    static $instance = null;
    
    if ($instance === null) {
        $instance = new Google_Index_Direct();
    }
    
    return $instance;
}

/**
 * Convenience function to index a post
 * 
 * @param int $post_id Post ID
 * @param string $post_type Post type
 * @param string $action Action (update, delete, status)
 * @return array Result
 */
function google_index_post($post_id, $post_type = 'post', $action = 'update') 
{
    return google_index_direct()->index_post($post_id, $post_type, $action);
}

/**
 * Convenience function to index a URL
 * 
 * @param string $url URL to index
 * @param string $action Action (update, delete, status)
 * @return array Result
 */
function google_index_url($url, $action = 'update') 
{
    return google_index_direct()->index_url($url, $action);
}

/**
 * Save Google Service Account configuration to database
 * 
 * @param array $service_account_json Service account JSON configuration
 * @return bool True on success, false on failure
 */
function google_index_save_service_account($service_account_json) 
{
    return google_index_direct()->save_service_account_config($service_account_json);
}


// Initialize on WordPress init
add_action('init', 'google_index_direct_init');

/**
 * Example usage in your plugin/theme:
 * 
 * // Include this file to initialize the functionality
 * require_once 'google-index/google-index-init.php';
 * 
 * // SETUP: Configure service account via admin interface
 * // 1. Go to WordPress Admin → MetaSync → Settings → General tab
 * // 2. Scroll to "Google Index API" section
 * // 3. Upload or paste your service account JSON and save settings
 * 
 * // OR programmatically (one-time setup)
 * $service_account = json_decode(file_get_contents('path/to/service-account.json'), true);
 * google_index_save_service_account($service_account);
 * 
 * // Basic usage - index a post manually
 * $result = google_index_post(123, 'post', 'update');
 * 
 * // Index a custom URL manually
 * $result = google_index_url('https://yoursite.com/special-page/', 'update');
 * 
 * // Check indexing status
 * $result = google_index_url('https://yoursite.com/some-page/', 'status');
 * 
 * // Get the main instance for advanced usage
 * $google_index = google_index_direct();
 * $test_results = $google_index->test_connection();
 * 
 * // Auto-index posts on publish
 * add_action('publish_post', function($post_id) {
 *     google_index_post($post_id, 'post', 'update');
 * });
 */
