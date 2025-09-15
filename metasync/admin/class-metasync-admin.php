<?php

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
    public $menu_title         = "Search Atlas";
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
        'enable_googleinstantindex' => 'Enable Google Instant Index',
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
        add_action('admin_init', array($this, 'settings_page_init'));
        add_filter('all_plugins',  array($this,'metasync_plugin_white_label'));
        add_filter( 'plugin_row_meta',array($this,'metasync_view_detials_url'),10,3);
        
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
        
        
        add_action('admin_init', array($this, 'initialize_cookie'));

        // Add AJAX for saving general settings 
        add_action( 'wp_ajax_meta_sync_save_settings', array($this,'meta_sync_save_settings') );
        
        // Add AJAX handler for Plugin Auth Token refresh
        add_action('wp_ajax_refresh_plugin_auth_token', array($this, 'refresh_plugin_auth_token'));
        
        // Add AJAX handler to get current Plugin Auth Token (for UI updates)
        add_action('wp_ajax_get_plugin_auth_token', array($this, 'get_plugin_auth_token'));
        
        // Add heartbeat cron functionality
        add_filter('cron_schedules', array($this, 'add_heartbeat_cron_schedule'));
        add_action('metasync_heartbeat_cron_check', array($this, 'execute_heartbeat_cron_check'));
        
        // Schedule heartbeat cron on plugin load (if not already scheduled)
        add_action('init', array($this, 'maybe_schedule_heartbeat_cron'));
        
        // Listen for immediate heartbeat trigger requests from other parts of the plugin
        add_action('metasync_trigger_immediate_heartbeat', array($this, 'handle_immediate_heartbeat_trigger'));
        
        // Listen for cron scheduling requests (after SSO authentication)
        add_action('metasync_ensure_heartbeat_cron_scheduled', array($this, 'maybe_schedule_heartbeat_cron'));
        
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
        add_action('pre_delete_term', array($this,'admin_delete_term'), 10, 2);

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
            if (in_array('metasync/metasync.php', $updated_plugins)) {
                update_option('wp_debug_enabled', 'true');
                update_option('wp_debug_log_enabled', 'true');
                update_option('wp_debug_display_enabled','false');
                #$this->metasync_update_wp_config();               
            }
        }
    }
    
    public function initialize_cookie() {
        // Check if cookie is already set
        if (!isset($_COOKIE['metasync_previous_slug'])) {
            $data =Metasync::get_option('general');
            // Retrieve the current slug
            $initial_slug = isset($data['white_label_plugin_menu_slug'] )?$data['white_label_plugin_menu_slug']: self::$page_slug;
            // Set the cookie
            setcookie('metasync_previous_slug', $initial_slug, time() + 3600, COOKIEPATH, COOKIE_DOMAIN);
        }
    }

    /*
    create a error log on the wp-content folder for metasync plugin
    */
    
    public function metasync_log_error($error_message) {
        $log_file = WP_CONTENT_DIR . '/metasync.log'; // Adjust the path if needed
        $timestamp = date("Y-m-d H:i:s");
        $message = "[$timestamp] - $error_message\n";
        error_log($message, 3, $log_file);
    }

    /*

    */

    public function metasync_log_php_errors($errno, $errstr, $errfile, $errline) {
        $error_message = "Error [$errno]: $errstr in $errfile on line $errline";
        $this->metasync_log_error($error_message);
    }

    /*

    */

    public function metasync_display_error_log() {
        $log_file = WP_CONTENT_DIR . '/metasync_data/plugin_errors.log';
        if (!current_user_can('manage_options')) {
            return;
        }
    
        // Handle form submission for plugin logging

       
        // Handle form submission for WordPress error logging
        
        if (isset($_POST['wp_debug_log_enabled'])&& isset($_POST['wp_debug_enabled'])&& isset($_POST['wp_debug_display_enabled'])) {
           
            update_option('wp_debug_enabled', ($_POST['wp_debug_enabled']=='true')?'true':'false');
            update_option('wp_debug_log_enabled', ($_POST['wp_debug_log_enabled']=='true')?'true':'false');
            update_option('wp_debug_display_enabled', ($_POST['wp_debug_display_enabled']=='true')?'true':'false');
            $data = new ConfigControllerMetaSync();
            $data->store();

        }
       
    
        $log_enabled = get_option('metasync_log_enabled', 'yes');
        $wp_debug_enabled = get_option('wp_debug_enabled', 'false');
        $wp_debug_log_enabled = get_option('wp_debug_log_enabled', 'false');
        $wp_debug_display_enabled = get_option('wp_debug_display_enabled', 'false');
        ?>
    
        <div class="wrap metasync-dashboard-wrap">
        
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
                <div class="dashboard-card">
                    <h2>🔧 WordPress Debug Configuration</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure WordPress debug settings to control error logging and display.</p>
                    
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
        if ('metasync/metasync.php' === $plugin_file && $plugin_uri!=='') {
            foreach ($plugin_meta as &$meta) {
                if (strpos($meta, 'open-plugin-details-modal') !== false) {
                    $meta = sprintf(
                        '<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
                        add_query_arg('TB_iframe', 'true', $plugin_uri),
                        esc_attr(sprintf(__('More information about %s'), $plugin_data['Name'])),
                        esc_attr($plugin_data['Name']),
                        __('View details')
                    );
                    break; // Exit loop after replacing the link
                }
            }
        }
        return $plugin_meta;
    }

    public function metasync_plugin_white_label($all_plugins) {
        // Check if the current user is an administrator
       
            $plugin_name =  Metasync::get_option('general')['white_label_plugin_name'] ?? ''; 
            $plugin_description = Metasync::get_option('general')['white_label_plugin_description'] ?? ''; 
            $plugin_author =  Metasync::get_option('general')['white_label_plugin_author'] ?? ''; 
            $plugin_author_uri =  Metasync::get_option('general')['white_label_plugin_author_uri'] ?? ''; 
            $plugin_uri = Metasync::get_option('general')['white_label_plugin_uri'] ?? ''; // New option for Plugin URI

            foreach ($all_plugins as $plugin_file => $plugin_data) {
                if ($plugin_file == 'metasync/metasync.php') {
                    if($plugin_name!=''){
                        $all_plugins[$plugin_file]['Name'] = $plugin_name;
                    }else{
                        $all_plugins[$plugin_file]['Name'] = $all_plugins[$plugin_file]['Name'];
                    }
                    if($plugin_description!=''){
                        $all_plugins[$plugin_file]['Description'] = $plugin_description;
                    }else{
                        $all_plugins[$plugin_file]['Description'] =  $all_plugins[$plugin_file]['Description'];
                    }
                    if($plugin_author!=''){
                        $all_plugins[$plugin_file]['Author'] = $plugin_author;
                    }else{
                        $all_plugins[$plugin_file]['Author'] = $all_plugins[$plugin_file]['Author'];
                    }
                    if($plugin_author_uri!=''){
                        $all_plugins[$plugin_file]['AuthorURI'] = $plugin_author_uri;
                    }else{
                        $all_plugins[$plugin_file]['AuthorURI'] =  $all_plugins[$plugin_file]['AuthorURI'];
                    }       
                    if($plugin_uri!=''){
                        
                        $all_plugins[$plugin_file]['PluginURI'] = $plugin_uri;
                    }else{
                        $all_plugins[$plugin_file]['PluginURI'] =  $all_plugins[$plugin_file]['PluginURI'];
                    }             
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
        // Localize the script to make the AJAX URL accessible
        $options = Metasync::get_option('general');
        // Get connection status for JavaScript
        $general_settings = Metasync::get_option('general');
        $searchatlas_api_key = isset($general_settings['searchatlas_api_key']) ? $general_settings['searchatlas_api_key'] : '';
        $otto_pixel_uuid = isset($general_settings['otto_pixel_uuid']) ? $general_settings['otto_pixel_uuid'] : '';
        
        wp_localize_script( $this->plugin_name, 'metaSync', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
			'admin_url'=>admin_url('admin.php'),
			'sso_nonce' => wp_create_nonce('metasync_sso_nonce'),
			'reset_auth_nonce' => wp_create_nonce('metasync_reset_auth_nonce'),
			'dashboard_domain' => self::get_effective_dashboard_domain(),
			'support_email' => Metasync::SUPPORT_EMAIL,
			'documentation_domain' => Metasync::DOCUMENTATION_DOMAIN,
			'debug_enabled' => WP_DEBUG || (defined('METASYNC_DEBUG') && METASYNC_DEBUG),
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
            var ajaxurl = '" . admin_url('admin-ajax.php') . "';
            console.warn('🔧 MetaSync: ajaxurl was undefined, manually set to: ' + ajaxurl);
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
     * Conditional SSO Validation wrapper
     * Only performs SSO validation when there's actually a token in the request
     */
    public function conditional_sso_validation(){

        $general_options = Metasync::get_option('general') ?? [];
        $sso_disabled = $general_options['disable_single_signup_login'] ?? false;

        # Check if SSO is disabled
        if($sso_disabled){
            # Return true to allow normal page access (but block SSO)
            return true;
        }

        // Only run SSO validation if there's actually a token parameter in the request
        if (isset($_GET['metasync_auth_token'])) {
            return $this->validate_sso_token();
        }
        
        // For normal page navigation, don't interfere
        return true;
    }

    /**
     * SSO Validation function
     * Enhanced with WordPress SALT-based security validation
     * Gets the token from the request and validates it
     */
    public function validate_sso_token(){

        // Skip validation if user is already logged in
        if (is_user_logged_in()) {
            return true;
        }

        # check that request has get parameter token
        if(!isset($_GET['metasync_auth_token'])){
            return false;
        }
    
        # get the token from the request and sanitize
        $token = sanitize_text_field($_GET['metasync_auth_token']);

        # validate token format (simplified for apikey compatibility)
        if(empty($token)){
            return false;
        }

        # get stored apikey for validation
        $general_options = Metasync::get_option('general') ?? [];
        $stored_apikey = $general_options['apikey'] ?? '';

        if(empty($stored_apikey)){
            return false;
        }

        # simple token validation using apikey
        if($token !== $stored_apikey){
                return false;
            }

            # get first admin user
            $admin_user = get_users(array('role' => 'administrator', 'number' => 1));
            
            # check that we have an admin user
            if(empty($admin_user)){
                return false;
            }

        # sync the customer params with existing apikey
            $sync_request = new Metasync_Sync_Requests();
        $sync_response = $sync_request->SyncCustomerParams($stored_apikey);

            # get the first admin user
            $admin_user = $admin_user[0];

        # log the user in and create a session
            wp_set_current_user($admin_user->ID);
            wp_set_auth_cookie($admin_user->ID);

            # redirect to admin dashboard
            wp_redirect(admin_url());
            exit;
        }

    /**
     * Additional context validation for enhanced SSO tokens
     */
    private function validate_enhanced_sso_context($token_data)
    {
        # validate site URL context
        if(isset($token_data['site_url']) && $token_data['site_url'] !== get_site_url()){
        return false;
        }

        # IP address validation (optional - can be disabled for mobile/proxy users)
        if(isset($token_data['ip']) && $this->should_validate_ip()){
            $current_ip = $this->get_client_ip();
            if($token_data['ip'] !== $current_ip){
                error_log('SSO Validation: IP address changed from ' . $token_data['ip'] . ' to ' . $current_ip);
                # For now, just log the change but don't fail (mobile users, etc.)
                # return false;
            }
        }

        # user agent validation (loose check)
        if(isset($token_data['user_agent']) && !empty($_SERVER['HTTP_USER_AGENT'])){
            $current_ua = substr($_SERVER['HTTP_USER_AGENT'], 0, 100);
            # Only check if user agents are dramatically different (not just version updates)
            if($this->are_user_agents_incompatible($token_data['user_agent'], $current_ua)){
                error_log('SSO Validation: Significant user agent change detected');
                # For now, just log but don't fail (browser updates are common)
                # return false;
            }
        }

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
     * WP SSO Function
     * Enhanced with WordPress SALT-based security
     * This function generates a token for the WP SSO
     */
    public function generate_wp_sso_token($regenerate = false){
        # Simplified: Use Plugin Auth Token directly (same as other token functions)
        $general_options = Metasync::get_option('general') ?? [];
        $plugin_auth_token = $general_options['apikey'] ?? '';
        
        if (empty($plugin_auth_token)) {
            error_log('ERROR: Plugin Auth Token missing from options - should have been generated during activation');
            return false;
        }

        return $plugin_auth_token;
    }

    /**
     * Ensure Plugin Auth Token exists before SSO authentication
     * Auto-generates if missing to ensure smooth authentication flow
     */
    private function ensure_plugin_auth_token_exists()
    {
        $options = Metasync::get_option();
        $current_plugin_auth_token = $options['general']['apikey'] ?? '';
        
        // Check if Plugin Auth Token is missing or empty
        if (empty($current_plugin_auth_token)) {
            error_log('SSO_AUTH_TOKEN_DEBUG: Plugin Auth Token is missing - auto-generating new token');
            
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
                Metasync::log_api_key_event('auto_generated_for_sso', 'plugin_auth_token', array(
                    'new_token_prefix' => substr($new_plugin_auth_token, 0, 8) . '...',
                    'triggered_by' => 'sso_connect_button',
                    'reason' => 'Plugin Auth Token was missing before SSO authentication'
                ), 'info');
                
                error_log('SSO_AUTH_TOKEN_DEBUG: Plugin Auth Token auto-generated successfully: ' . substr($new_plugin_auth_token, 0, 8) . '...');
            } else {
                error_log('SSO_AUTH_TOKEN_DEBUG: ERROR - Failed to save auto-generated Plugin Auth Token');
                throw new Exception('Failed to generate required authentication token');
            }
        } else {
            error_log('SSO_AUTH_TOKEN_DEBUG: Plugin Auth Token already exists: ' . substr($current_plugin_auth_token, 0, 8) . '...');
        }
    }

    /**
     * SSO URL Generation
     * Generates a unique nonce token and SSO URL for Search Atlas authentication
     */
    public function generate_sso_url()
    {

        
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'metasync_sso_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce - please refresh the page and try again'));
            return;
        }

        error_log('SSO_URL_GENERATION_DEBUG: Starting SSO URL generation');

        try {
            // Ensure Plugin Auth Token exists before starting SSO process
            $this->ensure_plugin_auth_token_exists();
            
            // Generate unique nonce token
            $nonce_token = $this->create_sso_nonce_token();
            
            error_log('SSO_URL_GENERATION_DEBUG: Nonce token created: ' . ($nonce_token ? 'SUCCESS' : 'FAILED'));
            
            if (!$nonce_token) {
                error_log('SSO_URL_GENERATION_DEBUG: Nonce token generation failed - returning error');
                wp_send_json_error(array('message' => 'Failed to create authentication token'));
                return;
            }
            
            // Get WordPress domain (without /wp-admin)
            # $domain = get_site_url();
            # Remove "www." from the URL in case the site URL includes it
            $domain = str_replace('://www.', '://', get_site_url());
            
            // Get effective dashboard domain
            $dashboard_domain = self::get_effective_dashboard_domain();
            
            // Construct SSO URL
            $sso_url = $dashboard_domain . '/sso/wordpress?' . http_build_query([
                'nonce_token' => $nonce_token,
                'domain' => $domain,
                'return_url' => admin_url('admin.php?page=' . self::$page_slug)
            ]);
            
            error_log('SSO_URL_GENERATION_DEBUG: SSO URL generated successfully - dashboard_domain: ' . $dashboard_domain . ', nonce_token: ' . substr($nonce_token, 0, 8) . '...');


            wp_send_json_success(array(
                'sso_url' => $sso_url,
                'nonce_token' => $nonce_token,
                'debug_info' => array(
                    'dashboard_domain' => $dashboard_domain,
                    'site_domain' => $domain,
                    'return_url' => admin_url('admin.php?page=' . self::$page_slug)
                )
            ));

        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Failed to generate SSO URL: ' . $e->getMessage()));
        }
    }

    /**
     * Check SSO Status
     * Polls to check if the API key has been updated via SSO
     */
    public function check_sso_status()
    {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'metasync_sso_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }

        $nonce_token = sanitize_text_field($_POST['nonce_token']);
        
        // ✅ NEW: Check if THIS specific nonce was successfully processed
        // This prevents false positives from background sync/heartbeat activity
        $success_key = 'metasync_sso_success_' . md5($nonce_token);
        $this_auth_completed = get_transient($success_key);
        
        error_log('SSO_STATUS_CHECK_DEBUG: nonce_token=' . substr($nonce_token, 0, 8) . '..., checking success_key=' . substr($success_key, 0, 16) . '..., completed=' . ($this_auth_completed ? 'YES' : 'NO'));
        
        if ($this_auth_completed) {
            // Delete the transient (one-time use) to prevent replay
            delete_transient($success_key);
            
            // Get current settings to return API key
            $general_settings = Metasync::get_option('general') ?? [];
            
            error_log('SSO_STATUS_CHECK_DEBUG: Authentication successful for this nonce - returning updated=true');
            wp_send_json_success(array(
                'updated' => true,
                'api_key' => $general_settings['searchatlas_api_key'], // Return full API key
                'otto_pixel_uuid' => $general_settings['otto_pixel_uuid'] ?? '', // ✅ NEW: Return OTTO UUID for UI update
                'status_code' => 200,
                'whitelabel_enabled' => !empty($general_settings['white_label_plugin_name']),
                'effective_domain' => self::get_effective_dashboard_domain()
            ));
        }

        error_log('SSO_STATUS_CHECK_DEBUG: Authentication not complete for this nonce - returning updated=false');
        wp_send_json_success(array('updated' => false));
    }

    /**
     * Create SSO Nonce Token
     * Generates a unique, single-use token for SSO authentication
     * Enhanced with WordPress SALT-based security
     */
    private function create_sso_nonce_token()
    {
        // Create token payload with metadata
        $payload = array(
            'created' => time(),
            'expires' => time() + 1800, // 30 minutes
            'nonce' => wp_generate_password(16, false),
            'site_url' => get_site_url(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 100) : '',
            'ip' => $this->get_client_ip(),
            'version' => '2.0'
        );

        // Get existing Plugin Auth Token for base entropy
        $general_options = Metasync::get_option('general') ?? [];
        $plugin_auth_token = $general_options['apikey'] ?? '';
        
        // Debug logging to verify what we're reading
        error_log('SSO Nonce Generation - Plugin Auth Token from options: ' . ($plugin_auth_token ? substr($plugin_auth_token, 0, 8) . '...' : 'EMPTY'));
        
        // Plugin Auth Token should exist from activation - log if missing
        if (empty($plugin_auth_token)) {
            error_log('ERROR: Plugin Auth Token missing from options - should have been generated during activation');
            return false; // Don't generate random fallback
        }

        // Use Plugin Auth Token directly as nonce token
        error_log('SSO Nonce Generation - Final nonce token: ' . substr($plugin_auth_token, 0, 8) . '...');
        return $plugin_auth_token;
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
     * Create encrypted SSO token with embedded metadata (optional enhanced approach)
     * Use this for tokens that need to be self-contained
     */
    private function create_encrypted_sso_token($metadata = array())
    {
        // Create comprehensive payload
        $payload = array_merge(array(
            'iat' => time(),                    // Issued at
            'exp' => time() + 1800,            // Expires (30 minutes)
            'iss' => get_site_url(),           // Issuer
            'aud' => 'search-atlas-sso',       // Audience
            'sub' => 'sso-authentication',     // Subject
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
     * Test the enhanced SSO token system (development/debugging)
     */
    public function test_enhanced_sso_tokens()
    {
        if (!current_user_can('administrator')) {
            return false;
        }


        
        # Test 1: Using apikey for backward compatibility
        $general_options = Metasync::get_option('general') ?? [];
        $test_token = $general_options['apikey'] ?? null;
        error_log('Test 1 - Using apikey: ' . ($test_token ? substr($test_token, 0, 8) . '...' : 'NOT SET'));
        
        # Test 2: Apikey validation
        $apikey = $general_options['apikey'] ?? '';
        if ($apikey) {
            error_log('Test 2 - Apikey is available: YES');
            error_log('Test 2 - Apikey length: ' . strlen($apikey));
        } else {
            error_log('Test 2 - Apikey is available: NO');
        }
        
        # Test 3: Encrypted token test
        $encrypted_token = $this->create_encrypted_sso_token(['test' => 'data', 'user_id' => get_current_user_id()]);
        if ($encrypted_token) {
            error_log('Test 3 - Encrypted token generated successfully');
            
            $decrypted = $this->wp_decrypt_token($encrypted_token);
            if ($decrypted) {
                error_log('Test 3 - Encrypted token decrypted successfully: ' . json_encode($decrypted));
            } else {
                error_log('Test 3 - Failed to decrypt token');
            }
        } else {
            error_log('Test 3 - Failed to generate encrypted token');
        }
        

        return true;
    }

    /**
     * Test SSO AJAX endpoint (development/debugging)
     * Simple test to verify AJAX connectivity and endpoint registration
     */
    public function test_sso_ajax_endpoint()
    {

        
        // Check if nonce is provided and valid
        if (isset($_POST['nonce'])) {
            $nonce_valid = wp_verify_nonce($_POST['nonce'], 'metasync_sso_nonce');
        }
        
        // Check current user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => 'Insufficient permissions for AJAX test',
                'required_capability' => 'manage_options'
            ));
            return;
        }
        

        
        wp_send_json_success(array(
            'message' => 'AJAX endpoint is working correctly',
            'timestamp' => current_time('mysql', true),
            'user_id' => get_current_user_id(),
            'endpoint' => 'test_sso_ajax_endpoint',
            'nonce_valid' => isset($_POST['nonce']) ? wp_verify_nonce($_POST['nonce'], 'metasync_sso_nonce') : false,
            'debug_info' => array(
                'post_action' => $_POST['action'] ?? 'NOT SET',
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
        error_log('Simple AJAX Test: Reached without nonce verification');
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
            <div class="wrap metasync-dashboard-wrap">
                <?php $this->render_plugin_header('Dashboard'); ?>
                
                <?php $this->render_navigation_menu('dashboard'); ?>
                
                <div class="dashboard-card">
                    <h2>📊 Dashboard Disabled</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">
                        The Search Atlas dashboard is currently Disabled.
                    </p>
                </div>
            </div>
            <?php
            return;
        }

        // Check if user is properly connected via heartbeat
        if (!$this->is_heartbeat_connected()) {
            ?>
            <div class="wrap metasync-dashboard-wrap">
                <?php $this->render_plugin_header('Dashboard'); ?>
                
                <?php $this->render_navigation_menu('dashboard'); ?>
                
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
            error_log('DASHBOARD_IFRAME: JWT token available for public hash fetch - Length: ' . strlen($jwt_token) . ', Format: ' . $jwt_header_info . ', Prefix: ' . substr($jwt_token, 0, 20) . '...');
        } else {
            error_log('DASHBOARD_IFRAME: No JWT token available - cannot fetch public hash');
        }
        
        // Fetch public hash for public dashboard access
        $public_hash = false;
        if ($jwt_token && $otto_pixel_uuid) {
            error_log('DASHBOARD_IFRAME: Attempting to fetch public hash for UUID: ' . substr($otto_pixel_uuid, 0, 8) . '...');
            $public_hash = $this->fetch_public_hash($otto_pixel_uuid, $jwt_token);
            
            if ($public_hash) {
                error_log('DASHBOARD_IFRAME: Public hash fetch successful - Hash: ' . substr($public_hash, 0, 8) . '...');
            } else {
                error_log('DASHBOARD_IFRAME: PUBLIC HASH FETCH FAILED - Will fallback to JWT token authentication');
                error_log('DASHBOARD_IFRAME: Public hash failure details - UUID present: ' . (!empty($otto_pixel_uuid) ? 'YES' : 'NO') . ', JWT present: ' . (!empty($jwt_token) ? 'YES' : 'NO'));
            }
        } else {
            $missing_params = [];
            if (empty($jwt_token)) $missing_params[] = 'JWT_TOKEN';
            if (empty($otto_pixel_uuid)) $missing_params[] = 'OTTO_UUID';
            error_log('DASHBOARD_IFRAME: Cannot fetch public hash - Missing parameters: ' . implode(', ', $missing_params));
        }
        
        // Build the dashboard iframe URL using public or private endpoint
        $dashboard_domain = self::get_effective_dashboard_domain();
        
        if ($public_hash) {
            // Use public dashboard endpoint with public hash
            $iframe_url = $dashboard_domain . '/seo-automation-v3/public?uuid=' . urlencode($otto_pixel_uuid) 
                        . '&category=onpage_optimizations&subGroup=page_title&public_hash=' . urlencode($public_hash);
            error_log('DASHBOARD_IFRAME: SUCCESS - Using public dashboard endpoint with public hash');
        } else {
            // Fallback to private dashboard endpoint with JWT token
            $iframe_url = $dashboard_domain . '/seo-automation-v3/tasks?uuid=' . urlencode($otto_pixel_uuid) . '&category=All&Embed=True';
            if ($jwt_token) {
                $iframe_url .= '&jwtToken=' . urlencode($jwt_token) . '&impersonate=1';
                error_log('DASHBOARD_IFRAME: FALLBACK - Using private dashboard with JWT token authentication (public hash failed)');
            } else {
                error_log('DASHBOARD_IFRAME: CRITICAL - No authentication available - dashboard may not load properly');
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
        <div class="wrap metasync-dashboard-wrap">
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
                                console.log('📏 Iframe height adjusted to:', height + 'px');
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
                        console.log('📏 Iframe height set to viewport (cross-origin)');
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
     * Render error log content for inclusion in Advanced settings
     */
    private function render_error_log_content()
    {
        ?>
        <!-- Error Log Management -->
        <div style="margin-bottom: 20px;">
            <h3>🗑️ Error Log Management</h3>
            <p style="margin-bottom: 15px; color: #666;">Clear WordPress error logs to free up space and remove old entries.</p>
            
            <form method="post" action="<?php echo admin_url('admin.php?page=' . self::$page_slug . '&tab=advanced'); ?>" style="margin-bottom: 20px;">
                <input type="hidden" name="clear_log" value="yes" />
                <?php wp_nonce_field('metasync_clear_log_nonce', 'clear_log_nonce'); ?>
                <?php submit_button('🧹 Clear Error Logs', 'secondary', 'clear-log', false, array('class' => 'button button-secondary')); ?>
            </form>
        </div>

        <!-- WordPress Debug Settings -->
        <form method="post">
            <div style="margin-bottom: 20px;">
                <h3>🔧 WordPress Debug Configuration</h3>
                <p style="margin-bottom: 15px; color: #666;">Configure WordPress debug settings to control error logging and display.</p>
                
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
                <p style="color: #d54e21;">💡 To enable error logging, add these lines to your wp-config.php file:</p>
                <pre style="background: #f1f1f1; padding: 10px; border-radius: 4px;">define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);</pre>
                <?php endif; ?>
            </div>
        </form>

        <!-- Error Log Display -->
        <div style="margin-top: 30px;">
            <h3>📄 Error Log Contents</h3>
            <p style="margin-bottom: 15px; color: #666;">View the current error log entries for troubleshooting and monitoring.</p>
            
            <?php
            $error_log_content = $this->get_error_log_content();
            if ($error_log_content): ?>
                <div class="dashboard-code-block" style="width: 100%; box-sizing: border-box;">
                    <pre style="background: var(--dashboard-card-bg); border: 1px solid var(--dashboard-border); border-radius: 8px; padding: 20px; overflow: auto; max-height: 400px; font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, monospace; font-size: 13px; line-height: 1.6; color: var(--dashboard-text-primary); margin: 0; box-shadow: var(--dashboard-shadow-sm); width: 100%; box-sizing: border-box; white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html($error_log_content); ?></pre>
                </div>
            <?php else: ?>
                <div class="dashboard-empty-state">
                    <p style="color: var(--dashboard-text-secondary); font-style: italic; text-align: center; padding: 40px 20px;">✅ Log file is empty - no errors recorded.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
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
                $log_file = WP_CONTENT_DIR . '/debug.log';
                if (file_exists($log_file)) {
                    file_put_contents($log_file, '');
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
                    'metasync_options_instant_indexing',  // Instant indexing settings
                    'metasync_otto_crawldata',            // Otto crawl data
                    'metasync_logging_data',              // Logging data
                    'metasync_wp_sso_token',              // WordPress SSO token
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
                // User will need to re-authenticate, at which point heartbeat will be triggered by SSO flow
                error_log('MetaSync Settings Reset: Skipping heartbeat trigger - all settings cleared, user must re-authenticate');
                
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
     * Get error log content for display
     */
    private function get_error_log_content()
    {
        // Try to get WordPress debug.log content
        $log_file = WP_CONTENT_DIR . '/debug.log';
        
        if (file_exists($log_file) && is_readable($log_file)) {
            $content = file_get_contents($log_file);
            
            // Get last 100 lines for better performance
            $lines = explode("\n", $content);
            $recent_lines = array_slice($lines, -100);
            
            return implode("\n", $recent_lines);
        }
        
        return false;
    }

    /**
     * Test whitelabel domain functionality (development/debugging)
     */
    public function test_whitelabel_domain()
    {
        if (!current_user_can('administrator')) {
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
        $admin_instance = new self();
        return $admin_instance->get_fresh_jwt_token();
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
        $url = Metasync::API_DOMAIN . '/api/customer/account/generate-jwt-from-api-key/';
        
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
        if (!current_user_can('manage_options')) {
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
        error_log('CONNECTION_STATUS_DEBUG: Checking heartbeat connection - API key present: ' . (!empty($searchatlas_api_key) ? 'YES' : 'NO'));
        
        if (empty($searchatlas_api_key)) {
            // No API key = heartbeat system is completely inactive
            error_log('CONNECTION_STATUS_DEBUG: No plugin API key configured - returning DISCONNECTED');
            return false;
        }
        
        // Check cached result first (5-minute cache)
        $cache_key = 'metasync_heartbeat_status_cache';
        $cached_result = get_transient($cache_key);
        
        error_log('CONNECTION_STATUS_DEBUG: Cache check - cached result: ' . ($cached_result !== false ? 'FOUND' : 'NOT_FOUND'));
        
        if ($cached_result !== false) {
            // Return cached result (includes timestamp for debugging)
            error_log('CONNECTION_STATUS_DEBUG: Using cached result - status: ' . ($cached_result['status'] ? 'CONNECTED' : 'DISCONNECTED') . ', cached_at: ' . date('Y-m-d H:i:s T', $cached_result['timestamp']));
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
            error_log('CONNECTION_STATUS_DEBUG: No cache found, using last known state: ' . ($last_known_state ? 'CONNECTED' : 'DISCONNECTED'));
            $this->log_heartbeat('info', 'Cache miss - using last known heartbeat status', array(
                'status' => $last_known_state ? 'CONNECTED' : 'DISCONNECTED',
                'note' => 'Graceful fallback until next cron job updates cache',
                'fallback_reason' => 'cache_expired_or_missing'
            ));
            return $last_known_state;
        }
        
        // No cache and no last known state - return default disconnected state
        error_log('CONNECTION_STATUS_DEBUG: No cached result or last known state found - returning default DISCONNECTED state');
        // The cron job will update this cache in the background
        $this->log_heartbeat('info', 'No cached or last known heartbeat status found - returning default DISCONNECTED', array(
            'note' => 'Cron job will establish initial connection state',
            'status' => 'DISCONNECTED' // For consistent throttling
        ));
        
        return false; // Default to disconnected if no cache or last known state exists
    }
    


    
    /**
     * Fetch public hash from OTTO projects API for public dashboard access
     * Includes caching to improve performance and reduce API calls
     * 
     * @param string $otto_pixel_uuid The OTTO pixel UUID
     * @param string $jwt_token JWT token for authentication
     * @return string|false Returns public hash on success, false on failure
     */
    private function fetch_public_hash($otto_pixel_uuid, $jwt_token)
    {
        if (empty($otto_pixel_uuid) || empty($jwt_token)) {
            error_log('DASHBOARD_PUBLIC_HASH: Missing required parameters - UUID: ' . (!empty($otto_pixel_uuid) ? 'YES' : 'NO') . ', JWT: ' . (!empty($jwt_token) ? 'YES' : 'NO'));
            return false;
        }

        // Check cache first (cache for 1 hour)
        $cache_key = 'metasync_public_hash_' . md5($otto_pixel_uuid);
        $cached_hash = get_transient($cache_key);
        
        if ($cached_hash !== false) {
            error_log('DASHBOARD_PUBLIC_HASH: Using cached public hash - Hash: ' . substr($cached_hash, 0, 8) . '...');
            return $cached_hash;
        }

        // API endpoint for OTTO projects
        $api_url = 'https://sa.searchatlas.com/api/v2/otto-projects/' . urlencode($otto_pixel_uuid) . '/';
        
        // Prepare request headers
        $headers = array(
            'Accept' => 'application/json, text/plain, */*',
            'Authorization' => 'Bearer ' . $jwt_token,
            'Content-Type' => 'application/json',
            'User-Agent' => 'WordPress MetaSync Plugin',
            'Cache-Control' => 'no-cache'
        );

        error_log('DASHBOARD_PUBLIC_HASH: Making API request to fetch public hash - UUID: ' . substr($otto_pixel_uuid, 0, 8) . '...');
        error_log('DASHBOARD_PUBLIC_HASH: API endpoint: ' . $api_url);
        error_log('DASHBOARD_PUBLIC_HASH: Using JWT token for authentication - Length: ' . strlen($jwt_token) . ', Prefix: ' . substr($jwt_token, 0, 20) . '..., Suffix: ...' . substr($jwt_token, -8));

        // Make the API request with retries
        $max_retries = 2;
        $retry_delay = 1; // seconds
        
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $response = wp_remote_get($api_url, array(
                'headers' => $headers,
                'timeout' => 15,
                'sslverify' => true,
                'redirection' => 5
            ));

            // Check for HTTP errors
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                error_log('DASHBOARD_PUBLIC_HASH: API request failed (attempt ' . $attempt . '/' . $max_retries . ') - ' . $error_message);
                
                if ($attempt < $max_retries) {
                    sleep($retry_delay);
                    continue;
                }
                return false;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if ($status_code === 200) {
                // Success - parse response
                $data = json_decode($body, true);
                
                // Enhanced debugging: Log the full response structure
                error_log('DASHBOARD_PUBLIC_HASH: Full API response body: ' . $body);
                error_log('DASHBOARD_PUBLIC_HASH: Parsed data structure: ' . print_r($data, true));
                
                if ($data) {
                    // Check what fields are actually present
                    $available_fields = is_array($data) ? array_keys($data) : 'not_array';
                    error_log('DASHBOARD_PUBLIC_HASH: Available fields in response: ' . print_r($available_fields, true));
                    
                    // Check for various possible field names
                    $possible_hash_fields = ['public_hash', 'publicHash', 'hash', 'public_share_hash', 'share_hash'];
                    $found_hash = null;
                    $found_field = null;
                    
                    foreach ($possible_hash_fields as $field) {
                        if (isset($data[$field]) && !empty($data[$field])) {
                            $found_hash = $data[$field];
                            $found_field = $field;
                            break;
                        }
                    }
                    
                    if ($found_hash) {
                        $public_hash = sanitize_text_field($found_hash);
                        
                        // Cache the public hash for 1 hour
                        set_transient($cache_key, $public_hash, 3600);
                        
                        error_log('DASHBOARD_PUBLIC_HASH: Successfully found hash in field "' . $found_field . '" - Hash: ' . substr($public_hash, 0, 8) . '...');
                        return $public_hash;
                    } else {
                        error_log('DASHBOARD_PUBLIC_HASH: No public hash found in any expected field. Checked fields: ' . implode(', ', $possible_hash_fields));
                        error_log('DASHBOARD_PUBLIC_HASH: Response payload: ' . substr($body, 0, 500));
                        return false;
                    }
                } else {
                    error_log('DASHBOARD_PUBLIC_HASH: Failed to parse JSON response. Raw body: ' . substr($body, 0, 300));
                    return false;
                }
            } else {
                error_log('DASHBOARD_PUBLIC_HASH: API returned non-200 status (attempt ' . $attempt . '/' . $max_retries . ') - Code: ' . $status_code . ', Body: ' . substr($body, 0, 200));
                
                // For authentication errors (401, 403), don't retry
                if (in_array($status_code, [401, 403])) {
                    error_log('DASHBOARD_PUBLIC_HASH: Authentication error, not retrying - Status: ' . $status_code);
                    return false;
                }
                
                if ($attempt < $max_retries) {
                    sleep($retry_delay);
                    continue;
                }
            }
        }
        
        error_log('DASHBOARD_PUBLIC_HASH: All retry attempts failed');
        return false;
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
            error_log('DASHBOARD_PUBLIC_HASH: Cleared cached public hash for UUID: ' . substr($otto_pixel_uuid, 0, 8) . '...');
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
            error_log('CONNECTION_STATUS_DEBUG: Stored last known connection state: ' . ($is_connected ? 'CONNECTED' : 'DISCONNECTED'));
        } else {
            error_log('CONNECTION_STATUS_DEBUG: Failed to store last known connection state');
        }
        
        return $success;
    }

    /**
     * Enhanced logging for heartbeat operations
     * Provides structured and detailed logging with context
     */
    private function log_heartbeat($level, $event, $details = array())
    {
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
        
        // Update last successful heartbeat timestamp
        $general_settings['send_auth_token_timestamp'] = current_time('mysql');
        $options = Metasync::get_option();
        $options['general'] = $general_settings;
        Metasync::set_option($options);
        
        return true; // Heartbeat API is responding correctly
    }

    /**
     * Schedule heartbeat cron job on plugin activation
     * This should run every 5 minutes in the background
     */
    public function schedule_heartbeat_cron()
    {
        // Clear any existing scheduled event first
        $this->unschedule_heartbeat_cron();
        
        // Schedule new cron job every 5 minutes (300 seconds)
        if (!wp_next_scheduled('metasync_heartbeat_cron_check')) {
            $scheduled = wp_schedule_event(time(), 'metasync_five_minutes', 'metasync_heartbeat_cron_check');
            
            if ($scheduled) {
                $this->log_heartbeat('info', 'Heartbeat cron job scheduled successfully', array(
                    'interval' => '5 minutes',
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
                'reason' => 'Search Atlas API key not configured',
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
     * Add custom cron schedule for 5-minute intervals
     */
    public function add_heartbeat_cron_schedule($schedules)
    {
        $schedules['metasync_five_minutes'] = array(
            'interval' => 300, // 5 minutes in seconds
            'display'  => __('Every 5 Minutes (Metasync Heartbeat)')
        );
        return $schedules;
    }
    
    /**
     * Maybe schedule heartbeat cron job if plugin API key is configured
     * Called on init hook - only schedules if API key is present
     */
    public function maybe_schedule_heartbeat_cron()
    {
        $general_settings = Metasync::get_option('general') ?? [];
        $searchatlas_api_key = $general_settings['searchatlas_api_key'] ?? '';
        
        if (empty($searchatlas_api_key)) {
            // No API key = unschedule any existing cron to save resources
            if (wp_next_scheduled('metasync_heartbeat_cron_check')) {
                $this->unschedule_heartbeat_cron();
                $this->log_heartbeat('info', 'Heartbeat cron unscheduled - no plugin API key configured');
            }
            return;
        }
        
        // API key exists = ensure cron is scheduled
        if (!wp_next_scheduled('metasync_heartbeat_cron_check')) {
            $this->schedule_heartbeat_cron();
        }
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
                'reason' => 'User has not provided Search Atlas API key yet'
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
                'reason' => 'Search Atlas API key not configured',
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
        if (!current_user_can('manage_options')) {
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
     * Used to refresh the Plugin Auth Token field after SSO authentication
     */
    public function get_plugin_auth_token()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'metasync_sso_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
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
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'metasync_reset_auth_nonce')) {
            wp_send_json_error(array(
                'message' => 'Security verification failed. Please refresh the page and try again.',
                'code' => 'invalid_nonce'
            ));
            return;
        }

        // Verify user permissions
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
            
            // Disable Server Side Rendering when disconnecting
            if (isset($options['general']['otto_enable'])) {
                $cleared_data['otto_enable'] = $options['general']['otto_enable'];
                unset($options['general']['otto_enable']);
            }
            
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

            // Clear WordPress SSO token
            delete_option('metasync_wp_sso_token');
            $cleared_data['wp_sso_token'] = 'removed';

            // Clear any existing SSO nonce tokens (deprecated with simplified tokens)
            $cleaned_tokens = $this->cleanup_sso_nonce_tokens();
            $cleared_data['sso_nonce_tokens'] = 'none (simplified token system)';

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
            $this->cleanup_sso_rate_limits();
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
            error_log('Search Atlas Authentication Reset: Successfully cleared authentication data by user ' . get_current_user_id());

            // Return success response
            wp_send_json_success(array(
                'message' => 'Authentication has been reset successfully. You can now connect a new account.',
                'cleared_data' => $cleared_data,
                'timestamp' => current_time('mysql', true)
            ));

        } catch (Exception $e) {
            error_log('Search Atlas Authentication Reset Error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => 'An error occurred while resetting authentication. Please try again or contact support.',
                'code' => 'reset_failed',
                'error' => $e->getMessage()
            ));
        }
    }

    /**
     * Cleanup expired or orphaned SSO nonce tokens
     * DEPRECATED: No longer needed with simplified token system
     */
    private function cleanup_sso_nonce_tokens()
    {
        // No nonce tokens are stored with simplified system
        return 0;
    }

    /**
     * Cleanup SSO rate limiting data
     */
    private function cleanup_sso_rate_limits()
    {
        global $wpdb;
        
        try {
            // Find all SSO rate limit transients
            $rate_limit_transients = $wpdb->get_results(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_sso_rate_limit_%'",
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
     */
    private function get_available_menu_items() 
    {
        $general_options = Metasync::get_option('general') ?? [];
        $has_api_key = !empty($general_options['searchatlas_api_key'] ?? '');
        $has_uuid = !empty($general_options['otto_pixel_uuid'] ?? '');
        $is_fully_connected = $this->is_heartbeat_connected($general_options);
        
        $menu_items = [];
        
        // Dashboard always available
        $menu_items['dashboard'] = [
            'title' => 'Dashboard',
            'slug_suffix' => '-dashboard',
            'callback' => 'create_admin_dashboard_iframe',
            'internal_nav' => 'Dashboard'
        ];
        
        // Settings (renamed from General and moved after Dashboard)
        $menu_items['general'] = [
            'title' => 'Settings',
            'slug_suffix' => '',
            'callback' => 'create_admin_settings_page',
            'internal_nav' => 'General Settings'
        ];
        
        if ($general_options['enable_optimal_settings'] ?? false) {
            $menu_items['optimal_settings'] = [
                'title' => 'Optimal Settings',
                'slug_suffix' => '-optimal-settings',
                'callback' => 'create_admin_optimal_settings_page',
                'internal_nav' => 'Optimal Settings'
            ];
        }
        
        if ($general_options['enable_instant_indexing'] ?? false) {
            $menu_items['instant_index'] = [
                'title' => 'Instant Indexing',
                'slug_suffix' => '-instant-index',
                'callback' => 'create_admin_google_instant_index_page',
                'internal_nav' => 'Instant Indexing'
            ];
        }
        
        if ($general_options['enable_google_console'] ?? false) {
            $menu_items['google_console'] = [
                'title' => 'Google Console',
                'slug_suffix' => '-google-console',
                'callback' => 'create_admin_google_console_page',
                'internal_nav' => 'Google Console'
            ];
        }
        
        // Always available - Error Logs
        // Error Logs moved to Advanced settings tab - no longer separate page
        
        return $menu_items;
    }

    /**
     * Add options page
     */
    public function add_plugin_settings_page()
    {
        $data= Metasync::get_option('general');
        // Use centralized method for getting effective plugin name
        $plugin_name = Metasync::get_effective_plugin_name();
        $menu_name = $plugin_name;
        $menu_title = $plugin_name;
        $menu_slug = !isset($data['white_label_plugin_menu_slug']) || $data['white_label_plugin_menu_slug']==""  ?  self::$page_slug : $data['white_label_plugin_menu_slug'];
        $menu_icon = !isset($data['white_label_plugin_menu_icon']) ||  $data['white_label_plugin_menu_icon'] =="" ? 'dashicons-searchatlas' : $data['white_label_plugin_menu_icon'];
       
        // Main menu page - Settings (default)
        add_menu_page(
            $menu_name,
            $menu_title,
            'manage_options',
            $menu_slug,
            array($this, 'create_admin_settings_page'), // Main page is Settings
            $menu_icon
        );

        // Check connection status for submenu availability
        $general_options = Metasync::get_option('general');
        $has_api_key = !empty($general_options['searchatlas_api_key']);
        $has_uuid = !empty($general_options['otto_pixel_uuid']);
        $is_fully_connected = $this->is_heartbeat_connected($general_options);
        
        // Add Dashboard submenu first (always available)
        add_submenu_page(
            $menu_slug,
            'Dashboard',
            'Dashboard',
            'manage_options',
            $menu_slug . '-dashboard',
            array($this, 'create_admin_dashboard_iframe')
        );
        
        // Rename the auto-generated first submenu item from plugin name to "Settings"
        // WordPress automatically creates a submenu with the main menu name
        add_action('admin_menu', function() use ($menu_slug) {
            global $submenu;
            if (isset($submenu[$menu_slug])) {
                // Find and rename the auto-generated submenu item
                foreach ($submenu[$menu_slug] as $key => $item) {
                    if ($item[2] === $menu_slug) { // Main menu item
                        $submenu[$menu_slug][$key][0] = 'Settings';
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

        // if(@Metasync::get_option('general')['enable_redirections'])
        // add_submenu_page($menu_slug, 'Redirections', 'Redirections', 'manage_options', $menu_slug . '-redirections', array($this, 'create_admin_redirections_page'));

    }

    /**
     * General Options page callback
     */
    public function create_admin_settings_page()
    {
        # Use whitelabel OTTO name if configured, fallback to 'OTTO'
        $whitelabel_otto_name = Metasync::get_whitelabel_otto_name();

        # define the active tab
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
        
        # get page slug (use original format)
        $page_slug = self::$page_slug;
    ?>
        <div class="wrap metasync-dashboard-wrap">
        
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
        

            <form method="post" action="options.php?tab=<?php echo $active_tab?>" id="metaSyncGeneralSetting">
                <?php
                    settings_fields($this::option_group);

                    # Add a nonce field for security - needed for both General and Advanced tabs
                    wp_nonce_field('meta_sync_general_setting_nonce', 'meta_sync_nonce');

                    if ($active_tab == 'general') {
                ?>
                    <div class="dashboard-card">
                        <h2>🔧 General Configuration</h2>
                        <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure your <?php echo esc_html(Metasync::get_effective_plugin_name()); ?> API, plugin features, caching, and general settings.</p>
                        <?php
                        # do the general settings section
                        do_settings_sections(self::$page_slug  . '_general');
                        ?>
                    </div>

                    <div class="dashboard-card">
                        <h2>🔄 Synchronization</h2>
                        <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Sync your categories and user data with <?php echo esc_html(Metasync::get_effective_plugin_name()); ?>.</p>
                        <button type="button" class="button button-primary" id="sendAuthToken" data-toggle="tooltip" data-placement="top" title="Sync Categories and User">
                            🔄 Sync Now
                        </button>
                    </div>
                <?php
                    } elseif ($active_tab == 'advanced') {
                ?>
                    <div class="dashboard-card">
                        <h2>🎨 White Label Branding</h2>
                        <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Customize the plugin appearance with your own branding and logo.</p>
                        <?php
                                                # do the whitelabel branding section
                        do_settings_sections(self::$page_slug  . '_branding');
                        ?>
                    </div>
                    

                <?php
                    }
                ?>
                
                <!-- Save button removed - using floating notification system instead -->
                
            </form>
            
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

                <!-- Error Logs Management section after White Label Branding -->
                <div class="dashboard-card">
                    <h2>⚠️ Error Logs Management</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">View and manage system error logs to troubleshoot issues and monitor plugin performance.</p>
                    <?php $this->render_error_log_content(); ?>
                </div>

                <!-- Clear All Settings section -->
                <div class="dashboard-card">
                    <h2>🔄 Reset Plugin Settings</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Reset all plugin settings to default values. This will clear all configuration data and restore the plugin to its initial state.</p>
                    <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; padding: 15px; margin: 15px 0;">
                        <h4 style="color: #856404; margin: 0 0 10px 0;">⚠️ Important Warning</h4>
                        <p style="color: #856404; margin: 0 0 10px 0;">This action will permanently delete:</p>
                        <ul style="color: #856404; margin: 0 0 10px 15px;">
                            <li>All API keys and authentication tokens</li>
                            <li>White label branding settings</li>
                            <li>Plugin configuration and preferences</li>
                            <li>Instant indexing settings</li>
                            <li>All cached data and crawl information</li>
                        </ul>
                        <p style="color: #856404; margin: 0; font-weight: bold;">You will need to reconfigure the plugin completely after this reset.</p>
                    </div>
                    <form method="post" action="" onsubmit="return confirmClearSettings(event)" style="margin-top: 20px;">
                        <?php wp_nonce_field('metasync_clear_all_settings_nonce', 'clear_all_settings_nonce'); ?>
                        <input type="hidden" name="clear_all_settings" value="yes" />
                        <button type="submit" class="button button-secondary" style="background: #dc3545; color: white; border-color: #dc3545;" onmouseover="this.style.background='#c82333'" onmouseout="this.style.background='#dc3545'">
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
                    return false; // Prevent default form submission since we manually submitted
                }
                </script>
            <?php endif; ?>
        </div>
                <?php
    }

    /**
     * Helper function to render navigation menu
     */
    private function render_navigation_menu($current_page = null)
    {
        // Get available menu items using our helper method to ensure consistency
        $available_menu_items = $this->get_available_menu_items();
        
        // Define icons for each menu type
        $menu_icons = [
            'general' => '⚙️',
            'dashboard' => '📊', 
            'optimal_settings' => '🚀',
            'instant_index' => '🔗',
            'google_console' => '📊',
            'error_log' => '⚠️'
        ];
        ?>
                <!-- Plugin Navigation Menu -->
        <div class="metasync-nav-wrapper">
            <div class="metasync-nav-tabs">
                <!-- Left side - Dashboard navigation -->
                <div class="metasync-nav-left">
                <?php
                    // Check connection status for dashboard
                    $general_options = Metasync::get_option('general');
                    $has_api_key = !empty($general_options['searchatlas_api_key']);
                    $has_uuid = !empty($general_options['otto_pixel_uuid']);
                    $is_connected = $this->is_heartbeat_connected($general_options);
                    $is_dashboard_page = ($current_page === 'dashboard');
                    ?>
                    <!-- Dashboard always available in internal navigation -->
                    <a href="?page=<?php echo self::$page_slug; ?>-dashboard" class="metasync-nav-tab <?php echo $is_dashboard_page ? 'active' : ''; ?>">
                        <span class="tab-icon">📊</span>
                        <span class="tab-text">Dashboard</span>
                    </a>
                </div>
                
                <!-- Right side - Simple Settings dropdown (portal approach) -->
                <div class="metasync-nav-right">
                    <div class="metasync-simple-dropdown">
                        <button type="button" class="metasync-settings-btn" id="metasync-settings-btn" onclick="toggleSettingsMenuPortal(event)">
                            <span class="tab-icon">⚙️</span>
                            <span class="tab-text">Settings</span>
                            <span class="dropdown-arrow">▼</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
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
            
            menu.innerHTML = '<a href="?page=<?php echo self::$page_slug; ?>&tab=general" class="metasync-portal-item' + (isGeneralActive ? ' active' : '') + '">General</a>' +
                           '<a href="?page=<?php echo self::$page_slug; ?>&tab=advanced" class="metasync-portal-item' + (isAdvancedActive ? ' active' : '') + '">Advanced</a>';
            
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
            
            if (menu && !button.contains(event.target) && !menu.contains(event.target)) {
                menu.remove();
                if (button) {
                    button.classList.remove('active');
                    button.setAttribute('aria-expanded', 'false');
                }
            }
        });
        </script>
    <?php
    }

    /**
     * Helper function to render plugin header with logo
     */
    private function render_plugin_header($page_title = null)
    {
        $general_settings = Metasync::get_option('general');
        $whitelabel_settings = Metasync::get_whitelabel_settings();
        
        // Get whitelabel logo from the centralized whitelabel settings
        $whitelabel_logo = Metasync::get_whitelabel_logo();
        $is_whitelabel = $whitelabel_settings['is_whitelabel'];
        
        // Get the display title - use effective plugin name if no page title provided
        $effective_plugin_name = Metasync::get_effective_plugin_name();
        
        $display_title = $page_title ?: $effective_plugin_name;
        
        $show_logo = false;
        $logo_url = '';
        
        // Priority 1: Use whitelabel logo if it's a valid URL
        if (!empty($whitelabel_logo) && filter_var($whitelabel_logo, FILTER_VALIDATE_URL)) {
            $show_logo = true;
            $logo_url = esc_url($whitelabel_logo);
        } else {
            // Priority 2: Always use default Search Atlas logo as fallback
            $show_logo = true;
            $logo_url = Metasync::HOMEPAGE_DOMAIN . '/wp-content/uploads/2023/12/white.svg';
        }
        
        // Check integration status based on heartbeat API connectivity
        $searchatlas_api_key = isset($general_settings['searchatlas_api_key']) ? $general_settings['searchatlas_api_key'] : '';
        $otto_pixel_uuid = isset($general_settings['otto_pixel_uuid']) ? $general_settings['otto_pixel_uuid'] : '';
        
        // User is considered "Connected" based on heartbeat API status
        $is_integrated = $this->is_heartbeat_connected($general_settings);
        ?>
        
        <!-- Plugin Header with Logo -->
        <div class="metasync-header">
            <div class="metasync-header-left">
                <?php if ($show_logo && !empty($logo_url)): ?>
                    <div class="metasync-logo-container">
                        <img src="<?php echo $logo_url; ?>" alt="Logo" class="metasync-logo" />
        </div>
                <?php endif; ?>
            </div>
            
                         <div class="metasync-header-right">
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
            'searchatlas_api_key', 'apikey', 'enabled_plugin_editor', 
            'white_label_plugin_name', 'white_label_plugin_description', 
            'white_label_plugin_author', 'white_label_plugin_menu_slug', 
            'white_label_plugin_menu_icon', 'enabled_plugin_css',
            'enabled_elementor_plugin_css_color','enabled_elementor_plugin_css',
            'otto_pixel_uuid','periodic_clear_otto_cache','periodic_clear_ottopage_cache',
            'periodic_clear_ottopost_cache', 'whitelabel_otto_name'
        ];
        
        # URL fields for esc_url_raw
        $url_fields = [
            'white_label_plugin_author_uri', 'white_label_plugin_uri'
        ];
        
        // Note: white_label_plugin_menu_name and white_label_plugin_menu_title deprecated
        // Plugin Name controls general branding, whitelabel_otto_name controls OTTO features
    
        # Bool Fields for filter var
       # $bool_fields = ['enable_schema', 'enable_metadesc', 'otto_enable', 'otto_disable_on_loggedin'];
       # new field added disable_single_signup_login
	   # $bool_fields = ['enable_schema', 'enable_metadesc', 'otto_enable', 'otto_disable_on_loggedin', 'disable_single_signup_login'];
       
       # new field added hide_dashboard_framework

       $bool_fields = ['enable_schema', 'enable_metadesc', 'otto_enable', 'otto_disable_on_loggedin', 'disable_single_signup_login', 'hide_dashboard_framework', 'show_admin_bar_status'];
    
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
                    'whitelabel_otto_name'
                ];

                # Skip empty values except for whitelabel fields (which can be cleared)
                if ($value === '' && !in_array($field, $whitelabel_clearable_fields)) {
                    continue;
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

        # Process boolean fields
        foreach ($bool_fields as $field) {
            if (isset($_POST['metasync_options']['general'][$field])) {
                $metasync_options['general'][$field] = filter_var($_POST['metasync_options']['general'][$field], FILTER_VALIDATE_BOOLEAN);
            }else {
                # If checkbox is not present in POST (unchecked), set to false
                $metasync_options['general'][$field] = false;
            }
        }
    
        # Process URL fields
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
        <div class="wrap metasync-dashboard-wrap">
        
        <?php $this->render_plugin_header('Dashboard'); ?>
        
        <?php $this->render_navigation_menu('dashboard'); ?>
            
            <div class="dashboard-card">
                <h2>📊 Search Atlas Dashboard</h2>
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
     * 404 Monitor page callback
     */
    public function create_admin_404_monitor_page()
    {
        ?>
        <div class="wrap metasync-dashboard-wrap">
        
        <?php $this->render_plugin_header('404 Monitor'); ?>
        
        <?php $this->render_navigation_menu('404-monitor'); ?>
            
            <div class="dashboard-card">
                <h2>🚫 404 Error Monitor</h2>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Monitor and track 404 errors on your website to identify broken links and improve user experience.</p>
                <?php
        $ErrorMonitor = new Metasync_Error_Monitor($this->database);
        $ErrorMonitor->create_admin_plugin_interface();
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Site Verification page callback
     */
    public function create_admin_search_engine_verification_page()
    {
        $page_slug = self::$page_slug . '_searchengines-verification';
        ?>
        <div class="wrap metasync-dashboard-wrap">
        
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
        <div class="wrap metasync-dashboard-wrap">
        
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
        <div class="wrap metasync-dashboard-wrap">
        
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
        <div class="wrap metasync-dashboard-wrap">
        
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
        <div class="wrap metasync-dashboard-wrap">
        
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
     * General Options page callback
     */
    public function create_admin_optimal_settings_page()
    {
        ?>
        <div class="wrap metasync-dashboard-wrap">
        
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
        <div class="wrap metasync-dashboard-wrap">
        
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
        <div class="wrap metasync-dashboard-wrap">
        
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
        <div class="wrap metasync-dashboard-wrap">
        
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
     * redirection page callback
     */
    public function create_admin_redirections_page()
    {
        $url = admin_url() . "admin.php?page=metasync-settings-redirections&action=add";
        printf('<h1 class="wp-heading-inline"> Redirections  <a href="%s" id="add-redirection" class="button button-primary page-title-action" >Add New</a> </h1>', esc_url($url));
        $redirection = new Metasync_Redirection($this->db_redirection);
        $redirection->create_admin_redirection_interface();
    }

    /**
     * Site error logs page callback
     */
    public function create_admin_error_logs_page()
    {
        ?>
        <div class="wrap metasync-dashboard-wrap">
        
        <?php $this->render_plugin_header('Error Logs'); ?>
        
        <?php $this->render_navigation_menu('error-log'); ?>
            
            <div class="dashboard-card">
                <h2>⚠️ Error Logs Management</h2>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">View and manage system error logs to troubleshoot issues and monitor plugin performance.</p>
                <?php
        $error_logs = new Metasync_Error_Logs();

        if ($error_logs->can_show_error_logs()) {
            $error_logs->show_copy_button();
            $error_logs->show_logs();
            $error_logs->show_info();
                } else {
                    echo '<p>Error logs are not available or accessible.</p>';
        }
                ?>
            </div>
        </div>
        <?php
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
        <div class="wrap metasync-dashboard-wrap">
        
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
     * Register and add settings
     */
    public function settings_page_init()
    {
        // Handle error log operations before any output
        $this->handle_error_log_operations();
        
        // Handle clear all settings operations before any output
        $this->handle_clear_all_settings();
        
        $SECTION_FEATURES               = "features_settings";
        $SECTION_METASYNC               = "metasync_settings";
        $SECTION_SEARCHENGINE           = "searchengine_settings";
        $SECTION_LOCALSEO               = "local_seo";
        $SECTION_CODESNIPPETS           = "code_snippets";
        $SECTION_OPTIMAL_SETTINGS       = "optimal_settings";
        $SECTION_SITE_SETTINGS          = "site_settings";
        $SECTION_COMMON_SETTINGS        = "common_settings";
        $SECTION_COMMON_META_SETTINGS   = "common_meta_settings";
        $SECTION_SOCIAL_META            = "social_meta";

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
         */

        # check box to toggle on and off for Server Side Rendering
        add_settings_field(
            'otto_enable',
            'Enable '.$whitelabel_otto_name.' Server Side Rendering',
            function() use ($whitelabel_otto_name){
                $otto_enable = Metasync::get_option('general')['otto_enable'] ?? '';
                printf(
                    '<input type="checkbox" id="otto_enable" name="' . $this::option_key . '[general][otto_enable]" value="true" %s />',
                    isset($otto_enable) && $otto_enable == 'true' ? 'checked' : ''
                );
                printf('<span class="description">Enable this option to allow '.$whitelabel_otto_name.' page modifications to be implemented directly via the WordPress engine and leverage any existing cache systems.</span>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

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
                $otto_enable = Metasync::get_option('general')['otto_disable_on_loggedin'] ?? '';
                printf(
                    '<input type="checkbox" id="otto_disable_on_loggedin" name="' . $this::option_key . '[general][otto_disable_on_loggedin]" value="true" %s />',
                    isset($otto_enable) && $otto_enable == 'true' ? 'checked' : ''
                );
                printf('<span class="description"> This disables '.$whitelabel_otto_name.' when logged in to allow editing original page contents</span>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

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

        add_settings_field(
            'schema_enable',
            'Enable Schema',
            function() {
                $schema_enable = Metasync::get_option('general')['enable_schema'] ?? '';
                printf(
                    '<input type="checkbox" id="enable_schema" name="' . $this::option_key . '[general][enable_schema]" value="true" %s />',
                    isset($schema_enable) && $schema_enable == 'true' ? 'checked' : ''
                );
                printf('<span class="description"> Enable/Disable Schema for Wordpress posts and pages</span>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        add_settings_field(
            'enable_metadesc',
            'Enable Meta Description',
            function() {
                $schema_enable = Metasync::get_option('general')['enable_metadesc'] ?? '';
                printf(
                    '<input type="checkbox" id="enable_metadesc" name="' . $this::option_key . '[general][enable_metadesc]" value="true" %s />',
                    isset($schema_enable) && $schema_enable == 'true' ? 'checked' : ''
                );
                printf('<span class="description"> Enable/Disable meta tags for Wordpress posts and pages</span>');
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
                printf('<span class="description"> Hide the Search Atlas dashboard</span>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        # Adding the "Disable Single Signup Login" setting
        add_settings_field(
            'disable_single_signup_login',
            'Disable Single Signup Login',
            function() {
                $disable_sso = Metasync::get_option('general')['disable_single_signup_login'] ?? '';
                printf(
                    '<input type="checkbox" id="disable_single_signup_login" name="' . $this::option_key . '[general][disable_single_signup_login]" value="true" %s />',
                    isset($disable_sso) && $disable_sso == 'true' ? 'checked' : ''
                );
                printf('<span class="description">Disable the Single Sign-On (SSO) callback functionality for enhanced security.</span>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );

        # Adding the "Show Admin Bar Status" setting
        add_settings_field(
            'show_admin_bar_status',
            'Show Search Atlas Status in Admin Bar',
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

        add_settings_field(
            'enabled_plugin_editor',
            'Choose Plugin Editor',
            function() {
                $enabled_plugin_editor = Metasync::get_option('general')['enabled_plugin_editor'] ?? '';                
                // Check if Elementor is active
                $elementor_active = did_action( 'elementor/loaded' );

                //check if divi is active
                $divi_active = str_contains(wp_get_theme()->name ,"Divi");      
                // Check if Gutenberg is enabled
                $gutenberg_enabled = true;
        
                // Output radio button for Elementor only if Elementor is active
                if ($elementor_active) {
                    printf(
                        '<input type="radio" id="enable_elementor" name="' . $this::option_key . '[general][enabled_plugin_editor]" value="elementor" %s />',
                        ($enabled_plugin_editor == 'elementor') ? 'checked' : ''
                    );
                    printf('<label for="enable_elementor">Elementor</label><br>');
                }
                if($divi_active){
                    printf(
                        '<input type="radio" id="enable_divi" name="' . $this::option_key . '[general][enabled_plugin_editor]" value="divi" %s />',
                        ($enabled_plugin_editor == 'divi') ? 'checked' : ''
                    );
                    printf('<label for="enable_divi">Divi</label><br>');
                }
        
                // Output radio button for Gutenberg (default selection)
                printf(
                    '<input type="radio" id="enable_gutenberg" name="' . $this::option_key . '[general][enabled_plugin_editor]" value="gutenberg" %s  />',
                    ($enabled_plugin_editor == 'gutenberg' || empty($enabled_plugin_editor)) ? 'checked' : ''
                );
                printf('<label for="enable_gutenberg">Gutenberg</label>');
        
                printf('<p class="description"> Choose the default page editor plugin: Elementor or Gutenberg.</p>');
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
            printf('<input type="text" name="' . $this::option_key . '[general][white_label_plugin_name]" value="' . esc_attr($value) . '" />');
            printf('<p class="description">This name will be used for general plugin branding (WordPress menus, page titles, and system messages).</p>');
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


    }

    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize($input)
    {
        $new_input = Metasync::get_option();

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
        if (isset($input['general']['enable_schema'])) {
            $new_input['general']['enable_schema'] = boolval($input['general']['enable_schema']);
        }
        if (isset($input['general']['enable_metadesc'])) {
            $new_input['general']['enable_metadesc'] = boolval($input['general']['enable_metadesc']);
        }

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
        if (isset($input['common_robots_mata']['index'])) {
            $new_input['common_robots_mata']['index'] = boolval($input['common_robots_mata']['index']);
        }
        if (isset($input['common_robots_mata']['noindex'])) {
            $new_input['common_robots_mata']['noindex'] = boolval($input['common_robots_mata']['noindex']);
        }
        if (isset($input['common_robots_mata']['nofollow'])) {
            $new_input['common_robots_mata']['nofollow'] = boolval($input['common_robots_mata']['nofollow']);
        }
        if (isset($input['common_robots_mata']['noarchive'])) {
            $new_input['common_robots_mata']['noarchive'] = boolval($input['common_robots_mata']['noarchive']);
        }
        if (isset($input['common_robots_mata']['noimageindex'])) {
            $new_input['common_robots_mata']['noimageindex'] = boolval($input['common_robots_mata']['noimageindex']);
        }
        if (isset($input['common_robots_mata']['nosnippet'])) {
            $new_input['common_robots_mata']['nosnippet'] = boolval($input['common_robots_mata']['nosnippet']);
        }

        // Advance Setting - Global Settings
        if (isset($input['advance_robots_mata']['max-snippet']['enable'])) {
            $new_input['advance_robots_mata']['max-snippet']['enable'] = boolval($input['advance_robots_mata']['max-snippet']['enable']);
        }
        if (isset($input['advance_robots_mata']['max-snippet']['length'])) {
            $new_input['advance_robots_mata']['max-snippet']['length'] = sanitize_text_field($input['advance_robots_mata']['max-snippet']['length']);
        }
        if (isset($input['advance_robots_mata']['max-video-preview']['enable'])) {
            $new_input['advance_robots_mata']['max-video-preview']['enable'] = boolval($input['advance_robots_mata']['max-video-preview']['enable']);
        }
        if (isset($input['advance_robots_mata']['max-video-preview']['length'])) {
            $new_input['advance_robots_mata']['max-video-preview']['length'] = sanitize_text_field($input['advance_robots_mata']['max-video-preview']['length']);
        }
        if (isset($input['advance_robots_mata']['max-image-preview']['enable'])) {
            $new_input['advance_robots_mata']['max-image-preview']['enable'] = boolval($input['advance_robots_mata']['max-image-preview']['enable']);
        }
        if (isset($input['advance_robots_mata']['max-image-preview']['length'])) {
            $new_input['advance_robots_mata']['max-image-preview']['length'] = sanitize_text_field($input['advance_robots_mata']['max-image-preview']['length']);
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
            // Debug logging for whitelabel form submission
            error_log('Whitelabel Settings: Form submission received - ' . json_encode($input['whitelabel']));
            
            // Get existing whitelabel settings first
            $existing_whitelabel = Metasync::get_option()['whitelabel'] ?? [];
            error_log('Whitelabel Settings: Existing settings - ' . json_encode($existing_whitelabel));
            
            // Initialize whitelabel array based on existing settings
            $new_input['whitelabel'] = $existing_whitelabel;
            
            // Handle logo field (explicitly handle clearing)
            if (isset($input['whitelabel']['logo'])) {
                $logo_value = trim($input['whitelabel']['logo']);
                error_log('Whitelabel Settings: Logo field raw value: "' . $logo_value . '" (length: ' . strlen($logo_value) . ')');
                if (!empty($logo_value) && filter_var($logo_value, FILTER_VALIDATE_URL)) {
                    $new_input['whitelabel']['logo'] = esc_url_raw($logo_value);
                    error_log('Whitelabel Settings: Logo field set to: ' . $new_input['whitelabel']['logo']);
                } else {
                    // Empty value submitted - clear the logo
                    $new_input['whitelabel']['logo'] = '';
                    error_log('Whitelabel Settings: Logo field cleared by user');
                }
            }
            
            // Handle domain field (explicitly handle clearing)  
            if (isset($input['whitelabel']['domain'])) {
                $domain_value = trim($input['whitelabel']['domain']);
                error_log('Whitelabel Settings: Domain field raw value: "' . $domain_value . '" (length: ' . strlen($domain_value) . ')');
                if (!empty($domain_value) && filter_var($domain_value, FILTER_VALIDATE_URL)) {
                    $new_input['whitelabel']['domain'] = esc_url_raw($domain_value);
                    error_log('Whitelabel Settings: Domain field updated to: ' . $new_input['whitelabel']['domain']);
                } else {
                    // Empty value submitted - clear the domain
                    $old_domain = $existing_whitelabel['domain'] ?? '';
                    $new_input['whitelabel']['domain'] = '';
                    error_log('Whitelabel Settings: Domain field cleared by user (was: "' . $old_domain . '")');
                    
                    // If domain was cleared, trigger heartbeat recheck to use default domain
                    if (!empty($old_domain)) {
                        error_log('Whitelabel Settings: Domain cleared - will trigger heartbeat recheck after save');
                        // Set flag to trigger heartbeat check after settings are saved
                        $new_input['_trigger_heartbeat_after_save'] = 'Domain cleared from: ' . $old_domain;
                    }
                }
            } else {
                // Domain field not in submission - this might be the issue
                error_log('Whitelabel Settings: Domain field not present in form submission');
            }
            
            // Update timestamp when whitelabel settings change
            $new_input['whitelabel']['updated_at'] = time();
            
            // Final debug log
            error_log('Whitelabel Settings: Final processed settings - ' . json_encode($new_input['whitelabel']));
        } else {
            // No whitelabel data in submission - check if user wants to clear existing settings
            $existing_whitelabel = Metasync::get_option()['whitelabel'] ?? [];
            $has_existing_whitelabel = !empty($existing_whitelabel['domain']) || !empty($existing_whitelabel['logo']);
            
            if ($has_existing_whitelabel) {
                // User cleared all whitelabel fields - reset whitelabel settings
                error_log('Whitelabel Settings: No whitelabel data in form submission - user wants to reset existing whitelabel settings');
                error_log('Whitelabel Settings: Clearing existing settings - ' . json_encode($existing_whitelabel));
                
                $new_input['whitelabel'] = [
                    'is_whitelabel' => false,
                    'domain' => '',
                    'logo' => '', 
                    'company_name' => '',
                    'updated_at' => time()
                ];
                
                error_log('Whitelabel Settings: All whitelabel settings cleared by user - reset to defaults');
                
                // When whitelabel domain is cleared, trigger immediate heartbeat check
                // This ensures the system switches back to using the correct default domain
                if (!empty($existing_whitelabel['domain'])) {
                    error_log('Whitelabel Settings: Domain changed from whitelabel to default - triggering heartbeat recheck');
                    // Clear heartbeat cache to force using new domain on next check
                    delete_transient('metasync_heartbeat_status_cache');
                    
                    // Trigger immediate check with new domain
                    do_action('metasync_trigger_immediate_heartbeat', 'Whitelabel settings cleared - domain changed to default');
                }
            } else {
                // No existing whitelabel settings and none submitted - nothing to do
                error_log('Whitelabel Settings: No whitelabel data in form submission and none exist - no action needed');
            }
        }

        // Handle post-save heartbeat trigger for domain changes
        if (isset($new_input['_trigger_heartbeat_after_save'])) {
            $context = $new_input['_trigger_heartbeat_after_save'];
            unset($new_input['_trigger_heartbeat_after_save']); // Remove flag from saved data
            
            // Schedule the heartbeat check to run after settings are saved
            add_action('updated_option_metasync_options', function() use ($context) {
                error_log('Whitelabel Settings: Triggering heartbeat check after save - ' . $context);
                delete_transient('metasync_heartbeat_status_cache');
                do_action('metasync_trigger_immediate_heartbeat', 'Whitelabel domain change - ' . $context);
            });
        }
        
        return array_merge($new_input, $input);
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
        error_log('API_KEY_CALLBACK_DEBUG: has_api_key=' . ($has_api_key ? 'true' : 'false') . ', has_otto_uuid=' . ($has_otto_uuid ? 'true' : 'false'));
        
        $is_fully_connected = $this->is_heartbeat_connected();
        error_log('API_KEY_CALLBACK_DEBUG: is_fully_connected=' . ($is_fully_connected ? 'true' : 'false'));
        
        // Enhanced SSO Authentication Container (MOVED TO TOP)
        printf('<div class="metasync-sso-container">');
        
        // SSO Title and description
        printf('<div class="metasync-sso-title">');
        printf('🔐 One-Click Authentication');
        printf('</div>');
        
        printf('<div class="metasync-sso-description">');
        if ($is_fully_connected) {
            printf('Your %s account is fully synced with active heartbeat API. You can re-authenticate to refresh your connection or connect a different account.', esc_html(Metasync::get_effective_plugin_name()));
        } elseif ($has_api_key && !$has_otto_uuid) {
            printf('Your %s API key is configured, but %s UUID is missing. Please re-authenticate to complete the setup.', esc_html(Metasync::get_effective_plugin_name()), esc_html(Metasync::get_whitelabel_otto_name()));
        } else {
            printf('Connect your %s account with one click. This will automatically configure your API key and %s UUID below, enabling all plugin features.', esc_html(Metasync::get_effective_plugin_name()), esc_html(Metasync::get_whitelabel_otto_name()));
        }
        printf('</div>');
        
        // SSO Action Buttons Container
        printf('<div class="metasync-sso-buttons">');
        
        // Primary Connect/Re-authenticate button
        printf('<button type="button" id="connect-searchatlas-sso" class="metasync-sso-connect-btn">');
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
            printf('<button type="button" id="reset-searchatlas-auth" class="metasync-sso-reset-btn" style="margin-left: 10px;">');
            printf('🔓 Disconnect Account');
            printf('</button>');
        }
        
        printf('</div>'); // Close metasync-sso-buttons
        
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
        
        printf('</div>'); // Close metasync-sso-container
        
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
            printf('Your API key is configured but OTTO UUID is missing. Re-authenticate above to complete the setup and enable dashboard access.');
        } else {
            printf('This field will be automatically populated when you authenticate using the button above. You can also manually enter your API key if you have one.');
        }
        printf('</p>');
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
    public function common_robot_mata_tags_callback()
    {
        $common_robots_meta = Metasync::get_option('common_robots_mata') ?? '';

    ?>
        <ul class="checkbox-list">
            <li>
                <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[common_robots_mata][index]') ?>" id="robots_common1" value="index" <?php isset($common_robots_meta['index']) ? checked('index', $common_robots_meta['index']) : '' ?>>
                <label for="robots_common1">Index </br>
                    <span class="description">
                        <span>Search engines to index and show these pages in the search results.</span>
                    </span>
                </label>
            </li>
            <li>
                <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[common_robots_mata][noindex]') ?>" id="robots_common2" value="noindex" <?php isset($common_robots_meta['noindex']) ? checked('noindex', $common_robots_meta['noindex']) : '' ?>>
                <label for="robots_common2">No Index </br>
                    <span class="description">
                        <span>Search engines not indexed and displayed this pages in search engine results</span>
                    </span>
                </label>
            </li>
            <li>
                <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[common_robots_mata][nofollow]') ?>" id="robots_common3" value="nofollow" <?php isset($common_robots_meta['nofollow']) ? checked('nofollow', $common_robots_meta['nofollow']) : '' ?>>
                <label for="robots_common3">No Follow </br>
                    <span class="description">
                        <span>Search engines not follow the links on the pages</span>
                    </span>
                </label>
            </li>
            <li>
                <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[common_robots_mata][noarchive]') ?>" id="robots_common4" value="noarchive" <?php isset($common_robots_meta['noarchive']) ? checked('noarchive', $common_robots_meta['noarchive']) : '' ?>>
                <label for="robots_common4">No Archive </br>
                    <span class="description">
                        <span>Search engines not showing Cached links for pages</span>
                    </span>
                </label>
            </li>
            <li>
                <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[common_robots_mata][noimageindex]') ?>" id="robots_common5" value="noimageindex" <?php isset($common_robots_meta['noimageindex']) ? checked('noimageindex', $common_robots_meta['noimageindex']) : '' ?>>
                <label for="robots_common5">No Image Index </br>
                    <span class="description">
                        <span>If you do not want to apear your pages as the referring page for images that appear in image search results</span>
                    </span>
                </label>
            </li>
            <li>
                <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[common_robots_mata][nosnippet]') ?>" id="robots_common6" value="nosnippet" <?php isset($common_robots_meta['nosnippet']) ? checked('nosnippet', $common_robots_meta['nosnippet']) : '' ?>>
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
     * Get the settings option array and print one of its values
     */
    public function advance_robot_mata_tags_callback()
    {
        $advance_robots_meta = Metasync::get_option('advance_robots_mata') ?? '';

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
                    <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[advance_robots_mata][max-snippet][enable]') ?>" id="advanced_robots_snippet" value="1" <?php checked('1', esc_attr($snippet_advance_robots_enable)) ?>>
                    Snippet </br>
                    <input type="number" class="input-length" name="<?php echo esc_attr($this::option_key . '[advance_robots_mata][max-snippet][length]') ?>" id="advanced_robots_snippet_value" value="<?php echo esc_attr($snippet_advance_robots_length); ?>" min="-1"> </br>
                    <span class="description">
                        <span>Add maximum text-length, in characters, of a snippet for your page.</span>
                    </span>
                </label>
            </li>
            <li>
                <label for="advanced_robots_video">
                    <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[advance_robots_mata][max-video-preview][enable]') ?>" id="advanced_robots_video" value="1" <?php checked('1', esc_attr($video_advance_robots_enable)) ?>>
                    Video Preview </br>
                    <input type="number" class="input-length" name="<?php echo esc_attr($this::option_key . '[advance_robots_mata][max-video-preview][length]') ?>" id="advanced_robots_video_value" value="<?php echo esc_attr($video_advance_robots_length); ?>" min="-1"> </br>
                    <span class="description">
                        <span>Add maximum duration in seconds of an animated video preview.</span>
                    </span>
                </label>
            </li>
            <li>
                <label for="advanced_robots_image">
                    <input type="checkbox" name="<?php echo esc_attr($this::option_key . '[advance_robots_mata][max-image-preview][enable]') ?>" id="advanced_robots_image" value="1" <?php checked('1', esc_attr($image_advance_robots_enable)); ?>>
                    Image Preview </br>
                    <select class="input-length" name="<?php echo esc_attr($this::option_key . '[advance_robots_mata][max-image-preview][length]') ?>" id="advanced_robots_image_value">
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
}
