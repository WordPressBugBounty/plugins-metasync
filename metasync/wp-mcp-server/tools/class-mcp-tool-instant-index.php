<?php
/**
 * MCP Tools for Google Instant Index Operations
 *
 * Provides MCP tools for managing Google Instant Indexing API integration.
 * Uses the native PHP implementation in google-index/class-google-index-direct.php
 * via the google_index_direct() singleton.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(dirname(__FILE__)) . 'class-mcp-tool-base.php';

/**
 * Instant Index Update Tool
 *
 * Submit URL to Google for indexing
 */
class MCP_Tool_Instant_Index_Update extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_instant_index_update';
    }

    public function get_description() {
        return 'Submit a URL to Google Instant Index API for indexing (URL_UPDATED)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The URL to submit for indexing',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $url = esc_url_raw($params['url']);

        if (empty($url)) {
            throw new Exception('Invalid URL provided');
        }

        if (!function_exists('google_index_direct')) {
            throw new Exception('Google Index Direct module is not available');
        }

        $service_info = google_index_direct()->get_service_account_info();
        if (isset($service_info['error'])) {
            throw new Exception('Google Instant Index API is not configured. Please add your service account JSON in settings.');
        }

        $result = google_index_direct()->index_url($url, 'update');

        if (!empty($result['success'])) {
            return $this->success([
                'url' => $url,
                'action' => 'update',
                'result' => $result,
                'message' => 'URL submitted for indexing successfully',
            ]);
        }

        $error_msg = isset($result['error']['message']) ? $result['error']['message'] : 'Unknown error';
        throw new Exception('Google API error: ' . $error_msg);
    }
}

/**
 * Instant Index Delete Tool
 *
 * Request URL removal from Google index
 */
class MCP_Tool_Instant_Index_Delete extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_instant_index_delete';
    }

    public function get_description() {
        return 'Request URL removal from Google Instant Index API (URL_DELETED)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The URL to request removal for',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $url = esc_url_raw($params['url']);

        if (empty($url)) {
            throw new Exception('Invalid URL provided');
        }

        if (!function_exists('google_index_direct')) {
            throw new Exception('Google Index Direct module is not available');
        }

        $service_info = google_index_direct()->get_service_account_info();
        if (isset($service_info['error'])) {
            throw new Exception('Google Instant Index API is not configured. Please add your service account JSON in settings.');
        }

        $result = google_index_direct()->index_url($url, 'delete');

        if (!empty($result['success'])) {
            return $this->success([
                'url' => $url,
                'action' => 'delete',
                'result' => $result,
                'message' => 'URL removal requested successfully',
            ]);
        }

        $error_msg = isset($result['error']['message']) ? $result['error']['message'] : 'Unknown error';
        throw new Exception('Google API error: ' . $error_msg);
    }
}

/**
 * Instant Index Status Tool
 *
 * Check indexing status of a URL
 */
class MCP_Tool_Instant_Index_Status extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_instant_index_status';
    }

    public function get_description() {
        return 'Check the indexing status of a URL in Google Instant Index API';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The URL to check status for',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $url = esc_url_raw($params['url']);

        if (empty($url)) {
            throw new Exception('Invalid URL provided');
        }

        if (!function_exists('google_index_direct')) {
            throw new Exception('Google Index Direct module is not available');
        }

        $service_info = google_index_direct()->get_service_account_info();
        if (isset($service_info['error'])) {
            throw new Exception('Google Instant Index API is not configured. Please add your service account JSON in settings.');
        }

        $result = google_index_direct()->get_url_status($url);

        if (!empty($result['success'])) {
            return $this->success([
                'url' => $url,
                'status' => $result,
                'message' => 'Status retrieved successfully',
            ]);
        }

        $error_msg = isset($result['error']['message']) ? $result['error']['message'] : 'Unknown error';
        throw new Exception('Google API error: ' . $error_msg);
    }
}

/**
 * Instant Index Bulk Update Tool
 *
 * Submit multiple URLs to Google for indexing
 */
class MCP_Tool_Instant_Index_Bulk_Update extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_instant_index_bulk_update';
    }

    public function get_description() {
        return 'Submit multiple URLs to Google Instant Index API for indexing (batch operation, max 100 URLs)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'urls' => [
                    'type' => 'array',
                    'description' => 'Array of URLs to submit for indexing (max 100)',
                    'items' => [
                        'type' => 'string',
                    ],
                    'maxItems' => 100,
                ],
            ],
            'required' => ['urls'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        if (!isset($params['urls']) || !is_array($params['urls'])) {
            throw new Exception('URLs must be provided as an array');
        }

        $urls = array_map('esc_url_raw', array_filter($params['urls']));

        if (empty($urls)) {
            throw new Exception('No valid URLs provided');
        }

        if (count($urls) > 100) {
            throw new Exception('Maximum 100 URLs can be submitted at once');
        }

        if (!function_exists('google_index_direct')) {
            throw new Exception('Google Index Direct module is not available');
        }

        $service_info = google_index_direct()->get_service_account_info();
        if (isset($service_info['error'])) {
            throw new Exception('Google Instant Index API is not configured. Please add your service account JSON in settings.');
        }

        $results = [];
        foreach ($urls as $url) {
            $results[$url] = google_index_direct()->index_url($url, 'update');
        }

        return $this->success([
            'urls_count' => count($urls),
            'urls' => $urls,
            'action' => 'update',
            'results' => $results,
            'message' => count($urls) . ' URL(s) submitted for indexing successfully',
        ]);
    }
}

/**
 * Get Instant Index Settings Tool
 *
 * Get Google Instant Index API configuration
 */
class MCP_Tool_Get_Instant_Index_Settings extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_instant_index_settings';
    }

    public function get_description() {
        return 'Get Google Instant Index API settings and configuration';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => (object)[],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        if (!function_exists('google_index_direct')) {
            throw new Exception('Google Index Direct module is not available');
        }

        $service_info = google_index_direct()->get_service_account_info();
        $is_configured = !isset($service_info['error']);

        $options = get_option('metasync_options_instant_indexing', ['json_key' => '', 'post_types' => []]);
        $post_types = isset($options['post_types']) && is_array($options['post_types']) ? $options['post_types'] : [];

        return $this->success([
            'api_configured' => $is_configured,
            'service_account' => $service_info,
            'post_types' => $post_types,
            'guide_url' => 'https://developers.google.com/search/apis/indexing-api/v3/quickstart',
        ]);
    }
}

/**
 * Update Instant Index Settings Tool
 *
 * Update Google Instant Index API configuration
 */
class MCP_Tool_Update_Instant_Index_Settings extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_instant_index_settings';
    }

    public function get_description() {
        return 'Update Google Instant Index API settings (JSON key and post types)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'json_key' => [
                    'type' => 'string',
                    'description' => 'Google service account JSON key (entire JSON content)',
                ],
                'post_types' => [
                    'type' => 'array',
                    'description' => 'Array of post type slugs to enable instant indexing for',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
            ],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        if (!function_exists('google_index_direct')) {
            throw new Exception('Google Index Direct module is not available');
        }

        $options = get_option('metasync_options_instant_indexing', ['json_key' => '', 'post_types' => []]);

        // Update JSON key if provided
        if (isset($params['json_key'])) {
            $json_key = sanitize_textarea_field($params['json_key']);

            // Validate JSON format
            $decoded = json_decode($json_key, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON key format');
            }

            // Save parsed credentials via google_index_direct
            $save_result = google_index_direct()->save_service_account_config($decoded);
            if (!$save_result) {
                throw new Exception('Failed to save service account configuration');
            }

            // Also store raw JSON in legacy option for settings page display
            $options['json_key'] = $json_key;
        }

        // Update post types if provided
        if (isset($params['post_types'])) {
            if (!is_array($params['post_types'])) {
                throw new Exception('post_types must be an array');
            }

            $valid_post_types = get_post_types(['public' => true]);
            $post_types = [];
            foreach ($params['post_types'] as $post_type) {
                $post_type = sanitize_text_field($post_type);
                if (isset($valid_post_types[$post_type])) {
                    $post_types[] = $post_type;
                }
            }

            $options['post_types'] = $post_types;
        }

        // Save legacy options (post_types + json_key for display)
        update_option('metasync_options_instant_indexing', $options);

        $service_info = google_index_direct()->get_service_account_info();
        $is_configured = !isset($service_info['error']);

        return $this->success([
            'api_configured' => $is_configured,
            'post_types' => isset($options['post_types']) ? $options['post_types'] : [],
            'message' => 'Instant Index settings updated successfully',
        ]);
    }
}
