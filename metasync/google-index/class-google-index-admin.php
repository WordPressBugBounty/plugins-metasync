<?php

/**
 * Google Index - Admin Integration
 * 
 * Integrates Google Index settings into MetaSync admin general settings
 * 
 * @package GoogleIndexDirect
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Google_Index_Admin 
{
    /**
     * Section ID for Google Index settings
     */
    private const SECTION_GOOGLE_INDEX = 'google_index_direct_settings';
    
    /**
     * Initialize admin functionality
     */
    public function __construct()
    {
        // Hook into MetaSync admin initialization
        add_action('admin_init', array($this, 'add_settings_to_metasync'), 20);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Hook into MetaSync's AJAX settings processing
        add_action('wp_ajax_meta_sync_save_settings', array($this, 'process_google_index_settings'), 5);
        
        // Display admin notices after redirect
        add_action('admin_notices', array($this, 'display_admin_notices'));
        
        // AJAX handlers
        add_action('wp_ajax_google_index_direct_test', array($this, 'ajax_test_connection'));
    }
    
    
    /**
     * Add Google Index settings to MetaSync admin
     */
    public function add_settings_to_metasync()
    {
        // Only add if MetaSync Admin class exists
        if (!class_exists('Metasync_Admin')) {
            return;
        }
        
        // Get MetaSync page slug
        $page_slug = $this->get_metasync_page_slug();
        if (!$page_slug) {
            return;
        }
        
        // Add settings section for Google Index
        add_settings_section(
            self::SECTION_GOOGLE_INDEX,
            '', // Empty title - we'll use dashboard card styling
            function(){}, // Empty callback
            $page_slug . '_general'
        );

        // Add the main settings field
        add_settings_field(
            'google_index_direct_config',
            'Google Index API',
            array($this, 'render_settings_field'),
            $page_slug . '_general',
            self::SECTION_GOOGLE_INDEX
        );
    }
    
    /**
     * Get MetaSync page slug
     */
    private function get_metasync_page_slug()
    {
        if (class_exists('Metasync_Admin') && property_exists('Metasync_Admin', 'page_slug')) {
            return Metasync_Admin::$page_slug;
        }
        return 'searchatlas'; // fallback
    }
    
    /**
     * Render the Google Index settings field
     */
    public function render_settings_field()
    {
        // Load Google Index functionality
        if (!function_exists('google_index_direct')) {
            require_once plugin_dir_path(__FILE__) . 'google-index-init.php';
        }
        
        // Get current service account info (safe - doesn't expose private key)
        $google_index = google_index_direct();
        $service_info = $google_index->get_service_account_info();
        $is_configured = !isset($service_info['error']);
        
        // Include the settings field view
        include plugin_dir_path(__FILE__) . '../views/metasync-google-index-api-settings.php';
    }
    
    /**
     * Process Google Index settings during MetaSync AJAX save
     * This runs early in the AJAX processing chain (priority 5)
     */
    public function process_google_index_settings()
    {
        // Only process if our fields are present in the request
        if (!isset($_POST['google_index_service_account_json']) && 
            !isset($_POST['google_index_clear_config']) && 
            !isset($_FILES['google_index_service_account_file'])) {
            return; // No Google Index data to process
        }
        
        // Load Google Index functionality
        if (!function_exists('google_index_save_service_account')) {
            require_once plugin_dir_path(__FILE__) . 'google-index-init.php';
        }
        
        $service_account_json = '';
        
        // Get JSON from textarea
        if (isset($_POST['google_index_service_account_json'])) {
            $service_account_json = sanitize_textarea_field(wp_unslash($_POST['google_index_service_account_json']));
        }
        
        // Override with file upload if provided
        if (isset($_FILES['google_index_service_account_file']) && 
            !empty($_FILES['google_index_service_account_file']['tmp_name']) && 
            file_exists($_FILES['google_index_service_account_file']['tmp_name'])) {
            
            $uploaded_json = file_get_contents($_FILES['google_index_service_account_file']['tmp_name']);
            if ($uploaded_json !== false) {
                $service_account_json = $uploaded_json; // Removed unnecessary wp_unslash
            }
        }
        
        // Handle clear configuration
        if (isset($_POST['google_index_clear_config'])) {
            delete_option('google_index_service_account');
            // Success messages shown after redirect (to prevent interfering with MetaSync flow)
            $this->add_settings_notice('Service account configuration cleared successfully!', 'success');
        }
        // Process service account JSON if provided
        elseif (!empty($service_account_json) && trim($service_account_json) !== '') {
            
            // Skip if it's just the placeholder text
            if (strpos($service_account_json, 'Service account configured') !== false) {
                return; // Don't process placeholder text
            }
            
            // Decode and validate JSON
            $service_account_data = json_decode($service_account_json, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                // JSON parsing error - send immediate error response
                $json_error_message = 'Invalid JSON format';
                switch (json_last_error()) {
                    case JSON_ERROR_SYNTAX:
                        $json_error_message .= ' - Syntax error in JSON';
                        break;
                    case JSON_ERROR_UTF8:
                        $json_error_message .= ' - Invalid UTF-8 encoding';
                        break;
                    default:
                        $json_error_message .= ' - ' . json_last_error_msg();
                        break;
                }
                
                wp_send_json_error([
                    'errors' => ['Google Index: ' . $json_error_message . '. Please check your service account JSON.']
                ]);
            }
            
            if (!is_array($service_account_data)) {
                wp_send_json_error([
                    'errors' => ['Google Index: JSON must be an object/array. Please check your service account JSON format.']
                ]);
            }
            
            // Validate required fields
            $required_fields = ['type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id', 'auth_uri', 'token_uri'];
            $missing_fields = [];
            
            foreach ($required_fields as $field) {
                if (!isset($service_account_data[$field]) || empty($service_account_data[$field])) {
                    $missing_fields[] = $field;
                }
            }
            
            if (!empty($missing_fields)) {
                wp_send_json_error([
                    'errors' => ['Google Index: Missing required fields - ' . implode(', ', $missing_fields) . '. Please ensure you have a complete service account JSON.']
                ]);
            }
            
            // Save using Google Index function
            $result = google_index_save_service_account($service_account_data);
            
            if ($result) {
                $this->add_settings_notice('Google Index service account configured successfully!', 'success');
            } else {
                // Send immediate error response using MetaSync's expected format
                wp_send_json_error([
                    'errors' => ['Google Index: Failed to save service account configuration. Please try again or check your JSON format.']
                ]);
            }
        }
    }
    
    /**
     * Add a settings notice to be displayed after AJAX save
     * 
     * @param string $message Notice message
     * @param string $type Notice type: 'success', 'error', 'warning', 'info'
     */
    private function add_settings_notice($message, $type = 'info')
    {
        $notices = get_transient('google_index_admin_notices') ?: [];
        $notices[] = [
            'message' => $message,
            'type' => $type,
            'time' => time()
        ];
        
        // Store notices for 30 seconds (enough time for page redirect)
        set_transient('google_index_admin_notices', $notices, 30);
    }
    
    /**
     * Display admin notices for Google Index settings
     */
    public function display_admin_notices()
    {
        // Only show notices on MetaSync settings pages
        $page_slug = $this->get_metasync_page_slug();
        $current_screen = get_current_screen();
        
        if (!$current_screen || strpos($current_screen->id, $page_slug) === false) {
            return;
        }
        
        // Get and display notices
        $notices = get_transient('google_index_admin_notices');
        if (!empty($notices)) {
            foreach ($notices as $notice) {
                $class = 'notice notice-' . esc_attr($notice['type']) . ' is-dismissible';
                echo '<div class="' . $class . '">';
                echo '<p><strong>Google Index:</strong> ' . esc_html($notice['message']) . '</p>';
                echo '</div>';
            }
            
            // Clear notices after displaying
            delete_transient('google_index_admin_notices');
        }
    }
    
    /**
     * AJAX handler for testing connection
     */
    public function ajax_test_connection()
    {
        // Check nonce and permissions
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'google_index_direct_test') || 
            !Metasync::current_user_has_plugin_access()) {
            wp_die('Security check failed');
        }
        
        // Load Google Index functionality
        if (!function_exists('google_index_direct')) {
            require_once plugin_dir_path(__FILE__) . 'google-index-init.php';
        }
        
        try {
            // Test the connection
            $google_index = google_index_direct();
            $test_results = $google_index->test_connection();
            
            wp_send_json_success([
                'message' => 'Connection test completed',
                'results' => $test_results
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => 'Test failed: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook)
    {
        // Only load on MetaSync settings pages
        $page_slug = $this->get_metasync_page_slug();
        if (strpos($hook, $page_slug) === false) {
            return;
        }
        
        // Only load on general tab (default tab when none specified)
        $current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
        if ($current_tab !== 'general') {
            return;
        }
        
        // Add inline JavaScript for functionality
        wp_add_inline_script('jquery', $this->get_admin_javascript());
    }
    
    /**
     * Get JavaScript for admin functionality
     * 
     * @return string JavaScript code
     */
    private function get_admin_javascript()
    {
        $nonce = wp_create_nonce('google_index_direct_test');
        
        return "
        jQuery(document).ready(function($) {
            // Integration with MetaSync's unsaved changes detection
            function integrateWithUnsavedChangesDetection() {
                // Monitor textarea for changes (avoid recursion)
                $('#google_index_service_account_json').on('input change paste keyup', function(e) {
                    console.log('Google Index: Textarea changed via', e.type);
                    // Trigger MetaSync's change detection (works on both Settings and Indexation Control pages)
                    $('#metaSyncGeneralSetting, #metaSyncSeoControlsForm').trigger('change');
                });
                
                // Handle file upload with auto-populate and change detection
                $('#google_index_service_account_file').on('change', function(e) {
                    var file = e.target.files[0];
                    var textarea = $('#google_index_service_account_json');
                    
                    if (file && file.type === 'application/json') {
                        var reader = new FileReader();
                        reader.onload = function(e) {
                            try {
                                var json = JSON.parse(e.target.result);
                                // Update textarea value
                                var jsonString = JSON.stringify(json, null, 2);
                                textarea.val(jsonString);
                                
                                // Create and dispatch native events to ensure proper detection
                                setTimeout(function() {
                                    // Create native events
                                    var inputEvent = new Event('input', { bubbles: true, cancelable: true });
                                    var changeEvent = new Event('change', { bubbles: true, cancelable: true });
                                    
                                    // Dispatch events on the actual DOM element (not jQuery)
                                    textarea[0].dispatchEvent(inputEvent);
                                    textarea[0].dispatchEvent(changeEvent);
                                    
                                    // Also trigger jQuery events as backup
                                    textarea.trigger('input').trigger('change');
                                    
                                    console.log('Google Index: File upload triggered change detection');
                                }, 100);
                            } catch (err) {
                                alert('Invalid JSON file selected.');
                            }
                        };
                        reader.readAsText(file);
                    } else {
                        // File upload indicates change even if not JSON (works on both Settings and Indexation Control pages)
                        $('#metaSyncGeneralSetting, #metaSyncSeoControlsForm').trigger('change');
                    }
                });
            }
            
            // Initialize integration after a small delay to ensure MetaSync is ready
            setTimeout(integrateWithUnsavedChangesDetection, 100);
            
            // Handle test connection button
            $('#google-index-test-connection').on('click', function(e) {
                e.preventDefault();
                
                var button = $(this);
                var resultDiv = $('#google-index-test-results');
                
                button.prop('disabled', true).text('🔄 Testing...');
                resultDiv.html('<div class=\"notice notice-info inline\"><p>Testing connection...</p></div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'google_index_direct_test',
                        nonce: '{$nonce}'
                    },
                    success: function(response) {
                        if (response.success) {
                            var results = response.data.results;
                            var html = '<div class=\"notice notice-success inline\">';
                            html += '<p><strong>✅ Connection Test Results:</strong></p>';
                            html += '<ul>';
                            
                            if (results.token_test) {
                                html += '<li><strong>Token Generation:</strong> ' + (results.token_test.success ? '✅ Success' : '❌ Failed') + '</li>';
                                html += '<li><strong>Token Cached:</strong> ' + (results.token_test.cached ? '✅ Yes' : '⚪ No') + '</li>';
                            }
                            
                            if (results.credentials_test) {
                                html += '<li><strong>Service Account:</strong> ' + results.credentials_test.client_email + '</li>';
                                html += '<li><strong>Project ID:</strong> ' + results.credentials_test.project_id + '</li>';
                                html += '<li><strong>Private Key:</strong> ' + (results.credentials_test.has_private_key ? '✅ Present' : '❌ Missing') + '</li>';
                            }
                            
                            if (results.homepage_test) {
                                if (results.homepage_test.success) {
                                    html += '<li><strong>Homepage Status:</strong> ✅ Success</li>';
                                } else {
                                    html += '<li><strong>Homepage Status:</strong> ⚠️ ' + results.homepage_test.error.message + '</li>';
                                    if (results.homepage_test.note) {
                                        html += '<li><strong>Note:</strong> ' + results.homepage_test.note + '</li>';
                                    }
                                }
                            }
                            
                            html += '</ul></div>';
                            resultDiv.html(html);
                        } else {
                            resultDiv.html('<div class=\"notice notice-error inline\"><p><strong>❌ Test Failed:</strong> ' + response.data.message + '</p></div>');
                        }
                    },
                    error: function() {
                        resultDiv.html('<div class=\"notice notice-error inline\"><p><strong>❌ Connection Error:</strong> Unable to perform test.</p></div>');
                    },
                    complete: function() {
                        button.prop('disabled', false).text('🧪 Test Connection');
                    }
                });
            });
            
            // Handle clear configuration button
            $('#google-index-clear-config').on('click', function(e) {
                e.preventDefault();
                
                if (!confirm('Are you sure you want to clear the service account configuration?')) {
                    return;
                }
                
                // Create hidden input to indicate clear configuration request
                var input = $('<input>')
                    .attr('type', 'hidden')
                    .attr('name', 'google_index_clear_config')
                    .attr('value', '1');
                
                // Append to form
                $('#metaSyncGeneralSetting').append(input);
                
                // Trigger the form's existing AJAX submission handler
                $('#metaSyncGeneralSetting').trigger('submit');
            });
            
        });
        ";
    }
}

// Initialize admin functionality
if (is_admin()) {
    new Google_Index_Admin();
}
