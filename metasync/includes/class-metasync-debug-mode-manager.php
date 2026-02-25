<?php
/**
 * MetaSync Debug Mode Manager
 *
 * Manages debug mode with automatic disable, safety limits, and log rotation.
 * Implements time-based auto-disable and file size limits to prevent log file growth issues.
 *
 * @package MetaSync
 * @subpackage MetaSync/includes
 * @since 2.5.15
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Metasync_Debug_Mode_Manager
 *
 * Handles all debug mode functionality including:
 * - Time-based auto-disable (24 hours default)
 * - File size monitoring and rotation (10MB max)
 * - Manual override for indefinite debug mode
 * - Admin notifications for state changes
 * - Dashboard widget for status display
 *
 * @since 2.5.15
 */
class Metasync_Debug_Mode_Manager
{
    /**
     * Singleton instance
     *
     * @var Metasync_Debug_Mode_Manager|null
     */
    private static $instance = null;

    /**
     * Maximum debug log file size in bytes (10MB)
     *
     * @var int
     */
    const MAX_LOG_SIZE = 10485760; // 10MB in bytes

    /**
     * Debug mode duration in seconds (24 hours)
     *
     * @var int
     */
    const DEBUG_DURATION = 86400; // 24 hours in seconds

    /**
     * Maximum number of rotated log files to keep
     *
     * @var int
     */
    const MAX_ROTATED_LOGS = 1; // Keep current + 1 old

    /**
     * Cron hook name for checking debug limits
     *
     * @var string
     */
    const CRON_HOOK = 'metasync_check_debug_limits';

    /**
     * Option key for debug mode settings
     *
     * @var string
     */
    const OPTION_KEY = 'metasync_debug_mode_settings';

    /**
     * Transient key for admin notices
     *
     * @var string
     */
    const NOTICE_TRANSIENT = 'metasync_debug_mode_notices';

    /**
     * Debug log file path
     *
     * @var string
     */
    private $log_file_path;

    /**
     * Get singleton instance
     *
     * @return Metasync_Debug_Mode_Manager
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - Initialize hooks and settings
     */
    private function __construct()
    {
        $this->log_file_path = WP_CONTENT_DIR . '/debug.log';
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     *
     * @return void
     */
    private function init_hooks()
    {
        // Register cron schedules
        add_filter('cron_schedules', array($this, 'register_cron_schedules'));

        // Schedule cron job on activation
        add_action('init', array($this, 'maybe_schedule_cron'));

        // Cron job handler
        add_action(self::CRON_HOOK, array($this, 'check_debug_limits'));

        // Admin notices
        add_action('admin_notices', array($this, 'display_admin_notices'));

        // Dashboard widget
        add_action('wp_dashboard_setup', array($this, 'register_dashboard_widget'));

        // REST API endpoints
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Register custom cron schedules
     *
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     */
    public function register_cron_schedules($schedules)
    {
        // Ensure we don't override existing schedule
        if (!isset($schedules['hourly'])) {
            $schedules['hourly'] = array(
                'interval' => 3600,
                'display' => __('Once Hourly', 'metasync')
            );
        }
        return $schedules;
    }

    /**
     * Schedule cron job if not already scheduled
     *
     * @return void
     */
    public function maybe_schedule_cron()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
        }
    }

    /**
     * Enable debug mode
     *
     * @param bool $indefinite Whether to enable indefinitely
     * @return bool Success status
     */
    public function enable_debug_mode($indefinite = false)
    {
        $settings = array(
            'enabled' => true,
            'enabled_at' => current_time('timestamp'),
            'indefinite' => $indefinite,
            'extended_count' => 0
        );

        $result = update_option(self::OPTION_KEY, $settings);

        if ($result) {
            // Enable WP_DEBUG constants via ConfigController
            $this->update_wp_debug_constants(true);

            // Ensure cron job is scheduled (safety net)
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
            }

            // Clear any previous notices
            delete_transient(self::NOTICE_TRANSIENT);

            // Log the action
            error_log('MetaSync: Debug mode enabled' . ($indefinite ? ' (indefinite)' : ' (24 hours)'));
        }

        return $result;
    }

    /**
     * Disable debug mode
     *
     * @param string $reason Reason for disabling
     * @return bool Success status
     */
    public function disable_debug_mode($reason = 'manual')
    {
        $settings = $this->get_settings();
        $settings['enabled'] = false;
        $settings['disabled_at'] = current_time('timestamp');
        $settings['disabled_reason'] = $reason;

        $result = update_option(self::OPTION_KEY, $settings);

        if ($result) {
            // Disable WP_DEBUG constants via ConfigController
            $this->update_wp_debug_constants(false);

            // Add admin notice
            $this->add_notice(
                'Debug mode has been disabled (' . $reason . ').',
                'info'
            );

            // Log the action
            error_log('MetaSync: Debug mode disabled - ' . $reason);
        }

        return $result;
    }

    /**
     * Extend debug mode for another 24 hours
     *
     * @return bool Success status
     */
    public function extend_debug_mode()
    {
        $settings = $this->get_settings();

        if (!$settings['enabled']) {
            return false;
        }

        $settings['enabled_at'] = current_time('timestamp');
        $settings['extended_count'] = ($settings['extended_count'] ?? 0) + 1;

        $result = update_option(self::OPTION_KEY, $settings);

        if ($result) {
            $this->add_notice(
                'Debug mode extended for another 24 hours.',
                'success'
            );
        }

        return $result;
    }

    /**
     * Toggle indefinite mode
     *
     * @param bool $enable Whether to enable indefinite mode
     * @return bool Success status
     */
    public function toggle_indefinite_mode($enable)
    {
        $settings = $this->get_settings();
        $settings['indefinite'] = $enable;

        return update_option(self::OPTION_KEY, $settings);
    }

    /**
     * Check debug limits (called by cron)
     *
     * @return void
     */
    public function check_debug_limits()
    {
        $this->check_time_limit();
        $this->check_file_size_limit();
    }

    /**
     * Check if debug mode has exceeded time limit
     *
     * @return void
     */
    private function check_time_limit()
    {
        $settings = $this->get_settings();

        // Skip if debug mode is disabled or in indefinite mode
        if (!$settings['enabled'] || $settings['indefinite']) {
            return;
        }

        $enabled_at = $settings['enabled_at'];
        $current_time = current_time('timestamp');
        $elapsed_time = $current_time - $enabled_at;

        // Check if 24 hours have passed
        if ($elapsed_time >= self::DEBUG_DURATION) {
            $this->disable_debug_mode('auto_expired');
            $this->add_notice(
                'Debug mode auto-disabled after 24 hours.',
                'warning'
            );
        }
    }

    /**
     * Check if debug log file has exceeded size limit
     *
     * @return void
     */
    private function check_file_size_limit()
    {
        if (!file_exists($this->log_file_path)) {
            return;
        }

        $file_size = filesize($this->log_file_path);

        if ($file_size >= self::MAX_LOG_SIZE) {
            $this->rotate_log_file();
            $this->add_notice(
                sprintf('Debug log rotated due to size limit (%s).', $this->format_bytes(self::MAX_LOG_SIZE)),
                'info'
            );
        }
    }

    /**
     * Rotate debug log file
     *
     * @return bool Success status
     */
    private function rotate_log_file()
    {
        if (!file_exists($this->log_file_path)) {
            return false;
        }

        $backup_path = $this->log_file_path . '.old';

        // Remove existing .old file if it exists
        if (file_exists($backup_path)) {
            @unlink($backup_path);
        }

        // Rename current log to .old
        $result = @rename($this->log_file_path, $backup_path);

        if ($result) {
            // Create new empty log file
            @file_put_contents($this->log_file_path, '');
            error_log('MetaSync: Debug log rotated - exceeded 10MB limit');
        }

        // Cleanup old rotations (keep only MAX_ROTATED_LOGS)
        $this->cleanup_old_rotations();

        return $result;
    }

    /**
     * Cleanup old log rotations
     *
     * @return void
     */
    private function cleanup_old_rotations()
    {
        $log_dir = dirname($this->log_file_path);
        $log_basename = basename($this->log_file_path);
        $pattern = $log_dir . '/' . $log_basename . '.old*';

        $old_logs = glob($pattern);

        if (count($old_logs) > self::MAX_ROTATED_LOGS) {
            // Sort by modification time (oldest first)
            usort($old_logs, function ($a, $b) {
                return filemtime($a) - filemtime($b);
            });

            // Delete oldest files, keep only MAX_ROTATED_LOGS
            $to_delete = array_slice($old_logs, 0, count($old_logs) - self::MAX_ROTATED_LOGS);
            foreach ($to_delete as $old_log) {
                @unlink($old_log);
            }
        }
    }

    /**
     * Get current debug mode settings
     *
     * @return array Debug mode settings
     */
    public function get_settings()
    {
        $defaults = array(
            'enabled' => false,
            'enabled_at' => 0,
            'indefinite' => false,
            'extended_count' => 0,
            'disabled_at' => 0,
            'disabled_reason' => ''
        );

        $settings = get_option(self::OPTION_KEY, $defaults);

        return wp_parse_args($settings, $defaults);
    }

    /**
     * Get debug mode status for dashboard widget
     *
     * @return array Status information
     */
    public function get_status()
    {
        $settings = $this->get_settings();
        $file_size = file_exists($this->log_file_path) ? filesize($this->log_file_path) : 0;

        $status = array(
            'enabled' => $settings['enabled'],
            'indefinite' => $settings['indefinite'],
            'enabled_at' => $settings['enabled_at'],
            'time_remaining' => 0,
            'time_remaining_formatted' => 'N/A',
            'log_file_size' => $file_size,
            'log_file_size_formatted' => $this->format_bytes($file_size),
            'log_file_path' => $this->log_file_path,
            'max_log_size' => self::MAX_LOG_SIZE,
            'max_log_size_formatted' => $this->format_bytes(self::MAX_LOG_SIZE),
            'percentage_used' => 0
        );

        if ($settings['enabled'] && !$settings['indefinite']) {
            $elapsed_time = current_time('timestamp') - $settings['enabled_at'];
            $time_remaining = max(0, self::DEBUG_DURATION - $elapsed_time);
            $status['time_remaining'] = $time_remaining;
            $status['time_remaining_formatted'] = $this->format_time_remaining($time_remaining);
        } elseif ($settings['enabled'] && $settings['indefinite']) {
            $status['time_remaining_formatted'] = 'Indefinite';
        }

        if (self::MAX_LOG_SIZE > 0) {
            $status['percentage_used'] = min(100, ($file_size / self::MAX_LOG_SIZE) * 100);
        }

        return $status;
    }

    /**
     * Update WP_DEBUG constants via ConfigController
     *
     * @param bool $enable Whether to enable or disable
     * @return void
     */
    private function update_wp_debug_constants($enable)
    {
        try {
            update_option('wp_debug_enabled', $enable ? 'true' : 'false');
            update_option('wp_debug_log_enabled', $enable ? 'true' : 'false');

            $config_controller = new ConfigControllerMetaSync();
            $config_controller->store();
        } catch (Exception $e) {
            error_log('MetaSync: Error updating wp-config.php - ' . $e->getMessage());
        }
    }

    /**
     * Add admin notice
     *
     * @param string $message Notice message
     * @param string $type Notice type (success, warning, error, info)
     * @return void
     */
    private function add_notice($message, $type = 'info')
    {
        $notices = get_transient(self::NOTICE_TRANSIENT);
        if (!is_array($notices)) {
            $notices = array();
        }

        $notices[] = array(
            'message' => $message,
            'type' => $type,
            'timestamp' => current_time('timestamp')
        );

        set_transient(self::NOTICE_TRANSIENT, $notices, DAY_IN_SECONDS);
    }

    /**
     * Display admin notices
     *
     * @return void
     */
    public function display_admin_notices()
    {
        $notices = get_transient(self::NOTICE_TRANSIENT);

        if (!is_array($notices) || empty($notices)) {
            return;
        }

        foreach ($notices as $notice) {
            $class = 'notice notice-' . esc_attr($notice['type']) . ' is-dismissible';
            printf(
                '<div class="%1$s"><p><strong>MetaSync Debug Mode:</strong> %2$s</p></div>',
                $class,
                esc_html($notice['message'])
            );
        }

        // Clear notices after displaying
        delete_transient(self::NOTICE_TRANSIENT);
    }

    /**
     * Register dashboard widget
     *
     * @return void
     */
    public function register_dashboard_widget()
    {
        // Only show to users with manage_options capability
        if (!current_user_can('manage_options')) {
            return;
        }

        $status = $this->get_status();

        // Only show widget if debug mode is enabled
        if (!$status['enabled']) {
            return;
        }

        wp_add_dashboard_widget(
            'metasync_debug_mode_widget',
            'MetaSync Debug Mode',
            array($this, 'render_dashboard_widget')
        );
    }

    /**
     * Render dashboard widget
     *
     * @return void
     */
    public function render_dashboard_widget()
    {
        $status = $this->get_status();
        ?>
        <div class="metasync-debug-widget">
            <div class="debug-status">
                <span class="status-indicator <?php echo $status['enabled'] ? 'active' : 'inactive'; ?>">
                    <?php echo $status['enabled'] ? '⚠️ Active' : '✓ Inactive'; ?>
                </span>
            </div>

            <?php if ($status['enabled']): ?>
                <div class="debug-info">
                    <p>
                        <strong>Auto-disable in:</strong>
                        <span class="time-remaining"><?php echo esc_html($status['time_remaining_formatted']); ?></span>
                    </p>
                    <p>
                        <strong>Log file size:</strong>
                        <span class="file-size">
                            <?php echo esc_html($status['log_file_size_formatted']); ?> /
                            <?php echo esc_html($status['max_log_size_formatted']); ?>
                        </span>
                    </p>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo esc_attr($status['percentage_used']); ?>%"></div>
                    </div>
                </div>

                <div class="debug-actions">
                    <?php if (!$status['indefinite']): ?>
                        <button type="button" class="button button-secondary" id="metasync-extend-debug">
                            Extend for 24 Hours
                        </button>
                    <?php endif; ?>
                    <button type="button" class="button button-primary" id="metasync-disable-debug">
                        Disable Now
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <style>
            .metasync-debug-widget {
                padding: 10px 0;
            }
            .debug-status {
                margin-bottom: 15px;
                font-size: 16px;
            }
            .status-indicator {
                display: inline-block;
                padding: 5px 10px;
                border-radius: 3px;
                font-weight: 600;
            }
            .status-indicator.active {
                background: #fff3cd;
                color: #856404;
            }
            .status-indicator.inactive {
                background: #d4edda;
                color: #155724;
            }
            .debug-info p {
                margin: 8px 0;
            }
            .progress-bar {
                width: 100%;
                height: 20px;
                background: #f0f0f0;
                border-radius: 3px;
                overflow: hidden;
                margin: 10px 0;
            }
            .progress-fill {
                height: 100%;
                background: linear-gradient(90deg, #46b450 0%, #ffb900 70%, #dc3232 100%);
                transition: width 0.3s ease;
            }
            .debug-actions {
                margin-top: 15px;
                display: flex;
                gap: 10px;
            }
            .debug-actions button {
                flex: 1;
            }
        </style>
        <?php
    }

    /**
     * Enqueue admin scripts
     *
     * @param string $hook Current admin page hook
     * @return void
     */
    public function enqueue_admin_scripts($hook)
    {
        // Only enqueue on dashboard
        if ($hook !== 'index.php') {
            return;
        }

        wp_enqueue_script(
            'metasync-debug-widget',
            plugin_dir_url(dirname(__FILE__)) . 'admin/js/debug-widget.js',
            array('jquery'),
            METASYNC_VERSION,
            true
        );

        wp_localize_script('metasync-debug-widget', 'metasyncDebug', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('metasync_debug_mode'),
            'restUrl' => rest_url('metasync/v1/debug-mode/'),
            'restNonce' => wp_create_nonce('wp_rest')
        ));
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_rest_routes()
    {
        register_rest_route('metasync/v1', '/debug-mode/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_status'),
            'permission_callback' => array($this, 'rest_permission_check')
        ));

        register_rest_route('metasync/v1', '/debug-mode/enable', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_enable_debug'),
            'permission_callback' => array($this, 'rest_permission_check')
        ));

        register_rest_route('metasync/v1', '/debug-mode/disable', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_disable_debug'),
            'permission_callback' => array($this, 'rest_permission_check')
        ));

        register_rest_route('metasync/v1', '/debug-mode/extend', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_extend_debug'),
            'permission_callback' => array($this, 'rest_permission_check')
        ));
    }

    /**
     * REST API permission check
     *
     * @return bool
     */
    public function rest_permission_check()
    {
        return current_user_can('manage_options');
    }

    /**
     * REST API: Get debug mode status
     *
     * @return WP_REST_Response
     */
    public function rest_get_status()
    {
        return new WP_REST_Response($this->get_status(), 200);
    }

    /**
     * REST API: Enable debug mode
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function rest_enable_debug($request)
    {
        $indefinite = $request->get_param('indefinite') === true;
        $result = $this->enable_debug_mode($indefinite);

        if ($result) {
            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Debug mode enabled',
                'status' => $this->get_status()
            ), 200);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to enable debug mode'
        ), 500);
    }

    /**
     * REST API: Disable debug mode
     *
     * @return WP_REST_Response
     */
    public function rest_disable_debug()
    {
        $result = $this->disable_debug_mode('manual');

        if ($result) {
            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Debug mode disabled',
                'status' => $this->get_status()
            ), 200);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to disable debug mode'
        ), 500);
    }

    /**
     * REST API: Extend debug mode
     *
     * @return WP_REST_Response
     */
    public function rest_extend_debug()
    {
        $result = $this->extend_debug_mode();

        if ($result) {
            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Debug mode extended for 24 hours',
                'status' => $this->get_status()
            ), 200);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to extend debug mode'
        ), 500);
    }

    /**
     * Format bytes to human-readable format
     *
     * @param int $bytes File size in bytes
     * @return string Formatted size
     */
    private function format_bytes($bytes)
    {
        if ($bytes == 0) {
            return '0 B';
        }

        $units = array('B', 'KB', 'MB', 'GB');
        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf('%.2f %s', $bytes / pow(1024, $factor), $units[$factor]);
    }

    /**
     * Format time remaining to human-readable format
     *
     * @param int $seconds Time in seconds
     * @return string Formatted time
     */
    private function format_time_remaining($seconds)
    {
        if ($seconds <= 0) {
            return 'Expired';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($hours > 0) {
            return sprintf('%d hours %d minutes', $hours, $minutes);
        }

        return sprintf('%d minutes', $minutes);
    }

    /**
     * Uninstall - Clean up options and cron jobs
     *
     * @return void
     */
    public static function uninstall()
    {
        // Remove scheduled cron
        wp_clear_scheduled_hook(self::CRON_HOOK);

        // Remove options
        delete_option(self::OPTION_KEY);
        delete_transient(self::NOTICE_TRANSIENT);
    }
}
