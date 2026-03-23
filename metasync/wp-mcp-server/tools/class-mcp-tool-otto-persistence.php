<?php
/**
 * MCP Tools: OTTO Persistence Settings
 *
 * Provides tools for reading and updating the 12 OTTO persistence flags
 * that control whether OTTO-generated SEO data is also written to native
 * WordPress fields (ensuring data survives plugin removal).
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get OTTO Persistence Settings Tool
 */
class MCP_Tool_Get_Otto_Persistence_Settings extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_otto_persistence_settings';
    }

    public function get_description() {
        return 'Get OTTO persistence settings — which of the 12 OTTO data types are configured to save to native WordPress fields';
    }

    public function get_input_schema() {
        return [
            'type'       => 'object',
            'properties' => new stdClass(),
            'required'   => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        if (!class_exists('Metasync_Otto_Persistence_Settings')) {
            throw new Exception('Metasync_Otto_Persistence_Settings class is not available. Ensure the OTTO persistence module is loaded.');
        }

        $settings = Metasync_Otto_Persistence_Settings::get_settings();

        return $this->success([
            'settings'     => $settings,
            'enabled_count' => count(array_filter($settings)),
            'total_flags'  => count($settings),
        ], 'OTTO persistence settings retrieved successfully');
    }
}

/**
 * Update OTTO Persistence Settings Tool
 */
class MCP_Tool_Update_Otto_Persistence_Settings extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_otto_persistence_settings';
    }

    public function get_description() {
        return 'Enable or disable persistence for one or all OTTO data types. When enabled, OTTO writes data to native WordPress fields so it survives plugin removal.';
    }

    public function get_input_schema() {
        return [
            'type'       => 'object',
            'properties' => [
                'settings' => [
                    'type'        => 'object',
                    'description' => 'Key-value pairs of persistence flags to update. Keys: meta_title, meta_description, meta_keywords, og_title, og_description, twitter_title, twitter_description, canonical_url, image_alt_text, link_corrections, heading_changes, structured_data. Values: true or false.',
                ],
                'setting_key' => [
                    'type'        => 'string',
                    'description' => 'Single persistence flag key to update (alternative to the settings object)',
                    'enum'        => [
                        'meta_title',
                        'meta_description',
                        'meta_keywords',
                        'og_title',
                        'og_description',
                        'twitter_title',
                        'twitter_description',
                        'canonical_url',
                        'image_alt_text',
                        'link_corrections',
                        'heading_changes',
                        'structured_data',
                    ],
                ],
                'setting_value' => [
                    'type'        => 'boolean',
                    'description' => 'Boolean value for the single setting_key update',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        if (!class_exists('Metasync_Otto_Persistence_Settings')) {
            throw new Exception('Metasync_Otto_Persistence_Settings class is not available. Ensure the OTTO persistence module is loaded.');
        }

        $valid_keys = [
            'meta_title', 'meta_description', 'meta_keywords',
            'og_title', 'og_description',
            'twitter_title', 'twitter_description',
            'canonical_url', 'image_alt_text',
            'link_corrections', 'heading_changes', 'structured_data',
        ];

        $updated_keys = [];
        $current_settings = Metasync_Otto_Persistence_Settings::get_settings();

        // Handle bulk settings object
        if (!empty($params['settings']) && is_array($params['settings'])) {
            foreach ($params['settings'] as $key => $value) {
                if (!in_array($key, $valid_keys, true)) {
                    continue;
                }
                if (!is_bool($value) && !is_string($value) && !is_numeric($value)) {
                    throw new Exception(
                        "Invalid value type for setting '{$key}': expected boolean, got " . gettype($value)
                    );
                }
                $bool_value = $this->coerce_bool($value);
                Metasync_Otto_Persistence_Settings::set_setting($key, $bool_value);
                $updated_keys[] = $key;
            }
        }

        // Handle single key update
        if (!empty($params['setting_key'])) {
            $key = sanitize_key($params['setting_key']);
            if (!in_array($key, $valid_keys, true)) {
                throw new Exception("Invalid setting_key '{$key}'. Must be one of: " . implode(', ', $valid_keys));
            }
            if (!array_key_exists('setting_value', $params)) {
                throw new Exception("setting_value is required when setting_key is provided");
            }
            $bool_value = $this->coerce_bool($params['setting_value']);
            Metasync_Otto_Persistence_Settings::set_setting($key, $bool_value);
            $updated_keys[] = $key;
        }

        if (empty($updated_keys)) {
            throw new Exception("No valid settings provided. Supply a 'settings' object or a 'setting_key'/'setting_value' pair.");
        }

        $updated_settings = Metasync_Otto_Persistence_Settings::get_settings();

        return $this->success([
            'settings'      => $updated_settings,
            'updated_keys'  => $updated_keys,
            'enabled_count' => count(array_filter($updated_settings)),
            'total_flags'   => count($updated_settings),
        ], sprintf('Updated %d persistence flag(s): %s', count($updated_keys), implode(', ', $updated_keys)));
    }

    /**
     * Coerce a value to boolean with flexible input handling
     */
    private function coerce_bool($value) {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
        }
        if (is_numeric($value)) {
            return (bool) $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
