<?php
/**
 * API Backoff Admin Notices
 *
 * Handles display of admin notices when API endpoints are in backoff mode.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 * @since      2.5.15
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Metasync_API_Backoff_Notices
 *
 * Displays informative admin notices when API backoff is active.
 */
class Metasync_API_Backoff_Notices {

    /**
     * Singleton instance
     *
     * @var Metasync_API_Backoff_Notices|null
     */
    private static $instance = null;

    /**
     * Backoff manager instance
     *
     * @var Metasync_API_Backoff_Manager
     */
    private $backoff_manager;

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        $this->backoff_manager = Metasync_API_Backoff_Manager::get_instance();
        $this->init_hooks();
    }

    /**
     * Get singleton instance
     *
     * @return Metasync_API_Backoff_Notices
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
        // Display admin notices
        add_action('admin_notices', [$this, 'display_backoff_notices']);

        // Add AJAX handler for dismissing notices
        add_action('wp_ajax_metasync_dismiss_backoff_notice', [$this, 'ajax_dismiss_notice']);

        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Display backoff notices in admin
     */
    public function display_backoff_notices() {
        // Only show on MetaSync admin pages
        if (!$this->is_metasync_admin_page()) {
            return;
        }

        $active_backoffs = $this->backoff_manager->get_all_active_backoffs();

        if (empty($active_backoffs)) {
            return;
        }

        foreach ($active_backoffs as $backoff) {
            $this->render_backoff_notice($backoff);
        }
    }

    /**
     * Render individual backoff notice
     *
     * @param array $backoff Backoff state data.
     */
    private function render_backoff_notice(array $backoff) {
        $endpoint = esc_html($backoff['endpoint']);
        $time_remaining = Metasync_API_Backoff_Manager::format_time_remaining($backoff['time_remaining']);
        $occurrence = $backoff['occurrence_count'];
        $response_code = $backoff['response_code'];
        $endpoint_hash = $backoff['endpoint_hash'];

        $notice_class = 'notice notice-warning is-dismissible metasync-backoff-notice';
        $notice_id = 'metasync-backoff-' . esc_attr($endpoint_hash);

        $status_label = $response_code === 429 ? 'Rate Limited' : 'Service Unavailable';
        $status_icon = '⏸️';

        ?>
        <div id="<?php echo $notice_id; ?>" class="<?php echo esc_attr($notice_class); ?>" data-endpoint-hash="<?php echo esc_attr($endpoint_hash); ?>">
            <div style="display: flex; align-items: flex-start; gap: 12px; padding: 4px 0;">
                <div style="font-size: 24px; line-height: 1;"><?php echo $status_icon; ?></div>
                <div style="flex: 1;">
                    <p style="margin: 0 0 8px 0; font-weight: 600; font-size: 14px;">
                        <strong>API Backoff Active:</strong> <?php echo esc_html($endpoint); ?>
                    </p>
                    <p style="margin: 0 0 8px 0; font-size: 13px;">
                        <span style="background: #f0f0f1; padding: 3px 8px; border-radius: 3px; margin-right: 8px;">
                            <strong>Status:</strong> <?php echo esc_html($status_label); ?> (<?php echo esc_html($response_code); ?>)
                        </span>
                        <span style="background: #f0f0f1; padding: 3px 8px; border-radius: 3px; margin-right: 8px;">
                            <strong>Occurrence:</strong> <?php echo esc_html($occurrence); ?>/3
                        </span>
                        <span style="background: #fef7f0; color: #b5670e; padding: 3px 8px; border-radius: 3px;">
                            <strong>Time Remaining:</strong> <?php echo esc_html($time_remaining); ?>
                        </span>
                    </p>
                    <p style="margin: 0; font-size: 13px; color: #646970;">
                        All requests to this endpoint are temporarily paused. The plugin will automatically resume requests when the backoff period expires.
                        <?php if ($occurrence < 3): ?>
                        If this continues, the pause duration will increase to prevent further rate limiting.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Check if current page is a MetaSync admin page
     *
     * @return bool True if on MetaSync admin page.
     */
    private function is_metasync_admin_page() {
        $screen = get_current_screen();

        if (!$screen) {
            return false;
        }

        // Check if on MetaSync settings page or any page with metasync in the ID
        return strpos($screen->id, 'metasync') !== false ||
               (isset($_GET['page']) && strpos($_GET['page'], 'metasync') !== false);
    }

    /**
     * Enqueue admin scripts for notice handling
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_scripts($hook) {
        if (!$this->is_metasync_admin_page()) {
            return;
        }

        // Enqueue inline script for auto-refresh and dismiss handling
        wp_add_inline_script('jquery', $this->get_notice_script(), 'after');
    }

    /**
     * Get JavaScript for notice handling
     *
     * @return string JavaScript code.
     */
    private function get_notice_script() {
        return "
        jQuery(document).ready(function($) {
            // Auto-refresh backoff notices every 30 seconds
            var refreshInterval = setInterval(function() {
                var backoffNotices = $('.metasync-backoff-notice');
                if (backoffNotices.length > 0) {
                    // Check if any notices are still active
                    location.reload();
                } else {
                    // No more notices, stop refreshing
                    clearInterval(refreshInterval);
                }
            }, 30000);

            // Handle notice dismissal
            $(document).on('click', '.metasync-backoff-notice .notice-dismiss', function() {
                var notice = $(this).closest('.metasync-backoff-notice');
                var endpointHash = notice.data('endpoint-hash');

                // Optionally send AJAX to log dismissal
                $.post(ajaxurl, {
                    action: 'metasync_dismiss_backoff_notice',
                    endpoint_hash: endpointHash,
                    nonce: '" . wp_create_nonce('metasync_backoff_notice') . "'
                });
            });
        });
        ";
    }

    /**
     * AJAX handler for dismissing notices
     */
    public function ajax_dismiss_notice() {
        check_ajax_referer('metasync_backoff_notice', 'nonce');

        $endpoint_hash = sanitize_text_field($_POST['endpoint_hash'] ?? '');

        if (empty($endpoint_hash)) {
            wp_send_json_error(['message' => 'Invalid endpoint hash']);
        }

        // Log the dismissal (notice will reappear on page refresh if still active)
        error_log(sprintf(
            '[MetaSync API_BACKOFF_NOTICE_DISMISSED] User %d dismissed notice for endpoint: %s',
            get_current_user_id(),
            $endpoint_hash
        ));

        wp_send_json_success(['message' => 'Notice dismissed']);
    }

    /**
     * Display backoff status in admin bar (optional)
     *
     * @param WP_Admin_Bar $wp_admin_bar WordPress admin bar object.
     */
    public function add_admin_bar_status($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $active_backoffs = $this->backoff_manager->get_all_active_backoffs();

        if (empty($active_backoffs)) {
            return;
        }

        $count = count($active_backoffs);

        $wp_admin_bar->add_node([
            'id'    => 'metasync-backoff-status',
            'title' => sprintf(
                '<span style="color: #f0c36d;">⏸️ API Backoff (%d)</span>',
                $count
            ),
            'href'  => admin_url('admin.php?page=metasync-settings'),
            'meta'  => [
                'title' => sprintf(
                    '%d API endpoint%s in backoff mode',
                    $count,
                    $count > 1 ? 's' : ''
                ),
            ],
        ]);

        // Add child nodes for each backoff
        foreach ($active_backoffs as $backoff) {
            $wp_admin_bar->add_node([
                'parent' => 'metasync-backoff-status',
                'id'     => 'metasync-backoff-' . $backoff['endpoint_hash'],
                'title'  => sprintf(
                    '%s - %s remaining',
                    esc_html($backoff['endpoint']),
                    Metasync_API_Backoff_Manager::format_time_remaining($backoff['time_remaining'])
                ),
            ]);
        }
    }

    /**
     * Get notice HTML for manual display
     *
     * @param string $endpoint_hash Endpoint hash.
     * @return string Notice HTML.
     */
    public function get_notice_html($endpoint_hash) {
        $backoff = $this->backoff_manager->get_backoff_state($endpoint_hash);

        if (!$backoff || $backoff['time_remaining'] <= 0) {
            return '';
        }

        ob_start();
        $backoff['endpoint_hash'] = $endpoint_hash;
        $this->render_backoff_notice($backoff);
        return ob_get_clean();
    }
}
