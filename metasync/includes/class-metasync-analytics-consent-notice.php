<?php
/**
 * Analytics Consent Notice
 *
 * Asks admins on existing installs, who never saw the setup wizard's consent
 * toggle, whether they want to share usage analytics.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Metasync_Analytics_Consent_Notice
 *
 * Shown on this plugin's own admin pages while analytics consent is off.
 * "Not now" (or the X) hides it for SNOOZE_DAYS; it comes back after that
 * for as long as consent stays off.
 */
class Metasync_Analytics_Consent_Notice {

    /**
     * Singleton instance
     *
     * @var Metasync_Analytics_Consent_Notice|null
     */
    private static $instance = null;

    /**
     * Days the notice stays hidden after "Not now".
     */
    public const SNOOZE_DAYS = 10;

    /**
     * Unix time until which the notice is hidden. Site-wide, like the consent itself.
     */
    public const SNOOZE_OPTION = 'metasync_analytics_notice_snoozed_until';

    private const NONCE_ACTION = 'metasync_analytics_consent_notice';

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        add_action('admin_notices', [$this, 'display_notice']);
        add_action('wp_ajax_metasync_analytics_consent_allow', [$this, 'ajax_allow']);
        add_action('wp_ajax_metasync_analytics_consent_snooze', [$this, 'ajax_snooze']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Get singleton instance
     *
     * @return Metasync_Analytics_Consent_Notice
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Whether the notice should show to the current user right now.
     *
     * @return bool
     */
    public function should_display() {
        if (!current_user_can('manage_options')) {
            return false;
        }

        if (get_option('metasync_analytics_opt_in', 'no') === 'yes') {
            return false;
        }

        return time() >= (int) get_option(self::SNOOZE_OPTION, 0);
    }

    /**
     * Whether the current admin screen is one of this plugin's own pages.
     *
     * The slug is whitelabellable, so match Metasync_Admin::$page_slug and its
     * `{slug}-...` sub-pages rather than a fixed string.
     *
     * @return bool
     */
    public function is_plugin_admin_page() {
        if (empty($_GET['page'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page check for notice placement
            return false;
        }

        $page = sanitize_text_field(wp_unslash($_GET['page'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page check for notice placement
        $slug = class_exists('Metasync_Admin') && !empty(Metasync_Admin::$page_slug)
            ? Metasync_Admin::$page_slug
            : 'searchatlas';

        return $page === $slug || strpos($page, $slug . '-') === 0;
    }

    /**
     * admin_notices callback.
     */
    public function display_notice() {
        if (!$this->is_plugin_admin_page() || !$this->should_display()) {
            return;
        }

        $plugin_name = class_exists('Metasync') ? Metasync::get_effective_plugin_name() : 'Search Atlas';
        $slug = class_exists('Metasync_Admin') && !empty(Metasync_Admin::$page_slug)
            ? Metasync_Admin::$page_slug
            : 'searchatlas';
        $settings_url = admin_url('admin.php?page=' . $slug);
        ?>
        <div id="metasync-analytics-consent-notice" class="notice notice-info is-dismissible" style="padding: 12px 16px; border-left-color: #2271b1;">
            <div style="display: flex; align-items: flex-start; gap: 14px;">
                <span class="dashicons dashicons-chart-bar" style="font-size: 28px; width: 28px; height: 28px; color: #2271b1;" aria-hidden="true"></span>
                <div style="flex: 1;">
                    <p style="margin: 0 0 6px 0; font-size: 14px; font-weight: 600;">
                        <?php
                        /* translators: %s: Plugin name. */
                        echo esc_html(sprintf(__('Help improve %s', 'metasync'), $plugin_name));
                        ?>
                    </p>
                    <p class="metasync-analytics-consent-message" style="margin: 0 0 6px 0; font-size: 13px;">
                        <?php
                        /* translators: %s: Plugin name. */
                        echo esc_html(sprintf(__('Allow %s to collect limited usage analytics. This helps us understand which features are used, improve the product, and identify problems.', 'metasync'), $plugin_name));
                        ?>
                    </p>
                    <p class="metasync-analytics-consent-details" style="margin: 0 0 10px 0; font-size: 12px; color: #646970;">
                        <?php esc_html_e('We collect limited technical information such as your site URL, plugin version, WordPress and PHP versions, user role, and feature usage. We do not collect passwords, API keys, customer content, names, or email addresses.', 'metasync'); ?>
                        <a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('You can change this anytime in Settings.', 'metasync'); ?></a>
                    </p>
                    <?php // no-loading: the dashboard script otherwise disables plugin buttons on click, and jQuery skips delegated clicks on disabled buttons. ?>
                    <p class="metasync-analytics-consent-actions" style="margin: 0; display: flex; gap: 10px; flex-wrap: wrap;">
                        <button type="button" class="button button-primary no-loading metasync-analytics-consent-allow">
                            <?php esc_html_e('Allow', 'metasync'); ?>
                        </button>
                        <button type="button" class="button no-loading metasync-analytics-consent-snooze">
                            <?php esc_html_e('Not now', 'metasync'); ?>
                        </button>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Enqueue the button handlers.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_scripts($hook) {
        if (!$this->is_plugin_admin_page() || !$this->should_display()) {
            return;
        }

        wp_add_inline_script('jquery', $this->get_notice_script(), 'after');
    }

    /**
     * Inline JS for the notice buttons.
     *
     * @return string JavaScript code.
     */
    private function get_notice_script() {
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        $thanks = __('Thanks! Usage analytics are now on. You can turn them off anytime in Settings.', 'metasync');
        $failed = __('Could not save your choice. Please try again, or use the toggle in Settings.', 'metasync');

        return "
        jQuery(document).ready(function($) {
            var notice = $('#metasync-analytics-consent-notice');
            var nonce = '" . esc_js($nonce) . "';

            notice.on('click', '.metasync-analytics-consent-allow', function() {
                var button = $(this).prop('disabled', true);
                $.post(ajaxurl, { action: 'metasync_analytics_consent_allow', nonce: nonce })
                    .done(function(response) {
                        if (response && response.success) {
                            notice.removeClass('notice-info').addClass('notice-success');
                            notice.find('.metasync-analytics-consent-message').text('" . esc_js($thanks) . "');
                            notice.find('.metasync-analytics-consent-actions, .metasync-analytics-consent-details').remove();
                        } else {
                            button.prop('disabled', false);
                            notice.find('.metasync-analytics-consent-message').text('" . esc_js($failed) . "');
                        }
                    })
                    .fail(function() {
                        button.prop('disabled', false);
                        notice.find('.metasync-analytics-consent-message').text('" . esc_js($failed) . "');
                    });
            });

            // 'Not now' and the X both snooze the notice.
            notice.on('click', '.metasync-analytics-consent-snooze, .notice-dismiss', function() {
                if (notice.hasClass('notice-success')) {
                    return;
                }
                $.post(ajaxurl, { action: 'metasync_analytics_consent_snooze', nonce: nonce });
                notice.fadeOut();
            });
        });
        ";
    }

    /**
     * AJAX handler: turn analytics on.
     *
     * Saves the same two options as the setup wizard and the settings toggle,
     * so the choice counts as explicit consent.
     */
    public function ajax_allow() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
            // Unreachable in WordPress; keeps the handler safe under the
            // non-terminating stubs the unit tests run with.
            // @phpstan-ignore-next-line deadCode.unreachable
            return;
        }

        update_option('metasync_analytics_opt_in', 'yes');
        update_option('metasync_analytics_consent_explicit', 'yes');
        delete_option(self::SNOOZE_OPTION);

        wp_send_json_success(['message' => 'Analytics enabled']);
    }

    /**
     * AJAX handler: hide the notice for SNOOZE_DAYS. Consent stays off.
     */
    public function ajax_snooze() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
            // Same as above: defensive under test stubs, unreachable in WordPress.
            // @phpstan-ignore-next-line deadCode.unreachable
            return;
        }

        update_option(self::SNOOZE_OPTION, time() + (self::SNOOZE_DAYS * DAY_IN_SECONDS), false);

        wp_send_json_success(['message' => 'Notice snoozed']);
    }
}
