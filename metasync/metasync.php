<?php

/**
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @package     Search Engine Labs SEO
 * @copyright   Copyright (C) 2021-2022, Search Atlas Group - support@searchatlas.com
 * @link		https://searchatlas.com/
 * @since		1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:       Search Engine Labs Content
 * Plugin URI:        https://searchatlas.com/
 * Description:       Search Engine Labs SEO is an intuitive WordPress Plugin that transforms the most complicated, most labor-intensive SEO tasks into streamlined, straightforward processes. With a few clicks, the meta-bulk update feature automates the re-optimization of meta tags using AI to increase clicks. Stay up-to-date with the freshest Google Search data for your entire site or targeted URLs within the Meta Sync plug-in page.
 * Version:           2.2.3
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
define('METASYNC_VERSION', '2.2.3');

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
	
    # Log into the default PHP error log and tringger error
    error_log($e->getMessage());
}

function run_metasync()
{
	$plugin = new Metasync();
	$plugin->run();
}
run_metasync();
require plugin_dir_path(__FILE__) . 'MetaSyncDebug.php';
