<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       http://linkgraph.io
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
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
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
    public $menu_title         = "SearchAtlas";
    public const page_title         = "MetaSync Settings";
    public const option_group       = "metasync_group";
    public const option_key         = "metasync_options";
    public static $page_slug          = "searchatlas";

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
    // private $data_error_log_list;

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
        $data = Metasync::get_option('general');
        if(!isset($data['white_label_plugin_menu_name'])){
            $this->menu_title = "SearchAtlas";   
        }else{
            $this->menu_title =  $data['white_label_plugin_menu_name']==""  ? "SearchAtlas":$data['white_label_plugin_menu_name'];    
        }      
        if(!isset( $data['white_label_plugin_menu_slug'])){
            self::$page_slug = "searchatlas";   
        }else{
            self::$page_slug = $data['white_label_plugin_menu_slug']==""  ? "searchatlas":$data['white_label_plugin_menu_slug'];
        } 
       
        add_action('admin_menu', array($this, 'add_plugin_settings_page'));
        add_action('admin_init', array($this, 'settings_page_init'));
        add_filter('all_plugins',  array($this,'metasync_plugin_white_label'));
        add_filter( 'plugin_row_meta',array($this,'metasync_view_detials_url'),10,3);
        add_action('update_option_metasync_options', array($this, 'check_and_redirect_slug'), 10, 3);
        
        #lets try listening for update any option
        add_action('add_option_metasync_options', array($this, 'redirect_slug_for_freshinstalls'));
        
        add_action('admin_init', array($this, 'initialize_cookie'));
        add_action('wp', function() {
            set_error_handler(array($this,'metasync_log_php_errors'));
        });       
        
        #--------------------------
        #   we are disabling this code
        #   To prevent enabling debuging on every pluging Update
        #   This causes Issue #102 on gilab
        #--------------------------
        #
        # NOTE:
        # Do not delete this as we may need it in implementing universal logging

        # add_action('upgrader_process_complete', array($this,'metasync_plugin_updated_action'), 10, 2);

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
        $log_file = WP_CONTENT_DIR . '/metasync.log';
        if (!current_user_can('manage_options')) {
            return;
        }
    
        // Handle form submission for plugin logging
        // if (isset($_POST['metasync_log_enabled'])) { 
        //     update_option('metasync_log_enabled',isset($_POST['metasync_log_enabled'])?'yes':'no');
        // }
       
        // Handle form submission for WordPress error logging
        
        if (isset($_POST['wp_debug_log_enabled'])&& isset($_POST['wp_debug_enabled'])&& isset($_POST['wp_debug_display_enabled'])) {
           
            update_option('wp_debug_enabled', ($_POST['wp_debug_enabled']=='true')?'true':'false');
            update_option('wp_debug_log_enabled', ($_POST['wp_debug_log_enabled']=='true')?'true':'false');
            update_option('wp_debug_display_enabled', ($_POST['wp_debug_display_enabled']=='true')?'true':'false');
            $data = new ConfigControllerMetaSync();
            $data->store();

        }
       
    
        // Handle deletion of the log file
        if (isset($_POST['metasync_delete_log'])) {
            unlink(WP_CONTENT_DIR . '/metasync.log');
        }
    
        $log_enabled = get_option('metasync_log_enabled', 'yes');
        $wp_debug_enabled = get_option('wp_debug_enabled', 'false');
        $wp_debug_log_enabled = get_option('wp_debug_log_enabled', 'false');
        $wp_debug_display_enabled = get_option('wp_debug_display_enabled', 'false');
        ?>
    
        <div class="wrap">
            <h1>Metasync Error Log Manager</h1>
            <!-- <form method="post">
                <h2>Metasync Plugin Logging</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Enable Error Logging</th>
                        <td>
                            <input type="checkbox" name="metasync_log_enabled" value="yes" <?php checked('yes', $log_enabled); ?> />
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Changes'); ?>
            </form> -->
    
            <form method="post" style="margin-top: 20px;">
                <input type="hidden" name="metasync_delete_log" value="yes" />
                <?php submit_button('Delete metasync Error Log', 'delete', 'delete-log'); ?>
            </form>
    
            <form method="post" style="margin-top: 40px;">
                <h2>WordPress Error Logging</h2>
                <table class="form-table">
    <tr valign="top">
        <th scope="row">WP_DEBUG</th>
        <td>
            <select name="wp_debug_enabled">
                 <!-- change the first option to Disable -->
                <option value="false" <?php selected('false', $wp_debug_enabled); ?>>Disabled</option>
                <option value="true" <?php selected('true', $wp_debug_enabled); ?>>Enabled</option>                
            </select>
        </td>
    </tr>
    <tr valign="top">
        <th scope="row">WP_DEBUG_LOG</th>
        <td>
            <select name="wp_debug_log_enabled">
                <option value="false" <?php selected('false', $wp_debug_log_enabled); ?>>Disabled</option>
                <option value="true" <?php selected('true', $wp_debug_log_enabled); ?>>Enabled</option>                
            </select>
        </td>
    </tr>
    <tr valign="top">
        <th scope="row">WP_DEBUG_DISPLAY</th>
        <td>
            <select name="wp_debug_display_enabled">
                <option value="false" <?php selected('false', $wp_debug_display_enabled); ?>>Disabled</option>
                <option value="true" <?php selected('true', $wp_debug_display_enabled); ?>>Enabled</option>                
            </select>
        </td>
    </tr>
</table>
                <?php submit_button('Save WordPress Logging Settings'); ?>
            </form>
        </div>
        <?php 
        if (isset($_POST['clear_log'])) {
            file_put_contents($log_file, '');
        }
        if (file_exists($log_file)) {
            echo '<h1>Metasync Plugin Error Log</h1>';
            echo '<pre style="background: #f1f1f1; padding: 10px; overflow: auto;">';
            echo esc_html(file_get_contents($log_file));
            echo '</pre>';
        } else {
            echo '<h1>Metasync Plugin Error Log</h1>';
            echo '<p>No log file found.</p>';
        }
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
        add_action('admin_notices', array($this, 'permalink_structure_dashboard_warning'));

        wp_enqueue_script('heartbeat');

        // wp_deregister_script('heartbeat');
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
        // update_option('metasync_heartbeat', $data);

        // if ($data['client'] == 'marco')
        $response['server'] = wp_json_encode($data);

        return $response;
    }

    /**
     * Data or Response received from HeartBeat API for admin area.
     */
    public function lgSendCustomerParams()
    {
        $sync_request = new Metasync_Sync_Requests();
        $response = $sync_request->SyncCustomerParams();

        $responseBody = wp_remote_retrieve_body($response);
        $responseCode = wp_remote_retrieve_response_code($response);

        if ($responseCode == 200) {
            $dt = new DateTime();
            $send_auth_token_timestamp = Metasync::get_option();
            $send_auth_token_timestamp['general']['send_auth_token_timestamp'] = $dt->format('M d, Y  h:i:s A');;
            Metasync::set_option($send_auth_token_timestamp);
            $result = json_decode($responseBody);
            $timestamp = @Metasync::get_option('general')['send_auth_token_timestamp'];
            $result->send_auth_token_timestamp = $timestamp;
            $result->send_auth_token_diffrence = $this->time_elapsed_string($timestamp);
            wp_send_json($result);
            wp_die();
        }

        $result = json_decode($responseBody);
        wp_send_json($result);
        wp_die();
    }

    /**
     * Add options page
     */
    public function add_plugin_settings_page()
    {
        $data= Metasync::get_option('general');
        $menu_name = !isset($data['white_label_plugin_menu_name']) || $data['white_label_plugin_menu_name']=="" ? $this::page_title : $data['white_label_plugin_menu_name'];
        $menu_title = !isset($data['white_label_plugin_menu_title']) || $data['white_label_plugin_menu_title']==""  ?  $this->menu_title : $data['white_label_plugin_menu_title'];
        $menu_slug = !isset($data['white_label_plugin_menu_slug']) || $data['white_label_plugin_menu_slug']==""  ?  self::$page_slug : $data['white_label_plugin_menu_slug'];
        $menu_icon = !isset($data['white_label_plugin_menu_icon']) ||  $data['white_label_plugin_menu_icon'] =="" ? 'dashicons-searchatlas' : $data['white_label_plugin_menu_icon'];
       
        add_menu_page(
            $menu_name,
            $menu_title,
            'manage_options',
            $menu_slug,
            array($this, 'create_admin_settings_page'),
            $menu_icon
        );

        add_submenu_page($menu_slug, 'General', 'General', 'manage_options', $menu_slug, array($this, 'create_admin_settings_page'));
        add_submenu_page($menu_slug , 'Metasync Plugin Errors', 'Metasync Errors', 'manage_options', $menu_slug.'-error-log' ,array($this, 'metasync_display_error_log'));

        // add_submenu_page(self::$page_slug, 'Dashboard', 'Dashboard', 'manage_options', self::$page_slug . '-dashboard', array($this, 'create_admin_dashboard_page'));

        // if(@Metasync::get_option('general')['enable_404monitor'])
        // add_submenu_page(self::$page_slug, '404 Monitor', '404 Monitor', 'manage_options', self::$page_slug . '-404-monitor', array($this, 'create_admin_404_monitor_page'));

        // if(@Metasync::get_option('general')['enable_siteverification'])
        // add_submenu_page(self::$page_slug, 'Site Verification', 'Site Verification', 'manage_options', self::$page_slug . '-search-engine-verify', array($this, 'create_admin_search_engine_verification_page'));

        // if(@Metasync::get_option('general')['enable_localbusiness'])
        // add_submenu_page(self::$page_slug, 'Local Business', 'Local Business', 'manage_options', self::$page_slug . '-local-business', array($this, 'create_admin_local_business_page'));

        // if(@Metasync::get_option('general')['enable_codesnippets'])
        // add_submenu_page(self::$page_slug, 'Code Snippets', 'Code Snippets', 'manage_options', self::$page_slug . '-code-snippets', array($this, 'create_admin_code_snippets_page'));

        // if(@Metasync::get_option('general')['enable_googleinstantindex'])
        // add_submenu_page(self::$page_slug, 'Google Instant Index', 'Google Instant Index', 'manage_options', self::$page_slug . '-instant-index', array($this, 'create_admin_google_instant_index_page'));

        // if(@Metasync::get_option('general')['enable_googleconsole'])
        // add_submenu_page(self::$page_slug, 'Google Console', 'Google Console', 'manage_options', self::$page_slug . '-google-console', array($this, 'create_admin_google_console_page'));

        // if(@Metasync::get_option('general')['enable_optimalsettings'])
        // add_submenu_page(self::$page_slug, 'Optimal Settings', 'Optimal Settings', 'manage_options', self::$page_slug . '-optimal-setting', array($this, 'create_admin_optimal_settings_page'));

        // if(@Metasync::get_option('general')['enable_globalsettings'])
        // add_submenu_page(self::$page_slug, 'Global Settings', 'Global Settings', 'manage_options', self::$page_slug . '-common-settings', array($this, 'create_admin_global_settings_page'));

        // if(@Metasync::get_option('general')['enable_commonmetastatus'])
        // add_submenu_page(self::$page_slug, 'Common Meta Status', 'Common Meta Status', 'manage_options', self::$page_slug . '-common-meta-settings', array($this, 'create_admin_common_meta_settings_page'));

        // if(@Metasync::get_option('general')['enable_socialmeta'])
        // add_submenu_page(self::$page_slug, 'Social Meta', 'Social Meta', 'manage_options', self::$page_slug . '-social-meta', array($this, 'create_admin_social_meta_page'));

        // if(@Metasync::get_option('general')['enable_redirections'])
        // add_submenu_page(self::$page_slug, 'Redirections', 'Redirections', 'manage_options', self::$page_slug . '-redirections', array($this, 'create_admin_redirections_page'));

        // add_submenu_page(self::$page_slug, 'Error Logs', 'Error Logs', 'manage_options', self::$page_slug . '-error-logs', array($this, 'create_admin_error_logs_page'));
        // add_submenu_page(self::$page_slug, 'HeartBeat Error Logs', 'HeartBeat Error Logs', 'manage_options', self::$page_slug . '-heartbeat-error-logs', array($this, 'create_admin_heartbeat_error_logs_page'));

        // if(@Metasync::get_option('general')['enable_errorlogs'])
        // add_submenu_page(self::$page_slug, 'Error Logs', 'Error Logs', 'manage_options', self::$page_slug . '-error-logs-list', array($this, 'creat_error_Logs_List'));

    }

    /**
     * General Options page callback
     */
    public function create_admin_settings_page()
    {
        printf('<div class="wrap">');
        printf('<h1> General Settings </h1>');
        $page_slug = self::$page_slug  . '_general';
        printf('<form method="post" action="options.php">');
        // This prints out all hidden setting fields
        settings_fields($this::option_group);
        do_settings_sections($page_slug);
        do_settings_sections(self::$page_slug . '_linkgraph');
        printf('<br/> <button tyreadonly="readonly"pe="button" class="button button-primary" id="sendAuthToken" data-toggle="tooltip" data-placement="top" title="Sync Categories and User">Sync Now</button>');

        // do_settings_sections(self::$page_slug . '_sitemap');
        submit_button();
        printf('</form>');
        printf('</div>');
    }

    /**
     * Dashboard page callback
     */
    public function create_admin_dashboard_page()
    {
        printf('<h1> Dashboard </h1>');
        if (!isset(Metasync::get_option('general')['linkgraph_token']) || Metasync::get_option('general')['linkgraph_token'] == '') {
            printf('<span>Authenticate with your Link Graph account. Save your Link Graph auth token in general settings</span>');
            return;
        }
?>
        <a href="https://dashboard.linkgraph.io/?jwtToken=<?php echo esc_attr(Metasync::get_option('general')['linkgraph_token']); ?>" target="_blank">Go to Link Graph Dashbord</a>
    <?php
    }

    /**
     * 404 Monitor page callback
     */
    public function create_admin_404_monitor_page()
    {
        printf('<h1> 404 Monitor Logs </h1>');
        $ErrorMonitor = new Metasync_Error_Monitor($this->database);
        $ErrorMonitor->create_admin_plugin_interface();
    }

    /**
     * Site Verification page callback
     */
    public function create_admin_search_engine_verification_page()
    {
        $page_slug = self::$page_slug . '_searchengines-verification';

        printf('<h1> Site Verification </h1>');
        printf('<form method="post" action="options.php">');
        // This prints out all hidden setting fields
        settings_fields($this::option_group);
        do_settings_sections($page_slug);

        submit_button();
        printf('</form>');
    }

    /**
     * Local Business page callback
     */
    public function create_admin_local_business_page()
    {
        $page_slug = self::$page_slug . '_local-seo';

        printf('<h1> Local Business </h1>');
        printf('<form method="post" action="options.php">');
        // This prints out all hidden setting fields
        settings_fields($this::option_group);
        do_settings_sections($page_slug);

        submit_button();
        printf('</form>');
    }

    /**
     * Code Snippets page callback
     */
    public function create_admin_code_snippets_page()
    {
        $page_slug = self::$page_slug . '_code-snippets';

        printf('<h1> Code Snippets </h1>');
        printf('<form method="post" action="options.php">');
        // This prints out all hidden setting fields
        settings_fields($this::option_group);
        do_settings_sections($page_slug);

        submit_button();
        printf('</form>');
    }

    /**
     * Google Instant Index Setting page callback
     */
    public function create_admin_google_instant_index_page()
    {
        $instant_index = new Metasync_Instant_Index();
        $instant_index->show_google_instant_indexing_settings();
    }

    /**
     * Google Console page callback
     */
    public function create_admin_google_console_page()
    {
        $instant_index = new Metasync_Instant_Index();
        $instant_index->show_google_instant_indexing_console();
    }

    /**
     * General Options page callback
     */
    public function create_admin_optimal_settings_page()
    {
        printf('<h1> Optimal Settings </h1>');
        $optimal_settings = new Metasync_Optimal_Settings();
        $optimal_settings->site_compatible_status_view();
        $this->optimization_settings_options();
    }

    /**
     * Global Options page callback
     */
    public function create_admin_global_settings_page()
    {
        $page_slug = self::$page_slug . '_common-settings';

        printf('<form method="post" action="options.php">');
        settings_fields($this::option_group);
        do_settings_sections($page_slug);
       
        submit_button();
        printf('</form>');
    }

    /**
     * Common Meta Options page callback
     */
    public function create_admin_common_meta_settings_page()
    {
        $page_slug = self::$page_slug . '_common-meta-settings';

        printf('<form method="post" action="options.php">');
        settings_fields($this::option_group);
        do_settings_sections($page_slug);
        submit_button();
        printf('</form>');
    }

    /**
     * Social meta page callback
     */
    public function create_admin_social_meta_page()
    {
        $page_slug = self::$page_slug . '_social-meta';

        printf('<form method="post" action="options.php">');
        settings_fields($this::option_group);
        do_settings_sections($page_slug);
        submit_button();
        printf('</form>');
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
        printf('<h1> Error Logs </h1>');

        $error_logs = new Metasync_Error_Logs();

        if ($error_logs->can_show_error_logs()) {

            $error_logs->show_copy_button();

            $error_logs->show_logs();

            $error_logs->show_info();
        }
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
        printf('<h1 class="wp-heading-inline"> HeartBeat Error Logs </h1>');
        $heartbeat_errors = new Metasync_HeartBeat_Error_Monitor($this->db_heartbeat_errors);
        $heartbeat_errors->create_admin_heartbeat_errors_interface();
    }

    /**
     * Register and add settings
     */
    public function settings_page_init()
    {
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

        // Register Admin Page URL
        register_setting(
            $this::option_group, // Option group
            $this::option_key, // Option name
            array($this, 'sanitize') // Sanitize
        );

       
        add_settings_section(
            $SECTION_METASYNC, // ID
           $this->menu_title . ' API Settings:', // Title
            function(){}, // Callback
            self::$page_slug . '_general' // Page
        );

        add_settings_field(
            'searchatlas_api_key',
            $this->menu_title .  ' API Key',
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
                    printf('<span class="description" style="color:#ff0000;opacity:1;">Please revise your Permaink structure <a href="' . get_admin_url() . 'options-permalink.php">Check Setting</a> </span>');
                } else {
                    printf('<span class="description" style="color:#008000;opacity:1;">Permalink is Okay </span>');
                }
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
        
                // Output radio button for Gutenberg
                printf(
                    '<input type="radio" id="enable_gutenberg" name="' . $this::option_key . '[general][enabled_plugin_editor]" value="gutenberg" %s  />',
                    ($enabled_plugin_editor == 'gutenberg' || ($gutenberg_enabled && !$elementor_active &&!$divi_active)) ? 'checked' : '',
                   
                );
                printf('<label for="enable_gutenberg">Gutenberg</label>');
        
                printf('<p class="description"> Choose the default page editor plugin: Elementor or Gutenberg.</p>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );
        if(is_admin()){
        add_settings_field(
            'white_label_plugin_name',
            'Plugin Name',
           function(){           
            $value = Metasync::get_option('general')['white_label_plugin_name'] ?? '';   
            printf('<input type="text" name="' . $this::option_key . '[general][white_label_plugin_name]" value="' . esc_attr($value) . '" />');
           },
           self::$page_slug . '_general',
                $SECTION_METASYNC
        );
        add_settings_field(
            'white_label_plugin_description',
            'Plugin Description',
            function(){
                $value = Metasync::get_option('general')['white_label_plugin_description'] ?? '';   
                printf('<input type="text" name="' . $this::option_key . '[general][white_label_plugin_description]" value="' . esc_attr($value) . '" />');      
               },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );
    
        add_settings_field(
            'white_label_plugin_author',
            'Author',
           function(){
            $value = Metasync::get_option('general')['white_label_plugin_author'] ?? '';   
            printf('<input type="text" name="' . $this::option_key . '[general][white_label_plugin_author]" value="' . esc_attr($value) . '" />');  
           },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );
            
        add_settings_field(
            'white_label_plugin_author_uri',
            'Author URI',
            function(){
                $value = Metasync::get_option('general')['white_label_plugin_author_uri'] ?? '';   
                printf('<input type="text" name="' . $this::option_key . '[general][white_label_plugin_author_uri]" value="' . esc_attr($value) . '" />');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );
        add_settings_field(
            'white_label_plugin_uri',
            'Plugin URI',
            function(){
                $value = Metasync::get_option('general')['white_label_plugin_uri'] ?? ''; // New option for Plugin URI
                printf('<input type="text" name="' . $this::option_key . '[general][white_label_plugin_uri]" value="' . esc_attr($value) . '" />');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );
            register_setting($this::option_group, // Option group
            $this::option_key, // Option name
            array($this, 'sanitize') // Sanitize
            );  

            add_settings_field(
                'white_label_plugin_menu_name',
                'Menu Name',
                function(){
                    $value = Metasync::get_option('general')['white_label_plugin_menu_name'] ?? '';   
                    printf('<input type="text" name="' . $this::option_key . '[general][white_label_plugin_menu_name]" value="' . esc_attr($value) . '" />');
                },
                self::$page_slug . '_general',
                $SECTION_METASYNC
            );
            add_settings_field(
                'white_label_plugin_title',
                'Menu Title',
                function(){
                    $value = Metasync::get_option('general')['white_label_plugin_menu_title'] ?? '';   
                    printf('<input type="text" name="' . $this::option_key . '[general][white_label_plugin_menu_title]" value="' . esc_attr($value) . '" />');
                },
                self::$page_slug . '_general',
                $SECTION_METASYNC
            );
            add_settings_field(
                'white_label_plugin_menu_slug',
                'Menu Slug',
                function(){
                    $value = Metasync::get_option('general')['white_label_plugin_menu_slug'] ?? '';   
                    printf('<input type="text" name="' . $this::option_key . '[general][white_label_plugin_menu_slug]" value="' . esc_attr($value) . '" />');        
                },
                self::$page_slug . '_general',
                $SECTION_METASYNC
            );
            add_settings_field(
                'white_label_plugin_menu_icon',
                'Menu Icon',
                function(){
                    $value = Metasync::get_option('general')['white_label_plugin_menu_icon'] ?? '';   
                    printf('<input type="text" name="' . $this::option_key . '[general][white_label_plugin_menu_icon]" value="' . esc_attr($value) . '" />');
                },
                self::$page_slug . '_general',
                $SECTION_METASYNC
            );
        }
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
                
        
                // Output radio button for Gutenberg
                printf(
                    '<input type="radio" id="enable_metasync" name="' . $this::option_key . '[general][enabled_plugin_css]" value="metasync" %s  />',
                    ($enabled_plugin_css == 'metasync' || ($enabled_plugin_css && !$enabled_plugin_css)) ? 'checked' : '',
                   
                );
                printf('<label for="enable_metasync">Metasync Style</label>');
        
                printf('<p class="description"> Choose the default page Style Sheet: Default or MetaSync.</p>');
            },
            self::$page_slug . '_general',
            $SECTION_METASYNC
        );


        // Features Section
        // add_settings_section(
        //     $SECTION_FEATURES, // ID
        //     '---------- Enable / Disable Features ----------', // Title
        //     null, // Callback
        //     self::$page_slug . '_general' // Page
        // );


        // foreach ($this::feature_sections as $key => $title) {
        //     add_settings_field($key, $title, function() use ($key) {
        //             $getSettings = Metasync::get_option('general')[$key] ?? '';
        //             printf(
        //                 '<input type="checkbox" id="'.$key.'" name="'.$this::option_key.'[general]['.$key.']" value="true" %s />',
        //                 isset($getSettings) && $getSettings == 'true' ? 'checked' : ''
        //             );
        //         },
        //         self::$page_slug . '_general', // Page
        //         $SECTION_FEATURES // Section
        //     );
        // }

        /**
         * Site Verification Tool Settings Section
         *
         * Bing Site Verification
         * Baidu Site Verification
         * Alexa Site Verification
         * Yandex Site Verification
         * Google Site Verification
         * Pinterest Site Verification
         * Norton Safe Web Site Verification
         */
        // add_settings_section(
        //     $SECTION_SEARCHENGINE, // ID
        //     null, // Title
        //     null, // Callback
        //     self::$page_slug . '_searchengines-verification' // Page
        // );

        // add_settings_field(
        //     'bing_site_verification',
        //     'Bing Site Verification',
        //     array($this, 'bing_site_verification_callback'),
        //     self::$page_slug . '_searchengines-verification',
        //     $SECTION_SEARCHENGINE
        // );

        // add_settings_field(
        //     'baidu_site_verification',
        //     'Baidu Site Verification',
        //     array($this, 'baidu_site_verification_callback'),
        //     self::$page_slug . '_searchengines-verification',
        //     $SECTION_SEARCHENGINE
        // );

        // add_settings_field(
        //     'alexa_site_verification',
        //     'Alexa Site Verification',
        //     array($this, 'alexa_site_verification_callback'),
        //     self::$page_slug . '_searchengines-verification',
        //     $SECTION_SEARCHENGINE
        // );

        // add_settings_field(
        //     'yandex_site_verification',
        //     'Yandex Site Verification',
        //     array($this, 'yandex_site_verification_callback'),
        //     self::$page_slug . '_searchengines-verification',
        //     $SECTION_SEARCHENGINE
        // );

        // add_settings_field(
        //     'google_site_verification',
        //     'Google Site Verification',
        //     array($this, 'google_site_verification_callback'),
        //     self::$page_slug . '_searchengines-verification',
        //     $SECTION_SEARCHENGINE
        // );

        // add_settings_field(
        //     'pinterest_site_verification',
        //     'Pinterest Site Verification',
        //     array($this, 'pinterest_site_verification_callback'),
        //     self::$page_slug . '_searchengines-verification',
        //     $SECTION_SEARCHENGINE
        // );

        // add_settings_field(
        //     'norton_save_site_verification',
        //     'Norton Save Site Verification',
        //     array($this, 'norton_save_site_verification_callback'),
        //     self::$page_slug . '_searchengines-verification',
        //     $SECTION_SEARCHENGINE
        // );

        // /**
        //  * Local Business SEO Settings Section
        //  *
        //  */
        // add_settings_section(
        //     $SECTION_LOCALSEO, // ID
        //     null, // Title
        //     null, // Callback
        //     self::$page_slug . '_local-seo' // Page
        // );

        // add_settings_field(
        //     'local-seo-person-organization',
        //     'Person or Organization',
        //     array($this, 'local_seo_person_organization_callback'),
        //     self::$page_slug . '_local-seo',
        //     $SECTION_LOCALSEO
        // );

        // add_settings_field(
        //     'local-seo-name',
        //     'Name',
        //     array($this, 'local_seo_name_callback'),
        //     self::$page_slug . '_local-seo',
        //     $SECTION_LOCALSEO
        // );

        // add_settings_field(
        //     'local-seo-logo',
        //     'Logo',
        //     array($this, 'local_seo_logo_callback'),
        //     self::$page_slug . '_local-seo',
        //     $SECTION_LOCALSEO
        // );

        // add_settings_field(
        //     'local-seo-url',
        //     'URL',
        //     array($this, 'local_seo_url_callback'),
        //     self::$page_slug . '_local-seo',
        //     $SECTION_LOCALSEO
        // );

        // add_settings_field(
        //     'local-seo-email',
        //     'Email',
        //     array($this, 'local_seo_email_callback'),
        //     self::$page_slug . '_local-seo',
        //     $SECTION_LOCALSEO
        // );

        // add_settings_field(
        //     'local-seo-phone',
        //     'Phone',
        //     array($this, 'local_seo_phone_callback'),
        //     self::$page_slug . '_local-seo',
        //     $SECTION_LOCALSEO
        // );

        // add_settings_field(
        //     'local-seo-address',
        //     'Address',
        //     array($this, 'local_seo_address_callback'),
        //     self::$page_slug . '_local-seo',
        //     $SECTION_LOCALSEO
        // );

        // add_settings_field(
        //     'local-seo-business-type',
        //     'Business Type',
        //     array($this, 'local_seo_business_type_callback'),
        //     self::$page_slug . '_local-seo',
        //     $SECTION_LOCALSEO
        // );

        // add_settings_field(
        //     'local-seo-hours-format',
        //     'Opening Hours Format',
        //     array($this, 'local_seo_hours_format_callback'),
        //     self::$page_slug . '_local-seo',
        //     $SECTION_LOCALSEO
        // );

        // add_settings_field(
        //     'local-seo-opening-hours',
        //     'Opening Hours',
        //     array($this, 'local_seo_opening_hours_callback'),
        //     self::$page_slug . '_local-seo',
        //     $SECTION_LOCALSEO
        // );

        // add_settings_field(
        //     'local-seo-phone-numbers',
        //     'Phone Numbers',
        //     array($this, 'local_seo_phone_numbers_callback'),
        //     self::$page_slug . '_local-seo',
        //     $SECTION_LOCALSEO
        // );

        // add_settings_field(
        //     'local-seo-price-range',
        //     'Price Range',
        //     array($this, 'local_seo_price_range_callback'),
        //     self::$page_slug . '_local-seo',
        //     $SECTION_LOCALSEO
        // );

        // add_settings_field(
        //     'local-seo-about-page',
        //     'About Page',
        //     array($this, 'local_seo_about_page_callback'),
        //     self::$page_slug . '_local-seo',
        //     $SECTION_LOCALSEO
        // );

        // add_settings_field(
        //     'local-seo-contact-page',
        //     'Contact Page',
        //     array($this, 'local_seo_contact_page_callback'),
        //     self::$page_slug . '_local-seo',
        //     $SECTION_LOCALSEO
        // );

        // add_settings_field(
        //     'local-seo-map-key',
        //     'Google Map Key',
        //     array($this, 'local_seo_map_key_callback'),
        //     self::$page_slug . '_local-seo',
        //     $SECTION_LOCALSEO
        // );

        // add_settings_field(
        //     'local-seo-geo-coordinates',
        //     'Geo Coordinates',
        //     array($this, 'local_seo_geo_coordinates_callback'),
        //     self::$page_slug . '_local-seo',
        //     $SECTION_LOCALSEO
        // );

        /**
         * Code Snippets Settings Section
         *
         */
        // add_settings_section(
        //     $SECTION_CODESNIPPETS, // ID
        //     null, // Title
        //     null, // Callback
        //     self::$page_slug . '_code-snippets' // Page
        // );

        // add_settings_field(
        //     'header-snippets',
        //     'Header Snippets',
        //     array($this, 'header_snippets_callback'),
        //     self::$page_slug . '_code-snippets',
        //     $SECTION_CODESNIPPETS
        // );

        // add_settings_field(
        //     'footer-snippets',
        //     'Footer Snippets',
        //     array($this, 'footer_snippets_callback'),
        //     self::$page_slug . '_code-snippets',
        //     $SECTION_CODESNIPPETS
        // );

        /**
         * Optimal Settings Settings Section
         *
         */
        // add_settings_section(
        //     $SECTION_OPTIMAL_SETTINGS, // ID
        //     'Site Optimization', // Title
        //     null, // Callback
        //     self::$page_slug . '_optimal-settings' // Page
        // );

        // add_settings_field(
        //     'no-index-posts',
        //     'No Index Posts',
        //     array($this, 'no_index_posts_callback'),
        //     self::$page_slug . '_optimal-settings',
        //     $SECTION_OPTIMAL_SETTINGS
        // );

        // add_settings_field(
        //     'no-follow_posts',
        //     'No Follow Posts',
        //     array($this, 'no_follow_links_callback'),
        //     self::$page_slug . '_optimal-settings',
        //     $SECTION_OPTIMAL_SETTINGS
        // );

        // add_settings_field(
        //     'open-external-links',
        //     'Open External Links',
        //     array($this, 'open_external_links_callback'),
        //     self::$page_slug . '_optimal-settings',
        //     $SECTION_OPTIMAL_SETTINGS
        // );

        // add_settings_field(
        //     'add-alt-image-tags',
        //     'Add ALT to Image Tags',
        //     array($this, 'add_alt_image_tags_callback'),
        //     self::$page_slug . '_optimal-settings',
        //     $SECTION_OPTIMAL_SETTINGS
        // );

        // add_settings_field(
        //     'add-title-image-tags',
        //     'Add Title to Image Tags',
        //     array($this, 'add_title_image_tags_callback'),
        //     self::$page_slug . '_optimal-settings',
        //     $SECTION_OPTIMAL_SETTINGS
        // );

        /**
         * Site Information section of Optimal Settings
         *
         */
        // add_settings_section(
        //     $SECTION_SITE_SETTINGS, // ID
        //     'Site Information', // Title
        //     null, // Callback
        //     self::$page_slug . '_site-info-settings' // Page
        // );

        // add_settings_field(
        //     'site_type', // ID
        //     get_bloginfo('name') . ' is a', // Title
        //     array($this, 'site_type_callback'), // Callback
        //     self::$page_slug . '_site-info-settings', // Page
        //     $SECTION_SITE_SETTINGS // Section
        // );

        // add_settings_field(
        //     'site_business_type', // ID
        //     'Site Business Type', // Title
        //     array($this, 'site_business_type_callback'), // Callback
        //     self::$page_slug . '_site-info-settings', // Page
        //     $SECTION_SITE_SETTINGS // Section
        // );

        // add_settings_field(
        //     'site_company_name', // ID
        //     'Company Name', // Title
        //     array($this, 'site_company_name_callback'), // Callback
        //     self::$page_slug . '_site-info-settings', // Page
        //     $SECTION_SITE_SETTINGS // Section
        // );

        // add_settings_field(
        //     'site_google_logo', // ID
        //     'Site Logo for Google', // Title
        //     array($this, 'site_google_logo_callback'), // Callback
        //     self::$page_slug . '_site-info-settings', // Page
        //     $SECTION_SITE_SETTINGS // Section
        // );

        // add_settings_field(
        //     'site_social_share_image', // ID
        //     'Site Social Share Image', // Title
        //     array($this, 'site_social_share_image_callback'), // Callback
        //     self::$page_slug . '_site-info-settings', // Page
        //     $SECTION_SITE_SETTINGS // Section
        // );


        /**
         *  Section of Common Settings
         *
         */
        // add_settings_section(
        //     $SECTION_COMMON_SETTINGS, // ID
        //     'Global Settings', // Title
        //     null, // Callback
        //     self::$page_slug . '_common-settings' // Page
        // );

        // add_settings_field(
        //     'robots-mata', // ID
        //     'Robots Mata', // Title
        //     array($this, 'common_robot_mata_tags_callback'), // Callback
        //     self::$page_slug . '_common-settings', // Page
        //     $SECTION_COMMON_SETTINGS // Section
        // );

        // add_settings_field(
        //     'advance-robots-mata', // ID
        //     'Advance Robots Mata', // Title
        //     array($this, 'advance_robot_mata_tags_callback'), // Callback
        //     self::$page_slug . '_common-settings', // Page
        //     $SECTION_COMMON_SETTINGS // Section
        // );

        // add_settings_field(
        //     'twitter-card-type', // ID
        //     'Twitter Card Type', // Title
        //     array($this, 'global_twitter_card_type_callback'), // Callback
        //     self::$page_slug . '_common-settings', // Page
        //     $SECTION_COMMON_SETTINGS // Section
        // );

        /**
         *  Section of Common Settings
         *
         */
        // add_settings_section(
        //     $SECTION_COMMON_META_SETTINGS, // ID
        //     'Common Meta Settings', // Title
        //     null, // Callback
        //     self::$page_slug . '_common-meta-settings' // Page
        // );

        // add_settings_field(
        //     'open-graph-meta-tags', // ID
        //     'Open Graph Meta Tags', // Title
        //     array($this, 'global_open_graph_meta_callback'), // Callback
        //     self::$page_slug . '_common-meta-settings', // Page
        //     $SECTION_COMMON_META_SETTINGS // Section
        // );

        // add_settings_field(
        //     'facebook-meta-tags', // ID
        //     'Facebook Meta Tags', // Title
        //     array($this, 'global_facebook_meta_callback'), // Callback
        //     self::$page_slug . '_common-meta-settings', // Page
        //     $SECTION_COMMON_META_SETTINGS // Section
        // );

        // add_settings_field(
        //     'twitter-meta-tags', // ID
        //     'Twitter Meta Tags', // Title
        //     array($this, 'global_twitter_meta_callback'), // Callback
        //     self::$page_slug . '_common-meta-settings', // Page
        //     $SECTION_COMMON_META_SETTINGS // Section
        // );

        /**
         *  Social meta Section.
         *
         */
        // add_settings_section(
        //     $SECTION_SOCIAL_META, // ID
        //     'Social Meta', // Title
        //     null, // Callback
        //     self::$page_slug . '_social-meta' // Page
        // );

        // add_settings_field(
        //     'facebook-page-url', // ID
        //     'Facebook Page URL', // Title
        //     array($this, 'facebook_page_url_callback'), // Callback
        //     self::$page_slug . '_social-meta', // Page
        //     $SECTION_SOCIAL_META // Section
        // );

        // add_settings_field(
        //     'facebook-authorship', // ID
        //     'Facebook Authorship', // Title
        //     array($this, 'facebook_authorship_callback'), // Callback
        //     self::$page_slug . '_social-meta', // Page
        //     $SECTION_SOCIAL_META // Section
        // );

        // add_settings_field(
        //     'facebook-admin', // ID
        //     'Facebook Admin', // Title
        //     array($this, 'facebook_admin_callback'), // Callback
        //     self::$page_slug . '_social-meta', // Page
        //     $SECTION_SOCIAL_META // Section
        // );

        // add_settings_field(
        //     'facebook-app', // ID
        //     'Facebook App', // Title
        //     array($this, 'facebook_app_callback'), // Callback
        //     self::$page_slug . '_social-meta', // Page
        //     $SECTION_SOCIAL_META // Section
        // );

        // add_settings_field(
        //     'facebook-secret', // ID
        //     'Facebook Secret', // Title
        //     array($this, 'facebook_secret_callback'), // Callback
        //     self::$page_slug . '_social-meta', // Page
        //     $SECTION_SOCIAL_META // Section
        // );

        // add_settings_field(
        //     'twitter username', // ID
        //     'Twitter Username', // Title
        //     array($this, 'twitter_username_callback'), // Callback
        //     self::$page_slug . '_social-meta', // Page
        //     $SECTION_SOCIAL_META // Section
        // );
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

        return array_merge($new_input, $input);
    }

    public function metasync_settings_genkey_callback()
    {
        $str_result = '0123456789abcdefghijklmnopqrstuvwxyz';
        $randomString = substr(
            str_shuffle($str_result),
            0,
            36
        );

        printf(
            '<input type="text" id="apikey" name="' . $this::option_key . '[general][apikey]" value="%s" size="40" readonly="readonly" /> ',
            isset(Metasync::get_option('general')['apikey']) ? esc_attr(Metasync::get_option('general')['apikey']) : esc_attr($randomString)
        );
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
        <p id="lgerror" class="notice notice-error hidden"></p>
    <?php
    }


    private function time_elapsed_string($datetime, $full = false)
    {
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
        printf(
            '<input type="text" id="searchatlas-api-key" name="' . $this::option_key . '[general][searchatlas_api_key]" value="%s" size="40"  />',
            isset(Metasync::get_option('general')['searchatlas_api_key']) ? esc_attr(Metasync::get_option('general')['searchatlas_api_key']) : ''
        );
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
    public function baidu_site_verification_callback()
    {
        printf(
            '<input type="text" id="baidu_site_verification" name="' . $this::option_key . '[searchengines][baidu_site_verification]" value="%s" size="50" />',
            isset(Metasync::get_option('searchengines')['baidu_site_verification']) ? esc_attr(Metasync::get_option('searchengines')['baidu_site_verification']) : ''
        );

        printf(' <br> <span class="description"> Enter Baidu Webmaster verification code: </span>');
        printf(' <a href="https://ziyuan.baidu.com/site/" target="_blank">Get from here</a> <br> ');

        /// highlight_string('<meta name="baidu-site-verification" content="XXXXXXXXXXXXXXXXXXXXX" />');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function alexa_site_verification_callback()
    {
        printf(
            '<input type="text" id="alexa_site_verification" name="' . $this::option_key . '[searchengines][alexa_site_verification]" value="%s" size="50" />',
            isset(Metasync::get_option('searchengines')['alexa_site_verification']) ? esc_attr(Metasync::get_option('searchengines')['alexa_site_verification']) : ''
        );

        printf(' <br> <span class="description"> Enter Alexa verification code: </span>');
        printf(' <a href="https://www.alexa.com/login" target="_blank">Get from here</a> <br> ');

        /// highlight_string('<meta name="alexaVerifyID" content="XXXXXXXXXXXXXXXXXXXXX" />');
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
     * Get the settings option array and print one of its values
     */
    public function norton_save_site_verification_callback()
    {
        printf(
            '<input type="text" id="norton_save_site_verification" name="' . $this::option_key . '[searchengines][norton_save_site_verification]" value="%s" size="50" />',
            isset(Metasync::get_option('searchengines')['norton_save_site_verification']) ? esc_attr(Metasync::get_option('searchengines')['norton_save_site_verification']) : ''
        );

        printf(' <br> <span class="description"> Enter Norton Safe Web verification code: </span>');
        printf(' <a href="https://support.norton.com/sp/en/in/home/current/solutions/kb20090410134005EN" target="_blank">Get from here</a> <br> ');

        /// highlight_string('<meta name="norton-safeweb-site-verification" content="XXXXXXXXXXXXXXXXXXXXX" />');
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
    public function local_seo_hours_format_callback()
    {
        $hours_format = Metasync::get_option('localseo')['local_seo_hours_format'] ?? '';
    ?>
        <select name="<?php echo esc_attr($this::option_key . '[localseo][local_seo_hours_format]') ?>">
            <?php
            printf('<option value="12:00" %s >12:00</option>', selected('12:00', esc_attr($hours_format)));
            printf('<option value="24:00" %s >24:00</option>', selected('24:00', esc_attr($hours_format)));
            ?>
        </select>
    <?php
        printf(' <br> <span class="description"> Time format used in the contact shortcode. </span>');
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
    public function permalink_structure_dashboard_warning()
    {
        $current_permalink_structure = get_option('permalink_structure');
        $current_rewrite_rules = get_option('rewrite_rules');
        // Check if the current permalink structure is set to "Plain"
        if (($current_permalink_structure == '/%post_id%/' || $current_permalink_structure == '') && $current_rewrite_rules == '') {       
            printf( '<div class="notice notice-error is-dismissible">
                <p>Please revise your Permaink structure to be anything besides "Plain"</p>
            </div>');   
        }
        flush_rewrite_rules();
    }
}
