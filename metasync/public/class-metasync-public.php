<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}


/**
 * The public-facing functionality of the plugin.
 *
 * Thin coordinator that instantiates Metasync_Rest_Api and Metasync_Seo_Output
 * and retains only asset enqueue methods, plugin links, and shortcode init.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 *
 * @package    Metasync
 * @subpackage Metasync/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Metasync
 * @subpackage Metasync/public
 * @author     Engineering Team <support@searchatlas.com>
 */

class Metasync_Public
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
	 * REST API handler instance.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      Metasync_Rest_Api    $rest_api
	 */
	private $rest_api;

	/**
	 * SEO Output handler instance.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      Metasync_Seo_Output    $seo_output
	 */
	private $seo_output;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since	1.0.0
	 * @param	string	$plugin_name	The name of the plugin.
	 * @param	string	$version		The version of this plugin.
	 */
	public function __construct($plugin_name, $version)
	{
		$this->plugin_name = $plugin_name;
		$this->version = $version;

		$this->rest_api = new Metasync_Rest_Api($plugin_name, $version);
		$this->seo_output = new Metasync_Seo_Output($plugin_name, $version);
	}

	/**
	 * Get the REST API handler instance.
	 *
	 * @return Metasync_Rest_Api
	 */
	public function get_rest_api()
	{
		return $this->rest_api;
	}

	/**
	 * Get the SEO Output handler instance.
	 *
	 * @return Metasync_Seo_Output
	 */
	public function get_seo_output()
	{
		return $this->seo_output;
	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles()
	{

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Metasync_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Metasync_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */
		$enabled_plugin_css = Metasync::get_option('general')['enabled_plugin_css'] ?? '';
		if($enabled_plugin_css!=="default" && $enabled_plugin_css!=='' ){
			wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/metasync-public.css', array(), $this->version, 'all');
		}
	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts()
	{

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Metasync_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Metasync_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		 # Enqueue the JavaScript file 'otto-tracker.js' with defer for better performance
		# Load in footer (true) and add defer attribute to prevent render blocking
		wp_enqueue_script($this->plugin_name . '-tracker', plugin_dir_url(__FILE__) . 'js/otto-tracker.min.js', array('jquery'), $this->version, true);
		wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/metasync-public.js', array('jquery'), $this->version, true);


		# Get the Otto Pixel UUID from plugin settings.
		$otto_uuid = Metasync::get_option('general')['otto_pixel_uuid'] ?? '';

		# Get the current full page URL safely.
		$page_url = esc_url(home_url(add_query_arg([], $_SERVER['REQUEST_URI'])));

		# Pass PHP data to the JavaScript file using wp_localize_script.
		# OTTO SSR is always enabled by default when UUID is set
		if (!empty($otto_uuid)) {
			wp_localize_script($this->plugin_name . '-tracker', 'saOttoData', [
				'otto_uuid' => $otto_uuid,
				'page_url'  => $page_url,
				'context'   => null,
				'enable_metadesc' => true, // Always enabled by default
			]);
		}

		# Add defer attribute to OTTO scripts for performance optimization
		add_filter('script_loader_tag', array($this, 'add_defer_attribute'), 10, 2);
	}

	/**
	 * Add defer attribute to OTTO scripts to prevent render blocking
	 * This improves PageSpeed scores by allowing scripts to load asynchronously
	 *
	 * @since    1.0.0
	 * @param    string    $tag     The script tag
	 * @param    string    $handle  The script handle
	 * @return   string    Modified script tag with defer attribute
	 */
	public function add_defer_attribute($tag, $handle) {
		# Only add defer to our OTTO scripts
		if ($handle === $this->plugin_name . '-tracker' || $handle === $this->plugin_name) {
			# Check if defer is not already present
			if (strpos($tag, 'defer') === false) {
				# Add defer attribute before the closing >
				$tag = str_replace(' src', ' defer src', $tag);
			}
		}
		return $tag;
	}

	public function metasync_plugin_init()
	{
		$this->rest_api->rest_authorization_middleware();
		$this->shortcodes_init();
	}

	public function shortcodes_init()
	{
		# add_shortcode('accordion', 'metasync_accordion');

		# name changed from accordion to accordion_metasync to avoid conflict with other plugins
		add_shortcode('accordion_metasync', 'metasync_accordion');
		// add_shortcode('markdown', 'metasync_markdown');


	function metasync_accordion($atts, $content = "")
	{
		// SECURITY FIX: Properly escape shortcode attributes
		$title = isset($atts['title']) ? esc_html($atts['title']) : '';
		$safe_content = wp_kses_post($content);

		$block = "<div class=\"metasync-accordion-block\">
			<button class=\"metasync-accordion\">{$title}</button>
			<div class=\"metasync-panel\">{$safe_content}</div>
			</div>";
		return $block;
	}
	}

	public function metasync_plugin_links($links)
	{
		# Removed Sync link as requested

		# Solving Issue 103
		# get the neneral options into a var
		$general_options = Metasync::get_option('general');

		# set the menu slug to the default
		$menu_slug = 'searchatlas';

		#now check if the menu slug is in the general opts
		if (is_array($general_options) && !empty($general_options['white_label_plugin_menu_slug'])) {

			#set the slug to the modified
			$menu_slug = $general_options['white_label_plugin_menu_slug'];
		}

		$links[] = '<a href="' . get_admin_url(null, 'admin.php?page=' .$menu_slug) . '">' . esc_html__('Settings', 'metasync') . '</a>';
		return $links;
	}

	/**
	 * Enqueue custom CSS stored in post meta for converted pages.
	 * Runs at priority 999 (after theme CSS) so custom styles win.
	 */
	public function enqueue_page_custom_css() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$custom_css = get_post_meta( $post_id, '_metasync_custom_css', true );
		if ( empty( $custom_css ) ) {
			return;
		}

		$scoped_css = $this->scope_css_to_body_class( $custom_css, $post_id );

		wp_register_style( 'metasync-page-css-' . $post_id, false );
		wp_enqueue_style( 'metasync-page-css-' . $post_id );
		wp_add_inline_style( 'metasync-page-css-' . $post_id, $scoped_css );
	}

	/**
	 * Enqueue custom CSS into Elementor editor preview iframe.
	 */
	public function enqueue_elementor_editor_css() {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$custom_css = get_post_meta( $post_id, '_metasync_custom_css', true );
		if ( empty( $custom_css ) ) {
			return;
		}

		$scoped_css = $this->scope_css_to_body_class( $custom_css, $post_id );

		wp_register_style( 'metasync-elementor-css-' . $post_id, false );
		wp_enqueue_style( 'metasync-elementor-css-' . $post_id );
		wp_add_inline_style( 'metasync-elementor-css-' . $post_id, $scoped_css );
	}

	/**
	 * Enqueue custom CSS when Divi Visual Builder is active.
	 */
	public function enqueue_divi_builder_css() {
		if ( empty( $_GET['et_fb'] ) ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$custom_css = get_post_meta( $post_id, '_metasync_custom_css', true );
		if ( empty( $custom_css ) ) {
			return;
		}

		$scoped_css = $this->scope_css_to_body_class( $custom_css, $post_id );

		wp_register_style( 'metasync-divi-css-' . $post_id, false );
		wp_enqueue_style( 'metasync-divi-css-' . $post_id );
		wp_add_inline_style( 'metasync-divi-css-' . $post_id, $scoped_css );
	}

	/**
	 * Scope all CSS selectors to body.postid-{ID} for higher specificity.
	 *
	 * @param string $css     Raw CSS.
	 * @param int    $post_id Post ID.
	 * @return string Scoped CSS.
	 */
	private function scope_css_to_body_class( $css, $post_id ) {
		$prefix  = 'body.postid-' . intval( $post_id );
		$scoped  = '';
		$pattern = '/([^{]+)\{([^}]*)\}/s';

		preg_match_all( $pattern, $css, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$selectors        = array_map( 'trim', explode( ',', $match[1] ) );
			$scoped_selectors = array_map( function( $sel ) use ( $prefix ) {
				if ( empty( $sel ) || strpos( $sel, '@' ) === 0 ) {
					return $sel;
				}
				return $prefix . ' ' . $sel;
			}, $selectors );
			$scoped .= implode( ', ', $scoped_selectors ) . ' {' . $match[2] . "}\n";
		}

		return $scoped ?: $css;
	}

}
