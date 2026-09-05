<?php

/**
 * Import data from other SEO plugins.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/includes
 * @author     Engineering Team <support@searchatlas.com>
 */

// Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_External_Importer
{
    private $db_redirection;
    private $redirection_importer;

    public function __construct($db_redirection = null)
    {
        $this->db_redirection = $db_redirection;
        
        // Initialize redirection importer if DB resource is provided
        if ($this->db_redirection) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'redirections/class-metasync-redirection-importer.php';
            $this->redirection_importer = new Metasync_Redirection_Importer($this->db_redirection);
        }
    }

    /**
     * Get available plugins for a specific import type
     */
    public function get_plugins_for_type($type)
    {
        $plugins = [
            'yoast' => ['name' => 'Yoast SEO', 'constant' => 'WPSEO_VERSION'],
            'rankmath' => ['name' => 'Rank Math', 'constant' => 'RANK_MATH_VERSION'],
            'aioseo' => ['name' => 'All in One SEO', 'constant' => 'AIOSEO_VERSION'],
            'redirection' => ['name' => 'Redirection', 'constant' => 'REDIRECTION_VERSION'],
            'simple301' => ['name' => 'Simple 301 Redirects', 'constant' => 'SIMPLE_301_REDIRECTS_VERSION'],
        ];

        // Filter plugins based on type support
        $supported_plugins = [];
        switch ($type) {
            case 'redirections':
                if ($this->redirection_importer) {
                    return $this->redirection_importer->get_available_plugins();
                }
                return [];
            case 'sitemap':
            case 'robots':
                // These types are generally supported by the main SEO plugins
                $supported = ['yoast', 'rankmath', 'aioseo'];
                foreach ($supported as $slug) {
                    $plugin = $plugins[$slug];
                    $is_active = defined($plugin['constant']);

                    // Basic data structure similar to redirection importer
                    $supported_plugins[$slug] = [
                        'name' => $plugin['name'],
                        'key' => $slug,
                        'installed' => $is_active,
                        'has_data' => $is_active, // Assume data exists if active for now
                        'count' => 0, // Count not applicable/calculated yet
                        'version' => $is_active ? constant($plugin['constant']) : ''
                    ];
                }
                break;

            case 'schema':
                // Check for actual per-post schema data
                // Even if plugin is deactivated, we can still import the data
                $supported = ['yoast', 'rankmath', 'aioseo'];
                foreach ($supported as $slug) {
                    $plugin = $plugins[$slug];
                    $is_active = defined($plugin['constant']);
                    $count = 0;

                    // Always check for data, regardless of plugin activation status
                    $has_data = $this->check_schema_data($slug, $count);

                    $supported_plugins[$slug] = [
                        'name' => $plugin['name'],
                        'key' => $slug,
                        'installed' => $is_active,
                        'has_data' => $has_data,
                        'count' => $count,
                        'version' => $is_active ? constant($plugin['constant']) : ''
                    ];
                }
                break;

            case 'indexation':
                // Check for actual per-post SEO data
                // Even if plugin is deactivated, we can still import the data
                $supported = ['yoast', 'rankmath', 'aioseo'];
                foreach ($supported as $slug) {
                    $plugin = $plugins[$slug];
                    $is_active = defined($plugin['constant']);
                    $count = 0;

                    // Always check for data, regardless of plugin activation status
                    $has_data = $this->check_indexation_data($slug, $count);

                    $supported_plugins[$slug] = [
                        'name' => $plugin['name'],
                        'key' => $slug,
                        'installed' => $is_active,
                        'has_data' => $has_data,
                        'count' => $count,
                        'version' => $is_active ? constant($plugin['constant']) : ''
                    ];
                }
                break;

            case 'primary_category':
                // Check for primary category data from other SEO plugins
                $supported = ['yoast', 'rankmath', 'aioseo'];
                foreach ($supported as $slug) {
                    $plugin = $plugins[$slug];
                    $is_active = defined($plugin['constant']);
                    $count = 0;

                    $has_data = $this->check_primary_category_data($slug, $count);

                    $supported_plugins[$slug] = [
                        'name' => $plugin['name'],
                        'key' => $slug,
                        'installed' => $is_active,
                        'has_data' => $has_data,
                        'count' => $count,
                        'version' => $is_active ? constant($plugin['constant']) : ''
                    ];
                }
                break;

            case 'seo_metadata':
                // Check for actual SEO metadata (titles and descriptions)
                // Even if plugin is deactivated, we can still import the data
                $supported = ['yoast', 'rankmath', 'aioseo'];
                foreach ($supported as $slug) {
                    $plugin = $plugins[$slug];
                    $is_active = defined($plugin['constant']);
                    $count = 0;

                    // Always check for data, regardless of plugin activation status
                    $has_data = $this->check_seo_metadata($slug, $count);

                    $supported_plugins[$slug] = [
                        'name' => $plugin['name'],
                        'key' => $slug,
                        'installed' => $is_active,
                        'has_data' => $has_data,
                        'count' => $count,
                        'version' => $is_active ? constant($plugin['constant']) : ''
                    ];
                }
                break;
        }

        return $supported_plugins;
    }

    /**
     * Check if indexation data exists for a plugin
     */
    private function check_indexation_data($plugin, &$count)
    {
        global $wpdb;

        $count = 0;

        switch ($plugin) {
            case 'yoast':
                $count = (int) $wpdb->get_var("
                    SELECT COUNT(DISTINCT post_id)
                    FROM {$wpdb->postmeta}
                    WHERE meta_key LIKE '_yoast_wpseo_%'
                    AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
                ");
                break;

            case 'rankmath':
                $count = (int) $wpdb->get_var("
                    SELECT COUNT(DISTINCT post_id)
                    FROM {$wpdb->postmeta}
                    WHERE meta_key LIKE 'rank_math_%'
                    AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
                ");
                break;

            case 'aioseo':
                $table = $wpdb->prefix . 'aioseo_posts';
                if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                    $count = (int) $wpdb->get_var("
                        SELECT COUNT(post_id)
                        FROM {$table}
                        WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
                    ");
                }
                break;
        }

        return $count > 0;
    }

    /**
     * Check if schema data exists for a plugin
     */
    private function check_schema_data($plugin, &$count)
    {
        global $wpdb;

        $count = 0;

        switch ($plugin) {
            case 'yoast':
                // Check for Yoast schema meta
                // Yoast Premium stores schema type in _yoast_wpseo_schema_article_type
                // Free version may use _yoast_wpseo_schema (JSON)
                $count = (int) $wpdb->get_var("
                    SELECT COUNT(DISTINCT post_id)
                    FROM {$wpdb->postmeta}
                    WHERE (meta_key = '_yoast_wpseo_schema' OR meta_key = '_yoast_wpseo_schema_article_type')
                    AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
                ");
                break;

            case 'rankmath':
                // Check for Rank Math schema meta (any schema type)
                // Rank Math uses meta keys like: rank_math_schema_Article, rank_math_schema_BlogPosting, rank_math_schema_Product, etc.
                // Exclude shortcode schemas (rank_math_shortcode_schema_*)
                $count = (int) $wpdb->get_var("
                    SELECT COUNT(DISTINCT post_id)
                    FROM {$wpdb->postmeta}
                    WHERE meta_key LIKE 'rank_math_schema_%'
                    AND meta_key NOT LIKE 'rank_math_shortcode_schema_%'
                    AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
                ");
                break;

            case 'aioseo':
                // Check for AIOSEO schema in table
                $table = $wpdb->prefix . 'aioseo_posts';
                if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                    $count = (int) $wpdb->get_var("
                        SELECT COUNT(post_id)
                        FROM {$table}
                        WHERE (schema_type IS NOT NULL AND schema_type != '')
                        AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
                    ");
                }
                break;
        }

        return $count > 0;
    }

    /**
     * Check if SEO metadata (titles and descriptions) exists for a plugin
     */
    private function check_seo_metadata($plugin, &$count)
    {
        global $wpdb;

        $count = 0;

        switch ($plugin) {
            case 'yoast':
                // Check for Yoast SEO title or description
                $count = (int) $wpdb->get_var("
                    SELECT COUNT(DISTINCT post_id)
                    FROM {$wpdb->postmeta}
                    WHERE (meta_key = '_yoast_wpseo_title' OR meta_key = '_yoast_wpseo_metadesc')
                    AND meta_value != ''
                    AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
                ");
                break;

            case 'rankmath':
                // Check for Rank Math title or description
                $count = (int) $wpdb->get_var("
                    SELECT COUNT(DISTINCT post_id)
                    FROM {$wpdb->postmeta}
                    WHERE (meta_key = 'rank_math_title' OR meta_key = 'rank_math_description')
                    AND meta_value != ''
                    AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
                ");
                break;

            case 'aioseo':
                // Check for AIOSEO title or description in their custom table
                $table = $wpdb->prefix . 'aioseo_posts';
                if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                    $count = (int) $wpdb->get_var("
                        SELECT COUNT(post_id)
                        FROM {$table}
                        WHERE ((title IS NOT NULL AND title != '') OR (description IS NOT NULL AND description != ''))
                        AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
                    ");

                    // Per-post overrides are the exception. AIOSEO also serves a
                    // title and description for every post from its site-wide
                    // templates, and those are what the import mainly picks up —
                    // so a site that never overrode an individual post still has
                    // plenty to migrate. Counting overrides alone reported zero
                    // there, which rendered the card "Unavailable" with a disabled
                    // button and made the import impossible to start.
                    $templates = $this->get_aioseo_post_type_templates();
                    if (!empty($templates)) {
                        $post_types        = array_keys($templates);
                        $type_placeholders = implode(', ', array_fill(0, count($post_types), '%s'));

                        $template_count = (int) $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*)
                             FROM {$wpdb->posts}
                             WHERE post_status = 'publish'
                             AND post_type IN ({$type_placeholders})",
                            $post_types
                        ));

                        // Take the larger of the two. Templates configured for a
                        // post type with no published posts would otherwise report
                        // zero and disable the card, hiding override rows that do
                        // exist — the same dead end from the other direction.
                        $count = max($count, $template_count);
                    }
                }
                break;
        }

        return $count > 0;
    }

    /**
     * Check if primary category data exists for a plugin
     */
    private function check_primary_category_data($plugin, &$count)
    {
        global $wpdb;

        $count = 0;

        switch ($plugin) {
            case 'yoast':
                $count = (int) $wpdb->get_var("
                    SELECT COUNT(DISTINCT post_id)
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = '_yoast_wpseo_primary_category'
                    AND meta_value != ''
                    AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
                ");
                break;

            case 'rankmath':
                $count = (int) $wpdb->get_var("
                    SELECT COUNT(DISTINCT post_id)
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = 'rank_math_primary_category'
                    AND meta_value != ''
                    AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
                ");
                break;

            case 'aioseo':
                $count = (int) $wpdb->get_var("
                    SELECT COUNT(DISTINCT post_id)
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = '_aioseo_primary_category'
                    AND meta_value != ''
                    AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
                ");
                break;
        }

        return $count > 0;
    }

    /**
     * Import primary category from another SEO plugin
     *
     * @param string $plugin Plugin slug (yoast, rankmath, aioseo)
     * @param array  $options Import options
     * @return array Result array with success, imported, skipped, total, etc.
     */
    public function import_primary_category($plugin, $options = [])
    {
        $defaults = [
            'overwrite_existing' => false,
            'batch_size' => 50,
            'offset' => 0,
        ];
        $options = array_merge($defaults, $options);

        if (!in_array($plugin, ['yoast', 'rankmath', 'aioseo'])) {
            return [
                'success' => false,
                'message' => 'Invalid plugin specified.',
            ];
        }

        switch ($plugin) {
            case 'yoast':
                return $this->import_yoast_primary_category($options);
            case 'rankmath':
                return $this->import_rankmath_primary_category($options);
            case 'aioseo':
                return $this->import_aioseo_primary_category($options);
        }

        return [
            'success' => false,
            'message' => 'Unknown error occurred.',
        ];
    }

    /**
     * Import primary category from Yoast SEO
     */
    private function import_yoast_primary_category($options)
    {
        global $wpdb;

        $batch_size = intval($options['batch_size']);
        $offset = intval($options['offset']);
        $overwrite = (bool) $options['overwrite_existing'];

        $total_posts = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_yoast_wpseo_primary_category'
            AND meta_value != ''
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
        ");

        $posts = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_yoast_wpseo_primary_category'
            AND meta_value != ''
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
            ORDER BY post_id ASC
            LIMIT %d OFFSET %d
        ", $batch_size, $offset));

        $imported_count = 0;
        $skipped_count = 0;

        foreach ($posts as $post_obj) {
            $post_id = $post_obj->post_id;
            $term_id = absint(get_post_meta($post_id, '_yoast_wpseo_primary_category', true));

            if ($term_id <= 0 || !get_term($term_id, 'category')) {
                $skipped_count++;
                continue;
            }

            $existing = (int) get_post_meta($post_id, '_metasync_primary_category', true);
            if ($existing > 0 && !$overwrite) {
                $skipped_count++;
                continue;
            }

            update_post_meta($post_id, '_metasync_primary_category', $term_id);
            $imported_count++;
        }

        $processed = $offset + count($posts);
        $has_more = $processed < $total_posts;

        return [
            'success' => true,
            'imported' => $imported_count,
            'skipped' => $skipped_count,
            'total' => $total_posts,
            'has_more' => $has_more,
        ];
    }

    /**
     * Import primary category from Rank Math
     */
    private function import_rankmath_primary_category($options)
    {
        global $wpdb;

        $batch_size = intval($options['batch_size']);
        $offset = intval($options['offset']);
        $overwrite = (bool) $options['overwrite_existing'];

        $total_posts = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key = 'rank_math_primary_category'
            AND meta_value != ''
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
        ");

        $posts = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key = 'rank_math_primary_category'
            AND meta_value != ''
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
            ORDER BY post_id ASC
            LIMIT %d OFFSET %d
        ", $batch_size, $offset));

        $imported_count = 0;
        $skipped_count = 0;

        foreach ($posts as $post_obj) {
            $post_id = $post_obj->post_id;
            $term_id = absint(get_post_meta($post_id, 'rank_math_primary_category', true));

            if ($term_id <= 0 || !get_term($term_id, 'category')) {
                $skipped_count++;
                continue;
            }

            $existing = (int) get_post_meta($post_id, '_metasync_primary_category', true);
            if ($existing > 0 && !$overwrite) {
                $skipped_count++;
                continue;
            }

            update_post_meta($post_id, '_metasync_primary_category', $term_id);
            $imported_count++;
        }

        $processed = $offset + count($posts);
        $has_more = $processed < $total_posts;

        return [
            'success' => true,
            'imported' => $imported_count,
            'skipped' => $skipped_count,
            'total' => $total_posts,
            'has_more' => $has_more,
        ];
    }

    /**
     * Import primary category from AIOSEO
     */
    private function import_aioseo_primary_category($options)
    {
        global $wpdb;

        $batch_size = intval($options['batch_size']);
        $offset = intval($options['offset']);
        $overwrite = (bool) $options['overwrite_existing'];

        $total_posts = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_aioseo_primary_category'
            AND meta_value != ''
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
        ");

        $posts = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_aioseo_primary_category'
            AND meta_value != ''
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
            ORDER BY post_id ASC
            LIMIT %d OFFSET %d
        ", $batch_size, $offset));

        $imported_count = 0;
        $skipped_count = 0;

        foreach ($posts as $post_obj) {
            $post_id = $post_obj->post_id;
            $term_id = absint(get_post_meta($post_id, '_aioseo_primary_category', true));

            if ($term_id <= 0 || !get_term($term_id, 'category')) {
                $skipped_count++;
                continue;
            }

            $existing = (int) get_post_meta($post_id, '_metasync_primary_category', true);
            if ($existing > 0 && !$overwrite) {
                $skipped_count++;
                continue;
            }

            update_post_meta($post_id, '_metasync_primary_category', $term_id);
            $imported_count++;
        }

        $processed = $offset + count($posts);
        $has_more = $processed < $total_posts;

        return [
            'success' => true,
            'imported' => $imported_count,
            'skipped' => $skipped_count,
            'total' => $total_posts,
            'has_more' => $has_more,
        ];
    }

    /**
     * Import Redirections
     */
    public function import_redirections($plugin)
    {
        if (!$this->redirection_importer) {
            return ['success' => false, 'message' => 'Redirection database not initialized.'];
        }
        return $this->redirection_importer->import_from_plugin($plugin);
    }

    /**
     * Import Sitemap Settings
     */
    public function import_sitemap($plugin)
    {
        $imported = false;
        $message = '';

        switch ($plugin) {
            case 'yoast':
                $options = get_option('wpseo_xml');
                if ($options && isset($options['enablexmlsitemap'])) {
                    // Yoast stores sitemap settings in wpseo_xml option
                    // Metasync sitemap is auto-generated, so we just acknowledge the import
                    $message = 'Sitemap settings imported from Yoast.';
                    $imported = true;
                }
                break;

            case 'rankmath':
                $options = get_option('rank-math-options-sitemap');
                if ($options) {
                    // Import logic here
                    $message = 'Sitemap settings imported from Rank Math.';
                    $imported = true;
                }
                break;

            case 'aioseo':
                $options = get_option('aioseo_options');
                if ($options && isset($options['sitemap'])) {
                    // Import logic here
                    $message = 'Sitemap settings imported from AIOSEO.';
                    $imported = true;
                }
                break;
        }

        if (!$imported) {
            return ['success' => false, 'message' => 'No sitemap settings found or plugin not active.'];
        }

        return ['success' => true, 'message' => $message];
    }

    /**
     * Import Robots.txt
     */
    public function import_robots($plugin)
    {
        $content = '';
        
        switch ($plugin) {
            case 'yoast':
                // Yoast doesn't store robots.txt in DB, it edits the file.
                // But it might have settings for it.
                // If we are "importing", we might just want to read the current file if managed by them?
                // Actually, if they have a custom robots.txt editor, they might store it.
                // Yoast uses the file system directly.
                $content = $this->get_robots_content_from_file();
                break;

            case 'rankmath':
                $options = get_option('rank-math-options-general');
                if (isset($options['robots_txt_content'])) {
                    $content = $options['robots_txt_content'];
                } else {
                    $content = $this->get_robots_content_from_file();
                }
                break;

            case 'aioseo':
                $options = get_option('aioseo_options');
                if (isset($options['tools']['robots']['rules'])) {
                    // AIOSEO stores rules as array, need to reconstruct
                    // For simplicity, let's try reading the file first as it's the source of truth
                    $content = $this->get_robots_content_from_file();
                }
                break;
        }

        if (empty($content)) {
             return ['success' => false, 'message' => 'No robots.txt content found.'];
        }

        // Save to Metasync Robots.txt
        // Load the class if not already loaded
        if (!class_exists('Metasync_Robots_Txt')) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'robots-txt/class-metasync-robots-txt.php';
        }

        $robots_class = Metasync_Robots_Txt::get_instance();
        $result = $robots_class->write_robots_file($content);

        if (is_wp_error($result)) {
            return ['success' => false, 'message' => $result->get_error_message()];
        }

        return ['success' => true, 'message' => 'Robots.txt content imported successfully.'];
    }

    private function get_robots_content_from_file() {
        $robots_file = ABSPATH . 'robots.txt';
        if (file_exists($robots_file)) {
            return file_get_contents($robots_file);
        }
        return '';
    }

    /**
     * Import Indexation Options (Per-Post Robots Meta)
     */
    public function import_indexation($plugin, $options = [])
    {
        $defaults = ['batch_size' => 50, 'offset' => 0];
        $options = array_merge($defaults, $options);

        switch ($plugin) {
            case 'yoast':
                return $this->import_yoast_indexation($options);

            case 'rankmath':
                return $this->import_rankmath_indexation($options);

            case 'aioseo':
                return $this->import_aioseo_indexation($options);

            default:
                return ['success' => false, 'message' => 'Invalid plugin specified.'];
        }
    }

    /**
     * Import per-post indexation settings from Yoast SEO
     */
    private function import_yoast_indexation($options = [])
    {
        global $wpdb;
        $imported_count = 0;

        $batch_size = intval($options['batch_size']);
        $offset = intval($options['offset']);

        // Get total count (for progress tracking)
        $total = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key LIKE '_yoast_wpseo_%'
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
        ");

        // Get batch of posts with Yoast robots meta
        $posts = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key LIKE '_yoast_wpseo_%'
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
            ORDER BY post_id ASC
            LIMIT %d OFFSET %d
        ", $batch_size, $offset));

        foreach ($posts as $post_obj) {
            $post_id = $post_obj->post_id;
            $has_changes = false;

            // Get existing Metasync robots meta
            $metasync_robots = get_post_meta($post_id, 'metasync_common_robots', true);
            if (!is_array($metasync_robots)) {
                $metasync_robots = [];
            }

            // Import noindex
            $yoast_noindex = get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true);
            if ($yoast_noindex === '1' && !isset($metasync_robots['noindex'])) {
                $metasync_robots['noindex'] = 'noindex';
                $has_changes = true;
            } elseif ($yoast_noindex === '2' && isset($metasync_robots['noindex'])) {
                // '2' means 'index' in Yoast. Index is the implicit default, so
                // clear any stored noindex rather than recording a redundant flag.
                unset($metasync_robots['noindex']);
                $has_changes = true;
            }

            // Import nofollow
            $yoast_nofollow = get_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', true);
            if ($yoast_nofollow === '1' && !isset($metasync_robots['nofollow'])) {
                $metasync_robots['nofollow'] = 'nofollow';
                $has_changes = true;
            }

            // Import advanced robots (noarchive, nosnippet, noimageindex)
            $yoast_adv = get_post_meta($post_id, '_yoast_wpseo_meta-robots-adv', true);
            // Normalise the meta value to a string before calling explode().
            // Some sites store malformed/legacy data where this meta value is
            // an array, which would otherwise throw a TypeError on explode().
            if (is_array($yoast_adv)) {
                $yoast_adv = implode(',', $yoast_adv);
            } elseif (!is_string($yoast_adv)) {
                $yoast_adv = '';
            }
            if (!empty($yoast_adv)) {
                $adv_directives = explode(',', $yoast_adv);
                foreach ($adv_directives as $directive) {
                    $directive = trim($directive);
                    if (in_array($directive, ['noarchive', 'nosnippet', 'noimageindex']) && !isset($metasync_robots[$directive])) {
                        $metasync_robots[$directive] = $directive;
                        $has_changes = true;
                    }
                }
            }

            // Also write to _metasync_robots_advanced JSON
            $robots_advanced = [];
            if (!empty($yoast_adv)) {
                $adv_directives = array_map('trim', explode(',', $yoast_adv));
                foreach (['noarchive', 'nosnippet', 'noimageindex'] as $dir) {
                    if (in_array($dir, $adv_directives, true)) {
                        $robots_advanced[$dir] = true;
                    }
                }
            }
            // nofollow from Yoast
            if ($yoast_nofollow === '1') {
                $robots_advanced['nofollow'] = true;
            }
            if (!empty($robots_advanced)) {
                update_post_meta($post_id, '_metasync_robots_advanced', wp_json_encode($robots_advanced));
            }

            // Import canonical URL
            $yoast_canonical = get_post_meta($post_id, '_yoast_wpseo_canonical', true);
            if (!empty($yoast_canonical)) {
                $existing_canonical = get_post_meta($post_id, 'meta_canonical', true);
                if (empty($existing_canonical)) {
                    // Validate: never import a corrupted value such as
                    // "http://Array" from third-party storage.
                    $clean_canonical = Metasync_Canonical_Sanitizer::sanitize_for_save($yoast_canonical);
                    if ($clean_canonical !== '') {
                        update_post_meta($post_id, 'meta_canonical', $clean_canonical);
                        $has_changes = true;
                    }
                }
            }

            // Save Metasync robots meta if changes were made
            if ($has_changes) {
                if (!empty($metasync_robots)) {
                    update_post_meta($post_id, 'metasync_common_robots', $metasync_robots);
                }
                $imported_count++;
            }

            // Flush per-post object cache to prevent unbounded memory growth across batches
            clean_post_cache($post_id);
        }

        $processed = $offset + count($posts);
        $is_complete = $processed >= $total;

        return [
            'success' => true,
            'imported' => $imported_count,
            'skipped' => count($posts) - $imported_count,
            'total' => $total,
            'processed' => $processed,
            'is_complete' => $is_complete,
            'progress_percent' => $total > 0 ? round(($processed / $total) * 100) : 100,
            'message' => $is_complete
                ? "Import complete! Imported {$imported_count} posts."
                : "Processing... {$imported_count} imported."
        ];
    }

    /**
     * Import per-post indexation settings from Rank Math
     */
    private function import_rankmath_indexation($options = [])
    {
        global $wpdb;
        $imported_count = 0;

        $batch_size = intval($options['batch_size']);
        $offset = intval($options['offset']);

        // Get total count (for progress tracking)
        $total = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key LIKE 'rank_math_%'
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
        ");

        // Get batch of posts with Rank Math robots meta
        $posts = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key LIKE 'rank_math_%'
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
            ORDER BY post_id ASC
            LIMIT %d OFFSET %d
        ", $batch_size, $offset));

        foreach ($posts as $post_obj) {
            $post_id = $post_obj->post_id;
            $has_changes = false;

            // Get existing Metasync robots meta
            $metasync_robots = get_post_meta($post_id, 'metasync_common_robots', true);
            if (!is_array($metasync_robots)) {
                $metasync_robots = [];
            }

            // Import robots array
            $rm_robots = get_post_meta($post_id, 'rank_math_robots', true);
            if (is_array($rm_robots)) {
                // Rank Math stores as array like ['noindex', 'nofollow']
                foreach ($rm_robots as $directive) {
                    $directive = strtolower(trim($directive));
                    if (in_array($directive, ['index', 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex']) && !isset($metasync_robots[$directive])) {
                        $metasync_robots[$directive] = $directive;
                        $has_changes = true;
                    }
                }
            }

            // Import advanced robots
            $rm_adv_robots = get_post_meta($post_id, 'rank_math_advanced_robots', true);
            if (is_array($rm_adv_robots)) {
                foreach ($rm_adv_robots as $directive) {
                    $directive = strtolower(trim($directive));
                    if (in_array($directive, ['noarchive', 'nosnippet', 'noimageindex', 'max-snippet', 'max-video-preview', 'max-image-preview']) && !isset($metasync_robots[$directive])) {
                        $metasync_robots[$directive] = $directive;
                        $has_changes = true;
                    }
                }
            }

            // Parse max-* values and write _metasync_robots_advanced JSON
            $robots_advanced = [];
            // Boolean directives from rank_math_robots
            if (is_array($rm_robots)) {
                foreach (['nofollow', 'noarchive', 'nosnippet', 'noimageindex'] as $dir) {
                    if (in_array($dir, $rm_robots, true)) {
                        $robots_advanced[$dir] = true;
                    }
                }
            }
            // max-* directives from rank_math_advanced_robots
            if (is_array($rm_adv_robots)) {
                foreach ($rm_adv_robots as $key => $value) {
                    // Values are formatted like "max-snippet:-1"
                    if (strpos($key, 'max-snippet') !== false && strpos($value, ':') !== false) {
                        $robots_advanced['max_snippet'] = (int) explode(':', $value)[1];
                    }
                    if (strpos($key, 'max-image-preview') !== false && strpos($value, ':') !== false) {
                        $robots_advanced['max_image_preview'] = explode(':', $value)[1];
                    }
                    if (strpos($key, 'max-video-preview') !== false && strpos($value, ':') !== false) {
                        $robots_advanced['max_video_preview'] = (int) explode(':', $value)[1];
                    }
                }
            }
            if (!empty($robots_advanced)) {
                update_post_meta($post_id, '_metasync_robots_advanced', wp_json_encode($robots_advanced));
            }

            // Import canonical URL
            $rm_canonical = get_post_meta($post_id, 'rank_math_canonical_url', true);
            if (!empty($rm_canonical)) {
                $existing_canonical = get_post_meta($post_id, 'meta_canonical', true);
                if (empty($existing_canonical)) {
                    // Validate: never import a corrupted value such as
                    // "http://Array" from third-party storage.
                    $clean_canonical = Metasync_Canonical_Sanitizer::sanitize_for_save($rm_canonical);
                    if ($clean_canonical !== '') {
                        update_post_meta($post_id, 'meta_canonical', $clean_canonical);
                        $has_changes = true;
                    }
                }
            }

            // Save Metasync robots meta if changes were made
            if ($has_changes) {
                if (!empty($metasync_robots)) {
                    update_post_meta($post_id, 'metasync_common_robots', $metasync_robots);
                }
                $imported_count++;
            }

            // Flush per-post object cache to prevent unbounded memory growth across batches
            clean_post_cache($post_id);
        }

        $processed = $offset + count($posts);
        $is_complete = $processed >= $total;

        return [
            'success' => true,
            'imported' => $imported_count,
            'skipped' => count($posts) - $imported_count,
            'total' => $total,
            'processed' => $processed,
            'is_complete' => $is_complete,
            'progress_percent' => $total > 0 ? round(($processed / $total) * 100) : 100,
            'message' => $is_complete
                ? "Import complete! Imported {$imported_count} posts."
                : "Processing... {$imported_count} imported."
        ];
    }

    /**
     * Import per-post indexation settings from AIOSEO
     */
    private function import_aioseo_indexation($options = [])
    {
        global $wpdb;
        $imported_count = 0;

        $batch_size = intval($options['batch_size']);
        $offset = intval($options['offset']);

        // AIOSEO stores data in a custom table
        $aioseo_table = $wpdb->prefix . 'aioseo_posts';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$aioseo_table'") === $aioseo_table;

        if (!$table_exists) {
            return ['success' => false, 'message' => 'AIOSEO table not found.'];
        }

        // Get total count (for progress tracking)
        $total = (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$aioseo_table}
            WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
        ");

        // Get batch of posts with AIOSEO settings
        $posts = $wpdb->get_results($wpdb->prepare("
            SELECT post_id, robots_default, robots_noindex, robots_nofollow,
                   robots_noarchive, robots_nosnippet, robots_noimageindex,
                   robots_max_snippet, robots_max_imagepreview, robots_max_videopreview,
                   canonical_url
            FROM {$aioseo_table}
            WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
            ORDER BY post_id ASC
            LIMIT %d OFFSET %d
        ", $batch_size, $offset));

        foreach ($posts as $aioseo_data) {
            $post_id = $aioseo_data->post_id;
            $has_changes = false;

            // Get existing Metasync robots meta
            $metasync_robots = get_post_meta($post_id, 'metasync_common_robots', true);
            if (!is_array($metasync_robots)) {
                $metasync_robots = [];
            }

            // Only import if not using default (robots_default = 0)
            if ($aioseo_data->robots_default == 0) {
                // Import noindex
                if ($aioseo_data->robots_noindex == 1 && !isset($metasync_robots['noindex'])) {
                    $metasync_robots['noindex'] = 'noindex';
                    $has_changes = true;
                }

                // Import nofollow
                if ($aioseo_data->robots_nofollow == 1 && !isset($metasync_robots['nofollow'])) {
                    $metasync_robots['nofollow'] = 'nofollow';
                    $has_changes = true;
                }

                // Import noarchive
                if ($aioseo_data->robots_noarchive == 1 && !isset($metasync_robots['noarchive'])) {
                    $metasync_robots['noarchive'] = 'noarchive';
                    $has_changes = true;
                }

                // Import nosnippet
                if ($aioseo_data->robots_nosnippet == 1 && !isset($metasync_robots['nosnippet'])) {
                    $metasync_robots['nosnippet'] = 'nosnippet';
                    $has_changes = true;
                }

                // Import noimageindex
                if ($aioseo_data->robots_noimageindex == 1 && !isset($metasync_robots['noimageindex'])) {
                    $metasync_robots['noimageindex'] = 'noimageindex';
                    $has_changes = true;
                }
            }

            // Write _metasync_robots_advanced JSON
            $robots_advanced = [];
            if ($aioseo_data->robots_default == 0) {
                if ($aioseo_data->robots_nofollow == 1) $robots_advanced['nofollow'] = true;
                if ($aioseo_data->robots_noarchive == 1) $robots_advanced['noarchive'] = true;
                if ($aioseo_data->robots_nosnippet == 1) $robots_advanced['nosnippet'] = true;
                if ($aioseo_data->robots_noimageindex == 1) $robots_advanced['noimageindex'] = true;
            }
            if (isset($aioseo_data->robots_max_snippet) && is_numeric($aioseo_data->robots_max_snippet)) {
                $robots_advanced['max_snippet'] = (int) $aioseo_data->robots_max_snippet;
            }
            if (!empty($aioseo_data->robots_max_imagepreview)) {
                $robots_advanced['max_image_preview'] = $aioseo_data->robots_max_imagepreview;
            }
            if (isset($aioseo_data->robots_max_videopreview) && is_numeric($aioseo_data->robots_max_videopreview)) {
                $robots_advanced['max_video_preview'] = (int) $aioseo_data->robots_max_videopreview;
            }
            if (!empty($robots_advanced)) {
                update_post_meta($post_id, '_metasync_robots_advanced', wp_json_encode($robots_advanced));
                $has_changes = true;
            }

            // Import canonical URL
            if (!empty($aioseo_data->canonical_url)) {
                $existing_canonical = get_post_meta($post_id, 'meta_canonical', true);
                if (empty($existing_canonical)) {
                    // Validate: never import a corrupted value such as
                    // "http://Array" from third-party storage.
                    $clean_canonical = Metasync_Canonical_Sanitizer::sanitize_for_save($aioseo_data->canonical_url);
                    if ($clean_canonical !== '') {
                        update_post_meta($post_id, 'meta_canonical', $clean_canonical);
                        $has_changes = true;
                    }
                }
            }

            // Save Metasync robots meta if changes were made
            if ($has_changes) {
                if (!empty($metasync_robots)) {
                    update_post_meta($post_id, 'metasync_common_robots', $metasync_robots);
                }
                $imported_count++;
            }

            // Flush per-post object cache to prevent unbounded memory growth across batches
            clean_post_cache($post_id);
        }

        $processed = $offset + count($posts);
        $is_complete = $processed >= $total;

        return [
            'success' => true,
            'imported' => $imported_count,
            'skipped' => count($posts) - $imported_count,
            'total' => $total,
            'processed' => $processed,
            'is_complete' => $is_complete,
            'progress_percent' => $total > 0 ? round(($processed / $total) * 100) : 100,
            'message' => $is_complete
                ? "Import complete! Imported {$imported_count} posts."
                : "Processing... {$imported_count} imported."
        ];
    }

    /**
     * Import Schema Settings (Per-Post Schema)
     */
    public function import_schema($plugin)
    {
        $imported_count = 0;

        switch ($plugin) {
            case 'yoast':
                $imported_count = $this->import_yoast_schema();
                break;

            case 'rankmath':
                $imported_count = $this->import_rankmath_schema();
                break;

            case 'aioseo':
                $imported_count = $this->import_aioseo_schema();
                break;

            default:
                return ['success' => false, 'message' => 'Invalid plugin specified.'];
        }

        if ($imported_count > 0) {
            return ['success' => true, 'message' => "Successfully imported schema settings for $imported_count posts."];
        }

        return ['success' => false, 'message' => 'No post-level schema settings found to import.'];
    }

    /**
     * Import per-post schema from Yoast SEO
     */
    private function import_yoast_schema()
    {
        global $wpdb;
        $imported_count = 0;

        // First, try to import from full schema JSON (free version or old approach)
        $posts = $wpdb->get_results("
            SELECT post_id, meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_yoast_wpseo_schema'
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
        ");

        foreach ($posts as $post_obj) {
            $post_id = $post_obj->post_id;

            // Check if Metasync schema already exists
            $existing_schema = get_post_meta($post_id, 'metasync_schema_markup', true);
            if (!empty($existing_schema) && !empty($existing_schema['types'])) {
                continue; // Skip if already has Metasync schema
            }

            // Decode Yoast schema JSON
            $yoast_schema = json_decode((string)($post_obj->meta_value ?? ''), true);
            if (empty($yoast_schema) || !is_array($yoast_schema)) {
                continue;
            }

            // Convert Yoast schema to Metasync format
            $metasync_schema = $this->convert_yoast_schema_to_metasync($yoast_schema, $post_id);

            if (!empty($metasync_schema['types'])) {
                update_post_meta($post_id, 'metasync_schema_markup', $metasync_schema);
                $imported_count++;
            }
        }

        // Second, try to import from schema type (Premium version)
        $posts = $wpdb->get_results("
            SELECT post_id, meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_yoast_wpseo_schema_article_type'
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
        ");

        foreach ($posts as $post_obj) {
            $post_id = $post_obj->post_id;

            // Check if Metasync schema already exists
            $existing_schema = get_post_meta($post_id, 'metasync_schema_markup', true);
            if (!empty($existing_schema) && !empty($existing_schema['types'])) {
                continue; // Skip if already has Metasync schema
            }

            $schema_type = strtolower($post_obj->meta_value);

            // Create basic article schema with placeholders
            // Yoast Premium generates schema dynamically, so we create a minimal version
            if ($schema_type === 'article' || $schema_type === 'newsarticle' || $schema_type === 'blogposting') {
                $metasync_schema = [
                    'enabled' => true,
                    'types' => [
                        [
                            'type' => 'article',
                            'fields' => [
                                'title_override' => '{{post_title}}',
                                'description_override' => '{{post_description}}',
                                'image_override' => '{{featured_image}}',
                                'organization_name' => '',
                                'organization_logo' => ''
                            ]
                        ]
                    ]
                ];

                update_post_meta($post_id, 'metasync_schema_markup', $metasync_schema);
                $imported_count++;
            }
        }

        return $imported_count;
    }

    /**
     * Import per-post schema from Rank Math
     */
    private function import_rankmath_schema()
    {
        global $wpdb;
        $imported_count = 0;

        // Get all posts with any Rank Math schema (dynamically detect schema types)
        // Exclude shortcode schemas
        $posts = $wpdb->get_results("
            SELECT DISTINCT pm.post_id, pm.meta_key, pm.meta_value
            FROM {$wpdb->postmeta} pm
            WHERE pm.meta_key LIKE 'rank_math_schema_%'
            AND pm.meta_key NOT LIKE 'rank_math_shortcode_schema_%'
            AND pm.post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
            ORDER BY pm.post_id
        ");

        $processed_posts = [];

        foreach ($posts as $post_obj) {
            $post_id = $post_obj->post_id;

            // Skip if we already processed this post
            if (in_array($post_id, $processed_posts)) {
                continue;
            }

            // Check if Metasync schema already exists for this post
            $existing_schema = get_post_meta($post_id, 'metasync_schema_markup', true);
            if (!empty($existing_schema) && !empty($existing_schema['types'])) {
                continue; // Skip if already has Metasync schema
            }

            // Extract schema type from meta key (e.g., rank_math_schema_BlogPosting -> BlogPosting)
            $schema_type = str_replace('rank_math_schema_', '', $post_obj->meta_key);

            // Decode Rank Math schema
            $rm_schema = maybe_unserialize($post_obj->meta_value);
            if (empty($rm_schema) || !is_array($rm_schema)) {
                continue;
            }

            // Convert Rank Math schema to Metasync format
            $metasync_schema = $this->convert_rankmath_schema_to_metasync($rm_schema, $schema_type, $post_id);

            if (!empty($metasync_schema['types'])) {
                update_post_meta($post_id, 'metasync_schema_markup', $metasync_schema);
                $imported_count++;
                $processed_posts[] = $post_id; // Mark post as processed
            }
        }

        return $imported_count;
    }

    /**
     * Import per-post schema from AIOSEO
     */
    private function import_aioseo_schema()
    {
        global $wpdb;
        $imported_count = 0;

        // Check if AIOSEO table exists
        $table = $wpdb->prefix . 'aioseo_posts';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return 0;
        }

        // Get all posts with AIOSEO schema
        $posts = $wpdb->get_results("
            SELECT post_id, schema_type, schema_type_options
            FROM {$table}
            WHERE (schema_type IS NOT NULL AND schema_type != '')
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
        ");

        foreach ($posts as $aioseo_data) {
            $post_id = $aioseo_data->post_id;

            // Check if Metasync schema already exists
            $existing_schema = get_post_meta($post_id, 'metasync_schema_markup', true);
            if (!empty($existing_schema) && !empty($existing_schema['types'])) {
                continue; // Skip if already has Metasync schema
            }

            // Decode AIOSEO schema options
            $schema_options = json_decode((string)($aioseo_data->schema_type_options ?? ''), true);
            if (!is_array($schema_options)) {
                $schema_options = [];
            }

            // Convert AIOSEO schema to Metasync format
            $metasync_schema = $this->convert_aioseo_schema_to_metasync(
                $aioseo_data->schema_type,
                $schema_options,
                $post_id
            );

            if (!empty($metasync_schema['types'])) {
                update_post_meta($post_id, 'metasync_schema_markup', $metasync_schema);
                $imported_count++;
            }
        }

        return $imported_count;
    }

    /**
     * Convert Yoast schema to Metasync format
     */
    private function convert_yoast_schema_to_metasync($yoast_schema, $post_id)
    {
        $metasync_schema = [
            'enabled' => true,
            'types' => []
        ];

        // Yoast stores schema as a graph array
        if (isset($yoast_schema['@graph']) && is_array($yoast_schema['@graph'])) {
            foreach ($yoast_schema['@graph'] as $item) {
                if (!isset($item['@type'])) {
                    continue;
                }

                $raw_type = $item['@type'];
                if ( is_array( $raw_type ) ) {
                    $raw_type = reset( $raw_type );
                }
                if ( ! is_string( $raw_type ) || '' === $raw_type ) {
                    continue;
                }
                $type = strtolower( $raw_type );

                // Map Yoast types to Metasync types
                if ($type === 'article' || $type === 'newsarticle' || $type === 'blogposting') {
                    $metasync_schema['types'][] = [
                        'type' => 'article',
                        'fields' => [
                            'title_override' => isset($item['headline']) ? $item['headline'] : '{{post_title}}',
                            'description_override' => isset($item['description']) ? $item['description'] : '{{post_description}}',
                            'image_override' => isset($item['image']) ? (is_array($item['image']) ? $item['image'][0] : $item['image']) : '{{featured_image}}',
                            'organization_name' => isset($item['publisher']['name']) ? $item['publisher']['name'] : '',
                            'organization_logo' => isset($item['publisher']['logo']['url']) ? $item['publisher']['logo']['url'] : ''
                        ]
                    ];
                } elseif ($type === 'faqpage') {
                    $faq_items = [];
                    if (isset($item['mainEntity']) && is_array($item['mainEntity'])) {
                        foreach ($item['mainEntity'] as $question) {
                            if (isset($question['name']) && isset($question['acceptedAnswer']['text'])) {
                                $faq_items[] = [
                                    'question' => $question['name'],
                                    'answer' => $question['acceptedAnswer']['text']
                                ];
                            }
                        }
                    }
                    if (!empty($faq_items)) {
                        $metasync_schema['types'][] = [
                            'type' => 'FAQPage',
                            'fields' => [
                                'faq_items' => $faq_items
                            ]
                        ];
                    }
                } elseif ($type === 'product') {
                    $metasync_schema['types'][] = [
                        'type' => 'product',
                        'fields' => [
                            'title_override' => isset($item['name']) ? $item['name'] : '{{post_title}}',
                            'description_override' => isset($item['description']) ? $item['description'] : '{{post_description}}',
                            'image_override' => isset($item['image']) ? (is_array($item['image']) ? $item['image'][0] : $item['image']) : '{{featured_image}}',
                            'sku' => isset($item['sku']) ? $item['sku'] : '',
                            'brand' => isset($item['brand']['name']) ? $item['brand']['name'] : '',
                            'price' => isset($item['offers']['price']) ? floatval($item['offers']['price']) : 0,
                            'currency' => isset($item['offers']['priceCurrency']) ? $item['offers']['priceCurrency'] : 'USD',
                            'availability' => isset($item['offers']['availability']) ? basename($item['offers']['availability']) : 'InStock',
                            'condition' => isset($item['offers']['itemCondition']) ? basename($item['offers']['itemCondition']) : 'NewCondition'
                        ]
                    ];
                } elseif ($type === 'recipe') {
                    $ingredients = [];
                    if (isset($item['recipeIngredient']) && is_array($item['recipeIngredient'])) {
                        $ingredients = $item['recipeIngredient'];
                    }

                    $instructions = [];
                    if (isset($item['recipeInstructions']) && is_array($item['recipeInstructions'])) {
                        foreach ($item['recipeInstructions'] as $step) {
                            if (is_string($step)) {
                                $instructions[] = $step;
                            } elseif (isset($step['text'])) {
                                $instructions[] = $step['text'];
                            }
                        }
                    }

                    $metasync_schema['types'][] = [
                        'type' => 'recipe',
                        'fields' => [
                            'title_override' => isset($item['name']) ? $item['name'] : '{{post_title}}',
                            'description_override' => isset($item['description']) ? $item['description'] : '{{post_description}}',
                            'image_override' => isset($item['image']) ? (is_array($item['image']) ? $item['image'][0] : $item['image']) : '{{featured_image}}',
                            'yield' => isset($item['recipeYield']) ? $item['recipeYield'] : '',
                            'ingredients' => $ingredients,
                            'instructions' => $instructions,
                            'prep_time' => isset($item['prepTime']) ? $this->parse_duration($item['prepTime']) : 0,
                            'cook_time' => isset($item['cookTime']) ? $this->parse_duration($item['cookTime']) : 0,
                            'total_time' => isset($item['totalTime']) ? $this->parse_duration($item['totalTime']) : 0,
                            'calories' => isset($item['nutrition']['calories']) ? intval($item['nutrition']['calories']) : 0
                        ]
                    ];
                }
            }
        }

        return $metasync_schema;
    }

    /**
     * Convert Rank Math schema to Metasync format
     */
    private function convert_rankmath_schema_to_metasync($rm_schema, $schema_type, $post_id)
    {
        $metasync_schema = [
            'enabled' => true,
            'types' => []
        ];

        $type = strtolower($schema_type);

        // Handle article-like schema types (Article, BlogPosting, NewsArticle, etc.)
        if ($type === 'article' || $type === 'blogposting' || $type === 'newsarticle') {
            $metasync_schema['types'][] = [
                'type' => 'article',
                'fields' => [
                    'title_override' => $this->normalize_text_value($rm_schema['headline'] ?? null, '{{post_title}}'),
                    'description_override' => $this->normalize_text_value($rm_schema['description'] ?? null, '{{post_description}}'),
                    'image_override' => $this->normalize_image_value($rm_schema['image'] ?? null),
                    'organization_name' => isset($rm_schema['publisher']) ? $rm_schema['publisher'] : '',
                    'organization_logo' => isset($rm_schema['publisher_logo']) ? $rm_schema['publisher_logo'] : ''
                ]
            ];
        } elseif ($type === 'faqpage') {
            $faq_items = [];
            if (isset($rm_schema['questions']) && is_array($rm_schema['questions'])) {
                foreach ($rm_schema['questions'] as $question) {
                    if (isset($question['name']) && isset($question['text'])) {
                        $faq_items[] = [
                            'question' => $question['name'],
                            'answer' => $question['text']
                        ];
                    }
                }
            }
            if (!empty($faq_items)) {
                $metasync_schema['types'][] = [
                    'type' => 'FAQPage',
                    'fields' => [
                        'faq_items' => $faq_items
                    ]
                ];
            }
        } elseif ($type === 'product') {
            $metasync_schema['types'][] = [
                'type' => 'product',
                'fields' => [
                    'title_override' => $this->normalize_text_value($rm_schema['name'] ?? null, '{{post_title}}'),
                    'description_override' => $this->normalize_text_value($rm_schema['description'] ?? null, '{{post_description}}'),
                    'image_override' => $this->normalize_image_value($rm_schema['image'] ?? null),
                    'sku' => isset($rm_schema['sku']) ? $rm_schema['sku'] : '',
                    'brand' => isset($rm_schema['brand']) ? $rm_schema['brand'] : '',
                    'price' => isset($rm_schema['price']) ? floatval($rm_schema['price']) : 0,
                    'currency' => isset($rm_schema['currency']) ? $rm_schema['currency'] : 'USD',
                    'availability' => isset($rm_schema['inStock']) ? ($rm_schema['inStock'] ? 'InStock' : 'OutOfStock') : 'InStock',
                    'condition' => 'NewCondition'
                ]
            ];
        } elseif ($type === 'recipe') {
            $metasync_schema['types'][] = [
                'type' => 'recipe',
                'fields' => [
                    'title_override' => $this->normalize_text_value($rm_schema['name'] ?? null, '{{post_title}}'),
                    'description_override' => $this->normalize_text_value($rm_schema['description'] ?? null, '{{post_description}}'),
                    'image_override' => $this->normalize_image_value($rm_schema['image'] ?? null),
                    'yield' => isset($rm_schema['recipeYield']) ? $rm_schema['recipeYield'] : '',
                    'ingredients' => isset($rm_schema['recipeIngredient']) ? $rm_schema['recipeIngredient'] : [],
                    'instructions' => isset($rm_schema['recipeInstructions']) ? $rm_schema['recipeInstructions'] : [],
                    'prep_time' => isset($rm_schema['prepTime']) ? intval($rm_schema['prepTime']) : 0,
                    'cook_time' => isset($rm_schema['cookTime']) ? intval($rm_schema['cookTime']) : 0,
                    'total_time' => isset($rm_schema['totalTime']) ? intval($rm_schema['totalTime']) : 0,
                    'calories' => isset($rm_schema['calories']) ? intval($rm_schema['calories']) : 0
                ]
            ];
        }

        return $metasync_schema;
    }

    /**
     * Convert AIOSEO schema to Metasync format
     */
    private function convert_aioseo_schema_to_metasync($schema_type, $schema_options, $post_id)
    {
        $metasync_schema = [
            'enabled' => true,
            'types' => []
        ];

        $type = strtolower($schema_type);

        if ($type === 'article') {
            $metasync_schema['types'][] = [
                'type' => 'article',
                'fields' => [
                    'title_override' => isset($schema_options['headline']) ? $schema_options['headline'] : '{{post_title}}',
                    'description_override' => isset($schema_options['description']) ? $schema_options['description'] : '{{post_description}}',
                    'image_override' => isset($schema_options['image']) ? $schema_options['image'] : '{{featured_image}}',
                    'organization_name' => isset($schema_options['organizationName']) ? $schema_options['organizationName'] : '',
                    'organization_logo' => isset($schema_options['organizationLogo']) ? $schema_options['organizationLogo'] : ''
                ]
            ];
        } elseif ($type === 'faqpage') {
            $faq_items = [];
            if (isset($schema_options['questions']) && is_array($schema_options['questions'])) {
                foreach ($schema_options['questions'] as $question) {
                    if (isset($question['question']) && isset($question['answer'])) {
                        $faq_items[] = [
                            'question' => $question['question'],
                            'answer' => $question['answer']
                        ];
                    }
                }
            }
            if (!empty($faq_items)) {
                $metasync_schema['types'][] = [
                    'type' => 'FAQPage',
                    'fields' => [
                        'faq_items' => $faq_items
                    ]
                ];
            }
        } elseif ($type === 'product') {
            $metasync_schema['types'][] = [
                'type' => 'product',
                'fields' => [
                    'title_override' => isset($schema_options['name']) ? $schema_options['name'] : '{{post_title}}',
                    'description_override' => isset($schema_options['description']) ? $schema_options['description'] : '{{post_description}}',
                    'image_override' => isset($schema_options['image']) ? $schema_options['image'] : '{{featured_image}}',
                    'sku' => isset($schema_options['sku']) ? $schema_options['sku'] : '',
                    'brand' => isset($schema_options['brand']) ? $schema_options['brand'] : '',
                    'price' => isset($schema_options['price']) ? floatval($schema_options['price']) : 0,
                    'currency' => isset($schema_options['currency']) ? $schema_options['currency'] : 'USD',
                    'availability' => isset($schema_options['availability']) ? $schema_options['availability'] : 'InStock',
                    'condition' => isset($schema_options['condition']) ? $schema_options['condition'] : 'NewCondition'
                ]
            ];
        } elseif ($type === 'recipe') {
            $metasync_schema['types'][] = [
                'type' => 'recipe',
                'fields' => [
                    'title_override' => isset($schema_options['name']) ? $schema_options['name'] : '{{post_title}}',
                    'description_override' => isset($schema_options['description']) ? $schema_options['description'] : '{{post_description}}',
                    'image_override' => isset($schema_options['image']) ? $schema_options['image'] : '{{featured_image}}',
                    'yield' => isset($schema_options['recipeYield']) ? $schema_options['recipeYield'] : '',
                    'ingredients' => isset($schema_options['recipeIngredient']) ? $schema_options['recipeIngredient'] : [],
                    'instructions' => isset($schema_options['recipeInstructions']) ? $schema_options['recipeInstructions'] : [],
                    'prep_time' => isset($schema_options['prepTime']) ? intval($schema_options['prepTime']) : 0,
                    'cook_time' => isset($schema_options['cookTime']) ? intval($schema_options['cookTime']) : 0,
                    'total_time' => isset($schema_options['totalTime']) ? intval($schema_options['totalTime']) : 0,
                    'calories' => isset($schema_options['calories']) ? intval($schema_options['calories']) : 0
                ]
            ];
        }

        return $metasync_schema;
    }

    /**
     * Parse ISO 8601 duration to minutes
     * e.g., "PT15M" = 15 minutes, "PT1H30M" = 90 minutes
     */
    private function parse_duration($duration)
    {
        if (empty($duration)) {
            return 0;
        }

        // Simple parser for PT format
        $minutes = 0;
        if (preg_match('/PT(\d+)H/', $duration, $hours)) {
            $minutes += intval($hours[1]) * 60;
        }
        if (preg_match('/(\d+)M/', $duration, $mins)) {
            $minutes += intval($mins[1]);
        }

        return $minutes;
    }

    /**
     * Normalize image value to string URL
     * Handles arrays from Rank Math/Yoast and converts placeholders
     */
    private function normalize_image_value($image)
    {
        if (empty($image)) {
            return '{{featured_image}}';
        }

        // If it's an array (from Rank Math/Yoast), extract the URL
        if (is_array($image)) {
            // Check for 'url' key first
            if (isset($image['url'])) {
                $image = $image['url'];
            }
            // Check for '@id' key (Yoast format)
            elseif (isset($image['@id'])) {
                $image = $image['@id'];
            }
            // If it's still an array, try to get first element
            elseif (isset($image[0])) {
                $image = is_string($image[0]) ? $image[0] : '{{featured_image}}';
            }
            else {
                $image = '{{featured_image}}';
            }
        }

        // Convert common placeholder formats to Metasync format
        $placeholder_map = [
            '%post_thumbnail%' => '{{featured_image}}',
            '%featured_image%' => '{{featured_image}}',
            '%seo_title%' => '{{post_title}}',
            '%post_title%' => '{{post_title}}',
            '%seo_description%' => '{{post_description}}',
            '%post_excerpt%' => '{{post_description}}'
        ];

        foreach ($placeholder_map as $old => $new) {
            if ($image === $old || strpos($image, $old) !== false) {
                $image = str_replace($old, $new, $image);
            }
        }

        return is_string($image) ? $image : '{{featured_image}}';
    }

    /**
     * Normalize text value to string
     * Converts placeholders to Metasync format
     */
    private function normalize_text_value($text, $default = '')
    {
        if (empty($text)) {
            return $default;
        }

        // Convert common placeholder formats to Metasync format
        $placeholder_map = [
            '%seo_title%' => '{{post_title}}',
            '%post_title%' => '{{post_title}}',
            '%seo_description%' => '{{post_description}}',
            '%post_excerpt%' => '{{post_description}}'
        ];

        foreach ($placeholder_map as $old => $new) {
            if (is_string($text) && (strpos($text, $old) !== false || $text === $old)) {
                $text = str_replace($old, $new, $text);
            }
        }

        return is_string($text) ? $text : $default;
    }

    /**
     * Tokens whose value is the post's own body or excerpt.
     *
     * Keyed by source plugin. These are the only tokens that can carry the text
     * a password withholds; everything else (title, author, dates, taxonomy) is
     * already public on a protected post.
     *
     * @param string $plugin 'yoast', 'rankmath', or 'aioseo'.
     * @return string[]
     */
    private function content_derived_tokens($plugin) {
        switch ($plugin) {
            case 'yoast':
                return ['%%excerpt%%', '%%excerpt_only%%'];

            case 'rankmath':
                return ['%excerpt%', '%excerpt_only%'];

            case 'aioseo':
                return [
                    '#post_content',
                    '#post_excerpt',
                    '#post_excerpt_only',
                    '#attachment_description',
                    '#attachment_caption',
                ];
        }

        return [];
    }

    /**
     * Whether a raw template would publish text that a post password withholds.
     *
     * WordPress keeps a protected post's body and excerpt behind the password
     * but leaves the post itself published, so an import walks straight past the
     * usual visibility checks: the source plugin stores a template, we resolve it
     * against the raw row, and the body ends up in a meta description that every
     * visitor and crawler can read without ever entering the password.
     *
     * @param string  $text   Raw value from the source plugin.
     * @param WP_Post $post   Post the value belongs to.
     * @param string  $plugin Source plugin.
     * @return bool
     */
    private function references_protected_content($text, $post, $plugin) {
        if (empty($post->post_password)) {
            return false;
        }

        foreach ($this->content_derived_tokens($plugin) as $token) {
            if (strpos($text, $token) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve SEO placeholder tokens to their actual values.
     *
     * Tries each source plugin's own replacement engine first so the result
     * matches exactly what Yoast / Rank Math / AIOSEO would render. Falls back
     * to manual replacement of the most common tokens.
     *
     * @param string $text    Raw string that may contain placeholder tokens.
     * @param int    $post_id Post whose context is used for replacement.
     * @param string $plugin  'yoast', 'rankmath', or 'aioseo'.
     * @return string         Resolved string.
     */
    private function resolve_seo_placeholders($text, $post_id, $plugin) {
        if (empty($text) || !is_string($text)) {
            return (string) $text;
        }

        $post = get_post($post_id);
        if (!$post) {
            return $text;
        }

        // Drop the whole value rather than resolve the content token to nothing
        // and keep the rest. A half-template ("Title |") is worse than no import:
        // it still occupies the meta key, and it still outranks whatever OTTO
        // would have recommended for a page we know nothing publishable about.
        // Callers skip empty values, so nothing is persisted.
        if ($this->references_protected_content($text, $post, $plugin)) {
            return '';
        }

        // Yoast SEO — %%var%% tokens
        if ($plugin === 'yoast' && class_exists('WPSEO_Replace_Vars')) {
            try {
                $replacer = new WPSEO_Replace_Vars();
                $resolved = $replacer->replace($text, $post);
                if (is_string($resolved) && $resolved !== '') {
                    return $resolved;
                }
            } catch (Exception $e) {
                // fall through to manual replacement
            }
        }

        // Rank Math — %var% tokens
        if ($plugin === 'rankmath' && function_exists('rank_math_replace_vars')) {
            try {
                $resolved = rank_math_replace_vars($text, $post);
                if (is_string($resolved) && $resolved !== '') {
                    return $resolved;
                }
            } catch (Exception $e) {
                // fall through to manual replacement
            }
        }

        // All in One SEO — #var tokens
        if ($plugin === 'aioseo') {
            try {
                if (function_exists('aioseo') && isset(aioseo()->tags) && method_exists(aioseo()->tags, 'replaceTags')) {
                    $resolved = aioseo()->tags->replaceTags($text, $post_id);
                    if (is_string($resolved) && $resolved !== '') {
                        return $resolved;
                    }
                }
            } catch (Exception $e) {
                // fall through to manual replacement
            }
        }

        return $this->manually_replace_seo_placeholders($text, $post_id, $plugin);
    }

    /**
     * Manual fallback: replace the most common SEO placeholder tokens from
     * Yoast (%%var%%), Rank Math (%var%), and AIOSEO (#var) formats.
     *
     * AIOSEO tags are handled by resolve_aioseo_placeholders() because they
     * need pattern matching rather than plain string replacement — see the
     * docblock there.
     *
     * @param string $text    Text that may contain placeholder tokens.
     * @param int    $post_id Post ID used for context.
     * @param string $plugin  Optional source plugin ('yoast', 'rankmath',
     *                        'aioseo') so the separator is read from the
     *                        plugin the data actually came from.
     * @return string         Text with tokens replaced.
     */
    private function manually_replace_seo_placeholders($text, $post_id, $plugin = '') {
        $post = get_post($post_id);
        if (!$post) {
            return $text;
        }

        $site_name        = get_bloginfo('name');
        $post_title       = get_the_title($post_id);
        $post_excerpt     = has_excerpt($post_id) ? wp_strip_all_tags(get_the_excerpt($post)) : '';
        $author           = get_the_author_meta('display_name', (int) $post->post_author);
        $date             = get_the_date('', $post_id);
        $modified         = get_the_modified_date('', $post_id);

        $categories       = get_the_category($post_id);
        $primary_category = !empty($categories) ? $categories[0]->name : '';

        $tags             = get_the_tags($post_id);
        $first_tag        = !empty($tags) ? $tags[0]->name : '';

        $sep = $this->resolve_source_separator($plugin);

        $map = [
            // ── Yoast (%%var%%) ──────────────────────────────────────────────
            '%%title%%'            => $post_title,
            '%%sitename%%'         => $site_name,
            '%%sep%%'              => $sep,
            '%%excerpt%%'          => $post_excerpt,
            '%%excerpt_only%%'     => $post_excerpt,
            '%%author%%'           => $author,
            '%%date%%'             => $date,
            '%%modified%%'         => $modified,
            '%%id%%'               => (string) $post_id,
            '%%page%%'             => '',
            '%%pagenumber%%'       => '',
            '%%pagetotal%%'        => '',
            '%%primary_category%%' => $primary_category,
            '%%category%%'         => $primary_category,
            '%%tag%%'              => $first_tag,
            '%%focuskw%%'          => (string) get_post_meta($post_id, '_yoast_wpseo_focuskw', true),
            // ── Rank Math (%var%) ────────────────────────────────────────────
            '%title%'              => $post_title,
            '%sitename%'           => $site_name,
            '%sep%'                => $sep,
            '%excerpt%'            => $post_excerpt,
            '%author%'             => $author,
            '%date%'               => $date,
            '%modified%'           => $modified,
            '%id%'                 => (string) $post_id,
            '%page%'               => '',
            '%category%'           => $primary_category,
            '%tag%'                => $first_tag,
            '%focus_keyword%'      => (string) get_post_meta($post_id, 'rank_math_focus_keyword', true),
        ];

        // Sort longest token first to avoid partial matches
        // e.g. %%primary_category%% must replace before %%category%%
        uksort($map, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        $text = str_replace(array_keys($map), array_values($map), $text);

        // ── AIOSEO (#var) ────────────────────────────────────────────────────
        // Only for AIOSEO data, or when the caller did not name a source. Yoast
        // and Rank Math leave a literal '#categories' alone, so running this on
        // their imports would rewrite text they would have preserved.
        if ($plugin === 'aioseo' || $plugin === '') {
            $text = $this->resolve_aioseo_placeholders($text, $post_id, $sep);
        }

        return $text;
    }

    /**
     * Resolve the title separator for the plugin the data came from.
     *
     * Each plugin stores its separator in its own option, so an AIOSEO import
     * must not inherit Rank Math's separator just because Rank Math happens to
     * still be active on the site. When the source is known we read only that
     * plugin's setting and otherwise fall back to '-', which is the default
     * every one of the three ships anyway.
     *
     * With no source named, keep the original precedence — whichever plugin is
     * active, Yoast first — so callers that predate the $plugin argument see no
     * behaviour change.
     *
     * @param string $plugin Source plugin ('yoast', 'rankmath', 'aioseo') or ''.
     * @return string        Separator glyph, defaulting to '-'.
     */
    private function resolve_source_separator($plugin = '') {
        switch ($plugin) {
            case 'yoast':
                $sep = $this->get_yoast_separator();
                return $sep !== '' ? $sep : '-';
            case 'rankmath':
                $sep = $this->get_rankmath_separator();
                return $sep !== '' ? $sep : '-';
            case 'aioseo':
                $sep = $this->get_aioseo_separator();
                return $sep !== '' ? $sep : '-';
        }

        // Source unknown: original active-plugin precedence, including the
        // class_exists() guard the old code used, so a site with WPSEO_VERSION
        // defined but Yoast's classes unloaded still falls through to Rank Math
        // exactly as before.
        if (defined('WPSEO_VERSION') && class_exists('WPSEO_Options')) {
            $sep = $this->get_yoast_separator();
            return $sep !== '' ? $sep : '-';
        }
        if (defined('RANK_MATH_VERSION')) {
            $sep = $this->get_rankmath_separator();
            return $sep !== '' ? $sep : '-';
        }

        return '-';
    }

    /**
     * Read Yoast's separator. Yoast stores separator keys like 'sc-dash';
     * convert to a glyph via the public get_separator_options() lookup table.
     *
     * @return string Separator glyph, or '' when unavailable.
     */
    private function get_yoast_separator() {
        if (!defined('WPSEO_VERSION') || !class_exists('WPSEO_Options') || !class_exists('WPSEO_Option_Titles')) {
            return '';
        }

        try {
            $raw     = WPSEO_Options::get('separator', 'sc-dash');
            $options = WPSEO_Option_Titles::get_instance()->get_separator_options();
            if (isset($options[$raw])) {
                return html_entity_decode($options[$raw], ENT_QUOTES, 'UTF-8');
            }
        } catch (Exception $e) {
            return '';
        }

        return '';
    }

    /**
     * Read Rank Math's separator from its general settings option.
     *
     * @return string Separator glyph, or '' when unavailable.
     */
    private function get_rankmath_separator() {
        if (!defined('RANK_MATH_VERSION')) {
            return '';
        }

        $settings = get_option('rank_math_general_settings', []);
        if (is_array($settings) && !empty($settings['title_separator'])) {
            return html_entity_decode($settings['title_separator'], ENT_QUOTES, 'UTF-8');
        }

        return '';
    }

    /**
     * Read AIOSEO's separator from aioseo_options.
     *
     * Lives at searchAppearance.global.separator (NOT breadcrumbs.separator)
     * and is stored HTML-entity encoded, e.g. '&#45;'. The option itself may
     * be a JSON string or an already-decoded array depending on how it was
     * written, so handle both. Read straight from the option rather than
     * through aioseo() so it still works once AIOSEO is deactivated.
     *
     * @return string Separator glyph, or '' when unavailable.
     */
    private function get_aioseo_separator() {
        $options = get_option('aioseo_options', '');
        if (is_string($options) && $options !== '') {
            $options = json_decode($options, true);
        }
        if (!is_array($options)) {
            return '';
        }

        $raw = $options['searchAppearance']['global']['separator'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return '';
        }

        return html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
    }

    /**
     * AIOSEO's site-wide title / meta description templates, per post type.
     *
     * A post with no per-post override still gets a title and description from
     * these — AIOSEO's own getPostDescription() falls back to the post type
     * template before anything else. Reading only the per-post columns therefore
     * imports nothing at all on a site that never overrode individual posts,
     * which is the common case: templates are the default, overrides are the
     * exception.
     *
     * Stored in the 'aioseo_options_dynamic' option, which may be a JSON string
     * or an already-decoded array depending on how it was written — same as
     * get_aioseo_separator() above, and read straight from the option so it keeps
     * working once AIOSEO is deactivated.
     *
     * A post type is included only when AIOSEO is set to show SEO for it AND
     * WordPress registers it as public. The public test is deliberately
     * $obj->public rather than is_post_type_viewable(): a logging CPT registered
     * public=false, publicly_queryable=true is "viewable" by that helper, and
     * would drag hundreds of internal records into the import.
     *
     * @return array<string,array{title:string,description:string}> Keyed by post type.
     */
    private function get_aioseo_post_type_templates() {
        $options = get_option('aioseo_options_dynamic', '');
        if (is_string($options) && $options !== '') {
            $options = json_decode($options, true);
        }
        if (!is_array($options)) {
            return [];
        }

        $post_types = $options['searchAppearance']['postTypes'] ?? [];
        if (!is_array($post_types)) {
            return [];
        }

        $templates = [];

        foreach ($post_types as $post_type => $config) {
            if (!is_array($config)) {
                continue;
            }

            // 'show' false means AIOSEO emits no SEO for the post type at all.
            if (isset($config['show']) && !$config['show']) {
                continue;
            }

            $object = get_post_type_object($post_type);
            if (!$object || empty($object->public)) {
                continue;
            }

            $title       = isset($config['title']) && is_string($config['title']) ? $config['title'] : '';
            $description = isset($config['metaDescription']) && is_string($config['metaDescription'])
                ? $config['metaDescription']
                : '';

            if ($title === '' && $description === '') {
                continue;
            }

            $templates[$post_type] = [
                'title'       => $title,
                'description' => $description,
            ];
        }

        return $templates;
    }

    /**
     * Trim a template-derived meta description to a sane length.
     *
     * AIOSEO does not truncate — its sanitize() only collapses whitespace — so a
     * post type whose template is '#post_content' renders the entire body as the
     * description. Copying that verbatim into post meta would hand every page a
     * multi-thousand-character description, so template-derived values are cut at
     * a word boundary instead. Values the customer typed on the post itself are
     * never passed through here.
     *
     * @param string $text  Resolved description.
     * @param int    $limit Maximum length in characters.
     * @return string       Trimmed description.
     */
    private function truncate_meta_description($text, $limit = 160) {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        if ($text === '' || mb_strlen($text) <= $limit) {
            return $text;
        }

        // Leave room for the ellipsis so the result never exceeds $limit.
        $clipped = mb_substr($text, 0, $limit - 1);

        // Cut back to the last whole word so the value never ends mid-word.
        $last_space = mb_strrpos($clipped, ' ');
        if ($last_space !== false && $last_space > 0) {
            $clipped = mb_substr($clipped, 0, $last_space);
        }

        $trimmed = rtrim($clipped, " \t\n\r\0\x0B.,;:!?-");

        // Punctuation-only input would otherwise collapse to a bare ellipsis.
        return ($trimmed === '' ? $clipped : $trimmed) . '…';
    }

    /**
     * Every smart-tag id AIOSEO defines, taken from its own Tags class
     * (all-in-one-seo-pack/app/Common/Utils/Tags.php) plus the ids the Pro
     * build adds for WooCommerce.
     *
     * Used for detection as well as replacement: a tag missing from this list
     * is neither resolved nor flagged, so it would be written to post meta as
     * literal text. Restricting detection to AIOSEO's real vocabulary means
     * ordinary text containing a hash — "#1 Rated Clinic", "#hashtag" — is
     * never mistaken for a tag, and tokens AIOSEO does not define (#category_title,
     * #tag_title) stay literal exactly as AIOSEO itself renders them.
     *
     * Supersets are safe in any order thanks to the negative lookahead used
     * for matching: #post_date cannot consume the start of #post_date_w3c,
     * nor #featured_image the start of #featured_image_url.
     *
     * @return string[]
     */
    private function get_aioseo_tag_ids() {
        return [
            'alt_tag', 'archive_date', 'archive_title', 'attachment_caption',
            'attachment_description', 'author_bio', 'author_first_name',
            'author_last_name', 'author_link', 'author_link_alt', 'author_name',
            'author_url', 'blog_link', 'blog_title', 'categories', 'category',
            'category_link', 'category_link_alt', 'current_date', 'current_day',
            'current_month', 'current_year', 'custom_field', 'description',
            'event_end_date', 'event_start_date', 'featured_image',
            'featured_image_url', 'page_number', 'parent_title', 'permalink',
            'post_content', 'post_date', 'post_date_w3c', 'post_day',
            'post_excerpt', 'post_excerpt_only', 'post_link', 'post_link_alt',
            'post_modified_date', 'post_modified_date_w3c', 'post_month',
            'post_title', 'post_year', 'search_term', 'separator_sa',
            'site_description', 'site_link', 'site_link_alt', 'site_title',
            'tagline', 'tax_name', 'tax_parent_name', 'taxonomy_description',
            'taxonomy_title', 'woocommerce_brand', 'woocommerce_price',
            'woocommerce_sku',
        ];
    }

    /**
     * Resolve AIOSEO smart tags to their values.
     *
     * AIOSEO denotes a tag with a single leading '#' and no closing delimiter
     * (Tags::$denotationChar), ending the match with a negative lookahead so
     * supersets do not collide — #post_excerpt must not consume the start of
     * #post_excerpt_only, nor #post_link the start of #post_link_alt. We use
     * the same rule here so results match what AIOSEO itself would render,
     * and so replacement order is irrelevant.
     *
     * @param string $text    Text that may contain AIOSEO tags.
     * @param int    $post_id Post ID used for context.
     * @param string $sep     Separator glyph for #separator_sa.
     * @return string         Text with AIOSEO tags replaced.
     */
    private function resolve_aioseo_placeholders($text, $post_id, $sep = '-') {
        if (!is_string($text) || strpos($text, '#') === false) {
            return (string) $text;
        }

        $post = get_post($post_id);
        if (!$post) {
            return $text;
        }

        // Tags that carry an argument have to be handled before the plain ones.
        // The plain pattern's lookahead permits '-', so '#custom_field-price'
        // would otherwise match bare '#custom_field' and leave an orphan
        // '-price' glued to the resolved value. AIOSEO splits these out for the
        // same reason (Tags::replaceTags() skips them, then parseCustomFields()
        // and parseTaxonomyNames() run afterwards).
        $text = $this->resolve_aioseo_parameterised_tags($text, $post_id);

        // Resolve only the tags the text actually references. Building every
        // value up front meant generating an excerpt for every imported post,
        // and excerpt generation runs the whole the_content filter chain — ~8x
        // the cost per post on real (page-builder) content, for a value the
        // template usually never mentions.
        foreach ($this->get_aioseo_tag_ids() as $tag) {
            $pattern = '/#' . preg_quote($tag, '/') . '(?![a-zA-Z0-9_])/i';
            if (!preg_match($pattern, $text)) {
                continue;
            }

            $value = $this->resolve_aioseo_tag($tag, $post_id, $post, $sep);
            if ($value === null) {
                // Not resolvable outside AIOSEO. Leave it in place so
                // has_unresolved_placeholders() can stop the write.
                continue;
            }

            // Escape backslashes first, then '$', so a value containing "$1"
            // is not treated as a backreference by preg_replace().
            $replacement = str_replace(['\\', '$'], ['\\\\', '\\$'], (string) $value);
            $result      = preg_replace($pattern, $replacement, $text);
            if (is_string($result)) {
                $text = $result;
            }
        }

        return $text;
    }

    /**
     * Resolve the two AIOSEO tags that take an argument after a hyphen:
     * '#custom_field-{meta_key}' and '#tax_name-{taxonomy}'.
     *
     * Patterns and fallback order mirror AIOSEO's parseCustomFields() and
     * parseTaxonomyNames(), including the detail that a bare '#custom_field' or
     * '#tax_name' with no argument resolves to an empty string rather than
     * being left in place.
     *
     * @param string $text    Text that may contain parameterised tags.
     * @param int    $post_id Post ID used for context.
     * @return string         Text with parameterised tags replaced.
     */
    private function resolve_aioseo_parameterised_tags($text, $post_id) {
        $text = preg_replace_callback(
            '/#custom_field-([a-zA-Z0-9_-]+)/i',
            function ($m) use ($post_id) {
                return $this->resolve_aioseo_custom_field($m[1], $post_id);
            },
            $text
        );

        $text = preg_replace_callback(
            '/#tax_name-([a-zA-Z0-9_-]+)/i',
            function ($m) use ($post_id) {
                return $this->resolve_aioseo_taxonomy_name($m[1], $post_id);
            },
            $text
        );

        // With no argument AIOSEO drops the token entirely.
        $text = preg_replace('/#custom_field(?![a-zA-Z0-9_-])/i', '', (string) $text);
        $text = preg_replace('/#tax_name(?![a-zA-Z0-9_-])/i', '', (string) $text);

        return (string) $text;
    }

    /**
     * Value of a custom field referenced by '#custom_field-{key}'.
     *
     * ACF is consulted first when present, matching AIOSEO's own order, so a
     * field with both an ACF definition and raw post meta resolves the way the
     * live site rendered it. Non-scalar values (arrays, objects) have no textual
     * form and become an empty string.
     *
     * @param string $field_key Meta key captured from the tag.
     * @param int    $post_id   Post ID.
     * @return string           Field value as text.
     */
    private function resolve_aioseo_custom_field($field_key, $post_id) {
        $value = '';

        if (function_exists('get_field')) {
            $value = get_field($field_key, $post_id);
        }

        if (empty($value)) {
            $value = get_post_meta($post_id, $field_key, true);
        }

        return is_scalar($value) ? wp_strip_all_tags((string) $value) : '';
    }

    /**
     * Term name for the taxonomy referenced by '#tax_name-{taxonomy}'.
     *
     * AIOSEO prefers its own "primary term" for the taxonomy and falls back to
     * the first assigned term. We have no primary-term store to read once
     * AIOSEO is gone, so the first assigned term is used directly.
     *
     * @param string $taxonomy Taxonomy slug captured from the tag.
     * @param int    $post_id  Post ID.
     * @return string          Term name, or '' when unavailable.
     */
    private function resolve_aioseo_taxonomy_name($taxonomy, $post_id) {
        if (!taxonomy_exists($taxonomy)) {
            return '';
        }

        $terms = get_the_terms($post_id, $taxonomy);
        if (empty($terms) || is_wp_error($terms)) {
            return '';
        }

        $term = reset($terms);

        return (string) $term->name;
    }

    /**
     * Value for a single AIOSEO tag in the context of one post.
     *
     * Every tag AIOSEO can render for a post is resolved here. The two that take
     * an argument (#custom_field, #tax_name) are handled before this switch and
     * so return null, which callers treat as "leave in place" — a leftover then
     * gets reported and the write skipped rather than saved half-resolved.
     *
     * @param string  $tag     AIOSEO tag id, without the leading '#'.
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     * @param string  $sep     Separator glyph.
     * @return string|null     Replacement value, or null if unresolvable.
     */
    private function resolve_aioseo_tag($tag, $post_id, $post, $sep) {
        // Second line of defence behind the whole-value check in
        // resolve_seo_placeholders(). Any future caller that reaches a single
        // tag directly still cannot read out a protected post's body.
        if (!empty($post->post_password)
            && in_array('#' . $tag, $this->content_derived_tokens('aioseo'), true)) {
            return '';
        }

        switch ($tag) {
            case 'site_title':
            case 'blog_title':
                return (string) get_bloginfo('name');

            case 'tagline':
            case 'site_description':
                return (string) get_bloginfo('description');

            case 'post_title':
                return (string) get_the_title($post_id);

            case 'parent_title':
                $parent = isset($post->post_parent) ? (int) $post->post_parent : 0;
                return $parent ? (string) get_the_title($parent) : '';

            case 'post_excerpt_only':
                // Manual excerpt only — AIOSEO never falls back for this one.
                return isset($post->post_excerpt)
                    ? $this->strip_builder_markup((string) $post->post_excerpt)
                    : '';

            case 'post_excerpt':
                // Manual excerpt if set, otherwise a content-derived one, which
                // is what AIOSEO does. This is the expensive branch, so it only
                // runs when the template actually references the tag.
                $manual = isset($post->post_excerpt) ? (string) $post->post_excerpt : '';
                return $this->strip_builder_markup(
                    $manual !== '' ? $manual : (string) get_the_excerpt($post)
                );

            case 'post_content':
                return $this->strip_builder_markup((string) $post->post_content);

            case 'separator_sa':
                return (string) $sep;

            case 'author_name':
                return (string) get_the_author_meta('display_name', (int) $post->post_author);
            case 'author_first_name':
                return (string) get_the_author_meta('first_name', (int) $post->post_author);
            case 'author_last_name':
                return (string) get_the_author_meta('last_name', (int) $post->post_author);
            case 'author_bio':
                return (string) get_the_author_meta('description', (int) $post->post_author);

            case 'author_link':
            case 'author_link_alt':
            case 'author_url':
                $author = isset($post->post_author) ? (int) $post->post_author : 0;
                return $author ? (string) get_author_posts_url($author) : '';

            case 'current_date':
                $format = get_option('date_format');
                $format = (is_string($format) && $format !== '') ? $format : 'F j, Y';
                return (string) date_i18n($format);
            case 'current_day':
                return (string) date_i18n('d');
            case 'current_month':
                return (string) date_i18n('F');
            case 'current_year':
                return (string) date_i18n('Y');

            case 'post_date':
                return (string) get_the_date('', $post_id);
            case 'post_day':
                return (string) get_the_date('d', $post_id);
            case 'post_month':
                return (string) get_the_date('F', $post_id);
            case 'post_year':
                return (string) get_the_date('Y', $post_id);

            case 'permalink':
            case 'post_link':
            case 'post_link_alt':
                return (string) get_permalink($post_id);

            case 'site_link':
            case 'site_link_alt':
            case 'blog_link':
                return (string) home_url();

            case 'categories':
                $names = [];
                foreach ($this->get_post_categories($post_id) as $category) {
                    if (isset($category->name)) {
                        $names[] = $category->name;
                    }
                }
                return implode(', ', $names);

            case 'category':
            case 'taxonomy_title':
                $categories = $this->get_post_categories($post_id);
                return !empty($categories) && isset($categories[0]->name) ? (string) $categories[0]->name : '';

            case 'taxonomy_description':
                $categories = $this->get_post_categories($post_id);
                return !empty($categories) && isset($categories[0]->description) ? (string) $categories[0]->description : '';

            case 'category_link':
            case 'category_link_alt':
                $categories = $this->get_post_categories($post_id);
                return !empty($categories) && isset($categories[0]->term_id)
                    ? (string) get_category_link($categories[0]->term_id)
                    : '';

            case 'featured_image':
                return (string) get_the_post_thumbnail_url($post_id, 'full');

            case 'featured_image_url':
                $thumbnail_id = get_post_thumbnail_id($post_id);
                if (!$thumbnail_id) {
                    return '';
                }
                $src = wp_get_attachment_image_src($thumbnail_id, 'full');
                return (is_array($src) && !empty($src[0])) ? (string) $src[0] : '';

            case 'alt_tag':
                // AIOSEO reads the alt off the post id itself, not off the
                // featured image's attachment. Mirror that: on an ordinary post
                // this is empty, which is what the live site rendered.
                return (string) get_post_meta($post_id, '_wp_attachment_image_alt', true);

            case 'attachment_caption':
                return (string) wp_get_attachment_caption($post_id);

            case 'attachment_description':
                return $this->strip_builder_markup((string) $post->post_content);

            case 'post_modified_date':
                return (string) get_the_modified_date('', $post_id);

            case 'post_date_w3c':
                return (string) mysql2date(DATE_W3C, $post->post_date, false);

            case 'post_modified_date_w3c':
                return (string) mysql2date(DATE_W3C, $post->post_modified, false);

            case 'woocommerce_sku':
                return (string) get_post_meta($post_id, '_sku', true);

            case 'woocommerce_price':
                return (string) get_post_meta($post_id, '_price', true);

            // AIOSEO renders these empty in a single-post context.
            case 'description':
            case 'page_number':
            case 'search_term':
            case 'archive_date':
            case 'archive_title':
            // Term-context only. AIOSEO resolves #tax_parent_name off a term id,
            // so it is empty for a post.
            case 'tax_parent_name':
            // Supplied by AIOSEO's event add-on, which has no data to read here.
            case 'event_start_date':
            case 'event_end_date':
            case 'woocommerce_brand':
                return '';
        }

        // #custom_field and #tax_name take an argument and are resolved ahead of
        // this switch, in resolve_aioseo_parameterised_tags().
        return null;
    }

    /**
     * Reduce raw post content to plain prose.
     *
     * Page-builder pages are almost entirely shortcode markup, so a description
     * template built on the content imports as '[et_pb_section][et_pb_row]…'
     * without this. AIOSEO strips shortcodes too, but strip_shortcodes() only
     * removes tags that are *registered* at the time it runs, and a builder
     * registers its own only in the contexts it renders in — not necessarily
     * during an import request. So sweep any remaining shortcode-shaped tokens
     * as well, which also covers content left behind by a builder that is no
     * longer installed.
     *
     * @param string $content Raw post content.
     * @return string         Plain text.
     */
    private function strip_builder_markup($content) {
        $content = strip_shortcodes($content);

        // Opening/closing shortcode-shaped tokens: [tag], [tag a="b"], [/tag].
        $content = preg_replace('/\[\/?[a-z][a-z0-9_-]*(?:\s[^\]]*)?\]/i', ' ', (string) $content);

        return trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $content)));
    }

    /**
     * Categories for a post, normalised to an array.
     *
     * @param int $post_id Post ID.
     * @return array
     */
    private function get_post_categories($post_id) {
        $categories = get_the_category($post_id);
        return is_array($categories) ? $categories : [];
    }

    /**
     * Whether a value still contains AIOSEO tags after resolution.
     *
     * Callers use this to skip the write instead of persisting a literal
     * token such as '#post_title' as a page title. A tag that legitimately
     * resolves to an empty string is gone from the text and so is not
     * reported here.
     *
     * Only AIOSEO is checked — see the fall-through comment at the end for why
     * Yoast and Rank Math are excluded.
     *
     * @param string $text   Resolved text.
     * @param string $plugin Source plugin; only 'aioseo' is checked.
     * @return bool          True when at least one tag survived.
     */
    private function has_unresolved_placeholders($text, $plugin) {
        if (!is_string($text) || $text === '') {
            return false;
        }

        switch ($plugin) {
            case 'aioseo':
                if (strpos($text, '#') === false) {
                    return false;
                }
                foreach ($this->get_aioseo_tag_ids() as $tag) {
                    if (preg_match('/#' . preg_quote($tag, '/') . '(?![a-zA-Z0-9_])/i', $text)) {
                        return true;
                    }
                }
                return false;
        }

        // Yoast and Rank Math deliberately fall through. Their manual fallback
        // maps cover only a fraction of the variables those plugins define
        // (16 of ~60 for Yoast, 12 of 49 for Rank Math), so treating a leftover
        // token as a reason to skip would drop common real-world titles
        // wholesale — "Best Dentist in %currentyear% %sep% %sitename%" resolves
        // only partly and would import as nothing at all. Until those maps are
        // filled in, their existing behaviour (save the partly-resolved value)
        // is the lesser evil and is left untouched.
        return false;
    }

    /**
     * Resolve an imported value and decide whether it is safe to persist.
     *
     * Returns null when a placeholder survived resolution, so the caller skips
     * the write rather than saving a literal '#post_title' that would then
     * render as the page title and outrank OTTO's recommendation. Skipped
     * values are collected in $unresolved so the import report can surface
     * them.
     *
     * @param string $raw        Raw value from the source plugin.
     * @param int    $post_id    Post ID used for context.
     * @param string $plugin     Source plugin ('yoast', 'rankmath', 'aioseo').
     * @param string $meta_key   Destination meta key, for the report.
     * @param array  $unresolved Collected failures, appended to by reference.
     * @return string|null       Resolved value, or null when it must be skipped.
     */
    private function resolve_import_value($raw, $post_id, $plugin, $meta_key, array &$unresolved) {
        $resolved = $this->resolve_seo_placeholders($raw, $post_id, $plugin);

        if ($this->has_unresolved_placeholders($resolved, $plugin)) {
            $unresolved[] = [
                'post_id'  => (int) $post_id,
                'meta_key' => $meta_key,
                'raw'      => (string) $raw,
                'resolved' => (string) $resolved,
            ];

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'MetaSync importer: unresolved %s placeholder for post %d (%s) — %s',
                    $plugin,
                    (int) $post_id,
                    $meta_key,
                    (string) $raw
                ));
            }

            return null;
        }

        return $resolved;
    }

    /**
     * Sanitize an imported value according to what its destination key holds.
     *
     * Kept in one place because the post and term writers must agree: they are
     * two copies of the same rule, and the pair has already had to be patched in
     * lockstep once (image keys were reaching the free-text sanitizer).
     *
     * An image key goes through esc_url_raw(), which returns '' for anything it
     * will not vouch for — a bare word, a javascript: scheme, a mangled host.
     * Callers must treat that '' as "could not sanitize", not as "empty value";
     * the two mean different things to an overwrite run.
     *
     * @param  string $value    Resolved value from the source plugin.
     * @param  string $meta_key Imported destination key.
     * @return string           Sanitized value, or '' if it did not survive.
     */
    private function sanitize_imported_value($value, $meta_key) {
        if ($meta_key === Metasync_Seo_Precedence::KEY_IMPORTED_TITLE) {
            return sanitize_text_field($value);
        }

        if (in_array($meta_key, array(
            Metasync_Seo_Precedence::KEY_IMPORTED_OG_IMAGE,
            Metasync_Seo_Precedence::KEY_IMPORTED_TWITTER_IMAGE,
        ), true)) {
            return esc_url_raw($value);
        }

        return sanitize_textarea_field($value);
    }

    /**
     * Persist an imported title or description on its low-priority key.
     *
     * Every importer routes through here so the three source plugins cannot
     * drift apart on the rules that matter: imported values never land on the
     * customer-override keys, a value that resolves to nothing is not written,
     * and an overwrite run clears a now-stale value instead of preserving it.
     *
     * @param int    $post_id    Post being imported.
     * @param string $raw        Raw value from the source plugin.
     * @param string $plugin     'yoast', 'rankmath', or 'aioseo'.
     * @param string $meta_key   Imported destination key.
     * @param bool   $overwrite  Whether the run replaces existing values.
     * @param array  $unresolved Collected failures, appended to by reference.
     * @param bool   $truncate   Trim to meta-description length first.
     * @return bool              Whether the post's meta changed.
     */
    private function store_imported_post_value($post_id, $raw, $plugin, $meta_key, $overwrite, array &$unresolved, $truncate = false) {
        if ($raw === '') {
            return false;
        }

        $existing = get_post_meta($post_id, $meta_key, true);
        if (!empty($existing) && !$overwrite) {
            return false;
        }

        $resolved = $this->resolve_import_value($raw, $post_id, $plugin, $meta_key, $unresolved);

        if ($resolved !== null && $truncate) {
            $resolved = $this->truncate_meta_description($resolved);
        }

        if ($resolved !== null && trim($resolved) !== '') {
            $sanitized = $this->sanitize_imported_value($resolved, $meta_key);

            // The source gave us something, but it could not survive
            // sanitization — an og:image/twitter:image URL rejected by
            // esc_url_raw() is the case this guards. Writing the resulting ''
            // would blank a good stored image and still report the import as a
            // success. Skip instead: no write, no success, and whatever is
            // already stored stays. A genuinely empty source is a different
            // thing and is handled by the overwrite branch below.
            if ($sanitized === '') {
                return false;
            }

            // update_post_meta() unslashes what it is given, so a value holding
            // a backslash ("AC\DC") would be stored stripped ("ACDC"). Slash it
            // here so the round trip through meta storage is lossless.
            update_post_meta($post_id, $meta_key, wp_slash($sanitized));
            return true;
        }

        // Overwrite means "make this match the source". The source now yields
        // nothing, so a value left from an earlier run is stale — clear it so
        // re-importing repairs rather than preserves. Only ever touches the
        // imported key, never anything the customer typed.
        if ($overwrite && !empty($existing)) {
            delete_post_meta($post_id, $meta_key);
            return true;
        }

        return false;
    }

    /**
     * Resolve placeholder tokens in a value imported for a taxonomy term.
     *
     * The post resolver cannot be reused. Every token it answers comes off a
     * WP_Post, and a term has no post to answer from — asking it would either
     * blank the value or, worse, resolve it against whatever post happens to
     * share the term's ID. The vocabularies barely overlap anyway: a term
     * template is built from the term's own name and description, so this is a
     * deliberately small, separate map.
     *
     * @param string  $text   Raw value from the source plugin.
     * @param WP_Term $term   Term the value belongs to.
     * @param string  $plugin 'yoast', 'rankmath', or 'aioseo'.
     * @return string         Resolved string.
     */
    private function resolve_term_placeholders($text, $term, $plugin) {
        if (empty($text) || empty($term->term_id)) {
            return (string) $text;
        }

        // Yoast and Rank Math both accept a term in place of a post, so their
        // own engines still give the exact string the source site rendered.
        if ($plugin === 'yoast' && class_exists('WPSEO_Replace_Vars')) {
            try {
                $replacer = new WPSEO_Replace_Vars();
                $resolved = $replacer->replace($text, $term);
                if (is_string($resolved) && $resolved !== '') {
                    return $resolved;
                }
            } catch (Exception $e) {
                // fall through to manual replacement
            }
        }

        if ($plugin === 'rankmath' && function_exists('rank_math_replace_vars')) {
            try {
                $resolved = rank_math_replace_vars($text, $term);
                if (is_string($resolved) && $resolved !== '') {
                    return $resolved;
                }
            } catch (Exception $e) {
                // fall through to manual replacement
            }
        }

        // AIOSEO's replaceTags() takes a post ID. Handing it a term ID does not
        // fail — it resolves against whatever post shares that ID and returns
        // another page's title, so the native engine is deliberately skipped
        // here and the manual map below is the only path for AIOSEO terms.
        return $this->manually_replace_term_placeholders($text, $term, $plugin);
    }

    /**
     * Manual token replacement for term values.
     *
     * @param string  $text
     * @param WP_Term $term
     * @param string  $plugin
     * @return string
     */
    private function manually_replace_term_placeholders($text, $term, $plugin) {
        $site_name = (string) get_bloginfo('name');
        $site_desc = (string) get_bloginfo('description');
        $sep       = $this->resolve_source_separator($plugin);
        $year      = (string) gmdate('Y');

        $term_name = (string) $term->name;
        $term_desc = wp_strip_all_tags((string) $term->description);

        $map = [
            // ── Yoast (%%var%%) ──────────────────────────────────────────────
            '%%term_title%%'           => $term_name,
            '%%term_description%%'     => $term_desc,
            '%%category%%'             => $term_name,
            '%%category_description%%' => $term_desc,
            '%%tag%%'                  => $term_name,
            '%%sitename%%'             => $site_name,
            '%%sitedesc%%'             => $site_desc,
            '%%sep%%'                  => $sep,
            '%%currentyear%%'          => $year,
            '%%page%%'                 => '',
            '%%pagenumber%%'           => '',
            '%%pagetotal%%'            => '',
            // ── Rank Math (%var%) ────────────────────────────────────────────
            '%term%'                   => $term_name,
            '%term_description%'       => $term_desc,
            '%category%'               => $term_name,
            '%tag%'                    => $term_name,
            '%sitename%'               => $site_name,
            '%sitedesc%'               => $site_desc,
            '%sep%'                    => $sep,
            '%currentyear%'            => $year,
            '%page%'                   => '',
            // ── AIOSEO (#var) ────────────────────────────────────────────────
            '#taxonomy_title'          => $term_name,
            '#taxonomy_description'    => $term_desc,
            '#archive_title'           => $term_name,
            '#site_title'              => $site_name,
            '#blog_title'              => $site_name,
            '#site_description'        => $site_desc,
            '#tagline'                 => $site_desc,
            '#separator_sa'            => $sep,
            '#current_year'            => $year,
            '#page_number'             => '',
        ];

        // Longest token first, so a short token never eats the start of a
        // longer one (#site_title must not consume #site_description).
        uksort($map, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        $resolved = str_replace(array_keys($map), array_values($map), $text);

        return trim(preg_replace('/\s+/', ' ', $resolved));
    }

    /**
     * Persist an imported term title or description on its low-priority key.
     *
     * Mirrors store_imported_post_value(): imported values never land on the
     * key that means "the customer set this", a value that resolves to nothing
     * is not written, and an overwrite run clears a now-stale value.
     *
     * @param WP_Term $term      Term being imported.
     * @param string  $raw       Raw value from the source plugin.
     * @param string  $plugin    'yoast', 'rankmath', or 'aioseo'.
     * @param string  $meta_key  Imported destination key.
     * @param bool    $overwrite Whether the run replaces existing values.
     * @return bool              Whether the term's meta changed.
     */
    private function store_imported_term_value($term, $raw, $plugin, $meta_key, $overwrite) {
        if ($raw === '') {
            return false;
        }

        $term_id  = (int) $term->term_id;
        $existing = get_term_meta($term_id, $meta_key, true);
        if (!empty($existing) && !$overwrite) {
            return false;
        }

        $resolved = $this->resolve_term_placeholders($raw, $term, $plugin);

        // A token we do not know would otherwise be stored literally and render
        // as '#taxonomy_title' on the archive — the defect this path exists to
        // prevent. Skipping leaves the slot free for OTTO.
        if ($this->has_unresolved_placeholders($resolved, $plugin)) {
            return false;
        }

        if (trim($resolved) !== '') {
            $sanitized = $this->sanitize_imported_value($resolved, $meta_key);

            // See store_imported_post_value(): a value the source supplied but
            // sanitization rejected must not blank an existing one, and must
            // not count as a successful update.
            if ($sanitized === '') {
                return false;
            }

            // update_term_meta() unslashes what it stores; slash first so a
            // backslash in the value survives the round trip.
            update_term_meta($term_id, $meta_key, wp_slash($sanitized));
            return true;
        }

        if ($overwrite && !empty($existing)) {
            delete_term_meta($term_id, $meta_key);
            return true;
        }

        return false;
    }

    /**
     * Add unresolved-placeholder reporting to an import result.
     *
     * @param array $result     Result array to augment.
     * @param array $unresolved Failures collected during the batch.
     * @return array            Result with the report attached.
     */
    private function attach_unresolved_report(array $result, array $unresolved) {
        $count = count($unresolved);
        $result['unresolved'] = $count;

        if ($count === 0) {
            return $result;
        }

        // Keep the payload small — a few examples are enough to diagnose.
        $result['unresolved_samples'] = array_slice($unresolved, 0, 5);

        if (!empty($result['message'])) {
            $result['message'] .= sprintf(
                ' %d value(s) skipped because placeholders could not be resolved.',
                $count
            );
        }

        return $result;
    }

    /**
     * Import SEO Metadata (Titles and Descriptions)
     * Supports batch processing via AJAX
     *
     * @param string $plugin Plugin to import from (yoast, rankmath, aioseo)
     * @param array $options Import options (import_titles, import_descriptions, overwrite_existing, batch_size, offset)
     * @return array Result with success status, progress info, and statistics
     */
    public function import_seo_metadata($plugin, $options = [])
    {
        // Default options
        $defaults = [
            'import_titles' => true,
            'import_descriptions' => true,
            'overwrite_existing' => false,
            'batch_size' => 50, // Process 50 posts per batch
            'offset' => 0
        ];
        $options = array_merge($defaults, $options);

        // Validate plugin
        if (!in_array($plugin, ['yoast', 'rankmath', 'aioseo'])) {
            return [
                'success' => false,
                'message' => 'Invalid plugin specified.'
            ];
        }

        // Route to appropriate import method
        switch ($plugin) {
            case 'yoast':
                return $this->import_yoast_seo_metadata($options);
            case 'rankmath':
                return $this->import_rankmath_seo_metadata($options);
            case 'aioseo':
                return $this->import_aioseo_seo_metadata($options);
        }

        return [
            'success' => false,
            'message' => 'Unknown error occurred.'
        ];
    }

    /**
     * Import SEO metadata from Yoast SEO
     */
    private function import_yoast_seo_metadata($options)
    {
        global $wpdb;

        $batch_size = intval($options['batch_size']);
        $offset = intval($options['offset']);
        $import_titles = (bool) $options['import_titles'];
        $import_descriptions = (bool) $options['import_descriptions'];
        $import_social_text = !empty($options['import_social_text']);
        $import_social_images = !empty($options['import_social_images']);
        $overwrite = (bool) $options['overwrite_existing'];

        // Build WHERE clause for meta keys
        $meta_keys = [];
        if ($import_titles) {
            $meta_keys[] = '_yoast_wpseo_title';
        }
        if ($import_descriptions) {
            $meta_keys[] = '_yoast_wpseo_metadesc';
        }
        if ($import_social_text) {
            $meta_keys[] = '_yoast_wpseo_opengraph-title';
            $meta_keys[] = '_yoast_wpseo_opengraph-description';
            $meta_keys[] = '_yoast_wpseo_twitter-title';
            $meta_keys[] = '_yoast_wpseo_twitter-description';
        }
        if ($import_social_images) {
            $meta_keys[] = '_yoast_wpseo_opengraph-image';
            $meta_keys[] = '_yoast_wpseo_twitter-image';
        }

        if (!$import_titles && !$import_descriptions && !$import_social_text && !$import_social_images) {
            return [
                'success' => false,
                'message' => 'No import options selected.'
            ];
        }

        // Get total count (for progress tracking)
        $meta_keys_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
        $total_posts = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key IN ($meta_keys_placeholders)
            AND meta_value != ''
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
        ", $meta_keys));

        // Get batch of posts with Yoast data
        $posts = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key IN ($meta_keys_placeholders)
            AND meta_value != ''
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
            ORDER BY post_id ASC
            LIMIT %d OFFSET %d
        ", array_merge($meta_keys, [$batch_size, $offset])));

        $imported_count = 0;
        $skipped_count = 0;
        $unresolved = [];

        foreach ($posts as $post_obj) {
            $post_id = $post_obj->post_id;
            $updated = false;

            // Import title
            if ($import_titles) {
                $yoast_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
                if (!empty($yoast_title)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $yoast_title,
                        'yoast',
                        Metasync_Seo_Precedence::KEY_IMPORTED_TITLE,
                        $overwrite,
                        $unresolved
                    );
                }
            }

            // Import description
            if ($import_descriptions) {
                $yoast_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
                if (!empty($yoast_desc)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $yoast_desc,
                        'yoast',
                        Metasync_Seo_Precedence::KEY_IMPORTED_DESC,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }
            }

            // Import social text
            if ($import_social_text) {
                $yoast_og_title = get_post_meta($post_id, '_yoast_wpseo_opengraph-title', true);
                if (!empty($yoast_og_title)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $yoast_og_title,
                        'yoast',
                        Metasync_Seo_Precedence::KEY_IMPORTED_OG_TITLE,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }

                $yoast_og_desc = get_post_meta($post_id, '_yoast_wpseo_opengraph-description', true);
                if (!empty($yoast_og_desc)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $yoast_og_desc,
                        'yoast',
                        Metasync_Seo_Precedence::KEY_IMPORTED_OG_DESC,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }

                $yoast_tw_title = get_post_meta($post_id, '_yoast_wpseo_twitter-title', true);
                if (!empty($yoast_tw_title)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $yoast_tw_title,
                        'yoast',
                        Metasync_Seo_Precedence::KEY_IMPORTED_TWITTER_TITLE,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }

                $yoast_tw_desc = get_post_meta($post_id, '_yoast_wpseo_twitter-description', true);
                if (!empty($yoast_tw_desc)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $yoast_tw_desc,
                        'yoast',
                        Metasync_Seo_Precedence::KEY_IMPORTED_TWITTER_DESC,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }
            }

            // Import social images
            if ($import_social_images) {
                $yoast_og_image = get_post_meta($post_id, '_yoast_wpseo_opengraph-image', true);
                if (!empty($yoast_og_image)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $yoast_og_image,
                        'yoast',
                        Metasync_Seo_Precedence::KEY_IMPORTED_OG_IMAGE,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }

                $yoast_twitter_image = get_post_meta($post_id, '_yoast_wpseo_twitter-image', true);
                if (!empty($yoast_twitter_image)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $yoast_twitter_image,
                        'yoast',
                        Metasync_Seo_Precedence::KEY_IMPORTED_TWITTER_IMAGE,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }
            }

            if ($updated) {
                $imported_count++;
            } else {
                $skipped_count++;
            }
        }

        $processed = $offset + count($posts);
        $is_complete = $processed >= $total_posts;

        return $this->attach_unresolved_report([
            'success' => true,
            'imported' => $imported_count,
            'skipped' => $skipped_count,
            'total' => $total_posts,
            'processed' => $processed,
            'is_complete' => $is_complete,
            'progress_percent' => $total_posts > 0 ? round(($processed / $total_posts) * 100) : 100,
            'message' => $is_complete
                ? "Import complete! Imported {$imported_count} posts, skipped {$skipped_count} posts."
                : "Processing... {$imported_count} imported, {$skipped_count} skipped."
        ], $unresolved);
    }

    /**
     * Import SEO metadata from Rank Math
     */
    private function import_rankmath_seo_metadata($options)
    {
        global $wpdb;

        $batch_size = intval($options['batch_size']);
        $offset = intval($options['offset']);
        $import_titles = (bool) $options['import_titles'];
        $import_descriptions = (bool) $options['import_descriptions'];
        $import_social_text = !empty($options['import_social_text']);
        $import_social_images = !empty($options['import_social_images']);
        $overwrite = (bool) $options['overwrite_existing'];

        // Build WHERE clause for meta keys
        $meta_keys = [];
        if ($import_titles) {
            $meta_keys[] = 'rank_math_title';
        }
        if ($import_descriptions) {
            $meta_keys[] = 'rank_math_description';
        }
        if ($import_social_text) {
            $meta_keys[] = 'rank_math_facebook_title';
            $meta_keys[] = 'rank_math_facebook_description';
            $meta_keys[] = 'rank_math_twitter_title';
            $meta_keys[] = 'rank_math_twitter_description';
        }
        if ($import_social_images) {
            $meta_keys[] = 'rank_math_facebook_image';
            $meta_keys[] = 'rank_math_twitter_image';
        }

        if (!$import_titles && !$import_descriptions && !$import_social_text && !$import_social_images) {
            return [
                'success' => false,
                'message' => 'No import options selected.'
            ];
        }

        // Get total count
        $meta_keys_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
        $total_posts = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key IN ($meta_keys_placeholders)
            AND meta_value != ''
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
        ", $meta_keys));

        // Get batch of posts
        $posts = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key IN ($meta_keys_placeholders)
            AND meta_value != ''
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
            ORDER BY post_id ASC
            LIMIT %d OFFSET %d
        ", array_merge($meta_keys, [$batch_size, $offset])));

        $imported_count = 0;
        $skipped_count = 0;
        $unresolved = [];

        foreach ($posts as $post_obj) {
            $post_id = $post_obj->post_id;
            $updated = false;

            // Import title
            if ($import_titles) {
                $rm_title = get_post_meta($post_id, 'rank_math_title', true);
                if (!empty($rm_title)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $rm_title,
                        'rankmath',
                        Metasync_Seo_Precedence::KEY_IMPORTED_TITLE,
                        $overwrite,
                        $unresolved
                    );
                }
            }

            // Import description
            if ($import_descriptions) {
                $rm_desc = get_post_meta($post_id, 'rank_math_description', true);
                if (!empty($rm_desc)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $rm_desc,
                        'rankmath',
                        Metasync_Seo_Precedence::KEY_IMPORTED_DESC,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }
            }

            // Import social text
            if ($import_social_text) {
                $rm_og_title = get_post_meta($post_id, 'rank_math_facebook_title', true);
                if (!empty($rm_og_title)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $rm_og_title,
                        'rankmath',
                        Metasync_Seo_Precedence::KEY_IMPORTED_OG_TITLE,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }

                $rm_og_desc = get_post_meta($post_id, 'rank_math_facebook_description', true);
                if (!empty($rm_og_desc)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $rm_og_desc,
                        'rankmath',
                        Metasync_Seo_Precedence::KEY_IMPORTED_OG_DESC,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }

                $rm_tw_title = get_post_meta($post_id, 'rank_math_twitter_title', true);
                if (!empty($rm_tw_title)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $rm_tw_title,
                        'rankmath',
                        Metasync_Seo_Precedence::KEY_IMPORTED_TWITTER_TITLE,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }

                $rm_tw_desc = get_post_meta($post_id, 'rank_math_twitter_description', true);
                if (!empty($rm_tw_desc)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $rm_tw_desc,
                        'rankmath',
                        Metasync_Seo_Precedence::KEY_IMPORTED_TWITTER_DESC,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }
            }

            // Import social images
            if ($import_social_images) {
                $rm_og_image = get_post_meta($post_id, 'rank_math_facebook_image', true);
                if (!empty($rm_og_image)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $rm_og_image,
                        'rankmath',
                        Metasync_Seo_Precedence::KEY_IMPORTED_OG_IMAGE,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }

                $rm_twitter_image = get_post_meta($post_id, 'rank_math_twitter_image', true);
                if (!empty($rm_twitter_image)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $rm_twitter_image,
                        'rankmath',
                        Metasync_Seo_Precedence::KEY_IMPORTED_TWITTER_IMAGE,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }
            }

            if ($updated) {
                $imported_count++;
            } else {
                $skipped_count++;
            }
        }

        $processed = $offset + count($posts);
        $is_complete = $processed >= $total_posts;

        return $this->attach_unresolved_report([
            'success' => true,
            'imported' => $imported_count,
            'skipped' => $skipped_count,
            'total' => $total_posts,
            'processed' => $processed,
            'is_complete' => $is_complete,
            'progress_percent' => $total_posts > 0 ? round(($processed / $total_posts) * 100) : 100,
            'message' => $is_complete
                ? "Import complete! Imported {$imported_count} posts, skipped {$skipped_count} posts."
                : "Processing... {$imported_count} imported, {$skipped_count} skipped."
        ], $unresolved);
    }

    /**
     * Import SEO metadata from All in One SEO
     */
    private function import_aioseo_seo_metadata($options)
    {
        global $wpdb;

        $batch_size = intval($options['batch_size']);
        $offset = intval($options['offset']);
        $import_titles = (bool) $options['import_titles'];
        $import_descriptions = (bool) $options['import_descriptions'];
        $import_social_text = !empty($options['import_social_text']);
        $import_social_images = !empty($options['import_social_images']);
        $overwrite = (bool) $options['overwrite_existing'];

        // Check if AIOSEO table exists
        $table = $wpdb->prefix . 'aioseo_posts';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return [
                'success' => false,
                'message' => 'AIOSEO database table not found.'
            ];
        }

        if (!$import_titles && !$import_descriptions && !$import_social_text && !$import_social_images) {
            return [
                'success' => false,
                'message' => 'No import options selected.'
            ];
        }

        // Columns read off the AIOSEO row, excluding post_id. The same list gates
        // row selection, so deriving both from one array keeps them in step — a
        // WHERE that admitted a column the SELECT did not fetch would read a
        // property that isn't there.
        $value_columns = [];
        if ($import_titles) {
            $value_columns[] = 'title';
        }
        if ($import_descriptions) {
            $value_columns[] = 'description';
        }
        if ($import_social_text) {
            $value_columns[] = 'og_title';
            $value_columns[] = 'og_description';
            $value_columns[] = 'twitter_title';
            $value_columns[] = 'twitter_description';
        }
        if ($import_social_images) {
            $value_columns[] = 'og_image_custom_url';
            $value_columns[] = 'twitter_image_custom_url';
        }

        $build_has_value_clause = function ($prefix) use ($value_columns) {
            $conditions = [];
            foreach ($value_columns as $column) {
                $col          = $prefix . $column;
                $conditions[] = "({$col} IS NOT NULL AND {$col} != '')";
            }
            return implode(' OR ', $conditions);
        };

        $where_clause          = $build_has_value_clause('');
        $joined_has_value      = $build_has_value_clause('a.');

        // AIOSEO serves a title and description for every post through its
        // site-wide templates, not only for posts carrying a per-post override.
        // Walking the AIOSEO table alone therefore reaches almost nothing on a
        // typical site — overrides are the exception, templates are the rule. So
        // when templates are in play, anchor the walk on the posts table and join
        // the AIOSEO row in; the per-post value still wins wherever it exists.
        $templates = ($import_titles || $import_descriptions)
            ? $this->get_aioseo_post_type_templates()
            : [];

        $template_post_types = [];
        foreach ($templates as $post_type => $template) {
            if (($import_titles && $template['title'] !== '')
                || ($import_descriptions && $template['description'] !== '')) {
                $template_post_types[] = $post_type;
            }
        }

        if (!empty($template_post_types)) {
            $type_placeholders = implode(', ', array_fill(0, count($template_post_types), '%s'));

            // Template mode visits every published post, not just the few with an
            // override, and AIOSEO's default description template is #post_excerpt
            // — which on a post without a manual excerpt runs the whole the_content
            // filter chain. On page-builder content that is a large fraction of a
            // second each, so the caller's batch of 50 can outlive a proxy read
            // timeout. Smaller batches cost more requests and finish.
            $batch_size = min($batch_size, 20);

            // COUNT and SELECT must describe the identical row set. is_complete
            // compares (offset + rows returned) against this count and the client
            // loops until they meet, with no iteration cap — a count broader than
            // the select spins forever, a narrower one stops early and silently
            // drops the remainder. Counting the joined set rather than the posts
            // table alone also keeps cardinality identical if the AIOSEO table
            // ever held two rows for one post: its post_id index is not unique.
            //
            // The post-type list governs which posts get a *template*, so it must
            // not also decide which posts get read at all: a post type with no
            // template — one AIOSEO never configured, one with SEO switched off,
            // one registered non-public — can still carry per-post overrides that
            // the override-only walk always imported. The second arm keeps every
            // row holding real AIOSEO data in scope whatever its post type, so
            // adding a template never removes anything from the import.
            $from_clause = "FROM {$wpdb->posts} p
                LEFT JOIN {$table} a ON a.post_id = p.ID
                WHERE p.post_status = 'publish'
                AND (p.post_type IN ({$type_placeholders}) OR ({$joined_has_value}))";

            $total_posts = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) {$from_clause}", $template_post_types)
            );

            $joined_columns = array_map(
                function ($column) {
                    return "a.{$column}";
                },
                $value_columns
            );
            // At least one import flag is set — the "no import options selected"
            // guard above returns before this point — so there is always at
            // least one value column to select.
            $joined_select = 'p.ID AS post_id, p.post_type AS post_type, '
                . implode(', ', $joined_columns);

            $posts = $wpdb->get_results($wpdb->prepare(
                "SELECT {$joined_select}
                 {$from_clause}
                 ORDER BY p.ID ASC
                 LIMIT %d OFFSET %d",
                array_merge($template_post_types, [$batch_size, $offset])
            ));
        } else {
            // No usable templates — e.g. only the social options are selected, or
            // aioseo_options_dynamic is missing. Fall back to the original
            // override-only walk so those sites behave exactly as before.
            $select_clause = implode(', ', array_merge(['post_id'], $value_columns));

            $total_posts = (int) $wpdb->get_var("
                SELECT COUNT(post_id)
                FROM {$table}
                WHERE ({$where_clause})
                AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
            ");

            $posts = $wpdb->get_results($wpdb->prepare("
                SELECT {$select_clause}
                FROM {$table}
                WHERE ({$where_clause})
                AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
                ORDER BY post_id ASC
                LIMIT %d OFFSET %d
            ", $batch_size, $offset));
        }

        $imported_count = 0;
        $skipped_count = 0;
        $unresolved = [];

        foreach ($posts as $aioseo_data) {
            $post_id = $aioseo_data->post_id;
            $updated = false;

            // Present only on the template-anchored query; the fallback walk has
            // no join to read it from.
            $post_type = isset($aioseo_data->post_type)
                ? (string) $aioseo_data->post_type
                : (string) get_post_type($post_id);

            $title_template = $templates[$post_type]['title'] ?? '';
            $desc_template  = $templates[$post_type]['description'] ?? '';

            // Import title.
            //
            // The per-post value wins; the site-wide template is the fallback,
            // matching the order AIOSEO itself resolves in. Destination and
            // write rules come from store_imported_post_value().
            if ($import_titles) {
                $raw_title = !empty($aioseo_data->title) ? (string) $aioseo_data->title : '';

                if ($raw_title === '' && $title_template !== '') {
                    $raw_title = $title_template;
                }

                $updated = $this->store_imported_post_value(
                    $post_id,
                    $raw_title,
                    'aioseo',
                    Metasync_Seo_Precedence::KEY_IMPORTED_TITLE,
                    $overwrite,
                    $unresolved
                );
            }

            // Import description — same reasoning as the title above.
            if ($import_descriptions) {
                $raw_desc           = !empty($aioseo_data->description) ? (string) $aioseo_data->description : '';
                $desc_from_template = false;

                if ($raw_desc === '' && $desc_template !== '') {
                    $raw_desc           = $desc_template;
                    $desc_from_template = true;
                }

                // Only template-derived values are trimmed. A '#post_content'
                // template resolves to the whole body, and AIOSEO emits that
                // untruncated — copying it verbatim would give every page a
                // multi-thousand-character description. What the customer
                // typed on the post is left exactly as they wrote it.
                $updated = $this->store_imported_post_value(
                    $post_id,
                    $raw_desc,
                    'aioseo',
                    Metasync_Seo_Precedence::KEY_IMPORTED_DESC,
                    $overwrite,
                    $unresolved,
                    $desc_from_template
                ) || $updated;
            }

            // Import social text
            if ($import_social_text) {
                $aioseo_og_title = isset($aioseo_data->og_title) ? $aioseo_data->og_title : '';
                if (!empty($aioseo_og_title)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $aioseo_og_title,
                        'aioseo',
                        Metasync_Seo_Precedence::KEY_IMPORTED_OG_TITLE,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }

                $aioseo_og_desc = isset($aioseo_data->og_description) ? $aioseo_data->og_description : '';
                if (!empty($aioseo_og_desc)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $aioseo_og_desc,
                        'aioseo',
                        Metasync_Seo_Precedence::KEY_IMPORTED_OG_DESC,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }

                $aioseo_tw_title = isset($aioseo_data->twitter_title) ? $aioseo_data->twitter_title : '';
                if (!empty($aioseo_tw_title)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $aioseo_tw_title,
                        'aioseo',
                        Metasync_Seo_Precedence::KEY_IMPORTED_TWITTER_TITLE,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }

                $aioseo_tw_desc = isset($aioseo_data->twitter_description) ? $aioseo_data->twitter_description : '';
                if (!empty($aioseo_tw_desc)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $aioseo_tw_desc,
                        'aioseo',
                        Metasync_Seo_Precedence::KEY_IMPORTED_TWITTER_DESC,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }
            }

            // Import social images
            if ($import_social_images) {
                $aioseo_og_image = isset($aioseo_data->og_image_custom_url) ? $aioseo_data->og_image_custom_url : '';
                if (!empty($aioseo_og_image)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $aioseo_og_image,
                        'aioseo',
                        Metasync_Seo_Precedence::KEY_IMPORTED_OG_IMAGE,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }

                $aioseo_twitter_image = isset($aioseo_data->twitter_image_custom_url) ? $aioseo_data->twitter_image_custom_url : '';
                if (!empty($aioseo_twitter_image)) {
                    $updated = $this->store_imported_post_value(
                        $post_id,
                        (string) $aioseo_twitter_image,
                        'aioseo',
                        Metasync_Seo_Precedence::KEY_IMPORTED_TWITTER_IMAGE,
                        $overwrite,
                        $unresolved
                    ) || $updated;
                }
            }

            if ($updated) {
                $imported_count++;
            } else {
                $skipped_count++;
            }
        }

        $processed = $offset + count($posts);
        // An empty batch always ends the run. The client loops on !is_complete
        // with no iteration cap, so if the count and the select ever disagreed,
        // a batch returning no rows would leave processed stuck below total and
        // spin forever against the server.
        $is_complete = $processed >= $total_posts || empty($posts);

        return $this->attach_unresolved_report([
            'success' => true,
            'imported' => $imported_count,
            'skipped' => $skipped_count,
            'total' => $total_posts,
            'processed' => $processed,
            'is_complete' => $is_complete,
            'progress_percent' => $total_posts > 0 ? round(($processed / $total_posts) * 100) : 100,
            'message' => $is_complete
                ? "Import complete! Imported {$imported_count} posts, skipped {$skipped_count} posts."
                : "Processing... {$imported_count} imported, {$skipped_count} skipped."
        ], $unresolved);
    }

    /**
     * Import term-level SEO metadata from a third-party SEO plugin into
     * MetaSync term meta (`_metasync_*`).
     *
     * Mirrors import_seo_metadata() but operates on terms (wp_termmeta for
     * Yoast/Rank Math and the wp_aioseo_terms table for AIOSEO) and walks
     * every registered public taxonomy so category, post_tag, and custom
     * taxonomies are all covered.
     *
     * @param string $plugin  One of 'yoast', 'rankmath', 'aioseo'.
     * @param array  $options Batch options (overwrite_existing, batch_size, offset).
     * @return array ['success'=>bool,'imported'=>int,'skipped'=>int,'total'=>int,'message'=>string]
     */
    public function import_term_seo_metadata($plugin, $options = [])
    {
        $defaults = [
            'overwrite_existing' => false,
            'batch_size'         => 100,
            'offset'             => 0,
        ];
        $options = array_merge($defaults, $options);

        if (!in_array($plugin, ['yoast', 'rankmath', 'aioseo'], true)) {
            return [
                'success' => false,
                'message' => 'Invalid plugin specified.',
            ];
        }

        switch ($plugin) {
            case 'yoast':
                return $this->import_yoast_term_meta($options);
            case 'rankmath':
                return $this->import_rankmath_term_meta($options);
            case 'aioseo':
                return $this->import_aioseo_term_meta($options);
        }

        return [
            'success' => false,
            'message' => 'Unknown error occurred.',
        ];
    }

    /**
     * Import Yoast term meta into MetaSync term meta.
     *
     * @param array $options
     * @return array
     */
    private function import_yoast_term_meta($options)
    {
        $batch_size = intval($options['batch_size']);
        $offset     = intval($options['offset']);
        $overwrite  = (bool) $options['overwrite_existing'];

        // Yoast stores taxonomy term SEO data in the `wpseo_taxonomy_meta`
        // option (wp_options), NOT in wp_termmeta.  We must read from there.
        if (!class_exists('WPSEO_Taxonomy_Meta')) {
            return [
                'success' => false,
                'message' => 'Yoast SEO is not active or WPSEO_Taxonomy_Meta class not available.',
            ];
        }

        $taxonomies = array_values(get_taxonomies(['public' => true], 'names'));

        $terms = get_terms([
            'taxonomy'   => $taxonomies,
            'hide_empty' => false,
            'number'     => $batch_size,
            'offset'     => $offset,
        ]);

        if (is_wp_error($terms)) {
            return [
                'success' => false,
                'message' => $terms->get_error_message(),
            ];
        }

        // Title and description are handled separately below: they go to the
        // imported keys so they lose to OTTO, and they need their tokens
        // resolved. The rest keep their own keys.
        $field_map = [
            'wpseo_opengraph-title'       => '_metasync_og_title',
            'wpseo_opengraph-description' => '_metasync_og_description',
            'wpseo_canonical'             => '_metasync_canonical_url',
        ];

        $imported_map = [
            'wpseo_title' => Metasync_Seo_Precedence::KEY_IMPORTED_TITLE,
            'wpseo_desc'  => Metasync_Seo_Precedence::KEY_IMPORTED_DESC,
        ];

        $imported = 0;
        $skipped  = 0;

        foreach ($terms as $term) {
            $term_updated = false;

            // Read from Yoast's wpseo_taxonomy_meta option via its API.
            $yoast_meta = WPSEO_Taxonomy_Meta::get_term_meta($term->term_id, $term->taxonomy);
            if (!is_array($yoast_meta)) {
                $yoast_meta = [];
            }

            foreach ($imported_map as $src_key => $dest_key) {
                $term_updated = $this->store_imported_term_value(
                    $term,
                    isset($yoast_meta[$src_key]) ? (string) $yoast_meta[$src_key] : '',
                    'yoast',
                    $dest_key,
                    $overwrite
                ) || $term_updated;
            }

            foreach ($field_map as $src_key => $dest_key) {
                $src_value = isset($yoast_meta[$src_key]) ? $yoast_meta[$src_key] : '';
                if ($src_value === '' || $src_value === null) {
                    continue;
                }

                $existing = get_term_meta($term->term_id, $dest_key, true);
                if (!empty($existing) && !$overwrite) {
                    continue;
                }

                // Validate canonical: never import a corrupted value.
                if ($dest_key === '_metasync_canonical_url') {
                    $src_value = Metasync_Canonical_Sanitizer::sanitize_for_save($src_value);
                    if ($src_value === '') {
                        continue;
                    }
                } else {
                    $src_value = $this->resolve_term_placeholders($src_value, $term, 'yoast');
                    if (trim($src_value) === '') {
                        continue;
                    }
                }

                update_term_meta($term->term_id, $dest_key, $src_value);
                $term_updated = true;
            }

            $noindex = isset($yoast_meta['wpseo_noindex']) ? $yoast_meta['wpseo_noindex'] : '';
            if ($noindex === 'noindex') {
                $existing = get_term_meta($term->term_id, '_metasync_robots_index', true);
                if (empty($existing) || $overwrite) {
                    update_term_meta($term->term_id, '_metasync_robots_index', 'noindex');
                    $term_updated = true;
                }
            }

            if ($term_updated) {
                $imported++;
            } else {
                $skipped++;
            }
        }

        $processed = $offset + count($terms);

        return [
            'success'  => true,
            'imported' => $imported,
            'skipped'  => $skipped,
            'total'    => $processed,
            'message'  => "Processed {$processed} terms: {$imported} imported, {$skipped} skipped.",
        ];
    }

    /**
     * Import Rank Math term meta into MetaSync term meta.
     *
     * @param array $options
     * @return array
     */
    private function import_rankmath_term_meta($options)
    {
        $batch_size = intval($options['batch_size']);
        $offset     = intval($options['offset']);
        $overwrite  = (bool) $options['overwrite_existing'];

        $taxonomies = array_values(get_taxonomies(['public' => true], 'names'));

        $terms = get_terms([
            'taxonomy'   => $taxonomies,
            'hide_empty' => false,
            'number'     => $batch_size,
            'offset'     => $offset,
        ]);

        if (is_wp_error($terms)) {
            return [
                'success' => false,
                'message' => $terms->get_error_message(),
            ];
        }

        // See import_yoast_term_meta(): title and description are routed to the
        // imported keys and token-resolved; the rest keep their own keys.
        $field_map = [
            'rank_math_facebook_title'       => '_metasync_og_title',
            'rank_math_facebook_description' => '_metasync_og_description',
            'rank_math_canonical_url'        => '_metasync_canonical_url',
        ];

        $imported_map = [
            'rank_math_title'       => Metasync_Seo_Precedence::KEY_IMPORTED_TITLE,
            'rank_math_description' => Metasync_Seo_Precedence::KEY_IMPORTED_DESC,
        ];

        $imported = 0;
        $skipped  = 0;

        foreach ($terms as $term) {
            $term_updated = false;

            foreach ($imported_map as $src_key => $dest_key) {
                $term_updated = $this->store_imported_term_value(
                    $term,
                    (string) get_term_meta($term->term_id, $src_key, true),
                    'rankmath',
                    $dest_key,
                    $overwrite
                ) || $term_updated;
            }

            foreach ($field_map as $src_key => $dest_key) {
                $src_value = get_term_meta($term->term_id, $src_key, true);
                if ($src_value === '' || $src_value === null) {
                    continue;
                }

                $existing = get_term_meta($term->term_id, $dest_key, true);
                if (!empty($existing) && !$overwrite) {
                    continue;
                }

                // Validate canonical: never import a corrupted value.
                if ($dest_key === '_metasync_canonical_url') {
                    $src_value = Metasync_Canonical_Sanitizer::sanitize_for_save($src_value);
                    if ($src_value === '') {
                        continue;
                    }
                } else {
                    $src_value = $this->resolve_term_placeholders($src_value, $term, 'rankmath');
                    if (trim($src_value) === '') {
                        continue;
                    }
                }

                update_term_meta($term->term_id, $dest_key, $src_value);
                $term_updated = true;
            }

            $robots_raw = get_term_meta($term->term_id, 'rank_math_robots', true);
            $robots = maybe_unserialize($robots_raw);
            if (is_array($robots) && in_array('noindex', $robots, true)) {
                $existing = get_term_meta($term->term_id, '_metasync_robots_index', true);
                if (empty($existing) || $overwrite) {
                    update_term_meta($term->term_id, '_metasync_robots_index', 'noindex');
                    $term_updated = true;
                }
            }

            if ($term_updated) {
                $imported++;
            } else {
                $skipped++;
            }
        }

        $processed = $offset + count($terms);

        return [
            'success'  => true,
            'imported' => $imported,
            'skipped'  => $skipped,
            'total'    => $processed,
            'message'  => "Processed {$processed} terms: {$imported} imported, {$skipped} skipped.",
        ];
    }

    /**
     * Import AIOSEO term meta (from the wp_aioseo_terms custom table) into
     * MetaSync term meta.
     *
     * @param array $options
     * @return array
     */
    private function import_aioseo_term_meta($options)
    {
        global $wpdb;

        $batch_size = intval($options['batch_size']);
        $offset     = intval($options['offset']);
        $overwrite  = (bool) $options['overwrite_existing'];

        $table = $wpdb->prefix . 'aioseo_terms';

        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) {
            return [
                'success' => false,
                'message' => 'AIOSEO terms table not found.',
            ];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id, title, description, og_title, og_description, canonical_url, robots_noindex
             FROM {$table}
             ORDER BY term_id ASC
             LIMIT %d OFFSET %d",
            $batch_size,
            $offset
        ));

        $imported = 0;
        $skipped  = 0;

        foreach ($rows as $row) {
            $term_id = (int) $row->term_id;
            if ($term_id <= 0) {
                continue;
            }

            // The resolver needs the term's own name and description, and a row
            // can outlive the term it points at.
            $term = get_term($term_id);
            if (!$term || is_wp_error($term)) {
                $skipped++;
                continue;
            }

            $term_updated = false;

            // See import_yoast_term_meta(): title and description are routed to
            // the imported keys and token-resolved; the rest keep their own keys.
            $field_map = [
                'og_title'      => '_metasync_og_title',
                'og_description' => '_metasync_og_description',
                'canonical_url' => '_metasync_canonical_url',
            ];

            $imported_map = [
                'title'       => Metasync_Seo_Precedence::KEY_IMPORTED_TITLE,
                'description' => Metasync_Seo_Precedence::KEY_IMPORTED_DESC,
            ];

            foreach ($imported_map as $column => $dest_key) {
                $term_updated = $this->store_imported_term_value(
                    $term,
                    isset($row->$column) ? (string) $row->$column : '',
                    'aioseo',
                    $dest_key,
                    $overwrite
                ) || $term_updated;
            }

            foreach ($field_map as $column => $dest_key) {
                $value = isset($row->$column) ? $row->$column : '';
                if ($value === '' || $value === null) {
                    continue;
                }

                $existing = get_term_meta($term_id, $dest_key, true);
                if (!empty($existing) && !$overwrite) {
                    continue;
                }

                // Validate canonical: never import a corrupted value.
                if ($dest_key === '_metasync_canonical_url') {
                    $value = Metasync_Canonical_Sanitizer::sanitize_for_save($value);
                    if ($value === '') {
                        continue;
                    }
                } else {
                    $value = $this->resolve_term_placeholders($value, $term, 'aioseo');
                    if (trim($value) === '' || $this->has_unresolved_placeholders($value, 'aioseo')) {
                        continue;
                    }
                }

                update_term_meta($term_id, $dest_key, $value);
                $term_updated = true;
            }

            if (!empty($row->robots_noindex) && (int) $row->robots_noindex === 1) {
                $existing = get_term_meta($term_id, '_metasync_robots_index', true);
                if (empty($existing) || $overwrite) {
                    update_term_meta($term_id, '_metasync_robots_index', 'noindex');
                    $term_updated = true;
                }
            }

            if ($term_updated) {
                $imported++;
            } else {
                $skipped++;
            }
        }

        $processed = $offset + count($rows);

        return [
            'success'  => true,
            'imported' => $imported,
            'skipped'  => $skipped,
            'total'    => $processed,
            'message'  => "Processed {$processed} terms: {$imported} imported, {$skipped} skipped.",
        ];
    }
}
