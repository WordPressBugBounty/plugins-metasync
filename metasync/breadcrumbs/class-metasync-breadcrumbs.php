<?php
/**
 * MetaSync Breadcrumbs — core breadcrumb path resolver and HTML output.
 *
 * @package    Metasync
 * @subpackage Metasync/breadcrumbs
 * @since      2.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Breadcrumbs {

    /**
     * Plugin name.
     *
     * @var string
     */
    private $plugin_name;

    /**
     * Plugin version.
     *
     * @var string
     */
    private $version;

    /**
     * Breadcrumb settings (cached per request).
     *
     * @var array
     */
    private $settings;

    /**
     * Constructor.
     *
     * @param string $plugin_name Plugin slug.
     * @param string $version     Plugin version.
     */
    public function __construct($plugin_name = 'metasync', $version = '1.0.0') {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;
        $this->settings    = $this->load_settings();

        add_action('init', array($this, 'register_meta_fields'));
        add_action('init', array($this, 'register_shortcode'));
    }

    /**
     * Load breadcrumb settings from the shared option.
     *
     * @return array
     */
    private function load_settings() {
        $defaults = array(
            'enabled'              => true,
            'separator'            => '&raquo;',
            'home_label'           => 'Home',
            'home_url'             => '',
            'show_current_page'    => true,
            'prefix_text'          => '',
            'archive_label_format' => '{name}',
        );

        $saved = Metasync::get_option('breadcrumbs', array());
        if (!is_array($saved)) {
            $saved = array();
        }

        return wp_parse_args($saved, $defaults);
    }

    /**
     * Register post meta fields for breadcrumb title override and primary category.
     */
    public function register_meta_fields() {
        $post_types = get_post_types(array('public' => true), 'names');

        foreach ($post_types as $post_type) {
            register_post_meta($post_type, '_metasync_breadcrumb_title', array(
                'show_in_rest'      => true,
                'single'            => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback'     => function () {
                    return current_user_can('edit_posts');
                },
            ));

            register_post_meta($post_type, '_metasync_primary_category', array(
                'show_in_rest'      => true,
                'single'            => true,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'auth_callback'     => function () {
                    return current_user_can('edit_posts');
                },
            ));
        }
    }

    /**
     * Register the [metasync_breadcrumb] shortcode.
     */
    public function register_shortcode() {
        add_shortcode('metasync_breadcrumb', array($this, 'render_breadcrumb_html'));
    }

    /**
     * Resolve the breadcrumb trail for the current page context.
     *
     * Returns an ordered array of [ 'label' => string, 'url' => string|'' ].
     * The last item may have an empty URL (current page).
     *
     * @param int $post_id Optional. Explicit post ID (used by MCP tool).
     * @return array
     */
    public function resolve_breadcrumb_trail($post_id = 0) {
        $trail = array();

        // Home item.
        $home_url   = !empty($this->settings['home_url']) ? $this->settings['home_url'] : home_url('/');
        $home_label = !empty($this->settings['home_label']) ? $this->settings['home_label'] : 'Home';
        $trail[]    = array('label' => $home_label, 'url' => $home_url);

        // --- Front page ---
        if (is_front_page()) {
            return $trail;
        }

        // --- Explicit post_id (MCP / non-frontend context) ---
        // is_singular() and friends rely on the global query and are always false in
        // admin or REST API requests. When an explicit post_id is supplied outside a
        // frontend request, bypass the query-conditional checks entirely.
        if ($post_id > 0 && !did_action('wp')) {
            $current_post = get_post($post_id);
            if (!$current_post) {
                return $trail;
            }

            if (class_exists('WooCommerce') && $current_post->post_type === 'product') {
                $woo_trail = $this->resolve_woocommerce_trail($post_id);
                if ($woo_trail !== null) {
                    return array_merge($trail, $woo_trail);
                }
            }

            if ($current_post->post_type === 'page') {
                // Build parent chain.
                $ancestors = get_post_ancestors($current_post);
                $ancestors = array_reverse($ancestors);
                foreach ($ancestors as $ancestor_id) {
                    $ancestor = get_post($ancestor_id);
                    if ($ancestor) {
                        $trail[] = array(
                            'label' => get_the_title($ancestor),
                            'url'   => get_permalink($ancestor),
                        );
                    }
                }
            } elseif ($current_post->post_type === 'post') {
                // Category in trail — respect primary category.
                $category = $this->get_primary_category($current_post->ID);
                if ($category) {
                    // Build category ancestor chain.
                    $cat_ancestors = get_ancestors($category->term_id, 'category');
                    $cat_ancestors = array_reverse($cat_ancestors);
                    foreach ($cat_ancestors as $cat_ancestor_id) {
                        $cat_ancestor = get_term($cat_ancestor_id, 'category');
                        if ($cat_ancestor && !is_wp_error($cat_ancestor)) {
                            $trail[] = array(
                                'label' => $this->format_archive_label($cat_ancestor->name),
                                'url'   => get_term_link($cat_ancestor),
                            );
                        }
                    }
                    $trail[] = array(
                        'label' => $this->format_archive_label($category->name),
                        'url'   => get_term_link($category),
                    );
                }
            } else {
                // Custom post type — add post type archive link if available.
                $post_type_obj = get_post_type_object($current_post->post_type);
                if ($post_type_obj && $post_type_obj->has_archive) {
                    $trail[] = array(
                        'label' => $post_type_obj->labels->name,
                        'url'   => get_post_type_archive_link($current_post->post_type),
                    );
                }
            }

            // Final item — the current post.
            $label = $this->get_breadcrumb_title($current_post->ID);
            $trail[] = array('label' => $label, 'url' => '');

            return $trail;
        }

        // --- WooCommerce pages (must be checked before generic singular) ---
        if (class_exists('WooCommerce')) {
            $woo_trail = $this->resolve_woocommerce_trail($post_id);
            if ($woo_trail !== null) {
                return array_merge($trail, $woo_trail);
            }
        }

        // --- Single post / page / CPT ---
        if (is_singular()) {
            $current_post = $post_id ? get_post($post_id) : get_queried_object();
            if (!$current_post) {
                return $trail;
            }

            if (is_page()) {
                // Build parent chain.
                $ancestors = get_post_ancestors($current_post);
                $ancestors = array_reverse($ancestors);
                foreach ($ancestors as $ancestor_id) {
                    $ancestor = get_post($ancestor_id);
                    if ($ancestor) {
                        $trail[] = array(
                            'label' => get_the_title($ancestor),
                            'url'   => get_permalink($ancestor),
                        );
                    }
                }
            } elseif ($current_post->post_type === 'post') {
                // Category in trail — respect primary category.
                $category = $this->get_primary_category($current_post->ID);
                if ($category) {
                    $cat_ancestors = get_ancestors($category->term_id, 'category');
                    $cat_ancestors = array_reverse($cat_ancestors);
                    foreach ($cat_ancestors as $cat_ancestor_id) {
                        $cat_ancestor = get_term($cat_ancestor_id, 'category');
                        if ($cat_ancestor && !is_wp_error($cat_ancestor)) {
                            $trail[] = array(
                                'label' => $this->format_archive_label($cat_ancestor->name),
                                'url'   => get_term_link($cat_ancestor),
                            );
                        }
                    }
                    $trail[] = array(
                        'label' => $this->format_archive_label($category->name),
                        'url'   => get_term_link($category),
                    );
                }
            } else {
                // Custom post type — add post type archive link if available.
                $post_type_obj = get_post_type_object($current_post->post_type);
                if ($post_type_obj && $post_type_obj->has_archive) {
                    $trail[] = array(
                        'label' => $post_type_obj->labels->name,
                        'url'   => get_post_type_archive_link($current_post->post_type),
                    );
                }
            }

            $label   = $this->get_breadcrumb_title($current_post->ID);
            $trail[] = array('label' => $label, 'url' => '');

            return $trail;
        }

        // --- Category archive ---
        if (is_category()) {
            $category = get_queried_object();
            if ($category) {
                $cat_ancestors = get_ancestors($category->term_id, 'category');
                $cat_ancestors = array_reverse($cat_ancestors);
                foreach ($cat_ancestors as $cat_ancestor_id) {
                    $cat_ancestor = get_term($cat_ancestor_id, 'category');
                    if ($cat_ancestor && !is_wp_error($cat_ancestor)) {
                        $trail[] = array(
                            'label' => $this->format_archive_label($cat_ancestor->name),
                            'url'   => get_term_link($cat_ancestor),
                        );
                    }
                }
                $trail[] = array('label' => $this->format_archive_label($category->name), 'url' => '');
            }
            return $trail;
        }

        // --- Tag archive ---
        if (is_tag()) {
            $tag = get_queried_object();
            if ($tag) {
                $trail[] = array('label' => $this->format_archive_label($tag->name), 'url' => '');
            }
            return $trail;
        }

        // --- Author archive ---
        if (is_author()) {
            $author = get_queried_object();
            if ($author) {
                $trail[] = array('label' => $author->display_name, 'url' => '');
            }
            return $trail;
        }

        // --- Date archive ---
        if (is_date()) {
            if (is_year()) {
                $trail[] = array('label' => get_the_date('Y'), 'url' => '');
            } elseif (is_month()) {
                $trail[] = array('label' => get_the_date('Y'), 'url' => get_year_link(get_the_date('Y')));
                $trail[] = array('label' => get_the_date('F'), 'url' => '');
            } elseif (is_day()) {
                $trail[] = array('label' => get_the_date('Y'), 'url' => get_year_link(get_the_date('Y')));
                $trail[] = array('label' => get_the_date('F'), 'url' => get_month_link(get_the_date('Y'), get_the_date('m')));
                $trail[] = array('label' => get_the_date('j'), 'url' => '');
            }
            return $trail;
        }

        // --- Search results ---
        if (is_search()) {
            /* translators: %s: search query */
            $trail[] = array(
                'label' => sprintf(__('Search results for "%s"', 'metasync'), get_search_query()),
                'url'   => '',
            );
            return $trail;
        }

        // --- 404 ---
        if (is_404()) {
            $trail[] = array('label' => __('404 Not Found', 'metasync'), 'url' => '');
            return $trail;
        }

        // --- Post type archive (generic) ---
        if (is_post_type_archive()) {
            $post_type_obj = get_queried_object();
            if ($post_type_obj) {
                $trail[] = array('label' => $post_type_obj->labels->name, 'url' => '');
            }
            return $trail;
        }

        // --- Taxonomy archive (generic) ---
        if (is_tax()) {
            $term = get_queried_object();
            if ($term) {
                $taxonomy_obj = get_taxonomy($term->taxonomy);
                if ($taxonomy_obj) {
                    $trail[] = array('label' => $taxonomy_obj->labels->name, 'url' => '');
                }
                $trail[] = array('label' => $this->format_archive_label($term->name), 'url' => '');
            }
            return $trail;
        }

        return $trail;
    }

    /**
     * Resolve WooCommerce-specific breadcrumb segments.
     *
     * @param int $post_id Optional explicit post ID.
     * @return array|null Null when the current page is not a WooCommerce page.
     */
    private function resolve_woocommerce_trail($post_id = 0) {
        $trail = array();

        // Shop page.
        $shop_page_id = function_exists('wc_get_page_id') ? wc_get_page_id('shop') : 0;
        $shop_label   = $shop_page_id ? get_the_title($shop_page_id) : __('Shop', 'metasync');
        $shop_url     = $shop_page_id ? get_permalink($shop_page_id) : '';

        // WooCommerce product category archive.
        if (is_tax('product_cat')) {
            $trail[] = array('label' => $shop_label, 'url' => $shop_url);
            $term = get_queried_object();
            if ($term) {
                $cat_ancestors = get_ancestors($term->term_id, 'product_cat');
                $cat_ancestors = array_reverse($cat_ancestors);
                foreach ($cat_ancestors as $anc_id) {
                    $anc = get_term($anc_id, 'product_cat');
                    if ($anc && !is_wp_error($anc)) {
                        $trail[] = array('label' => $this->format_archive_label($anc->name), 'url' => get_term_link($anc));
                    }
                }
                $trail[] = array('label' => $this->format_archive_label($term->name), 'url' => '');
            }
            return $trail;
        }

        // WooCommerce shop page.
        if (is_shop()) {
            $trail[] = array('label' => $shop_label, 'url' => '');
            return $trail;
        }

        // Single product.
        if (is_singular('product')) {
            $trail[] = array('label' => $shop_label, 'url' => $shop_url);

            $product_post = $post_id ? get_post($post_id) : get_queried_object();
            if ($product_post) {
                $terms = get_the_terms($product_post->ID, 'product_cat');
                if ($terms && !is_wp_error($terms)) {
                    // Use first product category (or primary if set).
                    $primary_cat_id = get_post_meta($product_post->ID, '_metasync_primary_category', true);
                    $category = null;
                    if ($primary_cat_id) {
                        foreach ($terms as $t) {
                            if ((int) $t->term_id === (int) $primary_cat_id) {
                                $category = $t;
                                break;
                            }
                        }
                    }
                    if (!$category) {
                        $category = $terms[0];
                    }

                    $cat_ancestors = get_ancestors($category->term_id, 'product_cat');
                    $cat_ancestors = array_reverse($cat_ancestors);
                    foreach ($cat_ancestors as $anc_id) {
                        $anc = get_term($anc_id, 'product_cat');
                        if ($anc && !is_wp_error($anc)) {
                            $trail[] = array('label' => $this->format_archive_label($anc->name), 'url' => get_term_link($anc));
                        }
                    }
                    $trail[] = array('label' => $this->format_archive_label($category->name), 'url' => get_term_link($category));
                }

                $label   = $this->get_breadcrumb_title($product_post->ID);
                $trail[] = array('label' => $label, 'url' => '');
            }
            return $trail;
        }

        // Not a WooCommerce page.
        return null;
    }

    /**
     * Get the breadcrumb title for a post, with override support.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    private function get_breadcrumb_title($post_id) {
        $override = get_post_meta($post_id, '_metasync_breadcrumb_title', true);
        if (!empty($override)) {
            return $override;
        }
        return get_the_title($post_id);
    }

    /**
     * Get the primary category for a post.
     *
     * Falls back to the first assigned category.
     *
     * @param int $post_id Post ID.
     * @return WP_Term|null
     */
    private function get_primary_category($post_id) {
        $primary_cat_id = get_post_meta($post_id, '_metasync_primary_category', true);

        if ($primary_cat_id) {
            $term = get_term((int) $primary_cat_id, 'category');
            if ($term && !is_wp_error($term)) {
                return $term;
            }
        }

        $categories = get_the_category($post_id);
        if (!empty($categories)) {
            return $categories[0];
        }

        return null;
    }

    /**
     * Format an archive label using the configured format.
     *
     * @param string $name Term/archive name.
     * @return string
     */
    private function format_archive_label($name) {
        $format = $this->settings['archive_label_format'];
        if (empty($format) || $format === '{name}') {
            return $name;
        }
        return str_replace('{name}', $name, $format);
    }

    /**
     * Render breadcrumb HTML.
     *
     * Can be called as a shortcode callback or directly.
     *
     * @param array $args Shortcode / function arguments (unused reserved).
     * @return string HTML output.
     */
    public function render_breadcrumb_html($args = array()) {
        if (empty($this->settings['enabled'])) {
            return '';
        }

        $trail = $this->resolve_breadcrumb_trail();
        if (empty($trail)) {
            return '';
        }

        $separator   = $this->settings['separator'];
        $prefix_text = $this->settings['prefix_text'];
        $show_current = $this->settings['show_current_page'];

        $items_html = array();
        $count      = count($trail);

        foreach ($trail as $index => $item) {
            $is_last = ($index === $count - 1);

            // Skip last item if show_current_page is disabled.
            if ($is_last && !$show_current && $count > 1) {
                continue;
            }

            if ($is_last || empty($item['url'])) {
                $items_html[] = '<li class="metasync-breadcrumb__item metasync-breadcrumb__current">'
                    . '<span aria-current="page">' . esc_html($item['label']) . '</span>'
                    . '</li>';
            } else {
                $items_html[] = '<li class="metasync-breadcrumb__item">'
                    . '<a class="metasync-breadcrumb__link" href="' . esc_url($item['url']) . '">'
                    . esc_html($item['label'])
                    . '</a>'
                    . '</li>';
            }
        }

        $separator_html = '<li class="metasync-breadcrumb__separator" aria-hidden="true">'
            . '<span>' . wp_kses_post($separator) . '</span>'
            . '</li>';

        $list_html = implode($separator_html, $items_html);

        $prefix_html = '';
        if (!empty($prefix_text)) {
            $prefix_html = '<span class="metasync-breadcrumb__prefix">' . esc_html($prefix_text) . '</span>';
        }

        $html = '<nav class="metasync-breadcrumb" aria-label="breadcrumb">'
            . $prefix_html
            . '<ol class="metasync-breadcrumb__list">'
            . $list_html
            . '</ol>'
            . '</nav>';

        return $html;
    }
}

/**
 * Global template function for themes.
 *
 * Usage: <?php if ( function_exists( 'metasync_breadcrumb' ) ) metasync_breadcrumb(); ?>
 */
if (!function_exists('metasync_breadcrumb')) {
    function metasync_breadcrumb() {
        $breadcrumbs = new Metasync_Breadcrumbs();
        echo $breadcrumbs->render_breadcrumb_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is escaped inside render method
    }
}
