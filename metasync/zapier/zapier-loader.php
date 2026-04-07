<?php
/**
 * Zapier Connector Bootstrap
 *
 * Loads all Zapier integration classes and wires them into WordPress.
 *
 * @package    Metasync
 * @subpackage Metasync/zapier
 * @since      2.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-metasync-zapier-database.php';
require_once __DIR__ . '/class-metasync-zapier-dispatcher.php';
require_once __DIR__ . '/class-metasync-zapier-rest.php';

/**
 * Initialize the Zapier connector.
 * Called on 'init' at priority 10.
 */
function metasync_init_zapier(): void {
    new Metasync_Zapier_Dispatcher();
    new Metasync_Zapier_REST();
}

add_action('init', 'metasync_init_zapier', 10);
