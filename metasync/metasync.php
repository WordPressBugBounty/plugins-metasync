<?php

/**
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @package     Search Engine Labs SEO
 * @copyright   Copyright (C) 2021-2022, Search Engine Labs SEO - support@linkgraph.io
 * @link		https://linkgraph.io
 * @since		1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:       Search Engine Labs Content
 * Plugin URI:        https://www.linkgraph.com/searchatlas-seo-software/
 * Description:       Search Engine Labs SEO is an intuitive WordPress Plugin that transforms the most complicated, most labor-intensive SEO tasks into streamlined, straightforward processes. With a few clicks, the meta-bulk update feature automates the re-optimization of meta tags using AI to increase clicks. Stay up-to-date with the freshest Google Search data for your entire site or targeted URLs within the Meta Sync plug-in page.
 * Version:           1.8.7
 * Author:            LinkGraph
 * Author URI:        https://linkgraph.io/
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
define('METASYNC_VERSION', '1.8.7');

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-metasync-activator.php
 */
function activate_metasync()
{
	require_once plugin_dir_path(__FILE__) . 'includes/class-metasync-activator.php';
	require_once plugin_dir_path(__FILE__) . 'database/class-db-migrations.php';
	Metasync_Activator::activate();
	DBMigration::activation();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-metasync-deactivator.php
 */
function deactivate_metasync()
{
	require_once plugin_dir_path(__FILE__) . 'includes/class-metasync-deactivator.php';
	require_once plugin_dir_path(__FILE__) . 'database/class-db-migrations.php';
	Metasync_Deactivator::deactivate();
	DBMigration::deactivation();
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
	$error_log =new Metasync_Admin;
    $error_log->metasync_log_error($e->getMessage());
}

function run_metasync()
{
	$plugin = new Metasync();
	$plugin->run();
}
run_metasync();
require plugin_dir_path(__FILE__) . 'MetaSyncDebug.php';
