<?php
/**
 * MCP Tool: Redirect Management
 *
 * Provides tools for managing WordPress redirects.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create Redirect Tool
 */
class MCP_Tool_Create_Redirect extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_create_redirect';
    }

    public function get_description() {
        return 'Create a 301 or 302 redirect from one URL to another';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'source' => [
                    'type' => 'string',
                    'description' => 'Source URL path (e.g., /old-page or https://example.com/old-page)'
                ],
                'destination' => [
                    'type' => 'string',
                    'description' => 'Destination URL (full URL or path)'
                ],
                'type' => [
                    'type' => 'integer',
                    'description' => 'Redirect HTTP code',
                    'enum' => [301, 302],
                    'default' => 301
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional description of the redirect',
                    'default' => ''
                ]
            ],
            'required' => ['source', 'destination']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        // Load database class
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'redirections/class-metasync-redirection-database.php';
        $db = new Metasync_Redirection_Database();

        $source = $this->sanitize_url($params['source']);
        $destination = $this->sanitize_url($params['destination']);
        $http_code = isset($params['type']) ? $this->sanitize_integer($params['type']) : 301;
        $description = isset($params['description']) ? $this->sanitize_textarea($params['description']) : '';

        // Parse source to get path
        $source_path = parse_url($source, PHP_URL_PATH) ?: $source;

        // Create redirect
        $result = $db->add([
            'sources_from' => [$source_path],
            'url_redirect_to' => $destination,
            'http_code' => $http_code,
            'description' => $description,
            'status' => 'active',
            'pattern_type' => 'exact'
        ]);

        if ($result === false) {
            throw new Exception('Failed to create redirect');
        }

        return $this->success([
            'redirect_id' => $result,
            'source' => $source_path,
            'destination' => $destination,
            'type' => $http_code,
            'status' => 'active'
        ], 'Redirect created successfully');
    }
}

/**
 * List Redirects Tool
 */
class MCP_Tool_List_Redirects extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_list_redirects';
    }

    public function get_description() {
        return 'List all redirects with optional status filter';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'description' => 'Filter by status',
                    'enum' => ['active', 'inactive', 'all'],
                    'default' => 'active'
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results',
                    'default' => 50,
                    'minimum' => 1,
                    'maximum' => 200
                ]
            ],
            'required' => []
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'redirections/class-metasync-redirection-database.php';
        $db = new Metasync_Redirection_Database();

        $status = isset($params['status']) ? $params['status'] : 'active';

        // Get redirects
        if ($status === 'active') {
            $redirects = $db->getAllActiveRecords();
        } else {
            $redirects = $db->getAllRecords();
        }

        // Filter by status if needed
        if ($status !== 'all' && $status !== 'active') {
            $redirects = array_filter($redirects, function($r) use ($status) {
                return $r->status === $status;
            });
        }

        // Limit results
        $limit = isset($params['limit']) ? $this->sanitize_integer($params['limit']) : 50;
        $redirects = array_slice($redirects, 0, $limit);

        // Format results
        $result = array_map(function($redirect) {
            return [
                'id' => $redirect->id,
                'source' => is_array($redirect->sources_from) ? $redirect->sources_from : json_decode($redirect->sources_from, true),
                'destination' => $redirect->url_redirect_to,
                'type' => $redirect->http_code,
                'hits' => $redirect->hits_count,
                'status' => $redirect->status,
                'pattern_type' => $redirect->pattern_type ?? 'exact',
                'description' => $redirect->description ?? '',
                'created_at' => $redirect->created_at ?? null
            ];
        }, $redirects);

        return $this->success([
            'redirects' => $result,
            'total' => count($result)
        ]);
    }
}

/**
 * Delete Redirect Tool
 */
class MCP_Tool_Delete_Redirect extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_delete_redirect';
    }

    public function get_description() {
        return 'Delete a redirect by ID';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'redirect_id' => [
                    'type' => 'integer',
                    'description' => 'Redirect ID to delete',
                    'minimum' => 1
                ]
            ],
            'required' => ['redirect_id']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'redirections/class-metasync-redirection-database.php';
        $db = new Metasync_Redirection_Database();

        $redirect_id = $this->sanitize_integer($params['redirect_id']);

        // Verify redirect exists
        $redirect = $db->find($redirect_id);
        if (!$redirect) {
            throw new Exception(sprintf("Redirect not found: %d", absint($redirect_id)));
        }

        // Delete redirect
        $result = $db->delete([$redirect_id]);

        if ($result === false) {
            throw new Exception('Failed to delete redirect');
        }

        return $this->success([
            'redirect_id' => $redirect_id,
            'deleted' => true
        ], 'Redirect deleted successfully');
    }
}

/**
 * Update Redirect Tool
 */
class MCP_Tool_Update_Redirect extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_redirect';
    }

    public function get_description() {
        return 'Update an existing redirect';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'redirect_id' => [
                    'type' => 'integer',
                    'description' => 'Redirect ID to update',
                    'minimum' => 1
                ],
                'destination' => [
                    'type' => 'string',
                    'description' => 'New destination URL (optional)'
                ],
                'type' => [
                    'type' => 'integer',
                    'description' => 'New redirect HTTP code (optional)',
                    'enum' => [301, 302]
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'New status (optional)',
                    'enum' => ['active', 'inactive']
                ]
            ],
            'required' => ['redirect_id']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'redirections/class-metasync-redirection-database.php';
        $db = new Metasync_Redirection_Database();

        $redirect_id = $this->sanitize_integer($params['redirect_id']);

        // Verify redirect exists
        $redirect = $db->find($redirect_id);
        if (!$redirect) {
            throw new Exception(sprintf("Redirect not found: %d", absint($redirect_id)));
        }

        // Build update args
        $update_args = ['updated_at' => current_time('mysql')];

        if (isset($params['destination'])) {
            $update_args['url_redirect_to'] = $this->sanitize_url($params['destination']);
        }

        if (isset($params['type'])) {
            $update_args['http_code'] = $this->sanitize_integer($params['type']);
        }

        if (isset($params['status'])) {
            $update_args['status'] = $this->sanitize_string($params['status']);
        }

        // Update redirect
        $result = $db->update($update_args, $redirect_id);

        if ($result === false) {
            throw new Exception('Failed to update redirect');
        }

        // Get updated redirect
        $updated = $db->find($redirect_id);

        return $this->success([
            'redirect_id' => $redirect_id,
            'source' => json_decode($updated->sources_from, true),
            'destination' => $updated->url_redirect_to,
            'type' => $updated->http_code,
            'status' => $updated->status
        ], 'Redirect updated successfully');
    }
}
