<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * The sitemap generator functionality of the plugin.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 *
 * @package    Metasync
 * @subpackage Metasync/sitemap
 */

/**
 * The sitemap generator class.
 *
 * Handles XML sitemap generation and management.
 *
 * @package    Metasync
 * @subpackage Metasync/sitemap
 * @author     Engineering Team <support@searchatlas.com>
 */
class Metasync_Sitemap_Generator
{
    /**
     * The sitemap index file path
     *
     * @var string
     */
    private $sitemap_index_path;

    /**
     * The sitemap index URL
     *
     * @var string
     */
    private $sitemap_index_url;

    /**
     * Maximum URLs per sitemap file
     *
     * @var int
     */
    private $urls_per_sitemap = 5000;

    /**
     * Initialize the class and set its properties.
     */
    public function __construct()
    {
        $this->sitemap_index_path = ABSPATH . 'sitemap_index.xml';
        $this->sitemap_index_url = home_url('/sitemap_index.xml');

        // Disable WordPress core sitemap if option is set
        if (get_option('metasync_disable_wp_sitemap', false)) {
            add_filter('wp_sitemaps_enabled', '__return_false', 10);
        }

        // Register hook to serve virtual sitemap files
        add_action('template_redirect', array($this, 'serve_virtual_sitemap'), 1);

        // Route sitemap URLs through WordPress rewrites so requests always reach PHP,
        // even on nginx setups that 403 direct .xml access.
        static $rewrite_hooks_registered = false;
        if (!$rewrite_hooks_registered) {
            $rewrite_hooks_registered = true;
            add_filter('query_vars', array($this, 'add_sitemap_query_var'));
            add_filter('option_rewrite_rules', array($this, 'inject_rewrite_rules'));
            add_filter('sanitize_option_rewrite_rules', array($this, 'strip_dynamic_rewrite_rules'));
            add_filter('redirect_canonical', array($this, 'disable_canonical_redirect_for_sitemaps'));
        }

        $this->register_cache_bust_hooks();
    }

    /**
     * Register `metasync_sitemap` as a public WordPress query var.
     *
     * @param array $vars Registered query vars.
     * @return array
     */
    public function add_sitemap_query_var($vars)
    {
        $vars[] = 'metasync_sitemap';
        return $vars;
    }

    /**
     * Dynamically inject sitemap rewrite rules at the top of the rules array so
     * they take effect without requiring a manual flush_rewrite_rules() call
     * (mirrors Yoast's class-yoast-dynamic-rewrites.php pattern).
     *
     * @param mixed $rules Existing rewrite rules array (or empty value before flush).
     * @return array
     */
    public function inject_rewrite_rules($rules)
    {
        $sitemap_rules = [
            '^sitemap_index\.xml$'   => 'index.php?metasync_sitemap=sitemap_index.xml',
            '^sitemap(\d*)\.xml$'    => 'index.php?metasync_sitemap=sitemap$matches[1].xml',
            '^news-sitemap\.xml$'    => 'index.php?metasync_sitemap=news-sitemap.xml',
            '^video-sitemap\.xml$'   => 'index.php?metasync_sitemap=video-sitemap.xml',
        ];

        if (!is_array($rules)) {
            $rules = [];
        }

        return $sitemap_rules + $rules;
    }

    /**
     * Strip dynamically-injected sitemap rules before they are persisted to the
     * rewrite_rules option in the database.
     *
     * @param mixed $rules Rewrite rules about to be saved.
     * @return array|string Filtered rules without the dynamic sitemap entries.
     */
    public function strip_dynamic_rewrite_rules($rules)
    {
        if (empty($rules) || !is_array($rules)) {
            return $rules;
        }

        $dynamic_keys = [
            '^sitemap_index\.xml$',
            '^sitemap(\d*)\.xml$',
            '^news-sitemap\.xml$',
            '^video-sitemap\.xml$',
        ];

        return array_diff_key($rules, array_flip($dynamic_keys));
    }

    /**
     * Suppress WordPress's canonical redirect when a sitemap is being served.
     *
     * @param string $redirect_url The proposed canonical URL.
     * @return string|false The unmodified URL, or false to cancel the redirect.
     */
    public function disable_canonical_redirect_for_sitemaps($redirect_url)
    {
        if (get_query_var('metasync_sitemap')) {
            return false;
        }
        return $redirect_url;
    }

    /**
     * Register WordPress content lifecycle hooks that bust the sitemap caches.
     *
     * Bust callbacks are intentionally lightweight (transient deletes only) so they
     * do not add latency to the editor's save action. Optional async warm-up can
     * be enabled via the `metasync_sitemap_async_warmup` filter.
     */
    public function register_cache_bust_hooks()
    {
        // Post lifecycle.
        add_action('save_post',    array($this, 'bust_sitemap_cache'));
        add_action('delete_post',  array($this, 'bust_sitemap_cache'));
        add_action('trashed_post', array($this, 'bust_sitemap_cache'));

        // Taxonomy lifecycle.
        add_action('created_term', array($this, 'bust_sitemap_cache'));
        add_action('edited_term',  array($this, 'bust_sitemap_cache'));
        add_action('delete_term',  array($this, 'bust_sitemap_cache'));

        // Meta updates that affect indexability.
        add_action('added_post_meta',   array($this, 'bust_sitemap_on_meta_update'), 10, 3);
        add_action('updated_post_meta', array($this, 'bust_sitemap_on_meta_update'), 10, 3);

        // Async warm-up listener.
        add_action('metasync_sitemap_async_warmup_event', array($this, 'async_warmup_handler'));
    }

    /**
     * Bust all sitemap-related transients.
     *
     * Coalesces multiple calls within the same request via a static guard so
     * bulk operations (e.g. a 50-product import) only trigger one bust.
     */
    public function bust_sitemap_cache()
    {
        static $already_busted = false;
        if ($already_busted) {
            return;
        }
        $already_busted = true;

        delete_transient('metasync_vsm_' . md5('news-sitemap.xml'));
        delete_transient('metasync_vsm_' . md5('video-sitemap.xml'));

        $virtual_index = get_option('metasync_sitemap_virtual_index', []);
        if (is_array($virtual_index)) {
            foreach ($virtual_index as $tkey) {
                if (is_string($tkey) && $tkey !== '') {
                    delete_transient($tkey);
                }
            }
        }
        delete_option('metasync_sitemap_virtual_index');

        if (apply_filters('metasync_sitemap_async_warmup', false)
            && !wp_next_scheduled('metasync_sitemap_async_warmup_event')) {
            wp_schedule_single_event(time() + 30, 'metasync_sitemap_async_warmup_event');
        }
    }

    /**
     * Conditionally bust the sitemap cache when meta affecting indexability changes.
     *
     * Only `_metasync_robots_index` and `_metasync_canonical_url` trigger a bust;
     * unrelated keys (e.g. `_edit_lock`) are ignored.
     *
     * @param int    $meta_id   ID of the metadata entry.
     * @param int    $object_id Object the metadata is attached to.
     * @param string $meta_key  The meta key being updated.
     */
    public function bust_sitemap_on_meta_update($meta_id, $object_id, $meta_key)
    {
        if ($meta_key !== '_metasync_robots_index' && $meta_key !== '_metasync_canonical_url') {
            return;
        }
        $this->bust_sitemap_cache();
    }

    /**
     * Async warm-up handler invoked by wp_schedule_single_event after a bust.
     */
    public function async_warmup_handler()
    {
        $this->generate_sitemap();
    }

    /**
     * Generate the XML sitemap (split into multiple files if needed)
     *
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function generate_sitemap()
    {
        try {
            // Delete existing general sitemap files first (preserve news/video)
            $this->delete_sitemap('general');

            // Clean up any orphaned temp files from previous crashed generations
            $this->cleanup_temp_sitemap_files();

            // Resolve streaming preference once for the entire generation cycle
            $force_memory = (bool) apply_filters('metasync_sitemap_force_memory', false);

            // Collect all URLs
            $all_urls = $this->collect_all_urls();

            if (empty($all_urls)) {
                return new WP_Error('no_urls', 'No URLs found to include in sitemap.');
            }

            // Split URLs into chunks
            $url_chunks = array_chunk($all_urls, $this->urls_per_sitemap);
            $sitemap_files = [];

            // Generate individual sitemap files
            foreach ($url_chunks as $index => $urls) {
                $sitemap_number = $index + 1;
                $sitemap_filename = $this->get_sitemap_filename($sitemap_number);
                $sitemap_path = ABSPATH . $sitemap_filename;

                $result = $this->generate_sitemap_file($sitemap_path, $urls, $force_memory);

                if ($result === false) {
                    continue; // Skip this file if write permission issue
                }

                if (is_wp_error($result)) {
                    return $result;
                }

                $sitemap_files[] = [
                    'filename' => $sitemap_filename,
                    'url' => home_url('/' . $sitemap_filename),
                    'lastmod' => $this->get_chunk_lastmod($urls),
                ];
            }

            // Build the index entries: main sitemaps + news/video if they exist
            $index_entries = $sitemap_files;

            $news_settings = get_option('metasync_news_sitemap_settings', []);
            $video_settings = get_option('metasync_video_sitemap_settings', []);

            if (!empty($news_settings['enabled'])) {
                $index_entries[] = [
                    'filename' => 'news-sitemap.xml',
                    'url'      => home_url('/news-sitemap.xml'),
                    'lastmod'  => current_time('mysql', true),
                ];
            }

            if (!empty($video_settings['enabled'])) {
                $index_entries[] = [
                    'filename' => 'video-sitemap.xml',
                    'url'      => home_url('/video-sitemap.xml'),
                    'lastmod'  => current_time('mysql', true),
                ];
            }

            // Generate sitemap index file (includes all sitemaps)
            $index_result = $this->generate_sitemap_index($index_entries, $force_memory);

            if ($index_result === false) {
                return false; // Return false instead of WP_Error to prevent Sentry capture
            }

            if (is_wp_error($index_result)) {
                return $index_result;
            }

            // Store sitemap info for admin display
            update_option('metasync_sitemap_files', $sitemap_files);
            update_option('metasync_sitemap_total_urls', count($all_urls));
            update_option('metasync_sitemap_last_generated', current_time('mysql'));

            // Auto-update robots.txt with sitemap index URL
            $robots_result = $this->update_robots_txt_sitemap();
            if ($robots_result['success'] && $robots_result['action'] !== 'unchanged') {
                // Store the result for admin notice
                set_transient('metasync_sitemap_robots_updated', $robots_result, 60);
            }

            return true;

        } catch (Exception $e) {
            return new WP_Error('sitemap_generation_failed', $e->getMessage());
        }
    }

    /**
     * Collect all URLs for the sitemap
     *
     * @return array Array of URL data
     */
    private function collect_all_urls()
    {
        global $wpdb;

        $urls = [];

        // Load user-configured sitemap settings
        $sitemap_settings = get_option('metasync_sitemap_settings', []);

        // Get all published posts, pages, and custom post types
        $post_types = get_post_types(['public' => true], 'names');

        // Exclude certain post types
        $excluded_types = [
            'attachment',
            'revision',
            'nav_menu_item',
            'elementor_library',
            'elementor-hf',
            'ct_template',
            'oxy_user_library',
            'brizy-template',
            'fusion_template',
            'fusion_tb_section',
            'ae_global_templates',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_global_styles',
            'wp_navigation',
            'acf-field-group',
            'acf-field',
            'fl-builder-template',
            'fl-theme-layout',
            'wpr_mega_menu',
            'wpr_templates',
        ];
        $excluded_types = apply_filters('metasync_sitemap_excluded_post_types', $excluded_types);
        $post_types = array_diff($post_types, $excluded_types);

        // Apply user-configured post type selection (only when settings have been explicitly saved)
        if (!empty($sitemap_settings['_configured'])) {
            if (!empty($sitemap_settings['post_types'])) {
                $post_types = array_intersect($post_types, (array) $sitemap_settings['post_types']);
            } else {
                // Configured but empty = user unchecked all post types
                $post_types = [];
            }
        }

        if (empty($post_types)) {
            return $urls;
        }

        // Build excluded URLs set for fast lookup
        $excluded_urls = [];
        if (!empty($sitemap_settings['excluded_urls'])) {
            $raw_lines = explode("\n", $sitemap_settings['excluded_urls']);
            foreach ($raw_lines as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $excluded_urls[$line] = true;
                }
            }
        }

        // Get indexation control settings
        $seo_controls = $this->get_seo_controls();

        // Add homepage first
        $home_url = home_url('/');
        $urls[] = [
            'loc' => $home_url,
            'lastmod' => get_lastpostmodified('gmt'),
            'priority' => '1.0',
            'changefreq' => 'daily',
        ];

        // Track seen URLs to prevent duplicates (e.g. front page appearing again from posts query).
        // Normalize with untrailingslashit() to avoid trailing-slash mismatches between
        // home_url('/') and get_permalink() for static front pages.
        $seen_urls = [untrailingslashit($home_url) => true];

        // Build taxonomy query for category/tag filtering (only when explicitly configured)
        $sitemap_tax_query = [];
        if (!empty($sitemap_settings['_configured']) && !empty($sitemap_settings['categories'])) {
            $sitemap_tax_query[] = [
                'taxonomy' => 'category',
                'field'    => 'term_id',
                'terms'    => array_map('absint', (array) $sitemap_settings['categories']),
            ];
        }
        if (!empty($sitemap_settings['_configured']) && !empty($sitemap_settings['tags'])) {
            $sitemap_tax_query[] = [
                'taxonomy' => 'post_tag',
                'field'    => 'term_id',
                'terms'    => array_map('absint', (array) $sitemap_settings['tags']),
            ];
        }

        // Process posts in chunks to bound memory and database load.
        $chunk_size = (int) apply_filters('metasync_sitemap_chunk_size', 200);
        if ($chunk_size < 1) {
            $chunk_size = 200;
        }
        $paged = 1;

        while (true) {
            $query_args = [
                'post_type'              => array_values($post_types),
                'post_status'            => 'publish',
                'posts_per_page'         => $chunk_size,
                'paged'                  => $paged,
                'orderby'                => 'modified',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'update_post_term_cache' => true,
                'update_post_meta_cache' => true,
                'cache_results'          => true,
                'ignore_sticky_posts'    => true,
                'suppress_filters'       => true,
            ];

            if (!empty($sitemap_tax_query)) {
                $query_args['tax_query'] = $sitemap_tax_query;
            }

            $query = new WP_Query($query_args);

            $posts = $query->posts;
            if (empty($posts)) {
                wp_reset_postdata();
                unset($query, $posts);
                break;
            }

            // Batch-resolve noindex status for the entire chunk in a single query.
            // Mirrors is_post_noindex(): metasync_common_robots is a serialized array
            // and a post is noindex when it contains 'noindex' => 'noindex'.
            $post_ids = wp_list_pluck($posts, 'ID');
            $post_ids = array_map('intval', $post_ids);
            $id_placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
            $noindex_pattern = '%"noindex";s:7:"noindex"%';
            $noindex_args = array_merge($post_ids, [$noindex_pattern]);
            $noindex_ids = (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'metasync_common_robots' AND post_id IN ({$id_placeholders}) AND meta_value LIKE %s",
                    $noindex_args
                )
            );
            $noindex_ids = array_map('intval', $noindex_ids);
            $noindex_set = array_flip($noindex_ids);

            foreach ($posts as $post) {
                if (isset($noindex_set[(int) $post->ID])) {
                    continue;
                }

                $priority = '0.8';
                if ($post->post_type === 'page') {
                    $priority = '0.9';
                } elseif ($post->post_type === 'post') {
                    $priority = '0.7';
                }

                $changefreq = 'weekly';
                if ($post->post_type === 'page') {
                    $changefreq = 'monthly';
                }

                // Note: get_permalink() respects the post_link_category filter registered
                // in Metasync_Seo_Output, which automatically resolves the primary category.
                $permalink = get_permalink($post);

                // Skip URLs with query strings (e.g. ?wpr_mega_menu=... internal items).
                // Only when the site uses pretty permalinks — under the plain permalink
                // structure (?p=123) every legitimate URL contains '?', so applying this
                // unconditionally would empty the sitemap down to the homepage.
                if (get_option('permalink_structure') && strpos($permalink, '?') !== false) {
                    continue;
                }

                // Skip duplicate URLs (e.g. front page appearing again from posts query)
                $normalized_permalink = untrailingslashit($permalink);
                if (isset($seen_urls[$normalized_permalink])) {
                    continue;
                }

                // Skip URLs that are in the exclusion list
                if (!empty($excluded_urls) && isset($excluded_urls[$permalink])) {
                    continue;
                }

                $seen_urls[$normalized_permalink] = true;

                $urls[] = [
                    'loc' => $permalink,
                    'lastmod' => $post->post_modified_gmt,
                    'priority' => $priority,
                    'changefreq' => $changefreq,
                ];
            }

            wp_reset_postdata();
            unset($query, $posts, $noindex_ids, $noindex_set, $post_ids, $id_placeholders, $noindex_args);
            $paged++;
        }

        // Add taxonomies (categories, tags, etc.)
        $taxonomies = get_taxonomies(['public' => true], 'names');

        // Apply user-configured taxonomy selection (only when settings have been explicitly saved)
        if (!empty($sitemap_settings['_configured'])) {
            if (!empty($sitemap_settings['taxonomies'])) {
                $taxonomies = array_intersect($taxonomies, (array) $sitemap_settings['taxonomies']);
            } else {
                // Configured but empty = user unchecked all taxonomies
                $taxonomies = [];
            }
        }

        foreach ($taxonomies as $taxonomy) {
            // Skip taxonomies that are set to noindex
            if ($this->is_taxonomy_noindex($taxonomy, $seo_controls)) {
                continue;
            }

            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => true,
            ]);

            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            // Single aggregated query: most-recently-modified post per term in this taxonomy.
            $type_values = array_values($post_types);
            $type_placeholders = implode(',', array_fill(0, count($type_values), '%s'));
            $lastmod_args = array_merge([$taxonomy], $type_values);
            $lastmod_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT tt.term_id, MAX(p.post_modified_gmt) AS lastmod
                     FROM {$wpdb->term_taxonomy} tt
                     INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                     INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                     WHERE tt.taxonomy = %s AND p.post_status = 'publish' AND p.post_type IN ({$type_placeholders})
                     GROUP BY tt.term_id",
                    $lastmod_args
                ),
                ARRAY_A
            );

            $lastmod_map = [];
            if (is_array($lastmod_rows)) {
                foreach ($lastmod_rows as $row) {
                    $lastmod_map[(int) $row['term_id']] = $row['lastmod'];
                }
            }

            foreach ($terms as $term) {
                $term_link = get_term_link($term);
                if (is_wp_error($term_link)) {
                    continue;
                }

                // Skip URLs that are in the exclusion list
                if (!empty($excluded_urls) && isset($excluded_urls[$term_link])) {
                    continue;
                }

                // Skip duplicate URLs
                $normalized_term_link = untrailingslashit($term_link);
                if (isset($seen_urls[$normalized_term_link])) {
                    continue;
                }

                $seen_urls[$normalized_term_link] = true;

                $lastmod = isset($lastmod_map[(int) $term->term_id])
                    ? $lastmod_map[(int) $term->term_id]
                    : current_time('mysql', 1);
                $urls[] = [
                    'loc' => $term_link,
                    'lastmod' => $lastmod,
                    'priority' => '0.6',
                    'changefreq' => 'weekly',
                ];
            }

            unset($lastmod_rows, $lastmod_map, $terms);
        }

        return $urls;
    }

    /**
     * Get the sitemap filename for a given number
     *
     * @param int $number The sitemap number (1, 2, 3, etc.)
     * @return string The filename
     */
    private function get_sitemap_filename($number)
    {
        if ($number === 1) {
            return 'sitemap.xml';
        }
        return 'sitemap' . $number . '.xml';
    }

    /**
     * Generate a single sitemap file
     *
     * @param string $path The file path
     * @param array $urls Array of URL data
     * @param bool $force_memory Whether to skip streaming and use in-memory generation
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    private function generate_sitemap_file($path, $urls, $force_memory = false)
    {
        $xml_content = $this->build_sitemap_xml_string($urls);
        $this->store_virtual_sitemap_file(basename($path), $xml_content);
        return true;
    }

    /**
     * Stream sitemap URLs to a temp file using XMLWriter, then atomic-rename.
     *
     * @param string $dir Directory for the temp file (must be same mount as $final_path)
     * @param string $final_path Final destination path
     * @param array $urls Array of URL data
     * @return bool True on success, false on failure
     */
    private function stream_sitemap_urls($dir, $final_path, $urls)
    {
        $writer = new XMLWriter();
        $tmp_path = tempnam($dir, 'metasync-sitemap-tmp-');
        if ($tmp_path === false) {
            return false;
        }
        // tempnam creates the file; rename it with .xml extension for clarity
        $tmp_xml_path = $tmp_path . '.xml';
        rename($tmp_path, $tmp_xml_path);
        $tmp_path = $tmp_xml_path;

        if ($writer->openUri($tmp_path) === false) {
            $this->safe_unlink($tmp_path);
            return false;
        }

        $stream_ok = true;

        try {
            $writer->setIndent(true);
            $writer->startDocument('1.0', 'UTF-8');
            $writer->startElement('urlset');
            $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            $writer->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
            $writer->writeAttribute(
                'xsi:schemaLocation',
                'http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd'
            );

            foreach ($urls as $url_data) {
                $writer->startElement('url');
                $writer->writeElement('loc', esc_url($url_data['loc']));
                if (!empty($url_data['lastmod'])) {
                    $writer->writeElement('lastmod', gmdate('Y-m-d\TH:i:s+00:00', strtotime($url_data['lastmod'])));
                }
                $writer->writeElement('changefreq', $url_data['changefreq']);
                $writer->writeElement('priority', $url_data['priority']);
                $writer->endElement();
                $writer->flush();
            }

            $writer->endElement();
            $writer->endDocument();
            $writer->flush();
        } catch (Exception $e) {
            $stream_ok = false;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MetaSync: XMLWriter streaming error: ' . $e->getMessage());
            }
        }

        unset($writer);

        if ($stream_ok && file_exists($tmp_path) && rename($tmp_path, $final_path)) {
            return true;
        }

        $this->safe_unlink($tmp_path);
        return false;
    }

    /**
     * Stream sitemap index entries to a temp file using XMLWriter, then atomic-rename.
     *
     * @param string $dir Directory for the temp file
     * @param string $final_path Final destination path
     * @param array $sitemap_files Array of sitemap file info
     * @return bool True on success, false on failure
     */
    private function stream_sitemap_index($dir, $final_path, $sitemap_files)
    {
        $writer = new XMLWriter();
        $tmp_path = tempnam($dir, 'metasync-sitemap-tmp-');
        if ($tmp_path === false) {
            return false;
        }
        $tmp_xml_path = $tmp_path . '.xml';
        rename($tmp_path, $tmp_xml_path);
        $tmp_path = $tmp_xml_path;

        if ($writer->openUri($tmp_path) === false) {
            $this->safe_unlink($tmp_path);
            return false;
        }

        $stream_ok = true;

        try {
            $writer->setIndent(true);
            $writer->startDocument('1.0', 'UTF-8');
            $writer->startElement('sitemapindex');
            $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

            foreach ($sitemap_files as $sitemap) {
                $writer->startElement('sitemap');
                $writer->writeElement('loc', esc_url($sitemap['url']));
                if (!empty($sitemap['lastmod'])) {
                    $writer->writeElement('lastmod', gmdate('Y-m-d\TH:i:s+00:00', strtotime($sitemap['lastmod'])));
                }
                $writer->endElement();
                $writer->flush();
            }

            $writer->endElement();
            $writer->endDocument();
            $writer->flush();
        } catch (Exception $e) {
            $stream_ok = false;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MetaSync: XMLWriter streaming error: ' . $e->getMessage());
            }
        }

        unset($writer);

        if ($stream_ok && file_exists($tmp_path) && rename($tmp_path, $final_path)) {
            return true;
        }

        $this->safe_unlink($tmp_path);
        return false;
    }

    /**
     * Build the sitemap XML string in memory using DOMDocument.
     *
     * @param array $urls Array of URL data
     * @return string XML content
     */
    private function build_sitemap_xml_string($urls)
    {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $urlset = $xml->createElement('urlset');
        $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urlset->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $urlset->setAttribute('xsi:schemaLocation', 'http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd');
        $xml->appendChild($urlset);

        foreach ($urls as $url_data) {
            $this->add_url_to_sitemap($xml, $urlset, $url_data['loc'], $url_data['lastmod'], $url_data['priority'], $url_data['changefreq']);
        }

        return $xml->saveXML();
    }

    /**
     * Build the sitemap index XML string in memory using DOMDocument.
     *
     * @param array $sitemap_files Array of sitemap file info
     * @return string XML content
     */
    private function build_sitemap_index_xml_string($sitemap_files)
    {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $sitemapindex = $xml->createElement('sitemapindex');
        $sitemapindex->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->appendChild($sitemapindex);

        foreach ($sitemap_files as $sitemap) {
            $sitemap_element = $xml->createElement('sitemap');

            $loc = $xml->createElement('loc', esc_url($sitemap['url']));
            $sitemap_element->appendChild($loc);

            if (!empty($sitemap['lastmod'])) {
                $lastmod_formatted = gmdate('Y-m-d\TH:i:s+00:00', strtotime($sitemap['lastmod']));
                $lastmod = $xml->createElement('lastmod', $lastmod_formatted);
                $sitemap_element->appendChild($lastmod);
            }

            $sitemapindex->appendChild($sitemap_element);
        }

        return $xml->saveXML();
    }

    /**
     * Write content to a file atomically using temp file + rename.
     *
     * Both files must reside on the same filesystem/mount for rename() to be atomic.
     *
     * @param string $dir Directory for the temp file (same mount as $final_path)
     * @param string $final_path Final destination path
     * @param string $content File content to write
     * @return bool True on success, false on failure
     */
    private function atomic_write($dir, $final_path, $content)
    {
        $tmp_path = tempnam($dir, 'metasync-sitemap-tmp-');
        if ($tmp_path === false) {
            return false;
        }

        if (file_put_contents($tmp_path, $content) === false) {
            $this->safe_unlink($tmp_path);
            return false;
        }

        if (rename($tmp_path, $final_path)) {
            return true;
        }

        $this->safe_unlink($tmp_path);
        return false;
    }

    /**
     * Unlink a file with WP_DEBUG-gated error logging instead of @ suppression.
     *
     * @param string $path File path to delete
     */
    private function safe_unlink($path)
    {
        if (file_exists($path) && !unlink($path)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MetaSync: failed to remove temp file: ' . $path);
            }
        }
    }

    /**
     * Remove orphaned temp files from previous crashed/timed-out generations.
     */
    private function cleanup_temp_sitemap_files()
    {
        $dirs = array_unique([dirname($this->sitemap_index_path), ABSPATH]);
        foreach ($dirs as $dir) {
            $pattern = $dir . '/metasync-sitemap-tmp-*';
            $stale_files = glob($pattern);
            if (!is_array($stale_files)) {
                continue;
            }
            foreach ($stale_files as $file) {
                $this->safe_unlink($file);
            }
        }
    }

    /**
     * Generate the sitemap index file
     *
     * @param array $sitemap_files Array of sitemap file info
     * @param bool $force_memory Whether to skip streaming and use in-memory generation
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    private function generate_sitemap_index($sitemap_files, $force_memory = false)
    {
        $xml_content = $this->build_sitemap_index_xml_string($sitemap_files);
        $this->store_virtual_sitemap_file('sitemap_index.xml', $xml_content);
        return true;
    }

    /**
     * Get the most recent lastmod date from a chunk of URLs
     *
     * @param array $urls Array of URL data
     * @return string The most recent lastmod date
     */
    private function get_chunk_lastmod($urls)
    {
        $latest = '';
        foreach ($urls as $url) {
            if (!empty($url['lastmod']) && $url['lastmod'] > $latest) {
                $latest = $url['lastmod'];
            }
        }
        return $latest ?: current_time('mysql', 1);
    }

    /**
     * Get SEO controls/indexation settings
     *
     * @return array The SEO controls settings
     */
    private function get_seo_controls()
    {
        if (class_exists('Metasync')) {
            return Metasync::get_option('seo_controls', []);
        }
        return get_option('metasync_seo_controls', []);
    }

    /**
     * Check if a taxonomy is set to noindex in indexation controls
     *
     * @param string $taxonomy The taxonomy name
     * @param array $seo_controls The SEO controls settings
     * @return bool True if the taxonomy should be excluded from sitemap
     */
    private function is_taxonomy_noindex($taxonomy, $seo_controls)
    {
        // Map taxonomy names to their indexation control settings
        $taxonomy_settings_map = [
            'post_tag' => 'index_tag_archives',
            'category' => 'index_category_archives',
            'post_format' => 'index_format_archives',
        ];
        
        // Check if this taxonomy has an indexation control setting
        if (isset($taxonomy_settings_map[$taxonomy])) {
            $setting_key = $taxonomy_settings_map[$taxonomy];
            $setting_value = $seo_controls[$setting_key] ?? false;
            
            // If the setting is 'true' or true, it means noindex is enabled
            // (The setting name is "index_*" but when true, it means "disable indexing")
            if ($setting_value === 'true' || $setting_value === true) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Add URL to sitemap
     *
     * @param DOMDocument $xml The XML document
     * @param DOMElement $urlset The urlset element
     * @param string $loc The URL location
     * @param string $lastmod The last modified date
     * @param string $priority The priority
     * @param string $changefreq The change frequency
     */
    private function add_url_to_sitemap($xml, $urlset, $loc, $lastmod, $priority, $changefreq)
    {
        $url = $xml->createElement('url');

        $loc_element = $xml->createElement('loc', esc_url($loc));
        $url->appendChild($loc_element);

        if (!empty($lastmod)) {
            $lastmod_formatted = gmdate('Y-m-d\TH:i:s+00:00', strtotime($lastmod));
            $lastmod_element = $xml->createElement('lastmod', $lastmod_formatted);
            $url->appendChild($lastmod_element);
        }

        $changefreq_element = $xml->createElement('changefreq', $changefreq);
        $url->appendChild($changefreq_element);

        $priority_element = $xml->createElement('priority', $priority);
        $url->appendChild($priority_element);

        $urlset->appendChild($url);
    }

    /**
     * Check if sitemap index exists
     *
     * @return bool
     */
    public function sitemap_exists()
    {
        // Check virtual content first (now the primary storage path).
        $virtual_content = $this->get_virtual_sitemap_file('sitemap_index.xml');
        if (false !== $virtual_content) {
            return true;
        }

        // Check tracked sitemap files option (survives transient eviction).
        $tracked = get_option('metasync_sitemap_files', []);
        if (!empty($tracked)) {
            return true;
        }

        // Fall back to physical file for legacy installs that haven't migrated yet.
        return file_exists($this->sitemap_index_path);
    }

    /**
     * Get sitemap index content
     *
     * @return string|false
     */
    public function get_sitemap_content()
    {
        // Check virtual content first (now the primary storage path).
        $virtual_content = $this->get_virtual_sitemap_file('sitemap_index.xml');
        if (false !== $virtual_content) {
            return $virtual_content;
        }

        // Fall back to physical file for legacy installs that haven't migrated yet.
        if (file_exists($this->sitemap_index_path)) {
            return file_get_contents($this->sitemap_index_path);
        }

        return false;
    }

    /**
     * Get sitemap index URL
     *
     * @return string
     */
    public function get_sitemap_url()
    {
        return $this->sitemap_index_url;
    }

    /**
     * Get total size of all sitemap files
     *
     * @return int|false
     */
    public function get_sitemap_size()
    {
        $sitemap_files = get_option('metasync_sitemap_files', []);
        if (empty($sitemap_files)) {
            return false;
        }

        $total_size = 0;

        // Add index file size
        if (file_exists($this->sitemap_index_path)) {
            $total_size += filesize($this->sitemap_index_path);
        }

        // Add all sitemap files size
        foreach ($sitemap_files as $sitemap) {
            $path = ABSPATH . $sitemap['filename'];
            if (file_exists($path)) {
                $total_size += filesize($path);
            }
        }

        return $total_size;
    }

    /**
     * Get last generation time
     *
     * @return string|false
     */
    public function get_last_generated_time()
    {
        return get_option('metasync_sitemap_last_generated', false);
    }

    /**
     * Count total URLs across all sitemaps
     *
     * @return int
     */
    public function count_urls()
    {
        return (int) get_option('metasync_sitemap_total_urls', 0);
    }

    /**
     * Get the list of generated sitemap files
     *
     * @return array
     */
    public function get_sitemap_files()
    {
        return get_option('metasync_sitemap_files', []);
    }

    /**
     * Get the number of sitemap files
     *
     * @return int
     */
    public function get_sitemap_count()
    {
        $files = $this->get_sitemap_files();
        return count($files);
    }

    /**
     * Get the maximum URLs per sitemap file
     *
     * @return int
     */
    public function get_urls_per_sitemap()
    {
        return $this->urls_per_sitemap;
    }

    /**
     * Delete sitemap files (physical and virtual)
     *
     * @param string $type One of 'all', 'general', 'news', 'video'. Defaults to 'all'.
     * @return bool
     */
    public function delete_sitemap($type = 'all')
    {
        if (!in_array($type, ['all', 'general', 'news', 'video'], true)) {
            return false;
        }

        $deleted = false;

        if ('general' === $type || 'all' === $type) {
            // Delete physical sitemap index
            if (file_exists($this->sitemap_index_path)) {
                @unlink($this->sitemap_index_path);
                $deleted = true;
            }

            // Delete all physical sitemap files (sitemap.xml, sitemap2.xml, etc.)
            $sitemap_files = get_option('metasync_sitemap_files', []);
            foreach ($sitemap_files as $sitemap) {
                $path = ABSPATH . $sitemap['filename'];
                if (file_exists($path)) {
                    @unlink($path);
                    $deleted = true;
                }
            }

            // Also check for any sitemap*.xml files that might exist
            $files = glob(ABSPATH . 'sitemap*.xml');
            if ($files) {
                foreach ($files as $file) {
                    // Only delete sitemap files that match our pattern
                    if (preg_match('/sitemap\d*\.xml$/', basename($file))) {
                        @unlink($file);
                        $deleted = true;
                    }
                }
            }
        }

        if ('news' === $type || 'all' === $type) {
            $news_file = 'news-sitemap.xml';
            $tkey = 'metasync_vsm_' . md5($news_file);
            if (false !== get_transient($tkey)) {
                delete_transient($tkey);
                $deleted = true;
            }
            $physical = ABSPATH . $news_file;
            if (file_exists($physical)) {
                @unlink($physical);
                $deleted = true;
            }
        }

        if ('video' === $type || 'all' === $type) {
            $video_file = 'video-sitemap.xml';
            $tkey = 'metasync_vsm_' . md5($video_file);
            if (false !== get_transient($tkey)) {
                delete_transient($tkey);
                $deleted = true;
            }
            $physical = ABSPATH . $video_file;
            if (file_exists($physical)) {
                @unlink($physical);
                $deleted = true;
            }
        }

        if ('general' === $type || 'all' === $type) {
            // Clear tracked virtual sitemap transients, preserving news/video
            // when deleting only the general sitemap.
            $preserved_filenames = ['news-sitemap.xml', 'video-sitemap.xml'];
            $virtual_index = get_option('metasync_sitemap_virtual_index', []);
            if (is_array($virtual_index)) {
                $remaining_index = [];
                foreach ($virtual_index as $filename => $tkey) {
                    if ('general' === $type && in_array($filename, $preserved_filenames, true)) {
                        $remaining_index[$filename] = $tkey;
                        continue;
                    }
                    if (is_string($tkey) && $tkey !== '') {
                        delete_transient($tkey);
                        $deleted = true;
                    }
                }
                if (!empty($remaining_index)) {
                    update_option('metasync_sitemap_virtual_index', $remaining_index, false);
                } else {
                    delete_option('metasync_sitemap_virtual_index');
                }
            }

            // Clear virtual content if it exists.
            // Only fully disable virtual mode when deleting all — news/video
            // still need it when only the general sitemap is removed.
            if ('all' === $type) {
                $virtual_mode = get_option('metasync_sitemap_virtual_mode', false);
                $virtual_files = get_option('metasync_sitemap_virtual', array());
                if ($virtual_mode || !empty($virtual_files)) {
                    delete_option('metasync_sitemap_virtual');
                    delete_option('metasync_sitemap_virtual_mode');
                    $deleted = true;
                }
            }

            // Clear stored options
            delete_option('metasync_sitemap_files');
            delete_option('metasync_sitemap_total_urls');
            delete_option('metasync_sitemap_last_generated');
        }

        return $deleted;
    }

    /**
     * Check if other sitemap plugins are active
     *
     * @return array Array of active sitemap plugins
     */
    public function check_active_sitemap_plugins()
    {
        // Ensure plugin.php is loaded for is_plugin_active()
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $sitemap_plugins = [
            'wordpress-seo/wp-seo.php' => 'Yoast SEO',
            'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
            'google-sitemap-generator/sitemap.php' => 'Google XML Sitemaps',
            'better-wp-google-xml-sitemaps/bwp-simple-gxs.php' => 'Better WordPress Google XML Sitemaps',
            'xml-sitemap-feed/xml-sitemap.php' => 'XML Sitemap & Google News',
            'sitemap/sitemap.php' => 'Google Sitemap Plugin',
            'rank-math/rank-math.php' => 'Rank Math SEO',
            'seo-by-rank-math/rank-math.php' => 'Rank Math SEO (Free)',
            'wordpress-seo-premium/wp-seo-premium.php' => 'Yoast SEO Premium',
        ];

        $active_plugins = [];
        foreach ($sitemap_plugins as $plugin_path => $plugin_name) {
            if (is_plugin_active($plugin_path)) {
                $active_plugins[$plugin_path] = $plugin_name;
            }
        }

        return $active_plugins;
    }

    /**
     * Disable sitemap generation from other plugins
     *
     * @return bool True if any changes were made
     */
    public function disable_other_sitemap_generators()
    {
        $changes_made = false;

        // Disable Yoast SEO sitemap
        if (class_exists('WPSEO_Options')) {
            $yoast_options = get_option('wpseo');
            if (isset($yoast_options['enable_xml_sitemap']) && $yoast_options['enable_xml_sitemap'] === true) {
                $yoast_options['enable_xml_sitemap'] = false;
                update_option('wpseo', $yoast_options);
                $changes_made = true;
            }
        }

        // Disable All in One SEO sitemap (options stored as JSON string)
        if (function_exists('aioseo')) {
            $aioseo_raw = get_option('aioseo_options');
            $aioseo_options = is_string($aioseo_raw) ? json_decode($aioseo_raw, true) : $aioseo_raw;
            if (is_array($aioseo_options) && !empty($aioseo_options['sitemap']['general']['enable'])) {
                $aioseo_options['sitemap']['general']['enable'] = false;
                update_option('aioseo_options', is_string($aioseo_raw) ? wp_json_encode($aioseo_options) : $aioseo_options);
                $changes_made = true;
            }
        }

        // Disable Rank Math sitemap
        if (class_exists('RankMath')) {
            $rank_math_modules = get_option('rank_math_modules', []);
            if (is_array($rank_math_modules) && in_array('sitemap', $rank_math_modules, true)) {
                $rank_math_modules = array_values(array_diff($rank_math_modules, ['sitemap']));
                update_option('rank_math_modules', $rank_math_modules);
                $changes_made = true;
            }
        }

        // Disable WordPress core sitemap (WP 5.5+) - Store in option for persistence
        $wp_core_disabled = get_option('metasync_disable_wp_sitemap', false);
        if (!$wp_core_disabled) {
            update_option('metasync_disable_wp_sitemap', true);
            $changes_made = true;
        }

        return $changes_made;
    }

    /**
     * Re-enable sitemap generation from other plugins
     *
     * @return bool True if any changes were made
     */
    public function enable_other_sitemap_generators()
    {
        $changes_made = false;

        // Re-enable Yoast SEO sitemap
        if (class_exists('WPSEO_Options')) {
            $yoast_options = get_option('wpseo');
            if (isset($yoast_options['enable_xml_sitemap']) && $yoast_options['enable_xml_sitemap'] === false) {
                $yoast_options['enable_xml_sitemap'] = true;
                update_option('wpseo', $yoast_options);
                $changes_made = true;
            }
        }

        // Re-enable All in One SEO sitemap (options stored as JSON string)
        if (function_exists('aioseo')) {
            $aioseo_raw = get_option('aioseo_options');
            $aioseo_options = is_string($aioseo_raw) ? json_decode($aioseo_raw, true) : $aioseo_raw;
            if (is_array($aioseo_options) && isset($aioseo_options['sitemap']['general']['enable']) && !$aioseo_options['sitemap']['general']['enable']) {
                $aioseo_options['sitemap']['general']['enable'] = true;
                update_option('aioseo_options', is_string($aioseo_raw) ? wp_json_encode($aioseo_options) : $aioseo_options);
                $changes_made = true;
            }
        }

        // Re-enable Rank Math sitemap
        if (class_exists('RankMath')) {
            $rank_math_modules = get_option('rank_math_modules', []);
            if (is_array($rank_math_modules) && !in_array('sitemap', $rank_math_modules, true)) {
                $rank_math_modules[] = 'sitemap';
                update_option('rank_math_modules', $rank_math_modules);
                $changes_made = true;
            }
        }

        // Re-enable WordPress core sitemap (WP 5.5+)
        $wp_core_disabled = get_option('metasync_disable_wp_sitemap', false);
        if ($wp_core_disabled) {
            delete_option('metasync_disable_wp_sitemap');
            $changes_made = true;
        }

        return $changes_made;
    }

    /**
     * Setup automatic sitemap updates on post changes
     */
    public function setup_auto_update_hooks()
    {
        // Post hooks
        add_action('save_post', [$this, 'auto_update_sitemap'], 10, 2);
        add_action('delete_post', [$this, 'auto_update_sitemap_simple']);
        add_action('trashed_post', [$this, 'auto_update_sitemap_simple']);
        add_action('untrashed_post', [$this, 'auto_update_sitemap_simple']);

        // Term hooks
        add_action('created_term', [$this, 'auto_update_sitemap_simple']);
        add_action('edited_term', [$this, 'auto_update_sitemap_simple']);
        add_action('delete_term', [$this, 'auto_update_sitemap_simple']);

        // News sitemap auto-update on post save
        add_action('save_post', [$this, 'auto_update_news_sitemap'], 10, 2);

        // Video sitemap auto-update on post save
        add_action('save_post', [$this, 'auto_update_video_sitemap'], 10, 2);
    }

    /**
     * Auto update sitemap on post save
     *
     * @param int $post_id The post ID
     * @param WP_Post $post The post object
     */
    public function auto_update_sitemap($post_id, $post)
    {
        // Check if auto-update is enabled
        if (!get_option('metasync_sitemap_auto_update', false)) {
            return;
        }

        // Avoid auto-updates during autosave, revisions, or drafts
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        // Get post object if not provided (some hooks don't pass it)
        if (empty($post)) {
            $post = get_post($post_id);
        }

        // Validate post object exists
        if (!$post || !is_object($post)) {
            return;
        }

        // Only update for published posts
        if ($post->post_status !== 'publish') {
            return;
        }

        // Regenerate sitemap
        $this->generate_sitemap();
    }

    /**
     * Auto update sitemap (simple version for hooks without post object)
     */
    public function auto_update_sitemap_simple()
    {
        // Check if auto-update is enabled
        if (!get_option('metasync_sitemap_auto_update', false)) {
            return;
        }

        // Regenerate sitemap
        $this->generate_sitemap();
    }

    /**
     * Update robots.txt with the sitemap index URL
     *
     * @return array Result with 'success' boolean and 'action' string
     */
    public function update_robots_txt_sitemap()
    {
        // Load the robots.txt class if not already loaded
        if (!class_exists('Metasync_Robots_Txt')) {
            $robots_txt_file = plugin_dir_path(dirname(__FILE__)) . 'robots-txt/class-metasync-robots-txt.php';
            if (file_exists($robots_txt_file)) {
                require_once $robots_txt_file;
            } else {
                return [
                    'success' => false,
                    'action' => 'error',
                    'message' => esc_html__('Robots.txt management class not found.', 'metasync')
                ];
            }
        }

        // Get the robots.txt instance and update the sitemap index URL
        $robots_txt = Metasync_Robots_Txt::get_instance();
        return $robots_txt->update_sitemap_url($this->sitemap_index_url);
    }

    /**
     * Check if robots.txt contains the correct sitemap URL
     *
     * @return bool True if robots.txt has the correct sitemap URL
     */
    public function robots_has_sitemap_url()
    {
        // Load the robots.txt class if not already loaded
        if (!class_exists('Metasync_Robots_Txt')) {
            $robots_txt_file = plugin_dir_path(dirname(__FILE__)) . 'robots-txt/class-metasync-robots-txt.php';
            if (file_exists($robots_txt_file)) {
                require_once $robots_txt_file;
            } else {
                return false;
            }
        }

        $robots_txt = Metasync_Robots_Txt::get_instance();
        return $robots_txt->has_sitemap_url($this->sitemap_index_url);
    }

    /**
     * Generate the news sitemap.
     *
     * @return bool True on success, false on failure.
     */
    public function generate_news_sitemap()
    {
        require_once plugin_dir_path(__FILE__) . 'class-metasync-sitemap-news.php';

        $news = new Metasync_Sitemap_News();
        $xml = $news->generate();

        if (false === $xml) {
            return false;
        }

        $this->store_virtual_sitemap_file('news-sitemap.xml', $xml);

        // Add to robots.txt
        if (!class_exists('Metasync_Robots_Txt')) {
            $robots_txt_file = plugin_dir_path(dirname(__FILE__)) . 'robots-txt/class-metasync-robots-txt.php';
            if (file_exists($robots_txt_file)) {
                require_once $robots_txt_file;
            }
        }

        if (class_exists('Metasync_Robots_Txt')) {
            $robots_txt = Metasync_Robots_Txt::get_instance();
            $robots_txt->add_sitemap_url(home_url('/news-sitemap.xml'));
        }

        // Ping Google
        $news->ping_google(home_url('/news-sitemap.xml'));

        return true;
    }

    /**
     * Generate the video sitemap.
     *
     * @return bool True on success, false on failure.
     */
    public function generate_video_sitemap()
    {
        require_once plugin_dir_path(__FILE__) . 'class-metasync-sitemap-video.php';

        $video = new Metasync_Sitemap_Video();
        $xml = $video->generate();

        if (false === $xml) {
            return false;
        }

        $this->store_virtual_sitemap_file('video-sitemap.xml', $xml);

        // Add to robots.txt
        if (!class_exists('Metasync_Robots_Txt')) {
            $robots_txt_file = plugin_dir_path(dirname(__FILE__)) . 'robots-txt/class-metasync-robots-txt.php';
            if (file_exists($robots_txt_file)) {
                require_once $robots_txt_file;
            }
        }

        if (class_exists('Metasync_Robots_Txt')) {
            $robots_txt = Metasync_Robots_Txt::get_instance();
            $robots_txt->add_sitemap_url(home_url('/video-sitemap.xml'));
        }

        return true;
    }

    /**
     * Auto-update news sitemap on post save.
     *
     * @param int     $post_id The post ID.
     * @param WP_Post $post    The post object.
     */
    public function auto_update_news_sitemap($post_id, $post)
    {
        $settings = get_option('metasync_news_sitemap_settings', []);
        if (empty($settings['enabled'])) {
            return;
        }

        // Skip autosave and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        if (empty($post)) {
            $post = get_post($post_id);
        }

        if (!$post || !is_object($post)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        // Only regenerate if the post was modified within the last 2 days
        $post_time = strtotime($post->post_modified_gmt);
        $cutoff = strtotime('-2 days');
        if (false === $post_time || false === $cutoff || $post_time < $cutoff) {
            return;
        }

        $this->generate_news_sitemap();
    }

    /**
     * Auto-update video sitemap on post save.
     *
     * @param int     $post_id The post ID.
     * @param WP_Post $post    The post object.
     */
    public function auto_update_video_sitemap($post_id, $post)
    {
        $settings = get_option('metasync_video_sitemap_settings', []);
        if (empty($settings['enabled'])) {
            return;
        }

        // Skip autosave and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        if (empty($post)) {
            $post = get_post($post_id);
        }

        if (!$post || !is_object($post)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        // Only regenerate if the post type is included in video sitemap settings
        $post_types = !empty($settings['post_types']) ? (array) $settings['post_types'] : ['post', 'page'];
        if (!in_array($post->post_type, $post_types, true)) {
            return;
        }

        $this->generate_video_sitemap();
    }

    /**
     * Store virtual sitemap file content using individual transients.
     *
     * @param string $filename The sitemap filename (e.g., 'sitemap.xml', 'sitemap_index.xml')
     * @param string $content The XML content
     * @return bool True on success
     */
    public function store_virtual_sitemap_file($filename, $content)
    {
        $cache_key = 'metasync_vsm_' . md5($filename);
        set_transient($cache_key, $content, 30 * DAY_IN_SECONDS);

        // Track which virtual sitemaps exist
        $index = get_option('metasync_sitemap_virtual_index', []);
        $index[$filename] = $cache_key;
        update_option('metasync_sitemap_virtual_index', $index, false);
        update_option('metasync_sitemap_virtual_mode', true, false);
        return true;
    }

    /**
     * Get virtual sitemap file content from transient.
     *
     * @param string $filename The sitemap filename
     * @return string|false Content or false if not found
     */
    private function get_virtual_sitemap_file($filename)
    {
        $cache_key = 'metasync_vsm_' . md5($filename);
        $content = get_transient($cache_key);

        if (false !== $content) {
            return $content;
        }

        // Fallback: check legacy option storage for backward compatibility
        $virtual_sitemaps = get_option('metasync_sitemap_virtual', []);
        if (isset($virtual_sitemaps[$filename])) {
            // Migrate to transient
            $this->store_virtual_sitemap_file($filename, $virtual_sitemaps[$filename]);
            unset($virtual_sitemaps[$filename]);
            if (empty($virtual_sitemaps)) {
                delete_option('metasync_sitemap_virtual');
            } else {
                update_option('metasync_sitemap_virtual', $virtual_sitemaps, false);
            }
            return get_transient($cache_key);
        }

        return false;
    }

    /**
     * Check if virtual mode is active
     *
     * @return bool True if virtual mode is active
     */
    public function is_virtual_mode()
    {
        return get_option('metasync_sitemap_virtual_mode', false) === true;
    }

    /**
     * Serve sitemap files via template_redirect.
     *
     * Resolves the requested sitemap filename in two ways: first via the
     * `metasync_sitemap` query var populated by our rewrite rules (the primary
     * path), and second via REQUEST_URI parsing (kept as a fallback for sites
     * with rewrites disabled). When a physical file exists it is streamed
     * directly by PHP — letting the web server serve it is unsafe because some
     * nginx configurations 403 static .xml files.
     */
    public function serve_virtual_sitemap()
    {
        if (is_admin()) {
            return;
        }

        $filename = '';

        // Primary path: query var set by our rewrite rule.
        $qv = get_query_var('metasync_sitemap');
        if (!empty($qv)) {
            $filename = sanitize_file_name($qv);
            if (!preg_match('/^(news-|video-)?sitemap(_index)?\d*\.xml$/', $filename)) {
                return;
            }
        } else {
            // Fallback path: REQUEST_URI regex for sites where rewrite rules are
            // not engaged (e.g. pretty permalinks disabled or pre-flush state).
            $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            $request_uri = strtok($request_uri, '?');

            $sitemap_pattern = '/\/((news-|video-)?sitemap(_index)?\d*\.xml)$/';
            if (!preg_match($sitemap_pattern, $request_uri, $matches)) {
                return;
            }

            $filename = $matches[1];
        }

        if (empty($filename)) {
            return;
        }

        // If a physical file exists, stream it ourselves. We cannot rely on the
        // web server to serve it: on some nginx configurations direct .xml
        // requests are 403'd before PHP is involved.
        $physical_path = ABSPATH . $filename;
        if (file_exists($physical_path) && is_readable($physical_path)) {
            header('Content-Type: application/xml; charset=utf-8');
            header('X-Robots-Tag: noindex');
            status_header(200);
            readfile($physical_path);
            exit;
        }

        // Check if we have virtual content
        $virtual_content = $this->get_virtual_sitemap_file($filename);

        // Regenerate-on-miss: if the transient expired, rebuild on the spot
        // instead of returning 404. Only regenerate for known sitemaps to
        // prevent DoS via requests to non-existent sitemap numbers.
        if (false === $virtual_content) {
            if ($filename === 'news-sitemap.xml') {
                $this->generate_news_sitemap();
            } elseif ($filename === 'video-sitemap.xml') {
                $this->generate_video_sitemap();
            } else {
                $known_files = get_option('metasync_sitemap_files', []);
                $known_names = array_column($known_files, 'filename');
                $known_names[] = 'sitemap_index.xml';
                if (empty($known_files) || in_array($filename, $known_names, true)) {
                    $this->generate_sitemap();
                }
            }
            $virtual_content = $this->get_virtual_sitemap_file($filename);
        }

        if (false === $virtual_content) {
            return;
        }

        header('Content-Type: application/xml; charset=utf-8');
        header('X-Robots-Tag: noindex');
        status_header(200);
        echo $virtual_content;
        exit;
    }
}
