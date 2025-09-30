<?php

/**
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2022, Search Atlas Group - support@searchatlas.com
 * @link		https://searchatlas.com/
 * @since		1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:       Search Atlas SEO
 * Plugin URI:        https://searchatlas.com/
 * Description:       Search Atlas SEO is an intuitive WordPress Plugin that transforms the most complicated, most labor-intensive SEO tasks into streamlined, straightforward processes. With a few clicks, the meta-bulk update feature automates the re-optimization of meta tags using AI to increase clicks. Stay up-to-date with the freshest Google Search data for your entire site or targeted URLs within the Meta Sync plug-in page.
 * Version:           2.5.5
 * Author:            Search Atlas
 * Author URI:        https://searchatlas.com
 * License:           GPL v3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:       metasync
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */

$metasync_version = '2.5.5';
define('METASYNC_VERSION', $metasync_version == '2.5.5' ? '9.9.9' : $metasync_version);

/**
 * Define the current required php version 
 * This will be used to validate whether the user can install the plugin or not
 */
define('METASYNC_MIN_PHP', '7.1');

/**
 * Define the current required php version 
 * This will be used to validate whether the user can install the plugin or not
 */
define('METASYNC_MIN_WP', '5.2');

/**
 * Telemetry Configuration Constants
 * These replace the old database options for better security and consistency
 */
define('METASYNC_SENTRY_PROJECT_ID', '4509950439849985');
define('METASYNC_SENTRY_ENVIRONMENT', 'production');
define('METASYNC_SENTRY_RELEASE', METASYNC_VERSION);
define('METASYNC_SENTRY_SAMPLE_RATE', 1.0);

/**
 * Define whether to show the plugin status in WordPress admin top navigation bar
 * Set to false to hide the status indicator
 */
define('METASYNC_SHOW_ADMIN_BAR_STATUS', true);


/**
 * Centralized class loading function
 */
function metasync_load_class($class_name) {
    $class_map = [
        'Metasync_Sync_History_Database' => 'sync-history/class-metasync-sync-history-database.php',
        //'Metasync_Admin' => 'admin/class-metasync-admin.php',
        //'Metasync_Public' => 'public/class-metasync-public.php',
        //'Metasync_Activator' => 'includes/class-metasync-activator.php',
        //'MetaSync_DBMigration' => 'database/class-db-migrations.php',
        // Add more classes as needed
    ];
    
    if (isset($class_map[$class_name])) {
        $file_path = plugin_dir_path(__FILE__) . $class_map[$class_name];
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
}

// Register the autoloader
spl_autoload_register('metasync_load_class');

/**
 * Include the Otto Pixel Php Code
 */
require_once plugin_dir_path( __FILE__ ) . '/otto/otto_pixel.php';

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-metasync-activator.php
 */
function activate_metasync()
{
    # Include WordPress plugin functions to access plugin metadata
    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    # Get plugin data
    $plugin_data = get_plugin_data(__FILE__);
	
	# Get the plugin name
    $plugin_name = $plugin_data['Name']; 

    # Get the current WordPress
    global $wp_version;

	# Get the current php version
    $php_version = PHP_VERSION;

    # Check for incompatible WordPress version
    if (version_compare($wp_version, METASYNC_MIN_WP, '<')) {
		
		#show error
		wp_die(

			#craft the message
            sprintf(
                '%s requires WordPress version %s or later. You are currently using version %s. Please update WordPress to activate this plugin.',
                esc_html($plugin_name),
                METASYNC_MIN_WP,
                esc_html($wp_version)
            ),

			#the plugin title as page title
            esc_html($plugin_name).'Plugin Activation Error',

			#include the back link
            ['back_link' => true]
        );
    }

    # Check for incompatible PHP version
    if (version_compare($php_version, METASYNC_MIN_PHP, '<')) {
        
		#show error message
		wp_die(

			#craft the message
            sprintf(
                '%s requires PHP version %s or later. You are currently using version %s. Please update PHP to activate this plugin.',
                esc_html($plugin_name),
                METASYNC_MIN_PHP,
                esc_html($php_version)
            ),

			#the plugin title as page title
            esc_html($plugin_name).'Plugin Activation Error',

			#include the back link
            ['back_link' => true]
        );
    }

	require_once plugin_dir_path(__FILE__) . 'includes/class-metasync-activator.php';
	require_once plugin_dir_path(__FILE__) . 'database/class-db-migrations.php';
	Metasync_Activator::activate();
    // class name is changed at class-db-migrations.php
	MetaSync_DBMigration::activation();
}


require_once plugin_dir_path(__FILE__) . 'log-sync/log-sync.php';

/**
 * Initialize telemetry system for error monitoring and Sentry integration
 */
#require_once plugin_dir_path(__FILE__) . 'telemetry/telemetry-init.php';

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-metasync-deactivator.php
 */
function deactivate_metasync()
{
	require_once plugin_dir_path(__FILE__) . 'includes/class-metasync-deactivator.php';
	require_once plugin_dir_path(__FILE__) . 'database/class-db-migrations.php';
	Metasync_Deactivator::deactivate();
    // class name is changed at class-db-migrations.php
	MetaSync_DBMigration::deactivation();
}

register_activation_hook(__FILE__, 'activate_metasync');
register_deactivation_hook(__FILE__, 'deactivate_metasync');

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path(__FILE__) . 'admin/class-metasync-admin.php';

try {
	require plugin_dir_path(__FILE__) . 'includes/class-metasync.php';
} catch (Exception $e) {
	
    # Log into the default PHP error log and trigger error
    error_log($e->getMessage());
}

function run_metasync()
{
	$plugin = new Metasync();
	$plugin->run();
}
run_metasync();

/**
 * Global convenience function to get active JWT token
 * Can be called from anywhere in WordPress (themes, other plugins, etc.)
 * 
 * @param bool $force_refresh Force generation of new token even if cached one exists
 * @return string|false JWT token on success, false on failure
 */
if (!function_exists('metasync_get_jwt_token')) {
	function metasync_get_jwt_token($force_refresh = false)
	{
		return Metasync::get_jwt_token($force_refresh);
	}
}

require plugin_dir_path(__FILE__) . 'MetaSyncDebug.php';
