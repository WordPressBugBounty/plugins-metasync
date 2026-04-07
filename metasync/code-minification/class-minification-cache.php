<?php
/**
 * Metasync_Minification_Cache
 * Manages file-based cache for minified CSS/JS assets.
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.5.23
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Minification_Cache {

    const CACHE_DIR_NAME = 'metasync-speed';
    const CRON_HOOK      = 'metasync_speed_cache_cleanup';

    /**
     * Get the base cache directory path.
     */
    public static function get_cache_dir(): string {
        return WP_CONTENT_DIR . '/cache/' . self::CACHE_DIR_NAME . '/';
    }

    /**
     * Get the base cache directory URL.
     */
    public static function get_cache_url(): string {
        return content_url('/cache/' . self::CACHE_DIR_NAME . '/');
    }

    /**
     * Compute cache key for a file.
     *
     * @param string $file_path Absolute path to the original file.
     * @return string 8-char hash.
     */
    public static function compute_hash(string $file_path): string {
        $version = defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0';
        $mtime   = file_exists($file_path) ? (string) filemtime($file_path) : '0';
        return substr(md5($file_path . $mtime . $version), 0, 8);
    }

    /**
     * Get the cached file path for a given handle and type.
     *
     * @param string $handle  The enqueue handle.
     * @param string $type    'css' or 'js'.
     * @param string $hash    The content hash.
     * @return string Absolute path to cached file.
     */
    public static function get_cached_path(string $handle, string $type, string $hash): string {
        $safe_handle = sanitize_file_name($handle);
        return self::get_cache_dir() . $type . '/' . $safe_handle . '-' . $hash . '.min.' . $type;
    }

    /**
     * Get the cached file URL for a given handle and type.
     *
     * @param string $handle  The enqueue handle.
     * @param string $type    'css' or 'js'.
     * @param string $hash    The content hash.
     * @return string URL to the cached file.
     */
    public static function get_cached_url(string $handle, string $type, string $hash): string {
        $safe_handle = sanitize_file_name($handle);
        return self::get_cache_url() . $type . '/' . $safe_handle . '-' . $hash . '.min.' . $type;
    }

    /**
     * Check if a cached file exists and return its path, or false.
     *
     * @param string $handle    The enqueue handle.
     * @param string $type      'css' or 'js'.
     * @param string $file_path Original file path (for hash computation).
     * @return array|false ['path' => ..., 'url' => ..., 'hash' => ...] or false.
     */
    public static function get_cached_file(string $handle, string $type, string $file_path) {
        $hash = self::compute_hash($file_path);
        $cached_path = self::get_cached_path($handle, $type, $hash);

        if (file_exists($cached_path)) {
            return [
                'path' => $cached_path,
                'url'  => self::get_cached_url($handle, $type, $hash),
                'hash' => $hash,
            ];
        }

        return false;
    }

    /**
     * Store a minified file in cache.
     *
     * @param string $handle    The enqueue handle.
     * @param string $type      'css' or 'js'.
     * @param string $file_path Original file path (for hash computation).
     * @param string $content   Minified content to store.
     * @return array|false ['path' => ..., 'url' => ..., 'hash' => ...] or false on failure.
     */
    public static function store_cached_file(string $handle, string $type, string $file_path, string $content) {
        $hash = self::compute_hash($file_path);
        $cached_path = self::get_cached_path($handle, $type, $hash);
        $dir = dirname($cached_path);

        if (!wp_mkdir_p($dir)) {
            return false;
        }

        // Write .htaccess to protect the cache directory (deny PHP execution, allow static assets).
        $base_dir = self::get_cache_dir();
        $htaccess = $base_dir . '.htaccess';
        if (!file_exists($htaccess)) {
            $rules  = "Options -Indexes\n";
            $rules .= "<FilesMatch \"\.php$\">\n";
            $rules .= "  deny from all\n";
            $rules .= "</FilesMatch>\n";
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents($htaccess, $rules);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if (file_put_contents($cached_path, $content) === false) {
            return false;
        }

        return [
            'path' => $cached_path,
            'url'  => self::get_cached_url($handle, $type, $hash),
            'hash' => $hash,
        ];
    }

    /**
     * Purge all cached minification files.
     */
    public static function purge_all(): bool {
        $cache_dir = self::get_cache_dir();

        if (!is_dir($cache_dir)) {
            return true;
        }

        return self::delete_directory_contents($cache_dir);
    }

    /**
     * Purge expired cached files (older than TTL days).
     *
     * @param int $ttl_days Number of days after which files are considered expired.
     */
    public static function purge_expired(int $ttl_days = 30): int {
        $cache_dir = self::get_cache_dir();
        $deleted = 0;

        if (!is_dir($cache_dir)) {
            return $deleted;
        }

        $expiry_time = time() - ($ttl_days * DAY_IN_SECONDS);

        foreach (['css', 'js'] as $type) {
            $type_dir = $cache_dir . $type . '/';
            if (!is_dir($type_dir)) {
                continue;
            }

            $files = glob($type_dir . '*.min.' . $type);
            if (!$files) {
                continue;
            }

            foreach ($files as $file) {
                if (filemtime($file) < $expiry_time) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    if (unlink($file)) {
                        $deleted++;
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * Get cache statistics.
     *
     * @return array ['total_files' => int, 'total_size' => int, 'css_files' => int, 'js_files' => int]
     */
    public static function get_cache_stats(): array {
        $stats = [
            'total_files' => 0,
            'total_size'  => 0,
            'css_files'   => 0,
            'js_files'    => 0,
            'css_size'    => 0,
            'js_size'     => 0,
        ];

        $cache_dir = self::get_cache_dir();

        if (!is_dir($cache_dir)) {
            return $stats;
        }

        foreach (['css', 'js'] as $type) {
            $type_dir = $cache_dir . $type . '/';
            if (!is_dir($type_dir)) {
                continue;
            }

            $files = glob($type_dir . '*.min.' . $type);
            if (!$files) {
                continue;
            }

            foreach ($files as $file) {
                $size = filesize($file);
                $stats['total_files']++;
                $stats['total_size'] += $size;
                $stats[$type . '_files']++;
                $stats[$type . '_size'] += $size;
            }
        }

        return $stats;
    }

    /**
     * Schedule the daily cache cleanup cron job.
     */
    public static function schedule_cleanup(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Unschedule the daily cache cleanup cron job.
     */
    public static function unschedule_cleanup(): void {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Cron callback: purge expired files.
     */
    public static function cron_cleanup(): void {
        $settings = Metasync_Minification_Settings::get_settings();
        $ttl = (int) ($settings['cache_ttl_days'] ?? 30);
        self::purge_expired($ttl);
    }

    /**
     * Recursively delete contents of a directory (but keep the directory itself).
     */
    private static function delete_directory_contents(string $dir): bool {
        if (!is_dir($dir)) {
            return false;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
                rmdir($item->getRealPath());
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink($item->getRealPath());
            }
        }

        return true;
    }
}
