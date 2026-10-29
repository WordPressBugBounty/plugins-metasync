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
 * White-label guard for the MCP plugin-settings tools.
 *
 * The white-label workflow is agency → client: the agency sets branding,
 * locks it with the whitelabel settings password in the admin UI, and ships
 * the branded plugin to the client via Export-as-ZIP. That password only
 * gates the admin UI tabs (handle_session_management_early()); it does not
 * guard the MCP path. Exposing white-label settings through MCP would let
 * any MCP credential holder (e.g. the client) re-brand the plugin or unset
 * the password, unlocking the guarded UI tabs and bypassing the agency's
 * lock entirely.
 *
 * White-label settings are therefore hidden from MCP reads and rejected on
 * MCP writes. They remain manageable only through the password-gated admin
 * UI and the whitelabel-settings.json import/export flow.
 */
final class MCP_Plugin_Settings_Whitelabel_Guard {

    /**
     * general.* option keys that carry white-label branding.
     *
     * Fallback only — when the plugin classes are loaded (production and
     * most test paths), general_branding_keys() delegates to
     * Metasync_Whitelabel_Preservation::general_whitelabel_keys() so this
     * list can never drift from the reset/export source of truth.
     */
    private static $general_branding_keys_fallback = [
        'white_label_plugin_name',
        'white_label_plugin_description',
        'white_label_plugin_author',
        'white_label_plugin_author_uri',
        'white_label_plugin_uri',
        'white_label_plugin_menu_slug',
        'white_label_plugin_menu_icon',
        'whitelabel_otto_name',
    ];

    /**
     * Extra legacy flat keys (beyond the general branding keys) that carry
     * white-label data, including the settings password itself.
     */
    private static $extra_flat_protected_keys = [
        'whitelabel_logo_url',
        'whitelabel_domain_url',
        'whitelabel_settings_password',
    ];

    /**
     * Return the general.* keys that carry white-label branding, from the
     * single source of truth when it is available.
     *
     * @return string[]
     */
    public static function general_branding_keys() {
        if (class_exists('Metasync_Whitelabel_Preservation')) {
            return Metasync_Whitelabel_Preservation::general_whitelabel_keys();
        }
        return self::$general_branding_keys_fallback;
    }

    /**
     * Return every flat top-level key that must not leave the server via MCP.
     *
     * @return string[]
     */
    public static function flat_protected_keys() {
        return array_merge(self::general_branding_keys(), self::$extra_flat_protected_keys);
    }

    /**
     * Remove every white-label value from an option tree before it is
     * returned to an MCP caller. Covers the nested whitelabel blob, the
     * general branding keys, and any legacy flat keys.
     *
     * @param mixed $options
     * @return mixed
     */
    public static function strip_from_options($options) {
        if (!is_array($options)) {
            return $options;
        }
        unset($options['whitelabel']);
        if (isset($options['general']) && is_array($options['general'])) {
            foreach (self::general_branding_keys() as $key) {
                unset($options['general'][$key]);
            }
        }
        foreach (self::flat_protected_keys() as $key) {
            unset($options[$key]);
        }
        return $options;
    }

    /**
     * Throw when resolved settings address any white-label path — the whole
     * whitelabel section, a branding key nested under general, or a legacy
     * flat key.
     *
     * Keys are compared in their sanitize_key()-normalized form. The guard
     * runs BEFORE sanitize_settings(), but the store is written with
     * post-sanitization keys, so a caller sending "Whitelabel",
     * "White_Label_Plugin_Name", or "whitelabel\u0000" would otherwise sail
     * past an exact match here and land on the protected path when
     * sanitize_key() lowercases and strips the noise characters at write
     * time.
     *
     * @param array $settings Resolved settings (post alias resolution).
     * @return void
     * @throws Exception When a white-label setting is addressed.
     */
    public static function assert_not_addressed($settings) {
        if (!is_array($settings)) {
            return;
        }
        foreach ($settings as $key => $value) {
            $norm = sanitize_key((string) $key);
            if ($norm === 'whitelabel') {
                self::reject('whitelabel.*');
            }
            if (in_array($norm, self::flat_protected_keys(), true)) {
                self::reject($norm);
            }
            if ($norm === 'general' && is_array($value)) {
                foreach ($value as $general_key => $_) {
                    $general_norm = sanitize_key((string) $general_key);
                    if (in_array($general_norm, self::general_branding_keys(), true)) {
                        self::reject('general.' . $general_norm);
                    }
                }
            }
        }
    }

    /**
     * @param string $key
     * @return void
     * @throws Exception
     */
    private static function reject($key) {
        throw new Exception(
            'Setting "' . esc_html($key) . '" is not available through MCP: '
            . 'white-label branding is managed only in the plugin admin UI '
            . '(password-gated) or via the whitelabel-settings.json import/export flow.'
        );
    }
}

/**
 * Get Plugin Settings Tool
 */
class MCP_Tool_Get_Plugin_Settings extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_plugin_settings';
    }

    public function get_description() {
        return 'Get all plugin settings or settings for a specific section. Returns the plugin configuration including features, SEO controls, API keys, and more. White-label settings are not available through this tool (manageable only in the password-gated admin UI).';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'section' => [
                    'type' => 'string',
                    'description' => 'Optional: specific settings section to retrieve (e.g., "general", "seo", "social"). If omitted, returns all settings.',
                    'enum' => [
                        'general',
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

        // Get all plugin options. White-label settings (branding and the
        // whitelabel settings password) are stripped before the values leave
        // the server — they are managed only through the password-gated admin
        // UI and the whitelabel-settings.json import/export flow.
        $all_options = MCP_Plugin_Settings_Whitelabel_Guard::strip_from_options(
            get_option(Metasync::option_name, [])
        );

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
                'settings' => $this->mask_sensitive_values($result),
                'count' => count($result)
            ]);
        }

        // If section specified (options are nested e.g. $all_options['general']['apikey'])
        if (!empty($params['section']) && $params['section'] !== 'all') {
            $section = $this->sanitize_string($params['section']);

            if (isset($all_options[$section]) && is_array($all_options[$section])) {
                $result = $all_options[$section];
            } else {
                // Fallback: map section to flat keys for legacy structure
                $section_keys = $this->get_section_keys($section);
                $result = [];
                foreach ($section_keys as $key) {
                    if (isset($all_options[$key])) {
                        $result[$key] = $all_options[$key];
                    }
                }
            }

            return $this->success([
                'section' => $section,
                'settings' => $this->mask_sensitive_values($result),
                'count' => count($result)
            ]);
        }

        // Return all settings
        return $this->success([
            'settings' => $this->mask_sensitive_values($all_options),
            'count' => count($all_options),
            'available_sections' => [
                'general' => 'API keys, integration settings',
                'seo' => 'SEO controls and indexation settings',
                'social' => 'Social media and OpenGraph settings',
                'advanced' => 'Advanced plugin features',
                'features' => 'Feature toggles and visibility',
                'api' => 'API configuration and tokens'
            ]
        ]);
    }

    /**
     * Recursively mask sensitive values before returning settings to a caller.
     *
     * The Search Atlas API key is stored encrypted at rest (enc_v1: ciphertext).
     * Neither the ciphertext nor the decrypted plaintext may leave the server,
     * so any 'searchatlas_api_key' entry is replaced with a masked display
     * (8 bullets + last 4 chars of the real key), or '' when not configured.
     *
     * @param mixed $data
     * @return mixed
     */
    private function mask_sensitive_values($data) {
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->mask_sensitive_values($value);
            } elseif ($key === 'searchatlas_api_key') {
                $decrypted = Metasync::get_searchatlas_api_key();
                if ($decrypted === false || $decrypted === '') {
                    $data[$key] = '';
                } else {
                    $data[$key] = str_repeat('•', 8) . substr($decrypted, -4);
                }
            } elseif ($key === 'apikey') {
                $data[$key] = !empty($value) ? str_repeat('•', 8) . substr((string) $value, -4) : '';
            }
        }
        return $data;
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
                'enable_schema_markup',
                'default_schema_type'
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
                'disable_social_opengraph_metabox',
                'disable_schema_markup_metabox',
                'disable_seo_metabox'
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
        return 'Update one or more plugin settings. Allows modifying plugin configuration including features, SEO controls, API keys, and more. White-label settings are not available through this tool.';
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

        // Resolve any aliased or misnamed keys to their canonical nested paths
        $settings = $this->resolve_setting_aliases($params['settings']);

        // The plugin auth token is read-only through MCP. Reads return a masked
        // display value, so never allow that display value to replace the
        // secret. Match every key spelling sanitize_key() would collapse to
        // "apikey" ("APIKEY", "apiKey", a differently-cased "general"
        // section, ...); a re-spelled key would otherwise survive this strip
        // and, once sanitization lowercases it, overwrite the stored token.
        $settings = $this->strip_auth_token_spellings($settings);

        // A payload that carried nothing but the auth token (in any spelling)
        // has nothing writable left once the token is stripped. In replace
        // mode it would otherwise swap the whole options blob for nothing but
        // the carried-forward read-only keys — wiping every other plugin
        // setting while still reporting success. Reject it, the same contract
        // as the read-only UUID below, so the caller learns the token cannot
        // be set here.
        if (empty($settings)) {
            throw new Exception(
                'No writable settings were provided: the plugin auth token is '
                . 'read-only and cannot be set through this tool. Include other '
                . 'settings to update.'
            );
        }

        // otto_pixel_uuid is read-only. It is assigned only by the
        // trusted Search Atlas / heartbeat synchronization flows; a username or
        // any other arbitrary text stored here breaks OTTO SSR authentication.
        // Reject the write outright rather than silently dropping it, so an
        // MCP consumer knows to re-authenticate instead of retrying the write.
        $this->assert_no_read_only_keys($settings);

        // White-label settings (branding + the whitelabel settings password)
        // must not be writable through MCP — the password only gates the
        // admin UI, so an MCP write would bypass the agency's lock. Reject
        // with an explanatory error rather than silently dropping the write.
        // Asserted twice: once on the raw payload and once on its sanitized
        // form, so any key spelling that survives into the store is caught.
        MCP_Plugin_Settings_Whitelabel_Guard::assert_not_addressed($settings);
        MCP_Plugin_Settings_Whitelabel_Guard::assert_not_addressed($this->sanitize_settings($settings));

        // Get current options
        $current_options = get_option(Metasync::option_name, []);
        $settings = $this->restore_masked_searchatlas_api_key($settings, $current_options);

        if ($merge) {
            // Deep merge to preserve nested sub-arrays (e.g. general, seo_controls)
            $new_options = $this->array_merge_deep($current_options, $settings);
        } else {
            // Replace all settings
            $new_options = $settings;
        }

        // White-label branding values are mirrored into the metasync.php
        // plugin header; a value that fails the header contract would be
        // stored but could never be applied, leaving the options inconsistent
        // with the Plugins screen branding. Reject the whole write with the
        // offending fields named — the same contract the administrator form
        // and the JSON package import enforce. Only keys this write actually
        // supplies are checked, so legacy stored values never block unrelated
        // settings updates.
        if (!class_exists('Metasync_Activator')) {
            require_once dirname(__DIR__, 2) . '/includes/class-metasync-activator.php';
        }
        $submitted_general = is_array($settings['general'] ?? null) ? $settings['general'] : [];
        $submitted_branding = array_intersect_key(
            $submitted_general,
            array_flip(Metasync_Activator::BRANDING_HEADER_FIELDS)
        );
        $branding_errors = Metasync_Activator::validate_plugin_header_settings($submitted_branding);
        if (!empty($branding_errors)) {
            // The messages are plugin-controlled static labels; the escaping
            // layer is inert here, but Plugin Check's EscapeOutput sniff
            // requires an escaper on dynamic output inside an exception
            // message, so keep it.
            throw new Exception(
                'Invalid white-label branding values; nothing was saved: '
                . esc_html(implode(' ', array_values($branding_errors)))
            );
        }

        // Replace mode can omit the stored authentication token, but omission
        // must not clear it. MCP cannot rotate this token.
        $stored_auth_token = isset($current_options['general']) && is_array($current_options['general'])
            ? ($current_options['general']['apikey'] ?? null) : null;
        if ($stored_auth_token !== null) {
            if (!isset($new_options['general']) || !is_array($new_options['general'])) {
                $new_options['general'] = [];
            }
            $new_options['general']['apikey'] = $stored_auth_token;
        }

        // Replace mode can omit the read-only UUID, but omission must not
        // disconnect the site. Always carry the stored value forward.
        $stored_uuid_present = isset($current_options['general'])
            && is_array($current_options['general'])
            && array_key_exists('otto_pixel_uuid', $current_options['general']);
        if ($stored_uuid_present) {
            if (!isset($new_options['general']) || !is_array($new_options['general'])) {
                $new_options['general'] = [];
            }
            $new_options['general']['otto_pixel_uuid'] = $current_options['general']['otto_pixel_uuid'];
        }

        // Sanitize sensitive fields
        $new_options = $this->sanitize_settings($new_options);
        // sanitize_key() lowercases every key, so any spelling of the auth
        // token key that still reached this point lands on general.apikey only
        // now. Re-apply the stored token post-sanitize — the same guarantee
        // the UUID gets below — so the stored secret is the only value that
        // can survive at that path. On a site with no stored token, drop the
        // path instead: MCP cannot plant one either.
        if ($stored_auth_token !== null) {
            if (!isset($new_options['general']) || !is_array($new_options['general'])) {
                $new_options['general'] = [];
            }
            $new_options['general']['apikey'] = $stored_auth_token;
        } elseif (isset($new_options['general']) && is_array($new_options['general'])) {
            unset($new_options['general']['apikey']);
        }
        if ($stored_uuid_present) {
            $new_options['general']['otto_pixel_uuid'] = $current_options['general']['otto_pixel_uuid'];
        }

        // Replace mode can omit the whole whitelabel identity, but omission
        // must not be able to destroy it either. Carry the stored whitelabel
        // blob and general branding keys forward untouched (post-sanitize,
        // like the UUID above, so stored values are not re-sanitized).
        if (isset($current_options['whitelabel']) && is_array($current_options['whitelabel'])) {
            if (!isset($new_options['whitelabel']) || !is_array($new_options['whitelabel'])) {
                $new_options['whitelabel'] = [];
            }
            $new_options['whitelabel'] = array_merge($new_options['whitelabel'], $current_options['whitelabel']);
        }
        if (isset($current_options['general']) && is_array($current_options['general'])) {
            foreach (MCP_Plugin_Settings_Whitelabel_Guard::general_branding_keys() as $key) {
                if (array_key_exists($key, $current_options['general'])) {
                    if (!isset($new_options['general']) || !is_array($new_options['general'])) {
                        $new_options['general'] = [];
                    }
                    $new_options['general'][$key] = $current_options['general'][$key];
                }
            }
        }

        // Encrypt the Search Atlas API key at rest if this update supplied a new
        // value. It is persisted as enc_v1: ciphertext so the cleartext never
        // lands in wp_options. Already-encrypted values pass through untouched.
        if (isset($new_options['general']['searchatlas_api_key'])
            && !Metasync::is_encrypted_api_key($new_options['general']['searchatlas_api_key'])) {
            $new_options['general']['searchatlas_api_key'] =
                Metasync::encrypt_api_key($new_options['general']['searchatlas_api_key']);
        }
        if (isset($new_options['searchatlas_api_key'])
            && !Metasync::is_encrypted_api_key($new_options['searchatlas_api_key'])) {
            $new_options['searchatlas_api_key'] =
                Metasync::encrypt_api_key($new_options['searchatlas_api_key']);
        }

        // Defense in depth only: with the whitelabel guard working, the
        // carried-forward value is always the already-encrypted stored one,
        // so encrypt_secret() is a no-op here. This can only do real work
        // for a legacy plaintext-at-rest value; MCP callers cannot write
        // this field (the guard above rejects every whitelabel path).
        if (!empty($new_options['whitelabel']['settings_password']) && is_string($new_options['whitelabel']['settings_password'])) {
            $new_options['whitelabel']['settings_password'] = Metasync::encrypt_secret($new_options['whitelabel']['settings_password']);
        }

        // Update the option
        $updated = update_option(Metasync::option_name, $new_options);

        if ($updated === false && $current_options !== $new_options) {
            throw new Exception('Failed to update plugin settings');
        }

        // Clear any relevant caches
        wp_cache_delete(Metasync::option_name, 'options');
        Metasync::invalidate_api_key_cache();

        return $this->success([
            'message' => 'Plugin settings updated successfully',
            'updated_keys' => array_keys($params['settings']),
            'merge_mode' => $merge,
            'total_settings' => count($new_options)
        ]);
    }

    /**
     * Resolve aliased or flat keys to their canonical nested location.
     *
     * When an MCP consumer sends a flat key like "enable_schema_markup", this
     * method routes it into the correct nested sub-array ("general") so it
     * updates the same option the admin UI uses.
     */
    private function resolve_setting_aliases($settings) {
        // Map of flat keys → [section, canonical_key]
        $nested_key_map = [
            'enable_schema_markup' => ['general', 'enable_schema_markup'],
            'enable_schema'        => ['general', 'enable_schema_markup'],
            'default_schema_type'  => ['general', 'default_schema_type'],
        ];

        $resolved = [];
        foreach ($settings as $key => $value) {
            if (isset($nested_key_map[$key])) {
                list($section, $canonical) = $nested_key_map[$key];
                if (!isset($resolved[$section])) {
                    $resolved[$section] = [];
                }
                $resolved[$section][$canonical] = $value;
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    /**
     * Settings this tool refuses to write because another, trusted flow owns
     * them.
     *
     * general.otto_pixel_uuid is assigned by the Search Atlas SSO callback and
     * the heartbeat synchronization only. Storing anything else there — a
     * WordPress username, or arbitrary text an agent guessed — breaks OTTO SSR
     * authentication, so the write is rejected with an explanatory error
     * rather than silently dropped.
     */
    private function get_read_only_setting_paths() {
        return [
            'otto_pixel_uuid'             => 'general.otto_pixel_uuid',
            'general.otto_pixel_uuid'     => 'general.otto_pixel_uuid',
            // The schema groups the UUID under "social"; a consumer following
            // that documentation must get the same rejection rather than a
            // silent write into a bogus top-level "social" section.
            'social.otto_pixel_uuid'      => 'social.otto_pixel_uuid',
        ];
    }

    /**
     * Throw when the requested settings touch a read-only path.
     *
     * @param array $settings Resolved settings (post alias resolution).
     * @return void
     * @throws Exception When a read-only setting is addressed.
     */
    private function assert_no_read_only_keys($settings) {
        foreach ($this->get_read_only_setting_paths() as $needle => $canonical) {
            list($section, $key) = explode('.', $canonical);
            $addressed = array_key_exists($needle, $settings)
                || (isset($settings[$section]) && is_array($settings[$section]) && array_key_exists($key, $settings[$section]));
            if ($addressed) {
                $canonical_display = ($canonical === 'social.otto_pixel_uuid')
                    ? 'general.otto_pixel_uuid'
                    : $canonical;
                throw new Exception(
                    'Setting "' . esc_html($canonical_display) . '" is read-only: the '
                    . 'OTTO Pixel UUID is assigned automatically by the Search Atlas / heartbeat '
                    . 'synchronization flows and cannot be set through this tool. '
                    . 'Use the plugin\'s one-click authentication to change it.'
                );
            }
        }
    }

    /**
     * Preserve the stored Search Atlas key when a caller writes back a masked
     * value returned by the settings read tool.
     *
     * The read tool intentionally returns eight bullets plus the real key's
     * last four characters. That display value is not a new API key and must
     * never be encrypted and persisted as one. Restore the original stored
     * value before merge/replace processing; callers can still submit a real
     * new key when they intentionally provide a non-masked value.
     */
    private function restore_masked_searchatlas_api_key(array $settings, array $current_options) {
        $stored = null;
        $stored_location = null;
        if (isset($current_options['general']) && is_array($current_options['general'])
            && array_key_exists('searchatlas_api_key', $current_options['general'])) {
            $stored = $current_options['general']['searchatlas_api_key'];
            $stored_location = 'general';
        } elseif (array_key_exists('searchatlas_api_key', $current_options)) {
            $stored = $current_options['searchatlas_api_key'];
            $stored_location = 'flat';
        }
        if (!is_string($stored) || $stored === '') {
            return $settings;
        }

        $decrypted = Metasync::decrypt_api_key($stored);
        $masked = is_string($decrypted) && $decrypted !== ''
            ? str_repeat('•', 8) . substr($decrypted, -4) : null;
        // The read tool returns an empty string only when the stored value
        // cannot be decrypted. Round-tripping that read must preserve the
        // ciphertext — but an empty write against a *working* key is an
        // intentional clear and must go through unchanged.
        $empty_restores_stored = $decrypted === false;
        $is_masked_value = static function ($value) use ($masked, $empty_restores_stored) {
            if (!is_string($value)) {
                return ($value === null || $value === false) && $empty_restores_stored;
            }
            $value = trim($value);
            if ($value === '') {
                return $empty_restores_stored;
            }
            if ($masked !== null && $value === $masked) {
                return true;
            }
            return (bool) preg_match('/^[•*]{7,}[A-Za-z0-9]{0,4}$/u', $value);
        };

        $masked_key_seen = false;
        $canonical_general = [];
        foreach ($settings as $key => $value) {
            if (sanitize_key((string) $key) !== 'general' || !is_array($value)) {
                continue;
            }
            unset($settings[$key]);
            foreach ($value as $inner_key => $inner_value) {
                if (sanitize_key((string) $inner_key) === 'searchatlas_api_key') {
                    if ($is_masked_value($inner_value)) {
                        $masked_key_seen = true;
                    } else {
                        $canonical_general[$inner_key] = $inner_value;
                    }
                } else {
                    $canonical_general[$inner_key] = $inner_value;
                }
            }
        }
        if ($masked_key_seen) {
            if ($stored_location === 'general') {
                $canonical_general['searchatlas_api_key'] = $stored;
                $settings['general'] = $canonical_general;
            } else {
                $settings['searchatlas_api_key'] = $stored;
                if (!empty($canonical_general)) {
                    $settings['general'] = $canonical_general;
                }
            }
        } elseif (!empty($canonical_general)) {
            $settings['general'] = $canonical_general;
        }

        foreach ($settings as $key => $value) {
            if (sanitize_key((string) $key) === 'searchatlas_api_key' && $is_masked_value($value)) {
                unset($settings[$key]);
                if ($stored_location === 'general') {
                    if (!isset($settings['general']) || !is_array($settings['general'])) {
                        $settings['general'] = [];
                    }
                    $settings['general']['searchatlas_api_key'] = $stored;
                } else {
                    $settings['searchatlas_api_key'] = $stored;
                }
            }
        }

        return $settings;
    }

    /**
     * Recursively merge two arrays, preserving nested sub-arrays.
     */
    private function array_merge_deep($base, $override) {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->array_merge_deep($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /**
     * Remove every spelling of the plugin auth token key from a settings
     * payload, together with a general section left empty by the removal.
     *
     * sanitize_key() lowercases keys and strips punctuation later in the
     * pipeline, so "APIKEY", "apiKey", "api key", "api.key" or a "General "
     * section normalize to the canonical key only after this point — where
     * they would overwrite the stored token. Match with sanitize_key() so
     * the strip sees exactly what the save pipeline will produce.
     */
    private function strip_auth_token_spellings(array $settings) {
        foreach ($settings as $key => $value) {
            $normalized = sanitize_key((string) $key);
            if ($normalized === 'apikey') {
                unset($settings[$key]);
                continue;
            }
            if ($normalized === 'general' && is_array($value)) {
                foreach ($value as $inner_key => $inner_value) {
                    if (sanitize_key((string) $inner_key) === 'apikey') {
                        unset($settings[$key][$inner_key]);
                    }
                }
                if (empty($settings[$key])) {
                    unset($settings[$key]);
                }
            }
        }
        return $settings;
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
            } elseif (is_bool($value)) {
                $sanitized[$key] = $value;
            } elseif (is_numeric($value)) {
                $sanitized[$key] = $value;
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
            'properties' => (object)[],
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
                    'enable_schema_markup' => [
                        'type' => 'boolean',
                        'description' => 'Enable automatic schema markup generation. Canonical key — do NOT use "enable_schema".'
                    ],
                    'default_schema_type' => [
                        'type' => 'string',
                        'description' => 'Default schema type for new posts (e.g., "article", "WebSite")'
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
                        'description' => 'Otto Pixel UUID for tracking (READ-ONLY: assigned only by the Search Atlas / heartbeat synchronization; not writable via this tool)',
                        'read_only' => true
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
                    ],
                    'disable_schema_markup_metabox' => [
                        'type' => 'boolean',
                        'description' => 'Disable schema markup meta box in post editor'
                    ],
                    'disable_seo_metabox' => [
                        'type' => 'boolean',
                        'description' => 'Disable SEO Title & Meta Description meta box in Classic editor'
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
            'note' => 'Some settings like MCP configuration are stored as separate options, not in the main metasync_options array. White-label branding settings are excluded from MCP entirely and are manageable only in the password-gated admin UI or via the whitelabel-settings.json import/export flow.'
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
            'properties' => (object)[],
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
                'token_preview' => !empty($plugin_auth_token) ? str_repeat('•', 8) . substr($plugin_auth_token, -4) : ''
            ],
            'endpoints' => [
                'rest_endpoint' => rest_url('metasync/v1/mcp'),
                'health_endpoint' => rest_url('metasync/v1/mcp/health')
            ],
            'version' => METASYNC_VERSION
        ]);
    }
}

