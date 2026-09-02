<?php
/**
 * Helpers for the taxonomy selector on the XML sitemap settings page.
 *
 * @package Metasync
 */

if (!function_exists('metasync_get_sitemap_taxonomy_picker_taxonomies')) {
    /**
     * Filter registered taxonomies to the ones suitable for the sitemap picker.
     *
     * @param array $taxonomies Public taxonomy objects returned by WordPress.
     * @return array Taxonomy objects that can be selected in sitemap settings.
     */
    function metasync_get_sitemap_taxonomy_picker_taxonomies($taxonomies) {
        $excluded_taxonomies = [
            'product_shipping_class',
            'link_category',
            'nav_menu',
            'wp_pattern_category',
            'template_tag',
            'template_bundle',
        ];

        if (!current_theme_supports('post-formats')) {
            $excluded_taxonomies[] = 'post_format';
        }

        $filtered_taxonomies = [];
        foreach ((array) $taxonomies as $taxonomy) {
            if (!is_object($taxonomy) || empty($taxonomy->name)) {
                continue;
            }

            if (in_array($taxonomy->name, $excluded_taxonomies, true)) {
                continue;
            }

            if (!is_taxonomy_viewable($taxonomy)) {
                continue;
            }

            $filtered_taxonomies[] = $taxonomy;
        }

        /**
         * Allow integrations to remove additional taxonomies from the picker.
         *
         * @param array $filtered_taxonomies Taxonomies that passed the defaults.
         * @param array $taxonomies          Original public taxonomy objects.
         */
        return apply_filters(
            'metasync_sitemap_taxonomy_picker_taxonomies',
            $filtered_taxonomies,
            $taxonomies
        );
    }
}
