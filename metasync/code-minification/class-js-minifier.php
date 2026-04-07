<?php
/**
 * Metasync_JS_Minifier
 * Conservative regex-based JS minification engine (no AST parsing).
 * Hooks into script_loader_tag to rewrite enqueued scripts to cached minified versions.
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.5.23
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_JS_Minifier {

    /** @var array */
    private $settings;

    /** @var array Handles to exclude from minification. */
    private $excluded_handles = [];

    public function __construct(array $settings) {
        $this->settings = $settings;
        $this->excluded_handles = array_filter(
            array_map('trim', explode(',', $settings['js_exclude_handles'] ?? ''))
        );

        add_filter('script_loader_tag', [$this, 'maybe_minify_script'], 998, 3);
    }

    /**
     * Filter: attempt to minify the script and rewrite the tag to use the cached version.
     *
     * @param string $tag    The full <script> tag HTML.
     * @param string $handle The script's registered handle.
     * @param string $src    The script's source URL.
     * @return string Modified or original tag.
     */
    public function maybe_minify_script(string $tag, string $handle, string $src): string {
        // Skip admin, login, excluded handles, inline scripts
        if (is_admin() || $this->is_excluded($handle) || $this->is_login_page() || empty($src)) {
            return $tag;
        }

        // Only process local files
        $local_path = $this->url_to_local_path($src);
        if (!$local_path || !file_exists($local_path)) {
            return $tag;
        }

        // Skip already-minified files
        if (preg_match('/\.min\.js$/i', $local_path)) {
            return $tag;
        }

        // Skip ES module files — relocating them breaks relative import paths
        if ($this->is_es_module($tag, $local_path)) {
            return $tag;
        }

        // Check cache
        $cached = Metasync_Minification_Cache::get_cached_file($handle, 'js', $local_path);
        if ($cached) {
            return str_replace($src, $cached['url'], $tag);
        }

        // Read and minify
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $content = file_get_contents($local_path);
        if ($content === false || strlen($content) === 0) {
            return $tag;
        }

        $minified = self::minify($content);

        // Store in cache
        $cached = Metasync_Minification_Cache::store_cached_file($handle, 'js', $local_path, $minified);
        if ($cached) {
            return str_replace($src, $cached['url'], $tag);
        }

        return $tag;
    }

    /**
     * Minify a JavaScript string using conservative regex-based transformations.
     *
     * @param string $js Raw JavaScript content.
     * @return string Minified JavaScript.
     */
    public static function minify(string $js): string {
        // 1. Remove multi-line comments (but preserve conditional compilation comments /*! ... */)
        $js = preg_replace('/\/\*(?!![\s\S]*?\*\/)[\s\S]*?\*\//', '', $js);

        // 2. Remove single-line comments (careful with URLs and regex literals)
        // Only remove // comments that are not inside strings or URLs
        $js = preg_replace('/(?<![:\"\'\\\])\/\/[^\n]*/', '', $js);

        // 3. Remove trailing whitespace per line
        $js = preg_replace('/[ \t]+$/m', '', $js);

        // 4. Collapse multiple blank lines into one
        $js = preg_replace('/\n{3,}/', "\n\n", $js);

        // 5. Remove leading whitespace on lines (conservative: only spaces/tabs at start of line)
        $js = preg_replace('/^\s+/m', '', $js);

        // 6. Collapse multiple spaces into one (within lines)
        $js = preg_replace('/[ \t]+/', ' ', $js);

        // 7. Remove spaces around operators (conservative set only)
        $js = preg_replace('/\s*([{};,])\s*/', '$1', $js);

        // 8. Remove empty lines
        $js = preg_replace('/^\s*$/m', '', $js);
        $js = preg_replace('/\n+/', "\n", $js);

        return trim($js);
    }

    /**
     * Check if a script is an ES module (uses import/export with relative paths).
     * Relocating ES modules to the cache directory breaks their relative imports.
     */
    private function is_es_module(string $tag, string $local_path): bool {
        // Check if the script tag has type="module"
        if (preg_match('/type\s*=\s*["\']module["\']/', $tag)) {
            return true;
        }

        // Read beginning of file to check for ES module import/export syntax
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $head = file_get_contents($local_path, false, null, 0, 2048);
        if ($head === false) {
            return false;
        }

        // Detect relative import paths like: from"./js/..." or from'../something'
        if (preg_match('/\bimport\b.+?\bfrom\s*["\']\.\.?\//', $head)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a handle is excluded.
     */
    private function is_excluded(string $handle): bool {
        return in_array($handle, $this->excluded_handles, true);
    }

    /**
     * Convert a URL to a local filesystem path.
     *
     * @param string $url The script URL.
     * @return string|false Local path or false if not local.
     */
    private function url_to_local_path(string $url) {
        $url = strtok($url, '?');

        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }

        $site_url = site_url();
        $content_url = content_url();
        $abspath = ABSPATH;

        if (strpos($url, $site_url) === 0) {
            $relative = str_replace($site_url, '', $url);
            return $abspath . ltrim($relative, '/');
        }

        if (strpos($url, $content_url) === 0) {
            $relative = str_replace($content_url, '', $url);
            return WP_CONTENT_DIR . '/' . ltrim($relative, '/');
        }

        if (strpos($url, '/wp-content/') === 0 || strpos($url, '/wp-includes/') === 0) {
            return $abspath . ltrim($url, '/');
        }

        return false;
    }

    /**
     * Check if current request is the login page.
     */
    private function is_login_page(): bool {
        return in_array($GLOBALS['pagenow'] ?? '', ['wp-login.php', 'wp-register.php'], true);
    }
}
