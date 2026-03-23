<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Asset Enqueuing
 *
 * Registers and enqueues all admin-side CSS and JavaScript files.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */
class Metasync_Admin_Assets
{
    private static $instance = null;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Register the stylesheets for the admin area.
     *
     * @param string $plugin_name The plugin identifier.
     * @param string $version     The plugin version.
     * @param string $admin_dir_url URL to the admin/ directory (trailing slash).
     */
    public function enqueue_styles($plugin_name, $version, $admin_dir_url)
    {
        wp_enqueue_style(
            $plugin_name,
            $admin_dir_url . 'css/metasync-admin.css',
            array(),
            $version,
            'all'
        );

        wp_enqueue_style(
            $plugin_name . '-dashboard',
            $admin_dir_url . 'css/metasync-dashboard.css',
            array($plugin_name),
            $version,
            'all'
        );

        if (isset($_GET['page']) && strpos($_GET['page'], '-setup-wizard') !== false) {
            wp_enqueue_style(
                $plugin_name . '-setup-wizard',
                $admin_dir_url . 'css/metasync-setup-wizard.css',
                array($plugin_name . '-dashboard'),
                $version,
                'all'
            );
        }
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @param string $plugin_name The plugin identifier.
     * @param string $version     The plugin version.
     * @param string $admin_dir_url URL to the admin/ directory (trailing slash).
     */
    public function enqueue_scripts($plugin_name, $version, $admin_dir_url)
    {
        wp_enqueue_media();

        wp_enqueue_script(
            $plugin_name,
            $admin_dir_url . 'js/metasync-admin.js',
            array('jquery'),
            $version,
            false
        );

        wp_enqueue_script(
            $plugin_name . '-dashboard',
            $admin_dir_url . 'js/metasync-dashboard.js',
            array('jquery', $plugin_name),
            $version,
            true
        );

        wp_enqueue_script(
            $plugin_name . '-theme-switcher',
            $admin_dir_url . 'js/metasync-theme-switcher.js',
            array('jquery'),
            $version,
            true
        );
        
        wp_localize_script(
            $plugin_name . '-theme-switcher',
            'metasyncThemeData',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('metasync_theme_nonce'),
                'currentTheme' => get_option('metasync_theme', 'dark')
            )
        );

        $options = Metasync::get_option('general');
        $general_settings = Metasync::get_option('general');
        $searchatlas_api_key = isset($general_settings['searchatlas_api_key']) ? $general_settings['searchatlas_api_key'] : '';
        $otto_pixel_uuid = isset($general_settings['otto_pixel_uuid']) ? $general_settings['otto_pixel_uuid'] : '';
        
        // SECURITY FIX (CVE-2025-14386): Only generate Search Atlas connect nonce for administrators
        $sa_connect_nonce = '';
        if (current_user_can('manage_options')) {
            $sa_connect_nonce = wp_create_nonce('metasync_sa_connect_nonce');
        }
        
        $heartbeat_state = Metasync_Heartbeat_Manager::instance()->get_heartbeat_state();
        wp_localize_script( $plugin_name, 'metaSync', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
			'admin_url'=>admin_url('admin.php'),
			'sa_connect_nonce' => $sa_connect_nonce,
			'reset_auth_nonce' => wp_create_nonce('metasync_reset_auth_nonce'),
			'burst_ping_nonce' => wp_create_nonce('metasync_burst_ping'),
			'heartbeat_state' => $heartbeat_state,
			'dashboard_domain' => Metasync_Admin::get_effective_dashboard_domain(),
			'support_email' => Metasync::SUPPORT_EMAIL,
			'documentation_domain' => Metasync::DOCUMENTATION_DOMAIN,
			'debug_enabled' => WP_DEBUG || (defined('METASYNC_DEBUG') && constant('METASYNC_DEBUG')),
			'searchatlas_api_key' => !empty($searchatlas_api_key),
			'otto_pixel_uuid' => $otto_pixel_uuid,
			'is_connected' => (bool)Metasync_Heartbeat_Manager::instance()->is_heartbeat_connected()
        ));
        
        wp_enqueue_script('wp-util');
        
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
        wp_add_inline_script($plugin_name, $inline_script);

        if (isset($_GET['page']) && strpos($_GET['page'], '-setup-wizard') !== false) {
            wp_enqueue_script(
                $plugin_name . '-setup-wizard',
                $admin_dir_url . 'js/metasync-setup-wizard.js',
                array('jquery'),
                $version,
                true
            );

            wp_localize_script($plugin_name . '-setup-wizard', 'metasyncWizardData', array(
                'nonce' => wp_create_nonce('metasync_wizard'),
                'ssoNonce' => wp_create_nonce('metasync_sso_nonce'),
                'importNonce' => wp_create_nonce('metasync_import_external_data'),
                'dashboardUrl' => admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-dashboard'),
                'currentStep' => 1,
                'totalSteps' => 6,
                'pluginName' => Metasync::get_effective_plugin_name()
            ));
        }

        wp_enqueue_script('heartbeat');
    }
}
