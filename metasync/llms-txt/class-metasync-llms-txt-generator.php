<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * LLMs.txt generator.
 *
 * Serves virtual /llms.txt and /llms-full.txt routes via template_redirect,
 * caches output in transients, and invalidates the cache on post or
 * settings changes. Modeled after Metasync_Sitemap_Generator.
 *
 * @package    Metasync
 * @subpackage Metasync/llms-txt
 */
class Metasync_Llms_Txt_Generator
{
    const OPTION_KEY          = 'metasync_llms_txt_settings';
    const TRANSIENT_SHORT     = 'metasync_llms_txt';
    const TRANSIENT_FULL      = 'metasync_llms_full_txt';
    const CONFLICT_TRANSIENT  = 'metasync_llms_conflict';
    const CACHE_TTL           = 12 * HOUR_IN_SECONDS;

    /**
     * Initialise the generator and register hooks.
     */
    public function __construct()
    {
        add_action('template_redirect', array($this, 'serve_virtual_llms_txt'), 1);

        // Invalidate the cache whenever a post is saved, deleted, or trashed.
        add_action('save_post', array($this, 'invalidate_cache'));
        add_action('deleted_post', array($this, 'invalidate_cache'));
        add_action('trashed_post', array($this, 'invalidate_cache'));

        // Invalidate when the settings are updated.
        add_action('update_option_' . self::OPTION_KEY, array($this, 'invalidate_cache'));
        add_action('add_option_' . self::OPTION_KEY, array($this, 'invalidate_cache'));

        // Sync robots.txt when the enabled setting changes.
        add_action('update_option_' . self::OPTION_KEY, array($this, 'on_settings_changed'), 10, 2);
        add_action('add_option_' . self::OPTION_KEY,    array($this, 'on_settings_changed'), 10, 2);
    }

    /**
     * Serve the virtual /llms.txt or /llms-full.txt route.
     */
    public function serve_virtual_llms_txt()
    {
        if (is_admin()) {
            return;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $request_uri = strtok($request_uri, '?');
        $path = rtrim(parse_url($request_uri, PHP_URL_PATH), '/');

        $is_short = ($path === '/llms.txt');
        $is_full  = ($path === '/llms-full.txt');

        if (!$is_short && !$is_full) {
            return;
        }

        // Let a physical file win if one exists.
        $filename = $is_full ? 'llms-full.txt' : 'llms.txt';
        if (file_exists(ABSPATH . $filename)) {
            return;
        }

        $settings = $this->get_settings();

        if (empty($settings['enabled'])) {
            return;
        }

        // Detect conflicts for admin notice purposes only — MetaSync always serves when enabled.
        $this->detect_plugin_conflict();

        if ($is_full && empty($settings['llms_full_enabled'])) {
            status_header(404);
            return;
        }

        $transient_key = $is_full ? self::TRANSIENT_FULL : self::TRANSIENT_SHORT;
        $content = get_transient($transient_key);

        if (false === $content || $content === '') {
            $content = $is_full ? $this->generate_full() : $this->generate();
            if ($content !== '') {
                set_transient($transient_key, $content, self::CACHE_TTL);
            }
        }

        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo $content;
        exit;
    }

    /**
     * Build /llms.txt content.
     *
     * @return string
     */
    public function generate()
    {
        $settings = $this->get_settings();
        $site_name = get_bloginfo('name');
        $tagline = !empty($settings['custom_description'])
            ? $settings['custom_description']
            : get_bloginfo('description');

        $content  = '# ' . $site_name . "\n\n";
        if (!empty($tagline)) {
            $content .= '> ' . $tagline . "\n\n";
        }

        $posts = $this->query_posts($settings);

        // Group posts by post type for cleaner section headers.
        $grouped = [];
        foreach ($posts as $post) {
            $grouped[$post->post_type][] = $post;
        }

        $type_labels = [
            'page' => 'About',
            'post' => 'Blog',
        ];

        foreach ($grouped as $type => $items) {
            $label = isset($type_labels[$type]) ? $type_labels[$type] : ucfirst($type);
            $content .= '## ' . $label . "\n";
            foreach ($items as $post) {
                $desc = $this->resolve_description($post);
                $line = '- [' . wp_strip_all_tags($post->post_title) . '](' . get_permalink($post) . ')';
                if ($desc !== '') {
                    $line .= ': ' . $desc;
                }
                $content .= $line . "\n";
            }
            $content .= "\n";
        }

        if (!empty($settings['llms_full_enabled'])) {
            $content .= "## Optional\n";
            $content .= '- [llms-full.txt](' . site_url('/llms-full.txt') . '): Extended version with full content' . "\n";
        }

        return $content;
    }

    /**
     * Build /llms-full.txt content.
     *
     * @return string
     */
    public function generate_full()
    {
        if (!class_exists('Metasync_Html_To_Markdown')) {
            $converter_file = plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-html-to-markdown.php';
            if (file_exists($converter_file)) {
                require_once $converter_file;
            }
        }

        $settings = $this->get_settings();
        $site_name = get_bloginfo('name');
        $tagline = !empty($settings['custom_description'])
            ? $settings['custom_description']
            : get_bloginfo('description');

        $content  = '# ' . $site_name . "\n\n";
        if (!empty($tagline)) {
            $content .= '> ' . $tagline . "\n\n";
        }

        $posts = $this->query_posts_full($settings);
        $max_bytes = 1048576; // 1 MB

        foreach ($posts as $post) {
            $entry = "\n---\n\n";
            if (class_exists('Metasync_Html_To_Markdown')) {
                $entry .= Metasync_Html_To_Markdown::convert_post($post->ID, [
                    'include_frontmatter'    => true,
                    'include_featured_image' => true,
                ]);
                $entry .= "\n";
            } else {
                $entry .= '# ' . $post->post_title . "\n\n";
                $entry .= wp_strip_all_tags($post->post_content) . "\n";
            }

            if (strlen($content) + strlen($entry) > $max_bytes) {
                break;
            }

            $content .= $entry;
        }

        return $content;
    }

    /**
     * Sync the robots.txt LLMs.txt reference when settings are saved.
     *
     * Called on both update_option_ and add_option_ hooks. For add_option the
     * hook passes ($option, $value) but we only need the second argument, which
     * is the new value in both cases.
     *
     * @param mixed $old_value Previous option value (or option name for add_option).
     * @param mixed $new_value New option value.
     */
    public function on_settings_changed( $old_value, $new_value ) {
        $robots_file = plugin_dir_path(dirname(__FILE__)) . 'robots-txt/class-metasync-robots-txt.php';
        if (!class_exists('Metasync_Robots_Txt') && file_exists($robots_file)) {
            require_once $robots_file;
        }

        if (!class_exists('Metasync_Robots_Txt')) {
            return;
        }

        $robots   = Metasync_Robots_Txt::get_instance();
        $llms_url = site_url('/llms.txt');

        if (!empty($new_value['enabled'])) {
            $robots->add_llms_txt_url($llms_url);
        } else {
            $robots->remove_llms_txt_url($llms_url);
        }
    }

    /**
     * Flush cached LLMs.txt content.
     */
    public function invalidate_cache()
    {
        delete_transient(self::TRANSIENT_SHORT);
        delete_transient(self::TRANSIENT_FULL);
    }

    /**
     * Fetch posts that should appear in the listing.
     *
     * @param array $settings
     * @return WP_Post[]
     */
    private function query_posts($settings)
    {
        $post_types = !empty($settings['post_types']) && is_array($settings['post_types'])
            ? $settings['post_types']
            : ['page', 'post'];

        $max_posts = isset($settings['max_posts']) ? (int) $settings['max_posts'] : 50;
        if ($max_posts < 1) {
            $max_posts = 50;
        }
        if ($max_posts > 500) {
            $max_posts = 500;
        }

        $excluded = !empty($settings['excluded_ids']) && is_array($settings['excluded_ids'])
            ? array_map('absint', $settings['excluded_ids'])
            : [];

        $args = [
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $max_posts,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'relation' => 'OR',
                    [
                        'key'     => '_metasync_robots_index',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => '_metasync_robots_index',
                        'value'   => 'noindex',
                        'compare' => '!=',
                    ],
                ],
            ],
        ];

        if (!empty($excluded)) {
            $args['post__not_in'] = $excluded;
        }

        $query = new WP_Query($args);
        return $query->posts ?: [];
    }

    /**
     * Fetch posts for /llms-full.txt (uses the separate max_posts_full limit).
     *
     * @param array $settings
     * @return WP_Post[]
     */
    private function query_posts_full($settings)
    {
        $override = $settings;
        $max = isset($settings['max_posts_full']) ? (int) $settings['max_posts_full'] : 25;
        if ($max < 1) {
            $max = 25;
        }
        if ($max > 500) {
            $max = 500;
        }
        $override['max_posts'] = $max;
        return $this->query_posts($override);
    }

    /**
     * Resolve the description for a post using the documented priority:
     *   _metasync_seo_desc → _metasync_otto_description → manual excerpt → auto excerpt.
     *
     * @param WP_Post $post
     * @return string
     */
    private function resolve_description($post)
    {
        $desc = get_post_meta($post->ID, '_metasync_seo_desc', true);
        if (!empty($desc)) {
            return $this->sanitize_description($desc);
        }

        $desc = get_post_meta($post->ID, '_metasync_otto_description', true);
        if (!empty($desc)) {
            return $this->sanitize_description($desc);
        }

        if (!empty($post->post_excerpt)) {
            return $this->sanitize_description($post->post_excerpt);
        }

        $auto = wp_trim_words(wp_strip_all_tags(strip_shortcodes($post->post_content)), 20, '…');
        return $this->sanitize_description($auto);
    }

    /**
     * Collapse whitespace and normalise a description string for markdown output.
     *
     * @param string $desc
     * @return string
     */
    private function sanitize_description($desc)
    {
        $desc = wp_strip_all_tags((string) $desc);
        $desc = preg_replace('/\s+/', ' ', $desc);
        return trim($desc);
    }

    /**
     * Get plugin settings with defaults merged.
     *
     * @return array
     */
    public function get_settings()
    {
        $defaults = [
            'enabled'            => false,
            'post_types'         => ['page', 'post'],
            'max_posts'          => 50,
            'max_posts_full'     => 25,
            'excluded_ids'       => [],
            'custom_description' => '',
            'llms_full_enabled'  => false,
        ];

        $saved = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        return array_merge($defaults, $saved);
    }

    /**
     * Detect whether another SEO plugin already handles /llms.txt.
     *
     * Caches the result in a short-lived transient so admin notices can
     * reflect the current state without re-checking on every request.
     *
     * @return bool
     */
    public function detect_plugin_conflict()
    {
        $conflict = false;

        // Yoast SEO.
        if (class_exists('WPSEO_Options')) {
            $wpseo = get_option('wpseo');
            if (is_array($wpseo) && !empty($wpseo['enable_llms_txt'])) {
                $conflict = true;
            }
        }

        // Rank Math.
        if (!$conflict && class_exists('RankMath')) {
            $rank_math_modules = get_option('rank_math_modules', []);
            if (is_array($rank_math_modules) && in_array('llms-txt', $rank_math_modules, true)) {
                $conflict = true;
            }
            if (!$conflict) {
                $rank_math_general = get_option('rank-math-options-general');
                if (is_array($rank_math_general) && !empty($rank_math_general['llms_txt_enable'])) {
                    $conflict = true;
                }
            }
        }

        // All in One SEO.
        if (!$conflict && function_exists('aioseo')) {
            try {
                $aioseo = aioseo();
                if (isset($aioseo->options) && isset($aioseo->options->searchAppearance)
                    && isset($aioseo->options->searchAppearance->global)
                    && isset($aioseo->options->searchAppearance->global->llmsEnabled)
                    && $aioseo->options->searchAppearance->global->llmsEnabled) {
                    $conflict = true;
                }
            } catch (\Throwable $e) {
                // Ignore – AIOSEO API changed or not fully loaded.
            }

            if (!$conflict) {
                $aioseo_raw = get_option('aioseo_options');
                $aioseo_options = is_string($aioseo_raw) ? json_decode($aioseo_raw, true) : $aioseo_raw;
                if (is_array($aioseo_options)
                    && !empty($aioseo_options['searchAppearance']['global']['llmsEnabled'])) {
                    $conflict = true;
                }
            }
        }

        if ($conflict) {
            set_transient(self::CONFLICT_TRANSIENT, true, HOUR_IN_SECONDS);
        } else {
            delete_transient(self::CONFLICT_TRANSIENT);
        }

        return $conflict;
    }
}
