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
     * Outcomes of a wp-config.php constant write.
     *
     * WRITE_PARTIAL matters: some constants are live on disk, so the caller must not
     * report "off" - that hides the dashboard widget and stops the auto-disable cron.
     */
    const WRITE_OK = 'ok';
    const WRITE_PARTIAL = 'partial';
    const WRITE_FAILED = 'failed';

    /**
     * Maximum queued admin notices.
     */
    const MAX_NOTICES = 5;

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
        $previousSettings = get_option(self::OPTION_KEY);

        $settings = array(
            'enabled' => true,
            'enabled_at' => current_time('timestamp'),
            'indefinite' => $indefinite,
            'extended_count' => 0
        );

        $result = update_option(self::OPTION_KEY, $settings);

        if ($result) {
            // Enable WP_DEBUG constants via ConfigController
            $write = $this->update_wp_debug_constants(true);

            // Ensure cron job is scheduled (safety net). Scheduled before the write is
            // judged, because the partial-write branch below deliberately leaves debug
            // enabled on the assumption this cron can still clean it up.
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
            }

            if (self::WRITE_OK !== $write) {
                // Only roll the tracking state back when nothing reached wp-config.php.
                // After a partial write the constants that landed are live, and a state of
                // "off" would hide the dashboard widget and make the auto-disable cron
                // skip - leaving debug on with nothing left to turn it off.
                if (self::WRITE_FAILED === $write) {
                    if (false !== $previousSettings) {
                        update_option(self::OPTION_KEY, $previousSettings);
                    } else {
                        delete_option(self::OPTION_KEY);
                    }
                }

                return false;
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
        $previousSettings = get_option(self::OPTION_KEY);

        $settings = $this->get_settings();
        $settings['enabled'] = false;
        $settings['disabled_at'] = current_time('timestamp');
        $settings['disabled_reason'] = $reason;

        $result = update_option(self::OPTION_KEY, $settings);

        if ($result) {
            // Disable WP_DEBUG constants via ConfigController
            if (self::WRITE_OK !== $this->update_wp_debug_constants(false)) {
                // Debug is still on in wp-config.php, wholly or partly. Restore the
                // enabled state so it matches the file: that keeps the dashboard widget
                // visible and the auto-disable cron running, which is what will eventually
                // clear it. Reporting "disabled" here would hide both.
                if (false !== $previousSettings) {
                    update_option(self::OPTION_KEY, $previousSettings);
                }

                return false;
            }

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
            // Only announce the auto-disable if it actually succeeded. This runs hourly,
            // so claiming it unconditionally would repeat the message every hour on a site
            // where wp-config.php cannot be written.
            if ($this->disable_debug_mode('auto_expired')) {
                $this->add_notice(
                    'Debug mode auto-disabled after 24 hours.',
                    'warning'
                );
            }
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
     * Check whether debug mode is currently enabled
     *
     * Static and side-effect free: reads the option directly rather than going
     * through get_instance(), which registers hooks as part of construction.
     * Safe to call from front-end request paths, including ones that run before
     * the singleton is initialized on 'init'.
     *
     * @return bool True when debug mode is on
     */
    public static function is_enabled()
    {
        $settings = get_option(self::OPTION_KEY, array());

        return is_array($settings) && !empty($settings['enabled']);
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
     * @return string One of self::WRITE_OK, self::WRITE_PARTIAL or self::WRITE_FAILED.
     */
    private function update_wp_debug_constants($enable)
    {
        $previousEnabled = get_option('wp_debug_enabled', 'false');
        $previousLog = get_option('wp_debug_log_enabled', 'false');

        try {
            update_option('wp_debug_enabled', $enable ? 'true' : 'false');
            update_option('wp_debug_log_enabled', $enable ? 'true' : 'false');

            $config_controller = new ConfigControllerMetaSync();

            $reason = '';
            $partial = false;
            if (!$config_controller->isReady()) {
                $reason = $config_controller->getConfigError() !== ''
                    ? $config_controller->getConfigError()
                    : 'the file is not writable.';
            } elseif (!$config_controller->store()) {
                $reason = $config_controller->getConfigError() !== ''
                    ? $config_controller->getConfigError()
                    : 'the write did not complete.';
                $partial = $config_controller->hadPartialWrite();
            }

            if ('' !== $reason) {
                if (!$partial) {
                    // Nothing reached the file, so the flags can safely go back.
                    update_option('wp_debug_enabled', $previousEnabled);
                    update_option('wp_debug_log_enabled', $previousLog);
                }

                // admin_notices never fires during a REST request, so the controller's own
                // notice would be discarded - use the transient notices the admin reads.
                $this->add_notice('wp-config.php could not be updated: ' . $reason, 'error');
                error_log('MetaSync: wp-config.php not updated - ' . $reason);

                return $partial ? self::WRITE_PARTIAL : self::WRITE_FAILED;
            }

            return self::WRITE_OK;
        } catch (\Throwable $e) {
            // Throwable rather than Exception: an Error here would be a fatal on what is
            // an ordinary REST request.
            update_option('wp_debug_enabled', $previousEnabled);
            update_option('wp_debug_log_enabled', $previousLog);
            error_log('MetaSync: Error updating wp-config.php - ' . $e->getMessage());
            return self::WRITE_FAILED;
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

        // Skip an identical message that is already queued. The limit checks run hourly,
        // so a persistent failure would otherwise append the same notice every hour -
        // and set_transient() refreshes the TTL each time, so the queue never expires.
        foreach ($notices as $existing) {
            if (isset($existing['message'], $existing['type'])
                && $existing['message'] === $message
                && $existing['type'] === $type) {
                return;
            }
        }

        $notices[] = array(
            'message' => $message,
            'type' => $type,
            'timestamp' => current_time('timestamp')
        );

        // Keep the queue bounded so the admin screen cannot be flooded. Drop informational
        // notices first: evicting oldest-first would discard an error about wp-config.php
        // not being writable in favour of routine status messages.
        if (count($notices) > self::MAX_NOTICES) {
            $errors = array_values(array_filter($notices, function ($notice) {
                return isset($notice['type']) && 'error' === $notice['type'];
            }));
            $others = array_values(array_filter($notices, function ($notice) {
                return !isset($notice['type']) || 'error' !== $notice['type'];
            }));

            $keepOthers = max(0, self::MAX_NOTICES - count($errors));
            $notices = array_merge(
                array_slice($errors, -self::MAX_NOTICES),
                array_slice($others, -$keepOthers)
            );
        }

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
