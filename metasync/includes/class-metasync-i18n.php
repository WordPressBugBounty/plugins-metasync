<?php

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       http://linkgraph.io
 * @since      1.0.0
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/includes
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
 */
class Metasync_i18n
{


	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain()
	{

		// echo dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/';
		$isLoaded = load_plugin_textdomain(
			'metasync',
			false,
			dirname(dirname(plugin_basename(__FILE__))) . '/languages/'
		);

		// var_dump($isLoaded);
		// echo __('Sync Now', 'metasync');
	}
}
