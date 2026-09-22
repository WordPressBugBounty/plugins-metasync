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
     * Nonce action shared by the two dedicated (standalone-page) endpoints.
     */
    private const ACCOUNT_NONCE_ACTION = 'metasync_google_index_account';

    /**
     * Placeholder the redacted display JSON uses in place of the private key.
     *
     * Its presence means the textarea still holds what the server rendered, so
     * the submission carries no new credential and must leave storage alone.
     */
    private const REDACTED_MARKER = '-----REDACTED-----';

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
        add_action('wp_ajax_meta_sync_save_seo_controls', array($this, 'process_google_index_settings'), 5);

        // Display admin notices after redirect
        add_action('admin_notices', array($this, 'display_admin_notices'));

        // AJAX handlers
        add_action('wp_ajax_metasync_google_index_direct_test', array($this, 'ajax_test_connection'));

        // Dedicated save/clear for the standalone Instant Indexing page, which
        // has no settings form to piggyback on.
        add_action('wp_ajax_metasync_google_index_save_account', array($this, 'ajax_save_service_account'));
        add_action('wp_ajax_metasync_google_index_clear_account', array($this, 'ajax_clear_service_account'));
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
            if (file_exists(plugin_dir_path(__FILE__) . 'google-index-init.php')) {
                require_once plugin_dir_path(__FILE__) . 'google-index-init.php';
            } else {
                error_log('MetaSync Google Index: google-index-init.php not found at ' . plugin_dir_path(__FILE__) . 'google-index-init.php');
                return;
            }
        }
        
        // Get current service account info (safe - doesn't expose private key)
        $google_index = google_index_direct();
        if (!$google_index) {
            // Nothing to render against, and calling through would be fatal.
            return;
        }
        $service_info = $google_index->get_service_account_info();
        $is_configured = !isset($service_info['error']);

        $saved_json_display = $is_configured ? $google_index->get_redacted_config_json() : '';

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

        if (!$this->authorize_settings_request()) {
            return;
        }

        // Clearing does not need to load or inspect credential contents.
        if (isset($_POST['google_index_clear_config'])) {
            $this->clear_stored_service_account();
            $this->add_settings_notice('Service account configuration cleared successfully!', 'success');
            return;
        }

        $result = $this->persist_submitted_service_account();

        if ($result['status'] === 'error') {
            // The parent save is still mid-flight; aborting here with an error
            // keeps a bad credential from being reported as a successful save.
            wp_send_json_error([
                'errors'  => ['Google Index: ' . $result['message']],
                'message' => 'Google Index: ' . $result['message'],
            ]);
        }

        if ($result['status'] === 'unavailable') {
            // The sub-module is missing. Let the rest of the settings save
            // finish and surface this as a notice instead of failing the page.
            $this->add_settings_notice($result['message'], 'warning');
            return;
        }

        if ($result['status'] === 'saved') {
            $this->add_settings_notice('Google Index service account configured successfully!', 'success');
        }
    }

    /**
     * Read the submitted service account from $_POST/$_FILES, validate it, and
     * persist it when it is a genuinely new credential.
     *
     * Shared by the form-embedded flow and the dedicated standalone endpoint so
     * both accept and reject exactly the same payloads. Returns a status rather
     * than emitting a response, because the two callers report differently: the
     * embedded flow must abort the parent save, the dedicated endpoint owns the
     * whole response.
     *
     * @return array{status:string, message:string} status is one of:
     *         'saved'     - a new credential was written
     *         'unchanged' - nothing to write (empty, or the redacted display text)
     *         'error'     - validation or persistence failed; message explains why
     */
    private function persist_submitted_service_account()
    {
        // Load Google Index functionality
        if (!$this->module_function_available('google_index_save_service_account')) {
            if (file_exists(plugin_dir_path(__FILE__) . 'google-index-init.php')) {
                require_once plugin_dir_path(__FILE__) . 'google-index-init.php';
            }

            // A partial deploy can leave the file present but the function
            // undefined, so re-check rather than assuming the require worked.
            if (!$this->module_function_available('google_index_save_service_account')) {
                error_log('MetaSync Google Index: google_index_save_service_account() unavailable; expected google-index-init.php at ' . plugin_dir_path(__FILE__) . 'google-index-init.php');

                // Deliberately NOT 'error'. This optional sub-module failing to
                // load must not abort the parent settings save — the embedded
                // caller would turn that into a wp_send_json_error() and take
                // the whole General Settings / Indexation Control page down.
                return ['status' => 'unavailable', 'message' => 'The Google Index module could not be loaded, so the service account was not saved.'];
            }
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

        if (empty($service_account_json) || trim($service_account_json) === '') {
            return ['status' => 'unchanged', 'message' => ''];
        }

        // Skip if it contains redacted private key (user didn't paste new JSON)
        if (strpos($service_account_json, self::REDACTED_MARKER) !== false) {
            return ['status' => 'unchanged', 'message' => ''];
        }

        $validation = $this->validate_service_account_json($service_account_json);
        if (!empty($validation['error'])) {
            return ['status' => 'error', 'message' => $validation['error']];
        }

        if (!google_index_save_service_account($validation['data'])) {
            return [
                'status'  => 'error',
                'message' => 'Failed to save service account configuration. Please try again or check your JSON format.',
            ];
        }

        return ['status' => 'saved', 'message' => 'Google Index service account configured successfully!'];
    }

    /**
     * Whether a function the optional Google Index sub-module provides exists.
     *
     * Taking the name as a parameter keeps the check opaque to static analysis,
     * which otherwise treats the second call in a load-then-verify pair as dead
     * because it cannot see that the intervening require_once may define it.
     *
     * Genuinely impure: the same argument yields a different answer once the
     * sub-module has been required, which is precisely why it is called twice.
     *
     * @phpstan-impure
     *
     * @param string $name Function name to look for.
     * @return bool
     */
    private function module_function_available($name)
    {
        return function_exists($name);
    }

    /**
     * Decode and structurally validate a service account JSON string.
     *
     * @param string $json Raw JSON text.
     * @return array{data:array|null, error:string} error is '' when valid.
     */
    private function validate_service_account_json($json)
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $message = 'Invalid JSON format';
            switch (json_last_error()) {
                case JSON_ERROR_SYNTAX:
                    $message .= ' - Syntax error in JSON';
                    break;
                case JSON_ERROR_UTF8:
                    $message .= ' - Invalid UTF-8 encoding';
                    break;
                default:
                    $message .= ' - ' . json_last_error_msg();
                    break;
            }

            return ['data' => null, 'error' => $message . '. Please check your service account JSON.'];
        }

        if (!is_array($data)) {
            return [
                'data'  => null,
                'error' => 'JSON must be an object/array. Please check your service account JSON format.',
            ];
        }

        $required_fields = ['type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id', 'auth_uri', 'token_uri'];
        $missing_fields = [];

        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $missing_fields[] = $field;
            }
        }

        if (!empty($missing_fields)) {
            return [
                'data'  => null,
                'error' => 'Missing required fields - ' . implode(', ', $missing_fields) . '. Please ensure you have a complete service account JSON.',
            ];
        }

        return ['data' => $data, 'error' => ''];
    }

    /**
     * AJAX: save the service account from the standalone Instant Indexing page.
     *
     * The page has no settings form, so this endpoint carries its own nonce and
     * capability check rather than relying on a parent save's.
     */
    public function ajax_save_service_account()
    {
        $this->authorize_account_request();

        $result = $this->persist_submitted_service_account();

        if ($result['status'] === 'error' || $result['status'] === 'unavailable') {
            // Here the credential save IS the whole request, so a missing
            // sub-module is a genuine failure and must be reported as one.
            wp_send_json_error([
                'message' => $result['message'],
                'errors'  => [$result['message']],
            ]);
        }

        if ($result['status'] === 'unchanged') {
            // Nothing was submitted, or the textarea still holds the redacted
            // text the server rendered. Reporting this plainly is better than a
            // success banner for a save that wrote nothing.
            wp_send_json_success([
                'message' => 'No new service account JSON was provided, so the saved configuration is unchanged.',
                'saved'   => false,
            ]);
        }

        // Survives the page reload the JS performs, which is what actually
        // renders the confirmation banner and the configured-state UI.
        $this->add_settings_notice('Google Index service account configured successfully!', 'success');

        wp_send_json_success([
            'message' => 'Service account saved successfully!',
            'saved'   => true,
        ]);
    }

    /**
     * AJAX: clear the stored service account from the standalone page.
     */
    public function ajax_clear_service_account()
    {
        $this->authorize_account_request();

        $this->clear_stored_service_account();

        $this->add_settings_notice('Service account configuration cleared successfully!', 'success');

        wp_send_json_success(['message' => 'Service account configuration cleared successfully!']);
    }

    /**
     * Remove the stored service account and any token minted from it.
     *
     * Shared by both clear paths: a cached access token that outlives the
     * credential it was issued for would keep working until it expired.
     */
    private function clear_stored_service_account()
    {
        delete_option('google_index_service_account');

        if (function_exists('google_index_direct')) {
            $instance = google_index_direct();
            if ($instance) {
                $instance->clear_token_cache();
            }
        }
    }

    /**
     * Authorize a dedicated (standalone-page) service account request.
     *
     * Terminates the request with a 403 JSON response when the caller lacks the
     * capability or a valid nonce.
     */
    private function authorize_account_request()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'), 403);
        }

        $nonce = isset($_POST['metasync_google_index_account_nonce'])
            ? wp_unslash($_POST['metasync_google_index_account_nonce'])
            : '';

        if (!$nonce || !wp_verify_nonce($nonce, self::ACCOUNT_NONCE_ACTION)) {
            wp_send_json_error(array('message' => 'Invalid nonce'), 403);
        }

        return true;
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
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }
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
        if (!current_user_can('manage_options') ||
            !wp_verify_nonce(wp_unslash($_POST['nonce'] ?? ''), 'metasync_google_index_direct_test')) {
            wp_die('Security check failed');
        }
        
        // Load Google Index functionality
        if (!function_exists('google_index_direct')) {
            if (file_exists(plugin_dir_path(__FILE__) . 'google-index-init.php')) {
                require_once plugin_dir_path(__FILE__) . 'google-index-init.php';
            } else {
                error_log('MetaSync Google Index: google-index-init.php not found at ' . plugin_dir_path(__FILE__) . 'google-index-init.php');
                return;
            }
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
     * Authorize the shared MetaSync settings AJAX action before secret handling.
     */
    private function authorize_settings_request()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'), 403);
        }

        $action = isset($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : '';
        $nonce_field = '';
        $nonce_action = '';

        if ($action === 'meta_sync_save_settings') {
            $nonce_field = 'meta_sync_nonce';
            $nonce_action = 'meta_sync_general_setting_nonce';
        } elseif ($action === 'meta_sync_save_seo_controls') {
            $nonce_field = 'meta_sync_seo_controls_nonce';
            $nonce_action = 'meta_sync_seo_controls_nonce';
        }

        if (
            $nonce_field === ''
            || !isset($_POST[$nonce_field])
            || !wp_verify_nonce(wp_unslash($_POST[$nonce_field]), $nonce_action)
        ) {
            wp_send_json_error(array('message' => 'Invalid nonce'), 403);
        }

        return true;
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

        // The credentials section renders in three places: the General Settings
        // "general" tab, the Indexation Control page, and the standalone
        // Instant Indexing page. Gating on the tab alone left the standalone
        // page — the one the ticket is about — without any of this behaviour.
        $current_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $is_standalone_page = (substr($current_page, -14) === '-instant-index');

        if (!$is_standalone_page) {
            $current_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
            if ($current_tab !== 'general' && strpos($hook, 'seo-controls') === false) {
                return;
            }
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
        $nonce = wp_create_nonce('metasync_google_index_direct_test');
        
        return "
        jQuery(document).ready(function($) {
            // What the textarea held immediately before a file overwrote it.
            // Seeded from the server-rendered value and re-snapshotted on each
            // file pick, so 'Remove' undoes exactly the file selection — it
            // does not also discard a credential the user typed by hand before
            // mis-clicking Choose File.
            var originalJsonValue = $('#google_index_service_account_json').val();

            // FileReader is async and the dedicated Save posts the textarea,
            // not the file input. Without this flag a quick Save click after
            // choosing a file would send the pre-file contents — on an already
            // configured site that is the redacted text, so the save would
            // silently do nothing and report 'no new JSON provided'.
            var fileReadPending = false;

            // Test Connection builds its results list as an HTML string. The
            // values come from the API/server rather than the page, so escape
            // them before they reach that sink.
            function escHtml(value) {
                return $('<div>').text(value === undefined || value === null ? '' : value).html();
            }

            function showFileMessage(text, type) {
                var box = $('#google-index-file-messages');
                if (!box.length) { return; }
                box.empty();
                if (!text) { return; }
                var notice = $('<div>').addClass('notice notice-' + type + ' inline');
                notice.append($('<p>').text(text));
                box.append(notice);
            }

            function showSaveMessage(text, type) {
                var box = $('#google-index-save-messages');
                if (!box.length) {
                    // Embedded contexts have no dedicated box; fall back to the
                    // file-message area so errors are never silent.
                    showFileMessage(text, type);
                    return;
                }
                box.empty();
                if (!text) { return; }
                var notice = $('<div>').addClass('notice notice-' + type + ' inline');
                notice.append($('<p>').text(text));
                box.append(notice);
            }

            function clearSelectedFile(restoreTextarea) {
                fileReadPending = false;
                var input = $('#google_index_service_account_file');
                if (input.length) {
                    input.val('');
                }
                $('#google-index-selected-file').hide();
                $('#google-index-selected-file-name').text('');
                showFileMessage('', 'info');

                if (restoreTextarea) {
                    var textarea = $('#google_index_service_account_json');
                    textarea.val(originalJsonValue);
                    textarea.trigger('input').trigger('change');
                    $('#metaSyncGeneralSetting, #metaSyncSeoControlsForm').trigger('change');
                }
            }

            // Integration with MetaSync's unsaved changes detection
            function integrateWithUnsavedChangesDetection() {
                // Monitor textarea for changes (avoid recursion)
                $('#google_index_service_account_json').on('input change paste keyup', function(e) {
                    // Trigger MetaSync's change detection (works on both Settings and Indexation Control pages)
                    $('#metaSyncGeneralSetting, #metaSyncSeoControlsForm').trigger('change');
                });

                // Handle file upload with auto-populate and change detection
                $('#google_index_service_account_file').on('change', function(e) {
                    var file = e.target.files[0];
                    var textarea = $('#google_index_service_account_json');

                    if (!file) {
                        clearSelectedFile(true);
                        return;
                    }

                    if (!file.name.toLowerCase().endsWith('.json')) {
                        // Inline feedback instead of the old blocking alert().
                        showFileMessage('\"' + file.name + '\" is not a .json file. Choose the service account key file you downloaded from Google Cloud.', 'error');
                        $('#google-index-selected-file').hide();
                        $('#google_index_service_account_file').val('');
                        return;
                    }

                    // Snapshot only when no file is currently selected, i.e. at
                    // the START of a pick sequence. Re-snapshotting on every
                    // pick would make Remove restore file A's contents after
                    // the user picked A then B then removed — leaving a
                    // credential in the box while the UI says no file is
                    // selected.
                    if (!$('#google-index-selected-file').is(':visible')) {
                        originalJsonValue = textarea.val();
                    }

                    var reader = new FileReader();
                    fileReadPending = true;
                    reader.onload = function(ev) {
                        fileReadPending = false;
                        try {
                            var json = JSON.parse(ev.target.result);
                            var jsonString = JSON.stringify(json, null, 2);
                            textarea.val(jsonString);

                            $('#google-index-selected-file-name').text(file.name);
                            $('#google-index-selected-file').show();
                            showFileMessage('\"' + file.name + '\" loaded. Click Save Configuration to store it.', 'info');

                            // Create and dispatch native events to ensure proper detection
                            setTimeout(function() {
                                var inputEvent = new Event('input', { bubbles: true, cancelable: true });
                                var changeEvent = new Event('change', { bubbles: true, cancelable: true });

                                textarea[0].dispatchEvent(inputEvent);
                                textarea[0].dispatchEvent(changeEvent);

                                textarea.trigger('input').trigger('change');
                            }, 100);
                        } catch (err) {
                            showFileMessage('\"' + file.name + '\" is not valid JSON. Re-download the key file from Google Cloud and try again.', 'error');
                            $('#google-index-selected-file').hide();
                            $('#google_index_service_account_file').val('');
                        }
                    };
                    reader.onerror = function() {
                        fileReadPending = false;
                        showFileMessage('\"' + file.name + '\" could not be read. Try choosing it again.', 'error');
                        $('#google-index-selected-file').hide();
                        $('#google_index_service_account_file').val('');
                    };
                    reader.readAsText(file);
                });

                // Remove/reselect
                $('#google-index-remove-file').on('click', function(e) {
                    e.preventDefault();
                    clearSelectedFile(true);
                });
            }

            // Initialize integration after a small delay to ensure MetaSync is ready
            setTimeout(integrateWithUnsavedChangesDetection, 100);

            // Dedicated save — standalone Instant Indexing page only.
            $('#google-index-save-config').on('click', function(e) {
                e.preventDefault();

                if (fileReadPending) {
                    showSaveMessage('Still reading the selected file. Try again in a moment.', 'warning');
                    return;
                }

                var button = $(this);
                var original = button.html();
                button.prop('disabled', true).text('Saving...');
                showSaveMessage('', 'info');

                $.ajax({
                    url: (typeof ajaxurl !== 'undefined') ? ajaxurl : metaSync.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'metasync_google_index_save_account',
                        metasync_google_index_account_nonce: $('#metasync_google_index_account_nonce').val(),
                        google_index_service_account_json: $('#google_index_service_account_json').val()
                    },
                    success: function(response) {
                        if (response && response.success) {
                            if (response.data && response.data.saved) {
                                // Reload so the configured-state UI and the
                                // transient success banner both render.
                                window.location.reload();
                                return;
                            }
                            showSaveMessage((response.data && response.data.message) || 'Nothing to save.', 'warning');
                        } else {
                            var msg = (response && response.data && response.data.message)
                                ? response.data.message
                                : 'Failed to save the service account.';
                            showSaveMessage(msg, 'error');
                        }
                    },
                    error: function() {
                        showSaveMessage('Network error while saving. Please try again.', 'error');
                    },
                    complete: function() {
                        button.prop('disabled', false).html(original);
                    }
                });
            });

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
                        action: 'metasync_google_index_direct_test',
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
                                html += '<li><strong>Service Account:</strong> ' + escHtml(results.credentials_test.client_email) + '</li>';
                                html += '<li><strong>Project ID:</strong> ' + escHtml(results.credentials_test.project_id) + '</li>';
                                html += '<li><strong>Private Key:</strong> ' + (results.credentials_test.has_private_key ? '✅ Present' : '❌ Missing') + '</li>';
                            }
                            
                            if (results.homepage_test) {
                                if (results.homepage_test.success) {
                                    html += '<li><strong>Homepage Status:</strong> ✅ Success</li>';
                                } else {
                                    html += '<li><strong>Homepage Status:</strong> ⚠️ ' + escHtml(results.homepage_test.error.message) + '</li>';
                                    if (results.homepage_test.note) {
                                        html += '<li><strong>Note:</strong> ' + escHtml(results.homepage_test.note) + '</li>';
                                    }
                                }
                            }
                            
                            html += '</ul></div>';
                            resultDiv.html(html);
                        } else {
                            resultDiv.html('<div class=\"notice notice-error inline\"><p><strong>❌ Test Failed:</strong> ' + escHtml(response.data.message) + '</p></div>');
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

                var dedicatedNonce = $('#metasync_google_index_account_nonce').val();

                if (dedicatedNonce) {
                    // Standalone Instant Indexing page: there is no settings
                    // form to serialize, so post to the dedicated endpoint.
                    $.ajax({
                        url: (typeof ajaxurl !== 'undefined') ? ajaxurl : metaSync.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'metasync_google_index_clear_account',
                            metasync_google_index_account_nonce: dedicatedNonce
                        },
                        success: function(response) {
                            if (response && response.success) {
                                window.location.reload();
                                return;
                            }
                            var msg = (response && response.data && response.data.message)
                                ? response.data.message
                                : 'Failed to clear the service account.';
                            showSaveMessage(msg, 'error');
                        },
                        error: function() {
                            showSaveMessage('Network error while clearing. Please try again.', 'error');
                        }
                    });
                    return;
                }

                // Embedded contexts: piggyback on the page's settings form.
                var input = $('<input>')
                    .attr('type', 'hidden')
                    .attr('name', 'google_index_clear_config')
                    .attr('value', '1');

                // Append to the correct form (Indexation Control vs General Settings) and submit via AJAX
                var clrForm = $('#metaSyncSeoControlsForm').length ? $('#metaSyncSeoControlsForm') : $('#metaSyncGeneralSetting');
                var clrAction = $('#metaSyncSeoControlsForm').length ? 'meta_sync_save_seo_controls' : 'meta_sync_save_settings';
                clrForm.append(input);
                var clrData = clrForm.serialize() + '&action=' + clrAction;

                // Submit the clear request directly via AJAX, then reload
                $.ajax({ url: ajaxurl || metaSync.ajax_url, type: 'POST', data: clrData, complete: function() { window.location.reload(); } });
            });
            
        });
        ";
    }
}

// Initialize admin functionality
if (is_admin()) {
    new Google_Index_Admin();
}
