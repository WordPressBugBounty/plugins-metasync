<?php
/**
 * Sentry Proxy Connection Test
 * 
 * Access this file directly via browser:
 * http://localhost/wordpress/wp-content/plugins/metasync/sentry-test.php
 */

// Load WordPress
require_once('../../../wp-load.php');

// Security check
if (!current_user_can('manage_options')) {
    die('Access denied. Please log in as administrator.');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>MetaSync Sentry Proxy Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .info { color: blue; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ccc; }
        pre { background: #f5f5f5; padding: 10px; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word; }
        .postman-section { background: #e8f4f8; border: 2px solid #0073aa; }
        .copy-button { background: #0073aa; color: white; padding: 5px 10px; margin: 5px 0; border: none; cursor: pointer; }
    </style>
    <script>
        function copyToClipboard(elementId) {
            const element = document.getElementById(elementId);
            const text = element.textContent;
            navigator.clipboard.writeText(text).then(() => {
                alert('Copied to clipboard!');
            });
        }
    </script>
</head>
<body>
    <h1>MetaSync Sentry Proxy Connection Test</h1>
    
    <div class="section">
        <h2>1. Constants Check</h2>
        <ul>
            <li><strong>METASYNC_SENTRY_PROJECT_ID:</strong> 
                <span class="<?php echo defined('METASYNC_SENTRY_PROJECT_ID') ? 'success' : 'error'; ?>">
                    <?php echo defined('METASYNC_SENTRY_PROJECT_ID') ? METASYNC_SENTRY_PROJECT_ID : 'NOT DEFINED'; ?>
                </span>
            </li>
            <li><strong>METASYNC_SENTRY_ENVIRONMENT:</strong> 
                <span class="<?php echo defined('METASYNC_SENTRY_ENVIRONMENT') ? 'success' : 'error'; ?>">
                    <?php echo defined('METASYNC_SENTRY_ENVIRONMENT') ? METASYNC_SENTRY_ENVIRONMENT : 'NOT DEFINED'; ?>
                </span>
            </li>
            <li><strong>METASYNC_SENTRY_RELEASE:</strong> 
                <span class="<?php echo defined('METASYNC_SENTRY_RELEASE') ? 'success' : 'error'; ?>">
                    <?php echo defined('METASYNC_SENTRY_RELEASE') ? METASYNC_SENTRY_RELEASE : 'NOT DEFINED'; ?>
                </span>
            </li>
            <li><strong>METASYNC_VERSION:</strong> 
                <span class="<?php echo defined('METASYNC_VERSION') ? 'success' : 'error'; ?>">
                    <?php echo defined('METASYNC_VERSION') ? METASYNC_VERSION : 'NOT DEFINED'; ?>
                </span>
            </li>
        </ul>
    </div>

    <div class="section">
        <h2>2. Functions Check</h2>
        <ul>
            <li><strong>metasync_get_jwt_token:</strong> 
                <span class="<?php echo function_exists('metasync_get_jwt_token') ? 'success' : 'error'; ?>">
                    <?php echo function_exists('metasync_get_jwt_token') ? 'EXISTS' : 'NOT EXISTS'; ?>
                </span>
            </li>
            <li><strong>metasync_telemetry:</strong> 
                <span class="<?php echo function_exists('metasync_telemetry') ? 'success' : 'error'; ?>">
                    <?php echo function_exists('metasync_telemetry') ? 'EXISTS' : 'NOT EXISTS'; ?>
                </span>
            </li>
            <li><strong>metasync_sentry_capture_message:</strong> 
                <span class="<?php echo function_exists('metasync_sentry_capture_message') ? 'success' : 'error'; ?>">
                    <?php echo function_exists('metasync_sentry_capture_message') ? 'EXISTS' : 'NOT EXISTS'; ?>
                </span>
            </li>
            <li><strong>metasync_sentry_test_connection:</strong> 
                <span class="<?php echo function_exists('metasync_sentry_test_connection') ? 'success' : 'error'; ?>">
                    <?php echo function_exists('metasync_sentry_test_connection') ? 'EXISTS' : 'NOT EXISTS'; ?>
                </span>
            </li>
        </ul>
    </div>

    <div class="section">
        <h2>3. JWT Token Test</h2>
        <?php
        if (function_exists('metasync_get_jwt_token')) {
            try {
                echo '<p class="info">Attempting to retrieve JWT token...</p>';
                $jwt_token = metasync_get_jwt_token();
                if (!empty($jwt_token)) {
                    echo '<p class="success">✅ JWT Token Retrieved Successfully</p>';
                    echo '<p><strong>Token Preview:</strong> ' . substr($jwt_token, 0, 50) . '...</p>';
                    echo '<p><strong>Token Length:</strong> ' . strlen($jwt_token) . ' characters</p>';
                } else {
                    echo '<p class="error">❌ JWT Token is Empty</p>';
                    echo '<p class="info">This may be because:</p>';
                    echo '<ul>';
                    echo '<li>No API key is configured in plugin settings</li>';
                    echo '<li>API key is invalid or expired</li>';
                    echo '<li>Network connectivity issues</li>';
                    echo '</ul>';
                    
                    // Check if API key exists using the correct method
                    if (class_exists('Metasync')) {
                        $general_options = Metasync::get_option('general') ?? [];
                        $api_key = $general_options['searchatlas_api_key'] ?? '';
                        echo '<p><strong>Search Atlas API Key Status:</strong> ' . (empty($api_key) ? '❌ Not configured' : '✅ Configured (' . substr($api_key, 0, 10) . '...)') . '</p>';
                        
                        // Additional debug info
                        echo '<p><strong>Debug Info:</strong></p>';
                        echo '<ul>';
                        echo '<li>Option method: Metasync::get_option(\'general\')</li>';
                        echo '<li>Available keys: ' . implode(', ', array_keys($general_options)) . '</li>';
                        echo '</ul>';
                        
                        // Also check raw option
                        $raw_options = get_option('metasync_options', []);
                        $raw_general = $raw_options['general'] ?? [];
                        $raw_api_key = $raw_general['searchatlas_api_key'] ?? '';
                        echo '<p><strong>Raw Option Check:</strong> ' . (empty($raw_api_key) ? '❌ Empty in raw option' : '✅ Found in raw option (' . substr($raw_api_key, 0, 10) . '...)') . '</p>';
                    } else {
                        echo '<p class="error">❌ Metasync class not available</p>';
                    }
                }
            } catch (Exception $e) {
                echo '<p class="error">❌ JWT Token Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            } catch (Error $e) {
                echo '<p class="error">❌ JWT Token Fatal Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
        } else {
            echo '<p class="error">❌ JWT Token function not available</p>';
        }
        ?>
    </div>

    <div class="section">
        <h2>4. Proxy Configuration</h2>
        <?php
        if (class_exists('Metasync')) {
            echo '<p><strong>CA_API_DOMAIN:</strong> <span class="success">' . Metasync::CA_API_DOMAIN . '</span></p>';
        } else {
            echo '<p class="error">❌ Metasync class not loaded</p>';
        }
        
        // Show correct tunnel endpoint
        echo '<p><strong>Sentry Tunnel Endpoint:</strong> <span class="info">https://wordpress.telemetry.staging.searchatlas.com/wp-json/sa/v1/sentry-tunnel</span></p>';
        
        if (defined('METASYNC_SENTRY_PROJECT_ID')) {
            echo '<p><strong>Proxy DSN Format:</strong> <span class="success">proxy://' . METASYNC_SENTRY_PROJECT_ID . '</span></p>';
        } else {
            echo '<p class="error">❌ Proxy DSN not configured</p>';
        }
        ?>
    </div>

    <div class="section">
        <h2>5. Sentry Connection Test</h2>
        <?php
        if (function_exists('metasync_sentry_test_connection')) {
            try {
                echo '<p class="info">Testing Sentry connection...</p>';
                $test_result = metasync_sentry_test_connection();
                
                if (is_array($test_result)) {
                    if (isset($test_result['success']) && $test_result['success']) {
                        echo '<p class="success">✅ Sentry Connection Test: SUCCESS</p>';
                    } else {
                        echo '<p class="error">❌ Sentry Connection Test: FAILED</p>';
                    }
                    echo '<pre>' . htmlspecialchars(json_encode($test_result, JSON_PRETTY_PRINT)) . '</pre>';
                } else {
                    echo '<p class="warning">⚠️ Unexpected test result format</p>';
                    echo '<pre>' . htmlspecialchars(var_export($test_result, true)) . '</pre>';
                }
            } catch (Exception $e) {
                echo '<p class="error">❌ Sentry Test Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
        } else {
            echo '<p class="error">❌ Sentry test function not available</p>';
        }
        ?>
    </div>

    <div class="section">
        <h2>6. Send Test Message</h2>
        <?php
        if (function_exists('metasync_sentry_capture_message') && function_exists('metasync_get_jwt_token')) {
            $jwt_token = metasync_get_jwt_token();
            if (!empty($jwt_token)) {
                try {
                    echo '<p class="info">Sending test message through proxy...</p>';
                    $message_result = metasync_sentry_capture_message(
                        'Test message from Sentry proxy web test - ' . date('Y-m-d H:i:s'),
                        'info',
                        [
                            'test_type' => 'web_browser_test',
                            'timestamp' => time(),
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                            'implementation' => 'jwt_proxy_web_test'
                        ]
                    );
                    
                    if ($message_result) {
                        echo '<p class="success">✅ Test Message Sent Successfully</p>';
                        echo '<p class="info">Check your backend logs at <code>' . (class_exists('Metasync') ? Metasync::CA_API_DOMAIN : 'https://ca.searchatlas.com') . '/api/sentry-proxy/</code> for the incoming request.</p>';
                    } else {
                        echo '<p class="error">❌ Test Message Failed</p>';
                    }
                    
                    if ($message_result !== true && $message_result !== false) {
                        echo '<pre>' . htmlspecialchars(var_export($message_result, true)) . '</pre>';
                    }
                } catch (Exception $e) {
                    echo '<p class="error">❌ Message Send Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
                }
            } else {
                echo '<p class="warning">⚠️ Cannot send test message - JWT token is empty</p>';
            }
        } else {
            echo '<p class="error">❌ Required functions not available for message test</p>';
        }
        ?>
    </div>

    <div class="section postman-section">
        <h2>🚀 POSTMAN TEST REQUEST</h2>
        <p class="info">Use this data to test your backend endpoint directly with Postman:</p>
        
        <?php
        // Generate test data for Postman
        if (function_exists('metasync_get_jwt_token')) {
            $jwt_token = metasync_get_jwt_token();
            
            if (!empty($jwt_token)) {
                // Create test Sentry data
                $test_data = [
                    'event_id' => str_replace('-', '', wp_generate_uuid4()),
                    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                    'level' => 'info',
                    'platform' => 'php',
                    'sdk' => [
                        'name' => 'metasync-postman-test',
                        'version' => '1.0.0'
                    ],
                    'server_name' => $_SERVER['HTTP_HOST'] ?? 'localhost',
                    'release' => defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0',
                    'environment' => defined('METASYNC_SENTRY_ENVIRONMENT') ? METASYNC_SENTRY_ENVIRONMENT : 'development',
                    'message' => [
                        'message' => 'Postman test message - ' . date('Y-m-d H:i:s')
                    ],
                    'tags' => [
                        'test_source' => 'postman',
                        'plugin_name' => 'metasync'
                    ],
                    'extra' => [
                        'test_type' => 'postman_direct_test',
                        'timestamp' => time(),
                        'source' => 'direct_postman_request'
                    ]
                ];
                
                // Create envelope
                $envelope_header = [
                    'event_id' => $test_data['event_id'],
                    'dsn' => defined('METASYNC_SENTRY_PROJECT_ID') ? 'proxy://' . METASYNC_SENTRY_PROJECT_ID : 'proxy://unknown',
                    'sdk' => $test_data['sdk'],
                    'sent_at' => gmdate('c')
                ];
                
                $item_header = [
                    'type' => 'event',
                    'content_type' => 'application/json'
                ];
                
                $envelope = json_encode($envelope_header) . "\n";
                $envelope .= json_encode($item_header) . "\n";
                $envelope .= json_encode($test_data) . "\n";
                
                echo '<h3>📍 Endpoint URL:</h3>';
                echo '<pre id="endpoint-url">https://wordpress.telemetry.staging.searchatlas.com/wp-json/sa/v1/sentry-tunnel</pre>';
                echo '<button class="copy-button" onclick="copyToClipboard(\'endpoint-url\')">Copy URL</button>';
                
                echo '<h3>📋 Request Method:</h3>';
                echo '<pre>POST</pre>';
                
                echo '<h3>🔑 Headers:</h3>';
                echo '<pre id="headers">Authorization: Bearer ' . $jwt_token . '
Content-Type: application/x-sentry-envelope
User-Agent: Postman MetaSync Test</pre>';
                echo '<button class="copy-button" onclick="copyToClipboard(\'headers\')">Copy Headers</button>';
                
                echo '<h3>📦 Request Body (Sentry Envelope Format):</h3>';
                echo '<pre id="request-body">' . htmlspecialchars($envelope) . '</pre>';
                echo '<button class="copy-button" onclick="copyToClipboard(\'request-body\')">Copy Body</button>';
                
                echo '<h3>🔧 Postman Setup Instructions:</h3>';
                echo '<ol>';
                echo '<li>Create a new POST request in Postman</li>';
                echo '<li>Set the URL to the endpoint above</li>';
                echo '<li>Go to Headers tab and add the headers above</li>';
                echo '<li>Go to Body tab, select "raw", and paste the envelope data</li>';
                echo '<li>Send the request</li>';
                echo '<li>Check for 200-299 response code for success</li>';
                echo '</ol>';
                
                echo '<h3>✅ Expected Success Response:</h3>';
                echo '<pre>HTTP 200 OK
Content-Type: application/json

{
    "success": true,
    "message": "Event received"
}</pre>';
                
            } else {
                echo '<p class="error">❌ Cannot generate Postman test - JWT token is empty</p>';
            }
        } else {
            echo '<p class="error">❌ Cannot generate Postman test - JWT function not available</p>';
        }
        ?>
    </div>

    <div class="section">
        <h2>7. Summary</h2>
        <?php
        $constants_ok = defined('METASYNC_SENTRY_PROJECT_ID');
        $functions_ok = function_exists('metasync_get_jwt_token') && function_exists('metasync_sentry_capture_message');
        $jwt_ok = function_exists('metasync_get_jwt_token') && !empty(metasync_get_jwt_token());
        $config_ok = class_exists('Metasync');
        
        echo '<ul>';
        echo '<li><strong>Constants:</strong> <span class="' . ($constants_ok ? 'success' : 'error') . '">' . ($constants_ok ? '✅ OK' : '❌ MISSING') . '</span></li>';
        echo '<li><strong>Functions:</strong> <span class="' . ($functions_ok ? 'success' : 'error') . '">' . ($functions_ok ? '✅ OK' : '❌ MISSING') . '</span></li>';
        echo '<li><strong>JWT Token:</strong> <span class="' . ($jwt_ok ? 'success' : 'error') . '">' . ($jwt_ok ? '✅ OK' : '❌ EMPTY') . '</span></li>';
        echo '<li><strong>Configuration:</strong> <span class="' . ($config_ok ? 'success' : 'error') . '">' . ($config_ok ? '✅ OK' : '❌ MISSING') . '</span></li>';
        echo '</ul>';
        
        if ($constants_ok && $functions_ok && $config_ok) {
            if ($jwt_ok) {
                echo '<p class="success"><strong>🎉 READY FOR PRODUCTION!</strong> All components are working correctly.</p>';
                echo '<p class="info">The Sentry telemetry system is now proxying through your backend with JWT authentication.</p>';
            } else {
                echo '<p class="warning"><strong>⚠️ ALMOST READY!</strong> Everything is configured but JWT token is empty.</p>';
                echo '<p class="info">This usually means the plugin needs proper API credentials configured.</p>';
            }
        } else {
            echo '<p class="error"><strong>❌ CONFIGURATION ISSUES</strong> Some components are missing.</p>';
        }
        ?>
    </div>

    <div class="section">
        <h2>8. Debug Logs</h2>
        <p class="info">Check your WordPress debug log for detailed Sentry connection information:</p>
        <pre><?php echo WP_CONTENT_DIR . '/debug.log'; ?></pre>
        <p class="info">Look for lines starting with:</p>
        <ul>
            <li><code>🔍 Sentry Debug:</code> - Detailed request/response info</li>
            <li><code>✅ Sentry Proxy:</code> - Success messages</li>
            <li><code>❌ Sentry Proxy:</code> - Error messages</li>
        </ul>
    </div>

    <p><small>Test completed at: <?php echo date('Y-m-d H:i:s'); ?></small></p>
</body>
</html>
