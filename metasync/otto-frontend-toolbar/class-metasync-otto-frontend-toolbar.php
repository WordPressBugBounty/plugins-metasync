<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * OTTO Frontend Toolbar
 *
 * Provides a frontend control bar for authenticated users to enable/disable OTTO on specific pages
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 *
 * @package    Metasync
 * @subpackage Metasync/otto-frontend-toolbar
 */

/**
 * Class Metasync_Otto_Frontend_Toolbar
 *
 * Manages the frontend toolbar for OTTO control on individual pages/posts
 */
class Metasync_Otto_Frontend_Toolbar {

	/**
	 * Meta key used to store OTTO enabled/disabled state
	 */
	const META_KEY = '_metasync_otto_disabled';

	/**
	 * Plugin name
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * Plugin version
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param string $plugin_name The name of the plugin.
	 * @param string $version     The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		// Handle toggle on page load
		add_action( 'init', array( $this, 'handle_toggle_request' ) );
		
		// Handle AJAX toggle request
		add_action( 'wp_ajax_metasync_otto_toggle', array( $this, 'ajax_handle_otto_toggle' ) );
		
		// Handle preview mode - disable user authentication
		add_filter( 'determine_current_user', array( $this, 'disable_auth_for_preview' ), 999 );
		add_action( 'send_headers', array( $this, 'clear_auth_cookies_for_preview' ) );
	}

	/**
	 * Check if current user can manage OTTO
	 *
	 * @param int $post_id Optional. Post ID to check ownership for Authors
	 * @return bool
	 */
	public function current_user_can_manage_otto( $post_id = 0 ) {
		// First check if user has plugin access (uses common method from Metasync class)
		if ( ! Metasync::current_user_has_plugin_access() ) {
			return false;
		}

		// Administrators and Editors can manage all posts/pages
		if ( current_user_can( 'edit_others_posts' ) || current_user_can( 'edit_others_pages' ) ) {
			return true;
		}
		
		// Authors can only manage their own posts
		if ( current_user_can( 'publish_posts' ) ) {
			// If no post_id provided, allow (for general permission checks)
			if ( empty( $post_id ) ) {
				return true;
			}
			
			// Check if the post belongs to the current user
			$post = get_post( $post_id );
			if ( $post && $post->post_author == get_current_user_id() ) {
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Check if OTTO is disabled for a specific post/page
	 *
	 * Reports the effective status, which is what every indicator has to show. OTTO
	 * is off for a post when the per-page `_metasync_otto_disabled` meta flag is set
	 * OR when the post's URL is on the Compatibility page's manual "Excluded Auto
	 * URL" list: otto/otto_pixel.php aborts the render for an excluded URL well
	 * before it reaches the meta check, so an indicator that reads only the meta
	 * reports "Enabled" for a page OTTO never touches.
	 *
	 * The exclusion acts as a read-only overlay — it never writes post meta — so
	 * taking a URL back off the list restores whatever the meta flag already said.
	 *
	 * @param int         $post_id Post ID to check
	 * @param string|null $url     Optional. Weigh the exclusion against this URL
	 *                             instead of resolving one. For callers that know
	 *                             which page is being judged but have no query of
	 *                             their own — admin-ajax, most obviously.
	 * @return bool True if OTTO is disabled, false if enabled
	 */
	public static function is_otto_disabled( $post_id, $url = null ) {
		$disabled = get_post_meta( $post_id, self::META_KEY, true );
		if ( $disabled === '1' || $disabled === 'true' ) {
			return true;
		}

		return self::is_url_manually_excluded( $post_id, $url );
	}

	/**
	 * Whether this post's URL sits on the manual OTTO exclusion list.
	 *
	 * metasync_is_otto_url_manually_excluded() lives in otto/otto_pixel.php, which
	 * metasync.php loads on every request except a non-MetaSync admin-ajax call —
	 * the one context where pulling the OTTO stack in is deliberately avoided. So
	 * the function is checked rather than lazily required: where it is missing OTTO
	 * is not running either, and false is the honest answer.
	 *
	 * Memoised per request because is_otto_disabled() is called once per row of the
	 * posts list table, and each miss costs a URL resolution. The key carries the
	 * blog id so a switch_to_blog() loop cannot read site A's answer for site B —
	 * the exclusion table is per-site — and the URL, so two callers judging the
	 * same post against different pages cannot read each other's answer.
	 *
	 * @param int         $post_id Post ID whose URL to test
	 * @param string|null $url     Optional. Test this URL instead of resolving one.
	 * @return bool True if the URL matches a manual exclusion
	 */
	public static function is_url_manually_excluded( $post_id, $url = null ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}

		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$key     = $blog_id . ':' . $post_id . ':' . ( is_string( $url ) ? $url : '' );

		static $memo = array();
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		// Nothing is memoised until an answer is actually resolved: caching the
		// "could not determine" bail-outs below would freeze a non-answer into a
		// definitive "not excluded" for the rest of the request.
		if ( ! function_exists( 'metasync_is_otto_url_manually_excluded' ) ) {
			return false;
		}

		if ( ! is_string( $url ) || $url === '' ) {
			$url = self::get_exclusion_test_url( $post_id );
		}

		if ( $url === '' ) {
			return false;
		}

		$memo[ $key ] = (bool) metasync_is_otto_url_manually_excluded( $url );

		return $memo[ $key ];
	}

	/**
	 * The URL to weigh against the exclusion list for a post.
	 *
	 * The render gate in otto/otto_pixel.php (metasync_start_otto) matches on the
	 * URL that was actually requested, not on the post's permalink, and the two are
	 * not always the same page: `/my-post/2/` and a plain `?p=12` permalink both
	 * resolve to a different string than get_permalink() returns. Testing the
	 * permalink on a front-end request would let the indicator claim OTTO is off on
	 * a page OTTO just rendered — the very mismatch this check exists to close, only
	 * inverted — and would leave the persisted-meta output paths gated by one URL
	 * while the SSR render was gated by another, producing a half-OTTO page.
	 *
	 * So on a front-end request for this exact post, resolve the URL the same way
	 * the render gate does. Everywhere else — admin list tables, cron, REST — there
	 * is no request URL that belongs to the post, and the permalink is the only
	 * meaningful answer.
	 *
	 * @param int $post_id Post ID.
	 * @return string URL to test, or an empty string when none can be resolved.
	 */
	private static function get_exclusion_test_url( $post_id ) {
		$is_this_posts_request = ! is_admin()
			&& isset( $_SERVER['REQUEST_URI'] )
			&& function_exists( 'get_queried_object_id' )
			&& (int) get_queried_object_id() === $post_id;

		if ( $is_this_posts_request ) {
			// Derived exactly as otto/otto_pixel.php (metasync_start_otto) derives it,
			// character for character. The goal is not a canonically correct URL but
			// the *same* URL the render gate weighed, so the indicator and the render
			// can never reach opposite conclusions — including on a subdirectory
			// install, where both are wrong in the same direction.
			$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );

			return home_url( strtok( $request_uri, '?' ) ?: $request_uri );
		}

		$permalink = get_permalink( $post_id );

		return is_string( $permalink ) ? $permalink : '';
	}

	/**
	 * Set OTTO status for a specific post/page
	 *
	 * @param int  $post_id Post ID
	 * @param bool $disabled True to disable OTTO, false to enable
	 * @return bool Success status
	 */
	public function set_otto_status( $post_id, $disabled ) {
		if ( $disabled ) {
			update_post_meta( $post_id, self::META_KEY, '1' );
		} else {
			delete_post_meta( $post_id, self::META_KEY );
		}

		// Both update_post_meta() and delete_post_meta() return false when the post
		// was already in the requested state, which is indistinguishable from a real
		// write failure — and "enable a post that was never disabled" is the common
		// case, so callers were reporting a spurious failure for a no-op. Report on
		// the state that resulted instead of on whether a row changed.
		$flag = get_post_meta( $post_id, self::META_KEY, true );

		return $disabled ? ( $flag === '1' || $flag === 'true' ) : ( $flag === '' );
	}

	/**
	 * Enqueue toolbar styles
	 */
	public function enqueue_styles() {
		// Only load on frontend for users with permissions
		if ( is_admin() ) {
			return;
		}

		// Only load on singular posts/pages
		if ( ! is_singular() ) {
			return;
		}
		
		// Check if toolbar is disabled via settings
		$metasync_options = get_option( 'metasync_options' );
		$toolbar_disabled = isset( $metasync_options['general']['otto_disable_preview_button'] ) && 
		                    filter_var( $metasync_options['general']['otto_disable_preview_button'], FILTER_VALIDATE_BOOLEAN );
		if ( $toolbar_disabled ) {
			return;
		}
		
		// Check if user can manage OTTO for this specific post
		if ( ! $this->current_user_can_manage_otto( get_the_ID() ) ) {
			return;
		}

		wp_enqueue_style(
			$this->plugin_name . '-otto-toolbar',
			plugin_dir_url( __FILE__ ) . 'css/otto-toolbar.css',
			array(),
			$this->version,
			'all'
		);
	}

	/**
	 * Enqueue toolbar scripts
	 */
	public function enqueue_scripts() {
		// Only load on frontend for users with permissions
		if ( is_admin() ) {
			return;
		}

		// Only load on singular posts/pages
		if ( ! is_singular() ) {
			return;
		}
		
		// Check if toolbar is disabled via settings
		$metasync_options = get_option( 'metasync_options' );
		$toolbar_disabled = isset( $metasync_options['general']['otto_disable_preview_button'] ) && 
		                    filter_var( $metasync_options['general']['otto_disable_preview_button'], FILTER_VALIDATE_BOOLEAN );
		if ( $toolbar_disabled ) {
			return;
		}
		
		// Check if user can manage OTTO for this specific post
		if ( ! $this->current_user_can_manage_otto( get_the_ID() ) ) {
			return;
		}

		wp_enqueue_script(
			$this->plugin_name . '-otto-toolbar',
			plugin_dir_url( __FILE__ ) . 'js/otto-toolbar.js',
			array( 'jquery' ),
			$this->version,
			true
		);

		// Localize script with API data
		$metasync_options = get_option( 'metasync_options' );
		$otto_uuid = isset( $metasync_options['general']['otto_pixel_uuid'] ) ? $metasync_options['general']['otto_pixel_uuid'] : '';
		$whitelabel_otto_name = Metasync::get_whitelabel_otto_name();
		
		# Use endpoint manager to get the correct API URL
		$api_url = class_exists('Metasync_Endpoint_Manager')
			? Metasync_Endpoint_Manager::get_endpoint('OTTO_URL_DETAILS')
			: 'https://sa.searchatlas.com/api/v2/otto-url-details';

		# Ensure trailing slash
		$api_url = rtrim($api_url, '/') . '/';

		wp_localize_script(
			$this->plugin_name . '-otto-toolbar',
			'metasyncOttoDebug',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'apiUrl'     => $api_url,
				'ottoUuid'   => $otto_uuid,
				'currentUrl' => get_permalink( get_the_ID() ),
				'nonce'      => wp_create_nonce( 'metasync_otto_debug' ),
				'ottoName'   => $whitelabel_otto_name,
			)
		);
	}

	/**
	 * Render sticky debug bar at bottom left
	 */
	public function render_debug_bar() {
		// Only show on frontend for users with permissions
		if ( is_admin() ) {
			return;
		}

		// Only show on singular posts/pages
		if ( ! is_singular() ) {
			return;
		}
		
		// Check if toolbar is disabled via settings
		$metasync_options = get_option( 'metasync_options' );
		$toolbar_disabled = isset( $metasync_options['general']['otto_disable_preview_button'] ) && 
		                    filter_var( $metasync_options['general']['otto_disable_preview_button'], FILTER_VALIDATE_BOOLEAN );
		if ( $toolbar_disabled ) {
			return;
		}
		
		// Check if user can manage OTTO for this specific post
		if ( ! $this->current_user_can_manage_otto( get_the_ID() ) ) {
			return;
		}

		// each emit it, appearing duplicated over the editing canvas.
		if ( isset( $_GET['ct_builder'] ) || ( defined( 'SHOW_CT_BUILDER' ) && SHOW_CT_BUILDER ) ) {
			return;
		}

		// Guard against duplicate output when wp_footer fires more than once
		// per request (e.g. Oxygen Builder re-invokes the footer hook).
		static $rendered = false;
		if ( $rendered ) { return; }
		$rendered = true;

		$post_id = get_the_ID();
		$is_disabled = self::is_otto_disabled( $post_id );
		$is_excluded = self::is_url_manually_excluded( $post_id );
		$status_class = $is_disabled ? 'otto-disabled' : 'otto-enabled';
		$whitelabel_otto_name = Metasync::get_whitelabel_otto_name();
		$status_text = $is_disabled ? $whitelabel_otto_name . ' Disabled' : $whitelabel_otto_name . ' Enabled';

		?>
		<div id="metasync-otto-debug-bar" class="metasync-otto-debug-bar <?php echo esc_attr( $status_class ); ?>">
		<div class="otto-debug-status">
			<span class="otto-status-indicator"></span>
			<span class="otto-status-text"><?php echo esc_html( $status_text ); ?></span>
		</div>
		<?php if ( ! $is_disabled ) : ?>
			<button type="button" class="otto-preview-btn" id="otto-preview-btn">
				<span class="dashicons dashicons-visibility"></span>
				Preview Original
			</button>
		<?php endif; ?>
		<button type="button" class="otto-debug-btn" id="otto-debug-btn">
			<span class="dashicons dashicons-admin-tools"></span>
			Debug
		</button>
		<button type="button" class="otto-close-btn" id="otto-close-btn" aria-label="<?php esc_attr_e( 'Close toolbar', 'metasync' ); ?>">
			&times;
		</button>
		</div>
		
		<!-- Debug Tray (hidden by default) -->
		<div id="metasync-otto-debug-tray" class="metasync-otto-debug-tray">
			<div class="otto-debug-tray-header">
				<h3><?php echo esc_html( $whitelabel_otto_name ); ?> Debug - Comparison Data</h3>
				<button type="button" class="otto-debug-tray-close" id="otto-debug-tray-close">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
			</div>
			<div class="otto-debug-tray-content" id="otto-debug-tray-content">
				<div class="otto-debug-loading">
					<div class="otto-loading-spinner"></div>
					<p>Loading comparison data...</p>
				</div>
			</div>
			<div class="otto-debug-tray-footer">
				<div class="otto-toggle-container">
					<label class="otto-toggle-label">
						<span class="otto-toggle-text"><?php echo esc_html( $whitelabel_otto_name ); ?> Status:</span>
						<span class="otto-toggle-status-text"><?php echo $is_disabled ? 'Disabled' : 'Enabled'; ?></span>
					</label>
					<label class="otto-toggle-switch">
						<input type="checkbox" id="otto-debug-toggle" <?php checked( ! $is_disabled ); ?> <?php disabled( $is_excluded ); ?> data-post-id="<?php echo esc_attr( $post_id ); ?>">
						<span class="otto-toggle-slider"></span>
					</label>
				</div>
				<?php if ( $is_excluded ) : ?>
					<p class="otto-toggle-excluded-note">
						<?php
						printf(
							/* translators: %1$s: OTTO product name (used twice: list name, then status). */
							esc_html__( 'This URL is on the %1$s Excluded URLs list on the Compatibility page, so %1$s stays disabled for it. Remove the exclusion to use this toggle.', 'metasync' ),
							esc_html( $whitelabel_otto_name )
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		
		<!-- Preview iframe overlay (hidden by default) -->
		<div id="metasync-otto-preview-overlay" class="metasync-otto-preview-overlay">
			<div class="otto-preview-header">
				<span class="otto-preview-title">
					<span class="dashicons dashicons-visibility"></span>
					Preview: Original Content (<?php echo esc_html( $whitelabel_otto_name ); ?> Disabled)
				</span>
				<button type="button" class="otto-preview-close" id="otto-preview-close">
					<span class="dashicons dashicons-no-alt"></span>
					Close Preview
				</button>
			</div>
			<div class="otto-preview-loading" id="otto-preview-loading">
				<div class="otto-loading-spinner"></div>
				<p>Loading preview...</p>
			</div>
			<iframe id="metasync-otto-preview-iframe" class="metasync-otto-preview-iframe" src="" style="opacity: 0;" scrolling="yes" frameborder="0"></iframe>
		</div>
		<?php
	}

	/**
	 * Add OTTO control to WordPress admin bar
	 */
	public function add_admin_bar_menu( $wp_admin_bar ) {
		// Only show on frontend for users with permissions
		if ( is_admin() ) {
			return;
		}

		// Only show on singular posts/pages
		if ( ! is_singular() ) {
			return;
		}

		// Check if toolbar is disabled via settings
		$metasync_options = get_option( 'metasync_options' );
		$toolbar_disabled = isset( $metasync_options['general']['otto_disable_preview_button'] ) && 
		                    filter_var( $metasync_options['general']['otto_disable_preview_button'], FILTER_VALIDATE_BOOLEAN );
		if ( $toolbar_disabled ) {
			return;
		}

		$post_id = get_the_ID();
		
		// Check if user can manage OTTO for this specific post
		if ( ! $this->current_user_can_manage_otto( $post_id ) ) {
			return;
		}
		$is_disabled = self::is_otto_disabled( $post_id );
		$whitelabel_otto_name = Metasync::get_whitelabel_otto_name();

		// Set status-based styling
		$status_class = $is_disabled ? 'metasync-otto-disabled' : 'metasync-otto-enabled';
		$icon_class = 'metasync-otto-signal-icon';

		// Add parent menu with status indicator
		$wp_admin_bar->add_node( array(
			'id'    => 'metasync-otto-control',
			'title' => '<span class="ab-label">' . esc_html( $whitelabel_otto_name ) . ' Control</span><span class="' . $icon_class . '"></span>',
			'href'  => '#',
			'meta'  => array(
				'class' => 'metasync-otto-control-menu ' . $status_class,
			),
		) );

		// A manually excluded URL is disabled by the exclusion list, not by the
		// per-page toggle, and the toggle cannot lift it. Offering Enable/Disable
		// here would be offering a switch that changes nothing, so state the reason
		// instead and link to the one page where the exclusion can be removed.
		if ( self::is_url_manually_excluded( $post_id ) ) {
			$wp_admin_bar->add_node( array(
				'id'     => 'metasync-otto-excluded-status',
				'parent' => 'metasync-otto-control',
				/* translators: %s: OTTO product name. */
				'title'  => '<strong>✓ ' . esc_html( sprintf( __( '%s Disabled', 'metasync' ), $whitelabel_otto_name ) ) . '</strong>',
				'meta'   => array(
					// Deliberately not `metasync-otto-toggle`: that class carries a
					// hover state and a JS click handler that dims what it is bound
					// to, and this node has no href to act on.
					'class' => 'metasync-otto-status-line',
				),
			) );

			// The Compatibility page can be hidden per-role on white-labelled sites,
			// so only offer the way out to someone who can actually walk through it.
			// Everyone else still gets the reason, just without a link to a screen
			// they would be bounced from.
			$can_reach_compatibility = ! class_exists( 'Metasync_Access_Control' )
				|| Metasync_Access_Control::user_can_access( 'hide_compatibility' );

			$compat_url = '';
			if ( $can_reach_compatibility ) {
				$raw_slug   = isset( $metasync_options['general']['white_label_plugin_menu_slug'] )
					? sanitize_title( $metasync_options['general']['white_label_plugin_menu_slug'] )
					: '';
				$menu_slug  = ( $raw_slug !== '' ) ? $raw_slug : 'searchatlas';
				$compat_url = admin_url( 'admin.php?page=' . $menu_slug . '-compatibility#ms-otto-excluded-urls' );
			}

			$wp_admin_bar->add_node( array(
				'id'     => 'metasync-otto-excluded-manage',
				'parent' => 'metasync-otto-control',
				/* translators: %s: OTTO product name. */
				'title'  => esc_html( sprintf( __( 'URL is on the %s Excluded URLs list', 'metasync' ), $whitelabel_otto_name ) ),
				'href'   => $compat_url,
				'meta'   => array(
					'class' => 'metasync-otto-excluded-notice',
				),
			) );

			return;
		}

		// Generate toggle URLs with nonce
		$enable_url = wp_nonce_url(
			add_query_arg( array(
				'metasync_otto_action' => 'enable',
				'post_id' => $post_id
			) ),
			'metasync_otto_toggle_' . $post_id,
			'metasync_otto_nonce'
		);

		$disable_url = wp_nonce_url(
			add_query_arg( array(
				'metasync_otto_action' => 'disable',
				'post_id' => $post_id
			) ),
			'metasync_otto_toggle_' . $post_id,
			'metasync_otto_nonce'
		);

		// Add Enable OTTO submenu
		$wp_admin_bar->add_node( array(
			'id'     => 'metasync-otto-enable',
			'parent' => 'metasync-otto-control',
			'title'  => $is_disabled ? 'Enable ' . esc_html( $whitelabel_otto_name ) : '<strong>✓ ' . esc_html( $whitelabel_otto_name ) . ' Enabled</strong>',
			'href'   => $enable_url,
			'meta'   => array(
				'class' => 'metasync-otto-toggle ' . ( $is_disabled ? '' : 'active' ),
			),
		) );

		// Add Disable OTTO submenu
		$wp_admin_bar->add_node( array(
			'id'     => 'metasync-otto-disable',
			'parent' => 'metasync-otto-control',
			'title'  => $is_disabled ? '<strong>✓ ' . esc_html( $whitelabel_otto_name ) . ' Disabled</strong>' : 'Disable ' . esc_html( $whitelabel_otto_name ),
			'href'   => $disable_url,
			'meta'   => array(
				'class' => 'metasync-otto-toggle ' . ( $is_disabled ? 'active' : '' ),
			),
		) );
	}

	/**
	 * Disable authentication for preview mode
	 * This allows logged-in users to preview the page as if they were logged out
	 *
	 * @param int|bool $user_id User ID if already determined, false otherwise
	 * @return int|bool Modified user ID or false
	 */
	public function disable_auth_for_preview( $user_id ) {
		// Check if this is a preview request
		if ( isset( $_GET['otto_preview'] ) && $_GET['otto_preview'] === '1' ) {
			// Return false to indicate no user is logged in
			return false;
		}
		
		return $user_id;
	}

	/**
	 * Clear authentication cookies for preview mode
	 * Ensures the preview iframe doesn't use the parent page's authentication
	 */
	public function clear_auth_cookies_for_preview() {
		// Check if this is a preview request
		if ( isset( $_GET['otto_preview'] ) && $_GET['otto_preview'] === '1' ) {
			// Clear WordPress auth cookies for this request only
			// This prevents the logged-in state from the parent page affecting the iframe
			$_COOKIE = array_filter( $_COOKIE, function( $key ) {
				// Remove WordPress auth cookies
				return strpos( $key, 'wordpress_logged_in_' ) === false &&
				       strpos( $key, 'wordpress_' ) === false &&
				       $key !== 'wp-settings-' . get_current_user_id() &&
				       $key !== 'wp-settings-time-' . get_current_user_id();
			}, ARRAY_FILTER_USE_KEY );
		}
	}

	/**
	 * Handle AJAX toggle request
	 */
	public function ajax_handle_otto_toggle() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'metasync_otto_debug' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ) );
		}

		// Get parameters
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$otto_action = isset( $_POST['otto_action'] ) ? sanitize_text_field( $_POST['otto_action'] ) : '';

		// Validate post
		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid post ID.' ) );
		}
		
		// Check permissions for this specific post
		$whitelabel_otto_name = Metasync::get_whitelabel_otto_name();
		if ( ! $this->current_user_can_manage_otto( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to manage ' . esc_html( $whitelabel_otto_name ) . ' settings for this post.' ) );
		}

		// Validate action
		if ( ! in_array( $otto_action, array( 'enable', 'disable' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid action.' ) );
		}

		// Update OTTO status
		$disabled = ( $otto_action === 'disable' );
		$success = $this->set_otto_status( $post_id, $disabled );

		if ( $success ) {
			// Report the effective status rather than the requested one. A URL on the
			// manual exclusion list stays disabled whatever the per-page meta now
			// says, and the toolbar must not repaint itself "Enabled" for a page OTTO
			// will not render.
			//
			// Weigh it against the page the toggle was clicked from, not the
			// permalink: admin-ajax has no query of its own, so the permalink is all
			// get_exclusion_test_url() could resolve — and for a post viewed at a URL
			// other than its permalink that answer would contradict the very bar that
			// sent this request. wp_get_referer() validates against this site and
			// returns false when it cannot, in which case the permalink stands.
			$page_url           = wp_get_referer();
			$effective_disabled = self::is_otto_disabled( $post_id, is_string( $page_url ) ? $page_url : null );

			// These strings are delivered as JSON and shown through alert(), so they
			// are plain text — HTML-escaping them here would surface a white-label
			// name such as "Bob's SEO" to the user as "Bob&#039;s SEO".
			if ( ! $disabled && $effective_disabled ) {
				$message = sprintf(
					/* translators: %1$s: OTTO product name (used twice: list name, then status). */
					__( 'This URL is on the %1$s Excluded URLs list on the Compatibility page, so %1$s stays disabled for it.', 'metasync' ),
					$whitelabel_otto_name
				);
			} else {
				$message = $effective_disabled ? $whitelabel_otto_name . ' disabled successfully!' : $whitelabel_otto_name . ' enabled successfully!';
			}

			wp_send_json_success( array(
				'message' => $message,
				'is_disabled' => $effective_disabled
			) );
		} else {
			wp_send_json_error( array( 'message' => 'Failed to update OTTO status.' ) );
		}
	}

	/**
	 * Handle toggle request via URL parameters
	 */
	public function handle_toggle_request() {
		// Check if this is a toggle request
		if ( ! isset( $_GET['metasync_otto_action'] ) || ! isset( $_GET['post_id'] ) ) {
			return;
		}

		// Get parameters
		$action = sanitize_text_field( $_GET['metasync_otto_action'] );
		$post_id = absint( $_GET['post_id'] );

		// Verify nonce
		if ( ! isset( $_GET['metasync_otto_nonce'] ) || ! wp_verify_nonce( $_GET['metasync_otto_nonce'], 'metasync_otto_toggle_' . $post_id ) ) {
			wp_die( 'Security check failed.' );
		}

		// Validate post
		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_die( 'Invalid post ID.' );
		}
		
		// Check permissions for this specific post
		$whitelabel_otto_name = Metasync::get_whitelabel_otto_name();
		if ( ! $this->current_user_can_manage_otto( $post_id ) ) {
			wp_die( 'You do not have permission to manage ' . esc_html( $whitelabel_otto_name ) . ' settings for this post.' );
		}

		// Toggle OTTO status
		if ( $action === 'enable' ) {
			$this->set_otto_status( $post_id, false );
		} elseif ( $action === 'disable' ) {
			$this->set_otto_status( $post_id, true );
			// Clear Divi's per-page CSS cache when OTTO is disabled.
			// OTTO's HTTP render can corrupt the CSS cache file; clearing it
			// forces Divi to rebuild it on the next normal render.
			// Also delete the 24h transient so the fix re-runs when OTTO is re-enabled.
			$et_cache_dir = WP_CONTENT_DIR . '/et-cache/' . $post_id;
			if ( is_dir( $et_cache_dir ) ) {
				$files = glob( $et_cache_dir . '/*' );
				if ( $files ) {
					foreach ( $files as $file ) {
						if ( is_file( $file ) ) {
							@unlink( $file );
						}
					}
				}
			}
			$permalink = get_permalink( $post_id );
			if ( $permalink ) {
				delete_transient( 'otto_divi_css_fix_' . md5( $permalink ) );
			}
		}

		// Redirect back to the post without query parameters
		$redirect_url = get_permalink( $post_id );
		wp_safe_redirect( $redirect_url );
		exit;
	}
}

