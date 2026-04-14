<?php
/**
 * MCP Tool — Get Breadcrumb Path
 *
 * Returns the resolved breadcrumb trail for a given post or page.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 * @since      2.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCP_Tool_Get_Breadcrumb_Path extends MCP_Tool_Base {

    /**
     * @inheritDoc
     */
    public function get_name() {
        return 'wordpress_get_breadcrumb_path';
    }

    /**
     * @inheritDoc
     */
    public function get_description() {
        return 'Get the resolved breadcrumb trail for a post or page';
    }

    /**
     * @inheritDoc
     */
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'post_id' => array(
                    'type'        => 'integer',
                    'description' => 'The post ID to resolve the breadcrumb trail for',
                ),
            ),
            'required' => array('post_id'),
        );
    }

    /**
     * @inheritDoc
     */
    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_posts');

        $post_id = intval($params['post_id']);
        $this->check_post_permission($post_id);
        $this->verify_post_exists($post_id);

        $breadcrumbs = new Metasync_Breadcrumbs();
        $trail       = $breadcrumbs->resolve_breadcrumb_trail($post_id);

        return $this->success(array(
            'post_id'     => $post_id,
            'trail'       => $trail,
            'trail_count' => count($trail),
        ));
    }
}
