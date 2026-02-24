<?php
/**
 * OTTO Debug Page for MetaSync Plugin
 * 
 * This class provides comprehensive diagnostics for OTTO functionality
 * to help developers troubleshoot why OTTO changes are not being applied.
 * 
 * @package MetaSync
 * @subpackage MetaSync/admin
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Otto_Debug {
    
    /**
     * The plugin name
     */
    private $plugin_name;
    
    /**
     * The plugin version
     */
    private $version;
    
    /**
     * Constructor
     */
    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_debug_menu'));
        
        // Add magic word handler
        add_action('admin_init', array($this, 'handle_magic_word_access'));
        
        // Add AJAX handlers
        add_action('wp_ajax_metasync_otto_debug_test_api', array($this, 'ajax_test_otto_api'));
        add_action('wp_ajax_metasync_otto_debug_test_notification', array($this, 'ajax_test_notification_endpoint'));
        add_action('wp_ajax_metasync_otto_debug_clear_cache', array($this, 'ajax_clear_otto_cache'));
        add_action('wp_ajax_metasync_otto_debug_simulate_crawl', array($this, 'ajax_simulate_crawl_notification'));
        add_action('wp_ajax_metasync_otto_debug_test_url', array($this, 'ajax_test_specific_url'));
        add_action('wp_ajax_metasync_otto_debug_emulate_changes', array($this, 'ajax_emulate_otto_changes'));
        add_action('wp_ajax_metasync_otto_debug_simple_test', array($this, 'ajax_simple_test'));
        add_action('wp_ajax_metasync_otto_debug_test_db_permissions', array($this, 'ajax_test_db_permissions'));
    }
    
    
    /**
     * Add debug menu (developer only - hidden by default)
     */
    public function add_debug_menu() {
        // Check if user has developer capabilities
        if (!Metasync::current_user_has_plugin_access()) {
            return;
        }
        
        // Check if debug access is enabled via magic word
        if (!$this->is_debug_access_enabled()) {
            return;
        }
        
        $menu_slug = Metasync_Admin::$page_slug;
        
        add_submenu_page(
            $menu_slug,
            Metasync::get_whitelabel_otto_name() . ' Debug',
            Metasync::get_whitelabel_otto_name() . ' Debug',
            'manage_options',
            $menu_slug . '-otto-debug',
            array($this, 'create_debug_page')
        );
    }
    
    /**
     * Add magic word handler for direct access
     */
    public function handle_magic_word_access() {
        // Check if magic word is provided
        if (isset($_GET['metasync_debug']) && $_GET['metasync_debug'] === 'abracadabra@2020') {
            // Enable debug access via user meta (persistent, no sessions needed)
            $current_user = wp_get_current_user();
            if ($current_user && $current_user->ID) {
                update_user_meta($current_user->ID, 'metasync_debug_enabled', 'true');
            }

            // Redirect to admin with debug access enabled
            $redirect_url = admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-otto-debug');
            wp_redirect($redirect_url);
            exit;
        }
    }
    
    /**
     * Check if debug access is enabled via magic word system
     * Hidden from regular users, only accessible with secret key
     */
    private function is_debug_access_enabled() {
        // Magic word for developer access
        $magic_word = 'abracadabra@2020';

        // Check if magic word is provided in URL parameter
        if (isset($_GET['metasync_debug']) && $_GET['metasync_debug'] === $magic_word) {
            // Enable debug access via user meta (persistent, no sessions needed)
            $current_user = wp_get_current_user();
            if ($current_user && $current_user->ID) {
                update_user_meta($current_user->ID, 'metasync_debug_enabled', 'true');
            }
            return true;
        }

        // Check if debug access is enabled via user meta (for persistent access)
        $current_user = wp_get_current_user();
        if ($current_user && $current_user->ID) {
            $debug_enabled = get_user_meta($current_user->ID, 'metasync_debug_enabled', true);
            if ($debug_enabled === 'true') {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Check if current user is a developer
     * Multiple methods to identify developers without requiring WP_DEBUG
     */
    private function is_developer_user($user) {
        // Method 1: Check for specific user meta
        $is_developer = get_user_meta($user->ID, 'metasync_developer', true);
        if ($is_developer === 'true') {
            return true;
        }
        
        // Method 2: Check for specific user roles
        $developer_roles = array('administrator', 'developer', 'super_admin');
        $user_roles = $user->roles;
        
        foreach ($developer_roles as $role) {
            if (in_array($role, $user_roles)) {
                return true;
            }
        }
        
        // Method 3: Check for specific capabilities
        if ($user->has_cap('manage_options') && $user->has_cap('edit_plugins')) {
            return true;
        }
        
        // Method 4: Check for specific email domains (optional)
        $email_domain = substr(strrchr($user->user_email, "@"), 1);
        $developer_domains = array('searchatlas.com', 'yourcompany.com'); // Add your company domains
        
        if (in_array($email_domain, $developer_domains)) {
            return true;
        }
        
        // Method 5: Check for specific username patterns
        $username_patterns = array('/^dev_/', '/^admin_/', '/^support_/');
        foreach ($username_patterns as $pattern) {
            if (preg_match($pattern, $user->user_login)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Helper function to enable developer access for a specific user
     * Call this function to grant debug access to a user
     * 
     * Usage: Metasync_Otto_Debug::enable_developer_access($user_id);
     */
    public static function enable_developer_access($user_id) {
        update_user_meta($user_id, 'metasync_debug_enabled', 'true');
    }
    
    /**
     * Helper function to disable developer access for a specific user
     * 
     * Usage: Metasync_Otto_Debug::disable_developer_access($user_id);
     */
    public static function disable_developer_access($user_id) {
        delete_user_meta($user_id, 'metasync_debug_enabled');
    }
    
    /**
     * Get the magic word for debug access
     * 
     * Usage: Metasync_Otto_Debug::get_magic_word();
     */
    public static function get_magic_word() {
        return 'abracadabra@2020';
    }
    
    /**
     * Generate debug access URL
     * 
     * Usage: Metasync_Otto_Debug::get_debug_access_url();
     */
    public static function get_debug_access_url() {
        $admin_url = admin_url('admin.php');
        $magic_word = self::get_magic_word();
        return add_query_arg('metasync_debug', $magic_word, $admin_url);
    }
    
    /**
     * Quick enable debug access for current user
     * 
     * Usage: Metasync_Otto_Debug::quick_enable_debug();
     */
    public static function quick_enable_debug() {
        $current_user = wp_get_current_user();
        if ($current_user && $current_user->ID) {
            update_user_meta($current_user->ID, 'metasync_debug_enabled', 'true');
            return true;
        }
        return false;
    }
    
    /**
     * Create the debug page
     */
    public function create_debug_page() {
        // Check if this is a magic word access request
        if (isset($_GET['metasync_debug']) && $_GET['metasync_debug'] === 'abracadabra@2020') {
            // Enable debug access via user meta (persistent, no sessions needed)
            $current_user = wp_get_current_user();
            if ($current_user && $current_user->ID) {
                update_user_meta($current_user->ID, 'metasync_debug_enabled', 'true');
            }

            // Show access granted message
            $this->show_access_granted_page();
            return;
        }

        $whitelabel_otto_name = Metasync::get_whitelabel_otto_name();
        ?>
        <div class="wrap metasync-otto-debug">
            <h1><?php echo esc_html($whitelabel_otto_name); ?> Debug & Diagnostics</h1>
            <p class="description">Comprehensive diagnostics for <?php echo esc_html($whitelabel_otto_name); ?> functionality. This page helps identify why <?php echo esc_html($whitelabel_otto_name); ?> changes may not be applied.</p>
            
            <div class="metasync-debug-container">
                <?php $this->render_developer_access_status(); ?>
                <?php $this->render_url_testing_section(); ?>
                <?php $this->render_configuration_status(); ?>
                <?php $this->render_notification_endpoint_status(); ?>
                <?php $this->render_api_connectivity_status(); ?>
                <?php $this->render_crawl_data_status(); ?>
                <?php $this->render_processing_status(); ?>
                <?php $this->render_debug_tools(); ?>
            </div>
        </div>
        
        <style>
        .metasync-debug-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        
        .debug-section {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        
        .debug-section h3 {
            margin-top: 0;
            color: #23282d;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .status-success { background-color: #46b450; }
        .status-warning { background-color: #ffb900; }
        .status-error { background-color: #dc3232; }
        .status-info { background-color: #00a0d2; }
        
        .debug-item {
            margin: 10px 0;
            padding: 8px;
            background: #f9f9f9;
            border-left: 4px solid #ddd;
        }
        
        .debug-item.success { border-left-color: #46b450; }
        .debug-item.warning { border-left-color: #ffb900; }
        .debug-item.error { border-left-color: #dc3232; }
        .debug-item.info { border-left-color: #00a0d2; }
        
        .debug-value {
            font-family: monospace;
            background: #fff;
            padding: 4px 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
            word-break: break-all;
        }
        
        .debug-tools {
            grid-column: 1 / -1;
        }
        
        .debug-button {
            background: #0073aa;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 3px;
            cursor: pointer;
            margin: 5px;
        }
        
        .debug-button:hover {
            background: #005a87;
        }
        
        .debug-button.danger {
            background: #dc3232;
        }
        
        .debug-button.danger:hover {
            background: #a00;
        }
        
        .debug-results {
            margin-top: 15px;
            padding: 15px;
            background: #f1f1f1;
            border-radius: 3px;
            display: none;
        }
        
        .debug-results.show {
            display: block;
        }
        
        .debug-results .error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }
        
        .json-output {
            background: #fff;
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 3px;
            font-family: monospace;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Test API connectivity
            $('#test-otto-api').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('Testing...');
                
                $.post(ajaxurl, {
                    action: 'metasync_otto_debug_test_api',
                    nonce: '<?php echo wp_create_nonce('metasync_otto_debug'); ?>'
                }, function(response) {
                    $('#api-test-results').html('<div class="json-output">' + JSON.stringify(response, null, 2) + '</div>').show();
                    button.prop('disabled', false).text('Test API Connectivity');
                });
            });
            
            // Test notification endpoint
            $('#test-notification-endpoint').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('Testing...');
                
                $.post(ajaxurl, {
                    action: 'metasync_otto_debug_test_notification',
                    nonce: '<?php echo wp_create_nonce('metasync_otto_debug'); ?>'
                }, function(response) {
                    $('#notification-test-results').html('<div class="json-output">' + JSON.stringify(response, null, 2) + '</div>').show();
                    button.prop('disabled', false).text('Test Notification Endpoint');
                });
            });
            
            // Clear OTTO cache
            $('#clear-otto-cache').on('click', function() {
                if (!confirm('Are you sure you want to clear all OTTO cache data?')) {
                    return;
                }
                
                var button = $(this);
                button.prop('disabled', true).text('Clearing...');
                
                $.post(ajaxurl, {
                    action: 'metasync_otto_debug_clear_cache',
                    nonce: '<?php echo wp_create_nonce('metasync_otto_debug'); ?>'
                }, function(response) {
                    $('#cache-clear-results').html('<div class="json-output">' + JSON.stringify(response, null, 2) + '</div>').show();
                    button.prop('disabled', false).text('Clear OTTO Cache');
                });
            });
            
            // Simulate crawl notification
            $('#simulate-crawl').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('Simulating...');
                
                $.post(ajaxurl, {
                    action: 'metasync_otto_debug_simulate_crawl',
                    nonce: '<?php echo wp_create_nonce('metasync_otto_debug'); ?>'
                }, function(response) {
                    $('#crawl-simulate-results').html('<div class="json-output">' + JSON.stringify(response, null, 2) + '</div>').show();
                    button.prop('disabled', false).text('Simulate Crawl Notification');
                });
            });
            
            // Test specific URL
            $('#test-specific-url').on('click', function() {
                var button = $(this);
                var testUrl = $('#test-url-input').val();
                
                if (!testUrl) {
                    alert('Please enter a URL to test');
                    return;
                }
                
                button.prop('disabled', true).text('Testing...');
                
                console.log('Testing URL:', testUrl);
                
                $.post(ajaxurl, {
                    action: 'metasync_otto_debug_test_url',
                    nonce: '<?php echo wp_create_nonce('metasync_otto_debug'); ?>',
                    test_url: testUrl
                }, function(response) {
                    console.log('URL Test Response:', response);
                    $('#url-test-results').html('<div class="json-output">' + JSON.stringify(response, null, 2) + '</div>').show();
                    button.prop('disabled', false).text('Test URL & Check All Points');
                }).fail(function(xhr, status, error) {
                    console.error('URL Test Error:', error, xhr.responseText);
                    $('#url-test-results').html('<div class="error">Error: ' + error + '<br>Response: ' + xhr.responseText + '</div>').show();
                    button.prop('disabled', false).text('Test URL & Check All Points');
                });
            });
            
            // Emulate OTTO changes
            $('#emulate-otto-changes').on('click', function() {
                var button = $(this);
                var testUrl = $('#test-url-input').val();
                
                if (!testUrl) {
                    alert('Please enter a URL to test');
                    return;
                }
                
                button.prop('disabled', true).text('Emulating...');
                
                console.log('Emulating OTTO changes for URL:', testUrl);
                
                $.post(ajaxurl, {
                    action: 'metasync_otto_debug_emulate_changes',
                    nonce: '<?php echo wp_create_nonce('metasync_otto_debug'); ?>',
                    test_url: testUrl
                }, function(response) {
                    console.log('Emulation Response:', response);
                    $('#emulation-results').html('<div class="json-output">' + JSON.stringify(response, null, 2) + '</div>').show();
                    button.prop('disabled', false).text('Emulate OTTO Changes');
                }).fail(function(xhr, status, error) {
                    console.error('Emulation Error:', error, xhr.responseText);
                    $('#emulation-results').html('<div class="error">Error: ' + error + '<br>Response: ' + xhr.responseText + '</div>').show();
                    button.prop('disabled', false).text('Emulate OTTO Changes');
                });
            });
            
            // Simple AJAX test
            $('#simple-ajax-test').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('Testing...');
                
                console.log('Testing basic AJAX connection');
                
                $.post(ajaxurl, {
                    action: 'metasync_otto_debug_simple_test',
                    nonce: '<?php echo wp_create_nonce('metasync_otto_debug'); ?>'
                }, function(response) {
                    console.log('Simple Test Response:', response);
                    $('#url-test-results').html('<div class="json-output">' + JSON.stringify(response, null, 2) + '</div>').show();
                    button.prop('disabled', false).text('Test AJAX Connection');
                }).fail(function(xhr, status, error) {
                    console.error('Simple Test Error:', error, xhr.responseText);
                    $('#url-test-results').html('<div class="error">AJAX Error: ' + error + '<br>Response: ' + xhr.responseText + '</div>').show();
                    button.prop('disabled', false).text('Test AJAX Connection');
                });
            });
            
            // Test database permissions
            $('#test-db-permissions').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('Testing...');
                
                console.log('Testing database permissions');
                
                $.post(ajaxurl, {
                    action: 'metasync_otto_debug_test_db_permissions',
                    nonce: '<?php echo wp_create_nonce('metasync_otto_debug'); ?>'
                }, function(response) {
                    console.log('DB Permissions Response:', response);
                    $('#db-permissions-results').html('<div class="json-output">' + JSON.stringify(response, null, 2) + '</div>').show();
                    button.prop('disabled', false).text('Test Database Permissions');
                }).fail(function(xhr, status, error) {
                    console.error('DB Permissions Error:', error, xhr.responseText);
                    $('#db-permissions-results').html('<div class="error">Error: ' + error + '<br>Response: ' + xhr.responseText + '</div>').show();
                    button.prop('disabled', false).text('Test Database Permissions');
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Show access granted page
     */
    private function show_access_granted_page() {
        $whitelabel_otto_name = Metasync::get_whitelabel_otto_name();
        $current_user = wp_get_current_user();
        ?>
        <div class="wrap">
            <h1>🔑 <?php echo esc_html($whitelabel_otto_name); ?> Debug Access Granted</h1>
            
            <div class="notice notice-success">
                <p><strong>✅ Debug Access Enabled!</strong></p>
                <p>You now have access to the <?php echo esc_html($whitelabel_otto_name); ?> Debug page.</p>
            </div>
            
            <div class="card">
                <h2>🎯 Next Steps</h2>
                <ol>
                    <li><strong>Access Debug Page:</strong> The "<?php echo esc_html($whitelabel_otto_name); ?> Debug" menu should now be visible in the MetaSync plugin menu</li>
                    <li><strong>Use Diagnostics:</strong> Click on "<?php echo esc_html($whitelabel_otto_name); ?> Debug" to access comprehensive diagnostic tools</li>
                    <li><strong>Troubleshoot Issues:</strong> Use the debug tools to identify why <?php echo esc_html($whitelabel_otto_name); ?> changes may not be applied</li>
                </ol>
            </div>
            
            <div class="card">
                <h2>🔧 Access Information</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Magic Word</th>
                        <td><code>abracadabra@2020</code></td>
                    </tr>
                    <tr>
                        <th scope="row">Current User</th>
                        <td><?php echo esc_html($current_user->user_login); ?> (ID: <?php echo $current_user->ID; ?>)</td>
                    </tr>
                    <tr>
                        <th scope="row">Access Type</th>
                        <td>User Meta (persistent)</td>
                    </tr>
                    <tr>
                        <th scope="row">Debug Page URL</th>
                        <td><code><?php echo esc_html(admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-otto-debug')); ?></code></td>
                    </tr>
                </table>
            </div>

            <div class="card">
                <h2>🔒 Security Notes</h2>
                <ul>
                    <li><strong>Persistent Access:</strong> This access is stored in your user profile and persists across sessions</li>
                    <li><strong>Magic Word:</strong> Keep the magic word <code>abracadabra@2020</code> confidential</li>
                    <li><strong>Developer Only:</strong> This debug page is intended for plugin developers only</li>
                    <li><strong>To Revoke Access:</strong> Use <code>Metasync_Otto_Debug::disable_developer_access(<?php echo $current_user->ID; ?>)</code></li>
                </ul>
            </div>
            
            <div class="card">
                <h2>🚀 Quick Access</h2>
                <p>To access the debug page directly, use this URL:</p>
                <p><a href="<?php echo esc_url(admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-otto-debug')); ?>" class="button button-primary">Open <?php echo esc_html($whitelabel_otto_name); ?> Debug Page</a></p>
                
                <p>Or add the magic word to any WordPress admin URL:</p>
                <p><code>?metasync_debug=abracadabra@2020</code></p>
            </div>
            
            <style>
            .card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                margin: 20px 0;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            .card h2 {
                margin-top: 0;
                color: #23282d;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
            }
            .form-table th {
                width: 200px;
            }
            </style>
        </div>
        <?php
    }
    
    /**
     * Render developer access status section
     */
    private function render_developer_access_status() {
        $current_user = wp_get_current_user();
        $is_debug_enabled = $this->is_debug_access_enabled();
        $debug_meta = get_user_meta($current_user->ID, 'metasync_debug_enabled', true);
        
        ?>
        <div class="debug-section">
            <h3><span class="status-indicator <?php echo $is_debug_enabled ? 'status-success' : 'status-warning'; ?>"></span>Developer Access Status</h3>
            
            <div class="debug-item <?php echo $is_debug_enabled ? 'success' : 'warning'; ?>">
                <strong>Debug Access:</strong>
                <span class="debug-value"><?php echo $is_debug_enabled ? 'Granted' : 'Not Granted'; ?></span>
            </div>
            
            <div class="debug-item info">
                <strong>Current User:</strong>
                <span class="debug-value"><?php echo esc_html($current_user->user_login); ?> (ID: <?php echo $current_user->ID; ?>)</span>
            </div>
            
            <div class="debug-item info">
                <strong>User Roles:</strong>
                <span class="debug-value"><?php echo implode(', ', $current_user->roles); ?></span>
            </div>
            
            <div class="debug-item info">
                <strong>Email Domain:</strong>
                <span class="debug-value"><?php echo esc_html(substr(strrchr($current_user->user_email, "@"), 1)); ?></span>
            </div>
            
            <div class="debug-item info">
                <strong>Debug Meta:</strong>
                <span class="debug-value"><?php echo $debug_meta ? esc_html($debug_meta) : 'Not Set'; ?></span>
            </div>
            
            <?php if (!$is_debug_enabled): ?>
            <div class="debug-item warning">
                <strong>Enable Debug Access:</strong>
                <p><strong>Method 1 - Magic Word (Recommended):</strong></p>
                <p>Add this parameter to any WordPress admin URL:</p>
                <div class="debug-value">
                    <code>?metasync_debug=abracadabra@2020</code>
                </div>
                <p><strong>Example:</strong> <code><?php echo esc_html(admin_url('admin.php?metasync_debug=abracadabra@2020')); ?></code></p>
                
                <p><strong>Method 2 - User Meta:</strong></p>
                <p>Run this code in WordPress:</p>
                <div class="debug-value">
                    <code>Metasync_Otto_Debug::enable_developer_access(<?php echo $current_user->ID; ?>);</code>
                </div>
                
                <p><strong>Method 3 - WordPress CLI:</strong></p>
                <div class="debug-value">
                    <code>wp user meta update <?php echo $current_user->ID; ?> metasync_debug_enabled true</code>
                </div>
            </div>
            <?php else: ?>
            <div class="debug-item success">
                <strong>Debug Access Active</strong>
                <p>You have access to all <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> debug tools and diagnostics.</p>
                <p><strong>Magic Word:</strong> <code>abracadabra@2020</code></p>
                <p><strong>Access URL:</strong> <code><?php echo esc_html(self::get_debug_access_url()); ?></code></p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render URL testing section
     */
    private function render_url_testing_section() {
        $whitelabel_otto_name = Metasync::get_whitelabel_otto_name();
        $site_url = get_site_url();
        ?>
        <div class="debug-section debug-tools">
            <h3><span class="status-indicator status-info"></span>URL Testing & <?php echo esc_html($whitelabel_otto_name); ?> Emulation</h3>

            <div class="debug-item info">
                <strong>Test Specific URL:</strong>
                <p>Enter any URL from your site to test <?php echo esc_html($whitelabel_otto_name); ?> functionality and check all diagnostic points.</p>
            </div>

            <div style="margin-bottom: 20px;">
                <label for="test-url-input"><strong>URL to Test:</strong></label><br>
                <input type="url" id="test-url-input" placeholder="<?php echo esc_attr($site_url); ?>/sample-page/" style="width: 100%; padding: 8px; margin: 5px 0;" />
                <br>
                <button id="test-specific-url" class="debug-button">Test URL & Check All Points</button>
                <button id="emulate-otto-changes" class="debug-button">Emulate <?php echo esc_html($whitelabel_otto_name); ?> Changes</button>
                <button id="simple-ajax-test" class="debug-button">Test AJAX Connection</button>
            </div>
            
            <div id="url-test-results" class="debug-results"></div>
            <div id="emulation-results" class="debug-results"></div>
            
            <div class="debug-item info">
                <strong>What This Tests:</strong>
                <ul style="margin-left: 20px;">
                    <li>URL accessibility and response</li>
                    <li><?php echo esc_html($whitelabel_otto_name); ?> API data for the specific URL</li>
                    <li>Crawl status and processing eligibility</li>
                    <li>Page type detection and exclusions</li>
                    <li><?php echo esc_html($whitelabel_otto_name); ?> recommendations and changes</li>
                    <li>Error simulation and validation</li>
                </ul>
            </div>
            
            <div class="debug-item warning">
                <strong>Database Permissions Check:</strong>
                <p>Testing if Action Scheduler can schedule jobs...</p>
                <button id="test-db-permissions" class="debug-button">Test Database Permissions</button>
                <div id="db-permissions-results" class="debug-results"></div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render configuration status section
     */
    private function render_configuration_status() {
        $whitelabel_otto_name = Metasync::get_whitelabel_otto_name();
        $general_options = Metasync::get_option('general');
        
        // OTTO SSR is always enabled by default
        $otto_enabled = true;
        $otto_uuid = $general_options['otto_pixel_uuid'] ?? '';
        $otto_disable_loggedin = $general_options['otto_disable_on_loggedin'] ?? false;
        
        ?>
        <div class="debug-section">
            <h3><span class="status-indicator <?php echo !empty($otto_uuid) ? 'status-success' : 'status-error'; ?>"></span>Configuration Status</h3>
            
            <div class="debug-item success">
                <strong><?php echo esc_html($whitelabel_otto_name); ?> SSR Enabled:</strong>
                <span class="debug-value">Yes (Always Active)</span>
            </div>
            
            <div class="debug-item <?php echo !empty($otto_uuid) ? 'success' : 'error'; ?>">
                <strong><?php echo esc_html($whitelabel_otto_name); ?> UUID:</strong>
                <span class="debug-value"><?php echo !empty($otto_uuid) ? esc_html($otto_uuid) : 'Not Set'; ?></span>
            </div>
            
            <div class="debug-item <?php echo $otto_disable_loggedin ? 'warning' : 'info'; ?>">
                <strong>Disable for Logged-in Users:</strong>
                <span class="debug-value"><?php echo $otto_disable_loggedin ? 'Yes' : 'No'; ?></span>
            </div>
            
            <div class="debug-item info">
                <strong>Current User Logged In:</strong>
                <span class="debug-value"><?php echo is_user_logged_in() ? 'Yes' : 'No'; ?></span>
            </div>
            
            <div class="debug-item info">
                <strong>Plugin Version:</strong>
                <span class="debug-value"><?php echo defined('METASYNC_VERSION') ? METASYNC_VERSION : 'Unknown'; ?></span>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render notification endpoint status
     */
    private function render_notification_endpoint_status() {
        $rest_url = rest_url('metasync/v1/otto_crawl_notify');
        $site_url = get_site_url();
        
        ?>
        <div class="debug-section">
            <h3><span class="status-indicator status-info"></span>Notification Endpoint Status</h3>
            
            <div class="debug-item info">
                <strong>REST API Base URL:</strong>
                <span class="debug-value"><?php echo esc_html($site_url); ?></span>
            </div>
            
            <div class="debug-item info">
                <strong><?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> Notification Endpoint:</strong>
                <span class="debug-value"><?php echo esc_html($rest_url); ?></span>
            </div>
            
            <div class="debug-item <?php echo function_exists('rest_url') ? 'success' : 'error'; ?>">
                <strong>REST API Available:</strong>
                <span class="debug-value"><?php echo function_exists('rest_url') ? 'Yes' : 'No'; ?></span>
            </div>
            
            <div class="debug-item <?php echo $this->is_rest_endpoint_registered() ? 'success' : 'error'; ?>">
                <strong>Endpoint Registered:</strong>
                <span class="debug-value"><?php echo $this->is_rest_endpoint_registered() ? 'Yes' : 'No'; ?></span>
            </div>
            
            <div class="debug-item info">
                <strong>Expected Method:</strong>
                <span class="debug-value">POST</span>
            </div>
            
            <div class="debug-item info">
                <strong>Expected JSON Fields:</strong>
                <span class="debug-value">domain, urls</span>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render API connectivity status
     */
    private function render_api_connectivity_status() {
        $general_options = Metasync::get_option('general');
        $otto_uuid = $general_options['otto_pixel_uuid'] ?? '';

        # Use endpoint manager to get the correct API URL
        $api_url = class_exists('Metasync_Endpoint_Manager')
            ? Metasync_Endpoint_Manager::get_endpoint('OTTO_URL_DETAILS')
            : 'https://sa.searchatlas.com/api/v2/otto-url-details';
        $test_url = add_query_arg(array(
            'url' => get_site_url(),
            'uuid' => $otto_uuid
        ), $api_url);
        
        ?>
        <div class="debug-section">
            <h3><span class="status-indicator status-info"></span>API Connectivity Status</h3>
            
            <div class="debug-item info">
                <strong><?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> API Endpoint:</strong>
                <span class="debug-value"><?php echo esc_html($api_url); ?></span>
            </div>
            
            <div class="debug-item info">
                <strong>Test URL:</strong>
                <span class="debug-value"><?php echo esc_html($test_url); ?></span>
            </div>
            
            <div class="debug-item <?php echo $this->can_reach_otto_api() ? 'success' : 'error'; ?>">
                <strong>API Reachable:</strong>
                <span class="debug-value"><?php echo $this->can_reach_otto_api() ? 'Yes' : 'No'; ?></span>
            </div>
            
            <div class="debug-item info">
                <strong>SSL Verification:</strong>
                <span class="debug-value">Enabled</span>
            </div>
            
            <div class="debug-item info">
                <strong>Timeout:</strong>
                <span class="debug-value">30 seconds</span>
            </div>
            
            <div class="debug-item info">
                <strong>User Agent:</strong>
                <span class="debug-value">MetaSync-WordPress-Plugin/1.0</span>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render crawl data status
     */
    private function render_crawl_data_status() {
        $crawl_data = get_option('metasync_otto_crawldata');
        
        ?>
        <div class="debug-section">
            <h3><span class="status-indicator <?php echo !empty($crawl_data) ? 'status-success' : 'status-warning'; ?>"></span>Crawl Data Status</h3>
            
            <div class="debug-item <?php echo !empty($crawl_data) ? 'success' : 'warning'; ?>">
                <strong>Crawl Data Available:</strong>
                <span class="debug-value"><?php echo !empty($crawl_data) ? 'Yes' : 'No'; ?></span>
            </div>
            
            <?php if (!empty($crawl_data)): ?>
            <div class="debug-item info">
                <strong>Domain:</strong>
                <span class="debug-value"><?php echo esc_html($crawl_data['domain'] ?? 'Not Set'); ?></span>
            </div>
            
            <div class="debug-item info">
                <strong>Total URLs Crawled:</strong>
                <span class="debug-value"><?php echo count($crawl_data['urls'] ?? []); ?></span>
            </div>
            
            <div class="debug-item info">
                <strong>Last Updated:</strong>
                <span class="debug-value"><?php echo $this->get_option_last_updated('metasync_otto_crawldata'); ?></span>
            </div>
            
            <div class="debug-item info">
                <strong>Sample URLs:</strong>
                <div class="debug-value">
                    <?php 
                    $sample_urls = array_slice($crawl_data['urls'] ?? [], 0, 5);
                    foreach ($sample_urls as $url) {
                        echo esc_html($url) . '<br>';
                    }
                    if (count($crawl_data['urls'] ?? []) > 5) {
                        echo '... and ' . (count($crawl_data['urls']) - 5) . ' more';
                    }
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render processing status
     */
    private function render_processing_status() {
        $current_url = $this->get_current_url();
        $otto_pixel = new Metasync_otto_pixel(Metasync::get_option('general')['otto_pixel_uuid'] ?? '');
        $is_crawled = $otto_pixel->is_url_crawled($current_url);
        $render_diagnostics = $this->get_render_strategy_diagnostics();
        
        ?>
        <div class="debug-section">
            <h3><span class="status-indicator <?php echo $is_crawled ? 'status-success' : 'status-warning'; ?>"></span>Processing Status</h3>
            
            <div class="debug-item info">
                <strong>Current URL:</strong>
                <span class="debug-value"><?php echo esc_html($current_url); ?></span>
            </div>
            
            <div class="debug-item <?php echo $is_crawled ? 'success' : 'warning'; ?>">
                <strong>URL Crawled by <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?>:</strong>
                <span class="debug-value"><?php echo $is_crawled ? 'Yes' : 'No'; ?></span>
            </div>

            <div class="debug-item <?php echo $this->is_otto_excluded() ? 'warning' : 'info'; ?>">
                <strong><?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> Excluded:</strong>
                <span class="debug-value"><?php echo $this->is_otto_excluded() ? 'Yes' : 'No'; ?></span>
            </div>
            
            <div class="debug-item info">
                <strong>Page Type:</strong>
                <span class="debug-value"><?php echo $this->get_page_type(); ?></span>
            </div>
            
            <div class="debug-item info">
                <strong>Cache Status:</strong>
                <span class="debug-value"><?php echo $this->get_cache_status(); ?></span>
            </div>
            
            <div class="debug-item info">
                <strong>Processing Method:</strong>
                <span class="debug-value"><?php echo $this->get_processing_method(); ?></span>
            </div>
            
            <?php if (!empty($render_diagnostics) && isset($render_diagnostics['available']) === false): ?>
            <div class="debug-item info">
                <strong>Render Strategy:</strong>
                <div class="debug-value">
                    <ul style="margin: 5px 0 0 15px; padding: 0;">
                        <li><strong>PHP:</strong> <?php echo esc_html($render_diagnostics['php_version'] ?? 'Unknown'); ?></li>
                        <li><strong>WP:</strong> <?php echo esc_html($render_diagnostics['wp_version'] ?? 'Unknown'); ?></li>
                        <li><strong>Memory:</strong> <?php echo esc_html($render_diagnostics['memory_limit'] ?? 'Unknown'); ?> (used: <?php echo esc_html($render_diagnostics['memory_used'] ?? 'Unknown'); ?>)</li>
                        <li><strong>Buffer Level:</strong> <?php echo esc_html($render_diagnostics['buffer_level'] ?? 'Unknown'); ?></li>
                        <li><strong>Headers Sent:</strong> <?php echo ($render_diagnostics['headers_sent'] ?? false) ? 'Yes' : 'No'; ?></li>
                    </ul>
                </div>
            </div>
            
            <div class="debug-item info">
                <strong>Detected Plugins:</strong>
                <div class="debug-value">
                    <?php 
                    $plugins = $render_diagnostics['detected_plugins'] ?? [];
                    foreach ($plugins as $plugin => $active):
                    ?>
                        <span style="display: inline-block; margin: 2px 5px; padding: 2px 8px; background: <?php echo $active ? '#d4edda' : '#f8f9fa'; ?>; border-radius: 3px;">
                            <?php echo esc_html($plugin); ?>: <?php echo $active ? '✓' : '✗'; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="debug-item info">
                <strong>Detected Hosts:</strong>
                <div class="debug-value">
                    <?php 
                    $hosts = $render_diagnostics['detected_hosts'] ?? [];
                    foreach ($hosts as $host => $detected):
                    ?>
                        <span style="display: inline-block; margin: 2px 5px; padding: 2px 8px; background: <?php echo $detected ? '#fff3cd' : '#f8f9fa'; ?>; border-radius: 3px;">
                            <?php echo esc_html($host); ?>: <?php echo $detected ? '✓' : '✗'; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render debug tools
     */
    private function render_debug_tools() {
        ?>
        <div class="debug-section debug-tools">
            <h3><span class="status-indicator status-info"></span>Debug Tools</h3>
            
            <div style="margin-bottom: 20px;">
                <button id="test-otto-api" class="debug-button">Test API Connectivity</button>
                <button id="test-notification-endpoint" class="debug-button">Test Notification Endpoint</button>
                <button id="simulate-crawl" class="debug-button">Simulate Crawl Notification</button>
                <button id="clear-otto-cache" class="debug-button danger">Clear <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> Cache</button>
            </div>

            <div id="api-test-results" class="debug-results"></div>
            <div id="notification-test-results" class="debug-results"></div>
            <div id="crawl-simulate-results" class="debug-results"></div>
            <div id="cache-clear-results" class="debug-results"></div>

            <div class="debug-item info">
                <strong>Note:</strong> These tools help diagnose <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> issues. Use with caution in production environments.
            </div>
        </div>
        <?php
    }
    
    /**
     * Check if REST endpoint is registered
     */
    private function is_rest_endpoint_registered() {
        $routes = rest_get_server()->get_routes();
        return isset($routes['/metasync/v1/otto_crawl_notify']);
    }
    
    /**
     * Check if OTTO API is reachable
     */
    private function can_reach_otto_api() {
        $general_options = Metasync::get_option('general');
        $otto_uuid = $general_options['otto_pixel_uuid'] ?? '';
        
        if (empty($otto_uuid)) {
            return false;
        }

        # Use endpoint manager to get the correct API URL
        $api_endpoint = class_exists('Metasync_Endpoint_Manager')
            ? Metasync_Endpoint_Manager::get_endpoint('OTTO_URL_DETAILS')
            : 'https://sa.searchatlas.com/api/v2/otto-url-details';

        $api_url = add_query_arg(array(
            'url' => get_site_url(),
            'uuid' => $otto_uuid
        ), $api_endpoint);
        
        $response = wp_remote_get($api_url, array(
            'timeout' => 10,
            'sslverify' => true
        ));
        
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
    
    /**
     * Get option last updated time
     */
    private function get_option_last_updated($option_name) {
        global $wpdb;
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $option_name
        ));
        
        if ($result) {
            $data = maybe_unserialize($result);
            if (isset($data['last_updated'])) {
                return date('Y-m-d H:i:s', $data['last_updated']);
            }
        }
        
        return 'Unknown';
    }
    
    /**
     * Get current URL
     */
    private function get_current_url() {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return $scheme . '://' . $host . $uri;
    }
    
    /**
     * Check if OTTO is excluded for current request
     */
    private function is_otto_excluded() {
        // Check AJAX requests
        if (wp_doing_ajax() || defined('DOING_AJAX') && DOING_AJAX) {
            return true;
        }
        
        // Check WooCommerce pages
        if (function_exists('is_woocommerce') && is_woocommerce()) {
            return true;
        }
        
        // Check logged-in user exclusion
        $general_options = Metasync::get_option('general');
        if (!empty($general_options['otto_disable_on_loggedin']) && 
            $general_options['otto_disable_on_loggedin'] === 'true' && 
            is_user_logged_in()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get page type
     */
    private function get_page_type() {
        if (is_home()) return 'Home';
        if (is_front_page()) return 'Front Page';
        if (is_single()) return 'Single Post';
        if (is_page()) return 'Page';
        if (is_category()) return 'Category';
        if (is_tag()) return 'Tag';
        if (is_archive()) return 'Archive';
        if (is_search()) return 'Search';
        if (is_404()) return '404';
        return 'Other';
    }
    
    /**
     * Get cache status
     */
    private function get_cache_status() {
        // Cache is disabled in current implementation
        return 'Disabled (SSR Mode)';
    }
    
    /**
     * Get processing method
     */
    private function get_processing_method() {
        // OTTO SSR is always enabled by default
        $otto_enabled = true;
        
        if ($otto_enabled) {
            // Check which render strategy would be used
            if (class_exists('Metasync_Otto_Render_Strategy')) {
                $method = Metasync_Otto_Render_Strategy::determine_method();
                if ($method === Metasync_Otto_Render_Strategy::METHOD_BUFFER) {
                    return 'Server-Side Rendering (SSR) - Output Buffer (Fast)';
                } elseif ($method === Metasync_Otto_Render_Strategy::METHOD_HTTP) {
                    return 'Server-Side Rendering (SSR) - HTTP Request (Fallback)';
                }
            }
            return 'Server-Side Rendering (SSR)';
        } else {
            return 'Client-Side JavaScript';
        }
    }
    
    /**
     * Get render strategy diagnostics
     */
    public function get_render_strategy_diagnostics() {
        if (!class_exists('Metasync_Otto_Render_Strategy')) {
            return array(
                'available' => false,
                'message' => 'Render Strategy class not loaded'
            );
        }
        
        return Metasync_Otto_Render_Strategy::get_diagnostics();
    }
    
    /**
     * AJAX handler for testing OTTO API
     */
    public function ajax_test_otto_api() {
        check_ajax_referer('metasync_otto_debug', 'nonce');
        
        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('Unauthorized');
        }
        
        $general_options = Metasync::get_option('general');
        $otto_uuid = $general_options['otto_pixel_uuid'] ?? '';
        
        if (empty($otto_uuid)) {
            wp_send_json_error(Metasync::get_whitelabel_otto_name() . ' UUID not configured');
        }
        
        $test_url = get_site_url();

        # Use endpoint manager to get the correct API URL
        $api_endpoint = class_exists('Metasync_Endpoint_Manager')
            ? Metasync_Endpoint_Manager::get_endpoint('OTTO_URL_DETAILS')
            : 'https://sa.searchatlas.com/api/v2/otto-url-details';

        $api_url = add_query_arg(array(
            'url' => $test_url,
            'uuid' => $otto_uuid
        ), $api_endpoint);
        
        $response = wp_remote_get($api_url, array(
            'timeout' => 30,
            'sslverify' => true,
            'headers' => array(
                'User-Agent' => 'MetaSync-WordPress-Plugin/1.0'
            )
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'error' => $response->get_error_message(),
                'url' => $api_url
            ));
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        wp_send_json_success(array(
            'response_code' => $response_code,
            'url' => $api_url,
            'body' => $body,
            'has_data' => !empty($body),
            'data_valid' => json_decode($body, true) !== null
        ));
    }
    
    /**
     * AJAX handler for testing notification endpoint
     */
    public function ajax_test_notification_endpoint() {
        check_ajax_referer('metasync_otto_debug', 'nonce');
        
        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('Unauthorized');
        }
        
        $endpoint_url = rest_url('metasync/v1/otto_crawl_notify');
        
        $test_data = array(
            'domain' => get_site_url(),
            'urls' => array('/', '/about/', '/contact/')
        );
        
        $response = wp_remote_post($endpoint_url, array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($test_data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'error' => $response->get_error_message(),
                'url' => $endpoint_url
            ));
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        wp_send_json_success(array(
            'response_code' => $response_code,
            'url' => $endpoint_url,
            'body' => $body,
            'test_data' => $test_data
        ));
    }
    
    /**
     * AJAX handler for clearing OTTO cache
     */
    public function ajax_clear_otto_cache() {
        check_ajax_referer('metasync_otto_debug', 'nonce');
        
        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('Unauthorized');
        }
        
        // Clear crawl data
        delete_option('metasync_otto_crawldata');
        
        // Clear any cached API responses
        $general_options = Metasync::get_option('general');
        $otto_uuid = $general_options['otto_pixel_uuid'] ?? '';
        
        if (!empty($otto_uuid)) {
            $cache_key = 'metasync_public_hash_' . md5($otto_uuid);
            delete_transient($cache_key);
        }
        
        wp_send_json_success(array(
            'message' => Metasync::get_whitelabel_otto_name() . ' cache cleared successfully',
            'cleared_items' => array(
                'crawl_data' => true,
                'api_cache' => true
            )
        ));
    }
    
    /**
     * AJAX handler for simulating crawl notification
     */
    public function ajax_simulate_crawl_notification() {
        check_ajax_referer('metasync_otto_debug', 'nonce');
        
        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('Unauthorized');
        }
        
        $general_options = Metasync::get_option('general');
        $otto_uuid = $general_options['otto_pixel_uuid'] ?? '';
        
        if (empty($otto_uuid)) {
            wp_send_json_error(Metasync::get_whitelabel_otto_name() . ' UUID not configured');
        }
        
        // Simulate crawl notification
        $test_data = array(
            'domain' => get_site_url(),
            'urls' => array('/', '/about/', '/contact/', '/blog/')
        );
        
        // Create a mock request object
        $request = new WP_REST_Request('POST', '/metasync/v1/otto_crawl_notify');
        $request->set_body(json_encode($test_data));
        
        // Call the notification handler
        $response = metasync_otto_crawl_notify($request);
        
        wp_send_json_success(array(
            'message' => 'Crawl notification simulated',
            'test_data' => $test_data,
            'response' => $response->get_data(),
            'response_code' => $response->get_status()
        ));
    }
    
    /**
     * AJAX handler for testing specific URL
     */
    public function ajax_test_specific_url() {
        try {
            check_ajax_referer('metasync_otto_debug', 'nonce');

            if (!Metasync::current_user_has_plugin_access()) {
                wp_die('Unauthorized');
            }

            $test_url = sanitize_url($_POST['test_url'] ?? '');

            if (empty($test_url)) {
                wp_send_json_error('No URL provided');
            }

            $results = array(
                'test_url' => $test_url,
                'timestamp' => current_time('mysql'),
                'tests' => array()
            );

            // Test 1: URL Accessibility
            $results['tests']['url_accessibility'] = $this->test_url_accessibility($test_url);

            // Test 2: OTTO API Data
            $results['tests']['otto_api_data'] = $this->test_otto_api_for_url($test_url);

            // Test 3: Crawl Status
            $results['tests']['crawl_status'] = $this->test_crawl_status($test_url);

            // Test 4: Page Type Detection
            $results['tests']['page_type_detection'] = $this->test_page_type_detection($test_url);

            // Test 5: Processing Eligibility
            $results['tests']['processing_eligibility'] = $this->test_processing_eligibility($test_url);

            wp_send_json_success($results);

        } catch (Exception $e) {
            error_log('MetaSync OTTO Debug: Exception in ajax_test_specific_url: ' . $e->getMessage());
            wp_send_json_error('Exception: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for emulating OTTO changes
     */
    public function ajax_emulate_otto_changes() {
        check_ajax_referer('metasync_otto_debug', 'nonce');
        
        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('Unauthorized');
        }
        
        $test_url = sanitize_url($_POST['test_url'] ?? '');
        
        if (empty($test_url)) {
            wp_send_json_error('No URL provided');
        }
        
        $results = array(
            'test_url' => $test_url,
            'timestamp' => current_time('mysql'),
            'emulation' => array()
        );
        
        // Emulate OTTO processing
        $results['emulation'] = $this->emulate_otto_processing($test_url);
        
        wp_send_json_success($results);
    }
    
    /**
     * Test URL accessibility
     */
    private function test_url_accessibility($url) {
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'user-agent' => 'MetaSync Debug Tool'
        ));
        
        if (is_wp_error($response)) {
            return array(
                'status' => 'error',
                'message' => $response->get_error_message(),
                'accessible' => false
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        return array(
            'status' => 'success',
            'status_code' => $status_code,
            'accessible' => $status_code === 200,
            'content_length' => strlen($body),
            'headers' => wp_remote_retrieve_headers($response)->getAll()
        );
    }
    
    /**
     * Test OTTO API data for specific URL
     */
    private function test_otto_api_for_url($url) {
        $general_options = Metasync::get_option('general');
        $otto_uuid = $general_options['otto_pixel_uuid'] ?? '';
        
        if (empty($otto_uuid)) {
            return array(
                'status' => 'error',
                'message' => Metasync::get_whitelabel_otto_name() . ' UUID not configured'
            );
        }
        
        // Use the existing OTTO API function
        if (function_exists('metasync_fetch_otto_seo_data')) {
            $data = metasync_fetch_otto_seo_data($url, $otto_uuid);
            
            if (is_wp_error($data)) {
                return array(
                    'status' => 'error',
                    'message' => $data->get_error_message(),
                    'has_data' => false
                );
            }
            
            return array(
                'status' => 'success',
                'has_data' => !empty($data),
                'data_keys' => array_keys($data ?? array()),
                'recommendations_count' => count($data['recommendations'] ?? array()),
                'sample_data' => array_slice($data ?? array(), 0, 3) // First 3 items for preview
            );
        }
        
        return array(
            'status' => 'error',
            'message' => Metasync::get_whitelabel_otto_name() . ' API function not available'
        );
    }
    
    /**
     * Test crawl status for URL
     */
    private function test_crawl_status($url) {
        if (class_exists('Metasync_otto_pixel')) {
            $otto_pixel = new Metasync_otto_pixel(false);
            $is_crawled = $otto_pixel->is_url_crawled($url);
            
            // Get crawl data from options
            $crawl_data = get_option('metasync_otto_crawldata');
            
            return array(
                'status' => 'success',
                'is_crawled' => $is_crawled,
                'crawl_data' => $crawl_data,
                'total_crawled_urls' => count($crawl_data['urls'] ?? array()),
                'domain' => $crawl_data['domain'] ?? 'Not set'
            );
        }
        
        return array(
            'status' => 'error',
            'message' => Metasync::get_whitelabel_otto_name() . ' pixel class not available'
        );
    }
    
    /**
     * Test page type detection
     */
    private function test_page_type_detection($url) {
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'] ?? '/';
        
        $detection = array(
            'url' => $url,
            'path' => $path,
            'is_ajax' => strpos($path, 'wp-admin/admin-ajax.php') !== false,
            'is_woocommerce' => function_exists('is_woocommerce') ? false : 'WooCommerce not available',
            'is_admin' => strpos($path, '/wp-admin/') !== false,
            'is_login' => strpos($path, '/wp-login.php') !== false,
            'is_cron' => strpos($path, '/wp-cron.php') !== false,
            'is_xmlrpc' => strpos($path, '/xmlrpc.php') !== false
        );
        
        // Check if it's a WooCommerce page
        if (function_exists('is_woocommerce')) {
            // This would need to be tested with actual page context
            $detection['is_woocommerce'] = 'Requires page context to determine';
        }
        
        return $detection;
    }
    
    /**
     * Test processing eligibility
     */
    private function test_processing_eligibility($url) {
        $general_options = Metasync::get_option('general');
        // OTTO SSR is always enabled by default
        $otto_enabled = true;
        $disable_logged_in = $general_options['otto_disable_on_loggedin'] ?? false;
        
        $eligibility = array(
            'otto_enabled' => $otto_enabled,
            'disable_logged_in' => $disable_logged_in,
            'user_logged_in' => is_user_logged_in(),
            'eligible_for_processing' => false,
            'exclusion_reasons' => array()
        );
        
        // OTTO SSR is always enabled, so this check is no longer needed
        
        if ($disable_logged_in && is_user_logged_in()) {
            $eligibility['exclusion_reasons'][] = 'User is logged in and ' . Metasync::get_whitelabel_otto_name() . ' disabled for logged-in users';
        }
        
        $eligibility['eligible_for_processing'] = empty($eligibility['exclusion_reasons']);
        
        return $eligibility;
    }
    
    /**
     * Emulate OTTO processing
     */
    private function emulate_otto_processing($url) {
        $emulation = array(
            'url' => $url,
            'steps' => array(),
            'errors' => array(),
            'changes_applied' => array()
        );
        
        // Step 1: Check if URL is crawled
        $emulation['steps']['check_crawled'] = $this->test_crawl_status($url);
        
        // Step 2: Fetch OTTO data
        $emulation['steps']['fetch_otto_data'] = $this->test_otto_api_for_url($url);
        
        // Step 3: Simulate HTML processing
        if (class_exists('Metasync_otto_html')) {
            try {
                $otto_html = new Metasync_otto_html(false);
                $emulation['steps']['html_processing'] = array(
                    'status' => 'success',
                    'message' => Metasync::get_whitelabel_otto_name() . ' HTML class available'
                );

                // Simulate processing (without actually modifying files)
                $emulation['changes_applied'] = array(
                    'header_changes' => 'Simulated header modifications',
                    'body_changes' => 'Simulated body modifications',
                    'footer_changes' => 'Simulated footer modifications'
                );

            } catch (Exception $e) {
                $emulation['errors'][] = 'HTML processing error: ' . $e->getMessage();
            }
        } else {
            $emulation['errors'][] = Metasync::get_whitelabel_otto_name() . ' HTML class not available';
        }
        
        return $emulation;
    }
    
    /**
     * Simple AJAX test to verify basic functionality
     */
    public function ajax_simple_test() {
        try {
            check_ajax_referer('metasync_otto_debug', 'nonce');

            if (!Metasync::current_user_has_plugin_access()) {
                wp_send_json_error('Unauthorized');
            }

            wp_send_json_success(array(
                'message' => 'AJAX is working!',
                'timestamp' => current_time('mysql'),
                'user_id' => get_current_user_id(),
                'nonce_verified' => true
            ));

        } catch (Exception $e) {
            error_log('MetaSync OTTO Debug: Exception in ajax_simple_test: ' . $e->getMessage());
            wp_send_json_error('Exception: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for testing database permissions
     */
    public function ajax_test_db_permissions() {
        try {
            check_ajax_referer('metasync_otto_debug', 'nonce');

            if (!Metasync::current_user_has_plugin_access()) {
                wp_send_json_error('Unauthorized');
            }

            $results = array(
                'timestamp' => current_time('mysql'),
                'tests' => array()
            );
            
            // Test 1: Try to schedule a simple action
            $test_action = 'metasync_debug_test_action';
            $scheduled = wp_schedule_single_event(time() + 60, $test_action, array('test' => 'data'));
            
            $results['tests']['action_scheduler'] = array(
                'can_schedule' => $scheduled !== false,
                'scheduled' => $scheduled,
                'message' => $scheduled !== false ? 'Action Scheduler working' : 'Action Scheduler failed - likely database permissions issue'
            );
            
            // Test 2: Check if we can access Action Scheduler tables
            global $wpdb;
            $table_name = $wpdb->prefix . 'actionscheduler_actions';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
            
            $results['tests']['table_access'] = array(
                'table_exists' => $table_exists,
                'table_name' => $table_name,
                'message' => $table_exists ? 'Action Scheduler table exists' : 'Action Scheduler table not found'
            );
            
            // Test 3: Try to insert a test record
            if ($table_exists) {
                $insert_result = $wpdb->insert(
                    $table_name,
                    array(
                        'hook' => 'metasync_debug_test',
                        'status' => 'pending',
                        'scheduled_date_gmt' => current_time('mysql', 1),
                        'args' => json_encode(array('test' => true)),
                        'schedule' => 'once'
                    ),
                    array('%s', '%s', '%s', '%s', '%s')
                );
                
                $results['tests']['insert_test'] = array(
                    'can_insert' => $insert_result !== false,
                    'insert_id' => $wpdb->insert_id,
                    'last_error' => $wpdb->last_error,
                    'message' => $insert_result !== false ? 'Can insert records' : 'Cannot insert records - ' . $wpdb->last_error
                );
                
                // Clean up test record
                if ($insert_result !== false && $wpdb->insert_id) {
                    $wpdb->delete($table_name, array('ID' => $wpdb->insert_id));
                }
            }
            
            // Test 4: Check WordPress cron functionality
            $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            $results['tests']['wp_cron'] = array(
                'enabled' => !$cron_disabled,
                'message' => $cron_disabled ? 'WordPress cron is disabled' : 'WordPress cron is enabled'
            );
            
            wp_send_json_success($results);

        } catch (Exception $e) {
            error_log('MetaSync OTTO Debug: Exception in ajax_test_db_permissions: ' . $e->getMessage());
            wp_send_json_error('Exception: ' . $e->getMessage());
        }
    }
}
