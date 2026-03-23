<?php
/**
 * Review Notice
 *
 * Handles display of admin notice asking users to rate the plugin on WordPress.org.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 * @since      2.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Metasync_Review_Notice
 *
 * Displays a dismissible admin notice prompting users to rate the plugin.
 */
class Metasync_Review_Notice {

    /**
     * Singleton instance
     *
     * @var Metasync_Review_Notice|null
     */
    private static $instance = null;

    /**
     * Number of days after activation before showing the notice
     *
     * @var int
     */
    private const DAYS_BEFORE_SHOWING = 7;

    /**
     * Number of days to postpone when user clicks "Remind Me Later"
     *
     * @var int
     */
    private const REMIND_LATER_DAYS = 7;

    /**
     * WordPress.org review URL for the plugin
     *
     * @var string
     */
    private const REVIEW_URL = 'https://wordpress.org/support/plugin/flavor/reviews/#new-post';

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Get singleton instance
     *
     * @return Metasync_Review_Notice
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('admin_notices', [$this, 'display_review_notice']);
        add_action('wp_ajax_metasync_dismiss_review_notice', [$this, 'ajax_dismiss_notice']);
        add_action('wp_ajax_metasync_remind_later_review_notice', [$this, 'ajax_remind_later']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Check if the review notice should be displayed
     *
     * @return bool
     */
    private function should_display() {
        // Don't show if whitelabel is enabled
        if (class_exists('Metasync') && Metasync::is_whitelabel_enabled()) {
            return false;
        }

        // Don't show if permanently dismissed
        if (get_option('metasync_review_notice_dismissed', false)) {
            return false;
        }

        // Check "remind me later" postponement
        $remind_later = get_option('metasync_review_notice_remind_later', 0);
        if ($remind_later && time() < (int) $remind_later) {
            return false;
        }

        // Check if enough time has passed since first activation
        $first_activation = get_option('metasync_first_activation_time', '');
        if (empty($first_activation)) {
            return false;
        }

        $activation_time = strtotime($first_activation);
        if ($activation_time === false) {
            return false;
        }

        $days_since_activation = (time() - $activation_time) / DAY_IN_SECONDS;
        if ($days_since_activation < self::DAYS_BEFORE_SHOWING) {
            return false;
        }

        return true;
    }

    /**
     * Display the review notice
     */
    public function display_review_notice() {
        if (!$this->should_display()) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $plugin_name = class_exists('Metasync') ? Metasync::get_effective_plugin_name() : 'Search Atlas';
        $review_url = self::REVIEW_URL;
        $nonce = wp_create_nonce('metasync_review_notice');

        ?>
        <div id="metasync-review-notice" class="notice notice-info" style="padding: 12px 16px; border-left-color: #f0b849;">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="font-size: 28px; line-height: 1;">
                    ⭐
                </div>
                <div style="flex: 1;">
                    <p style="margin: 0 0 6px 0; font-size: 14px;">
                        Enjoying <strong><?php echo esc_html($plugin_name); ?></strong>? Please consider leaving a rating to help us spread the word!
                    </p>
                    <p style="margin: 0; display: flex; gap: 10px; flex-wrap: wrap;">
                        <a href="<?php echo esc_url($review_url); ?>" target="_blank" rel="noopener noreferrer"
                           class="button button-primary metasync-review-rate-now"
                           style="background-color: #f0b849; border-color: #d9a53e; color: #1e1e1e;">
                            ★ Rate Now
                        </a>
                        <button type="button" class="button metasync-review-remind-later">
                            Maybe Later
                        </button>
                        <button type="button" class="button-link metasync-review-dismiss" style="color: #646970; text-decoration: none; padding: 4px 8px;">
                            Already Did / No Thanks
                        </button>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Enqueue admin scripts for notice handling
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_scripts($hook) {
        if (!$this->should_display()) {
            return;
        }

        wp_add_inline_script('jquery', $this->get_notice_script(), 'after');
    }

    /**
     * Get JavaScript for notice handling
     *
     * @return string JavaScript code.
     */
    private function get_notice_script() {
        $nonce = wp_create_nonce('metasync_review_notice');

        return "
        jQuery(document).ready(function($) {
            // Rate Now - dismiss permanently after clicking
            $('#metasync-review-notice').on('click', '.metasync-review-rate-now', function() {
                $.post(ajaxurl, {
                    action: 'metasync_dismiss_review_notice',
                    nonce: '" . esc_js($nonce) . "'
                });
                $('#metasync-review-notice').fadeOut();
            });

            // Maybe Later
            $('#metasync-review-notice').on('click', '.metasync-review-remind-later', function() {
                $.post(ajaxurl, {
                    action: 'metasync_remind_later_review_notice',
                    nonce: '" . esc_js($nonce) . "'
                });
                $('#metasync-review-notice').fadeOut();
            });

            // Already Did / No Thanks - dismiss permanently
            $('#metasync-review-notice').on('click', '.metasync-review-dismiss', function() {
                $.post(ajaxurl, {
                    action: 'metasync_dismiss_review_notice',
                    nonce: '" . esc_js($nonce) . "'
                });
                $('#metasync-review-notice').fadeOut();
            });
        });
        ";
    }

    /**
     * AJAX handler for permanently dismissing the review notice
     */
    public function ajax_dismiss_notice() {
        check_ajax_referer('metasync_review_notice', 'nonce');

        update_option('metasync_review_notice_dismissed', true);
        wp_send_json_success(['message' => 'Notice dismissed']);
    }

    /**
     * AJAX handler for "remind me later"
     */
    public function ajax_remind_later() {
        check_ajax_referer('metasync_review_notice', 'nonce');

        $remind_at = time() + (self::REMIND_LATER_DAYS * DAY_IN_SECONDS);
        update_option('metasync_review_notice_remind_later', $remind_at);
        wp_send_json_success(['message' => 'Reminder set']);
    }
}
