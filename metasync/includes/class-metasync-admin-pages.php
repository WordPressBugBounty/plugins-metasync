<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Admin page renderer callbacks extracted from Metasync_Admin.
 *
 * Each public method renders a specific admin page. The class receives the
 * Metasync_Admin instance so it can call back into shared helpers such as
 * render_plugin_header() and render_navigation_menu().
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */
class Metasync_Admin_Pages
{
    /** @var Metasync_Admin */
    private $admin;

    /** @var self|null */
    private static $instance = null;

    private function __construct(Metasync_Admin $admin)
    {
        $this->admin = $admin;
    }

    /**
     * @param Metasync_Admin $admin
     * @return self
     */
    public static function get_instance(Metasync_Admin $admin): self
    {
        if (null === self::$instance || self::$instance->admin !== $admin) {
            self::$instance = new self($admin);
        }
        return self::$instance;
    }

    // ------------------------------------------------------------------
    //  Dashboard iframe (~200 lines)
    // ------------------------------------------------------------------

    public function create_admin_dashboard_iframe()
    {
        $general_options = Metasync::get_option('general');
        $otto_pixel_uuid = isset($general_options['otto_pixel_uuid']) ? $general_options['otto_pixel_uuid'] : '';
        $api_key = isset($general_options['searchatlas_api_key']) ? $general_options['searchatlas_api_key'] : '';
        
        $hide_dashboard = $general_options['hide_dashboard_framework'] ?? false;
        
        if ($hide_dashboard) {
            ?>
                <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
                <?php $this->admin->render_plugin_header('Dashboard'); ?>
                
                <?php $this->admin->render_navigation_menu('dashboard'); ?>
                
                <div class="dashboard-card">
                    <h2>📊 Dashboard Disabled</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">
                        The dashboard is currently Disabled.
                    </p>
                </div>
            </div>
            <?php
            return;
        }

        if (!$this->admin->is_heartbeat_connected()) {
            ?>
                <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
                <?php $this->admin->render_plugin_header('Dashboard'); ?>
                
                <?php $this->admin->render_navigation_menu('dashboard'); ?>

                <div class="dashboard-card">
                    <h2>🚀 Setup Wizard</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Run the setup wizard to configure your plugin, import from other SEO plugins, and optimize your settings in just a few minutes.</p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-setup-wizard')); ?>" class="button button-primary" style="text-decoration: none;">
                        ✨ Start Setup Wizard
                    </a>
                </div>
                
                <div class="dashboard-card">
                    <h2>⚠️ Authentication Required</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">
                        You need to authenticate with <?php echo esc_html(Metasync::get_effective_plugin_name()); ?> to access the dashboard.
                    </p>
                    <a href="<?php echo admin_url('admin.php?page=' . Metasync_Admin::$page_slug); ?>" class="button button-primary">
                        🔗 Go to Settings & Connect
                    </a>
                </div>
            </div>
            <?php
            return;
        }
        
        $jwt_token = $this->admin->get_fresh_jwt_token();
        
        if ($jwt_token) {
            $jwt_parts = explode('.', $jwt_token);
            $jwt_header_info = count($jwt_parts) >= 2 ? 'Valid format (' . count($jwt_parts) . ' parts)' : 'Invalid format';
        } else {
        }
        
        $public_hash = false;
        if ($jwt_token && $otto_pixel_uuid) {
            $public_hash = $this->admin->fetch_public_hash($otto_pixel_uuid, $jwt_token);
            
            if ($public_hash) {
            } else {
            }
        } else {
            $missing_params = [];
            if (empty($jwt_token)) $missing_params[] = 'JWT_TOKEN';
            if (empty($otto_pixel_uuid)) $missing_params[] = 'OTTO_UUID';
        }
        
        $dashboard_domain = Metasync_Admin::get_effective_dashboard_domain();
        
        if ($public_hash) {
            $iframe_url = $dashboard_domain . '/seo-automation-v3/public?uuid=' . urlencode($otto_pixel_uuid) 
                        . '&category=onpage_optimizations&subGroup=page_title&public_hash=' . urlencode($public_hash);
        } else {
            $iframe_url = $dashboard_domain . '/seo-automation-v3/tasks?uuid=' . urlencode($otto_pixel_uuid) . '&category=All&Embed=True';
            if ($jwt_token) {
                $iframe_url .= '&jwtToken=' . urlencode($jwt_token) . '&impersonate=1';
            } else {
            }
        }
        
        $iframe_url .= '&source=wordpress-plugin-iframe';
        
        $whitelabel_company_name = Metasync::get_whitelabel_company_name();
        if ($whitelabel_company_name) {
            $iframe_url .= '&whitelabel=' . urlencode($whitelabel_company_name);
        }
        
        $whitelabel_settings = Metasync::get_whitelabel_settings();
        $is_whitelabel_domain = !empty($whitelabel_settings['domain']);
        
        ?>
            <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
            <?php $this->admin->render_plugin_header('Dashboard'); ?>
            
            <?php $this->admin->render_navigation_menu('dashboard'); ?>
            
            <iframe id="metasync-dashboard-iframe"
                    src="<?php echo esc_url($iframe_url); ?>"
                    width="100%"
                    height="100vh"
                    frameborder="0"
                    <?php if (!$is_whitelabel_domain): ?>
                    allow="cookies"
                    referrerpolicy="strict-origin-when-cross-origin"
                    <?php endif; ?>
                    style="border: none; margin: 0; padding: 0; min-height: 800px;"
                    onload="adjustIframeHeight(this)">
            </iframe>
            
            <script>
            function adjustIframeHeight(iframe) {
                var attempts = 0;
                var maxAttempts = 20;
                
                function tryAdjustHeight() {
                    try {
                        attempts++;
                        
                        var iframeDocument = iframe.contentDocument || iframe.contentWindow.document;
                        if (iframeDocument) {
                            var body = iframeDocument.body;
                            var hasContent = body && (body.children.length > 1 || body.innerText.trim().length > 100);
                            
                            if (!hasContent && attempts < maxAttempts) {
                                setTimeout(tryAdjustHeight, 500);
                                return;
                            }
                            
                            var height = Math.max(
                                body ? body.scrollHeight : 0,
                                body ? body.offsetHeight : 0,
                                iframeDocument.documentElement.clientHeight,
                                iframeDocument.documentElement.scrollHeight,
                                iframeDocument.documentElement.offsetHeight
                            );
                            
                            if (height > 600) {
                                iframe.style.height = height + 'px';
                            } else if (attempts < maxAttempts) {
                                setTimeout(tryAdjustHeight, 500);
                                return;
                            }
                        } else {
                            if (attempts < maxAttempts) {
                                setTimeout(tryAdjustHeight, 500);
                                return;
                            }
                        }
                    } catch (e) {
                        iframe.style.height = '100vh';
                    }
                }
                
                tryAdjustHeight();
            }
            
            window.addEventListener('resize', function() {
                var iframe = document.getElementById('metasync-dashboard-iframe');
                if (iframe) {
                    adjustIframeHeight(iframe);
                }
            });
            
            setTimeout(function() {
                var iframe = document.getElementById('metasync-dashboard-iframe');
                if (iframe) {
                    adjustIframeHeight(iframe);
                }
            }, 3000);
            </script>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    //  Settings page (~1000 lines)
    // ------------------------------------------------------------------

    public function create_admin_settings_page()
    {
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';

        $whitelabel_settings = Metasync::get_whitelabel_settings();

        $user_password = $whitelabel_settings['settings_password'] ?? '';
        $hide_settings_enabled = !empty($whitelabel_settings['hide_settings']);

        $protected_tabs = [];

        if (!empty($user_password)) {
            $protected_tabs[] = 'whitelabel';
        }

        if ($hide_settings_enabled && !empty($user_password)) {
            $protected_tabs = ['general', 'whitelabel', 'advanced'];
        }

        $password_protection_enabled = false;
        $password_validated = false;
        $password_error = '';

        if (in_array($active_tab, $protected_tabs)) {

            $password_protection_enabled = !empty($user_password);

            $auth = new Metasync_Auth_Manager('whitelabel', 1800);
            $password_validated = $auth->has_access();

            if (isset($_POST['whitelabel_password_submit']) && !$password_validated) {
                $password_error = 'Incorrect password. Please try again.';
            }
        }

        $is_authenticated = $password_validated;

        $whitelabel_otto_name = Metasync::get_whitelabel_otto_name();
        
        $page_slug = Metasync_Admin::$page_slug;
    ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->admin->render_plugin_header('Settings'); ?>
        
        <?php
        /*
        # Temporarily commented out: Clear Cache notice and button (can be re-enabled later)

        *    <div class="notice notice-success">
        *        <p>
        *            <b>Clear all caches at once</b><br/>
        *            This will slow down your site until caches are rebuilt
        *            <button style="margin-left: 15px;" type ="button" class="button" id="clear_otto_caches" data-toggle="tooltip" data-placement="top" title="Clear all <?php echo $whitelabel_otto_name;?> Caches">Clear <?php echo $whitelabel_otto_name;?> Cache</button>
        *        </p>
        *    </div> 
        */
        ?>
        
        <?php $this->admin->render_navigation_menu('general'); ?>
        
        <?php
            if (in_array($active_tab, $protected_tabs)) {

                if ($password_protection_enabled && !$password_validated) {
        ?>
                    <div class="dashboard-card" style="max-width: 500px; margin: 0 auto;">
                        <h2 style="text-align: center;">🔐 Protected Section</h2>
                        <p style="color: var(--dashboard-text-secondary); margin-bottom: 30px; text-align: center;">
                            <?php
                            $plugin_name = Metasync::get_effective_plugin_name('');
                            if ($hide_settings_enabled && !empty($user_password)) {
                                printf('Please enter the password to access the %s Settings section.', esc_html($plugin_name));
                            } else {
                                echo 'Please enter the password to access the Branding section.';
                            }
                            ?>
                        </p>
                        
                        <?php if (!empty($password_error)): ?>
                            <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
                                <strong>❌ Access Denied:</strong> <?php echo esc_html($password_error); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="post" action="" style="max-width: 400px; margin: 0 auto;">
                            <?php wp_nonce_field('whitelabel_password_nonce', 'whitelabel_nonce'); ?>
                            
                            <div style="margin-bottom: 20px;">
                                <label for="whitelabel_password" style="display: block; font-weight: 600; margin-bottom: 8px; color: var(--dashboard-text-primary);">
                                    🔑 Validate Password
                                </label>
                                <input
                                    type="password"
                                    id="whitelabel_password"
                                    name="whitelabel_password"
                                    placeholder="Enter password to access protected settings"
                                    style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px; box-sizing: border-box;"
                                    required
                                    autocomplete="off"
                                />
                            </div>
                            
                            <div style="text-align: center;">
                                <button
                                    type="submit"
                                    name="whitelabel_password_submit"
                                    value="1"
                                    class="button button-primary"
                                    style="padding: 12px 24px; font-size: 14px; font-weight: 600;"
                                >
                                    🚀 Submit Password
                                </button>
                            </div>
                        </form>

                        <div style="text-align: center; margin-top: 20px;">
                            <a href="#" id="metasync-forgot-password-link" style="color: #2271b1; text-decoration: none; font-size: 14px;">
                                🔓 Forgot Password?
                            </a>
                            <div id="metasync-recovery-message" style="margin-top: 15px; padding: 12px; border-radius: 6px; display: none;"></div>
                        </div>

                    </div>
                    
                    <script>
                    jQuery(document).ready(function($) {
                        $('#whitelabel_password').focus();

                        $('#whitelabel_password').on('keypress', function(e) {
                            if (e.which === 13) {
                                $(this).closest('form').submit();
                            }
                        });

                        $('#metasync-forgot-password-link').on('click', function(e) {
                            e.preventDefault();

                            var $link = $(this);
                            var $message = $('#metasync-recovery-message');

                            $link.css('pointer-events', 'none').css('opacity', '0.6');
                            $message.removeClass('success error').hide();
                            $message.html('⏳ Sending recovery email...').css('background', '#f0f6fc').css('color', '#0c5ba5').css('border', '1px solid #cfe2f3').fadeIn(200);

                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'metasync_recover_password',
                                    nonce: '<?php echo wp_create_nonce('metasync_recover_password_nonce'); ?>'
                                },
                                success: function(response) {
                                    $link.css('pointer-events', 'auto').css('opacity', '1');

                                    if (response.success) {
                                        $message.addClass('success').html('✅ ' + response.data.message)
                                            .css('background', '#d4edda')
                                            .css('color', '#155724')
                                            .css('border', '1px solid #c3e6cb');
                                    } else {
                                        $message.addClass('error').html('❌ ' + response.data.message)
                                            .css('background', '#f8d7da')
                                            .css('color', '#721c24')
                                            .css('border', '1px solid #f5c6cb');
                                    }
                                },
                                error: function() {
                                    $link.css('pointer-events', 'auto').css('opacity', '1');
                                    $message.addClass('error').html('❌ An error occurred. Please try again.')
                                        .css('background', '#f8d7da')
                                        .css('color', '#721c24')
                                        .css('border', '1px solid #f5c6cb');
                                }
                            });
                        });
                    });
                    </script>
        <?php
                    return;
                } elseif (!$password_protection_enabled) {
        ?>
                    <div class="notice notice-info" style="margin: 15px 0;">
                        <p>
                            <strong>💡 Security Tip:</strong> You can set a custom password in the White Label Settings to protect this section.
                        </p>
                    </div>
        <?php
                }
            }
        ?>

            <form method="post" action="options.php?tab=<?php echo $active_tab?>" id="metaSyncGeneralSetting">
                <?php
                    settings_fields(Metasync_Admin::option_group);

                    wp_nonce_field('meta_sync_general_setting_nonce', 'meta_sync_nonce');

                    if ($active_tab == 'general') {
                ?>
                    <div class="dashboard-card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <div style="flex: 1;">
                                <h2 style="margin: 0;">🔧 General Configuration</h2>
                                <p style="color: var(--dashboard-text-secondary); margin: 5px 0 0 0;">Configure your <?php echo esc_html(Metasync::get_effective_plugin_name()); ?> API, plugin features, caching, and general settings.</p>
                            </div>
                            <?php if ($hide_settings_enabled && !empty($user_password) && $is_authenticated): ?>
                            <div>
                                <?php Metasync_Compatibility_Checker::instance()->render_lock_button('general'); ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($hide_settings_enabled && !empty($user_password) && $is_authenticated): ?>
                        <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 20px;">
                            <strong>✅ Access Granted:</strong> You have successfully authenticated and can now modify settings.
                        </div>
                        <?php endif; ?>

                        <?php
                        $this->admin->render_accordion_sections(Metasync_Admin::$page_slug . '_general');
                        ?>
                    </div>

                    <div class="dashboard-card">
                        <h2>🔄 Content Genius Synchronization</h2>
                        <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Sync your categories and user/author data with <?php echo esc_html(Metasync::get_effective_plugin_name()); ?>.</p>
                        <button type="button" class="button button-primary" id="sendAuthToken" data-toggle="tooltip" data-placement="top" title="Sync Categories and User">
                            🔄 Sync Now
                        </button>
                    </div>

                    <div class="dashboard-card">
                        <h2>🚀 Setup Wizard</h2>
                        <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Run the setup wizard to configure your plugin, import from other SEO plugins, and optimize your settings in just a few minutes.</p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-setup-wizard')); ?>" class="button button-primary" style="text-decoration: none;">
                            ✨ Start Setup Wizard
                        </a>
                    </div>
                <?php
                    } elseif ($active_tab == 'whitelabel') {
                        $whitelabel_settings = Metasync::get_whitelabel_settings();
                        $user_password = $whitelabel_settings['settings_password'] ?? '';
                        $password_protection_enabled = !empty($user_password);
                ?>
                    <div class="dashboard-card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <div>
                                <h2>🎨 White Label Branding</h2>
                                <p style="color: var(--dashboard-text-secondary); margin: 5px 0 0 0;">Customize the plugin appearance with your own branding and logo.</p>
                            </div>
                            <?php if ($password_protection_enabled && $is_authenticated): ?>
                            <div>
                                <?php Metasync_Compatibility_Checker::instance()->render_lock_button('whitelabel'); ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($password_protection_enabled && $is_authenticated): ?>
                        <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong>✅ Access Granted:</strong> You have successfully authenticated and can now modify white label settings.
                            </div>
                        </div>

                        <?php endif; ?>
                        
                        <?php
                        do_settings_sections(Metasync_Admin::$page_slug . '_branding');
                        ?>
                    </div>

                    <!-- Export Whitelabel Settings section -->
                    <div class="dashboard-card">
                        <h2>📦 Export Whitelabel Plugin</h2>
                        <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Export the entire plugin with all whitelabel settings pre-configured. This creates a complete plugin zip file ready for installation on another WordPress site.</p>
                        <button type="button" class="button button-primary" id="metasync-export-whitelabel-btn" style="padding: 10px 20px; font-size: 14px; font-weight: 600;">
                            📥 Export Plugin with Whitelabel Settings
                        </button>
                        <p class="description" style="margin-top: 10px;">This will create a zip file containing the complete plugin with all your whitelabel settings included. Upload and install this zip file on another WordPress site via Plugins → Add New → Upload Plugin. All whitelabel configurations will be automatically applied upon activation.</p>
                    </div>

                    <!-- Advanced Access Control section -->
                    <?php Metasync_Access_Control_UI::render_access_control_table(); ?>

                    <!-- Custom Modal for Alerts -->
                    <div id="metasync-custom-modal" style="display: none; position: fixed; z-index: 999999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.6);">
                        <div style="position: relative; margin: 10% auto; max-width: 500px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 2px; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); animation: modalSlideIn 0.3s ease-out;">
                            <div style="background: white; border-radius: 10px; padding: 0; overflow: hidden;">
                                <!-- Header -->
                                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 24px; text-align: center;">
                                    <div id="modal-icon" style="font-size: 48px; margin-bottom: 12px;">⚠️</div>
                                    <h2 id="modal-title" style="color: white; margin: 0; font-size: 24px; font-weight: 600;">Password Required</h2>
                                </div>

                                <!-- Body -->
                                <div style="padding: 32px 24px;">
                                    <p id="modal-message" style="color: #4a5568; font-size: 15px; line-height: 1.6; text-align: center; margin: 0;">
                                        You must set a White Label Settings Password before enabling "Hide Settings".
                                    </p>
                                </div>

                                <!-- Footer -->
                                <div style="padding: 0 24px 24px; text-align: center;">
                                    <button type="button" id="modal-close-btn" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 12px 32px; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4); transition: all 0.3s ease;">
                                        Got It
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <style>
                        @keyframes modalSlideIn {
                            from {
                                opacity: 0;
                                transform: translateY(-50px);
                            }
                            to {
                                opacity: 1;
                                transform: translateY(0);
                            }
                        }

                        #modal-close-btn:hover {
                            transform: translateY(-2px);
                            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
                        }
                    </style>

                    <script>
                    jQuery(document).ready(function($) {
                        var isShowingModal = false;

                        function showModal(icon, title, message) {
                            isShowingModal = true;
                            $('#modal-icon').text(icon);
                            $('#modal-title').text(title);
                            $('#modal-message').html(message);
                            $('#metasync-custom-modal').fadeIn(200);

                            setTimeout(function() {
                                isShowingModal = false;
                            }, 300);
                        }

                        $('#modal-close-btn').on('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            $('#metasync-custom-modal').fadeOut(200);
                            return false;
                        });

                        $('#metasync-custom-modal').on('click', function(e) {
                            if (e.target === this) {
                                $(this).fadeOut(200);
                            }
                        });

                        function hasPassword() {
                            var passwordField = $('input[name="<?php echo Metasync_Admin::option_key; ?>[whitelabel][settings_password]"]');
                            var passwordValue = passwordField.val();
                            return passwordValue && passwordValue.length > 0;
                        }

                        function hasRecoveryEmail() {
                            var recoveryEmailField = $('input[name="<?php echo Metasync_Admin::option_key; ?>[whitelabel][recovery_email]"]');
                            var emailValue = recoveryEmailField.val();
                            return emailValue && emailValue.length > 0 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue);
                        }

                        var passwordField = $('input[name="<?php echo Metasync_Admin::option_key; ?>[whitelabel][settings_password]"]');
                        var recoveryEmailField = $('input[name="<?php echo Metasync_Admin::option_key; ?>[whitelabel][recovery_email]"]');

                        passwordField.on('blur', function() {
                            if (hasPassword() && !hasRecoveryEmail()) {
                                showModal(
                                    '📧',
                                    'Recovery Email Required',
                                    'You must set a <strong>Recovery Email</strong> when setting a password.<br><br>Please enter a valid email address in the <strong>Recovery Email</strong> field.'
                                );
                                recoveryEmailField.focus();
                            }
                        });

                        passwordField.on('input', function() {
                            if (hasPassword()) {
                                recoveryEmailField.attr('required', true);
                                recoveryEmailField.closest('tr').find('th').addClass('required-field');
                            } else {
                                recoveryEmailField.removeAttr('required');
                                recoveryEmailField.closest('tr').find('th').removeClass('required-field');
                            }
                        });

                        passwordField.trigger('input');

                        var hideSettingsCheckbox = $('#checkbox_hide_settings');
                        var originalCheckboxState = hideSettingsCheckbox.is(':checked');

                        hideSettingsCheckbox.on('click', function(e) {
                            if (!originalCheckboxState && !hasPassword()) {
                                e.preventDefault();
                                e.stopImmediatePropagation();

                                showModal(
                                    '🔐',
                                    'Password Required',
                                    'You must set a <strong>Settings Password</strong> before enabling "Hide Settings".<br><br>Please scroll up to the <strong>Branding</strong> section and set a password first.'
                                );

                                setTimeout(function() {
                                    $('input[name="<?php echo Metasync_Admin::option_key; ?>[whitelabel][settings_password]"]').focus();
                                }, 300);

                                return false;
                            }

                            setTimeout(function() {
                                originalCheckboxState = hideSettingsCheckbox.is(':checked');
                            }, 0);
                        });

                        var storedPassword = '<?php echo esc_js($whitelabel_settings['settings_password'] ?? ''); ?>';

                        passwordField.on('keydown', function(e) {
                            var currentValue = $(this).val();

                            if (hideSettingsCheckbox.is(':checked') &&
                                (currentValue.length <= 1 || !currentValue) &&
                                (e.keyCode === 8 || e.keyCode === 46)) {

                                e.preventDefault();
                                e.stopImmediatePropagation();

                                if (!isShowingModal) {
                                    showModal(
                                        '🚫',
                                        'Cannot Remove Password',
                                        'You cannot remove the <strong>White Label Settings Password</strong> while "Hide Settings" is enabled.<br><br>Please uncheck <strong>"Hide Settings"</strong> first if you want to remove the password.'
                                    );
                                }

                                return false;
                            }
                        });

                        $(document).on('metasync_settings_saved', function() {
                        });

                        $(document).on('keydown', function(e) {
                            if (e.key === 'Escape') {
                                $('#metasync-custom-modal').fadeOut(200);
                            }
                        });

                        $('#metasync-export-whitelabel-btn').on('click', function(e) {
                            e.preventDefault();
                            
                            var $button = $(this);
                            var originalText = $button.html();
                            
                            $button.prop('disabled', true).html('⏳ Exporting...');
                            
                            var form = $('<form>', {
                                'method': 'POST',
                                'action': '<?php echo esc_js(admin_url('admin-post.php')); ?>',
                                'target': '_blank'
                            });
                            
                            form.append($('<input>', {
                                'type': 'hidden',
                                'name': 'action',
                                'value': 'metasync_export_whitelabel_settings'
                            }));
                            
                            form.append($('<input>', {
                                'type': 'hidden',
                                'name': '_wpnonce',
                                'value': '<?php echo wp_create_nonce('metasync_export_whitelabel'); ?>'
                            }));
                            
                            $('body').append(form);
                            form.submit();
                            
                            setTimeout(function() {
                                form.remove();
                                $button.prop('disabled', false).html(originalText);
                            }, 2000);
                        });
                    });
                    </script>
                <?php
                    } elseif ($active_tab == 'advanced') {
                        if (!Metasync_Access_Control::user_can_access('hide_advanced')) {
                            echo '<div class="dashboard-card" style="background: var(--dashboard-card-bg); padding: 25px; border-radius: 8px; text-align: center;">';
                            echo '<h2 style="color: var(--dashboard-text-primary);">🔒 Access Denied</h2>';
                            echo '<p style="color: var(--dashboard-text-secondary);">You do not have permission to access this page.</p>';
                            echo '</div>';
                        } else {
                ?>
                    <div class="dashboard-card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <div style="flex: 1;">
                                <h2 style="margin: 0;">🧰 Advanced Settings</h2>
                                <p style="color: var(--dashboard-text-secondary); margin: 5px 0 0 0;">Technical utilities for troubleshooting and connectivity checks.</p>
                            </div>
                            <?php if ($hide_settings_enabled && !empty($user_password) && $is_authenticated): ?>
                            <div>
                                <?php Metasync_Compatibility_Checker::instance()->render_lock_button('advanced'); ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($hide_settings_enabled && !empty($user_password) && $is_authenticated): ?>
                        <div style="background: rgba(212, 237, 218, 0.2); border: 1px solid rgba(195, 230, 203, 0.5); color: var(--dashboard-success, #10b981); padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                            <strong>✅ Access Granted:</strong> You have successfully authenticated and can now modify settings.
                        </div>
                        <?php endif; ?>

                        <?php
                        if (isset($_GET['debug_mode_enabled']) && $_GET['debug_mode_enabled'] == '1'):
                            $indefinite = isset($_GET['indefinite']) && $_GET['indefinite'] == '1';
                        ?>
                        <div class="notice notice-success inline" style="margin-bottom: 20px;">
                            <p>
                                <strong>✅ Success!</strong> Debug mode has been enabled<?php echo $indefinite ? ' indefinitely' : ' for 24 hours'; ?>.
                            </p>
                        </div>
                        <?php endif; ?>

                        <?php if (isset($_GET['debug_mode_disabled']) && $_GET['debug_mode_disabled'] == '1'): ?>
                        <div class="notice notice-info inline" style="margin-bottom: 20px;">
                            <p>
                                <strong>ℹ️ Debug Mode Disabled:</strong> Debug mode has been successfully disabled.
                            </p>
                        </div>
                        <?php endif; ?>

                        <?php if (isset($_GET['debug_mode_extended']) && $_GET['debug_mode_extended'] == '1'): ?>
                        <div class="notice notice-success inline" style="margin-bottom: 20px;">
                            <p>
                                <strong>✅ Extended!</strong> Debug mode has been extended for another 24 hours.
                            </p>
                        </div>
                        <?php endif; ?>

                        <?php if (isset($_GET['debug_error']) && $_GET['debug_error'] == '1'): ?>
                        <div class="notice notice-error inline" style="margin-bottom: 20px;">
                            <p>
                                <strong>❌ Error:</strong> Unable to perform the debug mode operation. Please try again.
                            </p>
                        </div>
                        <?php endif; ?>

                        <?php
                        $this->admin->render_advanced_accordion();
                        ?>
                    </div>

                <?php
                        }
                    }
                ?>

                <!-- Save button removed - using floating notification system instead -->

            </form>

            <!-- Lock Section Button Handler (runs on all tabs) -->
            <script>
            jQuery(document).ready(function($) {
                var ajaxUrl = (typeof window.ajaxurl !== 'undefined' && window.ajaxurl)
                    ? window.ajaxurl
                    : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                $('.metasync-lock-btn').on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    var currentTab = $(this).data('tab');

                    var logoutForm = $('<form>', {
                        'method': 'post',
                        'action': ''
                    });

                    logoutForm.append('<?php echo wp_nonce_field("whitelabel_logout_nonce", "whitelabel_logout_nonce", true, false); ?>');

                    logoutForm.append($('<input>', {
                        'type': 'hidden',
                        'name': 'whitelabel_logout',
                        'value': '1'
                    }));

                    $('body').append(logoutForm);
                    logoutForm.submit();

                    return false;
                });
            });
            </script>

            <!-- Host Blocking-generated JavaScript -->
            <script>
            jQuery(document).ready(function($) {
                var ajaxUrl = (typeof window.ajaxurl !== 'undefined' && window.ajaxurl)
                    ? window.ajaxurl
                    : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                
                $(document).on('click', '#test-get-request', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    runHostTest('GET');
                    return false;
                });
                
                $(document).on('click', '#test-post-request', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    runHostTest('POST');
                    return false;
                });
                
                $(document).on('click', '#test-both-requests', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    runHostTest('BOTH');
                    return false;
                });
                
                function runHostTest(method) {
                    var buttonId = (method === 'BOTH' ? 'test-both-requests' : 'test-' + method.toLowerCase() + '-request');
                    var $button = $('#' + buttonId);
                    
                    if ($button.length === 0) {
                        alert('Error: Test button not found. Please refresh the page.');
                        return;
                    }
                    
                    var originalText = $button.text();
                    
                    $button.prop('disabled', true);
                    $button.text('🔄 Testing...');
                    
                    var $resultsDiv = $('#host-test-results');
                    var $resultsContent = $('#test-results-content');
                    $resultsDiv.show();
                    $resultsContent.html('<div class="notice notice-info"><p>Running ' + (method === 'BOTH' ? 'GET and POST' : method) + ' test(s)...</p></div>');
                    
                    var testsToRun = (method === 'BOTH') ? ['GET', 'POST'] : [method];
                    var completedTests = 0;
                    var allResults = [];
                    
                    testsToRun.forEach(function(testMethod) {
                        var action = 'metasync_test_host_blocking_' + testMethod.toLowerCase();
                        
                        $.ajax({
                            url: ajaxUrl,
                            type: 'POST',
                            dataType: 'json',
                            data: { action: action },
                            timeout: 35000,
                            success: function(response, textStatus, xhr) {
                                try {
                                    if (response && response.success && response.data) {
                                        allResults.push(response.data);
                                    } else {
                                        allResults.push({
                                            method: testMethod,
                                            status: 'error',
                                            error: (response && response.data) ? response.data : 'Unexpected response',
                                            blocked: true,
                                            details: 'Received non-success response from server.'
                                        });
                                    }
                                } catch (e) {
                                    allResults.push({
                                        method: testMethod,
                                        status: 'error',
                                        error: 'Response parse error: ' + (e && e.message ? e.message : e),
                                        blocked: true
                                    });
                                }
                                finalizeOne();
                            },
                            error: function(xhr, status, error) {
                                var payload = (xhr && xhr.responseText) ? xhr.responseText.substring(0, 500) : '';
                                allResults.push({
                                    method: testMethod,
                                    status: 'error',
                                    error: 'AJAX failed: ' + error + (payload ? ' — ' + payload : ''),
                                    blocked: true,
                                    details: 'Request did not complete successfully. Status: ' + status
                                });
                                finalizeOne();
                            }
                        });
                    });
                    
                    function finalizeOne() {
                        completedTests++;
                        if (completedTests === testsToRun.length) {
                            displayResults(allResults);
                            resetButtons();
                            var $container = $('#host-test-results');
                            if ($container && $container[0] && $container[0].scrollIntoView) {
                                $container[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
                            }
                        }
                    }
                }
                
                function resetButtons() {
                    $('#test-get-request, #test-post-request, #test-both-requests').prop('disabled', false);
                    $('#test-get-request').text('🔍 Test GET Request');
                    $('#test-post-request').text('📤 Test POST Request');
                    $('#test-both-requests').text('🔄 Test Both Requests');
                }
                
                function displayResults(results) {
                    var html = '';
                    
                    results.forEach(function(result) {
                        var statusClass = result.status === 'success' ? 'success' : 'error';
                        var statusIcon = result.status === 'success' ? '✅' : '❌';
                        var blockedStatus = result.blocked ? 'BLOCKED' : 'ALLOWED';
                        var blockedClass = result.blocked ? 'blocked' : 'allowed';
                        
                        html += '<div class="test-result-item ' + statusClass + '">';
                        html += '<div class="test-result-header">';
                        html += '<h4>' + statusIcon + ' ' + result.method + ' Request - ' + blockedStatus + '</h4>';
                        html += '<span class="test-status ' + blockedClass + '">' + blockedStatus + '</span>';
                        html += '</div>';
                        
                        html += '<div class="test-result-details">';
                        html += '<p><strong>Response Time:</strong> ' + result.response_time + '</p>';
                        html += '<p><strong>Status:</strong> ' + result.status + '</p>';
                        
                        if (result.status_code) {
                            html += '<p><strong>HTTP Status Code:</strong> <span class="status-code">' + result.status_code + '</span></p>';
                        }
                        
                        if (result.error) {
                            html += '<p><strong>Error:</strong> <span class="error-message">' + result.error + '</span></p>';
                        }
                        
                        if (result.body) {
                            html += '<p><strong>Response Body:</strong></p>';
                            html += '<pre class="response-body">' + escapeHtml(result.body) + '</pre>';
                        }
                        
                        if (result.headers && Object.keys(result.headers).length > 0) {
                            html += '<p><strong>Response Headers:</strong></p>';
                            html += '<pre class="response-headers">';
                            Object.keys(result.headers).forEach(function(key) {
                                html += key + ': ' + result.headers[key] + '\n';
                            });
                            html += '</pre>';
                        }
                        
                        if (result.sent_data) {
                            html += '<p><strong>Sent Data:</strong></p>';
                            html += '<pre class="sent-data">' + escapeHtml(JSON.stringify(result.sent_data, null, 2)) + '</pre>';
                        }
                        
                        if (result.parsed_response) {
                            html += '<p><strong>Parsed Response:</strong></p>';
                            html += '<pre class="parsed-response">' + escapeHtml(JSON.stringify(result.parsed_response, null, 2)) + '</pre>';
                        }
                        
                        html += '<p><strong>Details:</strong> ' + result.details + '</p>';
                        html += '</div>';
                        html += '</div>';
                    });
                    
                    $('#test-results-content').html(html);
                    $('#host-test-results').show();
                }
                
                function escapeHtml(text) {
                    var map = {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        '\'': '&#039;'
                    };
                    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
                }
            });
            </script>

            <!-- Host Blocking Test CSS -->
            <style>
            .test-result-item {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 8px;
                margin-bottom: 20px;
                overflow: hidden;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            
            .test-result-item.success {
                border-left: 4px solid #28a745;
            }
            
            .test-result-item.error {
                border-left: 4px solid #dc3545;
            }
            
            .test-result-header {
                background: #f8f9fa;
                padding: 15px 20px;
                border-bottom: 1px solid #dee2e6;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .test-result-header h4 {
                margin: 0;
                color: #495057;
                font-size: 16px;
            }
            
            .test-status {
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: bold;
                text-transform: uppercase;
            }
            
            .test-status.allowed {
                background: #d4edda;
                color: #155724;
            }
            
            .test-status.blocked {
                background: #f8d7da;
                color: #721c24;
            }
            
            .test-result-details {
                padding: 20px;
            }
            
            .test-result-details p {
                margin: 8px 0;
                color: #495057;
            }
            
            .test-result-details code {
                background: #f8f9fa;
                padding: 2px 6px;
                border-radius: 3px;
                font-family: 'Courier New', monospace;
                color: #e83e8c;
            }
            
            .status-code {
                font-weight: bold;
                color: #007cba;
            }
            
            .error-message {
                color: #dc3545;
                font-weight: bold;
            }
            
            .response-body, .response-headers, .sent-data, .parsed-response {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 4px;
                padding: 12px;
                margin: 8px 0;
                font-family: 'Courier New', monospace;
                font-size: 12px;
                line-height: 1.4;
                max-height: 200px;
                overflow-y: auto;
                white-space: pre-wrap;
                word-break: break-all;
            }
            
            .response-body {
                color: #495057;
            }
            
            .response-headers {
                color: #6c757d;
            }
            
            .sent-data {
                color: #007cba;
            }
            
            .parsed-response {
                color: #28a745;
                background: #f8fff9;
                border-color: #c3e6cb;
            }
            
            #host-test-results {
                margin-top: 20px;
            }
            
            #host-test-results h3 {
                color: #495057;
                margin-bottom: 15px;
                padding-bottom: 10px;
                border-bottom: 2px solid #dee2e6;
            }
            
            .button:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            </style>

            <?php if ($active_tab === 'advanced'): ?>
                <?php if (isset($_GET['settings_cleared']) && $_GET['settings_cleared'] == '1'): ?>
                    <div class="notice notice-success is-dismissible">
                        <p><strong>✅ Success!</strong> All plugin settings have been cleared successfully and a new Plugin Auth Token has been generated. Please reconfigure the plugin as needed.</p>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['clear_settings_error']) && $_GET['clear_settings_error'] == '1'): ?>
                    <div class="notice notice-error is-dismissible">
                        <p><strong>❌ Error!</strong> Failed to clear settings due to a security check failure. Please try again.</p>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['log_cleared']) && $_GET['log_cleared'] == '1'): ?>
                    <div class="notice notice-success is-dismissible">
                        <p><strong>✅ Success!</strong> Error logs have been cleared successfully.</p>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['clear_error']) && $_GET['clear_error'] == '1'): ?>
                    <div class="notice notice-error is-dismissible">
                        <p><strong>❌ Error!</strong> Failed to clear error logs due to a security check failure. Please try again.</p>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['access_roles_saved']) && $_GET['access_roles_saved'] == '1'): ?>
                    <div class="notice notice-success is-dismissible">
                        <p><strong>✅ Success!</strong> Plugin access roles have been saved successfully.</p>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['access_roles_error']) && $_GET['access_roles_error'] == '1'): ?>
                    <div class="notice notice-error is-dismissible">
                        <p><strong>❌ Error!</strong> Failed to save plugin access roles. <?php echo isset($_GET['message']) ? esc_html(urldecode($_GET['message'])) : 'Please try again.'; ?></p>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
                <?php
    }

    // ------------------------------------------------------------------
    //  Simple page renderers
    // ------------------------------------------------------------------

    public function create_admin_dashboard_page()
    {
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->admin->render_plugin_header('Dashboard'); ?>
        
        <?php $this->admin->render_navigation_menu('dashboard'); ?>
            
            <div class="dashboard-card">
                <h2>📊 <?php echo esc_html($this->admin->get_effective_menu_title()); ?> Dashboard</h2>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Access your <?php echo esc_html(Metasync::get_effective_plugin_name()); ?> dashboard to view analytics, manage SEO settings, and monitor your site performance.</p>
                <?php
        if (!isset(Metasync::get_option('general')['linkgraph_token']) || Metasync::get_option('general')['linkgraph_token'] == '') {
                    echo '<p style="color: #d54e21; margin-bottom: 15px;">⚠️ Authentication required: Please authenticate with your ' . esc_html(Metasync::get_effective_plugin_name()) . ' account and save your auth token in general settings.</p>';
                    echo '<a href="' . admin_url('admin.php?page=' . Metasync_Admin::$page_slug) . '" class="button button-secondary">Go to Settings</a>';
                } else {
                    echo '<a href="' . esc_url($this->admin->get_dashboard_url()) . '" target="_blank" class="button button-primary">🌐 Open Dashboard</a>';
                }
                ?>
            </div>
        </div>
    <?php
    }

    public function create_admin_robots_txt_page()
    {
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->admin->render_plugin_header('Robots.txt'); ?>
        
        <?php $this->admin->render_navigation_menu('robots_txt'); ?>
        
        <?php
        require_once plugin_dir_path(dirname(__FILE__)) . 'robots-txt/class-metasync-robots-txt.php';

        $robots_txt = Metasync_Robots_Txt::get_instance();

        if (isset($_POST['metasync_robots_txt_nonce'])) {
            check_admin_referer('metasync_save_robots_txt', 'metasync_robots_txt_nonce');

            if (isset($_POST['robots_content'])) {
                $content = wp_unslash($_POST['robots_content']);

                $validation = $robots_txt->validate_content($content);

                if ($validation['valid']) {
                    $result = $robots_txt->write_robots_file($content);

                    if (is_wp_error($result)) {
                        echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
                    } else {
                        echo '<div class="notice notice-success"><p>' . esc_html__('robots.txt file saved successfully!', 'metasync') . '</p></div>';

                        if (!empty($validation['warnings'])) {
                            foreach ($validation['warnings'] as $warning) {
                                echo '<div class="notice notice-warning"><p>' . esc_html($warning) . '</p></div>';
                            }
                        }
                    }
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Validation failed. Please fix the errors below:', 'metasync') . '</p>';
                    foreach ($validation['errors'] as $error) {
                        echo '<p>- ' . esc_html($error) . '</p>';
                    }
                    echo '</div>';
                }
            }
        }

        $current_content = $robots_txt->read_robots_file();
        if (is_wp_error($current_content)) {
            echo '<div class="notice notice-error"><p>' . esc_html($current_content->get_error_message()) . '</p></div>';
            $current_content = '';
        }

        $backups = $robots_txt->get_backup_history(10);

        $file_exists = $robots_txt->file_exists();
        $is_writable = $robots_txt->is_writable();

        $robots_txt->render($this->admin, $current_content, $backups, $file_exists, $is_writable);
        ?>
        </div>
    <?php
    }

    public function create_admin_report_issue_page()
    {
        require_once plugin_dir_path(dirname(__FILE__)) . 'views/metasync-report-issue.php';
    }

    public function create_admin_xml_sitemap_page()
    {
        require_once plugin_dir_path(dirname(__FILE__)) . 'sitemap/class-metasync-sitemap-generator.php';

        $sitemap_generator = new Metasync_Sitemap_Generator();

        if (isset($_POST['metasync_sitemap_nonce'])) {
            check_admin_referer('metasync_sitemap_action', 'metasync_sitemap_nonce');

            if (isset($_POST['generate_sitemap'])) {
                $disabled_plugins = $sitemap_generator->disable_other_sitemap_generators();

                $result = $sitemap_generator->generate_sitemap();

                if (is_wp_error($result)) {
                    echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
                } else {
                    $message = esc_html__('Sitemap generated successfully!', 'metasync');
                    if ($disabled_plugins) {
                        $message .= ' ' . esc_html__('Conflicting sitemap generators have been automatically disabled.', 'metasync');
                    }
                    
                    $robots_result = get_transient('metasync_sitemap_robots_updated');
                    if ($robots_result && $robots_result['success']) {
                        if ($robots_result['action'] === 'added') {
                            $message .= ' ' . esc_html__('Sitemap URL has been added to robots.txt.', 'metasync');
                        } elseif ($robots_result['action'] === 'updated') {
                            $message .= ' ' . esc_html__('Sitemap URL has been updated in robots.txt.', 'metasync');
                        } elseif ($robots_result['action'] === 'created') {
                            $message .= ' ' . esc_html__('robots.txt file has been created with sitemap URL.', 'metasync');
                        }
                        delete_transient('metasync_sitemap_robots_updated');
                    }
                    
                    echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
                }
            } elseif (isset($_POST['enable_auto_update'])) {
                update_option('metasync_sitemap_auto_update', true);
                $sitemap_generator->setup_auto_update_hooks();
                echo '<div class="notice notice-success"><p>' . esc_html__('Auto-update enabled!', 'metasync') . '</p></div>';
            } elseif (isset($_POST['disable_auto_update'])) {
                update_option('metasync_sitemap_auto_update', false);
                echo '<div class="notice notice-success"><p>' . esc_html__('Auto-update disabled!', 'metasync') . '</p></div>';
            } elseif (isset($_POST['delete_sitemap'])) {
                $deleted = $sitemap_generator->delete_sitemap();
                if ($deleted) {
                    update_option('metasync_sitemap_auto_update', false);
                    echo '<div class="notice notice-success"><p>' . esc_html__('Sitemap deleted successfully!', 'metasync') . '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Failed to delete sitemap. The file may not exist or is not writable.', 'metasync') . '</p></div>';
                }
            } elseif (isset($_POST['enable_other_sitemaps'])) {
                $enabled_plugins = $sitemap_generator->enable_other_sitemap_generators();
                if ($enabled_plugins) {
                    echo '<div class="notice notice-success"><p>' . esc_html__('Other sitemap plugins have been re-enabled successfully!', 'metasync') . '</p></div>';
                } else {
                    echo '<div class="notice notice-info"><p>' . esc_html__('No sitemap plugins were found to re-enable.', 'metasync') . '</p></div>';
                }
            }
        }

        $sitemap_exists = $sitemap_generator->sitemap_exists();
        $sitemap_url = $sitemap_generator->get_sitemap_url();
        $url_count = $sitemap_generator->count_urls();
        $last_generated = $sitemap_generator->get_last_generated_time();
        $auto_update_enabled = get_option('metasync_sitemap_auto_update', false);
        $active_sitemap_plugins = $sitemap_generator->check_active_sitemap_plugins();

        require_once plugin_dir_path(dirname(__FILE__)) . 'views/metasync-xml-sitemap.php';
    }

    public function create_admin_custom_pages_page()
    {
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->admin->render_plugin_header('Custom HTML Pages'); ?>
        
        <?php $this->admin->render_navigation_menu('custom_pages'); ?>
        
        <div class="metasync-page-content">
            <?php
            require_once plugin_dir_path(dirname(__FILE__)) . 'custom-pages/class-metasync-custom-pages-admin.php';
            Metasync_Custom_Pages_Admin::render_admin_page();
            ?>
        </div>
        
        </div>
        <?php
    }

    public function create_admin_404_monitor_page()
    {
        require_once plugin_dir_path(dirname(__FILE__)) . '404-monitor/class-metasync-404-monitor-database.php';
        require_once plugin_dir_path(dirname(__FILE__)) . '404-monitor/class-metasync-404-monitor.php';

        $db_404 = new Metasync_Error_Monitor_Database();
        $ErrorMonitor = new Metasync_Error_Monitor($db_404);
        
        $ErrorMonitor->create_admin_plugin_interface();
    }

    public function create_admin_search_engine_verification_page()
    {
        $page_slug = Metasync_Admin::$page_slug . '_searchengines-verification';
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->admin->render_plugin_header('Site Verification'); ?>
        
        <?php $this->admin->render_navigation_menu('site-verification'); ?>
            
            <form method="post" action="options.php">
                <div class="dashboard-card">
                    <h2>🔍 Search Engine Verification</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Verify your site ownership with major search engines to access their tools and analytics.</p>
                    <?php
        settings_fields(Metasync_Admin::option_group);
        do_settings_sections($page_slug);
                    ?>
                </div>
                
                <!-- Save button removed - using floating notification system instead -->
            </form>
        </div>
        <?php
    }

    public function create_admin_local_business_page()
    {
        $page_slug = Metasync_Admin::$page_slug . '_local-seo';
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->admin->render_plugin_header('Local Business'); ?>
        
        <?php $this->admin->render_navigation_menu('local-business'); ?>
            
            <form method="post" action="options.php">
                <div class="dashboard-card">
                    <h2>🏢 Local Business Configuration</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure your local business information for better local search engine optimization.</p>
                    <?php
        settings_fields(Metasync_Admin::option_group);
        do_settings_sections($page_slug);
                    ?>
                </div>
                
                <!-- Save button removed - using floating notification system instead -->
            </form>
        </div>
        <?php
    }

    public function create_admin_code_snippets_page()
    {
        $page_slug = Metasync_Admin::$page_slug . '_code-snippets';
        ?>
       <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->admin->render_plugin_header('Code Snippets'); ?>
        
        <?php $this->admin->render_navigation_menu('code-snippets'); ?>
            
            <form method="post" action="options.php">
                <div class="dashboard-card">
                    <h2>📝 Code Snippets Management</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Add custom code snippets to enhance your site functionality and tracking capabilities.</p>
                    <?php
        settings_fields(Metasync_Admin::option_group);
        do_settings_sections($page_slug);
                    ?>
                </div>
                
                <!-- Save button removed - using floating notification system instead -->
            </form>
        </div>
        <?php
    }

    public function create_admin_google_instant_index_page()
    {
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->admin->render_plugin_header('Instant Indexing'); ?>
        
        <?php $this->admin->render_navigation_menu('instant_index'); ?>
        
        <?php
        google_index_direct()->show_google_instant_indexing_settings();
        ?>
        </div>
        <?php
    }

    public function create_admin_google_console_page()
    {
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">

        <?php $this->admin->render_plugin_header('Google Console'); ?>

        <?php $this->admin->render_navigation_menu('google_console'); ?>

        <?php
        google_index_direct()->show_google_instant_indexing_console();
        ?>
        </div>
        <?php
    }

    public function create_admin_bing_console_page()
    {
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">

        <?php $this->admin->render_plugin_header('Bing Console'); ?>

        <?php $this->admin->render_navigation_menu('bing_console'); ?>

        <?php
        require_once plugin_dir_path(dirname(__FILE__)) . 'bing-index/class-metasync-bing-instant-index.php';
        $bing_instant_index = new Metasync_Bing_Instant_Index();
        $bing_instant_index->show_bing_instant_indexing_console();
        ?>
        </div>
        <?php
    }

    public function create_admin_optimal_settings_page()
    {
        ?>
       <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->admin->render_plugin_header('Settings'); ?>
        
        <?php $this->admin->render_navigation_menu('optimal-settings'); ?>
            
            <div class="dashboard-card">
                <h2>🚀 Site Compatibility Status</h2>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Check your site's compatibility with optimal <?php echo esc_html(Metasync::get_effective_plugin_name()); ?> settings.</p>
                <?php
        $optimal_settings = new Metasync_Optimal_Settings();
        $optimal_settings->site_compatible_status_view();
                ?>
            </div>

            <div class="dashboard-card">
                <h2>⚙️ Optimization Settings</h2>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure optimization settings for best performance.</p>
                <?php
        $this->optimization_settings_options();
                ?>
            </div>
        </div>
        <?php
    }

    public function create_admin_global_settings_page()
    {
        $page_slug = Metasync_Admin::$page_slug . '_common-settings';
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->admin->render_plugin_header('Settings'); ?>
        
        <?php $this->admin->render_navigation_menu('global-settings'); ?>
            
            <form method="post" action="options.php">
                <div class="dashboard-card">
                    <h2>🌐 Global Configuration</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure global settings that apply across your entire site.</p>
                    <?php
        settings_fields(Metasync_Admin::option_group);
        do_settings_sections($page_slug);
                    ?>
                </div>
                
                <!-- Save button removed - using floating notification system instead -->
            </form>
        </div>
        <?php
    }

    public function create_admin_common_meta_settings_page()
    {
        $page_slug = Metasync_Admin::$page_slug . '_common-meta-settings';
        ?>
       <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->admin->render_plugin_header('Settings'); ?>
        
        <?php $this->admin->render_navigation_menu('common-meta-settings'); ?>
            
            <form method="post" action="options.php">
                <div class="dashboard-card">
                    <h2>🏷️ Meta Configuration</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure common meta tags and SEO settings for your site.</p>
                    <?php
        settings_fields(Metasync_Admin::option_group);
        do_settings_sections($page_slug);
                    ?>
                </div>
                
                <!-- Save button removed - using floating notification system instead -->
            </form>
        </div>
        <?php
    }

    public function create_admin_social_meta_page()
    {
        $page_slug = Metasync_Admin::$page_slug . '_social-meta';
        ?>
       <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->admin->render_plugin_header('Settings'); ?>
        
        <?php $this->admin->render_navigation_menu('social-meta'); ?>
            
            <form method="post" action="options.php">
                <div class="dashboard-card">
                    <h2>📲 Social Meta Tags</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure how your content appears when shared on social media platforms.</p>
                    <?php
        settings_fields(Metasync_Admin::option_group);
        do_settings_sections($page_slug);
                    ?>
                </div>
                
                <!-- Save button removed - using floating notification system instead -->
            </form>
        </div>
        <?php
    }

    public function create_admin_seo_controls_page()
    {
        $page_slug = Metasync_Admin::$page_slug . '_seo-controls';
        ?>
       <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->admin->render_plugin_header('Indexation Control'); ?>
        
        <?php $this->admin->render_navigation_menu('seo_controls'); ?>
        
        <!-- Status Messages Container -->
        <div id="seo-controls-messages"></div>
            
            <form method="post" action="options.php" id="metaSyncSeoControlsForm">
                <div class="dashboard-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="margin: 0;">🚫 Indexation Control</h2>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=metasync-import-external&tab=indexation')); ?>" class="button button-secondary">
                            <span>📥</span> Import from SEO Plugins
                        </a>
                    </div>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Control which archive pages should be disallowed from search engine indexing to improve your site's SEO health and conserve crawl budget.</p>
                    <?php
                         settings_fields(Metasync_Admin::option_group);
                         do_settings_sections($page_slug);
                         
                         wp_nonce_field('meta_sync_seo_controls_nonce', 'meta_sync_seo_controls_nonce');
                    ?>
                </div>
                
                <!-- Save button removed - using floating notification system instead -->
            </form>
        </div>
        <?php
    }

    public function optimization_settings_options()
    {
        $page_slug = Metasync_Admin::$page_slug . '_optimal-settings';
        $site_info_slug = Metasync_Admin::$page_slug . '_site-info-settings';

        printf('<form method="post" action="options.php">');
        settings_fields(Metasync_Admin::option_group);
        do_settings_sections($page_slug);
        do_settings_sections($site_info_slug);
        submit_button();
        printf('</form>');
    }

    public function create_admin_error_logs_page()
    {
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->admin->render_plugin_header('Error Logs'); ?>
        
        <?php $this->admin->render_navigation_menu('error-log'); ?>
            
            <div class="dashboard-card">
                <h2>⚠️ Error Logs Management</h2>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">View and manage system error logs to troubleshoot issues and monitor plugin performance.</p>
                <?php
        $error_logs = new Metasync_Error_Logs();

        if ($error_logs->can_show_error_logs()) {
            $log_content = $error_logs->get_error_logs(50);
            
            if (!empty(trim($log_content))) {
                $error_logs->show_copy_button();
                $error_logs->show_logs();
                $error_logs->show_info();
            } else {
                echo '<div class="dashboard-empty-state">';
                echo '<p style="color: var(--dashboard-text-secondary); font-style: italic; text-align: center; padding: 40px 20px;">✅ Log file is empty - no errors recorded.</p>';
                echo '</div>';
            }
        } else {
            $error_message = $error_logs->get_error_message();
            if (!empty($error_message)) {
                echo '<div class="dashboard-empty-state">';
                echo '<p style="color: #d54e21; font-weight: bold; text-align: center; padding: 20px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; margin: 20px 0;">';
                echo '⚠️ ' . esc_html($error_message);
                echo '</p>';
                echo '</div>';
            } else {
                echo '<div class="dashboard-empty-state">';
                echo '<p style="color: var(--dashboard-text-secondary); font-style: italic; text-align: center; padding: 40px 20px;">⚠️ Unable to access error log file. Please check permissions.</p>';
                echo '</div>';
            }
        }
                ?>
            </div>
        </div>
        <?php
    }

    public function create_admin_sync_log_page()
    {
        $sync_db = new Metasync_Sync_History_Database();
        
        if (wp_doing_ajax()) {
            $this->handle_sync_log_ajax();
            return;
        }
        
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 10;
        $offset = ($page - 1) * $per_page;
        
        $filters = [
            'date_range' => isset($_GET['date_range']) ? sanitize_text_field($_GET['date_range']) : '',
            'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
        ];

        $date_range = $filters['date_range'];
        $wp_now_ts = current_time('timestamp');
        $date_from = '';
        $date_to = '';

        if (!empty($date_range)) {
            $date_to = date('Y-m-d H:i:s', $wp_now_ts);

            if ($date_range === 'today') {
                $start_ts = strtotime('today', $wp_now_ts);
                $date_from = date('Y-m-d H:i:s', $start_ts);
            } elseif ($date_range === 'yesterday') {
                $start_ts = strtotime('yesterday', $wp_now_ts);
                $end_ts = strtotime('today', $wp_now_ts) - 1;
                $date_from = date('Y-m-d H:i:s', $start_ts);
                $date_to = date('Y-m-d H:i:s', $end_ts);
            } elseif ($date_range === 'this_week') {
                $start_of_week = (int) get_option('start_of_week', 1);
                $day_of_week = (int) date('w', $wp_now_ts);
                $delta_days = ($day_of_week - $start_of_week + 7) % 7;
                $start_ts = strtotime('-' . $delta_days . ' days', strtotime('today', $wp_now_ts));
                $date_from = date('Y-m-d H:i:s', $start_ts);
            } elseif ($date_range === 'this_month') {
                $start_ts = strtotime(date('Y-m-01 00:00:00', $wp_now_ts));
                $date_from = date('Y-m-d H:i:s', $start_ts);
            } elseif ($date_range === 'all') {
                // no bounds
            }
        }

        if (!empty($date_from)) {
            $filters['date_from'] = $date_from;
        }
        if (!empty($date_to)) {
            $filters['date_to'] = $date_to;
        }
        
        $filters = array_filter($filters);
        
        $sync_records = $sync_db->getAllRecords($per_page, $offset, $filters);
        $total_records = $sync_db->get_count($filters);
        $total_pages = ceil($total_records / $per_page);
        
        $stats = $sync_db->get_statistics();
        
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->admin->render_plugin_header('Sync History'); ?>
        
        <?php $this->admin->render_navigation_menu('sync_log'); ?>
            
            <div class="dashboard-card">
                <div class="sync-log-header">
                    <div class="sync-log-title-section">
                        <h2>📋 Sync History</h2>
                        <p style="color: var(--dashboard-text-secondary); margin-bottom: 0;">Recent content synchronizations from external tools.</p>
                    </div>
                    
                    <!-- Filters - Right aligned -->
                    <div class="sync-log-filters">
                        <form method="get" class="sync-filters-form" onchange="this.submit()">
                            <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page']); ?>">
                            
                            <select name="date_range" class="sync-filter-select">
                                <option value="all" <?php selected($filters['date_range'] ?? 'all', 'all'); ?>> All Time</option>
                                <option value="today" <?php selected($filters['date_range'] ?? '', 'today'); ?>>Today</option>
                                <option value="yesterday" <?php selected($filters['date_range'] ?? '', 'yesterday'); ?>>Yesterday</option>
                                <option value="this_week" <?php selected($filters['date_range'] ?? '', 'this_week'); ?>>This week</option>
                                <option value="this_month" <?php selected($filters['date_range'] ?? '', 'this_month'); ?>>This month</option>
                            </select>
                            
                            <select name="status" class="sync-filter-select">
                                <option value="" <?php selected($filters['status'] ?? '', ''); ?>>Status Filter</option>
                                <option value="published" <?php selected($filters['status'] ?? '', 'published'); ?>>Published</option>
                                <option value="draft" <?php selected($filters['status'] ?? '', 'draft'); ?>>Draft</option>
                            </select>
                        </form>
                    </div>
                </div>
                
                <!-- Sync History List -->
                <div class="sync-log-list">
                    <?php if (empty($sync_records)): ?>
                        <div class="sync-log-empty">
                            <div class="sync-log-empty-icon">📄</div>
                            <h3>No sync records found</h3>
                            <p>Sync records will appear here when content/pages receive new updates.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($sync_records as $record): ?>
                            <div class="sync-log-item">
                                <div class="sync-log-icon">
                                    <div class="sync-icon-circle">
                                        <span class="sync-icon">📄</span>
                                    </div>
                                </div>
                                
                                <div class="sync-log-content">
                                    <div class="sync-log-title"><?php echo esc_html($record->title); ?>
                                    <?php if (!empty($record->url)): ?>
                                        <a href="<?php echo esc_url($record->url); ?>" target="_blank" rel="noopener" title="Open URL" style="margin-left:8px; text-decoration:none;">🔗</a>
                                    <?php endif; ?>
                                    </div>
                                    <div class="sync-log-meta">
                                        <?php echo $this->time_elapsed_string($record->created_at); ?>
                                    </div>
                                </div>
                                
                                <div class="sync-log-status">
                                    <?php if ($record->status === 'published' || $record->status === 'publish'): ?>
                                        <span class="sync-status-badge sync-status-published">
                                            <span class="sync-status-icon">✓</span>
                                            Published
                                        </span>
                                    <?php else: ?>
                                        <span class="sync-status-badge sync-status-draft">
                                            <span class="sync-status-icon">i</span>
                                            Draft
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="sync-log-pagination">
                        <div class="sync-log-pagination-info">
                            Total records: <?php echo $total_records; ?> | Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $per_page, $total_records); ?>
                        </div>
                        
                        <div class="sync-log-pagination-controls">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo esc_attr($_GET['page']); ?>&paged=<?php echo $page - 1; ?><?php echo $this->build_filter_query_string($filters); ?>" class="sync-pagination-btn">‹</a>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <a href="?page=<?php echo esc_attr($_GET['page']); ?>&paged=<?php echo $i; ?><?php echo $this->build_filter_query_string($filters); ?>" 
                                   class="sync-pagination-btn <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo esc_attr($_GET['page']); ?>&paged=<?php echo $page + 1; ?><?php echo $this->build_filter_query_string($filters); ?>" class="sync-pagination-btn">›</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function creat_error_Logs_List()
    {
        // printf('<h1> Error Logs </h1>');
        // $error_log = new Metasync_Error_Logs_Table($this->data_error_log_list);
        // $error_log->create_admin_error_log_list_interface();
    }

    public function create_admin_heartbeat_error_logs_page()
    {
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">
        
        <?php $this->admin->render_plugin_header('HeartBeat Error Logs'); ?>
        
        <?php $this->admin->render_navigation_menu('heartbeat-error-logs'); ?>
            
            <div class="dashboard-card">
                <h2>💓 HeartBeat Error Logs</h2>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Monitor WordPress heartbeat errors to identify connectivity issues and system problems.</p>
                <?php
        $heartbeat_errors = new Metasync_HeartBeat_Error_Monitor($this->admin->db_heartbeat_errors);
        $heartbeat_errors->create_admin_heartbeat_errors_interface();
                ?>
            </div>
        </div>
        <?php
    }

    public function create_admin_bot_statistics_page()
    {
        require_once plugin_dir_path(dirname(__FILE__)) . 'views/metasync-otto-bot-statistics.php';
    }

    // ------------------------------------------------------------------
    //  Private helpers (copied from Metasync_Admin, used only by pages above)
    // ------------------------------------------------------------------

    private function time_elapsed_string($datetime, $full = false)
    {
        if(empty($datetime)){
            return "";
        }
        $now = new DateTime;
        $ago = new DateTime($datetime);

        $diff = $now->diff($ago);

        $string = [
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        ];

        foreach ($string as $k => &$v) {
            if (isset($diff->$k) && $diff->$k) {
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }
        if (!$full) $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ago' : 'just now';
    }

    private function build_filter_query_string($filters)
    {
        $query_parts = [];
        foreach ($filters as $key => $value) {
            if (!empty($value)) {
                $query_parts[] = $key . '=' . urlencode($value);
            }
        }
        return !empty($query_parts) ? '&' . implode('&', $query_parts) : '';
    }

    private function handle_sync_log_ajax()
    {
        wp_die();
    }
}
