<?php
/**
 * API Backoff REST API Endpoints
 *
 * Provides REST API endpoints for managing and monitoring API backoff states.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 * @since      2.5.15
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Metasync_API_Backoff_REST
 *
 * Handles REST API endpoints for backoff management.
 */
class Metasync_API_Backoff_REST {

    /**
     * REST API namespace
     */
    private const NAMESPACE = 'metasync/v1';

    /**
     * Backoff manager instance
     *
     * @var Metasync_API_Backoff_Manager
     */
    private $backoff_manager;

    /**
     * Constructor
     */
    public function __construct() {
        $this->backoff_manager = Metasync_API_Backoff_Manager::get_instance();
        $this->register_routes();
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Get all active backoffs
        register_rest_route(self::NAMESPACE, '/backoff/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_backoff_status'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        // Get specific endpoint backoff state
        register_rest_route(self::NAMESPACE, '/backoff/(?P<hash>[a-f0-9]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_endpoint_backoff'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args'                => [
                'hash' => [
                    'description' => 'Endpoint hash identifier',
                    'type'        => 'string',
                    'pattern'     => '^[a-f0-9]+$',
                ],
            ],
        ]);

        // Clear specific endpoint backoff
        register_rest_route(self::NAMESPACE, '/backoff/(?P<hash>[a-f0-9]+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'clear_endpoint_backoff'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args'                => [
                'hash' => [
                    'description' => 'Endpoint hash identifier',
                    'type'        => 'string',
                    'pattern'     => '^[a-f0-9]+$',
                ],
            ],
        ]);

        // Clear all backoffs
        register_rest_route(self::NAMESPACE, '/backoff/clear-all', [
            'methods'             => 'POST',
            'callback'            => [$this, 'clear_all_backoffs'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        // Get backoff statistics
        register_rest_route(self::NAMESPACE, '/backoff/statistics', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_statistics'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);
    }

    /**
     * Check if user has admin permission
     *
     * @param WP_REST_Request $request Request object.
     * @return bool True if user has permission.
     */
    public function check_admin_permission($request) {
        return current_user_can('manage_options');
    }

    /**
     * Get all active backoffs
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_backoff_status($request) {
        $active_backoffs = $this->backoff_manager->get_all_active_backoffs();

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'active_backoffs' => $active_backoffs,
                'count'           => count($active_backoffs),
                'timestamp'       => current_time('timestamp'),
            ],
        ], 200);
    }

    /**
     * Get specific endpoint backoff state
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_endpoint_backoff($request) {
        $endpoint_hash = $request->get_param('hash');
        $backoff_state = $this->backoff_manager->get_backoff_state($endpoint_hash);

        if ($backoff_state === false) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'No active backoff for this endpoint',
                'data'    => [
                    'endpoint_hash' => $endpoint_hash,
                    'is_active'     => false,
                ],
            ], 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'endpoint_hash' => $endpoint_hash,
                'is_active'     => true,
                'backoff_state' => $backoff_state,
            ],
        ], 200);
    }

    /**
     * Clear specific endpoint backoff
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function clear_endpoint_backoff($request) {
        $endpoint_hash = $request->get_param('hash');
        $cleared = $this->backoff_manager->clear_backoff($endpoint_hash);

        if (!$cleared) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to clear backoff or no backoff exists',
                'data'    => [
                    'endpoint_hash' => $endpoint_hash,
                ],
            ], 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Backoff cleared successfully',
            'data'    => [
                'endpoint_hash' => $endpoint_hash,
                'cleared_by'    => get_current_user_id(),
                'cleared_at'    => current_time('timestamp'),
            ],
        ], 200);
    }

    /**
     * Clear all backoffs
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function clear_all_backoffs($request) {
        $cleared_count = $this->backoff_manager->clear_all_backoffs();

        return new WP_REST_Response([
            'success' => true,
            'message' => sprintf('Successfully cleared %d backoff(s)', $cleared_count),
            'data'    => [
                'cleared_count' => $cleared_count,
                'cleared_by'    => get_current_user_id(),
                'cleared_at'    => current_time('timestamp'),
            ],
        ], 200);
    }

    /**
     * Get backoff statistics
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_statistics($request) {
        $statistics = $this->backoff_manager->get_statistics();

        return new WP_REST_Response([
            'success' => true,
            'data'    => $statistics,
        ], 200);
    }
}

/**
 * Initialize REST API endpoints
 */
function metasync_api_backoff_rest_init() {
    new Metasync_API_Backoff_REST();
}
add_action('rest_api_init', 'metasync_api_backoff_rest_init');
