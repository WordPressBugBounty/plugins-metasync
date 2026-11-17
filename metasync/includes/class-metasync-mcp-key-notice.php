<?php
/**
 * MCP client-key feature notice.
 *
 * Introduces existing administrators to the AI Agents (MCP) client-key page
 * once per user after upgrading to a build that includes the feature.
 *
 * @package Search Atlas SEO
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_MCP_Key_Notice
{
    private const DISMISSED_META = 'metasync_mcp_key_notice_dismissed';
    private const NONCE_ACTION = 'metasync_mcp_key_notice';

    /** @var self|null */
    private static $instance = null;

    private function __construct()
    {
        add_action('admin_notices', [$this, 'display_notice']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_metasync_dismiss_mcp_key_notice', [$this, 'ajax_dismiss']);
    }

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function get_page_slug()
    {
        return class_exists('Metasync_Admin') && !empty(Metasync_Admin::$page_slug)
            ? Metasync_Admin::$page_slug
            : 'searchatlas';
    }

    private function get_current_page()
    {
        if (empty($_GET['page'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice placement check.
            return '';
        }

        return sanitize_text_field(wp_unslash($_GET['page'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice placement check.
    }

    public function is_plugin_admin_page()
    {
        $page = $this->get_current_page();
        if ($page === '') {
            return false;
        }

        $slug = $this->get_page_slug();

        return $page === $slug || strpos($page, $slug . '-') === 0;
    }

    public function should_display()
    {
        if (!$this->is_plugin_admin_page() || !current_user_can('manage_options')) {
            return false;
        }

        // No "View AI Agents" nudge on the AI Agents page itself.
        if ($this->get_current_page() === $this->get_page_slug() . '-mcp-keys') {
            return false;
        }

        if (function_exists('metasync_is_non_metasync_admin_ajax') && metasync_is_non_metasync_admin_ajax()) {
            return false;
        }

        if (class_exists('Metasync') && !Metasync::current_user_has_plugin_access()) {
            return false;
        }

        if (class_exists('Metasync_Access_Control') && !Metasync_Access_Control::user_can_access('hide_mcp')) {
            return false;
        }

        return !get_user_meta(get_current_user_id(), self::DISMISSED_META, true);
    }

    public function display_notice()
    {
        if (!$this->should_display()) {
            return;
        }

        $slug = class_exists('Metasync_Admin') && !empty(Metasync_Admin::$page_slug)
            ? Metasync_Admin::$page_slug
            : 'searchatlas';
        $url = admin_url('admin.php?page=' . $slug . '-mcp-keys');
        ?>
        <div id="metasync-mcp-key-notice" class="notice notice-info is-dismissible" style="padding: 12px 16px; border-left-color: #2271b1;">
            <div style="display: flex; align-items: flex-start; gap: 12px;">
                <span class="dashicons dashicons-rest-api" style="font-size: 26px; width: 26px; height: 26px; color: #2271b1;" aria-hidden="true"></span>
                <div style="flex: 1;">
                    <p style="margin: 0 0 5px; font-size: 14px; font-weight: 600;">
                        <?php esc_html_e('New: AI Agents (MCP) client keys', 'metasync'); ?>
                    </p>
                    <p style="margin: 0 0 9px; font-size: 13px;">
                        <?php esc_html_e('Create named, expiring keys for AI clients with read-only or full access. You can revoke any key at any time.', 'metasync'); ?>
                    </p>
                    <p style="margin: 0;">
                        <a class="button button-primary no-loading metasync-mcp-key-notice-open" href="<?php echo esc_url($url); ?>">
                            <?php esc_html_e('View AI Agents', 'metasync'); ?>
                        </a>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    public function enqueue_admin_scripts()
    {
        if (!$this->should_display()) {
            return;
        }

        wp_add_inline_script('jquery', $this->get_notice_script(), 'after');
    }

    private function get_notice_script()
    {
        $nonce = wp_create_nonce(self::NONCE_ACTION);

        return "
        jQuery(function($) {
            var notice = $('#metasync-mcp-key-notice');
            var nonce = '" . esc_js($nonce) . "';
            var dismiss = function(done) {
                $.post(ajaxurl, {
                    action: 'metasync_dismiss_mcp_key_notice',
                    nonce: nonce
                }).always(function() {
                    if (typeof done === 'function') {
                        done();
                    }
                });
            };
            notice.on('click', '.notice-dismiss', function() {
                dismiss();
            });
            notice.on('click', '.metasync-mcp-key-notice-open', function(event) {
                event.preventDefault();
                var href = this.href;
                dismiss(function() {
                    window.location.href = href;
                });
            });
        });
        ";
    }

    public function ajax_dismiss()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        update_user_meta(get_current_user_id(), self::DISMISSED_META, 1);
        wp_send_json_success();
    }
}
