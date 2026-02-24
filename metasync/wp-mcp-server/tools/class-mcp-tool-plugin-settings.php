<?php
/**
 * MCP Tool: Plugin Settings Management
 *
 * Provides tools for viewing and managing WordPress plugin settings.
 * This allows AI agents to read and update all MetaSync plugin configuration.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get Plugin Settings Tool
 */
class MCP_Tool_Get_Plugin_Settings extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_plugin_settings';
    }

    public function get_description() {
        return 'Get all plugin settings or settings for a specific section. Returns the complete plugin configuration including features, SEO controls, API keys, and more.';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'section' => [
                    'type' => 'string',
                    'description' => 'Optional: specific settings section to retrieve (e.g., "general", "whitelabel", "seo"). If omitted, returns all settings.',
                    'enum' => [
                        'general',
                        'whitelabel',
                        'seo',
                        'social',
                        'advanced',
                        'features',
                        'api',
                        'all'
                    ]
                ],
                'keys' => [
                    'type' => 'array',
                    'description' => 'Optional: specific setting keys to retrieve. If provided, only these keys will be returned.',
                    'items' => [
                        'type' => 'string'
                    ]
                ]
            ],
            'required' => []
        ];
    }

    public function execute($params) {
        // Validate and check permissions
        $this->validate_params($params);
        $this->require_capability('manage_options');

        // Get all plugin options
        $all_options = get_option(Metasync::option_name, []);

        // If specific keys requested
        if (!empty($params['keys']) && is_array($params['keys'])) {
            $result = [];
            foreach ($params['keys'] as $key) {
                $key = $this->sanitize_string($key);
                if (isset($all_options[$key])) {
                    $result[$key] = $all_options[$key];
                }
            }
            return $this->success([
                'settings' => $result,
                'count' => count($result)
            ]);
        }

        // If section specified
        if (!empty($params['section']) && $params['section'] !== 'all') {
            $section = $this->sanitize_string($params['section']);

            // Map section names to option keys
            $section_keys = $this->get_section_keys($section);
            $result = [];

            foreach ($section_keys as $key) {
                if (isset($all_options[$key])) {
                    $result[$key] = $all_options[$key];
                }
            }

            return $this->success([
                'section' => $section,
                'settings' => $result,
                'count' => count($result)
            ]);
        }

        // Return all settings
        return $this->success([
            'settings' => $all_options,
            'count' => count($all_options),
            'available_sections' => [
                'general' => 'API keys, integration settings',
                'whitelabel' => 'Branding and white-label configuration',
                'seo' => 'SEO controls and indexation settings',
                'social' => 'Social media and OpenGraph settings',
                'advanced' => 'Advanced plugin features',
                'features' => 'Feature toggles and visibility',
                'api' => 'API configuration and tokens'
            ]
        ]);
    }

    /**
     * Get setting keys for a specific section
     */
    private function get_section_keys($section) {
        $section_map = [
            'general' => [
                'searchatlas_api_key',
                'apikey',
                'permalink_structure',
                'hide_dashboard_framework',
                'show_admin_bar_status',
                'enable_auto_updates'
            ],
            'whitelabel' => [
                'white_label_plugin_name',
                'whitelabel_otto_name',
                'whitelabel_logo_url',
                'whitelabel_domain_url',
                'white_label_plugin_description',
                'white_label_plugin_author',
                'white_label_plugin_author_uri',
                'white_label_plugin_uri',
                'white_label_plugin_menu_slug',
                'white_label_plugin_menu_icon',
                'whitelabel_settings_password'
            ],
            'seo' => [
                'index_date_archives',
                'index_tag_archives',
                'index_author_archives',
                'index_format_archives',
                'index_category_archives',
                'override_robots_tags',
                'enable_googleinstantindex',
                'google_index_api_config'
            ],
            'social' => [
                'otto_pixel_uuid',
                'otto_disable_on_loggedin',
                'otto_disable_preview_button',
                'periodic_clear_ottopage_cache',
                'periodic_clear_ottopost_cache',
                'periodic_clear_otto_cache'
            ],
            'advanced' => [
                'disable_common_robots_metabox',
                'disable_advance_robots_metabox',
                'disable_redirection_metabox',
                'disable_canonical_metabox',
                'disable_social_opengraph_metabox'
            ],
            'features' => [
                'enabled_elementor_plugin_css',
                'enabled_elementor_plugin_css_color',
                'import_external_data',
                'content_genius_sync_roles'
            ],
            'api' => [
                'searchatlas_api_key',
                'apikey',
                'google_index_api_config'
            ]
        ];

        return $section_map[$section] ?? [];
    }
}

/**
 * Update Plugin Settings Tool
 */
class MCP_Tool_Update_Plugin_Settings extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_plugin_settings';
    }

    public function get_description() {
        return 'Update one or more plugin settings. Allows modifying plugin configuration including features, SEO controls, API keys, whitelabel settings, and more.';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'settings' => [
                    'type' => 'object',
                    'description' => 'Key-value pairs of settings to update. Each key is a setting name and value is the new value.',
                    'additionalProperties' => true
                ],
                'merge' => [
                    'type' => 'boolean',
                    'description' => 'If true (default), merges with existing settings. If false, replaces all settings with provided values.',
                    'default' => true
                ]
            ],
            'required' => ['settings']
        ];
    }

    public function execute($params) {
        // Validate and check permissions
        $this->validate_params($params);
        $this->require_capability('manage_options');

        if (empty($params['settings']) || !is_array($params['settings'])) {
            throw new Exception('Settings must be a non-empty object/array');
        }

        $merge = isset($params['merge']) ? (bool)$params['merge'] : true;

        // Get current options
        $current_options = get_option(Metasync::option_name, []);

        if ($merge) {
            // Merge with existing settings
            $new_options = array_merge($current_options, $params['settings']);
        } else {
            // Replace all settings
            $new_options = $params['settings'];
        }

        // Sanitize sensitive fields
        $new_options = $this->sanitize_settings($new_options);

        // Update the option
        $updated = update_option(Metasync::option_name, $new_options);

        if ($updated === false && $current_options !== $new_options) {
            throw new Exception('Failed to update plugin settings');
        }

        // Clear any relevant caches
        wp_cache_delete(Metasync::option_name, 'options');

        return $this->success([
            'message' => 'Plugin settings updated successfully',
            'updated_keys' => array_keys($params['settings']),
            'merge_mode' => $merge,
            'total_settings' => count($new_options)
        ]);
    }

    /**
     * Sanitize settings before saving
     */
    private function sanitize_settings($settings) {
        $sanitized = [];

        foreach ($settings as $key => $value) {
            $key = sanitize_key($key);

            // Handle different types of values
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize_array($value);
            } elseif (is_bool($value)) {
                $sanitized[$key] = (bool)$value;
            } elseif (is_numeric($value)) {
                $sanitized[$key] = $value;
            } elseif ($this->is_sensitive_field($key)) {
                // Don't sanitize sensitive fields too aggressively
                $sanitized[$key] = $value;
            } elseif ($this->is_url_field($key)) {
                $sanitized[$key] = esc_url_raw($value);
            } else {
                $sanitized[$key] = sanitize_text_field($value);
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize array values recursively
     */
    private function sanitize_array($array) {
        $sanitized = [];
        foreach ($array as $key => $value) {
            $key = sanitize_key($key);
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize_array($value);
            } else {
                $sanitized[$key] = sanitize_text_field($value);
            }
        }
        return $sanitized;
    }

    /**
     * Check if field is sensitive (API keys, tokens, passwords)
     */
    private function is_sensitive_field($key) {
        $sensitive_patterns = ['api_key', 'apikey', 'token', 'password', 'secret'];
        foreach ($sensitive_patterns as $pattern) {
            if (stripos($key, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if field should be a URL
     */
    private function is_url_field($key) {
        $url_patterns = ['url', 'uri', 'domain', 'logo', 'link'];
        foreach ($url_patterns as $pattern) {
            if (stripos($key, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
}

/**
 * List Available Settings Tool
 */
class MCP_Tool_List_Plugin_Settings_Schema extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_list_plugin_settings_schema';
    }

    public function get_description() {
        return 'Get a schema of all available plugin settings with descriptions and expected types. Useful for understanding what settings can be configured.';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => []
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $schema = [
            'general' => [
                'description' => 'General plugin configuration',
                'settings' => [
                    'searchatlas_api_key' => [
                        'type' => 'string',
                        'description' => 'Search Atlas API key for integration',
                        'sensitive' => true
                    ],
                    'apikey' => [
                        'type' => 'string',
                        'description' => 'Plugin authentication token',
                        'sensitive' => true
                    ],
                    'hide_dashboard_framework' => [
                        'type' => 'boolean',
                        'description' => 'Hide the dashboard framework'
                    ],
                    'show_admin_bar_status' => [
                        'type' => 'boolean',
                        'description' => 'Show plugin status in admin bar'
                    ],
                    'enable_auto_updates' => [
                        'type' => 'boolean',
                        'description' => 'Enable automatic plugin updates'
                    ]
                ]
            ],
            'whitelabel' => [
                'description' => 'White-label branding configuration',
                'settings' => [
                    'white_label_plugin_name' => [
                        'type' => 'string',
                        'description' => 'Custom plugin name'
                    ],
                    'whitelabel_otto_name' => [
                        'type' => 'string',
                        'description' => 'Custom Otto feature name'
                    ],
                    'whitelabel_logo_url' => [
                        'type' => 'string',
                        'description' => 'URL to custom logo image'
                    ],
                    'whitelabel_domain_url' => [
                        'type' => 'string',
                        'description' => 'Custom dashboard domain URL'
                    ],
                    'white_label_plugin_description' => [
                        'type' => 'string',
                        'description' => 'Custom plugin description'
                    ],
                    'white_label_plugin_author' => [
                        'type' => 'string',
                        'description' => 'Custom author name'
                    ],
                    'white_label_plugin_author_uri' => [
                        'type' => 'string',
                        'description' => 'Custom author URI'
                    ],
                    'white_label_plugin_uri' => [
                        'type' => 'string',
                        'description' => 'Custom plugin URI'
                    ]
                ]
            ],
            'seo' => [
                'description' => 'SEO controls and indexation settings',
                'settings' => [
                    'index_date_archives' => [
                        'type' => 'boolean',
                        'description' => 'Disallow date archives from indexation'
                    ],
                    'index_tag_archives' => [
                        'type' => 'boolean',
                        'description' => 'Disallow tag archives from indexation'
                    ],
                    'index_author_archives' => [
                        'type' => 'boolean',
                        'description' => 'Disallow author archives from indexation'
                    ],
                    'index_format_archives' => [
                        'type' => 'boolean',
                        'description' => 'Disallow format archives from indexation'
                    ],
                    'index_category_archives' => [
                        'type' => 'boolean',
                        'description' => 'Disallow category archives from indexation'
                    ],
                    'override_robots_tags' => [
                        'type' => 'boolean',
                        'description' => 'Override robots tags from other plugins'
                    ],
                    'enable_googleinstantindex' => [
                        'type' => 'boolean',
                        'description' => 'Enable Google Instant Indexing'
                    ]
                ]
            ],
            'social' => [
                'description' => 'Social media and Otto settings',
                'settings' => [
                    'otto_pixel_uuid' => [
                        'type' => 'string',
                        'description' => 'Otto Pixel UUID for tracking'
                    ],
                    'otto_disable_on_loggedin' => [
                        'type' => 'boolean',
                        'description' => 'Disable Otto for logged-in users'
                    ],
                    'otto_disable_preview_button' => [
                        'type' => 'boolean',
                        'description' => 'Disable Otto frontend toolbar'
                    ]
                ]
            ],
            'advanced' => [
                'description' => 'Advanced meta box visibility controls',
                'settings' => [
                    'disable_common_robots_metabox' => [
                        'type' => 'boolean',
                        'description' => 'Disable common robots meta box in post editor'
                    ],
                    'disable_advance_robots_metabox' => [
                        'type' => 'boolean',
                        'description' => 'Disable advanced robots meta box in post editor'
                    ],
                    'disable_redirection_metabox' => [
                        'type' => 'boolean',
                        'description' => 'Disable redirection meta box in post editor'
                    ],
                    'disable_canonical_metabox' => [
                        'type' => 'boolean',
                        'description' => 'Disable canonical meta box in post editor'
                    ],
                    'disable_social_opengraph_metabox' => [
                        'type' => 'boolean',
                        'description' => 'Disable social/OpenGraph meta box in post editor'
                    ]
                ]
            ],
            'mcp' => [
                'description' => 'MCP Server configuration',
                'settings' => [
                    'metasync_mcp_enabled' => [
                        'type' => 'boolean',
                        'description' => 'Enable/disable the MCP server',
                        'stored_separately' => true,
                        'option_name' => 'metasync_mcp_enabled'
                    ],
                    'metasync_mcp_api_key' => [
                        'type' => 'string',
                        'description' => 'MCP server API key for authentication',
                        'sensitive' => true,
                        'stored_separately' => true,
                        'option_name' => 'metasync_mcp_api_key'
                    ]
                ]
            ]
        ];

        return $this->success([
            'schema' => $schema,
            'sections' => array_keys($schema),
            'total_sections' => count($schema),
            'note' => 'Some settings like MCP configuration are stored as separate options, not in the main metasync_options array'
        ]);
    }
}

/**
 * Get MCP Server Settings Tool
 */
class MCP_Tool_Get_MCP_Settings extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_mcp_settings';
    }

    public function get_description() {
        return 'Get MCP server-specific settings including authentication information and endpoint URLs.';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => []
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $options = get_option('metasync_options', []);
        $plugin_auth_token = isset($options['general']['apikey']) ? $options['general']['apikey'] : '';

        return $this->success([
            'mcp_enabled' => true, // MCP is always enabled
            'authentication' => [
                'type' => 'plugin_auth_token',
                'header' => 'X-API-Key',
                'token_set' => !empty($plugin_auth_token),
                'token_length' => !empty($plugin_auth_token) ? strlen($plugin_auth_token) : 0,
                'token_preview' => !empty($plugin_auth_token) ? substr($plugin_auth_token, 0, 8) . '...' : ''
            ],
            'endpoints' => [
                'rest_endpoint' => rest_url('metasync/v1/mcp'),
                'health_endpoint' => rest_url('metasync/v1/mcp/health')
            ],
            'version' => METASYNC_VERSION
        ]);
    }
}

