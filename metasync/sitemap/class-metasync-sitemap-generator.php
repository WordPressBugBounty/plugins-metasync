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
    }

    /**
     * Generate the XML sitemap (split into multiple files if needed)
     *
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function generate_sitemap()
    {
        try {
            // Delete existing sitemap files first
            $this->delete_sitemap();

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

                $result = $this->generate_sitemap_file($sitemap_path, $urls);

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

            // Generate sitemap index file
            $index_result = $this->generate_sitemap_index($sitemap_files);

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
        $urls = [];

        // Get all published posts, pages, and custom post types
        $post_types = get_post_types(['public' => true], 'names');

        // Exclude certain post types
        $excluded_types = [
            'attachment',
            'revision',
            'nav_menu_item',
            'elementor_library',
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
        ];
        $post_types = array_diff($post_types, $excluded_types);

        $args = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        $posts = get_posts($args);

        // Get indexation control settings
        $seo_controls = $this->get_seo_controls();

        // Add homepage first
        $urls[] = [
            'loc' => home_url('/'),
            'lastmod' => get_lastpostmodified('gmt'),
            'priority' => '1.0',
            'changefreq' => 'daily',
        ];

        // Add posts, pages, and custom post types
        foreach ($posts as $post) {
            // Skip posts/pages marked as noindex
            if ($this->is_post_noindex($post->ID)) {
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

            $urls[] = [
                'loc' => get_permalink($post->ID),
                'lastmod' => $post->post_modified_gmt,
                'priority' => $priority,
                'changefreq' => $changefreq,
            ];
        }

        // Add taxonomies (categories, tags, etc.)
        $taxonomies = get_taxonomies(['public' => true], 'names');
        foreach ($taxonomies as $taxonomy) {
            // Skip taxonomies that are set to noindex
            if ($this->is_taxonomy_noindex($taxonomy, $seo_controls)) {
                continue;
            }

            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => true,
            ]);

            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $term) {
                    $term_link = get_term_link($term);
                    if (!is_wp_error($term_link)) {
                        $urls[] = [
                            'loc' => $term_link,
                            'lastmod' => $this->get_term_last_modified($term->term_id, $taxonomy),
                            'priority' => '0.6',
                            'changefreq' => 'weekly',
                        ];
                    }
                }
            }
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
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    private function generate_sitemap_file($path, $urls)
    {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        // Create urlset element
        $urlset = $xml->createElement('urlset');
        $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urlset->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $urlset->setAttribute('xsi:schemaLocation', 'http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd');
        $xml->appendChild($urlset);

        // Add URLs
        foreach ($urls as $url_data) {
            $this->add_url_to_sitemap($xml, $urlset, $url_data['loc'], $url_data['lastmod'], $url_data['priority'], $url_data['changefreq']);
        }

        // Preflight check: Verify write permissions before attempting save
        $dir = dirname($path);
        if (!is_writable($dir) && (!file_exists($path) || !is_writable($path))) {
            error_log('Metasync Sitemap: Cannot write sitemap file to ' . $path . ' - directory is not writable');
            return false;
        }

        // Save the XML file
        $saved = $xml->save($path);

        if ($saved === false) {
           # return new WP_Error('sitemap_save_failed', 'Failed to save sitemap file: ' . basename($path));
           error_log('Metasync Sitemap: Failed to save sitemap file: ' . basename($path));
            return false;
        }

        return true;
    }

    /**
     * Generate the sitemap index file
     *
     * @param array $sitemap_files Array of sitemap file info
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    private function generate_sitemap_index($sitemap_files)
    {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        // Create sitemapindex element
        $sitemapindex = $xml->createElement('sitemapindex');
        $sitemapindex->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->appendChild($sitemapindex);

        // Add each sitemap to the index
        foreach ($sitemap_files as $sitemap) {
            $sitemap_element = $xml->createElement('sitemap');

            $loc = $xml->createElement('loc', esc_url($sitemap['url']));
            $sitemap_element->appendChild($loc);

            if (!empty($sitemap['lastmod'])) {
                $lastmod_formatted = date('Y-m-d\TH:i:s+00:00', strtotime($sitemap['lastmod']));
                $lastmod = $xml->createElement('lastmod', $lastmod_formatted);
                $sitemap_element->appendChild($lastmod);
            }

            $sitemapindex->appendChild($sitemap_element);
        }

         // Preflight check: Verify write permissions before attempting save
        $dir = dirname($this->sitemap_index_path);
        if (!is_writable($dir) && (!file_exists($this->sitemap_index_path) || !is_writable($this->sitemap_index_path))) {
            error_log('Metasync Sitemap: Cannot write sitemap index file to ' . $this->sitemap_index_path . ' - directory is not writable');
            return false;
        }

        // Save the index file
        $saved = $xml->save($this->sitemap_index_path);

        if ($saved === false) {
            # return new WP_Error('sitemap_index_save_failed', 'Failed to save sitemap index file.');
            error_log('Metasync Sitemap: Failed to save sitemap index file');
            return false;
        }

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
     * Get the last modified date for a taxonomy term
     *
     * @param int $term_id The term ID
     * @param string $taxonomy The taxonomy name
     * @return string The last modified date in MySQL format (GMT)
     */
    private function get_term_last_modified($term_id, $taxonomy)
    {
        // Get the most recently modified post in this term
        $recent_post = get_posts([
            'tax_query' => [
                [
                    'taxonomy' => $taxonomy,
                    'field' => 'term_id',
                    'terms' => $term_id,
                ],
            ],
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);

        if (!empty($recent_post)) {
            return $recent_post[0]->post_modified_gmt;
        }

        // Fallback to current time if no posts found
        return current_time('mysql', 1);
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
     * Check if a post/page is set to noindex
     *
     * @param int $post_id The post ID
     * @return bool True if the post should be excluded from sitemap
     */
    private function is_post_noindex($post_id)
    {
        // Check post-specific robots meta settings
        $common_robots = get_post_meta($post_id, 'metasync_common_robots', true);
        
        if (!empty($common_robots) && is_array($common_robots)) {
            // Check if noindex is set
            if (isset($common_robots['noindex']) && $common_robots['noindex'] === 'noindex') {
                return true;
            }
        }
        
        return false;
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
            $lastmod_formatted = date('Y-m-d\TH:i:s+00:00', strtotime($lastmod));
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
        return file_exists($this->sitemap_index_path);
    }

    /**
     * Get sitemap index content
     *
     * @return string|false
     */
    public function get_sitemap_content()
    {
        if ($this->sitemap_exists()) {
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
     * Delete all sitemap files
     *
     * @return bool
     */
    public function delete_sitemap()
    {
        $deleted = false;

        // Delete sitemap index
        if (file_exists($this->sitemap_index_path)) {
            unlink($this->sitemap_index_path);
            $deleted = true;
        }

        // Delete all sitemap files (sitemap.xml, sitemap2.xml, etc.)
        $sitemap_files = get_option('metasync_sitemap_files', []);
        foreach ($sitemap_files as $sitemap) {
            $path = ABSPATH . $sitemap['filename'];
            if (file_exists($path)) {
                unlink($path);
                $deleted = true;
            }
        }

        // Also check for any sitemap*.xml files that might exist
        $files = glob(ABSPATH . 'sitemap*.xml');
        if ($files) {
            foreach ($files as $file) {
                // Only delete sitemap files that match our pattern
                if (preg_match('/sitemap\d*\.xml$/', basename($file))) {
                    unlink($file);
                    $deleted = true;
                }
            }
        }

        // Clear stored options
        delete_option('metasync_sitemap_files');
        delete_option('metasync_sitemap_total_urls');

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

        // Disable All in One SEO sitemap
        if (function_exists('aioseo')) {
            $aioseo_options = get_option('aioseo_options');
            if ($aioseo_options && isset($aioseo_options['sitemap']['general']['enable']) && $aioseo_options['sitemap']['general']['enable'] === true) {
                $aioseo_options['sitemap']['general']['enable'] = false;
                update_option('aioseo_options', $aioseo_options);
                $changes_made = true;
            }
        }

        // Disable Rank Math sitemap
        if (class_exists('RankMath')) {
            $rank_math_options = get_option('rank-math-options-sitemap');
            if ($rank_math_options && isset($rank_math_options['sitemap_disable']) && $rank_math_options['sitemap_disable'] !== 'on') {
                $rank_math_options['sitemap_disable'] = 'on';
                update_option('rank-math-options-sitemap', $rank_math_options);
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

        // Re-enable All in One SEO sitemap
        if (function_exists('aioseo')) {
            $aioseo_options = get_option('aioseo_options');
            if ($aioseo_options && isset($aioseo_options['sitemap']['general']['enable']) && $aioseo_options['sitemap']['general']['enable'] === false) {
                $aioseo_options['sitemap']['general']['enable'] = true;
                update_option('aioseo_options', $aioseo_options);
                $changes_made = true;
            }
        }

        // Re-enable Rank Math sitemap
        if (class_exists('RankMath')) {
            $rank_math_options = get_option('rank-math-options-sitemap');
            if ($rank_math_options && isset($rank_math_options['sitemap_disable']) && $rank_math_options['sitemap_disable'] === 'on') {
                $rank_math_options['sitemap_disable'] = 'off';
                update_option('rank-math-options-sitemap', $rank_math_options);
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
}
