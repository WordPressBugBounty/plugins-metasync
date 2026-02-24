<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Developer Panel for MetaSync Plugin
 *
 * Provides a password-protected panel for switching between production and staging endpoints.
 *
 * @package    Metasync
 * @subpackage Metasync/admin
 */

/**
 * The developer panel class.
 *
 * Manages the developer tools panel with endpoint switching capabilities.
 *
 * @package    Metasync
 * @subpackage Metasync/admin
 */
class Metasync_Dev_Panel {

	/**
	 * The ID of this plugin.
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Option name for storing the developer panel password.
	 */
	const PASSWORD_OPTION = 'metasync_dev_panel_password';

	/**
	 * Authentication manager instance.
	 *
	 * @var Metasync_Auth_Manager
	 */
	private $auth;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version     The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		// Initialize auth manager with 30-minute timeout
		if ( class_exists( 'Metasync_Auth_Manager' ) ) {
			$this->auth = new Metasync_Auth_Manager( 'dev_panel', 1800 );
		}

		// Register hooks
		add_action( 'admin_menu', array( $this, 'add_dev_panel_menu' ) );
		add_action( 'admin_notices', array( $this, 'display_staging_mode_banner' ) );
		add_action( 'wp_ajax_metasync_switch_endpoints', array( $this, 'ajax_switch_endpoints' ) );
		add_action( 'wp_ajax_metasync_update_dev_password', array( $this, 'ajax_update_dev_password' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Register the developer panel menu (hidden from menu, accessible via URL).
	 */
	public function add_dev_panel_menu() {
		// Add hidden submenu page (no parent = hidden from menu)
		// Accessible to admins only (developer tools)
		add_submenu_page(
			null,
			'Developer Tools',
			'Developer Tools',
			'manage_options',
			'searchatlas-dev-tools',
			array( $this, 'render_dev_panel_page' )
		);
	}

	/**
	 * Display staging mode banner on all admin pages.
	 */
	public function display_staging_mode_banner() {
		// Only show if staging mode is active
		if ( ! Metasync_Endpoint_Manager::is_staging_mode() ) {
			return;
		}

		// Only show in admin area
		if ( ! is_admin() ) {
			return;
		}

		// Don't show on dev panel page itself
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
		if ( $current_page === 'searchatlas-dev-tools' ) {
			return;
		}

		$dev_panel_url = admin_url( 'admin.php?page=searchatlas-dev-tools' );
		?>
		<div class="notice notice-warning" style="border-left: 4px solid #ff9800; background: #fff3cd; padding: 12px 15px;">
			<p style="font-size: 14px; margin: 0;">
				<strong>⚠️ STAGING MODE ACTIVE</strong><br>
				All SearchAtlas API endpoints are currently pointing to STAGING servers. This is intended for development and testing only.
				<br>
				<a href="<?php echo esc_url( $dev_panel_url ); ?>" class="button button-secondary" style="margin-top: 8px;">
					🔧 Open Developer Tools
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Enqueue scripts and styles for dev panel.
	 *
	 * @param string $hook The current admin page.
	 */
	public function enqueue_scripts( $hook ) {
		// Only load on dev panel page
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'searchatlas-dev-tools' ) {
			wp_enqueue_style( 'metasync-admin' );
		}
	}

	/**
	 * Render the developer panel page.
	 */
	public function render_dev_panel_page() {
		// Check permissions (admin only for developer tools)
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'metasync' ) );
		}

		// Check if password is set
		$saved_password = get_option( self::PASSWORD_OPTION, '' );

		// If no password is set, show password setup form
		if ( empty( $saved_password ) ) {
			$this->render_password_setup_form();
			return;
		}

		// Check authentication
		if ( ! $this->auth || ! $this->auth->has_access() ) {
			$this->render_password_form();
			return;
		}

		// User is authenticated - show endpoint switcher
		$this->render_endpoint_switcher();
	}

	/**
	 * Render the password setup form (first-time setup).
	 */
	private function render_password_setup_form() {
		$setup_error = '';

		// Handle password setup submission
		if ( isset( $_POST['dev_panel_setup_submit'] ) ) {
			if ( wp_verify_nonce( $_POST['dev_panel_setup_nonce'], 'metasync_dev_panel_setup' ) ) {
				$new_password = sanitize_text_field( $_POST['dev_panel_new_password'] );
				$confirm_password = sanitize_text_field( $_POST['dev_panel_confirm_password'] );

				if ( empty( $new_password ) ) {
					$setup_error = 'Password cannot be empty.';
				} elseif ( $new_password !== $confirm_password ) {
					$setup_error = 'Passwords do not match.';
				} elseif ( strlen( $new_password ) < 6 ) {
					$setup_error = 'Password must be at least 6 characters long.';
				} else {
					$result = update_option( self::PASSWORD_OPTION, $new_password );
					if ( $result ) {
						// Grant access immediately
						if ( $this->auth ) {
							$this->auth->grant_transient_access();
						}
						// Refresh to show authenticated state - add timestamp to prevent caching
						wp_safe_redirect( add_query_arg( 'setup', 'complete', $_SERVER['REQUEST_URI'] ) );
						exit;
					} else {
						$setup_error = 'Failed to save password. Please try again.';
					}
				}
			}
		}
		?>
		<div class="wrap metasync-dashboard-wrap">
			<?php Metasync_Admin::render_static_header( 'Developer Tools - Setup' ); ?>

			<div class="dashboard-card" style="max-width: 600px; margin: 0 auto;">
				<h2 style="text-align: center; color: #fff;">🔐 Developer Tools - Initial Setup</h2>
				<p style="color: #646970; margin-bottom: 30px; text-align: center;">
					Welcome to the Developer Tools panel. Please create a password to secure access to endpoint switching features.
				</p>

				<?php if ( ! empty( $setup_error ) ) : ?>
					<div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
						<strong>❌ Error:</strong> <?php echo esc_html( $setup_error ); ?>
					</div>
				<?php endif; ?>

				<form method="post" action="" style="max-width: 400px; margin: 0 auto;">
					<?php wp_nonce_field( 'metasync_dev_panel_setup', 'dev_panel_setup_nonce' ); ?>

					<div style="margin-bottom: 20px;">
						<label for="dev_panel_new_password" style="display: block; font-weight: 600; margin-bottom: 8px; color: #fff;">
							🔑 Create Password
						</label>
						<input
							type="password"
							id="dev_panel_new_password"
							name="dev_panel_new_password"
							placeholder="Enter a strong password (min 6 characters)"
							style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px; box-sizing: border-box;"
							required
							autocomplete="off"
						/>
					</div>

					<div style="margin-bottom: 20px;">
						<label for="dev_panel_confirm_password" style="display: block; font-weight: 600; margin-bottom: 8px; color: #fff;">
							🔁 Confirm Password
						</label>
						<input
							type="password"
							id="dev_panel_confirm_password"
							name="dev_panel_confirm_password"
							placeholder="Re-enter your password"
							style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px; box-sizing: border-box;"
							required
							autocomplete="off"
						/>
					</div>

					<div style="text-align: center;">
						<button
							type="submit"
							name="dev_panel_setup_submit"
							value="1"
							class="button button-primary"
							style="padding: 12px 24px; font-size: 14px; font-weight: 600;"
						>
							🚀 Create Password & Continue
						</button>
					</div>
				</form>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('#dev_panel_new_password').focus();
		});
		</script>
		<?php
	}

	/**
	 * Render the password authentication form.
	 */
	private function render_password_form() {
		$password_error = '';

		// Handle password submission
		if ( isset( $_POST['dev_panel_password_submit'] ) ) {
			if ( wp_verify_nonce( $_POST['dev_panel_nonce'], 'metasync_dev_panel_nonce' ) ) {
				$submitted_password = sanitize_text_field( $_POST['dev_panel_password'] );
				$saved_password = get_option( self::PASSWORD_OPTION, '' );

				if ( $this->auth && $this->auth->verify_and_grant( $submitted_password, $saved_password, false ) ) {
					// Refresh page to show authenticated state - add timestamp to prevent caching
					wp_safe_redirect( add_query_arg( 'login', 'success', $_SERVER['REQUEST_URI'] ) );
					exit;
				} else {
					$password_error = 'Incorrect password. Please try again.';
				}
			}
		}
		?>
		<div class="wrap metasync-dashboard-wrap">
			<?php Metasync_Admin::render_static_header( 'Developer Tools' ); ?>

			<div class="dashboard-card" style="max-width: 500px; margin: 0 auto;">
				<h2 style="text-align: center; color: #fff;">🔐 Protected Area</h2>
				<p style="color: #646970; margin-bottom: 30px; text-align: center;">
					Please enter the password to access the Developer Tools panel.
				</p>

				<?php if ( ! empty( $password_error ) ) : ?>
					<div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
						<strong>❌ Access Denied:</strong> <?php echo esc_html( $password_error ); ?>
					</div>
				<?php endif; ?>

				<form method="post" action="" style="max-width: 400px; margin: 0 auto;">
					<?php wp_nonce_field( 'metasync_dev_panel_nonce', 'dev_panel_nonce' ); ?>

					<div style="margin-bottom: 20px;">
						<label for="dev_panel_password" style="display: block; font-weight: 600; margin-bottom: 8px; color: #fff;">
							🔑 Enter Password
						</label>
						<input
							type="password"
							id="dev_panel_password"
							name="dev_panel_password"
							placeholder="Enter developer panel password"
							style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px; box-sizing: border-box;"
							required
							autocomplete="off"
						/>
					</div>

					<div style="text-align: center;">
						<button
							type="submit"
							name="dev_panel_password_submit"
							value="1"
							class="button button-primary"
							style="padding: 12px 24px; font-size: 14px; font-weight: 600;"
						>
							🚀 Submit Password
						</button>
					</div>
				</form>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('#dev_panel_password').focus();
		});
		</script>
		<?php
	}

	/**
	 * Render the endpoint switcher interface.
	 */
	private function render_endpoint_switcher() {
		$current_mode = Metasync_Endpoint_Manager::get_mode();
		$all_endpoints = Metasync_Endpoint_Manager::get_all_endpoints();
		$message = '';
		$message_type = '';

		// Handle mode switch
		if ( isset( $_POST['switch_endpoints_submit'] ) ) {
			if ( wp_verify_nonce( $_POST['switch_endpoints_nonce'], 'metasync_switch_endpoints' ) ) {
				$new_mode = sanitize_text_field( $_POST['endpoint_mode'] );
				$result = Metasync_Endpoint_Manager::set_mode( $new_mode );

				if ( $result ) {
					$message = 'Successfully switched to ' . esc_html( $new_mode ) . ' mode!';
					$message_type = 'success';
					$current_mode = $new_mode;
					$all_endpoints = Metasync_Endpoint_Manager::get_all_endpoints();
				} else {
					$message = 'Failed to switch endpoints.';
					$message_type = 'error';
				}
			}
		}

		// Handle password update
		if ( isset( $_POST['update_password_submit'] ) ) {
			if ( wp_verify_nonce( $_POST['update_password_nonce'], 'metasync_update_dev_password' ) ) {
				$new_password = sanitize_text_field( $_POST['dev_panel_new_password'] );

				if ( empty( $new_password ) ) {
					$message = 'Password cannot be empty.';
					$message_type = 'error';
				} elseif ( strlen( $new_password ) < 6 ) {
					$message = 'Password must be at least 6 characters long.';
					$message_type = 'error';
				} else {
					$result = update_option( self::PASSWORD_OPTION, $new_password );
					if ( $result ) {
						$message = 'Password updated successfully!';
						$message_type = 'success';
					} else {
						$message = 'Failed to update password.';
						$message_type = 'error';
					}
				}
			}
		}

		// Handle logout
		if ( isset( $_POST['logout_submit'] ) ) {
			if ( wp_verify_nonce( $_POST['logout_nonce'], 'metasync_dev_panel_logout' ) ) {
				if ( $this->auth ) {
					$this->auth->revoke_access();
				}
				wp_safe_redirect( remove_query_arg( array( 'setup', 'login' ), $_SERVER['REQUEST_URI'] ) );
				exit;
			}
		}
		?>
		<div class="wrap metasync-dashboard-wrap">
			<?php Metasync_Admin::render_static_header( 'Developer Tools' ); ?>

			<?php if ( ! empty( $message ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $message_type ); ?>" style="margin: 15px 0; padding: 12px;">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<div class="dashboard-card">
				<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
					<div>
						<h2 style="margin: 0; color: #fff;">🔧 Developer Tools - Endpoint Switching</h2>
						<p style="color: #646970; margin: 5px 0 0 0;">
							Switch between production and staging API endpoints for testing.
						</p>
					</div>
					<form method="post" style="margin: 0;">
						<?php wp_nonce_field( 'metasync_dev_panel_logout', 'logout_nonce' ); ?>
						<button type="submit" name="logout_submit" value="1" class="button" style="margin: 0;">
							🔒 Logout
						</button>
					</form>
				</div>

				<div style="background: <?php echo $current_mode === 'staging' ? '#fff3cd' : '#d1ecf1'; ?>; border-left: 4px solid <?php echo $current_mode === 'staging' ? '#ff9800' : '#0c5460'; ?>; padding: 15px; border-radius: 6px; margin-bottom: 30px;">
					<h3 style="margin: 0 0 5px 0; font-size: 16px; color: #000;">
						Current Mode: <span style="color: <?php echo $current_mode === 'staging' ? '#ff9800' : '#0c5460'; ?>; font-weight: 700;">
							<?php echo esc_html( strtoupper( $current_mode ) ); ?>
						</span>
					</h3>
					<p style="margin: 0; font-size: 13px; color: #3c434a;">
						<?php
						if ( $current_mode === 'staging' ) {
							echo 'All API calls are pointing to staging servers. Remember to switch back to production when done testing!';
						} else {
							echo 'All API calls are pointing to production servers (live data).';
						}
						?>
					</p>
				</div>

				<h3 style="margin-top: 30px; margin-bottom: 15px; color: #fff;">🔄 Switch Endpoint Mode</h3>
				<form method="post">
					<?php wp_nonce_field( 'metasync_switch_endpoints', 'switch_endpoints_nonce' ); ?>

					<div style="margin-bottom: 20px;">
						<label style="display: block; margin-bottom: 10px; padding: 15px; border: 2px solid <?php echo $current_mode === 'production' ? '#2271b1' : '#ddd'; ?>; border-radius: 6px; cursor: pointer; background: <?php echo $current_mode === 'production' ? '#f0f6fc' : '#fff'; ?>; color: #1d2327;">
							<input type="radio" name="endpoint_mode" value="production" <?php checked( $current_mode, 'production' ); ?> style="margin-right: 10px;">
							<strong style="color: #1d2327;">Production</strong> - Live SearchAtlas servers (api.searchatlas.com)
						</label>

						<label style="display: block; margin-bottom: 10px; padding: 15px; border: 2px solid <?php echo $current_mode === 'staging' ? '#d63638' : '#ddd'; ?>; border-radius: 6px; cursor: pointer; background: <?php echo $current_mode === 'staging' ? '#fcf0f1' : '#fff'; ?>; color: #1d2327;">
							<input type="radio" name="endpoint_mode" value="staging" <?php checked( $current_mode, 'staging' ); ?> style="margin-right: 10px;">
							<strong style="color: #1d2327;">Staging</strong> - Testing servers (api.staging.searchatlas.com) - For development only
						</label>
					</div>

					<button type="submit" name="switch_endpoints_submit" value="1" class="button button-primary">
						🔄 Switch Endpoints
					</button>
				</form>
			</div>

			<div class="dashboard-card">
				<h3 style="color: #fff;">📋 Current Endpoint Configuration</h3>
				<table class="widefat" style="border: 1px solid #ddd;">
					<thead>
						<tr>
							<th style="padding: 12px; background: #f6f7f7; color: #1d2327;">Endpoint Type</th>
							<th style="padding: 12px; background: #f6f7f7; color: #1d2327;">Current URL</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $all_endpoints as $key => $url ) : ?>
						<tr>
							<td style="padding: 12px; border-top: 1px solid #ddd; color: #1d2327;"><code><?php echo esc_html( $key ); ?></code></td>
							<td style="padding: 12px; border-top: 1px solid #ddd; font-family: monospace; font-size: 13px; color: #1d2327;">
								<?php echo esc_html( $url ); ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="dashboard-card">
				<h3 style="color: #fff;">🔐 Password Management</h3>
				<p style="color: #646970; margin-bottom: 20px;">
					Update the password required to access this developer panel.
				</p>

				<form method="post" style="max-width: 500px;">
					<?php wp_nonce_field( 'metasync_update_dev_password', 'update_password_nonce' ); ?>

					<div style="margin-bottom: 15px;">
						<label for="dev_panel_new_password" style="display: block; font-weight: 600; margin-bottom: 8px; color: #fff;">
							New Password (min 6 characters)
						</label>
						<input
							type="password"
							id="dev_panel_new_password"
							name="dev_panel_new_password"
							placeholder="Enter new password"
							class="regular-text"
							style="padding: 8px;"
						/>
					</div>

					<button type="submit" name="update_password_submit" value="1" class="button">
						💾 Update Password
					</button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler for switching endpoints.
	 */
	public function ajax_switch_endpoints() {
		// Verify nonce
		check_ajax_referer( 'metasync_switch_endpoints', 'nonce' );

		// Verify permissions (matches main plugin's access control)
		if ( ! Metasync::current_user_has_plugin_access() ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
			return;
		}

		// Verify authentication
		if ( ! $this->auth || ! $this->auth->has_access() ) {
			wp_send_json_error( array( 'message' => 'Authentication required' ) );
			return;
		}

		// Get and validate mode
		$mode = isset( $_POST['endpoint_mode'] ) ? sanitize_text_field( $_POST['endpoint_mode'] ) : '';
		if ( ! in_array( $mode, array( 'production', 'staging' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid mode' ) );
			return;
		}

		// Switch mode
		$result = Metasync_Endpoint_Manager::set_mode( $mode );

		if ( $result ) {
			wp_send_json_success(
				array(
					'message'   => "Successfully switched to {$mode} mode",
					'mode'      => $mode,
					'endpoints' => Metasync_Endpoint_Manager::get_all_endpoints(),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => 'Failed to switch endpoints' ) );
		}
	}

	/**
	 * AJAX handler for updating dev panel password.
	 */
	public function ajax_update_dev_password() {
		// Verify nonce
		check_ajax_referer( 'metasync_update_dev_password', 'nonce' );

		// Verify permissions (matches main plugin's access control)
		if ( ! Metasync::current_user_has_plugin_access() ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
			return;
		}

		// Verify authentication
		if ( ! $this->auth || ! $this->auth->has_access() ) {
			wp_send_json_error( array( 'message' => 'Authentication required' ) );
			return;
		}

		// Get new password
		$new_password = isset( $_POST['dev_panel_password'] ) ? sanitize_text_field( $_POST['dev_panel_password'] ) : '';

		if ( empty( $new_password ) ) {
			wp_send_json_error( array( 'message' => 'Password cannot be empty' ) );
			return;
		}

		if ( strlen( $new_password ) < 6 ) {
			wp_send_json_error( array( 'message' => 'Password must be at least 6 characters long' ) );
			return;
		}

		$result = update_option( self::PASSWORD_OPTION, $new_password );

		if ( $result ) {
			wp_send_json_success( array( 'message' => 'Password updated successfully' ) );
		} else {
			wp_send_json_error( array( 'message' => 'Failed to update password' ) );
		}
	}
}
