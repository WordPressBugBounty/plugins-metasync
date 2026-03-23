<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Redirections admin page logic extracted from Metasync_Admin.
 *
 * Handles the tabbed redirections / 404-monitor UI, form processing,
 * validation, and related database checks.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */
class Metasync_Redirections_Admin
{
    private static $instance = null;

    /** @var object Redirection database helper */
    private $db_redirection;

    /** @var Metasync_Admin Back-reference used for shared UI helpers */
    private $admin;

    private function __construct($db_redirection, $admin)
    {
        $this->db_redirection = $db_redirection;
        $this->admin          = $admin;
    }

    /**
     * @param object|null       $db_redirection  Required on first call.
     * @param Metasync_Admin|null $admin          Required on first call.
     */
    public static function get_instance($db_redirection = null, $admin = null)
    {
        if (self::$instance === null) {
            self::$instance = new self($db_redirection, $admin);
        }
        return self::$instance;
    }

    /* ------------------------------------------------------------------
     *  Public entry points (called from Metasync_Admin delegation stubs)
     * ------------------------------------------------------------------ */

    public function create_admin_redirections_page()
    {
        $this->handle_redirection_form_processing();

        $this->check_database_structure();

        $this->ensure_404_monitor_table();

        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'redirections';

        $this->add_tabbed_interface_assets();
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->admin->render_plugin_header('Redirections'); ?>
        
        <?php $this->admin->render_navigation_menu('redirections'); ?>
        
        <?php
        $this->render_tab_navigation($current_tab);

        $this->render_tab_content($current_tab);
        ?>
        </div>
        <?php
    }

    public function display_redirection_messages()
    {
        if ($error = get_transient('metasync_redirection_error')) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
            delete_transient('metasync_redirection_error');
        }

        if ($success = get_transient('metasync_redirection_success')) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($success) . '</p></div>';
            delete_transient('metasync_redirection_success');
        }
    }

    /* ------------------------------------------------------------------
     *  Private helpers
     * ------------------------------------------------------------------ */

    private function safe_redirect($url)
    {
        if (!headers_sent()) {
            wp_redirect($url);
            exit;
        } else {
            echo '<script type="text/javascript">window.location.href = "' . esc_url($url) . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url($url) . '"></noscript>';
            exit;
        }
    }

    private function handle_redirection_form_processing()
    {
        if (!isset($_POST['submit'])) {
            return;
        }

        $nonce_valid = false;
        
        if (isset($_POST['metasync_redirection_nonce']) && wp_verify_nonce($_POST['metasync_redirection_nonce'], 'metasync_redirection_form')) {
            $nonce_valid = true;
        }
        elseif (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'metasync_redirection_form')) {
            $nonce_valid = true;
        }
        
        if (!$nonce_valid) {
            wp_die('Security check failed. Please refresh and try again.');
        }
        
        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('Insufficient permissions.');
        }

        $source_urls = isset($_POST['source_url']) ? array_map('sanitize_text_field', $_POST['source_url']) : [];
        $search_types = isset($_POST['search_type']) ? array_map('sanitize_text_field', $_POST['search_type']) : [];
        $destination_url = isset($_POST['destination_url']) ? sanitize_text_field($_POST['destination_url']) : '';
        $redirect_type = isset($_POST['redirect_type']) ? intval($_POST['redirect_type']) : 301;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'active';
        $regex_pattern = isset($_POST['regex_pattern']) ? wp_unslash(trim($_POST['regex_pattern'])) : '';
        $description = isset($_POST['description']) ? sanitize_text_field($_POST['description']) : '';
        $redirect_id = isset($_POST['redirect_id']) ? intval($_POST['redirect_id']) : 0;

        $validation_errors = [];

        if (empty($source_urls)) {
            $validation_errors[] = 'Please enter at least one source URL.';
        } else {
            $processed_sources = [];
            $empty_count = 0;

            foreach ($source_urls as $source_url) {
                $trimmed_url = trim($source_url);

                if (empty($trimmed_url)) {
                    $empty_count++;
                    continue;
                }

                if (!$this->is_valid_url($trimmed_url)) {
                    $validation_errors[] = 'Invalid source URL format: "' . esc_html($trimmed_url) . '". URLs should start with / for relative paths or be complete URLs.';
                }

                if (in_array($trimmed_url, $processed_sources)) {
                    $validation_errors[] = 'Duplicate source URL detected: "' . esc_html($trimmed_url) . '".';
                } else {
                    $processed_sources[] = $trimmed_url;
                }
            }

            if ($empty_count === count($source_urls)) {
                $validation_errors[] = 'All source URL fields are empty. Please enter at least one source URL.';
            }
        }

        $allowed_redirect_types = [301, 302, 307, 410, 451];
        if (!in_array($redirect_type, $allowed_redirect_types)) {
            $validation_errors[] = 'Invalid redirection type selected.';
        }

        if (!in_array($redirect_type, [410, 451])) {
            $trimmed_dest = trim($destination_url);
            if (empty($trimmed_dest)) {
                $validation_errors[] = 'Destination URL is required for this redirect type.';
            } elseif (!$this->is_valid_url($trimmed_dest)) {
                $validation_errors[] = 'Invalid destination URL format. URLs should start with / for relative paths or be complete URLs.';
            }
        }

        $allowed_statuses = ['active', 'inactive'];
        if (!in_array($status, $allowed_statuses)) {
            $validation_errors[] = 'Invalid status selected.';
        }

        if (!empty($validation_errors)) {
            $error_message = implode(' ', $validation_errors);
            set_transient('metasync_redirection_error', $error_message, 45);
            $this->safe_redirect(admin_url('admin.php?page=searchatlas-redirections'));
        }

        $sources_from = [];
        foreach ($source_urls as $index => $source_url) {
            $trimmed_url = trim($source_url);
            if (!empty($trimmed_url)) {
                $search_type = isset($search_types[$index]) ? $search_types[$index] : 'exact';
                $sources_from[$trimmed_url] = $search_type;
            }
        }

        $pattern_type = 'exact';
        foreach ($search_types as $search_type) {
            if (!empty($search_type)) {
                $pattern_type = $search_type;
                break;
            }
        }

        if ($pattern_type === 'regex') {
            if (empty($regex_pattern)) {
                set_transient('metasync_redirection_error', 'Please enter a regex pattern when using "Regex Pattern" as the pattern type.', 45);
                $this->safe_redirect(admin_url('admin.php?page=searchatlas-redirections'));
                return;
            }

            $test_pattern = $regex_pattern;
            $delimiter_chars = ['/', '#', '~', '%', '@'];
            $has_valid_delimiters = false;

            if (strlen($test_pattern) >= 2) {
                $first_char = $test_pattern[0];
                if (in_array($first_char, $delimiter_chars)) {
                    $last_pos = strrpos($test_pattern, $first_char);
                    if ($last_pos > 0) {
                        $has_valid_delimiters = true;
                    }
                }
            }

            if (!$has_valid_delimiters) {
                $test_pattern = '/' . $test_pattern . '/';
            }
            $is_valid = @preg_match($test_pattern, '');            
            if ($is_valid === false) {
                $error_message = error_get_last();
                $error_text = isset($error_message['message']) ? $error_message['message'] : 'Unknown regex error';
                set_transient('metasync_redirection_error', 'Invalid Regex Pattern: ' . $error_text . ' Please fix the regex pattern and try again.', 45);
                $this->safe_redirect(admin_url('admin.php?page=searchatlas-redirections'));
                return;
            }
        }

        $data = [
            'sources_from' => serialize($sources_from),
            'url_redirect_to' => $destination_url,
            'http_code' => $redirect_type,
            'status' => $status,
            'pattern_type' => $pattern_type,
            'regex_pattern' => $regex_pattern,
            'description' => $description,
        ];

        try {
            if ($redirect_id > 0) {
                $result = $this->db_redirection->update($data, $redirect_id);
                if ($result === false) {
                    throw new Exception('Failed to update redirection');
                }
                $message = 'Redirection updated successfully.';
            } else {
                $result = $this->db_redirection->add($data);
                if ($result === false) {
                    throw new Exception('Failed to add redirection');
                }
                $message = 'Redirection added successfully.';
            }
            
        } catch (Exception $e) {
            set_transient('metasync_redirection_error', 'Error saving redirection: ' . $e->getMessage(), 45);
            $this->safe_redirect(admin_url('admin.php?page=searchatlas-redirections'));
        }

        set_transient('metasync_redirection_success', $message, 45);

        $this->safe_redirect(admin_url('admin.php?page=searchatlas-redirections'));
    }

    private function is_valid_url($url)
    {
        if (strpos($url, '/') === 0) {
            return true;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function add_tabbed_interface_assets()
    {
        ?>
        <style>
        /* Root Variables - Dashboard Color Scheme */
        :root {
            --dashboard-bg: #0f1419;
            --dashboard-card-bg: #1a1f26;
            --dashboard-card-hover: #222831;
            --dashboard-text-primary: #ffffff;
            --dashboard-text-secondary: #9ca3af;
            --dashboard-accent: #3b82f6;
            --dashboard-accent-hover: #2563eb;
            --dashboard-success: #10b981;
            --dashboard-warning: #f59e0b;
            --dashboard-error: #ef4444;
            --dashboard-border: #374151;
            --dashboard-gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --dashboard-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.1);
            --dashboard-shadow-hover: 0 20px 25px -5px rgba(0, 0, 0, 0.4), 0 10px 10px -2px rgba(0, 0, 0, 0.2);
        }

        .metasync-tabs {
            margin: 20px 0;
            background: var(--dashboard-card-bg);
            border: 1px solid var(--dashboard-border);
            border-radius: 12px;
            padding: 6px;
            box-shadow: var(--dashboard-shadow);
        }
        
        .metasync-tab-nav {
            border-bottom: none;
            margin: 0;
            padding: 0;
            display: flex;
            gap: 4px;
            background: transparent;
        }
        
        .metasync-tab-nav li {
            display: inline-block;
            margin: 0;
            list-style: none;
        }
        
        .metasync-tab-nav a {
            display: block;
            padding: 12px 20px;
            text-decoration: none;
            color: var(--dashboard-text-secondary);
            border-bottom: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
            font-size: 14px;
            background: transparent;
            position: relative;
            overflow: hidden;
        }
        
        .metasync-tab-nav a:hover {
            color: var(--dashboard-text-primary);
            background: rgba(255, 255, 255, 0.05);
            transform: translateY(-1px);
        }
        
        .metasync-tab-nav a.active {
            color: var(--dashboard-text-primary);
            background: var(--dashboard-card-hover);
            border-bottom: none;
            box-shadow: var(--dashboard-shadow);
            transform: translateY(-1px);
            font-weight: 600;
        }
        
        .metasync-tab-nav a.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--dashboard-accent);
            border-radius: 1px;
        }
        
        .metasync-tab-content {
            display: none;
            padding: 20px 0;
        }
        
        .metasync-tab-content.active {
            display: block;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            function switchToTab(targetTab) {
                $('.metasync-tab-nav a').removeClass('active');
                $('.metasync-tab-nav a[data-tab="' + targetTab + '"]').addClass('active');
                
                $('.metasync-tab-content').removeClass('active');
                $('#' + targetTab + '-content').addClass('active');
            }
            
            function initializeTabs() {
                var urlParams = new URLSearchParams(window.location.search);
                var currentTab = urlParams.get('tab');

                if (currentTab && (currentTab === 'redirections' || currentTab === '404-monitor')) {
                    switchToTab(currentTab);
                } else {
                    switchToTab('redirections');
                    currentTab = 'redirections';
                }

                var needsCleanup = false;
                if (currentTab === '404-monitor') {
                    if (urlParams.has('paged') || urlParams.has('paged_redir')) {
                        urlParams.delete('paged');
                        urlParams.delete('paged_redir');
                        needsCleanup = true;
                    }
                } else if (currentTab === 'redirections') {
                    if (urlParams.has('paged') || urlParams.has('paged_404')) {
                        urlParams.delete('paged');
                        urlParams.delete('paged_404');
                        needsCleanup = true;
                    }
                }

                if (needsCleanup) {
                    var newUrl = window.location.pathname + '?' + urlParams.toString();
                    window.history.replaceState({}, '', newUrl);
                }
            }

            initializeTabs();

            setTimeout(initializeTabs, 100);
            
            $('.metasync-tab-nav a').on('click', function(e) {
                e.preventDefault();

                var targetTab = $(this).data('tab');
                switchToTab(targetTab);

                var url = new URL(window.location);
                url.searchParams.set('tab', targetTab);

                url.searchParams.delete('paged');
                url.searchParams.delete('paged_404');
                url.searchParams.delete('paged_redir');

                window.history.pushState({}, '', url);
            });
        });
        </script>
        <?php
    }

    private function render_tab_navigation($current_tab)
    {
        $base_url = admin_url('admin.php?page=searchatlas-redirections');
        
        ?>
        <div class="metasync-tabs">
            <ul class="metasync-tab-nav">
                <li>
                    <a href="<?php echo esc_url($base_url . '&tab=redirections'); ?>" 
                       data-tab="redirections" 
                       class="<?php echo $current_tab === 'redirections' ? 'active' : ''; ?>">
                        Redirections
                    </a>
                </li>
                <li>
                    <a href="<?php echo esc_url($base_url . '&tab=404-monitor'); ?>" 
                       data-tab="404-monitor" 
                       class="<?php echo $current_tab === '404-monitor' ? 'active' : ''; ?>">
                        404 Monitor
                    </a>
                </li>
            </ul>
        </div>
        <?php
    }

    private function render_tab_content($current_tab)
    {
        $this->render_redirections_tab($current_tab);
        $this->render_404_monitor_tab($current_tab);
    }

    private function render_redirections_tab($current_tab = null)
    {
        if ($current_tab === null) {
            $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'redirections';
        }
        $active_class = $current_tab === 'redirections' ? 'active' : '';
        
        ?>
        <div id="redirections-content" class="metasync-tab-content <?php echo $active_class; ?>">
            <?php
            $redirection = new Metasync_Redirection($this->db_redirection);
            $redirection->create_admin_redirection_interface();
            ?>
        </div>
        <?php
    }

    private function render_404_monitor_tab($current_tab = null)
    {
        if ($current_tab === null) {
            $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'redirections';
        }
        $active_class = $current_tab === '404-monitor' ? 'active' : '';
        
        ?>
        <div id="404-monitor-content" class="metasync-tab-content <?php echo $active_class; ?>">
            <?php
            try {
                require_once plugin_dir_path(dirname(__FILE__)) . '404-monitor/class-metasync-404-monitor-database.php';
                require_once plugin_dir_path(dirname(__FILE__)) . '404-monitor/class-metasync-404-monitor.php';
                
                $db_404 = new Metasync_Error_Monitor_Database();
                $ErrorMonitor = new Metasync_Error_Monitor($db_404);
                
                $ErrorMonitor->create_admin_plugin_interface();
                
            } catch (Exception $e) {
                echo '<div class="notice notice-error"><p>Error loading 404 monitor: ' . esc_html($e->getMessage()) . '</p></div>';
                error_log('MetaSync 404 Monitor Error: ' . $e->getMessage());
            } catch (Error $e) {
                echo '<div class="notice notice-error"><p>Fatal error loading 404 monitor: ' . esc_html($e->getMessage()) . '</p></div>';
                error_log('MetaSync 404 Monitor Fatal Error: ' . $e->getMessage());
            }
            ?>
        </div>
        <?php
    }

    private function ensure_404_monitor_table()
    {
        global $wpdb;
        require_once plugin_dir_path(dirname(__FILE__)) . '404-monitor/class-metasync-404-monitor-database.php';
        
        $table_name = $wpdb->prefix . Metasync_Error_Monitor_Database::$table_name;
        
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'database/class-db-migrations.php';
            MetaSync_DBMigration::run_migrations();
        }
    }

    private function check_database_structure()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'metasync_redirections';
        
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
            return;
        }
        
        $columns = $wpdb->get_col("DESCRIBE {$table_name}");
        $required_columns = ['pattern_type', 'regex_pattern', 'description', 'created_at', 'updated_at', 'last_accessed_at'];
        
        $missing_columns = array_diff($required_columns, $columns);
        
        if (!empty($missing_columns)) {
            add_action('admin_notices', function() use ($missing_columns) {
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p><strong>MetaSync:</strong> Database structure needs updating. Missing columns: ' . implode(', ', $missing_columns) . '</p>';
                echo '<p><button type="button" class="button button-secondary" onclick="updateDatabaseStructure()">Update Database Structure</button></p>';
                echo '</div>';
                
                echo '<script>
                function updateDatabaseStructure() {
                    if (confirm("This will update your database structure. Continue?")) {
                        const formData = new FormData();
                        formData.append("action", "metasync_update_db_structure");
                        formData.append("nonce", "' . wp_create_nonce('metasync_update_db_nonce') . '");
                        
                        fetch(ajaxurl, {
                            method: "POST",
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert("Database structure updated successfully!");
                                location.reload();
                            } else {
                                alert("Error updating database: " + (data.data || "Unknown error"));
                            }
                        })
                        .catch(error => {
                            alert("Error updating database: " + error.message);
                        });
                    }
                }
                </script>';
            });
        }
    }
}
