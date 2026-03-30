<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings field callbacks and accordion/section helpers extracted from Metasync_Admin.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */
class Metasync_Settings_Fields {

    private static $instance = null;

    /**
     * Reference to the admin instance for callbacks to non-extracted admin methods.
     *
     * @var Metasync_Admin|null
     */
    private $admin_instance = null;

    private function __construct() {}

    /**
     * @return self
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inject the admin instance so callbacks to non-extracted methods still work.
     *
     * @param Metasync_Admin $admin
     */
    public function set_admin_instance($admin) {
        $this->admin_instance = $admin;
    }

    // ────────────────────────────────────────────────────────────────
    //  Accordion / Section helpers
    // ────────────────────────────────────────────────────────────────

    public function get_accordion_sections_config() {
        return array(
            'connection' => array(
                'title' => 'Connection & Authentication',
                'description' => 'Manage your API connection and authentication settings',
                'icon' => '🔐',
                'priority' => 10,
                'default_open' => true,
                'fields' => array(
                    'searchatlas_api_key',
                    'apikey'
                )
            ),
            'otto_ssr' => array(
                'title' => Metasync::get_whitelabel_otto_name() . ' Server-Side Rendering',
                'description' => 'Configure ' . Metasync::get_whitelabel_otto_name() . ' rendering and display options',
                'icon' => '🚀',
                'priority' => 20,
                'default_open' => false, 
                'fields' => array(
                    'otto_pixel_uuid',
                    'otto_disable_on_loggedin',
                    'otto_disable_preview_button',
                    'otto_wp_rocket_compat',
                )
            ),
            'edge_cache' => array(
                'title' => 'Edge Cache / CDN',
                'description' => 'Configure CDN credentials for cache-tag purging',
                'icon' => '🌐',
                'priority' => 22,
                'default_open' => false,
                'render_callback' => array('Metasync_Edge_Cache_Settings', 'render'),
            ),
            'bot_detection' => array(
                'title' => 'Bot Detection & Filtering',
                'description' => 'Manage bot traffic and reduce unnecessary API calls',
                'icon' => '🤖',
                'priority' => 25,
                'default_open' => false,
                'fields' => array(
                    'otto_disable_for_bots',
                    'otto_bot_whitelist',
                    'otto_bot_blacklist',
                    'otto_bot_statistics_link'
                )
            ),
            'editor_settings' => array(
                'title' => 'Post/Page Editor Settings',
                'description' => 'Customize meta boxes and editor functionality',
                'icon' => '✏️',
                'priority' => 40,
                'default_open' => false,
                'fields' => array(
                    'disable_common_robots_metabox',
                    'disable_advance_robots_metabox',
                    'disable_redirection_metabox',
                    'disable_canonical_metabox',
                    'disable_social_opengraph_metabox',
                    'disable_schema_markup_metabox',
                    'open_external_links'
                )
            ),
            'user_management' => array(
                'title' => 'User Management for Content',
                'description' => 'Configure which users are allowed to be authors of content synced.',
                'icon' => '👥',
                'priority' => 50,
                'default_open' => false,
                'fields' => array(
                    'content_genius_sync_roles'
                )
            ),
            'advanced' => array(
                'title' => 'Plugin Settings',
                'description' => 'System configuration and maintenance options',
                'icon' => '⚙️',
                'priority' => 60,
                'default_open' => false,
                'fields' => array(
                    'permalink_structure',
                    'hide_dashboard_framework',
                    'show_admin_bar_status',
                    'enable_auto_updates',
                    'import_external_data'
                )
            )
        );
    }

    public function get_advanced_accordion_config() {
        $config = array();
        
        $user = wp_get_current_user();
        if (in_array('administrator', (array) $user->roles)) {
            $config['plugin_access'] = array(
                'title' => 'User Roles with Plugin Access',
                'description' => 'Control which user roles can see and access this plugin',
                'icon' => '🔐',
                'priority' => 5,
                'default_open' => false,
                'render_callback' => array($this, 'render_plugin_access_roles_section')
            );
        }

        $config['debug_mode'] = array(
            'title' => 'Debug Mode',
            'description' => 'Manage debug mode with automatic disable and safety limits',
            'icon' => '🐛',
            'priority' => 8,
            'default_open' => false,
            'render_callback' => array($this->admin_instance, 'render_debug_mode_section')
        );

        $config['error_logs'] = array(
            'title' => 'Error Logs',
            'description' => 'View and manage error logs to troubleshoot issues',
            'icon' => '⚠️',
            'priority' => 10,
            'default_open' => true,
            'render_callback' => array($this->admin_instance, 'render_error_log_content')
        );
        
        $config['execution_settings'] = array(
            'title' => 'Execution Settings',
            'description' => 'Configure resource limits and execution parameters',
            'icon' => '⚡',
            'priority' => 15,
            'default_open' => false,
            'render_callback' => array($this, 'render_execution_settings_section')
        );
        
        $config['otto_cache'] = array(
            'title' => 'Cache Management',
            'description' => 'Manage ' . Metasync::get_whitelabel_otto_name() . ' cache and clear all cache plugins',
            'icon' => '🗄️',
            'priority' => 20,
            'default_open' => false,
            'render_callback' => array($this->admin_instance, 'render_otto_cache_management')
        );
        
        $config['db_cleanup'] = array(
            'title' => 'Database Cleanup',
            'description' => 'Remove orphaned data and schedule automated weekly cleanup',
            'icon' => '🗃️',
            'priority' => 25,
            'default_open' => false,
            'render_callback' => array($this->admin_instance, 'render_db_cleanup_section')
        );

        $config['reset_settings'] = array(
            'title' => 'Reset Plugin Settings',
            'description' => 'Reset all plugin settings to default values',
            'icon' => '🔄',
            'priority' => 30,
            'default_open' => false,
            'render_callback' => array($this, 'render_reset_settings_section')
        );
        
        return $config;
    }

    public function render_advanced_accordion() {
        $sections_config = $this->get_advanced_accordion_config();

        uasort($sections_config, function($a, $b) {
            return $a['priority'] - $b['priority'];
        });

        echo '<div class="metasync-settings-accordion">';

        foreach ($sections_config as $section_key => $section_data) {
            $section_id = 'metasync-advanced-section-' . $section_key;
            $is_open = $section_data['default_open'];
            $aria_expanded = $is_open ? 'true' : 'false';
            $content_state = $is_open ? 'open' : 'closed';

            echo '<div class="metasync-accordion-section" data-section="' . esc_attr($section_key) . '">';

            echo '<div class="metasync-accordion-header" role="button" tabindex="0" aria-expanded="' . $aria_expanded . '" aria-controls="' . $section_id . '">';
            echo '<div class="metasync-accordion-title">';
            echo '<span class="metasync-accordion-icon">' . esc_html($section_data['icon']) . '</span>';
            echo '<div class="metasync-accordion-text">';
            echo '<h3>' . esc_html($section_data['title']) . '</h3>';
            echo '<p class="metasync-accordion-description">' . esc_html($section_data['description']) . '</p>';
            echo '</div>';
            echo '</div>';
            echo '<button type="button" class="metasync-accordion-toggle" aria-label="Toggle section">';
            echo '<span class="toggle-icon">▼</span>';
            echo '</button>';
            echo '</div>';

            echo '<div class="metasync-accordion-content" id="' . $section_id . '" data-state="' . $content_state . '">';

            if (isset($section_data['render_callback']) && is_callable($section_data['render_callback'])) {
                call_user_func($section_data['render_callback']);
            }

            echo '</div>';
            echo '</div>';
        }

        echo '</div>';
    }

    public function render_reset_settings_section() {
        ?>
        <div style="background: var(--dashboard-card-bg); padding: 20px; border-radius: 8px;">
            <div style="background: rgba(255, 243, 205, 0.1); border: 1px solid rgba(255, 234, 167, 0.3); border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                <h4 style="color: var(--dashboard-warning, #f59e0b); margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px;">
                    <span>⚠️</span>
                    <span>Important Warning</span>
                </h4>
                <p style="color: var(--dashboard-text-secondary); margin: 0 0 12px 0;">This action will permanently delete:</p>
                <ul style="color: var(--dashboard-text-secondary); margin: 0 0 12px 20px; line-height: 1.8;">
                    <li>All API keys and authentication tokens</li>
                    <li>White label branding settings</li>
                    <li>Plugin configuration and preferences</li>
                    <li>Instant indexing settings</li>
                    <li>All cached data and crawl information</li>
                </ul>
                <p style="color: var(--dashboard-warning, #f59e0b); margin: 0; font-weight: 600;">You will need to reconfigure the plugin completely after this reset.</p>
            </div>
            <form method="post" action="" onsubmit="return confirmClearSettings(event)">
                <?php wp_nonce_field('metasync_clear_all_settings_nonce', 'clear_all_settings_nonce'); ?>
                <input type="hidden" name="clear_all_settings" value="yes" />
                <button type="submit" class="metasync-btn-danger" style="background: var(--dashboard-error, #ef4444); color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(239, 68, 68, 0.2);" onmouseover="this.style.background='#dc2626'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(239, 68, 68, 0.3)';" onmouseout="this.style.background='var(--dashboard-error, #ef4444)'; this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(239, 68, 68, 0.2)';">
                    🗑️ Clear All Settings
                </button>
            </form>
        </div>
        <script>
        function confirmClearSettings(event) {
            event.preventDefault();

            var firstConfirm = confirm("⚠️ WARNING: This will permanently delete ALL plugin settings!\n\nThis action cannot be undone. Are you sure you want to continue?");
            if (!firstConfirm) {
                return false;
            }

            var secondConfirm = confirm("🚨 FINAL WARNING 🚨\n\nThis will delete:\n• All API keys and authentication tokens\n• White label branding settings\n• Plugin configuration and preferences\n• Instant indexing settings\n• All cached data\n\nYou will need to reconfigure the entire plugin from scratch.\n\nType 'DELETE' in the next prompt to confirm.");
            if (!secondConfirm) {
                return false;
            }

            var typeConfirm = prompt("Type 'DELETE' (in capital letters) to confirm you want to permanently clear all settings:");
            if (typeConfirm !== 'DELETE') {
                alert("Settings reset cancelled. Type 'DELETE' exactly to confirm.");
                return false;
            }

            event.target.submit();
            return false;
        }
        </script>
        <?php
    }

    public function render_google_index_section() {
        if (!function_exists('google_index_direct')) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'google-index/google-index-init.php';
        }

        $google_index = google_index_direct();
        $service_info = $google_index->get_service_account_info();
        $is_configured = !isset($service_info['error']);

        include plugin_dir_path(dirname(__FILE__)) . 'views/metasync-google-index-api-settings.php';
    }

    public function render_bing_index_section() {
        require_once plugin_dir_path(dirname(__FILE__)) . 'bing-index/class-metasync-bing-instant-index.php';
        $bing_index = new Metasync_Bing_Instant_Index();
        $api_key = $bing_index->get_setting('api_key');
        $endpoint = $bing_index->get_setting('endpoint', 'indexnow');
        $post_types_settings = $bing_index->get_setting('post_types', []);
        $is_configured = !empty($api_key);

        $post_types = get_post_types(['public' => true], 'objects');

        ?>
        <div style="padding: 20px;">
            <!-- About IndexNow (Collapsible) -->
            <details style="margin-bottom: 20px;">
                <summary style="cursor: pointer; font-weight: 600; color: var(--dashboard-text-primary);">
                    ℹ️ About IndexNow Protocol
                </summary>
                <div style="margin-top: 10px; padding: 15px; background: rgba(255, 255, 255, 0.03); border-radius: 4px;">
                    <p style="color: var(--dashboard-text-secondary); margin: 0 0 10px 0;">
                        IndexNow is a simple protocol that allows websites to instantly notify search engines about URL changes.
                        It's supported by Bing, Yandex, Naver, Seznam, and other search engines.
                    </p>
                    <ul style="color: var(--dashboard-text-secondary); margin: 0 0 10px 20px;">
                        <li>✓ Instant notification to multiple search engines</li>
                        <li>✓ Simple API key authentication (no OAuth required)</li>
                        <li>✓ Supports batch URL submissions (up to 10,000 URLs)</li>
                        <li>✓ Free to use with no quotas</li>
                        <li>✓ Fire-and-forget protocol (no status checking needed)</li>
                    </ul>
                    <p style="color: var(--dashboard-text-secondary); margin: 0;">
                        <strong>Resources:</strong>
                        <a href="https://www.indexnow.org/documentation" target="_blank" style="color: var(--dashboard-accent);">IndexNow Documentation</a> |
                        <a href="https://www.bing.com/webmasters" target="_blank" style="color: var(--dashboard-accent);">Bing Webmaster Tools</a>
                    </p>
                </div>
            </details>

            <!-- Configuration Status -->
            <div style="margin-bottom: 20px;">
                <?php if ($is_configured): ?>
                    <div style="padding: 12px; background: rgba(76, 175, 80, 0.1); border-left: 4px solid #4caf50; border-radius: 4px;">
                        <p style="margin: 0; color: var(--dashboard-text-primary);">
                            <strong style="color: #4caf50;">✅ IndexNow API Configured</strong><br>
                            <span style="color: var(--dashboard-text-secondary); font-size: 0.95em;">
                                API Key: <code style="padding: 2px 6px; background: rgba(0,0,0,0.1); border-radius: 3px;"><?php echo esc_html(substr($api_key, 0, 8) . '...' . substr($api_key, -8)); ?></code>
                            </span>
                        </p>
                    </div>
                <?php else: ?>
                    <div style="padding: 12px; background: rgba(255, 152, 0, 0.1); border-left: 4px solid #ff9800; border-radius: 4px;">
                        <p style="margin: 0; color: var(--dashboard-text-primary);">
                            <strong style="color: #ff9800;">⚠️ Configuration Required</strong><br>
                            <span style="color: var(--dashboard-text-secondary); font-size: 0.95em;">
                                Configure your IndexNow API key below to enable instant indexing with Bing and other search engines.
                            </span>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Settings Fields -->
            <table class="form-table" style="margin-top: 0;">
                <tr>
                    <th scope="row" style="width: 200px; padding-top: 15px;">
                        <label for="metasync_bing_api_key_inline">IndexNow API Key <span style="color: #d63638;">*</span></label>
                    </th>
                    <td style="padding-top: 15px;">
                        <input type="text"
                               name="metasync_bing_api_key_inline"
                               id="metasync_bing_api_key_inline"
                               class="large-text"
                               value="<?php echo esc_attr($api_key); ?>"
                               placeholder="Enter your IndexNow API key (32+ character hexadecimal string)" />
                        <br>
                        <button type="button"
                                id="generate-bing-api-key-inline"
                                class="button button-secondary"
                                style="margin-top: 8px;">
                            🔑 Generate Random API Key
                        </button>
                        <p class="description" style="margin-top: 8px;">
                            Your IndexNow API key is required to submit URLs for instant indexing. You can generate a random key or use your own (32+ character hexadecimal string).
                            After saving, a verification file will be automatically created at your site root.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row" style="padding-top: 15px;">
                        <label>API Endpoint</label>
                    </th>
                    <td style="padding-top: 15px;">
                        <fieldset>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="radio"
                                       name="metasync_bing_endpoint_inline"
                                       value="indexnow"
                                       <?php checked($endpoint, 'indexnow'); ?>>
                                <strong>IndexNow.org</strong> (Recommended)
                                <span style="color: var(--dashboard-text-secondary); font-size: 0.9em; display: block; margin-left: 24px;">
                                    Notifies Bing, Yandex, Naver, Seznam, and other participating search engines
                                </span>
                            </label>
                            <label style="display: block;">
                                <input type="radio"
                                       name="metasync_bing_endpoint_inline"
                                       value="bing"
                                       <?php checked($endpoint, 'bing'); ?>>
                                <strong>Bing.com</strong> (Bing-specific)
                                <span style="color: var(--dashboard-text-secondary); font-size: 0.9em; display: block; margin-left: 24px;">
                                    Direct submission to Bing only
                                </span>
                            </label>
                        </fieldset>
                        <p class="description">
                            Select which endpoint to use for submitting URLs. The IndexNow.org endpoint is recommended as it notifies multiple search engines at once.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row" style="padding-top: 15px;">
                        <label>Auto-Submit Post Types</label>
                    </th>
                    <td style="padding-top: 15px;">
                        <fieldset>
                            <?php foreach ($post_types as $post_type): ?>
                                <label style="display: inline-block; margin-right: 20px; margin-bottom: 8px;">
                                    <input type="checkbox"
                                           name="metasync_bing_post_types_inline[<?php echo esc_attr($post_type->name); ?>]"
                                           value="<?php echo esc_attr($post_type->name); ?>"
                                           <?php checked(in_array($post_type->name, $post_types_settings, true)); ?>>
                                    <?php echo esc_html($post_type->label); ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description">
                            Selected post types will be automatically submitted to IndexNow when published or updated.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row" style="padding-top: 15px;">
                        <label>Plugin Source Control</label>
                    </th>
                    <td style="padding-top: 15px;">
                        <fieldset>
                            <label style="display: flex; align-items: flex-start; gap: 8px;">
                                <input type="checkbox"
                                       name="metasync_bing_disable_other_plugins_inline"
                                       value="1"
                                       <?php checked($bing_index->get_setting('disable_other_plugins', true), true); ?>
                                       style="margin-top: 2px;">
                                <span>
                                    <strong>Disable other IndexNow plugins</strong>
                                    <span style="display: block; color: var(--dashboard-text-secondary); font-size: 0.9em; margin-top: 4px; font-weight: normal;">
                                        When enabled, Our plugin will be the exclusive source for IndexNow submissions in Bing Webmaster Tools.
                                        This disables IndexNow features in Yoast SEO, Rank Math, and other competing plugins to prevent duplicate submissions.
                                    </span>
                                </span>
                            </label>
                        </fieldset>
                    </td>
                </tr>
            </table>

        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#generate-bing-api-key-inline').on('click', function() {
                const array = new Uint8Array(16);
                crypto.getRandomValues(array);
                const hexString = Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('');
                $('#metasync_bing_api_key_inline').val(hexString);

                $('#enable_binginstantindex').prop('checked', true);

                $('#metasync_bing_api_key_inline').trigger('change');
            });

            $('#metasync_bing_api_key_inline').on('input', function() {
                const apiKey = $(this).val().trim();
                if (apiKey.length >= 8) {
                    $('#enable_binginstantindex').prop('checked', true);
                }
            });

            function toggleBingConfig() {
                const isEnabled = $('#enable_binginstantindex').is(':checked');
                const $apiConfig = $('#enable_binginstantindex').closest('tr').nextAll('tr').first();
                if (isEnabled) {
                    $apiConfig.show();
                } else {
                    $apiConfig.hide();
                }
            }

            toggleBingConfig();

            $('#enable_binginstantindex').on('change', toggleBingConfig);
        });
        </script>

        <style>
        .form-table th {
            color: var(--dashboard-text-primary);
        }

        .form-table td {
            color: var(--dashboard-text-secondary);
        }

        .form-table code {
            padding: 2px 6px;
            background: rgba(0, 0, 0, 0.1);
            border-radius: 3px;
            font-size: 0.9em;
        }

        details summary {
            transition: color 0.2s;
        }

        details summary:hover {
            color: var(--dashboard-accent);
        }

        details[open] summary {
            margin-bottom: 10px;
        }
        </style>
        <?php
    }

    public function render_plugin_access_roles_section() {
        $general_options = Metasync::get_option('general');
        
        $setting_exists = isset($general_options['plugin_access_roles']);
        $selected_roles = $general_options['plugin_access_roles'] ?? null;
        
        if (is_string($selected_roles)) {
            $selected_roles = array($selected_roles);
        } elseif (!is_array($selected_roles)) {
            $selected_roles = null;
        }
        
        if (!function_exists('wp_roles')) {
            require_once(ABSPATH . 'wp-admin/includes/user.php');
        }
        global $wp_roles;
        
        if (!isset($wp_roles) || !is_object($wp_roles)) {
            $wp_roles = new WP_Roles();
        }
        
        $all_roles = $wp_roles->roles;
        
        $all_roles_checked = is_array($selected_roles) && in_array('all', $selected_roles);
        ?>
        <div style="background: var(--dashboard-card-bg); padding: 20px; border-radius: 8px;">
            <p style="color: var(--dashboard-text-secondary); margin: 0 0 20px 0;">
                Select which user roles can see and access this plugin's menu, settings, and options in the WordPress admin area.
            </p>
            
            <form method="post" action="<?php echo admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '&tab=advanced'); ?>" id="plugin-access-roles-form">
                <?php wp_nonce_field('metasync_plugin_access_roles_nonce', 'plugin_access_roles_nonce'); ?>
                <input type="hidden" name="save_plugin_access_roles" value="yes" />
                
                <div class="metasync-role-selector-container" style="background: var(--dashboard-card-bg-alt, rgba(255,255,255,0.05)); border: 1px solid var(--dashboard-border); border-radius: 8px; padding: 16px; max-height: 300px; overflow-y: auto;">
                    
                    <!-- All Roles Option -->
                    <label class="metasync-role-option-all" style="display: flex; align-items: center; gap: 10px; padding: 12px; cursor: pointer; border-radius: 6px; transition: background 0.2s ease;">
                        <input type="checkbox" 
                               id="plugin-access-all-roles"
                               name="plugin_access_roles[]" 
                               value="all" 
                               style="width: 18px; height: 18px; cursor: pointer;"
                               <?php checked($all_roles_checked, true); ?> />
                        <strong style="color: var(--dashboard-text-primary);">All Roles</strong>
                    </label>
                    
                    <!-- Divider -->
                    <hr style="border: none; border-top: 1px solid var(--dashboard-border); margin: 12px 0;">
                    
                    <?php
                    if (!empty($all_roles) && is_array($all_roles)) {
                        foreach ($all_roles as $role_key => $role_details) {
                            if ($role_key === 'administrator') {
                                continue;
                            }
                            
                            if (!isset($role_details['name'])) {
                                continue;
                            }
                            
                            $is_checked = is_array($selected_roles) && in_array($role_key, $selected_roles);
                            $role_name = translate_user_role($role_details['name']);
                            ?>
                            <label class="metasync-role-option" style="display: flex; align-items: center; gap: 10px; padding: 10px 12px; cursor: pointer; border-radius: 6px; transition: background 0.2s ease;">
                                <input type="checkbox" 
                                       class="plugin-access-individual-role"
                                       name="plugin_access_roles[]" 
                                       value="<?php echo esc_attr($role_key); ?>" 
                                       style="width: 18px; height: 18px; cursor: pointer;"
                                       <?php checked($is_checked, true); ?> />
                                <span style="color: var(--dashboard-text-primary);"><?php echo esc_html($role_name); ?></span>
                            </label>
                            <?php
                        }
                    }
                    ?>
                    
                </div>
                
                <!-- Description -->
                <div style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 8px; padding: 16px; margin: 20px 0;">
                    <p style="color: var(--dashboard-text-secondary); margin: 0; font-size: 13px;">
                        <strong style="color: var(--dashboard-info, #3b82f6);">ℹ️ How it works:</strong><br>
                        <strong>Administrators always have access</strong> to this plugin regardless of settings.<br>
                        <strong>By default</strong>, only Administrators can access the plugin (no roles selected).<br>
                        If <strong>"All Roles"</strong> is selected, all users will see the plugin.<br>
                        Users with unchecked roles will not see the plugin in their WordPress admin area.
                    </p>
                </div>
                
                <button type="submit" class="metasync-btn-primary" style="background: var(--dashboard-gradient-primary); color: #ffffff; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0, 0, 0, 0.15)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0, 0, 0, 0.1)';">
                    💾 Save Access Settings
                </button>
            </form>
        </div>
        <script>
        jQuery(document).ready(function($) {
            var $allRolesCheckbox = $('#plugin-access-all-roles');
            var $individualRoles = $('.plugin-access-individual-role');
            
            $allRolesCheckbox.on('change', function() {
                if ($(this).is(':checked')) {
                    $individualRoles.prop('checked', false);
                }
            });
            
            $individualRoles.on('change', function() {
                if ($(this).is(':checked')) {
                    $allRolesCheckbox.prop('checked', false);
                }
            });
        });
        </script>
        <?php
    }

    public function get_default_execution_settings() {
        return array(
            'max_execution_time' => 30,
            'max_memory_limit' => 256,
            'log_batch_size' => 1000,
            'action_scheduler_batches' => 1,
            'otto_rate_limit' => 10,
            'queue_cleanup_days' => 31
        );
    }

    public function get_execution_setting($key, $default = null) {
        $settings = get_option('metasync_execution_settings', array());
        $defaults = $this->get_default_execution_settings();
        
        if (empty($settings)) {
            return isset($defaults[$key]) ? $defaults[$key] : $default;
        }
        
        return isset($settings[$key]) ? $settings[$key] : (isset($defaults[$key]) ? $defaults[$key] : $default);
    }

    public function get_all_execution_settings() {
        $settings = get_option('metasync_execution_settings', array());
        $defaults = $this->get_default_execution_settings();
        
        return wp_parse_args($settings, $defaults);
    }

    public function can_change_memory_limit() {
        $current_limit = ini_get('memory_limit');
        
        $test_result = @ini_set('memory_limit', $current_limit);
        
        return $test_result !== false;
    }

    public function get_server_limits() {
        $max_execution_time = ini_get('max_execution_time');
        $memory_limit = ini_get('memory_limit');
        
        $memory_limit_mb = $this->parse_memory_limit_to_mb($memory_limit);
        
        $wp_memory_limit = null;
        if (defined('WP_MAX_MEMORY_LIMIT')) {
            $wp_memory_limit = WP_MAX_MEMORY_LIMIT;
        } elseif (defined('WP_MEMORY_LIMIT')) {
            $wp_memory_limit = WP_MEMORY_LIMIT;
        }
        $wp_memory_limit_mb = $wp_memory_limit ? $this->parse_memory_limit_to_mb($wp_memory_limit) : null;
        
        $actual_php_limit_mb = $memory_limit_mb;
        if ($wp_memory_limit_mb && $wp_memory_limit_mb >= 256) {
            $actual_php_limit_mb = 128;
        }
        
        $can_change_memory = $this->can_change_memory_limit();
        
        $memory_limit_display = $actual_php_limit_mb == -1 ? 'Unlimited' : $actual_php_limit_mb . ' MB';
        
        return array(
            'max_execution_time' => $max_execution_time == -1 ? 'Unlimited' : $max_execution_time . ' seconds',
            'memory_limit' => $memory_limit_display,
            'max_execution_time_raw' => $max_execution_time,
            'memory_limit_raw' => $actual_php_limit_mb,
            'can_change_memory' => $can_change_memory
        );
    }

    public function apply_memory_limit() {
        if (!$this->can_change_memory_limit()) {
            return false;
        }
        
        $memory_limit_mb = $this->get_execution_setting('max_memory_limit');
        
        $result = @ini_set('memory_limit', $memory_limit_mb . 'M');
        
        return $result !== false;
    }

    public function parse_memory_limit_to_mb($memory_limit) {
        if ($memory_limit == -1 || $memory_limit == '-1') {
            return -1;
        }
        
        $memory_limit = trim($memory_limit);
        $last = strtolower(substr($memory_limit, -1));
        $value = (int) $memory_limit;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
                break;
            case 'm':
                break;
            case 'k':
                $value /= 1024;
                break;
        }
        
        return $value;
    }

    public function render_execution_settings_section() {
        $settings = $this->get_all_execution_settings();
        $server_limits = $this->get_server_limits();
        
        $warnings = array();
        if ($server_limits['max_execution_time_raw'] != -1 && $settings['max_execution_time'] > $server_limits['max_execution_time_raw']) {
            $warnings['max_execution_time'] = true;
        }
        if ($server_limits['memory_limit_raw'] != -1 && $settings['max_memory_limit'] > $server_limits['memory_limit_raw']) {
            $warnings['max_memory_limit'] = true;
        }
        ?>
        <div style="background: var(--dashboard-card-bg); padding: 20px; border-radius: 8px;">
            <p style="color: var(--dashboard-text-secondary); margin: 0 0 20px 0;">
                Configure resource limits and execution parameters for plugin operations. Adjust these settings based on your server capabilities.
            </p>
            
            <form id="metasync-execution-settings-form" method="post">
                <?php wp_nonce_field('metasync_execution_settings_nonce', 'execution_settings_nonce'); ?>
                
                <!-- Execution & Memory Section -->
                <div style="background: var(--dashboard-card-bg-alt, rgba(255,255,255,0.05)); border: 1px solid var(--dashboard-border); border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                    <h3 style="color: var(--dashboard-text-primary); margin: 0 0 16px 0; font-size: 16px; font-weight: 600;">Execution & Memory</h3>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; color: var(--dashboard-text-primary); margin-bottom: 8px; font-weight: 500;">
                            Max Execution Time:
                        </label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" 
                                   id="max_execution_time" 
                                   name="max_execution_time" 
                                   value="<?php echo esc_attr($settings['max_execution_time']); ?>" 
                                   min="1" 
                                   max="300" 
                                   required
                                   style="width: 100px; padding: 8px; border: 1px solid var(--dashboard-border); border-radius: 6px; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary);" />
                            <span style="color: var(--dashboard-text-secondary);">seconds</span>
                        </div>
                        <p style="color: var(--dashboard-text-secondary); font-size: 12px; margin: 4px 0 0 0;">
                            Server Limit: <?php echo esc_html($server_limits['max_execution_time']); ?>
                        </p>
                        <p id="max_execution_time_warning" style="display: none; color: #f59e0b; font-size: 12px; margin: 4px 0 0 0;">
                            <span style="margin-right: 4px;">⚠️</span>
                            <span>Configured value exceeds server limit</span>
                        </p>
                        <?php if (isset($warnings['max_execution_time'])): ?>
                        <script>
                        jQuery(document).ready(function($) {
                            $('#max_execution_time_warning').show();
                        });
                        </script>
                        <?php endif; ?>
                    </div>
                    
                    <div>
                        <label style="display: block; color: var(--dashboard-text-primary); margin-bottom: 8px; font-weight: 500;">
                            Max Memory Limit:
                        </label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" 
                                   id="max_memory_limit" 
                                   name="max_memory_limit" 
                                   value="<?php echo esc_attr($settings['max_memory_limit']); ?>" 
                                   min="64" 
                                   max="512" 
                                   <?php if (!$server_limits['can_change_memory']): ?>
                                   readonly
                                   disabled
                                   <?php endif; ?>
                                   required
                                   style="width: 100px; padding: 8px; border: 1px solid var(--dashboard-border); border-radius: 6px; background: <?php echo $server_limits['can_change_memory'] ? 'var(--dashboard-card-bg)' : 'rgba(128, 128, 128, 0.1)'; ?>; color: <?php echo $server_limits['can_change_memory'] ? 'var(--dashboard-text-primary)' : 'var(--dashboard-text-secondary)'; ?>; cursor: <?php echo $server_limits['can_change_memory'] ? 'text' : 'not-allowed'; ?>;" />
                            <span style="color: var(--dashboard-text-secondary);">MB</span>
                        </div>
                        <p style="color: var(--dashboard-text-secondary); font-size: 12px; margin: 4px 0 0 0;">
                            Server Limit: <?php echo esc_html($server_limits['memory_limit']); ?>
                        </p>
                        <?php if (!$server_limits['can_change_memory']): ?>
                        <p style="color: #f59e0b; font-size: 12px; margin: 4px 0 0 0; display: flex; align-items: center; gap: 4px;">
                            <span>🔒</span>
                            <span>Server does not allow changing memory limit. This setting is read-only.</span>
                        </p>
                        <?php else: ?>
                        <p id="max_memory_limit_warning" style="display: none; color: #f59e0b; font-size: 12px; margin: 4px 0 0 0;">
                            <span style="margin-right: 4px;">⚠️</span>
                            <span>Configured value exceeds server limit</span>
                        </p>
                        <?php if (isset($warnings['max_memory_limit'])): ?>
                        <script>
                        jQuery(document).ready(function($) {
                            $('#max_memory_limit_warning').show();
                        });
                        </script>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Batch Processing Section -->
                <div style="background: var(--dashboard-card-bg-alt, rgba(255,255,255,0.05)); border: 1px solid var(--dashboard-border); border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                    <h3 style="color: var(--dashboard-text-primary); margin: 0 0 16px 0; font-size: 16px; font-weight: 600;">Batch Processing</h3>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; color: var(--dashboard-text-primary); margin-bottom: 8px; font-weight: 500;">
                            Log Processing Batch Size:
                        </label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" 
                                   id="log_batch_size" 
                                   name="log_batch_size" 
                                   value="<?php echo esc_attr($settings['log_batch_size']); ?>" 
                                   min="100" 
                                   max="5000" 
                                   required
                                   style="width: 100px; padding: 8px; border: 1px solid var(--dashboard-border); border-radius: 6px; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary);" />
                            <span style="color: var(--dashboard-text-secondary);">lines</span>
                        </div>
                    </div>
                    
                    <div>
                        <label style="display: block; color: var(--dashboard-text-primary); margin-bottom: 8px; font-weight: 500;">
                            Action Scheduler Concurrent Batches:
                        </label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" 
                                   id="action_scheduler_batches" 
                                   name="action_scheduler_batches" 
                                   value="<?php echo esc_attr($settings['action_scheduler_batches']); ?>" 
                                   min="1" 
                                   max="10" 
                                   required
                                   style="width: 100px; padding: 8px; border: 1px solid var(--dashboard-border); border-radius: 6px; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary);" />
                        </div>
                        <p style="color: #f59e0b; font-size: 12px; margin: 4px 0 0 0; display: flex; align-items: center; gap: 4px;">
                            <span>⚠️</span>
                            <span>Higher values increase server load</span>
                        </p>
                    </div>
                </div>
                
                <!-- API Rate Limiting Section -->
                <div style="background: var(--dashboard-card-bg-alt, rgba(255,255,255,0.05)); border: 1px solid var(--dashboard-border); border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                    <h3 style="color: var(--dashboard-text-primary); margin: 0 0 16px 0; font-size: 16px; font-weight: 600;">API Rate Limiting</h3>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; color: var(--dashboard-text-primary); margin-bottom: 8px; font-weight: 500;">
                            OTTO API Calls Per Minute:
                        </label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" 
                                   id="otto_rate_limit" 
                                   name="otto_rate_limit" 
                                   value="<?php echo esc_attr($settings['otto_rate_limit']); ?>" 
                                   min="1" 
                                   max="60" 
                                   required
                                   style="width: 100px; padding: 8px; border: 1px solid var(--dashboard-border); border-radius: 6px; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary);" />
                        </div>
                    </div>
                    
                    <div>
                        <label style="display: block; color: var(--dashboard-text-primary); margin-bottom: 8px; font-weight: 500;">
                            Queue Auto-Cleanup:
                        </label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" 
                                   id="queue_cleanup_days" 
                                   name="queue_cleanup_days" 
                                   value="<?php echo esc_attr($settings['queue_cleanup_days']); ?>" 
                                   min="7" 
                                   max="90" 
                                   required
                                   style="width: 100px; padding: 8px; border: 1px solid var(--dashboard-border); border-radius: 6px; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary);" />
                            <span style="color: var(--dashboard-text-secondary);">days</span>
                        </div>
                    </div>
                </div>
                
                <!-- Save Button -->
                <div style="margin-top: 20px;">
                    <button type="submit" 
                            id="metasync-execution-settings-save-btn"
                            class="button button-primary" 
                            style="padding: 10px 20px; font-size: 14px; font-weight: 500;">
                        <span class="save-text">Save Settings</span>
                        <span class="save-spinner" style="display: none; margin-left: 8px;">⏳</span>
                    </button>
                </div>
                
                <!-- Success/Error Messages -->
                <div id="metasync-execution-settings-message" style="display: none; margin-top: 16px; padding: 12px; border-radius: 6px;"></div>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var $form = $('#metasync-execution-settings-form');
            var $saveBtn = $('#metasync-execution-settings-save-btn');
            var $message = $('#metasync-execution-settings-message');
            
            var serverMaxExecTime = <?php echo $server_limits['max_execution_time_raw'] == -1 ? 'Infinity' : $server_limits['max_execution_time_raw']; ?>;
            var serverMaxMemory = <?php echo $server_limits['memory_limit_raw'] == -1 ? 'Infinity' : $server_limits['memory_limit_raw']; ?>;
            var canChangeMemory = <?php echo $server_limits['can_change_memory'] ? 'true' : 'false'; ?>;
            
            function checkServerLimits() {
                var maxExecTime = parseInt($('#max_execution_time').val()) || 0;
                var maxMemory = parseInt($('#max_memory_limit').val()) || 0;
                
                if (serverMaxExecTime !== Infinity && maxExecTime > serverMaxExecTime) {
                    $('#max_execution_time_warning').show();
                } else {
                    $('#max_execution_time_warning').hide();
                }
                
                if (canChangeMemory && serverMaxMemory !== Infinity && maxMemory > serverMaxMemory) {
                    $('#max_memory_limit_warning').show();
                } else {
                    $('#max_memory_limit_warning').hide();
                }
            }
            
            $('#max_execution_time, #max_memory_limit').on('input change', function() {
                checkServerLimits();
                $(this).css('border-color', 'var(--dashboard-border)');
            });
            
            function highlightInvalidField($field, isValid) {
                if (isValid) {
                    $field.css({
                        'border-color': 'var(--dashboard-border)',
                        'box-shadow': 'none'
                    });
                } else {
                    $field.css({
                        'border-color': '#ef4444',
                        'box-shadow': '0 0 0 3px rgba(239, 68, 68, 0.1)'
                    });
                }
            }
            
            $('#max_execution_time').on('blur', function() {
                var value = parseInt($(this).val()) || 0;
                var isValid = value >= 1 && value <= 300 && (serverMaxExecTime === Infinity || value <= serverMaxExecTime);
                highlightInvalidField($(this), isValid);
            });
            
            $('#max_memory_limit').on('blur', function() {
                if (!canChangeMemory) return;
                var value = parseInt($(this).val()) || 0;
                var isValid = value >= 64 && value <= 512 && (serverMaxMemory === Infinity || value <= serverMaxMemory);
                highlightInvalidField($(this), isValid);
            });
            
            checkServerLimits();
            
            $form.on('submit', function(e) {
                e.preventDefault();
                
                var formData = {
                    action: 'metasync_save_execution_settings',
                    execution_settings_nonce: $('#execution_settings_nonce').val(),
                    max_execution_time: $('#max_execution_time').val(),
                    max_memory_limit: $('#max_memory_limit').val(),
                    log_batch_size: $('#log_batch_size').val(),
                    action_scheduler_batches: $('#action_scheduler_batches').val(),
                    otto_rate_limit: $('#otto_rate_limit').val(),
                    queue_cleanup_days: $('#queue_cleanup_days').val()
                };
                
                $('input[type="number"]').css({
                    'border-color': 'var(--dashboard-border)',
                    'box-shadow': 'none'
                });
                
                var hasError = false;
                var errorField = null;
                
                if (formData.max_execution_time < 1 || formData.max_execution_time > 300) {
                    showMessage('Max Execution Time must be between 1 and 300 seconds.', 'error');
                    highlightInvalidField($('#max_execution_time'), false);
                    errorField = $('#max_execution_time');
                    hasError = true;
                } else if (serverMaxExecTime !== Infinity && formData.max_execution_time > serverMaxExecTime) {
                    showMessage('Max Execution Time exceeds server limit of ' + serverMaxExecTime + ' seconds. Please reduce the value.', 'error');
                    highlightInvalidField($('#max_execution_time'), false);
                    errorField = $('#max_execution_time');
                    hasError = true;
                }
                
                if (canChangeMemory) {
                    if (formData.max_memory_limit < 64 || formData.max_memory_limit > 512) {
                        showMessage('Max Memory Limit must be between 64 and 512 MB.', 'error');
                        highlightInvalidField($('#max_memory_limit'), false);
                        if (!hasError) {
                            errorField = $('#max_memory_limit');
                            hasError = true;
                        }
                    } else if (serverMaxMemory !== Infinity && formData.max_memory_limit > serverMaxMemory) {
                        showMessage('Max Memory Limit exceeds server limit of ' + serverMaxMemory + ' MB. Please reduce the value.', 'error');
                        highlightInvalidField($('#max_memory_limit'), false);
                        if (!hasError) {
                            errorField = $('#max_memory_limit');
                            hasError = true;
                        }
                    }
                }
                
                if (formData.log_batch_size < 100 || formData.log_batch_size > 5000) {
                    showMessage('Log Batch Size must be between 100 and 5000 lines.', 'error');
                    highlightInvalidField($('#log_batch_size'), false);
                    if (!hasError) {
                        errorField = $('#log_batch_size');
                        hasError = true;
                    }
                }
                if (formData.action_scheduler_batches < 1 || formData.action_scheduler_batches > 10) {
                    showMessage('Action Scheduler Batches must be between 1 and 10.', 'error');
                    highlightInvalidField($('#action_scheduler_batches'), false);
                    if (!hasError) {
                        errorField = $('#action_scheduler_batches');
                        hasError = true;
                    }
                }
                if (formData.otto_rate_limit < 1 || formData.otto_rate_limit > 60) {
                    showMessage('OTTO Rate Limit must be between 1 and 60 calls per minute.', 'error');
                    highlightInvalidField($('#otto_rate_limit'), false);
                    if (!hasError) {
                        errorField = $('#otto_rate_limit');
                        hasError = true;
                    }
                }
                if (formData.queue_cleanup_days < 7 || formData.queue_cleanup_days > 90) {
                    showMessage('Queue Cleanup Days must be between 7 and 90 days.', 'error');
                    highlightInvalidField($('#queue_cleanup_days'), false);
                    if (!hasError) {
                        errorField = $('#queue_cleanup_days');
                        hasError = true;
                    }
                }
                
                if (hasError) {
                    if (errorField) {
                        errorField.focus();
                        $('html, body').animate({
                            scrollTop: errorField.offset().top - 100
                        }, 300);
                    }
                    return;
                }
                
                $saveBtn.prop('disabled', true);
                $saveBtn.find('.save-text').text('Saving...');
                $saveBtn.find('.save-spinner').show();
                $message.hide();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    success: function(response) {
                        if (response.success) {
                            showMessage(response.data.message || 'Settings saved successfully!', 'success');
                            $saveBtn.prop('disabled', false);
                            $saveBtn.find('.save-text').text('Save Settings');
                            $saveBtn.find('.save-spinner').hide();
                            setTimeout(function() {
                                checkServerLimits();
                            }, 100);
                            $('html, body').animate({
                                scrollTop: $form.offset().top - 100
                            }, 300);
                        } else {
                            showMessage(response.data.message || 'Error saving settings.', 'error');
                            $saveBtn.prop('disabled', false);
                            $saveBtn.find('.save-text').text('Save Settings');
                            $saveBtn.find('.save-spinner').hide();
                            $('html, body').animate({
                                scrollTop: $message.offset().top - 100
                            }, 300);
                        }
                    },
                    error: function() {
                        showMessage('An error occurred while saving settings. Please try again.', 'error');
                        $saveBtn.prop('disabled', false);
                        $saveBtn.find('.save-text').text('Save Settings');
                        $saveBtn.find('.save-spinner').hide();
                        $('html, body').animate({
                            scrollTop: $message.offset().top - 100
                        }, 300);
                    }
                });
            });
            
            function showMessage(text, type) {
                $message.removeClass('notice-success notice-error')
                        .addClass('notice-' + type)
                        .css({
                            'background': type === 'success' ? 'rgba(34, 197, 94, 0.1)' : 'rgba(239, 68, 68, 0.1)',
                            'border': '1px solid ' + (type === 'success' ? 'rgba(34, 197, 94, 0.3)' : 'rgba(239, 68, 68, 0.3)'),
                            'color': type === 'success' ? '#22c55e' : '#ef4444',
                            'padding': '12px 16px',
                            'border-radius': '6px',
                            'font-size': '14px',
                            'line-height': '1.5',
                            'display': 'block'
                        })
                        .html('<strong style="margin-right: 8px;">' + (type === 'success' ? '✓' : '✗') + '</strong>' + text)
                        .show();
                
                if (type === 'success') {
                    setTimeout(function() {
                        $message.fadeOut(300);
                    }, 5000);
                }
            }
        });
        </script>
        <?php
    }

    public function get_field_tooltips() {
        $plugin_name = Metasync::get_effective_plugin_name();
        $otto_name = Metasync::get_whitelabel_otto_name();

        return array(
            'searchatlas_api_key' => sprintf('Connect your WordPress site to your %s dashboard to retrieve your Search Atlas API key and Otto UUID. This does not create a WordPress login session.', $plugin_name),
            'apikey' => sprintf('Auto-generated authentication token used for secure API communication between your WordPress site and %s services. You can refresh this token if needed for security purposes.', $plugin_name),
            'otto_pixel_uuid' => sprintf('Your unique %s tracking pixel identifier. This UUID is used to track %s modifications and analytics on your website pages.', $otto_name, $otto_name),
            'otto_disable_on_loggedin' => sprintf('Disable %s modifications when you are logged in to WordPress. This allows you to see and edit the original content without %s\'s enhancements during editing sessions.', $otto_name, $otto_name),
            'otto_disable_preview_button' => sprintf('Hide the %s frontend toolbar that displays the status indicator, preview button, and debug button. Enable this for a cleaner frontend experience.', $otto_name),
            'otto_wp_rocket_compat' => sprintf('WP Rocket Compatibility Mode: Controls how %s interacts with WP Rocket. "Auto" (recommended) allows both to work together by avoiding DONOTCACHEPAGE constant unless necessary for Brizy pages or SG Optimizer conflicts. This ensures WP Rocket\'s JavaScript delay and optimization features continue working.', $otto_name),
            'otto_disable_for_bots' => sprintf('Enable bot detection to automatically skip %s processing for search engine crawlers, SEO tools, and other bots. This reduces unnecessary API calls and improves performance.', $otto_name),
            'otto_bot_whitelist' => sprintf('Enter bot names or user-agent patterns (one per line) that should always be processed by %s, even when "Disable for Bots" is enabled. For example: Googlebot, Bingbot. This allows you to ensure specific search engines always see your optimized content.', $otto_name),
            'otto_bot_blacklist' => sprintf('Enter bot names or user-agent patterns (one per line) that should always be blocked from %s processing, regardless of other settings. For example: BadBot, MaliciousCrawler. Use this to exclude problematic crawlers or unwanted traffic sources.', $otto_name),
            'otto_bot_statistics_link' => 'View detailed bot detection statistics including total hits, API calls saved, breakdown by bot type, and unique bot entries with hit counts.',
            'disable_common_robots_metabox' => 'Hide the Common Robots meta box from post and page edit screens. This removes the robots meta tag controls (index/noindex, follow/nofollow) from the editor interface.',
            'disable_advance_robots_metabox' => 'Hide the Advanced Robots meta box from post and page edit screens. This removes advanced robots directives like max-snippet, max-image-preview, and max-video-preview settings.',
            'disable_redirection_metabox' => 'Hide the Redirection meta box from post and page edit screens. This removes the URL redirect configuration options from the editor interface.',
            'disable_canonical_metabox' => 'Hide the Canonical URL meta box from post and page edit screens. This removes the canonical URL override field from the editor interface.',
            'disable_social_opengraph_metabox' => 'Hide the Social Media & Open Graph meta box from post and page edit screens. This removes Facebook, Twitter, and other social media meta tag controls from the editor.',
            'disable_schema_markup_metabox' => 'Hide the Schema Markup meta box from post and page edit screens. This removes the structured data (Article, FAQ, Product, Recipe, etc.) configuration from the editor interface.',
            'open_external_links' => 'Automatically add target="_blank" attribute to external links appearing in your posts, pages, and other post types when rendered by Otto.',
            'content_genius_sync_roles' => 'Select which WordPress user roles should be synchronized with Content Genius. This determines which users will have their profiles and permissions synced for content collaboration.',
            'permalink_structure' => sprintf('Displays your current WordPress permalink structure. %s works best with pretty permalinks (not "Plain"). If you see a warning, visit Settings > Permalinks to change your structure.', $plugin_name),
            'hide_dashboard_framework' => sprintf('Hide the main %s dashboard from the WordPress admin menu. This is useful if you want to reduce menu clutter but still keep the plugin active.', $plugin_name),
            'show_admin_bar_status' => sprintf('Display the %s status indicator in the WordPress admin bar at the top of your screen. This provides quick visibility of plugin status and key metrics.', $plugin_name),
            'enable_auto_updates' => sprintf('Allow WordPress to automatically update the %s plugin when new versions are released. Recommended for security patches, but you may prefer manual updates for major versions.', $plugin_name),
            'import_external_data' => sprintf('Import your existing SEO settings and metadata from other popular SEO plugins like Yoast, Rank Math, or All in One SEO. This makes migration to %s seamless without losing your SEO data.', $plugin_name),
            'import_seo_metadata' => 'Migrate your existing SEO titles and meta descriptions from Yoast, Rank Math, or All in One SEO. This one-click import preserves your search rankings by copying your optimized meta data to MetaSync, even if the source plugin is deactivated.'
        );
    }

    public function get_field_section($field_id) {
        $sections = $this->get_accordion_sections_config();

        foreach ($sections as $section_key => $section_data) {
            if (in_array($field_id, $section_data['fields'])) {
                return $section_key;
            }
        }

        return null;
    }

    public function render_accordion_sections($page) {
        global $wp_settings_sections, $wp_settings_fields;

        if (!isset($wp_settings_fields[$page])) {
            return;
        }

        $sections_config = $this->get_accordion_sections_config();

        echo '<div class="metasync-settings-accordion">';

        uasort($sections_config, function($a, $b) {
            return $a['priority'] - $b['priority'];
        });

        foreach ($sections_config as $section_key => $section_data) {
            $section_id = 'metasync-section-' . $section_key;
            $is_open = $section_data['default_open'];
            $aria_expanded = $is_open ? 'true' : 'false';
            $content_state = $is_open ? 'open' : 'closed';

            echo '<div class="metasync-accordion-section" data-section="' . esc_attr($section_key) . '">';

            echo '<div class="metasync-accordion-header" role="button" tabindex="0" aria-expanded="' . $aria_expanded . '" aria-controls="' . $section_id . '">';
            echo '<div class="metasync-accordion-title">';
            echo '<span class="metasync-accordion-icon">' . esc_html($section_data['icon']) . '</span>';
            echo '<div class="metasync-accordion-text">';
            echo '<h3>' . esc_html($section_data['title']) . '</h3>';
            echo '<p class="metasync-accordion-description">' . esc_html($section_data['description']) . '</p>';
            echo '</div>';
            echo '</div>';
            echo '<button type="button" class="metasync-accordion-toggle" aria-label="Toggle section">';
            echo '<span class="toggle-icon">▼</span>';
            echo '</button>';
            echo '</div>';

            echo '<div class="metasync-accordion-content" id="' . $section_id . '" data-state="' . $content_state . '">';

            if (isset($section_data['render_callback']) && is_callable($section_data['render_callback'])) {
                call_user_func($section_data['render_callback']);
            } elseif (isset($section_data['fields']) && is_array($section_data['fields'])) {
                echo '<table class="form-table" role="presentation">';

                $tooltips = $this->get_field_tooltips();

                foreach ($section_data['fields'] as $field_id) {
                    if (isset($wp_settings_fields[$page]['metasync_settings'][$field_id])) {
                        $field = $wp_settings_fields[$page]['metasync_settings'][$field_id];

                        echo '<tr>';
                        echo '<th scope="row">';
                        if (!empty($field['title'])) {
                            echo '<div class="metasync-field-label-wrapper">';
                            echo '<label for="' . esc_attr($field['id']) . '">' . $field['title'] . '</label>';

                            if (isset($tooltips[$field_id])) {
                                echo '<button type="button" class="metasync-tooltip-trigger" data-tooltip-id="' . esc_attr($field_id) . '" aria-label="More information">';
                                echo '<svg class="metasync-info-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
                                echo '<circle cx="12" cy="12" r="10"></circle>';
                                echo '<path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>';
                                echo '<line x1="12" y1="17" x2="12.01" y2="17"></line>';
                                echo '</svg>';
                                echo '</button>';

                                echo '<div class="metasync-tooltip" id="tooltip-' . esc_attr($field_id) . '" role="tooltip">';
                                echo '<div class="metasync-tooltip-arrow"></div>';
                                echo '<div class="metasync-tooltip-content">' . esc_html($tooltips[$field_id]) . '</div>';
                                echo '</div>';
                            }

                            echo '</div>';
                        }
                        echo '</th>';
                        echo '<td>';
                        call_user_func($field['callback'], $field['args']);
                        echo '</td>';
                        echo '</tr>';
                    }
                }

                echo '</table>';
            }

            echo '</div>';
            echo '</div>';
        }

        echo '</div>';
    }

    // ────────────────────────────────────────────────────────────────
    //  Settings field callbacks
    // ────────────────────────────────────────────────────────────────

    public function metasync_settings_genkey_callback()
    {
        $current_token = Metasync::get_option('general')['apikey'] ?? '';
        
        if (!empty($current_token)) {
            $display_value = $current_token;
            $status_message = 'Plugin Auth Token is active and ready for authentication.';
            $refresh_help = 'Click refresh to generate a new token and update the heartbeat API.';
        } else {
            $display_value = 'Auto-generated when connecting to ' . esc_html(Metasync::get_effective_plugin_name());
            $status_message = 'Plugin Auth Token will be automatically generated when you click "Connect to ' . esc_html(Metasync::get_effective_plugin_name()) . '".';
            $refresh_help = 'You can also manually generate a token by clicking refresh.';
        }
        
        printf(
            '<input type="text" id="apikey" name="' . Metasync_Admin::option_key . '[general][apikey]" value="%s" size="40" readonly="readonly" /> ',
            esc_attr($display_value)
        );
        
        printf('<button type="button" id="refresh-plugin-auth-token" class="button button-secondary" style="margin-left: 10px;">🔄 Refresh Token</button>');
        printf('<p class="description">%s %s</p>', $status_message, $refresh_help);
    }

    public function linkgraph_token_callback()
    {
        printf(
            '<input type="text" id="linkgraph_token" name="' . Metasync_Admin::option_key . '[general][linkgraph_token]" value="%s" size="25" readonly="readonly" />',
            isset(Metasync::get_option('general')['linkgraph_token']) ? esc_attr(Metasync::get_option('general')['linkgraph_token']) : ''
        );

        printf(
            '<input type="text" id="linkgraph_customer_id" name="' . Metasync_Admin::option_key . '[general][linkgraph_customer_id]" value="%s" size="25" readonly="readonly" />',
            isset(Metasync::get_option('general')['linkgraph_customer_id']) ? esc_attr(Metasync::get_option('general')['linkgraph_customer_id']) : ''
        );

    ?>
        <button type="button" class="button button-primary" id="lgloginbtn">Fetch Token</button>
        <input type="text" id="lgusername" class="input lguser hidden" placeholder="username" />
        <input type="text" id="lgpassword" class="input lguser hidden" placeholder="password" />
        <p id="lgerror" class="notice notice-error hidden" style="display: none;"></p>
    <?php
    }


    public function time_elapsed_string($datetime, $full = false)
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

    public function searchatlas_api_key_callback()
    {
        $current_api_key = isset(Metasync::get_option('general')['searchatlas_api_key']) ? esc_attr(Metasync::get_option('general')['searchatlas_api_key']) : '';
        $otto_uuid = isset(Metasync::get_option('general')['otto_pixel_uuid']) ? Metasync::get_option('general')['otto_pixel_uuid'] : '';
        
        $has_api_key = !empty($current_api_key);
        $has_otto_uuid = !empty($otto_uuid);
        
        $is_fully_connected = $this->admin_instance->is_heartbeat_connected();
        
        printf('<div class="metasync-sa-connect-container">');
        
        printf('<div class="metasync-sa-connect-title">');
        printf('🔐 One-Click Authentication');
        printf('</div>');
        
        printf('<div class="metasync-sa-connect-description">');
        if ($is_fully_connected) {
            printf('Your %s account is fully synced with active heartbeat API. You can re-authenticate to refresh your connection or connect a different account.', esc_html(Metasync::get_effective_plugin_name()));
        } elseif ($has_api_key && !$has_otto_uuid) {
            printf('Your %s API key is configured, but %s UUID is missing. Please re-authenticate to complete the setup.', esc_html(Metasync::get_effective_plugin_name()), esc_html(Metasync::get_whitelabel_otto_name()));
        } else {
            printf('Connect your %s account with one click. This will automatically configure your API key and %s UUID below, enabling all plugin features.', esc_html(Metasync::get_effective_plugin_name()), esc_html(Metasync::get_whitelabel_otto_name()));
        }
        printf('</div>');

        ?>
        <div class="metasync-mcp-consent" style="margin-top: 15px; padding: 12px 15px; background: #f0f9ff; border-left: 3px solid #0ea5e9; border-radius: 4px;">
            <p style="margin: 0; color: #334155; font-size: 13px; line-height: 1.6;">
                <strong>🤖 AI-Powered SEO Automation:</strong> By authenticating, you authorize <strong><?php echo esc_html(Metasync::get_effective_plugin_name()); ?> Brain</strong> (our AI assistant) to access your WordPress admin capabilities through the Model Context Protocol (MCP). This enables intelligent automation for SEO optimizations, content enhancements, and performance improvements.
            </p>
        </div>
        <?php

        printf('<div class="metasync-sa-connect-buttons">');
        
        printf('<button type="button" id="connect-searchatlas-btn" class="metasync-sa-connect-btn">');
        if ($is_fully_connected) {
            printf('🔄 Re-authenticate with %s', esc_html(Metasync::get_effective_plugin_name()));
        } elseif ($has_api_key && !$has_otto_uuid) {
            printf('🔧 Complete Authentication Setup');
        } else {
            printf('🔗 Connect to %s', esc_html(Metasync::get_effective_plugin_name()));
        }
        printf('</button>');
        
        if ($has_api_key) {
            printf('<button type="button" id="reset-searchatlas-auth" class="metasync-sa-reset-btn" style="margin-left: 10px;">');
            printf('🔓 Disconnect Account');
            printf('</button>');
        }
        
        printf('</div>');
        
        printf('<div style="margin-top: 15px;">');
        printf('<details style="margin-top: 10px;">');
        printf('<summary style="cursor: pointer; color: #666; font-size: 13px;">💡 Authentication Tips</summary>');
        printf('<div style="padding: 10px 0; color: #666; font-size: 13px; line-height: 1.5;">');
        printf('• Make sure you have a %s account before connecting<br/>', esc_html(Metasync::get_effective_plugin_name()));
        printf('• The authentication window will open in a popup - please allow popups<br/>');
        printf('• The process typically takes 15-30 seconds to complete<br/>');
        printf('• Your API key will be automatically filled in the field below<br/>');
        printf('• If you encounter issues, try disabling ad blockers temporarily<br/>');
        printf('• Contact <a href="mailto:%s">%s</a> if you need assistance', Metasync::SUPPORT_EMAIL, Metasync::SUPPORT_EMAIL);
        printf('</div>');
        printf('</details>');
        printf('</div>');
        
        printf('</div>');
        
        printf('<div style="margin-top: 20px;">');
        printf('<label for="searchatlas-api-key" style="font-weight: 600; display: block; margin-bottom: 8px;">');
        printf('🔑 %s API Key', esc_html(Metasync::get_effective_plugin_name()));
        if ($is_fully_connected) {
            printf('<span style="color: #46b450; margin-left: 10px; font-weight: normal;">✓ Synced</span>');
        } elseif ($has_api_key && !$has_otto_uuid) {
            printf('<span style="color: #ff8c00; margin-left: 10px; font-weight: normal;">⚠️ Partial Connection (Missing %s UUID)</span>', esc_html(Metasync::get_whitelabel_otto_name()));
        }
        printf('</label>');
        
        printf(
            '<input type="text" id="searchatlas-api-key" name="' . Metasync_Admin::option_key . '[general][searchatlas_api_key]" value="%s" size="40" class="regular-text" placeholder="Your API key will appear here after authentication" />',
            $current_api_key
        );
        
        printf('<p class="description" style="margin-top: 8px;">');
        if ($is_fully_connected) {
            printf('Your %s API key for secure communication with the platform. Use the authentication button above to refresh or change accounts.', esc_html(Metasync::get_effective_plugin_name()));
        } elseif ($has_api_key && !$has_otto_uuid) {
            printf('Your API key is configured but %s UUID is missing. Re-authenticate above to complete the setup and enable dashboard access.', esc_html(Metasync::get_whitelabel_otto_name()));
        } else {
            printf('This field will be automatically populated when you authenticate using the button above. You can also manually enter your API key if you have one.');
        }
        printf('</p>');

        ?>
        <p class="description" style="margin-top: 10px; padding: 8px 12px; background: #fff9e6; border-left: 3px solid #f0b849; border-radius: 4px; font-size: 12px;">
            <strong>📝 Manual Authentication:</strong> If you manually enter your <?php echo esc_html(Metasync::get_effective_plugin_name()); ?> API Key and OTTO UUID, you also consent to the same AI-powered automation permissions described above.
        </p>
        <?php

        printf('</div>');

        if(  isset(Metasync::get_option('general')['searchatlas_api_key'])&&Metasync::get_option('general')['searchatlas_api_key']!=''){
            $timestamp = @Metasync::get_option('general')['send_auth_token_timestamp'];
            printf(
                '<p id="sendAuthTokenTimestamp" class="descriptionValue">%s (%s)</p>',
                esc_attr($timestamp),
                $this->time_elapsed_string($timestamp)
            );
    
        
        }
      }

    public function bing_site_verification_callback()
    {
        printf(
            '<input type="text" id="bing_site_verification" name="' . Metasync_Admin::option_key . '[searchengines][bing_site_verification]" value="%s" size="50" />',
            isset(Metasync::get_option('searchengines')['bing_site_verification']) ? esc_attr(Metasync::get_option('searchengines')['bing_site_verification']) : ''
        );

        printf(' <br> <span class="description"> Enter Bing Webmaster Tools verification code: </span> ');
        printf(' <a href="https://www.bing.com/webmasters/about" target="_blank">Get from here</a> <br> ');
    }

    public function yandex_site_verification_callback()
    {
        printf(
            '<input type="text" id="yandex_site_verification" name="' . Metasync_Admin::option_key . '[searchengines][yandex_site_verification]" value="%s" size="50" />',
            isset(Metasync::get_option('searchengines')['yandex_site_verification']) ? esc_attr(Metasync::get_option('searchengines')['yandex_site_verification']) : ''
        );

        printf(' <br> <span class="description"> Enter Yandex verification code: </span>');
        printf(' <a href="https://passport.yandex.com/auth" target="_blank">Get from here</a> <br> ');
    }

    public function google_site_verification_callback()
    {
        printf(
            '<input type="text" id="google_site_verification" name="' . Metasync_Admin::option_key . '[searchengines][google_site_verification]" value="%s" size="50" />',
            isset(Metasync::get_option('searchengines')['google_site_verification']) ? esc_attr(Metasync::get_option('searchengines')['google_site_verification']) : ''
        );

        printf(' <br> <span class="description"> Enter Google Search Console verification code: </span>');
        printf(' <a href="https://www.google.com/webmasters/verification" target="_blank">Get from here</a> <br> ');
    }

    public function pinterest_site_verification_callback()
    {
        printf(
            '<input type="text" id="pinterest_site_verification" name="' . Metasync_Admin::option_key . '[searchengines][pinterest_site_verification]" value="%s" size="50" />',
            isset(Metasync::get_option('searchengines')['pinterest_site_verification']) ? esc_attr(Metasync::get_option('searchengines')['pinterest_site_verification']) : ''
        );

        printf(' <br> <span class="description"> Enter Pinterest verification code: </span>');
        printf(' <a href="https://in.pinterest.com/" target="_blank">Get from here</a> <br> ');
    }

    public function local_seo_person_organization_callback()
    {
        $person_organization = Metasync::get_option('localseo')['local_seo_person_organization'] ?? '';
    ?>
        <select id="local_seo_person_organization" name="<?php echo esc_attr(Metasync_Admin::option_key . '[localseo][local_seo_person_organization]') ?>">
            <?php
            printf('<option value="Person" %s >Person</option>', selected('Person', esc_attr($person_organization)));
            printf('<option value="Organization" %s >Organization</option>', selected('Organization', esc_attr($person_organization)));
            ?>
        </select>
    <?php
        printf(' <br> <span class="description"> Choose whether the site represents a person or an organization. </span>');
    }

    public function local_seo_name_callback()
    {
        printf(
            '<input type="text" id="local_seo_name" name="' . Metasync_Admin::option_key . '[localseo][local_seo_name]" value="%s" size="50" />',
            isset(Metasync::get_option('localseo')['local_seo_name']) ? esc_attr(Metasync::get_option('localseo')['local_seo_name']) : get_bloginfo()
        );

        printf(' <br> <span class="description"> Your name or company name </span>');
    }

    public function local_seo_logo_callback()
    {
        $local_seo_logo = Metasync::get_option('localseo')['local_seo_logo'] ?? '';

        printf(
            '<input type="hidden" id="local_seo_logo" name="' . Metasync_Admin::option_key . '[localseo][local_seo_logo]" value="%s" size="50" />',
            isset(Metasync::get_option('localseo')['local_seo_logo']) ? esc_attr(Metasync::get_option('localseo')['local_seo_logo']) : ''
        );

        printf(' <br> <input class="button-secondary" type="button" id="logo_upload_button" value="Add or Upload File">');

        printf(' <br><br> <span class="description bold"> Min Size: 160Χ90px, Max Size: 1920X1080px. </span> <br> <span class="description"> A squared image is preferred by the search engines. </span> <br><br> ');

        printf('<img src="%s" id="local_seo_business_logo" width="300">', wp_get_attachment_image_src($local_seo_logo, 'medium')[0] ?? '');

        $button_type = 'hidden';
        if ($local_seo_logo) {
            $button_type = 'button';
        }
        printf('<input type="%s" class="button-secondary" id="local_seo_logo_close_btn" value="X">', $button_type);
    }

    public function local_seo_url_callback()
    {
        printf(
            '<input type="text" id="local_seo_url" name="' . Metasync_Admin::option_key . '[localseo][local_seo_url]" value="%s" size="50" />',
            isset(Metasync::get_option('localseo')['local_seo_url']) ? esc_attr(Metasync::get_option('localseo')['local_seo_url']) : home_url()
        );

        printf(' <br> <span class="description"> URL of the item. </span>');
    }

    public function local_seo_email_callback()
    {
        printf(
            '<input type="text" id="local_seo_email" name="' . Metasync_Admin::option_key . '[localseo][local_seo_email]" value="%s" size="50" />',
            isset(Metasync::get_option('localseo')['local_seo_email']) ? esc_attr(Metasync::get_option('localseo')['local_seo_email']) : ''
        );

        printf(' <br> <span class="description"> Search engines display your email address. </span>');
    }

    public function local_seo_phone_callback()
    {
        printf(
            '<input type="text" id="local_seo_phone" name="' . Metasync_Admin::option_key . '[localseo][local_seo_phone]" value="%s" size="50" />',
            isset(Metasync::get_option('localseo')['local_seo_phone']) ? esc_attr(Metasync::get_option('localseo')['local_seo_phone']) : ''
        );

        printf(' <br> <span class="description"> Search engines may prominently display your contact phone number for mobile users. </span>');
    }

    public function local_seo_address_callback()
    {
        printf(
            '<input type="text" id="local_seo_address_street" name="' . Metasync_Admin::option_key . '[localseo][address][street]" value="%s" size="50" placeholder="Street Address"/> <br>',
            isset(Metasync::get_option('localseo')['address']['street']) ? esc_attr(Metasync::get_option('localseo')['address']['street']) : ''
        );

        printf(
            '<input type="text" id="local_seo_address_locality" name="' . Metasync_Admin::option_key . '[localseo][address][locality]" value="%s" size="50" placeholder="Locality"/> <br>',
            isset(Metasync::get_option('localseo')['address']['locality']) ? esc_attr(Metasync::get_option('localseo')['address']['locality']) : ''
        );

        printf(
            '<input type="text" id="local_seo_address_region" name="' . Metasync_Admin::option_key . '[localseo][address][region]" value="%s" size="50" placeholder="Region"/> <br>',
            isset(Metasync::get_option('localseo')['address']['region']) ? esc_attr(Metasync::get_option('localseo')['address']['region']) : ''
        );

        printf(
            '<input type="text" id="local_seo_address_postalcode" name="' . Metasync_Admin::option_key . '[localseo][address][postalcode]" value="%s" size="50" placeholder="Postal Code"/> <br>',
            isset(Metasync::get_option('localseo')['address']['postalcode']) ? esc_attr(Metasync::get_option('localseo')['address']['postalcode']) : ''
        );

        printf(
            '<input type="text" id="local_seo_address_country" name="' . Metasync_Admin::option_key . '[localseo][address][country]" value="%s" size="50" placeholder="Country"/> <br>',
            isset(Metasync::get_option('localseo')['address']['country']) ? esc_attr(Metasync::get_option('localseo')['address']['country']) : ''
        );
    }

    public function local_seo_business_type_callback()
    {
        $types = self::get_business_types();
        sort($types);

        $business_type = Metasync::get_option('localseo')['local_seo_business_type'] ?? '';

    ?>
        <select name="<?php echo esc_attr(Metasync_Admin::option_key . '[localseo][local_seo_business_type]') ?>">
            <option value='0'>Select Business Type</option>
            <?php
            foreach ($types as $type) {
                printf('<option value="%s" %s >%s</option>', $type, selected($type, esc_attr($business_type)), $type);
            }
            ?>
        </select>
    <?php
    }

    public function local_seo_opening_hours_callback()
    {
        $days_name = ['Monday', 'Tuseday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $days = isset(Metasync::get_option('localseo')['days']) ? Metasync::get_option('localseo')['days'] : '';
        $times = isset(Metasync::get_option('localseo')['times']) ? Metasync::get_option('localseo')['times'] : '';

    ?>
        <ul id="daysTime">
            <?php
            $opening_days = [];
            if ($days && $times) {
                $opening_days = array_combine($days, $times);
            }
            foreach ($opening_days as $day_name => $day_time) {
            ?>
                <li>
                    <select name="<?php echo esc_attr(Metasync_Admin::option_key . '[localseo][days][]') ?>">
                        <?php
                        foreach ($days_name as $name) {
                            printf('<option value="%s" %s >%s</option>', $name, selected(esc_attr($name), esc_attr($day_name)), esc_attr($name));
                        }
                        ?>
                    </select>
                    <input type="text" name="<?php echo esc_attr(Metasync_Admin::option_key . '[localseo][times][]') ?>" value="<?php echo esc_attr($day_time) ?>">
                    <button id="timeDelete">Delete</button>
                </li>
            <?php } ?>
            <?php if (empty($opening_days)) { ?>
                <li>
                    <select name="<?php echo esc_attr(Metasync_Admin::option_key . '[localseo][days][]') ?>">
                        <?php
                        foreach ($days_name as $name) {
                            printf('<option value="%s" >%s</option>', esc_attr($name), esc_attr($name));
                        }
                        ?>
                    </select>
                    <input type="text" name="<?php echo esc_attr(Metasync_Admin::option_key . '[localseo][times][]') ?>" value="">
                    <button id="timeDelete">Delete</button>
                </li>
            <?php } ?>
        </ul>
    <?php

        printf(' <input type="hidden" id="days_time_count" value="%s"/>', count($opening_days));
        printf(' <input class="button-secondary" type="button" id="addNewTime" value="Add Time">');
        printf(' <br> <span class="description"> Select opening hours. You can add multiple sets if you have different opening or closing hours on some days or if you have a mid-day break. Times are specified using 24:00 time. </span>');
    }

    public function local_seo_phone_numbers_callback()
    {
        $number_types = ['Customer Service', 'Technical Support', 'Billing Support', 'Bill Payment', 'Sales', 'Reservations', 'Credit Card Support', 'Emergency', 'Baggage Tracking', 'Roadside Assistance', 'Package Tracking'];
        $types = isset(Metasync::get_option('localseo')['phonetype']) ? Metasync::get_option('localseo')['phonetype'] : '';
        $numbers = isset(Metasync::get_option('localseo')['phonenumber']) ? Metasync::get_option('localseo')['phonenumber'] : '';

    ?>

        <ul id="phone-numbers">
            <?php
            $phone_numbers = [];
            if ($types && $numbers) {
                $phone_numbers = array_combine($types, $numbers);
            }
            foreach ($phone_numbers as $phone_type => $phone_number) {
            ?>
                <li>
                    <select name="<?php echo esc_attr(Metasync_Admin::option_key . '[localseo][phonetype][]') ?>">
                        <?php
                        foreach ($number_types as $type) {
                            printf('<option value="%s" %s >%s</option>', esc_attr($type), selected(esc_attr($type), esc_attr($phone_type)), esc_attr($type));
                        }
                        ?>
                    </select>
                    <input type="text" name="<?php echo esc_attr(Metasync_Admin::option_key . '[localseo][phonenumber][]') ?>" value="<?php echo esc_attr($phone_number) ?>">
                    <button id="number-delete">Delete</button>
                </li>
            <?php } ?>
            <?php if (empty($phone_numbers)) { ?>
                <li>
                    <select name="<?php echo esc_attr(Metasync_Admin::option_key . '[localseo][phonetype][]') ?>">
                        <?php
                        foreach ($number_types as $type) {
                            printf('<option value="%s" >%s</option>', esc_attr($type), esc_attr($type));
                        }
                        ?>
                    </select>
                    <input type="text" name="<?php echo esc_attr(Metasync_Admin::option_key . '[localseo][phonenumber][]') ?>" value="">
                    <button id="number-delete">Delete</button>
                </li>
            <?php } ?>
        </ul>
    <?php

        printf(' <input type="hidden" id="phone_number_count" value="%s"/>', count($phone_numbers));
        printf(' <input class="button-secondary" type="button" id="addNewNumber" value="Add Number">');
        printf(' <br> <span class="description"> Search engines may prominently display your contact phone number for mobile users. </span>');
    }

    public function local_seo_price_range_callback()
    {
        printf(
            '<input type="text" id="local_seo_price_range" name="' . Metasync_Admin::option_key . '[localseo][local_seo_price_range]" value="%s" size="50" />',
            isset(Metasync::get_option('localseo')['local_seo_price_range']) ? esc_attr(Metasync::get_option('localseo')['local_seo_price_range']) : ''
        );
        printf(' <br> <span class="description"> The price range of the business, for example $$$. </span>');
    }

    public function local_seo_about_page_callback()
    {
    ?>
        <select name="<?php echo esc_attr(Metasync_Admin::option_key . '[localseo][local_seo_about_page]') ?>">
            <option value='0'>Select About Page</option>
            <?php
            $about_page = Metasync::get_option('localseo')['local_seo_about_page'] ?? '';
            $pages = get_pages();
            foreach ($pages as $page) {
                printf('<option value="%s" %s >%s</option>', $page->ID, selected($page->ID, esc_attr($about_page)), $page->post_title);
            }
            ?>
        </select>
    <?php
        printf(' <br> <span class="description"> Search engines tag your about us page. </span>');
    }

    public function local_seo_contact_page_callback()
    {
    ?>
        <select name="<?php echo esc_attr(Metasync_Admin::option_key . '[localseo][local_seo_contact_page]') ?>">
            <option value='0'>Select Contact Page</option>
            <?php
            $contact_page = Metasync::get_option('localseo')['local_seo_contact_page'] ?? '';
            $pages = get_pages();
            foreach ($pages as $page) {
                printf('<option value="%s" %s >%s</option>', $page->ID, selected($page->ID, esc_attr($contact_page)), $page->post_title);
            }
            ?>
        </select>
    <?php
        printf(' <br> <span class="description"> Search engines tag your contact page. </span>');
    }

    public function local_seo_map_key_callback()
    {
        printf(
            '<input type="text" id="local_seo_map_key" name="' . Metasync_Admin::option_key . '[localseo][local_seo_map_key]" value="%s" size="50" />',
            isset(Metasync::get_option('localseo')['local_seo_map_key']) ? esc_attr(Metasync::get_option('localseo')['local_seo_map_key']) : ''
        );

        printf(' <br> <span class="description"> An API Key is required to display embedded Google Maps on your site. </span>');
    }

    public function local_seo_geo_coordinates_callback()
    {
        printf(
            '<input type="text" id="local_seo_geo_coordinates" name="' . Metasync_Admin::option_key . '[localseo][local_seo_geo_coordinates]" value="%s" size="50" />',
            isset(Metasync::get_option('localseo')['local_seo_geo_coordinates']) ? esc_attr(Metasync::get_option('localseo')['local_seo_geo_coordinates']) : ''
        );

        printf(' <br> <span class="description"> Latitude and longitude values separated by comma. </span>');
    }

    public function header_snippets_callback()
    {
        printf(
            '<textarea class="wide-text" id="header_snippets" rows="8" name="' . Metasync_Admin::option_key . '[codesnippets][header_snippet]" >%s</textarea>',
            isset(Metasync::get_option('codesnippets')['header_snippet']) ? esc_attr(Metasync::get_option('codesnippets')['header_snippet']) : ''
        );
    }

    public function footer_snippets_callback()
    {
        printf(
            '<textarea class="wide-text" id="footer_snippets" rows="8" name="' . Metasync_Admin::option_key . '[codesnippets][footer_snippet]" >%s</textarea>',
            isset(Metasync::get_option('codesnippets')['footer_snippet']) ? esc_attr(Metasync::get_option('codesnippets')['footer_snippet']) : ''
        );
    }

    public function no_index_posts_callback()
    {
        printf(
            '<input type="checkbox" id="no_index_posts" name="' . Metasync_Admin::option_key . '[optimal_settings][no_index_posts]" value="true" %s />',
            isset(Metasync::get_option('optimal_settings')['no_index_posts']) && Metasync::get_option('optimal_settings')['no_index_posts'] == 'true' ? 'checked' : ''
        );

        printf(' <br> <span class="description"> Setting empty archives to <code>noindex</code> is useful for avoiding indexation of thin content pages and dilution of page rank. As soon as a post is added, the page is updated to index. </span>');
    }

    public function no_follow_links_callback()
    {
        printf(
            '<input type="checkbox" id="no_follow_links" name="' . Metasync_Admin::option_key . '[optimal_settings][no_follow_links]" value="true" %s />',
            isset(Metasync::get_option('optimal_settings')['no_follow_links']) && Metasync::get_option('optimal_settings')['no_follow_links'] == 'true' ? 'checked' : ''
        );

        printf(' <br> <span class="description"> Automatically add <code>rel="nofollow"</code> attribute to external links appearing in your posts, pages, and other post types. The attribute is dynamically applied when the url is displayed</span>');
    }

    public function open_external_links_callback()
    {
        printf(
            '<input type="checkbox" id="open_external_links" name="' . Metasync_Admin::option_key . '[optimal_settings][open_external_links]" value="true" %s />',
            isset(Metasync::get_option('optimal_settings')['open_external_links']) && Metasync::get_option('optimal_settings')['open_external_links'] == 'true' ? 'checked' : ''
        );

        printf(' <br> <span class="description"> Automatically add <code>target="_blank"</code> attribute to external links appearing in your posts, pages, and other post types. The attribute is applied when the url is displayed.</span>');
    }

    public function add_alt_image_tags_callback()
    {
        printf(
            '<input type="checkbox" name="' . Metasync_Admin::option_key . '[optimal_settings][add_alt_image_tags]" value="true" %s />',
            isset(Metasync::get_option('optimal_settings')['add_alt_image_tags']) && Metasync::get_option('optimal_settings')['add_alt_image_tags'] == 'true' ? 'checked' : ''
        );

        printf(' <br> <span class="description"> Automatically add <code>alt</code> attribute to Image Tags appearing in your posts, pages, and other post types. The attribute is applied when the content is displayed.</span>');
    }

    public function add_title_image_tags_callback()
    {
        printf(
            '<input type="checkbox" name="' . Metasync_Admin::option_key . '[optimal_settings][add_title_image_tags]" value="true" %s />',
            isset(Metasync::get_option('optimal_settings')['add_title_image_tags']) && Metasync::get_option('optimal_settings')['add_title_image_tags'] == 'true' ? 'checked' : ''
        );

        printf(' <br> <span class="description"> Automatically add <code>title</code> attribute to Image Tags appearing in your posts, pages, and other post types. The attribute is applied when the content is displayed.</span>');
    }

    public function site_type_callback()
    {

        $site_type = Metasync::get_option('optimal_settings')['site_info']['type'] ?? '';

        $types = [
            ['name' => 'Personal Blog', 'value' => 'blog'],
            ['name' => 'Community Blog/News Site', 'value' => 'news'],
            ['name' => 'Personal Portfolio', 'value' => 'portfolio'],
            ['name' => 'Small Business Site', 'value' => 'business'],
            ['name' => 'Webshop', 'value' => 'webshop'],
            ['name' => 'Other Personal Website', 'value' => 'otherpersonal'],
            ['name' => 'Other Business Website', 'value' => 'otherbusiness'],
        ];

    ?>
        <select name="<?php echo esc_attr(Metasync_Admin::option_key . '[optimal_settings][site_info][type]') ?>" id="site_info_type">
            <?php
            foreach ($types as $type) {
                printf('<option value="%s" %s >%s</option>', esc_attr($type['value']), selected(esc_attr($type['value']), esc_attr($site_type)), ($type['name']));
            }
            ?>
        </select>
    <?php

    }

    public function site_business_type_callback()
    {

        $business_type = Metasync::get_option('optimal_settings')['site_info']['business_type'] ?? '';

        $types = self::get_business_types();
        sort($types);

    ?>
        <select name="<?php echo esc_attr(Metasync_Admin::option_key . '[optimal_settings][site_info][business_type]') ?>">
            <option value='0'>Select Business Type</option>
            <?php
            foreach ($types as $type) {
                printf('<option value="%s" %s >%s</option>', esc_attr($type), selected(esc_attr($type), esc_attr($business_type)), esc_attr($type));
            }
            ?>
        </select>
    <?php
    }

    public function site_company_name_callback()
    {

        $company_name = Metasync::get_option('optimal_settings')['site_info']['company_name'] ?? get_bloginfo('name');

        printf(
            '<input type="text" name="' . Metasync_Admin::option_key . '[optimal_settings][site_info][company_name]" value="%s" size="50" />',
            $company_name ? $company_name : get_bloginfo('name')
        );
    }

    public function site_google_logo_callback()
    {

        $google_logo = Metasync::get_option('optimal_settings')['site_info']['google_logo'] ?? '';

        printf(
            '<input type="hidden" id="site_google_logo" name="' . Metasync_Admin::option_key . '[optimal_settings][site_info][google_logo]" value="%s" size="50" />',
            $google_logo
        );

        printf(' <br> <input class="button-secondary" type="button" id="google_logo_btn" value="Add or Upload File">');
        printf(' <br><br> <span class="description bold"> Min Size: 160X90px, Max Size: 1920X1080px. </span> <br> <span class="description"> A squared image is preferred by the search engines. </span> <br><br> ');
        printf('<img src="%s" id="site_google_logo_img" width="300">', wp_get_attachment_image_src($google_logo, 'medium')[0] ?? '');

        $button_type = 'hidden';
        if ($google_logo) {
            $button_type = 'button';
        }
        printf('<input type="%s" class="button-secondary" id="site_google_logo_close_btn" value="X">', $button_type);
    }

    public function site_social_share_image_callback()
    {

        $social_share_image = Metasync::get_option('optimal_settings')['site_info']['social_share_image'] ?? '';

        printf(
            '<input type="hidden" id="site_social_share_image" name="' . Metasync_Admin::option_key . '[optimal_settings][site_info][social_share_image]" value="%s" size="50" />',
            $social_share_image
        );

        printf(' <br> <input class="button-secondary" type="button" id="social_share_image_btn" value="Add or Upload File">');
        printf(' <br><br> <span class="description bold"> The recommended image size is 1200 x 630 pixels. </span> <br> <span class="description"> When a featured image or an OpenGraph Image is not set for individual posts/pages/CPTs, this image will be used as a fallback thumbnail when your post is shared on Facebook. </span> <br><br> ');
        printf('<img src="%s" id="site_social_share_img" width="300">', wp_get_attachment_image_src($social_share_image, 'medium')[0] ?? '');

        $button_type = 'hidden';
        if ($social_share_image) {
            $button_type = 'button';
        }
        printf('<input type="%s" class="button-secondary" id="site_social_image_close_btn" value="X">', $button_type);
    }

    public function common_robot_meta_tags_callback()
    {
        $common_robots_meta = Metasync::get_option('common_robots_meta') ?? Metasync::get_option('common_robots_mata') ?? '';

    ?>
        <ul class="checkbox-list">
            <li>
                <input type="checkbox" name="<?php echo esc_attr(Metasync_Admin::option_key . '[common_robots_meta][index]') ?>" id="robots_common1" value="index" <?php isset($common_robots_meta['index']) ? checked('index', $common_robots_meta['index']) : '' ?>>
                <label for="robots_common1">Index </br>
                    <span class="description">
                        <span>Search engines to index and show these pages in the search results.</span>
                    </span>
                </label>
            </li>
            <li>
                <input type="checkbox" name="<?php echo esc_attr(Metasync_Admin::option_key . '[common_robots_meta][noindex]') ?>" id="robots_common2" value="noindex" <?php isset($common_robots_meta['noindex']) ? checked('noindex', $common_robots_meta['noindex']) : '' ?>>
                <label for="robots_common2">No Index </br>
                    <span class="description">
                        <span>Search engines not indexed and displayed this pages in search engine results</span>
                    </span>
                </label>
            </li>
            <li>
                <input type="checkbox" name="<?php echo esc_attr(Metasync_Admin::option_key . '[common_robots_meta][nofollow]') ?>" id="robots_common3" value="nofollow" <?php isset($common_robots_meta['nofollow']) ? checked('nofollow', $common_robots_meta['nofollow']) : '' ?>>
                <label for="robots_common3">No Follow </br>
                    <span class="description">
                        <span>Search engines not follow the links on the pages</span>
                    </span>
                </label>
            </li>
            <li>
                <input type="checkbox" name="<?php echo esc_attr(Metasync_Admin::option_key . '[common_robots_meta][noarchive]') ?>" id="robots_common4" value="noarchive" <?php isset($common_robots_meta['noarchive']) ? checked('noarchive', $common_robots_meta['noarchive']) : '' ?>>
                <label for="robots_common4">No Archive </br>
                    <span class="description">
                        <span>Search engines not showing Cached links for pages</span>
                    </span>
                </label>
            </li>
            <li>
                <input type="checkbox" name="<?php echo esc_attr(Metasync_Admin::option_key . '[common_robots_meta][noimageindex]') ?>" id="robots_common5" value="noimageindex" <?php isset($common_robots_meta['noimageindex']) ? checked('noimageindex', $common_robots_meta['noimageindex']) : '' ?>>
                <label for="robots_common5">No Image Index </br>
                    <span class="description">
                        <span>If you do not want to apear your pages as the referring page for images that appear in image search results</span>
                    </span>
                </label>
            </li>
            <li>
                <input type="checkbox" name="<?php echo esc_attr(Metasync_Admin::option_key . '[common_robots_meta][nosnippet]') ?>" id="robots_common6" value="nosnippet" <?php isset($common_robots_meta['nosnippet']) ? checked('nosnippet', $common_robots_meta['nosnippet']) : '' ?>>
                <label for="robots_common6">No Snippet </br>
                    <span class="description">
                        <span>Search engines not snippet to show in the search results</span>
                    </span>
                </label>
            </li>
        </ul>
    <?php
    }

    /**
     * @deprecated Use common_robot_meta_tags_callback() instead
     */
    public function common_robot_mata_tags_callback()
    {
        return $this->common_robot_meta_tags_callback();
    }

    public function advance_robot_meta_tags_callback()
    {
        $advance_robots_meta = Metasync::get_option('advance_robots_meta') ?? Metasync::get_option('advance_robots_mata') ?? '';

        $snippet_advance_robots_enable = $advance_robots_meta['max-snippet']['enable'] ?? '';
        $snippet_advance_robots_length = $advance_robots_meta['max-snippet']['length'] ?? '-1';
        $video_advance_robots_enable = $advance_robots_meta['max-video-preview']['enable'] ?? '';
        $video_advance_robots_length = $advance_robots_meta['max-video-preview']['length'] ?? '-1';
        $image_advance_robots_enable = $advance_robots_meta['max-image-preview']['enable'] ?? '';
        $image_advance_robots_length = $advance_robots_meta['max-image-preview']['length'] ?? '';

    ?>
        <ul class="checkbox-list">
            <li>
                <label for="advanced_robots_snippet">
                    <input type="checkbox" name="<?php echo esc_attr(Metasync_Admin::option_key . '[advance_robots_meta][max-snippet][enable]') ?>" id="advanced_robots_snippet" value="1" <?php checked('1', esc_attr($snippet_advance_robots_enable)) ?>>
                    Snippet </br>
                    <input type="number" class="input-length" name="<?php echo esc_attr(Metasync_Admin::option_key . '[advance_robots_meta][max-snippet][length]') ?>" id="advanced_robots_snippet_value" value="<?php echo esc_attr($snippet_advance_robots_length); ?>" min="-1"> </br>
                    <span class="description">
                        <span>Add maximum text-length, in characters, of a snippet for your page.</span>
                    </span>
                </label>
            </li>
            <li>
                <label for="advanced_robots_video">
                    <input type="checkbox" name="<?php echo esc_attr(Metasync_Admin::option_key . '[advance_robots_meta][max-video-preview][enable]') ?>" id="advanced_robots_video" value="1" <?php checked('1', esc_attr($video_advance_robots_enable)) ?>>
                    Video Preview </br>
                    <input type="number" class="input-length" name="<?php echo esc_attr(Metasync_Admin::option_key . '[advance_robots_meta][max-video-preview][length]') ?>" id="advanced_robots_video_value" value="<?php echo esc_attr($video_advance_robots_length); ?>" min="-1"> </br>
                    <span class="description">
                        <span>Add maximum duration in seconds of an animated video preview.</span>
                    </span>
                </label>
            </li>
            <li>
                <label for="advanced_robots_image">
                    <input type="checkbox" name="<?php echo esc_attr(Metasync_Admin::option_key . '[advance_robots_meta][max-image-preview][enable]') ?>" id="advanced_robots_image" value="1" <?php checked('1', esc_attr($image_advance_robots_enable)); ?>>
                    Image Preview </br>
                    <select class="input-length" name="<?php echo esc_attr(Metasync_Admin::option_key . '[advance_robots_meta][max-image-preview][length]') ?>" id="advanced_robots_image_value">
                        <option value="large" <?php selected('large', esc_attr($image_advance_robots_length)) ?>>Large</option>
                        <option value="standard" <?php selected('standard', esc_attr($image_advance_robots_length)) ?>>Standard</option>
                        <option value="none" <?php selected('none', esc_attr($image_advance_robots_length)) ?>>None</option>
                    </select>
                    </br>
                    <span class="description">
                        <span>Add maximum size of image preview to show the images on this page.</span>
                    </span>
                </label>
            </li>
        </ul>
    <?php
    }

    /**
     * @deprecated Use advance_robot_meta_tags_callback() instead
     */
    public function advance_robot_mata_tags_callback()
    {
        return $this->advance_robot_meta_tags_callback();
    }

    public function global_twitter_card_type_callback()
    {
        $twitter_card_type = Metasync::get_option('twitter_card_type') ?? '';
    ?>

        <select class="input-length" name="<?php echo esc_attr(Metasync_Admin::option_key . '[twitter_card_type]') ?>" id="twitter_card_type">
            <option value="summary_large_image" <?php selected('summary_large_image', esc_attr($twitter_card_type)) ?>>Summary Large Image</option>
            <option value="summary_card" <?php selected('summary_card', esc_attr($twitter_card_type)) ?>>Summary Card</option>
        </select>

        <?php
    }

    public function global_open_graph_meta_callback()
    {
        printf(
            '<input type="checkbox" name="' . Metasync_Admin::option_key . '[common_meta_settings][open_graph_meta_tags]" value="true" %s />',
            isset(Metasync::get_option('common_meta_settings')['open_graph_meta_tags']) && Metasync::get_option('common_meta_settings')['open_graph_meta_tags'] == 'true' ? 'checked' : ''
        );
        printf(' <br> <span class="description"> Automatically add the Open Graph meta tags in a page or post.</span>');
    }

    public function global_facebook_meta_callback()
    {
        printf(
            '<input type="checkbox" name="' . Metasync_Admin::option_key . '[common_meta_settings][facebook_meta_tags]" value="true" %s />',
            isset(Metasync::get_option('common_meta_settings')['facebook_meta_tags']) && Metasync::get_option('common_meta_settings')['facebook_meta_tags'] == 'true' ? 'checked' : ''
        );
        printf(' <br> <span class="description"> Automatically add the Facebook meta tags in a page or post.</span>');
    }

    public function global_twitter_meta_callback()
    {
        printf(
            '<input type="checkbox" name="' . Metasync_Admin::option_key . '[common_meta_settings][twitter_meta_tags]" value="true" %s />',
            isset(Metasync::get_option('common_meta_settings')['twitter_meta_tags']) && Metasync::get_option('common_meta_settings')['twitter_meta_tags'] == 'true' ? 'checked' : ''
        );
        printf(' <br> <span class="description"> Automatically add the Twitter meta tags in a page or post.</span>');
    }

    public function facebook_page_url_callback()
    {
        $facebook_page_url = Metasync::get_option('social_meta')['facebook_page_url'] ?? '';
        printf('<input type="text" name="' . Metasync_Admin::option_key . '[social_meta][facebook_page_url]" value="%s" size="50" />', esc_attr($facebook_page_url));
        printf('<br><span class="description"> Enter your Facebook page URL. eg: <code>https://www.facebook.com/MetaSync/</code> </span>');
    }

    public function facebook_authorship_callback()
    {
        $facebook_authorship = Metasync::get_option('social_meta')['facebook_authorship'] ?? '';
        printf('<input type="text" name="' . Metasync_Admin::option_key . '[social_meta][facebook_authorship]" value="%s" size="50" />', esc_attr($facebook_authorship));
        printf('<br><span class="description"> Enter Facebook profile URL to show Facebook Authorship when your articles are being shared on Facebook. eg: <code>https://www.facebook.com/shahrukh/</code> </span>');
    }

    public function facebook_admin_callback()
    {
        $facebook_admin = Metasync::get_option('social_meta')['facebook_admin'] ?? '';
        printf('<input type="text" name="' . Metasync_Admin::option_key . '[social_meta][facebook_admin]" value="%s" size="50" />', esc_attr($facebook_admin));
        printf(' <br> <span class="description"> Enter numeric user ID of Facebook. </span>');
    }

    public function facebook_app_callback()
    {
        $facebook_app = Metasync::get_option('social_meta')['facebook_app'] ?? '';
        printf('<input type="text" name="' . Metasync_Admin::option_key . '[social_meta][facebook_app]" value="%s" size="50" />', esc_attr($facebook_app));
        printf(' <br> <span class="description"> Enter numeric app ID of Facebook </span>');
    }

    public function facebook_secret_callback()
    {
        $facebook_secret = Metasync::get_option('social_meta')['facebook_secret'] ?? '';
        printf('<input type="text" name="' . Metasync_Admin::option_key . '[social_meta][facebook_secret]" value="%s" size="50" />', esc_attr($facebook_secret));
        printf(' <br> <span class="description"> Enter alphanumeric access token from Facebook. </span>');
    }

    public function twitter_username_callback()
    {
        $twitter_username = Metasync::get_option('social_meta')['twitter_username'] ?? '';
        printf('<input type="text" name="' . Metasync_Admin::option_key . '[social_meta][twitter_username]" value="%s" size="50" />', esc_attr($twitter_username));
        printf(' <br> <span class="description"> Twitter username of the author to add <code>twitter:creator</code> tag to post. eg: <code>MetaSync</code> </span>');
    }

    public static function get_business_types()
    {
        $business_type = [
            'Airline',
            'Consortium',
            'Corporation',
            'Educational Organization',
            'College Or University',
            'Elementary School',
            'High School',
            'Middle School',
            'Preschool',
            'School',
            'Funding Scheme',
            'Government Organization',
            'Library System',
            'Local Business',
            'Animal Shelter',
            'Archive Organization',
            'Automotive Business',
            'Auto Body Shop',
            'Auto Dealer',
            'Auto Parts Store',
            'Auto Rental',
            'Auto Repair',
            'Auto Wash',
            'Gas Station',
            'Motorcycle Dealer',
            'Motorcycle Repair',
            'Child Care',
            'Dry Cleaning Or Laundry',
            'Emergency Service',
            'Fire Station',
            'Hospital',
            'Police Station',
            'Employment Agency',
            'Entertainment Business',
            'Adult Entertainment',
            'Amusement Park',
            'Art Gallery',
            'Casino',
            'Comedy Club',
            'Movie Theater',
            'Night Club',
            'Financial Service',
            'Accounting Service',
            'Automated Teller',
            'Bank Or CreditUnion',
            'Insurance Agency',
            'Food Establishment',
            'Bakery',
            'Bar Or Pub',
            'Brewery',
            'Cafe Or CoffeeShop',
            'Distillery',
            'Fast Food Restaurant',
            'IceCream Shop',
            'Restaurant',
            'Winery',
            'Government Office',
            'Post Office',
            'Health And Beauty Business',
            'Beauty Salon',
            'Day Spa',
            'Hair Salon',
            'Health Club',
            'Nail Salon',
            'Tattoo Parlor',
            'Home And Construction Business',
            'Electrician',
            'General Contractor',
            'HVAC Business',
            'House Painter',
            'Locksmith',
            'Moving Company',
            'Plumber',
            'Roofing Contractor',
            'Internet Cafe',
            'Legal Service',
            'Attorney',
            'Notary',
            'Library',
            'Lodging Business',
            'Bed And Breakfast',
            'Campground',
            'Hostel',
            'Hotel',
            'Motel',
            'Resort',
            'Ski Resort',
            'Medical Business',
            'Community Health',
            'Dentist',
            'Dermatology',
            'Diet Nutrition',
            'Emergency',
            'Geriatric',
            'Gynecologic',
            'Medical Clinic',
            'Optician',
            'Pharmacy',
            'Physician',
            'Professional Service',
            'Radio Station',
            'Real Estate Agent',
            'Recycling Center',
            'Self Storage',
            'Shopping Center',
            'Sports Activity Location',
            'Bowling Alley',
            'Exercise Gym',
            'Golf Course',
            'Public Swimming Pool',
            'Ski Resort',
            'Sports Club',
            'Stadium Or Arena',
            'Tennis Complex',
            'Store',
            'Bike Store',
            'Book Store',
            'Clothing Store',
            'Computer Store',
            'Convenience Store',
            'Department Store',
            'Electronics Store',
            'Florist',
            'Furniture Store',
            'Garden Store',
            'Grocery Store',
            'Hardware Store',
            'Hobby Shop',
            'Home Goods Store',
            'Jewelry Store',
            'Liquor Store',
            'Mens Clothing Store',
            'Mobile Phone Store',
            'Movie Rental Store',
            'Music Store',
            'Office Equipment Store',
            'Outlet Store',
            'Pawn Shop',
            'Pet Store',
            'Shoe Store',
            'Sporting GoodsStore',
            'Tire Shop',
            'Toy Store',
            'Wholesale Store',
            'Television Station',
            'Tourist Information Center',
            'Travel Agency',
            'Tree Services',
            'Medical Organization',
            'Diagnostic Lab',
            'Veterinary Care',
            'NGO',
            'News Media Organization',
            'Performing Group',
            'Dance Group',
            'Music Group',
            'Theater Group',
            'Project',
            'Funding Agency',
            'Research Project',
            'Sports Organization',
            'Sports Team',
            'Workers Union',
        ];

        return $business_type;
    }
}
