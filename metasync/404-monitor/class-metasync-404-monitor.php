<?php

/**
 * The 404 error monitor functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @link       http://linkgraph.io
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/404-monitor
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
 */

// Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Error_Monitor
{
    private $database;

    public function __construct(&$database)
    {
        $this->database = $database;
    }

   

    public function create_admin_plugin_interface()
    {
        if (!class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }
        require dirname(__FILE__, 2) . '/404-monitor/class-metasync-404-monitor-list-table.php';

        $Metasync404Monitor = new Metasync_Error_Monitor_List_Table();
        $Metasync404Monitor->setDatabaseResource($this->database);
        $Metasync404Monitor->prepare_items();

        // Include the view markup.
        include dirname(__FILE__, 2) . '/views/metasync-404-monitor.php';
    }

    public function get_current_page_url($ignore_qs = false)
    {
        $server_data =  sanitize_post($_SERVER);
        $link = '://' . $server_data['HTTP_HOST'] . $server_data['REQUEST_URI'];
        $link = (is_ssl() ? 'https' : 'http') . $link;
        if ($ignore_qs) {
            $link = explode('?', sanitize_url($link));
            $link = $link[0];
        }
        return sanitize_url($link);
    }
}
