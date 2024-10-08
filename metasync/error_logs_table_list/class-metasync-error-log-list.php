<?php

/**
 * The site error logs for the plugin.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/site-error-logs
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
 */
class Metasync_Error_Logs_Table
{

    private $data_error_log_list;

    public function __construct(&$data_error_log_list)
    {
        $this->data_error_log_list = $data_error_log_list;
    }

    public function create_admin_error_log_list_interface()
    {
        if (!class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }
        require dirname(__FILE__, 2) . '/error_logs_table_list/class-metasync-error-log-list-table.php';

        $MetasyncErrorLogList = new Metasync_Error_Log_List();
        $MetasyncErrorLogList->setDatabaseResource($this->data_error_log_list);

        echo "<form method='post' name='frm_error_list' action=''>";
        // Prepare table
        $MetasyncErrorLogList->prepare_items();
        // Display table
        $MetasyncErrorLogList->display();
        // Show file info
        $MetasyncErrorLogList->show_file_info();
        echo "</form>";
    }
}
