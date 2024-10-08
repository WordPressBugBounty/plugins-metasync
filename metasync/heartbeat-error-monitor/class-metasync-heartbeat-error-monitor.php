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

class Metasync_HeartBeat_Error_Monitor
{
    private $db_heartbeat_errors;

    public function __construct(&$db_heartbeat_errors)
    {
        $this->db_heartbeat_errors = $db_heartbeat_errors;
    }

    public function create_admin_heartbeat_errors_interface()
    {
        if (!class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        require dirname(__FILE__, 2) . '/heartbeat-error-monitor/class-metasync-heartbeat-error-monitor-list-table.php';

        $MetasyncHeartBeatMonitor = new Metasync_HeartBeat_Error_Monitor_List_Table();
        $MetasyncHeartBeatMonitor->setDatabaseResource($this->db_heartbeat_errors);

        echo '<div class="wrap">';
        // Prepare table
        $MetasyncHeartBeatMonitor->prepare_items();
        // Display table
        $MetasyncHeartBeatMonitor->display();
        echo '</div>';
    }
}
