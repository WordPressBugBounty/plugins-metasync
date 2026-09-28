<?php

/**
 * Central owner of the plugin's on-disk data directory.
 *
 * All persistent files the plugin writes outside the database (error logs,
 * rotated log copies, zipped log exports) live under
 * `wp-content/metasync_data/`, created and protected here so every writer
 * agrees on the location, its permissions, and its web-server hardening.
 *
 * Why wp-content and not the plugin folder or uploads:
 *  - The plugin folder (`wp-content/plugins/metasync/…`) is replaced on every
 *    plugin update, so anything stored there is destroyed by an upgrade.
 *    This directory is a sibling of `plugins`, not inside it, and therefore
 *    survives updates — Plugin Check flags any write it can trace to a
 *    plugin-directory path, and every traceable write was rerouted here.
 *  - `wp-content/uploads/` is served to the web by URL. Error logs contain
 *    paths, hostnames, and configuration fragments, so they must NOT be
 *    web-downloadable; this directory ships an .htaccess deny plus a PHP
 *    index killer and stays outside the uploads tree.
 *
 * The physical path is intentionally resolved only inside this class. Callers
 * receive it from these accessors instead of concatenating WP_CONTENT_DIR
 * themselves, which is what keeps the "where does data live and why" decision
 * auditable in one place.
 *
 * @link       https://searchatlas.com
 * @since      2.7.1
 * @package    Metasync
 * @subpackage Metasync/includes
 * @author     Engineering Team <support@searchatlas.com>
 */

# Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Data_Store
{
    /**
     * Directory name under wp-content that holds plugin data files.
     */
    const DIR_NAME = 'metasync_data';

    /**
     * Physical directory path, without any creation or protection guarantees.
     *
     * The one place the raw WP_CONTENT_DIR concatenation lives. Every path
     * expression that feeds a write resolves through this accessor (or
     * base_dir()/file_path()) rather than a local variable, so static
     * analysis — and human reviewers — always land here for the location
     * decision.
     *
     * @return string Absolute directory path (may not exist yet).
     */
    private static function raw_dir()
    {
        return WP_CONTENT_DIR . '/' . self::DIR_NAME;
    }

    /**
     * Resolve (and if needed create and harden) the plugin data directory.
     *
     * Ensures the directory exists (wp_mkdir_p, 0755 on standard setups),
     * drops an Apache deny-all .htaccess and a PHP index.php in place when
     * missing, and verifies the directory is writable (retrying once via
     * chmod), matching the guarantees the individual writers used to
     * re-implement per file.
     *
     * @return string|false Absolute normalized directory path, or false when
     *                      the directory cannot be created or written to.
     */
    public static function base_dir()
    {
        $dir = self::raw_dir();

        if (!wp_mkdir_p($dir)) {
            error_log('Metasync_Data_Store: Failed to create data directory: ' . $dir); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- plugin logger sink / last-resort fallback
            return false;
        }

        // Web-server hardening for the directory contents: both are fixed
        // marker names written only on first creation, never caller input.
        if (!file_exists(self::raw_dir() . '/.htaccess')) {
            @file_put_contents(self::raw_dir() . '/.htaccess', "Order deny,allow\nDeny from all\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-shot fixed-content marker write; WP_Filesystem would prompt for FTP credentials on non-direct hosts
        }
        if (!file_exists(self::raw_dir() . '/index.php')) {
            @file_put_contents(self::raw_dir() . '/index.php', "<?php\n// Silence is golden\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-shot fixed-content marker write; WP_Filesystem would prompt for FTP credentials on non-direct hosts
        }

        if (!wp_is_writable($dir)) {
            @chmod($dir, 0755); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- direct POSIX permission change; WP_Filesystem would prompt for FTP credentials on non-direct hosts
        }

        // Re-probe as a separate statement: the chmod above may have just
        // changed the answer, so this is a genuinely second observation.
        if (!wp_is_writable($dir)) {
            error_log('Metasync_Data_Store: Directory not writable: ' . $dir); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- plugin logger sink / last-resort fallback
            return false;
        }

        return wp_normalize_path($dir);
    }

    /**
     * Resolve a file inside the plugin data directory.
     *
     * The filename is passed through sanitize_file_name() plus basename() so
     * no caller can smuggle traversal segments or platform separators into
     * the final path; only its sanitized basename is ever appended to the
     * directory.
     *
     * @param string $filename File name relative to the data directory.
     * @return string|false Absolute normalized file path, or false when the
     *                      directory is unavailable or the name is unusable.
     */
    public static function file_path($filename)
    {
        $safe_name = basename(sanitize_file_name((string) $filename));
        if ($safe_name === '' || $safe_name === '.') {
            return false;
        }

        $dir = self::base_dir();
        if (false === $dir) {
            return false;
        }

        return wp_normalize_path($dir . '/' . $safe_name);
    }
}
