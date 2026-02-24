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
	 * @param int $post_id Post ID to check
	 * @return bool True if OTTO is disabled, false if enabled
	 */
	public static function is_otto_disabled( $post_id ) {
		$disabled = get_post_meta( $post_id, self::META_KEY, true );
		return $disabled === '1' || $disabled === 'true';
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
			return update_post_meta( $post_id, self::META_KEY, '1' );
		} else {
			return delete_post_meta( $post_id, self::META_KEY );
		}
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

		$post_id = get_the_ID();
		$is_disabled = self::is_otto_disabled( $post_id );
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
						<input type="checkbox" id="otto-debug-toggle" <?php checked( ! $is_disabled ); ?> data-post-id="<?php echo esc_attr( $post_id ); ?>">
						<span class="otto-toggle-slider"></span>
					</label>
				</div>
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
			$message = $disabled ? esc_html( $whitelabel_otto_name ) . ' disabled successfully!' : esc_html( $whitelabel_otto_name ) . ' enabled successfully!';
			wp_send_json_success( array( 
				'message' => $message,
				'is_disabled' => $disabled
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
		}

		// Redirect back to the post without query parameters
		$redirect_url = get_permalink( $post_id );
		wp_safe_redirect( $redirect_url );
		exit;
	}
}

