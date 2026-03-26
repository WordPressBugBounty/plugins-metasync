<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}


/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 *
 * @package    Metasync
 * @subpackage Metasync/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Metasync
 * @subpackage Metasync/admin
 * @author     Engineering Team <support@searchatlas.com>
 */
class Metasync_Admin
{
    
    // Section constants for settings
    const SECTION_FEATURES              = "features_settings";
    const SECTION_METASYNC              = "metasync_settings";
    const SECTION_SEARCHENGINE          = "searchengine_settings";
    const SECTION_LOCALSEO              = "local_seo";
    const SECTION_CODESNIPPETS          = "code_snippets";
    const SECTION_OPTIMAL_SETTINGS      = "optimal_settings";
    const SECTION_SITE_SETTINGS         = "site_settings";
    const SECTION_COMMON_SETTINGS       = "common_settings";
    const SECTION_COMMON_META_SETTINGS  = "common_meta_settings";
    const SECTION_SOCIAL_META           = "social_meta";
    const SECTION_SEO_CONTROLS          = "seo_controls";
    const SECTION_SEO_CONTROLS_ADVANCED = "seo_controls_advanced";
    const SECTION_SEO_CONTROLS_INSTANT_INDEX = "seo_controls_instant_index";
    const SECTION_PLUGIN_VISIBILITY     = "plugin_visibility_settings";

    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $plugin_name    The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $version    The current version of this plugin.
     */
    private $version;

    /**
     * Holds the values to be used in the fields callbacks
     */
    private $options;
    public $menu_title         = "Search Atlas"; // Default, overridden by get_effective_menu_title()
    public const page_title         = "MetaSync Settings";

    /**
     * Get effective menu title with whitelabel company name
     */
    public function get_effective_menu_title()
    {
        $whitelabel_company_name = Metasync::get_whitelabel_company_name();
        
        if ($whitelabel_company_name) {
            return $whitelabel_company_name . ' SEO';
        }
        
        // Use centralized method for getting effective plugin name
        return Metasync::get_effective_plugin_name();
    }
    public const option_group       = "metasync_group";
    public const option_key         = "metasync_options";
    public static $page_slug          = "searchatlas";
    /**
     * Get the effective dashboard domain
     * Returns whitelabel domain if set, otherwise returns default production domain
     * Uses centralized constant from main Metasync class
     */
    public static function get_effective_dashboard_domain()
    {
        // Delegate to main Metasync class for consistent domain resolution
        return Metasync::get_dashboard_domain();
    }
    
    /**
     * Static method to render header and navigation for external pages (like AI Agent)
     * This creates a minimal instance just for rendering
     */
    public static function render_standard_header_nav($page_title = null, $current_page = null) {
        Metasync_Admin_Navigation::instance()->render_standard_header_nav($page_title, $current_page);
    }
    
    /**
     * Static header render
     */
    public static function render_static_header($page_title = null) {
        Metasync_Admin_Navigation::instance()->render_static_header($page_title);
    }
    
    /**
     * Static navigation render
     */
    public static function render_static_navigation($current_page = null) {
        Metasync_Admin_Navigation::instance()->render_static_navigation($current_page);
    }

    /**
     * Get dashboard URL with authentication tokens and tracking parameters
     * Returns the complete URL for accessing the Search Atlas dashboard
     */
    public function get_dashboard_url()
    {
        // Get the effective dashboard domain (whitelabel or production)
        $dashboard_url = self::get_effective_dashboard_domain();
        
        // Get current options for token inclusion
        $general_options = Metasync::get_option('general');
        
        // Add JWT token if available for seamless login
        if (isset($general_options['linkgraph_token']) && !empty($general_options['linkgraph_token'])) {
            $dashboard_url .= '/?jwtToken=' . urlencode($general_options['linkgraph_token']);
        }
        
        // Add source tracking parameter
        $dashboard_url .= (strpos($dashboard_url, '?') !== false ? '&' : '?') . 'source=wordpress-plugin';
        
        // Add whitelabel identification if in whitelabel mode
        $whitelabel_company_name = Metasync::get_whitelabel_company_name();
        if ($whitelabel_company_name) {
            $dashboard_url .= '&whitelabel=' . urlencode($whitelabel_company_name);
        }
        
        return $dashboard_url;
    }

    public const feature_sections = array(
        'enable_404monitor'         => 'Enable 404 Monitor',
        'enable_siteverification'   => 'Enable Site Verification',
        'enable_localbusiness'      => 'Enable Local Business',
        'enable_codesnippets'       => 'Enable Code Snippets',
        'enable_googleconsole'      => 'Enable Google Console',
        'enable_optimalsettings'    => 'Enable Optimal Settings',
        'enable_globalsettings'     => 'Enable Global Settings',
        'enable_commonmetastatus'   => 'Enable Common Meta Status',
        'enable_socialmeta'         => 'Enable Social Meta',
        'enable_redirections'       => 'Enable Redirections',
        'enable_errorlogs'          => 'Enable Error Logs'
    );

    private $database;
    private $db_redirection;
    public $db_heartbeat_errors;
    public $setup_wizard;


    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param      string    $plugin_name       The name of this plugin.
     * @param      string    $version    The version of this plugin.
     */

    public function __construct($plugin_name, $version, &$database, $db_redirection, $db_heartbeat_errors) // , $data_error_log_list
    {

        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->database = $database;
        $this->db_redirection = $db_redirection;
        $this->db_heartbeat_errors = $db_heartbeat_errors;
        // $this->data_error_log_list = $data_error_log_list;

        // Initialize setup wizard
        $this->setup_wizard = new Metasync_Setup_Wizard($plugin_name, $version);

        // Wire up the extracted settings-fields singleton
        Metasync_Settings_Fields::instance()->set_admin_instance($this);
        
        // Get data first for menu configuration
        $data = Metasync::get_option('general');
        
        // Set menu title using the effective title (includes whitelabel company name if available)
        $this->menu_title = $this->get_effective_menu_title();      
        if(!isset( $data['white_label_plugin_menu_slug'])){
            self::$page_slug = "searchatlas";   
        }else{
            self::$page_slug = $data['white_label_plugin_menu_slug']==""  ? "searchatlas":$data['white_label_plugin_menu_slug'];
        } 
       
        add_action('admin_menu', array($this, 'add_plugin_settings_page'));
        add_action('admin_menu', array($this, 'add_import_external_data_page'));
        add_action('admin_init', array($this, 'settings_page_init'));
        add_filter('all_plugins',  array($this,'metasync_plugin_white_label'));
        add_filter( 'plugin_row_meta',array($this,'metasync_view_detials_url'),10,3);
        add_filter('site_transient_update_plugins', array($this, 'inject_whitelabel_icon_into_update_transient'));

        // Display transient error/success messages for redirections
        add_action('admin_notices', array($this, 'display_redirection_messages'));

        // Add custom column for HTML-converted pages
        add_filter('manage_posts_columns', array($this, 'add_html_converted_column'));
        add_filter('manage_pages_columns', array($this, 'add_html_converted_column'));
        add_action('manage_posts_custom_column', array($this, 'render_html_converted_column'), 10, 2);
        add_action('manage_pages_custom_column', array($this, 'render_html_converted_column'), 10, 2);

        // Add badge to page editor screen
        add_action('edit_form_after_title', array($this, 'add_editor_source_notice'));

        // Add badge to quick edit panel
        add_action('quick_edit_custom_box', array($this, 'add_quick_edit_source_display'), 10, 2);

        // Add dashboard widget
        add_action('wp_dashboard_setup', array($this, 'add_html_pages_dashboard_widget'));

        // Add Search Atlas status to WordPress admin bar (priority 999 to ensure plugin is fully loaded)
        // Always add the action - the method will check the setting internally
        add_action('admin_bar_menu', array($this, 'add_searchatlas_admin_bar_status'), 999);

        #add css into admin header for icon image

        add_action('admin_head', array($this,'metasync_admin_icon_style'));
        
        // Always add admin bar styles - the method will check the setting internally
        add_action('wp_head', array($this,'metasync_admin_bar_style')); // For frontend admin bar
        add_action('admin_head', array($this,'metasync_admin_bar_style')); // For backend admin bar
        // removing this as we don't need it anymore because we are using wp-ajax to implement the white label
       // add_action('update_option_metasync_options', array($this, 'check_and_redirect_slug'), 10, 3);

        // Sync plugin file headers whenever metasync_options is updated (covers AJAX save, Settings API, import)
        add_action('update_option_metasync_options', array($this, 'on_options_updated_sync_file_headers'), 10, 2);

        add_action('admin_init', array($this, 'initialize_cookie'));
        add_action('admin_init', array($this, 'maybe_redirect_to_wizard'));

        // Add admin_post hooks for form submissions (WordPress standard way - no output buffering needed)
        add_action('admin_post_metasync_clear_all_cache_plugins', array($this, 'handle_clear_all_cache_plugins'));
        add_action('admin_post_metasync_clear_otto_cache_all', array($this, 'handle_clear_otto_cache_all'));
        add_action('admin_post_metasync_clear_otto_cache_url', array($this, 'handle_clear_otto_cache_url'));
        add_action('admin_post_metasync_purge_hosting_cache', array($this, 'handle_purge_hosting_cache'));

        // Add AJAX for saving general settings
        add_action( 'wp_ajax_meta_sync_save_settings', array($this,'meta_sync_save_settings') );
        
        // Add AJAX for saving Indexation Control settings
        add_action( 'wp_ajax_meta_sync_save_seo_controls', array($this,'meta_sync_save_seo_controls') );
        
        // Add AJAX for saving execution settings
        add_action( 'wp_ajax_metasync_save_execution_settings', array($this, 'ajax_save_execution_settings') );

        // Add AJAX for saving hosting cache settings
        add_action( 'wp_ajax_metasync_save_hosting_cache_settings', array($this, 'ajax_save_hosting_cache_settings') );

        // Add AJAX for saving edge cache / CDN settings
        add_action( 'wp_ajax_metasync_save_edge_cache_settings', array('Metasync_Edge_Cache_Settings', 'ajax_save') );
        
        // Add AJAX handler for Plugin Auth Token refresh
        add_action('wp_ajax_refresh_plugin_auth_token', array($this, 'refresh_plugin_auth_token'));
        
        // Add AJAX handler to get current Plugin Auth Token (for UI updates)
        add_action('wp_ajax_get_plugin_auth_token', array($this, 'get_plugin_auth_token'));
        
        // Add AJAX handler for creating redirects from 404 suggestions
        add_action('wp_ajax_metasync_create_redirect_from_404', array($this, 'ajax_create_redirect_from_404'));

        // Add AJAX handler for updating database structure
        add_action('wp_ajax_metasync_update_db_structure', array($this, 'ajax_update_db_structure'));

        // Add AJAX handlers for setup wizard
        add_action('wp_ajax_metasync_save_wizard_progress', array($this, 'ajax_save_wizard_progress'));
        add_action('wp_ajax_metasync_complete_wizard', array($this, 'ajax_complete_wizard'));

        // Add AJAX handlers for robots.txt
        add_action('wp_ajax_metasync_validate_robots', array($this, 'ajax_validate_robots'));
        add_action('wp_ajax_metasync_get_default_robots', array($this, 'ajax_get_default_robots'));
        add_action('wp_ajax_metasync_preview_robots_backup', array($this, 'ajax_preview_robots_backup'));
        add_action('wp_ajax_metasync_delete_robots_backup', array($this, 'ajax_delete_robots_backup'));
        add_action('wp_ajax_metasync_restore_robots_backup', array($this, 'ajax_restore_robots_backup'));
        
        // Add AJAX handlers for host blocking test
        add_action('wp_ajax_metasync_test_host_blocking_get', array($this, 'ajax_test_host_blocking_get'));
        add_action('wp_ajax_metasync_test_host_blocking_post', array($this, 'ajax_test_host_blocking_post'));

        // Add AJAX handlers for OTTO excluded URLs
        add_action('wp_ajax_metasync_otto_add_excluded_url', array($this, 'ajax_otto_add_excluded_url'));
        add_action('wp_ajax_metasync_otto_delete_excluded_url', array($this, 'ajax_otto_delete_excluded_url'));
        add_action('wp_ajax_metasync_burst_ping', array($this, 'ajax_burst_ping'));
        add_action('wp_ajax_metasync_otto_get_excluded_urls', array($this, 'ajax_otto_get_excluded_urls'));
        add_action('wp_ajax_metasync_otto_recheck_excluded_url', array($this, 'ajax_otto_recheck_excluded_url'));

        # Add AJAX handler for Mixpanel tracking
        add_action('wp_ajax_metasync_track_one_click_activation', array($this, 'ajax_track_one_click_activation'));

        # Add AJAX handler for submitting issue reports
        add_action('wp_ajax_metasync_submit_issue_report', array($this, 'ajax_submit_issue_report'));

        # Add AJAX handlers for support token management

        # Add AJAX handler for theme switcher
        add_action('wp_ajax_metasync_save_theme', array($this, 'ajax_save_theme'));

        # Add AJAX handler for external data import
        add_action('wp_ajax_metasync_import_external_data', array($this, 'ajax_import_external_data'));

        # Add AJAX handler for SEO metadata batch import
        add_action('wp_ajax_metasync_import_seo_metadata', array($this, 'ajax_import_seo_metadata'));

        # Add AJAX handler for password recovery
        add_action('wp_ajax_metasync_recover_password', array($this, 'ajax_recover_password'));

        # Add AJAX handler for resetting bot statistics
        add_action('wp_ajax_metasync_reset_bot_stats', array($this, 'ajax_reset_bot_stats'));

        # Add admin-post handler for exporting whitelabel settings (file download)
        add_action('admin_post_metasync_export_whitelabel_settings', array($this, 'handle_export_whitelabel_settings'));

        # Media Optimization AJAX handlers
        add_action('wp_ajax_metasync_optimize_single_image', array($this, 'ajax_optimize_single_image'));
        add_action('wp_ajax_metasync_revert_single_image', array($this, 'ajax_revert_single_image'));
        add_action('wp_ajax_metasync_start_batch_optimize', array($this, 'ajax_start_batch_optimize'));
        add_action('wp_ajax_metasync_cancel_batch_optimize', array($this, 'ajax_cancel_batch_optimize'));
        add_action('wp_ajax_metasync_batch_progress', array($this, 'ajax_batch_progress'));
        add_action('wp_ajax_metasync_bulk_optimize_selected', array($this, 'ajax_bulk_optimize_selected'));
        add_action('wp_ajax_metasync_process_batch_tick', array($this, 'ajax_process_batch_tick'));
        add_action('metasync_media_batch_optimize_cron', array($this, 'handle_media_batch_cron'));

        # Add AJAX handlers for Google Instant Indexing
        add_action('wp_ajax_send_giapi', array($this, 'ajax_send_giapi'));

        # Add AJAX handlers for Bing Instant Indexing (IndexNow)
        add_action('wp_ajax_send_bing_indexnow', array($this, 'ajax_send_bing_indexnow'));

        # Add hooks for instant indexing settings saves
        add_action('admin_init', array($this, 'save_instant_indexing_settings'));

        # Add hooks for instant indexing post actions
        add_filter('post_row_actions', array($this, 'add_instant_indexing_post_actions'), 10, 2);
        add_filter('page_row_actions', array($this, 'add_instant_indexing_post_actions'), 10, 2);

        # Add hooks for auto-submission on post publish
        add_action('save_post', array($this, 'auto_submit_to_instant_indexing'), 10, 3);

        // Add REST API endpoint for ping
        add_action('rest_api_init', array($this, 'register_ping_rest_endpoint'));
        
        // Add heartbeat cron functionality
        add_filter('cron_schedules', array($this, 'add_heartbeat_cron_schedule'));
        add_action('metasync_heartbeat_cron_check', array($this, 'execute_heartbeat_cron_check'));
        add_action('metasync_burst_heartbeat', array($this, 'execute_burst_heartbeat'));
        add_action('metasync_announce_cron', array($this, 'execute_announce_cron'));
        
        // PR3: Transition to KEY_PENDING when API key is added or rotated
        add_action('metasync_heartbeat_state_key_pending', array($this, 'set_heartbeat_state_key_pending'));
        
        // Add transient cleanup cron functionality
        add_action('metasync_cleanup_transients', array($this, 'execute_transient_cleanup'));
        
        // Schedule heartbeat cron on plugin load (if not already scheduled)
        add_action('init', array($this, 'maybe_schedule_heartbeat_cron'));
        
        // Schedule transient cleanup cron on plugin load
        add_action('init', array($this, 'maybe_schedule_transient_cleanup_cron'));
        
        # Schedule hidden post manager cron on plugin load (runs every 7 days)
        add_action('init', array($this, 'maybe_schedule_hidden_post_check'));

        # Schedule OTTO 404 exclusion recheck (runs daily to recheck URLs excluded 7+ days ago)
        add_action('init', array($this, 'maybe_schedule_otto_recheck_404_cron'));

        # Schedule support token cleanup cron on plugin load (runs daily)

        // Listen for immediate heartbeat trigger requests from other parts of the plugin
        add_action('metasync_trigger_immediate_heartbeat', array($this, 'handle_immediate_heartbeat_trigger'));
        
        // Listen for cron scheduling requests (after Search Atlas connect authentication)
        add_action('metasync_ensure_heartbeat_cron_scheduled', array($this, 'maybe_schedule_heartbeat_cron'));
        
        // Action Scheduler configuration filters
        add_filter('action_scheduler_queue_runner_concurrent_batches', array($this, 'get_action_scheduler_batches'));
        add_filter('action_scheduler_retention_period', array($this, 'get_action_scheduler_retention_period'));
        
        // Note: Option change monitoring is now handled by the centralized API Key Monitor class
        // This provides more comprehensive and intelligent monitoring of API key changes
       
        
        #--------------------------
        #   we are disabling this code
        #   To prevent enabling debuging on every pluging Update
        #   This causes Issue #102 on gilab
        #--------------------------
        #
        # NOTE:
        # Do not delete this as we may need it in implementing universal logging

        # add_action('upgrader_process_complete', array($this,'metasync_plugin_updated_action'), 10, 2);

        # Hook into post category creation and update (AFTER they are saved)
        add_action('saved_term', array($this,'admin_crud_term'), 10, 3);

        # Hook into post category deletion (AFTER it is deleted)
       // add_action('pre_delete_term', array($this,'admin_delete_term'), 10, 2);
        # Using delete_category hook which fires AFTER category is fully deleted and provides correct data
        add_action('delete_category', array($this,'admin_delete_category'), 10, 1);

    }
    /*
        This function add css to wp admin header
    */
    public function metasync_admin_icon_style(){
        # Get Metasync Option

        $data= Metasync::get_option('general');

        # Get white label menu slug
        $menu_slug = empty($data['white_label_plugin_menu_slug']) ?  self::$page_slug : $data['white_label_plugin_menu_slug'];
        
            ?>
            <style>
                #toplevel_page_<?php echo esc_attr(str_replace(' ', '-',$menu_slug)); ?> .wp-menu-image.dashicons-before img {
                    width: 36px;
                    height: 34px;
                    padding: 0!important;
                    object-fit: contain;
                    object-position: center;
                }
                #toplevel_page_<?php echo esc_attr(str_replace(' ', '-',$menu_slug)); ?> .wp-menu-image img {
                    width: 20px !important;
                }
            </style>
            <?php        
    }

    #---------fixes issue : #95 ----------
    #This function is to redirect in case client changes slug on fresh install
    #It is called by the add_option hook
    
    public function redirect_slug_for_freshinstalls(){
        #get the db menu slug
        $plugin_menu_slug = Metasync::get_option('general')['white_label_plugin_menu_slug'] ?? '';
        
        #check that the slug is set or set it to the defaul class slug usually ('searchatlas')
        $current_slug = empty($plugin_menu_slug) ? self::$page_slug : $plugin_menu_slug;
        
        #check if we have the cookie set and check if the slug has changed
        if (isset($_COOKIE['metasync_previous_slug']) && $_COOKIE['metasync_previous_slug'] !== $current_slug) {
            # the slug changed so we need to update the cookie
            setcookie('metasync_previous_slug', $current_slug, time() + 3600, COOKIEPATH, COOKIE_DOMAIN);
            $_COOKIE['metasync_previous_slug'] = $current_slug;
    
            #Redirect url to the new slug
            $redirect_url = admin_url('admin.php?page=' . $current_slug);
            
            wp_redirect($redirect_url);
            exit;
        }
    }

    public function metasync_plugin_updated_action($upgrader_object, $options){
        if ($options['action'] == 'update' && $options['type'] == 'plugin') {
            // List of plugins being updated
            $updated_plugins = $options['plugins'];
    
            // Loop through plugins and check if your plugin is updated
            if (in_array(plugin_basename(dirname(__DIR__) . '/metasync.php'), $updated_plugins, true)) {
                // Only set debug options if they don't already exist (first install/update)
                if (get_option('wp_debug_enabled') === false) {
                    update_option('wp_debug_enabled', 'true');
                }
                if (get_option('wp_debug_log_enabled') === false) {
                    update_option('wp_debug_log_enabled', 'true');
                }
                if (get_option('wp_debug_display_enabled') === false) {
                    update_option('wp_debug_display_enabled', 'false');
                }
            }
        }
    }
    
    public function initialize_cookie() {
        // Check if cookie is already set
        if (!isset($_COOKIE['metasync_previous_slug'])) {
            // Only set cookie if headers haven't been sent yet
            if (!headers_sent()) {
                $data = Metasync::get_option('general');
                // Retrieve the current slug
                $initial_slug = isset($data['white_label_plugin_menu_slug']) ? $data['white_label_plugin_menu_slug'] : self::$page_slug;
                // Set the cookie
                setcookie('metasync_previous_slug', $initial_slug, time() + 3600, COOKIEPATH, COOKIE_DOMAIN);
            }
        }
    }

    /**
     * Redirect to setup wizard on first activation
     *
     * @since    1.0.0
     */
    public function maybe_redirect_to_wizard() {
        // Only redirect if wizard should be shown
        if (get_option('metasync_show_wizard') && !isset($_GET['page'])) {
            delete_option('metasync_show_wizard');

            // Don't redirect during AJAX, cron, or bulk activation
            if (wp_doing_ajax() || wp_doing_cron() || isset($_GET['activate-multi'])) {
                return;
            }

            // Only redirect if user has access to the plugin
            if (!Metasync::current_user_has_plugin_access()) {
                return;
            }

            wp_safe_redirect(admin_url('admin.php?page=' . self::$page_slug . '-setup-wizard'));
            exit;
        }
    }

    public function metasync_display_error_log() {
        Metasync_Debug_Manager::instance()->metasync_display_error_log($this);
    }
    public function metasync_update_wp_config() {
        Metasync_Debug_Manager::instance()->metasync_update_wp_config();
    }
    

    /**
     * Sync plugin file headers when metasync_options is updated.
     * This ensures whitelabel values are written to the plugin file header
     * so they persist even when the plugin is deactivated.
     *
     * @param mixed $old_value The old option value.
     * @param mixed $new_value The new option value.
     * @since 2.5.0
     */
    public function on_options_updated_sync_file_headers($old_value, $new_value)
    {
        Metasync_Debug_Manager::instance()->on_options_updated_sync_file_headers($old_value, $new_value);
    }

    public function check_and_redirect_slug($option, $old_value, $new_value) {
        // Ensure this hook is only triggered for your specific option group

        if (!isset($option['general'] ) && !isset($option['general']['white_label_plugin_menu_slug'])) {   
                 
            return;
        }    
    
        $new_slug = $new_value['general']['white_label_plugin_menu_slug'] ?? self::$page_slug;
        
        $old_slug = $old_value['general']['white_label_plugin_menu_slug'] ?? self::$page_slug;

        if ($new_slug !== $old_slug && $old_slug !=='' ){
            // Set a new cookie
            setcookie('metasync_previous_slug', $new_slug, time() + 3600, COOKIEPATH, COOKIE_DOMAIN);
            $_COOKIE['metasync_previous_slug'] = $new_slug;           
            // Redirect to the new slug
            $redirect_url = admin_url('admin.php?page=' . $old_slug);
            wp_redirect($redirect_url);
            exit;
        }else{
            self::$page_slug = Metasync::get_option('general')['white_label_plugin_menu_slug']==""  ? "searchatlas":Metasync::get_option('general')['white_label_plugin_menu_slug'];
            $redirect_url = admin_url('admin.php?page=' .  self::$page_slug);

            #add redirection for when the old slug is not defined
            #this fixes the redirect issue #
            wp_redirect($redirect_url);
            exit;
        }
    }
    public function metasync_view_detials_url( $plugin_meta, $plugin_file, $plugin_data ) {
        $plugin_uri = Metasync::get_option('general')['white_label_plugin_uri'] ?? '';
        $this_plugin = plugin_basename(dirname(__DIR__) . '/metasync.php');
        if ($this_plugin === $plugin_file && $plugin_uri !== '') {
            foreach ($plugin_meta as &$meta) {
                if (strpos($meta, 'open-plugin-details-modal') !== false) {
                    $meta = sprintf(
                        '<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
                        add_query_arg('TB_iframe', 'true', $plugin_uri),
                        esc_attr(sprintf(esc_html__('More information about %s'), $plugin_data['Name'])),
                        esc_attr($plugin_data['Name']),
                        esc_html__('View details', 'metasync')
                    );
                    break; // Exit loop after replacing the link
                }
            }
        }
        return $plugin_meta;
    }

    public function metasync_plugin_white_label($all_plugins) {
        $general = Metasync::get_option('general');
        if (!is_array($general)) {
            return $all_plugins;
        }

        $plugin_name = $general['white_label_plugin_name'] ?? '';
        $plugin_description = $general['white_label_plugin_description'] ?? '';
        $plugin_author = $general['white_label_plugin_author'] ?? '';
        $plugin_author_uri = $general['white_label_plugin_author_uri'] ?? '';
        $plugin_uri = $general['white_label_plugin_uri'] ?? '';

        // Dynamically resolve the plugin basename to handle renamed plugin folders
        $this_plugin = plugin_basename(dirname(__DIR__) . '/metasync.php');

        if (isset($all_plugins[$this_plugin])) {
            if ($plugin_name !== '') {
                $all_plugins[$this_plugin]['Name'] = $plugin_name;
                $all_plugins[$this_plugin]['Title'] = $plugin_name;
            }
            if ($plugin_description !== '') {
                $all_plugins[$this_plugin]['Description'] = $plugin_description;
            }
            if ($plugin_author !== '') {
                $all_plugins[$this_plugin]['Author'] = $plugin_author;
                $all_plugins[$this_plugin]['AuthorName'] = $plugin_author;
            }
            if ($plugin_author_uri !== '') {
                $all_plugins[$this_plugin]['AuthorURI'] = $plugin_author_uri;
            }
            if ($plugin_uri !== '') {
                $all_plugins[$this_plugin]['PluginURI'] = $plugin_uri;
            }
        }

        return $all_plugins;
    }

    /**
     * Inject the whitelabel icon into the update_plugins transient so that
     * /wp-admin/update-core.php (Dashboard → Updates) shows the WL icon
     * instead of the SearchAtlas icon returned by the update API.
     *
     * @param  object $transient  The site transient object.
     * @return object
     */
    public function inject_whitelabel_icon_into_update_transient($transient) {
        if (empty($transient) || !is_object($transient)) {
            return $transient;
        }

        $general = Metasync::get_option('general');
        if (!is_array($general)) {
            return $transient;
        }

        $icon_url = $general['white_label_plugin_menu_icon'] ?? '';
        if (empty($icon_url)) {
            return $transient;
        }

        $this_plugin = plugin_basename(dirname(__DIR__) . '/metasync.php');

        // Inject into pending updates list
        if (!empty($transient->response) && isset($transient->response[$this_plugin])) {
            $transient->response[$this_plugin]->icons = [
                '1x'  => $icon_url,
                '2x'  => $icon_url,
            ];
        }

        // Also inject into the "no update needed" list so the icon appears
        // on the updates screen even when the plugin is up-to-date
        if (!empty($transient->no_update) && isset($transient->no_update[$this_plugin])) {
            $transient->no_update[$this_plugin]->icons = [
                '1x'  => $icon_url,
                '2x'  => $icon_url,
            ];
        }

        return $transient;
    }

    /**
     * Register the stylesheets for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_styles()
    {

        wp_enqueue_style(
            $this->plugin_name,
            plugin_dir_url(__FILE__) . 'css/metasync-admin.css',
            array(),
            $this->version,
            'all'
        );

        // Enqueue dashboard-style CSS for admin pages
        wp_enqueue_style(
            $this->plugin_name . '-dashboard',
            plugin_dir_url(__FILE__) . 'css/metasync-dashboard.css',
            array($this->plugin_name),
            $this->version,
            'all'
        );

        // Enqueue wizard CSS if on wizard page
        if (isset($_GET['page']) && strpos($_GET['page'], '-setup-wizard') !== false) {
            wp_enqueue_style(
                $this->plugin_name . '-setup-wizard',
                plugin_dir_url(__FILE__) . 'css/metasync-setup-wizard.css',
                array($this->plugin_name . '-dashboard'),
                $this->version,
                'all'
            );
        }
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts()
    {

        wp_enqueue_media();

        wp_enqueue_script(
            $this->plugin_name,
            plugin_dir_url(__FILE__) . 'js/metasync-admin.js',
            array('jquery'),
            $this->version,
            false
        );

        // Enqueue dashboard-style JavaScript for enhanced interactions
        wp_enqueue_script(
            $this->plugin_name . '-dashboard',
            plugin_dir_url(__FILE__) . 'js/metasync-dashboard.js',
            array('jquery', $this->plugin_name),
            $this->version,
            true
        );

        # Enqueue theme switcher
        wp_enqueue_script(
            $this->plugin_name . '-theme-switcher',
            plugin_dir_url(__FILE__) . 'js/metasync-theme-switcher.js',
            array('jquery'),
            $this->version,
            true
        );
        
        // --- Phase 5 (#887): Extracted inline JS files ---
        $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        $plugin_root_url = plugin_dir_url(dirname(__FILE__));

        // Dashboard iframe height (only on dashboard page)
        if ($current_page === self::$page_slug . '-dashboard' || $current_page === self::$page_slug) {
            wp_enqueue_script(
                $this->plugin_name . '-iframe',
                plugin_dir_url(__FILE__) . 'js/metasync-iframe.js',
                array(),
                $this->version,
                false // Load in head — needed before iframe renders
            );
        }

        // Settings page scripts (save btn, clear settings, bing key, access roles)
        if ($current_page === self::$page_slug || strpos($current_page, self::$page_slug) === 0) {
            wp_enqueue_script(
                $this->plugin_name . '-settings',
                plugin_dir_url(__FILE__) . 'js/metasync-settings.js',
                array('jquery'),
                $this->version,
                true
            );
        }

        // Tab switcher (redirections / 404-monitor page)
        if ($current_page === self::$page_slug . '-redirections') {
            wp_enqueue_script(
                $this->plugin_name . '-tab-switcher',
                plugin_dir_url(__FILE__) . 'js/metasync-tab-switcher.js',
                array('jquery'),
                $this->version,
                true
            );
        }

        // Error logs — copy to clipboard
        if ($current_page === self::$page_slug || strpos($current_page, self::$page_slug) === 0) {
            wp_enqueue_script(
                $this->plugin_name . '-error-logs',
                $plugin_root_url . 'site-error-logs/js/metasync-error-logs.js',
                array(),
                $this->version,
                true
            );
        }

        // Access control UI
        if ($current_page === self::$page_slug || strpos($current_page, self::$page_slug) === 0) {
            wp_enqueue_script(
                $this->plugin_name . '-access-control',
                $plugin_root_url . 'includes/js/metasync-access-control.js',
                array(),
                $this->version,
                true
            );
        }

        // 404 monitor filter
        if ($current_page === self::$page_slug . '-redirections' || $current_page === self::$page_slug . '-404-monitor') {
            wp_enqueue_script(
                $this->plugin_name . '-404-monitor',
                $plugin_root_url . 'views/js/metasync-404-monitor.js',
                array(),
                $this->version,
                true
            );
        }

        // Redirections filter
        if ($current_page === self::$page_slug . '-redirections') {
            wp_enqueue_script(
                $this->plugin_name . '-redirections',
                $plugin_root_url . 'views/js/metasync-redirections.js',
                array(),
                $this->version,
                true
            );
        }
        // --- Phase 5 Part B: Extracted inline JS with wp_localize_script ---

        // Navigation portal menus (top bar + settings inner page)
        $whitelabel_data = Metasync::get_option('whitelabel');
        if ($current_page === self::$page_slug || strpos($current_page, self::$page_slug) === 0) {
            wp_enqueue_script(
                $this->plugin_name . '-navigation',
                plugin_dir_url(__FILE__) . 'js/metasync-navigation.js',
                array(),
                $this->version,
                false // Load in head — global functions called from onclick attributes
            );
            wp_localize_script($this->plugin_name . '-navigation', 'metasyncNavData', array(
                'hideAdvanced' => !empty($whitelabel_data['hide_advanced']),
                'showGeneral'  => Metasync_Access_Control::user_can_access('hide_settings'),
                'pageSlug'     => self::$page_slug,
            ));
        }

        // Debug mode timer
        if ($current_page === self::$page_slug || strpos($current_page, self::$page_slug) === 0) {
            $debug_manager = Metasync_Debug_Mode_Manager::get_instance();
            $debug_status = $debug_manager->get_status();
            wp_enqueue_script(
                $this->plugin_name . '-debug-mode',
                plugin_dir_url(__FILE__) . 'js/metasync-debug-mode.js',
                array('jquery'),
                $this->version,
                true
            );
            wp_localize_script($this->plugin_name . '-debug-mode', 'metasyncDebugData', array(
                'enabled'       => !empty($debug_status['enabled']),
                'indefinite'    => !empty($debug_status['indefinite']),
                'timeRemaining' => isset($debug_status['time_remaining']) ? (int) $debug_status['time_remaining'] : 0,
                'statusUrl'     => rest_url('metasync/v1/debug-mode/status'),
                'restNonce'     => wp_create_nonce('wp_rest'),
            ));
        }

        // MetasyncConfig + admin bar sync
        if ($current_page === self::$page_slug || strpos($current_page, self::$page_slug) === 0) {
            wp_enqueue_script(
                $this->plugin_name . '-config',
                plugin_dir_url(__FILE__) . 'js/metasync-config.js',
                array('jquery'),
                $this->version,
                true
            );
            wp_localize_script($this->plugin_name . '-config', 'metasyncConfigData', array(
                'pluginName' => Metasync::get_effective_plugin_name(),
                'ottoName'   => Metasync::get_whitelabel_otto_name(),
            ));
        }

        // Whitelabel password (forgot password handler)
        if ($current_page === self::$page_slug || strpos($current_page, self::$page_slug) === 0) {
            wp_enqueue_script(
                $this->plugin_name . '-whitelabel',
                plugin_dir_url(__FILE__) . 'js/metasync-whitelabel.js',
                array('jquery'),
                $this->version,
                true
            );
            wp_localize_script($this->plugin_name . '-whitelabel', 'metasyncWhitelabelData', array(
                'recoverNonce' => wp_create_nonce('metasync_recover_password_nonce'),
            ));
        }

        // Whitelabel connect (validation modal + lock section + export)
        if ($current_page === self::$page_slug || strpos($current_page, self::$page_slug) === 0) {
            wp_enqueue_script(
                $this->plugin_name . '-connect',
                plugin_dir_url(__FILE__) . 'js/metasync-connect.js',
                array('jquery'),
                $this->version,
                true
            );
            wp_localize_script($this->plugin_name . '-connect', 'metasyncConnectData', array(
                'optionKey'        => self::option_key,
                'storedPassword'   => isset($whitelabel_data['settings_password']) ? $whitelabel_data['settings_password'] : '',
                'adminPostUrl'     => admin_url('admin-post.php'),
                'exportNonce'      => wp_create_nonce('metasync_export_whitelabel'),
                'ajaxUrl'          => admin_url('admin-ajax.php'),
                'logoutNonceField' => wp_nonce_field('whitelabel_logout_nonce', 'whitelabel_logout_nonce', true, false),
            ));
        }

        // Host blocking test (settings + dashboard)
        if ($current_page === self::$page_slug || strpos($current_page, self::$page_slug) === 0) {
            wp_enqueue_script(
                $this->plugin_name . '-host-blocking',
                plugin_dir_url(__FILE__) . 'js/metasync-host-blocking.js',
                array('jquery'),
                $this->version,
                true
            );
            wp_localize_script($this->plugin_name . '-host-blocking', 'metasyncHostBlockingData', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
            ));
        }

        // OTTO excluded URLs
        if ($current_page === self::$page_slug || strpos($current_page, self::$page_slug) === 0) {
            wp_enqueue_script(
                $this->plugin_name . '-excluded-urls',
                plugin_dir_url(__FILE__) . 'js/metasync-excluded-urls.js',
                array('jquery'),
                $this->version,
                true
            );
            wp_localize_script($this->plugin_name . '-excluded-urls', 'metasyncExcludedUrlsData', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('metasync_otto_excluded_urls'),
            ));
        }

        // Execution settings form
        if ($current_page === self::$page_slug || strpos($current_page, self::$page_slug) === 0) {
            $server_limits = $this->get_server_limits();
            wp_enqueue_script(
                $this->plugin_name . '-execution-settings',
                plugin_dir_url(__FILE__) . 'js/metasync-execution-settings.js',
                array('jquery'),
                $this->version,
                true
            );
            wp_localize_script($this->plugin_name . '-execution-settings', 'metasyncExecSettingsData', array(
                'serverMaxExecTime' => ($server_limits['max_execution_time_raw'] == -1) ? 'Infinity' : (int) $server_limits['max_execution_time_raw'],
                'serverMaxMemory'   => ($server_limits['memory_limit_raw'] == -1) ? 'Infinity' : (int) $server_limits['memory_limit_raw'],
                'canChangeMemory'   => !empty($server_limits['can_change_memory']),
            ));
        }

        // Quick edit badge (custom pages)
        wp_enqueue_script(
            $this->plugin_name . '-quick-edit',
            plugin_dir_url(__FILE__) . 'js/metasync-quick-edit.js',
            array('jquery'),
            $this->version,
            true
        );
        wp_localize_script($this->plugin_name . '-quick-edit', 'metasyncQuickEditData', array(
            'standardPageLabel' => __('Standard page', 'metasync'),
        ));

        // Report issue form
        if ($current_page === self::$page_slug . '-report-issue') {
            wp_enqueue_script(
                $this->plugin_name . '-report-issue',
                $plugin_root_url . 'views/js/metasync-report-issue.js',
                array('jquery'),
                $this->version,
                true
            );
        }

        // Bing console
        if ($current_page === self::$page_slug . '-bing-console') {
            wp_enqueue_script(
                $this->plugin_name . '-bing-console',
                $plugin_root_url . 'views/js/metasync-bing-console.js',
                array('jquery'),
                $this->version,
                true
            );
        }

        // Add redirection form
        if ($current_page === self::$page_slug . '-redirections') {
            wp_enqueue_script(
                $this->plugin_name . '-add-redirection',
                $plugin_root_url . 'views/js/metasync-add-redirection.js',
                array(),
                $this->version,
                true
            );
        }

        // Import redirections
        if ($current_page === self::$page_slug . '-redirections') {
            wp_enqueue_script(
                $this->plugin_name . '-import-redirections',
                $plugin_root_url . 'views/js/metasync-import-redirections.js',
                array('jquery'),
                $this->version,
                true
            );
            wp_localize_script($this->plugin_name . '-import-redirections', 'metasyncImportRedirData', array(
                'nonce'       => wp_create_nonce('metasync_import_redirections'),
                'redirectUrl' => admin_url('admin.php?page=' . self::$page_slug . '-redirections'),
            ));
        }

        // Import external data (SEO metadata)
        if ($current_page === self::$page_slug || strpos($current_page, self::$page_slug) === 0) {
            wp_enqueue_script(
                $this->plugin_name . '-import-external-data',
                $plugin_root_url . 'views/js/metasync-import-external-data.js',
                array('jquery'),
                $this->version,
                true
            );
            wp_localize_script($this->plugin_name . '-import-external-data', 'metasyncImportData', array(
                'importNonce'    => wp_create_nonce('metasync_import_external_data'),
                'seoImportNonce' => wp_create_nonce('metasync_import_seo_metadata'),
            ));
        }

        // OTTO bot statistics
        if ($current_page === self::$page_slug || strpos($current_page, self::$page_slug) === 0) {
            wp_enqueue_script(
                $this->plugin_name . '-bot-statistics',
                $plugin_root_url . 'views/js/metasync-bot-statistics.js',
                array('jquery'),
                $this->version,
                true
            );
            wp_localize_script($this->plugin_name . '-bot-statistics', 'metasyncBotStatsData', array(
                'resetNonce' => wp_create_nonce('metasync_reset_bot_stats'),
            ));
        }

        // OTTO debug page
        if ($current_page === self::$page_slug . '-otto-debug') {
            wp_enqueue_script(
                $this->plugin_name . '-otto-debug',
                plugin_dir_url(__FILE__) . 'js/metasync-otto-debug.js',
                array('jquery'),
                $this->version,
                true
            );
            wp_localize_script($this->plugin_name . '-otto-debug', 'metasyncOttoDebugData', array(
                'nonce' => wp_create_nonce('metasync_otto_debug'),
            ));
        }

        // --- End Phase 5 enqueues ---

        # Localize theme switcher script
        wp_localize_script(
            $this->plugin_name . '-theme-switcher',
            'metasyncThemeData',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('metasync_theme_nonce'),
                'currentTheme' => get_option('metasync_theme', 'dark')
            )
        );

        // Localize the script to make the AJAX URL accessible
        $options = Metasync::get_option('general');
        // Get connection status for JavaScript
        $general_settings = Metasync::get_option('general');
        $searchatlas_api_key = isset($general_settings['searchatlas_api_key']) ? $general_settings['searchatlas_api_key'] : '';
        $otto_pixel_uuid = isset($general_settings['otto_pixel_uuid']) ? $general_settings['otto_pixel_uuid'] : '';
        
        // SECURITY FIX (CVE-2025-14386): Only generate Search Atlas connect nonce for administrators
        // Using strict capability check instead of plugin access roles
        $sa_connect_nonce = '';
        if (current_user_can('manage_options')) {
            $sa_connect_nonce = wp_create_nonce('metasync_sa_connect_nonce');
        }
        
        $heartbeat_state = $this->get_heartbeat_state();
        wp_localize_script( $this->plugin_name, 'metaSync', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
			'admin_url'=>admin_url('admin.php'),
			'sa_connect_nonce' => $sa_connect_nonce,
			'reset_auth_nonce' => wp_create_nonce('metasync_reset_auth_nonce'),
			'burst_ping_nonce' => wp_create_nonce('metasync_burst_ping'),
			'heartbeat_state' => $heartbeat_state,
			'dashboard_domain' => self::get_effective_dashboard_domain(),
			'support_email' => Metasync::SUPPORT_EMAIL,
			'documentation_domain' => Metasync::DOCUMENTATION_DOMAIN,
			'debug_enabled' => WP_DEBUG || (defined('METASYNC_DEBUG') && constant('METASYNC_DEBUG')),
			'searchatlas_api_key' => !empty($searchatlas_api_key),
			'otto_pixel_uuid' => $otto_pixel_uuid,
			'is_connected' => (bool)$this->is_heartbeat_connected()
        ));
        
        // Ensure ajaxurl is available for admin pages (WordPress standard)  
        // This creates a global ajaxurl variable for JavaScript
        wp_enqueue_script('wp-util');
        
        // Add inline script to ensure ajaxurl is defined
        $inline_script = "
        if (typeof ajaxurl === 'undefined') {
            var ajaxurl = '" . esc_js(admin_url('admin-ajax.php')) . "';
        }
        
        // Add Plugin Auth Token refresh functionality
        jQuery(document).ready(function($) {
            $('#refresh-plugin-auth-token').click(function() {
                var button = $(this);
                var originalText = button.text();
                
                if (confirm('Are you sure you want to refresh the Plugin Auth Token? This will generate a new token and update the heartbeat API.')) {
                    // Disable button and show loading
                    button.prop('disabled', true).text('🔄 Refreshing...');
                    
                    $.post(ajaxurl, {
                        action: 'refresh_plugin_auth_token',
                        nonce: '" . wp_create_nonce('refresh_plugin_auth_token') . "'
                    })
                    .done(function(response) {
                        if (response.success && response.data && response.data.new_token) {
                            // Update the field value immediately
                            $('#apikey').val(response.data.new_token);
                            
                            // Visual feedback with green border
                            $('#apikey').css('border', '2px solid #28a745').animate({borderColor: '#ddd'}, 2000);
                            
                            alert('✅ Plugin Auth Token refreshed successfully!\\n\\nNew token: ' + response.data.new_token.substring(0, 8) + '...');
                        } else {
                            alert('❌ Error refreshing token: ' + (response.data ? response.data.message : 'Unknown error'));
                        }
                    })
                    .fail(function() {
                        alert('❌ Network error while refreshing token');
                    })
                    .always(function() {
                        // Re-enable button
                        button.prop('disabled', false).text(originalText);
                    });
                }
            });
        });
        ";
        wp_add_inline_script($this->plugin_name, $inline_script);
        add_action('admin_notices', array($this, 'permalink_structure_dashboard_warning'));
        // Display update warning banner if plugin update is available
        add_action('admin_notices', array($this, 'display_update_warning_banner'));
        // Enqueue wizard assets if on wizard page
        if (isset($_GET['page']) && strpos($_GET['page'], '-setup-wizard') !== false) {
            wp_enqueue_script(
                $this->plugin_name . '-setup-wizard',
                plugin_dir_url(__FILE__) . 'js/metasync-setup-wizard.js',
                array('jquery'),
                $this->version,
                true
            );

            wp_localize_script($this->plugin_name . '-setup-wizard', 'metasyncWizardData', array(
                'nonce' => wp_create_nonce('metasync_wizard'),
                'ssoNonce' => wp_create_nonce('metasync_sso_nonce'),
                'saConnectNonce' => wp_create_nonce('metasync_sa_connect_nonce'),
                'importNonce' => wp_create_nonce('metasync_import_external_data'),
                'dashboardUrl' => admin_url('admin.php?page=' . self::$page_slug . '-dashboard'),
                'currentStep' => 1,
                'totalSteps' => 6,
                'pluginName' => Metasync::get_effective_plugin_name()
            ));
        }

        wp_enqueue_script('heartbeat');
    }

    /**
     * Settings of HeartBeat API for admin area.
     * Set time interval of send request.
     */
    function metasync_heartbeat_settings($settings)
    {
        global $heartbeat_frequency;
        $settings['interval'] = 300;
        return $settings;
    }

    /**
     * Data or Response received from HeartBeat API for admin area.
     */
    function metasync_received_data($response, $data)
    {
        // if ($data['client'] == 'marco')

        $response['server'] = wp_json_encode($data);

        return $response;
    }

    /**
     * Add Import External Data page
     */
    public function add_import_external_data_page()
    {
        # Check if current user has plugin access based on role settings
        if (!$this->current_user_has_plugin_access()) {
            return; // Don't add this page for users without access
        }

        // Use 'read' capability since actual access is controlled by current_user_has_plugin_access() check above
        add_submenu_page(
            null, // Hidden from menu, linked from other pages
            'Import External Data',
            'Import External Data',
            'read',
            self::$page_slug . '-import-external',
            array($this, 'render_import_external_data_page')
        );
    }

    /**
     * Render Import External Data page
     */
    public function render_import_external_data_page()
    {
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-external-importer.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'views/metasync-import-external-data.php';
    }

    /**
     * AJAX handler for external data import
     */
    public function ajax_import_external_data()
    {
        Metasync_Admin_Ajax::instance()->ajax_import_external_data();
    }

    /**
     * AJAX handler for SEO metadata batch import
     * Supports batch processing with progress tracking
     */
    public function ajax_import_seo_metadata()
    {
        Metasync_Admin_Ajax::instance()->ajax_import_seo_metadata();
    }

    /**
     * Additional context validation for Search Atlas connect tokens.
     *
     * Validates site URL and optional IP/user-agent context for connect tokens
     * used during the Search Atlas API key + Otto UUID retrieval flow.
     * This does NOT create WordPress login sessions.
     */
    private function validate_searchatlas_context($token_data)
    {
        return Metasync_Connect_Manager::instance()->validate_searchatlas_context($token_data);
    }

    private function should_validate_ip()
    {
        return Metasync_Connect_Manager::instance()->should_validate_ip();
    }

    private function are_user_agents_incompatible($old_ua, $new_ua)
    {
        return Metasync_Connect_Manager::instance()->are_user_agents_incompatible($old_ua, $new_ua);
    }

    private function extract_browser_name($ua)
    {
        return Metasync_Connect_Manager::instance()->extract_browser_name($ua);
    }

    public function generate_searchatlas_wp_connect_token($regenerate = false)
    {
        return Metasync_Connect_Manager::instance()->generate_searchatlas_wp_connect_token($regenerate);
    }

    private function ensure_plugin_auth_token_exists()
    {
        Metasync_Connect_Manager::instance()->ensure_plugin_auth_token_exists();
    }

    public function generate_searchatlas_connect_url()
    {
        Metasync_Connect_Manager::instance()->generate_searchatlas_connect_url();
    }

    public function check_searchatlas_connect_status()
    {
        Metasync_Connect_Manager::instance()->check_searchatlas_connect_status();
    }

    private function create_searchatlas_nonce_token()
    {
        return Metasync_Connect_Manager::instance()->create_searchatlas_nonce_token();
    }

    private function get_client_ip()
    {
        return Metasync_Connect_Manager::instance()->get_client_ip();
    }

    private function create_encrypted_searchatlas_token($metadata = array())
    {
        return Metasync_Connect_Manager::instance()->create_encrypted_searchatlas_token($metadata);
    }

    private function wp_encrypt_token($payload)
    {
        return Metasync_Connect_Manager::instance()->wp_encrypt_token($payload);
    }

    public function test_enhanced_searchatlas_tokens()
    {
        return Metasync_Connect_Manager::instance()->test_enhanced_searchatlas_tokens();
    }

    public function test_searchatlas_ajax_endpoint()
    {
        Metasync_Connect_Manager::instance()->test_searchatlas_ajax_endpoint();
    }

    public function simple_ajax_test()
    {
        Metasync_Connect_Manager::instance()->simple_ajax_test();
    }

    /**
     * Create Admin Dashboard Iframe Page
     * Embeds the Search Atlas dashboard directly in WordPress admin
     */
    public function create_admin_dashboard_iframe()
    {
        Metasync_Admin_Pages::get_instance($this)->create_admin_dashboard_iframe();
    }

    /**
     * Render OTTO cache management interface
     */
    public function render_otto_cache_management()
    {
        Metasync_Otto_Cache_Manager::instance()->render_otto_cache_management();
    }

    /**
     * Render debug mode section for inclusion in Advanced settings
     */
    public function render_debug_mode_section()
    {
        Metasync_Debug_Manager::instance()->render_debug_mode_section();
    }

    /**
     * Render error log content for inclusion in Advanced settings
     */
    public function render_error_log_content()
    {
        Metasync_Debug_Manager::instance()->render_error_log_content();
    }

    /**
     * WordPress standard handler for clearing all cache plugins (admin_post hook)
     * This method runs early and prevents any output before redirect
     */
    public function handle_clear_all_cache_plugins() {
        Metasync_Otto_Cache_Manager::instance()->handle_clear_all_cache_plugins();
    }

    /**
     * WordPress standard handler for clearing OTTO cache (admin_post hook)
     */
    public function handle_clear_otto_cache_all() {
        Metasync_Otto_Cache_Manager::instance()->handle_clear_otto_cache_all();
    }

    /**
     * WordPress standard handler for clearing OTTO cache by URL (admin_post hook)
     */
    public function handle_clear_otto_cache_url() {
        Metasync_Otto_Cache_Manager::instance()->handle_clear_otto_cache_url();
    }

    /**
     * Get hosting cache integration settings with defaults
     *
     * @return array Settings array with 'wpengine_enabled' and 'kinsta_enabled' keys
     */
    private function get_hosting_cache_settings() {
        $defaults = array(
            'wpengine_enabled' => true,
            'kinsta_enabled'   => true,
        );
        $saved = get_option('metasync_hosting_cache_options', array());
        return wp_parse_args($saved, $defaults);
    }

    /**
     * AJAX handler for saving hosting cache settings
     */
    public function ajax_save_hosting_cache_settings() {
        if (!isset($_POST['hosting_cache_nonce']) || !wp_verify_nonce($_POST['hosting_cache_nonce'], 'metasync_hosting_cache_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }

        $settings = array(
            'wpengine_enabled' => !empty($_POST['wpengine_enabled']) && $_POST['wpengine_enabled'] === '1',
            'kinsta_enabled'   => !empty($_POST['kinsta_enabled']) && $_POST['kinsta_enabled'] === '1',
        );

        update_option('metasync_hosting_cache_options', $settings);
        wp_send_json_success(array('message' => 'Hosting cache settings saved'));
    }

    /**
     * admin_post handler: purge WP Engine and Kinsta hosting-level caches
     */
    public function handle_purge_hosting_cache() {
        if (!isset($_POST['hosting_cache_purge_nonce']) || !wp_verify_nonce($_POST['hosting_cache_purge_nonce'], 'metasync_hosting_cache_purge_nonce')) {
            wp_die('Security check failed');
        }

        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('You do not have permission to perform this action');
        }

        $redirect_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced');
        $settings     = $this->get_hosting_cache_settings();
        $cleared      = array();
        $failed       = array();
        $not_detected = array();

        // WP Engine native purge (Varnish + Memcached)
        if (!empty($settings['wpengine_enabled'])) {
            if (class_exists('WpeCommon')) {
                try {
                    WpeCommon::purge_varnish_cache();
                    WpeCommon::purge_memcached();
                    $cleared[] = 'WP Engine';
                } catch (Exception $e) {
                    error_log('MetaSync: WP Engine hosting cache purge failed - ' . $e->getMessage());
                    $failed[] = 'WP Engine';
                }
            } else {
                $not_detected[] = 'WP Engine';
            }
        }

        // Kinsta native purge (full site)
        if (!empty($settings['kinsta_enabled'])) {
            if (class_exists('KinstaCache')) {
                try {
                    KinstaCache::get_instance()->kinsta_cache_purge_full();
                    $cleared[] = 'Kinsta';
                } catch (Exception $e) {
                    error_log('MetaSync: Kinsta hosting cache purge failed - ' . $e->getMessage());
                    $failed[] = 'Kinsta';
                }
            } else {
                $not_detected[] = 'Kinsta';
            }
        }

        $redirect_url .= '&hosting_cache_cleared=1';
        if (!empty($cleared)) {
            $redirect_url .= '&hc_cleared=' . urlencode(implode(',', $cleared));
        }
        if (!empty($failed)) {
            $redirect_url .= '&hc_failed=' . urlencode(implode(',', $failed));
        }
        if (!empty($not_detected)) {
            $redirect_url .= '&hc_not_detected=' . urlencode(implode(',', $not_detected));
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Handle debug mode operations (enable/disable/extend)
     */
    private function handle_debug_mode_operations()
    {
        Metasync_Debug_Manager::instance()->handle_debug_mode_operations();
    }

    /**
     * Handle error log operations (clear)
     */
    private function handle_error_log_operations()
    {
        Metasync_Debug_Manager::instance()->handle_error_log_operations();
    }

    /**
     * Handle clear all settings operations
     */
    private function handle_clear_all_settings()
    {
        Metasync_Debug_Manager::instance()->handle_clear_all_settings();
    }

    /**
     * Handle saving plugin access roles from Advanced Settings
     * @deprecated Now handled by Metasync_Settings_Registration::settings_page_init()
     */
    private function handle_plugin_access_roles_save() {
    }

    /**
     * Get error log content for display
     */
    private function get_error_log_content()
    {
        return Metasync_Debug_Manager::instance()->get_error_log_content();
    }
    
    /**
     * Memory-efficient function to get last N lines from a large file
     */
    private function get_log_tail($file_path, $lines = null)
    {
        return Metasync_Debug_Manager::instance()->get_log_tail($file_path, $lines);
    }

    /**
     * Test whitelabel domain functionality (development/debugging)
     */
    public function test_whitelabel_domain()
    {
        Metasync_Connect_Manager::instance()->test_whitelabel_domain();
    }

    /**
     * Decrypt token using WordPress SALTs
     */
    private function wp_decrypt_token($encrypted_token)
    {
        return Metasync_Connect_Manager::instance()->wp_decrypt_token($encrypted_token);
    }

    /**
     * Get active JWT token for the plugin
     * Public static method accessible from anywhere in the plugin
     * 
     * @param bool $force_refresh Force generation of new token even if cached one exists
     * @return string|false JWT token on success, false on failure
     */
    public static function get_active_jwt_token($force_refresh = false)
    {
        return Metasync_Connect_Manager::instance()->get_active_jwt_token($force_refresh);
    }


    /**
     * Get fresh JWT token from Search Atlas API with caching
     * Generates and caches JWT tokens to avoid repeated API calls
     * 
     * @return string|false JWT token on success, false on failure
     */
    public function get_fresh_jwt_token()
    {
        return Metasync_Connect_Manager::instance()->get_fresh_jwt_token();
    }

    /**
     * Clear cached JWT tokens
     * Useful when authentication is reset or API key changes
     */
    private function clear_jwt_token_cache()
    {
        Metasync_Connect_Manager::instance()->clear_jwt_token_cache();
    }



    /**
     * Data or Response received from HeartBeat API for admin area.
     */
    public function lgSendCustomerParams()
    {
        Metasync_Admin_Ajax::instance()->lgSendCustomerParams();
    }



    /**
     * Add CSS styles for Search Atlas admin bar status indicator
     */
    public function metasync_admin_bar_style()
    {
        Metasync_Admin_Navigation::instance()->metasync_admin_bar_style();
    }

    /**
     * Add Search Atlas status indicator to WordPress admin bar
     * Shows sync status with green/red emoji
     */
    public function add_searchatlas_admin_bar_status($wp_admin_bar)
    {
        Metasync_Admin_Navigation::instance()->add_searchatlas_admin_bar_status($wp_admin_bar);
    }

    // ------------------------------------------------------------------
    //  Heartbeat / connection-monitoring – delegated to Metasync_Heartbeat_Manager
    // ------------------------------------------------------------------

    public function is_heartbeat_connected($general_settings = null)
    {
        return Metasync_Heartbeat_Manager::instance()->is_heartbeat_connected($general_settings);
    }

    public function fetch_public_hash($otto_pixel_uuid, $jwt_token)
    {
        return Metasync_Heartbeat_Manager::instance()->fetch_public_hash($otto_pixel_uuid, $jwt_token);
    }

    public function schedule_heartbeat_cron()
    {
        Metasync_Heartbeat_Manager::instance()->schedule_heartbeat_cron();
    }

    public function unschedule_heartbeat_cron()
    {
        Metasync_Heartbeat_Manager::instance()->unschedule_heartbeat_cron();
    }

    public function execute_heartbeat_cron_check()
    {
        return Metasync_Heartbeat_Manager::instance()->execute_heartbeat_cron_check();
    }

    public function add_heartbeat_cron_schedule($schedules)
    {
        return Metasync_Heartbeat_Manager::instance()->add_heartbeat_cron_schedule($schedules);
    }

    public function get_heartbeat_state()
    {
        return Metasync_Heartbeat_Manager::instance()->get_heartbeat_state();
    }

    public function set_heartbeat_state_key_pending()
    {
        Metasync_Heartbeat_Manager::instance()->set_heartbeat_state_key_pending();
    }

    public function execute_burst_heartbeat()
    {
        Metasync_Heartbeat_Manager::instance()->execute_burst_heartbeat();
    }

    public function execute_announce_cron()
    {
        Metasync_Heartbeat_Manager::instance()->execute_announce_cron();
    }

    public function unschedule_burst_heartbeat_cron()
    {
        Metasync_Heartbeat_Manager::instance()->unschedule_burst_heartbeat_cron();
    }

    public function unschedule_announce_cron()
    {
        Metasync_Heartbeat_Manager::instance()->unschedule_announce_cron();
    }

    public function maybe_schedule_heartbeat_cron()
    {
        Metasync_Heartbeat_Manager::instance()->maybe_schedule_heartbeat_cron();
    }


    public function trigger_immediate_heartbeat_check($context = 'Manual trigger')
    {
        return Metasync_Heartbeat_Manager::instance()->trigger_immediate_heartbeat_check($context);
    }

    public function handle_immediate_heartbeat_trigger($context = 'WordPress action trigger')
    {
        Metasync_Heartbeat_Manager::instance()->handle_immediate_heartbeat_trigger($context);
    }

    public function ajax_burst_ping()
    {
        Metasync_Heartbeat_Manager::instance()->ajax_burst_ping();
    }

    public function update_heartbeat_cache_after_sync($is_connected, $context = 'Sync operation')
    {
        return Metasync_Heartbeat_Manager::instance()->update_heartbeat_cache_after_sync($is_connected, $context);
    }


    /**
     * Refresh Plugin Auth Token
     * Generates a new Plugin Auth Token and updates heartbeat API
     */
    public function refresh_plugin_auth_token()
    {
        Metasync_Connect_Manager::instance()->refresh_plugin_auth_token();
    }

    public function get_plugin_auth_token()
    {
        Metasync_Connect_Manager::instance()->get_plugin_auth_token();
    }

    public function reset_searchatlas_authentication()
    {
        Metasync_Connect_Manager::instance()->reset_searchatlas_authentication();
    }

    private function cleanup_searchatlas_nonce_tokens()
    {
        return Metasync_Connect_Manager::instance()->cleanup_searchatlas_nonce_tokens();
    }

    private function cleanup_searchatlas_rate_limits()
    {
        return Metasync_Connect_Manager::instance()->cleanup_searchatlas_rate_limits();
    }

    private function get_available_menu_items()
    {
        return Metasync_Admin_Navigation::instance()->get_available_menu_items();
    }

    /**
     * Add options page
     */
    public function add_plugin_settings_page()
    {
        Metasync_Admin_Navigation::instance()->add_plugin_settings_page($this);
    }

    /**
     * General Options page callback
     */
    public function create_admin_settings_page()
    {
        Metasync_Admin_Pages::get_instance($this)->create_admin_settings_page();
    }

    public function render_navigation_menu($current_page = null)
    {
        Metasync_Admin_Navigation::instance()->render_navigation_menu($current_page);
    }

    public function render_plugin_header($page_title = null)
    {
        Metasync_Admin_Navigation::instance()->render_plugin_header($page_title);
    }

    /*
        Method to handle Ajax request from "General Settings" page
    */
    public function meta_sync_save_settings() {
        Metasync_Settings_Registration::instance()->meta_sync_save_settings();
    }

    /**
     * AJAX handler for saving execution settings
     */
    public function ajax_save_execution_settings() {
        Metasync_Settings_Registration::instance()->ajax_save_execution_settings();
    }

    /**
     * Get Action Scheduler concurrent batches from execution settings
     * 
     * @param int $default_batches Default concurrent batches
     * @return int Configured concurrent batches
     */
    public function get_action_scheduler_batches($default_batches) {
        // Only apply if Action Scheduler is active
        if (!class_exists('ActionScheduler')) {
            return $default_batches;
        }
        
        return $this->get_execution_setting('action_scheduler_batches');
    }

    /**
     * Get Action Scheduler retention period from execution settings
     * Converts days to seconds for Action Scheduler
     * 
     * @param int $default_seconds Default retention period in seconds
     * @return int Configured retention period in seconds
     */
    public function get_action_scheduler_retention_period($default_seconds) {
        // Only apply if Action Scheduler is active
        if (!class_exists('ActionScheduler')) {
            return $default_seconds; // Default is 30 days = 2592000 seconds
        }
        
        $cleanup_days = $this->get_execution_setting('queue_cleanup_days');
        return $cleanup_days * DAY_IN_SECONDS;
    }

    /*
     * Sync setting on CRUD term category
     */

    public function admin_crud_term($term_id,$term_tax_id,$taxonomy)
    {
        # Handle term creation, update, or deletion
        $this->sync_term($term_id, $taxonomy);

    }


    /*
     * Sync setting on Delete term category
     */

    public function admin_delete_term($term_id,$taxonomy)
    {
        # Handle term deletion
        $this->sync_term($term_id, $taxonomy);
    }

     /*
     * Handle category deletion - Sync after category is deleted
     * This hook fires AFTER the category is fully deleted from the database
     */
    public function admin_delete_category($term_id)
    {
        try {
            # Initialize MetaSync API request class and trigger synchronization
            (new Metasync_Sync_Requests())->SyncCustomerParams();
        } catch (Exception $e) {
            # Log any API request errors for debugging
            error_log('Metasync API Error: ' . $e->getMessage());
        }
    }
    
    /*
     * Call the SYNC API
     */
    private function sync_term($term_id, $taxonomy)
    {
        # Ensure the term belongs to the 'category' taxonomy and is not an error
        if ($taxonomy !== 'category' ) return;
    
        try {
            # Initialize MetaSync API request class and trigger synchronization
            (new Metasync_Sync_Requests())->SyncCustomerParams();
        } catch (Exception $e) {
            # Log any API request errors for debugging
            error_log('Metasync API Error: ' . $e->getMessage());
        }
    }

    /**
     * Dashboard page callback
     */
    public function create_admin_dashboard_page()
    {
        Metasync_Admin_Pages::get_instance($this)->create_admin_dashboard_page();
    }

    /**
     * Robots.txt page callback
     */
    public function create_admin_robots_txt_page()
    {
        Metasync_Admin_Pages::get_instance($this)->create_admin_robots_txt_page();
    }

    /**
     * Media Optimization page callback
     */
    public function create_admin_media_optimization_page()
    {
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">

        <?php $this->render_plugin_header('Media Optimization'); ?>

        <?php $this->render_navigation_menu('media_optimization'); ?>

        <?php
        // Load media optimization settings class
        require_once plugin_dir_path(dirname(__FILE__)) . 'media-optimization/class-media-settings.php';

        $save_success = false;

        // Handle form submissions
        if (isset($_POST['metasync_media_optimization_nonce'])) {
            check_admin_referer('metasync_save_media_optimization', 'metasync_media_optimization_nonce');

            // Handle reset to defaults
            if (!empty($_POST['metasync_media_reset'])) {
                $defaults = Metasync_Media_Settings::get_defaults();
                Metasync_Media_Settings::save_settings($defaults);
                $save_success = true;
            } elseif (isset($_POST['metasync_media'])) {
                $input = wp_unslash($_POST['metasync_media']);
                Metasync_Media_Settings::save_settings($input);
                $save_success = true;
            }
        }

        // Tab handling
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';

        // Prepare image library data
        $list_table     = null;
        $stats          = null;
        $batch_progress = null;

        require_once plugin_dir_path(dirname(__FILE__)) . 'media-optimization/class-media-library-list-table.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'media-optimization/class-media-batch-optimizer.php';

        $list_table = new Metasync_Media_Library_List_Table();
        $list_table->prepare_items();

        $stats          = Metasync_Media_Library_List_Table::get_stats();
        $batch_progress = Metasync_Media_Batch_Optimizer::get_progress();

        // Render the admin page view
        require_once plugin_dir_path(dirname(__FILE__)) . 'media-optimization/views/admin-page.php';
        ?>
        </div>
    <?php
    }

    // ── Media Optimization AJAX Handlers ──

    /**
     * AJAX: Optimize a single image.
     */
    public function ajax_optimize_single_image()
    {
        check_ajax_referer('metasync_media_opt_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(__('Permission denied.', 'metasync'));
        }

        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        if (!$attachment_id) {
            wp_send_json_error(__('Invalid attachment ID.', 'metasync'));
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'media-optimization/class-media-settings.php';
        $settings = Metasync_Media_Settings::get_settings();

        $success = Metasync_Image_Converter::convert_attachment($attachment_id, $settings);

        if ($success) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'media-optimization/class-media-library-list-table.php';
            wp_send_json_success([
                'status_html' => Metasync_Media_Library_List_Table::render_status_html($attachment_id),
            ]);
        }

        wp_send_json_error(__('Optimization failed.', 'metasync'));
    }

    /**
     * AJAX: Revert a single image.
     */
    public function ajax_revert_single_image()
    {
        check_ajax_referer('metasync_media_opt_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(__('Permission denied.', 'metasync'));
        }

        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        if (!$attachment_id) {
            wp_send_json_error(__('Invalid attachment ID.', 'metasync'));
        }

        $success = Metasync_Image_Converter::revert_attachment($attachment_id);

        if ($success) {
            wp_send_json_success();
        }

        wp_send_json_error(__('Revert failed. Original file may not exist (replace strategy).', 'metasync'));
    }

    /**
     * AJAX: Start batch optimization.
     */
    public function ajax_start_batch_optimize()
    {
        check_ajax_referer('metasync_media_opt_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'metasync'));
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'media-optimization/class-media-settings.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'media-optimization/class-media-batch-optimizer.php';

        $settings = Metasync_Media_Settings::get_settings();
        $progress = Metasync_Media_Batch_Optimizer::start_batch($settings);

        wp_send_json_success($progress);
    }

    /**
     * AJAX: Cancel batch optimization.
     */
    public function ajax_cancel_batch_optimize()
    {
        check_ajax_referer('metasync_media_opt_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'metasync'));
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'media-optimization/class-media-batch-optimizer.php';
        Metasync_Media_Batch_Optimizer::cancel_batch();

        wp_send_json_success();
    }

    /**
     * AJAX: Get batch progress + stats.
     */
    public function ajax_batch_progress()
    {
        check_ajax_referer('metasync_media_opt_nonce', 'nonce');

        require_once plugin_dir_path(dirname(__FILE__)) . 'media-optimization/class-media-batch-optimizer.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'media-optimization/class-media-library-list-table.php';

        $progress = Metasync_Media_Batch_Optimizer::get_progress();
        $progress['stats'] = Metasync_Media_Library_List_Table::get_stats();

        wp_send_json_success($progress);
    }

    /**
     * AJAX: Bulk optimize selected images.
     */
    public function ajax_bulk_optimize_selected()
    {
        check_ajax_referer('metasync_media_opt_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(__('Permission denied.', 'metasync'));
        }

        $ids = isset($_POST['ids']) ? array_map('absint', explode(',', sanitize_text_field($_POST['ids']))) : [];
        $ids = array_filter($ids);

        if (empty($ids)) {
            wp_send_json_error(__('No images selected.', 'metasync'));
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'media-optimization/class-media-settings.php';
        $settings = Metasync_Media_Settings::get_settings();

        $success = 0;
        $failed  = 0;

        foreach ($ids as $id) {
            if (Metasync_Image_Converter::convert_attachment($id, $settings)) {
                $success++;
            } else {
                $failed++;
            }
        }

        wp_send_json_success([
            'success' => $success,
            'failed'  => $failed,
        ]);
    }

    /**
     * AJAX: Process one batch tick (browser-driven chaining).
     */
    public function ajax_process_batch_tick()
    {
        check_ajax_referer('metasync_media_opt_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'metasync'));
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'media-optimization/class-media-batch-optimizer.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'media-optimization/class-media-library-list-table.php';

        $progress = Metasync_Media_Batch_Optimizer::process_ajax_tick();
        $progress['stats'] = Metasync_Media_Library_List_Table::get_stats();

        wp_send_json_success($progress);
    }

    /**
     * Cron handler: Process batch optimization tick.
     */
    public function handle_media_batch_cron()
    {
        require_once plugin_dir_path(dirname(__FILE__)) . 'media-optimization/class-media-settings.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'media-optimization/class-media-batch-optimizer.php';

        Metasync_Media_Batch_Optimizer::process_batch_tick();
    }

    /**
     * Report Issue page callback
     */
    public function create_admin_report_issue_page()
    {
        // Load the Report Issue view
        require_once plugin_dir_path(dirname(__FILE__)) . 'views/metasync-report-issue.php';
    }

    /**
     * XML Sitemap page callback
     */
    public function create_admin_xml_sitemap_page()
    {
        // Load sitemap generator class
        require_once plugin_dir_path(dirname(__FILE__)) . 'sitemap/class-metasync-sitemap-generator.php';

        $sitemap_generator = new Metasync_Sitemap_Generator();

        // Handle form submissions
        if (isset($_POST['metasync_sitemap_nonce'])) {
            check_admin_referer('metasync_sitemap_action', 'metasync_sitemap_nonce');

            if (isset($_POST['generate_sitemap'])) {
                // Auto-disable other sitemap generators before generating
                $disabled_plugins = $sitemap_generator->disable_other_sitemap_generators();

                $result = $sitemap_generator->generate_sitemap();

                if (is_wp_error($result)) {
                    echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
                } else {
                    $message = esc_html__('Sitemap generated successfully!', 'metasync');
                    if ($disabled_plugins) {
                        $message .= ' ' . esc_html__('Conflicting sitemap generators have been automatically disabled.', 'metasync');
                    }

                    // Check if robots.txt was updated
                    $robots_result = get_transient('metasync_sitemap_robots_updated');
                    if ($robots_result && $robots_result['success']) {
                        if ($robots_result['action'] === 'added') {
                            $message .= ' ' . esc_html__('Sitemap URL has been added to robots.txt.', 'metasync');
                        } elseif ($robots_result['action'] === 'updated') {
                            $message .= ' ' . esc_html__('Sitemap URL has been updated in robots.txt.', 'metasync');
                        } elseif ($robots_result['action'] === 'created') {
                            $message .= ' ' . esc_html__('robots.txt file has been created with sitemap URL.', 'metasync');
                        }
                        delete_transient('metasync_sitemap_robots_updated');
                    }

                    echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
                }
            } elseif (isset($_POST['enable_auto_update'])) {
                update_option('metasync_sitemap_auto_update', true);
                $sitemap_generator->setup_auto_update_hooks();
                echo '<div class="notice notice-success"><p>' . esc_html__('Auto-update enabled!', 'metasync') . '</p></div>';
            } elseif (isset($_POST['disable_auto_update'])) {
                update_option('metasync_sitemap_auto_update', false);
                echo '<div class="notice notice-success"><p>' . esc_html__('Auto-update disabled!', 'metasync') . '</p></div>';
            } elseif (isset($_POST['delete_sitemap'])) {
                // Delete the sitemap file (physical and/or virtual)
                $deleted = $sitemap_generator->delete_sitemap();
                if ($deleted) {
                    // Also disable auto-update when deleting
                    update_option('metasync_sitemap_auto_update', false);
                    echo '<div class="notice notice-success"><p>' . esc_html__('Sitemap deleted successfully!', 'metasync') . '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Failed to delete sitemap. The file may not exist or is not writable.', 'metasync') . '</p></div>';
                }
            } elseif (isset($_POST['enable_other_sitemaps'])) {
                // Re-enable other sitemap plugins
                $enabled_plugins = $sitemap_generator->enable_other_sitemap_generators();
                if ($enabled_plugins) {
                    echo '<div class="notice notice-success"><p>' . esc_html__('Other sitemap plugins have been re-enabled successfully!', 'metasync') . '</p></div>';
                } else {
                    echo '<div class="notice notice-info"><p>' . esc_html__('No sitemap plugins were found to re-enable.', 'metasync') . '</p></div>';
                }
            }
        }

        // Get sitemap info
        $sitemap_exists = $sitemap_generator->sitemap_exists();
        $sitemap_url = $sitemap_generator->get_sitemap_url();
        $url_count = $sitemap_generator->count_urls();
        $last_generated = $sitemap_generator->get_last_generated_time();
        $auto_update_enabled = get_option('metasync_sitemap_auto_update', false);
        $active_sitemap_plugins = $sitemap_generator->check_active_sitemap_plugins();

        // Load view
        require_once plugin_dir_path(dirname(__FILE__)) . 'views/metasync-xml-sitemap.php';
    }

    /**
     * Custom Pages page callback
     */
    public function create_admin_custom_pages_page()
    {
        Metasync_Admin_Pages::get_instance($this)->create_admin_custom_pages_page();
    }

    /**
     * 404 Monitor page callback
     */
    public function create_admin_404_monitor_page()
    {
        Metasync_Admin_Pages::get_instance($this)->create_admin_404_monitor_page();
    }

    /**
     * Site Verification page callback
     */
    public function create_admin_search_engine_verification_page()
    {
        Metasync_Admin_Pages::get_instance($this)->create_admin_search_engine_verification_page();
    }

    /**
     * Local Business page callback
     */
    public function create_admin_local_business_page()
    {
        Metasync_Admin_Pages::get_instance($this)->create_admin_local_business_page();
    }

    /**
     * Code Snippets page callback
     */
    public function create_admin_code_snippets_page()
    {
        Metasync_Admin_Pages::get_instance($this)->create_admin_code_snippets_page();
    }

    /**
     * Google Instant Index Setting page callback
     */
    public function create_admin_google_instant_index_page()
    {
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">

        <?php $this->render_plugin_header('Instant Indexing'); ?>

        <?php $this->render_navigation_menu('instant_index'); ?>

        <?php
        // Set up variables for the view template
        $options = get_option('metasync_options_instant_indexing', ['json_key' => '', 'post_types' => []]);
        $json_key = isset($options['json_key']) ? $options['json_key'] : '';
        $google_guide_url = 'https://developers.google.com/search/apis/indexing-api/v3/quickstart';
        $post_types_settings = isset($options['post_types']) && is_array($options['post_types']) ? $options['post_types'] : [];
        include_once plugin_dir_path(dirname(__FILE__)) . 'views/metasync-google-instant-settings.php';
        ?>
        </div>
        <?php
    }

    /**
     * Google Console page callback
     */
    public function create_admin_google_console_page()
    {
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">

        <?php $this->render_plugin_header('Google Console'); ?>

        <?php $this->render_navigation_menu('google_console'); ?>

        <?php
        // Check if Google service account is configured via google_index_direct()
        $service_info = function_exists('google_index_direct') ? google_index_direct()->get_service_account_info() : ['error' => 'Module not loaded'];
        $is_configured = !isset($service_info['error']);
        include_once plugin_dir_path(dirname(__FILE__)) . 'views/metasync-google-console.php';
        ?>
        </div>
        <?php
    }

    /**
     * Bing Console page callback
     */
    public function create_admin_bing_console_page()
    {
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">

        <?php $this->render_plugin_header('Bing Console'); ?>

        <?php $this->render_navigation_menu('bing_console'); ?>

        <?php
        require_once plugin_dir_path(dirname(__FILE__)) . 'bing-index/class-metasync-bing-instant-index.php';
        $bing_instant_index = new Metasync_Bing_Instant_Index();
        $bing_instant_index->show_bing_instant_indexing_console();
        ?>
        </div>
        <?php
    }

    /**
     * General Options page callback
     */
    public function create_admin_optimal_settings_page()
    {
        Metasync_Admin_Pages::get_instance($this)->create_admin_optimal_settings_page();
    }

    /**
     * Global Options page callback
     */
    public function create_admin_global_settings_page()
    {
        Metasync_Admin_Pages::get_instance($this)->create_admin_global_settings_page();
    }

    /**
     * Common Meta Options page callback
     */
    public function create_admin_common_meta_settings_page()
    {
        Metasync_Admin_Pages::get_instance($this)->create_admin_common_meta_settings_page();
    }

    /**
     * Social meta page callback
     */
    public function create_admin_social_meta_page()
    {
        Metasync_Admin_Pages::get_instance($this)->create_admin_social_meta_page();
    }


    /**
     * Indexation Control page callback
     */
    public function create_admin_seo_controls_page()
    {
        Metasync_Admin_Pages::get_instance($this)->create_admin_seo_controls_page();
    }

    /**
     * Site Optimal Settings page callback
     */
    public function optimization_settings_options()
    {
        Metasync_Admin_Pages::get_instance($this)->optimization_settings_options();
    }

    /**
     * redirection page callback with tabs
     */
    public function create_admin_redirections_page()
    {
        Metasync_Redirections_Admin::get_instance($this->db_redirection, $this)->create_admin_redirections_page();
    }

    /**
     * Display transient error/success messages for redirections
     */
    public function display_redirection_messages()
    {
        Metasync_Redirections_Admin::get_instance($this->db_redirection, $this)->display_redirection_messages();
    }

    /**
     * AJAX handler for updating database structure
     */
    public function ajax_update_db_structure()
    {
        Metasync_Admin_Ajax::instance()->ajax_update_db_structure();
    }

    /**
     * AJAX handler to save wizard progress
     *
     * @since    1.0.0
     */
    public function ajax_save_wizard_progress()
    {
        Metasync_Admin_Ajax::instance()->ajax_save_wizard_progress();
    }

    /**
     * AJAX handler to complete wizard
     *
     * @since    1.0.0
     */
    public function ajax_complete_wizard()
    {
        Metasync_Admin_Ajax::instance()->ajax_complete_wizard();
    }

    /**
     * AJAX handler to validate robots.txt content
     */
    public function ajax_validate_robots()
    {
        Metasync_Admin_Ajax::instance()->ajax_validate_robots();
    }

    /**
     * AJAX handler to get default robots.txt content
     */
    public function ajax_get_default_robots()
    {
        Metasync_Admin_Ajax::instance()->ajax_get_default_robots();
    }

    /**
     * AJAX handler to preview robots.txt backup content
     */
    public function ajax_preview_robots_backup()
    {
        Metasync_Admin_Ajax::instance()->ajax_preview_robots_backup();
    }

    /**
     * AJAX handler to delete robots.txt backup
     */
    public function ajax_delete_robots_backup()
    {
        Metasync_Admin_Ajax::instance()->ajax_delete_robots_backup();
    }

    /**
     * AJAX handler to restore robots.txt backup
     */
    public function ajax_restore_robots_backup()
    {
        Metasync_Admin_Ajax::instance()->ajax_restore_robots_backup();
    }
    public function ajax_create_redirect_from_404()
    {
        Metasync_Admin_Ajax::instance()->ajax_create_redirect_from_404();
    }

    /**
     * AJAX handler for testing host blocking with GET request
     */
    public function ajax_test_host_blocking_get()
    {
        Metasync_Admin_Ajax::instance()->ajax_test_host_blocking_get();
    }

    /**
     * AJAX handler for testing host blocking with POST request
     */
    public function ajax_test_host_blocking_post()
    {
        Metasync_Admin_Ajax::instance()->ajax_test_host_blocking_post();
    }

    /**
     * Register REST API endpoint for ping
     */
    public function register_ping_rest_endpoint()
    {
        register_rest_route('metasync/v1', '/ping', array(
            'methods' => array('GET', 'POST'),
            'callback' => array($this, 'handle_ping_rest_endpoint'),
            'permission_callback' => '__return_true', // Allow public access
            'args' => array(
                'test' => array(
                    'description' => 'Optional test parameter',
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
    }

    /**
     * Handle REST API ping endpoint
     */
    public function handle_ping_rest_endpoint($request)
    {
        // Get request method
        $method = $request->get_method();
        
        // Prepare response data
        $response_data = array(
            'response' => 'pong',
            'method' => $method,
            'timestamp' => current_time('mysql'),
            'site_url' => home_url(),
            'plugin_version' => METASYNC_VERSION
        );
        
        // Add request data for POST requests
        if ($method === 'POST') {
            $body = $request->get_body();
            if (!empty($body)) {
                $response_data['received_data'] = json_decode($body, true);
            }
            
            // Add any query parameters
            $params = $request->get_params();
            if (!empty($params)) {
                $response_data['query_params'] = $params;
            }
        }
        
        // Add test parameter if provided
        $test_param = $request->get_param('test');
        if (!empty($test_param)) {
            $response_data['test_param'] = $test_param;
        }
        
        return new WP_REST_Response($response_data, 200);
    }

    /**
     * Site error logs page callback
     */

    public function create_admin_error_logs_page()
    {
        Metasync_Admin_Pages::get_instance($this)->create_admin_error_logs_page();
    }

    /**
     * Compatibility page callback
     */
    public function create_admin_compatibility_page()
    {
        Metasync_Compatibility_Checker::instance()->create_admin_compatibility_page($this);
    }

    /**
     * Sync Log page callback
     */
    public function create_admin_sync_log_page()
    {
        Metasync_Admin_Pages::get_instance($this)->create_admin_sync_log_page();
    }
    /**
     * Build filter query string for pagination
     */
    private function build_filter_query_string($filters)
    {
        $query_parts = [];
        foreach ($filters as $key => $value) {
            if (!empty($value)) {
                $query_parts[] = $key . '=' . urlencode($value);
            }
        }
        return !empty($query_parts) ? '&' . implode('&', $query_parts) : '';
    }

    /**
     * Handle AJAX requests for sync log data
     */
    private function handle_sync_log_ajax()
    {
        // This can be used for future AJAX functionality like real-time updates
        wp_die();
    }

    /**
     * Render compatibility sections
     */
    private function render_compatibility_sections()
    {
        Metasync_Compatibility_Checker::instance()->render_compatibility_sections();
    }

    /**
     * Render Page Builders section
     */
    private function render_page_builders_section()
    {
        Metasync_Compatibility_Checker::instance()->render_page_builders_section();
    }

    /**
     * Render SEO Plugins section
     */
    private function render_seo_plugins_section()
    {
        Metasync_Compatibility_Checker::instance()->render_seo_plugins_section();
    }

    /**
     * Render Cache Plugins section
     */
    private function render_cache_plugins_section()
    {
        Metasync_Compatibility_Checker::instance()->render_cache_plugins_section();
    }

    /**
     * Render Lock Section button for protected tabs
     *
     * @param string $tab The tab identifier (general, whitelabel, advanced)
     */
    private function render_lock_button($tab)
    {
        Metasync_Compatibility_Checker::instance()->render_lock_button($tab);
    }

    /**
     * Get Page Builders compatibility information
     */
    private function get_page_builders_compatibility()
    {
        return Metasync_Compatibility_Checker::instance()->get_page_builders_compatibility();
    }

    /**
     * Get SEO Plugins compatibility information
     */
    private function get_seo_plugins_compatibility()
    {
        return Metasync_Compatibility_Checker::instance()->get_seo_plugins_compatibility();
    }

    /**
     * Get Cache Plugins compatibility information
     */
    private function get_cache_plugins_compatibility()
    {
        return Metasync_Compatibility_Checker::instance()->get_cache_plugins_compatibility();
    }

    /**
     * Check if a plugin is installed and active
     * @deprecated Use get_plugin_status() instead
     */
    private function is_plugin_installed($plugin_file)
    {
        return Metasync_Compatibility_Checker::instance()->is_plugin_installed($plugin_file);
    }

    /**
     * Get detailed plugin status (installed and/or active)
     * Checks multiple plugin file paths (e.g., free and premium versions)
     *
     * @param array $plugin_files Array of plugin file paths to check (e.g., ['free/plugin.php', 'pro/plugin.php'])
     * @param bool $is_core Whether this is a WordPress core feature (always installed/active)
     * @param string $theme_name Optional theme name to check if it's a theme instead of plugin
     * @return array ['is_installed' => bool, 'is_active' => bool, 'active_version' => string|null]
     */
    private function get_plugin_status($plugin_files, $is_core = false, $theme_name = null)
    {
        return Metasync_Compatibility_Checker::instance()->get_plugin_status($plugin_files, $is_core, $theme_name);
    }


    /**
     * Get plugin logo URL (optimized for performance)
     */
    private function get_plugin_logo($plugin_key, $type)
    {
        return Metasync_Compatibility_Checker::instance()->get_plugin_logo($plugin_key, $type);
    }


    public function creat_error_Logs_List()
    {
        Metasync_Admin_Pages::get_instance($this)->creat_error_Logs_List();
    }

    /**
     * Site error logs page callback
     */
    public function create_admin_heartbeat_error_logs_page()
    {
        Metasync_Admin_Pages::get_instance($this)->create_admin_heartbeat_error_logs_page();
    }


    /**
     * Handle session management early for whitelabel functionality
     */
    private function handle_session_management_early()
    {
        Metasync_Connect_Manager::instance()->handle_session_management_early();
    }

    /**
     * @deprecated 2.5.12 Use Metasync_Auth_Manager instead of sessions for authentication
     */
    private function safe_session_start() {
        // This method is deprecated and no longer used
        // Authentication now uses Metasync_Auth_Manager with WordPress transients and user meta
        _deprecated_function(__METHOD__, '2.5.12', 'Metasync_Auth_Manager');
        return Metasync_Session_Helper::safe_start();
    }

    private function handle_whitelabel_session_logic()
    {
        Metasync_Connect_Manager::instance()->handle_whitelabel_session_logic();
    }

    private function handle_whitelabel_password_early()
    {
        Metasync_Connect_Manager::instance()->handle_whitelabel_password_early();
    }

    /**
     * Get accordion sections configuration for General Settings
     *
     * @return array Accordion sections with field IDs, icons, and descriptions
     */
    private function get_accordion_sections_config() {
        return Metasync_Settings_Fields::instance()->get_accordion_sections_config();
    }

    /**
     * Get accordion sections configuration for Advanced Settings Tab
     *
     * @return array Accordion sections configuration
     */
    private function get_advanced_accordion_config() {
        return Metasync_Settings_Fields::instance()->get_advanced_accordion_config();
    }

    /**
     * Render accordion sections for Advanced Settings Tab
     */
    public function render_advanced_accordion() {
        Metasync_Settings_Fields::instance()->render_advanced_accordion();
    }

    /**
     * Render reset settings section for Advanced tab accordion
     */
    private function render_reset_settings_section() {
        Metasync_Settings_Fields::instance()->render_reset_settings_section();
    }

    /**
     * Render Google Index API section for Indexation Control page
     */
    public function render_google_index_section() {
        Metasync_Settings_Fields::instance()->render_google_index_section();
    }

    /**
     * Render Bing Index (IndexNow) section
     *
     * @since 2.6.0
     * @return void
     */
    public function render_bing_index_section() {
        Metasync_Settings_Fields::instance()->render_bing_index_section();
    }

    /**
     * Render Plugin Access Roles section for Advanced tab accordion
     */
    private function render_plugin_access_roles_section() {
        Metasync_Settings_Fields::instance()->render_plugin_access_roles_section();
    }

    /**
     * Check if the current user has access to the plugin based on role settings
     * Wrapper method that delegates to the common Metasync::current_user_has_plugin_access()
     * but also requires manage_options capability for admin area access
     * 
     * @return bool True if user has access, false otherwise
     */
    public function current_user_has_plugin_access() {
        return Metasync::current_user_has_plugin_access();
    }

    /**
     * Get default execution settings
     *
     * @return array Default execution settings
     */
    private function get_default_execution_settings() {
        return Metasync_Settings_Fields::instance()->get_default_execution_settings();
    }

    /**
     * Get execution setting value
     *
     * @param string $key Setting key
     * @param mixed $default Default value if setting doesn't exist
     * @return mixed Setting value or default
     */
    public function get_execution_setting($key, $default = null) {
        return Metasync_Settings_Fields::instance()->get_execution_setting($key, $default);
    }

    /**
     * Get all execution settings
     *
     * @return array All execution settings with defaults merged
     */
    public function get_all_execution_settings() {
        return Metasync_Settings_Fields::instance()->get_all_execution_settings();
    }

    /**
     * Check if server allows changing memory limit
     * Tests if ini_set('memory_limit') is allowed
     *
     * @return bool True if memory limit can be changed, false otherwise
     */
    private function can_change_memory_limit() {
        return Metasync_Settings_Fields::instance()->can_change_memory_limit();
    }

    /**
     * Get PHP server limits for display
     *
     * @return array Server limits (execution_time, memory_limit, can_change_memory)
     */
    private function get_server_limits() {
        return Metasync_Settings_Fields::instance()->get_server_limits();
    }

    /**
     * Apply memory limit from execution settings
     * Only applies if server allows changing memory limit
     *
     * @return bool True if memory limit was applied, false otherwise
     */
    public function apply_memory_limit() {
        return Metasync_Settings_Fields::instance()->apply_memory_limit();
    }

    /**
     * Parse memory limit string to MB
     *
     * @param string $memory_limit Memory limit string (e.g., "256M", "1G")
     * @return int Memory limit in MB
     */
    private function parse_memory_limit_to_mb($memory_limit) {
        return Metasync_Settings_Fields::instance()->parse_memory_limit_to_mb($memory_limit);
    }

    /**
     * Render Execution Settings section for Advanced tab accordion
     */
    private function render_execution_settings_section() {
        Metasync_Settings_Fields::instance()->render_execution_settings_section();
    }

    /**
     * Get tooltip content for settings fields
     *
     * @return array Field ID => Tooltip text mapping
     */
    private function get_field_tooltips() {
        return Metasync_Settings_Fields::instance()->get_field_tooltips();
    }

    /**
     * Get the section key for a given field ID
     *
     * @param string $field_id The settings field ID
     * @return string|null Section key or null if not found
     */
    private function get_field_section($field_id) {
        return Metasync_Settings_Fields::instance()->get_field_section($field_id);
    }

    /**
     * Render accordion sections for General Settings
     *
     * @param string $page The settings page slug
     */
    public function render_accordion_sections($page) {
        Metasync_Settings_Fields::instance()->render_accordion_sections($page);
    }

    /**
     * Register and add settings
     */
    public function settings_page_init()
    {
        Metasync_Settings_Registration::instance()->settings_page_init();
    }

    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize($input)
    {
        return Metasync_Settings_Registration::instance()->sanitize($input);
    }

    public function metasync_settings_genkey_callback()
    {
        Metasync_Settings_Fields::instance()->metasync_settings_genkey_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function linkgraph_token_callback()
    {
        Metasync_Settings_Fields::instance()->linkgraph_token_callback();
    }


    private function time_elapsed_string($datetime, $full = false)
    {
        return Metasync_Settings_Fields::instance()->time_elapsed_string($datetime, $full);
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function searchatlas_api_key_callback()
    {
        Metasync_Settings_Fields::instance()->searchatlas_api_key_callback();
    }


    /**
     * Site Verification Tools
     *
     * Bing Site Verification
     * Baidu Site Verification
     * Alexa Site Verification
     * Yandex Site Verification
     * Google Site Verification
     * Pinterest Site Verification
     * Norton Safe Web Site Verification
     */

    /**
     * Get the settings option array and print one of its values
     */
    public function bing_site_verification_callback()
    {
        Metasync_Settings_Fields::instance()->bing_site_verification_callback();
    }





    /**
     * Get the settings option array and print one of its values
     */
    public function yandex_site_verification_callback()
    {
        Metasync_Settings_Fields::instance()->yandex_site_verification_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function google_site_verification_callback()
    {
        Metasync_Settings_Fields::instance()->google_site_verification_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function pinterest_site_verification_callback()
    {
        Metasync_Settings_Fields::instance()->pinterest_site_verification_callback();
    }



    /**
     * Local SEO for business and person
     *
     */

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_person_organization_callback()
    {
        Metasync_Settings_Fields::instance()->local_seo_person_organization_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_name_callback()
    {
        Metasync_Settings_Fields::instance()->local_seo_name_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_logo_callback()
    {
        Metasync_Settings_Fields::instance()->local_seo_logo_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_url_callback()
    {
        Metasync_Settings_Fields::instance()->local_seo_url_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_email_callback()
    {
        Metasync_Settings_Fields::instance()->local_seo_email_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_phone_callback()
    {
        Metasync_Settings_Fields::instance()->local_seo_phone_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_address_callback()
    {
        Metasync_Settings_Fields::instance()->local_seo_address_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_business_type_callback()
    {
        Metasync_Settings_Fields::instance()->local_seo_business_type_callback();
    }



    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_opening_hours_callback()
    {
        Metasync_Settings_Fields::instance()->local_seo_opening_hours_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_phone_numbers_callback()
    {
        Metasync_Settings_Fields::instance()->local_seo_phone_numbers_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_price_range_callback()
    {
        Metasync_Settings_Fields::instance()->local_seo_price_range_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_about_page_callback()
    {
        Metasync_Settings_Fields::instance()->local_seo_about_page_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_contact_page_callback()
    {
        Metasync_Settings_Fields::instance()->local_seo_contact_page_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_map_key_callback()
    {
        Metasync_Settings_Fields::instance()->local_seo_map_key_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_geo_coordinates_callback()
    {
        Metasync_Settings_Fields::instance()->local_seo_geo_coordinates_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function header_snippets_callback()
    {
        Metasync_Settings_Fields::instance()->header_snippets_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function footer_snippets_callback()
    {
        Metasync_Settings_Fields::instance()->footer_snippets_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function no_index_posts_callback()
    {
        Metasync_Settings_Fields::instance()->no_index_posts_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function no_follow_links_callback()
    {
        Metasync_Settings_Fields::instance()->no_follow_links_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function open_external_links_callback()
    {
        Metasync_Settings_Fields::instance()->open_external_links_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function add_alt_image_tags_callback()
    {
        Metasync_Settings_Fields::instance()->add_alt_image_tags_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function add_title_image_tags_callback()
    {
        Metasync_Settings_Fields::instance()->add_title_image_tags_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function site_type_callback()
    {
        Metasync_Settings_Fields::instance()->site_type_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function site_business_type_callback()
    {
        Metasync_Settings_Fields::instance()->site_business_type_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function site_company_name_callback()
    {
        Metasync_Settings_Fields::instance()->site_company_name_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function site_google_logo_callback()
    {
        Metasync_Settings_Fields::instance()->site_google_logo_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function site_social_share_image_callback()
    {
        Metasync_Settings_Fields::instance()->site_social_share_image_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function common_robot_meta_tags_callback()
    {
        Metasync_Settings_Fields::instance()->common_robot_meta_tags_callback();
    }

    /**
     * Backward compatibility alias for common_robot_mata_tags_callback
     * @deprecated Use common_robot_meta_tags_callback() instead
     */
    public function common_robot_mata_tags_callback()
    {
        Metasync_Settings_Fields::instance()->common_robot_mata_tags_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function advance_robot_meta_tags_callback()
    {
        Metasync_Settings_Fields::instance()->advance_robot_meta_tags_callback();
    }

    /**
     * Backward compatibility alias for advance_robot_mata_tags_callback
     * @deprecated Use advance_robot_meta_tags_callback() instead
     */
    public function advance_robot_mata_tags_callback()
    {
        Metasync_Settings_Fields::instance()->advance_robot_mata_tags_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function global_twitter_card_type_callback()
    {
        Metasync_Settings_Fields::instance()->global_twitter_card_type_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function global_open_graph_meta_callback()
    {
        Metasync_Settings_Fields::instance()->global_open_graph_meta_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function global_facebook_meta_callback()
    {
        Metasync_Settings_Fields::instance()->global_facebook_meta_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function global_twitter_meta_callback()
    {
        Metasync_Settings_Fields::instance()->global_twitter_meta_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function facebook_page_url_callback()
    {
        Metasync_Settings_Fields::instance()->facebook_page_url_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function facebook_authorship_callback()
    {
        Metasync_Settings_Fields::instance()->facebook_authorship_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function facebook_admin_callback()
    {
        Metasync_Settings_Fields::instance()->facebook_admin_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function facebook_app_callback()
    {
        Metasync_Settings_Fields::instance()->facebook_app_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function facebook_secret_callback()
    {
        Metasync_Settings_Fields::instance()->facebook_secret_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function twitter_username_callback()
    {
        Metasync_Settings_Fields::instance()->twitter_username_callback();
    }

    /**
     * Get business types as choices in local business.
     *
     * @return array
     */
    public static function get_business_types()
    {
        return Metasync_Settings_Fields::get_business_types();
    }

    /**
     * Display a dashboard warning when using the plain permalink structure.
     * @param $data An array of data passed.
     */
    public function permalink_structure_dashboard_warning() {
        $current_permalink_structure = get_option('permalink_structure');

        # Get the plugin name using centralized method
        $plugin_name = Metasync::get_effective_plugin_name();
        $current_rewrite_rules = get_option('rewrite_rules');
        # Check if the current permalink structure is set to "Plain"
        if (($current_permalink_structure == '/%post_id%/' || $current_permalink_structure == '') && $current_rewrite_rules == '') {      
            
           # Show admin notice with plugin name included in the message  
           printf(
            '<div class="notice notice-error is-dismissible">
                <p>
                <b>Warning from %s</b><br>
                To ensure compatibility, please update your permalink structure to any option other than "Plain".
                For any inquiries, contact support.
                </p>
            </div>',
            esc_html($plugin_name)
        );
        }
        flush_rewrite_rules();
    }

    /**
     * Display update warning banner if plugin update is available
     * Checks WordPress update API to see if a newer version is available
     * 
     * @since 1.0.0
     */
    public function display_update_warning_banner() {
        // Get the installed version from database
        $installed_version = get_option('metasync_version', '0.0.0');
        
        // Get plugin basename for WordPress update API check
        // This is the plugin file path relative to plugins directory (e.g., 'metasync/metasync.php')
        $plugin_file = plugin_basename(plugin_dir_path(dirname(__FILE__)) . 'metasync.php');
        
        // Get WordPress update plugins transient (contains available updates)
        $update_plugins = get_site_transient('update_plugins');
        
        // Check if update information exists and if our plugin has an update available
        if ($update_plugins && isset($update_plugins->response) && isset($update_plugins->response[$plugin_file])) {
            $update_info = $update_plugins->response[$plugin_file];
            $latest_version = isset($update_info->new_version) ? $update_info->new_version : '';
            
            // Compare installed version with latest available version
            if ($latest_version && version_compare($installed_version, $latest_version, '<')) {
                // Get the plugin name using centralized method
                $plugin_name = Metasync::get_effective_plugin_name();
                
                // Show admin notice with plugin name included in the message
                printf(
                    '<div class="notice notice-error is-dismissible">
                        <p>
                        <b>Warning from %s</b><br>
                        A new version of %s is available. Please update to the latest version to ensure compatibility and access new features.
                        For any inquiries, contact support.
                        </p>
                    </div>',
                    esc_html($plugin_name),
                    esc_html($plugin_name)
                );
            }
        }
    }


    /*
        Method to handle Ajax request from "Indexation Control" page
    */
    public function meta_sync_save_seo_controls() {
        Metasync_Settings_Registration::instance()->meta_sync_save_seo_controls();
    }

    /**
     * Schedule transient cleanup cron job
     * Runs daily to clean up expired transients and reduce database load
     */
    public function schedule_transient_cleanup_cron()
    {
        // Clear any existing scheduled event first
        $this->unschedule_transient_cleanup_cron();
        
        // Schedule new cron job daily
        if (!wp_next_scheduled('metasync_cleanup_transients')) {
            $scheduled = wp_schedule_event(time(), 'metasync_daily_cleanup', 'metasync_cleanup_transients');
            
            if (!$scheduled) {
                error_log('MetaSync: Failed to schedule transient cleanup cron job');
            }
        }
    }
    
    /**
     * Unschedule transient cleanup cron job
     */
    public function unschedule_transient_cleanup_cron()
    {
        $timestamp = wp_next_scheduled('metasync_cleanup_transients');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'metasync_cleanup_transients');
            error_log('MetaSync: Transient cleanup cron job unscheduled');
        }
    }
    
    /**
     * Maybe schedule transient cleanup cron job
     * Called on init hook - always schedules for database maintenance
     */
    public function maybe_schedule_transient_cleanup_cron()
    {
        if (!wp_next_scheduled('metasync_cleanup_transients')) {
            $this->schedule_transient_cleanup_cron();
        }
    }

    /**
     * Schedule hidden post manager cron job (runs every 7 days)
     * Called on init hook - always schedules for template checking
     */
    public function maybe_schedule_hidden_post_check()
    {
        if (!wp_next_scheduled('metasync_hidden_post_check')) {
            $scheduled = wp_schedule_event(time(), 'metasync_weekly', 'metasync_hidden_post_check');
            
            if ($scheduled) {
                error_log('MetaSync: Hidden post manager cron job scheduled successfully (runs every 7 days)');
            } else {
                error_log('MetaSync: Failed to schedule hidden post manager cron job');
            }
        }
    }

    /**
     * Schedule OTTO 404 exclusion recheck cron job (runs daily)
     * Rechecks URLs auto-excluded due to 404 after 7 days; removes from exclusion if now available
     */
    public function maybe_schedule_otto_recheck_404_cron()
    {
        if (!wp_next_scheduled('metasync_otto_recheck_404_exclusions')) {
            $scheduled = wp_schedule_event(time(), 'metasync_daily_cleanup', 'metasync_otto_recheck_404_exclusions');
            if ($scheduled) {
                error_log('MetaSync: OTTO 404 recheck cron job scheduled successfully (runs daily)');
            } else {
                error_log('MetaSync: Failed to schedule OTTO 404 recheck cron job');
            }
        }
    }
    
    /**
     * Execute transient cleanup cron job
     * Cleans up expired transients and plugin-specific transients to reduce database load
     */
    public function execute_transient_cleanup()
    {
        Metasync_Admin_Ajax::instance()->execute_transient_cleanup();
    }

    /**
     * Control plugin auto-updates based on user setting
     *
     * @param bool $update Whether to update
     * @param object $item Update offer
     * @return bool Whether to allow auto-update
     */
    public function control_plugin_auto_updates($update, $item)
    {
        # Check if the item object has the slug property
        if (!isset($item->slug)) {
            return $update;
        }

        // Check if this is our plugin
        if ($item->slug === 'metasync') {
            $general_settings = Metasync::get_option('general') ?? [];
            $enable_auto_updates = $general_settings['enable_auto_updates'] ?? false;

            // Return the user's preference (true = allow auto-updates, false = prevent)
            return $enable_auto_updates === 'true' || $enable_auto_updates === true;
        }

        // For other plugins, don't interfere with their auto-update settings
        return $update;
    }

    /**
     * AJAX handler to add excluded URL for OTTO
     */
    public function ajax_otto_add_excluded_url()
    {
        Metasync_Otto_Cache_Manager::instance()->ajax_otto_add_excluded_url();
    }

    /**
     * AJAX handler to delete excluded URL for OTTO
     */
    public function ajax_otto_delete_excluded_url()
    {
        Metasync_Otto_Cache_Manager::instance()->ajax_otto_delete_excluded_url();
    }

    /**
     * AJAX handler to recheck if an excluded URL is now available
     * Used for "Recheck" action on Excluded URLs list
     */
    public function ajax_otto_recheck_excluded_url()
    {
        Metasync_Otto_Cache_Manager::instance()->ajax_otto_recheck_excluded_url();
    }

    /**
     * AJAX handler to get excluded URLs with pagination
     */
    public function ajax_otto_get_excluded_urls()
    {
        Metasync_Otto_Cache_Manager::instance()->ajax_otto_get_excluded_urls();
    }
    
    /**
     * AJAX handler for submitting issue reports to Sentry
     * 
     * @since 2.5.10
     * @return void Sends JSON response and exits
     */
    public function ajax_submit_issue_report()
    {
        Metasync_Admin_Ajax::instance()->ajax_submit_issue_report();
    }

    /**
     * Format duration seconds into human-readable label
     *
     * @since 2.5.11
     * @param int $seconds Duration in seconds
     * @return string Human-readable duration
     */
    private function format_duration_label($seconds)
    {
        $labels = array(
            3600 => '1 hour',
            14400 => '4 hours',
            28800 => '8 hours',
            86400 => '24 hours',
            172800 => '48 hours',
            604800 => '7 days',
            1209600 => '14 days',
            2592000 => '30 days'
        );

        if (isset($labels[$seconds])) {
            return $labels[$seconds];
        }

        # Calculate hours if not a standard duration
        $hours = round($seconds / 3600);
        return $hours . ' hours';
    }

    /**
     * AJAX handler for password recovery
     * Sends the whitelabel settings password to the configured recovery email
     */
    public function ajax_recover_password()
    {
        Metasync_Admin_Ajax::instance()->ajax_recover_password();
    }

    /**
     * AJAX handler for saving theme preference
     * Saves the user's theme choice (light/dark) to WordPress options
     */
    public function ajax_save_theme()
    {
        Metasync_Admin_Ajax::instance()->ajax_save_theme();
    }

    /**
     * AJAX handler for tracking 1-click activation in Mixpanel
     */
    public function ajax_track_one_click_activation()
    {
        Metasync_Admin_Ajax::instance()->ajax_track_one_click_activation();
    }

    /**
     * Handler for exporting whitelabel settings to a zip file
     * Uses admin-post action for file downloads
     */
    public function handle_export_whitelabel_settings()
    {
        Metasync_Admin_Ajax::instance()->handle_export_whitelabel_settings();
    }


    /**
     * Add custom column to posts/pages list for HTML-converted pages
     *
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function add_html_converted_column($columns)
    {
        // Add column after the title column
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['metasync_html_source'] = __('Source', 'metasync');
            }
        }
        return $new_columns;
    }

    /**
     * Render content for the HTML-converted column
     *
     * @param string $column_name Name of the column
     * @param int $post_id Post ID
     */
    public function render_html_converted_column($column_name, $post_id)
    {
        if ($column_name !== 'metasync_html_source') {
            return;
        }

        // Check if this is an HTML-converted page
        $has_raw_html = get_post_meta($post_id, '_metasync_raw_html_enabled', true);
        $has_custom_css = get_post_meta($post_id, '_metasync_custom_css', true);

        // If page has raw HTML or custom CSS from conversion, show badge
        if ($has_raw_html || !empty($has_custom_css)) {
            $label = $this->get_html_source_label();
            $tooltip = sprintf(
                __('This page was created using %s HTML-to-Builder converter', 'metasync'),
                $label
            );

            echo sprintf(
                '<span class="metasync-html-badge" title="%s">
                    <span class="metasync-badge-icon">⚡</span>
                    <span class="metasync-badge-text">%s</span>
                </span>',
                esc_attr($tooltip),
                esc_html($label)
            );
        }
    }

    /**
     * Get the label for HTML-converted pages (respects whitelabel settings)
     *
     * @return string Label to display
     */
    private function get_html_source_label()
    {
        $whitelabel_company = Metasync::get_whitelabel_company_name();
        if (!empty($whitelabel_company)) {
            return $whitelabel_company . ' AI';
        }
        return Metasync::get_effective_plugin_name() . ' AI';
    }

    /**
     * Add source notice banner in the page editor
     *
     * @param WP_Post $post Current post object
     */
    public function add_editor_source_notice($post)
    {
        if (!$post || !in_array($post->post_type, array('post', 'page'))) {
            return;
        }

        $has_raw_html = get_post_meta($post->ID, '_metasync_raw_html_enabled', true);
        $has_custom_css = get_post_meta($post->ID, '_metasync_custom_css', true);

        if ($has_raw_html || !empty($has_custom_css)) {
            $label = $this->get_html_source_label();
            $message = sprintf(
                __('This page was created using %s HTML-to-Builder converter. The design is preserved with custom CSS and inline styles.', 'metasync'),
                '<strong>' . esc_html($label) . '</strong>'
            );

            echo sprintf(
                '<div class="metasync-editor-notice notice notice-info is-dismissible">
                    <div class="metasync-editor-notice-content">
                        <span class="metasync-editor-badge">
                            <span class="metasync-badge-icon">⚡</span>
                            <span class="metasync-badge-text">%s</span>
                        </span>
                        <p class="metasync-editor-message">%s</p>
                    </div>
                </div>',
                esc_html($label),
                $message
            );
        }
    }

    /**
     * Add source display in quick edit panel
     *
     * @param string $column_name Column name
     * @param string $post_type Post type
     */
    public function add_quick_edit_source_display($column_name, $post_type)
    {
        if ($column_name !== 'metasync_html_source') {
            return;
        }

        if (!in_array($post_type, array('post', 'page'))) {
            return;
        }

        ?>
        <fieldset class="inline-edit-col-left metasync-quick-edit-source">
            <div class="inline-edit-col">
                <label>
                    <span class="title"><?php _e('Source', 'metasync'); ?></span>
                    <span class="metasync-quick-edit-badge-container"></span>
                </label>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Add dashboard widget for HTML-converted pages
     */
    public function add_html_pages_dashboard_widget()
    {
        $label = $this->get_html_source_label();
        $widget_title = sprintf(__('%s Pages', 'metasync'), $label);

        wp_add_dashboard_widget(
            'metasync_html_pages_widget',
            $widget_title,
            array($this, 'render_html_pages_dashboard_widget')
        );
    }

    /**
     * Render the dashboard widget content
     */
    public function render_html_pages_dashboard_widget()
    {
        Metasync_Admin_Ajax::instance()->render_html_pages_dashboard_widget();
    }

    /**
     * Bot Statistics page callback
     * Displays bot detection statistics and logs
     */
    public function create_admin_bot_statistics_page()
    {
        require_once plugin_dir_path(dirname(__FILE__)) . 'views/metasync-otto-bot-statistics.php';
    }

    /**
     * AJAX handler for resetting bot statistics
     */
    public function ajax_reset_bot_stats()
    {
        check_ajax_referer('metasync_reset_bot_stats', 'nonce');

        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'otto/class-metasync-otto-bot-statistics-database.php';
        $db = Metasync_Otto_Bot_Statistics_Database::get_instance();

        $result = $db->reset_statistics();

        if ($result) {
            wp_send_json_success(['message' => 'Statistics reset successfully.']);
        } else {
            wp_send_json_error(['message' => 'Failed to reset statistics.']);
        }
    }

    /**
     * AJAX handler for sending URLs to Google Instant Indexing API
     *
     * @since 2.6.0
     * @return void Sends JSON response and exits
     */
    public function ajax_send_giapi()
    {
        $post_data = metasync_sanitize_input_array($_POST);
        if (!isset($post_data['metasync_giapi_url'])) {
            return;
        }

        // Parse URLs from textarea input (one per line)
        $urls = array_values(array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($post_data['metasync_giapi_url']))))));

        if (empty($urls)) {
            return;
        }

        if (!isset($post_data['metasync_giapi_action'])) {
            return;
        }
        $action = sanitize_title($post_data['metasync_giapi_action']);

        // Map form action values to google_index_direct action values
        if ($action === 'remove') {
            $action = 'delete';
        }

        header('Content-type: application/json');

        $result_data = [];
        foreach ($urls as $i => $url) {
            $url = esc_url_raw($url);
            if (empty($url)) {
                continue;
            }

            if ($action === 'status') {
                $result = google_index_direct()->get_url_status($url);
            } else {
                $result = google_index_direct()->index_url($url, $action);
            }

            $key = 'url-' . $i;
            if (!empty($result['success'])) {
                $result_data[$key] = $result['data'];
            } else {
                $result_data[$key] = (object) [
                    'error' => (object) [
                        'code' => isset($result['error']['code']) ? $result['error']['code'] : 400,
                        'message' => isset($result['error']['message']) ? $result['error']['message'] : 'Unknown error',
                    ]
                ];
            }
        }

        // For single URL, unwrap from the batch format (matches old behavior)
        if (count($result_data) === 1) {
            $result_data = reset($result_data);
        }

        wp_send_json($result_data);
        wp_die();
    }

    /**
     * AJAX handler for sending URLs to Bing via IndexNow API
     *
     * @since 2.6.0
     * @return void Sends JSON response and exits
     */
    public function ajax_send_bing_indexnow()
    {
        require_once plugin_dir_path(dirname(__FILE__)) . 'bing-index/class-metasync-bing-instant-index.php';
        $bing_instant_index = new Metasync_Bing_Instant_Index();
        $bing_instant_index->send();
    }

    /**
     * Save instant indexing settings (Google and Bing)
     *
     * @since 2.6.0
     * @return void
     */
    public function save_instant_indexing_settings()
    {
        // Check if this is a settings submission
        if (!isset($_POST['submit'])) {
            return;
        }

        // Save Google Instant Indexing settings
        if (isset($_POST['metasync_google_json_key'])) {
            $post_data = metasync_sanitize_input_array($_POST);
            $settings = get_option('metasync_options_instant_indexing', ['json_key' => '', 'post_types' => []]);

            // Get JSON key from textarea or file upload
            $json = sanitize_textarea_field(wp_unslash($post_data['metasync_google_json_key']));
            if (isset($_FILES['metasync_google_json_file']) && !empty($_FILES['metasync_google_json_file']['tmp_name']) && file_exists($_FILES['metasync_google_json_file']['tmp_name'])) {
                $json = wp_unslash(file_get_contents($_FILES['metasync_google_json_file']['tmp_name']));
            }

            $post_types = isset($post_data['metasync_post_types']) && is_array($post_data['metasync_post_types']) && !empty($json) ? array_map('sanitize_title', $post_data['metasync_post_types']) : [];

            $new_settings = [
                'json_key'   => $json,
                'post_types' => array_values($post_types),
            ];

            $settings = array_merge($settings, $new_settings);

            if (!empty($settings)) {
                update_option('metasync_options_instant_indexing', $settings);
            }

            // Also save parsed credentials to google_index_direct for native API usage
            if (!empty($json) && function_exists('google_index_direct')) {
                $decoded = json_decode($json, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    google_index_direct()->save_service_account_config($decoded);
                }
            }
        }

        // Note: Bing Instant Indexing settings are saved via AJAX in save_bing_inline_settings_ajax()
    }

    /**
     * Save Bing instant indexing settings from inline form (Indexation Control page)
     *
     * @since 2.6.0
     * @return bool True on success, false on failure
     */
    private function save_bing_inline_settings_ajax() {
        return Metasync_Settings_Registration::instance()->save_bing_inline_settings_ajax();
    }

    /**
     * Add instant indexing action links to post/page rows
     *
     * @since 2.6.0
     * @param array $actions Current actions
     * @param WP_Post $post Current post object
     * @return array Modified actions
     */
    public function add_instant_indexing_post_actions($actions, $post)
    {
        // Add Google Instant Indexing links
        $options = get_option('metasync_options_instant_indexing', ['json_key' => '', 'post_types' => []]);
        $post_types = isset($options['post_types']) && is_array($options['post_types']) ? $options['post_types'] : [];

        if (in_array($post->post_type, $post_types) && $post->post_status == 'publish') {
            $link = get_permalink($post);

            // Get menu slug (support white label)
            $general_options = Metasync::get_option('general') ?? [];
            $menu_slug = !empty($general_options['white_label_plugin_menu_slug']) ? $general_options['white_label_plugin_menu_slug'] : 'searchatlas';
            $page_slug = $menu_slug . '-google-console';

            $actions['index-update'] = '<a href="' . admin_url("admin.php?page=" . $page_slug . "&postaction=update&posturl=" . rawurlencode($link)) . '" title="" rel="permalink">Update Google Index</a>';
            $actions['index-status'] = '<a href="' . admin_url("admin.php?page=" . $page_slug . "&postaction=status&posturl=" . rawurlencode($link)) . '" title="" rel="permalink">Status Google Index</a>';
        }

        // Add Bing Instant Indexing links
        require_once plugin_dir_path(dirname(__FILE__)) . 'bing-index/class-metasync-bing-instant-index.php';
        $bing_instant_index = new Metasync_Bing_Instant_Index();
        $actions = $bing_instant_index->bing_instant_index_post_link($actions, $post);

        return $actions;
    }

    /**
     * Auto-submit post to instant indexing services when published
     *
     * @since 2.6.0
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     * @param bool $update Whether this is an update
     * @return void
     */
    public function auto_submit_to_instant_indexing($post_id, $post, $update)
    {
        // Google Instant Indexing auto-submit is not yet implemented via google_index_direct().
        // The previous implementation was also a no-op for Google.
        // TODO: Implement async auto-submit via WP-Cron or Action Scheduler in a future phase.

        // Auto-submit to Bing Instant Indexing
        require_once plugin_dir_path(dirname(__FILE__)) . 'bing-index/class-metasync-bing-instant-index.php';
        $bing_instant_index = new Metasync_Bing_Instant_Index();
        $bing_instant_index->auto_submit_on_publish($post_id, $post, $update);
    }
}
