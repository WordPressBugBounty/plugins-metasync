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
     * Age in seconds before a stranded .metasync-tmp-* copy is safe to delete.
     */
    const TEMP_MAX_AGE = 300;

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
     * Saves new contents to the wp-config.php file via an atomic temp-file + rename() write.
     *
     * No persistent backup copy of wp-config.php is ever left in the web root. The new
     * contents are written to a non-guessable, 0600 temp file alongside the real config
     * file and then rename()'d over it, which is atomic on the same filesystem. The
     * original file is never modified until that rename succeeds, so on any failure it is
     * left intact and no on-disk backup or rollback write is needed.
     *
     * Symlinks are resolved first so a symlinked wp-config.php keeps pointing at its real
     * target instead of being replaced by a regular file, and the original mode and
     * ownership are reapplied to the newly renamed file.
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

        // Resolve symlinks and write to the real file. Replacing the path directly
        // would swap a symlinked wp-config.php for a regular file, which on sites that
        // deliberately keep the real config outside the web root would drop a full
        // credentials file into the web root - the opposite of the intent here.
        // Resolving also keeps the temp file on the same filesystem as the target,
        // which rename() needs in order to stay atomic.
        $targetPath = @realpath($this->wpConfigPath);
        if (false === $targetPath) {
            $targetPath = $this->wpConfigPath;
        }

        $dir = dirname($targetPath);

        // The atomic path needs a writable directory, while the constructor only
        // guarantees a writable file. WordPress' recommended "wp-config.php one level
        // above the web root" layout commonly has exactly that shape, and earlier
        // versions wrote fine there, so fall back rather than refusing to save.
        if (!is_writable($dir)) {
            // Logged because the fallback gives up atomicity: a crash mid-write can
            // leave wp-config.php short, so affected hosts should be identifiable.
            error_log('MetaSync: ' . $dir . ' is not writable; writing wp-config.php in place'
                . ' (non-atomic) instead of via an atomic replace.');
            return $this->saveInPlace($targetPath, $contents);
        }

        // Reap copies stranded by an earlier hard kill. A fatal, OOM or timeout between
        // fopen() and rename() skips the finally block below and leaves a 0600 copy of
        // wp-config.php next to it; nothing else would ever remove it.
        $this->reapStaleTempFiles($dir);

        $originalPerms = @fileperms($targetPath) & 0777;
        $originalOwner = @fileowner($targetPath);
        $originalGroup = @filegroup($targetPath);

        // Non-guessable temp path in the same directory so rename() stays atomic.
        $tempFile = $dir . '/.metasync-tmp-' . bin2hex(random_bytes(8));

        try {
            // 'x' mode fails if the path already exists, so we never clobber another file.
            $handle = @fopen($tempFile, 'x');
            if (false === $handle) {
                // The directory looked writable but the create failed anyway.
                return $this->saveInPlace($targetPath, $contents);
            }

            // Lock the temp file down before any sensitive content is written to it.
            $permissionsLocked = @chmod($tempFile, 0600);
            $permissionsWrong = PHP_OS_FAMILY !== 'Windows'
                && ((@fileperms($tempFile) & 0777) !== 0600);
            if (!$permissionsLocked || $permissionsWrong) {
                @fclose($handle);
                throw new WPConfigSaveException('Could not secure the temporary config file.');
            }

            $written = @fwrite($handle, $contents);
            $flushed = @fflush($handle);

            if (function_exists('fsync')) {
                @fsync($handle);
            }

            $closed = @fclose($handle);
            clearstatcache(true, $tempFile);

            // fwrite() can report a full write while the error only surfaces later at
            // flush or close time (a full disk, NFS, EIO). Confirm the bytes actually
            // landed before this file is allowed to replace a working wp-config.php.
            if (
                false === $written
                || strlen($contents) !== $written
                || false === $flushed
                || false === $closed
                || @filesize($tempFile) !== strlen($contents)
            ) {
                throw new WPConfigSaveException('Failed to update the config file.');
            }

            // Atomically move the temp file over the config file. The original is only
            // ever replaced by this single call; on failure it remains untouched.
            if (!@rename($tempFile, $targetPath)) {
                throw new WPConfigSaveException('Failed to update the config file.');
            }
        } finally {
            // A thrown failure, fatal or timeout must never leave a readable copy of
            // wp-config.php sitting next to it.
            clearstatcache(true, $tempFile);
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }

        // rename() installs a new inode, so the original ownership and mode do not
        // carry over. Restore both - ownership best-effort, since only a privileged
        // process may change it - so the file keeps the identity the host expects.
        if (PHP_OS_FAMILY !== 'Windows' && false !== $originalOwner && !@chown($targetPath, $originalOwner)) {
            // Common on hosts where wp-config.php is owned by a deploy user and only
            // group-writable by the web user: the file is now owned by the web user and
            // an unprivileged process cannot hand it back. Deploy tooling may lose write
            // access, so make it findable rather than silent.
            error_log('MetaSync: wp-config.php is no longer owned by uid ' . $originalOwner
                . ' after being rewritten, and ownership could not be restored.');
        }

        if (PHP_OS_FAMILY !== 'Windows' && false !== $originalGroup) {
            @chgrp($targetPath, $originalGroup);
        }

        if ($originalPerms) {
            @chmod($targetPath, $originalPerms);
        }

        return true;
    }

    /**
     * Deletes .metasync-tmp-* files older than the safety window.
     *
     * Each one is a full copy of wp-config.php, so they must not accumulate. Only files
     * older than TEMP_MAX_AGE are touched, so a save running concurrently in another
     * request never has its temp file pulled out from under it.
     *
     * @param string $dir Directory holding the config file.
     *
     * @return void
     */
    protected function reapStaleTempFiles($dir)
    {
        foreach ((array) glob($dir . '/.metasync-tmp-*') as $temp) {
            if (!is_file($temp)) {
                continue;
            }

            $mtime = @filemtime($temp);
            if (false === $mtime || abs(time() - $mtime) < self::TEMP_MAX_AGE) {
                continue;
            }

            @unlink($temp);
        }
    }

    /**
     * Writes the config file in place, keeping its inode, ownership and mode.
     *
     * Used when the config file is writable but its directory is not, so the atomic
     * temp-file + rename() path is unavailable. No second copy of the file is created,
     * so this does not reintroduce the web-root credential exposure; it trades
     * atomicity for still working on hosts where the directory cannot be written.
     *
     * Because an in-place write can genuinely truncate the file, this is the one path
     * where restoring the original contents from memory is the correct recovery.
     *
     * @throws WPConfigSaveException If the write fails or the file is short afterwards.
     *
     * @param string $targetPath Path to the config file.
     * @param string $contents   New config contents.
     *
     * @return bool
     */
    protected function saveInPlace($targetPath, $contents)
    {
        $written = @file_put_contents($targetPath, $contents, LOCK_EX);
        clearstatcache(true, $targetPath);

        if (
            false === $written
            || strlen($contents) !== $written
            || @filesize($targetPath) !== strlen($contents)
        ) {
            // The file may have been left short, so put the known-good source back.
            if ('' !== trim($this->wpConfigSrc)) {
                $restored = @file_put_contents($targetPath, $this->wpConfigSrc, LOCK_EX);
                clearstatcache(true, $targetPath);

                // A false return also fails this comparison, so it covers both cases.
                if (strlen($this->wpConfigSrc) !== $restored) {
                    // Both the write and the rollback failed, so the file on disk is
                    // very likely truncated and the site will not boot. Say so plainly -
                    // there is deliberately no backup copy to restore from.
                    error_log('MetaSync: wp-config.php may be truncated at ' . $targetPath
                        . ' - restore it manually.');
                    throw new WPConfigSaveException(
                        'Failed to update the config file, and wp-config.php may now be incomplete. Please check it.'
                    );
                }
            }

            throw new WPConfigSaveException('Failed to update the config file.');
        }

        return true;
    }
}

class ConfigControllerMetaSync
{
    const WPDD_DEBUGGING_PREDEFINED_CONSTANTS_STATE = 'dlct_data_initial';
    private static $configfilePath;

    protected $optionKey = 'debuglogconfigtool_updated_constant';
    public $debugConstants = ['WP_DEBUG', 'WP_DEBUG_LOG', 'SCRIPT_DEBUG'];
    protected $configFileManager;

    /**
     * Reason wp-config.php cannot be updated, empty string when it can.
     *
     * @var string
     */
    protected $configError = '';

    /**
     * True when no anchor could be found to insert new constants after.
     *
     * @var bool
     */
    protected $anchorMissing = false;

    /**
     * True when the last store() changed the file but did not apply every constant.
     *
     * Each constant is written by its own save(), so a run can land one and fail the
     * next. Callers must not roll their tracking state back to "off" in that case: the
     * constants that did land are live, and a state of "off" hides the dashboard widget
     * and makes the auto-disable cron skip, leaving debug on with nothing to clear it.
     *
     * @var bool
     */
    protected $partialWrite = false;

    private static $configArgs = [
        'normalize' => true,
        'raw'       => true,
        'add'       => true,
    ];

    public function __construct()
    {
        $this->initialize();
    }

    /**
     * Prepares the config file manager.
     *
     * Deliberately never throws. Callers construct this class without a try/catch, so
     * anything escaping here would be an uncaught fatal that takes the admin page down.
     * Any problem is recorded instead and every public method degrades gracefully.
     *
     * @return void
     */
    private function initialize()
    {
        self::$configfilePath = $this->getConfigFilePath();

        // $configArgs is static, so clear any anchor a previous instance left behind.
        // A stale anchor from a differently-shaped config file silently suppresses
        // writes to this one.
        unset(self::$configArgs['anchor'], self::$configArgs['placement']);
        $this->anchorMissing = false;

        if (!is_string(self::$configfilePath) || '' === self::$configfilePath || !file_exists(self::$configfilePath)) {
            $this->setConfigError('wp-config.php could not be located.');
            return;
        }

        // Set anchor for the constants to write
        $configContents = @file_get_contents(self::$configfilePath);
        if (!is_string($configContents)) {
            $this->setConfigError('wp-config.php could not be read.');
            return;
        }

        if (false === strpos($configContents, "/* That's all, stop editing!")) {
            preg_match('@\$table_prefix = (.*);@', $configContents, $matches);
            $anchor = $matches[0] ?? '';

            // With no anchor, add() cannot position a new constant and silently changes
            // nothing. Existing constants still update fine, so record it for the
            // failure message rather than refusing to work at all.
            $this->anchorMissing = ('' === $anchor);

            self::$configArgs['anchor'] = $anchor;
            self::$configArgs['placement'] = 'after';
        }

        if (!is_writable(self::$configfilePath)) {
            $this->setConfigError('Config file not writable');
            return;
        }

        try {
            $this->configFileManager = new WPConfigTransformerMetaSync(self::$configfilePath);
        } catch (\Throwable $e) {
            $this->configFileManager = null;
            $this->setConfigError($e->getMessage());
        }
    }

    /**
     * Records why wp-config.php cannot be updated and surfaces it in the admin.
     *
     * @param string $message Reason to display.
     *
     * @return void
     */
    protected function setConfigError($message)
    {
        $this->configError = (string) $message;

        if (!function_exists('add_action')) {
            return;
        }

        $notice = $this->configError;
        add_action('admin_notices', function () use ($notice) {
            printf(
                '<div class="%1$s"><p>%2$s</p></div>',
                esc_attr('notice notice-error is-dismissible'),
                esc_html($notice)
            );
        });
    }

    /**
     * Whether wp-config.php can be updated.
     *
     * @return bool
     */
    public function isReady()
    {
        return $this->configFileManager instanceof WPConfigTransformerMetaSync;
    }

    /**
     * Reason wp-config.php cannot be updated, empty string when it can.
     *
     * @return string
     */
    public function getConfigError()
    {
        return $this->configError;
    }

    /**
     * Writes the debug constants into wp-config.php.
     *
     * @return bool True when the constants were written, false when they could not be.
     */
    public function store()
    {
        $this->partialWrite = false;

        if (!$this->isReady()) {
            error_log('MetaSync: skipped wp-config.php debug constants - '
                . ($this->configError !== '' ? $this->configError : 'the file is not writable.'));
            return false;
        }

        $contentsBefore = @file_get_contents(self::$configfilePath);

        try {
            // Whitelist of allowed constants to prevent arbitrary constant modification
            $allowedConstants = ['WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY'];

            $updatedConstants = [];
            $unwritten = [];
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

                // update() can no-op silently - most notably when no anchor was found, so
                // a new constant has nowhere to be inserted - so confirm the file really
                // holds the wanted value rather than assuming the call worked.
                if (!$this->constantReflectsState($key, $value)) {
                    $unwritten[] = $key;
                }
            }

            if (!empty($unwritten)) {
                // Each constant has its own save(), so an earlier one may already be on
                // disk. Record that so the caller keeps its state consistent with the file
                // instead of reporting "off" over live constants.
                $contentsAfter = @file_get_contents(self::$configfilePath);
                $this->partialWrite = ($contentsBefore !== $contentsAfter);

                // Bytes only answer "did we change the file". The question that matters is
                // "is debug live in it": a constant already at the enabling value makes
                // update() a no-op, so nothing changes on disk yet debug is still on. The
                // caller must not report "off" over that either.
                if (!$this->partialWrite) {
                    foreach ($constants as $liveCheck) {
                        $liveKey = strtoupper(sanitize_key($liveCheck['name']));
                        if ($this->constantReflectsState($liveKey, 'true')) {
                            $this->partialWrite = true;
                            break;
                        }
                    }
                }

                $message = 'wp-config.php was not updated for: ' . implode(', ', $unwritten) . '.';
                if ($this->anchorMissing) {
                    $message .= ' No place to insert new constants was found in wp-config.php.';
                }
                if ($this->partialWrite) {
                    $message .= ' Debug constants are still live in wp-config.php, so it is'
                        . ' partially updated.';
                }

                error_log('MetaSync: ' . $message);
                $this->setConfigError($message);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            // Throwable rather than Exception: an Error here would otherwise be fatal.
            // Both callers are ordinary form/REST requests, so report the failure back
            // to them instead of emitting JSON and dying part-way through the page.
            $contentsAfter = @file_get_contents(self::$configfilePath);
            $this->partialWrite = ($contentsBefore !== $contentsAfter);

            // A failed update may happen after an earlier constant was already live, or
            // after update() no-oped because a constant was already enabled. Bytes alone
            // cannot distinguish those cases, so keep the caller's state aligned with the
            // constants that are actually active in wp-config.php.
            if (!$this->partialWrite) {
                foreach (['WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY'] as $liveKey) {
                    if ($this->constantReflectsState($liveKey, 'true')) {
                        $this->partialWrite = true;
                        break;
                    }
                }
            }

            error_log('MetaSync: Error updating wp-config.php - ' . $e->getMessage());
            $this->setConfigError('Could not update wp-config.php: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Whether the last store() left wp-config.php partially updated.
     *
     * @return bool
     */
    public function hadPartialWrite()
    {
        return $this->partialWrite;
    }

    /**
     * Whether wp-config.php now reflects the wanted state for a constant.
     *
     * Re-reads the file so the result is what was actually persisted rather than what the
     * writer was asked to do. Note the writer expresses "off" by removing the constant
     * rather than defining it false, so an absent constant satisfies a 'false' target.
     *
     * @param string $name     Constant name.
     * @param string $expected Expected raw value, 'true' or 'false'.
     *
     * @return bool
     */
    protected function constantReflectsState($name, $expected)
    {
        if (!$this->isReady()) {
            return false;
        }

        $wantEnabled = ('true' === strtolower(trim($expected)));

        try {
            if (!$this->configFileManager->exists('constant', $name)) {
                return !$wantEnabled;
            }

            $actual = $this->configFileManager->getValue('constant', $name);
        } catch (\Throwable $e) {
            return false;
        }

        if (!is_scalar($actual)) {
            return false;
        }

        return strtolower(trim((string) $actual)) === strtolower(trim($expected));
    }

    /**
     * Whether a constant is defined in wp-config.php.
     *
     * @param string $constant Constant name.
     *
     * @return bool False when wp-config.php cannot be read.
     */
    public function exists($constant)
    {
        if (!$this->isReady()) {
            return false;
        }

        try {
            return $this->configFileManager->exists('constant', strtoupper($constant));
        } catch (\Throwable $e) {
            error_log('MetaSync: Error reading wp-config.php - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Reads a constant's value from wp-config.php.
     *
     * @param string $constant Constant name.
     *
     * @return mixed|null Null when absent or wp-config.php cannot be read.
     */
    public function getValue($constant)
    {
        if (!$this->isReady()) {
            return null;
        }

        try {
            if ($this->configFileManager->exists('constant', strtoupper($constant))) {
                return $this->configFileManager->getValue('constant', strtoupper($constant));
            }
        } catch (\Throwable $e) {
            error_log('MetaSync: Error reading wp-config.php - ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Updates a single constant in wp-config.php.
     *
     * @param string $key   Constant name.
     * @param mixed  $value Constant value.
     *
     * @return bool False when the constant could not be written.
     */
    public function update($key, $value)
    {
        if (!$this->isReady()) {
            return false;
        }

        try {
            // By default, when attempting to update a config that doesn't exist, one will be added.
            $option = self::$configArgs;
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            return $this->configFileManager->update('constant', strtoupper($key), $value, $option);
        } catch (\Throwable $e) {
            error_log('MetaSync: Error updating wp-config.php - ' . $e->getMessage());
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
        if (!$this->isReady()) {
            return;
        }

        $deletedConstant = array_diff(array_column($constants, 'name'), array_column($constants, 'name'));

        foreach ($deletedConstant as $item) {
            $this->configFileManager->remove('constant', strtoupper($item));
        }
    }
}
