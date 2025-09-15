<?php
/**
 * Sentry Configuration Helper
 *
 * Helper functions to configure and test Sentry integration
 *
 * @package     Search Engine Labs SEO
 * @subpackage  Telemetry
 * @since       1.0.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Configure Sentry DSN
 * 
 * @param string $dsn Your Sentry DSN
 * @return bool Success status
 */
function metasync_set_sentry_dsn($dsn) {
    $options = get_option('metasync_options', array());
    $options['sentry_dsn'] = sanitize_text_field($dsn);
    return update_option('metasync_options', $options);
}

/**
 * Test Sentry connection
 * 
 * @return array Test results
 */
function metasync_test_sentry() {
    $telemetry = metasync_telemetry();
    return $telemetry->test_sentry_connection();
}

/**
 * Send test error to Sentry
 * 
 * @param string $message Optional test message
 * @return array Results
 */
function metasync_send_test_error($message = 'Test error from MetaSync Plugin') {
    $telemetry = metasync_telemetry();
    
    try {
        // Create a test exception
        throw new Exception($message . ' - Test Exception at ' . date('Y-m-d H:i:s'));
    } catch (Exception $e) {
        $telemetry->send_exception($e, array(
            'test' => true,
            'source' => 'manual_test',
            'timestamp' => time()
        ));
        
        return array(
            'success' => true,
            'message' => 'Test exception sent to both custom backend and Sentry'
        );
    }
}

/**
 * Send test message to Sentry
 * 
 * @param string $message Test message
 * @param string $level Log level
 * @return array Results
 */
function metasync_send_test_message($message = 'Test message from MetaSync Plugin', $level = 'warning') {
    $telemetry = metasync_telemetry();
    
    $telemetry->send_message($message, $level, array(
        'test' => true,
        'source' => 'manual_test',
        'timestamp' => time()
    ));
    
    return array(
        'success' => true,
        'message' => "Test {$level} message sent to both backends"
    );
}

/**
 * Get Sentry configuration status
 * 
 * @return array Configuration status
 */
function metasync_get_sentry_status() {
    $options = get_option('metasync_options', array());
    $dsn = isset($options['sentry_dsn']) ? $options['sentry_dsn'] : '';
    
    // Check if DSN is set via wp-config.php
    $wp_config_dsn = defined('METASYNC_SENTRY_DSN') ? METASYNC_SENTRY_DSN : '';
    
    $telemetry_stats = metasync_telemetry()->get_telemetry_stats();
    
    return array(
        'dsn_in_options' => !empty($dsn),
        'dsn_in_wp_config' => !empty($wp_config_dsn),
        'active_dsn' => !empty($wp_config_dsn) ? $wp_config_dsn : $dsn,
        'sentry_enabled' => isset($telemetry_stats['sentry_enabled']) ? $telemetry_stats['sentry_enabled'] : false,
        'telemetry_enabled' => isset($telemetry_stats['enabled']) ? $telemetry_stats['enabled'] : false,
        'environment' => Metasync_Telemetry_Config::detect_environment(),
        'plugin_version' => defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0'
    );
}

/**
 * Display Sentry setup instructions
 */
function metasync_show_sentry_instructions() {
    echo "<div style='background: #f9f9f9; border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 5px;'>";
    echo "<h3>🔧 Sentry Integration Setup</h3>";
    
    $status = metasync_get_sentry_status();
    
    if (!$status['sentry_enabled']) {
        echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 3px; margin-bottom: 15px;'>";
        echo "<strong>⚠️ Sentry Not Configured</strong><br>";
        echo "Follow the steps below to connect your Sentry dashboard.";
        echo "</div>";
    } else {
        echo "<div style='background: #d1edff; border: 1px solid #74b9ff; padding: 10px; border-radius: 3px; margin-bottom: 15px;'>";
        echo "<strong>✅ Sentry Configured</strong><br>";
        echo "Active DSN: " . substr($status['active_dsn'], 0, 30) . "...";
        echo "</div>";
    }
    
    echo "<h4>Step 1: Get Your Sentry DSN</h4>";
    echo "<ol>";
    echo "<li>Go to your <a href='https://sentry.io/projects/' target='_blank'>Sentry Dashboard</a></li>";
    echo "<li>Select your project (or create a new one)</li>";
    echo "<li>Go to Settings → Projects → [Your Project] → Client Keys (DSN)</li>";
    echo "<li>Copy the DSN (looks like: <code>https://[key]@[org].ingest.sentry.io/[project]</code>)</li>";
    echo "</ol>";
    
    echo "<h4>Step 2: Configure DSN (Choose One Method)</h4>";
    echo "<p><strong>Method A: Add to wp-config.php (Recommended)</strong></p>";
    echo "<pre style='background: #f1f1f1; padding: 10px; border-radius: 3px;'>";
    echo "// Add this line to your wp-config.php file\n";
    echo "define('METASYNC_SENTRY_DSN', 'YOUR_SENTRY_DSN_HERE');\n";
    echo "</pre>";
    
    echo "<p><strong>Method B: Using PHP function</strong></p>";
    echo "<pre style='background: #f1f1f1; padding: 10px; border-radius: 3px;'>";
    echo "// Add this to your theme's functions.php or a custom plugin\n";
    echo "metasync_set_sentry_dsn('YOUR_SENTRY_DSN_HERE');\n";
    echo "</pre>";
    
    echo "<h4>Step 3: Test the Connection</h4>";
    echo "<p>Run these commands to test your Sentry integration:</p>";
    echo "<pre style='background: #f1f1f1; padding: 10px; border-radius: 3px;'>";
    echo "// Test connection\n";
    echo "\$result = metasync_test_sentry();\n";
    echo "var_dump(\$result);\n\n";
    echo "// Send test error\n";
    echo "\$result = metasync_send_test_error('Test error message');\n";
    echo "var_dump(\$result);\n\n";
    echo "// Send test warning\n";
    echo "\$result = metasync_send_test_message('Test warning message', 'warning');\n";
    echo "var_dump(\$result);\n";
    echo "</pre>";
    
    echo "<h4>Current Status</h4>";
    echo "<table style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><td style='border: 1px solid #ddd; padding: 8px;'><strong>Telemetry Enabled:</strong></td><td style='border: 1px solid #ddd; padding: 8px;'>" . ($status['telemetry_enabled'] ? '✅ Yes' : '❌ No') . "</td></tr>";
    echo "<tr><td style='border: 1px solid #ddd; padding: 8px;'><strong>Sentry Enabled:</strong></td><td style='border: 1px solid #ddd; padding: 8px;'>" . ($status['sentry_enabled'] ? '✅ Yes' : '❌ No') . "</td></tr>";
    echo "<tr><td style='border: 1px solid #ddd; padding: 8px;'><strong>Environment:</strong></td><td style='border: 1px solid #ddd; padding: 8px;'>" . $status['environment'] . "</td></tr>";
    echo "<tr><td style='border: 1px solid #ddd; padding: 8px;'><strong>Plugin Version:</strong></td><td style='border: 1px solid #ddd; padding: 8px;'>" . $status['plugin_version'] . "</td></tr>";
    echo "</table>";
    
    echo "</div>";
}

// Auto-display instructions if called directly via admin
if (isset($_GET['metasync_sentry_setup'])) {
    add_action('admin_notices', 'metasync_show_sentry_instructions');
}
