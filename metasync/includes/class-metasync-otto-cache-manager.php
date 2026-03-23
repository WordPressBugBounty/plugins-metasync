<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * OTTO Cache Management manager.
 *
 * Extracted from Metasync_Admin to keep the admin class focused on UI concerns.
 * Handles OTTO transient cache rendering, clearing, and excluded-URL AJAX operations.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */
class Metasync_Otto_Cache_Manager
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

    // ------------------------------------------------------------------
    //  Rendering
    // ------------------------------------------------------------------

    /**
     * Render OTTO cache management section for inclusion in Advanced settings
     */
    public function render_otto_cache_management()
    {
        if (!class_exists('Metasync_Otto_Transient_Cache')) {
            echo '<div class="notice notice-error inline"><p>';
            echo '❌ <strong>Error:</strong> Transient Cache class not found.';
            echo '</p></div>';
            return;
        }
        
        $cache_count = Metasync_Otto_Transient_Cache::get_cache_count();
        
        ?>
        <!-- Cache Plugin Management -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);">Clear All Cache Plugins</h4>
            <p style="margin-bottom: 15px; color: var(--dashboard-text-secondary);">Clear all cache plugins to ensure changes are visible immediately.</p>

            <?php
            if (class_exists('Metasync_Cache_Purge')) {
                try {
                    $cache_purge = Metasync_Cache_Purge::get_instance();
                    $active_cache_plugins = $cache_purge->get_active_cache_plugins();

                    if (!empty($active_cache_plugins)) {
                        echo '<p style="color: var(--dashboard-text-primary);"><strong>Active Cache Plugins Detected:</strong></p>';
                        echo '<ul style="margin-bottom: 15px; color: var(--dashboard-text-primary);">';
                        foreach ($active_cache_plugins as $plugin_name) {
                            echo '<li>✅ ' . esc_html($plugin_name) . '</li>';
                        }
                        echo '</ul>';
                    } else {
                        echo '<p style="color: var(--dashboard-text-secondary);">ℹ️ No cache plugins detected.</p>';
                    }
                } catch (Exception $e) {
                    echo '<p style="color: var(--dashboard-error);">⚠️ Error: ' . esc_html($e->getMessage()) . '</p>';
                }
            } else {
                echo '<p style="color: var(--dashboard-error);">⚠️ Cache Purge class not loaded.</p>';
            }
            ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 15px;">
                <input type="hidden" name="action" value="metasync_clear_all_cache_plugins" />
                <?php wp_nonce_field('metasync_clear_cache_nonce', 'clear_cache_nonce'); ?>
                <button type="submit" class="metasync-btn-primary" style="background: var(--dashboard-gradient-primary); color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); display: inline-block; width: auto; min-width: 240px; max-width: fit-content;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0, 0, 0, 0.15)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0, 0, 0, 0.1)';">
                    🔄 Clear All Cache Plugins
                </button>
                <p class="description" style="margin-top: 10px; color: var(--dashboard-text-secondary);">This will clear cache from WP Rocket, LiteSpeed, W3 Total Cache, and all other detected cache plugins.</p>
            </form>

            <?php
            if (isset($_GET['cache_cleared']) && $_GET['cache_cleared'] == '1') {
                $cleared = isset($_GET['cleared']) ? intval($_GET['cleared']) : 0;
                $failed = isset($_GET['failed']) ? intval($_GET['failed']) : 0;
                $plugins = isset($_GET['plugins']) ? sanitize_text_field($_GET['plugins']) : '';

                if ($cleared > 0) {
                    echo '<div class="notice notice-success inline" style="margin-top: 15px;"><p>';
                    echo '✅ <strong>Success!</strong> Cleared cache for ' . $cleared . ' plugin(s)';
                    if ($plugins) {
                        echo ': ' . esc_html($plugins);
                    }
                    echo '</p></div>';
                } else {
                    echo '<div class="notice notice-info inline" style="margin-top: 15px;"><p>';
                    echo 'ℹ️ No cache plugins found to clear. WordPress object cache was cleared.';
                    echo '</p></div>';
                }

                if ($failed > 0) {
                    echo '<div class="notice notice-warning inline" style="margin-top: 15px;"><p>';
                    echo '⚠️ Failed to clear ' . $failed . ' plugin(s).';
                    echo '</p></div>';
                }
            }

            if (isset($_GET['cache_error']) && $_GET['cache_error'] == '1') {
                $message = isset($_GET['message']) ? urldecode(sanitize_text_field($_GET['message'])) : '';
                if (empty($message)) {
                    $message = 'An unknown error occurred while clearing cache. Please check error logs for details.';
                }
                echo '<div class="notice notice-error inline" style="margin-top: 15px;"><p>';
                echo '❌ <strong>Error clearing cache:</strong> ' . esc_html($message);
                echo '</p></div>';
            }
            ?>
        </div>

        <!-- Hosting Cache Integration -->
        <?php
        $hosting_defaults = array('wpengine_enabled' => true, 'kinsta_enabled' => true);
        $hosting_settings = wp_parse_args(get_option('metasync_hosting_cache_options', array()), $hosting_defaults);
        $wpe_detected      = class_exists('WpeCommon');
        $kinsta_detected   = class_exists('KinstaCache');
        ?>
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);">Hosting Cache Integration</h4>
            <p style="margin-bottom: 15px; color: var(--dashboard-text-secondary);">
                Use your hosting provider's native API to purge the <strong>entire site cache</strong> in one click.
                These options are independent of cache plugins and target the server-level cache layer.
            </p>

            <!-- Detection status badges -->
            <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px;">
                <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 500;
                      background: <?php echo $wpe_detected ? 'rgba(34,197,94,0.12)' : 'rgba(156,163,175,0.12)'; ?>;
                      color: <?php echo $wpe_detected ? '#22c55e' : 'var(--dashboard-text-secondary)'; ?>;
                      border: 1px solid <?php echo $wpe_detected ? 'rgba(34,197,94,0.3)' : 'rgba(156,163,175,0.3)'; ?>;">
                    <?php echo $wpe_detected ? '✅' : '⬜'; ?> WP Engine <?php echo $wpe_detected ? '(detected)' : '(not detected)'; ?>
                </span>
                <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 500;
                      background: <?php echo $kinsta_detected ? 'rgba(34,197,94,0.12)' : 'rgba(156,163,175,0.12)'; ?>;
                      color: <?php echo $kinsta_detected ? '#22c55e' : 'var(--dashboard-text-secondary)'; ?>;
                      border: 1px solid <?php echo $kinsta_detected ? 'rgba(34,197,94,0.3)' : 'rgba(156,163,175,0.3)'; ?>;">
                    <?php echo $kinsta_detected ? '✅' : '⬜'; ?> Kinsta <?php echo $kinsta_detected ? '(detected)' : '(not detected)'; ?>
                </span>
            </div>

            <!-- Settings toggles -->
            <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--dashboard-border); border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                <h5 style="margin: 0 0 14px 0; color: var(--dashboard-text-primary);">Enable Native Cache Purge</h5>

                <label style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px; cursor: pointer;">
                    <input type="checkbox"
                           id="metasync-hc-wpengine"
                           <?php checked(true, !empty($hosting_settings['wpengine_enabled'])); ?>
                           <?php echo !$wpe_detected ? 'disabled' : ''; ?>
                           style="width: 16px; height: 16px; cursor: <?php echo $wpe_detected ? 'pointer' : 'not-allowed'; ?>;" />
                    <span style="color: var(--dashboard-text-primary); font-weight: 500;">WP Engine</span>
                    <span style="color: var(--dashboard-text-secondary); font-size: 12px;">— purges Varnish + Memcached</span>
                </label>

                <label style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; cursor: pointer;">
                    <input type="checkbox"
                           id="metasync-hc-kinsta"
                           <?php checked(true, !empty($hosting_settings['kinsta_enabled'])); ?>
                           <?php echo !$kinsta_detected ? 'disabled' : ''; ?>
                           style="width: 16px; height: 16px; cursor: <?php echo $kinsta_detected ? 'pointer' : 'not-allowed'; ?>;" />
                    <span style="color: var(--dashboard-text-primary); font-weight: 500;">Kinsta</span>
                    <span style="color: var(--dashboard-text-secondary); font-size: 12px;">— purges full-page cache (kinsta_cache_purge_full)</span>
                </label>

                <div style="display: flex; align-items: center; gap: 12px;">
                    <button type="button"
                            id="metasync-hc-save-btn"
                            class="metasync-btn-primary"
                            style="background: var(--dashboard-gradient-primary); color: #ffffff; border: none; padding: 9px 18px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease;"
                            onmouseover="this.style.transform='translateY(-1px)';"
                            onmouseout="this.style.transform='translateY(0)';">
                        💾 Save Settings
                    </button>
                    <span id="metasync-hc-save-msg" style="display: none; font-size: 13px;"></span>
                </div>

                <input type="hidden" id="metasync-hc-nonce" value="<?php echo wp_create_nonce('metasync_hosting_cache_nonce'); ?>" />
            </div>

            <!-- Purge button -->
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="metasync_purge_hosting_cache" />
                <?php wp_nonce_field('metasync_hosting_cache_purge_nonce', 'hosting_cache_purge_nonce'); ?>
                <button type="submit"
                        class="metasync-btn-primary"
                        style="background: var(--dashboard-gradient-primary); color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1); display: inline-block; min-width: 240px; max-width: fit-content;"
                        onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.15)';"
                        onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)';">
                    🚀 Purge Entire Hosting Cache
                </button>
                <p class="description" style="margin-top: 10px; color: var(--dashboard-text-secondary);">
                    Triggers a full-site cache purge using the native WP Engine and/or Kinsta APIs (based on toggles above).
                </p>
            </form>

            <?php
            // Hosting cache result messages
            if (isset($_GET['hosting_cache_cleared']) && $_GET['hosting_cache_cleared'] == '1') {
                $hc_cleared      = isset($_GET['hc_cleared'])      ? sanitize_text_field(urldecode($_GET['hc_cleared']))      : '';
                $hc_failed       = isset($_GET['hc_failed'])       ? sanitize_text_field(urldecode($_GET['hc_failed']))       : '';
                $hc_not_detected = isset($_GET['hc_not_detected']) ? sanitize_text_field(urldecode($_GET['hc_not_detected'])) : '';

                if ($hc_cleared) {
                    echo '<div class="notice notice-success inline" style="margin-top: 15px;"><p>';
                    echo '✅ <strong>Success!</strong> Purged hosting cache on: ' . esc_html($hc_cleared);
                    echo '</p></div>';
                }
                if ($hc_failed) {
                    echo '<div class="notice notice-error inline" style="margin-top: 10px;"><p>';
                    echo '❌ <strong>Failed</strong> to purge: ' . esc_html($hc_failed);
                    echo '</p></div>';
                }
                if ($hc_not_detected && !$hc_cleared && !$hc_failed) {
                    echo '<div class="notice notice-info inline" style="margin-top: 10px;"><p>';
                    echo 'ℹ️ No enabled hosting providers were detected on this server (' . esc_html($hc_not_detected) . ').';
                    echo '</p></div>';
                }
            }
            ?>

            <script>
            jQuery(document).ready(function($) {
                $('#metasync-hc-save-btn').on('click', function() {
                    var $btn = $(this);
                    var $msg = $('#metasync-hc-save-msg');

                    $btn.prop('disabled', true).text('Saving…');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action:            'metasync_save_hosting_cache_settings',
                            hosting_cache_nonce: $('#metasync-hc-nonce').val(),
                            wpengine_enabled:  $('#metasync-hc-wpengine').is(':checked') ? '1' : '0',
                            kinsta_enabled:    $('#metasync-hc-kinsta').is(':checked')   ? '1' : '0',
                        },
                        success: function(response) {
                            if (response.success) {
                                $msg.text('✅ Saved').css('color', '#22c55e').show();
                            } else {
                                $msg.text('❌ ' + (response.data.message || 'Save failed')).css('color', '#ef4444').show();
                            }
                        },
                        error: function() {
                            $msg.text('❌ Request failed').css('color', '#ef4444').show();
                        },
                        complete: function() {
                            $btn.prop('disabled', false).text('💾 Save Settings');
                            setTimeout(function() { $msg.fadeOut(); }, 4000);
                        }
                    });
                });
            });
            </script>
        </div>

        <!-- OTTO Transient Cache -->
        <div style="margin-bottom: 30px;">
            <h4 style="margin-top: 0; color: var(--dashboard-text-primary);"><?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> Transient Cache</h4>
            <p style="margin-bottom: 15px; color: var(--dashboard-text-secondary);">
                Manage <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> suggestions cache. Clearing cache will force fresh API calls on next page load.
            </p>

            <div style="background: rgba(255, 255, 255, 0.05); padding: 12px; border-radius: 4px; margin-bottom: 20px; border: 1px solid var(--dashboard-border);">
                <strong style="color: var(--dashboard-text-primary);">Current Cache Status:</strong>
                <span style="color: var(--dashboard-accent);"><?php echo esc_html($cache_count); ?> cached entries</span>
            </div>
            
            <?php
            if (isset($_GET['otto_cache_cleared']) && $_GET['otto_cache_cleared'] == '1') {
                $cleared_count = isset($_GET['count']) ? intval($_GET['count']) : 0;
                $url = isset($_GET['url']) ? urldecode(sanitize_text_field($_GET['url'])) : '';
                
                echo '<div class="notice notice-success inline" style="margin-top: 15px;"><p>';
                if (!empty($url)) {
                    echo '✅ <strong>Success!</strong> Cleared cache for URL: <code>' . esc_html($url) . '</code> (' . $cleared_count . ' entries)';
                } else {
                    echo '✅ <strong>Success!</strong> Cleared entire transient cache (' . $cleared_count . ' entries)';
                }
                echo '</p></div>';
            }
            
            if (isset($_GET['otto_cache_error']) && $_GET['otto_cache_error'] == '1') {
                $message = isset($_GET['message']) ? urldecode(sanitize_text_field($_GET['message'])) : 'An unknown error occurred.';
                echo '<div class="notice notice-error inline" style="margin-top: 15px;"><p>';
                echo '❌ <strong>Error:</strong> ' . esc_html($message);
                echo '</p></div>';
            }
            ?>
            
            <!-- Clear Entire Cache -->
            <div style="margin-bottom: 30px; padding: 20px; border: 1px solid var(--dashboard-border); border-radius: 4px; background: rgba(255, 255, 255, 0.02);">
                <h5 style="margin-top: 0; color: var(--dashboard-text-primary);">Clear Entire Transient Cache</h5>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 15px;">
                    This will clear all <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> transient cache entries (suggestions, locks, stale cache, rate limits).
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      onsubmit="return confirm('Are you sure you want to clear the entire transient cache? This will force fresh API calls for all URLs.');">
                    <input type="hidden" name="action" value="metasync_clear_otto_cache_all" />
                    <?php wp_nonce_field('metasync_clear_otto_cache_nonce', 'clear_otto_cache_nonce'); ?>
                    <button type="submit" class="metasync-btn-primary" style="background: var(--dashboard-gradient-primary); color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); display: inline-block; width: auto; min-width: 240px; max-width: fit-content;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0, 0, 0, 0.15)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0, 0, 0, 0.1)';">
                        🗑️ Clear Entire Cache
                    </button>
                </form>
            </div>
            
            <!-- Clear Cache by URL -->
            <div style="padding: 20px; border: 1px solid var(--dashboard-border); border-radius: 4px; background: rgba(255, 255, 255, 0.02);">
                <h5 style="margin-top: 0; color: var(--dashboard-text-primary);">Clear Cache by URL</h5>
                <p style="color: var(--dashboard-text-secondary); margin-bottom: 15px;">
                    Enter a specific URL to clear its cached <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> suggestions. Use the full URL including protocol (e.g., https://example.com/page/).
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="metasync_clear_otto_cache_url" />
                    <?php wp_nonce_field('metasync_clear_otto_cache_nonce', 'clear_otto_cache_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="otto_cache_url" style="color: var(--dashboard-text-primary);">URL to Clear</label>
                            </th>
                            <td>
                                <input type="url"
                                       id="otto_cache_url"
                                       name="otto_cache_url"
                                       value="<?php echo isset($_GET['url']) ? esc_attr(urldecode(sanitize_text_field($_GET['url']))) : ''; ?>"
                                       class="regular-text"
                                       placeholder="https://example.com/page/"
                                       required />
                                <p class="description" style="color: var(--dashboard-text-secondary);">Enter the full URL of the page whose cache you want to clear.</p>
                            </td>
                        </tr>
                    </table>
                    <button type="submit" class="metasync-btn-primary" style="background: var(--dashboard-gradient-primary); color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); display: inline-block; width: auto; max-width: fit-content;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0, 0, 0, 0.15)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0, 0, 0, 0.1)';">
                        🗑️ Clear Cache for URL
                    </button>
                </form>
            </div>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    //  Admin-post handlers (form submissions)
    // ------------------------------------------------------------------

    /**
     * WordPress standard handler for clearing all cache plugins (admin_post hook)
     */
    public function handle_clear_all_cache_plugins() {
        if (!isset($_POST['clear_cache_nonce']) || !wp_verify_nonce($_POST['clear_cache_nonce'], 'metasync_clear_cache_nonce')) {
            wp_die('Security check failed');
        }

        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('You do not have permission to perform this action');
        }

        $redirect_url = admin_url('admin.php?page=' . $this->get_page_slug() . '&tab=advanced');

        if (class_exists('Metasync_Cache_Purge')) {
            try {
                $cache_purge = Metasync_Cache_Purge::get_instance();
                $results = $cache_purge->clear_all_caches('manual_admin');

                $cleared_count = count($results['cleared']);
                $failed_count = count($results['failed']);

                $redirect_url .= '&cache_cleared=1&cleared=' . $cleared_count . '&failed=' . $failed_count;
                if (!empty($results['cleared'])) {
                    $redirect_url .= '&plugins=' . urlencode(implode(',', $results['cleared']));
                }
            } catch (Exception $e) {
                error_log('MetaSync Cache Clear Error: ' . $e->getMessage());
                $redirect_url .= '&cache_error=1&message=' . urlencode($e->getMessage());
            }
        } else {
            $redirect_url .= '&cache_error=1&message=' . urlencode('Cache Purge class not available');
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * WordPress standard handler for clearing OTTO cache (admin_post hook)
     */
    public function handle_clear_otto_cache_all() {
        if (!isset($_POST['clear_otto_cache_nonce']) || !wp_verify_nonce($_POST['clear_otto_cache_nonce'], 'metasync_clear_otto_cache_nonce')) {
            wp_die('Security check failed');
        }

        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('You do not have permission to perform this action');
        }

        $redirect_url = admin_url('admin.php?page=' . $this->get_page_slug() . '&tab=advanced');

        if (class_exists('Metasync_Otto_Transient_Cache')) {
            try {
                $result = Metasync_Otto_Transient_Cache::clear_all_transients();
                $redirect_url .= '&otto_cache_cleared=1&count=' . $result['cleared_count'];
            } catch (Exception $e) {
                error_log('MetaSync OTTO Cache Clear Error: ' . $e->getMessage());
                $redirect_url .= '&otto_cache_error=1&message=' . urlencode($e->getMessage());
            }
        } else {
            $redirect_url .= '&otto_cache_error=1&message=' . urlencode('Transient Cache class not found');
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * WordPress standard handler for clearing OTTO cache by URL (admin_post hook)
     */
    public function handle_clear_otto_cache_url() {
        if (!isset($_POST['clear_otto_cache_nonce']) || !wp_verify_nonce($_POST['clear_otto_cache_nonce'], 'metasync_clear_otto_cache_nonce')) {
            wp_die('Security check failed');
        }

        if (!Metasync::current_user_has_plugin_access()) {
            wp_die('You do not have permission to perform this action');
        }

        $redirect_url = admin_url('admin.php?page=' . $this->get_page_slug() . '&tab=advanced');
        $url = isset($_POST['otto_cache_url']) ? trim($_POST['otto_cache_url']) : '';

        if (empty($url)) {
            wp_safe_redirect($redirect_url . '&otto_cache_error=1&message=' . urlencode('URL is required'));
            exit;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            wp_safe_redirect($redirect_url . '&otto_cache_error=1&message=' . urlencode('Invalid URL format'));
            exit;
        }

        if (class_exists('Metasync_Otto_Transient_Cache')) {
            try {
                $result = Metasync_Otto_Transient_Cache::clear_url_transient($url);

                if ($result['success']) {
                    $redirect_url .= '&otto_cache_cleared=1&count=' . $result['cleared_count'] . '&url=' . urlencode($url);
                } else {
                    $redirect_url .= '&otto_cache_error=1&message=' . urlencode($result['message']);
                }
            } catch (Exception $e) {
                error_log('MetaSync OTTO Cache Clear Error: ' . $e->getMessage());
                $redirect_url .= '&otto_cache_error=1&message=' . urlencode($e->getMessage());
            }
        } else {
            $redirect_url .= '&otto_cache_error=1&message=' . urlencode('Transient Cache class not found');
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    // ------------------------------------------------------------------
    //  AJAX handlers – excluded URLs
    // ------------------------------------------------------------------

    /**
     * AJAX handler to add excluded URL for OTTO
     */
    public function ajax_otto_add_excluded_url()
    {
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        if (!check_ajax_referer('metasync_otto_excluded_urls', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        $url_pattern = isset($_POST['url_pattern']) ? sanitize_text_field($_POST['url_pattern']) : '';
        $pattern_type = isset($_POST['pattern_type']) ? sanitize_text_field($_POST['pattern_type']) : 'exact';
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';

        if (empty($url_pattern)) {
            wp_send_json_error(['message' => 'URL pattern is required']);
            return;
        }

        $valid_types = ['exact', 'contain', 'start', 'end', 'regex'];
        if (!in_array($pattern_type, $valid_types)) {
            wp_send_json_error(['message' => 'Invalid pattern type']);
            return;
        }

        if ($pattern_type === 'regex') {
            $test_pattern = $url_pattern;
            $delimiter_chars = ['/', '#', '~', '%', '@'];
            $has_valid_delimiters = false;

            if (strlen($test_pattern) >= 2) {
                $first_char = $test_pattern[0];
                if (in_array($first_char, $delimiter_chars)) {
                    $last_pos = strrpos($test_pattern, $first_char);
                    if ($last_pos > 0) {
                        $has_valid_delimiters = true;
                    }
                }
            }

            if (!$has_valid_delimiters) {
                $test_pattern = '/' . $test_pattern . '/';
            }

            if (@preg_match($test_pattern, '') === false) {
                wp_send_json_error(['message' => 'Invalid regular expression pattern']);
                return;
            }
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'otto/class-metasync-otto-excluded-urls-database.php';
        $db = new Metasync_Otto_Excluded_URLs_Database();

        $result = $db->add([
            'url_pattern' => $url_pattern,
            'pattern_type' => $pattern_type,
            'description' => $description,
            'status' => 'active',
        ]);

        if ($result === 'duplicate') {
            wp_send_json_error([
                'message' => 'This URL pattern already exists in the exclusion list',
                'code' => 'duplicate'
            ]);
            return;
        }

        if ($result === 'reactivated') {
            $db->clear_cache();

            if ($pattern_type === 'exact') {
                try {
                    if (class_exists('Metasync_Cache_Purge')) {
                        $cache_purge = Metasync_Cache_Purge::get_instance();
                        $cache_purge->clear_url_cache($url_pattern);
                    }
                    wp_cache_flush();
                } catch (Exception $e) {
                    error_log('MetaSync: Failed to clear cache for reactivated URL: ' . $e->getMessage());
                }
            }

            wp_send_json_success([
                'message' => 'Previously inactive URL pattern has been reactivated. Cache cleared.',
            ]);
            return;
        }

        if ($result === true) {
            $db->clear_cache();

            if ($pattern_type === 'exact') {
                try {
                    if (class_exists('Metasync_Cache_Purge')) {
                        $cache_purge = Metasync_Cache_Purge::get_instance();
                        $cache_purge->clear_url_cache($url_pattern);
                    }

                    wp_cache_flush();
                } catch (Exception $e) {
                    error_log('MetaSync: Failed to clear cache for excluded URL: ' . $e->getMessage());
                }
            }

            wp_send_json_success([
                'message' => sprintf('URL excluded from %s successfully. Cache cleared.', Metasync::get_whitelabel_otto_name()),
            ]);
        } else {
            wp_send_json_error(['message' => 'Failed to add excluded URL']);
        }
    }

    /**
     * AJAX handler to delete excluded URL for OTTO
     */
    public function ajax_otto_delete_excluded_url()
    {
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        if (!check_ajax_referer('metasync_otto_excluded_urls', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($id <= 0) {
            wp_send_json_error(['message' => 'Invalid ID']);
            return;
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'otto/class-metasync-otto-excluded-urls-database.php';
        $db = new Metasync_Otto_Excluded_URLs_Database();

        $result = $db->delete([$id]);

        if ($result) {
            wp_send_json_success([
                'message' => 'Excluded URL deleted successfully',
            ]);
        } else {
            wp_send_json_error(['message' => 'Failed to delete excluded URL']);
        }
    }

    /**
     * AJAX handler to recheck if an excluded URL is now available
     */
    public function ajax_otto_recheck_excluded_url()
    {
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        if (!check_ajax_referer('metasync_otto_excluded_urls', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => 'Invalid ID']);
            return;
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'otto/class-metasync-otto-excluded-urls-database.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'otto/otto_pixel.php';

        $db = new Metasync_Otto_Excluded_URLs_Database();
        $row = $db->get_record_by_id($id);

        if (!$row || empty($row->url_pattern)) {
            wp_send_json_error(['message' => 'Excluded URL not found']);
            return;
        }

        $url = trim($row->url_pattern);
        $available = metasync_otto_is_url_available($url);

        wp_send_json_success([
            'available' => $available,
            'url' => $url,
        ]);
    }

    /**
     * AJAX handler to get excluded URLs with pagination
     */
    public function ajax_otto_get_excluded_urls()
    {
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        if (!check_ajax_referer('metasync_otto_excluded_urls', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;

        if ($page < 1) {
            $page = 1;
        }
        if ($per_page < 1 || $per_page > 100) {
            $per_page = 10;
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'otto/class-metasync-otto-excluded-urls-database.php';
        $db = new Metasync_Otto_Excluded_URLs_Database();

        $records = $db->get_paginated_records($per_page, $page);
        $total_count = $db->get_total_count();
        $total_pages = ceil($total_count / $per_page);

        wp_send_json_success([
            'records' => $records,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total_count' => $total_count,
                'total_pages' => $total_pages,
            ],
        ]);
    }
}
