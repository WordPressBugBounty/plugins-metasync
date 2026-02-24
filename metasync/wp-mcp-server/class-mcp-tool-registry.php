<?php
/**
 * MCP Tool Registry
 *
 * Manages registration, storage, and retrieval of MCP tools.
 * Provides the central registry for all available tools.
 *
 * @package    MetaSync
 * @subpackage MCP_Server
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCP_Tool_Registry {

    /**
     * Registered tools
     *
     * @var array Array of MCP_Tool_Base instances
     */
    private $tools = [];

    /**
     * Cache key for tools list
     */
    const CACHE_KEY = 'metasync_mcp_tools_list';

    /**
     * Cache expiration (5 minutes)
     */
    const CACHE_EXPIRATION = 300;

    /**
     * Singleton instance
     *
     * @var MCP_Tool_Registry
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return MCP_Tool_Registry
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Private constructor for singleton
    }

    /**
     * Register a tool
     *
     * @param MCP_Tool_Base $tool Tool instance
     * @return bool
     * @throws InvalidArgumentException If tool is invalid
     */
    public function register_tool(MCP_Tool_Base $tool) {
        $name = $tool->get_name();

        // Validate tool name
        if (empty($name)) {
            throw new InvalidArgumentException('Tool name cannot be empty');
        }

        // Check for duplicate
        if (isset($this->tools[$name])) {
            throw new InvalidArgumentException("Tool '{$name}' is already registered");
        }

        // Validate tool has required methods
        if (!method_exists($tool, 'execute')) {
            throw new InvalidArgumentException("Tool '{$name}' must implement execute() method");
        }

        // Store tool
        $this->tools[$name] = $tool;

        // Clear cache
        delete_transient(self::CACHE_KEY);

        // Fire action
        do_action('metasync_mcp_tool_registered', $name, $tool);

        return true;
    }

    /**
     * Unregister a tool
     *
     * @param string $name Tool name
     * @return bool
     */
    public function unregister_tool($name) {
        if (!isset($this->tools[$name])) {
            return false;
        }

        unset($this->tools[$name]);

        // Clear cache
        delete_transient(self::CACHE_KEY);

        // Fire action
        do_action('metasync_mcp_tool_unregistered', $name);

        return true;
    }

    /**
     * Get a specific tool
     *
     * @param string $name Tool name
     * @return MCP_Tool_Base|null
     */
    public function get_tool($name) {
        return isset($this->tools[$name]) ? $this->tools[$name] : null;
    }

    /**
     * Get all registered tools
     *
     * @return array Array of MCP_Tool_Base instances
     */
    public function get_all_tools() {
        return $this->tools;
    }

    /**
     * Get tools list (for tools/list MCP method)
     *
     * @param bool $use_cache Whether to use cached list
     * @return array
     */
    public function get_tools_list($use_cache = true) {
        // Try cache first
        if ($use_cache) {
            $cached = get_transient(self::CACHE_KEY);
            if ($cached !== false) {
                return $cached;
            }
        }

        $tools_list = [];

        foreach ($this->tools as $name => $tool) {
            $tools_list[] = $tool->get_definition();
        }

        // Apply filter to allow modifications
        $tools_list = apply_filters('metasync_mcp_tools_list', $tools_list);

        // Cache the result
        set_transient(self::CACHE_KEY, $tools_list, self::CACHE_EXPIRATION);

        return $tools_list;
    }

    /**
     * Execute a tool
     *
     * @param string $name   Tool name
     * @param array  $params Tool parameters
     * @return array Result
     * @throws Exception If tool not found or execution fails
     */
    public function execute_tool($name, $params = []) {
        $tool = $this->get_tool($name);

        if (!$tool) {
            throw new Exception(sprintf("Tool not found: %s", esc_html($name)));
        }

        // Execute tool (tools validate their own params internally)
        $start_time = microtime(true);
        $result = $tool->execute($params);
        $execution_time = microtime(true) - $start_time;

        // Log execution
        $this->log_execution($name, $params, $result, $execution_time);

        // Fire action
        do_action('metasync_mcp_tool_executed', $name, $params, $result, $execution_time);

        return $result;
    }

    /**
     * Check if tool exists
     *
     * @param string $name Tool name
     * @return bool
     */
    public function has_tool($name) {
        return isset($this->tools[$name]);
    }

    /**
     * Get tool count
     *
     * @return int
     */
    public function get_tool_count() {
        return count($this->tools);
    }

    /**
     * Clear all tools
     *
     * @return void
     */
    public function clear_all() {
        $this->tools = [];
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Log tool execution
     *
     * @param string $name           Tool name
     * @param array  $params         Parameters
     * @param mixed  $result         Result
     * @param float  $execution_time Execution time in seconds
     */
    private function log_execution($name, $params, $result, $execution_time) {
        // Only log in debug mode or if execution took > 1 second
        if (!WP_DEBUG && $execution_time < 1.0) {
            return;
        }

        $log_message = sprintf(
            'MCP Tool Executed: %s (%.3fs)',
            $name,
            $execution_time
        );

        error_log($log_message);
    }
}
