<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Debug / Error-Logging manager.
 *
 * Extracted from Metasync_Admin to keep the admin class focused on UI concerns.
 * Handles debug-mode rendering, error-log display, wp-config updates, and
 * related form-post handlers.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */
class Metasync_Debug_Manager
{
    /** @var self|null */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return self
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Get the admin page slug.
     */
    private function get_page_slug()
    {
        return Metasync_Admin::$page_slug;
    }

    /**
     * Read an execution setting (mirrors Metasync_Admin::get_execution_setting).
     */
    private function get_execution_setting($key, $default = null)
    {
        $settings = get_option('metasync_execution_settings', array());
        $defaults = array(
            'max_execution_time' => 30,
            'max_memory_limit' => 256,
            'log_batch_size' => 1000,
            'action_scheduler_batches' => 1,
            'otto_rate_limit' => 10,
            'queue_cleanup_days' => 31
        );

        if (empty($settings)) {
            return isset($defaults[$key]) ? $defaults[$key] : $default;
        }

        return isset($settings[$key]) ? $settings[$key] : (isset($defaults[$key]) ? $defaults[$key] : $default);
    }

    // ------------------------------------------------------------------
    //  Error-log page (standalone page)
    // ------------------------------------------------------------------

    /**
     * Display the standalone Error Log admin page.
     *
     * @param Metasync_Admin $admin The admin instance (needed for header/nav rendering).
     */
    public function metasync_display_error_log($admin) {
        $log_file = WP_CONTENT_DIR . '/metasync_data/plugin_errors.log';
        if (!Metasync::current_user_has_plugin_access()) {
            return;
        }
    
        if (isset($_POST['wp_debug_log_enabled']) && 
            isset($_POST['wp_debug_enabled']) && 
            isset($_POST['wp_debug_display_enabled']) &&
            isset($_POST['wp_debug_nonce']) &&
            wp_verify_nonce($_POST['wp_debug_nonce'], 'metasync_wp_debug_settings')) {
            
            $wp_debug = in_array($_POST['wp_debug_enabled'], ['true', 'false']) ? $_POST['wp_debug_enabled'] : 'false';
            $wp_debug_log = in_array($_POST['wp_debug_log_enabled'], ['true', 'false']) ? $_POST['wp_debug_log_enabled'] : 'false';
            $wp_debug_display = in_array($_POST['wp_debug_display_enabled'], ['true', 'false']) ? $_POST['wp_debug_display_enabled'] : 'false';
            
            update_option('wp_debug_enabled', $wp_debug);
            update_option('wp_debug_log_enabled', $wp_debug_log);
            update_option('wp_debug_display_enabled', $wp_debug_display);
            
            $data = new ConfigControllerMetaSync();
            $data->store();
            
            add_settings_error(
                'metasync_messages',
                'metasync_message',
                'WordPress debug settings updated successfully.',
                'updated'
            );
        } elseif (isset($_POST['wp_debug_nonce']) && !wp_verify_nonce($_POST['wp_debug_nonce'], 'metasync_wp_debug_settings')) {
            add_settings_error(
                'metasync_messages',
                'metasync_message',
                'Security verification failed. Please try again.',
                'error'
            );
        }
       
    
        $log_enabled = get_option('metasync_log_enabled', 'yes');
        $wp_debug_enabled = get_option('wp_debug_enabled', 'false');
        $wp_debug_log_enabled = get_option('wp_debug_log_enabled', 'false');
        $wp_debug_display_enabled = get_option('wp_debug_display_enabled', 'false');
        ?>
    
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">

        
        <?php
        $admin->render_plugin_header('Error Logs');
        $admin->render_navigation_menu('error-log');
        ?>
            
            <!-- Log File Management -->
            <div class="dashboard-card">
                <h2>🗑️ Error Log Management</h2>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Clear WordPress error logs to free up space and remove old entries.</p>
                
                <form method="post" style="margin-top: 15px;">
                    <input type="hidden" name="clear_log" value="yes" />
                    <?php wp_nonce_field('metasync_clear_log_nonce', 'clear_log_nonce'); ?>
                    <?php submit_button('🧹 Clear Error Logs', 'secondary', 'clear-log', false, array('class' => 'button button-secondary')); ?>
            </form>
            </div>
            
            <!-- WordPress Debug Settings -->
            <form method="post">
                <?php wp_nonce_field('metasync_wp_debug_settings', 'wp_debug_nonce'); ?>
                <div class="dashboard-card">
                    <h2>🔧 WordPress Debug Configuration</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure WordPress debug settings to control error logging and display.</p>
                    <?php settings_errors('metasync_messages'); ?>
                    
                <table class="form-table">
    <tr valign="top">
        <th scope="row">WP_DEBUG</th>
        <td>
            <select name="wp_debug_enabled">
                <option value="false" <?php selected('false', $wp_debug_enabled); ?>>Disabled</option>
                <option value="true" <?php selected('true', $wp_debug_enabled); ?>>Enabled</option>                
            </select>
                                <p class="description">Enable or disable WordPress debugging mode.</p>
        </td>
    </tr>
    <tr valign="top">
        <th scope="row">WP_DEBUG_LOG</th>
        <td>
            <select name="wp_debug_log_enabled">
                <option value="false" <?php selected('false', $wp_debug_log_enabled); ?>>Disabled</option>
                <option value="true" <?php selected('true', $wp_debug_log_enabled); ?>>Enabled</option>                
            </select>
                                <p class="description">Save debug messages to a log file.</p>
        </td>
    </tr>
    <tr valign="top">
        <th scope="row">WP_DEBUG_DISPLAY</th>
        <td>
            <select name="wp_debug_display_enabled">
                <option value="false" <?php selected('false', $wp_debug_display_enabled); ?>>Disabled</option>
                <option value="true" <?php selected('true', $wp_debug_display_enabled); ?>>Enabled</option>                
            </select>
                                <p class="description">Display debug messages on the website (not recommended for production).</p>
        </td>
    </tr>
</table>
        </div>
                
                <div class="dashboard-card">
                    <h2>💾 Save Changes</h2>
                    <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Apply your WordPress logging configuration changes.</p>
                    <?php submit_button('Save WordPress Logging Settings', 'primary', 'submit', false, array('class' => 'button button-primary')); ?>
                </div>
            </form>
            
            <!-- Error Log Display -->
            <div class="dashboard-card">
                <h2>📄 Error Log Contents</h2>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">View the current error log entries for troubleshooting and monitoring.</p>
                
        <?php 
        if (file_exists($log_file)) {
                    $log_content = file_get_contents($log_file);
                    if (!empty($log_content)) {
                        echo '<div class="dashboard-code-block" style="width: 100%; box-sizing: border-box;">';
                        echo '<pre style="background: var(--dashboard-card-bg); border: 1px solid var(--dashboard-border); border-radius: 8px; padding: 20px; overflow: auto; max-height: 400px; font-family: \'SF Mono\', Monaco, \'Cascadia Code\', \'Roboto Mono\', Consolas, monospace; font-size: 13px; line-height: 1.6; color: var(--dashboard-text-primary); margin: 0; box-shadow: var(--dashboard-shadow-sm); width: 100%; box-sizing: border-box; white-space: pre-wrap; word-wrap: break-word;">';
                        echo esc_html($log_content);
            echo '</pre>';
                        echo '</div>';
        } else {
                        echo '<div class="dashboard-empty-state">';
                        echo '<p style="color: var(--dashboard-text-secondary); font-style: italic; text-align: center; padding: 40px 20px;">✅ Log file is empty - no errors recorded.</p>';
                        echo '</div>';
                    }
                } else {
                    echo '<div class="dashboard-empty-state">';
                    echo '<p style="color: var(--dashboard-text-secondary); font-style: italic; text-align: center; padding: 40px 20px;">📝 No log file found. Error logging may not be enabled.</p>';
                    echo '</div>';
                }
                ?>
            </div>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    //  wp-config.php manipulation
    // ------------------------------------------------------------------

    /**
     * Update wp-config.php debug constants.
     */
    public function metasync_update_wp_config() {
        $wp_config_path = ABSPATH . 'wp-config.php';
        if (file_exists($wp_config_path) && is_writable($wp_config_path)) {
            $config_file = file_get_contents($wp_config_path);
    
            $wp_debug_enabled = get_option('wp_debug_enabled', 'false') === 'true' ? 'true' : 'false';
            if (preg_match("/define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*.*?\s*\)\s*;/", $config_file)) {
                $config_file = preg_replace("/define\s*\(\s*['\"]WP_DEBUG['\"]\s*,\s*.*?\s*\)\s*;/", "define('WP_DEBUG', $wp_debug_enabled);", $config_file);
            } else {
                $config_file = str_replace("/* That's all, stop editing! Happy publishing. */", "define('WP_DEBUG', $wp_debug_enabled);\n\n/* That's all, stop editing! Happy publishing. */", $config_file);
            }
    
            $wp_debug_log_enabled = get_option('wp_debug_log_enabled', 'false') === 'true' ? 'true' : 'false';
            if (preg_match("/define\s*\(\s*['\"]WP_DEBUG_LOG['\"]\s*,\s*.*?\s*\)\s*;/", $config_file)) {
                $config_file = preg_replace("/define\s*\(\s*['\"]WP_DEBUG_LOG['\"]\s*,\s*.*?\s*\)\s*;/", "define('WP_DEBUG_LOG', $wp_debug_log_enabled);", $config_file);
            } else {
                $config_file = str_replace("/* That's all, stop editing! Happy publishing. */", "define('WP_DEBUG_LOG', $wp_debug_log_enabled);\n\n/* That's all, stop editing! Happy publishing. */", $config_file);
            }
    
            $wp_debug_display_enabled = get_option('wp_debug_display_enabled', 'false') === 'true' ? 'true' : 'false';
            if (preg_match("/define\s*\(\s*['\"]WP_DEBUG_DISPLAY['\"]\s*,\s*.*?\s*\)\s*;/", $config_file)) {
                $config_file = preg_replace("/define\s*\(\s*['\"]WP_DEBUG_DISPLAY['\"]\s*,\s*.*?\s*\)\s*;/","define('WP_DEBUG_DISPLAY', $wp_debug_display_enabled);", $config_file);
            } else {
                $config_file = str_replace("/* That's all, stop editing! Happy publishing. */", "define('WP_DEBUG_DISPLAY', $wp_debug_display_enabled);\n\n/* That's all, stop editing! Happy publishing. */", $config_file);
            }
    
            file_put_contents($wp_config_path, $config_file);
        } else {
            wp_die('The wp-config.php file is not writable. Please check the file permissions.');
        }
    }

    // ------------------------------------------------------------------
    //  Options hook
    // ------------------------------------------------------------------

    /**
     * Sync plugin file headers when metasync_options is updated.
     *
     * @param mixed $old_value The old option value.
     * @param mixed $new_value The new option value.
     */
    public function on_options_updated_sync_file_headers($old_value, $new_value)
    {
        $old_general = is_array($old_value) ? ($old_value['general'] ?? []) : [];
        $new_general = is_array($new_value) ? ($new_value['general'] ?? []) : [];

        $whitelabel_keys = [
            'white_label_plugin_name',
            'white_label_plugin_description',
            'white_label_plugin_author',
            'white_label_plugin_author_uri',
            'white_label_plugin_uri',
        ];

        $changed = false;
        foreach ($whitelabel_keys as $key) {
            if (($old_general[$key] ?? '') !== ($new_general[$key] ?? '')) {
                $changed = true;
                break;
            }
        }

        if ($changed) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-activator.php';
            Metasync_Activator::sync_plugin_file_headers();
        }
    }

    // ------------------------------------------------------------------
    //  Rendering (Advanced-tab sections)
    // ------------------------------------------------------------------

    /**
     * Render debug mode section for inclusion in Advanced settings.
     */
    public function render_debug_mode_section()
    {
        if (!class_exists('Metasync_Debug_Mode_Manager')) {
            ?>
            <div style="background: rgba(255, 193, 7, 0.1); border: 1px solid rgba(255, 193, 7, 0.3); border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                <p style="color: var(--dashboard-text-primary); margin: 0;">
                    ⚠️ Debug Mode Manager is not available. Please ensure the plugin is properly installed.
                </p>
            </div>
            <?php
            return;
        }

        $debug_manager = Metasync_Debug_Mode_Manager::get_instance();
        $status = $debug_manager->get_status();
        ?>

        <!-- Debug Mode Status Overview -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);">Current Status</h4>
            <div style="background: rgba(255, 255, 255, 0.05); border: 1px solid var(--dashboard-border); padding: 20px; border-radius: 8px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <!-- Status Badge -->
                    <div style="padding: 10px; border-left: 4px solid <?php echo $status['enabled'] ? '#ffc107' : '#4caf50'; ?>; background: rgba(<?php echo $status['enabled'] ? '255, 193, 7' : '76, 175, 80'; ?>, 0.1); border-radius: 4px;">
                        <div style="font-size: 12px; color: var(--dashboard-text-secondary); margin-bottom: 5px;">Status</div>
                        <div style="font-weight: 600; color: var(--dashboard-text-primary); font-size: 16px;">
                            <?php echo $status['enabled'] ? '⚠️ Active' : '✓ Inactive'; ?>
                        </div>
                    </div>

                    <?php if ($status['enabled']): ?>
                    <!-- Mode Type -->
                    <div style="padding: 10px; border-left: 4px solid #2196f3; background: rgba(33, 150, 243, 0.1); border-radius: 4px;">
                        <div style="font-size: 12px; color: var(--dashboard-text-secondary); margin-bottom: 5px;">Mode</div>
                        <div style="font-weight: 600; color: var(--dashboard-text-primary); font-size: 14px;">
                            <?php echo $status['indefinite'] ? 'Indefinite' : '24-Hour Auto-Disable'; ?>
                        </div>
                    </div>

                    <?php if (!$status['indefinite']): ?>
                    <!-- Time Remaining -->
                    <div style="padding: 10px; border-left: 4px solid #9c27b0; background: rgba(156, 39, 176, 0.1); border-radius: 4px;">
                        <div style="font-size: 12px; color: var(--dashboard-text-secondary); margin-bottom: 5px;">Time Remaining</div>
                        <div style="font-weight: 600; color: var(--dashboard-text-primary); font-size: 14px;" class="debug-time-remaining">
                            <?php echo esc_html($status['time_remaining_formatted']); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Log File Size -->
                    <div style="padding: 10px; border-left: 4px solid #ff5722; background: rgba(255, 87, 34, 0.1); border-radius: 4px;">
                        <div style="font-size: 12px; color: var(--dashboard-text-secondary); margin-bottom: 5px;">Log File Size</div>
                        <div style="font-weight: 600; color: var(--dashboard-text-primary); font-size: 14px;">
                            <?php echo esc_html($status['log_file_size_formatted']); ?>
                        </div>
                        <div style="font-size: 11px; color: var(--dashboard-text-secondary); margin-top: 2px;">
                            <?php echo number_format($status['percentage_used'], 1); ?>% of <?php echo esc_html($status['max_log_size_formatted']); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($status['enabled']): ?>
                <!-- Progress Bar -->
                <div style="margin-top: 15px;">
                    <div style="background: rgba(255, 255, 255, 0.1); height: 8px; border-radius: 4px; overflow: hidden;">
                        <div style="height: 100%; width: <?php echo esc_attr($status['percentage_used']); ?>%; background: linear-gradient(90deg, #4caf50 0%, #ffc107 70%, #f44336 100%); transition: width 0.3s ease;"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Debug Mode Controls -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);">Controls</h4>

            <form method="post" action="<?php echo admin_url('admin.php?page=' . $this->get_page_slug() . '&tab=advanced'); ?>">
                <input type="hidden" name="metasync_debug_mode_action_advanced" value="1" />
                <?php wp_nonce_field('metasync_debug_mode_action_advanced', 'metasync_debug_mode_nonce_advanced'); ?>

                <?php if (!$status['enabled']): ?>
                    <!-- Enable Debug Mode -->
                    <div style="background: rgba(255, 255, 255, 0.05); border: 1px solid var(--dashboard-border); padding: 20px; border-radius: 8px; margin-bottom: 15px;">
                        <p style="color: var(--dashboard-text-secondary); margin-top: 0; margin-bottom: 15px;">
                            Activate debug mode to troubleshoot issues. Debug mode will automatically disable after 24 hours unless you enable indefinite mode.
                        </p>

                        <label style="display: flex; align-items: center; margin: 15px 0; cursor: pointer;">
                            <input type="checkbox" name="indefinite" value="1" id="indefinite-mode-advanced" style="margin-right: 8px;" />
                            <span style="font-weight: 500; color: var(--dashboard-text-primary);">Keep debug mode enabled indefinitely</span>
                        </label>

                        <div id="indefinite-warning-advanced" style="display: none; background: rgba(255, 193, 7, 0.1); border-left: 4px solid #ffc107; padding: 12px; border-radius: 4px; margin: 15px 0;">
                            <strong style="color: var(--dashboard-text-primary);">⚠️ Warning:</strong>
                            <span style="color: var(--dashboard-text-secondary);"> Indefinite debug mode may cause log files to grow without limits. This should only be used for extended troubleshooting sessions.</span>
                        </div>

                        <input type="hidden" name="action_type" value="enable" />
                        <button type="submit" class="metasync-btn-primary" style="background: var(--dashboard-gradient-primary); color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); display: inline-block; width: auto; min-width: 200px;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0, 0, 0, 0.15)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0, 0, 0, 0.1)';">
                            🐛 Enable Debug Mode
                        </button>
                    </div>

                <?php else: ?>
                    <!-- Manage Active Debug Mode -->
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <?php if (!$status['indefinite']): ?>
                        <div>
                            <input type="hidden" name="action_type" value="extend" />
                            <button type="submit" class="metasync-btn-secondary" style="background: rgba(255, 255, 255, 0.1); color: var(--dashboard-text-primary); border: 1px solid var(--dashboard-border); padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; display: inline-block; width: auto;" onmouseover="this.style.background='rgba(255, 255, 255, 0.15)';" onmouseout="this.style.background='rgba(255, 255, 255, 0.1)';">
                                ⏱️ Extend for 24 Hours
                            </button>
                        </div>
                        <?php endif; ?>

                        <div>
                            <input type="hidden" name="action_type" value="disable" />
                            <button type="submit" class="metasync-btn-danger" onclick="return confirm('Are you sure you want to disable debug mode?');" style="background: linear-gradient(135deg, #f44336, #d32f2f); color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); display: inline-block; width: auto;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0, 0, 0, 0.15)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0, 0, 0, 0.1)';">
                                ⏹️ Disable Debug Mode Now
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Configuration Details -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);">Configuration Details</h4>
            <div style="background: rgba(255, 255, 255, 0.05); border: 1px solid var(--dashboard-border); border-radius: 8px; padding: 20px;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tbody>
                        <tr style="border-bottom: 1px solid var(--dashboard-border);">
                            <td style="padding: 10px 0; font-weight: 600; color: var(--dashboard-text-primary);">Maximum Log Size</td>
                            <td style="padding: 10px 0; color: var(--dashboard-text-secondary);"><?php echo esc_html($status['max_log_size_formatted']); ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid var(--dashboard-border);">
                            <td style="padding: 10px 0; font-weight: 600; color: var(--dashboard-text-primary);">Auto-Disable Duration</td>
                            <td style="padding: 10px 0; color: var(--dashboard-text-secondary);">24 hours</td>
                        </tr>
                        <tr style="border-bottom: 1px solid var(--dashboard-border);">
                            <td style="padding: 10px 0; font-weight: 600; color: var(--dashboard-text-primary);">Log Rotation</td>
                            <td style="padding: 10px 0; color: var(--dashboard-text-secondary);">Automatic when size limit reached</td>
                        </tr>
                        <tr style="border-bottom: 1px solid var(--dashboard-border);">
                            <td style="padding: 10px 0; font-weight: 600; color: var(--dashboard-text-primary);">Rotated Files Kept</td>
                            <td style="padding: 10px 0; color: var(--dashboard-text-secondary);">1 (current + 1 old)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid var(--dashboard-border);">
                            <td style="padding: 10px 0; font-weight: 600; color: var(--dashboard-text-primary);">Check Frequency</td>
                            <td style="padding: 10px 0; color: var(--dashboard-text-secondary);">Hourly (via WP Cron)</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px 0; font-weight: 600; color: var(--dashboard-text-primary);">Log File Path</td>
                            <td style="padding: 10px 0; color: var(--dashboard-text-secondary); font-family: monospace; font-size: 12px; word-break: break-all;">
                                <?php echo esc_html($status['log_file_path']); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#indefinite-mode-advanced').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#indefinite-warning-advanced').slideDown();
                } else {
                    $('#indefinite-warning-advanced').slideUp();
                }
            });

            <?php if ($status['enabled'] && !$status['indefinite']): ?>
            var initialTimeRemaining = <?php echo $status['time_remaining']; ?>;
            var hasReloaded = false;

            function updateDebugTimeRemaining() {
                if (initialTimeRemaining <= 0 || hasReloaded) {
                    return;
                }

                $.ajax({
                    url: '<?php echo rest_url('metasync/v1/debug-mode/status'); ?>',
                    method: 'GET',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
                    },
                    success: function(response) {
                        console.log('MetaSync Debug Mode Status:', response);

                        if (response && typeof response.time_remaining !== 'undefined') {
                            if (response.time_remaining_formatted) {
                                $('.debug-time-remaining').text(response.time_remaining_formatted);
                            }

                            if (response.time_remaining <= 0 && initialTimeRemaining > 0 && !hasReloaded) {
                                hasReloaded = true;
                                console.log('Debug mode expired, reloading page...');
                                setTimeout(function() {
                                    window.location.reload();
                                }, 2000);
                            }
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('MetaSync Debug Mode: Failed to update time remaining', error);
                    }
                });
            }

            if (initialTimeRemaining > 0) {
                setTimeout(updateDebugTimeRemaining, 2000);
                setInterval(updateDebugTimeRemaining, 60000);
            } else {
                console.log('Debug mode already expired, skipping AJAX updates');
            }
            <?php endif; ?>
        });
        </script>
        <?php
    }

    /**
     * Render error log content for inclusion in Advanced settings.
     */
    public function render_error_log_content()
    {
        ?>
        <!-- Error Summary Section -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);">📊 Error Summary</h4>
            <p style="margin-bottom: 15px; color: var(--dashboard-text-secondary);">View categorized error statistics with counts and last occurrence times.</p>
            
            <?php
            if (class_exists('Metasync_Error_Logger')) {
                $error_summary = Metasync_Error_Logger::get_error_summary();
                
                if (!empty($error_summary) && is_array($error_summary)) {
                    uasort($error_summary, function($a, $b) {
                        return strtotime($b['last_seen']) - strtotime($a['last_seen']);
                    });
                    ?>
                    <div style="overflow-x: auto; margin-bottom: 20px;">
                        <table class="wp-list-table widefat fixed striped" style="background: var(--dashboard-card-bg); border: 1px solid var(--dashboard-border);">
                            <thead>
                                <tr style="background: var(--dashboard-card-bg);">
                                    <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--dashboard-border); color: var(--dashboard-text-primary); font-weight: 600;">Error Category</th>
                                    <th style="padding: 12px; text-align: center; border-bottom: 2px solid var(--dashboard-border); color: var(--dashboard-text-primary); font-weight: 600;">Error Code</th>
                                    <th style="padding: 12px; text-align: center; border-bottom: 2px solid var(--dashboard-border); color: var(--dashboard-text-primary); font-weight: 600;">Count</th>
                                    <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--dashboard-border); color: var(--dashboard-text-primary); font-weight: 600;">Last Occurred</th>
                                    <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--dashboard-border); color: var(--dashboard-text-primary); font-weight: 600;">Message</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($error_summary as $key => $error): ?>
                                    <tr>
                                        <td style="padding: 10px 12px; color: var(--dashboard-text-primary);">
                                            <strong><?php echo esc_html($error['category']); ?></strong>
                                        </td>
                                        <td style="padding: 10px 12px; text-align: center; color: var(--dashboard-text-secondary); font-family: monospace;">
                                            <code style="background: rgba(255, 255, 255, 0.1); padding: 2px 6px; border-radius: 3px;"><?php echo esc_html($error['code']); ?></code>
                                        </td>
                                        <td style="padding: 10px 12px; text-align: center;">
                                            <span style="display: inline-block; background: var(--dashboard-accent); color: #ffffff; padding: 4px 10px; border-radius: 12px; font-weight: 600; font-size: 13px;">
                                                <?php echo esc_html(number_format($error['count'])); ?>
                                            </span>
                                        </td>
                                        <td style="padding: 10px 12px; color: var(--dashboard-text-secondary); font-size: 13px;">
                                            <?php 
                                            $last_seen = strtotime($error['last_seen']);
                                            $time_diff = human_time_diff($last_seen, current_time('timestamp'));
                                            echo esc_html($error['last_seen']) . ' <span style="color: var(--dashboard-text-secondary);">(' . $time_diff . ' ago)</span>';
                                            ?>
                                        </td>
                                        <td style="padding: 10px 12px; color: var(--dashboard-text-primary); max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo esc_attr($error['message']); ?>">
                                            <?php echo esc_html($error['message']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <form method="post" action="<?php echo admin_url('admin.php?page=' . $this->get_page_slug() . '&tab=advanced'); ?>" style="margin-bottom: 20px;">
                        <input type="hidden" name="clear_error_summary" value="yes" />
                        <?php wp_nonce_field('metasync_clear_error_summary_nonce', 'clear_error_summary_nonce'); ?>
                        <button type="submit" class="button button-secondary" style="background: #dc3232; color: #ffffff; border: none; padding: 8px 16px; border-radius: 4px; font-weight: 500; cursor: pointer;">
                            🗑️ Clear Error Summary
                        </button>
                    </form>
                    <?php
                } else {
                    ?>
                    <div class="dashboard-empty-state" style="padding: 30px; text-align: center; background: var(--dashboard-card-bg); border: 1px solid var(--dashboard-border); border-radius: 8px;">
                        <p style="color: var(--dashboard-text-secondary); font-style: italic; margin: 0;">
                            ✅ No errors recorded yet. Error summary will appear here once errors are logged.
                        </p>
                    </div>
                    <?php
                }
            } else {
                ?>
                <div class="dashboard-empty-state" style="padding: 30px; text-align: center; background: rgba(255, 193, 7, 0.1); border: 1px solid rgba(255, 193, 7, 0.3); border-radius: 8px;">
                    <p style="color: var(--dashboard-text-primary); margin: 0;">
                        ⚠️ Error Logger class not available. Please ensure the plugin is properly loaded.
                    </p>
                </div>
                <?php
            }
            ?>
        </div>

        <!-- Error Log Management -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);">Clear Error Logs</h4>
            <p style="margin-bottom: 15px; color: var(--dashboard-text-secondary);">Clear WordPress error logs to free up space and remove old entries.</p>
            
            <form method="post" action="<?php echo admin_url('admin.php?page=' . $this->get_page_slug() . '&tab=advanced'); ?>" style="margin-bottom: 20px;">
                <input type="hidden" name="clear_log" value="yes" />
                <?php wp_nonce_field('metasync_clear_log_nonce', 'clear_log_nonce'); ?>
                <button type="submit" class="metasync-btn-primary" style="background: var(--dashboard-gradient-primary); color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); display: inline-block; width: auto; min-width: 240px; max-width: fit-content;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0, 0, 0, 0.15)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0, 0, 0, 0.1)';">
                    🧹 Clear Error Logs
                </button>
            </form>
        </div>

        <!-- WordPress Debug Settings -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);">WordPress Debug Configuration</h4>
            <p style="margin-bottom: 15px; color: var(--dashboard-text-secondary);">Configure WordPress debug settings to control error logging and display.</p>

            <form method="post">
                
                <?php
                $wp_debug = defined('WP_DEBUG') && WP_DEBUG;
                $debug_log = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
                $debug_display = defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY;
                ?>
                
                <p><strong>Current WordPress Debug Status:</strong></p>
                <ul>
                    <li>WP_DEBUG: <?php echo $wp_debug ? '✅ Enabled' : '❌ Disabled'; ?></li>
                    <li>WP_DEBUG_LOG: <?php echo $debug_log ? '✅ Enabled' : '❌ Disabled'; ?></li>
                    <li>WP_DEBUG_DISPLAY: <?php echo $debug_display ? '✅ Enabled' : '❌ Disabled'; ?></li>
                </ul>
                
                <?php if (!$wp_debug): ?>
                <p style="color: var(--dashboard-accent);">💡 To enable error logging, add these lines to your wp-config.php file:</p>
                <pre style="background: rgba(255, 255, 255, 0.05); border: 1px solid var(--dashboard-border); padding: 10px; border-radius: 4px; color: var(--dashboard-text-primary);">define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);</pre>
                <?php endif; ?>
            </form>
        </div>

        <!-- Error Log Display -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);">Error Log Contents</h4>
            <p style="margin-bottom: 15px; color: var(--dashboard-text-secondary);">View the current error log entries for troubleshooting and monitoring.</p>
            
            <?php
            $error_logs = new Metasync_Error_Logs();

            if ($error_logs->can_show_error_logs()): 
                $log_content = $error_logs->get_error_logs(50);
                
                if (!empty(trim($log_content))):
                    $error_logs->show_copy_button();
                    $error_logs->show_logs();
                    $error_logs->show_info();
                else: ?>
                    <div class="dashboard-empty-state">
                        <p style="color: var(--dashboard-text-secondary); font-style: italic; text-align: center; padding: 40px 20px;">✅ Log file is empty - no errors recorded.</p>
                    </div>
                <?php endif;
            else: 
                $error_message = $error_logs->get_error_message();
                if (!empty($error_message)): ?>
                    <div class="dashboard-empty-state">
                        <p style="color: var(--dashboard-text-primary); font-weight: bold; text-align: center; padding: 20px; background: rgba(255, 193, 7, 0.1); border: 1px solid rgba(255, 193, 7, 0.3); border-radius: 4px; margin: 20px 0;">
                            ⚠️ <?php echo esc_html($error_message); ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="dashboard-empty-state">
                        <p style="color: var(--dashboard-text-secondary); font-style: italic; text-align: center; padding: 40px 20px;">⚠️ Unable to access error log file. Please check permissions.</p>
                    </div>
                <?php endif;
            endif; ?>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    //  Form-post handlers
    // ------------------------------------------------------------------

    /**
     * Handle debug mode operations (enable/disable/extend).
     */
    public function handle_debug_mode_operations()
    {
        if (isset($_POST['metasync_debug_mode_action_advanced'])) {
            if (!wp_verify_nonce($_POST['metasync_debug_mode_nonce_advanced'], 'metasync_debug_mode_action_advanced')) {
                $redirect_url = admin_url('admin.php?page=' . $this->get_page_slug() . '&tab=advanced&debug_error=1');
                wp_redirect($redirect_url);
                exit;
            }

            if (!current_user_can('manage_options')) {
                wp_die('Unauthorized');
            }

            if (!class_exists('Metasync_Debug_Mode_Manager')) {
                $redirect_url = admin_url('admin.php?page=' . $this->get_page_slug() . '&tab=advanced&debug_error=1&msg=manager_not_available');
                wp_redirect($redirect_url);
                exit;
            }

            $debug_manager = Metasync_Debug_Mode_Manager::get_instance();
            $action = sanitize_text_field($_POST['action_type'] ?? '');
            $redirect_url = admin_url('admin.php?page=' . $this->get_page_slug() . '&tab=advanced');

            switch ($action) {
                case 'enable':
                    $indefinite = isset($_POST['indefinite']) && $_POST['indefinite'] === '1';
                    $result = $debug_manager->enable_debug_mode($indefinite);

                    $redirect_url = add_query_arg('debug_mode_enabled', '1', $redirect_url);
                    if ($indefinite) {
                        $redirect_url = add_query_arg('indefinite', '1', $redirect_url);
                    }
                    break;

                case 'disable':
                    $result = $debug_manager->disable_debug_mode('manual');
                    if ($result) {
                        $redirect_url = add_query_arg('debug_mode_disabled', '1', $redirect_url);
                    } else {
                        $redirect_url = add_query_arg('debug_error', '1', $redirect_url);
                    }
                    break;

                case 'extend':
                    $result = $debug_manager->extend_debug_mode();

                    $redirect_url = add_query_arg('debug_mode_extended', '1', $redirect_url);
                    break;

                default:
                    $redirect_url = add_query_arg('debug_error', '1', $redirect_url);
                    break;
            }

            wp_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Handle error log operations (clear).
     */
    public function handle_error_log_operations()
    {
        if (isset($_POST['clear_log'])) {
            if (wp_verify_nonce($_POST['clear_log_nonce'], 'metasync_clear_log_nonce')) {
                $log_file = WP_CONTENT_DIR . '/metasync_data/plugin_errors.log';

                if (file_exists($log_file)) {
                    file_put_contents($log_file, '');

                    $backup_files = glob(WP_CONTENT_DIR . '/metasync_data/plugin_errors.log.old.*');
                    if ($backup_files) {
                        foreach ($backup_files as $backup_file) {
                            @unlink($backup_file);
                        }
                    }
                }

                $redirect_url = admin_url('admin.php?page=' . $this->get_page_slug() . '&tab=advanced&log_cleared=1');
                wp_redirect($redirect_url);
                exit;
            } else {
                $redirect_url = admin_url('admin.php?page=' . $this->get_page_slug() . '&tab=advanced&clear_error=1');
                wp_redirect($redirect_url);
                exit;
            }
        }
        
        if (isset($_POST['clear_error_summary']) && isset($_POST['clear_error_summary_nonce'])) {
            if (wp_verify_nonce($_POST['clear_error_summary_nonce'], 'metasync_clear_error_summary_nonce')) {
                if (class_exists('Metasync_Error_Logger')) {
                    Metasync_Error_Logger::clear_error_summary();
                    $redirect_url = admin_url('admin.php?page=' . $this->get_page_slug() . '&tab=advanced&error_summary_cleared=1');
                    wp_redirect($redirect_url);
                    exit;
                } else {
                    $redirect_url = admin_url('admin.php?page=' . $this->get_page_slug() . '&tab=advanced&error_summary_error=1');
                    wp_redirect($redirect_url);
                    exit;
                }
            } else {
                $redirect_url = admin_url('admin.php?page=' . $this->get_page_slug() . '&tab=advanced&error_summary_error=1');
                wp_redirect($redirect_url);
                exit;
            }
        }
    }

    /**
     * Handle clear all settings operations.
     */
    public function handle_clear_all_settings()
    {
        if (isset($_POST['clear_all_settings'])) {
            if (wp_verify_nonce($_POST['clear_all_settings_nonce'], 'metasync_clear_all_settings_nonce')) {
                
                $metasync_options_to_clear = [
                    'metasync_options',
                    'metasync_options_instant_indexing',
                    'metasync_options_bing_instant_indexing',
                    'metasync_otto_crawldata',
                    'metasync_logging_data',
                    'metasync_wp_sa_connect_token',
                    'wp_debug_enabled',
                    'wp_debug_log_enabled',
                    'wp_debug_display_enabled',
                ];

                $cleared_count = 0;
                foreach ($metasync_options_to_clear as $option_name) {
                    if (get_option($option_name) !== false) {
                        delete_option($option_name);
                        $cleared_count++;
                    }
                }

                $transients_to_clear = [
                    'metasync_heartbeat_status_cache',
                ];
                
                foreach ($transients_to_clear as $transient_name) {
                    delete_transient($transient_name);
                }

                $timestamp = wp_next_scheduled('metasync_heartbeat_cron_check');
                if ($timestamp) {
                    wp_unschedule_event($timestamp, 'metasync_heartbeat_cron_check');
                }

                $new_plugin_auth_token = wp_generate_password(32, false, false);
                
                $fresh_options = [
                    'general' => [
                        'apikey' => $new_plugin_auth_token
                    ]
                ];
                
                update_option('metasync_options', $fresh_options);

                Metasync::log_api_key_event('settings_reset', 'plugin_auth_token', array(
                    'options_cleared_count' => $cleared_count,
                    'new_token_prefix' => substr($new_plugin_auth_token, 0, 8) . '...',
                    'triggered_by' => 'settings_reset_action'
                ), 'info');
                
                $redirect_url = admin_url('admin.php?page=' . $this->get_page_slug() . '&tab=advanced&settings_cleared=1');
                wp_redirect($redirect_url);
                exit;
            } else {
                $redirect_url = admin_url('admin.php?page=' . $this->get_page_slug() . '&tab=advanced&clear_settings_error=1');
                wp_redirect($redirect_url);
                exit;
            }
        }
    }

    // ------------------------------------------------------------------
    //  Log-file helpers
    // ------------------------------------------------------------------

    /**
     * Get error log content for display.
     */
    public function get_error_log_content()
    {
        $execution_time = $this->get_execution_setting('max_execution_time');
        if (function_exists('set_time_limit')) {
            @set_time_limit($execution_time);
        }
        
        $log_file = WP_CONTENT_DIR . '/debug.log';
        
        if (!file_exists($log_file) || !is_readable($log_file)) {
            return false;
        }
        
        $batch_size = $this->get_execution_setting('log_batch_size');
        
        $file_size = filesize($log_file);
        if ($file_size > 10 * 1024 * 1024) {
            return $this->get_log_tail($log_file, $batch_size);
        }
        
        $content = file_get_contents($log_file);
        if ($content === false) {
            return false;
        }
        
        $lines = explode("\n", $content);
        $recent_lines = array_slice($lines, -$batch_size);
        
        return implode("\n", $recent_lines);
    }
    
    /**
     * Memory-efficient function to get last N lines from a large file.
     */
    public function get_log_tail($file_path, $lines = null)
    {
        $execution_time = $this->get_execution_setting('max_execution_time');
        if (function_exists('set_time_limit')) {
            @set_time_limit($execution_time);
        }
        
        if ($lines === null) {
            $lines = $this->get_execution_setting('log_batch_size');
        }
        
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return false;
        }
        
        fseek($handle, -1, SEEK_END);
        
        $result_lines = array();
        $line = '';
        $line_count = 0;
        
        while (ftell($handle) > 0 && $line_count < $lines) {
            $char = fgetc($handle);
            
            if ($char === "\n") {
                if (!empty($line)) {
                    array_unshift($result_lines, strrev($line));
                    $line = '';
                    $line_count++;
                }
            } else {
                $line .= $char;
            }
            
            fseek($handle, -2, SEEK_CUR);
        }
        
        if (!empty($line) && $line_count < $lines) {
            array_unshift($result_lines, strrev($line));
        }
        
        fclose($handle);
        
        return implode("\n", $result_lines);
    }
}
