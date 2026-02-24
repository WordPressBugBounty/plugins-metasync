<?php
/**
 * MCP Tool Base Class
 *
 * Abstract base class for all MCP tools. Provides structure for
 * tool registration, schema validation, and execution.
 *
 * @package    MetaSync
 * @subpackage MCP_Server
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class MCP_Tool_Base {

    /**
     * Get tool name
     *
     * Must be unique across all tools
     *
     * @return string
     */
    abstract public function get_name();

    /**
     * Get tool description
     *
     * @return string
     */
    abstract public function get_description();

    /**
     * Get input schema
     *
     * JSON Schema format describing expected parameters
     *
     * @return array
     */
    abstract public function get_input_schema();

    /**
     * Execute the tool
     *
     * @param array $params Input parameters
     * @return array Result data
     * @throws InvalidArgumentException If parameters are invalid
     * @throws Exception If execution fails
     */
    abstract public function execute($params);

    /**
     * Get full tool definition (for tools/list)
     *
     * @return array
     */
    public function get_definition() {
        return [
            'name' => $this->get_name(),
            'description' => $this->get_description(),
            'inputSchema' => $this->get_input_schema()
        ];
    }

    /**
     * Validate parameters against schema
     *
     * @param array $params Parameters to validate
     * @return bool
     * @throws InvalidArgumentException If validation fails
     */
    protected function validate_params($params) {
        $schema = $this->get_input_schema();

        // Check required fields
        if (isset($schema['required'])) {
            foreach ($schema['required'] as $required_field) {
                if (!isset($params[$required_field])) {
                    throw new InvalidArgumentException("Missing required parameter: {$required_field}");
                }
            }
        }

        // Validate types
        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $field => $field_schema) {
                if (!isset($params[$field])) {
                    continue;
                }

                $value = $params[$field];
                $expected_type = isset($field_schema['type']) ? $field_schema['type'] : null;

                if ($expected_type && !$this->validate_type($value, $expected_type)) {
                    throw new InvalidArgumentException("Invalid type for parameter '{$field}': expected {$expected_type}");
                }

                // Validate enum
                if (isset($field_schema['enum']) && !in_array($value, $field_schema['enum'], true)) {
                    $allowed = implode(', ', $field_schema['enum']);
                    throw new InvalidArgumentException("Invalid value for parameter '{$field}': must be one of [{$allowed}]");
                }

                // Validate min/max for integers
                if ($expected_type === 'integer') {
                    if (isset($field_schema['minimum']) && $value < $field_schema['minimum']) {
                        throw new InvalidArgumentException("Parameter '{$field}' must be >= {$field_schema['minimum']}");
                    }
                    if (isset($field_schema['maximum']) && $value > $field_schema['maximum']) {
                        throw new InvalidArgumentException("Parameter '{$field}' must be <= {$field_schema['maximum']}");
                    }
                }
            }
        }

        return true;
    }

    /**
     * Validate value type
     *
     * @param mixed  $value Value to validate
     * @param string $type  Expected type
     * @return bool
     */
    private function validate_type($value, $type) {
        switch ($type) {
            case 'string':
                return is_string($value);
            case 'integer':
                return is_int($value);
            case 'number':
                return is_numeric($value);
            case 'boolean':
                return is_bool($value);
            case 'array':
                return is_array($value);
            case 'object':
                return is_array($value) || is_object($value);
            default:
                return true;
        }
    }

    /**
     * Sanitize string parameter
     *
     * @param string $value Value to sanitize
     * @return string
     */
    protected function sanitize_string($value) {
        return sanitize_text_field($value);
    }

    /**
     * Sanitize integer parameter
     *
     * @param mixed $value Value to sanitize
     * @return int
     */
    protected function sanitize_integer($value) {
        return intval($value);
    }

    /**
     * Sanitize URL parameter
     *
     * @param string $value Value to sanitize
     * @return string
     */
    protected function sanitize_url($value) {
        return esc_url_raw($value);
    }

    /**
     * Sanitize textarea/content parameter
     *
     * @param string $value Value to sanitize
     * @return string
     */
    protected function sanitize_textarea($value) {
        return sanitize_textarea_field($value);
    }

    /**
     * Check if user has required capability
     *
     * @param string $capability Required capability (default: manage_options)
     * @return bool
     * @throws Exception If user lacks capability
     */
    protected function require_capability($capability = 'manage_options') {
        if (!current_user_can($capability)) {
            throw new Exception('Insufficient permissions');
        }
        return true;
    }

    /**
     * Format success result
     *
     * @param mixed  $data    Result data
     * @param string $message Optional message
     * @return array
     */
    protected function success($data, $message = null) {
        $result = [
            'success' => true,
            'data' => $data
        ];

        if ($message) {
            $result['message'] = $message;
        }

        return $result;
    }

    /**
     * Format error result
     *
     * @param string $message Error message
     * @param string $code    Optional error code
     * @return array
     * @throws Exception
     */
    protected function error($message, $code = 'error') {
        throw new Exception($message);
    }
}
