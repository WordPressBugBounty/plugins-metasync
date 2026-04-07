<?php
/**
 * Metasync_CSS_Minifier
 * Regex-based CSS minification engine.
 * Hooks into style_loader_tag to rewrite enqueued stylesheets to cached minified versions.
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.5.23
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_CSS_Minifier {

    /** @var array */
    private $settings;

    /** @var array Handles to exclude from minification. */
    private $excluded_handles = [];

    public function __construct(array $settings) {
        $this->settings = $settings;
        $this->excluded_handles = array_filter(
            array_map('trim', explode(',', $settings['css_exclude_handles'] ?? ''))
        );

        add_filter('style_loader_tag', [$this, 'maybe_minify_style'], 999, 4);
    }

    /**
     * Filter: attempt to minify the stylesheet and rewrite the tag to use the cached version.
     *
     * @param string $tag    The full <link> tag HTML.
     * @param string $handle The style's registered handle.
     * @param string $href   The stylesheet's source URL.
     * @param string $media  The stylesheet's media attribute.
     * @return string Modified or original tag.
     */
    public function maybe_minify_style(string $tag, string $handle, string $href, string $media): string {
        // Skip admin, login, excluded handles
        if (is_admin() || $this->is_excluded($handle) || $this->is_login_page()) {
            return $tag;
        }

        // Only process local files
        $local_path = $this->url_to_local_path($href);
        if (!$local_path || !file_exists($local_path)) {
            return $tag;
        }

        // Skip already-minified files
        if (preg_match('/\.min\.css$/i', $local_path)) {
            return $tag;
        }

        // Check cache
        $cached = Metasync_Minification_Cache::get_cached_file($handle, 'css', $local_path);
        if ($cached) {
            return str_replace($href, $cached['url'], $tag);
        }

        // Read and minify
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $content = file_get_contents($local_path);
        if ($content === false || strlen($content) === 0) {
            return $tag;
        }

        $minified = self::minify($content);

        // Store in cache
        $cached = Metasync_Minification_Cache::store_cached_file($handle, 'css', $local_path, $minified);
        if ($cached) {
            return str_replace($href, $cached['url'], $tag);
        }

        return $tag;
    }

    /**
     * Minify a CSS string using regex-based transformations.
     *
     * @param string $css Raw CSS content.
     * @return string Minified CSS.
     */
    public static function minify(string $css): string {
        // 1. Remove CSS comments
        $css = preg_replace('/\/\*[\s\S]*?\*\//', '', $css);

        // 2. Collapse whitespace
        $css = preg_replace('/\s+/', ' ', $css);

        // 3. Remove spaces around { } : ; ,
        $css = preg_replace('/\s*\{\s*/', '{', $css);
        $css = preg_replace('/\s*\}\s*/', '}', $css);
        $css = preg_replace('/\s*:\s*/', ':', $css);
        $css = preg_replace('/\s*;\s*/', ';', $css);
        $css = preg_replace('/\s*,\s*/', ',', $css);

        // 4. Remove trailing semicolons before }
        $css = str_replace(';}', '}', $css);

        // 5. Remove units after zero (0px -> 0), but not 0% (used in keyframes/gradients)
        $css = preg_replace('/(?<=[\s:,])0(?:px|em|rem|ex|ch|vw|vh|vmin|vmax|cm|mm|in|pt|pc)\b/', '0', $css);

        return trim($css);
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
     * @param string $url The stylesheet URL.
     * @return string|false Local path or false if not local.
     */
    private function url_to_local_path(string $url) {
        // Remove query string
        $url = strtok($url, '?');

        // Handle protocol-relative URLs
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }

        $site_url = site_url();
        $content_url = content_url();
        $abspath = ABSPATH;

        // Check if the URL is local
        if (strpos($url, $site_url) === 0) {
            $relative = str_replace($site_url, '', $url);
            return $abspath . ltrim($relative, '/');
        }

        if (strpos($url, $content_url) === 0) {
            $relative = str_replace($content_url, '', $url);
            return WP_CONTENT_DIR . '/' . ltrim($relative, '/');
        }

        // Check for relative URLs
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
