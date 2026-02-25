<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}


/**
 * API Key Change Monitor for MetaSync Plugin
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

/**
 * Centralized API Key Change Monitor
 *
 * This class monitors all API key changes and ensures the backend is notified
 * via Heartbeat API whenever the Plugin Auth Token or Search Atlas API Key changes.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/includes
 * @author     Engineering Team <support@searchatlas.com>
 */
class Metasync_API_Key_Monitor
{
    /**
     * Instance of this class
     */
    private static $instance = null;
    
    /**
     * Flag to prevent infinite loops during monitoring
     */
    private $monitoring_active = false;
    
    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize the monitor
     */
    public function __construct()
    {
        // Only initialize once
        if (self::$instance !== null) {
            return;
        }
        
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks()
    {
        // Monitor option changes (catches direct database updates)
        add_action('update_option_metasync_options', array($this, 'on_option_update'), 10, 2);
        
        // Monitor option additions (for fresh installs)
        add_action('add_option_metasync_options', array($this, 'on_option_add'), 10, 2);
        
        // Provide centralized heartbeat trigger method
        add_action('metasync_api_key_changed', array($this, 'trigger_heartbeat_for_key_change'), 10, 3);
    }
    
    /**
     * Handle option updates
     */
    public function on_option_update($old_value, $new_value)
    {
        $this->analyze_and_respond_to_changes($old_value, $new_value, 'option_update');
    }
    
    /**
     * Handle option additions (fresh installs)
     */
    public function on_option_add($option_name, $new_value)
    {
        // For new installations, treat as change from empty state
        $this->analyze_and_respond_to_changes(array(), $new_value, 'option_add');
    }
    
    /**
     * Analyze API key changes and trigger appropriate responses
     */
    private function analyze_and_respond_to_changes($old_value, $new_value, $trigger_context = 'unknown')
    {
        // Prevent infinite loops
        if ($this->monitoring_active) {
            return;
        }
        
        try {
            $this->monitoring_active = true;
            
            // Normalize values
            $old_value = is_array($old_value) ? $old_value : array();
            $new_value = is_array($new_value) ? $new_value : array();
            
            // Extract API keys
            $changes = $this->detect_api_key_changes($old_value, $new_value);
            
            if (!empty($changes['changes_detected'])) {
                $this->log_changes($changes, $trigger_context);
                $this->trigger_heartbeat_response($changes, $trigger_context);
            }
            
        } catch (Exception $e) {
            error_log('MetaSync API Key Monitor Error: ' . $e->getMessage());
        } finally {
            $this->monitoring_active = false;
        }
    }
    
    /**
     * Detect API key changes between old and new values
     */
    private function detect_api_key_changes($old_value, $new_value)
    {
        // Extract values
        $old_plugin_auth_token = $old_value['general']['apikey'] ?? '';
        $new_plugin_auth_token = $new_value['general']['apikey'] ?? '';
        $old_searchatlas_api_key = $old_value['general']['searchatlas_api_key'] ?? '';
        $new_searchatlas_api_key = $new_value['general']['searchatlas_api_key'] ?? '';
        
        // Analyze changes
        $plugin_token_changed = $old_plugin_auth_token !== $new_plugin_auth_token;
        $searchatlas_key_changed = $old_searchatlas_api_key !== $new_searchatlas_api_key;
        
        return array(
            'changes_detected' => $plugin_token_changed || $searchatlas_key_changed,
            'plugin_auth_token' => array(
                'changed' => $plugin_token_changed,
                'old' => $old_plugin_auth_token,
                'new' => $new_plugin_auth_token,
                'action' => $this->determine_change_action($old_plugin_auth_token, $new_plugin_auth_token)
            ),
            'searchatlas_api_key' => array(
                'changed' => $searchatlas_key_changed,
                'old' => $old_searchatlas_api_key,
                'new' => $new_searchatlas_api_key,
                'action' => $this->determine_change_action($old_searchatlas_api_key, $new_searchatlas_api_key)
            )
        );
    }
    
    /**
     * Determine the type of change (added, removed, changed)
     */
    private function determine_change_action($old_value, $new_value)
    {
        if (empty($old_value) && !empty($new_value)) {
            return 'added';
        } elseif (!empty($old_value) && empty($new_value)) {
            return 'removed';
        } elseif (!empty($old_value) && !empty($new_value) && $old_value !== $new_value) {
            return 'changed';
        }
        return 'none';
    }
    
    /**
     * Log API key changes
     */
    private function log_changes($changes, $context)
    {
        $log_messages = array();
        
        if ($changes['plugin_auth_token']['changed']) {
            $token_change = $changes['plugin_auth_token'];
            $log_messages[] = sprintf('Plugin Auth Token %s: %s → %s', 
                $token_change['action'],
                empty($token_change['old']) ? '(empty)' : substr($token_change['old'], 0, 8) . '...',
                empty($token_change['new']) ? '(empty)' : substr($token_change['new'], 0, 8) . '...'
            );
        }
        
        if ($changes['searchatlas_api_key']['changed']) {
            $key_change = $changes['searchatlas_api_key'];
            $log_messages[] = sprintf('Search Atlas API Key %s: %s → %s',
                $key_change['action'],
                empty($key_change['old']) ? '(empty)' : substr($key_change['old'], 0, 8) . '...',
                empty($key_change['new']) ? '(empty)' : substr($key_change['new'], 0, 8) . '...'
            );
        }
        
        if (!empty($log_messages)) {
            // Use centralized logging for consistent formatting
            $details = array(
                'context' => $context,
                'changes' => implode(' | ', $log_messages)
            );
            
            if (class_exists('Metasync')) {
                Metasync::log_api_key_event('API key change detected', 'multiple', $details, 'info');
            } else {
                // Fallback to basic error_log if main class not available
                error_log('MetaSync API Key Monitor [' . $context . ']: ' . implode(' | ', $log_messages));
            }
        }
    }
    
    /**
     * Trigger heartbeat response based on changes
     */
    private function trigger_heartbeat_response($changes, $context)
    {
        // Determine the most appropriate heartbeat trigger message
        $heartbeat_context = $this->build_heartbeat_context($changes, $context);
        
        // Fire the centralized action for heartbeat triggering (always fire this for other plugins to hook into)
        do_action('metasync_api_key_changed', $changes, $context, $heartbeat_context);
        
        // Only trigger immediate heartbeat if Search Atlas API key is present
        // This follows the business rule that heartbeat API should only be called when Search Atlas API key is set
        if ($this->should_trigger_heartbeat($changes)) {
            do_action('metasync_heartbeat_state_key_pending'); // PR3: burst mode
            do_action('metasync_trigger_immediate_heartbeat', $heartbeat_context);
        } else {
            $this->log_heartbeat_skip_reason($changes, $heartbeat_context);
        }
    }
    
    /**
     * Determine if heartbeat should be triggered based on API key availability
     * Heartbeat API should only be called when Search Atlas API Key is present
     */
    private function should_trigger_heartbeat($changes)
    {
        // Get current Search Atlas API key
        $current_options = is_array(Metasync::get_option()) ? Metasync::get_option() : array();
        $current_searchatlas_key = $current_options['general']['searchatlas_api_key'] ?? '';
        
        // Always trigger heartbeat if Search Atlas API key is being changed
        if ($changes['searchatlas_api_key']['changed']) {
            // If the new Search Atlas API key is not empty, trigger heartbeat
            if (!empty($changes['searchatlas_api_key']['new'])) {
                return true;
            }
            // If Search Atlas API key was removed, still trigger to update backend status
            if (!empty($changes['searchatlas_api_key']['old'])) {
                return true;
            }
        }
        
        // For Plugin Auth Token changes, only trigger if Search Atlas API key exists
        if ($changes['plugin_auth_token']['changed']) {
            if (!empty($current_searchatlas_key)) {
                return true;
            }
            // Plugin Auth Token changed but no Search Atlas API key present
            return false;
        }
        
        // No changes that require heartbeat
        return false;
    }
    
    /**
     * Log why heartbeat trigger was skipped
     */
    private function log_heartbeat_skip_reason($changes, $context)
    {
        $skip_reasons = array();
        
        if ($changes['plugin_auth_token']['changed']) {
            $skip_reasons[] = 'Plugin Auth Token changed but Search Atlas API key not configured';
        }
        
        if ($changes['searchatlas_api_key']['changed'] && empty($changes['searchatlas_api_key']['new'])) {
            $skip_reasons[] = 'Search Atlas API key was removed';
        }
        
        if (!empty($skip_reasons)) {
            error_log('MetaSync API Key Monitor: Skipping heartbeat trigger - ' . implode(' | ', $skip_reasons) . ' - Context: ' . $context);
        }
    }

    /**
     * Build descriptive context for heartbeat trigger
     */
    private function build_heartbeat_context($changes, $context)
    {
        $context_parts = array('API Key Monitor');
        
        if ($changes['plugin_auth_token']['changed']) {
            $context_parts[] = 'Plugin Auth Token ' . $changes['plugin_auth_token']['action'];
        }
        
        if ($changes['searchatlas_api_key']['changed']) {
            $context_parts[] = 'Search Atlas API Key ' . $changes['searchatlas_api_key']['action'];
        }
        
        $context_parts[] = '(' . $context . ')';
        
        return implode(' - ', $context_parts);
    }
    
    /**
     * Handle centralized heartbeat trigger action
     */
    public function trigger_heartbeat_for_key_change($changes, $context, $heartbeat_context)
    {
        // This method can be extended to handle specific logic based on the type of change
        // For now, it serves as a central point for other plugins/components to hook into
        
        // Log the centralized trigger
        
    }
    
    /**
     * Manual API key change notification (for programmatic triggers)
     */
    public static function notify_api_key_change($key_type, $old_value, $new_value, $context = 'manual')
    {
        $instance = self::get_instance();
        
        // Build simulated change array
        if ($key_type === 'plugin_auth_token') {
            $changes = array(
                'changes_detected' => true,
                'plugin_auth_token' => array(
                    'changed' => true,
                    'old' => $old_value,
                    'new' => $new_value,
                    'action' => $instance->determine_change_action($old_value, $new_value)
                ),
                'searchatlas_api_key' => array('changed' => false)
            );
        } elseif ($key_type === 'searchatlas_api_key') {
            $changes = array(
                'changes_detected' => true,
                'plugin_auth_token' => array('changed' => false),
                'searchatlas_api_key' => array(
                    'changed' => true,
                    'old' => $old_value,
                    'new' => $new_value,
                    'action' => $instance->determine_change_action($old_value, $new_value)
                )
            );
        } else {
            return false;
        }
        
        $instance->log_changes($changes, $context);
        $instance->trigger_heartbeat_response($changes, $context);
        
        return true;
    }
    
    /**
     * Get monitoring status for debugging
     */
    public function get_monitoring_status()
    {
        return array(
            'class_loaded' => true,
            'hooks_active' => has_action('update_option_metasync_options', array($this, 'on_option_update')),
            'monitoring_active' => $this->monitoring_active,
            'instance_initialized' => self::$instance !== null
        );
    }
}
