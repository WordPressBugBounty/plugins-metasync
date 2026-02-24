<?php
/**
 * Transforms a wp-config.php file.
 *
 * @package MetaSync
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exception thrown when wp-config.php file is missing.
 */
class WPConfigFileNotFoundException extends \Exception
{
}

/**
 * Exception thrown when wp-config.php file is not writable.
 */
class WPConfigFileNotWritableException extends \Exception
{
}

/**
 * Exception thrown when wp-config.php file is empty.
 */
class WPConfigFileEmptyException extends \Exception
{
}

/**
 * Exception thrown when config type is invalid.
 */
class WPConfigInvalidTypeException extends \Exception
{
}

/**
 * Exception thrown when config value is invalid.
 */
class WPConfigInvalidValueException extends \Exception
{
}

/**
 * Exception thrown when placement anchor cannot be located.
 */
class WPConfigAnchorNotFoundException extends \Exception
{
}

/**
 * Exception thrown when normalization fails.
 */
class WPConfigNormalizationException extends \Exception
{
}

/**
 * Exception thrown when saving wp-config.php fails.
 */
class WPConfigSaveException extends \Exception
{
}

/**
 * Transforms a wp-config.php file.
 */
class WPConfigTransformerMetaSync
{
    /**
     * Append to end of file
     */
    const ANCHOR_EOF = 'EOF';

    /**
     * Path to the wp-config.php file.
     *
     * @var string
     */
    protected $wpConfigPath;

    /**
     * Original source of the wp-config.php file.
     *
     * @var string
     */
    protected $wpConfigSrc;

    /**
     * Array of parsed configs.
     *
     * @var array
     */
    protected $wpConfigs = [];

    /**
     * Instantiates the class with a valid wp-config.php.
     *
     * @throws WPConfigFileNotFoundException If the wp-config.php file is missing.
     * @throws WPConfigFileNotWritableException If the wp-config.php file is not writable.
     *
     * @param string $wpConfigPath Path to a wp-config.php file.
     */
    public function __construct($wpConfigPath)
    {
        $basename = basename($wpConfigPath);

        if (!file_exists($wpConfigPath)) {
            throw new WPConfigFileNotFoundException("{$basename} does not exist.");
        }

        if (!is_writable($wpConfigPath)) {
            throw new WPConfigFileNotWritableException("{$basename} is not writable.");
        }

        $this->wpConfigPath = $wpConfigPath;
    }

    /**
     * Checks if a config exists in the wp-config.php file.
     *
     * @throws WPConfigFileEmptyException If the wp-config.php file is empty.
     * @throws WPConfigInvalidTypeException If the requested config type is invalid.
     *
     * @param string $type Config type (constant or variable).
     * @param string $name Config name.
     *
     * @return bool
     */
    public function exists($type, $name)
    {
        $wpConfigSrc = file_get_contents($this->wpConfigPath);

        if (!trim($wpConfigSrc)) {
            throw new WPConfigFileEmptyException('Config file is empty.');
        }

        // Normalize the newline to prevent an issue coming from OSX.
        $this->wpConfigSrc = str_replace(["\n\r", "\r"], "\n", $wpConfigSrc);
        $this->wpConfigs = $this->parseWpConfig($this->wpConfigSrc);

        if (!isset($this->wpConfigs[$type])) {
            throw new WPConfigInvalidTypeException("Config type '{$type}' does not exist.");
        }

        return isset($this->wpConfigs[$type][$name]);
    }

    /**
     * Get the value of a config in the wp-config.php file.
     *
     * @throws WPConfigFileEmptyException If the wp-config.php file is empty.
     *
     * @param string $type Config type (constant or variable).
     * @param string $name Config name.
     *
     * @return mixed|null
     */
    public function getValue($type, $name)
    {
        $wpConfigSrc = file_get_contents($this->wpConfigPath);

        if (!trim($wpConfigSrc)) {
            throw new WPConfigFileEmptyException('Config file is empty.');
        }

        $this->wpConfigSrc = $wpConfigSrc;
        $this->wpConfigs = $this->parseWpConfig($this->wpConfigSrc);

        if (!isset($this->wpConfigs[$type])) {
            return null;
        }

        return $this->wpConfigs[$type][$name]['value'];
    }

    /**
     * Adds a config to the wp-config.php file.
     *
     * @throws WPConfigAnchorNotFoundException If the config placement anchor could not be located.
     *
     * @param string $type    Config type (constant or variable).
     * @param string $name     Config name.
     * @param string $value    Config value.
     * @param array  $options  Optional. Array of special behavior options.
     *
     * @return bool
     */
    public function add($type, $name, $value, array $options = [])
    {
        if (!is_string($value)) {
            return false;
        }

        if ($this->exists($type, $name)) {
            return false;
        }

        $defaults = [
            'raw'       => false, // Display value in raw format without quotes.
            'anchor'    => "/* That's all, stop editing!", // Config placement anchor string.
            'separator' => PHP_EOL, // Separator between config definition and anchor string.
            'placement' => 'before', // Config placement direction (insert before or after).
        ];

        list($raw, $anchor, $separator, $placement) = array_values(array_merge($defaults, $options));

        $raw = (bool) $raw;
        $anchor = (string) $anchor;
        $separator = (string) $separator;
        $placement = (string) $placement;

        if (self::ANCHOR_EOF === $anchor) {
            $contents = $this->wpConfigSrc . $this->normalize($type, $name, $this->formatValue($value, $raw));
        } else {
            if (false === strpos($this->wpConfigSrc, $anchor)) {
                throw new WPConfigAnchorNotFoundException('Unable to locate placement anchor.');
            }

            $newSrc = $this->normalize($type, $name, $this->formatValue($value, $raw));
            $newSrc = ('after' === $placement) ? $anchor . $separator . $newSrc : $newSrc . $separator . $anchor;
            $contents = str_replace($anchor, $newSrc, $this->wpConfigSrc);
        }

        return $this->save($contents);
    }

    /**
     * Updates an existing config in the wp-config.php file.
     *
     * @throws WPConfigInvalidValueException If the config value provided is not a string.
     *
     * @param string $type    Config type (constant or variable).
     * @param string $name    Config name.
     * @param string $value   Config value.
     * @param array  $options Optional. Array of special behavior options.
     *
     * @return bool
     */
    public function update($type, $name, $value, array $options = [])
    {
        if (!is_string($value)) {
            throw new WPConfigInvalidValueException('Config value must be a string.');
        }

        $defaults = [
            'add'       => true, // Add the config if missing.
            'raw'       => false, // Display value in raw format without quotes.
            'normalize' => true, // Normalize config output using WP Coding Standards.
        ];

        list($add, $raw, $normalize) = array_values(array_merge($defaults, $options));

        $add = (bool) $add;
        $raw = (bool) $raw;
        $normalize = (bool) $normalize;

        if (!$this->exists($type, $name)) {
            return ($add) ? $this->add($type, $name, $value, $options) : false;
        }

        $oldSrc = $this->wpConfigs[$type][$name]['src'];
        $oldValue = $this->wpConfigs[$type][$name]['value'];
        $newValue = $this->formatValue($value, $raw);

        if ($normalize) {
            $newSrc = $this->normalize($type, $name, $newValue);
        } else {
            $newParts = $this->wpConfigs[$type][$name]['parts'];
            $newParts[1] = str_replace($oldValue, $newValue, $newParts[1]); // Only edit the value part.
            $newSrc = implode('', $newParts);
        }

        if ($value === "true") {
            $contents = preg_replace(
                sprintf('/(?<=^|;|<\?php\s|<\?\s)(\s*?)%s/m', preg_quote(trim($oldSrc), '/')),
                '$1' . str_replace('$', '\$', trim($newSrc)),
                $this->wpConfigSrc
            );
        } else {
            if (!$this->exists($type, $name)) {
                return $this->save('');
            }

            $pattern = sprintf('/(?<=^|;|<\?php\s|<\?\s)%s\s*(\S|$)/m', preg_quote($this->wpConfigs[$type][$name]['src'], '/'));
            $contents = preg_replace($pattern, '$1', $this->wpConfigSrc);
        }

        return $this->save($contents);
    }

    /**
     * Removes a config from the wp-config.php file.
     *
     * @param string $type Config type (constant or variable).
     * @param string $name Config name.
     *
     * @return bool
     */
    public function remove($type, $name)
    {
        if (!$this->exists($type, $name)) {
            return false;
        }

        $pattern = sprintf('/(?<=^|;|<\?php\s|<\?\s)%s\s*(\S|$)/m', preg_quote($this->wpConfigs[$type][$name]['src'], '/'));
        $contents = preg_replace($pattern, '$1', $this->wpConfigSrc);

        return $this->save($contents);
    }

    /**
     * Applies formatting to a config value.
     *
     * @throws WPConfigInvalidValueException When a raw value is requested for an empty string.
     *
     * @param string $value Config value.
     * @param bool   $raw   Display value in raw format without quotes.
     *
     * @return mixed
     */
    protected function formatValue($value, $raw)
    {
        if ($raw && '' === trim($value)) {
            throw new WPConfigInvalidValueException('Raw value for empty string not supported.');
        }

        return ($raw) ? $value : var_export($value, true);
    }

    /**
     * Normalizes the source output for a name/value pair.
     *
     * @throws WPConfigNormalizationException If the requested config type does not support normalization.
     *
     * @param string $type  Config type (constant or variable).
     * @param string $name  Config name.
     * @param mixed  $value Config value.
     *
     * @return string
     */
    protected function normalize($type, $name, $value)
    {
        if ('constant' === $type) {
            $placeholder = "define( '%s', %s );";
        } elseif ('variable' === $type) {
            $placeholder = '$%s = %s;';
        } else {
            throw new WPConfigNormalizationException("Unable to normalize config type '{$type}'.");
        }

        return sprintf($placeholder, $name, $value);
    }

    /**
     * Parses the source of a wp-config.php file.
     *
     * @param string $src Config file source.
     *
     * @return array
     */
    protected function parseWpConfig($src)
    {
        $configs = [];
        $configs['constant'] = [];
        $configs['variable'] = [];

        // Strip comments.
        foreach (token_get_all($src) as $token) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $src = str_replace($token[1], '', $src);
            }
        }

        preg_match_all('/(?<=^|;|<\?php\s|<\?\s)(\h*define\s*\(\s*[\'"](\w*?)[\'"]\s*)(,\s*(\'\'|""|\'.*?[^\\\\]\'|".*?[^\\\\]"|.*?)\s*)((?:,\s*(?:true|false)\s*)?\)\s*;)/ims', $src, $constants);
        preg_match_all('/(?<=^|;|<\?php\s|<\?\s)(\h*\$(\w+)\s*=)(\s*(\'\'|""|\'.*?[^\\\\]\'|".*?[^\\\\]"|.*?)\s*;)/ims', $src, $variables);

        if (!empty($constants[0]) && !empty($constants[1]) && !empty($constants[2]) && !empty($constants[3]) && !empty($constants[4]) && !empty($constants[5])) {
            foreach ($constants[2] as $index => $name) {
                $configs['constant'][$name] = [
                    'src'   => $constants[0][$index],
                    'value' => $constants[4][$index],
                    'parts' => [
                        $constants[1][$index],
                        $constants[3][$index],
                        $constants[5][$index],
                    ],
                ];
            }
        }

        if (!empty($variables[0]) && !empty($variables[1]) && !empty($variables[2]) && !empty($variables[3]) && !empty($variables[4])) {
            // Remove duplicate(s), last definition wins.
            $variables[2] = array_reverse(array_unique(array_reverse($variables[2], true)), true);
            foreach ($variables[2] as $index => $name) {
                $configs['variable'][$name] = [
                    'src'   => $variables[0][$index],
                    'value' => $variables[4][$index],
                    'parts' => [
                        $variables[1][$index],
                        $variables[3][$index],
                    ],
                ];
            }
        }

        return $configs;
    }

    /**
     * Saves new contents to the wp-config.php file.
     *
     * @throws WPConfigFileEmptyException If the config file content provided is empty.
     * @throws WPConfigSaveException If there is a failure when saving the wp-config.php file.
     *
     * @param string $contents New config contents.
     *
     * @return bool
     */
    protected function save($contents)
    {
        if (!trim($contents)) {
            throw new WPConfigFileEmptyException('Cannot save the config file with empty contents.');
        }

        if ($contents === $this->wpConfigSrc) {
            return false;
        }

        // Create backup before modifying wp-config.php
        $backupPath = $this->wpConfigPath . '.metasync-backup-' . time();
        if (!copy($this->wpConfigPath, $backupPath)) {
            throw new WPConfigSaveException('Failed to create backup of wp-config.php');
        }

        $result = file_put_contents($this->wpConfigPath, $contents, LOCK_EX);

        if (false === $result) {
            // Restore from backup on failure
            copy($backupPath, $this->wpConfigPath);
            unlink($backupPath);
            throw new WPConfigSaveException('Failed to update the config file.');
        }

        // Clean up old backups (keep last 5)
        $this->cleanupOldBackups();

        return true;
    }

    /**
     * Clean up old wp-config backup files, keeping only the last 5
     *
     * @return void
     */
    protected function cleanupOldBackups()
    {
        $configDir = dirname($this->wpConfigPath);
        $backupPattern = basename($this->wpConfigPath) . '.metasync-backup-*';
        $backups = glob($configDir . '/' . $backupPattern);

        if (count($backups) > 5) {
            // Sort by modification time (oldest first)
            usort($backups, function ($a, $b) {
                return filemtime($a) - filemtime($b);
            });

            // Delete oldest backups, keep last 5
            $toDelete = array_slice($backups, 0, count($backups) - 5);
            foreach ($toDelete as $oldBackup) {
                unlink($oldBackup);
            }
        }
    }
}

class ConfigControllerMetaSync
{
    const WPDD_DEBUGGING_PREDEFINED_CONSTANTS_STATE = 'dlct_data_initial';
    private static $configfilePath;

    protected $optionKey = 'debuglogconfigtool_updated_constant';
    public $debugConstants = ['WP_DEBUG', 'WP_DEBUG_LOG', 'SCRIPT_DEBUG'];
    protected $configFileManager;
    private static $configArgs = [
        'normalize' => true,
        'raw'       => true,
        'add'       => true,
    ];

    public function __construct()
    {
        $this->initialize();
    }

    private function initialize()
    {
        self::$configfilePath = $this->getConfigFilePath();
        // Set anchor for the constants to write
        $configContents = file_get_contents(self::$configfilePath);
        if (false === strpos($configContents, "/* That's all, stop editing!")) {
            preg_match('@\$table_prefix = (.*);@', $configContents, $matches);
            self::$configArgs['anchor'] = $matches[0] ?? '';
            self::$configArgs['placement'] = 'after';
        }

        if (!is_writable(self::$configfilePath)) {
            add_action('admin_notices', function () {
                $class = 'notice notice-error is-dismissible';
                $message = 'Config file not writable';
                printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
            });
            return;
        }

        $this->configFileManager = new WPConfigTransformerMetaSync(self::$configfilePath);
    }

    public function store()
    {
        try {
            // Whitelist of allowed constants to prevent arbitrary constant modification
            $allowedConstants = ['WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY'];

            $updatedConstants = [];
            $wpDebugEnabled = get_option('wp_debug_enabled', 'false');
            $wpDebugLogEnabled = get_option('wp_debug_log_enabled', 'false');
            $wpDebugDisplayEnabled = get_option('wp_debug_display_enabled', 'false');
            $constants = [
                'WP_DEBUG' => [
                    'name'  => 'WP_DEBUG',
                    'value' => ($wpDebugEnabled === 'true' ? true : false),
                    'info'  => 'Enable WP_DEBUG mode',
                ],
                'WP_DEBUG_LOG' => [
                    'name'  => 'WP_DEBUG_LOG',
                    'value' => ($wpDebugLogEnabled === 'true' ? true : false),
                    'info'  => 'Enable Debug logging to the /wp-content/debug.log file',
                ],
                'WP_DEBUG_DISPLAY' => [
                    'name'  => 'WP_DEBUG_DISPLAY',
                    'value' => ($wpDebugDisplayEnabled === 'true' ? true : false),
                    'info'  => 'Disable or hide display of errors and warnings in html pages'
                ]
            ];
            $this->maybeRemoveDeletedConstants($constants);

            foreach ($constants as $constant) {
                // Use sanitize_key instead of sanitize_title for constant names
                $key = strtoupper(sanitize_key($constant['name']));

                // Whitelist validation - only allow specific constants
                if (!in_array($key, $allowedConstants, true)) {
                    error_log('MetaSync: Attempted to modify non-whitelisted constant: ' . $key);
                    continue;
                }

                if (empty($key)) {
                    continue;
                }

                // Sanitize value - only allow boolean values
                $value = is_bool($constant['value']) ? $constant['value'] : ($constant['value'] === 'true' || $constant['value'] === true);
                $value = $value ? 'true' : 'false';

                $this->configFileManager->update('constant', $key, $value, self::$configArgs);
                $updatedConstants[] = $constant;
            }
        } catch (\Exception $e) {
            error_log('MetaSync: Error updating wp-config.php - ' . $e->getMessage());
            wp_send_json_error([
                'message' => $e->getMessage(),
                'success' => false
            ]);
        }
    }

    public function exists($constant)
    {
        return $this->configFileManager->exists('constant', strtoupper($constant));
    }

    public function getValue($constant)
    {
        if ($this->exists(strtoupper($constant))) {
            return $this->configFileManager->getValue('constant', strtoupper($constant));
        }
        return null;
    }

    public function update($key, $value)
    {
        try {
            // By default, when attempting to update a config that doesn't exist, one will be added.
            $option = self::$configArgs;
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            return $this->configFileManager->update('constant', strtoupper($key), $value, $option);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getConfigFilePath()
    {
        $file = ABSPATH . 'wp-config.php';
        if (!file_exists($file)) {
            if (@file_exists(dirname(ABSPATH) . '/wp-config.php')) {
                $file = dirname(ABSPATH) . '/wp-config.php';
            }
        }
        return apply_filters('wp_dlct_config_file_manager_path', $file);
    }

    /**
     * Remove deleted constant from config
     *
     * @param array $constants Array of constants.
     *
     * @return void
     */
    protected function maybeRemoveDeletedConstants($constants)
    {
        $deletedConstant = array_diff(array_column($constants, 'name'), array_column($constants, 'name'));

        foreach ($deletedConstant as $item) {
            $this->configFileManager->remove('constant', strtoupper($item));
        }
    }
}
