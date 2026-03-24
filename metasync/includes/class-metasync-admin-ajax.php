<?php
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Admin_Ajax
{
    private static $instance = null;

    private $db_redirection = null;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    private function get_db_redirection()
    {
        if (null === $this->db_redirection) {
            $this->db_redirection = new Metasync_Redirection_Database();
        }
        return $this->db_redirection;
    }

    public function ajax_import_external_data()
    {
        $execution_time = Metasync_Settings_Fields::instance()->get_execution_setting('max_execution_time');
        if (function_exists('set_time_limit')) {
            @set_time_limit($execution_time);
        }
        
        Metasync_Settings_Fields::instance()->apply_memory_limit();
        
        check_ajax_referer('metasync_import_external_data', 'nonce');

        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
        }

        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $plugin = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';

        if (empty($type) || empty($plugin)) {
            wp_send_json_error(['message' => 'Missing required parameters.']);
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-external-importer.php';
        $importer = new Metasync_External_Importer($this->get_db_redirection());
        $result = ['success' => false, 'message' => 'Unknown import type.'];

        switch ($type) {
            case 'redirections':
                $result = $importer->import_redirections($plugin);
                break;
            case 'sitemap':
                $result = $importer->import_sitemap($plugin);
                break;
            case 'robots':
                $result = $importer->import_robots($plugin);
                break;
            case 'indexation':
                $result = $importer->import_indexation($plugin);
                break;
            case 'schema':
                $result = $importer->import_schema($plugin);
                break;
        }

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public function ajax_import_seo_metadata()
    {
        $execution_time = Metasync_Settings_Fields::instance()->get_execution_setting('max_execution_time');
        if (function_exists('set_time_limit')) {
            @set_time_limit($execution_time);
        }
        
        Metasync_Settings_Fields::instance()->apply_memory_limit();
        
        check_ajax_referer('metasync_import_seo_metadata', 'nonce');

        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
        }

        $plugin = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';
        $import_titles = isset($_POST['import_titles']) ? (bool) intval($_POST['import_titles']) : true;
        $import_descriptions = isset($_POST['import_descriptions']) ? (bool) intval($_POST['import_descriptions']) : true;
        $overwrite_existing = isset($_POST['overwrite_existing']) ? (bool) intval($_POST['overwrite_existing']) : false;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;

        if (empty($plugin)) {
            wp_send_json_error(['message' => 'Missing required plugin parameter.']);
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-external-importer.php';
        $importer = new Metasync_External_Importer($this->get_db_redirection());

        $options = [
            'import_titles' => $import_titles,
            'import_descriptions' => $import_descriptions,
            'overwrite_existing' => $overwrite_existing,
            'batch_size' => 50,
            'offset' => $offset
        ];

        $result = $importer->import_seo_metadata($plugin, $options);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public function lgSendCustomerParams()
    {
        $sync_request = new Metasync_Sync_Requests();

        # use the existing apikey for backward compatibility
        $general_options = Metasync::get_option('general') ?? [];
        $token = $general_options['apikey'] ?? null;

        # get the response
        $response = $sync_request->SyncCustomerParams($token);

        // Check if response is a throttling error object
        if (is_object($response) && isset($response->throttled) && $response->throttled === true) {
            wp_send_json($response);
            wp_die();
        }

        // Check if response is null/false (other error cases)
        if ($response === null || $response === false) {
            wp_send_json(['error' => 'Sync failed - no response from sync method', 'detail' => 'The sync method returned null or false']);
            wp_die();
        }

        $responseBody = wp_remote_retrieve_body($response);
        $responseCode = wp_remote_retrieve_response_code($response);

        if ($responseCode == 200) {
            $dt = new DateTime();
            $send_auth_token_timestamp = Metasync::get_option();
            $send_auth_token_timestamp['general']['send_auth_token_timestamp'] = $dt->format('M d, Y  h:i:s A');;
            Metasync::set_option($send_auth_token_timestamp);
            
            Metasync_Heartbeat_Manager::instance()->update_heartbeat_cache_after_sync(true, 'Sync Now - successful data sync');
            
            $result = json_decode($responseBody);
            $timestamp = @Metasync::get_option('general')['send_auth_token_timestamp'];
            $result->send_auth_token_timestamp = $timestamp;
            $result->send_auth_token_diffrence = Metasync_Settings_Fields::instance()->time_elapsed_string($timestamp);
            wp_send_json($result);
            wp_die();
        } else {
            Metasync_Heartbeat_Manager::instance()->update_heartbeat_cache_after_sync(false, 'Sync Now - failed data sync');
        }

        $result = json_decode($responseBody);
        wp_send_json($result);
        wp_die();
    }

    public function ajax_update_db_structure()
    {
        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('Insufficient permissions');
        }

        if (!wp_verify_nonce($_POST['nonce'], 'metasync_update_db_nonce')) {
            wp_die('Security check failed');
        }

        try {
            $this->get_db_redirection()->force_table_update();

            wp_send_json_success('Database structure updated successfully');
        } catch (Exception $e) {
            wp_send_json_error('Database update failed: ' . $e->getMessage());
        }
    }

    public function ajax_save_wizard_progress()
    {
        check_ajax_referer('metasync_wizard', 'nonce');

        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $step = isset($_POST['step']) ? intval($_POST['step']) : 0;
        $data = isset($_POST['data']) ? $_POST['data'] : array();

        $options = get_option('metasync_options', array());

        if (isset($data['verification'])) {
            if (!isset($options['general'])) {
                $options['general'] = array();
            }
            $options['general']['google_verification'] = sanitize_text_field($data['verification']['google']);
            $options['general']['bing_verification'] = sanitize_text_field($data['verification']['bing']);
        }

        if (isset($data['seo_settings'])) {
            if (!isset($options['seo_controls'])) {
                $options['seo_controls'] = array();
            }
            
            $options['seo_controls']['index_date_archives'] = $data['seo_settings']['date_archives'] ? 'false' : 'true';
            $options['seo_controls']['index_author_archives'] = $data['seo_settings']['author_archives'] ? 'false' : 'true';
            $options['seo_controls']['index_category_archives'] = $data['seo_settings']['category_archives'] ? 'false' : 'true';
            $options['seo_controls']['index_tag_archives'] = $data['seo_settings']['tag_archives'] ? 'false' : 'true';
        }

        if (isset($data['schema'])) {
            if (!isset($options['general'])) {
                $options['general'] = array();
            }
            $options['general']['enable_schema_markup'] = $data['schema']['enabled'];
            $options['general']['default_schema_type'] = sanitize_text_field($data['schema']['default_type']);
        }

        update_option('metasync_options', $options);

        wp_send_json_success(array('message' => 'Progress saved'));
    }

    public function ajax_complete_wizard()
    {
        check_ajax_referer('metasync_wizard', 'nonce');

        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        update_option('metasync_wizard_completed', array(
            'completed' => true,
            'completed_at' => current_time('mysql'),
            'completed_by' => get_current_user_id(),
            'version' => METASYNC_VERSION
        ));

        $user_id = get_current_user_id();
        delete_transient("metasync_wizard_state_{$user_id}");

        wp_send_json_success(array('message' => 'Wizard completed'));
    }

    public function ajax_validate_robots()
    {
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'robots-txt/class-metasync-robots-txt.php';
        $robots_txt = Metasync_Robots_Txt::get_instance();

        $content = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';
        $validation = $robots_txt->validate_content($content);

        wp_send_json_success($validation);
    }

    public function ajax_get_default_robots()
    {
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'robots-txt/class-metasync-robots-txt.php';
        $robots_txt = Metasync_Robots_Txt::get_instance();

        wp_send_json_success(array(
            'content' => $robots_txt->get_default_robots_content()
        ));
    }

    public function ajax_preview_robots_backup()
    {
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $backup_id = isset($_POST['backup_id']) ? intval($_POST['backup_id']) : 0;
        
        if (!$backup_id) {
            wp_send_json_error('Invalid backup ID');
            return;
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'robots-txt/class-metasync-robots-txt-database.php';
        $database = Metasync_Robots_Txt_Database::get_instance();

        $backup = $database->get_backup($backup_id);

        if (!$backup) {
            wp_send_json_error('Backup not found');
            return;
        }

        wp_send_json_success(array(
            'content' => $backup['content'],
            'created_at' => $backup['created_at'],
            'created_by_name' => isset($backup['created_by_name']) ? $backup['created_by_name'] : ''
        ));
    }

    public function ajax_delete_robots_backup()
    {
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(array('message' => esc_html__('Insufficient permissions', 'metasync')));
            return;
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'metasync_delete_robots_backup')) {
            wp_send_json_error(array('message' => esc_html__('Security check failed', 'metasync')));
            return;
        }

        $backup_id = isset($_POST['backup_id']) ? intval($_POST['backup_id']) : 0;
        
        if (!$backup_id) {
            wp_send_json_error(array('message' => esc_html__('Invalid backup ID', 'metasync')));
            return;
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'robots-txt/class-metasync-robots-txt.php';
        $robots_txt = Metasync_Robots_Txt::get_instance();

        $result = $robots_txt->delete_backup($backup_id);

        if ($result) {
            wp_send_json_success(array('message' => esc_html__('Backup deleted successfully!', 'metasync')));
        } else {
            wp_send_json_error(array('message' => esc_html__('Failed to delete backup.', 'metasync')));
        }
    }

    public function ajax_restore_robots_backup()
    {
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(array('message' => esc_html__('Insufficient permissions', 'metasync')));
            return;
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'metasync_restore_robots_backup')) {
            wp_send_json_error(array('message' => esc_html__('Security check failed', 'metasync')));
            return;
        }

        $backup_id = isset($_POST['backup_id']) ? intval($_POST['backup_id']) : 0;
        
        if (!$backup_id) {
            wp_send_json_error(array('message' => esc_html__('Invalid backup ID', 'metasync')));
            return;
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'robots-txt/class-metasync-robots-txt.php';
        $robots_txt = Metasync_Robots_Txt::get_instance();

        $result = $robots_txt->restore_backup($backup_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            $current_content = $robots_txt->read_robots_file();
            
            if (is_wp_error($current_content)) {
                wp_send_json_error(array('message' => $current_content->get_error_message()));
            } else {
                wp_send_json_success(array(
                    'message' => esc_html__('robots.txt restored from backup successfully!', 'metasync'),
                    'content' => $current_content
                ));
            }
        }
    }

    public function ajax_create_redirect_from_404()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'metasync_404_redirect')) {
            wp_die('Security check failed');
        }

        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('Insufficient permissions');
        }

        $uri = sanitize_text_field($_POST['uri']);
        $redirect_url = sanitize_url($_POST['redirect_url']);

        if (empty($uri) || empty($redirect_url)) {
            wp_send_json_error('Missing required parameters');
        }

        require_once plugin_dir_path(dirname(__FILE__)) . '404-monitor/class-metasync-404-monitor-database.php';
        require_once plugin_dir_path(dirname(__FILE__)) . '404-monitor/class-metasync-404-monitor.php';
        $db_404 = new Metasync_Error_Monitor_Database();
        $monitor_404 = new Metasync_Error_Monitor($db_404);
        
        $result = $monitor_404->create_redirection_from_404($uri, $redirect_url, 'Created from 404 suggestion');
        
        if ($result) {
            wp_send_json_success('Redirect created successfully');
        } else {
            wp_send_json_error('Failed to create redirect');
        }
    }

    public function ajax_test_host_blocking_get()
    {
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $endpoint = 'https://wp-check.searchatlas.com/ping';
        $start_time = microtime(true);
        
        $response = wp_remote_get($endpoint, array(
            'timeout' => 30,
            'user-agent' => 'MetaSync Plugin Host Test',
            'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Origin' => home_url(),
                'Referer' => admin_url(),
                'X-WordPress-Site' => home_url()
            )
        ));
        
        $end_time = microtime(true);
        $response_time = round(($end_time - $start_time) * 1000, 2);
        
        if (is_wp_error($response)) {
            wp_send_json_success(array(
                'method' => 'GET',
                'status' => 'error',
                'response_time' => $response_time . 'ms',
                'error' => $response->get_error_message(),
                'blocked' => true,
                'details' => 'Request failed - possible blocking detected'
            ));
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $headers = wp_remote_retrieve_headers($response);
            
            $is_blocked = false;
            $status_text = 'success';
            $details = 'GET request completed successfully';
            $parsed_response = null;
            
            if ($status_code === 200) {
                $parsed_response = json_decode($body, true);
                
                if ($parsed_response && isset($parsed_response['results']['get'])) {
                    $get_result = $parsed_response['results']['get'];
                    $get_status_code = isset($get_result['statusCode']) ? $get_result['statusCode'] : null;
                    
                    if ($get_status_code !== 200) {
                        $is_blocked = true;
                        $status_text = 'error';
                        $details = "GET request to target site returned status code {$get_status_code} - host blocking detected";
                    }
                } else {
                    $is_blocked = true;
                    $status_text = 'error';
                    $details = 'Unable to parse response structure - possible blocking or endpoint issue';
                }
            } else {
                $is_blocked = true;
                $status_text = 'error';
                $details = "External endpoint returned status code {$status_code} - possible blocking detected";
            }
            
            wp_send_json_success(array(
                'method' => 'GET',
                'status' => $status_text,
                'response_time' => $response_time . 'ms',
                'status_code' => $status_code,
                'body' => $body,
                'headers' => $headers->getAll(),
                'blocked' => $is_blocked,
                'details' => $details,
                'parsed_response' => $parsed_response
            ));
        }
    }

    public function ajax_test_host_blocking_post()
    {
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $endpoint = 'https://wp-check.searchatlas.com/ping';
        $start_time = microtime(true);
        
        $test_data = array(
            'test' => 'host_blocking_test',
            'timestamp' => current_time('mysql'),
            'source' => 'metasync_plugin',
            'method' => 'POST'
        );
        
        $response = wp_remote_post($endpoint, array(
            'timeout' => 30,
            'user-agent' => 'MetaSync Plugin Host Test',
            'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Origin' => home_url(),
                'Referer' => admin_url(),
                'X-WordPress-Site' => home_url()
            ),
            'body' => json_encode($test_data)
        ));
        
        $end_time = microtime(true);
        $response_time = round(($end_time - $start_time) * 1000, 2);
        
        if (is_wp_error($response)) {
            wp_send_json_success(array(
                'method' => 'POST',
                'status' => 'error',
                'response_time' => $response_time . 'ms',
                'error' => $response->get_error_message(),
                'blocked' => true,
                'details' => 'Request failed - possible blocking detected',
                'sent_data' => $test_data
            ));
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $headers = wp_remote_retrieve_headers($response);
            
            $is_blocked = false;
            $status_text = 'success';
            $details = 'POST request completed successfully';
            $parsed_response = null;
            
            if ($status_code === 200) {
                $parsed_response = json_decode($body, true);
                
                if ($parsed_response && isset($parsed_response['results']['post'])) {
                    $post_result = $parsed_response['results']['post'];
                    $post_status_code = isset($post_result['statusCode']) ? $post_result['statusCode'] : null;
                    
                    if ($post_status_code !== 200) {
                        $is_blocked = true;
                        $status_text = 'error';
                        $details = "POST request to target site returned status code {$post_status_code} - host blocking detected";
                    }
                } else {
                    $is_blocked = true;
                    $status_text = 'error';
                    $details = 'Unable to parse response structure - possible blocking or endpoint issue';
                }
            } else {
                $is_blocked = true;
                $status_text = 'error';
                $details = "External endpoint returned status code {$status_code} - possible blocking detected";
            }
            
            wp_send_json_success(array(
                'method' => 'POST',
                'status' => $status_text,
                'response_time' => $response_time . 'ms',
                'status_code' => $status_code,
                'body' => $body,
                'headers' => $headers->getAll(),
                'blocked' => $is_blocked,
                'details' => $details,
                'sent_data' => $test_data,
                'parsed_response' => $parsed_response
            ));
        }
    }

    public function execute_transient_cleanup()
    {
        global $wpdb;
        
        $cleanup_stats = array(
            'expired_transients' => 0,
            'plugin_transients' => 0,
            'rate_limit_transients' => 0,
            'telemetry_transients' => 0,
            'start_time' => microtime(true)
        );
        
        try {
            delete_expired_transients(true);
            $cleanup_stats['expired_transients'] = 'cleaned_by_wordpress';
            
            $plugin_transients = $wpdb->get_results(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_metasync_%'",
                ARRAY_A
            );
            
            foreach ($plugin_transients as $transient) {
                $transient_name = str_replace('_transient_', '', $transient['option_name']);
                delete_transient($transient_name);
                $cleanup_stats['plugin_transients']++;
            }
            
            $rate_limit_transients = $wpdb->get_results(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_sa_connect_rate_limit_%'",
                ARRAY_A
            );
            
            foreach ($rate_limit_transients as $transient) {
                $transient_name = str_replace('_transient_', '', $transient['option_name']);
                delete_transient($transient_name);
                $cleanup_stats['rate_limit_transients']++;
            }
            
            $telemetry_transients = $wpdb->get_results(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_metasync_telemetry_%'",
                ARRAY_A
            );
            
            foreach ($telemetry_transients as $transient) {
                $transient_name = str_replace('_transient_', '', $transient['option_name']);
                delete_transient($transient_name);
                $cleanup_stats['telemetry_transients']++;
            }
            
            $sa_connect_success_transients = $wpdb->get_results(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_metasync_sa_connect_success_%'",
                ARRAY_A
            );
            
            foreach ($sa_connect_success_transients as $transient) {
                $transient_name = str_replace('_transient_', '', $transient['option_name']);
                delete_transient($transient_name);
                $cleanup_stats['sa_connect_success_transients'] = ($cleanup_stats['sa_connect_success_transients'] ?? 0) + 1;
            }
            
            $cleanup_stats['execution_time'] = round((microtime(true) - $cleanup_stats['start_time']) * 1000, 2);
            $cleanup_stats['next_run'] = wp_next_scheduled('metasync_cleanup_transients') ? 
                                        date('Y-m-d H:i:s T', wp_next_scheduled('metasync_cleanup_transients')) : 'N/A';
            
            error_log('MetaSync: Transient cleanup completed - ' . json_encode($cleanup_stats));
            
        } catch (Exception $e) {
            error_log('MetaSync: Transient cleanup failed - ' . $e->getMessage());
        }
    }

    public function ajax_submit_issue_report()
    {
        try {
            # Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'metasync_report_issue')) {
                wp_send_json_error(array('message' => 'Security verification failed.'));
                return;
            }

            # Check user capabilities
            if (!Metasync::current_user_has_plugin_access()) {
                wp_send_json_error(array('message' => 'Insufficient permissions.'));
                return;
            }

            # Get and validate form data
            $issue_message = isset($_POST['issue_message']) ? sanitize_textarea_field(wp_unslash($_POST['issue_message'])) : '';
            $issue_severity = isset($_POST['issue_severity']) ? sanitize_text_field(wp_unslash($_POST['issue_severity'])) : 'warning';
            $include_user_info = isset($_POST['include_user_info']) && sanitize_text_field(wp_unslash($_POST['include_user_info'])) === 'true';

            # Validate severity level
            $valid_severity_levels = array('info', 'warning', 'error', 'fatal');
            if (!in_array($issue_severity, $valid_severity_levels, true)) {
                $issue_severity = 'warning';
            }

            # Validate message length
            if (empty($issue_message) || strlen($issue_message) < 10) {
                wp_send_json_error(array('message' => 'Please provide a more detailed description (at least 10 characters).'));
                return;
            }
            if (strlen($issue_message) > 1000) {
                wp_send_json_error(array('message' => 'Message is too long. Please limit to 1000 characters.'));
                return;
            }

            # Handle file upload if present
            $attachment = null;
            if (!empty($_FILES['issue_attachment']['tmp_name'])) {
                # Validate file type
                $allowed_types = array('image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp');
                $file_type = $_FILES['issue_attachment']['type'];
                
                if (!in_array($file_type, $allowed_types, true)) {
                    wp_send_json_error(array('message' => 'Invalid file type. Please upload a JPEG, PNG, GIF, or WebP image.'));
                    return;
                }

                # Validate file size (5MB max)
                $max_size = 5 * 1024 * 1024; // 5MB
                if ($_FILES['issue_attachment']['size'] > $max_size) {
                    wp_send_json_error(array('message' => 'File size exceeds 5MB. Please choose a smaller file.'));
                    return;
                }

                # Read file contents
                $file_contents = file_get_contents($_FILES['issue_attachment']['tmp_name']);
                if ($file_contents !== false) {
                    $attachment = array(
                        'filename' => sanitize_file_name($_FILES['issue_attachment']['name']),
                        'data' => $file_contents,
                        'content_type' => $file_type
                    );
                }
            }

            # Get general options (same way as used throughout the plugin)
            $general_options = Metasync::get_option('general');
            if (!is_array($general_options)) {
                $general_options = array();
            }
            
            $project_uuid = isset($general_options['otto_pixel_uuid']) ? sanitize_text_field($general_options['otto_pixel_uuid']) : '';

            # Always use standardized title format for Sentry prioritization
            $issue_title = !empty($project_uuid) ? 'Client Report ' . $project_uuid : 'Client Report (UUID Not Configured)';

            # Collect system information with error handling
            $active_plugins = get_option('active_plugins');
            $plugin_count = is_array($active_plugins) ? count($active_plugins) : 0;
            
            $active_theme = wp_get_theme();
            $theme_name = is_object($active_theme) ? $active_theme->get('Name') : get_template();

            $system_context = array(
                'report_type' => 'manual_client_report',
                'website_url' => esc_url_raw(home_url()),
                'site_title' => sanitize_text_field(get_bloginfo('name')),
                'admin_email' => sanitize_email(get_bloginfo('admin_email')),
                'plugin_version' => defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0',
                'plugin_name' => 'Search Engine Labs SEO (MetaSync)',
                'wordpress_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'active_theme' => $theme_name,
                'memory_limit' => ini_get('memory_limit'),
                'multisite' => is_multisite(),
                'project_uuid' => $project_uuid,
                'active_plugins' => $plugin_count,
                'report_timestamp' => current_time('mysql'),
                'severity_level' => $issue_severity
            );

            # Add user information if requested
            if ($include_user_info) {
                $current_user = wp_get_current_user();
                if ($current_user && $current_user->ID > 0) {
                    $system_context['reporter'] = array(
                        'username' => sanitize_user($current_user->user_login),
                        'email' => sanitize_email($current_user->user_email),
                        'display_name' => sanitize_text_field($current_user->display_name),
                        'roles' => is_array($current_user->roles) ? $current_user->roles : array()
                    );
                }
            }

            # Send to Sentry using User Feedback API
            $sent_to_sentry = false;
            
            # Check if Sentry feedback function exists
            if (!function_exists('metasync_sentry_capture_feedback')) {
                # Log warning if function doesn't exist
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MetaSync: Sentry feedback function not available for report submission.');
                }
            } else {
                $feedback_data = array(
                    'message' => $issue_message,
                    'severity' => $issue_severity
                );
                
                # Add user information if requested
                if ($include_user_info) {
                    $current_user = wp_get_current_user();
                    if ($current_user && $current_user->ID > 0) {
                        $feedback_data['name'] = sanitize_text_field($current_user->display_name);
                        $feedback_data['email'] = sanitize_email($current_user->user_email);
                    }
                }
                
                $sent_to_sentry = metasync_sentry_capture_feedback($feedback_data, $attachment);
            }

            if ($sent_to_sentry) {
                wp_send_json_success(array(
                    'message' => 'Report submitted successfully! Our team will review it shortly.',
                    'project_uuid' => $project_uuid,
                    'report_title' => esc_html($issue_title)
                ));
            } else {
                # Fallback: Log locally if Sentry fails or is unavailable
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        'MetaSync Client Report (Fallback): UUID: %s | Title: %s | Message: %s | Severity: %s',
                        $project_uuid,
                        $issue_title,
                        $issue_message,
                        $issue_severity
                    ));
                }
                
                wp_send_json_success(array(
                    'message' => 'Report logged locally. Note: Remote reporting may be unavailable.',
                    'project_uuid' => $project_uuid,
                    'report_title' => esc_html($issue_title),
                    'fallback' => true
                ));
            }
            
        } catch (Exception $e) {
            # Log the error securely (only in debug mode)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'MetaSync Report Submission Error: %s in %s on line %d',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
            }
            
            # Send generic error message to client
            wp_send_json_error(array('message' => 'Failed to submit report. Please try again later.'));
        }
    }

    public function ajax_recover_password()
    {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'metasync_recover_password_nonce')) {
                wp_send_json_error(array('message' => 'Security verification failed.'));
                return;
            }

            $whitelabel_settings = Metasync::get_whitelabel_settings();
            $password = $whitelabel_settings['settings_password'] ?? '';
            $recovery_email = $whitelabel_settings['recovery_email'] ?? '';

            if (empty($password)) {
                wp_send_json_error(array('message' => 'No password is configured for recovery.'));
                return;
            }

            if (empty($recovery_email) || !is_email($recovery_email)) {
                wp_send_json_error(array('message' => 'No valid recovery email is configured. Please contact your administrator.'));
                return;
            }

            $site_name = get_bloginfo('name');
            $site_url = home_url();
            $plugin_name = Metasync::get_effective_plugin_name('');
            $settings_url = admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '&tab=whitelabel');
            $to = $recovery_email;

            $subject = sprintf('[%s] Settings Password Recovery', $site_name);

            $message = sprintf(
                "Hello,\n\n" .
                "A password recovery request was made for the %s settings on %s.\n\n" .
                "Your Settings Password is:\n%s\n\n" .
                "You can use this password to access the protected settings at:\n%s\n\n" .
                "If you did not request this password recovery, please secure your WordPress admin account immediately.\n\n" .
                "---\n" .
                "This is an automated message from %s\n%s",
                $plugin_name,
                $site_name,
                $password,
                $settings_url,
                $site_name,
                $site_url
            );

            $from_name = !empty($whitelabel_settings['company_name'])
                ? $whitelabel_settings['company_name']
                : $site_name;

            $headers = array(
                'Content-Type: text/plain; charset=UTF-8',
                sprintf('From: %s <%s>', $from_name, get_option('admin_email'))
            );

            $mail_error = '';
            add_action('wp_mail_failed', function($error) use (&$mail_error) {
                $mail_error = $error->get_error_message();
            });

            $sent = wp_mail($to, $subject, $message, $headers);

            if ($sent) {
                wp_send_json_success(array(
                    'message' => sprintf('Password recovery email sent to %s', esc_html($recovery_email))
                ));
            } else {
                $error_message = 'Failed to send recovery email. ';
                if (!empty($mail_error)) {
                    $error_message .= 'Error: ' . $mail_error;
                } else {
                    $error_message .= 'Your server may not be configured to send emails. Please check your email configuration or contact your administrator.';
                }

                wp_send_json_error(array('message' => $error_message));
            }

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'MetaSync Password Recovery Error: %s in %s on line %d',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
            }

            wp_send_json_error(array('message' => 'An error occurred while processing your request. Please try again later.'));
        }
    }

    public function ajax_save_theme()
    {
        try {
            # Verify nonce for security
            if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])), 'metasync_theme_nonce')) {
                wp_send_json_error(array('message' => 'Security verification failed.'));
                return;
            }

            # Check user capabilities
            if (!Metasync::current_user_has_plugin_access()) {
                wp_send_json_error(array('message' => 'Insufficient permissions.'));
                return;
            }

            # Get and validate theme value
            $theme = isset($_POST['theme']) ? sanitize_text_field(wp_unslash($_POST['theme'])) : '';
            
            # Validate theme is either 'light' or 'dark'
            if (!in_array($theme, array('light', 'dark'), true)) {
                wp_send_json_error(array('message' => 'Invalid theme value.'));
                return;
            }

            # Save theme preference to WordPress options
            update_option('metasync_theme', $theme, true);

            # Send success response
            wp_send_json_success(array(
                'message' => 'Theme preference saved successfully.',
                'theme' => $theme
            ));
            
        } catch (Exception $e) {
            # Log error if debug is enabled
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MetaSync Theme Save Error: ' . $e->getMessage());
            }
            
            wp_send_json_error(array('message' => 'Failed to save theme preference.'));
        }
    }

    public function ajax_track_one_click_activation() 
    {
        # Check user capabilities
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        # Get parameters
        $auth_method = isset($_POST['auth_method']) ? sanitize_text_field(wp_unslash($_POST['auth_method'])) : 'searchatlas_connect';
        $is_reconnection = isset($_POST['is_reconnection']) ? filter_var($_POST['is_reconnection'], FILTER_VALIDATE_BOOLEAN) : false;

        # Track the event in Mixpanel
        try {
            $mixpanel = Metasync_Mixpanel::get_instance();
            $mixpanel->track_one_click_activation($auth_method, $is_reconnection);

            wp_send_json_success([
                'message' => '1-click activation tracked successfully',
                'auth_method' => $auth_method,
                'is_reconnection' => $is_reconnection
            ]);
        } catch (Exception $e) {
            # Still return success to avoid breaking the auth flow
            wp_send_json_success([
                'message' => 'Authentication successful'
            ]);
        }
    }

    public function handle_export_whitelabel_settings()
    {
        # Check user capabilities
        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('Insufficient permissions');
        }

        # Verify nonce for security (check both GET and POST)
        $nonce = '';
        if (isset($_POST['_wpnonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        } elseif (isset($_GET['_wpnonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));
        }
        
        if (empty($nonce) || !wp_verify_nonce($nonce, 'metasync_export_whitelabel')) {
            wp_die('Security verification failed.');
        }

        try {
            # Get all whitelabel settings
            $whitelabel_settings = Metasync::get_whitelabel_settings();
            
            # Get general settings that relate to whitelabel
            $general_settings = Metasync::get_option('general');
            $whitelabel_related_general = array();

            # Include ALL whitelabel-related general settings
            $whitelabel_keys = array(
                'white_label_plugin_name',
                'white_label_plugin_description',
                'white_label_plugin_author',
                'white_label_plugin_author_uri',
                'white_label_plugin_uri',
                'white_label_plugin_menu_slug',
                'white_label_plugin_menu_icon',
                'whitelabel_otto_name'
            );

            foreach ($whitelabel_keys as $key) {
                if (isset($general_settings[$key])) {
                    $whitelabel_related_general[$key] = $general_settings[$key];
                }
            }
            
            # Bundle the menu icon file so it survives import on a different site.
            # If the icon is a local URL (media library), replace it with a special
            # marker and include the actual file in the ZIP under the plugin folder.
            $bundled_icon_filename = null;
            $icon_url = $whitelabel_related_general['white_label_plugin_menu_icon'] ?? '';
            if (!empty($icon_url) && filter_var($icon_url, FILTER_VALIDATE_URL)) {
                $site_url = trailingslashit(site_url());
                if (strpos($icon_url, $site_url) === 0) {
                    // Resolve URL to an absolute filesystem path
                    $relative_path = str_replace($site_url, ABSPATH, $icon_url);
                    $icon_abs_path = realpath($relative_path);
                    if ($icon_abs_path && file_exists($icon_abs_path)) {
                        $ext = strtolower(pathinfo($icon_abs_path, PATHINFO_EXTENSION));
                        $bundled_icon_filename = 'whitelabel-icon.' . $ext;
                        // Replace the URL with a marker so the importer knows to restore it
                        $whitelabel_related_general['white_label_plugin_menu_icon'] = '__bundled_icon__' . $ext;
                    }
                }
            }

            # Prepare export data
            $export_data = array(
                'version' => '1.0',
                'exported_at' => current_time('mysql'),
                'whitelabel_settings' => $whitelabel_settings,
                'general_settings' => $whitelabel_related_general
            );

            # Convert to JSON
            $json_data = wp_json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            
            if ($json_data === false) {
                wp_die('Failed to encode settings to JSON.');
            }
            
            # Check if ZipArchive is available
            if (!class_exists('ZipArchive')) {
                wp_die('ZipArchive class is not available. Please enable PHP zip extension.');
            }
            
            # Get plugin directory path (remove trailing slash for basename)
            $plugin_dir = rtrim(plugin_dir_path(dirname(__FILE__)), '/');
            $plugin_folder_name = basename($plugin_dir);
            
            # Create temporary directory for zip file
            $upload_dir = wp_upload_dir();
            $temp_dir = $upload_dir['basedir'] . '/metasync-export-temp';
            
            # Create temp directory if it doesn't exist
            if (!file_exists($temp_dir)) {
                wp_mkdir_p($temp_dir);
            }
            
            # Generate unique filename
            $timestamp = date('Y-m-d_H-i-s');
            $zip_filename = 'metasync-whitelabel-plugin-' . $timestamp . '.zip';
            $json_filename = 'whitelabel-settings.json';
            $zip_path = $temp_dir . '/' . $zip_filename;
            
            # Create zip file
            $zip = new ZipArchive();
            if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                wp_die('Failed to create zip file.');
            }
            
            # Add whitelabel settings JSON file to zip (inside plugin folder)
            $zip->addFromString($plugin_folder_name . '/' . $json_filename, $json_data);

            # Bundle icon file if one was detected
            if ($bundled_icon_filename !== null && isset($icon_abs_path) && file_exists($icon_abs_path)) {
                $zip->addFile($icon_abs_path, $plugin_folder_name . '/' . $bundled_icon_filename);
            }


            # Files and directories to exclude from the zip
            $exclude_patterns = array(
                '.git',
                '.gitignore',
                '.gitattributes',
                'node_modules',
                '.DS_Store',
                'Thumbs.db',
                '.idea',
                '.vscode',
                'composer.lock',
                'package-lock.json',
                'yarn.lock',
                '.env',
                '.env.local',
                'docker-compose.yml',
                'Dockerfile',
                'Makefile',
                'renovate.json',
                'sonar-project.properties',
                'CODEOWNERS',
                'metasync-export-temp',
                'whitelabel-settings.json'
            );
            
            # Recursively add plugin files to zip (with plugin folder as root in zip)
            self::add_directory_to_zip($zip, $plugin_dir . '/', $plugin_folder_name . '/', $exclude_patterns);
            
            $zip->close();
            
            # Check if file was created
            if (!file_exists($zip_path)) {
                wp_die('Zip file was not created successfully.');
            }
            
            # Set headers for file download
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
            header('Content-Length: ' . filesize($zip_path));
            header('Pragma: no-cache');
            header('Expires: 0');
            
            # Output file and clean up
            readfile($zip_path);
            unlink($zip_path);
            
            # Clean up temp directory if empty
            if (is_dir($temp_dir) && count(scandir($temp_dir)) == 2) {
                rmdir($temp_dir);
            }
            
            # Exit to prevent WordPress from adding anything to the response
            exit;
            
        } catch (Exception $e) {
            # Log error if debug is enabled
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MetaSync Whitelabel Export Error: ' . $e->getMessage());
            }
            
            wp_die('Failed to export whitelabel settings: ' . $e->getMessage());
        }
    }

    private static function add_directory_to_zip($zip, $dir, $zip_path = '', $exclude_patterns = array())
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = scandir($dir);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $file_path = $dir . $file;
            $zip_file_path = $zip_path . $file;
            
            $should_exclude = false;
            foreach ($exclude_patterns as $pattern) {
                if (strpos($file, $pattern) !== false || strpos($file_path, $pattern) !== false) {
                    $should_exclude = true;
                    break;
                }
            }
            
            if ($should_exclude) {
                continue;
            }
            
            if (is_dir($file_path)) {
                $zip->addEmptyDir($zip_file_path);
                self::add_directory_to_zip($zip, $file_path . '/', $zip_file_path . '/', $exclude_patterns);
            } else {
                if (file_exists($file_path) && is_readable($file_path)) {
                    $zip->addFile($file_path, $zip_file_path);
                }
            }
        }
    }

    public function render_html_pages_dashboard_widget()
    {
        global $wpdb;

        $query = "
            SELECT p.ID, p.post_title, p.post_modified, p.post_type
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_status = 'publish'
            AND p.post_type IN ('post', 'page')
            AND (pm.meta_key = '_metasync_raw_html_enabled' OR pm.meta_key = '_metasync_custom_css')
            GROUP BY p.ID
            ORDER BY p.post_modified DESC
            LIMIT 10
        ";

        $html_pages = $wpdb->get_results($query);
        $total_count = count($html_pages);

        $label = $this->get_html_source_label();

        if (empty($html_pages)) {
            echo '<div class="metasync-dashboard-widget-empty">';
            echo '<span class="dashicons dashicons-admin-page" style="font-size: 48px; opacity: 0.3; display: block; margin: 20px auto;"></span>';
            echo '<p style="text-align: center; color: #666;">';
            echo sprintf(__('No pages created with %s yet.', 'metasync'), '<strong>' . esc_html($label) . '</strong>');
            echo '</p>';
            echo '<p style="text-align: center;">';
            echo '<a href="' . admin_url('admin.php?page=' . Metasync_Admin::$page_slug) . '" class="button button-primary">';
            echo __('Get Started', 'metasync');
            echo '</a>';
            echo '</p>';
            echo '</div>';
            return;
        }

        echo '<div class="metasync-dashboard-widget">';

        echo '<div class="metasync-widget-stats">';
        echo '<div class="metasync-stat-box">';
        echo '<span class="metasync-stat-number">' . $total_count . '</span>';
        echo '<span class="metasync-stat-label">' . __('AI-Generated Pages', 'metasync') . '</span>';
        echo '</div>';
        echo '</div>';

        echo '<div class="metasync-widget-list">';
        echo '<h4>' . __('Recent Pages', 'metasync') . '</h4>';
        echo '<ul>';

        foreach ($html_pages as $page) {
            $edit_link = get_edit_post_link($page->ID);
            $view_link = get_permalink($page->ID);
            $time_ago = human_time_diff(strtotime($page->post_modified), current_time('timestamp'));

            echo '<li class="metasync-widget-page-item">';
            echo '<span class="metasync-page-icon">⚡</span>';
            echo '<div class="metasync-page-details">';
            echo '<a href="' . esc_url($edit_link) . '" class="metasync-page-title">';
            echo esc_html($page->post_title ?: __('(no title)', 'metasync'));
            echo '</a>';
            echo '<span class="metasync-page-meta">';
            echo sprintf(__('Updated %s ago', 'metasync'), $time_ago);
            echo ' • ';
            echo '<a href="' . esc_url($view_link) . '" target="_blank">' . __('View', 'metasync') . '</a>';
            echo '</span>';
            echo '</div>';
            echo '</li>';
        }

        echo '</ul>';
        echo '</div>';

        echo '<div class="metasync-widget-footer">';
        echo '<a href="' . admin_url('edit.php?post_type=page') . '">';
        echo __('View All Pages', 'metasync') . ' →';
        echo '</a>';
        echo '</div>';

        echo '</div>';
    }

    private function get_html_source_label()
    {
        $whitelabel_company = Metasync::get_whitelabel_company_name();
        if (!empty($whitelabel_company)) {
            return $whitelabel_company . ' AI';
        }

        return Metasync::get_effective_plugin_name() . ' AI';
    }
}
