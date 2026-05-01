<?php
/**
 * MCP Tool: Redirect Health Check
 *
 * Checks the health of redirect chains — detects loops, long chains, and dead ends.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check Redirects Health Tool
 */
class MCP_Tool_Check_Redirects_Health extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_check_redirects_health';
    }

    public function get_description() {
        return 'Check health of redirect chains — detects loops, long chains, and dead ends';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'redirect_id' => [
                    'type' => 'integer',
                    'description' => 'Optional redirect ID to check a single redirect. Omit to check all active redirects.',
                    'minimum' => 1
                ]
            ],
            'required' => []
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'redirections/class-metasync-redirection-database.php';
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'redirections/class-metasync-redirection.php';

        $db = new Metasync_Redirection_Database();
        $helper = new Metasync_Redirection($db);

        $redirect_id = isset($params['redirect_id']) ? $this->sanitize_integer($params['redirect_id']) : null;

        $results = $helper->check_redirect_health($redirect_id);

        return $this->success([
            'results' => $results,
            'total'   => count($results),
        ], 'Health check completed');
    }
}
