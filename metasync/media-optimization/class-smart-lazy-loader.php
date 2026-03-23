<?php
/**
 * Metasync_Smart_Lazy_Loader
 * Adds loading="lazy" to images and iframes while protecting LCP elements.
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Smart_Lazy_Loader {

    private array $settings;
    private int $img_counter = 0;

    public function __construct(array $settings) {
        $this->settings = $settings;

        // Tell WordPress to skip lazy loading for the first N images (WP 5.9+)
        $skip = (int) ($settings['lcp_skip_count'] ?? 2);
        add_filter('wp_omit_loading_attr_threshold', fn() => $skip);

        // For broader control (including non-WP images and iframes), filter content
        add_filter('the_content', [$this, 'process_content'], 25);
        add_filter('post_thumbnail_html', [$this, 'process_content'], 25);
        add_filter('widget_text', [$this, 'process_content'], 25);
    }

    /**
     * Process HTML content to add/manage lazy loading attributes.
     */
    public function process_content(string $content): string {
        if (empty($content) || is_admin() || is_feed()) {
            return $content;
        }

        $content = $this->process_images($content);

        if (!empty($this->settings['lazy_load_iframes'])) {
            $content = $this->process_iframes($content);
        }

        return $content;
    }

    /**
     * Add loading="lazy" to <img> tags, skipping the first N for LCP.
     */
    private function process_images(string $content): string {
        $skip            = (int) ($this->settings['lcp_skip_count'] ?? 2);
        $exclude_classes = array_filter(array_map('trim', explode(',', $this->settings['exclude_classes'] ?? '')));

        return preg_replace_callback('/<img\s[^>]+>/i', function ($matches) use ($skip, $exclude_classes) {
            $tag = $matches[0];
            $this->img_counter++;

            // Already has a loading attribute - don't touch it
            if (preg_match('/\sloading\s*=/i', $tag)) {
                return $tag;
            }

            // Check excluded classes
            if (!empty($exclude_classes) && preg_match('/class=["\']([^"\']+)["\']/i', $tag, $class_match)) {
                $classes = explode(' ', $class_match[1]);
                foreach ($exclude_classes as $excluded) {
                    if (in_array($excluded, $classes, true)) {
                        return $tag;
                    }
                }
            }

            // Skip first N images to protect LCP
            if ($this->img_counter <= $skip) {
                return $this->inject_attribute($tag, 'loading', 'eager');
            }

            // Add loading="lazy" and decoding="async" for below-fold images
            $tag = $this->inject_attribute($tag, 'loading', 'lazy');
            $tag = $this->inject_attribute($tag, 'decoding', 'async');

            return $tag;
        }, $content);
    }

    /**
     * Add loading="lazy" to <iframe> tags.
     */
    private function process_iframes(string $content): string {
        return preg_replace_callback('/<iframe\s[^>]*>/i', function ($matches) {
            $tag = $matches[0];

            if (preg_match('/\sloading\s*=/i', $tag)) {
                return $tag;
            }

            return $this->inject_attribute($tag, 'loading', 'lazy');
        }, $content);
    }

    /**
     * Inject an HTML attribute into a tag if not already present.
     */
    private function inject_attribute(string $tag, string $attr, string $value): string {
        if (preg_match('/\s' . preg_quote($attr, '/') . '\s*=/i', $tag)) {
            return $tag;
        }

        return preg_replace('/\s*\/?>$/', sprintf(' %s="%s"$0', esc_attr($attr), esc_attr($value)), $tag, 1);
    }
}
