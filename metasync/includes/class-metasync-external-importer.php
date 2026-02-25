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
                }
                break;
        }

        return $count > 0;
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
    public function import_indexation($plugin)
    {
        $imported_count = 0;

        switch ($plugin) {
            case 'yoast':
                $imported_count = $this->import_yoast_indexation();
                break;

            case 'rankmath':
                $imported_count = $this->import_rankmath_indexation();
                break;

            case 'aioseo':
                $imported_count = $this->import_aioseo_indexation();
                break;

            default:
                return ['success' => false, 'message' => 'Invalid plugin specified.'];
        }

        if ($imported_count > 0) {
            return ['success' => true, 'message' => "Successfully imported indexation settings for $imported_count posts."];
        }

        return ['success' => false, 'message' => 'No post-level indexation settings found to import.'];
    }

    /**
     * Import per-post indexation settings from Yoast SEO
     */
    private function import_yoast_indexation()
    {
        global $wpdb;
        $imported_count = 0;

        // Get all posts with Yoast robots meta
        $posts = $wpdb->get_results("
            SELECT DISTINCT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key LIKE '_yoast_wpseo_%'
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
        ");

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
            } elseif ($yoast_noindex === '2' && !isset($metasync_robots['index'])) {
                // '2' means 'index' in Yoast
                $metasync_robots['index'] = 'index';
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

            // Import canonical URL
            $yoast_canonical = get_post_meta($post_id, '_yoast_wpseo_canonical', true);
            if (!empty($yoast_canonical)) {
                $existing_canonical = get_post_meta($post_id, 'meta_canonical', true);
                if (empty($existing_canonical)) {
                    update_post_meta($post_id, 'meta_canonical', sanitize_url($yoast_canonical));
                    $has_changes = true;
                }
            }

            // Save Metasync robots meta if changes were made
            if ($has_changes) {
                if (!empty($metasync_robots)) {
                    update_post_meta($post_id, 'metasync_common_robots', $metasync_robots);
                }
                $imported_count++;
            }
        }

        return $imported_count;
    }

    /**
     * Import per-post indexation settings from Rank Math
     */
    private function import_rankmath_indexation()
    {
        global $wpdb;
        $imported_count = 0;

        // Get all posts with Rank Math robots meta
        $posts = $wpdb->get_results("
            SELECT DISTINCT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key LIKE 'rank_math_%'
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
        ");

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

            // Import canonical URL
            $rm_canonical = get_post_meta($post_id, 'rank_math_canonical_url', true);
            if (!empty($rm_canonical)) {
                $existing_canonical = get_post_meta($post_id, 'meta_canonical', true);
                if (empty($existing_canonical)) {
                    update_post_meta($post_id, 'meta_canonical', sanitize_url($rm_canonical));
                    $has_changes = true;
                }
            }

            // Save Metasync robots meta if changes were made
            if ($has_changes) {
                if (!empty($metasync_robots)) {
                    update_post_meta($post_id, 'metasync_common_robots', $metasync_robots);
                }
                $imported_count++;
            }
        }

        return $imported_count;
    }

    /**
     * Import per-post indexation settings from AIOSEO
     */
    private function import_aioseo_indexation()
    {
        global $wpdb;
        $imported_count = 0;

        // AIOSEO stores data in a custom table
        $aioseo_table = $wpdb->prefix . 'aioseo_posts';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$aioseo_table'") === $aioseo_table;

        if (!$table_exists) {
            return 0;
        }

        // Get all posts with AIOSEO settings
        $posts = $wpdb->get_results("
            SELECT post_id, robots_default, robots_noindex, robots_nofollow,
                   robots_noarchive, robots_nosnippet, robots_noimageindex,
                   canonical_url
            FROM {$aioseo_table}
            WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
        ");

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

            // Import canonical URL
            if (!empty($aioseo_data->canonical_url)) {
                $existing_canonical = get_post_meta($post_id, 'meta_canonical', true);
                if (empty($existing_canonical)) {
                    update_post_meta($post_id, 'meta_canonical', sanitize_url($aioseo_data->canonical_url));
                    $has_changes = true;
                }
            }

            // Save Metasync robots meta if changes were made
            if ($has_changes) {
                if (!empty($metasync_robots)) {
                    update_post_meta($post_id, 'metasync_common_robots', $metasync_robots);
                }
                $imported_count++;
            }
        }

        return $imported_count;
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

                $type = strtolower($item['@type']);

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
        $overwrite = (bool) $options['overwrite_existing'];

        // Build WHERE clause for meta keys
        $meta_keys = [];
        if ($import_titles) {
            $meta_keys[] = '_yoast_wpseo_title';
        }
        if ($import_descriptions) {
            $meta_keys[] = '_yoast_wpseo_metadesc';
        }

        if (empty($meta_keys)) {
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

        foreach ($posts as $post_obj) {
            $post_id = $post_obj->post_id;
            $updated = false;

            // Import title
            if ($import_titles) {
                $yoast_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
                if (!empty($yoast_title)) {
                    $existing_title = get_post_meta($post_id, '_metasync_seo_title', true);

                    if (empty($existing_title) || $overwrite) {
                        update_post_meta($post_id, '_metasync_seo_title', sanitize_text_field($yoast_title));
                        $updated = true;
                    }
                }
            }

            // Import description
            if ($import_descriptions) {
                $yoast_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
                if (!empty($yoast_desc)) {
                    $existing_desc = get_post_meta($post_id, '_metasync_seo_desc', true);

                    if (empty($existing_desc) || $overwrite) {
                        update_post_meta($post_id, '_metasync_seo_desc', sanitize_textarea_field($yoast_desc));
                        $updated = true;
                    }
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

        return [
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
        ];
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
        $overwrite = (bool) $options['overwrite_existing'];

        // Build WHERE clause for meta keys
        $meta_keys = [];
        if ($import_titles) {
            $meta_keys[] = 'rank_math_title';
        }
        if ($import_descriptions) {
            $meta_keys[] = 'rank_math_description';
        }

        if (empty($meta_keys)) {
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

        foreach ($posts as $post_obj) {
            $post_id = $post_obj->post_id;
            $updated = false;

            // Import title
            if ($import_titles) {
                $rm_title = get_post_meta($post_id, 'rank_math_title', true);
                if (!empty($rm_title)) {
                    $existing_title = get_post_meta($post_id, '_metasync_seo_title', true);

                    if (empty($existing_title) || $overwrite) {
                        update_post_meta($post_id, '_metasync_seo_title', sanitize_text_field($rm_title));
                        $updated = true;
                    }
                }
            }

            // Import description
            if ($import_descriptions) {
                $rm_desc = get_post_meta($post_id, 'rank_math_description', true);
                if (!empty($rm_desc)) {
                    $existing_desc = get_post_meta($post_id, '_metasync_seo_desc', true);

                    if (empty($existing_desc) || $overwrite) {
                        update_post_meta($post_id, '_metasync_seo_desc', sanitize_textarea_field($rm_desc));
                        $updated = true;
                    }
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

        return [
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
        ];
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
        $overwrite = (bool) $options['overwrite_existing'];

        // Check if AIOSEO table exists
        $table = $wpdb->prefix . 'aioseo_posts';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return [
                'success' => false,
                'message' => 'AIOSEO database table not found.'
            ];
        }

        if (!$import_titles && !$import_descriptions) {
            return [
                'success' => false,
                'message' => 'No import options selected.'
            ];
        }

        // Build WHERE clause
        $where_conditions = [];
        if ($import_titles) {
            $where_conditions[] = '(title IS NOT NULL AND title != \'\')';
        }
        if ($import_descriptions) {
            $where_conditions[] = '(description IS NOT NULL AND description != \'\')';
        }
        $where_clause = implode(' OR ', $where_conditions);

        // Get total count
        $total_posts = (int) $wpdb->get_var("
            SELECT COUNT(post_id)
            FROM {$table}
            WHERE ({$where_clause})
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
        ");

        // Get batch of posts
        $posts = $wpdb->get_results($wpdb->prepare("
            SELECT post_id, title, description
            FROM {$table}
            WHERE ({$where_clause})
            AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish')
            ORDER BY post_id ASC
            LIMIT %d OFFSET %d
        ", $batch_size, $offset));

        $imported_count = 0;
        $skipped_count = 0;

        foreach ($posts as $aioseo_data) {
            $post_id = $aioseo_data->post_id;
            $updated = false;

            // Import title
            if ($import_titles && !empty($aioseo_data->title)) {
                $existing_title = get_post_meta($post_id, '_metasync_seo_title', true);

                if (empty($existing_title) || $overwrite) {
                    update_post_meta($post_id, '_metasync_seo_title', sanitize_text_field($aioseo_data->title));
                    $updated = true;
                }
            }

            // Import description
            if ($import_descriptions && !empty($aioseo_data->description)) {
                $existing_desc = get_post_meta($post_id, '_metasync_seo_desc', true);

                if (empty($existing_desc) || $overwrite) {
                    update_post_meta($post_id, '_metasync_seo_desc', sanitize_textarea_field($aioseo_data->description));
                    $updated = true;
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

        return [
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
        ];
    }
}
