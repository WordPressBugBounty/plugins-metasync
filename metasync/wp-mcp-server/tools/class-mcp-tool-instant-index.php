<?php
/**
 * MCP Tools for Google Instant Index Operations
 *
 * Provides MCP tools for managing Google Instant Indexing API integration.
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

        // Get the instant index class
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'instant-index/class-metasync-instant-index.php';
        $instant_index = new Metasync_Instant_Index();

        // Check if API is configured
        $json_key = $instant_index->get_setting('json_key');
        if (empty($json_key)) {
            throw new Exception('Google Instant Index API is not configured. Please add your JSON key in settings.');
        }

        // Send update request
        try {
            $result = $instant_index->google_api([$url], 'update');

            return $this->success([
                'url' => $url,
                'action' => 'update',
                'result' => $result,
                'message' => 'URL submitted for indexing successfully',
            ]);
        } catch (Exception $e) {
            throw new Exception('Google API error: ' . $e->getMessage());
        }
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

        // Get the instant index class
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'instant-index/class-metasync-instant-index.php';
        $instant_index = new Metasync_Instant_Index();

        // Check if API is configured
        $json_key = $instant_index->get_setting('json_key');
        if (empty($json_key)) {
            throw new Exception('Google Instant Index API is not configured. Please add your JSON key in settings.');
        }

        // Send delete request
        try {
            $result = $instant_index->google_api([$url], 'delete');

            return $this->success([
                'url' => $url,
                'action' => 'delete',
                'result' => $result,
                'message' => 'URL removal requested successfully',
            ]);
        } catch (Exception $e) {
            throw new Exception('Google API error: ' . $e->getMessage());
        }
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

        // Get the instant index class
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'instant-index/class-metasync-instant-index.php';
        $instant_index = new Metasync_Instant_Index();

        // Check if API is configured
        $json_key = $instant_index->get_setting('json_key');
        if (empty($json_key)) {
            throw new Exception('Google Instant Index API is not configured. Please add your JSON key in settings.');
        }

        // Get status
        try {
            $result = $instant_index->google_api([$url], 'status');

            return $this->success([
                'url' => $url,
                'status' => $result,
                'message' => 'Status retrieved successfully',
            ]);
        } catch (Exception $e) {
            throw new Exception('Google API error: ' . $e->getMessage());
        }
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

        // Get the instant index class
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'instant-index/class-metasync-instant-index.php';
        $instant_index = new Metasync_Instant_Index();

        // Check if API is configured
        $json_key = $instant_index->get_setting('json_key');
        if (empty($json_key)) {
            throw new Exception('Google Instant Index API is not configured. Please add your JSON key in settings.');
        }

        // Send bulk update request
        try {
            $result = $instant_index->google_api($urls, 'update');

            return $this->success([
                'urls_count' => count($urls),
                'urls' => $urls,
                'action' => 'update',
                'results' => $result,
                'message' => count($urls) . ' URL(s) submitted for indexing successfully',
            ]);
        } catch (Exception $e) {
            throw new Exception('Google API error: ' . $e->getMessage());
        }
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
            'properties' => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        // Get the instant index class
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'instant-index/class-metasync-instant-index.php';
        $instant_index = new Metasync_Instant_Index();

        $json_key = $instant_index->get_setting('json_key');
        $post_types = $instant_index->get_setting('post_types');

        return $this->success([
            'api_configured' => !empty($json_key),
            'json_key_length' => !empty($json_key) ? strlen($json_key) : 0,
            'post_types' => is_array($post_types) ? $post_types : [],
            'guide_url' => $instant_index->google_guide_url,
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

        // Get the instant index class
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'instant-index/class-metasync-instant-index.php';
        $instant_index = new Metasync_Instant_Index();

        // Get current settings
        $settings = get_option('metasync_options_instant_indexing', $instant_index->default_settings);

        // Update JSON key if provided
        if (isset($params['json_key'])) {
            $json_key = sanitize_textarea_field($params['json_key']);

            // Basic validation: check if it's valid JSON
            $decoded = json_decode($json_key, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON key format');
            }

            $settings['json_key'] = $json_key;
        }

        // Update post types if provided
        if (isset($params['post_types'])) {
            if (!is_array($params['post_types'])) {
                throw new Exception('post_types must be an array');
            }

            // Validate post types exist
            $valid_post_types = get_post_types(['public' => true]);
            $post_types = [];
            foreach ($params['post_types'] as $post_type) {
                $post_type = sanitize_text_field($post_type);
                if (isset($valid_post_types[$post_type])) {
                    $post_types[] = $post_type;
                }
            }

            $settings['post_types'] = $post_types;
        }

        // Save settings
        update_option('metasync_options_instant_indexing', $settings);

        return $this->success([
            'api_configured' => !empty($settings['json_key']),
            'post_types' => $settings['post_types'],
            'message' => 'Instant Index settings updated successfully',
        ]);
    }
}
