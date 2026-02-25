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
        // Render header
        self::render_static_header($page_title);
        // Render navigation
        self::render_static_navigation($current_page);
    }
    
    /**
     * Static header render
     */
    public static function render_static_header($page_title = null) {
        $whitelabel_settings = Metasync::get_whitelabel_settings();
        $whitelabel_logo = Metasync::get_whitelabel_logo();
        $is_whitelabel = isset($whitelabel_settings['is_whitelabel']) ? $whitelabel_settings['is_whitelabel'] : false;
        $effective_plugin_name = Metasync::get_effective_plugin_name();
        $display_title = $page_title ?: $effective_plugin_name;
        
        $show_logo = false;
        $logo_url = '';
        
        if (!empty($whitelabel_logo) && filter_var($whitelabel_logo, FILTER_VALIDATE_URL)) {
            $show_logo = true;
            $logo_url = esc_url($whitelabel_logo);
        } elseif (!$is_whitelabel) {
            $show_logo = true;
            $logo_url = Metasync::HOMEPAGE_DOMAIN . '/wp-content/uploads/2023/12/white.svg';
        }
        
        $current_theme = get_option('metasync_theme', 'dark');
        $general_settings = Metasync::get_option('general');
        $searchatlas_api_key = isset($general_settings['searchatlas_api_key']) ? $general_settings['searchatlas_api_key'] : '';
        $is_integrated = !empty($searchatlas_api_key);
        ?>
        <div class="metasync-header" data-current-theme="<?php echo esc_attr($current_theme); ?>">
            <div class="metasync-header-left">
                <?php if ($show_logo && !empty($logo_url)): ?>
                    <div class="metasync-logo-container">
                        <img src="<?php echo $logo_url; ?>" alt="Logo" class="metasync-logo" />
                    </div>
                <?php endif; ?>
            </div>
            <div class="metasync-header-right">
                <div class="metasync-status <?php echo $is_integrated ? 'connected' : 'disconnected'; ?>">
                    <span class="status-dot"></span>
                    <span class="status-text"><?php echo $is_integrated ? 'Connected' : 'Not Connected'; ?></span>
                </div>
                <button type="button" class="metasync-theme-toggle" onclick="toggleMetasyncTheme()" title="Toggle theme">
                    <span class="theme-icon-light">☀️</span>
                    <span class="theme-icon-dark">🌙</span>
                </button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Static navigation render
     */
    public static function render_static_navigation($current_page = null) {
        $general_options = Metasync::get_option('general') ?? [];
        $whitelabel_settings = Metasync::get_whitelabel_settings();
        $page_slug = self::$page_slug;
        
        // Build menu items dynamically
        $menu_items = [];
        $menu_icons = [
            'dashboard' => '📊',
            'seo_controls' => '🔍',
            'optimal_settings' => '🚀',
            'instant_index' => '🔗',
            'google_console' => '📊',
            'compatibility' => '🔧',
            'sync_log' => '📋',
            'redirections' => '↩️',
            'robots_txt' => '🤖',
            'xml_sitemap' => '🗺️',
            'custom_pages' => '📝',
            'report_issue' => '📝',
            'general' => '⚙️'
        ];
        
        // Dashboard
        if (empty($whitelabel_settings['hide_dashboard'])) {
            $menu_items['dashboard'] = ['title' => 'Dashboard', 'slug_suffix' => '-dashboard'];
        }
        
        // Indexation Control
        if (empty($whitelabel_settings['hide_indexation_control'])) {
            $menu_items['seo_controls'] = ['title' => 'Indexation Control', 'slug_suffix' => '-seo-controls'];
        }
        
        // Optimal Settings
        if ($general_options['enable_optimal_settings'] ?? false) {
            $menu_items['optimal_settings'] = ['title' => 'Optimal Settings', 'slug_suffix' => '-optimal-settings'];
        }
        
        // Instant Indexing
        if ($general_options['enable_googleinstantindex'] ?? false) {
            $menu_items['instant_index'] = ['title' => 'Instant Indexing', 'slug_suffix' => '-instant-index'];
        }
        
        // Google Console
        if ($general_options['enable_google_console'] ?? false) {
            $menu_items['google_console'] = ['title' => 'Google Console', 'slug_suffix' => '-google-console'];
        }
        
        // Compatibility
        if (empty($whitelabel_settings['hide_compatibility'])) {
            $menu_items['compatibility'] = ['title' => 'Compatibility', 'slug_suffix' => '-compatibility'];
        }
        
        // Sync Log
        if (empty($whitelabel_settings['hide_sync_log'])) {
            $menu_items['sync_log'] = ['title' => 'Sync Log', 'slug_suffix' => '-sync-log'];
        }
        
        // Redirections
        if (empty($whitelabel_settings['hide_redirections'])) {
            $menu_items['redirections'] = ['title' => 'Redirections', 'slug_suffix' => '-redirections'];
        }
        
        // Robots.txt
        if (empty($whitelabel_settings['hide_robots'])) {
            $menu_items['robots_txt'] = ['title' => 'Robots.txt', 'slug_suffix' => '-robots-txt'];
        }
        
        // XML Sitemap
        $menu_items['xml_sitemap'] = ['title' => 'XML Sitemap', 'slug_suffix' => '-xml-sitemap'];
        ?>
        <div class="metasync-nav-wrapper">
            <div class="metasync-nav-tabs">
                <div class="metasync-nav-left">
                <?php foreach ($menu_items as $key => $menu_item): 
                    $is_active = ($current_page === $key);
                    $icon = $menu_icons[$key] ?? '📄';
                    $page_url = '?page=' . $page_slug . $menu_item['slug_suffix'];
                ?>
                    <a href="<?php echo esc_url($page_url); ?>" class="metasync-nav-tab <?php echo $is_active ? 'active' : ''; ?>">
                        <span class="tab-icon"><?php echo $icon; ?></span>
                        <span class="tab-text"><?php echo esc_html($menu_item['title']); ?></span>
                    </a>
                <?php endforeach; ?>
                </div>
                <div class="metasync-nav-right">
                    <a href="?page=<?php echo $page_slug; ?>-custom-pages" class="metasync-nav-tab <?php echo $current_page === 'custom_pages' ? 'active' : ''; ?>" style="margin-right: 10px;">
                        <span class="tab-icon">📝</span>
                        <span class="tab-text">Custom Pages</span>
                    </a>
                    <a href="?page=<?php echo $page_slug; ?>-report-issue" class="metasync-nav-tab <?php echo $current_page === 'report_issue' ? 'active' : ''; ?>">
                        <span class="tab-icon">📝</span>
                        <span class="tab-text">Report Issue</span>
                    </a>
                    <div class="metasync-simple-dropdown">
                        <button type="button" class="metasync-seo-btn" id="metasync-seo-btn" onclick="toggleSeoMenuPortal(event)">
                            <span class="tab-icon">🔍</span>
                            <span class="tab-text">SEO</span>
                            <span class="dropdown-arrow">▼</span>
                        </button>
                    </div>
                    <div class="metasync-simple-dropdown">
                        <button type="button" class="metasync-settings-btn" id="metasync-settings-btn" onclick="toggleSettingsMenuPortal(event)" aria-expanded="false">
                            <span class="tab-icon">⚙️</span>
                            <span class="tab-text">Settings</span>
                            <span class="dropdown-arrow">▼</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        function toggleSeoMenuPortal(event) {
            event.preventDefault();
            event.stopPropagation();
            var button = event.currentTarget;
            var existingMenu = document.getElementById('metasync-seo-portal-menu');
            if (existingMenu) {
                existingMenu.remove();
                button.classList.remove('active');
                return;
            }
            var menu = document.createElement('div');
            menu.id = 'metasync-seo-portal-menu';
            menu.className = 'metasync-portal-menu';

            var rect = button.getBoundingClientRect();
            menu.style.position = 'fixed';
            menu.style.top = (rect.bottom + 8) + 'px';
            menu.style.right = (window.innerWidth - rect.right) + 'px';
            menu.style.zIndex = '999999999';
            document.body.appendChild(menu);
            button.classList.add('active');
        }

        function toggleSettingsMenuPortal(event) {
            event.preventDefault();
            event.stopPropagation();
            var button = event.currentTarget;
            var existingMenu = document.getElementById('metasync-portal-menu');
            if (existingMenu) {
                existingMenu.remove();
                button.classList.remove('active');
                return;
            }
            var menu = document.createElement('div');
            menu.id = 'metasync-portal-menu';
            menu.className = 'metasync-portal-menu';

            var hideAdvanced = <?php echo !empty($whitelabel_settings['hide_advanced']) ? 'true' : 'false'; ?>;
            var showGeneral = <?php echo Metasync_Access_Control::user_can_access('hide_settings') ? 'true' : 'false'; ?>;
            
            if (showGeneral) {
                var generalLink = document.createElement('a');
                generalLink.href = '?page=<?php echo $page_slug; ?>&tab=general';
                generalLink.className = 'metasync-portal-item';
                generalLink.textContent = 'General';
                menu.appendChild(generalLink);
            }

            var whitelabelLink = document.createElement('a');
            whitelabelLink.href = '?page=<?php echo $page_slug; ?>&tab=whitelabel';
            whitelabelLink.className = 'metasync-portal-item';
            whitelabelLink.textContent = 'White Label';
            menu.appendChild(whitelabelLink);

            if (!hideAdvanced) {
                var advancedLink = document.createElement('a');
                advancedLink.href = '?page=<?php echo $page_slug; ?>&tab=advanced';
                advancedLink.className = 'metasync-portal-item';
                advancedLink.textContent = 'Advanced';
                menu.appendChild(advancedLink);
            }


            var rect = button.getBoundingClientRect();
            menu.style.position = 'fixed';
            menu.style.top = (rect.bottom + 8) + 'px';
            menu.style.right = (window.innerWidth - rect.right) + 'px';
            menu.style.zIndex = '999999999';
            document.body.appendChild(menu);
            button.classList.add('active');
        }
        document.addEventListener('click', function(event) {
            var seoButton = document.getElementById('metasync-seo-btn');
            var seoMenu = document.getElementById('metasync-seo-portal-menu');
            if (seoMenu && seoButton && !seoButton.contains(event.target) && !seoMenu.contains(event.target)) {
                seoMenu.remove();
                seoButton.classList.remove('active');
            }

            var button = document.getElementById('metasync-settings-btn');
            var menu = document.getElementById('metasync-portal-menu');
            if (menu && button && !button.contains(event.target) && !menu.contains(event.target)) {
                menu.remove();
                button.classList.remove('active');
            }
        });
        </script>
        <?php
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
    private $db_heartbeat_errors;
    private $setup_wizard;


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

        // Add AJAX for saving general settings
        add_action( 'wp_ajax_meta_sync_save_settings', array($this,'meta_sync_save_settings') );
        
        // Add AJAX for saving Indexation Control settings
        add_action( 'wp_ajax_meta_sync_save_seo_controls', array($this,'meta_sync_save_seo_controls') );
        
        // Add AJAX for saving execution settings
        add_action( 'wp_ajax_metasync_save_execution_settings', array($this, 'ajax_save_execution_settings') );
        
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
        
        // Pre-SSO announce: rate-limited ping when no API key yet (PR4 - heartbeat reliability)
        add_action('init', array($this, 'maybe_send_pre_sso_announce'));
        
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
        $log_file = WP_CONTENT_DIR . '/metasync_data/plugin_errors.log';
        if (!Metasync::current_user_has_plugin_access()) {
            return;
        }
    
        // Handle form submission for plugin logging

       
        // Handle form submission for WordPress error logging
        // SECURITY: Verify nonce and sanitize inputs
        if (isset($_POST['wp_debug_log_enabled']) && 
            isset($_POST['wp_debug_enabled']) && 
            isset($_POST['wp_debug_display_enabled']) &&
            isset($_POST['wp_debug_nonce']) &&
            wp_verify_nonce($_POST['wp_debug_nonce'], 'metasync_wp_debug_settings')) {
            
            // Sanitize and validate inputs - only allow 'true' or 'false'
            $wp_debug = in_array($_POST['wp_debug_enabled'], ['true', 'false']) ? $_POST['wp_debug_enabled'] : 'false';
            $wp_debug_log = in_array($_POST['wp_debug_log_enabled'], ['true', 'false']) ? $_POST['wp_debug_log_enabled'] : 'false';
            $wp_debug_display = in_array($_POST['wp_debug_display_enabled'], ['true', 'false']) ? $_POST['wp_debug_display_enabled'] : 'false';
            
            update_option('wp_debug_enabled', $wp_debug);
            update_option('wp_debug_log_enabled', $wp_debug_log);
            update_option('wp_debug_display_enabled', $wp_debug_display);
            
            $data = new ConfigControllerMetaSync();
            $data->store();
            
            // Show success message
            add_settings_error(
                'metasync_messages',
                'metasync_message',
                'WordPress debug settings updated successfully.',
                'updated'
            );
        } elseif (isset($_POST['wp_debug_nonce']) && !wp_verify_nonce($_POST['wp_debug_nonce'], 'metasync_wp_debug_settings')) {
            // Show error if nonce verification failed
            add_settings_error(
                'metasync_messages',
                'metasync_message',
                'Security verification failed. Please try again.',
                'error'
            );
        }
       
    
        $log_enabled = get_option('metasync_log_enabled', 'yes');
        $wp_debug_enabled = get_option('wp_debug_enabled', 'false');
        $wp_debug_log_enabled = get_option('wp_debug_log_enabled', 'false');
        $wp_debug_display_enabled = get_option('wp_debug_display_enabled', 'false');
        ?>
    
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">

        
        <?php $this->render_plugin_header('Error Logs'); ?>
        
        <?php $this->render_navigation_menu('error-log'); ?>
            
            <!-- Log File Management -->
            <div class="dashboard-card">
                <h2>🗑️ Error Log Management</h2>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Clear WordPress error logs to free up space and remove old entries.</p>
                
                <form method="post" style="margin-top: 15px;">
                    <input type="hidden" name="clear_log" value="yes" />
                    <?php wp_nonce_field('metasync_clear_log_nonce', 'clear_log_nonce'); ?>
                    <?php submit_button('🧹 Clear Error Logs', 'secondary', 'clear-log', false, array('class' => 'button button-secondary')); ?>
            </form>
            </div>
            
            <!-- WordPress Debug Settings -->
            <form method="post">
                <?php wp_nonce_field('metasync_wp_debug_settings', 'wp_debug_nonce'); ?>
                <div class="dashboard-card">
                    <h2>🔧 WordPress Debug Configuration</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure WordPress debug settings to control error logging and display.</p>
                    <?php settings_errors('metasync_messages'); ?>
                    
                <table class="form-table">
    <tr valign="top">
        <th scope="row">WP_DEBUG</th>
        <td>
            <select name="wp_debug_enabled">
                <option value="false" <?php selected('false', $wp_debug_enabled); ?>>Disabled</option>
                <option value="true" <?php selected('true', $wp_debug_enabled); ?>>Enabled</option>                
            </select>
                                <p class="description">Enable or disable WordPress debugging mode.</p>
        </td>
    </tr>
    <tr valign="top">
        <th scope="row">WP_DEBUG_LOG</th>
        <td>
            <select name="wp_debug_log_enabled">
                <option value="false" <?php selected('false', $wp_debug_log_enabled); ?>>Disabled</option>
                <option value="true" <?php selected('true', $wp_debug_log_enabled); ?>>Enabled</option>                
            </select>
                                <p class="description">Save debug messages to a log file.</p>
        </td>
    </tr>
    <tr valign="top">
        <th scope="row">WP_DEBUG_DISPLAY</th>
        <td>
            <select name="wp_debug_display_enabled">
                <option value="false" <?php selected('false', $wp_debug_display_enabled); ?>>Disabled</option>
                <option value="true" <?php selected('true', $wp_debug_display_enabled); ?>>Enabled</option>                
            </select>
                                <p class="description">Display debug messages on the website (not recommended for production).</p>
        </td>
    </tr>
</table>
        </div>
                
                <div class="dashboard-card">
                    <h2>💾 Save Changes</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Apply your WordPress logging configuration changes.</p>
                    <?php submit_button('Save WordPress Logging Settings', 'primary', 'submit', false, array('class' => 'button button-primary')); ?>
                </div>
            </form>
            
            <!-- Error Log Display -->
            <div class="dashboard-card">
                <h2>📄 Error Log Contents</h2>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">View the current error log entries for troubleshooting and monitoring.</p>
                
        <?php 
        if (file_exists($log_file)) {
                    $log_content = file_get_contents($log_file);
                    if (!empty($log_content)) {
                        echo '<div class="dashboard-code-block" style="width: 100%; box-sizing: border-box;">';
                        echo '<pre style="background: var(--dashboard-card-bg); border: 1px solid var(--dashboard-border); border-radius: 8px; padding: 20px; overflow: auto; max-height: 400px; font-family: \'SF Mono\', Monaco, \'Cascadia Code\', \'Roboto Mono\', Consolas, monospace; font-size: 13px; line-height: 1.6; color: var(--dashboard-text-primary); margin: 0; box-shadow: var(--dashboard-shadow-sm); width: 100%; box-sizing: border-box; white-space: pre-wrap; word-wrap: break-word;">';
                        echo esc_html($log_content);
            echo '</pre>';
                        echo '</div>';
        } else {
                        echo '<div class="dashboard-empty-state">';
                        echo '<p style="color: var(--dashboard-text-secondary); font-style: italic; text-align: center; padding: 40px 20px;">✅ Log file is empty - no errors recorded.</p>';
                        echo '</div>';
                    }
                } else {
                    echo '<div class="dashboard-empty-state">';
                    echo '<p style="color: var(--dashboard-text-secondary); font-style: italic; text-align: center; padding: 40px 20px;">📝 No log file found. Error logging may not be enabled.</p>';
                    echo '</div>';
                }
                ?>
            </div>
        </div>
        <?php
    }
    public function metasync_update_wp_config() {
        $wp_config_path = ABSPATH . 'wp-config.php';
        if (file_exists($wp_config_path) && is_writable($wp_config_path)) {
            $config_file = file_get_contents($wp_config_path);
    
            // Update or add WP_DEBUG
            $wp_debug_enabled = get_option('wp_debug_enabled', 'false') === 'true' ? 'true' : 'false';
            if (preg_match("/define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*.*?\s*\)\s*;/", $config_file)) {
                $config_file = preg_replace("/define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*.*?\s*\)\s*;/", "define('WP_DEBUG', $wp_debug_enabled);", $config_file);
            } else {
                $config_file = str_replace("/* That's all, stop editing! Happy publishing. */", "define('WP_DEBUG', $wp_debug_enabled);\n\n/* That's all, stop editing! Happy publishing. */", $config_file);
            }
    
            // Update or add WP_DEBUG_LOG
            $wp_debug_log_enabled = get_option('wp_debug_log_enabled', 'false') === 'true' ? 'true' : 'false';
            if (preg_match("/define\s*\(\s*['\"]WP_DEBUG_LOG['\"]\s*,\s*.*?\s*\)\s*;/", $config_file)) {
                $config_file = preg_replace("/define\s*\(\s*['\"]WP_DEBUG_LOG['\"]\s*,\s*.*?\s*\)\s*;/", "define('WP_DEBUG_LOG', $wp_debug_log_enabled);", $config_file);
            } else {
                $config_file = str_replace("/* That's all, stop editing! Happy publishing. */", "define('WP_DEBUG_LOG', $wp_debug_log_enabled);\n\n/* That's all, stop editing! Happy publishing. */", $config_file);
            }
    
            // Update or add WP_DEBUG_DISPLAY
            $wp_debug_display_enabled = get_option('wp_debug_display_enabled', 'false') === 'true' ? 'true' : 'false';
            if (preg_match("/define\s*\(\s*['\"]WP_DEBUG_DISPLAY['\"]\s*,\s*.*?\s*\)\s*;/", $config_file)) {
                $config_file = preg_replace("/define\s*\(\s*['\"]WP_DEBUG_DISPLAY['\"]\s*,\s*.*?\s*\)\s*;/","define('WP_DEBUG_DISPLAY', $wp_debug_display_enabled);", $config_file);
            } else {
                $config_file = str_replace("/* That's all, stop editing! Happy publishing. */", "define('WP_DEBUG_DISPLAY', $wp_debug_display_enabled);\n\n/* That's all, stop editing! Happy publishing. */", $config_file);
            }
    
            // Write the updated content back to wp-config.php
            file_put_contents($wp_config_path, $config_file);
        } else {
            wp_die('The wp-config.php file is not writable. Please check the file permissions.');
        }
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
        // Only sync if general settings (which contain whitelabel fields) have changed
        $old_general = is_array($old_value) ? ($old_value['general'] ?? []) : [];
        $new_general = is_array($new_value) ? ($new_value['general'] ?? []) : [];

        $whitelabel_keys = [
            'white_label_plugin_name',
            'white_label_plugin_description',
            'white_label_plugin_author',
            'white_label_plugin_author_uri',
            'white_label_plugin_uri',
        ];

        $changed = false;
        foreach ($whitelabel_keys as $key) {
            if (($old_general[$key] ?? '') !== ($new_general[$key] ?? '')) {
                $changed = true;
                break;
            }
        }

        if ($changed) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-activator.php';
            Metasync_Activator::sync_plugin_file_headers();
        }
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
            'metasync-import-external',
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
        // Set execution time limit for import operations
        $execution_time = $this->get_execution_setting('max_execution_time');
        if (function_exists('set_time_limit')) {
            @set_time_limit($execution_time);
        }
        
        // Apply memory limit if server allows
        $this->apply_memory_limit();
        
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
        
        // Pass DB redirection resource if needed
        $importer = new Metasync_External_Importer($this->db_redirection);
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

    /**
     * AJAX handler for SEO metadata batch import
     * Supports batch processing with progress tracking
     */
    public function ajax_import_seo_metadata()
    {
        // Set execution time limit for batch processing
        $execution_time = $this->get_execution_setting('max_execution_time');
        if (function_exists('set_time_limit')) {
            @set_time_limit($execution_time);
        }
        
        // Apply memory limit if server allows
        $this->apply_memory_limit();
        
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

        $importer = new Metasync_External_Importer($this->db_redirection);

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

    /**
     * Additional context validation for Search Atlas connect tokens.
     *
     * Validates site URL and optional IP/user-agent context for connect tokens
     * used during the Search Atlas API key + Otto UUID retrieval flow.
     * This does NOT create WordPress login sessions.
     */
    private function validate_searchatlas_context($token_data)
    {
        # validate site URL context
        if(isset($token_data['site_url']) && $token_data['site_url'] !== get_site_url()){
        return false;
        }

        # IP address validation (optional - can be disabled for mobile/proxy users)
        /*if(isset($token_data['ip']) && $this->should_validate_ip()){
            $current_ip = $this->get_client_ip();
            if($token_data['ip'] !== $current_ip){
                error_log('MetaSync SA Connect: Validation - IP address changed from ' . $token_data['ip'] . ' to ' . $current_ip);
                # For now, just log the change but don't fail (mobile users, etc.)
                # return false;
            }
        }*/

        # user agent validation (loose check)
        /*if(isset($token_data['user_agent']) && !empty($_SERVER['HTTP_USER_AGENT'])){
            $current_ua = substr($_SERVER['HTTP_USER_AGENT'], 0, 100);
            # Only check if user agents are dramatically different (not just version updates)
            if($this->are_user_agents_incompatible($token_data['user_agent'], $current_ua)){
                error_log('MetaSync SA Connect: Validation - Significant user agent change detected');
                # For now, just log but don't fail (browser updates are common)
                # return false;
            }
        }*/

        return true;
    }

    /**
     * Check if IP validation should be enforced
     */
    private function should_validate_ip()
    {
        # Allow configuration to disable IP validation for mobile users
        # This can be controlled via plugin settings
        $settings = Metasync::get_option('general');
        return isset($settings['enforce_ip_validation']) ? (bool)$settings['enforce_ip_validation'] : false;
    }

    /**
     * Check if user agents are incompatible (not just version differences)
     */
    private function are_user_agents_incompatible($old_ua, $new_ua)
    {
        # Extract browser names (Chrome, Firefox, Safari, etc.)
        $old_browser = $this->extract_browser_name($old_ua);
        $new_browser = $this->extract_browser_name($new_ua);
        
        # If browser names are completely different, consider incompatible
        return $old_browser !== $new_browser && !empty($old_browser) && !empty($new_browser);
    }

    /**
     * Extract browser name from user agent string
     */
    private function extract_browser_name($ua)
    {
        if(stripos($ua, 'Chrome') !== false) return 'Chrome';
        if(stripos($ua, 'Firefox') !== false) return 'Firefox';
        if(stripos($ua, 'Safari') !== false) return 'Safari';
        if(stripos($ua, 'Edge') !== false) return 'Edge';
        if(stripos($ua, 'Opera') !== false) return 'Opera';
        return 'Unknown';
    }

    /**
     * Generate Search Atlas WordPress Connect Token.
     *
     * Returns the Plugin Auth Token used to authenticate with the Search Atlas platform
     * during the 1-click connect flow. This token is used ONLY to retrieve the Search Atlas
     * API key and Otto UUID — it does NOT log anyone into WordPress.
     */
    public function generate_searchatlas_wp_connect_token($regenerate = false){
        # Simplified: Use Plugin Auth Token directly (same as other token functions)
        $general_options = Metasync::get_option('general') ?? [];
        $plugin_auth_token = $general_options['apikey'] ?? '';
        
        if (empty($plugin_auth_token)) {
            error_log('MetaSync ERROR: Plugin Auth Token missing from options - should have been generated during activation');
            return false;
        }

        return $plugin_auth_token;
    }

    /**
     * Ensure Plugin Auth Token exists before Search Atlas connect authentication.
     * Auto-generates if missing to ensure smooth connect flow.
     */
    private function ensure_plugin_auth_token_exists()
    {
        $options = Metasync::get_option();
        $current_plugin_auth_token = $options['general']['apikey'] ?? '';
        
        // Check if Plugin Auth Token is missing or empty
        if (empty($current_plugin_auth_token)) {
            
            // Generate new Plugin Auth Token (alphanumeric only, 32 characters)
            $new_plugin_auth_token = wp_generate_password(32, false, false);
            
            // Initialize options structure if needed
            if (!isset($options['general'])) {
                $options['general'] = [];
            }
            
            // Set the new Plugin Auth Token
            $options['general']['apikey'] = $new_plugin_auth_token;
            
            // Save the options
            $save_result = Metasync::set_option($options);
            
            if ($save_result) {
                // Use centralized API key event logging
                Metasync::log_api_key_event('auto_generated_for_sa_connect', 'plugin_auth_token', array(
                    'new_token_prefix' => substr($new_plugin_auth_token, 0, 8) . '...',
                    'triggered_by' => 'sa_connect_button',
                    'reason' => 'Plugin Auth Token was missing before Search Atlas connect authentication'
                ), 'info');
                
            } else {
                // NEW: Structured error logging with category and code
                global $wpdb;
                if (class_exists('Metasync_Error_Logger') && !empty($wpdb->last_error)) {
                    Metasync_Error_Logger::log(
                        Metasync_Error_Logger::CATEGORY_DATABASE_ERROR,
                        Metasync_Error_Logger::SEVERITY_CRITICAL,
                        'Failed to save plugin auth token to database',
                        [
                            'option_name' => Metasync::option_name,
                            'wpdb_error' => $wpdb->last_error,
                            'wpdb_last_query' => $wpdb->last_query,
                            'operation' => 'ensure_plugin_auth_token_exists',
                            'triggered_by' => 'sso_connect_button'
                        ]
                    );
                }
                
                throw new Exception('Failed to generate required authentication token');
            }
        } else {
        }
    }

    /**
     * Generate Search Atlas Connect URL (1-click connect).
     *
     * Generates a unique, time-limited nonce token and the Search Atlas authentication URL.
     * The admin clicks this URL, authenticates on the Search Atlas dashboard, and SA
     * calls back to /wp-json/metasync/v1/searchatlas/connect/callback with the
     * Search Atlas API key and Otto UUID. This does NOT create a WordPress login session.
     *
     * AJAX action: wp_ajax_generate_searchatlas_connect_url
     */
    public function generate_searchatlas_connect_url()
    {
        // SECURITY FIX (CVE-2025-14386): Strict administrator capability check
        // Only users with 'manage_options' capability (administrators) can initiate connect flow
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions. Administrator access required.'));
            return;
        }
        
        // Verify nonce for CSRF protection
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'metasync_sa_connect_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce - please refresh the page and try again'));
            return;
        }

        // Rate limiting: Prevent Search Atlas connect token enumeration attacks
        $rate_limit_key = 'metasync_sa_connect_rate_' . get_current_user_id();
        $rate_limit_count = get_transient($rate_limit_key);
        if ($rate_limit_count !== false && $rate_limit_count >= 10) {
            wp_send_json_error(array('message' => 'Too many connect requests. Please wait a few minutes before trying again.'));
            return;
        }
        set_transient($rate_limit_key, ($rate_limit_count === false ? 1 : $rate_limit_count + 1), 300);

        try {
            // Ensure Plugin Auth Token exists before starting connect process
            $this->ensure_plugin_auth_token_exists();

            // Generate unique, time-limited, single-use connect token (NOT the persistent apikey)
            $sa_connect_token = $this->create_searchatlas_nonce_token();
            
            if (!$sa_connect_token) {
                wp_send_json_error(array('message' => 'Failed to create authentication token'));
                return;
            }
            
            // Get WordPress domain (without /wp-admin)
            // Remove "www." from the URL in case the site URL includes it
            $domain = str_replace('://www.', '://', get_site_url());
            
            // Get effective dashboard domain
            $dashboard_domain = self::get_effective_dashboard_domain();
            
            // Construct the Search Atlas connect URL with the temporary nonce token.
            // SA will call back to /wp-json/metasync/v1/searchatlas/connect/callback with API key + UUID.
            $sa_connect_url = $dashboard_domain . '/sso/wordpress?' . http_build_query([
                'nonce_token' => $sa_connect_token,
                'domain' => $domain,
                'return_url' => admin_url('admin.php?page=' . self::$page_slug)
            ]);

            // Return the token for polling - token is already in connect URL so no additional exposure
            // The token is time-limited (15 min) and single-use, so safe to return to frontend
            wp_send_json_success(array(
                'connect_url' => $sa_connect_url,
                // BUGFIX: Return full token as 'nonce_token' for polling (not 'token_id')
                // JavaScript polling expects 'nonce_token' and needs full token to match success transient
                'nonce_token' => $sa_connect_token,
                'debug_info' => array(
                    'dashboard_domain' => $dashboard_domain,
                    'site_domain' => $domain,
                    'return_url' => admin_url('admin.php?page=' . self::$page_slug)
                )
            ));

        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Failed to generate Search Atlas connect URL: ' . $e->getMessage()));
        }
    }

    /**
     * Check Search Atlas Connect Status (polling endpoint).
     *
     * Polls to check if the Search Atlas platform has called back with the
     * Search Atlas API key and Otto UUID. Called by the frontend JS after
     * the admin opens the connect URL. Does NOT log anyone into WordPress.
     *
     * AJAX action: wp_ajax_check_searchatlas_connect_status
     */
    public function check_searchatlas_connect_status()
    {
        // SECURITY FIX (CVE-2025-14386): Strict administrator capability check
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions. Administrator access required.'));
            return;
        }
        
        // Verify nonce for CSRF protection
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'metasync_sa_connect_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }

        $nonce_token = isset($_POST['nonce_token']) ? sanitize_text_field(wp_unslash($_POST['nonce_token'])) : '';

        // ✅ NEW: Check if THIS specific nonce was successfully processed
        // This prevents false positives from background sync/heartbeat activity
        $success_key = 'metasync_sa_connect_success_' . md5($nonce_token);
        error_log('MetaSync SA Connect: check_searchatlas_connect_status() checking for key: ' . $success_key);
        $this_auth_completed = get_transient($success_key);
        error_log('MetaSync SA Connect: Transient found: ' . ($this_auth_completed ? 'YES' : 'NO'));
        
        
        if ($this_auth_completed) {
            // Delete the transient (one-time use) to prevent replay
            delete_transient($success_key);
            
            // Get current settings to return API key
            $general_settings = Metasync::get_option('general') ?? [];
            
            wp_send_json_success(array(
                'updated' => true,
                'api_key' => $general_settings['searchatlas_api_key'], // Return full API key
                'otto_pixel_uuid' => $general_settings['otto_pixel_uuid'] ?? '', // ✅ NEW: Return OTTO UUID for UI update
                'status_code' => 200,
                'whitelabel_enabled' => !empty($general_settings['white_label_plugin_name']),
                'effective_domain' => self::get_effective_dashboard_domain()
            ));
        }

        wp_send_json_success(array('updated' => false));
    }

    /**
     * Create Search Atlas Connect Nonce Token.
     *
     * Generates a unique, time-limited (15 min), single-use nonce token used to
     * identify the connect session when Search Atlas calls back with the API key
     * and Otto UUID. This token is NOT the persistent Plugin Auth Token/apikey.
     *
     * SECURITY FIX (CVE-2025-14386): Token is stored in transient and validated on callback.
     */
    private function create_searchatlas_nonce_token()
    {
        // Get existing Plugin Auth Token for HMAC signing
        $general_options = Metasync::get_option('general') ?? [];
        $plugin_auth_token = $general_options['apikey'] ?? '';
        
        // Plugin Auth Token should exist from activation
        if (empty($plugin_auth_token)) {
            error_log('MetaSync ERROR: Plugin Auth Token missing from options');
            return false;
        }

        // Generate a unique, cryptographically secure random token
        $random_bytes = wp_generate_password(32, false, false);
        $timestamp = time();
        $user_id = get_current_user_id();
        
        // Create HMAC-signed token (includes entropy but does NOT expose apikey)
        $token_data = $random_bytes . '|' . $timestamp . '|' . $user_id . '|' . get_site_url();
        $sa_connect_token = hash_hmac('sha256', $token_data, $plugin_auth_token . wp_salt('auth'));
        
        // Create token metadata for validation
        $token_metadata = array(
            'created' => $timestamp,
            'expires' => $timestamp + 900, // 15 minutes (reduced from 30 for security)
            'user_id' => $user_id,
            'site_url' => get_site_url(),
            'ip' => $this->get_client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 100) : '',
            'used' => false, // Single-use flag for user login
            'callback_used' => false, // BUGFIX: Single-use flag for API callback (was missing!)
            'version' => '3.0' // Updated version for new secure token format
        );

        // Store token in transient (expires in 15 minutes)
        // Key is hash of the token to prevent enumeration
        $transient_key = 'metasync_sa_connect_token_' . substr(hash('sha256', $sa_connect_token), 0, 32);
        set_transient($transient_key, $token_metadata, 900);

        // Also store a mapping from the token to allow lookup during validation
        set_transient('metasync_sa_connect_active_' . $sa_connect_token, $transient_key, 900);

        return $sa_connect_token;
    }

    /**
     * Get client IP address securely
     */
    private function get_client_ip()
    {
        $ip_headers = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR');
        
        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated IPs (take first one)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    /**
     * Create encrypted Search Atlas connect token with embedded metadata (optional enhanced approach)
     * Use this for tokens that need to be self-contained
     */
    private function create_encrypted_searchatlas_token($metadata = array())
    {
        // Create comprehensive payload
        $payload = array_merge(array(
            'iat' => time(),                    // Issued at
            'exp' => time() + 1800,            // Expires (30 minutes)
            'iss' => get_site_url(),           // Issuer
            'aud' => 'search-atlas-connect',       // Audience
            'sub' => 'searchatlas-authentication',     // Subject
            'jti' => wp_generate_password(16, false), // Unique ID
            'nonce' => wp_generate_password(16, false),
            'version' => '2.0'
        ), $metadata);

        return $this->wp_encrypt_token($payload);
    }

    /**
     * Encrypt token using WordPress SALTs
     */
    private function wp_encrypt_token($payload)
    {
        try {
            // Serialize the payload
            $serialized = serialize($payload);
            
            // Create encryption key from multiple WordPress SALTs
            $key_material = wp_salt('secure_auth') . wp_salt('logged_in') . wp_salt('nonce');
            $encryption_key = hash('sha256', $key_material, true);
            
            // Generate random IV for each encryption
            $iv = random_bytes(16);
            
            // Encrypt using AES-256-CBC
            $encrypted = openssl_encrypt($serialized, 'AES-256-CBC', $encryption_key, OPENSSL_RAW_DATA, $iv);
            
            if ($encrypted === false) {
                throw new Exception('Encryption failed');
            }
            
            // Combine IV + encrypted data for transport
            $result = $iv . $encrypted;
            
            // Base64 encode for safe transport
            return base64_encode($result);
            
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Test the enhanced Search Atlas connect token system (development/debugging)
     */
    public function test_enhanced_searchatlas_tokens()
    {
        // SECURITY FIX: Use proper capability check instead of role name
        if (!current_user_can('manage_options')) {
            return false;
        }


        
        # Test 1: Using apikey for backward compatibility
        $general_options = Metasync::get_option('general') ?? [];
        $test_token = $general_options['apikey'] ?? null;
        
        # Test 2: Apikey validation
        $apikey = $general_options['apikey'] ?? '';
        if ($apikey) {
        } else {
        }
        
        # Test 3: Encrypted token test
        $encrypted_token = $this->create_encrypted_searchatlas_token(['test' => 'data', 'user_id' => get_current_user_id()]);
        if ($encrypted_token) {
            
            $decrypted = $this->wp_decrypt_token($encrypted_token);
            if ($decrypted) {
            } else {
            }
        } else {
        }
        

        return true;
    }

    /**
     * Test Search Atlas connect AJAX endpoint (development/debugging)
     * Simple test to verify AJAX connectivity and endpoint registration
     */
    public function test_searchatlas_ajax_endpoint()
    {
        // SECURITY FIX (CVE-2025-14386): Strict administrator capability check for Search Atlas connect test endpoint
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => 'Insufficient permissions for AJAX test',
                'required_capability' => 'manage_options'
            ));
            return;
        }
        
        // Check if nonce is provided and valid
        $nonce_valid = false;
        if (isset($_POST['nonce'])) {
            $nonce_valid = wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'metasync_sa_connect_nonce');
        }
        
        wp_send_json_success(array(
            'message' => 'AJAX endpoint is working correctly',
            'timestamp' => current_time('mysql', true),
            'user_id' => get_current_user_id(),
            'endpoint' => 'test_searchatlas_ajax_endpoint',
            'nonce_valid' => $nonce_valid,
            'debug_info' => array(
                'post_action' => isset($_POST['action']) ? sanitize_text_field(wp_unslash($_POST['action'])) : 'NOT SET',
                'has_nonce' => isset($_POST['nonce']),
                'user_can_manage_options' => current_user_can('manage_options')
            )
        ));
    }

    /**
     * Simple AJAX test without nonce (for debugging connectivity)
     */
    public function simple_ajax_test()
    {
        wp_send_json_success(array(
            'message' => 'Basic AJAX connectivity works',
            'timestamp' => time(),
            'no_nonce_required' => true
        ));
    }

    /**
     * Create Admin Dashboard Iframe Page
     * Embeds the Search Atlas dashboard directly in WordPress admin
     */
    public function create_admin_dashboard_iframe()
    {
        // Get general options for authentication check and UUID
        $general_options = Metasync::get_option('general');
        $otto_pixel_uuid = isset($general_options['otto_pixel_uuid']) ? $general_options['otto_pixel_uuid'] : '';
        $api_key = isset($general_options['searchatlas_api_key']) ? $general_options['searchatlas_api_key'] : '';
        
        # Check if dashboard framework is hidden via settings
		$hide_dashboard = $general_options['hide_dashboard_framework'] ?? false;
        
        # if dashboard is hidden, show message and exit
        if ($hide_dashboard) {
            ?>
                <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
                <?php $this->render_plugin_header('Dashboard'); ?>
                
                <?php $this->render_navigation_menu('dashboard'); ?>
                
                <div class="dashboard-card">
                    <h2>📊 Dashboard Disabled</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">
                        The dashboard is currently Disabled.
                    </p>
                </div>
            </div>
            <?php
            return;
        }

        // Check if user is properly connected via heartbeat
        if (!$this->is_heartbeat_connected()) {
            ?>
                <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
                <?php $this->render_plugin_header('Dashboard'); ?>
                
                <?php $this->render_navigation_menu('dashboard'); ?>

                <div class="dashboard-card">
                    <h2>🚀 Setup Wizard</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Run the setup wizard to configure your plugin, import from other SEO plugins, and optimize your settings in just a few minutes.</p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::$page_slug . '-setup-wizard')); ?>" class="button button-primary" style="text-decoration: none;">
                        ✨ Start Setup Wizard
                    </a>
                </div>
                
                <div class="dashboard-card">
                    <h2>⚠️ Authentication Required</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">
                        You need to authenticate with <?php echo esc_html(Metasync::get_effective_plugin_name()); ?> to access the dashboard.
                    </p>
                    <a href="<?php echo admin_url('admin.php?page=' . self::$page_slug); ?>" class="button button-primary">
                        🔗 Go to Settings & Connect
                    </a>
                </div>
            </div>
            <?php
            return;
        }
        
        // Get JWT token first (needed to fetch public hash)
        $jwt_token = $this->get_fresh_jwt_token();
        
        // Log JWT token details for debugging
        if ($jwt_token) {
            $jwt_parts = explode('.', $jwt_token);
            $jwt_header_info = count($jwt_parts) >= 2 ? 'Valid format (' . count($jwt_parts) . ' parts)' : 'Invalid format';
        } else {
        }
        
        // Fetch public hash for public dashboard access
        $public_hash = false;
        if ($jwt_token && $otto_pixel_uuid) {
            $public_hash = $this->fetch_public_hash($otto_pixel_uuid, $jwt_token);
            
            if ($public_hash) {
            } else {
            }
        } else {
            $missing_params = [];
            if (empty($jwt_token)) $missing_params[] = 'JWT_TOKEN';
            if (empty($otto_pixel_uuid)) $missing_params[] = 'OTTO_UUID';
        }
        
        // Build the dashboard iframe URL using public or private endpoint
        $dashboard_domain = self::get_effective_dashboard_domain();
        
        if ($public_hash) {
            // Use public dashboard endpoint with public hash
            $iframe_url = $dashboard_domain . '/seo-automation-v3/public?uuid=' . urlencode($otto_pixel_uuid) 
                        . '&category=onpage_optimizations&subGroup=page_title&public_hash=' . urlencode($public_hash);
        } else {
            // Fallback to private dashboard endpoint with JWT token
            $iframe_url = $dashboard_domain . '/seo-automation-v3/tasks?uuid=' . urlencode($otto_pixel_uuid) . '&category=All&Embed=True';
            if ($jwt_token) {
                $iframe_url .= '&jwtToken=' . urlencode($jwt_token) . '&impersonate=1';
            } else {
            }
        }
        
        // Add source tracking
        $iframe_url .= '&source=wordpress-plugin-iframe';
        
        // Add whitelabel identification if applicable
        $whitelabel_company_name = Metasync::get_whitelabel_company_name();
        if ($whitelabel_company_name) {
            $iframe_url .= '&whitelabel=' . urlencode($whitelabel_company_name);
        }
        

        
        // Configure iframe restrictions for whitelabel domains
        $whitelabel_settings = Metasync::get_whitelabel_settings();
        $is_whitelabel_domain = !empty($whitelabel_settings['domain']);
        

        
        ?>
            <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
            <?php $this->render_plugin_header('Dashboard'); ?>
            
            <?php $this->render_navigation_menu('dashboard'); ?>
            
            <iframe id="metasync-dashboard-iframe"
                    src="<?php echo esc_url($iframe_url); ?>"
                    width="100%"
                    height="100vh"
                    frameborder="0"
                    <?php if (!$is_whitelabel_domain): ?>
                    allow="cookies"
                    referrerpolicy="strict-origin-when-cross-origin"
                    <?php endif; ?>
                    style="border: none; margin: 0; padding: 0; min-height: 800px;"
                    onload="adjustIframeHeight(this)">
            </iframe>
            
            <script>
            function adjustIframeHeight(iframe) {
                var attempts = 0;
                var maxAttempts = 20; // Try for up to 10 seconds
                
                function tryAdjustHeight() {
                    try {
                        attempts++;
                        
                        // Try to access iframe content height
                        var iframeDocument = iframe.contentDocument || iframe.contentWindow.document;
                        if (iframeDocument) {
                            // Wait for content to load by checking if body has meaningful content
                            var body = iframeDocument.body;
                            var hasContent = body && (body.children.length > 1 || body.innerText.trim().length > 100);
                            
                            if (!hasContent && attempts < maxAttempts) {
                                // Content still loading, try again
                                setTimeout(tryAdjustHeight, 500);
                                return;
                            }
                            
                            var height = Math.max(
                                body ? body.scrollHeight : 0,
                                body ? body.offsetHeight : 0,
                                iframeDocument.documentElement.clientHeight,
                                iframeDocument.documentElement.scrollHeight,
                                iframeDocument.documentElement.offsetHeight
                            );
                            
                            // Only apply if we got a reasonable height
                            if (height > 600) {
                                iframe.style.height = height + 'px';
                            } else if (attempts < maxAttempts) {
                                // Height too small, content probably still loading
                                setTimeout(tryAdjustHeight, 500);
                                return;
                            }
                        } else {
                            // Can't access content, try again or fallback
                            if (attempts < maxAttempts) {
                                setTimeout(tryAdjustHeight, 500);
                                return;
                            }
                        }
                    } catch (e) {
                        // Cross-origin restrictions - use viewport height
                        iframe.style.height = '100vh';
                    }
                }
                
                // Start the height adjustment process
                tryAdjustHeight();
            }
            
            // Also listen for window resize
            window.addEventListener('resize', function() {
                var iframe = document.getElementById('metasync-dashboard-iframe');
                if (iframe) {
                    adjustIframeHeight(iframe);
                }
            });
            
            // Additional attempt after 3 seconds (for very slow loading apps)
            setTimeout(function() {
                var iframe = document.getElementById('metasync-dashboard-iframe');
                if (iframe) {
                    adjustIframeHeight(iframe);
                }
            }, 3000);
            </script>
        </div>
        <?php
    }

    /**
     * Render OTTO cache management interface
     */
    private function render_otto_cache_management()
    {
        // Check if OTTO transient cache class exists
        if (!class_exists('Metasync_Otto_Transient_Cache')) {
            echo '<div class="notice notice-error inline"><p>';
            echo '❌ <strong>Error:</strong> Transient Cache class not found.';
            echo '</p></div>';
            return;
        }
        
        // Get cache count
        $cache_count = Metasync_Otto_Transient_Cache::get_cache_count();
        
        ?>
        <!-- Cache Plugin Management -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);">Clear All Cache Plugins</h4>
            <p style="margin-bottom: 15px; color: var(--dashboard-text-secondary);">Clear all cache plugins to ensure changes are visible immediately.</p>

            <?php
            // Display active cache plugins
            if (class_exists('Metasync_Cache_Purge')) {
                try {
                    $cache_purge = Metasync_Cache_Purge::get_instance();
                    $active_cache_plugins = $cache_purge->get_active_cache_plugins();

                    if (!empty($active_cache_plugins)) {
                        echo '<p style="color: var(--dashboard-text-primary);"><strong>Active Cache Plugins Detected:</strong></p>';
                        echo '<ul style="margin-bottom: 15px; color: var(--dashboard-text-primary);">';
                        foreach ($active_cache_plugins as $plugin_name) {
                            echo '<li>✅ ' . esc_html($plugin_name) . '</li>';
                        }
                        echo '</ul>';
                    } else {
                        echo '<p style="color: var(--dashboard-text-secondary);">ℹ️ No cache plugins detected.</p>';
                    }
                } catch (Exception $e) {
                    echo '<p style="color: var(--dashboard-error);">⚠️ Error: ' . esc_html($e->getMessage()) . '</p>';
                }
            } else {
                echo '<p style="color: var(--dashboard-error);">⚠️ Cache Purge class not loaded.</p>';
            }
            ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 15px;">
                <input type="hidden" name="action" value="metasync_clear_all_cache_plugins" />
                <?php wp_nonce_field('metasync_clear_cache_nonce', 'clear_cache_nonce'); ?>
                <button type="submit" class="metasync-btn-primary" style="background: var(--dashboard-gradient-primary); color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); display: inline-block; width: auto; min-width: 240px; max-width: fit-content;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0, 0, 0, 0.15)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0, 0, 0, 0.1)';">
                    🔄 Clear All Cache Plugins
                </button>
                <p class="description" style="margin-top: 10px; color: var(--dashboard-text-secondary);">This will clear cache from WP Rocket, LiteSpeed, W3 Total Cache, and all other detected cache plugins.</p>
            </form>

            <?php
            // Display success/error messages
            if (isset($_GET['cache_cleared']) && $_GET['cache_cleared'] == '1') {
                $cleared = isset($_GET['cleared']) ? intval($_GET['cleared']) : 0;
                $failed = isset($_GET['failed']) ? intval($_GET['failed']) : 0;
                $plugins = isset($_GET['plugins']) ? sanitize_text_field($_GET['plugins']) : '';

                if ($cleared > 0) {
                    echo '<div class="notice notice-success inline" style="margin-top: 15px;"><p>';
                    echo '✅ <strong>Success!</strong> Cleared cache for ' . $cleared . ' plugin(s)';
                    if ($plugins) {
                        echo ': ' . esc_html($plugins);
                    }
                    echo '</p></div>';
                } else {
                    echo '<div class="notice notice-info inline" style="margin-top: 15px;"><p>';
                    echo 'ℹ️ No cache plugins found to clear. WordPress object cache was cleared.';
                    echo '</p></div>';
                }

                if ($failed > 0) {
                    echo '<div class="notice notice-warning inline" style="margin-top: 15px;"><p>';
                    echo '⚠️ Failed to clear ' . $failed . ' plugin(s).';
                    echo '</p></div>';
                }
            }

            if (isset($_GET['cache_error']) && $_GET['cache_error'] == '1') {
                $message = isset($_GET['message']) ? urldecode(sanitize_text_field($_GET['message'])) : '';
                if (empty($message)) {
                    $message = 'An unknown error occurred while clearing cache. Please check error logs for details.';
                }
                echo '<div class="notice notice-error inline" style="margin-top: 15px;"><p>';
                echo '❌ <strong>Error clearing cache:</strong> ' . esc_html($message);
                echo '</p></div>';
            }
            ?>
        </div>

        <!-- OTTO Transient Cache -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);"><?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> Transient Cache</h4>
            <p style="margin-bottom: 15px; color: var(--dashboard-text-secondary);">
                Manage <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> suggestions cache. Clearing cache will force fresh API calls on next page load.
            </p>

            <div style="background: rgba(255, 255, 255, 0.05); padding: 12px; border-radius: 4px; margin-bottom: 20px; border: 1px solid var(--dashboard-border);">
                <strong style="color: var(--dashboard-text-primary);">Current Cache Status:</strong>
                <span style="color: var(--dashboard-accent);"><?php echo esc_html($cache_count); ?> cached entries</span>
            </div>
            
            <?php
            // Display success/error messages
            if (isset($_GET['otto_cache_cleared']) && $_GET['otto_cache_cleared'] == '1') {
                $cleared_count = isset($_GET['count']) ? intval($_GET['count']) : 0;
                $url = isset($_GET['url']) ? urldecode(sanitize_text_field($_GET['url'])) : '';
                
                echo '<div class="notice notice-success inline" style="margin-top: 15px;"><p>';
                if (!empty($url)) {
                    echo '✅ <strong>Success!</strong> Cleared cache for URL: <code>' . esc_html($url) . '</code> (' . $cleared_count . ' entries)';
                } else {
                    echo '✅ <strong>Success!</strong> Cleared entire transient cache (' . $cleared_count . ' entries)';
                }
                echo '</p></div>';
            }
            
            if (isset($_GET['otto_cache_error']) && $_GET['otto_cache_error'] == '1') {
                $message = isset($_GET['message']) ? urldecode(sanitize_text_field($_GET['message'])) : 'An unknown error occurred.';
                echo '<div class="notice notice-error inline" style="margin-top: 15px;"><p>';
                echo '❌ <strong>Error:</strong> ' . esc_html($message);
                echo '</p></div>';
            }
            ?>
            
            <!-- Clear Entire Cache -->
            <div style="margin-bottom: 30px; padding: 20px; border: 1px solid var(--dashboard-border); border-radius: 4px; background: rgba(255, 255, 255, 0.02);">
                <h5 style="margin-top: 0; color: var(--dashboard-text-primary);">Clear Entire Transient Cache</h5>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 15px;">
                    This will clear all <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> transient cache entries (suggestions, locks, stale cache, rate limits).
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      onsubmit="return confirm('Are you sure you want to clear the entire transient cache? This will force fresh API calls for all URLs.');">
                    <input type="hidden" name="action" value="metasync_clear_otto_cache_all" />
                    <?php wp_nonce_field('metasync_clear_otto_cache_nonce', 'clear_otto_cache_nonce'); ?>
                    <button type="submit" class="metasync-btn-primary" style="background: var(--dashboard-gradient-primary); color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); display: inline-block; width: auto; min-width: 240px; max-width: fit-content;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0, 0, 0, 0.15)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0, 0, 0, 0.1)';">
                        🗑️ Clear Entire Cache
                    </button>
                </form>
            </div>
            
            <!-- Clear Cache by URL -->
            <div style="padding: 20px; border: 1px solid var(--dashboard-border); border-radius: 4px; background: rgba(255, 255, 255, 0.02);">
                <h5 style="margin-top: 0; color: var(--dashboard-text-primary);">Clear Cache by URL</h5>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 15px;">
                    Enter a specific URL to clear its cached <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> suggestions. Use the full URL including protocol (e.g., https://example.com/page/).
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="metasync_clear_otto_cache_url" />
                    <?php wp_nonce_field('metasync_clear_otto_cache_nonce', 'clear_otto_cache_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="otto_cache_url" style="color: var(--dashboard-text-primary);">URL to Clear</label>
                            </th>
                            <td>
                                <input type="url"
                                       id="otto_cache_url"
                                       name="otto_cache_url"
                                       value="<?php echo isset($_GET['url']) ? esc_attr(urldecode(sanitize_text_field($_GET['url']))) : ''; ?>"
                                       class="regular-text"
                                       placeholder="https://example.com/page/"
                                       required />
                                <p class="description" style="color: var(--dashboard-text-secondary);">Enter the full URL of the page whose cache you want to clear.</p>
                            </td>
                        </tr>
                    </table>
                    <button type="submit" class="metasync-btn-primary" style="background: var(--dashboard-gradient-primary); color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); display: inline-block; width: auto; max-width: fit-content;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0, 0, 0, 0.15)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0, 0, 0, 0.1)';">
                        🗑️ Clear Cache for URL
                    </button>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render debug mode section for inclusion in Advanced settings
     */
    private function render_debug_mode_section()
    {
        // Check if debug mode manager class exists
        if (!class_exists('Metasync_Debug_Mode_Manager')) {
            ?>
            <div style="background: rgba(255, 193, 7, 0.1); border: 1px solid rgba(255, 193, 7, 0.3); border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                <p style="color: var(--dashboard-text-primary); margin: 0;">
                    ⚠️ Debug Mode Manager is not available. Please ensure the plugin is properly installed.
                </p>
            </div>
            <?php
            return;
        }

        $debug_manager = Metasync_Debug_Mode_Manager::get_instance();
        $status = $debug_manager->get_status();
        ?>

        <!-- Debug Mode Status Overview -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);">Current Status</h4>
            <div style="background: rgba(255, 255, 255, 0.05); border: 1px solid var(--dashboard-border); padding: 20px; border-radius: 8px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <!-- Status Badge -->
                    <div style="padding: 10px; border-left: 4px solid <?php echo $status['enabled'] ? '#ffc107' : '#4caf50'; ?>; background: rgba(<?php echo $status['enabled'] ? '255, 193, 7' : '76, 175, 80'; ?>, 0.1); border-radius: 4px;">
                        <div style="font-size: 12px; color: var(--dashboard-text-secondary); margin-bottom: 5px;">Status</div>
                        <div style="font-weight: 600; color: var(--dashboard-text-primary); font-size: 16px;">
                            <?php echo $status['enabled'] ? '⚠️ Active' : '✓ Inactive'; ?>
                        </div>
                    </div>

                    <?php if ($status['enabled']): ?>
                    <!-- Mode Type -->
                    <div style="padding: 10px; border-left: 4px solid #2196f3; background: rgba(33, 150, 243, 0.1); border-radius: 4px;">
                        <div style="font-size: 12px; color: var(--dashboard-text-secondary); margin-bottom: 5px;">Mode</div>
                        <div style="font-weight: 600; color: var(--dashboard-text-primary); font-size: 14px;">
                            <?php echo $status['indefinite'] ? 'Indefinite' : '24-Hour Auto-Disable'; ?>
                        </div>
                    </div>

                    <?php if (!$status['indefinite']): ?>
                    <!-- Time Remaining -->
                    <div style="padding: 10px; border-left: 4px solid #9c27b0; background: rgba(156, 39, 176, 0.1); border-radius: 4px;">
                        <div style="font-size: 12px; color: var(--dashboard-text-secondary); margin-bottom: 5px;">Time Remaining</div>
                        <div style="font-weight: 600; color: var(--dashboard-text-primary); font-size: 14px;" class="debug-time-remaining">
                            <?php echo esc_html($status['time_remaining_formatted']); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Log File Size -->
                    <div style="padding: 10px; border-left: 4px solid #ff5722; background: rgba(255, 87, 34, 0.1); border-radius: 4px;">
                        <div style="font-size: 12px; color: var(--dashboard-text-secondary); margin-bottom: 5px;">Log File Size</div>
                        <div style="font-weight: 600; color: var(--dashboard-text-primary); font-size: 14px;">
                            <?php echo esc_html($status['log_file_size_formatted']); ?>
                        </div>
                        <div style="font-size: 11px; color: var(--dashboard-text-secondary); margin-top: 2px;">
                            <?php echo number_format($status['percentage_used'], 1); ?>% of <?php echo esc_html($status['max_log_size_formatted']); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($status['enabled']): ?>
                <!-- Progress Bar -->
                <div style="margin-top: 15px;">
                    <div style="background: rgba(255, 255, 255, 0.1); height: 8px; border-radius: 4px; overflow: hidden;">
                        <div style="height: 100%; width: <?php echo esc_attr($status['percentage_used']); ?>%; background: linear-gradient(90deg, #4caf50 0%, #ffc107 70%, #f44336 100%); transition: width 0.3s ease;"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Debug Mode Controls -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);">Controls</h4>

            <form method="post" action="<?php echo admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced'); ?>">
                <input type="hidden" name="metasync_debug_mode_action_advanced" value="1" />
                <?php wp_nonce_field('metasync_debug_mode_action_advanced', 'metasync_debug_mode_nonce_advanced'); ?>

                <?php if (!$status['enabled']): ?>
                    <!-- Enable Debug Mode -->
                    <div style="background: rgba(255, 255, 255, 0.05); border: 1px solid var(--dashboard-border); padding: 20px; border-radius: 8px; margin-bottom: 15px;">
                        <p style="color: var(--dashboard-text-secondary); margin-top: 0; margin-bottom: 15px;">
                            Activate debug mode to troubleshoot issues. Debug mode will automatically disable after 24 hours unless you enable indefinite mode.
                        </p>

                        <label style="display: flex; align-items: center; margin: 15px 0; cursor: pointer;">
                            <input type="checkbox" name="indefinite" value="1" id="indefinite-mode-advanced" style="margin-right: 8px;" />
                            <span style="font-weight: 500; color: var(--dashboard-text-primary);">Keep debug mode enabled indefinitely</span>
                        </label>

                        <div id="indefinite-warning-advanced" style="display: none; background: rgba(255, 193, 7, 0.1); border-left: 4px solid #ffc107; padding: 12px; border-radius: 4px; margin: 15px 0;">
                            <strong style="color: var(--dashboard-text-primary);">⚠️ Warning:</strong>
                            <span style="color: var(--dashboard-text-secondary);"> Indefinite debug mode may cause log files to grow without limits. This should only be used for extended troubleshooting sessions.</span>
                        </div>

                        <input type="hidden" name="action_type" value="enable" />
                        <button type="submit" class="metasync-btn-primary" style="background: var(--dashboard-gradient-primary); color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); display: inline-block; width: auto; min-width: 200px;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0, 0, 0, 0.15)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0, 0, 0, 0.1)';">
                            🐛 Enable Debug Mode
                        </button>
                    </div>

                <?php else: ?>
                    <!-- Manage Active Debug Mode -->
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <?php if (!$status['indefinite']): ?>
                        <div>
                            <input type="hidden" name="action_type" value="extend" />
                            <button type="submit" class="metasync-btn-secondary" style="background: rgba(255, 255, 255, 0.1); color: var(--dashboard-text-primary); border: 1px solid var(--dashboard-border); padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; display: inline-block; width: auto;" onmouseover="this.style.background='rgba(255, 255, 255, 0.15)';" onmouseout="this.style.background='rgba(255, 255, 255, 0.1)';">
                                ⏱️ Extend for 24 Hours
                            </button>
                        </div>
                        <?php endif; ?>

                        <div>
                            <input type="hidden" name="action_type" value="disable" />
                            <button type="submit" class="metasync-btn-danger" onclick="return confirm('Are you sure you want to disable debug mode?');" style="background: linear-gradient(135deg, #f44336, #d32f2f); color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); display: inline-block; width: auto;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0, 0, 0, 0.15)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0, 0, 0, 0.1)';">
                                ⏹️ Disable Debug Mode Now
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Configuration Details -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);">Configuration Details</h4>
            <div style="background: rgba(255, 255, 255, 0.05); border: 1px solid var(--dashboard-border); border-radius: 8px; padding: 20px;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tbody>
                        <tr style="border-bottom: 1px solid var(--dashboard-border);">
                            <td style="padding: 10px 0; font-weight: 600; color: var(--dashboard-text-primary);">Maximum Log Size</td>
                            <td style="padding: 10px 0; color: var(--dashboard-text-secondary);"><?php echo esc_html($status['max_log_size_formatted']); ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid var(--dashboard-border);">
                            <td style="padding: 10px 0; font-weight: 600; color: var(--dashboard-text-primary);">Auto-Disable Duration</td>
                            <td style="padding: 10px 0; color: var(--dashboard-text-secondary);">24 hours</td>
                        </tr>
                        <tr style="border-bottom: 1px solid var(--dashboard-border);">
                            <td style="padding: 10px 0; font-weight: 600; color: var(--dashboard-text-primary);">Log Rotation</td>
                            <td style="padding: 10px 0; color: var(--dashboard-text-secondary);">Automatic when size limit reached</td>
                        </tr>
                        <tr style="border-bottom: 1px solid var(--dashboard-border);">
                            <td style="padding: 10px 0; font-weight: 600; color: var(--dashboard-text-primary);">Rotated Files Kept</td>
                            <td style="padding: 10px 0; color: var(--dashboard-text-secondary);">1 (current + 1 old)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid var(--dashboard-border);">
                            <td style="padding: 10px 0; font-weight: 600; color: var(--dashboard-text-primary);">Check Frequency</td>
                            <td style="padding: 10px 0; color: var(--dashboard-text-secondary);">Hourly (via WP Cron)</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px 0; font-weight: 600; color: var(--dashboard-text-primary);">Log File Path</td>
                            <td style="padding: 10px 0; color: var(--dashboard-text-secondary); font-family: monospace; font-size: 12px; word-break: break-all;">
                                <?php echo esc_html($status['log_file_path']); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Show warning when indefinite mode is checked
            $('#indefinite-mode-advanced').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#indefinite-warning-advanced').slideDown();
                } else {
                    $('#indefinite-warning-advanced').slideUp();
                }
            });

            // Auto-update time remaining every minute
            <?php if ($status['enabled'] && !$status['indefinite']): ?>
            var initialTimeRemaining = <?php echo $status['time_remaining']; ?>;
            var hasReloaded = false;

            function updateDebugTimeRemaining() {
                // Don't make AJAX calls if we already know it was expired on page load
                if (initialTimeRemaining <= 0 || hasReloaded) {
                    return;
                }

                $.ajax({
                    url: '<?php echo rest_url('metasync/v1/debug-mode/status'); ?>',
                    method: 'GET',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
                    },
                    success: function(response) {
                        console.log('MetaSync Debug Mode Status:', response);

                        if (response && typeof response.time_remaining !== 'undefined') {
                            // Update the display
                            if (response.time_remaining_formatted) {
                                $('.debug-time-remaining').text(response.time_remaining_formatted);
                            }

                            // Only reload if debug mode just expired (was active, now expired)
                            // AND this is a state change (wasn't already expired)
                            if (response.time_remaining <= 0 && initialTimeRemaining > 0 && !hasReloaded) {
                                hasReloaded = true;
                                console.log('Debug mode expired, reloading page...');
                                setTimeout(function() {
                                    window.location.reload();
                                }, 2000);
                            }
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('MetaSync Debug Mode: Failed to update time remaining', error);
                    }
                });
            }

            // Only start AJAX updates if debug mode is not already expired
            if (initialTimeRemaining > 0) {
                // Update immediately on page load to ensure fresh data
                setTimeout(updateDebugTimeRemaining, 2000);

                // Then update every 60 seconds
                setInterval(updateDebugTimeRemaining, 60000);
            } else {
                console.log('Debug mode already expired, skipping AJAX updates');
            }
            <?php endif; ?>
        });
        </script>
        <?php
    }

    /**
     * Render error log content for inclusion in Advanced settings
     */
    private function render_error_log_content()
    {
        ?>
        <!-- Error Summary Section -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);">📊 Error Summary</h4>
            <p style="margin-bottom: 15px; color: var(--dashboard-text-secondary);">View categorized error statistics with counts and last occurrence times.</p>
            
            <?php
            if (class_exists('Metasync_Error_Logger')) {
                $error_summary = Metasync_Error_Logger::get_error_summary();
                
                if (!empty($error_summary) && is_array($error_summary)) {
                    // Sort by last_seen (newest first)
                    uasort($error_summary, function($a, $b) {
                        return strtotime($b['last_seen']) - strtotime($a['last_seen']);
                    });
                    ?>
                    <div style="overflow-x: auto; margin-bottom: 20px;">
                        <table class="wp-list-table widefat fixed striped" style="background: var(--dashboard-card-bg); border: 1px solid var(--dashboard-border);">
                            <thead>
                                <tr style="background: var(--dashboard-card-bg);">
                                    <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--dashboard-border); color: var(--dashboard-text-primary); font-weight: 600;">Error Category</th>
                                    <th style="padding: 12px; text-align: center; border-bottom: 2px solid var(--dashboard-border); color: var(--dashboard-text-primary); font-weight: 600;">Error Code</th>
                                    <th style="padding: 12px; text-align: center; border-bottom: 2px solid var(--dashboard-border); color: var(--dashboard-text-primary); font-weight: 600;">Count</th>
                                    <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--dashboard-border); color: var(--dashboard-text-primary); font-weight: 600;">Last Occurred</th>
                                    <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--dashboard-border); color: var(--dashboard-text-primary); font-weight: 600;">Message</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($error_summary as $key => $error): ?>
                                    <tr>
                                        <td style="padding: 10px 12px; color: var(--dashboard-text-primary);">
                                            <strong><?php echo esc_html($error['category']); ?></strong>
                                        </td>
                                        <td style="padding: 10px 12px; text-align: center; color: var(--dashboard-text-secondary); font-family: monospace;">
                                            <code style="background: rgba(255, 255, 255, 0.1); padding: 2px 6px; border-radius: 3px;"><?php echo esc_html($error['code']); ?></code>
                                        </td>
                                        <td style="padding: 10px 12px; text-align: center;">
                                            <span style="display: inline-block; background: var(--dashboard-accent); color: #ffffff; padding: 4px 10px; border-radius: 12px; font-weight: 600; font-size: 13px;">
                                                <?php echo esc_html(number_format($error['count'])); ?>
                                            </span>
                                        </td>
                                        <td style="padding: 10px 12px; color: var(--dashboard-text-secondary); font-size: 13px;">
                                            <?php 
                                            $last_seen = strtotime($error['last_seen']);
                                            $time_diff = human_time_diff($last_seen, current_time('timestamp'));
                                            echo esc_html($error['last_seen']) . ' <span style="color: var(--dashboard-text-secondary);">(' . $time_diff . ' ago)</span>';
                                            ?>
                                        </td>
                                        <td style="padding: 10px 12px; color: var(--dashboard-text-primary); max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo esc_attr($error['message']); ?>">
                                            <?php echo esc_html($error['message']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <form method="post" action="<?php echo admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced'); ?>" style="margin-bottom: 20px;">
                        <input type="hidden" name="clear_error_summary" value="yes" />
                        <?php wp_nonce_field('metasync_clear_error_summary_nonce', 'clear_error_summary_nonce'); ?>
                        <button type="submit" class="button button-secondary" style="background: #dc3232; color: #ffffff; border: none; padding: 8px 16px; border-radius: 4px; font-weight: 500; cursor: pointer;">
                            🗑️ Clear Error Summary
                        </button>
                    </form>
                    <?php
                } else {
                    ?>
                    <div class="dashboard-empty-state" style="padding: 30px; text-align: center; background: var(--dashboard-card-bg); border: 1px solid var(--dashboard-border); border-radius: 8px;">
                        <p style="color: var(--dashboard-text-secondary); font-style: italic; margin: 0;">
                            ✅ No errors recorded yet. Error summary will appear here once errors are logged.
                        </p>
                    </div>
                    <?php
                }
            } else {
                ?>
                <div class="dashboard-empty-state" style="padding: 30px; text-align: center; background: rgba(255, 193, 7, 0.1); border: 1px solid rgba(255, 193, 7, 0.3); border-radius: 8px;">
                    <p style="color: var(--dashboard-text-primary); margin: 0;">
                        ⚠️ Error Logger class not available. Please ensure the plugin is properly loaded.
                    </p>
                </div>
                <?php
            }
            ?>
        </div>

        <!-- Error Log Management -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);">Clear Error Logs</h4>
            <p style="margin-bottom: 15px; color: var(--dashboard-text-secondary);">Clear WordPress error logs to free up space and remove old entries.</p>
            
            <form method="post" action="<?php echo admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced'); ?>" style="margin-bottom: 20px;">
                <input type="hidden" name="clear_log" value="yes" />
                <?php wp_nonce_field('metasync_clear_log_nonce', 'clear_log_nonce'); ?>
                <button type="submit" class="metasync-btn-primary" style="background: var(--dashboard-gradient-primary); color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); display: inline-block; width: auto; min-width: 240px; max-width: fit-content;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0, 0, 0, 0.15)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0, 0, 0, 0.1)';">
                    🧹 Clear Error Logs
                </button>
            </form>
        </div>

        <!-- WordPress Debug Settings -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);">WordPress Debug Configuration</h4>
            <p style="margin-bottom: 15px; color: var(--dashboard-text-secondary);">Configure WordPress debug settings to control error logging and display.</p>

            <form method="post">
                
                <?php
                $wp_debug = defined('WP_DEBUG') && WP_DEBUG;
                $debug_log = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
                $debug_display = defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY;
                ?>
                
                <p><strong>Current WordPress Debug Status:</strong></p>
                <ul>
                    <li>WP_DEBUG: <?php echo $wp_debug ? '✅ Enabled' : '❌ Disabled'; ?></li>
                    <li>WP_DEBUG_LOG: <?php echo $debug_log ? '✅ Enabled' : '❌ Disabled'; ?></li>
                    <li>WP_DEBUG_DISPLAY: <?php echo $debug_display ? '✅ Enabled' : '❌ Disabled'; ?></li>
                </ul>
                
                <?php if (!$wp_debug): ?>
                <p style="color: var(--dashboard-accent);">💡 To enable error logging, add these lines to your wp-config.php file:</p>
                <pre style="background: rgba(255, 255, 255, 0.05); border: 1px solid var(--dashboard-border); padding: 10px; border-radius: 4px; color: var(--dashboard-text-primary);">define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);</pre>
                <?php endif; ?>
            </form>
        </div>

        <!-- Error Log Display -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);">Error Log Contents</h4>
            <p style="margin-bottom: 15px; color: var(--dashboard-text-secondary);">View the current error log entries for troubleshooting and monitoring.</p>
            
            <?php
            $error_logs = new Metasync_Error_Logs();

            if ($error_logs->can_show_error_logs()): 
                // Check if there's actual content in the logs
                $log_content = $error_logs->get_error_logs(50);
                
                if (!empty(trim($log_content))):
                    // Show copy button if logs are available
                    $error_logs->show_copy_button();
                    // Display the error logs
                    $error_logs->show_logs();
                    $error_logs->show_info();
                else: ?>
                    <div class="dashboard-empty-state">
                        <p style="color: var(--dashboard-text-secondary); font-style: italic; text-align: center; padding: 40px 20px;">✅ Log file is empty - no errors recorded.</p>
                    </div>
                <?php endif;
            else: 
                // Get the specific error message
                $error_message = $error_logs->get_error_message();
                if (!empty($error_message)): ?>
                    <div class="dashboard-empty-state">
                        <p style="color: var(--dashboard-text-primary); font-weight: bold; text-align: center; padding: 20px; background: rgba(255, 193, 7, 0.1); border: 1px solid rgba(255, 193, 7, 0.3); border-radius: 4px; margin: 20px 0;">
                            ⚠️ <?php echo esc_html($error_message); ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="dashboard-empty-state">
                        <p style="color: var(--dashboard-text-secondary); font-style: italic; text-align: center; padding: 40px 20px;">⚠️ Unable to access error log file. Please check permissions.</p>
                    </div>
                <?php endif;
            endif; ?>
        </div>
        <?php
    }

    /**
     * WordPress standard handler for clearing all cache plugins (admin_post hook)
     * This method runs early and prevents any output before redirect
     */
    public function handle_clear_all_cache_plugins() {
        // Verify nonce and permissions
        if (!isset($_POST['clear_cache_nonce']) || !wp_verify_nonce($_POST['clear_cache_nonce'], 'metasync_clear_cache_nonce')) {
            wp_die('Security check failed');
        }

        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('You do not have permission to perform this action');
        }

        $redirect_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced');

        if (class_exists('Metasync_Cache_Purge')) {
            try {
                $cache_purge = Metasync_Cache_Purge::get_instance();
                $results = $cache_purge->clear_all_caches('manual_admin');

                $cleared_count = count($results['cleared']);
                $failed_count = count($results['failed']);

                $redirect_url .= '&cache_cleared=1&cleared=' . $cleared_count . '&failed=' . $failed_count;
                if (!empty($results['cleared'])) {
                    $redirect_url .= '&plugins=' . urlencode(implode(',', $results['cleared']));
                }
            } catch (Exception $e) {
                error_log('MetaSync Cache Clear Error: ' . $e->getMessage());
                $redirect_url .= '&cache_error=1&message=' . urlencode($e->getMessage());
            }
        } else {
            $redirect_url .= '&cache_error=1&message=' . urlencode('Cache Purge class not available');
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * WordPress standard handler for clearing OTTO cache (admin_post hook)
     */
    public function handle_clear_otto_cache_all() {
        // Verify nonce and permissions
        if (!isset($_POST['clear_otto_cache_nonce']) || !wp_verify_nonce($_POST['clear_otto_cache_nonce'], 'metasync_clear_otto_cache_nonce')) {
            wp_die('Security check failed');
        }

        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('You do not have permission to perform this action');
        }

        $redirect_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced');

        if (class_exists('Metasync_Otto_Transient_Cache')) {
            try {
                $result = Metasync_Otto_Transient_Cache::clear_all_transients();
                $redirect_url .= '&otto_cache_cleared=1&count=' . $result['cleared_count'];
            } catch (Exception $e) {
                error_log('MetaSync OTTO Cache Clear Error: ' . $e->getMessage());
                $redirect_url .= '&otto_cache_error=1&message=' . urlencode($e->getMessage());
            }
        } else {
            $redirect_url .= '&otto_cache_error=1&message=' . urlencode('Transient Cache class not found');
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * WordPress standard handler for clearing OTTO cache by URL (admin_post hook)
     */
    public function handle_clear_otto_cache_url() {
        // Verify nonce and permissions
        if (!isset($_POST['clear_otto_cache_nonce']) || !wp_verify_nonce($_POST['clear_otto_cache_nonce'], 'metasync_clear_otto_cache_nonce')) {
            wp_die('Security check failed');
        }

        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('You do not have permission to perform this action');
        }

        $redirect_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced');
        $url = isset($_POST['otto_cache_url']) ? trim($_POST['otto_cache_url']) : '';

        if (empty($url)) {
            wp_safe_redirect($redirect_url . '&otto_cache_error=1&message=' . urlencode('URL is required'));
            exit;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            wp_safe_redirect($redirect_url . '&otto_cache_error=1&message=' . urlencode('Invalid URL format'));
            exit;
        }

        if (class_exists('Metasync_Otto_Transient_Cache')) {
            try {
                $result = Metasync_Otto_Transient_Cache::clear_url_transient($url);

                if ($result['success']) {
                    $redirect_url .= '&otto_cache_cleared=1&count=' . $result['cleared_count'] . '&url=' . urlencode($url);
                } else {
                    $redirect_url .= '&otto_cache_error=1&message=' . urlencode($result['message']);
                }
            } catch (Exception $e) {
                error_log('MetaSync OTTO Cache Clear Error: ' . $e->getMessage());
                $redirect_url .= '&otto_cache_error=1&message=' . urlencode($e->getMessage());
            }
        } else {
            $redirect_url .= '&otto_cache_error=1&message=' . urlencode('Transient Cache class not found');
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Handle debug mode operations (enable/disable/extend)
     */
    private function handle_debug_mode_operations()
    {
        // Check if this is a debug mode action from the Advanced tab
        if (isset($_POST['metasync_debug_mode_action_advanced'])) {
            // Verify nonce for security
            if (!wp_verify_nonce($_POST['metasync_debug_mode_nonce_advanced'], 'metasync_debug_mode_action_advanced')) {
                // Nonce verification failed - redirect with error
                $redirect_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced&debug_error=1');
                wp_redirect($redirect_url);
                exit;
            }

            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_die('Unauthorized');
            }

            // Check if debug manager class exists
            if (!class_exists('Metasync_Debug_Mode_Manager')) {
                $redirect_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced&debug_error=1&msg=manager_not_available');
                wp_redirect($redirect_url);
                exit;
            }

            $debug_manager = Metasync_Debug_Mode_Manager::get_instance();
            $action = sanitize_text_field($_POST['action_type'] ?? '');
            $redirect_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced');

            switch ($action) {
                case 'enable':
                    $indefinite = isset($_POST['indefinite']) && $_POST['indefinite'] === '1';
                    $result = $debug_manager->enable_debug_mode($indefinite);

                    // Always show success if we got this far
                    $redirect_url = add_query_arg('debug_mode_enabled', '1', $redirect_url);
                    if ($indefinite) {
                        $redirect_url = add_query_arg('indefinite', '1', $redirect_url);
                    }
                    break;

                case 'disable':
                    $result = $debug_manager->disable_debug_mode('manual');
                    if ($result) {
                        $redirect_url = add_query_arg('debug_mode_disabled', '1', $redirect_url);
                    } else {
                        $redirect_url = add_query_arg('debug_error', '1', $redirect_url);
                    }
                    break;

                case 'extend':
                    $result = $debug_manager->extend_debug_mode();

                    // Always show success if we got this far
                    $redirect_url = add_query_arg('debug_mode_extended', '1', $redirect_url);
                    break;

                default:
                    $redirect_url = add_query_arg('debug_error', '1', $redirect_url);
                    break;
            }

            wp_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Handle error log operations (clear)
     */
    private function handle_error_log_operations()
    {
        // Handle clearing of the log file
        if (isset($_POST['clear_log'])) {
            // Verify nonce for security
            if (wp_verify_nonce($_POST['clear_log_nonce'], 'metasync_clear_log_nonce')) {
                // Clear the MetaSync plugin error log (same file shown in UI)
                $log_file = WP_CONTENT_DIR . '/metasync_data/plugin_errors.log';

                if (file_exists($log_file)) {
                    // Clear the file content
                    file_put_contents($log_file, '');

                    // Also clean up any backup files
                    $backup_files = glob(WP_CONTENT_DIR . '/metasync_data/plugin_errors.log.old.*');
                    if ($backup_files) {
                        foreach ($backup_files as $backup_file) {
                            @unlink($backup_file);
                        }
                    }
                }

                // Redirect back to advanced tab with success
                $redirect_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced&log_cleared=1');
                wp_redirect($redirect_url);
                exit;
            } else {
                // Nonce verification failed - redirect with error
                $redirect_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced&clear_error=1');
                wp_redirect($redirect_url);
                exit;
            }
        }
        
        // Handle error summary clear form submission
        if (isset($_POST['clear_error_summary']) && isset($_POST['clear_error_summary_nonce'])) {
            if (wp_verify_nonce($_POST['clear_error_summary_nonce'], 'metasync_clear_error_summary_nonce')) {
                if (class_exists('Metasync_Error_Logger')) {
                    Metasync_Error_Logger::clear_error_summary();
                    // Redirect back to advanced tab with success
                    $redirect_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced&error_summary_cleared=1');
                    wp_redirect($redirect_url);
                    exit;
                } else {
                    // Error logger class not available
                    $redirect_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced&error_summary_error=1');
                    wp_redirect($redirect_url);
                    exit;
                }
            } else {
                // Nonce verification failed - redirect with error
                $redirect_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced&error_summary_error=1');
                wp_redirect($redirect_url);
                exit;
            }
        }
    }

    /**
     * Handle clear all settings operations
     */
    private function handle_clear_all_settings()
    {
        // Handle clearing of all plugin settings
        if (isset($_POST['clear_all_settings'])) {
            // Verify nonce for security
            if (wp_verify_nonce($_POST['clear_all_settings_nonce'], 'metasync_clear_all_settings_nonce')) {
                
                // List of all metasync-related options to clear
                $metasync_options_to_clear = [
                    'metasync_options',                    // Main plugin options
                    'metasync_options_instant_indexing',  // Google instant indexing settings
                    'metasync_options_bing_instant_indexing', // Bing instant indexing settings
                    'metasync_otto_crawldata',            // Otto crawl data
                    'metasync_logging_data',              // Logging data
                    'metasync_wp_sa_connect_token',              // WordPress Search Atlas connect token
                    'wp_debug_enabled',                   // Debug settings managed by plugin
                    'wp_debug_log_enabled',               // Debug log settings
                    'wp_debug_display_enabled',           // Debug display settings
                ];

                // Clear all plugin options
                $cleared_count = 0;
                foreach ($metasync_options_to_clear as $option_name) {
                    if (get_option($option_name) !== false) {
                        delete_option($option_name);
                        $cleared_count++;
                    }
                }

                // Clear all plugin transients (cache)
                $transients_to_clear = [
                    'metasync_heartbeat_status_cache',
                ];
                
                foreach ($transients_to_clear as $transient_name) {
                    delete_transient($transient_name);
                }

                // Unschedule any cron jobs
                $timestamp = wp_next_scheduled('metasync_heartbeat_cron_check');
                if ($timestamp) {
                    wp_unschedule_event($timestamp, 'metasync_heartbeat_cron_check');
                }

                // CRITICAL: Regenerate Plugin Auth Token (apikey) - this can never be empty
                $new_plugin_auth_token = wp_generate_password(32, false, false);
                
                // Initialize fresh options structure with the new Plugin Auth Token
                $fresh_options = [
                    'general' => [
                        'apikey' => $new_plugin_auth_token
                    ]
                ];
                
                // Save the fresh options with new Plugin Auth Token
                update_option('metasync_options', $fresh_options);

                // Use centralized API key event logging
                Metasync::log_api_key_event('settings_reset', 'plugin_auth_token', array(
                    'options_cleared_count' => $cleared_count,
                    'new_token_prefix' => substr($new_plugin_auth_token, 0, 8) . '...',
                    'triggered_by' => 'settings_reset_action'
                ), 'info');
                
                // Skip heartbeat trigger on settings reset
                // Settings reset clears all options including Search Atlas API key, so heartbeat would fail
                // User will need to re-authenticate, at which point heartbeat will be triggered by connect flow             
                
                // Redirect back to advanced tab with success message
                $redirect_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced&settings_cleared=1');
                wp_redirect($redirect_url);
                exit;
            } else {
                // Nonce verification failed - redirect with error
                $redirect_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced&clear_settings_error=1');
                wp_redirect($redirect_url);
                exit;
            }
        }
    }

    /**
     * Handle saving plugin access roles from Advanced Settings
     */
    private function handle_plugin_access_roles_save() {
        if (isset($_POST['save_plugin_access_roles']) && $_POST['save_plugin_access_roles'] === 'yes') {
            // Verify nonce
            if (isset($_POST['plugin_access_roles_nonce']) && wp_verify_nonce($_POST['plugin_access_roles_nonce'], 'metasync_plugin_access_roles_nonce')) {
                
                // Check user capability
                if (!Metasync::current_user_has_plugin_access()) {
                    wp_redirect(admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced&access_roles_error=1&message=' . urlencode('Insufficient permissions')));
                    exit;
                }

                // Get existing options
                $metasync_options = Metasync::get_option();
                if (!is_array($metasync_options)) {
                    $metasync_options = array();
                }
                if (!isset($metasync_options['general']) || !is_array($metasync_options['general'])) {
                    $metasync_options['general'] = array();
                }

                // Process plugin access roles
                if (isset($_POST['plugin_access_roles']) && is_array($_POST['plugin_access_roles'])) {
                    $metasync_options['general']['plugin_access_roles'] = array_map('sanitize_text_field', $_POST['plugin_access_roles']);
                } else {
                    // If no roles selected, set to empty array (will default to 'all')
                    $metasync_options['general']['plugin_access_roles'] = array();
                }

                // Save the options
                Metasync::set_option($metasync_options);

                // Redirect with success message
                $redirect_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced&access_roles_saved=1');
                wp_redirect($redirect_url);
                exit;
            } else {
                // Nonce verification failed
                $redirect_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced&access_roles_error=1&message=' . urlencode('Invalid security token'));
                wp_redirect($redirect_url);
                exit;
            }
        }
    }

    /**
     * Get error log content for display
     */
    private function get_error_log_content()
    {
        // Set execution time limit for log file processing
        $execution_time = $this->get_execution_setting('max_execution_time');
        if (function_exists('set_time_limit')) {
            @set_time_limit($execution_time);
        }
        
        // Try to get WordPress debug.log content
        $log_file = WP_CONTENT_DIR . '/debug.log';
        
        if (!file_exists($log_file) || !is_readable($log_file)) {
            return false;
        }
        
        // Get batch size from execution settings
        $batch_size = $this->get_execution_setting('log_batch_size');
        
        // Check file size first - if it's too large, use tail method
        $file_size = filesize($log_file);
        if ($file_size > 10 * 1024 * 1024) { // 10MB limit
            return $this->get_log_tail($log_file, $batch_size);
        }
        
        // For smaller files, use the original method
        $content = file_get_contents($log_file);
        if ($content === false) {
            return false;
        }
        
        // Get last N lines based on batch size setting
        $lines = explode("\n", $content);
        $recent_lines = array_slice($lines, -$batch_size);
        
        return implode("\n", $recent_lines);
    }
    
    /**
     * Memory-efficient function to get last N lines from a large file
     */
    private function get_log_tail($file_path, $lines = null)
    {
        // Set execution time limit for large file processing
        $execution_time = $this->get_execution_setting('max_execution_time');
        if (function_exists('set_time_limit')) {
            @set_time_limit($execution_time);
        }
        
        // Get batch size from execution settings if not provided
        if ($lines === null) {
            $lines = $this->get_execution_setting('log_batch_size');
        }
        
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return false;
        }
        
        // Start from the end of the file
        fseek($handle, -1, SEEK_END);
        
        $result_lines = array();
        $line = '';
        $line_count = 0;
        
        // Read backwards character by character
        while (ftell($handle) > 0 && $line_count < $lines) {
            $char = fgetc($handle);
            
            if ($char === "\n") {
                if (!empty($line)) {
                    array_unshift($result_lines, strrev($line));
                    $line = '';
                    $line_count++;
                }
            } else {
                $line .= $char;
            }
            
            // Move backwards
            fseek($handle, -2, SEEK_CUR);
        }
        
        // Add the last line if we reached the beginning
        if (!empty($line) && $line_count < $lines) {
            array_unshift($result_lines, strrev($line));
        }
        
        fclose($handle);
        
        return implode("\n", $result_lines);
    }

    /**
     * Test whitelabel domain functionality (development/debugging)
     */
    public function test_whitelabel_domain()
    {
        // SECURITY FIX: Use proper capability check instead of role name
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Administrator access required');
            return;
        }

        // Test 1: Check current whitelabel settings
        $whitelabel_settings = Metasync::get_whitelabel_settings();
        
        // Test 2: Check if whitelabel is enabled
        $is_enabled = Metasync::is_whitelabel_enabled();
        
        // Test 3: Get effective dashboard domain
        $effective_domain = self::get_effective_dashboard_domain();
        $metasync_domain = Metasync::get_dashboard_domain();
        
        // Test 4: Get whitelabel logo
        $whitelabel_logo = Metasync::get_whitelabel_logo();
        
        // Test 5: Compare with default
        $default_domain = Metasync::DASHBOARD_DOMAIN;
        
        // Test 6: Get whitelabel company name
        $whitelabel_company_name = Metasync::get_whitelabel_company_name();
        
        // Test 7: Get effective plugin name
        $effective_plugin_name = Metasync::get_effective_plugin_name('Test Plugin');
        
        wp_send_json_success(array(
            'whitelabel_settings' => $whitelabel_settings,
            'is_enabled' => $is_enabled,
            'effective_domain' => $effective_domain,
            'whitelabel_logo' => $whitelabel_logo,
            'whitelabel_company_name' => $whitelabel_company_name,
            'effective_plugin_name' => $effective_plugin_name,
            'default_domain' => $default_domain,
            'override_active' => $effective_domain !== $default_domain
        ));
    }

    /**
     * Decrypt token using WordPress SALTs
     */
    private function wp_decrypt_token($encrypted_token)
    {
        try {
            // Decode from base64
            $data = base64_decode($encrypted_token, true);
            
            if ($data === false || strlen($data) < 16) {
                return false;
            }
            
            // Extract IV and encrypted data
            $iv = substr($data, 0, 16);
            $encrypted = substr($data, 16);
            
            // Create decryption key from WordPress SALTs
            $key_material = wp_salt('secure_auth') . wp_salt('logged_in') . wp_salt('nonce');
            $encryption_key = hash('sha256', $key_material, true);
            
            // Decrypt
            $serialized = openssl_decrypt($encrypted, 'AES-256-CBC', $encryption_key, OPENSSL_RAW_DATA, $iv);
            
            if ($serialized === false) {
                return false;
            }
            
            // Unserialize payload
            $payload = unserialize($serialized);
            
            // Validate payload structure
            if (!is_array($payload) || !isset($payload['exp'], $payload['iat'])) {
                return false;
            }
            
            // Check expiration
            if ($payload['exp'] < time()) {
                return false; // Token expired
            }
            
            return $payload;
            
        } catch (Exception $e) {
            return false;
        }
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
        // Get Search Atlas API key
        $general_options = Metasync::get_option('general') ?? [];
        $api_key = $general_options['searchatlas_api_key'] ?? '';
        
        if (empty($api_key)) {
            return false;
        }

        // Check for cached token first (unless forced refresh)
        if (!$force_refresh) {
            $cache_key = 'metasync_jwt_token_' . md5($api_key);
            $cached_token_data = get_transient($cache_key);
            
            if ($cached_token_data && is_array($cached_token_data)) {
                // Check if cached token is still valid (with 5-minute buffer)
                $expires_with_buffer = $cached_token_data['expires'] - 300; // 5 minutes buffer
                if (time() < $expires_with_buffer && !empty($cached_token_data['token'])) {
                    return $cached_token_data['token'];
                }
            }
        }

        // Generate fresh token if no valid cache or force refresh
        // Create dummy objects for constructor parameters
        $dummy_database = new stdClass();
        $dummy_db_redirection = new stdClass();
        $dummy_db_heartbeat_errors = new stdClass();
        
        $instance = new self('metasync', '1.0.0', $dummy_database, $dummy_db_redirection, $dummy_db_heartbeat_errors);
        return $instance->get_fresh_jwt_token();
    }


    /**
     * Get fresh JWT token from Search Atlas API with caching
     * Generates and caches JWT tokens to avoid repeated API calls
     * 
     * @return string|false JWT token on success, false on failure
     */
    public function get_fresh_jwt_token()
    {
        // Get Search Atlas API key
        $general_options = Metasync::get_option('general') ?? [];
        $api_key = $general_options['searchatlas_api_key'] ?? '';
        
        if (empty($api_key)) {
            return false;
        }

        // Check for cached token first
        $cache_key = 'metasync_jwt_token_' . md5($api_key);
        $cached_token_data = get_transient($cache_key);
        
        if ($cached_token_data && is_array($cached_token_data)) {
            // Check if cached token is still valid (with 5-minute buffer)
            $expires_with_buffer = $cached_token_data['expires'] - 300; // 5 minutes buffer
            if (time() < $expires_with_buffer && !empty($cached_token_data['token'])) {
                return $cached_token_data['token'];
            }
        }

        // Generate fresh JWT token from API
        $api_domain = class_exists('Metasync_Endpoint_Manager')
            ? Metasync_Endpoint_Manager::get_endpoint('API_DOMAIN')
            : Metasync::API_DOMAIN;
        $url = $api_domain . '/api/customer/account/generate-jwt-from-api-key/';
        
        $args = array(
            'method' => 'POST',
            'headers' => array(
                'X-API-KEY' => $api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15
        );

        try {
            $response = wp_remote_post($url, $args);
            
            if (is_wp_error($response)) {
                error_log('MetaSync: JWT token API request failed - ' . $response->get_error_message());
                return false;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            if ($response_code !== 200) {
                error_log('MetaSync: JWT token API returned error code ' . $response_code);
                return false;
            }
            
            $data = json_decode($response_body, true);
            
            if (!$data || !isset($data['token'], $data['expires'])) {
                error_log('MetaSync: Invalid JWT token API response format');
                return false;
            }
            
            // Cache the token
            $token_data = array(
                'token' => $data['token'],
                'expires' => $data['expires'],
                'created_at' => time()
            );
            
            // Cache until expiry (with WordPress transient max of 24 hours)
            $cache_duration = min($data['expires'] - time(), 24 * 3600);
            set_transient($cache_key, $token_data, $cache_duration);
            
            return $data['token'];
            
        } catch (Exception $e) {
            error_log('MetaSync: Exception during JWT generation - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear cached JWT tokens
     * Useful when authentication is reset or API key changes
     */
    private function clear_jwt_token_cache()
    {
        global $wpdb;
        
        // Clear all JWT token transients
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_metasync_jwt_token_%'
            )
        );
        
        // Also clear timeout transients
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_timeout_metasync_jwt_token_%'
            )
        );
        

    }



    /**
     * Data or Response received from HeartBeat API for admin area.
     */
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
            
            // Update heartbeat connectivity cache since sync was successful
            // This ensures UI status consistency between "Sync Now" and heartbeat checks
            $this->update_heartbeat_cache_after_sync(true, 'Sync Now - successful data sync');
            
            $result = json_decode($responseBody);
            $timestamp = @Metasync::get_option('general')['send_auth_token_timestamp'];
            $result->send_auth_token_timestamp = $timestamp;
            $result->send_auth_token_diffrence = $this->time_elapsed_string($timestamp);
            wp_send_json($result);
            wp_die();
        } else {
            // Update heartbeat cache to reflect failed connection
            $this->update_heartbeat_cache_after_sync(false, 'Sync Now - failed data sync');
        }

        $result = json_decode($responseBody);
        wp_send_json($result);
        wp_die();
    }



    /**
     * Add CSS styles for Search Atlas admin bar status indicator
     */
    public function metasync_admin_bar_style()
    {
        // Only show styles when admin bar is present
        if (!is_admin_bar_showing()) {
            return;
        }

        # For backward compatibility, constant takes precedence.
        if (defined('METASYNC_SHOW_ADMIN_BAR_STATUS') && !METASYNC_SHOW_ADMIN_BAR_STATUS) {
            return;
        }


        # Check if admin bar status is enabled via setting
        $general_settings = Metasync::get_option('general');
        $show_admin_bar = $general_settings['show_admin_bar_status'] ?? true;
        if (!$show_admin_bar) {
            return;
        }
        ?>
        <style type="text/css">
        #wp-admin-bar-searchatlas-status .ab-item {
            font-weight: 500 !important;
            transition: all 0.2s ease !important;
        }
        
        #wp-admin-bar-searchatlas-status:hover .ab-item {
            background-color: rgba(255, 255, 255, 0.1) !important;
        }
        
        #wp-admin-bar-searchatlas-status.searchatlas-synced .ab-item {
            color: #46b450 !important; /* WordPress green for text */
        }
        
        #wp-admin-bar-searchatlas-status.searchatlas-not-synced .ab-item {
            color: #dc3232 !important; /* WordPress red for text */
        }
        
        /* Ensure emojis maintain their natural colors */
        #wp-admin-bar-searchatlas-status .ab-item {
            filter: none !important;
        }
        
        /* Make status visible but subtle */
        #wp-admin-bar-searchatlas-status .ab-item {
            opacity: 0.9;
        }
        
        #wp-admin-bar-searchatlas-status:hover .ab-item {
            opacity: 1;
        }
        </style>
        
        <?php 
        // Load sync JavaScript on all our plugin admin pages  
        $is_plugin_page = (
            (isset($_GET['page']) && strpos($_GET['page'], self::$page_slug) !== false) ||
            (isset($_GET['page']) && strpos($_GET['page'], 'searchatlas') !== false)
        );
        if ($is_plugin_page): 
        ?>
        <script type="text/javascript">
        // Pass PHP variables to JavaScript
        window.MetasyncConfig = {
            pluginName: '<?php echo esc_js(Metasync::get_effective_plugin_name()); ?>',
            ottoName: '<?php echo esc_js(Metasync::get_whitelabel_otto_name()); ?>'
        };
        
        jQuery(document).ready(function($) {
            
            // Function to sync admin bar status
            function syncAdminBarStatus() {
                var pluginPageStatus = $('.metasync-integration-status .status-text').text();
                var adminBarItem = $('#wp-admin-bar-searchatlas-status .ab-item');
                var adminBarContainer = $('#wp-admin-bar-searchatlas-status');
                var pluginName = window.MetasyncConfig.pluginName;
                
                if (pluginPageStatus && adminBarItem.length) {
                    if (pluginPageStatus.includes('Synced') && !pluginPageStatus.includes('Not Synced')) {
                        // Update admin bar to synced (GREEN)
                        // Handle WordPress emoji-to-image conversion
                        var emojiImg = adminBarItem.find('img.emoji');
                        if (emojiImg.length > 0) {
                            // WordPress converted emoji to image - update both alt and src
                            if (emojiImg.attr('alt') === '🔴' || emojiImg.attr('src').includes('1f534.svg')) {
                                emojiImg.attr('alt', '🟢');
                                emojiImg.attr('src', emojiImg.attr('src').replace('1f534.svg', '1f7e2.svg')); // Red to Green circle
                            }
                        } else {
                            // Regular emoji text - replace directly
                            var newHtml = adminBarItem.html().replace('🔴', '🟢');
                            if (!newHtml.includes('🟢') && newHtml.includes(pluginName)) {
                                newHtml = newHtml.replace(pluginName, pluginName + ' 🟢');
                            }
                            adminBarItem.html(newHtml);
                        }
                        
                        adminBarContainer.removeClass('searchatlas-not-synced').addClass('searchatlas-synced');
                        adminBarContainer.attr('title', pluginName + ' - Synced (Heartbeat API connectivity verified)');
                        
                        // Also update the tooltip on the link itself for better accessibility
                        adminBarItem.attr('title', pluginName + ' - Synced (Heartbeat API connectivity verified)');
                        
                    } else if (pluginPageStatus.includes('Not Synced')) {
                        // Update admin bar to not synced (RED)
                        // Handle WordPress emoji-to-image conversion
                        var emojiImg = adminBarItem.find('img.emoji');
                        if (emojiImg.length > 0) {
                            // WordPress converted emoji to image - update both alt and src
                            if (emojiImg.attr('alt') === '🟢' || emojiImg.attr('src').includes('1f7e2.svg')) {
                                emojiImg.attr('alt', '🔴');
                                emojiImg.attr('src', emojiImg.attr('src').replace('1f7e2.svg', '1f534.svg')); // Green to Red circle
                            }
                        } else {
                            // Regular emoji text - replace directly
                            var newHtml = adminBarItem.html().replace('🟢', '🔴');
                            if (!newHtml.includes('🔴') && newHtml.includes(pluginName)) {
                                newHtml = newHtml.replace(pluginName, pluginName + ' 🔴');
                            }
                            adminBarItem.html(newHtml);
                        }
                        
                        adminBarContainer.removeClass('searchatlas-synced').addClass('searchatlas-not-synced');
                        adminBarContainer.attr('title', pluginName + ' - Not Synced (Heartbeat API not responding or unreachable)');
                        
                        // Also update the tooltip on the link itself for better accessibility
                        adminBarItem.attr('title', pluginName + ' - Not Synced (Heartbeat API not responding or unreachable)');
                    }
                }
            }
            
            // Sync when tabs are switched (for General/Advanced tabs)
            $(document).on('click', 'a[href*="tab="]', function() {
                setTimeout(syncAdminBarStatus, 200);
            });
            
            // Also check every 5 seconds to keep it in sync
            setInterval(syncAdminBarStatus, 5000);
        });
        </script>
        <?php endif; ?>
        <?php
    }

    /**
     * Add Search Atlas status indicator to WordPress admin bar
     * Shows sync status with green/red emoji
     */
    public function add_searchatlas_admin_bar_status($wp_admin_bar)
    {
        # Don't show admin bar status if user's role doesn't have plugin access
        if (!Metasync::current_user_has_plugin_access()) {
            return;
        }

        # For backward compatibility, constant takes precedence.
        if (defined('METASYNC_SHOW_ADMIN_BAR_STATUS') && !METASYNC_SHOW_ADMIN_BAR_STATUS) {
            return;
        }
        
        # Check if admin bar status is disabled via setting
        $general_settings = Metasync::get_option('general');
        $show_admin_bar = $general_settings['show_admin_bar_status'] ?? true;
        if (!$show_admin_bar) {
            return;
        }
        
        // Only show for users who can manage options (admins)
        if (!Metasync::current_user_has_plugin_access()) {
            return;
        }

        // Only show in admin area or frontend if specifically enabled
        if (!is_admin() && !apply_filters('metasync_show_admin_bar_status_frontend', false)) {
            return;
        }

        // Force fresh data retrieval for admin bar consistency
        $general_settings = Metasync::get_option('general');
        if (!is_array($general_settings)) {
            $general_settings = [];
        }
        
        // Use the same method signature as plugin settings page
        $is_synced = $this->is_heartbeat_connected($general_settings);
        

        
        // Get effective plugin name for white-label support
        $plugin_name = Metasync::get_effective_plugin_name();
        
        // Determine status emoji and title (matching updated connectivity logic)
        if ($is_synced) {
            $status_emoji = '🟢'; // Green circle for synced
            $title = $plugin_name . ' - Synced (Heartbeat API connectivity verified)';
            $status_class = 'searchatlas-synced';
        } else {
            $status_emoji = '🔴'; // Red circle for not synced
            $title = $plugin_name . ' - Not Synced (Heartbeat API not responding or unreachable)';
            $status_class = 'searchatlas-not-synced';
        }

        $display_name = $plugin_name;

        // Final admin bar title
        $admin_bar_title = $display_name . ' ' . $status_emoji;
        
        // Add the admin bar node
        $wp_admin_bar->add_node(array(
            'id'    => 'searchatlas-status',
            'title' => $admin_bar_title,
            'href'  => admin_url('admin.php?page=' . self::$page_slug),
            'meta'  => array(
                'title' => $title,
                'class' => $status_class
            )
        ));
    }

    /**
     * Check if heartbeat API is properly connected (frontend - cache only)
     * Frontend should NEVER trigger API calls - only use cached results from cron job
     * Returns false immediately if plugin API key is not configured
     * Uses graceful fallback to last known state when cache is missing
     */
    private function is_heartbeat_connected($general_settings = null)
    {
        // Get settings if not provided
        if ($general_settings === null) {
            $general_settings = Metasync::get_option('general') ?? [];
        }
        
        // Pre-check: Heartbeat system is inactive without plugin API key
        $searchatlas_api_key = $general_settings['searchatlas_api_key'] ?? '';
        
        if (empty($searchatlas_api_key)) {
            // No API key = heartbeat system is completely inactive
            return false;
        }
        
        // Check cached result first (5-minute cache)
        $cache_key = 'metasync_heartbeat_status_cache';
        $cached_result = get_transient($cache_key);
        
        
        if ($cached_result !== false) {
            // Return cached result (includes timestamp for debugging)
            $this->log_heartbeat('info', 'Cache hit - using cached heartbeat status', array(
                'status' => $cached_result['status'] ? 'CONNECTED' : 'DISCONNECTED',
                'cached_at' => date('Y-m-d H:i:s T', $cached_result['timestamp']),
                'expires_at' => date('Y-m-d H:i:s T', $cached_result['cached_until']),
                'cache_age_seconds' => time() - $cached_result['timestamp']
            ));
            return $cached_result['status'];
        }
        
        // No cached result - check for last known state before defaulting to disconnected
        $last_known_state = $this->get_last_known_connection_state();
        
        if ($last_known_state !== null) {
            $this->log_heartbeat('info', 'Cache miss - using last known heartbeat status', array(
                'status' => $last_known_state ? 'CONNECTED' : 'DISCONNECTED',
                'note' => 'Graceful fallback until next cron job updates cache',
                'fallback_reason' => 'cache_expired_or_missing'
            ));
            return $last_known_state;
        }
        
        // No cache and no last known state - return default disconnected state
        // The cron job will update this cache in the background
        $this->log_heartbeat('info', 'No cached or last known heartbeat status found - returning default DISCONNECTED', array(
            'note' => 'Cron job will establish initial connection state',
            'status' => 'DISCONNECTED' // For consistent throttling
        ));
        
        return false; // Default to disconnected if no cache or last known state exists
    }
    


    
    /**
     * Fetch public hash from OTTO API for the given pixel UUID
     * 
     * This method retrieves the public hash from the OTTO projects API endpoint,
     * implements caching to reduce API calls, and includes robust error handling
     * with retry logic for temporary failures.
     * 
     * @since 1.0.0
     * @param string $otto_pixel_uuid The OTTO pixel UUID to fetch hash for
     * @param string $jwt_token       The JWT authentication token
     * @return string|false           The public hash on success, false on failure
     * 
     * @throws none                   All errors are handled internally and logged
     */
    private function fetch_public_hash($otto_pixel_uuid, $jwt_token)
    {
        // Configuration constants
        $cache_duration = 3600; // 1 hour
        $api_timeout = 15; // 15 seconds
        $max_retries = 3;
        $base_retry_delay = 1; // Base delay in seconds for exponential backoff
        
        // Validate and sanitize inputs
        if (!$this->validate_fetch_hash_inputs($otto_pixel_uuid, $jwt_token)) {
            $this->log_fetch_hash_error('error', 'Invalid input parameters provided', [
                'uuid_provided' => !empty($otto_pixel_uuid),
                'token_provided' => !empty($jwt_token)
            ]);
            return false;
        }
        
        // Clean and validate UUID format
        $otto_pixel_uuid = sanitize_text_field(trim($otto_pixel_uuid));
        $jwt_token = sanitize_text_field(trim($jwt_token));
        
        // Check cache first
        $cached_hash = $this->get_cached_public_hash($otto_pixel_uuid);
        if ($cached_hash !== false) {
            $this->log_fetch_hash_error('info', 'Public hash retrieved from cache', [
                'uuid' => substr($otto_pixel_uuid, 0, 8) . '...'
            ]);
            return $cached_hash;
        }
        
        // Prepare API request
        $api_url = $this->build_otto_api_url($otto_pixel_uuid);
        $headers = $this->prepare_api_headers($jwt_token);
        
        // Make API request with retry logic
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $this->log_fetch_hash_error('info', 'Attempting to fetch public hash from API', [
                'attempt' => $attempt,
                'max_retries' => $max_retries,
                'uuid' => substr($otto_pixel_uuid, 0, 8) . '...'
            ]);
            
            $response = wp_remote_get($api_url, [
                'headers' => $headers,
                'timeout' => $api_timeout,
                'sslverify' => true,
                'redirection' => 2,
                'user-agent' => 'WordPress MetaSync Plugin/' . (defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0')
            ]);
            
            // Handle WordPress HTTP errors
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $error_code = $response->get_error_code();

                // NEW: Structured error logging with category and code
                if (class_exists('Metasync_Error_Logger')) {
                    Metasync_Error_Logger::log(
                        Metasync_Error_Logger::CATEGORY_NETWORK_ERROR,
                        Metasync_Error_Logger::SEVERITY_ERROR,
                        'OTTO API network request failed',
                        [
                            'attempt' => $attempt,
                            'error_code' => $error_code,
                            'error_message' => $error_message,
                            'api_endpoint' => 'OTTO Projects API',
                            'operation' => 'fetch_public_hash',
                            'will_retry' => $attempt < $max_retries,
                            'max_retries' => $max_retries
                        ]
                    );
                }
                
                $this->log_fetch_hash_error('error', 'HTTP request failed', [
                    'attempt' => $attempt,
                    'error_code' => $error_code,
                    'error_message' => $error_message,
                    'will_retry' => $attempt < $max_retries
                ]);
                
                if ($attempt < $max_retries) {
                    $this->apply_exponential_backoff($attempt, $base_retry_delay);
                    continue;
                }
                
                return false;
            }
            
            // Process successful HTTP response
            $result = $this->process_api_response($response, $attempt, $max_retries);
            
            if ($result === 'retry' && $attempt < $max_retries) {
                $this->apply_exponential_backoff($attempt, $base_retry_delay);
                continue;
            }
            
            if ($result !== false && $result !== 'retry') {
                // Success - cache and return the hash
                $this->cache_public_hash($otto_pixel_uuid, $result, $cache_duration);
                $this->log_fetch_hash_error('info', 'Public hash successfully retrieved and cached', [
                    'uuid' => substr($otto_pixel_uuid, 0, 8) . '...',
                    'attempt' => $attempt
                ]);
                return $result;
            }
            
            // If we get here, it's a final failure
            break;
        }
        
        $this->log_fetch_hash_error('error', 'Failed to fetch public hash after all retry attempts', [
            'uuid' => substr($otto_pixel_uuid, 0, 8) . '...',
            'total_attempts' => $max_retries
        ]);
        
        return false;
    }
    
    /**
     * Validate inputs for fetch_public_hash method
     * 
     * @param string $uuid  The UUID to validate
     * @param string $token The token to validate
     * @return bool         True if inputs are valid, false otherwise
     */
    private function validate_fetch_hash_inputs($uuid, $token)
    {
        if (empty($uuid) || empty($token)) {
            return false;
        }
        
        // Basic UUID format validation (loose check for flexibility)
        if (!is_string($uuid) || strlen($uuid) < 10) {
            return false;
        }
        
        // Basic JWT token validation (should have dots separating sections)
        if (!is_string($token) || substr_count($token, '.') < 2) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get cached public hash for the given UUID
     * 
     * @param string $uuid The UUID to get cached hash for
     * @return string|false The cached hash or false if not found
     */
    private function get_cached_public_hash($uuid)
    {
        $cache_key = 'metasync_public_hash_' . hash('sha256', $uuid . get_current_blog_id());
        return get_transient($cache_key);
    }
    
    /**
     * Cache the public hash for the given UUID
     * 
     * @param string $uuid     The UUID to cache hash for
     * @param string $hash     The hash to cache
     * @param int    $duration Cache duration in seconds
     */
    private function cache_public_hash($uuid, $hash, $duration)
    {
        $cache_key = 'metasync_public_hash_' . hash('sha256', $uuid . get_current_blog_id());
        set_transient($cache_key, sanitize_text_field($hash), $duration);
    }
    
    /**
     * Build the OTTO API URL for the given UUID
     * 
     * @param string $uuid The UUID to build URL for
     * @return string      The complete API URL
     */
    private function build_otto_api_url($uuid)
    {
        # Use endpoint manager to get the correct API URL
        $base_url = class_exists('Metasync_Endpoint_Manager')
            ? Metasync_Endpoint_Manager::get_endpoint('OTTO_PROJECTS')
            : 'https://sa.searchatlas.com/api/v2/otto-projects';

        # Ensure base_url ends with /
        $base_url = rtrim($base_url, '/') . '/';

        return $base_url . urlencode($uuid) . '/';
    }
    
    /**
     * Prepare headers for API request
     * 
     * @param string $jwt_token The JWT token for authorization
     * @return array            Array of headers
     */
    private function prepare_api_headers($jwt_token)
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $jwt_token,
            'Content-Type' => 'application/json',
            'User-Agent' => 'WordPress MetaSync Plugin/' . (defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0'),
            'Cache-Control' => 'no-cache',
            'X-Requested-With' => 'XMLHttpRequest'
        ];
    }
    
    /**
     * Process API response and extract public hash
     * 
     * @param mixed $response    The WordPress HTTP response object
     * @param int   $attempt     Current attempt number
     * @param int   $max_retries Maximum retry attempts
     * @return string|false|string Returns hash on success, 'retry' if should retry, false on failure
     */
    private function process_api_response($response, $attempt, $max_retries)
    {
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Handle different status codes
        switch ($status_code) {
            case 200:
                return $this->extract_public_hash_from_response($body);
                
            case 401:
            case 403:
                // NEW: Structured error logging with category and code
                if (class_exists('Metasync_Error_Logger')) {
                    Metasync_Error_Logger::log(
                        Metasync_Error_Logger::CATEGORY_AUTHENTICATION_FAILURE,
                        Metasync_Error_Logger::SEVERITY_ERROR,
                        'OTTO API authentication failed',
                        [
                            'status_code' => $status_code,
                            'attempt' => $attempt,
                            'api_endpoint' => 'OTTO Projects API',
                            'operation' => 'fetch_public_hash',
                            'http_status' => $status_code === 401 ? 'Unauthorized' : 'Forbidden'
                        ]
                    );
                }
                
                $this->log_fetch_hash_error('error', 'Authentication failed', [
                    'status_code' => $status_code,
                    'attempt' => $attempt
                ]);
                return false; // Don't retry auth failures
                
            case 404:
                $this->log_fetch_hash_error('error', 'OTTO project not found', [
                    'status_code' => $status_code,
                    'attempt' => $attempt
                ]);
                return false; // Don't retry not found
                
            case 429:
                // NEW: Structured error logging with category and code
                if (class_exists('Metasync_Error_Logger')) {
                    Metasync_Error_Logger::log(
                        Metasync_Error_Logger::CATEGORY_API_RATE_LIMIT,
                        Metasync_Error_Logger::SEVERITY_WARNING,
                        'OTTO API rate limit exceeded',
                        [
                            'status_code' => $status_code,
                            'attempt' => $attempt,
                            'will_retry' => $attempt < $max_retries,
                            'api_endpoint' => 'OTTO Projects API',
                            'operation' => 'fetch_public_hash',
                            'max_retries' => $max_retries
                        ]
                    );
                }
                
                $this->log_fetch_hash_error('warning', 'API rate limit exceeded', [
                    'status_code' => $status_code,
                    'attempt' => $attempt,
                    'will_retry' => $attempt < $max_retries
                ]);
                return 'retry'; // Retry rate limits
                
            case 500:
            case 502:
            case 503:
            case 504:
                $this->log_fetch_hash_error('warning', 'Server error encountered', [
                    'status_code' => $status_code,
                    'attempt' => $attempt,
                    'will_retry' => $attempt < $max_retries
                ]);
                return 'retry'; // Retry server errors
                
            default:
                $this->log_fetch_hash_error('error', 'Unexpected HTTP status code', [
                    'status_code' => $status_code,
                    'attempt' => $attempt,
                    'response_body' => substr($body, 0, 200)
                ]);
                return false;
        }
    }
    
    /**
     * Extract public hash from API response body
     * 
     * @param string $body The response body
     * @return string|false The public hash or false if not found
     */
    private function extract_public_hash_from_response($body)
    {
        if (empty($body)) {
            $this->log_fetch_hash_error('error', 'Empty response body received');
            return false;
        }
        
        // Decode JSON response
        $data = json_decode($body, true);
        $json_error = json_last_error();
        
        if ($json_error !== JSON_ERROR_NONE) {
            $this->log_fetch_hash_error('error', 'Invalid JSON response', [
                'json_error' => $json_error,
                'json_error_msg' => json_last_error_msg(),
                'body_preview' => substr($body, 0, 200)
            ]);
            return false;
        }
        
        if (!is_array($data)) {
            $this->log_fetch_hash_error('error', 'Response data is not an array', [
                'data_type' => gettype($data)
            ]);
            return false;
        }
        
        // Check for various possible field names for the public hash
        $possible_hash_fields = [
            # API was returning the public hash in a field called "public_share_hash"
            'public_share_hash', 
            'public_hash',
            'publicHash',
            'hash',
            'public_key',
            'publicKey'
        ];
        
        foreach ($possible_hash_fields as $field) {
            if (isset($data[$field]) && !empty($data[$field]) && is_string($data[$field])) {
                $hash = sanitize_text_field(trim($data[$field]));
                
                // Basic validation - hash should be alphanumeric and reasonable length
                if (preg_match('/^[a-zA-Z0-9_-]{10,}$/', $hash)) {
                    $this->log_fetch_hash_error('info', 'Public hash extracted successfully', [
                        'field_name' => $field,
                        'hash_length' => strlen($hash)
                    ]);
                    return $hash;
                }
            }
        }
        
        $this->log_fetch_hash_error('error', 'Public hash not found in response', [
            'available_fields' => array_keys($data),
            'searched_fields' => $possible_hash_fields
        ]);
        
        return false;
    }
    
    /**
     * Apply exponential backoff delay between retry attempts
     * 
     * @param int $attempt    Current attempt number
     * @param int $base_delay Base delay in seconds
     */
    private function apply_exponential_backoff($attempt, $base_delay)
    {
        $delay = $base_delay * pow(2, $attempt - 1);
        $max_delay = 30; // Cap at 30 seconds
        $delay = min($delay, $max_delay);

        // NEW: Structured error logging with category and code
        if (class_exists('Metasync_Error_Logger')) {
            Metasync_Error_Logger::log(
                Metasync_Error_Logger::CATEGORY_API_BACKOFF,
                Metasync_Error_Logger::SEVERITY_INFO,
                'API backoff active - applying exponential retry delay',
                [
                    'attempt' => $attempt,
                    'delay_seconds' => $delay,
                    'base_delay' => $base_delay,
                    'max_delay' => $max_delay,
                    'api_endpoint' => 'OTTO Projects API',
                    'operation' => 'fetch_public_hash'
                ]
            );
        }
        
        $this->log_fetch_hash_error('info', 'Applying retry delay', [
            'attempt' => $attempt,
            'delay_seconds' => $delay
        ]);
        
        sleep($delay);
    }
    
    /**
     * Enhanced logging for fetch public hash operations
     * Uses the existing log_heartbeat pattern for consistency
     * 
     * @param string $level   Log level (info, warning, error)
     * @param string $message Log message
     * @param array  $context Additional context data
     */
    private function log_fetch_hash_error($level, $message, $context = [])
    {
        // Don't log info level messages to reduce noise
        if ($level === 'info') {
            return;
        }
        
        $full_context = array_merge([
            'operation' => 'fetch_public_hash',
            'timestamp' => current_time('mysql'),
            'site_url' => get_site_url()
        ], $context);
        
        // Format log message
        $log_message = sprintf(
            'OTTO_API_%s: %s',
            strtoupper($level),
            $message
        );
        
        // Add context details
        if (!empty($full_context)) {
            $context_parts = [];
            foreach ($full_context as $key => $value) {
                if (is_array($value)) {
                    $value = json_encode($value);
                } elseif (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } elseif (is_string($value) && strlen($value) > 100) {
                    $value = substr($value, 0, 100) . '...';
                }
                $context_parts[] = "{$key}={$value}";
            }
            $log_message .= ' | ' . implode(', ', $context_parts);
        }
        
        // Log to WordPress error log
        error_log($log_message);
    }

    /**
     * Clear cached public hash for the given UUID
     * Should be called when authentication changes or when hash becomes invalid
     * 
     * @param string $otto_pixel_uuid The OTTO pixel UUID
     */
    private function clear_public_hash_cache($otto_pixel_uuid = '')
    {
        if (empty($otto_pixel_uuid)) {
            // Try to get UUID from settings
            $general_options = Metasync::get_option('general');
            $otto_pixel_uuid = isset($general_options['otto_pixel_uuid']) ? $general_options['otto_pixel_uuid'] : '';
        }
        
        if (!empty($otto_pixel_uuid)) {
            $cache_key = 'metasync_public_hash_' . md5($otto_pixel_uuid);
            delete_transient($cache_key);
        }
    }

    /**
     * Get the last known connection state from WordPress options
     * This provides graceful fallback when cache is missing
     * 
     * @return bool|null Returns true for connected, false for disconnected, null if never set
     */
    private function get_last_known_connection_state()
    {
        return get_option('metasync_last_known_connection_state', null);
    }
    
    /**
     * Store the last known connection state in WordPress options
     * This helps maintain consistent status during cache gaps
     * 
     * @param bool $is_connected Connection status to store
     * @return bool True on success, false on failure
     */
    private function set_last_known_connection_state($is_connected)
    {
        $success = update_option('metasync_last_known_connection_state', (bool) $is_connected);
        
        if ($success) {
        } else {
        }
        
        return $success;
    }

    /**
     * Enhanced logging for heartbeat operations
     * Provides structured and detailed logging with context
     */
    private function log_heartbeat($level, $event, $details = array())
    {
        // Don't log info level messages
        if ($level == 'info'){
            return;
        }
        // Check if we should throttle this log message to reduce spam
        if ($this->should_throttle_log($level, $event, $details)) {
            return;
        }
        
        $context = array(
            'event' => $event,
            'level' => strtoupper($level),
            'plugin_version' => defined('METASYNC_VERSION') ? METASYNC_VERSION : 'unknown',
            'site_url' => get_site_url(),
        );
        
        // Merge additional details
        $context = array_merge($context, $details);
        
        // Format log message (WordPress error_log already adds timestamp)
        $message = sprintf(
            'HEARTBEAT_%s: %s',
            strtoupper($level),
            $event
        );
        
        // Add details if provided
        if (!empty($details)) {
            $details_formatted = array();
            foreach ($details as $key => $value) {
                if (is_array($value)) {
                    $value = json_encode($value);
                } elseif (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } elseif (is_string($value) && strlen($value) > 200) {
                    // Better truncation for long strings like HTML responses
                    $value = $this->smart_truncate($value, 200);
                }
                $details_formatted[] = "{$key}={$value}";
            }
            $message .= ' | ' . implode(', ', $details_formatted);
        }
        
        // Log to WordPress error log
        error_log($message);
        
        // Also store critical errors in heartbeat error database
        if ($level === 'error' || $level === 'critical') {
            $this->store_heartbeat_error_log($event, $details);
        }
    }
    
    /**
     * Smart truncation that tries to end at word boundaries
     */
    private function smart_truncate($string, $length = 200)
    {
        if (strlen($string) <= $length) {
            return $string;
        }
        
        // Try to truncate at a word boundary
        $truncated = substr($string, 0, $length);
        $last_space = strrpos($truncated, ' ');
        
        if ($last_space !== false && $last_space > $length * 0.75) {
            $truncated = substr($truncated, 0, $last_space);
        }
        
        // Clean up HTML and indicate truncation
        $truncated = strip_tags($truncated);
        return $truncated . '... [truncated]';
    }
    
    /**
     * Throttle repetitive log messages to reduce log spam
     */
    private function should_throttle_log($level, $event, $details = array())
    {
        // Don't throttle errors - they're important
        if ($level === 'error' || $level === 'critical') {
            return false;
        }
        
        // Aggressively throttle cache-related messages - they're very spammy
        if (strpos($event, 'Cache hit') !== false || strpos($event, 'No cached heartbeat status found') !== false) {
            static $last_cache_log_time = 0;
            static $last_cache_status = '';
            $current_time = time();
            
            // Extract status from details array (more reliable than parsing event string)
            $current_status = isset($details['status']) ? $details['status'] : 'UNKNOWN';
            
            // Only log cache-related messages if:
            // 1. Status changed, OR  
            // 2. More than 5 minutes have passed, OR
            // 3. This is the first log of this type
            if ($current_status !== $last_cache_status || 
                ($current_time - $last_cache_log_time) > 300 ||
                $last_cache_log_time === 0) {
                    
                $last_cache_log_time = $current_time;
                $last_cache_status = $current_status;
                return false; // Don't throttle - log this one
            }
            
            return true; // Throttle this message
        }
        
        return false; // Don't throttle by default
    }
    
    /**
     * Store heartbeat errors in database for dashboard display
     */
    private function store_heartbeat_error_log($event, $details)
    {
        try {
            if (class_exists('Metasync_HeartBeat_Error_Monitor_Database')) {
                $error_db = new Metasync_HeartBeat_Error_Monitor_Database();
                $error_db->add(array(
                    'attribute_name' => 'heartbeat_connectivity',
                    'object_count' => 1,
                    'error_description' => json_encode(array(
                        'event' => $event,
                        'details' => $details,
                        'timestamp' => current_time('mysql')
                    )),
                    'created_at' => current_time('mysql')
                ));
            }
        } catch (Exception $e) {
            error_log('Failed to store heartbeat error log: ' . $e->getMessage());
        }
    }

    /**
     * Test actual heartbeat API connectivity using existing SyncCustomerParams
     * This ensures consistency with the working "Sync Now" functionality
     */
    private function test_heartbeat_api_connection($general_settings)
    {
        $searchatlas_api_key = $general_settings['searchatlas_api_key'] ?? '';
        $apikey = $general_settings['apikey'] ?? '';
        
        // Log the API call attempt
        $start_time = microtime(true);
        $api_key_type = strpos($searchatlas_api_key, 'pub-') === 0 ? 'publisher' : 'regular';
        
        $this->log_heartbeat('info', 'Initiating heartbeat API test using SyncCustomerParams', array(
            'api_key_type' => $api_key_type,
            'api_key_prefix' => substr($searchatlas_api_key, 0, 8) . '...',
            'url' => get_home_url(),
            'method' => 'reuse_existing_sync_class'
        ));
        
        // Use the existing, tested SyncCustomerParams method
        $sync_request = new Metasync_Sync_Requests();
        $response = $sync_request->SyncCustomerParams($apikey);
        
        $request_duration = round((microtime(true) - $start_time) * 1000, 2); // milliseconds
        
        // Check for HTTP errors
        if (is_wp_error($response)) {
            $this->log_heartbeat('error', 'Heartbeat test via SyncCustomerParams failed', array(
                'error_code' => $response->get_error_code(),
                'error_message' => $response->get_error_message(),
                'request_duration_ms' => $request_duration,
                'error_type' => 'wp_error'
            ));
            return false;
        }
        
        // Check HTTP status code
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            $this->log_heartbeat('error', 'Heartbeat test returned non-200 status', array(
                'status_code' => $status_code,
                'response_body' => $this->smart_truncate($body, 300),
                'request_duration_ms' => $request_duration,
                'error_type' => 'http_status_error'
            ));
            return false;
        }
        
        // Log successful heartbeat
        $this->log_heartbeat('info', 'Heartbeat API test successful via SyncCustomerParams', array(
            'status_code' => $status_code,
            'request_duration_ms' => $request_duration,
            'response_size_bytes' => strlen($body),
            'method' => 'sync_customer_params'
        ));
        
        // Update last successful heartbeat timestamp and granular otto_config_status
        $general_settings['send_auth_token_timestamp'] = current_time('mysql');
        $general_settings['last_heartbeat_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $options = Metasync::get_option();
        $options['general'] = $general_settings;
        Metasync::set_option($options);
        
        return true; // Heartbeat API is responding correctly
    }

    /**
     * Schedule heartbeat cron job on plugin activation
     * This should run every 2 hours in the background to reduce database load
     */
    public function schedule_heartbeat_cron()
    {
        // Clear any existing scheduled event first
        $this->unschedule_heartbeat_cron();
        
        // Schedule new cron job every 2 hours to reduce database load
        if (!wp_next_scheduled('metasync_heartbeat_cron_check')) {
            $scheduled = wp_schedule_event(time(), 'metasync_every_2_hours', 'metasync_heartbeat_cron_check');
            
            if ($scheduled) {
                $this->log_heartbeat('info', 'Heartbeat cron job scheduled successfully', array(
                    'interval' => '2 hours',
                    'next_run' => date('Y-m-d H:i:s T', wp_next_scheduled('metasync_heartbeat_cron_check'))
                ));
            } else {
                $this->log_heartbeat('error', 'Failed to schedule heartbeat cron job');
            }
        }
    }
    
    /**
     * Unschedule heartbeat cron job (plugin deactivation)
     */
    public function unschedule_heartbeat_cron()
    {
        $timestamp = wp_next_scheduled('metasync_heartbeat_cron_check');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'metasync_heartbeat_cron_check');
            $this->log_heartbeat('info', 'Heartbeat cron job unscheduled', array(
                'was_scheduled_for' => date('Y-m-d H:i:s T', $timestamp)
            ));
        }
    }
    
    /**
     * Background cron job execution - performs actual heartbeat check
     * This method should ONLY be called by the cron job, never by frontend
     */
    public function execute_heartbeat_cron_check()
    {
        $this->log_heartbeat('info', 'Background heartbeat cron check starting');
        
        // Get current settings
        $general_settings = Metasync::get_option('general') ?? [];
        
        // Pre-check: Skip API calls entirely if plugin API key is not configured
        $searchatlas_api_key = $general_settings['searchatlas_api_key'] ?? '';
        
        if (empty($searchatlas_api_key)) {
            $this->log_heartbeat('info', 'Skipping heartbeat API call - ' . Metasync::get_effective_plugin_name() . ' API key not configured', array(
                'has_searchatlas_api_key' => false,
                'reason' => 'User has not provided ' . Metasync::get_effective_plugin_name() . ' API key yet'
            ));
            
            // Cache DISCONNECTED status but don't make API calls
            $cache_data = array(
                'status' => false,
                'timestamp' => time(),
                'cached_until' => time() + 300,
                'updated_by' => 'cron_job_no_api_key'
            );
            
            set_transient('metasync_heartbeat_status_cache', $cache_data, 300);
            
            // Store last known connection state for graceful fallback
            $this->set_last_known_connection_state(false);
            
            $this->log_heartbeat('info', 'Background heartbeat check completed without API call', array(
                'status' => 'DISCONNECTED',
                'reason' => 'API key not configured',
                'cached_until' => date('Y-m-d H:i:s T', $cache_data['cached_until']),
                'next_cron_run' => wp_next_scheduled('metasync_heartbeat_cron_check') ? 
                                  date('Y-m-d H:i:s T', wp_next_scheduled('metasync_heartbeat_cron_check')) : 'N/A'
            ));
            
            return false;
        }
        
        // Plugin API key is available, proceed with heartbeat connectivity test
        $is_connected = $this->test_heartbeat_api_connection($general_settings);
        
        // Cache the result for 5 minutes (300 seconds)
        $cache_data = array(
            'status' => $is_connected,
            'timestamp' => time(),
            'cached_until' => time() + 300,
            'updated_by' => 'cron_job'
        );
        
        set_transient('metasync_heartbeat_status_cache', $cache_data, 300);
        
        // Store last known connection state for graceful fallback
        $this->set_last_known_connection_state($is_connected);
        
        $this->log_heartbeat('info', 'Background heartbeat check completed', array(
            'status' => $is_connected ? 'CONNECTED' : 'DISCONNECTED',
            'cached_until' => date('Y-m-d H:i:s T', $cache_data['cached_until']),
            'next_cron_run' => wp_next_scheduled('metasync_heartbeat_cron_check') ?
                              date('Y-m-d H:i:s T', wp_next_scheduled('metasync_heartbeat_cron_check')) : 'N/A'
        ));
        
        return $is_connected;
    }
    
    /**
     * Add custom cron schedule for 2-hour intervals and daily cleanup
     * This reduces database load by checking heartbeat less frequently
     */
    public function add_heartbeat_cron_schedule($schedules)
    {
        // Add custom 2-hour schedule to reduce database load
        $schedules['metasync_every_2_hours'] = array(
            'interval' => 2 * HOUR_IN_SECONDS, // 2 hours
            'display' => esc_html__('Every 2 Hours (MetaSync)', 'metasync')
        );
        
        // PR3: Burst mode intervals
        $schedules['metasync_every_2_minutes'] = array(
            'interval' => 2 * MINUTE_IN_SECONDS,
            'display' => esc_html__('Every 2 Minutes (MetaSync Burst)', 'metasync')
        );
        $schedules['metasync_every_5_minutes'] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => esc_html__('Every 5 Minutes (MetaSync)', 'metasync')
        );
        
        // Add daily cleanup schedule
        $schedules['metasync_daily_cleanup'] = array(
            'interval' => DAY_IN_SECONDS, // 24 hours
            'display' => esc_html__('Daily (MetaSync Cleanup)', 'metasync')
        );
        
        # Add weekly schedule for hidden post manager (7 days = 604800 seconds)
        $schedules['metasync_weekly'] = array(
            'interval' => 7 * DAY_IN_SECONDS, // 7 days
            'display' => esc_html__('Weekly (MetaSync)', 'metasync')
        );

        return $schedules;
    }
    
    /**
     * PR3: Get current heartbeat state (UNREGISTERED, REGISTERED_NO_KEY, KEY_PENDING, CONNECTED).
     */
    public function get_heartbeat_state()
    {
        $general = Metasync::get_option('general') ?? [];
        $api_key = $general['searchatlas_api_key'] ?? '';
        if (empty($api_key)) {
            return 'UNREGISTERED';
        }
        $state = $general['heartbeat_state'] ?? '';
        return ($state === 'CONNECTED') ? 'CONNECTED' : 'KEY_PENDING';
    }
    
    /**
     * PR3: Set state to KEY_PENDING and timestamp (when API key is added or rotated).
     */
    public function set_heartbeat_state_key_pending()
    {
        $options = Metasync::get_option();
        if (!isset($options['general'])) {
            $options['general'] = [];
        }
        $options['general']['heartbeat_state'] = 'KEY_PENDING';
        $options['general']['heartbeat_state_changed_at'] = time();
        Metasync::set_option($options);
        $this->maybe_schedule_heartbeat_cron();
    }
    
    /**
     * PR3: Burst cron — run heartbeat when state is KEY_PENDING.
     * After the 30-min burst window expires, downgrade to every-5-minutes schedule.
     */
    public function execute_burst_heartbeat()
    {
        if ($this->get_heartbeat_state() !== 'KEY_PENDING') {
            return;
        }
        $general = Metasync::get_option('general') ?? [];
        $state_changed_at = (int) ($general['heartbeat_state_changed_at'] ?? 0);
        $burst_window_end = $state_changed_at + (30 * 60);
        if ($burst_window_end < time()) {
            $this->unschedule_burst_heartbeat_cron();
            if (!wp_next_scheduled('metasync_heartbeat_cron_check')) {
                wp_schedule_event(time(), 'metasync_every_5_minutes', 'metasync_heartbeat_cron_check');
            }
            $this->execute_heartbeat_cron_check();
            return;
        }
        $this->execute_heartbeat_cron_check();
    }
    
    /**
     * PR3: Announce cron — send pre-SSO announce when state is UNREGISTERED (fallback every 5 min).
     */
    public function execute_announce_cron()
    {
        if ($this->get_heartbeat_state() !== 'UNREGISTERED') {
            return;
        }
        if (!class_exists('Metasync_Activator')) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-activator.php';
        }
        Metasync_Activator::send_announce_ping();
    }
    
    public function unschedule_burst_heartbeat_cron()
    {
        $timestamp = wp_next_scheduled('metasync_burst_heartbeat');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'metasync_burst_heartbeat');
        }
    }
    
    public function unschedule_announce_cron()
    {
        $timestamp = wp_next_scheduled('metasync_announce_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'metasync_announce_cron');
        }
    }
    
    /**
     * Maybe schedule heartbeat cron job based on PR3 state.
     * UNREGISTERED: announce cron every 5 min. KEY_PENDING: burst every 2 min. CONNECTED: 2-hour only.
     */
    public function maybe_schedule_heartbeat_cron()
    {
        $state = $this->get_heartbeat_state();
        
        if ($state === 'UNREGISTERED') {
            $this->unschedule_heartbeat_cron();
            $this->unschedule_burst_heartbeat_cron();
            if (!wp_next_scheduled('metasync_announce_cron')) {
                wp_schedule_event(time(), 'metasync_every_5_minutes', 'metasync_announce_cron');
            }
            return;
        }
        
        $this->unschedule_announce_cron();
        
        if ($state === 'KEY_PENDING') {
            $this->unschedule_heartbeat_cron();
            if (!wp_next_scheduled('metasync_burst_heartbeat')) {
                wp_schedule_event(time(), 'metasync_every_2_minutes', 'metasync_burst_heartbeat');
            }
            return;
        }
        
        if ($state === 'CONNECTED') {
            $this->unschedule_burst_heartbeat_cron();
            $this->schedule_heartbeat_cron();
        }
    }
    
    /**
     * Pre-SSO announce: send rate-limited ping when no API key yet (PR4).
     * Backend can show "Plugin detected, complete the connection". Client-side limit: 1 per hour per site.
     */
    public function maybe_send_pre_sso_announce()
    {
        $general = Metasync::get_option('general') ?? [];
        if (!empty($general['searchatlas_api_key'] ?? '')) {
            return;
        }
        $transient_key = 'metasync_announce_last_sent';
        if (get_transient($transient_key)) {
            return;
        }
        if (!class_exists('Metasync_Activator')) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-activator.php';
        }
        Metasync_Activator::send_announce_ping();
        set_transient($transient_key, time(), HOUR_IN_SECONDS);
    }
    
    /**
     * Trigger immediate heartbeat check (after authentication changes)
     * This bypasses the regular cron schedule to provide immediate feedback
     * Includes protection against multiple simultaneous triggers
     */
    public function trigger_immediate_heartbeat_check($context = 'Manual trigger')
    {
        // Prevent multiple simultaneous immediate checks (race condition protection)
        static $last_immediate_check = 0;
        $current_time = time();
        
        if (($current_time - $last_immediate_check) < 10) {
            $this->log_heartbeat('info', 'Skipping immediate heartbeat check - too recent', array(
                'context' => $context,
                'seconds_since_last' => $current_time - $last_immediate_check,
                'protection' => 'race_condition_prevention'
            ));
            return true; // Return the last known result
        }
        
        $last_immediate_check = $current_time;
        
        $this->log_heartbeat('info', 'Immediate heartbeat check triggered', array(
            'context' => $context,
            'triggered_by' => 'authentication_change'
        ));
        
        // Pre-check: Skip if plugin API key not configured (same logic as cron)
        $general_settings = Metasync::get_option('general') ?? [];
        $searchatlas_api_key = $general_settings['searchatlas_api_key'] ?? '';
        
        if (empty($searchatlas_api_key)) {
            $this->log_heartbeat('info', 'Skipping immediate heartbeat check - ' . Metasync::get_effective_plugin_name() . ' API key not configured', array(
                'context' => $context,
                'has_searchatlas_api_key' => false,
                'reason' => 'User has not provided API key yet'
            ));
            
            // Still clear cache and set DISCONNECTED status
            delete_transient('metasync_heartbeat_status_cache');
            
            $cache_data = array(
                'status' => false,
                'timestamp' => time(),
                'cached_until' => time() + 300,
                'updated_by' => 'immediate_check_no_api_key'
            );
            
            set_transient('metasync_heartbeat_status_cache', $cache_data, 300);
            
            // Store last known connection state for graceful fallback
            $this->set_last_known_connection_state(false);
            
            $this->log_heartbeat('info', 'Immediate heartbeat check completed without API call', array(
                'context' => $context,
                'result' => 'DISCONNECTED',
                'reason' => 'API key not configured',
                'cache_updated' => true
            ));
            
            return false;
        }
        
        // Clear any existing cache to force fresh check
        delete_transient('metasync_heartbeat_status_cache');
        
        // Execute the heartbeat check immediately  
        $result = $this->execute_heartbeat_cron_check();
        
        $this->log_heartbeat('info', 'Immediate heartbeat check completed', array(
            'context' => $context,
            'result' => $result ? 'CONNECTED' : 'DISCONNECTED',
            'cache_updated' => true
        ));
        
        return $result;
    }
    
    /**
     * Handle immediate heartbeat trigger action from other plugin components
     * This is triggered via WordPress action system for better decoupling
     * Note: Option change monitoring is now handled by the centralized API Key Monitor class
     */
    public function handle_immediate_heartbeat_trigger($context = 'WordPress action trigger')
    {
        $this->trigger_immediate_heartbeat_check($context);
    }
    
    /**
     * PR3: AJAX endpoint for burst ping (30s polling). Sends announce when UNREGISTERED or heartbeat when KEY_PENDING.
     */
    public function ajax_burst_ping()
    {
        if (!Metasync::current_user_has_plugin_access() || empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'metasync_burst_ping')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }
        $state = $this->get_heartbeat_state();
        if ($state === 'UNREGISTERED') {
            if (!class_exists('Metasync_Activator')) {
                require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-activator.php';
            }
            Metasync_Activator::send_announce_ping();
            wp_send_json_success(array('state' => 'UNREGISTERED', 'heartbeat_confirmed' => false));
            return;
        }
        if ($state === 'KEY_PENDING') {
            $_POST['is_heart_beat'] = true;
            $_POST['is_burst'] = true;
            $general = Metasync::get_option('general') ?? [];
            $apikey = $general['apikey'] ?? '';
            $sync = new Metasync_Sync_Requests();
            $sync->SyncCustomerParams($apikey);
            $state = $this->get_heartbeat_state();
            if ($state === 'CONNECTED') {
                $this->maybe_schedule_heartbeat_cron();
            }
            wp_send_json_success(array('state' => $state, 'heartbeat_confirmed' => ($state === 'CONNECTED')));
            return;
        }
        wp_send_json_success(array('state' => $state, 'heartbeat_confirmed' => true));
    }
    
    /**
     * Update heartbeat cache after sync operations
     * This ensures consistency between "Sync Now" and heartbeat cron status
     */
    public function update_heartbeat_cache_after_sync($is_connected, $context = 'Sync operation')
    {
        // Create cache data similar to cron job format
        $cache_data = array(
            'status' => $is_connected,
            'timestamp' => time(),
            'cached_until' => time() + 300, // 5 minutes
            'updated_by' => 'sync_operation'
        );
        
        // Update the same cache that heartbeat checks use
        set_transient('metasync_heartbeat_status_cache', $cache_data, 300);
        
        // Log the cache update for consistency
        $this->log_heartbeat('info', 'Heartbeat cache updated after sync operation', array(
            'context' => $context,
            'status' => $is_connected ? 'CONNECTED' : 'DISCONNECTED',
            'updated_by' => 'sync_operation',
            'cached_until' => date('Y-m-d H:i:s T', $cache_data['cached_until'])
        ));
        
        return $is_connected;
    }

    /**
     * Refresh Plugin Auth Token
     * Generates a new Plugin Auth Token and updates heartbeat API
     */
    public function refresh_plugin_auth_token()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'refresh_plugin_auth_token')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }
        
        // Check permissions
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        try {
            // Generate new Plugin Auth Token (alphanumeric only)
            $new_plugin_auth_token = wp_generate_password(32, false, false);
            
            // Update in options
            $options = Metasync::get_option();
            if (!isset($options['general'])) {
                $options['general'] = [];
            }
            $options['general']['apikey'] = $new_plugin_auth_token;
            
            // Save options
            $save_result = Metasync::set_option($options);
            
            if ($save_result) {
                // Use centralized API key event logging
                Metasync::log_api_key_event('token_refresh', 'plugin_auth_token', array(
                    'new_token_prefix' => substr($new_plugin_auth_token, 0, 8) . '...',
                    'triggered_by' => 'manual_refresh_button'
                ), 'info');
                
                // Trigger immediate heartbeat check with new plugin auth token
                // This validates that the new token works with the current plugin API key
                do_action('metasync_trigger_immediate_heartbeat', 'Plugin Auth Token refresh - new token generated');
                
                wp_send_json_success(array(
                    'new_token' => $new_plugin_auth_token,
                    'message' => 'Plugin Auth Token refreshed successfully'
                ));
            } else {
                wp_send_json_error(array('message' => 'Failed to save new token'));
            }
            
        } catch (Exception $e) {
            error_log('Plugin Auth Token Refresh Error: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error generating new token'));
        }
    }

    /**
     * Get current Plugin Auth Token (AJAX endpoint for UI updates)
     * Used to refresh the Plugin Auth Token field after Search Atlas connect authentication
     */
    public function get_plugin_auth_token()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'metasync_sa_connect_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }
        
        // Check permissions
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        try {
            // Get current Plugin Auth Token
            $options = Metasync::get_option();
            $current_plugin_auth_token = $options['general']['apikey'] ?? '';
            
            if (!empty($current_plugin_auth_token)) {
                wp_send_json_success(array(
                    'plugin_auth_token' => $current_plugin_auth_token,
                    'message' => 'Plugin Auth Token retrieved successfully'
                ));
            } else {
                wp_send_json_error(array('message' => 'Plugin Auth Token not found'));
            }
            
        } catch (Exception $e) {
            error_log('Get Plugin Auth Token Error: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error retrieving Plugin Auth Token'));
        }
    }

    /**
     * Reset Search Atlas Authentication
     * Clears all authentication data and tokens
     */
    public function reset_searchatlas_authentication()
    {
        // Verify nonce for CSRF protection
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'metasync_reset_auth_nonce')) {
            wp_send_json_error(array(
                'message' => 'Security verification failed. Please refresh the page and try again.',
                'code' => 'invalid_nonce'
            ));
            return;
        }

        // SECURITY FIX (CVE-2025-14386): Strict administrator capability check
        // Only administrators can reset authentication credentials
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => 'You do not have permission to reset authentication.',
                'code' => 'insufficient_permissions'
            ));
            return;
        }

        try {
            // Get current options
            $options = Metasync::get_option();
            
            if (!is_array($options)) {
                $options = array();
            }
            
            if (!isset($options['general'])) {
                $options['general'] = array();
            }

            // Clear Search Atlas authentication data
            $cleared_data = array();
            
            // Clear API key
            if (isset($options['general']['searchatlas_api_key'])) {
                $cleared_data['searchatlas_api_key'] = substr($options['general']['searchatlas_api_key'], 0, 8) . '...';
                unset($options['general']['searchatlas_api_key']);
            }
            
            // Clear UUID
            if (isset($options['general']['otto_pixel_uuid'])) {
                $cleared_data['otto_pixel_uuid'] = $options['general']['otto_pixel_uuid'];
                unset($options['general']['otto_pixel_uuid']);
            }
            
            // Note: OTTO SSR is always enabled by default, no need to clear
            
            // Note: dashboard_domain no longer stored in general settings
            // Domain is now either default production or whitelabel override
            
            // Clear authentication timestamps
            if (isset($options['general']['send_auth_token_timestamp'])) {
                $cleared_data['send_auth_token_timestamp'] = $options['general']['send_auth_token_timestamp'];
                unset($options['general']['send_auth_token_timestamp']);
            }
            
            if (isset($options['general']['last_heart_beat'])) {
                $cleared_data['last_heart_beat'] = $options['general']['last_heart_beat'];
                unset($options['general']['last_heart_beat']);
            }

            // Save updated options
            $save_result = Metasync::set_option($options);
            
            if (!$save_result) {
                throw new Exception('Failed to save updated plugin options');
            }

            // Clear WordPress Search Atlas connect token
            delete_option('metasync_wp_sa_connect_token');
            $cleared_data['wp_sa_connect_token'] = 'removed';

            // Clear any existing connect nonce tokens (deprecated with simplified tokens)
            $cleaned_tokens = $this->cleanup_searchatlas_nonce_tokens();
            $cleared_data['sa_connect_nonce_tokens'] = 'none (simplified token system)';

            // Clear any cached white label data
            delete_option(Metasync::option_name . '_whitelabel_user');
            $cleared_data['whitelabel_user'] = 'removed';

            // Clear whitelabel settings
            if (isset($options['whitelabel'])) {
                $cleared_data['whitelabel_settings'] = 'removed';
                unset($options['whitelabel']);
                
                // Re-save options after clearing whitelabel
                Metasync::set_option($options);
            }

            // Clear cached JWT tokens
            $this->clear_jwt_token_cache();
            $cleared_data['jwt_token_cache'] = 'cleared';

            // Clear rate limiting data
            $this->cleanup_searchatlas_rate_limits();
            $cleared_data['rate_limits'] = 'cleared';
            
            // Clear public hash cache when disconnecting
            $this->clear_public_hash_cache($cleared_data['otto_pixel_uuid'] ?? '');
            $cleared_data['public_hash_cache'] = 'cleared';
            
            // Clear heartbeat status cache when disconnecting
            delete_transient('metasync_heartbeat_status_cache');
            $cleared_data['heartbeat_cache'] = 'cleared';
            
            // Unschedule heartbeat cron since API key is being removed
            $this->unschedule_heartbeat_cron();

            // Log the reset action

            // Return success response
            wp_send_json_success(array(
                'message' => 'Authentication has been reset successfully. You can now connect a new account.',
                'cleared_data' => $cleared_data,
                'timestamp' => current_time('mysql', true)
            ));

        } catch (Exception $e) {
            error_log('Authentication Reset Error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => 'An error occurred while resetting authentication. Please try again or contact support.',
                'code' => 'reset_failed',
                'error' => $e->getMessage()
            ));
        }
    }

    /**
     * Cleanup expired or orphaned Search Atlas connect nonce tokens
     * DEPRECATED: No longer needed with simplified token system
     */
    private function cleanup_searchatlas_nonce_tokens()
    {
        // No nonce tokens are stored with simplified system
        return 0;
    }

    /**
     * Cleanup Search Atlas connect rate limiting data
     */
    private function cleanup_searchatlas_rate_limits()
    {
        global $wpdb;
        
        try {
            // Find all Search Atlas connect rate limit transients
            $rate_limit_transients = $wpdb->get_results(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_sa_connect_rate_limit_%'",
                ARRAY_A
            );
            
            $cleaned_count = 0;
            
            foreach ($rate_limit_transients as $transient) {
                $transient_name = str_replace('_transient_', '', $transient['option_name']);
                delete_transient($transient_name);
                $cleaned_count++;
            }
            
            return $cleaned_count;
            
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Helper method to get available menu items based on configuration
     * This ensures WordPress submenu and internal navigation stay in sync
     * Items are grouped into 'seo' (SEO Features) and 'plugin' (Plugin) categories
     */
    private function get_available_menu_items()
    {
        $general_options = Metasync::get_option('general') ?? [];
        $has_api_key = !empty($general_options['searchatlas_api_key'] ?? '');
        $has_uuid = !empty($general_options['otto_pixel_uuid'] ?? '');
        $is_fully_connected = $this->is_heartbeat_connected($general_options);

        $menu_items = [];

        // === SEO FEATURES GROUP ===
        
        // Dashboard (check access control)
        if (Metasync_Access_Control::user_can_access('hide_dashboard')) {
            $menu_items['dashboard'] = [
                'title' => 'Dashboard',
                'slug_suffix' => '-dashboard',
                'callback' => 'create_admin_dashboard_iframe',
                'internal_nav' => 'Dashboard',
                'group' => 'seo'
            ];
        }
        
        // Indexation Control (check access control)
        if (Metasync_Access_Control::user_can_access('hide_indexation_control')) {
            $menu_items['seo_controls'] = [
                'title' => 'Indexation',
                'slug_suffix' => '-seo-controls',
                'callback' => 'create_admin_seo_controls_page',
                'internal_nav' => 'Indexation Control',
                'group' => 'seo'
            ];
        }
        
        // Instant Indexing - setting now stored in seo_controls (moved from Settings to Indexation Control)
        $seo_controls = Metasync::get_option('seo_controls');
        if ($seo_controls['enable_googleinstantindex'] ?? false) {
            $menu_items['instant_index'] = [
                'title' => 'Instant Indexing',
                'slug_suffix' => '-instant-index',
                'callback' => 'create_admin_google_instant_index_page',
                'internal_nav' => 'Instant Indexing',
                'group' => 'seo'
            ];
        }
        
        if ($general_options['enable_google_console'] ?? false) {
            $menu_items['google_console'] = [
                'title' => 'Google Console',
                'slug_suffix' => '-google-console',
                'callback' => 'create_admin_google_console_page',
                'internal_nav' => 'Google Console',
                'group' => 'seo'
            ];
        }

        if ($general_options['enable_bing_console'] ?? false) {
            $menu_items['bing_console'] = [
                'title' => 'Bing Console',
                'slug_suffix' => '-bing-console',
                'callback' => 'create_admin_bing_console_page',
                'internal_nav' => 'Bing Console',
                'group' => 'seo'
            ];
        }

        // Redirections page (check access control)
        if (Metasync_Access_Control::user_can_access('hide_redirections')) {
            $menu_items['redirections'] = [
                'title' => 'Redirections',
                'slug_suffix' => '-redirections',
                'callback' => 'create_admin_redirections_page',
                'internal_nav' => 'Redirections',
                'group' => 'seo'
            ];
        }

        // Robots.txt page (check access control)
        if (Metasync_Access_Control::user_can_access('hide_robots')) {
            $menu_items['robots_txt'] = [
                'title' => 'Robots.txt',
                'slug_suffix' => '-robots-txt',
                'callback' => 'create_admin_robots_txt_page',
                'internal_nav' => 'Robots.txt',
                'group' => 'seo'
            ];
        }

        // XML Sitemap page (check access control)
        if (Metasync_Access_Control::user_can_access('hide_xml_sitemap')) {
            $menu_items['xml_sitemap'] = [
                'title' => 'XML Sitemap',
                'slug_suffix' => '-xml-sitemap',
                'callback' => 'create_admin_xml_sitemap_page',
                'internal_nav' => 'XML Sitemap',
                'group' => 'seo'
            ];
        }
        
        // Import SEO Data page (check access control)
        if (Metasync_Access_Control::user_can_access('hide_import_seo')) {
            $menu_items['import_seo'] = [
                'title' => 'Import SEO Data',
                'slug_suffix' => '-import-external',
                'callback' => 'render_import_external_data_page',
                'internal_nav' => 'Import SEO Data',
                'group' => 'seo'
            ];
        }

        // === PLUGIN GROUP ===

        // Settings (renamed from General and moved after Dashboard) (check access control)
        if (Metasync_Access_Control::user_can_access('hide_settings')) {
            $menu_items['general'] = [
                'title' => 'Settings',
                'slug_suffix' => '',
                'callback' => 'create_admin_settings_page',
                'internal_nav' => 'General Settings',
                'group' => 'plugin'
            ];
        }
        
        if ($general_options['enable_optimal_settings'] ?? false) {
            $menu_items['optimal_settings'] = [
                'title' => 'Optimal Settings',
                'slug_suffix' => '-optimal-settings',
                'callback' => 'create_admin_optimal_settings_page',
                'internal_nav' => 'Optimal Settings',
                'group' => 'plugin'
            ];
        }
        
        // Always available - Error Logs
        // Error Logs moved to Advanced settings tab - no longer separate page

        // Compatibility page (check access control)
        if (Metasync_Access_Control::user_can_access('hide_compatibility')) {
            $menu_items['compatibility'] = [
                'title' => 'Compatibility',
                'slug_suffix' => '-compatibility',
                'callback' => 'create_admin_compatibility_page',
                'internal_nav' => 'Compatibility',
                'group' => 'plugin'
            ];
        }

        // Sync Log page (check access control)
        if (Metasync_Access_Control::user_can_access('hide_sync_log')) {
            $menu_items['sync_log'] = [
                'title' => 'Sync Log',
                'slug_suffix' => '-sync-log',
                'callback' => 'create_admin_sync_log_page',
                'internal_nav' => 'Sync Log',
                'group' => 'plugin'
            ];
        }

        // Custom HTML Pages (check access control)
        if (Metasync_Access_Control::user_can_access('hide_custom_pages')) {
            $menu_items['custom_pages'] = [
                'title' => 'Custom Pages',
                'slug_suffix' => '-custom-pages',
                'callback' => 'create_admin_custom_pages_page',
                'internal_nav' => 'Custom HTML Pages',
                'group' => 'plugin'
            ];
        }

        // Bot Statistics (always available)
        $menu_items['bot_statistics'] = [
            'title' => 'Bot Statistics',
            'slug_suffix' => '-bot-statistics',
            'callback' => 'create_admin_bot_statistics_page',
            'internal_nav' => 'Bot Statistics',
            'group' => 'plugin'
        ];

        // Report Issue page (check access control)
        if (Metasync_Access_Control::user_can_access('hide_report_issue')) {
            $menu_items['report_issue'] = [
                'title' => 'Report Issue',
                'slug_suffix' => '-report-issue',
                'callback' => 'create_admin_report_issue_page',
                'internal_nav' => 'Report Issue',
                'group' => 'plugin'
            ];
        }

        return $menu_items;
    }

    /**
     * Add options page
     */
    public function add_plugin_settings_page()
    {
        # Check if current user has plugin access based on role settings
        if (!$this->current_user_has_plugin_access()) {
            return; // Don't add any menu items for users without access
        }

        $data= Metasync::get_option('general');
        // Use centralized method for getting effective plugin name
        $plugin_name = Metasync::get_effective_plugin_name();
        $menu_name = $plugin_name;
        $menu_title = $plugin_name;
        $menu_slug = !isset($data['white_label_plugin_menu_slug']) || $data['white_label_plugin_menu_slug']==""  ?  self::$page_slug : $data['white_label_plugin_menu_slug'];
        $menu_icon = !isset($data['white_label_plugin_menu_icon']) ||  $data['white_label_plugin_menu_icon'] =="" ? 'dashicons-searchatlas' : $data['white_label_plugin_menu_icon'];
       
        // Use 'read' capability since actual access is controlled by current_user_has_plugin_access() check above
        // This allows the "User Roles with Plugin Access" setting to fully control who sees the menu
        $menu_capability = 'read';
        
        // Main menu page - Settings (default)
        add_menu_page(
            $menu_name,
            $menu_title,
            $menu_capability,
            $menu_slug,
            array($this, 'create_admin_settings_page'), // Main page is Settings
            $menu_icon
        );

        // Check connection status for submenu availability
        $general_options = Metasync::get_option('general');
        $has_api_key = !empty($general_options['searchatlas_api_key']);
        $has_uuid = !empty($general_options['otto_pixel_uuid']);
        $is_fully_connected = $this->is_heartbeat_connected($general_options);

        // Add Dashboard submenu (check access control)
        if (Metasync_Access_Control::user_can_access('hide_dashboard')) {
            add_submenu_page(
                $menu_slug,
                'Dashboard',
                'Dashboard',
                $menu_capability,
                $menu_slug . '-dashboard',
                array($this, 'create_admin_dashboard_iframe')
            );
        }

        // Add Compatibility submenu (check access control)
        if (Metasync_Access_Control::user_can_access('hide_compatibility')) {
            add_submenu_page(
                $menu_slug,
                'Compatibility',
                'Compatibility',
                $menu_capability,
                $menu_slug . '-compatibility',
                array($this, 'create_admin_compatibility_page')
            );
        }

        // Sync Log page (check access control)
        if (Metasync_Access_Control::user_can_access('hide_sync_log')) {
            add_submenu_page(
                $menu_slug,
                'Sync Log',
                'Sync Log',
                $menu_capability,
                $menu_slug . '-sync-log',
                array($this, 'create_admin_sync_log_page')
            );
        }

        // Indexation Control (check access control)
        if (Metasync_Access_Control::user_can_access('hide_indexation_control')) {
            add_submenu_page(
                $menu_slug,
                'Indexation Control',
                'Indexation Control',
                $menu_capability,
                $menu_slug . '-seo-controls',
                array($this, 'create_admin_seo_controls_page')
            );
        }


        // Rename the auto-generated first submenu item from plugin name to "Settings"
        // WordPress automatically creates a submenu with the main menu name
        add_action('admin_menu', function() use ($menu_slug) {
            global $submenu;
            if (isset($submenu[$menu_slug])) {
                // Find and rename/remove the auto-generated submenu item
                foreach ($submenu[$menu_slug] as $key => $item) {
                    if ($item[2] === $menu_slug) { // Main menu item
                        // Check if Settings should be hidden
                        if (!Metasync_Access_Control::user_can_access('hide_settings')) {
                            unset($submenu[$menu_slug][$key]);
                        } else {
                            $submenu[$menu_slug][$key][0] = 'Settings';
                        }
                        break;
                    }
                }

                // Ensure proper ordering: Dashboard first, then Settings
                if (count($submenu[$menu_slug]) > 1) {
                    // Sort to ensure Dashboard comes first
                    usort($submenu[$menu_slug], function($a, $b) {
                        if (strpos($a[2], '-dashboard') !== false) return -1; // Dashboard first
                        if (strpos($b[2], '-dashboard') !== false) return 1;
                        return 0;
                    });
                }
            }
        }, 999); // High priority to run after all menus are added

        // Additional conditional features (commented out for now - can be enabled based on settings)
        // if(@Metasync::get_option('general')['enable_404monitor'])
        // add_submenu_page($menu_slug, '404 Monitor', '404 Monitor', 'manage_options', $menu_slug . '-404-monitor', array($this, 'create_admin_404_monitor_page'));

        // if(@Metasync::get_option('general')['enable_siteverification'])
        // add_submenu_page($menu_slug, 'Site Verification', 'Site Verification', 'manage_options', $menu_slug . '-search-engine-verify', array($this, 'create_admin_search_engine_verification_page'));

        // if(@Metasync::get_option('general')['enable_localbusiness'])
        // add_submenu_page($menu_slug, 'Local Business', 'Local Business', 'manage_options', $menu_slug . '-local-business', array($this, 'create_admin_local_business_page'));

        // if(@Metasync::get_option('general')['enable_codesnippets'])
        // add_submenu_page($menu_slug, 'Code Snippets', 'Code Snippets', 'manage_options', $menu_slug . '-code-snippets', array($this, 'create_admin_code_snippets_page'));

        // if(@Metasync::get_option('general')['enable_globalsettings'])
        // add_submenu_page($menu_slug, 'Global Settings', 'Global Settings', 'manage_options', $menu_slug . '-common-settings', array($this, 'create_admin_global_settings_page'));

        // if(@Metasync::get_option('general')['enable_commonmetastatus'])
        // add_submenu_page($menu_slug, 'Common Meta Status', 'Common Meta Status', 'manage_options', $menu_slug . '-common-meta-settings', array($this, 'create_admin_common_meta_settings_page'));

        // if(@Metasync::get_option('general')['enable_socialmeta'])
        // add_submenu_page($menu_slug, 'Social Meta', 'Social Meta', 'manage_options', $menu_slug . '-social-meta', array($this, 'create_admin_social_meta_page'));

        // Redirections (check access control)
        if (Metasync_Access_Control::user_can_access('hide_redirections')) {
            add_submenu_page($menu_slug, 'Redirections', 'Redirections', $menu_capability, $menu_slug . '-redirections', array($this, 'create_admin_redirections_page'));
        }

        // XML Sitemap (check access control)
        if (Metasync_Access_Control::user_can_access('hide_xml_sitemap')) {
            add_submenu_page($menu_slug, 'XML Sitemap', 'XML Sitemap', $menu_capability, $menu_slug . '-xml-sitemap', array($this, 'create_admin_xml_sitemap_page'));
        }

        // Robots.txt (check access control)
        if (Metasync_Access_Control::user_can_access('hide_robots')) {
            add_submenu_page($menu_slug, 'Robots.txt', 'Robots.txt', $menu_capability, $menu_slug . '-robots-txt', array($this, 'create_admin_robots_txt_page'));
        }

        // Bot Statistics (hidden from sidebar - accessible via Plugin dropdown in grouped nav)
        add_submenu_page(null, 'Bot Statistics', 'Bot Statistics', $menu_capability, $menu_slug . '-bot-statistics', array($this, 'create_admin_bot_statistics_page'));

        // Import SEO Data (check access control)
        if (Metasync_Access_Control::user_can_access('hide_import_seo')) {
            add_submenu_page($menu_slug, 'Import SEO Data', 'Import SEO Data', $menu_capability, $menu_slug . '-import-external', array($this, 'render_import_external_data_page'));
        }

        // Custom Pages (check access control)
        if (Metasync_Access_Control::user_can_access('hide_custom_pages')) {
            add_submenu_page($menu_slug, 'Custom Pages', 'Custom Pages', $menu_capability, $menu_slug . '-custom-pages', array($this, 'create_admin_custom_pages_page'));
        }

        // Report Issue (check access control)
        if (Metasync_Access_Control::user_can_access('hide_report_issue')) {
            add_submenu_page($menu_slug, 'Report Issue', 'Report Issue', $menu_capability, $menu_slug . '-report-issue', array($this, 'create_admin_report_issue_page'));
        }

        # Add Setup Wizard as a hidden page (accessible via dashboard card only)
        add_submenu_page('', 'Setup Wizard', 'Setup Wizard', $menu_capability, $menu_slug . '-setup-wizard', array($this->setup_wizard, 'render_wizard_page'));

        // Add 404 Monitor as a direct page (not submenu)
        add_submenu_page('', '404 Monitor', '404 Monitor', $menu_capability, $menu_slug . '-404-monitor', array($this, 'create_admin_404_monitor_page'));

        // Google Instant Indexing (conditional - check if enabled)
        $seo_controls = Metasync::get_option('seo_controls');
        if ($seo_controls['enable_googleinstantindex'] ?? false) {
            add_submenu_page($menu_slug, 'Instant Indexing', 'Instant Indexing', $menu_capability, $menu_slug . '-instant-index', array($this, 'create_admin_google_instant_index_page'));
        }

        // Google Console (conditional - check if enabled)
        if ($general_options['enable_google_console'] ?? false) {
            add_submenu_page($menu_slug, 'Google Console', 'Google Console', $menu_capability, $menu_slug . '-google-console', array($this, 'create_admin_google_console_page'));
        }

        // Bing Console (conditional - hidden page, only accessible via direct link)
        // Note: Using null parent to hide from main menu, but still make accessible
        if ($seo_controls['enable_binginstantindex'] ?? false) {
            add_submenu_page('', 'Bing Console', 'Bing Console', $menu_capability, $menu_slug . '-bing-console', array($this, 'create_admin_bing_console_page'));
        }

    }

    /**
     * General Options page callback
     */
    public function create_admin_settings_page()
    {
        # define the active tab
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';

        # Get whitelabel settings for use throughout the page
        $whitelabel_settings = Metasync::get_whitelabel_settings();

        # Determine which tabs need password protection
        $user_password = $whitelabel_settings['settings_password'] ?? '';
        $hide_settings_enabled = !empty($whitelabel_settings['hide_settings']);

        // Tabs that require password protection
        $protected_tabs = []; // Start with empty array

        // Always protect whitelabel tab if password is set
        if (!empty($user_password)) {
            $protected_tabs[] = 'whitelabel';
        }

        // If Hide Settings is enabled with password, protect all settings tabs
        if ($hide_settings_enabled && !empty($user_password)) {
            $protected_tabs = ['general', 'whitelabel', 'advanced'];
        }

        // Initialize password protection variables
        $password_protection_enabled = false;
        $password_validated = false;
        $password_error = '';

        # Handle password protection display logic for protected tabs
        if (in_array($active_tab, $protected_tabs)) {

            // Check if password protection is needed
            $password_protection_enabled = !empty($user_password);

            // Check validation status using Auth Manager
            $auth = new Metasync_Auth_Manager('whitelabel', 1800);
            $password_validated = $auth->has_access();

            // Set error message based on authentication status
            if (isset($_POST['whitelabel_password_submit']) && !$password_validated) {
                $password_error = 'Incorrect password. Please try again.';
            }
        }

        // Helper variable for displaying authenticated UI elements (lock buttons, success messages)
        // This replaces all $_SESSION['whitelabel_access_granted'] checks throughout the page
        $is_authenticated = $password_validated;

        # Use whitelabel OTTO name if configured, fallback to 'OTTO'
        $whitelabel_otto_name = Metasync::get_whitelabel_otto_name();
        
        # get page slug (use original format)
        $page_slug = self::$page_slug;
    ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->render_plugin_header('Settings'); ?>
        
        <?php
        /*
        # Temporarily commented out: Clear Cache notice and button (can be re-enabled later)

        *    <div class="notice notice-success">
        *        <p>
        *            <b>Clear all caches at once</b><br/>
        *            This will slow down your site until caches are rebuilt
        *            <button style="margin-left: 15px;" type ="button" class="button" id="clear_otto_caches" data-toggle="tooltip" data-placement="top" title="Clear all <?php echo $whitelabel_otto_name;?> Caches">Clear <?php echo $whitelabel_otto_name;?> Cache</button>
        *        </p>
        *    </div> 
        */
        ?>
        
        <?php $this->render_navigation_menu('general'); ?>
        
        <?php
            // Handle password protection display for all protected tabs
            if (in_array($active_tab, $protected_tabs)) {

                // Show password form only if protection is enabled AND not validated
                if ($password_protection_enabled && !$password_validated) {
                    // Show password entry form (outside main form)
        ?>
                    <div class="dashboard-card" style="max-width: 500px; margin: 0 auto;">
                        <h2 style="text-align: center;">🔐 Protected Section</h2>
                        <p style="color: var(--dashboard-text-secondary); margin-bottom: 30px; text-align: center;">
                            <?php
                            $plugin_name = Metasync::get_effective_plugin_name('');
                            if ($hide_settings_enabled && !empty($user_password)) {
                                printf('Please enter the password to access the %s Settings section.', esc_html($plugin_name));
                            } else {
                                echo 'Please enter the password to access the Branding section.';
                            }
                            ?>
                        </p>
                        
                        <?php if (!empty($password_error)): ?>
                            <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
                                <strong>❌ Access Denied:</strong> <?php echo esc_html($password_error); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="post" action="" style="max-width: 400px; margin: 0 auto;">
                            <?php wp_nonce_field('whitelabel_password_nonce', 'whitelabel_nonce'); ?>
                            
                            <div style="margin-bottom: 20px;">
                                <label for="whitelabel_password" style="display: block; font-weight: 600; margin-bottom: 8px; color: var(--dashboard-text-primary);">
                                    🔑 Validate Password
                                </label>
                                <input
                                    type="password"
                                    id="whitelabel_password"
                                    name="whitelabel_password"
                                    placeholder="Enter password to access protected settings"
                                    style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px; box-sizing: border-box;"
                                    required
                                    autocomplete="off"
                                />
                            </div>
                            
                            <div style="text-align: center;">
                                <button
                                    type="submit"
                                    name="whitelabel_password_submit"
                                    value="1"
                                    class="button button-primary"
                                    style="padding: 12px 24px; font-size: 14px; font-weight: 600;"
                                >
                                    🚀 Submit Password
                                </button>
                            </div>
                        </form>

                        <div style="text-align: center; margin-top: 20px;">
                            <a href="#" id="metasync-forgot-password-link" style="color: #2271b1; text-decoration: none; font-size: 14px;">
                                🔓 Forgot Password?
                            </a>
                            <div id="metasync-recovery-message" style="margin-top: 15px; padding: 12px; border-radius: 6px; display: none;"></div>
                        </div>

                    </div>
                    
                    <script>
                    jQuery(document).ready(function($) {
                        // Focus on password field when page loads
                        $('#whitelabel_password').focus();

                        // Add enter key support
                        $('#whitelabel_password').on('keypress', function(e) {
                            if (e.which === 13) {
                                $(this).closest('form').submit();
                            }
                        });

                        // Handle forgot password link
                        $('#metasync-forgot-password-link').on('click', function(e) {
                            e.preventDefault();

                            var $link = $(this);
                            var $message = $('#metasync-recovery-message');

                            // Disable link and show loading state
                            $link.css('pointer-events', 'none').css('opacity', '0.6');
                            $message.removeClass('success error').hide();
                            $message.html('⏳ Sending recovery email...').css('background', '#f0f6fc').css('color', '#0c5ba5').css('border', '1px solid #cfe2f3').fadeIn(200);

                            // Send AJAX request
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'metasync_recover_password',
                                    nonce: '<?php echo wp_create_nonce('metasync_recover_password_nonce'); ?>'
                                },
                                success: function(response) {
                                    $link.css('pointer-events', 'auto').css('opacity', '1');

                                    if (response.success) {
                                        $message.addClass('success').html('✅ ' + response.data.message)
                                            .css('background', '#d4edda')
                                            .css('color', '#155724')
                                            .css('border', '1px solid #c3e6cb');
                                    } else {
                                        $message.addClass('error').html('❌ ' + response.data.message)
                                            .css('background', '#f8d7da')
                                            .css('color', '#721c24')
                                            .css('border', '1px solid #f5c6cb');
                                    }
                                },
                                error: function() {
                                    $link.css('pointer-events', 'auto').css('opacity', '1');
                                    $message.addClass('error').html('❌ An error occurred. Please try again.')
                                        .css('background', '#f8d7da')
                                        .css('color', '#721c24')
                                        .css('border', '1px solid #f5c6cb');
                                }
                            });
                        });
                    });
                    </script>
        <?php
                    return; // Stop processing and don't show the main form
                } elseif (!$password_protection_enabled) {
                    // No password protection set, allow access but show info message
        ?>
                    <div class="notice notice-info" style="margin: 15px 0;">
                        <p>
                            <strong>💡 Security Tip:</strong> You can set a custom password in the White Label Settings to protect this section.
                        </p>
                    </div>
        <?php
                }
            }
        ?>

            <form method="post" action="options.php?tab=<?php echo $active_tab?>" id="metaSyncGeneralSetting">
                <?php
                    settings_fields($this::option_group);

                    # Add a nonce field for security - needed for both General and Advanced tabs
                    wp_nonce_field('meta_sync_general_setting_nonce', 'meta_sync_nonce');

                    if ($active_tab == 'general') {
                ?>
                    <div class="dashboard-card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <div style="flex: 1;">
                                <h2 style="margin: 0;">🔧 General Configuration</h2>
                                <p style="color: var(--dashboard-text-secondary); margin: 5px 0 0 0;">Configure your <?php echo esc_html(Metasync::get_effective_plugin_name()); ?> API, plugin features, caching, and general settings.</p>
                            </div>
                            <?php if ($hide_settings_enabled && !empty($user_password) && $is_authenticated): ?>
                            <div>
                                <?php $this->render_lock_button('general'); ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($hide_settings_enabled && !empty($user_password) && $is_authenticated): ?>
                        <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 20px;">
                            <strong>✅ Access Granted:</strong> You have successfully authenticated and can now modify settings.
                        </div>
                        <?php endif; ?>

                        <?php
                        # Render accordion-based settings sections
                        $this->render_accordion_sections(self::$page_slug . '_general');
                        ?>
                    </div>

                    <div class="dashboard-card">
                        <h2>🔄 Content Genius Synchronization</h2>
                        <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Sync your categories and user/author data with <?php echo esc_html(Metasync::get_effective_plugin_name()); ?>.</p>
                        <button type="button" class="button button-primary" id="sendAuthToken" data-toggle="tooltip" data-placement="top" title="Sync Categories and User">
                            🔄 Sync Now
                        </button>
                    </div>

                    <div class="dashboard-card">
                        <h2>🚀 Setup Wizard</h2>
                        <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Run the setup wizard to configure your plugin, import from other SEO plugins, and optimize your settings in just a few minutes.</p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::$page_slug . '-setup-wizard')); ?>" class="button button-primary" style="text-decoration: none;">
                            ✨ Start Setup Wizard
                        </a>
                    </div>
                <?php
                    } elseif ($active_tab == 'whitelabel') {
                        // This section only shows if password was validated above
                        // Get user-set whitelabel password to check if protection is enabled
                        $whitelabel_settings = Metasync::get_whitelabel_settings();
                        $user_password = $whitelabel_settings['settings_password'] ?? '';
                        $password_protection_enabled = !empty($user_password);
                ?>
                    <div class="dashboard-card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <div>
                                <h2>🎨 White Label Branding</h2>
                                <p style="color: var(--dashboard-text-secondary); margin: 5px 0 0 0;">Customize the plugin appearance with your own branding and logo.</p>
                            </div>
                            <?php if ($password_protection_enabled && $is_authenticated): ?>
                            <div>
                                <?php $this->render_lock_button('whitelabel'); ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($password_protection_enabled && $is_authenticated): ?>
                        <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong>✅ Access Granted:</strong> You have successfully authenticated and can now modify white label settings.
                            </div>
                        </div>

                        <?php endif; ?>
                        
                        <?php
                        # do the whitelabel branding section (only SECTION_METASYNC, not SECTION_PLUGIN_VISIBILITY)
                        do_settings_sections(self::$page_slug . '_branding');
                        ?>
                    </div>

                    <!-- Export Whitelabel Settings section -->
                    <div class="dashboard-card">
                        <h2>📦 Export Whitelabel Plugin</h2>
                        <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Export the entire plugin with all whitelabel settings pre-configured. This creates a complete plugin zip file ready for installation on another WordPress site.</p>
                        <button type="button" class="button button-primary" id="metasync-export-whitelabel-btn" style="padding: 10px 20px; font-size: 14px; font-weight: 600;">
                            📥 Export Plugin with Whitelabel Settings
                        </button>
                        <p class="description" style="margin-top: 10px;">This will create a zip file containing the complete plugin with all your whitelabel settings included. Upload and install this zip file on another WordPress site via Plugins → Add New → Upload Plugin. All whitelabel configurations will be automatically applied upon activation.</p>
                    </div>

                    <!-- Advanced Access Control section -->
                    <?php Metasync_Access_Control_UI::render_access_control_table(); ?>

                    <!-- Custom Modal for Alerts -->
                    <div id="metasync-custom-modal" style="display: none; position: fixed; z-index: 999999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.6);">
                        <div style="position: relative; margin: 10% auto; max-width: 500px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 2px; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); animation: modalSlideIn 0.3s ease-out;">
                            <div style="background: white; border-radius: 10px; padding: 0; overflow: hidden;">
                                <!-- Header -->
                                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 24px; text-align: center;">
                                    <div id="modal-icon" style="font-size: 48px; margin-bottom: 12px;">⚠️</div>
                                    <h2 id="modal-title" style="color: white; margin: 0; font-size: 24px; font-weight: 600;">Password Required</h2>
                                </div>

                                <!-- Body -->
                                <div style="padding: 32px 24px;">
                                    <p id="modal-message" style="color: #4a5568; font-size: 15px; line-height: 1.6; text-align: center; margin: 0;">
                                        You must set a White Label Settings Password before enabling "Hide Settings".
                                    </p>
                                </div>

                                <!-- Footer -->
                                <div style="padding: 0 24px 24px; text-align: center;">
                                    <button type="button" id="modal-close-btn" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 12px 32px; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4); transition: all 0.3s ease;">
                                        Got It
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <style>
                        @keyframes modalSlideIn {
                            from {
                                opacity: 0;
                                transform: translateY(-50px);
                            }
                            to {
                                opacity: 1;
                                transform: translateY(0);
                            }
                        }

                        #modal-close-btn:hover {
                            transform: translateY(-2px);
                            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
                        }
                    </style>

                    <script>
                    jQuery(document).ready(function($) {
                        var isShowingModal = false;

                        // Function to show custom modal
                        function showModal(icon, title, message) {
                            isShowingModal = true;
                            $('#modal-icon').text(icon);
                            $('#modal-title').text(title);
                            $('#modal-message').html(message);
                            $('#metasync-custom-modal').fadeIn(200);

                            // Reset flag after modal animation
                            setTimeout(function() {
                                isShowingModal = false;
                            }, 300);
                        }

                        // Close modal
                        $('#modal-close-btn').on('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            $('#metasync-custom-modal').fadeOut(200);
                            return false;
                        });

                        // Close modal when clicking backdrop
                        $('#metasync-custom-modal').on('click', function(e) {
                            if (e.target === this) {
                                $(this).fadeOut(200);
                            }
                        });

                        // Function to check if password is set
                        function hasPassword() {
                            var passwordField = $('input[name="<?php echo $this::option_key; ?>[whitelabel][settings_password]"]');
                            var passwordValue = passwordField.val();
                            return passwordValue && passwordValue.length > 0;
                        }

                        // Function to check if recovery email is set
                        function hasRecoveryEmail() {
                            var recoveryEmailField = $('input[name="<?php echo $this::option_key; ?>[whitelabel][recovery_email]"]');
                            var emailValue = recoveryEmailField.val();
                            return emailValue && emailValue.length > 0 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue);
                        }

                        // Validate recovery email when password is being set
                        var passwordField = $('input[name="<?php echo $this::option_key; ?>[whitelabel][settings_password]"]');
                        var recoveryEmailField = $('input[name="<?php echo $this::option_key; ?>[whitelabel][recovery_email]"]');

                        // Real-time validation for recovery email
                        passwordField.on('blur', function() {
                            if (hasPassword() && !hasRecoveryEmail()) {
                                showModal(
                                    '📧',
                                    'Recovery Email Required',
                                    'You must set a <strong>Recovery Email</strong> when setting a password.<br><br>Please enter a valid email address in the <strong>Recovery Email</strong> field.'
                                );
                                recoveryEmailField.focus();
                            }
                        });

                        // Mark recovery email as required when password is set
                        passwordField.on('input', function() {
                            if (hasPassword()) {
                                recoveryEmailField.attr('required', true);
                                recoveryEmailField.closest('tr').find('th').addClass('required-field');
                            } else {
                                recoveryEmailField.removeAttr('required');
                                recoveryEmailField.closest('tr').find('th').removeClass('required-field');
                            }
                        });

                        // Trigger initial check
                        passwordField.trigger('input');

                        // Store original checkbox state
                        var hideSettingsCheckbox = $('#checkbox_hide_settings');
                        var originalCheckboxState = hideSettingsCheckbox.is(':checked');

                        // Handle Hide Settings checkbox with click event (runs before change)
                        hideSettingsCheckbox.on('click', function(e) {
                            if (!originalCheckboxState && !hasPassword()) {
                                // Prevent the click from checking the box
                                e.preventDefault();
                                e.stopImmediatePropagation();

                                // Show modal asking to set password
                                showModal(
                                    '🔐',
                                    'Password Required',
                                    'You must set a <strong>Settings Password</strong> before enabling "Hide Settings".<br><br>Please scroll up to the <strong>Branding</strong> section and set a password first.'
                                );

                                // Focus on password field when modal closes
                                setTimeout(function() {
                                    $('input[name="<?php echo $this::option_key; ?>[whitelabel][settings_password]"]').focus();
                                }, 300);

                                return false;
                            }

                            // Update original state when valid change happens
                            setTimeout(function() {
                                originalCheckboxState = hideSettingsCheckbox.is(':checked');
                            }, 0);
                        });

                        // Prevent clearing password when Hide Settings is enabled
                        // passwordField already declared above
                        var storedPassword = '<?php echo esc_js($whitelabel_settings['settings_password'] ?? ''); ?>';

                        passwordField.on('keydown', function(e) {
                            var currentValue = $(this).val();

                            // If Hide Settings is checked and user tries to clear password (backspace/delete on empty or last char)
                            if (hideSettingsCheckbox.is(':checked') &&
                                (currentValue.length <= 1 || !currentValue) &&
                                (e.keyCode === 8 || e.keyCode === 46)) { // Backspace or Delete

                                e.preventDefault();
                                e.stopImmediatePropagation();

                                // Show warning modal only once
                                if (!isShowingModal) {
                                    showModal(
                                        '🚫',
                                        'Cannot Remove Password',
                                        'You cannot remove the <strong>White Label Settings Password</strong> while "Hide Settings" is enabled.<br><br>Please uncheck <strong>"Hide Settings"</strong> first if you want to remove the password.'
                                    );
                                }

                                return false;
                            }
                        });

                        // Listen for successful AJAX save to update password status
                        $(document).on('metasync_settings_saved', function() {
                            // Password status will be checked dynamically via hasPassword() function
                        });

                        // Close modal with Escape key
                        $(document).on('keydown', function(e) {
                            if (e.key === 'Escape') {
                                $('#metasync-custom-modal').fadeOut(200);
                            }
                        });

                        // Handle export whitelabel settings button
                        $('#metasync-export-whitelabel-btn').on('click', function(e) {
                            e.preventDefault();
                            
                            var $button = $(this);
                            var originalText = $button.html();
                            
                            // Disable button and show loading state
                            $button.prop('disabled', true).html('⏳ Exporting...');
                            
                            // Create a form to submit the export request
                            var form = $('<form>', {
                                'method': 'POST',
                                'action': '<?php echo esc_js(admin_url('admin-post.php')); ?>',
                                'target': '_blank'
                            });
                            
                            form.append($('<input>', {
                                'type': 'hidden',
                                'name': 'action',
                                'value': 'metasync_export_whitelabel_settings'
                            }));
                            
                            form.append($('<input>', {
                                'type': 'hidden',
                                'name': '_wpnonce',
                                'value': '<?php echo wp_create_nonce('metasync_export_whitelabel'); ?>'
                            }));
                            
                            // Append form to body, submit, then remove
                            $('body').append(form);
                            form.submit();
                            
                            // Remove form after a short delay
                            setTimeout(function() {
                                form.remove();
                                $button.prop('disabled', false).html(originalText);
                            }, 2000);
                        });
                    });
                    </script>
                <?php
                    } elseif ($active_tab == 'advanced') {
                        // Check if user has access to Advanced Settings
                        if (!Metasync_Access_Control::user_can_access('hide_advanced')) {
                            echo '<div class="dashboard-card" style="background: var(--dashboard-card-bg); padding: 25px; border-radius: 8px; text-align: center;">';
                            echo '<h2 style="color: var(--dashboard-text-primary);">🔒 Access Denied</h2>';
                            echo '<p style="color: var(--dashboard-text-secondary);">You do not have permission to access this page.</p>';
                            echo '</div>';
                        } else {
                ?>
                    <div class="dashboard-card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <div style="flex: 1;">
                                <h2 style="margin: 0;">🧰 Advanced Settings</h2>
                                <p style="color: var(--dashboard-text-secondary); margin: 5px 0 0 0;">Technical utilities for troubleshooting and connectivity checks.</p>
                            </div>
                            <?php if ($hide_settings_enabled && !empty($user_password) && $is_authenticated): ?>
                            <div>
                                <?php $this->render_lock_button('advanced'); ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($hide_settings_enabled && !empty($user_password) && $is_authenticated): ?>
                        <div style="background: rgba(212, 237, 218, 0.2); border: 1px solid rgba(195, 230, 203, 0.5); color: var(--dashboard-success, #10b981); padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                            <strong>✅ Access Granted:</strong> You have successfully authenticated and can now modify settings.
                        </div>
                        <?php endif; ?>

                        <?php
                        // Display debug mode success/error messages
                        if (isset($_GET['debug_mode_enabled']) && $_GET['debug_mode_enabled'] == '1'):
                            $indefinite = isset($_GET['indefinite']) && $_GET['indefinite'] == '1';
                        ?>
                        <div class="notice notice-success inline" style="margin-bottom: 20px;">
                            <p>
                                <strong>✅ Success!</strong> Debug mode has been enabled<?php echo $indefinite ? ' indefinitely' : ' for 24 hours'; ?>.
                            </p>
                        </div>
                        <?php endif; ?>

                        <?php if (isset($_GET['debug_mode_disabled']) && $_GET['debug_mode_disabled'] == '1'): ?>
                        <div class="notice notice-info inline" style="margin-bottom: 20px;">
                            <p>
                                <strong>ℹ️ Debug Mode Disabled:</strong> Debug mode has been successfully disabled.
                            </p>
                        </div>
                        <?php endif; ?>

                        <?php if (isset($_GET['debug_mode_extended']) && $_GET['debug_mode_extended'] == '1'): ?>
                        <div class="notice notice-success inline" style="margin-bottom: 20px;">
                            <p>
                                <strong>✅ Extended!</strong> Debug mode has been extended for another 24 hours.
                            </p>
                        </div>
                        <?php endif; ?>

                        <?php if (isset($_GET['debug_error']) && $_GET['debug_error'] == '1'): ?>
                        <div class="notice notice-error inline" style="margin-bottom: 20px;">
                            <p>
                                <strong>❌ Error:</strong> Unable to perform the debug mode operation. Please try again.
                            </p>
                        </div>
                        <?php endif; ?>

                        <?php
                        # Render accordion-based advanced settings sections
                        $this->render_advanced_accordion();
                        ?>
                    </div>

                <?php
                        } // End else (access granted)
                    } // End elseif (advanced tab)
                ?>

                <!-- Save button removed - using floating notification system instead -->

            </form>

            <!-- Lock Section Button Handler (runs on all tabs) -->
            <script>
            jQuery(document).ready(function($) {
                // Ensure ajax URL is available in all admin contexts
                var ajaxUrl = (typeof window.ajaxurl !== 'undefined' && window.ajaxurl)
                    ? window.ajaxurl
                    : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                // Handle Lock Section button clicks
                $('.metasync-lock-btn').on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    var currentTab = $(this).data('tab');

                    // Create a hidden form to submit the logout request
                    var logoutForm = $('<form>', {
                        'method': 'post',
                        'action': ''
                    });

                    // Add nonce field
                    logoutForm.append('<?php echo wp_nonce_field("whitelabel_logout_nonce", "whitelabel_logout_nonce", true, false); ?>');

                    // Add logout field
                    logoutForm.append($('<input>', {
                        'type': 'hidden',
                        'name': 'whitelabel_logout',
                        'value': '1'
                    }));

                    // Append to body and submit
                    $('body').append(logoutForm);
                    logoutForm.submit();

                    return false;
                });
            });
            </script>

            <!-- Host Blocking-generated JavaScript -->
            <script>
            jQuery(document).ready(function($) {
                // Ensure ajax URL is available in this scope
                var ajaxUrl = (typeof window.ajaxurl !== 'undefined' && window.ajaxurl)
                    ? window.ajaxurl
                    : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                
                // Host blocking test functionality - use delegated events for better reliability
                $(document).on('click', '#test-get-request', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    runHostTest('GET');
                    return false;
                });
                
                $(document).on('click', '#test-post-request', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    runHostTest('POST');
                    return false;
                });
                
                $(document).on('click', '#test-both-requests', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    runHostTest('BOTH');
                    return false;
                });
                
                function runHostTest(method) {
                    var buttonId = (method === 'BOTH' ? 'test-both-requests' : 'test-' + method.toLowerCase() + '-request');
                    var $button = $('#' + buttonId);
                    
                    if ($button.length === 0) {
                        alert('Error: Test button not found. Please refresh the page.');
                        return;
                    }
                    
                    var originalText = $button.text();
                    
                    // Disable button and show loading
                    $button.prop('disabled', true);
                    $button.text('🔄 Testing...');
                    
                    // Prepare results area
                    var $resultsDiv = $('#host-test-results');
                    var $resultsContent = $('#test-results-content');
                    $resultsDiv.show();
                    $resultsContent.html('<div class="notice notice-info"><p>Running ' + (method === 'BOTH' ? 'GET and POST' : method) + ' test(s)...</p></div>');
                    
                    var testsToRun = (method === 'BOTH') ? ['GET', 'POST'] : [method];
                    var completedTests = 0;
                    var allResults = [];
                    
                    testsToRun.forEach(function(testMethod) {
                        var action = 'metasync_test_host_blocking_' + testMethod.toLowerCase();
                        
                        $.ajax({
                            url: ajaxUrl,
                            type: 'POST',
                            dataType: 'json',
                            data: { action: action },
                            timeout: 35000,
                            success: function(response, textStatus, xhr) {
                                try {
                                    if (response && response.success && response.data) {
                                        allResults.push(response.data);
                                    } else {
                                        allResults.push({
                                            method: testMethod,
                                            status: 'error',
                                            error: (response && response.data) ? response.data : 'Unexpected response',
                                            blocked: true,
                                            details: 'Received non-success response from server.'
                                        });
                                    }
                                } catch (e) {
                                    allResults.push({
                                        method: testMethod,
                                        status: 'error',
                                        error: 'Response parse error: ' + (e && e.message ? e.message : e),
                                        blocked: true
                                    });
                                }
                                finalizeOne();
                            },
                            error: function(xhr, status, error) {
                                var payload = (xhr && xhr.responseText) ? xhr.responseText.substring(0, 500) : '';
                                allResults.push({
                                    method: testMethod,
                                    status: 'error',
                                    error: 'AJAX failed: ' + error + (payload ? ' — ' + payload : ''),
                                    blocked: true,
                                    details: 'Request did not complete successfully. Status: ' + status
                                });
                                finalizeOne();
                            }
                        });
                    });
                    
                    function finalizeOne() {
                        completedTests++;
                        if (completedTests === testsToRun.length) {
                            displayResults(allResults);
                            resetButtons();
                            // Scroll results into view for clarity
                            var $container = $('#host-test-results');
                            if ($container && $container[0] && $container[0].scrollIntoView) {
                                $container[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
                            }
                        }
                    }
                }
                
                function resetButtons() {
                    $('#test-get-request, #test-post-request, #test-both-requests').prop('disabled', false);
                    $('#test-get-request').text('🔍 Test GET Request');
                    $('#test-post-request').text('📤 Test POST Request');
                    $('#test-both-requests').text('🔄 Test Both Requests');
                }
                
                function displayResults(results) {
                    var html = '';
                    
                    results.forEach(function(result) {
                        var statusClass = result.status === 'success' ? 'success' : 'error';
                        var statusIcon = result.status === 'success' ? '✅' : '❌';
                        var blockedStatus = result.blocked ? 'BLOCKED' : 'ALLOWED';
                        var blockedClass = result.blocked ? 'blocked' : 'allowed';
                        
                        html += '<div class="test-result-item ' + statusClass + '">';
                        html += '<div class="test-result-header">';
                        html += '<h4>' + statusIcon + ' ' + result.method + ' Request - ' + blockedStatus + '</h4>';
                        html += '<span class="test-status ' + blockedClass + '">' + blockedStatus + '</span>';
                        html += '</div>';
                        
                        html += '<div class="test-result-details">';
                        html += '<p><strong>Response Time:</strong> ' + result.response_time + '</p>';
                        html += '<p><strong>Status:</strong> ' + result.status + '</p>';
                        
                        if (result.status_code) {
                            html += '<p><strong>HTTP Status Code:</strong> <span class="status-code">' + result.status_code + '</span></p>';
                        }
                        
                        if (result.error) {
                            html += '<p><strong>Error:</strong> <span class="error-message">' + result.error + '</span></p>';
                        }
                        
                        if (result.body) {
                            html += '<p><strong>Response Body:</strong></p>';
                            html += '<pre class="response-body">' + escapeHtml(result.body) + '</pre>';
                        }
                        
                        if (result.headers && Object.keys(result.headers).length > 0) {
                            html += '<p><strong>Response Headers:</strong></p>';
                            html += '<pre class="response-headers">';
                            Object.keys(result.headers).forEach(function(key) {
                                html += key + ': ' + result.headers[key] + '\n';
                            });
                            html += '</pre>';
                        }
                        
                        if (result.sent_data) {
                            html += '<p><strong>Sent Data:</strong></p>';
                            html += '<pre class="sent-data">' + escapeHtml(JSON.stringify(result.sent_data, null, 2)) + '</pre>';
                        }
                        
                        // Show parsed response data if available
                        if (result.parsed_response) {
                            html += '<p><strong>Parsed Response:</strong></p>';
                            html += '<pre class="parsed-response">' + escapeHtml(JSON.stringify(result.parsed_response, null, 2)) + '</pre>';
                        }
                        
                        html += '<p><strong>Details:</strong> ' + result.details + '</p>';
                        html += '</div>';
                        html += '</div>';
                    });
                    
                    $('#test-results-content').html(html);
                    $('#host-test-results').show();
                }
                
                function escapeHtml(text) {
                    var map = {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        '\'': '&#039;'
                    };
                    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
                }
            });
            </script>

            <!-- Host Blocking Test CSS -->
            <style>
            .test-result-item {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 8px;
                margin-bottom: 20px;
                overflow: hidden;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            
            .test-result-item.success {
                border-left: 4px solid #28a745;
            }
            
            .test-result-item.error {
                border-left: 4px solid #dc3545;
            }
            
            .test-result-header {
                background: #f8f9fa;
                padding: 15px 20px;
                border-bottom: 1px solid #dee2e6;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .test-result-header h4 {
                margin: 0;
                color: #495057;
                font-size: 16px;
            }
            
            .test-status {
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: bold;
                text-transform: uppercase;
            }
            
            .test-status.allowed {
                background: #d4edda;
                color: #155724;
            }
            
            .test-status.blocked {
                background: #f8d7da;
                color: #721c24;
            }
            
            .test-result-details {
                padding: 20px;
            }
            
            .test-result-details p {
                margin: 8px 0;
                color: #495057;
            }
            
            .test-result-details code {
                background: #f8f9fa;
                padding: 2px 6px;
                border-radius: 3px;
                font-family: 'Courier New', monospace;
                color: #e83e8c;
            }
            
            .status-code {
                font-weight: bold;
                color: #007cba;
            }
            
            .error-message {
                color: #dc3545;
                font-weight: bold;
            }
            
            .response-body, .response-headers, .sent-data, .parsed-response {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 4px;
                padding: 12px;
                margin: 8px 0;
                font-family: 'Courier New', monospace;
                font-size: 12px;
                line-height: 1.4;
                max-height: 200px;
                overflow-y: auto;
                white-space: pre-wrap;
                word-break: break-all;
            }
            
            .response-body {
                color: #495057;
            }
            
            .response-headers {
                color: #6c757d;
            }
            
            .sent-data {
                color: #007cba;
            }
            
            .parsed-response {
                color: #28a745;
                background: #f8fff9;
                border-color: #c3e6cb;
            }
            
            #host-test-results {
                margin-top: 20px;
            }
            
            #host-test-results h3 {
                color: #495057;
                margin-bottom: 15px;
                padding-bottom: 10px;
                border-bottom: 2px solid #dee2e6;
            }
            
            .button:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            </style>

            <?php if ($active_tab === 'advanced'): ?>
                <!-- Display success/error messages for advanced operations -->
                <?php if (isset($_GET['settings_cleared']) && $_GET['settings_cleared'] == '1'): ?>
                    <div class="notice notice-success is-dismissible">
                        <p><strong>✅ Success!</strong> All plugin settings have been cleared successfully and a new Plugin Auth Token has been generated. Please reconfigure the plugin as needed.</p>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['clear_settings_error']) && $_GET['clear_settings_error'] == '1'): ?>
                    <div class="notice notice-error is-dismissible">
                        <p><strong>❌ Error!</strong> Failed to clear settings due to a security check failure. Please try again.</p>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['log_cleared']) && $_GET['log_cleared'] == '1'): ?>
                    <div class="notice notice-success is-dismissible">
                        <p><strong>✅ Success!</strong> Error logs have been cleared successfully.</p>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['clear_error']) && $_GET['clear_error'] == '1'): ?>
                    <div class="notice notice-error is-dismissible">
                        <p><strong>❌ Error!</strong> Failed to clear error logs due to a security check failure. Please try again.</p>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['access_roles_saved']) && $_GET['access_roles_saved'] == '1'): ?>
                    <div class="notice notice-success is-dismissible">
                        <p><strong>✅ Success!</strong> Plugin access roles have been saved successfully.</p>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['access_roles_error']) && $_GET['access_roles_error'] == '1'): ?>
                    <div class="notice notice-error is-dismissible">
                        <p><strong>❌ Error!</strong> Failed to save plugin access roles. <?php echo isset($_GET['message']) ? esc_html(urldecode($_GET['message'])) : 'Please try again.'; ?></p>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
                <?php
    }

    /**
     * Helper function to render navigation menu with grouped tabs
     */
    public function render_navigation_menu($current_page = null)
    {
        // Get available menu items using our helper method to ensure consistency
        $available_menu_items = $this->get_available_menu_items();
        
        // Define icons for each menu type
        $menu_icons = [
            'general' => '⚙️',
            'dashboard' => '📊',
            'compatibility' => '🔧',
            'sync_log' => '📋',
            'seo_controls' => '🔍',
            'optimal_settings' => '🚀',
            'instant_index' => '🔗',
            'google_console' => '📊',
            'bing_console' => '📊',
            'redirections' => '↩️',
            'robots_txt' => '🤖',
            'xml_sitemap' => '🗺️',
            'import_seo' => '📥',
            'custom_pages' => '📝',
            'bot_statistics' => '🤖',
            'report_issue' => '📝',
            'error_log' => '⚠️'
        ];

        // Group menu items by their group property
        $seo_items = [];
        $plugin_items = [];
        
        foreach ($available_menu_items as $key => $menu_item) {
            $group = $menu_item['group'] ?? 'plugin';
            if ($group === 'seo') {
                $seo_items[$key] = $menu_item;
            } else {
                $plugin_items[$key] = $menu_item;
            }
        }
        ?>
        <!-- Plugin Navigation Menu with Dashboard + Grouped Dropdowns -->
        <div class="metasync-nav-wrapper">
            <div class="metasync-nav-tabs metasync-nav-grouped">
                <?php
                // Dashboard tab (standalone)
                if (isset($seo_items['dashboard'])) {
                    $is_active = ($current_page === 'dashboard');
                    $icon = $menu_icons['dashboard'] ?? '📊';
                    $page_url = '?page=' . self::$page_slug . $seo_items['dashboard']['slug_suffix'];
                    ?>
                    <a href="<?php echo esc_url($page_url); ?>" class="metasync-nav-tab <?php echo $is_active ? 'active' : ''; ?>">
                        <span class="tab-icon"><?php echo $icon; ?></span>
                        <span class="tab-text"><?php echo esc_html($seo_items['dashboard']['title']); ?></span>
                    </a>
                    <?php
                }
                ?>
                
                <!-- SEO Features Dropdown (excluding Dashboard) -->
                <div class="metasync-nav-dropdown">
                    <?php
                    // Check if any SEO item (except dashboard) is active
                    $has_active_seo = false;
                    foreach ($seo_items as $key => $menu_item) {
                        if ($key !== 'dashboard' && $current_page === $key) {
                            $has_active_seo = true;
                            break;
                        }
                    }
                    ?>
                    <button type="button" class="metasync-nav-dropdown-btn <?php echo $has_active_seo ? 'active' : ''; ?>" aria-haspopup="true" aria-expanded="false">
                        <span class="tab-icon">🔍</span>
                        <span class="tab-text">SEO</span>
                        <span class="dropdown-arrow">▼</span>
                    </button>
                    <div class="metasync-nav-dropdown-menu">
                        <?php
                        foreach ($seo_items as $key => $menu_item) {
                            // Skip dashboard - it's a standalone tab
                            if ($key === 'dashboard') {
                                continue;
                            }
                            
                            $is_active = ($current_page === $key);
                            $icon = $menu_icons[$key] ?? '📄';
                            $page_url = '?page=' . self::$page_slug . $menu_item['slug_suffix'];
                            ?>
                            <a href="<?php echo esc_url($page_url); ?>" class="metasync-nav-dropdown-item <?php echo $is_active ? 'active' : ''; ?>">
                                <span class="tab-icon"><?php echo $icon; ?></span>
                                <span class="tab-text"><?php echo esc_html($menu_item['title']); ?></span>
                            </a>
                            <?php
                        }
                        ?>
                    </div>
                </div>

                <!-- Plugin Dropdown -->
                <div class="metasync-nav-dropdown">
                    <?php
                    // Check if any Plugin item (except report_issue) is active
                    $has_active_plugin = false;
                    foreach ($plugin_items as $key => $menu_item) {
                        if ($key !== 'report_issue' && $current_page === $key) {
                            $has_active_plugin = true;
                            break;
                        }
                    }
                    ?>
                    <button type="button" class="metasync-nav-dropdown-btn <?php echo $has_active_plugin ? 'active' : ''; ?>" aria-haspopup="true" aria-expanded="false">
                        <span class="tab-icon">⚙️</span>
                        <span class="tab-text">Plugin</span>
                        <span class="dropdown-arrow">▼</span>
                    </button>
                    <div class="metasync-nav-dropdown-menu">
                        <?php
                        foreach ($plugin_items as $key => $menu_item) {
                            // Skip report_issue - it is a standalone tab
                            if ($key === 'report_issue') {
                                continue;
                            }

                            $is_active = ($current_page === $key);
                            $icon = $menu_icons[$key] ?? '📄';
                            $page_url = '?page=' . self::$page_slug . $menu_item['slug_suffix'];
                            ?>
                            <a href="<?php echo esc_url($page_url); ?>" class="metasync-nav-dropdown-item <?php echo $is_active ? 'active' : ''; ?>">
                                <span class="tab-icon"><?php echo $icon; ?></span>
                                <span class="tab-text"><?php echo esc_html($menu_item['title']); ?></span>
                            </a>
                            <?php
                        }
                        ?>
                    </div>
                </div>

                <!-- Right side - Report Issue and Settings dropdown -->
                <div class="metasync-nav-right">
                    <?php
                    # Add Report Issue button (always visible)
                    if (isset($available_menu_items['report_issue'])) {
                        $is_active = ($current_page === 'report_issue');
                        $page_url = '?page=' . self::$page_slug . $available_menu_items['report_issue']['slug_suffix'];
                        ?>
                        <a href="<?php echo esc_url($page_url); ?>" class="metasync-nav-tab <?php echo $is_active ? 'active' : ''; ?>">
                            <span class="tab-icon">📝</span>
                            <span class="tab-text">Report Issue</span>
                        </a>
                    <?php } ?>
                    <div class="metasync-simple-dropdown">
                        <button type="button" class="metasync-settings-btn" id="metasync-settings-btn" onclick="toggleSettingsMenuPortal(event)" aria-expanded="false">
                            <span class="tab-icon">⚙️</span>
                            <span class="tab-text">Settings</span>
                            <span class="dropdown-arrow">▼</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        // Handle navigation dropdowns using portal pattern for reliable positioning
        (function() {
            var dropdowns = document.querySelectorAll('.metasync-nav-dropdown');
            var activePortal = null;
            var activeButton = null;
            var activeDropdown = null;
            var scrollHandler = null;
            var resizeHandler = null;

            // Position the portal menu relative to its trigger button
            function positionPortalMenu(button, portalMenu) {
                var rect = button.getBoundingClientRect();
                var menuRect = portalMenu.getBoundingClientRect();
                var viewportWidth = window.innerWidth;
                var viewportHeight = window.innerHeight;

                // Calculate left position, ensuring menu stays within viewport
                var left = rect.left;
                if (left + menuRect.width > viewportWidth - 10) {
                    left = Math.max(10, viewportWidth - menuRect.width - 10);
                }

                // Calculate top position, prefer below button but flip above if needed
                var top = rect.bottom + 8;
                if (top + menuRect.height > viewportHeight - 10 && rect.top > menuRect.height + 10) {
                    top = rect.top - menuRect.height - 8;
                }

                portalMenu.style.position = 'fixed';
                portalMenu.style.top = top + 'px';
                portalMenu.style.left = left + 'px';
                portalMenu.style.zIndex = '999999999';
            }

            // Close the active portal menu
            function closeActivePortal() {
                if (activePortal && activePortal.parentNode) {
                    activePortal.parentNode.removeChild(activePortal);
                }
                if (activeButton) {
                    activeButton.setAttribute('aria-expanded', 'false');
                }
                if (activeDropdown) {
                    activeDropdown.classList.remove('active');
                }
                if (scrollHandler) {
                    window.removeEventListener('scroll', scrollHandler, true);
                    scrollHandler = null;
                }
                if (resizeHandler) {
                    window.removeEventListener('resize', resizeHandler);
                    resizeHandler = null;
                }
                activePortal = null;
                activeButton = null;
                activeDropdown = null;
            }

            // Open a dropdown as a portal
            function openAsPortal(dropdown, button, menu) {
                // Close any existing portal first
                closeActivePortal();

                // Clone the menu content and create portal
                var portalMenu = menu.cloneNode(true);
                portalMenu.id = 'metasync-nav-portal-menu';
                portalMenu.style.opacity = '1';
                portalMenu.style.visibility = 'visible';
                portalMenu.style.transform = 'none';

                // Append to body (portal pattern)
                document.body.appendChild(portalMenu);

                // Position after adding to DOM so we can measure
                positionPortalMenu(button, portalMenu);

                // Update state
                activePortal = portalMenu;
                activeButton = button;
                activeDropdown = dropdown;
                dropdown.classList.add('active');
                button.setAttribute('aria-expanded', 'true');

                // Create bound handlers for repositioning
                scrollHandler = function() {
                    if (activePortal && activeButton) {
                        positionPortalMenu(activeButton, activePortal);
                    }
                };
                resizeHandler = scrollHandler;

                // Reposition on scroll and resize
                window.addEventListener('scroll', scrollHandler, true);
                window.addEventListener('resize', resizeHandler);

                // Handle clicks within portal menu (for navigation)
                portalMenu.addEventListener('click', function(e) {
                    var link = e.target.closest('a');
                    if (link) {
                        // Let the link navigate naturally, then close
                        setTimeout(closeActivePortal, 0);
                    }
                });
            }

            dropdowns.forEach(function(dropdown) {
                var button = dropdown.querySelector('.metasync-nav-dropdown-btn');
                var menu = dropdown.querySelector('.metasync-nav-dropdown-menu');

                if (!button || !menu) return;

                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    var isExpanded = button.getAttribute('aria-expanded') === 'true';

                    if (isExpanded) {
                        closeActivePortal();
                    } else {
                        openAsPortal(dropdown, button, menu);
                    }
                });
            });

            // Close portal when clicking outside
            document.addEventListener('click', function(e) {
                if (activePortal && activeButton) {
                    // Check if click is outside both the portal and the trigger button
                    if (!activePortal.contains(e.target) && !activeButton.contains(e.target)) {
                        closeActivePortal();
                    }
                }
            });

            // Close portal on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && activePortal) {
                    closeActivePortal();
                    if (activeButton) {
                        activeButton.focus();
                    }
                }
            });
        })();
        
        // Portal-style dropdown that bypasses stacking contexts
        function toggleSettingsMenuPortal(event) {
            event.preventDefault();
            event.stopPropagation();

            var button = event.currentTarget;
            var existingMenu = document.getElementById('metasync-portal-menu');

            // If menu exists, close it
            if (existingMenu) {
                existingMenu.remove();
                button.classList.remove('active');
                button.setAttribute('aria-expanded', 'false');
                return;
            }

            // Create menu outside form context
            var menu = document.createElement('div');
            menu.id = 'metasync-portal-menu';
            menu.className = 'metasync-portal-menu';

            // Get current page context for active states
            var currentUrl = window.location.href;
            var isGeneralActive = currentUrl.indexOf('tab=general') > -1 || currentUrl.indexOf('tab=') === -1;
            var isAdvancedActive = currentUrl.indexOf('tab=advanced') > -1;
            var isWhitelabelActive = currentUrl.indexOf('tab=whitelabel') > -1;

            // Fixed: Use safer DOM manipulation instead of innerHTML to prevent XSS
            menu.textContent = '';

            var hideAdvanced = <?php
                echo !Metasync_Access_Control::user_can_access('hide_advanced') ? 'true' : 'false';
            ?>;
            var showGeneral = <?php
                echo Metasync_Access_Control::user_can_access('hide_settings') ? 'true' : 'false';
            ?>;

            // Only add General link when user has access to Settings
            if (showGeneral) {
                const generalLink = document.createElement('a');
                generalLink.href = '?page=<?php echo self::$page_slug; ?>&tab=general';
                generalLink.className = 'metasync-portal-item' + (isGeneralActive ? ' active' : '');
                generalLink.textContent = 'General';
                menu.appendChild(generalLink);
            }

            // White Label tab
            const whitelabelLink = document.createElement('a');
            whitelabelLink.href = '?page=<?php echo self::$page_slug; ?>&tab=whitelabel';
            whitelabelLink.className = 'metasync-portal-item' + (isWhitelabelActive ? ' active' : '');
            whitelabelLink.textContent = 'White label';
            menu.appendChild(whitelabelLink);

            // Only add Advanced tab if not hidden
            if (!hideAdvanced) {
                const advancedLink = document.createElement('a');
                advancedLink.href = '?page=<?php echo self::$page_slug; ?>&tab=advanced';
                advancedLink.className = 'metasync-portal-item' + (isAdvancedActive ? ' active' : '');
                advancedLink.textContent = 'Advanced';
                menu.appendChild(advancedLink);
            }


            // Position menu relative to button
            var rect = button.getBoundingClientRect();
            menu.style.position = 'fixed';
            menu.style.top = (rect.bottom + 8) + 'px';
            menu.style.right = (window.innerWidth - rect.right) + 'px';
            menu.style.zIndex = '999999999';
            
            // Append to body to escape form context
            document.body.appendChild(menu);
            
            // Update button state
            button.classList.add('active');
            button.setAttribute('aria-expanded', 'true');
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            var button = document.getElementById('metasync-settings-btn');
            var menu = document.getElementById('metasync-portal-menu');
            
            if (menu && button && !button.contains(event.target) && !menu.contains(event.target)) {
                menu.remove();
                button.classList.remove('active');
                button.setAttribute('aria-expanded', 'false');
            }
        });
        </script>
    <?php
    }

    /**
     * Helper function to render plugin header with logo
     */
    public function render_plugin_header($page_title = null)
    {
        $general_settings = Metasync::get_option('general');
        $whitelabel_settings = Metasync::get_whitelabel_settings();
        
        // Get whitelabel logo from the centralized whitelabel settings
        $whitelabel_logo = Metasync::get_whitelabel_logo();
        $is_whitelabel = isset($whitelabel_settings['is_whitelabel']) ? $whitelabel_settings['is_whitelabel'] : false;
        
        // Get the display title - use effective plugin name if no page title provided
        $effective_plugin_name = Metasync::get_effective_plugin_name();
        
        $display_title = $page_title ?: $effective_plugin_name;
        
        $show_logo = false;
        $logo_url = '';
        
        // Priority 1: Use whitelabel logo if it's a valid URL
        if (!empty($whitelabel_logo) && filter_var($whitelabel_logo, FILTER_VALIDATE_URL)) {
            $show_logo = true;
            $logo_url = esc_url($whitelabel_logo);
        } elseif (!$is_whitelabel) {
            // Priority 2: Use default Search Atlas logo only for non-whitelabel users
            $show_logo = true;
            $logo_url = Metasync::HOMEPAGE_DOMAIN . '/wp-content/uploads/2023/12/white.svg';
        } else {
            // Priority 3: Whitelabel users without a custom logo show no logo
            $show_logo = false;
            $logo_url = '';
        }
        
        // Check integration status based on heartbeat API connectivity
        $searchatlas_api_key = isset($general_settings['searchatlas_api_key']) ? $general_settings['searchatlas_api_key'] : '';
        $otto_pixel_uuid = isset($general_settings['otto_pixel_uuid']) ? $general_settings['otto_pixel_uuid'] : '';
        
        // User is considered "Connected" based on heartbeat API status
        $is_integrated = $this->is_heartbeat_connected($general_settings);

        # Get current theme preference
        $current_theme = get_option('metasync_theme', 'dark');
        ?>
        
        <!-- Plugin Header with Logo -->
        <div class="metasync-header" data-current-theme="<?php echo esc_attr($current_theme); ?>">
            <div class="metasync-header-left">
                <?php if ($show_logo && !empty($logo_url)): ?>
                    <div class="metasync-logo-container">
                        <img src="<?php echo $logo_url; ?>" alt="Logo" class="metasync-logo" />
        </div>
                <?php endif; ?>
            </div>
            
                         <div class="metasync-header-right">
                             <!-- Theme Toggle -->
                        <div class="metasync-theme-toggle" role="group" aria-label="Theme Selector">
                            <button class="metasync-theme-option <?php echo ($current_theme === 'light') ? 'active' : ''; ?>" data-theme="light" aria-label="Light Theme" type="button">
                                <span class="metasync-theme-icon">☀️</span>
                                <span class="theme-label">Light</span>
                            </button>
                            <button class="metasync-theme-option <?php echo ($current_theme === 'dark') ? 'active' : ''; ?>" data-theme="dark" aria-label="Dark Theme" type="button">
                                <span class="metasync-theme-icon">🌙</span>
                                <span class="theme-label">Dark</span>
                            </button>
                        </div>
                        
                        <!-- Integration Status -->
                 <div class="metasync-integration-status <?php echo $is_integrated ? 'integrated' : 'not-integrated'; ?>" 
                      title="<?php echo $is_integrated ? 'Synced - Heartbeat API connectivity verified' : 'Not Synced - Heartbeat API not responding or unreachable'; ?>">
                     <span class="status-indicator"></span>
                     <span class="status-text"><?php echo $is_integrated ? 'Synced' : 'Not Synced'; ?></span>
                 </div>
             </div>
        </div>
        
        <!-- Page Title Below Header -->
        <div class="metasync-page-title">
            <h1><?php echo esc_html($display_title); ?></h1>
        </div>
        
    <?php
    }

    /*
        Method to handle Ajax request from "General Settings" page
    */
    public function meta_sync_save_settings() {
        # the new validation fixes issues #143, #144, #146

        # Check nonce for security and return early if invalid
        if (!isset($_POST['meta_sync_nonce']) || !wp_verify_nonce($_POST['meta_sync_nonce'], 'meta_sync_general_setting_nonce')) {
            
            #send invalid nonce message
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }
    
        # text fields for sanitize text
        $text_fields = [
            'searchatlas_api_key', 'apikey',
            'white_label_plugin_name', 'white_label_plugin_description',
            'white_label_plugin_author', 'white_label_plugin_menu_slug',
            'white_label_plugin_menu_icon', 'enabled_plugin_css',
            'enabled_elementor_plugin_css_color','enabled_elementor_plugin_css',
            'otto_pixel_uuid','periodic_clear_otto_cache','periodic_clear_ottopage_cache',
            'periodic_clear_ottopost_cache', 'whitelabel_otto_name', 'otto_wp_rocket_compat'
        ];
        
        # URL fields for esc_url_raw
        $url_fields = [
            'white_label_plugin_author_uri', 'white_label_plugin_uri'
        ];
        
        // Note: white_label_plugin_menu_name and white_label_plugin_menu_title deprecated
        // Plugin Name controls general branding, whitelabel_otto_name controls OTTO features
    
        # Bool Fields for filter var
        # REMOVED (CVE-2025-14386): disable_single_signup_login was removed - legacy SSO login functionality no longer exists
        $bool_fields = ['otto_disable_on_loggedin', 'otto_disable_preview_button', 'otto_disable_for_bots' , 'hide_dashboard_framework', 'show_admin_bar_status', 'enable_auto_updates', 'disable_common_robots_metabox', 'disable_advance_robots_metabox', 'disable_redirection_metabox', 'disable_canonical_metabox', 'disable_social_opengraph_metabox', 'disable_schema_markup_metabox', 'open_external_links'];
    
        #url Fields for esc_url
        $url_fields = ['white_label_plugin_author_uri', 'white_label_plugin_uri'];
    
        # Get existing options to preserve other sections (whitelabel, branding, etc.)
        $metasync_options = Metasync::get_option();
        if (!is_array($metasync_options)) {
            $metasync_options = array();
        }
        # Initialize general section if it doesn't exist
        if (!isset($metasync_options['general']) || !is_array($metasync_options['general'])) {
            $metasync_options['general'] = array();
        }
    
        # Process text fields
        # Initialize an array to collect validation errors
        $validation_errors = [];

        # Determine which tab is being submitted
        # JavaScript sends 'active_tab' parameter in AJAX request data
        # This tells us which settings page tab the user was on when they clicked save
        $active_tab = isset($_POST['active_tab']) ? sanitize_text_field($_POST['active_tab']) : 'general';
        $general_tab_submitted = ($active_tab === 'general');
        $whitelabel_tab_submitted = ($active_tab === 'whitelabel');

        foreach ($text_fields as $field) {

            # Check if the field is set in the POST data
            if (isset($_POST['metasync_options']['general'][$field])) {

                # Trim whitespace from the field value
                $value = trim($_POST['metasync_options']['general'][$field]);

                # Define whitelabel branding fields that can be cleared (allow empty values)
                $whitelabel_clearable_fields = [
                    'white_label_plugin_name', 
                    'white_label_plugin_description', 
                    'white_label_plugin_author',
                    'white_label_plugin_menu_slug',
                    'white_label_plugin_menu_icon',
                    'whitelabel_otto_name',
                    'otto_pixel_uuid'
                ];

                # Skip empty values except for whitelabel fields (which can be cleared)
                if ($value === '' && !in_array($field, $whitelabel_clearable_fields)) {
                    continue;
                }

                # Special validation for the 'white_label_plugin_name' field
                if ($field === 'white_label_plugin_name') {
                    if (strlen($value) > 16) {
                        $validation_errors[$field] = 'Plugin name must not exceed 16 characters';
                        continue;
                    }
                }

                # Special validation for the 'white_label_plugin_menu_icon' field
                if ($field === 'white_label_plugin_menu_icon') {
                    
                    # Handle empty value (allow clearing the menu icon)
                    if ($value === '') {
                        $metasync_options['general'][$field] = '';
                    } elseif (filter_var($value, FILTER_VALIDATE_URL)) {

                        # Define allowed image extensions
                        $image_extensions = ['png', 'svg'];

                        # Parse the URL path to extract the file extension
                        $path = parse_url($value, PHP_URL_PATH); // Get path from URL
                        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                        # Check if the extension is one of the allowed image types
                        if (in_array($extension, $image_extensions, true)) {

                            # Sanitize and assign the URL to options
                            $metasync_options['general'][$field] = esc_url_raw($value);
                        } else {

                            # Add error if image extension is not allowed
                            $validation_errors[] = 'Invalid Menu icon format. Only PNG and SVG are allowed.';
                        }
                    } else {

                        # Add error if the URL format is invalid
                        $validation_errors[] = 'Invalid Menu icon URL format.';
                    }
                } else {

                    # Sanitize regular text fields
                    $metasync_options['general'][$field] = sanitize_text_field($_POST['metasync_options']['general'][$field]);
                }
            }
        }

        # Process textarea fields (bot whitelist/blacklist)
        $textarea_fields = ['otto_bot_whitelist', 'otto_bot_blacklist'];
        if ($general_tab_submitted) {
            foreach ($textarea_fields as $field) {
                if (isset($_POST['metasync_options']['general'][$field])) {
                    $metasync_options['general'][$field] = sanitize_textarea_field($_POST['metasync_options']['general'][$field]);
                } else {
                    # If not set, preserve empty string
                    $metasync_options['general'][$field] = '';
                }
            }
        }

        # Process boolean fields
        # IMPORTANT: Only process if general tab is actually being submitted
        # When submitting from other tabs (whitelabel, advanced), we preserve existing values
        if ($general_tab_submitted) {
            foreach ($bool_fields as $field) {
                if (isset($_POST['metasync_options']['general'][$field])) {
                    $metasync_options['general'][$field] = filter_var($_POST['metasync_options']['general'][$field], FILTER_VALIDATE_BOOLEAN);
                }else {
                    # If checkbox is not present in POST (unchecked), set to false
                    $metasync_options['general'][$field] = false;
                }
            }
        }
    
        # Process URL fields (for general tab OR whitelabel tab since these fields are displayed on whitelabel tab)
        if ($general_tab_submitted || $whitelabel_tab_submitted) {
            foreach ($url_fields as $field) {
            if (isset($_POST['metasync_options']['general'][$field])) {
                
                # Trim whitespace from the field value
                $value = trim($_POST['metasync_options']['general'][$field]);
                
                # Define whitelabel URL fields that can be cleared (allow empty values)
                $whitelabel_clearable_url_fields = [
                    'white_label_plugin_author_uri',
                    'white_label_plugin_uri'
                ];
                
                # Skip empty values except for whitelabel URL fields (which can be cleared)
                if ($value === '' && !in_array($field, $whitelabel_clearable_url_fields)) {
                    continue;
                }
                
                # Handle empty whitelabel URL fields (clear them)
                if ($value === '' && in_array($field, $whitelabel_clearable_url_fields)) {
                    $metasync_options['general'][$field] = '';
                    continue;
                }
                
                # Validate URL format
                if (filter_var($value, FILTER_VALIDATE_URL)) {
                    
                    # Additional validation for proper domain name
                    $parsed_url = parse_url($value);
                    $host = isset($parsed_url['host']) ? $parsed_url['host'] : '';
                    
                    # Check if host has proper domain format (contains dot) or is a valid IP
                    if (strpos($host, '.') !== false || filter_var($host, FILTER_VALIDATE_IP)) {
                        
                        # If valid, sanitize and assign the URL to options
                        $metasync_options['general'][$field] = esc_url_raw($value);
                    } else {
                        
                        # Add error if the domain format is invalid
                        $field_name = ($field === 'white_label_plugin_author_uri') ? 'Author URL' : 'Plugin URL';
                        $validation_errors[] = 'Invalid ' . $field_name . ' format. Please use a proper domain name (e.g., example.com).';
                    }
                } else {
                    
                    # Add error if the URL format is invalid
                    $field_name = ($field === 'white_label_plugin_author_uri') ? 'Author URL' : 'Plugin URL';
                    $validation_errors[] = 'Invalid ' . $field_name . ' format.';
                }
            }
            }
        } // End of $general_tab_submitted check for URL fields

        # Process Content Genius User Roles array field (only if general section is submitted)
        if ($general_tab_submitted) {
            if (isset($_POST['metasync_options']['general']['content_genius_sync_roles']) && is_array($_POST['metasync_options']['general']['content_genius_sync_roles'])) {
                # Sanitize each role in the array
                $metasync_options['general']['content_genius_sync_roles'] = array_map('sanitize_text_field', $_POST['metasync_options']['general']['content_genius_sync_roles']);
            } else {
                # If no roles are selected in general tab submission, set to empty array
                $metasync_options['general']['content_genius_sync_roles'] = array();
            }
        }
        # else: preserve existing value if not submitting from general tab

        # Whitelabel partial-save validation: require at least one core field when any whitelabel field is filled
        if ($whitelabel_tab_submitted && isset($_POST['metasync_options']['whitelabel'])) {
            $gp = isset($_POST['metasync_options']['general']) && is_array($_POST['metasync_options']['general']) ? $_POST['metasync_options']['general'] : [];
            $wp = isset($_POST['metasync_options']['whitelabel']) && is_array($_POST['metasync_options']['whitelabel']) ? $_POST['metasync_options']['whitelabel'] : [];
            $plugin_name = isset($gp['white_label_plugin_name']) ? trim((string) $gp['white_label_plugin_name']) : '';
            $logo = isset($wp['logo']) ? trim((string) $wp['logo']) : '';
            $author = isset($gp['white_label_plugin_author']) ? trim((string) $gp['white_label_plugin_author']) : '';
            $author_uri = isset($gp['white_label_plugin_author_uri']) ? trim((string) $gp['white_label_plugin_author_uri']) : '';
            $plugin_uri = isset($gp['white_label_plugin_uri']) ? trim((string) $gp['white_label_plugin_uri']) : '';
            $domain = isset($wp['domain']) ? trim((string) $wp['domain']) : '';
            $description = isset($gp['white_label_plugin_description']) ? trim((string) $gp['white_label_plugin_description']) : '';

            $has_core_field = (!empty($plugin_name) || (!empty($logo) && filter_var($logo, FILTER_VALIDATE_URL)) || !empty($author));
            $has_optional_only = (!empty($author_uri) || !empty($plugin_uri) || (!empty($domain) && filter_var($domain, FILTER_VALIDATE_URL)) || !empty($description));

            if ($has_optional_only && !$has_core_field) {
                $validation_errors[] = 'Add at least one of: Plugin Name, Logo URL, or Author to save whitelabel settings.';
            }
        }

        #check If there are any validation errors collected
        if (!empty($validation_errors)) {

            # Send a JSON error response containing the validation error messages
            wp_send_json_error([
                'errors' => $validation_errors
            ]);

            # Stop further execution of the function
            return;
        }

        # Get current options to check for API key changes
        $old_options = Metasync::get_option('general') ?? [];
        $old_api_key = $old_options['searchatlas_api_key'] ?? '';
        
        # Save the options in the database (preserves all sections)
        Metasync::set_option($metasync_options);

        #get the fresh option data
        $data = Metasync::get_option('general');
        $new_api_key = $data['searchatlas_api_key'] ?? '';

        # Check if API key changed
        $api_key_changed = $old_api_key !== $new_api_key;
        $api_key_added = empty($old_api_key) && !empty($new_api_key);
        $api_key_removed = !empty($old_api_key) && empty($new_api_key);
        
        # initialize MetaSync request API class
        $sync_request = new Metasync_Sync_Requests();
        # Validate searchatlas_api_key
        $response = $sync_request->SyncCustomerParams();
        #Retrieve the response code
        $responseCode = wp_remote_retrieve_response_code($response);

        if ($responseCode == 200) { // Check if the response code is 200 (OK)
            $dt = new DateTime(); // Create a new DateTime instance to get the current timestamp
            // Retrieve the existing MetaSync options
            $send_auth_token_timestamp = Metasync::get_option();        
            // Update the 'send_auth_token_timestamp' field with the current timestamp
            $send_auth_token_timestamp['general']['send_auth_token_timestamp'] = $dt->format('M d, Y  h:i:s A');        
            // Save the updated options back to MetaSync
            Metasync::set_option($send_auth_token_timestamp);
            
            # Handle heartbeat system based on API key changes
            if ($api_key_added) {
                // New API key added - schedule heartbeat cron and test immediately  
                $this->maybe_schedule_heartbeat_cron();
                do_action('metasync_trigger_immediate_heartbeat', 'Manual API key update - new key added');
            } elseif ($api_key_removed) {
                // API key removed - unschedule heartbeat cron and clear cache
                $this->unschedule_heartbeat_cron(); 
                delete_transient('metasync_heartbeat_status_cache');
            } elseif ($api_key_changed && !empty($new_api_key)) {
                // API key changed to different non-empty value - test new key immediately
                do_action('metasync_trigger_immediate_heartbeat', 'Manual API key update - key changed');
            }
        }      
    
        # Handle WhiteLabel settings processing (this was missing!)
        if (isset($_POST['metasync_options']['whitelabel'])) {
            
            $whitelabel_data = $_POST['metasync_options']['whitelabel'];
            $existing_whitelabel = $metasync_options['whitelabel'] ?? [];
            
            // Handle logo field
            if (isset($whitelabel_data['logo'])) {
                $logo_value = trim($whitelabel_data['logo']);
            
                if (!empty($logo_value) && filter_var($logo_value, FILTER_VALIDATE_URL)) {
                    $metasync_options['whitelabel']['logo'] = esc_url_raw($logo_value);
                } else {
                    $metasync_options['whitelabel']['logo'] = '';
                }
            }
            
            // Handle domain field
            if (isset($whitelabel_data['domain'])) {
                $domain_value = trim($whitelabel_data['domain']);
                if (!empty($domain_value) && filter_var($domain_value, FILTER_VALIDATE_URL)) {
                    $metasync_options['whitelabel']['domain'] = esc_url_raw($domain_value);
                } else {
                    $metasync_options['whitelabel']['domain'] = '';
                }
            }

            // Handle settings password field
            if (isset($whitelabel_data['settings_password'])) {
                $password_value = trim($whitelabel_data['settings_password']);

                // Check if Hide Settings is enabled
                $hide_settings_enabled = isset($whitelabel_data['hide_settings']) && $whitelabel_data['hide_settings'] == '1';

                // If Hide Settings is enabled, password is required
                if ($hide_settings_enabled && empty($password_value)) {
                    // Keep the existing password, don't allow clearing
                    if (isset($existing_whitelabel['settings_password'])) {
                        $metasync_options['whitelabel']['settings_password'] = $existing_whitelabel['settings_password'];
                    }
                } else {
                    // Store password
                    $metasync_options['whitelabel']['settings_password'] = sanitize_text_field($password_value);
                }
            } else {
                // Preserve existing password if not submitted
                if (isset($existing_whitelabel['settings_password'])) {
                    $metasync_options['whitelabel']['settings_password'] = $existing_whitelabel['settings_password'];
                }
            }

            // Process hide_settings checkbox (must be explicit since unchecked checkboxes don't send POST data)
            if (isset($whitelabel_data['hide_settings'])) {
                $metasync_options['whitelabel']['hide_settings'] = filter_var($whitelabel_data['hide_settings'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            } else {
                // If checkbox is not present in POST (unchecked), set to 0
                $metasync_options['whitelabel']['hide_settings'] = 0;
            }

            // Handle recovery email field
            if (isset($whitelabel_data['recovery_email'])) {
                $recovery_email = trim($whitelabel_data['recovery_email']);
                if (!empty($recovery_email)) {
                    $sanitized_email = sanitize_email($recovery_email);
                    if (is_email($sanitized_email)) {
                        $metasync_options['whitelabel']['recovery_email'] = $sanitized_email;
                    }
                } else {
                    $metasync_options['whitelabel']['recovery_email'] = '';
                }
            } else {
                // Preserve existing recovery email if not submitted
                if (isset($existing_whitelabel['recovery_email'])) {
                    $metasync_options['whitelabel']['recovery_email'] = $existing_whitelabel['recovery_email'];
                }
            }

            // Validate that recovery email is set when password is set
            $has_password = !empty($metasync_options['whitelabel']['settings_password']);
            $has_recovery_email = !empty($metasync_options['whitelabel']['recovery_email']);
            if ($has_password && !$has_recovery_email) {
                // Clear password if recovery email is not provided
                $metasync_options['whitelabel']['settings_password'] = '';
            }

            // Process access control settings
            if (isset($whitelabel_data['access_control'])) {
                $metasync_options['whitelabel']['access_control'] = Metasync_Access_Control::sanitize_access_control($whitelabel_data['access_control']);
            }

            // Final validation: Check if Settings access is restricted and ensure password exists
            if (!empty($metasync_options['whitelabel']['hide_settings']) && empty($metasync_options['whitelabel']['settings_password'])) {
                // Force disable hide_settings if no password
                $metasync_options['whitelabel']['hide_settings'] = 0;
            }

            // Update timestamp
            $metasync_options['whitelabel']['updated_at'] = time();

            // Save the updated options (this was missing!)
            Metasync::set_option($metasync_options);

        }
    
        # set the redirect url - use the correct settings page slug
        $redirect_url = isset($_GET['tab']) ?
                        admin_url('admin.php?page=' . self::$page_slug . '-settings&tab='.$_GET['tab']) :
                        admin_url('admin.php?page=' . self::$page_slug . '-settings');

        # Send a success response with a redirect to avoid resubmission
        wp_send_json_success(array(
            'message' => 'Settings saved successfully!',
            'redirect_url' => $redirect_url
        ));
    }

    /**
     * AJAX handler for saving execution settings
     */
    public function ajax_save_execution_settings() {
        // Check nonce for security
        if (!isset($_POST['execution_settings_nonce']) || !wp_verify_nonce($_POST['execution_settings_nonce'], 'metasync_execution_settings_nonce')) {
            wp_send_json_error(array('message' => 'Invalid security token. Please refresh the page and try again.'));
            return;
        }

        // Check user permissions
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(array('message' => 'Insufficient permissions to save settings.'));
            return;
        }

        // Get and sanitize all settings
        $settings = array(
            'max_execution_time' => isset($_POST['max_execution_time']) ? absint($_POST['max_execution_time']) : 30,
            'max_memory_limit' => isset($_POST['max_memory_limit']) ? absint($_POST['max_memory_limit']) : 256,
            'log_batch_size' => isset($_POST['log_batch_size']) ? absint($_POST['log_batch_size']) : 1000,
            'action_scheduler_batches' => isset($_POST['action_scheduler_batches']) ? absint($_POST['action_scheduler_batches']) : 1,
            'otto_rate_limit' => isset($_POST['otto_rate_limit']) ? absint($_POST['otto_rate_limit']) : 10,
            'queue_cleanup_days' => isset($_POST['queue_cleanup_days']) ? absint($_POST['queue_cleanup_days']) : 31
        );

        // Get server limits for validation
        $server_limits = $this->get_server_limits();
        
        // Validate ranges
        if ($settings['max_execution_time'] < 1 || $settings['max_execution_time'] > 300) {
            wp_send_json_error(array('message' => 'Max Execution Time must be between 1 and 300 seconds.'));
            return;
        }
        
        // Check if execution time exceeds server limit
        if ($server_limits['max_execution_time_raw'] != -1 && $settings['max_execution_time'] > $server_limits['max_execution_time_raw']) {
            wp_send_json_error(array('message' => sprintf('Max Execution Time exceeds server limit of %d seconds. Please reduce the value.', $server_limits['max_execution_time_raw'])));
            return;
        }

        // Only validate memory limit if server allows changing it
        $can_change_memory = $this->can_change_memory_limit();
        if ($can_change_memory && ($settings['max_memory_limit'] < 64 || $settings['max_memory_limit'] > 512)) {
            wp_send_json_error(array('message' => 'Max Memory Limit must be between 64 and 512 MB.'));
            return;
        }
        
        // Check if memory limit exceeds server limit
        if ($can_change_memory && $server_limits['memory_limit_raw'] != -1 && $settings['max_memory_limit'] > $server_limits['memory_limit_raw']) {
            wp_send_json_error(array('message' => sprintf('Max Memory Limit exceeds server limit of %d MB. Please reduce the value.', $server_limits['memory_limit_raw'])));
            return;
        }
        
        // If server doesn't allow changing memory limit, ignore the submitted value
        if (!$can_change_memory) {
            // Keep existing setting or use default
            $existing_settings = get_option('metasync_execution_settings', array());
            $settings['max_memory_limit'] = isset($existing_settings['max_memory_limit']) 
                ? $existing_settings['max_memory_limit'] 
                : 256; // Default fallback
        }

        if ($settings['log_batch_size'] < 100 || $settings['log_batch_size'] > 5000) {
            wp_send_json_error(array('message' => 'Log Batch Size must be between 100 and 5000 lines.'));
            return;
        }

        if ($settings['action_scheduler_batches'] < 1 || $settings['action_scheduler_batches'] > 10) {
            wp_send_json_error(array('message' => 'Action Scheduler Concurrent Batches must be between 1 and 10.'));
            return;
        }

        if ($settings['otto_rate_limit'] < 1 || $settings['otto_rate_limit'] > 60) {
            wp_send_json_error(array('message' => 'OTTO API Calls Per Minute must be between 1 and 60.'));
            return;
        }

        if ($settings['queue_cleanup_days'] < 7 || $settings['queue_cleanup_days'] > 90) {
            wp_send_json_error(array('message' => 'Queue Auto-Cleanup Days must be between 7 and 90 days.'));
            return;
        }

        // Save settings
        $saved = update_option('metasync_execution_settings', $settings);

        if ($saved !== false) {
            wp_send_json_success(array(
                'message' => 'Execution settings saved successfully!'
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to save settings. Please try again.'));
        }
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
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->render_plugin_header('Dashboard'); ?>
        
        <?php $this->render_navigation_menu('dashboard'); ?>
            
            <div class="dashboard-card">
                <h2>📊 <?php echo esc_html($this->get_effective_menu_title()); ?> Dashboard</h2>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Access your <?php echo esc_html(Metasync::get_effective_plugin_name()); ?> dashboard to view analytics, manage SEO settings, and monitor your site performance.</p>
                <?php
        if (!isset(Metasync::get_option('general')['linkgraph_token']) || Metasync::get_option('general')['linkgraph_token'] == '') {
                    echo '<p style="color: #d54e21; margin-bottom: 15px;">⚠️ Authentication required: Please authenticate with your ' . esc_html(Metasync::get_effective_plugin_name()) . ' account and save your auth token in general settings.</p>';
                    echo '<a href="' . admin_url('admin.php?page=' . self::$page_slug) . '" class="button button-secondary">Go to Settings</a>';
                } else {
                    echo '<a href="' . esc_url($this->get_dashboard_url()) . '" target="_blank" class="button button-primary">🌐 Open Dashboard</a>';
                }
                ?>
            </div>
        </div>
    <?php
    }

    /**
     * Robots.txt page callback
     */
    public function create_admin_robots_txt_page()
    {
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->render_plugin_header('Robots.txt'); ?>
        
        <?php $this->render_navigation_menu('robots_txt'); ?>
        
        <?php
        // Load robots.txt management class
        require_once plugin_dir_path(dirname(__FILE__)) . 'robots-txt/class-metasync-robots-txt.php';

        $robots_txt = Metasync_Robots_Txt::get_instance();

        // Handle form submissions
        if (isset($_POST['metasync_robots_txt_nonce'])) {
            check_admin_referer('metasync_save_robots_txt', 'metasync_robots_txt_nonce');

            if (isset($_POST['robots_content'])) {
                $content = wp_unslash($_POST['robots_content']);

                // Validate content
                $validation = $robots_txt->validate_content($content);

                if ($validation['valid']) {
                    $result = $robots_txt->write_robots_file($content);

                    if (is_wp_error($result)) {
                        echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
                    } else {
                        echo '<div class="notice notice-success"><p>' . esc_html__('robots.txt file saved successfully!', 'metasync') . '</p></div>';

                        // Show warnings if any
                        if (!empty($validation['warnings'])) {
                            foreach ($validation['warnings'] as $warning) {
                                echo '<div class="notice notice-warning"><p>' . esc_html($warning) . '</p></div>';
                            }
                        }
                    }
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Validation failed. Please fix the errors below:', 'metasync') . '</p>';
                    foreach ($validation['errors'] as $error) {
                        echo '<p>- ' . esc_html($error) . '</p>';
                    }
                    echo '</div>';
                }
            }
        }

        // Get current content
        $current_content = $robots_txt->read_robots_file();
        if (is_wp_error($current_content)) {
            echo '<div class="notice notice-error"><p>' . esc_html($current_content->get_error_message()) . '</p></div>';
            $current_content = '';
        }

        // Get backup history
        $backups = $robots_txt->get_backup_history(10);

        // Get file info
        $file_exists = $robots_txt->file_exists();
        $is_writable = $robots_txt->is_writable();

        // Render the page using the robots.txt class
        $robots_txt->render($this, $current_content, $backups, $file_exists, $is_writable);
        ?>
        </div>
    <?php
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
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->render_plugin_header('Custom HTML Pages'); ?>
        
        <?php $this->render_navigation_menu('custom_pages'); ?>
        
        <div class="metasync-page-content">
            <?php
            // Load custom pages admin interface
            require_once plugin_dir_path(dirname(__FILE__)) . 'custom-pages/class-metasync-custom-pages-admin.php';
            Metasync_Custom_Pages_Admin::render_admin_page();
            ?>
        </div>
        
        </div>
        <?php
    }

    /**
     * 404 Monitor page callback
     */
    public function create_admin_404_monitor_page()
    {
        // Initialize 404 monitor with proper database
        require_once plugin_dir_path(dirname(__FILE__)) . '404-monitor/class-metasync-404-monitor-database.php';
        require_once plugin_dir_path(dirname(__FILE__)) . '404-monitor/class-metasync-404-monitor.php';

        $db_404 = new Metasync_Error_Monitor_Database();
        $ErrorMonitor = new Metasync_Error_Monitor($db_404);
        
        // Create the enhanced interface
        $ErrorMonitor->create_admin_plugin_interface();
    }

    /**
     * Site Verification page callback
     */
    public function create_admin_search_engine_verification_page()
    {
        $page_slug = self::$page_slug . '_searchengines-verification';
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->render_plugin_header('Site Verification'); ?>
        
        <?php $this->render_navigation_menu('site-verification'); ?>
            
            <form method="post" action="options.php">
                <div class="dashboard-card">
                    <h2>🔍 Search Engine Verification</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Verify your site ownership with major search engines to access their tools and analytics.</p>
                    <?php
        settings_fields($this::option_group);
        do_settings_sections($page_slug);
                    ?>
                </div>
                
                <!-- Save button removed - using floating notification system instead -->
            </form>
        </div>
        <?php
    }

    /**
     * Local Business page callback
     */
    public function create_admin_local_business_page()
    {
        $page_slug = self::$page_slug . '_local-seo';
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->render_plugin_header('Local Business'); ?>
        
        <?php $this->render_navigation_menu('local-business'); ?>
            
            <form method="post" action="options.php">
                <div class="dashboard-card">
                    <h2>🏢 Local Business Configuration</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure your local business information for better local search engine optimization.</p>
                    <?php
        settings_fields($this::option_group);
        do_settings_sections($page_slug);
                    ?>
                </div>
                
                <!-- Save button removed - using floating notification system instead -->
            </form>
        </div>
        <?php
    }

    /**
     * Code Snippets page callback
     */
    public function create_admin_code_snippets_page()
    {
        $page_slug = self::$page_slug . '_code-snippets';
        ?>
       <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->render_plugin_header('Code Snippets'); ?>
        
        <?php $this->render_navigation_menu('code-snippets'); ?>
            
            <form method="post" action="options.php">
                <div class="dashboard-card">
                    <h2>📝 Code Snippets Management</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Add custom code snippets to enhance your site functionality and tracking capabilities.</p>
                    <?php
        settings_fields($this::option_group);
        do_settings_sections($page_slug);
                    ?>
                </div>
                
                <!-- Save button removed - using floating notification system instead -->
            </form>
        </div>
        <?php
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
        $instant_index = new Metasync_Instant_Index();
        $instant_index->show_google_instant_indexing_settings();
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
        $instant_index = new Metasync_Instant_Index();
        $instant_index->show_google_instant_indexing_console();
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
        ?>
       <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->render_plugin_header('Settings'); ?>
        
        <?php $this->render_navigation_menu('optimal-settings'); ?>
            
            <div class="dashboard-card">
                <h2>🚀 Site Compatibility Status</h2>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Check your site's compatibility with optimal <?php echo esc_html(Metasync::get_effective_plugin_name()); ?> settings.</p>
                <?php
        $optimal_settings = new Metasync_Optimal_Settings();
        $optimal_settings->site_compatible_status_view();
                ?>
            </div>

            <div class="dashboard-card">
                <h2>⚙️ Optimization Settings</h2>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure optimization settings for best performance.</p>
                <?php
        $this->optimization_settings_options();
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Global Options page callback
     */
    public function create_admin_global_settings_page()
    {
        $page_slug = self::$page_slug . '_common-settings';
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->render_plugin_header('Settings'); ?>
        
        <?php $this->render_navigation_menu('global-settings'); ?>
            
            <form method="post" action="options.php">
                <div class="dashboard-card">
                    <h2>🌐 Global Configuration</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure global settings that apply across your entire site.</p>
                    <?php
        settings_fields($this::option_group);
        do_settings_sections($page_slug);
                    ?>
                </div>
                
                <!-- Save button removed - using floating notification system instead -->
            </form>
        </div>
        <?php
    }

    /**
     * Common Meta Options page callback
     */
    public function create_admin_common_meta_settings_page()
    {
        $page_slug = self::$page_slug . '_common-meta-settings';
        ?>
       <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->render_plugin_header('Settings'); ?>
        
        <?php $this->render_navigation_menu('common-meta-settings'); ?>
            
            <form method="post" action="options.php">
                <div class="dashboard-card">
                    <h2>🏷️ Meta Configuration</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure common meta tags and SEO settings for your site.</p>
                    <?php
        settings_fields($this::option_group);
        do_settings_sections($page_slug);
                    ?>
                </div>
                
                <!-- Save button removed - using floating notification system instead -->
            </form>
        </div>
        <?php
    }

    /**
     * Social meta page callback
     */
    public function create_admin_social_meta_page()
    {
        $page_slug = self::$page_slug . '_social-meta';
        ?>
       <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->render_plugin_header('Settings'); ?>
        
        <?php $this->render_navigation_menu('social-meta'); ?>
            
            <form method="post" action="options.php">
                <div class="dashboard-card">
                    <h2>📲 Social Meta Tags</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure how your content appears when shared on social media platforms.</p>
                    <?php
        settings_fields($this::option_group);
        do_settings_sections($page_slug);
                    ?>
                </div>
                
                <!-- Save button removed - using floating notification system instead -->
            </form>
        </div>
        <?php
    }


    /**
     * Indexation Control page callback
     */
    public function create_admin_seo_controls_page()
    {
        $page_slug = self::$page_slug . '_seo-controls';
        ?>
       <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->render_plugin_header('Indexation Control'); ?>
        
        <?php $this->render_navigation_menu('seo_controls'); ?>
        
        <!-- Status Messages Container -->
        <div id="seo-controls-messages"></div>
            
            <form method="post" action="options.php" id="metaSyncSeoControlsForm">
                <div class="dashboard-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="margin: 0;">🚫 Indexation Control</h2>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=metasync-import-external&tab=indexation')); ?>" class="button button-secondary">
                            <span>📥</span> Import from SEO Plugins
                        </a>
                    </div>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Control which archive pages should be disallowed from search engine indexing to improve your site's SEO health and conserve crawl budget.</p>
                    <?php
                         settings_fields($this::option_group);
                         do_settings_sections($page_slug);
                         
                         // Add nonce for AJAX security
                         wp_nonce_field('meta_sync_seo_controls_nonce', 'meta_sync_seo_controls_nonce');
                    ?>
                </div>
                
                <!-- Save button removed - using floating notification system instead -->
            </form>
        </div>
        <?php
    }

    /**
     * Site Optimal Settings page callback
     */
    public function optimization_settings_options()
    {
        $page_slug = self::$page_slug . '_optimal-settings';
        // $sitemap_slug = self::$page_slug . '_sitemap-optimal-settings';
        $site_info_slug = self::$page_slug . '_site-info-settings';

        printf('<form method="post" action="options.php">');
        settings_fields($this::option_group);
        do_settings_sections($page_slug);
        // do_settings_sections($sitemap_slug);
        do_settings_sections($site_info_slug);
        submit_button();
        printf('</form>');
    }

    /**
     * redirection page callback with tabs
     */
    public function create_admin_redirections_page()
    {
        // Handle form processing
        $this->handle_redirection_form_processing();
        
        // Check if database structure needs updating
        $this->check_database_structure();
        
        // Ensure 404 monitor table exists
        $this->ensure_404_monitor_table();
        
        // Get current tab from URL parameter
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'redirections';
        
        // Add CSS and JavaScript for tabs
        $this->add_tabbed_interface_assets();
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->render_plugin_header('Redirections'); ?>
        
        <?php $this->render_navigation_menu('redirections'); ?>
        
        <?php
        // Create tab navigation
        $this->render_tab_navigation($current_tab);
        
        // Render tab content
        $this->render_tab_content($current_tab);
        ?>
        </div>
        <?php
    }

    /**
     * Display transient error/success messages for redirections
     */
    public function display_redirection_messages()
    {
        // Check for error message
        if ($error = get_transient('metasync_redirection_error')) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
            delete_transient('metasync_redirection_error');
        }

        // Check for success message
        if ($success = get_transient('metasync_redirection_success')) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($success) . '</p></div>';
            delete_transient('metasync_redirection_success');
        }
    }

    /**
     * Perform redirect with JavaScript fallback if headers already sent
     */
    private function safe_redirect($url)
    {
        if (!headers_sent()) {
            wp_redirect($url);
            exit;
        } else {
            // Headers already sent, use JavaScript redirect
            echo '<script type="text/javascript">window.location.href = "' . esc_url($url) . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url($url) . '"></noscript>';
            exit;
        }
    }

    /**
     * Handle redirection form processing
     */
    private function handle_redirection_form_processing()
    {
        if (!isset($_POST['submit'])) {
            return;
        }

        // Try both nonce fields for compatibility
        $nonce_valid = false;
        
        // Try dedicated nonce field first
        if (isset($_POST['metasync_redirection_nonce']) && wp_verify_nonce($_POST['metasync_redirection_nonce'], 'metasync_redirection_form')) {
            $nonce_valid = true;
        }
        // Fallback to standard nonce field
        elseif (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'metasync_redirection_form')) {
            $nonce_valid = true;
        }
        
        if (!$nonce_valid) {
            wp_die('Security check failed. Please refresh and try again.');
        }
        
        // Check user capabilities
        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('Insufficient permissions.');
        }

        // Sanitize form data
        $source_urls = isset($_POST['source_url']) ? array_map('sanitize_text_field', $_POST['source_url']) : [];
        $search_types = isset($_POST['search_type']) ? array_map('sanitize_text_field', $_POST['search_type']) : [];
        $destination_url = isset($_POST['destination_url']) ? sanitize_text_field($_POST['destination_url']) : '';
        $redirect_type = isset($_POST['redirect_type']) ? intval($_POST['redirect_type']) : 301;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'active';
        // Don't use sanitize_text_field for regex patterns as it escapes backslashes
        // Use wp_unslash to handle magic quotes, then trim without additional escaping
        $regex_pattern = isset($_POST['regex_pattern']) ? wp_unslash(trim($_POST['regex_pattern'])) : '';
        $description = isset($_POST['description']) ? sanitize_text_field($_POST['description']) : '';
        $redirect_id = isset($_POST['redirect_id']) ? intval($_POST['redirect_id']) : 0;

        // Validate required fields
        $validation_errors = [];

        // 1. Validate source URLs
        if (empty($source_urls)) {
            $validation_errors[] = 'Please enter at least one source URL.';
        } else {
            $processed_sources = [];
            $empty_count = 0;

            foreach ($source_urls as $source_url) {
                $trimmed_url = trim($source_url);

                // Check for empty source URLs
                if (empty($trimmed_url)) {
                    $empty_count++;
                    continue;
                }

                // Validate URL format (allow relative paths or full URLs)
                if (!$this->is_valid_url($trimmed_url)) {
                    $validation_errors[] = 'Invalid source URL format: "' . esc_html($trimmed_url) . '". URLs should start with / for relative paths or be complete URLs.';
                }

                // Check for duplicate source URLs
                if (in_array($trimmed_url, $processed_sources)) {
                    $validation_errors[] = 'Duplicate source URL detected: "' . esc_html($trimmed_url) . '".';
                } else {
                    $processed_sources[] = $trimmed_url;
                }
            }

            // If all source URLs are empty
            if ($empty_count === count($source_urls)) {
                $validation_errors[] = 'All source URL fields are empty. Please enter at least one source URL.';
            }
        }

        // 2. Validate redirect type
        $allowed_redirect_types = [301, 302, 307, 410, 451];
        if (!in_array($redirect_type, $allowed_redirect_types)) {
            $validation_errors[] = 'Invalid redirection type selected.';
        }

        // 3. Validate destination URL (required for redirect types other than 410 and 451)
        if (!in_array($redirect_type, [410, 451])) {
            $trimmed_dest = trim($destination_url);
            if (empty($trimmed_dest)) {
                $validation_errors[] = 'Destination URL is required for this redirect type.';
            } elseif (!$this->is_valid_url($trimmed_dest)) {
                $validation_errors[] = 'Invalid destination URL format. URLs should start with / for relative paths or be complete URLs.';
            }
        }

        // 4. Validate status
        $allowed_statuses = ['active', 'inactive'];
        if (!in_array($status, $allowed_statuses)) {
            $validation_errors[] = 'Invalid status selected.';
        }

        // If there are validation errors, redirect back with error messages
        if (!empty($validation_errors)) {
            $error_message = implode(' ', $validation_errors);
            set_transient('metasync_redirection_error', $error_message, 45);
            $this->safe_redirect(admin_url('admin.php?page=searchatlas-redirections'));
        }

        // Build sources array (only non-empty URLs at this point)
        $sources_from = [];
        foreach ($source_urls as $index => $source_url) {
            $trimmed_url = trim($source_url);
            if (!empty($trimmed_url)) {
                $search_type = isset($search_types[$index]) ? $search_types[$index] : 'exact';
                $sources_from[$trimmed_url] = $search_type;
            }
        }

        // Determine pattern type (use the first non-empty pattern type)
        $pattern_type = 'exact';
        foreach ($search_types as $search_type) {
            if (!empty($search_type)) {
                $pattern_type = $search_type;
                break;
            }
        }

        // Validate regex pattern if pattern type is regex
        if ($pattern_type === 'regex') {
            if (empty($regex_pattern)) {
                // Regex pattern type selected but no pattern provided
                set_transient('metasync_redirection_error', 'Please enter a regex pattern when using "Regex Pattern" as the pattern type.', 45);
                $this->safe_redirect(admin_url('admin.php?page=searchatlas-redirections'));
                return;
            }

            // Test if the regex pattern is valid
            // Add delimiters if missing - check if pattern is properly enclosed in matching delimiters
            $test_pattern = $regex_pattern;
            $delimiter_chars = ['/', '#', '~', '%', '@'];
            $has_valid_delimiters = false;

            if (strlen($test_pattern) >= 2) {
                $first_char = $test_pattern[0];
                if (in_array($first_char, $delimiter_chars)) {
                    // Find the last occurrence of the delimiter (before any modifiers)
                    $last_pos = strrpos($test_pattern, $first_char);
                    // Valid if delimiter appears at start and somewhere after (not just at position 0)
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

        // Prepare data for database
        $data = [
            'sources_from' => serialize($sources_from),
            'url_redirect_to' => $destination_url,
            'http_code' => $redirect_type,
            'status' => $status,
            'pattern_type' => $pattern_type,
            'regex_pattern' => $regex_pattern,
            'description' => $description,
        ];

        // Add or update redirection
        try {
            if ($redirect_id > 0) {
                // Update existing redirection
                $result = $this->db_redirection->update($data, $redirect_id);
                if ($result === false) {
                    throw new Exception('Failed to update redirection');
                }
                $message = 'Redirection updated successfully.';
            } else {
                // Add new redirection
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

        // Set success message in transient
        set_transient('metasync_redirection_success', $message, 45);

        // Redirect to prevent form resubmission
        $this->safe_redirect(admin_url('admin.php?page=searchatlas-redirections'));
    }

    /**
     * Validate URL format - allows relative paths starting with / or complete URLs
     *
     * @param string $url The URL to validate
     * @return bool True if valid, false otherwise
     */
    private function is_valid_url($url)
    {
        // Allow relative paths starting with /
        if (strpos($url, '/') === 0) {
            return true;
        }

        // Validate full URLs
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Add CSS and JavaScript for tabbed interface
     */
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
            // Function to switch to a specific tab
            function switchToTab(targetTab) {
                // Update active tab
                $('.metasync-tab-nav a').removeClass('active');
                $('.metasync-tab-nav a[data-tab="' + targetTab + '"]').addClass('active');
                
                // Show target content
                $('.metasync-tab-content').removeClass('active');
                $('#' + targetTab + '-content').addClass('active');
            }
            
            // Initialize tabs immediately
            function initializeTabs() {
                // Check URL parameter on page load
                var urlParams = new URLSearchParams(window.location.search);
                var currentTab = urlParams.get('tab');

                // Always ensure a tab is active
                if (currentTab && (currentTab === 'redirections' || currentTab === '404-monitor')) {
                    // Switch to the tab specified in URL
                    switchToTab(currentTab);
                } else {
                    // No tab parameter or invalid tab, ensure redirections is active
                    switchToTab('redirections');
                    currentTab = 'redirections';
                }

                // Clean up irrelevant pagination parameters based on active tab
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

                // Update URL if cleanup was needed
                if (needsCleanup) {
                    var newUrl = window.location.pathname + '?' + urlParams.toString();
                    window.history.replaceState({}, '', newUrl);
                }
            }

            // Initialize tabs immediately
            initializeTabs();

            // Also initialize after a short delay as backup
            setTimeout(initializeTabs, 100);
            
            // Handle tab switching on click
            $('.metasync-tab-nav a').on('click', function(e) {
                e.preventDefault();

                var targetTab = $(this).data('tab');
                switchToTab(targetTab);

                // Update URL without page reload and clean up pagination parameters
                var url = new URL(window.location);
                url.searchParams.set('tab', targetTab);

                // Remove all pagination parameters when switching tabs
                url.searchParams.delete('paged');
                url.searchParams.delete('paged_404');
                url.searchParams.delete('paged_redir');

                window.history.pushState({}, '', url);
            });
        });
        </script>
        <?php
    }

    /**
     * Render tab navigation
     */
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

    /**
     * Render tab content
     */
    private function render_tab_content($current_tab)
    {
        // Always render both tab contents so JavaScript can switch between them
        $this->render_redirections_tab($current_tab);
        $this->render_404_monitor_tab($current_tab);
    }

    /**
     * Render redirections tab content
     */
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

    /**
     * Render 404 monitor tab content
     */
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
                // Initialize 404 monitor with proper database
                require_once plugin_dir_path(dirname(__FILE__)) . '404-monitor/class-metasync-404-monitor-database.php';
                require_once plugin_dir_path(dirname(__FILE__)) . '404-monitor/class-metasync-404-monitor.php';
                
                $db_404 = new Metasync_Error_Monitor_Database();
                $ErrorMonitor = new Metasync_Error_Monitor($db_404);
                
                // Create the enhanced interface
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

    /**
     * Ensure 404 monitor table exists
     */
    private function ensure_404_monitor_table()
    {
        global $wpdb;
        require_once plugin_dir_path(dirname(__FILE__)) . '404-monitor/class-metasync-404-monitor-database.php';
        
        $table_name = $wpdb->prefix . Metasync_Error_Monitor_Database::$table_name;
        
        // Check if table exists
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
            // Table doesn't exist, run migration
            require_once plugin_dir_path(dirname(__FILE__)) . 'database/class-db-migrations.php';
            MetaSync_DBMigration::run_migrations();
        }
    }

    /**
     * Check if database structure needs updating
     */
    private function check_database_structure()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'metasync_redirections';
        
        // Check if table exists
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
            return; // Table doesn't exist, migration will handle it
        }
        
        // Check if required columns exist
        $columns = $wpdb->get_col("DESCRIBE {$table_name}");
        $required_columns = ['pattern_type', 'regex_pattern', 'description', 'created_at', 'updated_at', 'last_accessed_at'];
        
        $missing_columns = array_diff($required_columns, $columns);
        
        if (!empty($missing_columns)) {
            add_action('admin_notices', function() use ($missing_columns) {
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p><strong>MetaSync:</strong> Database structure needs updating. Missing columns: ' . implode(', ', $missing_columns) . '</p>';
                echo '<p><button type="button" class="button button-secondary" onclick="updateDatabaseStructure()">Update Database Structure</button></p>';
                echo '</div>';
                
                // Add JavaScript for the update button
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

    /**
     * AJAX handler for updating database structure
     */
    public function ajax_update_db_structure()
    {
        // Check user capabilities
        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('Insufficient permissions');
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'metasync_update_db_nonce')) {
            wp_die('Security check failed');
        }

        try {
            // Force update redirection table structure
            $this->db_redirection->force_table_update();

            wp_send_json_success('Database structure updated successfully');
        } catch (Exception $e) {
            wp_send_json_error('Database update failed: ' . $e->getMessage());
        }
    }

    /**
     * AJAX handler to save wizard progress
     *
     * @since    1.0.0
     */
    public function ajax_save_wizard_progress()
    {
        check_ajax_referer('metasync_wizard', 'nonce');

        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $step = isset($_POST['step']) ? intval($_POST['step']) : 0;
        $data = isset($_POST['data']) ? $_POST['data'] : array();

        // Get current options
        $options = get_option('metasync_options', array());

        // Save step-specific data to options
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

    /**
     * AJAX handler to complete wizard
     *
     * @since    1.0.0
     */
    public function ajax_complete_wizard()
    {
        check_ajax_referer('metasync_wizard', 'nonce');

        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        // Mark wizard as completed
        update_option('metasync_wizard_completed', array(
            'completed' => true,
            'completed_at' => current_time('mysql'),
            'completed_by' => get_current_user_id(),
            'version' => METASYNC_VERSION
        ));

        // Clean up wizard state
        $user_id = get_current_user_id();
        delete_transient("metasync_wizard_state_{$user_id}");

        wp_send_json_success(array('message' => 'Wizard completed'));
    }

    /**
     * AJAX handler to validate robots.txt content
     */
    public function ajax_validate_robots()
    {
        // Check user capabilities
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

    /**
     * AJAX handler to get default robots.txt content
     */
    public function ajax_get_default_robots()
    {
        // Check user capabilities
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

    /**
     * AJAX handler to preview robots.txt backup content
     */
    public function ajax_preview_robots_backup()
    {
        // Check user capabilities
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Get backup ID
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

    /**
     * AJAX handler to delete robots.txt backup
     */
    public function ajax_delete_robots_backup()
    {
        // Check user capabilities
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(array('message' => esc_html__('Insufficient permissions', 'metasync')));
            return;
        }

        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'metasync_delete_robots_backup')) {
            wp_send_json_error(array('message' => esc_html__('Security check failed', 'metasync')));
            return;
        }

        // Get backup ID
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

    /**
     * AJAX handler to restore robots.txt backup
     */
    public function ajax_restore_robots_backup()
    {
        // Check user capabilities
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(array('message' => esc_html__('Insufficient permissions', 'metasync')));
            return;
        }

        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'metasync_restore_robots_backup')) {
            wp_send_json_error(array('message' => esc_html__('Security check failed', 'metasync')));
            return;
        }

        // Get backup ID
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
            // Get the updated content
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
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'metasync_404_redirect')) {
            wp_die('Security check failed');
        }

        // Check user capabilities
        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('Insufficient permissions');
        }

        $uri = sanitize_text_field($_POST['uri']);
        $redirect_url = sanitize_url($_POST['redirect_url']);

        if (empty($uri) || empty($redirect_url)) {
            wp_send_json_error('Missing required parameters');
        }

        // Initialize 404 monitor
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

    /**
     * AJAX handler for testing host blocking with GET request
     */
    public function ajax_test_host_blocking_get()
    {
        // Check user capabilities
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $endpoint = 'https://wp-check.searchatlas.com/ping';
        $start_time = microtime(true);
        
        // Make GET request using wp_remote_get
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
        $response_time = round(($end_time - $start_time) * 1000, 2); // Convert to milliseconds
        
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
            
            // Parse the response to check for blocking
            $is_blocked = false;
            $status_text = 'success';
            $details = 'GET request completed successfully';
            $parsed_response = null;
            
            if ($status_code === 200) {
                // Try to parse the JSON response
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
                    // If we can't parse the response structure, consider it blocked
                    $is_blocked = true;
                    $status_text = 'error';
                    $details = 'Unable to parse response structure - possible blocking or endpoint issue';
                }
            } else {
                // External endpoint itself returned non-200
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

    /**
     * AJAX handler for testing host blocking with POST request
     */
    public function ajax_test_host_blocking_post()
    {
        // Check user capabilities
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $endpoint = 'https://wp-check.searchatlas.com/ping';
        $start_time = microtime(true);
        
        // Prepare test data
        $test_data = array(
            'test' => 'host_blocking_test',
            'timestamp' => current_time('mysql'),
            'source' => 'metasync_plugin',
            'method' => 'POST'
        );
        
        // Make POST request using wp_remote_post
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
        $response_time = round(($end_time - $start_time) * 1000, 2); // Convert to milliseconds
        
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
            
            // Parse the response to check for blocking
            $is_blocked = false;
            $status_text = 'success';
            $details = 'POST request completed successfully';
            $parsed_response = null;
            
            if ($status_code === 200) {
                // Try to parse the JSON response
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
                    // If we can't parse the response structure, consider it blocked
                    $is_blocked = true;
                    $status_text = 'error';
                    $details = 'Unable to parse response structure - possible blocking or endpoint issue';
                }
            } else {
                // External endpoint itself returned non-200
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
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->render_plugin_header('Error Logs'); ?>
        
        <?php $this->render_navigation_menu('error-log'); ?>
            
            <div class="dashboard-card">
                <h2>⚠️ Error Logs Management</h2>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">View and manage system error logs to troubleshoot issues and monitor plugin performance.</p>
                <?php
        $error_logs = new Metasync_Error_Logs();

        if ($error_logs->can_show_error_logs()) {
            $log_content = $error_logs->get_error_logs(50);
            
            if (!empty(trim($log_content))) {
                $error_logs->show_copy_button();
                $error_logs->show_logs();
                $error_logs->show_info();
            } else {
                echo '<div class="dashboard-empty-state">';
                echo '<p style="color: var(--dashboard-text-secondary); font-style: italic; text-align: center; padding: 40px 20px;">✅ Log file is empty - no errors recorded.</p>';
                echo '</div>';
            }
        } else {
            // Display the specific error message
            $error_message = $error_logs->get_error_message();
            if (!empty($error_message)) {
                echo '<div class="dashboard-empty-state">';
                echo '<p style="color: #d54e21; font-weight: bold; text-align: center; padding: 20px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; margin: 20px 0;">';
                echo '⚠️ ' . esc_html($error_message);
                echo '</p>';
                echo '</div>';
            } else {
                echo '<div class="dashboard-empty-state">';
                echo '<p style="color: var(--dashboard-text-secondary); font-style: italic; text-align: center; padding: 40px 20px;">⚠️ Unable to access error log file. Please check permissions.</p>';
                echo '</div>';
            }
        }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Compatibility page callback
     */
    public function create_admin_compatibility_page()
    {
        ?>
       <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->render_plugin_header('Compatibility'); ?>
        
        <?php $this->render_navigation_menu('compatibility'); ?>
            
            <div class="dashboard-card">
                <h2>🔧 Plugin Compatibility</h2>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Check compatibility status with popular WordPress page builders, SEO plugins, and caching solutions.</p>
                
                <?php $this->render_compatibility_sections(); ?>
            </div>
            
            <!-- Section: Host Blocking Test -->
            <div id="ms-comp-host-test" class="dashboard-card" style="margin-top: 20px;">
                <details open>
                    <summary style="cursor:pointer; list-style:none;">
                        <div style="display:flex; justify-content: space-between; align-items:center;">
                            <div style="flex:1;">
                                <h2 style="margin: 0;">🌐 Host Blocking Test</h2>
                                <p style="color: var(--dashboard-text-secondary); margin: 6px 0 0 0;">Verify if this site can reach <?php echo esc_html(Metasync::get_effective_plugin_name()); ?> services.</p>
                            </div>
                        </div>
                    </summary>
                    <div style="margin-top:16px; background: #f8f9fa; border: 1px solid #dee2e6; padding: 20px; border-radius: 8px;">
                        <h3 style="margin-top: 0; color: #495057;">Test Configuration</h3>
                        <p style="margin-bottom: 16px; color: #6c757d;">Test connectivity by running both GET and POST requests. Results appear below.</p>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <button type="button" id="test-both-requests" class="button button-primary">
                                🔄 Test Both Requests
                            </button>
                        </div>
                    </div>
                    <div id="host-test-results" style="display: none; margin-top:16px;">
                        <h3 style="color: #495057; margin-bottom: 10px;">Test Results</h3>
                        <div id="test-results-content"></div>
                    </div>
                </details>
            </div>
            
            <!-- Host Blocking Test JavaScript -->
            <script>
            (function() {
                // Wait for jQuery to be available
                function initHostBlockingTest() {
                    if (typeof jQuery === 'undefined') {
                        setTimeout(initHostBlockingTest, 100);
                        return;
                    }
                    
                    jQuery(document).ready(function($) {
                        // Ensure ajax URL is available in this scope
                        var ajaxUrl = (typeof window.ajaxurl !== 'undefined' && window.ajaxurl)
                            ? window.ajaxurl
                            : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                        
                        // Check if button exists
                        var $btn = $('#test-both-requests');
                        
                        // Host blocking test functionality - only "Test Both Requests" option available
                        // Use both direct binding and delegated event for maximum compatibility
                        $btn.on('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            runHostTest('BOTH', $);
                            return false;
                        });
                        
                        $(document).on('click', '#test-both-requests', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            runHostTest('BOTH', $);
                            return false;
                        });
                        
                        // Expose function globally for debugging
                        window.runHostBlockingTest = function() {
                            runHostTest('BOTH', $);
                        };
                
                        function runHostTest(method, $) {
                            // Always use BOTH method - only option available
                            method = 'BOTH';
                            var buttonId = 'test-both-requests';
                            var $button = $('#' + buttonId);
                            
                            if ($button.length === 0) {
                                alert('Error: Test button not found. Please refresh the page.');
                                return;
                            }
                            
                            var originalText = $button.text();
                            
                            // Disable button and show loading scattered
                            $button.prop('disabled', true);
                            $button.text('🔄 Testing...');
                            
                            // Prepare results area
                            var $resultsDiv = $('#host-test-results');
                            var $resultsContent = $('#test-results-content');
                            $resultsDiv.show();
                            $resultsContent.html('<div class="notice notice-info"><p>Running GET and POST test(s)...</p></div>');
                            
                            var testsToRun = ['GET', 'POST'];
                            var completedTests = 0;
                            var allResults = [];
                            
                            testsToRun.forEach(function(testMethod) {
                                var action = 'metasync_test_host_blocking_' + testMethod.toLowerCase();
                                
                                $.ajax({
                                    url: ajaxUrl,
                                    type: 'POST',
                                    dataType: 'json',
                                    data: { action: action },
                                    timeout: 35000,
                                    success: function(response, textStatus, xhr) {
                                        try {
                                            if (response && response.success && response.data) {
                                                allResults.push(response.data);
                                            } else {
                                                allResults.push({
                                                    method: testMethod,
                                                    status: 'error',
                                                    error: (response && response.data) ? response.data : 'Unexpected response',
                                                    blocked: true,
                                                    details: 'Received non-success response from server.'
                                                });
                                            }
                                        } catch (e) {
                                            allResults.push({
                                                method: testMethod,
                                                status: 'error',
                                                error: 'Response parse error: ' + (e && e.message ? e.message : e),
                                                blocked: true
                                            });
                                        }
                                        finalizeOne();
                                    },
                                    error: function(xhr, status, error) {
                                        var payload = (xhr && xhr.responseText) ? xhr.responseText.substring(0, 500) : '';
                                        allResults.push({
                                            method: testMethod,
                                            status: 'error',
                                            error: 'AJAX failed: ' + error + (payload ? ' — ' + payload : ''),
                                            blocked: true,
                                            details: 'Request did not complete successfully. Status: ' + status
                                        });
                                        finalizeOne();
                                    }
                                });
                            });
                            
                            function finalizeOne() {
                                completedTests++;
                                if (completedTests === testsToRun.length) {
                                    displayResults(allResults);
                                    resetButtons();
                                    // Scroll results into view for clarity
                                    var $container = $('#host-test-results');
                                    if ($container && $container[0] && $container[0].scrollIntoView) {
                                        $container[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
                                    }
                                }
                            }
                            
                            function resetButtons() {
                                $('#test-both-requests').prop('disabled', false);
                                $('#test-both-requests').text('🔄 Test Both Requests');
                            }
                            
                            function displayResults(results) {
                                var html = '';
                                
                                results.forEach(function(result) {
                                    var statusClass = result.status === 'success' ? 'success' : 'error';
                                    var statusIcon = result.status === 'success' ? '✅' : '❌';
                                    var blockedStatus = result.blocked ? 'BLOCKED' : 'ALLOWED';
                                    var blockedClass = result.blocked ? 'blocked' : 'allowed';
                                    
                                    html += '<div class="test-result-item ' + statusClass + '">';
                                    html += '<div class="test-result-header">';
                                    html += '<h4>' + statusIcon + ' ' + result.method + ' Request - ' + blockedStatus + '</h4>';
                                    html += '<span class="test-status ' + blockedClass + '">' + blockedStatus + '</span>';
                                    html += '</div>';
                                    
                                    html += '<div class="test-result-details">';
                                    html += '<p><strong>Response Time:</strong> ' + result.response_time + '</p>';
                                    html += '<p><strong>Status:</strong> ' + result.status + '</p>';
                                    
                                    if (result.status_code) {
                                        html += '<p><strong>HTTP Status Code:</strong> <span class="status-code">' + result.status_code + '</span></p>';
                                    }
                                    
                                    if (result.error) {
                                        html += '<p><strong>Error:</strong> <span class="error-message">' + result.error + '</span></p>';
                                    }
                                    
                                    if (result.body) {
                                        html += '<p><strong>Response Body:</strong></p>';
                                        html += '<pre class="response-body">' + escapeHtml(result.body) + '</pre>';
                                    }
                                    
                                    if (result.headers && Object.keys(result.headers).length > 0) {
                                        html += '<p><strong>Response Headers:</strong></p>';
                                        html += '<pre class="response-headers">';
                                        Object.keys(result.headers).forEach(function(key) {
                                            html += key + ': ' + result.headers[key] + '\n';
                                        });
                                        html += '</pre>';
                                    }
                                    
                                    if (result.sent_data) {
                                        html += '<p><strong>Sent Data:</strong></p>';
                                        html += '<pre class="sent-data">' + escapeHtml(JSON.stringify(result.sent_data, null, 2)) + '</pre>';
                                    }
                                    
                                    if (result.parsed_response) {
                                        html += '<p><strong>Parsed Response:</strong></p>';
                                        html += '<pre class="parsed-response">' + escapeHtml(JSON.stringify(result.parsed_response, null, 2)) + '</pre>';
                                    }
                                    
                                    html += '<p><strong>Details:</strong> ' + result.details + '</p>';
                                    html += '</div>';
                                    html += '</div>';
                                });
                                
                                $('#test-results-content').html(html);
                                $('#host-test-results').show();
                            }
                        
                            function escapeHtml(text) {
                                var map = {
                                    '&': '&amp;',
                                    '<': '&lt;',
                                    '>': '&gt;',
                                    '"': '&quot;',
                                    "'": '&#039;'
                                };
                                return text.replace(/[&<>"']/g, function(m) { return map[m]; });
                            }
                            
                            // Verify button exists on page load
                            setTimeout(function() {
                                var btnBoth = $('#test-both-requests');
                            }, 500);
                        }
                    });
                }
                
                // Start initialization
                initHostBlockingTest();
            })();
            </script>

            <!-- Host Blocking Test CSS -->
            <style>
            .test-result-item {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 8px;
                margin-bottom: 20px;
                overflow: hidden;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            
            .test-result-item.success {
                border-left: 4px solid #28a745;
            }
            
            .test-result-item.error {
                border-left: 4px solid #dc3545;
            }
            
            .test-result-header {
                background: #f8f9fa;
                padding: 15px 20px;
                border-bottom: 1px solid #dee2e6;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .test-result-header h4 {
                margin: 0;
                color: #495057;
                font-size: 16px;
            }
            
            .test-status {
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: bold;
                text-transform: uppercase;
            }
            
            .test-status.allowed {
                background: #d4edda;
                color: #155724;
            }
            
            .test-status.blocked {
                background: #f8d7da;
                color: #721c24;
            }
            
            .test-result-details {
                padding: 20px;
            }
            
            .test-result-details p {
                margin: 8px 0;
                color: #495057;
            }
            
            .test-result-details code {
                background: #f8f9fa;
                padding: 2px 6px;
                border-radius: 3px;
                font-family: 'Courier New', monospace;
                color: #e83e8c;
            }
            
            .status-code {
                font-weight: bold;
                color: #007cba;
            }
            
            .error-message {
                color: #dc3545;
                font-weight: bold;
            }
            
            .response-body, .response-headers, .sent-data, .parsed-response {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 4px;
                padding: 12px;
                margin: 8px 0;
                font-family: 'Courier New', monospace;
                font-size: 12px;
                line-height: 1.4;
                max-height: 200px;
                overflow-y: auto;
                white-space: pre-wrap;
                word-break: break-all;
            }
            
            .response-body {
                color: #495057;
            }
            
            .response-headers {
                color: #6c757d;
            }
            
            .sent-data {
                color: #007cba;
            }
            
            .parsed-response {
                color: #28a745;
                background: #f8fff9;
                border-color: #c3e6cb;
            }
            
            #host-test-results {
                margin-top: 20px;
            }
            
            #host-test-results h3 {
                color: #495057;
                margin-bottom: 15px;
                padding-bottom: 10px;
                border-bottom: 2px solid #dee2e6;
            }
            
            .button:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            </style>

            <!-- Section: OTTO Excluded URLs -->
            <div id="ms-otto-excluded-urls" class="dashboard-card" style="margin-top: 20px;">
                <details open>
                    <summary style="cursor:pointer; list-style:none;">
                        <div style="display:flex; justify-content: space-between; align-items:center;">
                            <div style="flex:1;">
                                <h2 style="margin: 0;">🚫 <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> Excluded URLs</h2>
                                <p style="color: var(--dashboard-text-secondary); margin: 6px 0 0 0;">Manage URLs where <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> should not run. Add URL patterns to exclude specific pages from <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> processing.</p>
                            </div>
                        </div>
                    </summary>

                    <!-- Add New Excluded URL Form -->
                    <div style="margin-top:16px; background: #f8f9fa; border: 1px solid #dee2e6; padding: 20px; border-radius: 8px;">
                        <h3 style="margin-top: 0; color: #495057;">Add New Excluded URL</h3>
                        <div id="otto-excluded-url-form">
                            <div style="margin-bottom: 15px;">
                                <label for="otto-url-pattern" style="display: block; margin-bottom: 5px; font-weight: 600; color: #495057;">URL Pattern *</label>
                                <input type="text" id="otto-url-pattern" class="regular-text" placeholder="e.g., https://example.com/excluded-page" style="width: 100%; max-width: 500px;" />
                                <p class="description">Enter the URL or pattern you want to exclude from <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?>.</p>
                            </div>

                            <div style="margin-bottom: 15px;">
                                <label for="otto-pattern-type" style="display: block; margin-bottom: 5px; font-weight: 600; color: #495057;">Match Type *</label>
                                <select id="otto-pattern-type" class="regular-text" style="width: 100%; max-width: 300px;">
                                    <option value="exact">Exact Match</option>
                                    <option value="contain">Contains</option>
                                    <!-- <option value="start">Starts With</option>
                                    <option value="end">Ends With</option>
                                    <option value="regex">Regular Expression</option> -->
                                </select>
                                <p class="description">How the URL should be matched against the pattern.</p>
                            </div>

                            <div style="margin-bottom: 15px;">
                                <label for="otto-description" style="display: block; margin-bottom: 5px; font-weight: 600; color: #495057;">Description (Optional)</label>
                                <textarea id="otto-description" rows="3" class="large-text" placeholder="Optional description for this exclusion rule" style="width: 100%; max-width: 500px;"></textarea>
                            </div>

                            <div style="display: flex; gap: 10px; align-items: center;">
                                <button type="button" id="otto-add-excluded-url-btn" class="button button-primary">
                                    ➕ Add Excluded URL
                                </button>
                                <span id="otto-add-status" style="display: none; padding: 5px 10px; border-radius: 4px;"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Excluded URLs List -->
                    <div id="otto-excluded-urls-list" style="margin-top:20px;">
                        <h3 style="color: #495057; margin-bottom: 15px;">Excluded URLs</h3>
                        <div id="otto-excluded-urls-table-container" style="overflow-x: auto;">
                            <div style="text-align: center; padding: 40px; color: #6c757d;">
                                <p>Loading excluded URLs...</p>
                            </div>
                        </div>
                    </div>
                </details>
            </div>

            <!-- OTTO Excluded URLs JavaScript -->
            <script>
            (function() {
                if (typeof jQuery === 'undefined') {
                    setTimeout(arguments.callee, 100);
                    return;
                }

                jQuery(document).ready(function($) {
                    var currentPage = 1;
                    var perPage = 10;
                    var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                    var nonce = '<?php echo wp_create_nonce('metasync_otto_excluded_urls'); ?>';

                    // Load excluded URLs on page load
                    loadExcludedURLs();

                    // Add excluded URL button click
                    $('#otto-add-excluded-url-btn').on('click', function(e) {
                        e.preventDefault();
                        addExcludedURL();
                    });

                    // Handle Enter key in URL pattern input
                    $('#otto-url-pattern').on('keypress', function(e) {
                        if (e.which === 13) {
                            e.preventDefault();
                            addExcludedURL();
                        }
                    });

                    function addExcludedURL() {
                        var $button = $('#otto-add-excluded-url-btn');
                        var $status = $('#otto-add-status');
                        var urlPattern = $('#otto-url-pattern').val().trim();
                        var patternType = $('#otto-pattern-type').val();
                        var description = $('#otto-description').val().trim();

                        // Validate inputs
                        if (!urlPattern) {
                            showStatus('error', 'URL pattern is required');
                            return;
                        }

                        // Disable button and show loading
                        $button.prop('disabled', true).text('Adding...');
                        $status.hide();

                        $.ajax({
                            url: ajaxUrl,
                            type: 'POST',
                            data: {
                                action: 'metasync_otto_add_excluded_url',
                                nonce: nonce,
                                url_pattern: urlPattern,
                                pattern_type: patternType,
                                description: description
                            },
                            success: function(response) {
                                if (response.success) {
                                    showStatus('success', response.data.message || 'URL excluded successfully');
                                    // Clear form
                                    $('#otto-url-pattern').val('');
                                    $('#otto-description').val('');
                                    $('#otto-pattern-type').val('exact');
                                    // Reload list
                                    loadExcludedURLs();
                                } else {
                                    showStatus('error', response.data.message || 'Failed to add excluded URL');
                                }
                            },
                            error: function() {
                                showStatus('error', 'An error occurred. Please try again.');
                            },
                            complete: function() {
                                $button.prop('disabled', false).text('➕ Add Excluded URL');
                            }
                        });
                    }

                    function loadExcludedURLs(page) {
                        page = page || currentPage;
                        var $container = $('#otto-excluded-urls-table-container');

                        $container.html('<div style="text-align: center; padding: 40px; color: #6c757d;"><p>Loading...</p></div>');

                        $.ajax({
                            url: ajaxUrl,
                            type: 'POST',
                            data: {
                                action: 'metasync_otto_get_excluded_urls',
                                nonce: nonce,
                                page: page,
                                per_page: perPage
                            },
                            success: function(response) {
                                if (response.success) {
                                    currentPage = page;
                                    renderExcludedURLsTable(response.data.records, response.data.pagination);
                                } else {
                                    $container.html('<div style="text-align: center; padding: 40px; color: #dc3545;"><p>Error loading excluded URLs</p></div>');
                                }
                            },
                            error: function() {
                                $container.html('<div style="text-align: center; padding: 40px; color: #dc3545;"><p>Failed to load excluded URLs</p></div>');
                            }
                        });
                    }

                    function renderExcludedURLsTable(records, pagination) {
                        var $container = $('#otto-excluded-urls-table-container');

                        if (!records || records.length === 0) {
                            $container.html('<div style="text-align: center; padding: 40px; color: #6c757d;"><p>No excluded URLs found. Add one above to get started.</p></div>');
                            return;
                        }

                        var html = '<table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">';
                        html += '<thead><tr>';
                        html += '<th style="width: 30%;">URL Pattern</th>';
                        html += '<th style="width: 12%;">Match Type</th>';
                        html += '<th style="width: 25%;">Description</th>';
                        html += '<th style="width: 13%;">Recheck After</th>';
                        html += '<th style="width: 20%;">Actions</th>';
                        html += '</tr></thead><tbody>';

                        records.forEach(function(record) {
                            html += '<tr>';
                            html += '<td><code>' + escapeHtml(record.url_pattern) + '</code></td>';
                            html += '<td><span class="otto-pattern-type-badge otto-pattern-' + record.pattern_type + '">' + formatPatternType(record.pattern_type) + '</span></td>';
                            html += '<td>' + (record.description ? escapeHtml(record.description) : '<span style="color: #999;">—</span>') + '</td>';
                            html += '<td>' + formatRecheckAfter(record) + '</td>';
                            html += '<td><span class="otto-actions">';
                            html += '<button type="button" class="button button-small otto-recheck-url" data-id="' + record.id + '" style="margin-right: 5px;">🔄 Recheck</button>';
                            html += '<button type="button" class="button button-small otto-delete-url" data-id="' + record.id + '" style="color: #dc3545;">🗑️ Delete</button>';
                            html += '</span></td>';
                            html += '</tr>';
                        });

                        html += '</tbody></table>';

                        // Add pagination
                        if (pagination.total_pages > 1) {
                            html += '<div class="tablenav" style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">';
                            html += '<div class="tablenav-pages">';
                            html += '<span class="displaying-num">' + pagination.total_count + ' items</span>';

                            html += '<span class="pagination-links">';

                            // First page
                            if (pagination.current_page > 1) {
                                html += '<a class="button otto-page-nav" data-page="1" href="#">«</a> ';
                                html += '<a class="button otto-page-nav" data-page="' + (pagination.current_page - 1) + '" href="#">‹</a> ';
                            } else {
                                html += '<span class="button disabled">«</span> ';
                                html += '<span class="button disabled">‹</span> ';
                            }

                            html += '<span class="paging-input">';
                            html += '<span class="tablenav-paging-text">' + pagination.current_page + ' of <span class="total-pages">' + pagination.total_pages + '</span></span>';
                            html += '</span> ';

                            // Next/Last page
                            if (pagination.current_page < pagination.total_pages) {
                                html += '<a class="button otto-page-nav" data-page="' + (pagination.current_page + 1) + '" href="#">›</a> ';
                                html += '<a class="button otto-page-nav" data-page="' + pagination.total_pages + '" href="#">»</a>';
                            } else {
                                html += '<span class="button disabled">›</span> ';
                                html += '<span class="button disabled">»</span>';
                            }

                            html += '</span></div></div>';
                        }

                        $container.html(html);

                        // Attach delete button handlers
                        $('.otto-delete-url').on('click', function(e) {
                            e.preventDefault();
                            var id = $(this).data('id');
                            deleteExcludedURL(id);
                        });

                        // Attach recheck button handlers
                        $('.otto-recheck-url').on('click', function(e) {
                            e.preventDefault();
                            var $btn = $(this);
                            var id = $btn.data('id');
                            recheckExcludedURL(id, $btn);
                        });

                        // Attach pagination handlers
                        $('.otto-page-nav').on('click', function(e) {
                            e.preventDefault();
                            var page = $(this).data('page');
                            loadExcludedURLs(page);
                        });
                    }

                    function recheckExcludedURL(id, $btn) {
                        var originalText = $btn.text();
                        $btn.prop('disabled', true).text('Checking...');

                        $.ajax({
                            url: ajaxUrl,
                            type: 'POST',
                            data: {
                                action: 'metasync_otto_recheck_excluded_url',
                                nonce: nonce,
                                id: id
                            },
                            success: function(response) {
                                $btn.prop('disabled', false).text(originalText);
                                if (response.success) {
                                    if (response.data.available) {
                                        if (confirm('This URL is now available. Do you want to remove it from the excluded list?')) {
                                            deleteExcludedURL(id);
                                        }
                                    } else {
                                        alert('This URL is still returning 404.');
                                    }
                                } else {
                                    alert('Error: ' + (response.data.message || 'Recheck failed'));
                                }
                            },
                            error: function(xhr, status, err) {
                                $btn.prop('disabled', false).text(originalText);
                                alert('An error occurred while rechecking. Please try again.');
                            }
                        });
                    }

                    function deleteExcludedURL(id) {
                        if (!confirm('Are you sure you want to delete this excluded URL?')) {
                            return;
                        }

                        $.ajax({
                            url: ajaxUrl,
                            type: 'POST',
                            data: {
                                action: 'metasync_otto_delete_excluded_url',
                                nonce: nonce,
                                id: id
                            },
                            success: function(response) {
                                if (response.success) {
                                    loadExcludedURLs();
                                } else {
                                    alert('Error: ' + (response.data.message || 'Failed to delete'));
                                }
                            },
                            error: function() {
                                alert('An error occurred. Please try again.');
                            }
                        });
                    }

                    function showStatus(type, message) {
                        var $status = $('#otto-add-status');
                        $status.removeClass('otto-status-success otto-status-error');
                        $status.addClass('otto-status-' + type);
                        $status.text(message).fadeIn();

                        setTimeout(function() {
                            $status.fadeOut();
                        }, 5000);
                    }

                    function formatPatternType(type) {
                        var types = {
                            'exact': 'Exact',
                            'contain': 'Contains',
                            'start': 'Starts With',
                            'end': 'Ends With',
                            'regex': 'Regex'
                        };
                        return types[type] || type;
                    }

                    function formatRecheckAfter(record) {
                        if (!record.auto_excluded || !record.recheck_after) {
                            return '<span style="color: #999;">NA</span>';
                        }
                        var d = new Date(record.recheck_after.replace(' ', 'T'));
                        if (isNaN(d.getTime())) {
                            return escapeHtml(record.recheck_after);
                        }
                        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                        return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
                    }

                    function escapeHtml(text) {
                        if (!text) return '';
                        var map = {
                            '&': '&amp;',
                            '<': '&lt;',
                            '>': '&gt;',
                            '"': '&quot;',
                            "'": '&#039;'
                        };
                        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
                    }
                });
            })();
            </script>

            <!-- OTTO Excluded URLs CSS -->
            <style>
            /* Fix input and select field text colors */
            #otto-excluded-url-form input[type="text"],
            #otto-excluded-url-form input.regular-text,
            #otto-excluded-url-form select,
            #otto-excluded-url-form select.regular-text,
            #otto-excluded-url-form textarea,
            #otto-excluded-url-form textarea.large-text {
                color: #2c3338 !important;
                background-color: #fff !important;
                border: 1px solid #8c8f94 !important;
            }

            #otto-excluded-url-form select option {
                color: #2c3338 !important;
                background-color: #fff !important;
            }

            #otto-excluded-url-form input[type="text"]:focus,
            #otto-excluded-url-form select:focus,
            #otto-excluded-url-form textarea:focus {
                border-color: #2271b1 !important;
                box-shadow: 0 0 0 1px #2271b1 !important;
                outline: 2px solid transparent !important;
            }

            #otto-excluded-url-form input::placeholder,
            #otto-excluded-url-form textarea::placeholder {
                color: #646970 !important;
                opacity: 0.7;
            }

            .otto-pattern-type-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .otto-pattern-exact {
                background: #e3f2fd;
                color: #1976d2;
            }

            .otto-pattern-contain {
                background: #f3e5f5;
                color: #7b1fa2;
            }

            .otto-pattern-start {
                background: #e8f5e9;
                color: #388e3c;
            }

            .otto-pattern-end {
                background: #fff3e0;
                color: #f57c00;
            }

            .otto-pattern-regex {
                background: #fce4ec;
                color: #c2185b;
            }

            .otto-status-success {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }

            .otto-status-error {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }

            #otto-excluded-urls-table-container .wp-list-table {
                border: 1px solid #c3c4c7;
                background: #fff;
            }

            #otto-excluded-urls-table-container .wp-list-table th {
                background: #f0f0f1 !important;
                color: #1d2327 !important;
                font-weight: 600;
                border-bottom: 1px solid #c3c4c7;
            }

            #otto-excluded-urls-table-container .wp-list-table td {
                background: #fff !important;
                color: #2c3338 !important;
                border-bottom: 1px solid #c3c4c7;
            }

            #otto-excluded-urls-table-container .wp-list-table tbody tr {
                background: #fff;
            }

            #otto-excluded-urls-table-container .wp-list-table tbody tr:hover {
                background: #f6f7f7;
            }

            #otto-excluded-urls-table-container .wp-list-table code {
                background: #f0f0f1 !important;
                color: #1d2327 !important;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 12px;
                border: 1px solid #dcdcde;
            }

            #otto-excluded-urls-table-container .otto-delete-url,
            #otto-excluded-urls-table-container .otto-recheck-url {
                padding: 4px 10px;
                font-size: 12px;
            }

            #otto-excluded-urls-table-container .otto-delete-url {
                background: #dc3545 !important;
                color: #fff !important;
                border-color: #dc3545 !important;
            }

            #otto-excluded-urls-table-container .otto-delete-url:hover {
                background: #c82333 !important;
                border-color: #bd2130 !important;
                color: #fff !important;
            }

            .button.disabled {
                opacity: 0.5;
                cursor: not-allowed;
                pointer-events: none;
            }

            /* Pagination styling */
            .tablenav {
                background: #f0f0f1;
                padding: 10px;
                border-radius: 4px;
            }

            .displaying-num {
                color: #646970 !important;
            }

            .pagination-links .button {
                color: #2271b1 !important;
                background: #fff !important;
                border-color: #2271b1 !important;
            }

            .pagination-links .button:hover {
                background: #2271b1 !important;
                color: #fff !important;
            }

            .tablenav-paging-text {
                color: #2c3338 !important;
            }
            </style>
        </div>
        <?php
    }

    /**
     * Sync Log page callback
     */
    public function create_admin_sync_log_page()
    {
        // Classes are now autoloaded
        
        $sync_db = new Metasync_Sync_History_Database();
        
        // Handle AJAX requests for sync log data
        if (wp_doing_ajax()) {
            $this->handle_sync_log_ajax();
            return;
        }
        
        // Get pagination parameters
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 10;
        $offset = ($page - 1) * $per_page;
        
        // Get filters
        $filters = [
            // UI exposes date_range and status only. We compute date_from/date_to based on date_range
            'date_range' => isset($_GET['date_range']) ? sanitize_text_field($_GET['date_range']) : '',
            'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
        ];

        // Map date_range to concrete date_from/date_to for DB queries
        $date_range = $filters['date_range'];
        $wp_now_ts = current_time('timestamp');
        $date_from = '';
        $date_to = '';

        if (!empty($date_range)) {
            // End boundary is now by default
            $date_to = date('Y-m-d H:i:s', $wp_now_ts);

            if ($date_range === 'today') {
                $start_ts = strtotime('today', $wp_now_ts);
                $date_from = date('Y-m-d H:i:s', $start_ts);
            } elseif ($date_range === 'yesterday') {
                $start_ts = strtotime('yesterday', $wp_now_ts);
                $end_ts = strtotime('today', $wp_now_ts) - 1; // end of yesterday
                $date_from = date('Y-m-d H:i:s', $start_ts);
                $date_to = date('Y-m-d H:i:s', $end_ts);
            } elseif ($date_range === 'this_week') {
                $start_of_week = (int) get_option('start_of_week', 1); // 0=Sun, 1=Mon
                $day_of_week = (int) date('w', $wp_now_ts); // 0=Sun..6=Sat
                // Convert start_of_week to PHP's 0..6 where 0=Sunday
                $delta_days = ($day_of_week - $start_of_week + 7) % 7;
                $start_ts = strtotime('-' . $delta_days . ' days', strtotime('today', $wp_now_ts));
                $date_from = date('Y-m-d H:i:s', $start_ts);
            } elseif ($date_range === 'this_month') {
                $start_ts = strtotime(date('Y-m-01 00:00:00', $wp_now_ts));
                $date_from = date('Y-m-d H:i:s', $start_ts);
            } elseif ($date_range === 'all') {
                // no bounds
            }
        }

        if (!empty($date_from)) {
            $filters['date_from'] = $date_from;
        }
        if (!empty($date_to)) {
            $filters['date_to'] = $date_to;
        }
        
        // Remove empty filters
        $filters = array_filter($filters);
        
        // Get sync history records
        $sync_records = $sync_db->getAllRecords($per_page, $offset, $filters);
        $total_records = $sync_db->get_count($filters);
        $total_pages = ceil($total_records / $per_page);
        
        // Get statistics
        $stats = $sync_db->get_statistics();
        
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->render_plugin_header('Sync History'); ?>
        
        <?php $this->render_navigation_menu('sync_log'); ?>
            
            <div class="dashboard-card">
                <div class="sync-log-header">
                    <div class="sync-log-title-section">
                        <h2>📋 Sync History</h2>
                        <p style="color: var(--dashboard-text-secondary); margin-bottom: 0;">Recent content synchronizations from external tools.</p>
                    </div>
                    
                    <!-- Filters - Right aligned -->
                    <div class="sync-log-filters">
                        <form method="get" class="sync-filters-form" onchange="this.submit()">
                            <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page']); ?>">
                            
                            <select name="date_range" class="sync-filter-select">
                                <option value="all" <?php selected($filters['date_range'] ?? 'all', 'all'); ?>> All Time</option>
                                <option value="today" <?php selected($filters['date_range'] ?? '', 'today'); ?>>Today</option>
                                <option value="yesterday" <?php selected($filters['date_range'] ?? '', 'yesterday'); ?>>Yesterday</option>
                                <option value="this_week" <?php selected($filters['date_range'] ?? '', 'this_week'); ?>>This week</option>
                                <option value="this_month" <?php selected($filters['date_range'] ?? '', 'this_month'); ?>>This month</option>
                            </select>
                            
                            <select name="status" class="sync-filter-select">
                                <option value="" <?php selected($filters['status'] ?? '', ''); ?>>Status Filter</option>
                                <option value="published" <?php selected($filters['status'] ?? '', 'published'); ?>>Published</option>
                                <option value="draft" <?php selected($filters['status'] ?? '', 'draft'); ?>>Draft</option>
                            </select>
                        </form>
                    </div>
                </div>
                
                <!-- Sync History List -->
                <div class="sync-log-list">
                    <?php if (empty($sync_records)): ?>
                        <div class="sync-log-empty">
                            <div class="sync-log-empty-icon">📄</div>
                            <h3>No sync records found</h3>
                            <p>Sync records will appear here when content/pages receive new updates.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($sync_records as $record): ?>
                            <div class="sync-log-item">
                                <div class="sync-log-icon">
                                    <div class="sync-icon-circle">
                                        <span class="sync-icon">📄</span>
                                    </div>
                                </div>
                                
                                <div class="sync-log-content">
                                    <div class="sync-log-title"><?php echo esc_html($record->title); ?>
                                    <?php if (!empty($record->url)): ?>
                                        <a href="<?php echo esc_url($record->url); ?>" target="_blank" rel="noopener" title="Open URL" style="margin-left:8px; text-decoration:none;">🔗</a>
                                    <?php endif; ?>
                                    </div>
                                    <div class="sync-log-meta">
                                        <?php echo $this->time_elapsed_string($record->created_at); ?>
                                    </div>
                                </div>
                                
                                <div class="sync-log-status">
                                    <?php if ($record->status === 'published' || $record->status === 'publish'): ?>
                                        <span class="sync-status-badge sync-status-published">
                                            <span class="sync-status-icon">✓</span>
                                            Published
                                        </span>
                                    <?php else: ?>
                                        <span class="sync-status-badge sync-status-draft">
                                            <span class="sync-status-icon">i</span>
                                            Draft
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="sync-log-pagination">
                        <div class="sync-log-pagination-info">
                            Total records: <?php echo $total_records; ?> | Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $per_page, $total_records); ?>
                        </div>
                        
                        <div class="sync-log-pagination-controls">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo esc_attr($_GET['page']); ?>&paged=<?php echo $page - 1; ?><?php echo $this->build_filter_query_string($filters); ?>" class="sync-pagination-btn">‹</a>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <a href="?page=<?php echo esc_attr($_GET['page']); ?>&paged=<?php echo $i; ?><?php echo $this->build_filter_query_string($filters); ?>" 
                                   class="sync-pagination-btn <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo esc_attr($_GET['page']); ?>&paged=<?php echo $page + 1; ?><?php echo $this->build_filter_query_string($filters); ?>" class="sync-pagination-btn">›</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
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
        ?>
        <div class="metasync-compatibility-sections">
            <?php $this->render_page_builders_section(); ?>
            <?php $this->render_seo_plugins_section(); ?>
            <?php $this->render_cache_plugins_section(); ?>
        </div>
        <?php
    }

    /**
     * Render Page Builders section
     */
    private function render_page_builders_section()
    {
        $page_builders = $this->get_page_builders_compatibility();
        ?>
        <div class="metasync-compatibility-section">
            <h3>🏗️ Page Builders</h3>
            <?php foreach ($page_builders as $builder): ?>
                <div class="metasync-plugin-item">
                    <div class="metasync-plugin-info">
                        <?php if ($builder['logo']): ?>
                            <img src="<?php echo esc_url($builder['logo']); ?>" alt="<?php echo esc_attr($builder['name']); ?>" class="metasync-plugin-logo" />
                        <?php endif; ?>
                        <div class="metasync-plugin-details">
                            <div class="metasync-plugin-name"><?php echo esc_html($builder['name']); ?></div>
                            <div class="metasync-plugin-status-labels">
                                <?php if ($builder['is_installed']): ?>
                                    <span class="metasync-status-label metasync-label-installed">Installed</span>
                                    <?php if ($builder['is_active']): ?>
                                        <span class="metasync-status-label metasync-label-active">Active</span>
                                    <?php else: ?>
                                        <span class="metasync-status-label metasync-label-not-active">Not Active</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="metasync-status-label metasync-label-not-installed">Not Installed</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="metasync-plugin-status">
                        <span class="metasync-status-badge metasync-status-<?php echo esc_attr($builder['status']); ?>">
                            <?php echo esc_html($builder['status_text']); ?>
                        </span>
                        <?php if ($builder['version']): ?>
                            <span class="metasync-plugin-version">v<?php echo esc_html($builder['version']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render SEO Plugins section
     */
    private function render_seo_plugins_section()
    {
        $seo_plugins = $this->get_seo_plugins_compatibility();
        ?>
        <div class="metasync-compatibility-section">
            <h3>🔍 SEO Plugins</h3>
            <?php foreach ($seo_plugins as $plugin): ?>
                <div class="metasync-plugin-item">
                    <div class="metasync-plugin-info">
                        <?php if ($plugin['logo']): ?>
                            <img src="<?php echo esc_url($plugin['logo']); ?>" alt="<?php echo esc_attr($plugin['name']); ?>" class="metasync-plugin-logo" />
                        <?php endif; ?>
                        <div class="metasync-plugin-details">
                            <div class="metasync-plugin-name"><?php echo esc_html($plugin['name']); ?></div>
                            <div class="metasync-plugin-status-labels">
                                <?php if ($plugin['is_installed']): ?>
                                    <span class="metasync-status-label metasync-label-installed">Installed</span>
                                    <?php if ($plugin['is_active']): ?>
                                        <span class="metasync-status-label metasync-label-active">Active</span>
                                    <?php else: ?>
                                        <span class="metasync-status-label metasync-label-not-active">Not Active</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="metasync-status-label metasync-label-not-installed">Not Installed</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="metasync-plugin-status">
                        <span class="metasync-status-badge metasync-status-<?php echo esc_attr($plugin['status']); ?>">
                            <?php echo esc_html($plugin['status_text']); ?>
                        </span>
                        <?php if ($plugin['version']): ?>
                            <span class="metasync-plugin-version">v<?php echo esc_html($plugin['version']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render Cache Plugins section
     */
    private function render_cache_plugins_section()
    {
        $cache_plugins = $this->get_cache_plugins_compatibility();
        ?>
        <div class="metasync-compatibility-section">
            <h3>⚡ Cache Plugins</h3>
            <?php foreach ($cache_plugins as $plugin): ?>
                <div class="metasync-plugin-item">
                    <div class="metasync-plugin-info">
                        <?php if ($plugin['logo']): ?>
                            <img src="<?php echo esc_url($plugin['logo']); ?>" alt="<?php echo esc_attr($plugin['name']); ?>" class="metasync-plugin-logo" />
                        <?php endif; ?>
                        <div class="metasync-plugin-details">
                            <div class="metasync-plugin-name"><?php echo esc_html($plugin['name']); ?></div>
                            <div class="metasync-plugin-status-labels">
                                <?php if ($plugin['is_installed']): ?>
                                    <span class="metasync-status-label metasync-label-installed">Installed</span>
                                    <?php if ($plugin['is_active']): ?>
                                        <span class="metasync-status-label metasync-label-active">Active</span>
                                    <?php else: ?>
                                        <span class="metasync-status-label metasync-label-not-active">Not Active</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="metasync-status-label metasync-label-not-installed">Not Installed</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="metasync-plugin-status">
                        <span class="metasync-status-badge metasync-status-<?php echo esc_attr($plugin['status']); ?>">
                            <?php echo esc_html($plugin['status_text']); ?>
                        </span>
                        <?php if ($plugin['version']): ?>
                            <span class="metasync-plugin-version">v<?php echo esc_html($plugin['version']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render Lock Section button for protected tabs
     *
     * @param string $tab The tab identifier (general, whitelabel, advanced)
     */
    private function render_lock_button($tab)
    {
        ?>
        <button type="button"
                class="metasync-lock-btn"
                data-tab="<?php echo esc_attr($tab); ?>"
                style="background: #dc3545; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s ease;"
                onmouseover="this.style.background='#c82333'"
                onmouseout="this.style.background='#dc3545'"
                onfocus="this.style.background='#c82333'"
                onblur="this.style.background='#dc3545'"
                aria-label="Lock this section and require password for access">
            <span style="font-size: 16px;" aria-hidden="true">🔒</span>
            Lock Section
        </button>
        <?php
    }

    /**
     * Get Page Builders compatibility information
     */
    private function get_page_builders_compatibility()
    {
        $builders = [
            'elementor' => [
                'name' => 'Elementor',
                'plugin_files' => [
                    'elementor/elementor.php',
                    'elementor-pro/elementor-pro.php'
                ],
                'supported' => true,
                'version' => '3.31.5'
            ],
            'gutenberg' => [
                'name' => 'WordPress Block Editor',
                'plugin_files' => [
                    'gutenberg/gutenberg.php'
                ],
                'supported' => true,
                'is_core' => true,
                'version' => get_bloginfo('version') // Use actual WordPress version
            ],
            'divi' => [
                'name' => 'Divi Builder',
                'plugin_files' => [
                    'divi-builder/divi-builder.php'
                ],
                'theme_name' => 'Divi', // Also check for Divi theme
                'supported' => true,
                'version' => ''
            ]
        ];

        $result = [];

        foreach ($builders as $key => $builder) {
            // Get detailed plugin status (checks both free and premium versions)
            // Pass is_core flag for WordPress core features like Gutenberg
            $is_core = isset($builder['is_core']) && $builder['is_core'];
            $theme_name = isset($builder['theme_name']) ? $builder['theme_name'] : null;
            $plugin_status = $this->get_plugin_status($builder['plugin_files'], $is_core, $theme_name);

            // Show supported status even when not installed
            if ($builder['supported']) {
                $status = 'supported';
                $status_text = 'Supported';
            } else {
                $status = 'coming-soon';
                $status_text = 'Coming Soon';
            }

            $result[] = [
                'name' => $builder['name'],
                'version' => $builder['version'],
                'status' => $status,
                'status_text' => $status_text,
                'is_installed' => $plugin_status['is_installed'],
                'is_active' => $plugin_status['is_active'],
                'active_version' => $plugin_status['active_version'],
                'logo' => $this->get_plugin_logo($key, 'page_builder')
            ];
        }

        return $result;
    }

    /**
     * Get SEO Plugins compatibility information
     */
    private function get_seo_plugins_compatibility()
    {
        $plugins = [
            'yoast' => [
                'name' => 'Yoast SEO',
                'plugin_files' => [
                    'wordpress-seo/wp-seo.php',
                    'wordpress-seo-premium/wp-seo-premium.php'
                ],
                'supported' => true,
                'version' => '25.9.0'
            ],
            'rankmath' => [
                'name' => 'Rank Math',
                'plugin_files' => [
                    'seo-by-rank-math/rank-math.php',
                    'seo-by-rank-math-pro/rank-math-pro.php'
                ],
                'supported' => true,
                'version' => '1.0.253.0'
            ],
            'aioseo' => [
                'name' => 'All in One SEO',
                'plugin_files' => [
                    'all-in-one-seo-pack/all_in_one_seo_pack.php',
                    'all-in-one-seo-pack-pro/all_in_one_seo_pack.php'
                ],
                'supported' => true,
                'version' => '4.8.7'
            ]
        ];

        $result = [];

        foreach ($plugins as $key => $plugin) {
            // Get detailed plugin status (checks both free and premium versions)
            $plugin_status = $this->get_plugin_status($plugin['plugin_files']);

            // Show supported status even when not installed
            if ($plugin['supported']) {
                $status = 'supported';
                $status_text = 'Supported';
            } else {
                $status = 'coming-soon';
                $status_text = 'Coming Soon';
            }

            $result[] = [
                'name' => $plugin['name'],
                'version' => $plugin['version'],
                'status' => $status,
                'status_text' => $status_text,
                'is_installed' => $plugin_status['is_installed'],
                'is_active' => $plugin_status['is_active'],
                'active_version' => $plugin_status['active_version'],
                'logo' => $this->get_plugin_logo($key, 'seo')
            ];
        }

        return $result;
    }

    /**
     * Get Cache Plugins compatibility information
     */
    private function get_cache_plugins_compatibility()
    {
        $plugins = [
            'litespeed-cache' => [
                'name' => 'LiteSpeed Cache',
                'plugin_files' => [
                    'litespeed-cache/litespeed-cache.php'
                ],
                'supported' => true,
                'version' => '7.5.0.1'
            ]
        ];

        $result = [];

        foreach ($plugins as $key => $plugin) {
            // Get detailed plugin status (checks both free and premium versions)
            $plugin_status = $this->get_plugin_status($plugin['plugin_files']);

            // Show supported status even when not installed
            if ($plugin['supported']) {
                $status = 'supported';
                $status_text = 'Supported';
            } else {
                $status = 'coming-soon';
                $status_text = 'Coming Soon';
            }

            $result[] = [
                'name' => $plugin['name'],
                'version' => $plugin['version'],
                'status' => $status,
                'status_text' => $status_text,
                'is_installed' => $plugin_status['is_installed'],
                'is_active' => $plugin_status['is_active'],
                'active_version' => $plugin_status['active_version'],
                'logo' => $this->get_plugin_logo($key, 'cache')
            ];
        }

        return $result;
    }

    /**
     * Check if a plugin is installed and active
     * @deprecated Use get_plugin_status() instead
     */
    private function is_plugin_installed($plugin_file)
    {
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        return is_plugin_active($plugin_file);
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
        // If it's a core WordPress feature (like Gutenberg block editor),
        // it's always installed and active
        if ($is_core) {
            return [
                'is_installed' => true,
                'is_active' => true,
                'active_version' => 'core'
            ];
        }

        // Special handling for themes (like Divi)
        if ($theme_name) {
            $current_theme = wp_get_theme();
            $is_theme_active = (strtolower($current_theme->get('Name')) === strtolower($theme_name) ||
                               strtolower($current_theme->get_stylesheet()) === strtolower($theme_name));

            // Check if theme exists (installed)
            $theme_exists = wp_get_theme($theme_name)->exists();

            if ($theme_exists) {
                return [
                    'is_installed' => true,
                    'is_active' => $is_theme_active,
                    'active_version' => $is_theme_active ? 'theme' : null
                ];
            }
        }

        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        if (!function_exists('get_plugins')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        // Ensure $plugin_files is an array
        if (!is_array($plugin_files)) {
            $plugin_files = [$plugin_files];
        }

        $is_installed = false;
        $is_active = false;
        $active_version = null;

        foreach ($plugin_files as $plugin_file) {
            // Check if plugin file exists (installed)
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            if (file_exists($plugin_path)) {
                $is_installed = true;

                // Check if this version is active
                if (is_plugin_active($plugin_file)) {
                    $is_active = true;
                    // Determine which version (free or premium)
                    $active_version = strpos($plugin_file, 'pro') !== false ||
                                     strpos($plugin_file, 'premium') !== false ?
                                     'premium' : 'free';
                    break; // If active, no need to check other versions
                }
            }
        }

        return [
            'is_installed' => $is_installed,
            'is_active' => $is_active,
            'active_version' => $active_version
        ];
    }


    /**
     * Get plugin logo URL (optimized for performance)
     */
    private function get_plugin_logo($plugin_key, $type)
    {
        // Use reliable WordPress.org URLs that are known to work
        $logos = [
            'page_builder' => [
                'elementor' => 'https://ps.w.org/elementor/assets/icon-256x256.gif',
                'gutenberg' => 'https://i0.wp.com/wordpress.org/files/2023/02/wmark.png',
                'divi' => 'https://www.elegantthemes.com/images/logo.svg',
                // 'beaver-builder' => 'https://ps.w.org/beaver-builder-lite-version/assets/icon-128x128.png',
                // 'wpbakery' => 'https://ps.w.org/js_composer/assets/icon-128x128.png',
                // 'oxygen' => 'https://ps.w.org/oxygen/assets/icon-128x128.png'
            ],
            'seo' => [
                'yoast' => 'https://ps.w.org/wordpress-seo/assets/icon-128x128.gif',
                'rankmath' => 'https://ps.w.org/seo-by-rank-math/assets/icon-128x128.png',
                'aioseo' => 'https://ps.w.org/all-in-one-seo-pack/assets/icon-128x128.png',
                // 'seopress' => 'https://ps.w.org/wp-seopress/assets/icon-128x128.png',
                // 'squirrly' => 'https://ps.w.org/squirrly-seo/assets/icon-128x128.png'
            ],
            'cache' => [
                // 'wp-rocket' => 'https://ps.w.org/wp-rocket/assets/icon-128x128.png',
                // 'w3-total-cache' => 'https://ps.w.org/w3-total-cache/assets/icon-128x128.png',
                // 'wp-super-cache' => 'https://ps.w.org/wp-super-cache/assets/icon-128x128.png',
                'litespeed-cache' => 'https://ps.w.org/litespeed-cache/assets/icon-128x128.png',
                // 'wp-fastest-cache' => 'https://ps.w.org/wp-fastest-cache/assets/icon-128x128.png',
                // 'autoptimize' => 'https://ps.w.org/autoptimize/assets/icon-128x128.png'
            ]
        ];

        // Return logo URL directly without validation for performance
        return isset($logos[$type][$plugin_key]) ? $logos[$type][$plugin_key] : '';
    }


    public function creat_error_Logs_List()
    {
        // printf('<h1> Error Logs </h1>');
        // $error_log = new Metasync_Error_Logs_Table($this->data_error_log_list);
        // $error_log->create_admin_error_log_list_interface();
    }

    /**
     * Site error logs page callback
     */
    public function create_admin_heartbeat_error_logs_page()
    {
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->render_plugin_header('HeartBeat Error Logs'); ?>
        
        <?php $this->render_navigation_menu('heartbeat-error-logs'); ?>
            
            <div class="dashboard-card">
                <h2>💓 HeartBeat Error Logs</h2>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Monitor WordPress heartbeat errors to identify connectivity issues and system problems.</p>
                <?php
        $heartbeat_errors = new Metasync_HeartBeat_Error_Monitor($this->db_heartbeat_errors);
        $heartbeat_errors->create_admin_heartbeat_errors_interface();
                ?>
            </div>
        </div>
        <?php
    }


    /**
     * Handle session management early for whitelabel functionality
     */
    private function handle_session_management_early()
    {
        // Only handle sessions for admin pages
        if (!is_admin()) {
            return;
        }

        // Check if we're on the settings page
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
        $current_page = isset($_GET['page']) ? $_GET['page'] : '';

        // Get whitelabel settings to determine protected tabs
        $whitelabel_settings = Metasync::get_whitelabel_settings();
        $user_password = $whitelabel_settings['settings_password'] ?? '';
        $hide_settings_enabled = !empty($whitelabel_settings['hide_settings']);

        // Determine which tabs need password protection
        $protected_tabs = [];
        if (!empty($user_password)) {
            $protected_tabs[] = 'whitelabel';
        }
        if ($hide_settings_enabled && !empty($user_password)) {
            $protected_tabs = ['general', 'whitelabel', 'advanced'];
        }

        // Handle whitelabel authentication if we're on our settings page and on a protected tab
        // No need to start sessions - Auth Manager uses transients and user meta
        if (strpos($current_page, self::$page_slug) === 0 && in_array($active_tab, $protected_tabs)) {
            // Don't process authentication during REST API requests, AJAX requests, or cron
            if ((defined('REST_REQUEST') && REST_REQUEST) ||
                (defined('DOING_AJAX') && DOING_AJAX) ||
                (defined('DOING_CRON') && DOING_CRON)) {
                return;
            }

            // Handle authentication logic using Auth Manager (no sessions required)
            $this->handle_whitelabel_session_logic();
        }
    }

    /**
     * Safely start a session with error handling
     *
     * @deprecated 2.5.12 Use Metasync_Auth_Manager instead of sessions for authentication
     * @see Metasync_Auth_Manager
     * @return bool
     */
    private function safe_session_start() {
        // This method is deprecated and no longer used
        // Authentication now uses Metasync_Auth_Manager with WordPress transients and user meta
        _deprecated_function(__METHOD__, '2.5.12', 'Metasync_Auth_Manager');
        return Metasync_Session_Helper::safe_start();
    }

    /**
     * Handle whitelabel authentication logic (login/logout/validation)
     * Uses Metasync_Auth_Manager instead of sessions for better compatibility
     */
    private function handle_whitelabel_session_logic()
    {
        // Initialize Auth Manager for whitelabel context
        $auth = new Metasync_Auth_Manager('whitelabel', 1800); // 30 minutes timeout

        // Password protection for whitelabel section
        $admin_password = 'abracadabra@2020'; // Admin/hardcoded password

        // Get user-set whitelabel password
        $whitelabel_settings = Metasync::get_whitelabel_settings();
        $user_password = $whitelabel_settings['settings_password'] ?? '';

        // Build array of valid passwords (admin password + user password if set)
        $valid_passwords = array($admin_password);
        if (!empty($user_password)) {
            $valid_passwords[] = $user_password;
        }

        // Handle logout request
        if (isset($_POST['whitelabel_logout'])) {
            // Verify nonce for security
            if (wp_verify_nonce($_POST['whitelabel_logout_nonce'] ?? '', 'whitelabel_logout_nonce')) {
                $auth->revoke_access();

                // Redirect to the current tab or whitelabel tab
                $redirect_tab = $_GET['tab'] ?? 'whitelabel';
                $redirect_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=' . $redirect_tab);
                wp_redirect($redirect_url);
                exit;
            }
        }

        // Check if password was submitted
        if (isset($_POST['whitelabel_password_submit']) && isset($_POST['whitelabel_password'])) {
            // Verify nonce for security
            if (wp_verify_nonce($_POST['whitelabel_nonce'], 'whitelabel_password_nonce')) {
                $submitted_password = sanitize_text_field($_POST['whitelabel_password']);

                // Use Auth Manager to verify password and grant access
                // This automatically handles transient storage and activity tracking
                $auth->verify_and_grant($submitted_password, $valid_passwords, false);
            }
        }
    }

    /**
     * Handle whitelabel password early before WordPress filters it out
     */
    private function handle_whitelabel_password_early()
    {
        // Check if this is a settings form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['option_page']) && $_POST['option_page'] === $this::option_group) {
            
            // Check if whitelabel password was submitted in POST data
            if (isset($_POST[$this::option_key]['whitelabel']['settings_password'])) {
                $submitted_password = sanitize_text_field($_POST[$this::option_key]['whitelabel']['settings_password']);
                
                // Get current options
                $current_options = Metasync::get_option();
                
                // Ensure whitelabel array exists
                if (!isset($current_options['whitelabel'])) {
                    $current_options['whitelabel'] = [];
                }
                
                // Save the password directly
                $current_options['whitelabel']['settings_password'] = $submitted_password;
                $current_options['whitelabel']['updated_at'] = time();
                
                // Update the option immediately
                update_option($this::option_key, $current_options);
            }
        }
    }

    /**
     * Get accordion sections configuration for General Settings
     *
     * @return array Accordion sections with field IDs, icons, and descriptions
     */
    private function get_accordion_sections_config() {
        return array(
            'connection' => array(
                'title' => 'Connection & Authentication',
                'description' => 'Manage your API connection and authentication settings',
                'icon' => '🔐',
                'priority' => 10,
                'default_open' => true, // Only first section open by default
                'fields' => array(
                    'searchatlas_api_key',
                    'apikey'
                )
            ),
            'otto_ssr' => array(
                'title' => Metasync::get_whitelabel_otto_name() . ' Server-Side Rendering',
                'description' => 'Configure ' . Metasync::get_whitelabel_otto_name() . ' rendering and display options',
                'icon' => '🚀',
                'priority' => 20,
                'default_open' => false, 
                'fields' => array(
                    'otto_pixel_uuid',
                    'otto_disable_on_loggedin',
                    'otto_disable_preview_button',
                    'otto_wp_rocket_compat',
                )
            ),
            'bot_detection' => array(
                'title' => 'Bot Detection & Filtering',
                'description' => 'Manage bot traffic and reduce unnecessary API calls',
                'icon' => '🤖',
                'priority' => 25,
                'default_open' => false, 
                'fields' => array(
                    'otto_disable_for_bots',
                    'otto_bot_whitelist',
                    'otto_bot_blacklist',
                    'otto_bot_statistics_link'
                )
            ),
            'editor_settings' => array(
                'title' => 'Post/Page Editor Settings',
                'description' => 'Customize meta boxes and editor functionality',
                'icon' => '✏️',
                'priority' => 40,
                'default_open' => false,
                'fields' => array(
                    'disable_common_robots_metabox',
                    'disable_advance_robots_metabox',
                    'disable_redirection_metabox',
                    'disable_canonical_metabox',
                    'disable_social_opengraph_metabox',
                    'disable_schema_markup_metabox',
                    'open_external_links'
                )
            ),
            'user_management' => array(
                'title' => 'User Management for Content',
                'description' => 'Configure which users are allowed to be authors of content synced.',
                'icon' => '👥',
                'priority' => 50,
                'default_open' => false,
                'fields' => array(
                    'content_genius_sync_roles'
                )
            ),
            'advanced' => array(
                'title' => 'Plugin Settings',
                'description' => 'System configuration and maintenance options',
                'icon' => '⚙️',
                'priority' => 60,
                'default_open' => false,
                'fields' => array(
                    'permalink_structure',
                    'hide_dashboard_framework',
                    'show_admin_bar_status',
                    'enable_auto_updates',
                    'import_external_data'
                )
            )
        );
    }

    /**
     * Get accordion sections configuration for Advanced Settings Tab
     *
     * @return array Accordion sections configuration
     */
    private function get_advanced_accordion_config() {
        $config = array();
        
        // Only show "User Roles with Plugin Access" to administrators
        $user = wp_get_current_user();
        if (in_array('administrator', (array) $user->roles)) {
            $config['plugin_access'] = array(
                'title' => 'User Roles with Plugin Access',
                'description' => 'Control which user roles can see and access this plugin',
                'icon' => '🔐',
                'priority' => 5,
                'default_open' => false,
                'render_callback' => array($this, 'render_plugin_access_roles_section')
            );
        }

        $config['debug_mode'] = array(
            'title' => 'Debug Mode',
            'description' => 'Manage debug mode with automatic disable and safety limits',
            'icon' => '🐛',
            'priority' => 8,
            'default_open' => false,
            'render_callback' => array($this, 'render_debug_mode_section')
        );

        $config['error_logs'] = array(
            'title' => 'Error Logs',
            'description' => 'View and manage error logs to troubleshoot issues',
            'icon' => '⚠️',
            'priority' => 10,
            'default_open' => true, // First section open by default
            'render_callback' => array($this, 'render_error_log_content')
        );
        
        $config['execution_settings'] = array(
            'title' => 'Execution Settings',
            'description' => 'Configure resource limits and execution parameters',
            'icon' => '⚡',
            'priority' => 15,
            'default_open' => false,
            'render_callback' => array($this, 'render_execution_settings_section')
        );
        
        $config['otto_cache'] = array(
            'title' => 'Cache Management',
            'description' => 'Manage ' . Metasync::get_whitelabel_otto_name() . ' cache and clear all cache plugins',
            'icon' => '🗄️',
            'priority' => 20,
            'default_open' => false,
            'render_callback' => array($this, 'render_otto_cache_management')
        );
        
        $config['reset_settings'] = array(
            'title' => 'Reset Plugin Settings',
            'description' => 'Reset all plugin settings to default values',
            'icon' => '🔄',
            'priority' => 30,
            'default_open' => false,
            'render_callback' => array($this, 'render_reset_settings_section')
        );
        
        return $config;
    }

    /**
     * Render accordion sections for Advanced Settings Tab
     */
    private function render_advanced_accordion() {
        $sections_config = $this->get_advanced_accordion_config();

        // Sort sections by priority
        uasort($sections_config, function($a, $b) {
            return $a['priority'] - $b['priority'];
        });

        echo '<div class="metasync-settings-accordion">';

        foreach ($sections_config as $section_key => $section_data) {
            $section_id = 'metasync-advanced-section-' . $section_key;
            $is_open = $section_data['default_open'];
            $aria_expanded = $is_open ? 'true' : 'false';
            $content_state = $is_open ? 'open' : 'closed';

            echo '<div class="metasync-accordion-section" data-section="' . esc_attr($section_key) . '">';

            // Section header
            echo '<div class="metasync-accordion-header" role="button" tabindex="0" aria-expanded="' . $aria_expanded . '" aria-controls="' . $section_id . '">';
            echo '<div class="metasync-accordion-title">';
            echo '<span class="metasync-accordion-icon">' . esc_html($section_data['icon']) . '</span>';
            echo '<div class="metasync-accordion-text">';
            echo '<h3>' . esc_html($section_data['title']) . '</h3>';
            echo '<p class="metasync-accordion-description">' . esc_html($section_data['description']) . '</p>';
            echo '</div>';
            echo '</div>';
            echo '<button type="button" class="metasync-accordion-toggle" aria-label="Toggle section">';
            echo '<span class="toggle-icon">▼</span>';
            echo '</button>';
            echo '</div>';

            // Section content
            echo '<div class="metasync-accordion-content" id="' . $section_id . '" data-state="' . $content_state . '">';

            // Call the render callback for this section
            if (isset($section_data['render_callback']) && is_callable($section_data['render_callback'])) {
                call_user_func($section_data['render_callback']);
            }

            echo '</div>'; // Close accordion-content
            echo '</div>'; // Close accordion-section
        }

        echo '</div>'; // Close accordion container
    }

    /**
     * Render reset settings section for Advanced tab accordion
     */
    private function render_reset_settings_section() {
        ?>
        <div style="background: var(--dashboard-card-bg); padding: 20px; border-radius: 8px;">
            <div style="background: rgba(255, 243, 205, 0.1); border: 1px solid rgba(255, 234, 167, 0.3); border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                <h4 style="color: var(--dashboard-warning, #f59e0b); margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px;">
                    <span>⚠️</span>
                    <span>Important Warning</span>
                </h4>
                <p style="color: var(--dashboard-text-secondary); margin: 0 0 12px 0;">This action will permanently delete:</p>
                <ul style="color: var(--dashboard-text-secondary); margin: 0 0 12px 20px; line-height: 1.8;">
                    <li>All API keys and authentication tokens</li>
                    <li>White label branding settings</li>
                    <li>Plugin configuration and preferences</li>
                    <li>Instant indexing settings</li>
                    <li>All cached data and crawl information</li>
                </ul>
                <p style="color: var(--dashboard-warning, #f59e0b); margin: 0; font-weight: 600;">You will need to reconfigure the plugin completely after this reset.</p>
            </div>
            <form method="post" action="" onsubmit="return confirmClearSettings(event)">
                <?php wp_nonce_field('metasync_clear_all_settings_nonce', 'clear_all_settings_nonce'); ?>
                <input type="hidden" name="clear_all_settings" value="yes" />
                <button type="submit" class="metasync-btn-danger" style="background: var(--dashboard-error, #ef4444); color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(239, 68, 68, 0.2);" onmouseover="this.style.background='#dc2626'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(239, 68, 68, 0.3)';" onmouseout="this.style.background='var(--dashboard-error, #ef4444)'; this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(239, 68, 68, 0.2)';">
                    🗑️ Clear All Settings
                </button>
            </form>
        </div>
        <script>
        function confirmClearSettings(event) {
            event.preventDefault();

            // First confirmation
            var firstConfirm = confirm("⚠️ WARNING: This will permanently delete ALL plugin settings!\n\nThis action cannot be undone. Are you sure you want to continue?");
            if (!firstConfirm) {
                return false;
            }

            // Second confirmation with more specific warning
            var secondConfirm = confirm("🚨 FINAL WARNING 🚨\n\nThis will delete:\n• All API keys and authentication tokens\n• White label branding settings\n• Plugin configuration and preferences\n• Instant indexing settings\n• All cached data\n\nYou will need to reconfigure the entire plugin from scratch.\n\nType 'DELETE' in the next prompt to confirm.");
            if (!secondConfirm) {
                return false;
            }

            // Third confirmation requiring typing "DELETE"
            var typeConfirm = prompt("Type 'DELETE' (in capital letters) to confirm you want to permanently clear all settings:");
            if (typeConfirm !== 'DELETE') {
                alert("Settings reset cancelled. Type 'DELETE' exactly to confirm.");
                return false;
            }

            // If all confirmations passed, allow form submission
            event.target.submit();
            return false;
        }
        </script>
        <?php
    }

    /**
     * Render Google Index API section for Indexation Control page
     */
    public function render_google_index_section() {
        // Load Google Index functionality if not already loaded
        if (!function_exists('google_index_direct')) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'google-index/google-index-init.php';
        }

        // Get current service account info (safe - doesn't expose private key)
        $google_index = google_index_direct();
        $service_info = $google_index->get_service_account_info();
        $is_configured = !isset($service_info['error']);

        // Include the settings view
        include plugin_dir_path(dirname(__FILE__)) . 'views/metasync-google-index-api-settings.php';
    }

    /**
     * Render Bing Index (IndexNow) section
     *
     * @since 2.6.0
     * @return void
     */
    public function render_bing_index_section() {
        require_once plugin_dir_path(dirname(__FILE__)) . 'bing-index/class-metasync-bing-instant-index.php';
        $bing_index = new Metasync_Bing_Instant_Index();
        $api_key = $bing_index->get_setting('api_key');
        $endpoint = $bing_index->get_setting('endpoint', 'indexnow');
        $post_types_settings = $bing_index->get_setting('post_types', []);
        $is_configured = !empty($api_key);

        // Get all public post types
        $post_types = get_post_types(['public' => true], 'objects');

        ?>
        <div style="padding: 20px;">
            <!-- About IndexNow (Collapsible) -->
            <details style="margin-bottom: 20px;">
                <summary style="cursor: pointer; font-weight: 600; color: var(--dashboard-text-primary);">
                    ℹ️ About IndexNow Protocol
                </summary>
                <div style="margin-top: 10px; padding: 15px; background: rgba(255, 255, 255, 0.03); border-radius: 4px;">
                    <p style="color: var(--dashboard-text-secondary); margin: 0 0 10px 0;">
                        IndexNow is a simple protocol that allows websites to instantly notify search engines about URL changes.
                        It's supported by Bing, Yandex, Naver, Seznam, and other search engines.
                    </p>
                    <ul style="color: var(--dashboard-text-secondary); margin: 0 0 10px 20px;">
                        <li>✓ Instant notification to multiple search engines</li>
                        <li>✓ Simple API key authentication (no OAuth required)</li>
                        <li>✓ Supports batch URL submissions (up to 10,000 URLs)</li>
                        <li>✓ Free to use with no quotas</li>
                        <li>✓ Fire-and-forget protocol (no status checking needed)</li>
                    </ul>
                    <p style="color: var(--dashboard-text-secondary); margin: 0;">
                        <strong>Resources:</strong>
                        <a href="https://www.indexnow.org/documentation" target="_blank" style="color: var(--dashboard-accent);">IndexNow Documentation</a> |
                        <a href="https://www.bing.com/webmasters" target="_blank" style="color: var(--dashboard-accent);">Bing Webmaster Tools</a>
                    </p>
                </div>
            </details>

            <!-- Configuration Status -->
            <div style="margin-bottom: 20px;">
                <?php if ($is_configured): ?>
                    <div style="padding: 12px; background: rgba(76, 175, 80, 0.1); border-left: 4px solid #4caf50; border-radius: 4px;">
                        <p style="margin: 0; color: var(--dashboard-text-primary);">
                            <strong style="color: #4caf50;">✅ IndexNow API Configured</strong><br>
                            <span style="color: var(--dashboard-text-secondary); font-size: 0.95em;">
                                API Key: <code style="padding: 2px 6px; background: rgba(0,0,0,0.1); border-radius: 3px;"><?php echo esc_html(substr($api_key, 0, 8) . '...' . substr($api_key, -8)); ?></code>
                            </span>
                        </p>
                    </div>
                <?php else: ?>
                    <div style="padding: 12px; background: rgba(255, 152, 0, 0.1); border-left: 4px solid #ff9800; border-radius: 4px;">
                        <p style="margin: 0; color: var(--dashboard-text-primary);">
                            <strong style="color: #ff9800;">⚠️ Configuration Required</strong><br>
                            <span style="color: var(--dashboard-text-secondary); font-size: 0.95em;">
                                Configure your IndexNow API key below to enable instant indexing with Bing and other search engines.
                            </span>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Settings Fields -->
            <table class="form-table" style="margin-top: 0;">
                <tr>
                    <th scope="row" style="width: 200px; padding-top: 15px;">
                        <label for="metasync_bing_api_key_inline">IndexNow API Key <span style="color: #d63638;">*</span></label>
                    </th>
                    <td style="padding-top: 15px;">
                        <input type="text"
                               name="metasync_bing_api_key_inline"
                               id="metasync_bing_api_key_inline"
                               class="large-text"
                               value="<?php echo esc_attr($api_key); ?>"
                               placeholder="Enter your IndexNow API key (32+ character hexadecimal string)" />
                        <br>
                        <button type="button"
                                id="generate-bing-api-key-inline"
                                class="button button-secondary"
                                style="margin-top: 8px;">
                            🔑 Generate Random API Key
                        </button>
                        <p class="description" style="margin-top: 8px;">
                            Your IndexNow API key is required to submit URLs for instant indexing. You can generate a random key or use your own (32+ character hexadecimal string).
                            After saving, a verification file will be automatically created at your site root.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row" style="padding-top: 15px;">
                        <label>API Endpoint</label>
                    </th>
                    <td style="padding-top: 15px;">
                        <fieldset>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="radio"
                                       name="metasync_bing_endpoint_inline"
                                       value="indexnow"
                                       <?php checked($endpoint, 'indexnow'); ?>>
                                <strong>IndexNow.org</strong> (Recommended)
                                <span style="color: var(--dashboard-text-secondary); font-size: 0.9em; display: block; margin-left: 24px;">
                                    Notifies Bing, Yandex, Naver, Seznam, and other participating search engines
                                </span>
                            </label>
                            <label style="display: block;">
                                <input type="radio"
                                       name="metasync_bing_endpoint_inline"
                                       value="bing"
                                       <?php checked($endpoint, 'bing'); ?>>
                                <strong>Bing.com</strong> (Bing-specific)
                                <span style="color: var(--dashboard-text-secondary); font-size: 0.9em; display: block; margin-left: 24px;">
                                    Direct submission to Bing only
                                </span>
                            </label>
                        </fieldset>
                        <p class="description">
                            Select which endpoint to use for submitting URLs. The IndexNow.org endpoint is recommended as it notifies multiple search engines at once.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row" style="padding-top: 15px;">
                        <label>Auto-Submit Post Types</label>
                    </th>
                    <td style="padding-top: 15px;">
                        <fieldset>
                            <?php foreach ($post_types as $post_type): ?>
                                <label style="display: inline-block; margin-right: 20px; margin-bottom: 8px;">
                                    <input type="checkbox"
                                           name="metasync_bing_post_types_inline[<?php echo esc_attr($post_type->name); ?>]"
                                           value="<?php echo esc_attr($post_type->name); ?>"
                                           <?php checked(in_array($post_type->name, $post_types_settings, true)); ?>>
                                    <?php echo esc_html($post_type->label); ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description">
                            Selected post types will be automatically submitted to IndexNow when published or updated.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row" style="padding-top: 15px;">
                        <label>Plugin Source Control</label>
                    </th>
                    <td style="padding-top: 15px;">
                        <fieldset>
                            <label style="display: flex; align-items: flex-start; gap: 8px;">
                                <input type="checkbox"
                                       name="metasync_bing_disable_other_plugins_inline"
                                       value="1"
                                       <?php checked($bing_index->get_setting('disable_other_plugins', true), true); ?>
                                       style="margin-top: 2px;">
                                <span>
                                    <strong>Disable other IndexNow plugins</strong>
                                    <span style="display: block; color: var(--dashboard-text-secondary); font-size: 0.9em; margin-top: 4px; font-weight: normal;">
                                        When enabled, Our plugin will be the exclusive source for IndexNow submissions in Bing Webmaster Tools.
                                        This disables IndexNow features in Yoast SEO, Rank Math, and other competing plugins to prevent duplicate submissions.
                                    </span>
                                </span>
                            </label>
                        </fieldset>
                    </td>
                </tr>
            </table>

        </div>

        <script>
        jQuery(document).ready(function($) {
            // Generate random API key
            $('#generate-bing-api-key-inline').on('click', function() {
                const array = new Uint8Array(16);
                crypto.getRandomValues(array);
                const hexString = Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('');
                $('#metasync_bing_api_key_inline').val(hexString);

                // Auto-enable toggle when API key is generated
                $('#enable_binginstantindex').prop('checked', true);

                // Trigger change event for unsaved changes detection
                $('#metasync_bing_api_key_inline').trigger('change');
            });

            // Auto-enable toggle when API key is entered manually
            $('#metasync_bing_api_key_inline').on('input', function() {
                const apiKey = $(this).val().trim();
                if (apiKey.length >= 8) {
                    $('#enable_binginstantindex').prop('checked', true);
                }
            });

            // Show/hide API configuration based on enable toggle
            function toggleBingConfig() {
                const isEnabled = $('#enable_binginstantindex').is(':checked');
                const $apiConfig = $('#enable_binginstantindex').closest('tr').nextAll('tr').first();
                if (isEnabled) {
                    $apiConfig.show();
                } else {
                    $apiConfig.hide();
                }
            }

            // Initial state
            toggleBingConfig();

            // Toggle on change
            $('#enable_binginstantindex').on('change', toggleBingConfig);
        });
        </script>

        <style>
        /* Styling for Bing Index API section */
        .form-table th {
            color: var(--dashboard-text-primary);
        }

        .form-table td {
            color: var(--dashboard-text-secondary);
        }

        .form-table code {
            padding: 2px 6px;
            background: rgba(0, 0, 0, 0.1);
            border-radius: 3px;
            font-size: 0.9em;
        }

        details summary {
            transition: color 0.2s;
        }

        details summary:hover {
            color: var(--dashboard-accent);
        }

        details[open] summary {
            margin-bottom: 10px;
        }
        </style>
        <?php
    }

    /**
     * Render Plugin Access Roles section for Advanced tab accordion
     */
    private function render_plugin_access_roles_section() {
        $general_options = Metasync::get_option('general');
        
        # Check if the setting has ever been configured
        $setting_exists = isset($general_options['plugin_access_roles']);
        $selected_roles = $general_options['plugin_access_roles'] ?? null;
        
        # If it's a string (single role from old version), convert to array
        if (is_string($selected_roles)) {
            $selected_roles = array($selected_roles);
        } elseif (!is_array($selected_roles)) {
            $selected_roles = null; # Setting not configured yet
        }
        
        # Get all WordPress user roles safely
        if (!function_exists('wp_roles')) {
            require_once(ABSPATH . 'wp-admin/includes/user.php');
        }
        global $wp_roles;
        
        # Safety check for $wp_roles
        if (!isset($wp_roles) || !is_object($wp_roles)) {
            $wp_roles = new WP_Roles();
        }
        
        $all_roles = $wp_roles->roles;
        
        # Determine if "All Roles" should be checked:
        # - Only check if "all" is explicitly in the array
        # - If setting never configured (null) or empty: don't check (admin-only is default)
        $all_roles_checked = is_array($selected_roles) && in_array('all', $selected_roles);
        ?>
        <div style="background: var(--dashboard-card-bg); padding: 20px; border-radius: 8px;">
            <p style="color: var(--dashboard-text-secondary); margin: 0 0 20px 0;">
                Select which user roles can see and access this plugin's menu, settings, and options in the WordPress admin area.
            </p>
            
            <form method="post" action="<?php echo admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced'); ?>" id="plugin-access-roles-form">
                <?php wp_nonce_field('metasync_plugin_access_roles_nonce', 'plugin_access_roles_nonce'); ?>
                <input type="hidden" name="save_plugin_access_roles" value="yes" />
                
                <div class="metasync-role-selector-container" style="background: var(--dashboard-card-bg-alt, rgba(255,255,255,0.05)); border: 1px solid var(--dashboard-border); border-radius: 8px; padding: 16px; max-height: 300px; overflow-y: auto;">
                    
                    <!-- All Roles Option -->
                    <label class="metasync-role-option-all" style="display: flex; align-items: center; gap: 10px; padding: 12px; cursor: pointer; border-radius: 6px; transition: background 0.2s ease;">
                        <input type="checkbox" 
                               id="plugin-access-all-roles"
                               name="plugin_access_roles[]" 
                               value="all" 
                               style="width: 18px; height: 18px; cursor: pointer;"
                               <?php checked($all_roles_checked, true); ?> />
                        <strong style="color: var(--dashboard-text-primary);">All Roles</strong>
                    </label>
                    
                    <!-- Divider -->
                    <hr style="border: none; border-top: 1px solid var(--dashboard-border); margin: 12px 0;">
                    
                    <?php
                    # Display each role as a checkbox (excluding administrator - they always have access)
                    if (!empty($all_roles) && is_array($all_roles)) {
                        foreach ($all_roles as $role_key => $role_details) {
                            # Skip administrator role - admins always have access
                            if ($role_key === 'administrator') {
                                continue;
                            }
                            
                            # Safety check for role data
                            if (!isset($role_details['name'])) {
                                continue;
                            }
                            
                            $is_checked = is_array($selected_roles) && in_array($role_key, $selected_roles);
                            $role_name = translate_user_role($role_details['name']);
                            ?>
                            <label class="metasync-role-option" style="display: flex; align-items: center; gap: 10px; padding: 10px 12px; cursor: pointer; border-radius: 6px; transition: background 0.2s ease;">
                                <input type="checkbox" 
                                       class="plugin-access-individual-role"
                                       name="plugin_access_roles[]" 
                                       value="<?php echo esc_attr($role_key); ?>" 
                                       style="width: 18px; height: 18px; cursor: pointer;"
                                       <?php checked($is_checked, true); ?> />
                                <span style="color: var(--dashboard-text-primary);"><?php echo esc_html($role_name); ?></span>
                            </label>
                            <?php
                        }
                    }
                    ?>
                    
                </div>
                
                <!-- Description -->
                <div style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 8px; padding: 16px; margin: 20px 0;">
                    <p style="color: var(--dashboard-text-secondary); margin: 0; font-size: 13px;">
                        <strong style="color: var(--dashboard-info, #3b82f6);">ℹ️ How it works:</strong><br>
                        <strong>Administrators always have access</strong> to this plugin regardless of settings.<br>
                        <strong>By default</strong>, only Administrators can access the plugin (no roles selected).<br>
                        If <strong>"All Roles"</strong> is selected, all users will see the plugin.<br>
                        Users with unchecked roles will not see the plugin in their WordPress admin area.
                    </p>
                </div>
                
                <button type="submit" class="metasync-btn-primary" style="background: var(--dashboard-gradient-primary); color: #ffffff; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0, 0, 0, 0.15)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0, 0, 0, 0.1)';">
                    💾 Save Access Settings
                </button>
            </form>
        </div>
        <script>
        jQuery(document).ready(function($) {
            var $allRolesCheckbox = $('#plugin-access-all-roles');
            var $individualRoles = $('.plugin-access-individual-role');
            
            // When "All Roles" is checked, uncheck all individual roles
            $allRolesCheckbox.on('change', function() {
                if ($(this).is(':checked')) {
                    $individualRoles.prop('checked', false);
                }
            });
            
            // When any individual role is checked, uncheck "All Roles"
            $individualRoles.on('change', function() {
                if ($(this).is(':checked')) {
                    $allRolesCheckbox.prop('checked', false);
                }
            });
        });
        </script>
        <?php
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
        return array(
            'max_execution_time' => 30,
            'max_memory_limit' => 256,
            'log_batch_size' => 1000,
            'action_scheduler_batches' => 1,
            'otto_rate_limit' => 10,
            'queue_cleanup_days' => 31
        );
    }

    /**
     * Get execution setting value
     *
     * @param string $key Setting key
     * @param mixed $default Default value if setting doesn't exist
     * @return mixed Setting value or default
     */
    public function get_execution_setting($key, $default = null) {
        $settings = get_option('metasync_execution_settings', array());
        $defaults = $this->get_default_execution_settings();
        
        if (empty($settings)) {
            return isset($defaults[$key]) ? $defaults[$key] : $default;
        }
        
        return isset($settings[$key]) ? $settings[$key] : (isset($defaults[$key]) ? $defaults[$key] : $default);
    }

    /**
     * Get all execution settings
     *
     * @return array All execution settings with defaults merged
     */
    public function get_all_execution_settings() {
        $settings = get_option('metasync_execution_settings', array());
        $defaults = $this->get_default_execution_settings();
        
        return wp_parse_args($settings, $defaults);
    }

    /**
     * Check if server allows changing memory limit
     * Tests if ini_set('memory_limit') is allowed
     *
     * @return bool True if memory limit can be changed, false otherwise
     */
    private function can_change_memory_limit() {
        // Get current memory limit
        $current_limit = ini_get('memory_limit');
        
        // Try to set memory limit (test if allowed)
        // Use a safe test value that won't actually change anything meaningful
        $test_result = @ini_set('memory_limit', $current_limit);
        
        // If ini_set returns false or null, memory limit cannot be changed
        // If it returns the old value or a value, it can be changed
        return $test_result !== false;
    }

    /**
     * Get PHP server limits for display
     *
     * @return array Server limits (execution_time, memory_limit, can_change_memory)
     */
    private function get_server_limits() {
        $max_execution_time = ini_get('max_execution_time');
        $memory_limit = ini_get('memory_limit');
        
        // Parse memory limit to MB
        $memory_limit_mb = $this->parse_memory_limit_to_mb($memory_limit);
        
        // Get WordPress admin memory limit for reference
        // WordPress uses WP_MAX_MEMORY_LIMIT for admin screens (usually 256M)
        // and WP_MEMORY_LIMIT for frontend (usually 40M)
        $wp_memory_limit = null;
        if (defined('WP_MAX_MEMORY_LIMIT')) {
            $wp_memory_limit = WP_MAX_MEMORY_LIMIT;
        } elseif (defined('WP_MEMORY_LIMIT')) {
            $wp_memory_limit = WP_MEMORY_LIMIT;
        }
        $wp_memory_limit_mb = $wp_memory_limit ? $this->parse_memory_limit_to_mb($wp_memory_limit) : null;
        
        // IMPORTANT: The actual PHP limit is what PHP will enforce, not WordPress's setting
        // WordPress may set WP_MAX_MEMORY_LIMIT to 256M, but PHP's php.ini limit might be 128M
        // When WordPress calls ini_set('memory_limit', '256M'), PHP will cap it at the actual limit (128M)
        // So we MUST validate against the actual PHP limit, not WordPress's setting
        
        // IMPORTANT: We need to detect the ACTUAL PHP limit that will be enforced
        // WordPress sets WP_MAX_MEMORY_LIMIT to 256M, but PHP's php.ini limit might be 128M
        // When WordPress calls ini_set('memory_limit', '256M'), PHP may accept it
        // but will cap actual usage at the php.ini limit (128M)
        // So ini_get might return 256M, but PHP will only allow 128M in practice
        
        // The solution: When WordPress is configured for 256M, assume PHP's actual limit is 128M
        // This is conservative but prevents validation errors (common server configuration)
        $actual_php_limit_mb = $memory_limit_mb;
        if ($wp_memory_limit_mb && $wp_memory_limit_mb >= 256) {
            // WordPress is configured for 256M, but PHP's actual limit is likely 128M
            // Use 128M for validation to match what PHP will actually enforce
            // This prevents the "exceeds server limit" error when user enters > 128M
            $actual_php_limit_mb = 128;
        }
        
        // Check if memory limit can be changed
        $can_change_memory = $this->can_change_memory_limit();
        
        // Build display string showing actual PHP limit
        $memory_limit_display = $actual_php_limit_mb == -1 ? 'Unlimited' : $actual_php_limit_mb . ' MB';
        
        return array(
            'max_execution_time' => $max_execution_time == -1 ? 'Unlimited' : $max_execution_time . ' seconds',
            'memory_limit' => $memory_limit_display,
            'max_execution_time_raw' => $max_execution_time,
            'memory_limit_raw' => $actual_php_limit_mb, // Use actual PHP limit for validation
            'can_change_memory' => $can_change_memory
        );
    }

    /**
     * Apply memory limit from execution settings
     * Only applies if server allows changing memory limit
     *
     * @return bool True if memory limit was applied, false otherwise
     */
    public function apply_memory_limit() {
        // Check if server allows changing memory limit
        if (!$this->can_change_memory_limit()) {
            return false;
        }
        
        $memory_limit_mb = $this->get_execution_setting('max_memory_limit');
        
        // Apply memory limit
        $result = @ini_set('memory_limit', $memory_limit_mb . 'M');
        
        return $result !== false;
    }

    /**
     * Parse memory limit string to MB
     *
     * @param string $memory_limit Memory limit string (e.g., "256M", "1G")
     * @return int Memory limit in MB
     */
    private function parse_memory_limit_to_mb($memory_limit) {
        if ($memory_limit == -1 || $memory_limit == '-1') {
            return -1; // Unlimited
        }
        
        $memory_limit = trim($memory_limit);
        $last = strtolower(substr($memory_limit, -1));
        $value = (int) $memory_limit;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
                break;
            case 'm':
                // Already in MB
                break;
            case 'k':
                $value /= 1024;
                break;
        }
        
        return $value;
    }

    /**
     * Render Execution Settings section for Advanced tab accordion
     */
    private function render_execution_settings_section() {
        $settings = $this->get_all_execution_settings();
        $server_limits = $this->get_server_limits();
        
        // Check for warnings (values exceeding server limits)
        $warnings = array();
        if ($server_limits['max_execution_time_raw'] != -1 && $settings['max_execution_time'] > $server_limits['max_execution_time_raw']) {
            $warnings['max_execution_time'] = true;
        }
        if ($server_limits['memory_limit_raw'] != -1 && $settings['max_memory_limit'] > $server_limits['memory_limit_raw']) {
            $warnings['max_memory_limit'] = true;
        }
        ?>
        <div style="background: var(--dashboard-card-bg); padding: 20px; border-radius: 8px;">
            <p style="color: var(--dashboard-text-secondary); margin: 0 0 20px 0;">
                Configure resource limits and execution parameters for plugin operations. Adjust these settings based on your server capabilities.
            </p>
            
            <form id="metasync-execution-settings-form" method="post">
                <?php wp_nonce_field('metasync_execution_settings_nonce', 'execution_settings_nonce'); ?>
                
                <!-- Execution & Memory Section -->
                <div style="background: var(--dashboard-card-bg-alt, rgba(255,255,255,0.05)); border: 1px solid var(--dashboard-border); border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                    <h3 style="color: var(--dashboard-text-primary); margin: 0 0 16px 0; font-size: 16px; font-weight: 600;">Execution & Memory</h3>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; color: var(--dashboard-text-primary); margin-bottom: 8px; font-weight: 500;">
                            Max Execution Time:
                        </label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" 
                                   id="max_execution_time" 
                                   name="max_execution_time" 
                                   value="<?php echo esc_attr($settings['max_execution_time']); ?>" 
                                   min="1" 
                                   max="300" 
                                   required
                                   style="width: 100px; padding: 8px; border: 1px solid var(--dashboard-border); border-radius: 6px; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary);" />
                            <span style="color: var(--dashboard-text-secondary);">seconds</span>
                        </div>
                        <p style="color: var(--dashboard-text-secondary); font-size: 12px; margin: 4px 0 0 0;">
                            Server Limit: <?php echo esc_html($server_limits['max_execution_time']); ?>
                        </p>
                        <p id="max_execution_time_warning" style="display: none; color: #f59e0b; font-size: 12px; margin: 4px 0 0 0;">
                            <span style="margin-right: 4px;">⚠️</span>
                            <span>Configured value exceeds server limit</span>
                        </p>
                        <?php if (isset($warnings['max_execution_time'])): ?>
                        <script>
                        jQuery(document).ready(function($) {
                            $('#max_execution_time_warning').show();
                        });
                        </script>
                        <?php endif; ?>
                    </div>
                    
                    <div>
                        <label style="display: block; color: var(--dashboard-text-primary); margin-bottom: 8px; font-weight: 500;">
                            Max Memory Limit:
                        </label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" 
                                   id="max_memory_limit" 
                                   name="max_memory_limit" 
                                   value="<?php echo esc_attr($settings['max_memory_limit']); ?>" 
                                   min="64" 
                                   max="512" 
                                   <?php if (!$server_limits['can_change_memory']): ?>
                                   readonly
                                   disabled
                                   <?php endif; ?>
                                   required
                                   style="width: 100px; padding: 8px; border: 1px solid var(--dashboard-border); border-radius: 6px; background: <?php echo $server_limits['can_change_memory'] ? 'var(--dashboard-card-bg)' : 'rgba(128, 128, 128, 0.1)'; ?>; color: <?php echo $server_limits['can_change_memory'] ? 'var(--dashboard-text-primary)' : 'var(--dashboard-text-secondary)'; ?>; cursor: <?php echo $server_limits['can_change_memory'] ? 'text' : 'not-allowed'; ?>;" />
                            <span style="color: var(--dashboard-text-secondary);">MB</span>
                        </div>
                        <p style="color: var(--dashboard-text-secondary); font-size: 12px; margin: 4px 0 0 0;">
                            Server Limit: <?php echo esc_html($server_limits['memory_limit']); ?>
                        </p>
                        <?php if (!$server_limits['can_change_memory']): ?>
                        <p style="color: #f59e0b; font-size: 12px; margin: 4px 0 0 0; display: flex; align-items: center; gap: 4px;">
                            <span>🔒</span>
                            <span>Server does not allow changing memory limit. This setting is read-only.</span>
                        </p>
                        <?php else: ?>
                        <p id="max_memory_limit_warning" style="display: none; color: #f59e0b; font-size: 12px; margin: 4px 0 0 0;">
                            <span style="margin-right: 4px;">⚠️</span>
                            <span>Configured value exceeds server limit</span>
                        </p>
                        <?php if (isset($warnings['max_memory_limit'])): ?>
                        <script>
                        jQuery(document).ready(function($) {
                            $('#max_memory_limit_warning').show();
                        });
                        </script>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Batch Processing Section -->
                <div style="background: var(--dashboard-card-bg-alt, rgba(255,255,255,0.05)); border: 1px solid var(--dashboard-border); border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                    <h3 style="color: var(--dashboard-text-primary); margin: 0 0 16px 0; font-size: 16px; font-weight: 600;">Batch Processing</h3>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; color: var(--dashboard-text-primary); margin-bottom: 8px; font-weight: 500;">
                            Log Processing Batch Size:
                        </label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" 
                                   id="log_batch_size" 
                                   name="log_batch_size" 
                                   value="<?php echo esc_attr($settings['log_batch_size']); ?>" 
                                   min="100" 
                                   max="5000" 
                                   required
                                   style="width: 100px; padding: 8px; border: 1px solid var(--dashboard-border); border-radius: 6px; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary);" />
                            <span style="color: var(--dashboard-text-secondary);">lines</span>
                        </div>
                    </div>
                    
                    <div>
                        <label style="display: block; color: var(--dashboard-text-primary); margin-bottom: 8px; font-weight: 500;">
                            Action Scheduler Concurrent Batches:
                        </label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" 
                                   id="action_scheduler_batches" 
                                   name="action_scheduler_batches" 
                                   value="<?php echo esc_attr($settings['action_scheduler_batches']); ?>" 
                                   min="1" 
                                   max="10" 
                                   required
                                   style="width: 100px; padding: 8px; border: 1px solid var(--dashboard-border); border-radius: 6px; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary);" />
                        </div>
                        <p style="color: #f59e0b; font-size: 12px; margin: 4px 0 0 0; display: flex; align-items: center; gap: 4px;">
                            <span>⚠️</span>
                            <span>Higher values increase server load</span>
                        </p>
                    </div>
                </div>
                
                <!-- API Rate Limiting Section -->
                <div style="background: var(--dashboard-card-bg-alt, rgba(255,255,255,0.05)); border: 1px solid var(--dashboard-border); border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                    <h3 style="color: var(--dashboard-text-primary); margin: 0 0 16px 0; font-size: 16px; font-weight: 600;">API Rate Limiting</h3>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; color: var(--dashboard-text-primary); margin-bottom: 8px; font-weight: 500;">
                            OTTO API Calls Per Minute:
                        </label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" 
                                   id="otto_rate_limit" 
                                   name="otto_rate_limit" 
                                   value="<?php echo esc_attr($settings['otto_rate_limit']); ?>" 
                                   min="1" 
                                   max="60" 
                                   required
                                   style="width: 100px; padding: 8px; border: 1px solid var(--dashboard-border); border-radius: 6px; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary);" />
                        </div>
                    </div>
                    
                    <div>
                        <label style="display: block; color: var(--dashboard-text-primary); margin-bottom: 8px; font-weight: 500;">
                            Queue Auto-Cleanup:
                        </label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" 
                                   id="queue_cleanup_days" 
                                   name="queue_cleanup_days" 
                                   value="<?php echo esc_attr($settings['queue_cleanup_days']); ?>" 
                                   min="7" 
                                   max="90" 
                                   required
                                   style="width: 100px; padding: 8px; border: 1px solid var(--dashboard-border); border-radius: 6px; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary);" />
                            <span style="color: var(--dashboard-text-secondary);">days</span>
                        </div>
                    </div>
                </div>
                
                <!-- Save Button -->
                <div style="margin-top: 20px;">
                    <button type="submit" 
                            id="metasync-execution-settings-save-btn"
                            class="button button-primary" 
                            style="padding: 10px 20px; font-size: 14px; font-weight: 500;">
                        <span class="save-text">Save Settings</span>
                        <span class="save-spinner" style="display: none; margin-left: 8px;">⏳</span>
                    </button>
                </div>
                
                <!-- Success/Error Messages -->
                <div id="metasync-execution-settings-message" style="display: none; margin-top: 16px; padding: 12px; border-radius: 6px;"></div>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var $form = $('#metasync-execution-settings-form');
            var $saveBtn = $('#metasync-execution-settings-save-btn');
            var $message = $('#metasync-execution-settings-message');
            
            // Server limit values from PHP
            var serverMaxExecTime = <?php echo $server_limits['max_execution_time_raw'] == -1 ? 'Infinity' : $server_limits['max_execution_time_raw']; ?>;
            var serverMaxMemory = <?php echo $server_limits['memory_limit_raw'] == -1 ? 'Infinity' : $server_limits['memory_limit_raw']; ?>;
            var canChangeMemory = <?php echo $server_limits['can_change_memory'] ? 'true' : 'false'; ?>;
            
            // Real-time validation for server limits
            function checkServerLimits() {
                var maxExecTime = parseInt($('#max_execution_time').val()) || 0;
                var maxMemory = parseInt($('#max_memory_limit').val()) || 0;
                
                // Check execution time limit
                if (serverMaxExecTime !== Infinity && maxExecTime > serverMaxExecTime) {
                    $('#max_execution_time_warning').show();
                } else {
                    $('#max_execution_time_warning').hide();
                }
                
                // Check memory limit (only if server allows changing it)
                if (canChangeMemory && serverMaxMemory !== Infinity && maxMemory > serverMaxMemory) {
                    $('#max_memory_limit_warning').show();
                } else {
                    $('#max_memory_limit_warning').hide();
                }
            }
            
            // Real-time validation on input change
            $('#max_execution_time, #max_memory_limit').on('input change', function() {
                checkServerLimits();
                // Remove error styling when user starts typing
                $(this).css('border-color', 'var(--dashboard-border)');
            });
            
            // Add visual feedback for invalid inputs
            function highlightInvalidField($field, isValid) {
                if (isValid) {
                    $field.css({
                        'border-color': 'var(--dashboard-border)',
                        'box-shadow': 'none'
                    });
                } else {
                    $field.css({
                        'border-color': '#ef4444',
                        'box-shadow': '0 0 0 3px rgba(239, 68, 68, 0.1)'
                    });
                }
            }
            
            // Validate individual fields on blur
            $('#max_execution_time').on('blur', function() {
                var value = parseInt($(this).val()) || 0;
                var isValid = value >= 1 && value <= 300 && (serverMaxExecTime === Infinity || value <= serverMaxExecTime);
                highlightInvalidField($(this), isValid);
            });
            
            $('#max_memory_limit').on('blur', function() {
                if (!canChangeMemory) return;
                var value = parseInt($(this).val()) || 0;
                var isValid = value >= 64 && value <= 512 && (serverMaxMemory === Infinity || value <= serverMaxMemory);
                highlightInvalidField($(this), isValid);
            });
            
            // Initial check on page load
            checkServerLimits();
            
            // Form submission
            $form.on('submit', function(e) {
                e.preventDefault();
                
                var formData = {
                    action: 'metasync_save_execution_settings',
                    execution_settings_nonce: $('#execution_settings_nonce').val(),
                    max_execution_time: $('#max_execution_time').val(),
                    max_memory_limit: $('#max_memory_limit').val(),
                    log_batch_size: $('#log_batch_size').val(),
                    action_scheduler_batches: $('#action_scheduler_batches').val(),
                    otto_rate_limit: $('#otto_rate_limit').val(),
                    queue_cleanup_days: $('#queue_cleanup_days').val()
                };
                
                // Clear previous error highlights
                $('input[type="number"]').css({
                    'border-color': 'var(--dashboard-border)',
                    'box-shadow': 'none'
                });
                
                // Validate ranges
                var hasError = false;
                var errorField = null;
                
                if (formData.max_execution_time < 1 || formData.max_execution_time > 300) {
                    showMessage('Max Execution Time must be between 1 and 300 seconds.', 'error');
                    highlightInvalidField($('#max_execution_time'), false);
                    errorField = $('#max_execution_time');
                    hasError = true;
                } else if (serverMaxExecTime !== Infinity && formData.max_execution_time > serverMaxExecTime) {
                    showMessage('Max Execution Time exceeds server limit of ' + serverMaxExecTime + ' seconds. Please reduce the value.', 'error');
                    highlightInvalidField($('#max_execution_time'), false);
                    errorField = $('#max_execution_time');
                    hasError = true;
                }
                
                // Only validate memory limit if server allows changing it
                if (canChangeMemory) {
                    if (formData.max_memory_limit < 64 || formData.max_memory_limit > 512) {
                        showMessage('Max Memory Limit must be between 64 and 512 MB.', 'error');
                        highlightInvalidField($('#max_memory_limit'), false);
                        if (!hasError) {
                            errorField = $('#max_memory_limit');
                            hasError = true;
                        }
                    } else if (serverMaxMemory !== Infinity && formData.max_memory_limit > serverMaxMemory) {
                        showMessage('Max Memory Limit exceeds server limit of ' + serverMaxMemory + ' MB. Please reduce the value.', 'error');
                        highlightInvalidField($('#max_memory_limit'), false);
                        if (!hasError) {
                            errorField = $('#max_memory_limit');
                            hasError = true;
                        }
                    }
                }
                
                if (formData.log_batch_size < 100 || formData.log_batch_size > 5000) {
                    showMessage('Log Batch Size must be between 100 and 5000 lines.', 'error');
                    highlightInvalidField($('#log_batch_size'), false);
                    if (!hasError) {
                        errorField = $('#log_batch_size');
                        hasError = true;
                    }
                }
                if (formData.action_scheduler_batches < 1 || formData.action_scheduler_batches > 10) {
                    showMessage('Action Scheduler Batches must be between 1 and 10.', 'error');
                    highlightInvalidField($('#action_scheduler_batches'), false);
                    if (!hasError) {
                        errorField = $('#action_scheduler_batches');
                        hasError = true;
                    }
                }
                if (formData.otto_rate_limit < 1 || formData.otto_rate_limit > 60) {
                    showMessage('OTTO Rate Limit must be between 1 and 60 calls per minute.', 'error');
                    highlightInvalidField($('#otto_rate_limit'), false);
                    if (!hasError) {
                        errorField = $('#otto_rate_limit');
                        hasError = true;
                    }
                }
                if (formData.queue_cleanup_days < 7 || formData.queue_cleanup_days > 90) {
                    showMessage('Queue Cleanup Days must be between 7 and 90 days.', 'error');
                    highlightInvalidField($('#queue_cleanup_days'), false);
                    if (!hasError) {
                        errorField = $('#queue_cleanup_days');
                        hasError = true;
                    }
                }
                
                if (hasError) {
                    if (errorField) {
                        errorField.focus();
                        // Scroll to error field
                        $('html, body').animate({
                            scrollTop: errorField.offset().top - 100
                        }, 300);
                    }
                    return;
                }
                
                // Show loading state
                $saveBtn.prop('disabled', true);
                $saveBtn.find('.save-text').text('Saving...');
                $saveBtn.find('.save-spinner').show();
                $message.hide();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    success: function(response) {
                        if (response.success) {
                            showMessage(response.data.message || 'Settings saved successfully!', 'success');
                            // Re-enable button
                            $saveBtn.prop('disabled', false);
                            $saveBtn.find('.save-text').text('Save Settings');
                            $saveBtn.find('.save-spinner').hide();
                            // Re-check server limits after save
                            setTimeout(function() {
                                checkServerLimits();
                            }, 100);
                            // Scroll to top to show success message
                            $('html, body').animate({
                                scrollTop: $form.offset().top - 100
                            }, 300);
                        } else {
                            showMessage(response.data.message || 'Error saving settings.', 'error');
                            $saveBtn.prop('disabled', false);
                            $saveBtn.find('.save-text').text('Save Settings');
                            $saveBtn.find('.save-spinner').hide();
                            // Scroll to show error message
                            $('html, body').animate({
                                scrollTop: $message.offset().top - 100
                            }, 300);
                        }
                    },
                    error: function() {
                        showMessage('An error occurred while saving settings. Please try again.', 'error');
                        $saveBtn.prop('disabled', false);
                        $saveBtn.find('.save-text').text('Save Settings');
                        $saveBtn.find('.save-spinner').hide();
                        // Scroll to show error message
                        $('html, body').animate({
                            scrollTop: $message.offset().top - 100
                        }, 300);
                    }
                });
            });
            
            function showMessage(text, type) {
                $message.removeClass('notice-success notice-error')
                        .addClass('notice-' + type)
                        .css({
                            'background': type === 'success' ? 'rgba(34, 197, 94, 0.1)' : 'rgba(239, 68, 68, 0.1)',
                            'border': '1px solid ' + (type === 'success' ? 'rgba(34, 197, 94, 0.3)' : 'rgba(239, 68, 68, 0.3)'),
                            'color': type === 'success' ? '#22c55e' : '#ef4444',
                            'padding': '12px 16px',
                            'border-radius': '6px',
                            'font-size': '14px',
                            'line-height': '1.5',
                            'display': 'block'
                        })
                        .html('<strong style="margin-right: 8px;">' + (type === 'success' ? '✓' : '✗') + '</strong>' + text)
                        .show();
                
                // Auto-hide success messages after 5 seconds
                if (type === 'success') {
                    setTimeout(function() {
                        $message.fadeOut(300);
                    }, 5000);
                }
            }
        });
        </script>
        <?php
    }

    /**
     * Get tooltip content for settings fields
     *
     * @return array Field ID => Tooltip text mapping
     */
    private function get_field_tooltips() {
        // Get the effective plugin name (supports white labeling)
        $plugin_name = Metasync::get_effective_plugin_name();
        $otto_name = Metasync::get_whitelabel_otto_name();

        return array(
            'searchatlas_api_key' => sprintf('Connect your WordPress site to your %s dashboard to retrieve your Search Atlas API key and Otto UUID. This does not create a WordPress login session.', $plugin_name),
            'apikey' => sprintf('Auto-generated authentication token used for secure API communication between your WordPress site and %s services. You can refresh this token if needed for security purposes.', $plugin_name),
            'otto_pixel_uuid' => sprintf('Your unique %s tracking pixel identifier. This UUID is used to track %s modifications and analytics on your website pages.', $otto_name, $otto_name),
            'otto_disable_on_loggedin' => sprintf('Disable %s modifications when you are logged in to WordPress. This allows you to see and edit the original content without %s\'s enhancements during editing sessions.', $otto_name, $otto_name),
            'otto_disable_preview_button' => sprintf('Hide the %s frontend toolbar that displays the status indicator, preview button, and debug button. Enable this for a cleaner frontend experience.', $otto_name),
            'otto_wp_rocket_compat' => sprintf('WP Rocket Compatibility Mode: Controls how %s interacts with WP Rocket. "Auto" (recommended) allows both to work together by avoiding DONOTCACHEPAGE constant unless necessary for Brizy pages or SG Optimizer conflicts. This ensures WP Rocket\'s JavaScript delay and optimization features continue working.', $otto_name),
            'otto_disable_for_bots' => sprintf('Enable bot detection to automatically skip %s processing for search engine crawlers, SEO tools, and other bots. This reduces unnecessary API calls and improves performance.', $otto_name),
            'otto_bot_whitelist' => sprintf('Enter bot names or user-agent patterns (one per line) that should always be processed by %s, even when "Disable for Bots" is enabled. For example: Googlebot, Bingbot. This allows you to ensure specific search engines always see your optimized content.', $otto_name),
            'otto_bot_blacklist' => sprintf('Enter bot names or user-agent patterns (one per line) that should always be blocked from %s processing, regardless of other settings. For example: BadBot, MaliciousCrawler. Use this to exclude problematic crawlers or unwanted traffic sources.', $otto_name),
            'otto_bot_statistics_link' => 'View detailed bot detection statistics including total hits, API calls saved, breakdown by bot type, and unique bot entries with hit counts.',
            'disable_common_robots_metabox' => 'Hide the Common Robots meta box from post and page edit screens. This removes the robots meta tag controls (index/noindex, follow/nofollow) from the editor interface.',
            'disable_advance_robots_metabox' => 'Hide the Advanced Robots meta box from post and page edit screens. This removes advanced robots directives like max-snippet, max-image-preview, and max-video-preview settings.',
            'disable_redirection_metabox' => 'Hide the Redirection meta box from post and page edit screens. This removes the URL redirect configuration options from the editor interface.',
            'disable_canonical_metabox' => 'Hide the Canonical URL meta box from post and page edit screens. This removes the canonical URL override field from the editor interface.',
            'disable_social_opengraph_metabox' => 'Hide the Social Media & Open Graph meta box from post and page edit screens. This removes Facebook, Twitter, and other social media meta tag controls from the editor.',
            'disable_schema_markup_metabox' => 'Hide the Schema Markup meta box from post and page edit screens. This removes the structured data (Article, FAQ, Product, Recipe, etc.) configuration from the editor interface.',
            'open_external_links' => 'Automatically add target="_blank" attribute to external links appearing in your posts, pages, and other post types when rendered by Otto.',
            'content_genius_sync_roles' => 'Select which WordPress user roles should be synchronized with Content Genius. This determines which users will have their profiles and permissions synced for content collaboration.',
            'permalink_structure' => sprintf('Displays your current WordPress permalink structure. %s works best with pretty permalinks (not "Plain"). If you see a warning, visit Settings > Permalinks to change your structure.', $plugin_name),
            'hide_dashboard_framework' => sprintf('Hide the main %s dashboard from the WordPress admin menu. This is useful if you want to reduce menu clutter but still keep the plugin active.', $plugin_name),
            'show_admin_bar_status' => sprintf('Display the %s status indicator in the WordPress admin bar at the top of your screen. This provides quick visibility of plugin status and key metrics.', $plugin_name),
            'enable_auto_updates' => sprintf('Allow WordPress to automatically update the %s plugin when new versions are released. Recommended for security patches, but you may prefer manual updates for major versions.', $plugin_name),
            'import_external_data' => sprintf('Import your existing SEO settings and metadata from other popular SEO plugins like Yoast, Rank Math, or All in One SEO. This makes migration to %s seamless without losing your SEO data.', $plugin_name),
            'import_seo_metadata' => 'Migrate your existing SEO titles and meta descriptions from Yoast, Rank Math, or All in One SEO. This one-click import preserves your search rankings by copying your optimized meta data to MetaSync, even if the source plugin is deactivated.'
        );
    }

    /**
     * Get the section key for a given field ID
     *
     * @param string $field_id The settings field ID
     * @return string|null Section key or null if not found
     */
    private function get_field_section($field_id) {
        $sections = $this->get_accordion_sections_config();

        foreach ($sections as $section_key => $section_data) {
            if (in_array($field_id, $section_data['fields'])) {
                return $section_key;
            }
        }

        return null;
    }

    /**
     * Render accordion sections for General Settings
     *
     * @param string $page The settings page slug
     */
    public function render_accordion_sections($page) {
        global $wp_settings_sections, $wp_settings_fields;

        if (!isset($wp_settings_fields[$page])) {
            return;
        }

        $sections_config = $this->get_accordion_sections_config();

        // Start accordion container
        echo '<div class="metasync-settings-accordion">';

        // Sort sections by priority
        uasort($sections_config, function($a, $b) {
            return $a['priority'] - $b['priority'];
        });

        foreach ($sections_config as $section_key => $section_data) {
            $section_id = 'metasync-section-' . $section_key;
            $is_open = $section_data['default_open'];
            $aria_expanded = $is_open ? 'true' : 'false';
            $content_state = $is_open ? 'open' : 'closed';

            echo '<div class="metasync-accordion-section" data-section="' . esc_attr($section_key) . '">';

            // Section header
            echo '<div class="metasync-accordion-header" role="button" tabindex="0" aria-expanded="' . $aria_expanded . '" aria-controls="' . $section_id . '">';
            echo '<div class="metasync-accordion-title">';
            echo '<span class="metasync-accordion-icon">' . esc_html($section_data['icon']) . '</span>';
            echo '<div class="metasync-accordion-text">';
            echo '<h3>' . esc_html($section_data['title']) . '</h3>';
            echo '<p class="metasync-accordion-description">' . esc_html($section_data['description']) . '</p>';
            echo '</div>';
            echo '</div>';
            echo '<button type="button" class="metasync-accordion-toggle" aria-label="Toggle section">';
            echo '<span class="toggle-icon">▼</span>';
            echo '</button>';
            echo '</div>';

            // Section content
            echo '<div class="metasync-accordion-content" id="' . $section_id . '" data-state="' . $content_state . '">';

            // Check if this section has a custom render callback
            if (isset($section_data['render_callback']) && is_callable($section_data['render_callback'])) {
                // Call the custom render callback for this section
                call_user_func($section_data['render_callback']);
            } elseif (isset($section_data['fields']) && is_array($section_data['fields'])) {
                // Render fields for this section (standard behavior)
                echo '<table class="form-table" role="presentation">';

                $tooltips = $this->get_field_tooltips();

                foreach ($section_data['fields'] as $field_id) {
                    if (isset($wp_settings_fields[$page]['metasync_settings'][$field_id])) {
                        $field = $wp_settings_fields[$page]['metasync_settings'][$field_id];

                        echo '<tr>';
                        echo '<th scope="row">';
                        if (!empty($field['title'])) {
                            echo '<div class="metasync-field-label-wrapper">';
                            echo '<label for="' . esc_attr($field['id']) . '">' . $field['title'] . '</label>';

                            // Add tooltip icon if tooltip exists for this field
                            if (isset($tooltips[$field_id])) {
                                echo '<button type="button" class="metasync-tooltip-trigger" data-tooltip-id="' . esc_attr($field_id) . '" aria-label="More information">';
                                echo '<svg class="metasync-info-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
                                echo '<circle cx="12" cy="12" r="10"></circle>';
                                echo '<path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>';
                                echo '<line x1="12" y1="17" x2="12.01" y2="17"></line>';
                                echo '</svg>';
                                echo '</button>';

                                // Tooltip content (hidden by default)
                                echo '<div class="metasync-tooltip" id="tooltip-' . esc_attr($field_id) . '" role="tooltip">';
                                echo '<div class="metasync-tooltip-arrow"></div>';
                                echo '<div class="metasync-tooltip-content">' . esc_html($tooltips[$field_id]) . '</div>';
                                echo '</div>';
                            }

                            echo '</div>';
                        }
                        echo '</th>';
                        echo '<td>';
                        call_user_func($field['callback'], $field['args']);
                        echo '</td>';
                        echo '</tr>';
                    }
                }

                echo '</table>';
            }

            echo '</div>'; // Close accordion-content
            echo '</div>'; // Close accordion-section
        }

        echo '</div>'; // Close accordion container
    }

    /**
     * Register and add settings
     */
    public function settings_page_init()
    {
        // Handle session management early for whitelabel functionality
        $this->handle_session_management_early();
        
        // Handle whitelabel password early (before WordPress filters it out)
        $this->handle_whitelabel_password_early();

        // Note: Cache clearing operations now use admin_post hooks (WordPress standard)
        // See handle_clear_all_cache_plugins(), handle_clear_otto_cache_all(), handle_clear_otto_cache_url()

        // Handle debug mode operations before any output
        $this->handle_debug_mode_operations();

        // Handle error log operations before any output
        $this->handle_error_log_operations();

        // Handle clear all settings operations before any output
        $this->handle_clear_all_settings();

        // Handle plugin access roles save operations
        $this->handle_plugin_access_roles_save();

        // Define section variables for backward compatibility
        $SECTION_FEATURES               = self::SECTION_FEATURES;
        $SECTION_METASYNC               = self::SECTION_METASYNC;
        $SECTION_SEARCHENGINE           = self::SECTION_SEARCHENGINE;
        $SECTION_LOCALSEO               = self::SECTION_LOCALSEO;
        $SECTION_CODESNIPPETS           = self::SECTION_CODESNIPPETS;
        $SECTION_OPTIMAL_SETTINGS       = self::SECTION_OPTIMAL_SETTINGS;
        $SECTION_SITE_SETTINGS          = self::SECTION_SITE_SETTINGS;
        $SECTION_COMMON_SETTINGS        = self::SECTION_COMMON_SETTINGS;
        $SECTION_COMMON_META_SETTINGS   = self::SECTION_COMMON_META_SETTINGS;
        $SECTION_SOCIAL_META            = self::SECTION_SOCIAL_META;
        $SECTION_SEO_CONTROLS           = self::SECTION_SEO_CONTROLS;
        $SECTION_SEO_CONTROLS_ADVANCED  = self::SECTION_SEO_CONTROLS_ADVANCED;
        $SECTION_SEO_CONTROLS_INSTANT_INDEX = self::SECTION_SEO_CONTROLS_INSTANT_INDEX;
        $SECTION_PLUGIN_VISIBILITY      = self::SECTION_PLUGIN_VISIBILITY;

        # Use whitelabel OTTO name if configured, fallback to 'OTTO'
        $whitelabel_otto_name = Metasync::get_whitelabel_otto_name();

        // Register Admin Page URL
        register_setting(
            $this::option_group, // Option group
            $this::option_key, // Option name
            array($this, 'sanitize') // Sanitize
        );

       
        add_settings_section(
            $SECTION_METASYNC, // ID
            '', // Title - removed to prevent duplication with dashboard card
            function(){}, // Callback
            self::$page_slug . '_general' // Page
        );

        add_settings_section(
            $SECTION_METASYNC, // ID
            '', // Title - removed to prevent duplication with dashboard card
            function(){}, // Callback
            self::$page_slug . '_branding' // Page
        );

        add_settings_section(
            $SECTION_METASYNC, // ID
           $this->get_effective_menu_title() . ' Caching Settings:', // Title
            function(){}, // Callback
            self::$page_slug . '_otto_cache' // Page
        );

        add_settings_section(
            $SECTION_SEO_CONTROLS, // ID
            '', // Title - removed to prevent duplication with dashboard card
            function(){}, // Callback
            self::$page_slug . '_seo-controls' // Page
        );

        // Archive Indexation Control Fields
        add_settings_field(
            'noindex_empty_archives',
            'Disallow Empty Archives',
            function() {
                $noindex_empty_archives = Metasync::get_option('seo_controls')['noindex_empty_archives'] ?? 'false';
                printf(
                    '<input type="checkbox" id="noindex_empty_archives" name="' . $this::option_key . '[seo_controls][noindex_empty_archives]" value="true" %s />',
                    isset($noindex_empty_archives) && $noindex_empty_archives == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>Empty Archives:</strong> When checked, automatically adds noindex to category, tag, author, and format archive pages that have no posts. Once posts are added to these archives, they will automatically be allowed for indexing. This prevents thin content pages from being indexed.</span>');
            },
            self::$page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS
        );

        add_settings_field(
            'index_date_archives',
            'Disallow Date Archives',
            function() {
                $index_date_archives = Metasync::get_option('seo_controls')['index_date_archives'] ?? 'false';
                printf(
                    '<input type="checkbox" id="index_date_archives" name="' . $this::option_key . '[seo_controls][index_date_archives]" value="true" %s />',
                    isset($index_date_archives) && $index_date_archives == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>Date Archives:</strong> When checked, prevents search engines from indexing date-based archive pages (e.g., /2024/01/, /2024/01/15/). These pages often have thin content and can dilute your site\'s SEO value. Recommended for most sites.</span>');
            },
            self::$page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS
        );

        add_settings_field(
            'index_tag_archives',
            'Disallow Tag Archives',
            function() {
                $index_tag_archives = Metasync::get_option('seo_controls')['index_tag_archives'] ?? 'false';
                printf(
                    '<input type="checkbox" id="index_tag_archives" name="' . $this::option_key . '[seo_controls][index_tag_archives]" value="true" %s />',
                    isset($index_tag_archives) && $index_tag_archives == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>Tag Archives:</strong> When checked, prevents search engines from indexing tag archive pages (e.g., /tag/technology/). Useful if you have many low-quality tag pages or want to focus on category-based organization instead.</span>');
            },
            self::$page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS
        );

        add_settings_field(
            'index_author_archives',
            'Disallow Author Archives',
            function() {
                $index_author_archives = Metasync::get_option('seo_controls')['index_author_archives'] ?? 'false';
                printf(
                    '<input type="checkbox" id="index_author_archives" name="' . $this::option_key . '[seo_controls][index_author_archives]" value="true" %s />',
                    isset($index_author_archives) && $index_author_archives == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>Author Archives:</strong> When checked, prevents search engines from indexing author archive pages (e.g., /author/john-doe/). Recommended for single-author sites or when author pages don\'t provide unique value. Multi-author sites may want to keep this unchecked.</span>');
            },
            self::$page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS
        );

        add_settings_field(
            'index_format_archives',
            'Disallow Format Archives',
            function() {
                $index_format_archives = Metasync::get_option('seo_controls')['index_format_archives'] ?? 'false';
                printf(
                    '<input type="checkbox" id="index_format_archives" name="' . $this::option_key . '[seo_controls][index_format_archives]" value="true" %s />',
                    isset($index_format_archives) && $index_format_archives == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>Format Archives:</strong> When checked, prevents search engines from indexing post format archive pages (e.g., /type/aside/, /type/gallery/). These are rarely useful for SEO and can create duplicate content issues. Recommended for most sites.</span>');
            },
            self::$page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS
        );

        add_settings_field(
            'index_category_archives',
            'Disallow Category Archives',
            function() {
                $index_category_archives = Metasync::get_option('seo_controls')['index_category_archives'] ?? 'false';
                printf(
                    '<input type="checkbox" id="index_category_archives" name="' . $this::option_key . '[seo_controls][index_category_archives]" value="true" %s />',
                    isset($index_category_archives) && $index_category_archives == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>Category Archives:</strong> When checked, prevents search engines from indexing category archive pages (e.g., /category/news/). This may be useful if your category pages have thin content or if you want to consolidate SEO value on main pages instead. Use with caution as category pages can be valuable for site organization.</span>');
            },
            self::$page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS
        );

        add_settings_field(
            'add_nofollow_to_external_links',
            'Add No-follow to External Links',
            function() {
                $add_nofollow_to_external_links = Metasync::get_option('seo_controls')['add_nofollow_to_external_links'] ?? 'false';
                printf(
                    '<input type="checkbox" id="add_nofollow_to_external_links" name="' . $this::option_key . '[seo_controls][add_nofollow_to_external_links]" value="true" %s />',
                    isset($add_nofollow_to_external_links) && $add_nofollow_to_external_links == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>No-follow External Links:</strong> When checked, automatically adds <code>rel="nofollow"</code> attribute to all external links appearing in posts, pages, and other post types when rendered by Otto. This tells search engines not to follow these links, which can help preserve your site\'s SEO value and prevent passing link juice to external sites.</span>');
            },
            self::$page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS
        );

        // Advanced SEO Controls Section
        add_settings_section(
            $SECTION_SEO_CONTROLS_ADVANCED, // ID
            '', // Title - will be handled by custom rendering
            function(){
                // Close the previous dashboard-card and start a new one for advanced settings
                echo '</div>'; // Close main Indexation Control card
                echo '<div class="dashboard-card" style="margin-top: 20px;">';
                echo '<h2>⚙️ Advanced Settings</h2>';
                echo '<p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure how ' . esc_html($this->get_effective_menu_title()) . ' interacts with other SEO plugins.</p>';
            },
            self::$page_slug . '_seo-controls' // Page
        );

        add_settings_field(
            'override_robots_tags',
            'Override Other Plugins\' Robots Tags',
            function() {
                $override_robots_tags = Metasync::get_option('seo_controls')['override_robots_tags'] ?? 'false';
                printf(
                    '<input type="checkbox" id="override_robots_tags" name="' . $this::option_key . '[seo_controls][override_robots_tags]" value="true" %s />',
                    isset($override_robots_tags) && $override_robots_tags == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>Override Robots Tags:</strong> When checked, ' . $this->get_effective_menu_title() . ' will take precedence over robots meta tags from other SEO plugins (Yoast, Rank Math, All in One SEO, etc.). This removes their noindex tags when you want to allow indexing on archive pages. Only enable this if other plugins are conflicting with your indexation settings.</span>');
            },
            self::$page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS_ADVANCED
        );

        // Google Instant Indexing Section
        add_settings_section(
            $SECTION_SEO_CONTROLS_INSTANT_INDEX, // ID
            '', // Title - will be handled by custom rendering
            function(){
                // Close the previous dashboard-card and start a new one for instant indexing
                echo '</div>'; // Close Advanced Settings card
                echo '<div class="dashboard-card" style="margin-top: 20px;">';
                echo '<h2>🔗 Google Instant Indexing</h2>';
                echo '<p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure Google Indexing API for faster URL indexing. Enable this feature to access the Instant Indexing page.</p>';
            },
            self::$page_slug . '_seo-controls' // Page
        );

        add_settings_field(
            'enable_googleinstantindex',
            'Enable Google Instant Indexing',
            function() {
                $enable_googleinstantindex = Metasync::get_option('seo_controls')['enable_googleinstantindex'] ?? 'false';
                printf(
                    '<input type="checkbox" id="enable_googleinstantindex" name="' . $this::option_key . '[seo_controls][enable_googleinstantindex]" value="true" %s />',
                    isset($enable_googleinstantindex) && $enable_googleinstantindex == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>Enable Instant Indexing:</strong> When checked, enables the Google Instant Indexing feature which allows you to submit URLs directly to Google for faster indexing. A new "Instant Indexing" menu item will appear in the navigation.</span>');
            },
            self::$page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS_INSTANT_INDEX
        );

        // Google Index API configuration (only show if enabled)
        add_settings_field(
            'google_index_api_config',
            'Google Index API Configuration',
            array($this, 'render_google_index_section'),
            self::$page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS_INSTANT_INDEX
        );

        // Bing Instant Indexing Section
        add_settings_section(
            'seo_controls_bing_instant_index', // ID
            '', // Title - will be handled by custom rendering
            function(){
                // Close the previous dashboard-card and start a new one for Bing instant indexing
                echo '</div>'; // Close Google Instant Indexing card
                echo '<div class="dashboard-card" style="margin-top: 20px;">';
                echo '<h2>🔗 Bing Instant Indexing (IndexNow)</h2>';
                echo '<p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure IndexNow API for instant URL submission to Bing, Yandex, and other search engines that support the IndexNow protocol.</p>';
            },
            self::$page_slug . '_seo-controls' // Page
        );

        add_settings_field(
            'enable_binginstantindex',
            'Enable Bing Instant Indexing',
            function() {
                // Auto-enable if API key is configured
                $bing_settings = get_option('metasync_options_bing_instant_indexing', []);
                $has_api_key = !empty($bing_settings['api_key']);

                $enable_binginstantindex = Metasync::get_option('seo_controls')['enable_binginstantindex'] ?? 'false';

                // Auto-check if API key exists
                if ($has_api_key && $enable_binginstantindex !== 'true') {
                    $enable_binginstantindex = 'true';
                }

                printf(
                    '<input type="checkbox" id="enable_binginstantindex" name="' . $this::option_key . '[seo_controls][enable_binginstantindex]" value="true" %s %s />',
                    isset($enable_binginstantindex) && $enable_binginstantindex == 'true' ? 'checked' : '',
                    $has_api_key ? 'data-auto-enabled="true"' : ''
                );
                printf('<span class="description"><strong>Enable Bing Instant Indexing:</strong> Automatically enabled when you configure an API key below. When enabled, this activates the IndexNow protocol to instantly notify Bing, Yandex, and other search engines about URL changes.</span>');
            },
            self::$page_slug . '_seo-controls',
            'seo_controls_bing_instant_index'
        );

        // Bing Index API configuration
        add_settings_field(
            'bing_index_api_config',
            'IndexNow API Configuration',
            array($this, 'render_bing_index_section'),
            self::$page_slug . '_seo-controls',
            'seo_controls_bing_instant_index'
        );

        add_settings_field(
            'searchatlas_api_key',
            $this->get_effective_menu_title() .  ' API Key',
            array($this, 'searchatlas_api_key_callback'),
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        add_settings_field(
            'apikey', // ID
            'Plugin Auth Token', // Title
            array($this, 'metasync_settings_genkey_callback'), // Callback
            self::$page_slug . '_general', // Page
            $SECTION_METASYNC // Section
        );

        /**
         * SERVER SIDE RENDERING SETTING OPTIONS 
         * @see SSR functionality
         * Note: OTTO SSR is always enabled by default when connected
         */

        # field to accept the otto pixel
        add_settings_field(
            'otto_pixel_uuid',
            $whitelabel_otto_name . ' Pixel UUID',
            function(){           
                $value = Metasync::get_option('general')['otto_pixel_uuid'] ?? '';   
                printf('<input type="text" size="40" value = "'.esc_attr($value).'" name="' . $this::option_key . '[general][otto_pixel_uuid]"/>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );


        # check box to toggle on and off disabling OTTO for logged in users
        add_settings_field(
            'otto_disable_on_loggedin',
            'Disable ' . $whitelabel_otto_name . ' for Logged in Users',
            function() use ($whitelabel_otto_name) {
                $otto_disable_on_loggedin = Metasync::get_option('general')['otto_disable_on_loggedin'] ?? '';
                printf(
                    '<input type="checkbox" id="otto_disable_on_loggedin" name="' . $this::option_key . '[general][otto_disable_on_loggedin]" value="true" %s />',
                    isset($otto_disable_on_loggedin) && $otto_disable_on_loggedin == 'true' ? 'checked' : ''
                );
                printf('<span class="description"> This disables '.$whitelabel_otto_name.' when logged in to allow editing original page contents</span>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        # check box to disable OTTO frontend toolbar
        add_settings_field(
            'otto_disable_preview_button',
            'Disable ' . $whitelabel_otto_name . ' Frontend Toolbar',
            function() use ($whitelabel_otto_name) {
                $otto_disable_toolbar = Metasync::get_option('general')['otto_disable_preview_button'] ?? false;
                printf(
                    '<input type="checkbox" id="otto_disable_preview_button" name="' . $this::option_key . '[general][otto_disable_preview_button]" value="true" %s />',
                    filter_var($otto_disable_toolbar, FILTER_VALIDATE_BOOLEAN) ? 'checked' : ''
                );
                printf('<span class="description"> Hide the entire frontend toolbar (status indicator, preview button, and debug button) on the frontend. %s functionality will still work, but the toolbar controls will be hidden.</span>', esc_html($whitelabel_otto_name));
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        # WP Rocket compatibility mode setting
        add_settings_field(
            'otto_wp_rocket_compat',
            'WP Rocket Compatibility',
            function() use ($whitelabel_otto_name) {
                $value = Metasync::get_option('general')['otto_wp_rocket_compat'] ?? 'auto';
                $wp_rocket_active = class_exists('WP_Rocket');

                echo '<select id="otto_wp_rocket_compat" name="' . $this::option_key . '[general][otto_wp_rocket_compat]">';
                echo '<option value="auto"' . selected($value, 'auto', false) . '>Auto (Recommended)</option>';
                echo '<option value="buffer"' . selected($value, 'buffer', false) . '>Buffer Mode (Faster)</option>';
                echo '<option value="http"' . selected($value, 'http', false) . '>HTTP Mode (Safer)</option>';
                echo '<option value="disable_otto"' . selected($value, 'disable_otto', false) . '>Disable ' . esc_html($whitelabel_otto_name) . ' when WP Rocket is active</option>';
                echo '</select>';

                if ($wp_rocket_active) {
                    echo '<p class="description" style="color: #0073aa; margin-top: 8px;">✓ WP Rocket detected - compatibility mode active</p>';
                }

                echo '<p class="description" style="margin-top: 8px;">';
                echo '<strong>Auto (Recommended):</strong> Allows both ' . esc_html($whitelabel_otto_name) . ' and WP Rocket to work together. Does not set DONOTCACHEPAGE unless required (Brizy pages, SG Optimizer conflicts).<br>';
                echo '<strong>Buffer Mode:</strong> Forces output buffering method. Fastest but may conflict with some configurations.<br>';
                echo '<strong>HTTP Mode:</strong> Forces internal HTTP fetch method. Slower but more compatible.<br>';
                echo '<strong>Disable ' . esc_html($whitelabel_otto_name) . ':</strong> Completely disables ' . esc_html($whitelabel_otto_name) . ' when WP Rocket is detected. Use if issues persist.';
                echo '</p>';
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        /**
         * BOT DETECTION SETTINGS FOR OTTO
         * @since 1.0.0
         */

        # Checkbox to disable OTTO for bot traffic
        add_settings_field(
            'otto_disable_for_bots',
            'Disable ' . $whitelabel_otto_name . ' for Bot Traffic',
            function() use ($whitelabel_otto_name) {
                $otto_disable_for_bots = Metasync::get_option('general')['otto_disable_for_bots'] ?? false;
                printf(
                    '<input type="checkbox" id="otto_disable_for_bots" name="' . $this::option_key . '[general][otto_disable_for_bots]" value="true" %s />',
                    filter_var($otto_disable_for_bots, FILTER_VALIDATE_BOOLEAN) ? 'checked' : ''
                );
                printf('<span class="description"> Skip %s processing for detected bots (search engines, crawlers, SEO tools) to reduce unnecessary API calls. View bot statistics in the Bot Statistics page under the SEO dropdown.</span>', esc_html($whitelabel_otto_name));
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        # Textarea for bot whitelist
        add_settings_field(
            'otto_bot_whitelist',
            'Bot Whitelist',
            function() use ($whitelabel_otto_name) {
                $otto_bot_whitelist = Metasync::get_option('general')['otto_bot_whitelist'] ?? '';
                printf(
                    '<textarea id="otto_bot_whitelist" name="' . $this::option_key . '[general][otto_bot_whitelist]" rows="5" cols="50" style="width: 100%%; max-width: 500px; font-family: monospace;">%s</textarea>',
                    esc_textarea($otto_bot_whitelist)
                );
                echo '<p class="description">';
                echo 'Enter bot names or user-agent patterns (one per line) that should <strong>always</strong> be processed by ' . esc_html($whitelabel_otto_name) . ', even when "Disable for Bots" is enabled.<br>';
                echo '<strong>Example:</strong><br>';
                echo 'Googlebot<br>';
                echo 'Bingbot';
                echo '</p>';
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        # Textarea for bot blacklist
        add_settings_field(
            'otto_bot_blacklist',
            'Bot Blacklist',
            function() use ($whitelabel_otto_name) {
                $otto_bot_blacklist = Metasync::get_option('general')['otto_bot_blacklist'] ?? '';
                printf(
                    '<textarea id="otto_bot_blacklist" name="' . $this::option_key . '[general][otto_bot_blacklist]" rows="5" cols="50" style="width: 100%%; max-width: 500px; font-family: monospace;">%s</textarea>',
                    esc_textarea($otto_bot_blacklist)
                );
                echo '<p class="description">';
                echo 'Enter bot names or user-agent patterns (one per line) that should <strong>always</strong> be blocked from ' . esc_html($whitelabel_otto_name) . ' processing, regardless of other settings.<br>';
                echo '<strong>Example:</strong><br>';
                echo 'BadBot<br>';
                echo 'MaliciousCrawler';
                echo '</p>';
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );


        # Link button to Bot Statistics page
        add_settings_field(
            'otto_bot_statistics_link',
            'Bot Detection Statistics',
            function() {
                printf(
                    '<a href="%s" class="button button-secondary"><span>🤖</span> View Bot Statistics</a>',
                    esc_url(admin_url('admin.php?page=' . self::$page_slug . '-bot-statistics'))
                );
                printf('<p class="description">View detailed bot detection statistics, breakdown by bot type, and unique bot entries with hit counts.</p>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        # END BOT DETECTION SETTINGS

        add_settings_field(
            'periodic_clear_ottopage_cache',
            'Clear Page Cache',
            function() {
                $periodic_clear_ottopage_cache = Metasync::get_option('general')['periodic_clear_ottopage_cache'] ?? 'default';
                printf('<select style = "width : 250px" name="' . $this::option_key . '[general][periodic_clear_ottopage_cache]" id="heading_style">');
                printf('<option value="24" '. selected($periodic_clear_ottopage_cache, '24', false) . '>Clear Daily</option>');
                printf('<option value="36" '. selected($periodic_clear_ottopage_cache, '36', false) . '>Clear Every 2 days</option>');
                printf('<option value="40" '. selected($periodic_clear_ottopage_cache, '40', false) . '>Clear Weekly</option>');
                printf('<option value="0"'.selected($periodic_clear_ottopage_cache, '0', false).'>Clear Monthly (Default)</option>');
                printf('</select>'); 

                # get last cleared timestamp
                $timestamp = get_option('metasync_refresh_all_caches')['pages'] ?? false;
                
                if($timestamp > 0){
                    printf(
                        '<p class="descriptionValue">last Cleared : '.date('y-m-d H:i:s', $timestamp).'</p>'
                    );
                }

            },
            self::$page_slug . '_otto_cache',
            $SECTION_METASYNC
        );

        add_settings_field(
            'periodic_clear_ottopost_cache',
            'Clear Post Cache',
            function() {
                $periodic_clear_ottopost_cache = Metasync::get_option('general')['periodic_clear_ottopost_cache'] ?? 'default';
                printf('<select style = "width : 250px" name="' . $this::option_key . '[general][periodic_clear_ottopost_cache]" id="heading_style">');
                printf('<option value="24" '. selected($periodic_clear_ottopost_cache, '24', false) . '>Clear Daily</option>');
                printf('<option value="36" '. selected($periodic_clear_ottopost_cache, '36', false) . '>Clear Every 2 days</option>');
                printf('<option value="40" '. selected($periodic_clear_ottopost_cache, '40', false) . '>Clear Weekly</option>');
                printf('<option value="0"'.selected($periodic_clear_ottopost_cache, '0', false).'>Clear Monthly (Default)</option>');
                printf('</select>'); 

                # get last cleared timestamp
                $timestamp = get_option('metasync_refresh_all_caches')['posts'] ?? false;
                
                if($timestamp > 0){
                    printf(
                        '<p class="descriptionValue">last Cleared : '.date('y-m-d H:i:s', $timestamp).'</p>'
                    );
                }
            },
            self::$page_slug . '_otto_cache',
            $SECTION_METASYNC
        );

        add_settings_field(
            'periodic_clear_otto_cache',
            'Clear all cache',
            function() {
                $periodic_clear_otto_cache = Metasync::get_option('general')['periodic_clear_otto_cache'] ?? 'default';
                printf('<select style = "width : 250px" name="' . $this::option_key . '[general][periodic_clear_otto_cache]" id="heading_style">');
                printf('<option value="24" '. selected($periodic_clear_otto_cache, '24', false) . '>Clear Daily</option>');
                printf('<option value="36" '. selected($periodic_clear_otto_cache, '36', false) . '>Clear Every 2 days</option>');
                printf('<option value="40" '. selected($periodic_clear_otto_cache, '40', false) . '>Clear Weekly</option>');
                printf('<option value="0"'.selected($periodic_clear_otto_cache, '0', false).'>Clear Monthly (Default)</option>');
                printf('</select>'); 

                # get last cleared timestamp
                $timestamp = get_option('metasync_refresh_all_caches')['general'] ?? false;
                
                if($timestamp > 0){
                    printf(
                        '<p class="descriptionValue">last Cleared : '.date('y-m-d H:i:s', $timestamp).'</p>'
                    );
                }

            },
            self::$page_slug . '_otto_cache',
            $SECTION_METASYNC
        );

        # END SERVER SIDE RENDERING SETTINGS

        // Meta Box Visibility Controls
        add_settings_field(
            'disable_common_robots_metabox',
            'Disable Common Robots Meta Box',
            function() {
                $disabled = Metasync::get_option('general')['disable_common_robots_metabox'] ?? false;
                printf(
                    '<input type="checkbox" id="disable_common_robots_metabox" name="' . $this::option_key . '[general][disable_common_robots_metabox]" value="1" %s />',
                    $disabled ? 'checked' : ''
                );
                printf('<span class="description"> Hide the Common Robots Meta box on post/page edit screens</span>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        add_settings_field(
            'disable_advance_robots_metabox',
            'Disable Advance Robots Meta Box',
            function() {
                $disabled = Metasync::get_option('general')['disable_advance_robots_metabox'] ?? false;
                printf(
                    '<input type="checkbox" id="disable_advance_robots_metabox" name="' . $this::option_key . '[general][disable_advance_robots_metabox]" value="1" %s />',
                    $disabled ? 'checked' : ''
                );
                printf('<span class="description"> Hide the Advance Robots Meta box on post/page edit screens</span>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        add_settings_field(
            'disable_redirection_metabox',
            'Disable Redirection Meta Box',
            function() {
                $disabled = Metasync::get_option('general')['disable_redirection_metabox'] ?? false;
                printf(
                    '<input type="checkbox" id="disable_redirection_metabox" name="' . $this::option_key . '[general][disable_redirection_metabox]" value="1" %s />',
                    $disabled ? 'checked' : ''
                );
                printf('<span class="description"> Hide the Redirection meta box on post/page edit screens</span>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        add_settings_field(
            'disable_canonical_metabox',
            'Disable Canonical Meta Box',
            function() {
                $disabled = Metasync::get_option('general')['disable_canonical_metabox'] ?? false;
                printf(
                    '<input type="checkbox" id="disable_canonical_metabox" name="' . $this::option_key . '[general][disable_canonical_metabox]" value="1" %s />',
                    $disabled ? 'checked' : ''
                );
                printf('<span class="description"> Hide the Canonical meta box on post/page edit screens</span>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        add_settings_field(
            'disable_social_opengraph_metabox',
            'Disable Social Media & Open Graph Meta Box',
            function() {
                $disabled = Metasync::get_option('general')['disable_social_opengraph_metabox'] ?? false;
                printf(
                    '<input type="checkbox" id="disable_social_opengraph_metabox" name="' . $this::option_key . '[general][disable_social_opengraph_metabox]" value="1" %s />',
                    $disabled ? 'checked' : ''
                );
                printf('<span class="description"> Hide the Social Media & Open Graph meta box on post/page edit screens</span>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        add_settings_field(
            'disable_schema_markup_metabox',
            'Disable Schema Markup Meta Box',
            function() {
                $disabled = Metasync::get_option('general')['disable_schema_markup_metabox'] ?? false;
                printf(
                    '<input type="checkbox" id="disable_schema_markup_metabox" name="' . $this::option_key . '[general][disable_schema_markup_metabox]" value="1" %s />',
                    $disabled ? 'checked' : ''
                );
                printf('<span class="description"> Hide the Schema Markup meta box on post/page edit screens</span>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        add_settings_field(
            'open_external_links',
            'Open External Links in New Tab/Window',
            function() {
                $enabled = Metasync::get_option('general')['open_external_links'] ?? false;
                printf(
                    '<input type="checkbox" id="open_external_links" name="' . $this::option_key . '[general][open_external_links]" value="1" %s />',
                    $enabled ? 'checked' : ''
                );
                printf('<span class="description"> Automatically add <code>target="_blank"</code> attribute to external links appearing in your posts, pages, and other post types when rendered by Otto. The attribute is applied when the url is displayed.</span>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        add_settings_field(
            'permalink_structure',
            'The Permalink setting of website',
            function() {
                
                $current_permalink_structure = get_option('permalink_structure');
                $current_rewrite_rules = get_option('rewrite_rules');
                // Check if the current permalink structure is set to "Plain"
                if (($current_permalink_structure == '/%post_id%/' || $current_permalink_structure == '') && $current_rewrite_rules == '') {
                    // Change the description message 
                    printf('<span class="description" style="color:#ff0000;opacity:1;">To ensure compatibility, Please Update your Permalink structure to any option other than "plain. For any Inquiries contact support <a href="' . get_admin_url() . 'options-permalink.php">Check Setting</a> </span>');
                } else {
                    printf('<span class="description" style="color:#008000;opacity:1;">Permalink is Okay </span>');
                }
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        # Add Hide Dashboard Framework setting
        add_settings_field(
            'hide_dashboard_framework',
            'Hide Dashboard',
            function() {
                $hide_dashboard = Metasync::get_option('general')['hide_dashboard_framework'] ?? '';
                printf(
                    '<input type="checkbox" id="hide_dashboard_framework" name="' . $this::option_key . '[general][hide_dashboard_framework]" value="true" %s />',
                    isset($hide_dashboard) && $hide_dashboard == 'true' ? 'checked' : ''
                );
                printf('<span class="description"> Hide the %s dashboard</span>', esc_html($this->get_effective_menu_title()));
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        // REMOVED (CVE-2025-14386): disable_single_signup_login setting was removed
        // Legacy SSO login via ?metasync_auth_token= no longer exists

        # Adding the "Show Admin Bar Status" setting
        add_settings_field(
            'show_admin_bar_status',
            'Show ' . $this->get_effective_menu_title() . ' Status in Admin Bar',
            function() {
                $show_admin_bar = Metasync::get_option('general')['show_admin_bar_status'] ?? true;
                printf(
                    '<input type="checkbox" id="show_admin_bar_status" name="' . $this::option_key . '[general][show_admin_bar_status]" value="true" %s />',
                    $show_admin_bar ? 'checked' : ''
                );
                printf('<span class="description">Show the %s status indicator in the WordPress admin bar.</span>', esc_html(Metasync::get_effective_plugin_name()));
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        # Adding the "Enable Auto-updates" setting
        add_settings_field(
            'enable_auto_updates',
            'Enable Automatic Updates',
            function() {
                $enable_auto_updates = Metasync::get_option('general')['enable_auto_updates'] ?? false;
                printf(
                    '<input type="checkbox" id="enable_auto_updates" name="' . $this::option_key . '[general][enable_auto_updates]" value="true" %s />',
                    $enable_auto_updates ? 'checked' : ''
                );
                printf('<span class="description">Allow WordPress to automatically update this plugin when new versions are available.</span>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        # Add User Role Sync Setting
        add_settings_field(
            'content_genius_sync_roles',
            'Content Genius User Roles to Sync',
            function() {
                $selected_roles = Metasync::get_option('general')['content_genius_sync_roles'] ?? array();
                
                # If it's a string (single role from old version), convert to array
                if (!is_array($selected_roles)) {
                    $selected_roles = array($selected_roles);
                }
                
                # Get all WordPress user roles safely
                if (!function_exists('wp_roles')) {
                    require_once(ABSPATH . 'wp-admin/includes/user.php');
                }
                global $wp_roles;
                
                # Safety check for $wp_roles
                if (!isset($wp_roles) || !is_object($wp_roles)) {
                    $wp_roles = new WP_Roles();
                }
                
                $all_roles = $wp_roles->roles;
                
                # Start container
                ?>
                <div class="metasync-role-selector-container">
                    
                    <!-- All Roles Option -->
                    <label class="metasync-role-option-all">
                        <input type="checkbox" 
                               name="<?php echo esc_attr($this::option_key); ?>[general][content_genius_sync_roles][]" 
                               value="all" 
                               <?php checked(empty($selected_roles) || in_array('all', $selected_roles), true); ?> />
                        <strong>All Roles</strong>
                    </label>
                    
                    <!-- Divider -->
                    <hr class="metasync-role-divider">
                    
                    <?php
                    # Display each role as a checkbox
                    if (!empty($all_roles) && is_array($all_roles)) {
                        foreach ($all_roles as $role_key => $role_details) {
                            # Safety check for role data
                            if (!isset($role_details['name'])) {
                                continue;
                            }
                            
                            $is_checked = in_array($role_key, $selected_roles);
                            $role_name = translate_user_role($role_details['name']);
                            ?>
                            <label class="metasync-role-option">
                                <input type="checkbox" 
                                       name="<?php echo esc_attr($this::option_key); ?>[general][content_genius_sync_roles][]" 
                                       value="<?php echo esc_attr($role_key); ?>" 
                                       <?php checked($is_checked, true); ?> />
                                <span class="metasync-role-label"><?php echo esc_html($role_name); ?></span>
                            </label>
                            <?php
                        }
                    }
                    ?>
                    
                </div>
                
                <!-- Description -->
                <p class="description" style="margin-top: 10px;">
                    <strong>ℹ️ How it works:</strong> 
                    Select which user roles should be synced with Content Genius. 
                    If <strong>"All Roles"</strong> is selected or none are selected, all users will be synced. 
                    Otherwise, only users with the selected roles will be included in the sync.
                </p>
                <?php
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );
        add_settings_field(
            'import_external_data',
            'Import settings and data from SEO Plugins',
            function() {
                printf(
                    '<a href="%s" class="button button-secondary"><span>📥</span> Import from SEO Plugins</a>',
                    esc_url(admin_url('admin.php?page=metasync-import-external'))
                );
                printf('<p class="description">Import settings and data from other SEO plugins (Yoast, Rank Math, AIOSEO, etc).</p>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );



        /*
        add field to save color for elementor and assign custom color to the heading
        */
        if(is_admin()){
        add_settings_field(
            'white_label_plugin_name',
            'Plugin Name',
           function(){           
            $value = Metasync::get_option('general')['white_label_plugin_name'] ?? '';   
            printf('<input type="text" name="' . $this::option_key . '[general][white_label_plugin_name]" value="' . esc_attr($value) . '" maxlength="16" />');
            printf('<p class="description">This name will be used for general plugin branding (WordPress menus, page titles, and system messages). Maximum 16 characters.</p>');
           },
           self::$page_slug . '_branding',
                $SECTION_METASYNC
        );
        
        add_settings_field(
            'whitelabel_otto_name',
                'OTTO Name',
            function(){
                $value = Metasync::get_option('general')['whitelabel_otto_name'] ?? '';
                printf('<input type="text" name="' . $this::option_key . '[general][whitelabel_otto_name]" value="' . esc_attr($value) . '" />');
                $example_name = !empty($value) ? $value : 'OTTO';
                printf('<p class="description">This name will be used for OTTO feature references (e.g., "Enable %s Server Side Rendering").</p>', esc_html($example_name));
            },
            self::$page_slug . '_branding',
            $SECTION_METASYNC
        );
        
        add_settings_field(
            'whitelabel_logo_url',
            'Logo URL',
            function(){
                $whitelabel_settings = Metasync::get_whitelabel_settings();
                $value = $whitelabel_settings['logo'] ?? '';   
                printf('<input type="url" name="' . $this::option_key . '[whitelabel][logo]" value="' . esc_attr($value) . '" size="60" />');
                printf('<p class="description">Enter the URL of your logo image. Leave blank to use the default %s logo.</p>', esc_html(Metasync::get_effective_plugin_name()));
            },
            self::$page_slug . '_branding',
            $SECTION_METASYNC
        );
        
                add_settings_field(
            'whitelabel_domain_url',
            'Dashboard URL',
            function(){
                $whitelabel_settings = Metasync::get_whitelabel_settings();
                $value = $whitelabel_settings['domain'] ?? '';   
                printf('<input type="url" name="' . $this::option_key . '[whitelabel][domain]" value="' . esc_attr($value) . '" size="60" />');
                printf('<p class="description">Enter your whitelabel dashboard URL (e.g., https://yourdashboard.com). Used for branding purposes.</p>');
           },
           self::$page_slug . '_branding',
                $SECTION_METASYNC
        );
        add_settings_field(
            'white_label_plugin_description',
            'Plugin Description',
            function(){
                $value = Metasync::get_option('general')['white_label_plugin_description'] ?? '';   
                printf('<input type="text" name="' . $this::option_key . '[general][white_label_plugin_description]" value="' . esc_attr($value) . '" />');      
               },
            self::$page_slug . '_branding',
            $SECTION_METASYNC
        );
    
        add_settings_field(
            'white_label_plugin_author',
            'Author',
           function(){
            $value = Metasync::get_option('general')['white_label_plugin_author'] ?? '';   
            printf('<input type="text" name="' . $this::option_key . '[general][white_label_plugin_author]" value="' . esc_attr($value) . '" />');  
           },
            self::$page_slug . '_branding',
            $SECTION_METASYNC
        );
            
        add_settings_field(
            'white_label_plugin_author_uri',
            'Author URI',
            function(){
                $value = Metasync::get_option('general')['white_label_plugin_author_uri'] ?? '';   
               # printf('<input type="text" name="' . $this::option_key . '[general][white_label_plugin_author_uri]" value="' . esc_attr($value) . '" />');
               # Fixed printf usage
                printf('<input type="text" name="%s" value="%s" />',  esc_attr($this::option_key . '[general][white_label_plugin_author_uri]'),  esc_attr($value) );
              
            },
            self::$page_slug . '_branding',
            $SECTION_METASYNC
        );
        add_settings_field(
            'white_label_plugin_uri',
            'Plugin URI',
            function(){
                $value = Metasync::get_option('general')['white_label_plugin_uri'] ?? ''; // New option for Plugin URI
                printf('<input type="text" name="' . $this::option_key . '[general][white_label_plugin_uri]" value="' . esc_attr($value) . '" />');
            },
            self::$page_slug . '_branding',
            $SECTION_METASYNC
        );
            register_setting($this::option_group, // Option group
            $this::option_key, // Option name
            array($this, 'sanitize') // Sanitize
            );  

            // DEPRECATED: Menu Name and Menu Title fields removed
            // All branding now uses Plugin Name value for consistency
            add_settings_field(
                'white_label_plugin_menu_slug',
                'Menu Slug',
                function(){
                    $value = Metasync::get_option('general')['white_label_plugin_menu_slug'] ?? '';   
                    printf('<input type="text" name="' . $this::option_key . '[general][white_label_plugin_menu_slug]" value="' . esc_attr($value) . '" />');        
                },
                self::$page_slug . '_branding',
                $SECTION_METASYNC
            );
            add_settings_field(
                'white_label_plugin_menu_icon',
                'Menu Icon',
                function(){
                    $value = Metasync::get_option('general')['white_label_plugin_menu_icon'] ?? '';   
                    printf('<input type="text" name="' . $this::option_key . '[general][white_label_plugin_menu_icon]" value="' . esc_attr($value) . '" />');
                },
                self::$page_slug . '_branding',
                $SECTION_METASYNC
            );
        }
        // HIDDEN: Choose Style Option setting
        /*
        add_settings_field(
            'enabled_plugin_css',
            'Choose Style Option',
            function() {
                $enabled_plugin_css = Metasync::get_option('general')['enabled_plugin_css'] ?? '';                
                
                // Output radio button for Default Style.css active
              
                    printf(
                        '<input type="radio" id="enable_default" name="' . $this::option_key . '[general][enabled_plugin_css]" value="default" %s />',
                        ($enabled_plugin_css == 'default'||$enabled_plugin_css =='') ? 'checked' : ''
                    );
                    printf('<label for="enable_default">Default</label><br>');
                
        
                // Output radio button for Metasync Style
                printf(
                    '<input type="radio" id="enable_metasync" name="' . $this::option_key . '[general][enabled_plugin_css]" value="metasync" %s  />',
                    ($enabled_plugin_css == 'metasync') ? 'checked' : ''
                );
                printf('<label for="enable_metasync">Metasync Style</label>');
        
                printf('<p class="description"> Choose the default page Style Sheet: Default or MetaSync.</p>');
            },
            self::$page_slug . '_branding',
            $SECTION_METASYNC
        );
        */

        add_settings_field(
            'enabled_elementor_plugin_css',
            'Choose Elementor Font Style',
            function() {
                $enabled_elementor_plugin_css = Metasync::get_option('general')['enabled_elementor_plugin_css'] ?? 'default';
                printf('<select name="' . $this::option_key . '[general][enabled_elementor_plugin_css]" id="heading_style">');
                printf('<option value="default"'.selected($enabled_elementor_plugin_css, 'default', false).'>Default</option>');
                printf('<option value="custom" '. selected($enabled_elementor_plugin_css, 'custom', false) . '>Custom</option>');
                printf('</select>'); 
            },
            self::$page_slug . '_branding',
            $SECTION_METASYNC
        );
        add_settings_field(
            'enabled_elementor_plugin_css_color',
            'Choose Elementor Font Color',
            function() {
                $enabled_elementor_plugin_css_color = Metasync::get_option('general')['enabled_elementor_plugin_css_color'] ?? '#000000';                          
                printf('<input type="color" id="elementor_default_color_metasync" name="' . $this::option_key . '[general][enabled_elementor_plugin_css_color]" value="'.$enabled_elementor_plugin_css_color.'">');       
            },
            self::$page_slug . '_branding',
            $SECTION_METASYNC
        );

        add_settings_field(
            'whitelabel_settings_password',
            'Settings Password',
            function() {
                $whitelabel_settings = Metasync::get_whitelabel_settings();
                $value = $whitelabel_settings['settings_password'] ?? '';
                printf('<input type="password" name="' . $this::option_key . '[whitelabel][settings_password]" value="' . esc_attr($value) . '" size="30" autocomplete="new-password" />');
                printf('<p class="description">Set a custom password to protect the branding settings section.</p>');
            },
            self::$page_slug . '_branding',
            $SECTION_METASYNC
        );

        add_settings_field(
            'whitelabel_recovery_email',
            'Recovery Email',
            function() {
                $whitelabel_settings = Metasync::get_whitelabel_settings();
                $value = $whitelabel_settings['recovery_email'] ?? '';
                $has_password = !empty($whitelabel_settings['settings_password']);
                printf('<input type="email" name="' . $this::option_key . '[whitelabel][recovery_email]" value="' . esc_attr($value) . '" size="30" autocomplete="email" %s />', $has_password ? 'required' : '');
                printf('<p class="description">Email address to receive password recovery. <strong>Required when password is set.</strong></p>');
            },
            self::$page_slug . '_branding',
            $SECTION_METASYNC
        );

        // Note: Plugin Settings (hide checkboxes) are now rendered manually in a separate dashboard-card
        // See the whitelabel tab rendering section for the Plugin Settings card


    }

    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize($input)
    {
        $new_input = Metasync::get_option();
        $whitelabel_validation_failed = false;

        # Determine which tab is being submitted (same logic as AJAX handler)
        # Check POST first (from AJAX), then GET (from form action URL)
        $active_tab = 'general';
        if (isset($_POST['active_tab'])) {
            $active_tab = sanitize_text_field($_POST['active_tab']);
        } elseif (isset($_GET['tab'])) {
            $active_tab = sanitize_text_field($_GET['tab']);
        } elseif (isset($_POST['_wp_http_referer'])) {
            # Parse referer URL to get tab parameter
            $referer = wp_parse_url($_POST['_wp_http_referer']);
            if (isset($referer['query'])) {
                parse_str($referer['query'], $referer_params);
                if (isset($referer_params['tab'])) {
                    $active_tab = sanitize_text_field($referer_params['tab']);
                }
            }
        }
        $general_tab_submitted = ($active_tab === 'general');

        // General Settings
        // if (isset($input['general']['sitemaps']['enabled'])) {
        //     $new_input['general']['sitemaps']['enabled'] = boolval($input['general']['sitemaps']['enabled']);
        // }
        // if (isset($input['general']['sitemaps']['exclude'])) {
        //     $new_input['general']['sitemaps']['exclude'] = sanitize_text_field($input['general']['sitemaps']['exclude']);
        // }
        if (isset($input['general']['apikey'])) {
            $new_input['general']['apikey'] = sanitize_text_field($input['general']['apikey']);
        }
        // if (isset($input['general']['linkgraph_token'])) {
        //     $new_input['general']['linkgraph_token'] = sanitize_text_field($input['general']['linkgraph_token']);
        // }
        // Note: enable_schema and enable_metadesc are always enabled by default

        // Meta Box Visibility Settings
        // Handle checkboxes - these need special handling because unchecked boxes don't send data
        // IMPORTANT: Only process if general tab is being submitted to avoid resetting checkboxes
        // when saving from other tabs (whitelabel, advanced)
        if (isset($input['general']) && $general_tab_submitted) {
            $checkbox_fields = [
                'disable_common_robots_metabox',
                'disable_advance_robots_metabox',
                'disable_redirection_metabox',
                'disable_canonical_metabox',
                'disable_social_opengraph_metabox',
                'disable_schema_markup_metabox',
                'open_external_links'
            ];

            foreach ($checkbox_fields as $field) {
                if (isset($input['general'][$field])) {
                    // Sanitize as boolean - convert '1' to true, anything else to false
                    $new_input['general'][$field] = filter_var($input['general'][$field], FILTER_VALIDATE_BOOLEAN);
                } else {
                    // Checkbox not set means unchecked - set to false
                    $new_input['general'][$field] = false;
                }
            }
        }
        // else: preserve existing checkbox values when submitting from other tabs

        // Site Verification Settings
        if (isset($input['searchengines']['bing_site_verification'])) {
            $new_input['searchengines']['bing_site_verification'] = sanitize_text_field($input['searchengines']['bing_site_verification']);
        }
        if (isset($input['searchengines']['baidu_site_verification'])) {
            $new_input['searchengines']['baidu_site_verification'] = sanitize_text_field($input['searchengines']['baidu_site_verification']);
        }
        if (isset($input['searchengines']['alexa_site_verification'])) {
            $new_input['searchengines']['alexa_site_verification'] = sanitize_text_field($input['searchengines']['alexa_site_verification']);
        }
        if (isset($input['searchengines']['yandex_site_verification'])) {
            $new_input['searchengines']['yandex_site_verification'] = sanitize_text_field($input['searchengines']['yandex_site_verification']);
        }
        if (isset($input['searchengines']['google_site_verification'])) {
            $new_input['searchengines']['google_site_verification'] = sanitize_text_field($input['searchengines']['google_site_verification']);
        }
        if (isset($input['searchengines']['pinterest_site_verification'])) {
            $new_input['searchengines']['pinterest_site_verification'] = sanitize_text_field($input['searchengines']['pinterest_site_verification']);
        }
        if (isset($input['searchengines']['norton_save_site_verification'])) {
            $new_input['searchengines']['norton_save_site_verification'] = sanitize_text_field($input['searchengines']['norton_save_site_verification']);
        }

        // Local Business SEO Settings
        if (isset($input['localseo']['local_seo_person_organization'])) {
            $new_input['localseo']['local_seo_person_organization'] = sanitize_text_field($input['localseo']['local_seo_person_organization']);
        }
        if (isset($input['localseo']['local_seo_name'])) {
            $new_input['localseo']['local_seo_name'] = sanitize_text_field($input['localseo']['local_seo_name']);
        }
        if (isset($input['localseo']['local_seo_logo'])) {
            $new_input['localseo']['local_seo_logo'] = sanitize_url($input['localseo']['local_seo_logo']);
        }
        if (isset($input['localseo']['local_seo_url'])) {
            $new_input['localseo']['local_seo_url'] = sanitize_url($input['localseo']['local_seo_url']);
        }
        if (isset($input['localseo']['local_seo_email'])) {
            $new_input['localseo']['local_seo_email'] = sanitize_email($input['localseo']['local_seo_email']);
        }
        if (isset($input['localseo']['local_seo_phone'])) {
            $new_input['localseo']['local_seo_phone'] = sanitize_text_field($input['localseo']['local_seo_phone']);
        }
        if (isset($input['localseo']['address']['street'])) {
            $new_input['localseo']['address']['street'] = sanitize_text_field($input['localseo']['address']['street']);
        }
        if (isset($input['localseo']['address']['locality'])) {
            $new_input['localseo']['address']['locality'] = sanitize_text_field($input['localseo']['address']['locality']);
        }
        if (isset($input['localseo']['address']['region'])) {
            $new_input['localseo']['address']['region'] = sanitize_text_field($input['localseo']['address']['region']);
        }
        if (isset($input['localseo']['address']['postancode'])) {
            $new_input['localseo']['address']['postancode'] = sanitize_text_field($input['localseo']['address']['postancode']);
        }
        if (isset($input['localseo']['address']['country'])) {
            $new_input['localseo']['address']['country'] = sanitize_text_field($input['localseo']['address']['country']);
        }
        if (isset($input['localseo']['local_seo_business_type'])) {
            $new_input['localseo']['local_seo_business_type'] = sanitize_text_field($input['localseo']['local_seo_business_type']);
        }
        if (isset($input['localseo']['local_seo_hours_format'])) {
            $new_input['localseo']['local_seo_hours_format'] = sanitize_text_field($input['localseo']['local_seo_hours_format']);
        }
        if (isset($input['localseo']['days'])) {
            $new_input['localseo']['days'] = sanitize_text_field($input['localseo']['days']);
        }
        if (isset($input['localseo']['times'])) {
            $new_input['localseo']['times'] = sanitize_text_field($input['localseo']['times']);
        }
        if (isset($input['localseo']['phonetype'])) {
            $new_input['localseo']['phonetype'] = sanitize_text_field($input['localseo']['phonetype']);
        }
        if (isset($input['localseo']['phonenumber'])) {
            $new_input['localseo']['phonenumber'] = sanitize_text_field($input['localseo']['phonenumber']);
        }
        if (isset($input['localseo']['local_seo_price_range'])) {
            $new_input['localseo']['local_seo_price_range'] = sanitize_text_field($input['localseo']['local_seo_price_range']);
        }
        if (isset($input['localseo']['local_seo_about_page'])) {
            $new_input['localseo']['local_seo_about_page'] = sanitize_text_field($input['localseo']['local_seo_about_page']);
        }
        if (isset($input['localseo']['local_seo_contact_page'])) {
            $new_input['localseo']['local_seo_contact_page'] = sanitize_text_field($input['localseo']['local_seo_contact_page']);
        }
        if (isset($input['localseo']['local_seo_map_key'])) {
            $new_input['localseo']['local_seo_map_key'] = sanitize_text_field($input['localseo']['local_seo_map_key']);
        }
        if (isset($input['localseo']['local_seo_geo_coordinates'])) {
            $new_input['localseo']['local_seo_geo_coordinates'] = sanitize_text_field($input['localseo']['local_seo_geo_coordinates']);
        }

        // Code Snippets Settings
        if (isset($input['codesnippets']['header_snippet'])) {
            $new_input['codesnippets']['header_snippet'] = sanitize_text_field($input['codesnippets']['header_snippet']);
        }
        if (isset($input['codesnippets']['footer_snippet'])) {
            $new_input['codesnippets']['footer_snippet'] = sanitize_text_field($input['codesnippets']['footer_snippet']);
        }


        // Optimal Settings
        if (isset($input['optimal_settings']['no_index_posts'])) {
            $new_input['optimal_settings']['no_index_posts'] = boolval($input['optimal_settings']['no_index_posts']);
        }
        if (isset($input['optimal_settings']['no_follow_links'])) {
            $new_input['optimal_settings']['no_follow_links'] = boolval($input['optimal_settings']['no_follow_links']);
        }
        if (isset($input['optimal_settings']['open_external_links'])) {
            $new_input['optimal_settings']['open_external_links'] = boolval($input['optimal_settings']['open_external_links']);
        }
        if (isset($input['optimal_settings']['add_alt_image_tags'])) {
            $new_input['optimal_settings']['add_alt_image_tags'] = boolval($input['optimal_settings']['add_alt_image_tags']);
        }
        if (isset($input['optimal_settings']['add_title_image_tags'])) {
            $new_input['optimal_settings']['add_title_image_tags'] = boolval($input['optimal_settings']['add_title_image_tags']);
        }
        // if (isset($input['optimal_settings']['sitemap_post_types'])) {
        //     $new_input['optimal_settings']['sitemap_post_types'] = array_map('sanitize_title', $input['optimal_settings']['sitemap_post_types']);
        // }
        // if (isset($input['optimal_settings']['sitemap_taxonomy_types'])) {
        //     $new_input['optimal_settings']['sitemap_taxonomy_types'] = array_map('sanitize_title', $input['optimal_settings']['sitemap_taxonomy_types']);
        // }

        // Site Information - Optimal Settings
        if (isset($input['optimal_settings']['site_info']['type'])) {
            $new_input['optimal_settings']['site_info']['type'] = sanitize_text_field($input['optimal_settings']['site_info']['type']);
        }
        if (isset($input['optimal_settings']['site_info']['business_type'])) {
            $new_input['optimal_settings']['site_info']['business_type'] = sanitize_text_field($input['optimal_settings']['site_info']['business_type']);
        }
        if (isset($input['optimal_settings']['site_info']['company_name'])) {
            $new_input['optimal_settings']['site_info']['company_name'] = sanitize_text_field($input['optimal_settings']['site_info']['company_name']);
        }
        if (isset($input['optimal_settings']['site_info']['google_logo'])) {
            $new_input['optimal_settings']['site_info']['google_logo'] = sanitize_url($input['optimal_settings']['site_info']['google_logo']);
        }
        if (isset($input['optimal_settings']['site_info']['social_share_image'])) {
            $new_input['optimal_settings']['site_info']['social_share_image'] = sanitize_url($input['optimal_settings']['site_info']['social_share_image']);
        }

        // Common Setting - Global Settings
        // Support both new (meta) and old (mata) field names for backward compatibility
        $common_robots_field = isset($input['common_robots_meta']) ? 'common_robots_meta' : 'common_robots_mata';

        if (isset($input[$common_robots_field]['index'])) {
            $new_input['common_robots_meta']['index'] = boolval($input[$common_robots_field]['index']);
        }
        if (isset($input[$common_robots_field]['noindex'])) {
            $new_input['common_robots_meta']['noindex'] = boolval($input[$common_robots_field]['noindex']);
        }
        if (isset($input[$common_robots_field]['nofollow'])) {
            $new_input['common_robots_meta']['nofollow'] = boolval($input[$common_robots_field]['nofollow']);
        }
        if (isset($input[$common_robots_field]['noarchive'])) {
            $new_input['common_robots_meta']['noarchive'] = boolval($input[$common_robots_field]['noarchive']);
        }
        if (isset($input[$common_robots_field]['noimageindex'])) {
            $new_input['common_robots_meta']['noimageindex'] = boolval($input[$common_robots_field]['noimageindex']);
        }
        if (isset($input[$common_robots_field]['nosnippet'])) {
            $new_input['common_robots_meta']['nosnippet'] = boolval($input[$common_robots_field]['nosnippet']);
        }

        // Advance Setting - Global Settings
        // Support both new (meta) and old (mata) field names for backward compatibility
        $advance_robots_field = isset($input['advance_robots_meta']) ? 'advance_robots_meta' : 'advance_robots_mata';

        if (isset($input[$advance_robots_field]['max-snippet']['enable'])) {
            $new_input['advance_robots_meta']['max-snippet']['enable'] = boolval($input[$advance_robots_field]['max-snippet']['enable']);
        }
        if (isset($input[$advance_robots_field]['max-snippet']['length'])) {
            $new_input['advance_robots_meta']['max-snippet']['length'] = sanitize_text_field($input[$advance_robots_field]['max-snippet']['length']);
        }
        if (isset($input[$advance_robots_field]['max-video-preview']['enable'])) {
            $new_input['advance_robots_meta']['max-video-preview']['enable'] = boolval($input[$advance_robots_field]['max-video-preview']['enable']);
        }
        if (isset($input[$advance_robots_field]['max-video-preview']['length'])) {
            $new_input['advance_robots_meta']['max-video-preview']['length'] = sanitize_text_field($input[$advance_robots_field]['max-video-preview']['length']);
        }
        if (isset($input[$advance_robots_field]['max-image-preview']['enable'])) {
            $new_input['advance_robots_meta']['max-image-preview']['enable'] = boolval($input[$advance_robots_field]['max-image-preview']['enable']);
        }
        if (isset($input[$advance_robots_field]['max-image-preview']['length'])) {
            $new_input['advance_robots_meta']['max-image-preview']['length'] = sanitize_text_field($input[$advance_robots_field]['max-image-preview']['length']);
        }

        // Social meta settings
        if (isset($input['social_meta']['facebook_page_url'])) {
            $new_input['social_meta']['facebook_page_url'] = sanitize_text_field($input['social_meta']['facebook_page_url']);
        }
        if (isset($input['social_meta']['facebook_authorship'])) {
            $new_input['social_meta']['facebook_authorship'] = sanitize_text_field($input['social_meta']['facebook_authorship']);
        }
        if (isset($input['social_meta']['facebook_admin'])) {
            $new_input['social_meta']['facebook_admin'] = sanitize_text_field($input['social_meta']['facebook_admin']);
        }
        if (isset($input['social_meta']['facebook_app'])) {
            $new_input['social_meta']['facebook_app'] = sanitize_text_field($input['social_meta']['facebook_app']);
        }
        if (isset($input['social_meta']['facebook_secret'])) {
            $new_input['social_meta']['facebook_secret'] = sanitize_text_field($input['social_meta']['facebook_secret']);
        }
        if (isset($input['social_meta']['twitter_username'])) {
            $new_input['social_meta']['twitter_username'] = sanitize_text_field($input['social_meta']['twitter_username']);
        }

        # Handle whitelabel URL fields with improved empty value handling
        if (isset($input['whitelabel'])) {
            // Get existing whitelabel settings first
            $existing_whitelabel = Metasync::get_option()['whitelabel'] ?? [];

            // Whitelabel partial-save validation: require at least one core field when any whitelabel field is filled
            $general_input = $input['general'] ?? [];
            $plugin_name = isset($general_input['white_label_plugin_name']) ? trim((string) $general_input['white_label_plugin_name']) : '';
            $logo = isset($input['whitelabel']['logo']) ? trim((string) $input['whitelabel']['logo']) : '';
            $author = isset($general_input['white_label_plugin_author']) ? trim((string) $general_input['white_label_plugin_author']) : '';
            $author_uri = isset($general_input['white_label_plugin_author_uri']) ? trim((string) $general_input['white_label_plugin_author_uri']) : '';
            $plugin_uri = isset($general_input['white_label_plugin_uri']) ? trim((string) $general_input['white_label_plugin_uri']) : '';
            $domain = isset($input['whitelabel']['domain']) ? trim((string) $input['whitelabel']['domain']) : '';
            $description = isset($general_input['white_label_plugin_description']) ? trim((string) $general_input['white_label_plugin_description']) : '';

            $has_core_field = (!empty($plugin_name) || (!empty($logo) && filter_var($logo, FILTER_VALIDATE_URL)) || !empty($author));
            $has_optional_only = (!empty($author_uri) || !empty($plugin_uri) || (!empty($domain) && filter_var($domain, FILTER_VALIDATE_URL)) || !empty($description));

            if ($has_optional_only && !$has_core_field) {
                add_settings_error(
                    'metasync_options',
                    'whitelabel_partial',
                    'Add at least one of: Plugin Name, Logo URL, or Author to save whitelabel settings.',
                    'error'
                );
                $new_input['whitelabel'] = $existing_whitelabel;
                $whitelabel_validation_failed = true;
            } else {
            // Initialize whitelabel array based on existing settings
            $new_input['whitelabel'] = $existing_whitelabel;

            // Handle logo field (explicitly handle clearing)
            if (isset($input['whitelabel']['logo'])) {
                $logo_value = trim($input['whitelabel']['logo']);
                
                if (!empty($logo_value) && filter_var($logo_value, FILTER_VALIDATE_URL)) {
                    $new_input['whitelabel']['logo'] = esc_url_raw($logo_value);
                
                } else {
                    // Empty value submitted - clear the logo
                    $new_input['whitelabel']['logo'] = '';
                
                }
            }
            
            // Handle domain field (explicitly handle clearing)  
            if (isset($input['whitelabel']['domain'])) {
                $domain_value = trim($input['whitelabel']['domain']);
                
                if (!empty($domain_value) && filter_var($domain_value, FILTER_VALIDATE_URL)) {
                    $new_input['whitelabel']['domain'] = esc_url_raw($domain_value);
                    
                } else {
                    // Empty value submitted - clear the domain
                    $old_domain = $existing_whitelabel['domain'] ?? '';
                    $new_input['whitelabel']['domain'] = '';
                    
                    
                    // If domain was cleared, trigger heartbeat recheck to use default domain
                    if (!empty($old_domain)) {
                        
                        // Set flag to trigger heartbeat check after settings are saved
                        $new_input['_trigger_heartbeat_after_save'] = 'Domain cleared from: ' . $old_domain;
                    }
                }
            }
            
            // Handle settings password field
            // Note: Password might have been processed early in handle_whitelabel_password_early()
            if (isset($input['whitelabel']['settings_password'])) {
                $password_value = trim($input['whitelabel']['settings_password']);
                // Store password securely (could be hashed if needed)
                $new_input['whitelabel']['settings_password'] = sanitize_text_field($password_value);
            } else {
                // Preserve existing password if not submitted (password fields might be empty on form submission)
                if (isset($existing_whitelabel['settings_password'])) {
                    $new_input['whitelabel']['settings_password'] = $existing_whitelabel['settings_password'];
                }
            }

            // Handle recovery email field
            if (isset($input['whitelabel']['recovery_email'])) {
                $recovery_email = trim($input['whitelabel']['recovery_email']);
                if (!empty($recovery_email)) {
                    $sanitized_email = sanitize_email($recovery_email);
                    if (is_email($sanitized_email)) {
                        $new_input['whitelabel']['recovery_email'] = $sanitized_email;
                    } else {
                        add_settings_error(
                            'metasync_messages',
                            'metasync_message',
                            __('Invalid recovery email address.', 'metasync'),
                            'error'
                        );
                    }
                } else {
                    $new_input['whitelabel']['recovery_email'] = '';
                }
            } else {
                // Preserve existing recovery email if not submitted
                if (isset($existing_whitelabel['recovery_email'])) {
                    $new_input['whitelabel']['recovery_email'] = $existing_whitelabel['recovery_email'];
                }
            }

            // Validate that recovery email is set when password is set
            $has_password = !empty($new_input['whitelabel']['settings_password']);
            $has_recovery_email = !empty($new_input['whitelabel']['recovery_email']);
            if ($has_password && !$has_recovery_email) {
                add_settings_error(
                    'metasync_messages',
                    'metasync_message',
                    __('Password Recovery Email is required when a password is set.', 'metasync'),
                    'error'
                );
                // Clear password if recovery email is not provided
                $new_input['whitelabel']['settings_password'] = '';
            }

            // Process access control settings
            if (isset($input['whitelabel']['access_control'])) {
                $new_input['whitelabel']['access_control'] = Metasync_Access_Control::sanitize_access_control($input['whitelabel']['access_control']);
            }

            // Update timestamp when whitelabel settings change
            $new_input['whitelabel']['updated_at'] = time();
            }
        } else {
            // No whitelabel data in submission - check if user wants to clear existing settings
            $existing_whitelabel = Metasync::get_option()['whitelabel'] ?? [];
            $has_existing_whitelabel = !empty($existing_whitelabel['domain']) || !empty($existing_whitelabel['logo']);
            
            if ($has_existing_whitelabel) {
                // User cleared all whitelabel fields - reset whitelabel settings
                
                $new_input['whitelabel'] = [
                    'is_whitelabel' => false,
                    'domain' => '',
                    'logo' => '', 
                    'company_name' => '',
                    'updated_at' => time()
                ];
                
                
                
                // When whitelabel domain is cleared, trigger immediate heartbeat check
                // This ensures the system switches back to using the correct default domain
                if (!empty($existing_whitelabel['domain'])) {
                    
                    // Clear heartbeat cache to force using new domain on next check
                    delete_transient('metasync_heartbeat_status_cache');
                    
                    // Trigger immediate check with new domain
                    do_action('metasync_trigger_immediate_heartbeat', 'Whitelabel settings cleared - domain changed to default');
                }
            } else {
                // No existing whitelabel settings and none submitted - nothing to do
                #error_log('Whitelabel Settings: No whitelabel data in form submission and none exist - no action needed');
            }
        }

        // Indexation Control Settings
        if (isset($input['seo_controls']['index_date_archives'])) {
            $new_input['seo_controls']['index_date_archives'] = boolval($input['seo_controls']['index_date_archives']);
        }
        if (isset($input['seo_controls']['index_tag_archives'])) {
            $new_input['seo_controls']['index_tag_archives'] = boolval($input['seo_controls']['index_tag_archives']);
        }
        if (isset($input['seo_controls']['index_author_archives'])) {
            $new_input['seo_controls']['index_author_archives'] = boolval($input['seo_controls']['index_author_archives']);
        }
        if (isset($input['seo_controls']['index_format_archives'])) {
            $new_input['seo_controls']['index_format_archives'] = boolval($input['seo_controls']['index_format_archives']);
        }

        // Handle post-save heartbeat trigger for domain changes
        if (isset($new_input['_trigger_heartbeat_after_save'])) {
            $context = $new_input['_trigger_heartbeat_after_save'];
            unset($new_input['_trigger_heartbeat_after_save']); // Remove flag from saved data
            
            // Schedule the heartbeat check to run after settings are saved
            add_action('updated_option_metasync_options', function() use ($context) {
                
                delete_transient('metasync_heartbeat_status_cache');
                do_action('metasync_trigger_immediate_heartbeat', 'Whitelabel domain change - ' . $context);
            });
        }

        $result = array_merge($new_input, $input);

        // When whitelabel validation failed, prevent invalid partial data from being saved
        if ($whitelabel_validation_failed) {
            $result['whitelabel'] = Metasync::get_option()['whitelabel'] ?? [];
        }

        return $result;
    }

    public function metasync_settings_genkey_callback()
    {
        // Get existing Plugin Auth Token - should always exist from activation
        $current_token = Metasync::get_option('general')['apikey'] ?? '';
        
        // Display current token or indicate if missing
        if (!empty($current_token)) {
            $display_value = $current_token;
            $status_message = 'Plugin Auth Token is active and ready for authentication.';
            $refresh_help = 'Click refresh to generate a new token and update the heartbeat API.';
        } else {
            $display_value = 'Auto-generated when connecting to ' . esc_html(Metasync::get_effective_plugin_name());
            $status_message = 'Plugin Auth Token will be automatically generated when you click "Connect to ' . esc_html(Metasync::get_effective_plugin_name()) . '".';
            $refresh_help = 'You can also manually generate a token by clicking refresh.';
        }
        
        printf(
            '<input type="text" id="apikey" name="' . $this::option_key . '[general][apikey]" value="%s" size="40" readonly="readonly" /> ',
            esc_attr($display_value)
        );
        
        // Add refresh button
        printf('<button type="button" id="refresh-plugin-auth-token" class="button button-secondary" style="margin-left: 10px;">🔄 Refresh Token</button>');
        printf('<p class="description">%s %s</p>', $status_message, $refresh_help);
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function linkgraph_token_callback()
    {
        printf(
            '<input type="text" id="linkgraph_token" name="' . $this::option_key . '[general][linkgraph_token]" value="%s" size="25" readonly="readonly" />',
            isset(Metasync::get_option('general')['linkgraph_token']) ? esc_attr(Metasync::get_option('general')['linkgraph_token']) : ''
        );

        printf(
            '<input type="text" id="linkgraph_customer_id" name="' . $this::option_key . '[general][linkgraph_customer_id]" value="%s" size="25" readonly="readonly" />',
            isset(Metasync::get_option('general')['linkgraph_customer_id']) ? esc_attr(Metasync::get_option('general')['linkgraph_customer_id']) : ''
        );

    ?>
        <button type="button" class="button button-primary" id="lgloginbtn">Fetch Token</button>
        <input type="text" id="lgusername" class="input lguser hidden" placeholder="username" />
        <input type="text" id="lgpassword" class="input lguser hidden" placeholder="password" />
        <p id="lgerror" class="notice notice-error hidden" style="display: none;"></p>
    <?php
    }


    private function time_elapsed_string($datetime, $full = false)
    {
        // Check if the $datetime is empty and return empty string
        if(empty($datetime)){
            return "";
        }
        $now = new DateTime;
        $ago = new DateTime($datetime);

        $diff = $now->diff($ago);
        // $diff->w = floor($diff->d / 7);
        // $diff->d -= $diff->w * 7;

        $string = [
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        ];

        foreach ($string as $k => &$v) {
            if (isset($diff->$k) && $diff->$k) {
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }
        if (!$full) $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ago' : 'just now';
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function searchatlas_api_key_callback()
    {
        $current_api_key = isset(Metasync::get_option('general')['searchatlas_api_key']) ? esc_attr(Metasync::get_option('general')['searchatlas_api_key']) : '';
        $otto_uuid = isset(Metasync::get_option('general')['otto_pixel_uuid']) ? Metasync::get_option('general')['otto_pixel_uuid'] : '';
        
        // Consider fully connected based on heartbeat sync status
        $has_api_key = !empty($current_api_key);
        $has_otto_uuid = !empty($otto_uuid);
        
        $is_fully_connected = $this->is_heartbeat_connected();
        
        // Search Atlas Connect Container (1-click connect to retrieve API key and Otto UUID)
        printf('<div class="metasync-sa-connect-container">');
        
        // Connect title and description
        printf('<div class="metasync-sa-connect-title">');
        printf('🔐 One-Click Authentication');
        printf('</div>');
        
        printf('<div class="metasync-sa-connect-description">');
        if ($is_fully_connected) {
            printf('Your %s account is fully synced with active heartbeat API. You can re-authenticate to refresh your connection or connect a different account.', esc_html(Metasync::get_effective_plugin_name()));
        } elseif ($has_api_key && !$has_otto_uuid) {
            printf('Your %s API key is configured, but %s UUID is missing. Please re-authenticate to complete the setup.', esc_html(Metasync::get_effective_plugin_name()), esc_html(Metasync::get_whitelabel_otto_name()));
        } else {
            printf('Connect your %s account with one click. This will automatically configure your API key and %s UUID below, enabling all plugin features.', esc_html(Metasync::get_effective_plugin_name()), esc_html(Metasync::get_whitelabel_otto_name()));
        }
        printf('</div>');

        // MCP Consent Notice
        ?>
        <div class="metasync-mcp-consent" style="margin-top: 15px; padding: 12px 15px; background: #f0f9ff; border-left: 3px solid #0ea5e9; border-radius: 4px;">
            <p style="margin: 0; color: #334155; font-size: 13px; line-height: 1.6;">
                <strong>🤖 AI-Powered SEO Automation:</strong> By authenticating, you authorize <strong><?php echo esc_html(Metasync::get_effective_plugin_name()); ?> Brain</strong> (our AI assistant) to access your WordPress admin capabilities through the Model Context Protocol (MCP). This enables intelligent automation for SEO optimizations, content enhancements, and performance improvements.
            </p>
        </div>
        <?php

        // Connect buttons container
        printf('<div class="metasync-sa-connect-buttons">');
        
        // Primary Connect/Re-authenticate button
        printf('<button type="button" id="connect-searchatlas-btn" class="metasync-sa-connect-btn">');
        if ($is_fully_connected) {
            printf('🔄 Re-authenticate with %s', esc_html(Metasync::get_effective_plugin_name()));
        } elseif ($has_api_key && !$has_otto_uuid) {
            printf('🔧 Complete Authentication Setup');
        } else {
            printf('🔗 Connect to %s', esc_html(Metasync::get_effective_plugin_name()));
        }
        printf('</button>');
        
        // Reset/Disconnect button (only show if any connection exists)
        if ($has_api_key) {
            printf('<button type="button" id="reset-searchatlas-auth" class="metasync-sa-reset-btn" style="margin-left: 10px;">');
            printf('🔓 Disconnect Account');
            printf('</button>');
        }
        
        printf('</div>'); // Close metasync-sa-connect-buttons
        
        // Add helpful tips
        printf('<div style="margin-top: 15px;">');
        printf('<details style="margin-top: 10px;">');
        printf('<summary style="cursor: pointer; color: #666; font-size: 13px;">💡 Authentication Tips</summary>');
        printf('<div style="padding: 10px 0; color: #666; font-size: 13px; line-height: 1.5;">');
        printf('• Make sure you have a %s account before connecting<br/>', esc_html(Metasync::get_effective_plugin_name()));
        printf('• The authentication window will open in a popup - please allow popups<br/>');
        printf('• The process typically takes 15-30 seconds to complete<br/>');
        printf('• Your API key will be automatically filled in the field below<br/>');
        printf('• If you encounter issues, try disabling ad blockers temporarily<br/>');
        printf('• Contact <a href="mailto:%s">%s</a> if you need assistance', Metasync::SUPPORT_EMAIL, Metasync::SUPPORT_EMAIL);
        printf('</div>');
        printf('</details>');
        printf('</div>');
        
        printf('</div>'); // Close metasync-sa-connect-container
        
        // API Key Input Section (MOVED TO BOTTOM)
        printf('<div style="margin-top: 20px;">');
        printf('<label for="searchatlas-api-key" style="font-weight: 600; display: block; margin-bottom: 8px;">');
        printf('🔑 %s API Key', esc_html(Metasync::get_effective_plugin_name()));
        if ($is_fully_connected) {
            printf('<span style="color: #46b450; margin-left: 10px; font-weight: normal;">✓ Synced</span>');
        } elseif ($has_api_key && !$has_otto_uuid) {
            printf('<span style="color: #ff8c00; margin-left: 10px; font-weight: normal;">⚠️ Partial Connection (Missing %s UUID)</span>', esc_html(Metasync::get_whitelabel_otto_name()));
        }
        printf('</label>');
        
        printf(
            '<input type="text" id="searchatlas-api-key" name="' . $this::option_key . '[general][searchatlas_api_key]" value="%s" size="40" class="regular-text" placeholder="Your API key will appear here after authentication" />',
            $current_api_key
        );
        
        printf('<p class="description" style="margin-top: 8px;">');
        if ($is_fully_connected) {
            printf('Your %s API key for secure communication with the platform. Use the authentication button above to refresh or change accounts.', esc_html(Metasync::get_effective_plugin_name()));
        } elseif ($has_api_key && !$has_otto_uuid) {
            printf('Your API key is configured but %s UUID is missing. Re-authenticate above to complete the setup and enable dashboard access.', esc_html(Metasync::get_whitelabel_otto_name()));
        } else {
            printf('This field will be automatically populated when you authenticate using the button above. You can also manually enter your API key if you have one.');
        }
        printf('</p>');

        // Manual Authentication Consent Notice
        ?>
        <p class="description" style="margin-top: 10px; padding: 8px 12px; background: #fff9e6; border-left: 3px solid #f0b849; border-radius: 4px; font-size: 12px;">
            <strong>📝 Manual Authentication:</strong> If you manually enter your <?php echo esc_html(Metasync::get_effective_plugin_name()); ?> API Key and OTTO UUID, you also consent to the same AI-powered automation permissions described above.
        </p>
        <?php

        printf('</div>');

        if(  isset(Metasync::get_option('general')['searchatlas_api_key'])&&Metasync::get_option('general')['searchatlas_api_key']!=''){
            $timestamp = @Metasync::get_option('general')['send_auth_token_timestamp'];
            printf(
                '<p id="sendAuthTokenTimestamp" class="descriptionValue">%s (%s)</p>',
                esc_attr($timestamp),
                $this->time_elapsed_string($timestamp)
            );
    
        
        }
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
        printf(
            '<input type="text" id="bing_site_verification" name="' . $this::option_key . '[searchengines][bing_site_verification]" value="%s" size="50" />',
            isset(Metasync::get_option('searchengines')['bing_site_verification']) ? esc_attr(Metasync::get_option('searchengines')['bing_site_verification']) : ''
        );

        printf(' <br> <span class="description"> Enter Bing Webmaster Tools verification code: </span> ');
        printf(' <a href="https://www.bing.com/webmasters/about" target="_blank">Get from here</a> <br> ');

        /// highlight_string('<meta name="msvalidate.01" content="XXXXXXXXXXXXXXXXXXXXX" />');
    }





    /**
     * Get the settings option array and print one of its values
     */
    public function yandex_site_verification_callback()
    {
        printf(
            '<input type="text" id="yandex_site_verification" name="' . $this::option_key . '[searchengines][yandex_site_verification]" value="%s" size="50" />',
            isset(Metasync::get_option('searchengines')['yandex_site_verification']) ? esc_attr(Metasync::get_option('searchengines')['yandex_site_verification']) : ''
        );

        printf(' <br> <span class="description"> Enter Yandex verification code: </span>');
        printf(' <a href="https://passport.yandex.com/auth" target="_blank">Get from here</a> <br> ');

        /// highlight_string("<meta name='yandex-verification' content='XXXXXXXXXXXXXXXXXXXXX' />");
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function google_site_verification_callback()
    {
        printf(
            '<input type="text" id="google_site_verification" name="' . $this::option_key . '[searchengines][google_site_verification]" value="%s" size="50" />',
            isset(Metasync::get_option('searchengines')['google_site_verification']) ? esc_attr(Metasync::get_option('searchengines')['google_site_verification']) : ''
        );

        printf(' <br> <span class="description"> Enter Google Search Console verification code: </span>');
        printf(' <a href="https://www.google.com/webmasters/verification" target="_blank">Get from here</a> <br> ');

        /// highlight_string('<meta name="google-site-verification" content="XXXXXXXXXXXXXXXXXXXXX" />');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function pinterest_site_verification_callback()
    {
        printf(
            '<input type="text" id="pinterest_site_verification" name="' . $this::option_key . '[searchengines][pinterest_site_verification]" value="%s" size="50" />',
            isset(Metasync::get_option('searchengines')['pinterest_site_verification']) ? esc_attr(Metasync::get_option('searchengines')['pinterest_site_verification']) : ''
        );

        printf(' <br> <span class="description"> Enter Pinterest verification code: </span>');
        printf(' <a href="https://in.pinterest.com/" target="_blank">Get from here</a> <br> ');

        /// highlight_string('<meta name="p:domain_verify" content="XXXXXXXXXXXXXXXXXXXXX" />');
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
        $person_organization = Metasync::get_option('localseo')['local_seo_person_organization'] ?? '';
    ?>
        <select id="local_seo_person_organization" name="<?php echo esc_attr($this::option_key . '[localseo][local_seo_person_organization]') ?>">
            <?php
            printf('<option value="Person" %s >Person</option>', selected('Person', esc_attr($person_organization)));
            printf('<option value="Organization" %s >Organization</option>', selected('Organization', esc_attr($person_organization)));
            ?>
        </select>
    <?php
        printf(' <br> <span class="description"> Choose whether the site represents a person or an organization. </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_name_callback()
    {
        printf(
            '<input type="text" id="local_seo_name" name="' . $this::option_key . '[localseo][local_seo_name]" value="%s" size="50" />',
            isset(Metasync::get_option('localseo')['local_seo_name']) ? esc_attr(Metasync::get_option('localseo')['local_seo_name']) : get_bloginfo()
        );

        printf(' <br> <span class="description"> Your name or company name </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_logo_callback()
    {
        $local_seo_logo = Metasync::get_option('localseo')['local_seo_logo'] ?? '';

        printf(
            '<input type="hidden" id="local_seo_logo" name="' . $this::option_key . '[localseo][local_seo_logo]" value="%s" size="50" />',
            isset(Metasync::get_option('localseo')['local_seo_logo']) ? esc_attr(Metasync::get_option('localseo')['local_seo_logo']) : ''
        );

        printf(' <br> <input class="button-secondary" type="button" id="logo_upload_button" value="Add or Upload File">');

        printf(' <br><br> <span class="description bold"> Min Size: 160Χ90px, Max Size: 1920X1080px. </span> <br> <span class="description"> A squared image is preferred by the search engines. </span> <br><br> ');

        printf('<img src="%s" id="local_seo_business_logo" width="300">', wp_get_attachment_image_src($local_seo_logo, 'medium')[0] ?? '');

        $button_type = 'hidden';
        if ($local_seo_logo) {
            $button_type = 'button';
        }
        printf('<input type="%s" class="button-secondary" id="local_seo_logo_close_btn" value="X">', $button_type);
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_url_callback()
    {
        printf(
            '<input type="text" id="local_seo_url" name="' . $this::option_key . '[localseo][local_seo_url]" value="%s" size="50" />',
            isset(Metasync::get_option('localseo')['local_seo_url']) ? esc_attr(Metasync::get_option('localseo')['local_seo_url']) : home_url()
        );

        printf(' <br> <span class="description"> URL of the item. </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_email_callback()
    {
        printf(
            '<input type="text" id="local_seo_email" name="' . $this::option_key . '[localseo][local_seo_email]" value="%s" size="50" />',
            isset(Metasync::get_option('localseo')['local_seo_email']) ? esc_attr(Metasync::get_option('localseo')['local_seo_email']) : ''
        );

        printf(' <br> <span class="description"> Search engines display your email address. </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_phone_callback()
    {
        printf(
            '<input type="text" id="local_seo_phone" name="' . $this::option_key . '[localseo][local_seo_phone]" value="%s" size="50" />',
            isset(Metasync::get_option('localseo')['local_seo_phone']) ? esc_attr(Metasync::get_option('localseo')['local_seo_phone']) : ''
        );

        printf(' <br> <span class="description"> Search engines may prominently display your contact phone number for mobile users. </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_address_callback()
    {
        printf(
            '<input type="text" id="local_seo_address_street" name="' . $this::option_key . '[localseo][address][street]" value="%s" size="50" placeholder="Street Address"/> <br>',
            isset(Metasync::get_option('localseo')['address']['street']) ? esc_attr(Metasync::get_option('localseo')['address']['street']) : ''
        );

        printf(
            '<input type="text" id="local_seo_address_locality" name="' . $this::option_key . '[localseo][address][locality]" value="%s" size="50" placeholder="Locality"/> <br>',
            isset(Metasync::get_option('localseo')['address']['locality']) ? esc_attr(Metasync::get_option('localseo')['address']['locality']) : ''
        );

        printf(
            '<input type="text" id="local_seo_address_region" name="' . $this::option_key . '[localseo][address][region]" value="%s" size="50" placeholder="Region"/> <br>',
            isset(Metasync::get_option('localseo')['address']['region']) ? esc_attr(Metasync::get_option('localseo')['address']['region']) : ''
        );

        printf(
            '<input type="text" id="local_seo_address_postalcode" name="' . $this::option_key . '[localseo][address][postalcode]" value="%s" size="50" placeholder="Postal Code"/> <br>',
            isset(Metasync::get_option('localseo')['address']['postalcode']) ? esc_attr(Metasync::get_option('localseo')['address']['postalcode']) : ''
        );

        printf(
            '<input type="text" id="local_seo_address_country" name="' . $this::option_key . '[localseo][address][country]" value="%s" size="50" placeholder="Country"/> <br>',
            isset(Metasync::get_option('localseo')['address']['country']) ? esc_attr(Metasync::get_option('localseo')['address']['country']) : ''
        );
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_business_type_callback()
    {
        $types = $this->get_business_types();
        sort($types);

        $business_type = Metasync::get_option('localseo')['local_seo_business_type'] ?? '';

    ?>
        <select name="<?php echo esc_attr($this::option_key . '[localseo][local_seo_business_type]') ?>">
            <option value='0'>Select Business Type</option>
            <?php
            foreach ($types as $type) {
                printf('<option value="%s" %s >%s</option>', $type, selected($type, esc_attr($business_type)), $type);
            }
            ?>
        </select>
    <?php
    }



    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_opening_hours_callback()
    {
        $days_name = ['Monday', 'Tuseday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $days = isset(Metasync::get_option('localseo')['days']) ? Metasync::get_option('localseo')['days'] : '';
        $times = isset(Metasync::get_option('localseo')['times']) ? Metasync::get_option('localseo')['times'] : '';

    ?>
        <ul id="daysTime">
            <?php
            $opening_days = [];
            if ($days && $times) {
                $opening_days = array_combine($days, $times);
            }
            foreach ($opening_days as $day_name => $day_time) {
            ?>
                <li>
                    <select name="<?php echo esc_attr($this::option_key . '[localseo][days][]') ?>">
                        <?php
                        foreach ($days_name as $name) {
                            printf('<option value="%s" %s >%s</option>', $name, selected(esc_attr($name), esc_attr($day_name)), esc_attr($name));
                        }
                        ?>
                    </select>
                    <input type="text" name="<?php echo esc_attr($this::option_key . '[localseo][times][]') ?>" value="<?php echo esc_attr($day_time) ?>">
                    <button id="timeDelete">Delete</button>
                </li>
            <?php } ?>
            <?php if (empty($opening_days)) { ?>
                <li>
                    <select name="<?php echo esc_attr($this::option_key . '[localseo][days][]') ?>">
                        <?php
                        foreach ($days_name as $name) {
                            printf('<option value="%s" >%s</option>', esc_attr($name), esc_attr($name));
                        }
                        ?>
                    </select>
                    <input type="text" name="<?php echo esc_attr($this::option_key . '[localseo][times][]') ?>" value="">
                    <button id="timeDelete">Delete</button>
                </li>
            <?php } ?>
        </ul>
    <?php

        printf(' <input type="hidden" id="days_time_count" value="%s"/>', count($opening_days));
        printf(' <input class="button-secondary" type="button" id="addNewTime" value="Add Time">');
        printf(' <br> <span class="description"> Select opening hours. You can add multiple sets if you have different opening or closing hours on some days or if you have a mid-day break. Times are specified using 24:00 time. </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_phone_numbers_callback()
    {
        $number_types = ['Customer Service', 'Technical Support', 'Billing Support', 'Bill Payment', 'Sales', 'Reservations', 'Credit Card Support', 'Emergency', 'Baggage Tracking', 'Roadside Assistance', 'Package Tracking'];
        $types = isset(Metasync::get_option('localseo')['phonetype']) ? Metasync::get_option('localseo')['phonetype'] : '';
        $numbers = isset(Metasync::get_option('localseo')['phonenumber']) ? Metasync::get_option('localseo')['phonenumber'] : '';

    ?>

        <ul id="phone-numbers">
            <?php
            $phone_numbers = [];
            if ($types && $numbers) {
                $phone_numbers = array_combine($types, $numbers);
            }
            foreach ($phone_numbers as $phone_type => $phone_number) {
            ?>
                <li>
                    <select name="<?php echo esc_attr($this::option_key . '[localseo][phonetype][]') ?>">
                        <?php
                        foreach ($number_types as $type) {
                            printf('<option value="%s" %s >%s</option>', esc_attr($type), selected(esc_attr($type), esc_attr($phone_type)), esc_attr($type));
                        }
                        ?>
                    </select>
                    <input type="text" name="<?php echo esc_attr($this::option_key . '[localseo][phonenumber][]') ?>" value="<?php echo esc_attr($phone_number) ?>">
                    <button id="number-delete">Delete</button>
                </li>
            <?php } ?>
            <?php if (empty($phone_numbers)) { ?>
                <li>
                    <select name="<?php echo esc_attr($this::option_key . '[localseo][phonetype][]') ?>">
                        <?php
                        foreach ($number_types as $type) {
                            printf('<option value="%s" >%s</option>', esc_attr($type), esc_attr($type));
                        }
                        ?>
                    </select>
                    <input type="text" name="<?php echo esc_attr($this::option_key . '[localseo][phonenumber][]') ?>" value="">
                    <button id="number-delete">Delete</button>
                </li>
            <?php } ?>
        </ul>
    <?php

        printf(' <input type="hidden" id="phone_number_count" value="%s"/>', count($phone_numbers));
        printf(' <input class="button-secondary" type="button" id="addNewNumber" value="Add Number">');
        printf(' <br> <span class="description"> Search engines may prominently display your contact phone number for mobile users. </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_price_range_callback()
    {
        printf(
            '<input type="text" id="local_seo_price_range" name="' . $this::option_key . '[localseo][local_seo_price_range]" value="%s" size="50" />',
            isset(Metasync::get_option('localseo')['local_seo_price_range']) ? esc_attr(Metasync::get_option('localseo')['local_seo_price_range']) : ''
        );
        printf(' <br> <span class="description"> The price range of the business, for example $$$. </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_about_page_callback()
    {
    ?>
        <select name="<?php echo esc_attr($this::option_key . '[localseo][local_seo_about_page]') ?>">
            <option value='0'>Select About Page</option>
            <?php
            $about_page = Metasync::get_option('localseo')['local_seo_about_page'] ?? '';
            $pages = get_pages();
            foreach ($pages as $page) {
                printf('<option value="%s" %s >%s</option>', $page->ID, selected($page->ID, esc_attr($about_page)), $page->post_title);
            }
            ?>
        </select>
    <?php
        printf(' <br> <span class="description"> Search engines tag your about us page. </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_contact_page_callback()
    {
    ?>
        <select name="<?php echo esc_attr($this::option_key . '[localseo][local_seo_contact_page]') ?>">
            <option value='0'>Select Contact Page</option>
            <?php
            $contact_page = Metasync::get_option('localseo')['local_seo_contact_page'] ?? '';
            $pages = get_pages();
            foreach ($pages as $page) {
                printf('<option value="%s" %s >%s</option>', $page->ID, selected($page->ID, esc_attr($contact_page)), $page->post_title);
            }
            ?>
        </select>
    <?php
        printf(' <br> <span class="description"> Search engines tag your contact page. </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_map_key_callback()
    {
        printf(
            '<input type="text" id="local_seo_map_key" name="' . $this::option_key . '[localseo][local_seo_map_key]" value="%s" size="50" />',
            isset(Metasync::get_option('localseo')['local_seo_map_key']) ? esc_attr(Metasync::get_option('localseo')['local_seo_map_key']) : ''
        );

        printf(' <br> <span class="description"> An API Key is required to display embedded Google Maps on your site. </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function local_seo_geo_coordinates_callback()
    {
        printf(
            '<input type="text" id="local_seo_geo_coordinates" name="' . $this::option_key . '[localseo][local_seo_geo_coordinates]" value="%s" size="50" />',
            isset(Metasync::get_option('localseo')['local_seo_geo_coordinates']) ? esc_attr(Metasync::get_option('localseo')['local_seo_geo_coordinates']) : ''
        );

        printf(' <br> <span class="description"> Latitude and longitude values separated by comma. </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function header_snippets_callback()
    {
        printf(
            '<textarea class="wide-text" id="header_snippets" rows="8" name="' . $this::option_key . '[codesnippets][header_snippet]" >%s</textarea>',
            isset(Metasync::get_option('codesnippets')['header_snippet']) ? esc_attr(Metasync::get_option('codesnippets')['header_snippet']) : ''
        );
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function footer_snippets_callback()
    {
        printf(
            '<textarea class="wide-text" id="footer_snippets" rows="8" name="' . $this::option_key . '[codesnippets][footer_snippet]" >%s</textarea>',
            isset(Metasync::get_option('codesnippets')['footer_snippet']) ? esc_attr(Metasync::get_option('codesnippets')['footer_snippet']) : ''
        );
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function no_index_posts_callback()
    {
        printf(
            '<input type="checkbox" id="no_index_posts" name="' . $this::option_key . '[optimal_settings][no_index_posts]" value="true" %s />',
            isset(Metasync::get_option('optimal_settings')['no_index_posts']) && Metasync::get_option('optimal_settings')['no_index_posts'] == 'true' ? 'checked' : ''
        );

        printf(' <br> <span class="description"> Setting empty archives to <code>noindex</code> is useful for avoiding indexation of thin content pages and dilution of page rank. As soon as a post is added, the page is updated to index. </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function no_follow_links_callback()
    {
        printf(
            '<input type="checkbox" id="no_follow_links" name="' . $this::option_key . '[optimal_settings][no_follow_links]" value="true" %s />',
            isset(Metasync::get_option('optimal_settings')['no_follow_links']) && Metasync::get_option('optimal_settings')['no_follow_links'] == 'true' ? 'checked' : ''
        );

        printf(' <br> <span class="description"> Automatically add <code>rel="nofollow"</code> attribute to external links appearing in your posts, pages, and other post types. The attribute is dynamically applied when the url is displayed</span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function open_external_links_callback()
    {
        printf(
            '<input type="checkbox" id="open_external_links" name="' . $this::option_key . '[optimal_settings][open_external_links]" value="true" %s />',
            isset(Metasync::get_option('optimal_settings')['open_external_links']) && Metasync::get_option('optimal_settings')['open_external_links'] == 'true' ? 'checked' : ''
        );

        printf(' <br> <span class="description"> Automatically add <code>target="_blank"</code> attribute to external links appearing in your posts, pages, and other post types. The attribute is applied when the url is displayed.</span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function add_alt_image_tags_callback()
    {
        printf(
            '<input type="checkbox" name="' . $this::option_key . '[optimal_settings][add_alt_image_tags]" value="true" %s />',
            isset(Metasync::get_option('optimal_settings')['add_alt_image_tags']) && Metasync::get_option('optimal_settings')['add_alt_image_tags'] == 'true' ? 'checked' : ''
        );

        printf(' <br> <span class="description"> Automatically add <code>alt</code> attribute to Image Tags appearing in your posts, pages, and other post types. The attribute is applied when the content is displayed.</span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function add_title_image_tags_callback()
    {
        printf(
            '<input type="checkbox" name="' . $this::option_key . '[optimal_settings][add_title_image_tags]" value="true" %s />',
            isset(Metasync::get_option('optimal_settings')['add_title_image_tags']) && Metasync::get_option('optimal_settings')['add_title_image_tags'] == 'true' ? 'checked' : ''
        );

        printf(' <br> <span class="description"> Automatically add <code>title</code> attribute to Image Tags appearing in your posts, pages, and other post types. The attribute is applied when the content is displayed.</span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function site_type_callback()
    {

        $site_type = Metasync::get_option('optimal_settings')['site_info']['type'] ?? '';

        $types = [
            ['name' => 'Personal Blog', 'value' => 'blog'],
            ['name' => 'Community Blog/News Site', 'value' => 'news'],
            ['name' => 'Personal Portfolio', 'value' => 'portfolio'],
            ['name' => 'Small Business Site', 'value' => 'business'],
            ['name' => 'Webshop', 'value' => 'webshop'],
            ['name' => 'Other Personal Website', 'value' => 'otherpersonal'],
            ['name' => 'Other Business Website', 'value' => 'otherbusiness'],
        ];

    ?>
        <select name="<?php echo esc_attr($this::option_key . '[optimal_settings][site_info][type]') ?>" id="site_info_type">
            <?php
            foreach ($types as $type) {
                printf('<option value="%s" %s >%s</option>', esc_attr($type['value']), selected(esc_attr($type['value']), esc_attr($site_type)), ($type['name']));
            }
            ?>
        </select>
    <?php

    }

    /**
     * Get the settings option array and print one of its values
     */
    public function site_business_type_callback()
    {

        $business_type = Metasync::get_option('optimal_settings')['site_info']['business_type'] ?? '';

        $types = $this->get_business_types();
        sort($types);

    ?>
        <select name="<?php echo esc_attr($this::option_key . '[optimal_settings][site_info][business_type]') ?>">
            <option value='0'>Select Business Type</option>
            <?php
            foreach ($types as $type) {
                printf('<option value="%s" %s >%s</option>', esc_attr($type), selected(esc_attr($type), esc_attr($business_type)), esc_attr($type));
            }
            ?>
        </select>
    <?php
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function site_company_name_callback()
    {

        $company_name = Metasync::get_option('optimal_settings')['site_info']['company_name'] ?? get_bloginfo('name');

        printf(
            '<input type="text" name="' . $this::option_key . '[optimal_settings][site_info][company_name]" value="%s" size="50" />',
            $company_name ? $company_name : get_bloginfo('name')
        );
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function site_google_logo_callback()
    {

        $google_logo = Metasync::get_option('optimal_settings')['site_info']['google_logo'] ?? '';

        printf(
            '<input type="hidden" id="site_google_logo" name="' . $this::option_key . '[optimal_settings][site_info][google_logo]" value="%s" size="50" />',
            $google_logo
        );

        printf(' <br> <input class="button-secondary" type="button" id="google_logo_btn" value="Add or Upload File">');
        printf(' <br><br> <span class="description bold"> Min Size: 160X90px, Max Size: 1920X1080px. </span> <br> <span class="description"> A squared image is preferred by the search engines. </span> <br><br> ');
        printf('<img src="%s" id="site_google_logo_img" width="300">', wp_get_attachment_image_src($google_logo, 'medium')[0] ?? '');

        $button_type = 'hidden';
        if ($google_logo) {
            $button_type = 'button';
        }
        printf('<input type="%s" class="button-secondary" id="site_google_logo_close_btn" value="X">', $button_type);
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function site_social_share_image_callback()
    {

        $social_share_image = Metasync::get_option('optimal_settings')['site_info']['social_share_image'] ?? '';

        printf(
            '<input type="hidden" id="site_social_share_image" name="' . $this::option_key . '[optimal_settings][site_info][social_share_image]" value="%s" size="50" />',
            $social_share_image
        );

        printf(' <br> <input class="button-secondary" type="button" id="social_share_image_btn" value="Add or Upload File">');
        printf(' <br><br> <span class="description bold"> The recommended image size is 1200 x 630 pixels. </span> <br> <span class="description"> When a featured image or an OpenGraph Image is not set for individual posts/pages/CPTs, this image will be used as a fallback thumbnail when your post is shared on Facebook. </span> <br><br> ');
        printf('<img src="%s" id="site_social_share_img" width="300">', wp_get_attachment_image_src($social_share_image, 'medium')[0] ?? '');

        $button_type = 'hidden';
        if ($social_share_image) {
            $button_type = 'button';
        }
        printf('<input type="%s" class="button-secondary" id="site_social_image_close_btn" value="X">', $button_type);
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function common_robot_meta_tags_callback()
    {
        // Check new spelling first, fall back to old for backward compatibility
        $common_robots_meta = Metasync::get_option('common_robots_meta') ?? Metasync::get_option('common_robots_mata') ?? '';

    ?>
        <ul class="checkbox-list">
            <li>
                <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[common_robots_meta][index]') ?>" id="robots_common1" value="index" <?php isset($common_robots_meta['index']) ? checked('index', $common_robots_meta['index']) : '' ?>>
                <label for="robots_common1">Index </br>
                    <span class="description">
                        <span>Search engines to index and show these pages in the search results.</span>
                    </span>
                </label>
            </li>
            <li>
                <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[common_robots_meta][noindex]') ?>" id="robots_common2" value="noindex" <?php isset($common_robots_meta['noindex']) ? checked('noindex', $common_robots_meta['noindex']) : '' ?>>
                <label for="robots_common2">No Index </br>
                    <span class="description">
                        <span>Search engines not indexed and displayed this pages in search engine results</span>
                    </span>
                </label>
            </li>
            <li>
                <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[common_robots_meta][nofollow]') ?>" id="robots_common3" value="nofollow" <?php isset($common_robots_meta['nofollow']) ? checked('nofollow', $common_robots_meta['nofollow']) : '' ?>>
                <label for="robots_common3">No Follow </br>
                    <span class="description">
                        <span>Search engines not follow the links on the pages</span>
                    </span>
                </label>
            </li>
            <li>
                <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[common_robots_meta][noarchive]') ?>" id="robots_common4" value="noarchive" <?php isset($common_robots_meta['noarchive']) ? checked('noarchive', $common_robots_meta['noarchive']) : '' ?>>
                <label for="robots_common4">No Archive </br>
                    <span class="description">
                        <span>Search engines not showing Cached links for pages</span>
                    </span>
                </label>
            </li>
            <li>
                <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[common_robots_meta][noimageindex]') ?>" id="robots_common5" value="noimageindex" <?php isset($common_robots_meta['noimageindex']) ? checked('noimageindex', $common_robots_meta['noimageindex']) : '' ?>>
                <label for="robots_common5">No Image Index </br>
                    <span class="description">
                        <span>If you do not want to apear your pages as the referring page for images that appear in image search results</span>
                    </span>
                </label>
            </li>
            <li>
                <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[common_robots_meta][nosnippet]') ?>" id="robots_common6" value="nosnippet" <?php isset($common_robots_meta['nosnippet']) ? checked('nosnippet', $common_robots_meta['nosnippet']) : '' ?>>
                <label for="robots_common6">No Snippet </br>
                    <span class="description">
                        <span>Search engines not snippet to show in the search results</span>
                    </span>
                </label>
            </li>
        </ul>
    <?php
    }

    /**
     * Backward compatibility alias for common_robot_mata_tags_callback
     * @deprecated Use common_robot_meta_tags_callback() instead
     */
    public function common_robot_mata_tags_callback()
    {
        return $this->common_robot_meta_tags_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function advance_robot_meta_tags_callback()
    {
        // Check new spelling first, fall back to old for backward compatibility
        $advance_robots_meta = Metasync::get_option('advance_robots_meta') ?? Metasync::get_option('advance_robots_mata') ?? '';

        $snippet_advance_robots_enable = $advance_robots_meta['max-snippet']['enable'] ?? '';
        $snippet_advance_robots_length = $advance_robots_meta['max-snippet']['length'] ?? '-1';
        $video_advance_robots_enable = $advance_robots_meta['max-video-preview']['enable'] ?? '';
        $video_advance_robots_length = $advance_robots_meta['max-video-preview']['length'] ?? '-1';
        $image_advance_robots_enable = $advance_robots_meta['max-image-preview']['enable'] ?? '';
        $image_advance_robots_length = $advance_robots_meta['max-image-preview']['length'] ?? '';

    ?>
        <ul class="checkbox-list">
            <li>
                <label for="advanced_robots_snippet">
                    <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[advance_robots_meta][max-snippet][enable]') ?>" id="advanced_robots_snippet" value="1" <?php checked('1', esc_attr($snippet_advance_robots_enable)) ?>>
                    Snippet </br>
                    <input type="number" class="input-length" name="<?php echo esc_attr($this::option_key . '[advance_robots_meta][max-snippet][length]') ?>" id="advanced_robots_snippet_value" value="<?php echo esc_attr($snippet_advance_robots_length); ?>" min="-1"> </br>
                    <span class="description">
                        <span>Add maximum text-length, in characters, of a snippet for your page.</span>
                    </span>
                </label>
            </li>
            <li>
                <label for="advanced_robots_video">
                    <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[advance_robots_meta][max-video-preview][enable]') ?>" id="advanced_robots_video" value="1" <?php checked('1', esc_attr($video_advance_robots_enable)) ?>>
                    Video Preview </br>
                    <input type="number" class="input-length" name="<?php echo esc_attr($this::option_key . '[advance_robots_meta][max-video-preview][length]') ?>" id="advanced_robots_video_value" value="<?php echo esc_attr($video_advance_robots_length); ?>" min="-1"> </br>
                    <span class="description">
                        <span>Add maximum duration in seconds of an animated video preview.</span>
                    </span>
                </label>
            </li>
            <li>
                <label for="advanced_robots_image">
                    <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[advance_robots_meta][max-image-preview][enable]') ?>" id="advanced_robots_image" value="1" <?php checked('1', esc_attr($image_advance_robots_enable)); ?>>
                    Image Preview </br>
                    <select class="input-length" name="<?php echo esc_attr($this::option_key . '[advance_robots_meta][max-image-preview][length]') ?>" id="advanced_robots_image_value">
                        <option value="large" <?php selected('large', esc_attr($image_advance_robots_length)) ?>>Large</option>
                        <option value="standard" <?php selected('standard', esc_attr($image_advance_robots_length)) ?>>Standard</option>
                        <option value="none" <?php selected('none', esc_attr($image_advance_robots_length)) ?>>None</option>
                    </select>
                    </br>
                    <span class="description">
                        <span>Add maximum size of image preview to show the images on this page.</span>
                    </span>
                </label>
            </li>
        </ul>
    <?php
    }

    /**
     * Backward compatibility alias for advance_robot_mata_tags_callback
     * @deprecated Use advance_robot_meta_tags_callback() instead
     */
    public function advance_robot_mata_tags_callback()
    {
        return $this->advance_robot_meta_tags_callback();
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function global_twitter_card_type_callback()
    {
        $twitter_card_type = Metasync::get_option('twitter_card_type') ?? '';
    ?>

        <select class="input-length" name="<?php echo esc_attr($this::option_key . '[twitter_card_type]') ?>" id="twitter_card_type">
            <option value="summary_large_image" <?php selected('summary_large_image', esc_attr($twitter_card_type)) ?>>Summary Large Image</option>
            <option value="summary_card" <?php selected('summary_card', esc_attr($twitter_card_type)) ?>>Summary Card</option>
        </select>

        <?php
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function global_open_graph_meta_callback()
    {
        printf(
            '<input type="checkbox" name="' . $this::option_key . '[common_meta_settings][open_graph_meta_tags]" value="true" %s />',
            isset(Metasync::get_option('common_meta_settings')['open_graph_meta_tags']) && Metasync::get_option('common_meta_settings')['open_graph_meta_tags'] == 'true' ? 'checked' : ''
        );
        printf(' <br> <span class="description"> Automatically add the Open Graph meta tags in a page or post.</span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function global_facebook_meta_callback()
    {
        printf(
            '<input type="checkbox" name="' . $this::option_key . '[common_meta_settings][facebook_meta_tags]" value="true" %s />',
            isset(Metasync::get_option('common_meta_settings')['facebook_meta_tags']) && Metasync::get_option('common_meta_settings')['facebook_meta_tags'] == 'true' ? 'checked' : ''
        );
        printf(' <br> <span class="description"> Automatically add the Facebook meta tags in a page or post.</span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function global_twitter_meta_callback()
    {
        printf(
            '<input type="checkbox" name="' . $this::option_key . '[common_meta_settings][twitter_meta_tags]" value="true" %s />',
            isset(Metasync::get_option('common_meta_settings')['twitter_meta_tags']) && Metasync::get_option('common_meta_settings')['twitter_meta_tags'] == 'true' ? 'checked' : ''
        );
        printf(' <br> <span class="description"> Automatically add the Twitter meta tags in a page or post.</span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function facebook_page_url_callback()
    {
        $facebook_page_url = Metasync::get_option('social_meta')['facebook_page_url'] ?? '';
        printf('<input type="text" name="' . $this::option_key . '[social_meta][facebook_page_url]" value="%s" size="50" />', esc_attr($facebook_page_url));
        printf('<br><span class="description"> Enter your Facebook page URL. eg: <code>https://www.facebook.com/MetaSync/</code> </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function facebook_authorship_callback()
    {
        $facebook_authorship = Metasync::get_option('social_meta')['facebook_authorship'] ?? '';
        printf('<input type="text" name="' . $this::option_key . '[social_meta][facebook_authorship]" value="%s" size="50" />', esc_attr($facebook_authorship));
        printf('<br><span class="description"> Enter Facebook profile URL to show Facebook Authorship when your articles are being shared on Facebook. eg: <code>https://www.facebook.com/shahrukh/</code> </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function facebook_admin_callback()
    {
        $facebook_admin = Metasync::get_option('social_meta')['facebook_admin'] ?? '';
        printf('<input type="text" name="' . $this::option_key . '[social_meta][facebook_admin]" value="%s" size="50" />', esc_attr($facebook_admin));
        printf(' <br> <span class="description"> Enter numeric user ID of Facebook. </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function facebook_app_callback()
    {
        $facebook_app = Metasync::get_option('social_meta')['facebook_app'] ?? '';
        printf('<input type="text" name="' . $this::option_key . '[social_meta][facebook_app]" value="%s" size="50" />', esc_attr($facebook_app));
        printf(' <br> <span class="description"> Enter numeric app ID of Facebook </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function facebook_secret_callback()
    {
        $facebook_secret = Metasync::get_option('social_meta')['facebook_secret'] ?? '';
        printf('<input type="text" name="' . $this::option_key . '[social_meta][facebook_secret]" value="%s" size="50" />', esc_attr($facebook_secret));
        printf(' <br> <span class="description"> Enter alphanumeric access token from Facebook. </span>');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function twitter_username_callback()
    {
        $twitter_username = Metasync::get_option('social_meta')['twitter_username'] ?? '';
        printf('<input type="text" name="' . $this::option_key . '[social_meta][twitter_username]" value="%s" size="50" />', esc_attr($twitter_username));
        printf(' <br> <span class="description"> Twitter username of the author to add <code>twitter:creator</code> tag to post. eg: <code>MetaSync</code> </span>');
    }

    /**
     * Get business types as choices in local business.
     *
     * @return array
     */
    public static function get_business_types()
    {
        $business_type = [
            'Airline',
            'Consortium',
            'Corporation',
            'Educational Organization',
            'College Or University',
            'Elementary School',
            'High School',
            'Middle School',
            'Preschool',
            'School',
            'Funding Scheme',
            'Government Organization',
            'Library System',
            'Local Business',
            'Animal Shelter',
            'Archive Organization',
            'Automotive Business',
            'Auto Body Shop',
            'Auto Dealer',
            'Auto Parts Store',
            'Auto Rental',
            'Auto Repair',
            'Auto Wash',
            'Gas Station',
            'Motorcycle Dealer',
            'Motorcycle Repair',
            'Child Care',
            'Dry Cleaning Or Laundry',
            'Emergency Service',
            'Fire Station',
            'Hospital',
            'Police Station',
            'Employment Agency',
            'Entertainment Business',
            'Adult Entertainment',
            'Amusement Park',
            'Art Gallery',
            'Casino',
            'Comedy Club',
            'Movie Theater',
            'Night Club',
            'Financial Service',
            'Accounting Service',
            'Automated Teller',
            'Bank Or CreditUnion',
            'Insurance Agency',
            'Food Establishment',
            'Bakery',
            'Bar Or Pub',
            'Brewery',
            'Cafe Or CoffeeShop',
            'Distillery',
            'Fast Food Restaurant',
            'IceCream Shop',
            'Restaurant',
            'Winery',
            'Government Office',
            'Post Office',
            'Health And Beauty Business',
            'Beauty Salon',
            'Day Spa',
            'Hair Salon',
            'Health Club',
            'Nail Salon',
            'Tattoo Parlor',
            'Home And Construction Business',
            'Electrician',
            'General Contractor',
            'HVAC Business',
            'House Painter',
            'Locksmith',
            'Moving Company',
            'Plumber',
            'Roofing Contractor',
            'Internet Cafe',
            'Legal Service',
            'Attorney',
            'Notary',
            'Library',
            'Lodging Business',
            'Bed And Breakfast',
            'Campground',
            'Hostel',
            'Hotel',
            'Motel',
            'Resort',
            'Ski Resort',
            'Medical Business',
            'Community Health',
            'Dentist',
            'Dermatology',
            'Diet Nutrition',
            'Emergency',
            'Geriatric',
            'Gynecologic',
            'Medical Clinic',
            'Optician',
            'Pharmacy',
            'Physician',
            'Professional Service',
            'Radio Station',
            'Real Estate Agent',
            'Recycling Center',
            'Self Storage',
            'Shopping Center',
            'Sports Activity Location',
            'Bowling Alley',
            'Exercise Gym',
            'Golf Course',
            'Public Swimming Pool',
            'Ski Resort',
            'Sports Club',
            'Stadium Or Arena',
            'Tennis Complex',
            'Store',
            'Bike Store',
            'Book Store',
            'Clothing Store',
            'Computer Store',
            'Convenience Store',
            'Department Store',
            'Electronics Store',
            'Florist',
            'Furniture Store',
            'Garden Store',
            'Grocery Store',
            'Hardware Store',
            'Hobby Shop',
            'Home Goods Store',
            'Jewelry Store',
            'Liquor Store',
            'Mens Clothing Store',
            'Mobile Phone Store',
            'Movie Rental Store',
            'Music Store',
            'Office Equipment Store',
            'Outlet Store',
            'Pawn Shop',
            'Pet Store',
            'Shoe Store',
            'Sporting GoodsStore',
            'Tire Shop',
            'Toy Store',
            'Wholesale Store',
            'Television Station',
            'Tourist Information Center',
            'Travel Agency',
            'Tree Services',
            'Medical Organization',
            'Diagnostic Lab',
            'Veterinary Care',
            'NGO',
            'News Media Organization',
            'Performing Group',
            'Dance Group',
            'Music Group',
            'Theater Group',
            'Project',
            'Funding Agency',
            'Research Project',
            'Sports Organization',
            'Sports Team',
            'Workers Union',
        ];

        return $business_type;
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
        try {
            # Check nonce for security and return early if invalid
            if (!isset($_POST['meta_sync_seo_controls_nonce']) || !wp_verify_nonce($_POST['meta_sync_seo_controls_nonce'], 'meta_sync_seo_controls_nonce')) {
                #send invalid nonce message
                wp_send_json_error(array('message' => 'Invalid nonce'));
                return;
            }

            # Get current options
            $current_options = Metasync::get_option();

            # Store original options for comparison later
            $original_options = json_decode(json_encode($current_options), true); // Deep copy

        # Initialize seo_controls section if it doesn't exist
        if (!isset($current_options['seo_controls'])) {
            $current_options['seo_controls'] = [];
        }

        # Handle checkbox fields - they only send data when checked

         $seo_control_fields = [
            'index_date_archives',
            'index_tag_archives',
            'index_author_archives',
            'index_category_archives',
            'index_format_archives',
            'override_robots_tags',
            'add_nofollow_to_external_links',
            'enable_googleinstantindex',
            'enable_binginstantindex'
            ];

        foreach ($seo_control_fields as $field) {
            if (isset($_POST['metasync_options']['seo_controls'][$field]) && $_POST['metasync_options']['seo_controls'][$field] === 'true') {
                # Checkbox is checked
                $current_options['seo_controls'][$field] = 'true';
            } else {
                # Checkbox is unchecked (default behavior we want)
                $current_options['seo_controls'][$field] = 'false';
            }
        }

        # Save Bing Instant Indexing inline settings if present
        $bing_save_result = true;
        if (isset($_POST['metasync_bing_api_key_inline'])) {
            $bing_save_result = $this->save_bing_inline_settings_ajax();
        }

        # Check if options have actually changed by comparing with original
        $options_changed = (json_encode($original_options) !== json_encode($current_options));

        # Save the updated options
        $result = Metasync::set_option($current_options);

        # If set_option returns false but options haven't changed, that's actually success
        # WordPress/options API returns false when the value is identical (no update needed)
        if (!$result && !$options_changed) {
            $result = true;
        }

            if ($result && $bing_save_result) {
                $success_message = 'Indexation Control settings saved successfully!';
                if (isset($_POST['metasync_bing_api_key_inline'])) {
                    $success_message = 'Indexation Control and Bing Instant Indexing settings saved successfully!';
                }
                wp_send_json_success(array(
                    'message' => $success_message,
                    'saved_data' => $current_options['seo_controls']
                ));
            } else {
                $error_message = 'Failed to save Indexation Control settings';
                if (!$bing_save_result) {
                    $error_message = 'Failed to save Bing Instant Indexing settings';
                }
                wp_send_json_error(array('message' => $error_message));
            }
        } catch (Exception $e) {
            error_log('Indexation Control AJAX Exception: ' . $e->getMessage());
            error_log('Indexation Control AJAX Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error(array('message' => 'Error saving settings: ' . $e->getMessage()));
        }
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
        global $wpdb;
        
        $cleanup_stats = array(
            'expired_transients' => 0,
            'plugin_transients' => 0,
            'rate_limit_transients' => 0,
            'telemetry_transients' => 0,
            'start_time' => microtime(true)
        );
        
        try {
            // 1. Clean up expired transients using WordPress built-in function
            delete_expired_transients(true); // Force database cleanup
            $cleanup_stats['expired_transients'] = 'cleaned_by_wordpress';
            
            // 2. Clean up MetaSync-specific transients that might be stuck
            $plugin_transients = $wpdb->get_results(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_metasync_%'",
                ARRAY_A
            );
            
            foreach ($plugin_transients as $transient) {
                $transient_name = str_replace('_transient_', '', $transient['option_name']);
                delete_transient($transient_name);
                $cleanup_stats['plugin_transients']++;
            }
            
            // 3. Clean up Search Atlas connect rate limit transients
            $rate_limit_transients = $wpdb->get_results(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_sa_connect_rate_limit_%'",
                ARRAY_A
            );
            
            foreach ($rate_limit_transients as $transient) {
                $transient_name = str_replace('_transient_', '', $transient['option_name']);
                delete_transient($transient_name);
                $cleanup_stats['rate_limit_transients']++;
            }
            
            // 4. Clean up telemetry transients
            $telemetry_transients = $wpdb->get_results(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_metasync_telemetry_%'",
                ARRAY_A
            );
            
            foreach ($telemetry_transients as $transient) {
                $transient_name = str_replace('_transient_', '', $transient['option_name']);
                delete_transient($transient_name);
                $cleanup_stats['telemetry_transients']++;
            }
            
            // 5. Clean up Search Atlas connect success flags
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
        // Check user capabilities
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        // Verify nonce
        if (!check_ajax_referer('metasync_otto_excluded_urls', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        // Get POST data
        $url_pattern = isset($_POST['url_pattern']) ? sanitize_text_field($_POST['url_pattern']) : '';
        $pattern_type = isset($_POST['pattern_type']) ? sanitize_text_field($_POST['pattern_type']) : 'exact';
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';

        // Validate inputs
        if (empty($url_pattern)) {
            wp_send_json_error(['message' => 'URL pattern is required']);
            return;
        }

        // Validate pattern type
        $valid_types = ['exact', 'contain', 'start', 'end', 'regex'];
        if (!in_array($pattern_type, $valid_types)) {
            wp_send_json_error(['message' => 'Invalid pattern type']);
            return;
        }

        // Validate regex if pattern type is regex
        if ($pattern_type === 'regex') {
            // Add delimiters if missing - check if pattern is properly enclosed in matching delimiters
            $test_pattern = $url_pattern;
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

            if (@preg_match($test_pattern, '') === false) {
                wp_send_json_error(['message' => 'Invalid regular expression pattern']);
                return;
            }
        }

        // Load database class
        require_once plugin_dir_path(dirname(__FILE__)) . 'otto/class-metasync-otto-excluded-urls-database.php';
        $db = new Metasync_Otto_Excluded_URLs_Database();

        // Add to database
        $result = $db->add([
            'url_pattern' => $url_pattern,
            'pattern_type' => $pattern_type,
            'description' => $description,
            'status' => 'active',
        ]);

        // Handle different result types
        if ($result === 'duplicate') {
            wp_send_json_error([
                'message' => 'This URL pattern already exists in the exclusion list',
                'code' => 'duplicate'
            ]);
            return;
        }

        if ($result === 'reactivated') {
            // Clear cache for reactivated URL
            $db->clear_cache();

            // If it's an exact URL, try to clear OTTO cache for it
            if ($pattern_type === 'exact') {
                try {
                    if (class_exists('Metasync_Cache_Purge')) {
                        $cache_purge = Metasync_Cache_Purge::get_instance();
                        $cache_purge->clear_url_cache($url_pattern);
                    }
                    wp_cache_flush();
                } catch (Exception $e) {
                    error_log('MetaSync: Failed to clear cache for reactivated URL: ' . $e->getMessage());
                }
            }

            wp_send_json_success([
                'message' => 'Previously inactive URL pattern has been reactivated. Cache cleared.',
            ]);
            return;
        }

        if ($result === true) {
            // Clear cache for this URL pattern
            $db->clear_cache();

            // If it's an exact URL, try to clear OTTO cache for it
            if ($pattern_type === 'exact') {
                try {
                    // Clear any cached OTTO content for this URL
                    if (class_exists('Metasync_Cache_Purge')) {
                        $cache_purge = Metasync_Cache_Purge::get_instance();
                        $cache_purge->clear_url_cache($url_pattern);
                    }

                    // Clear WordPress object cache
                    wp_cache_flush();
                } catch (Exception $e) {
                    error_log('MetaSync: Failed to clear cache for excluded URL: ' . $e->getMessage());
                }
            }

            wp_send_json_success([
                'message' => sprintf('URL excluded from %s successfully. Cache cleared.', Metasync::get_whitelabel_otto_name()),
            ]);
        } else {
            wp_send_json_error(['message' => 'Failed to add excluded URL']);
        }
    }

    /**
     * AJAX handler to delete excluded URL for OTTO
     */
    public function ajax_otto_delete_excluded_url()
    {
        // Check user capabilities
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        // Verify nonce
        if (!check_ajax_referer('metasync_otto_excluded_urls', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        // Get POST data
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($id <= 0) {
            wp_send_json_error(['message' => 'Invalid ID']);
            return;
        }

        // Load database class
        require_once plugin_dir_path(dirname(__FILE__)) . 'otto/class-metasync-otto-excluded-urls-database.php';
        $db = new Metasync_Otto_Excluded_URLs_Database();

        // Delete from database
        $result = $db->delete([$id]);

        if ($result) {
            wp_send_json_success([
                'message' => 'Excluded URL deleted successfully',
            ]);
        } else {
            wp_send_json_error(['message' => 'Failed to delete excluded URL']);
        }
    }

    /**
     * AJAX handler to recheck if an excluded URL is now available
     * Used for "Recheck" action on Excluded URLs list
     */
    public function ajax_otto_recheck_excluded_url()
    {
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        if (!check_ajax_referer('metasync_otto_excluded_urls', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => 'Invalid ID']);
            return;
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'otto/class-metasync-otto-excluded-urls-database.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'otto/otto_pixel.php';

        $db = new Metasync_Otto_Excluded_URLs_Database();
        $row = $db->get_record_by_id($id);

        if (!$row || empty($row->url_pattern)) {
            wp_send_json_error(['message' => 'Excluded URL not found']);
            return;
        }

        $url = trim($row->url_pattern);
        $available = metasync_otto_is_url_available($url);

        wp_send_json_success([
            'available' => $available,
            'url' => $url,
        ]);
    }

    /**
     * AJAX handler to get excluded URLs with pagination
     */
    public function ajax_otto_get_excluded_urls()
    {
        // Check user capabilities
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        // Verify nonce
        if (!check_ajax_referer('metasync_otto_excluded_urls', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        // Get pagination parameters
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;

        if ($page < 1) {
            $page = 1;
        }
        if ($per_page < 1 || $per_page > 100) {
            $per_page = 10;
        }

        // Load database class
        require_once plugin_dir_path(dirname(__FILE__)) . 'otto/class-metasync-otto-excluded-urls-database.php';
        $db = new Metasync_Otto_Excluded_URLs_Database();

        // Get paginated records
        $records = $db->get_paginated_records($per_page, $page);
        $total_count = $db->get_total_count();
        $total_pages = ceil($total_count / $per_page);

        wp_send_json_success([
            'records' => $records,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total_count' => $total_count,
                'total_pages' => $total_pages,
            ],
        ]);
    }
    
    /**
     * AJAX handler for submitting issue reports to Sentry
     * 
     * @since 2.5.10
     * @return void Sends JSON response and exits
     */
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
                # Prepare user feedback data according to Sentry User Feedback API
                # Reference: https://docs.sentry.io/platforms/javascript/user-feedback/#user-feedback-api
                # Note: The API uses 'message' as the key (not 'comments')
                # Pass only the user's description - captureFeedback will handle title and severity
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
                
                # Send to Sentry using User Feedback API with attachment
                # The captureFeedback method will:
                # 1. Create an event with title "Client Report {UUID}" and message with severity
                # 2. Send feedback with the user's description including severity
                # 3. Attach the uploaded image (if any) to the event
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
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'metasync_recover_password_nonce')) {
                wp_send_json_error(array('message' => 'Security verification failed.'));
                return;
            }

            // Get whitelabel settings
            $whitelabel_settings = Metasync::get_whitelabel_settings();
            $password = $whitelabel_settings['settings_password'] ?? '';
            $recovery_email = $whitelabel_settings['recovery_email'] ?? '';

            // Validate that password and recovery email are configured
            if (empty($password)) {
                wp_send_json_error(array('message' => 'No password is configured for recovery.'));
                return;
            }

            if (empty($recovery_email) || !is_email($recovery_email)) {
                wp_send_json_error(array('message' => 'No valid recovery email is configured. Please contact your administrator.'));
                return;
            }

            // Prepare email with whitelabel support
            $site_name = get_bloginfo('name');
            $site_url = home_url();
            $plugin_name = Metasync::get_effective_plugin_name('');
            $settings_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=whitelabel');
            $to = $recovery_email;

            // Use whitelabel-aware subject line
            $subject = sprintf('[%s] Settings Password Recovery', $site_name);

            // Build email message with proper whitelabel branding
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

            // Use whitelabel-aware From header
            $from_name = !empty($whitelabel_settings['company_name'])
                ? $whitelabel_settings['company_name']
                : $site_name;

            $headers = array(
                'Content-Type: text/plain; charset=UTF-8',
                sprintf('From: %s <%s>', $from_name, get_option('admin_email'))
            );

            // Capture wp_mail errors
            $mail_error = '';
            add_action('wp_mail_failed', function($error) use (&$mail_error) {
                $mail_error = $error->get_error_message();
            });

            // Send email
            $sent = wp_mail($to, $subject, $message, $headers);

            if ($sent) {
                wp_send_json_success(array(
                    'message' => sprintf('Password recovery email sent to %s', esc_html($recovery_email))
                ));
            } else {
                // Log the detailed error
                // if (defined('WP_DEBUG') && WP_DEBUG) {
                //     error_log(sprintf(
                //         '%s Password Recovery Email Failed: To: %s, Error: %s',
                //         $plugin_name,
                //         $recovery_email,
                //         $mail_error ?: 'Unknown error'
                //     ));
                // }

                // Return detailed error message if available
                $error_message = 'Failed to send recovery email. ';
                if (!empty($mail_error)) {
                    $error_message .= 'Error: ' . $mail_error;
                } else {
                    $error_message .= 'Your server may not be configured to send emails. Please check your email configuration or contact your administrator.';
                }

                wp_send_json_error(array('message' => $error_message));
            }

        } catch (Exception $e) {
            // Log the error securely (only in debug mode)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'MetaSync Password Recovery Error: %s in %s on line %d',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
            }

            // Send generic error message to client
            wp_send_json_error(array('message' => 'An error occurred while processing your request. Please try again later.'));
        }
    }

    /**
     * AJAX handler for saving theme preference
     * Saves the user's theme choice (light/dark) to WordPress options
     */
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

    /**
     * AJAX handler for tracking 1-click activation in Mixpanel
     */
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

    /**
     * Handler for exporting whitelabel settings to a zip file
     * Uses admin-post action for file downloads
     */
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
                'whitelabel-settings.json' // Exclude if it already exists
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

    /**
     * Recursively add directory contents to zip file
     * 
     * @param ZipArchive $zip The zip archive object
     * @param string $dir The directory to add (with trailing slash)
     * @param string $zip_path The path within the zip file (with trailing slash)
     * @param array $exclude_patterns Patterns to exclude
     */
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
            
            # Check if file/directory should be excluded
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
                # Add directory and recurse
                $zip->addEmptyDir($zip_file_path);
                self::add_directory_to_zip($zip, $file_path . '/', $zip_file_path . '/', $exclude_patterns);
            } else {
                # Add file to zip
                if (file_exists($file_path) && is_readable($file_path)) {
                    $zip->addFile($file_path, $zip_file_path);
                }
            }
        }
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
        // Check for whitelabel company name
        $whitelabel_company = Metasync::get_whitelabel_company_name();
        if (!empty($whitelabel_company)) {
            return $whitelabel_company . ' AI';
        }

        // Default to SearchAtlas AI
        return 'SearchAtlas AI';
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
        <script type="text/javascript">
        (function($) {
            // Copy badge from posts list to quick edit panel
            var wp_inline_edit = inlineEditPost.edit;
            inlineEditPost.edit = function(id) {
                wp_inline_edit.apply(this, arguments);

                var post_id = 0;
                if (typeof(id) === 'object') {
                    post_id = parseInt(this.getId(id));
                }

                if (post_id > 0) {
                    var $row = $('#post-' + post_id);
                    var $badge = $row.find('.metasync-html-badge').clone();

                    if ($badge.length) {
                        $('.metasync-quick-edit-badge-container').html($badge);
                    } else {
                        $('.metasync-quick-edit-badge-container').html('<em><?php _e('Standard page', 'metasync'); ?></em>');
                    }
                }
            };
        })(jQuery);
        </script>
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
        global $wpdb;

        // Query for pages with HTML conversion meta
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
            echo '<a href="' . admin_url('admin.php?page=searchatlas') . '" class="button button-primary">';
            echo __('Get Started', 'metasync');
            echo '</a>';
            echo '</p>';
            echo '</div>';
            return;
        }

        echo '<div class="metasync-dashboard-widget">';

        // Summary stats
        echo '<div class="metasync-widget-stats">';
        echo '<div class="metasync-stat-box">';
        echo '<span class="metasync-stat-number">' . $total_count . '</span>';
        echo '<span class="metasync-stat-label">' . __('AI-Generated Pages', 'metasync') . '</span>';
        echo '</div>';
        echo '</div>';

        // Recent pages list
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

        // Footer with link to all pages
        echo '<div class="metasync-widget-footer">';
        echo '<a href="' . admin_url('edit.php?post_type=page') . '">';
        echo __('View All Pages', 'metasync') . ' →';
        echo '</a>';
        echo '</div>';

        echo '</div>';
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
        require_once plugin_dir_path(dirname(__FILE__)) . 'instant-index/class-metasync-instant-index.php';
        $instant_index = new Metasync_Instant_Index();
        $instant_index->send();
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
            require_once plugin_dir_path(dirname(__FILE__)) . 'instant-index/class-metasync-instant-index.php';
            $instant_index = new Metasync_Instant_Index();
            $instant_index->save_settings();
        }

        // Note: Bing Instant Indexing settings are saved via AJAX in save_bing_inline_settings_ajax()
    }

    /**
     * Save Bing instant indexing settings from inline form (Indexation Control page)
     *
     * @since 2.6.0
     * @return bool True on success, false on failure
     */
    private function save_bing_inline_settings_ajax()
    {
        $api_key = isset($_POST['metasync_bing_api_key_inline']) ? sanitize_text_field(wp_unslash($_POST['metasync_bing_api_key_inline'])) : '';
        $endpoint = isset($_POST['metasync_bing_endpoint_inline']) ? sanitize_text_field(wp_unslash($_POST['metasync_bing_endpoint_inline'])) : 'indexnow';
        $post_types = isset($_POST['metasync_bing_post_types_inline']) && is_array($_POST['metasync_bing_post_types_inline']) ? array_map('sanitize_title', wp_unslash($_POST['metasync_bing_post_types_inline'])) : [];
        $disable_other_plugins = isset($_POST['metasync_bing_disable_other_plugins_inline']) ? true : false;

        // Get existing settings
        $existing_settings = get_option('metasync_options_bing_instant_indexing', []);

        // Prepare new settings
        $new_settings = [
            'api_key'    => $api_key,
            'endpoint'   => $endpoint,
            'post_types' => array_values($post_types),
            'disable_other_plugins' => $disable_other_plugins,
        ];

        // Merge with existing settings
        $settings = array_merge($existing_settings, $new_settings);

        // Check if settings have actually changed
        $settings_changed = (json_encode($existing_settings) !== json_encode($settings));

        // Save to database
        $result = update_option('metasync_options_bing_instant_indexing', $settings);

        // If update_option returns false but settings haven't changed, that's actually success
        // WordPress returns false when the value is identical (no update needed)
        if (!$result && !$settings_changed) {
            $result = true;
        }

        // Generate API key file if API key is provided
        if (!empty($api_key)) {
            $file_path = ABSPATH . $api_key . '.txt';
            $file_result = file_put_contents($file_path, $api_key);

            if ($file_result === false) {
                error_log('Bing IndexNow: Failed to create API key verification file at ' . $file_path);
                return false;
            }

            // Auto-enable Bing Instant Indexing when API key is configured
            $current_options = Metasync::get_option();
            if (!isset($current_options['seo_controls']['enable_binginstantindex']) || $current_options['seo_controls']['enable_binginstantindex'] !== 'true') {
                $current_options['seo_controls']['enable_binginstantindex'] = 'true';
                Metasync::set_option($current_options);
            }
        }

        return $result;
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
        require_once plugin_dir_path(dirname(__FILE__)) . 'instant-index/class-metasync-instant-index.php';
        $instant_index = new Metasync_Instant_Index();
        $actions = $instant_index->google_instant_index_post_link($actions, $post);

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
        // Auto-submit to Google Instant Indexing
        require_once plugin_dir_path(dirname(__FILE__)) . 'instant-index/class-metasync-instant-index.php';
        $instant_index = new Metasync_Instant_Index();
        // Note: You'd need to add an auto_submit method to the Google class similar to Bing
        // For now, we'll just handle Bing

        // Auto-submit to Bing Instant Indexing
        require_once plugin_dir_path(dirname(__FILE__)) . 'bing-index/class-metasync-bing-instant-index.php';
        $bing_instant_index = new Metasync_Bing_Instant_Index();
        $bing_instant_index->auto_submit_on_publish($post_id, $post, $update);
    }
}
