<?php
/**
 * MCP Tools for WordPress Core SEO Settings
 *
 * Provides MCP tools for managing WordPress core settings that affect SEO.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(dirname(__FILE__)) . 'class-mcp-tool-base.php';

/**
 * Get Site Info Tool
 *
 * Gets site title, tagline, description, and URL
 */
class MCP_Tool_Get_Site_Info extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_site_info';
    }

    public function get_description() {
        return 'Get site information (title, tagline, description, URL)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        return $this->success([
            'site_title' => get_bloginfo('name'),
            'site_tagline' => get_bloginfo('description'),
            'site_url' => get_site_url(),
            'home_url' => get_home_url(),
            'admin_email' => get_option('admin_email'),
            'language' => get_locale(),
            'charset' => get_bloginfo('charset'),
            'wordpress_version' => get_bloginfo('version'),
        ]);
    }
}

/**
 * Update Site Info Tool
 *
 * Updates site title and tagline
 */
class MCP_Tool_Update_Site_Info extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_site_info';
    }

    public function get_description() {
        return 'Update site title and tagline';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'site_title' => [
                    'type' => 'string',
                    'description' => 'Site title (optional)',
                ],
                'site_tagline' => [
                    'type' => 'string',
                    'description' => 'Site tagline/description (optional)',
                ],
            ],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $updated = [];

        if (isset($params['site_title'])) {
            update_option('blogname', sanitize_text_field($params['site_title']));
            $updated[] = 'site_title';
        }

        if (isset($params['site_tagline'])) {
            update_option('blogdescription', sanitize_text_field($params['site_tagline']));
            $updated[] = 'site_tagline';
        }

        if (empty($updated)) {
            throw new Exception('No fields to update provided');
        }

        return $this->success([
            'site_title' => get_bloginfo('name'),
            'site_tagline' => get_bloginfo('description'),
            'updated_fields' => $updated,
            'message' => 'Site information updated successfully',
        ]);
    }
}

/**
 * Get Permalink Structure Tool
 *
 * Gets permalink settings
 */
class MCP_Tool_Get_Permalink_Structure extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_permalink_structure';
    }

    public function get_description() {
        return 'Get permalink structure settings';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $permalink_structure = get_option('permalink_structure');

        // Determine permalink type
        $type = 'plain';
        if ($permalink_structure === '/%year%/%monthnum%/%day%/%postname%/') {
            $type = 'day_and_name';
        } elseif ($permalink_structure === '/%year%/%monthnum%/%postname%/') {
            $type = 'month_and_name';
        } elseif ($permalink_structure === '/%postname%/') {
            $type = 'post_name';
        } elseif (!empty($permalink_structure)) {
            $type = 'custom';
        }

        return $this->success([
            'permalink_structure' => $permalink_structure ?: '',
            'type' => $type,
            'category_base' => get_option('category_base') ?: '',
            'tag_base' => get_option('tag_base') ?: '',
        ]);
    }
}

/**
 * Update Permalink Structure Tool
 *
 * Updates permalink settings
 */
class MCP_Tool_Update_Permalink_Structure extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_permalink_structure';
    }

    public function get_description() {
        return 'Update permalink structure (automatically flushes rewrite rules)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'structure' => [
                    'type' => 'string',
                    'description' => 'Permalink structure (e.g., "/%postname%/" or "/%year%/%monthnum%/%postname%/")',
                ],
                'category_base' => [
                    'type' => 'string',
                    'description' => 'Category base (optional)',
                ],
                'tag_base' => [
                    'type' => 'string',
                    'description' => 'Tag base (optional)',
                ],
            ],
            'required' => ['structure'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $structure = sanitize_text_field($params['structure']);

        // Validate structure
        if (!empty($structure) && strpos($structure, '%') === false) {
            throw new Exception('Invalid permalink structure. Must contain at least one tag (e.g., %postname%)');
        }

        // Update permalink structure
        update_option('permalink_structure', $structure);

        // Update category base if provided
        if (isset($params['category_base'])) {
            update_option('category_base', sanitize_title($params['category_base']));
        }

        // Update tag base if provided
        if (isset($params['tag_base'])) {
            update_option('tag_base', sanitize_title($params['tag_base']));
        }

        // Flush rewrite rules
        flush_rewrite_rules();

        return $this->success([
            'permalink_structure' => get_option('permalink_structure'),
            'category_base' => get_option('category_base') ?: '',
            'tag_base' => get_option('tag_base') ?: '',
            'message' => 'Permalink structure updated and rewrite rules flushed',
        ]);
    }
}

/**
 * Get Reading Settings Tool
 *
 * Gets reading settings
 */
class MCP_Tool_Get_Reading_Settings extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_reading_settings';
    }

    public function get_description() {
        return 'Get reading settings (posts per page, front page settings)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $show_on_front = get_option('show_on_front');
        $page_on_front = get_option('page_on_front');
        $page_for_posts = get_option('page_for_posts');

        $result = [
            'posts_per_page' => (int)get_option('posts_per_page'),
            'show_on_front' => $show_on_front, // 'posts' or 'page'
        ];

        if ($show_on_front === 'page') {
            $result['page_on_front'] = (int)$page_on_front;
            $result['page_on_front_title'] = $page_on_front ? get_the_title($page_on_front) : null;
            $result['page_for_posts'] = (int)$page_for_posts;
            $result['page_for_posts_title'] = $page_for_posts ? get_the_title($page_for_posts) : null;
        }

        return $this->success($result);
    }
}

/**
 * Update Reading Settings Tool
 *
 * Updates reading settings
 */
class MCP_Tool_Update_Reading_Settings extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_reading_settings';
    }

    public function get_description() {
        return 'Update reading settings (posts per page, front page)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'posts_per_page' => [
                    'type' => 'integer',
                    'description' => 'Number of posts to show per page (optional)',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
                'show_on_front' => [
                    'type' => 'string',
                    'enum' => ['posts', 'page'],
                    'description' => 'What to show on front page: posts or static page (optional)',
                ],
                'page_on_front' => [
                    'type' => 'integer',
                    'description' => 'Page ID to use as front page (required if show_on_front is "page")',
                ],
                'page_for_posts' => [
                    'type' => 'integer',
                    'description' => 'Page ID to use for blog posts (optional)',
                ],
            ],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $updated = [];

        if (isset($params['posts_per_page'])) {
            $posts_per_page = intval($params['posts_per_page']);
            if ($posts_per_page < 1 || $posts_per_page > 100) {
                throw new Exception('posts_per_page must be between 1 and 100');
            }
            update_option('posts_per_page', $posts_per_page);
            $updated[] = 'posts_per_page';
        }

        if (isset($params['show_on_front'])) {
            $show_on_front = sanitize_text_field($params['show_on_front']);

            if ($show_on_front === 'page' && !isset($params['page_on_front'])) {
                throw new Exception('page_on_front is required when show_on_front is "page"');
            }

            update_option('show_on_front', $show_on_front);
            $updated[] = 'show_on_front';
        }

        if (isset($params['page_on_front'])) {
            $page_id = intval($params['page_on_front']);
            $page = get_post($page_id);
            if (!$page || $page->post_type !== 'page') {
                throw new Exception(sprintf("Invalid page_on_front: %d", absint($page_id)));
            }
            update_option('page_on_front', $page_id);
            $updated[] = 'page_on_front';
        }

        if (isset($params['page_for_posts'])) {
            $page_id = intval($params['page_for_posts']);
            if ($page_id > 0) {
                $page = get_post($page_id);
                if (!$page || $page->post_type !== 'page') {
                    throw new Exception(sprintf("Invalid page_for_posts: %d", absint($page_id)));
                }
            }
            update_option('page_for_posts', $page_id);
            $updated[] = 'page_for_posts';
        }

        if (empty($updated)) {
            throw new Exception('No fields to update provided');
        }

        return $this->success([
            'posts_per_page' => (int)get_option('posts_per_page'),
            'show_on_front' => get_option('show_on_front'),
            'updated_fields' => $updated,
            'message' => 'Reading settings updated successfully',
        ]);
    }
}

/**
 * Get Search Visibility Tool
 *
 * Gets search engine visibility setting
 */
class MCP_Tool_Get_Search_Visibility extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_search_visibility';
    }

    public function get_description() {
        return 'Get search engine visibility setting (blog_public option)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $blog_public = (int)get_option('blog_public');

        return $this->success([
            'blog_public' => $blog_public,
            'visible_to_search_engines' => $blog_public === 1,
            'discourage_search_engines' => $blog_public === 0,
        ]);
    }
}

/**
 * Update Search Visibility Tool
 *
 * Updates search engine visibility setting
 */
class MCP_Tool_Update_Search_Visibility extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_search_visibility';
    }

    public function get_description() {
        return 'Update search engine visibility (requires confirm: true for safety)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'visible_to_search_engines' => [
                    'type' => 'boolean',
                    'description' => 'True to allow search engines, false to discourage',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'description' => 'Must be true to confirm this change',
                ],
            ],
            'required' => ['visible_to_search_engines', 'confirm'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        // Require confirmation
        if (!isset($params['confirm']) || $params['confirm'] !== true) {
            throw new Exception('This action requires confirm: true parameter for safety');
        }

        $visible = (bool)$params['visible_to_search_engines'];
        $blog_public = $visible ? 1 : 0;

        update_option('blog_public', $blog_public);

        return $this->success([
            'blog_public' => $blog_public,
            'visible_to_search_engines' => $visible,
            'message' => $visible
                ? 'Site is now visible to search engines'
                : 'Site is now discouraged from search engine indexing',
        ]);
    }
}

/**
 * Get Date Format Tool
 *
 * Gets date/time formats and timezone
 */
class MCP_Tool_Get_Date_Format extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_date_format';
    }

    public function get_description() {
        return 'Get date/time format settings and timezone';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        return $this->success([
            'date_format' => get_option('date_format'),
            'time_format' => get_option('time_format'),
            'timezone_string' => get_option('timezone_string'),
            'gmt_offset' => get_option('gmt_offset'),
            'week_starts_on' => (int)get_option('start_of_week'), // 0 = Sunday, 1 = Monday, etc.
            'example_date' => date_i18n(get_option('date_format')),
            'example_time' => date_i18n(get_option('time_format')),
        ]);
    }
}

/**
 * Get Discussion Settings Tool
 *
 * Gets comment and discussion settings
 */
class MCP_Tool_Get_Discussion_Settings extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_discussion_settings';
    }

    public function get_description() {
        return 'Get comment and discussion settings';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        return $this->success([
            'default_comment_status' => get_option('default_comment_status'), // 'open' or 'closed'
            'default_ping_status' => get_option('default_ping_status'), // 'open' or 'closed'
            'comment_registration' => (bool)get_option('comment_registration'), // Must be registered to comment
            'comment_moderation' => (bool)get_option('comment_moderation'), // Comments must be manually approved
            'comment_whitelist' => (bool)get_option('comment_whitelist'), // Comment author must have previously approved comment
            'comments_per_page' => (int)get_option('comments_per_page'),
            'thread_comments' => (bool)get_option('thread_comments'), // Enable threaded comments
            'thread_comments_depth' => (int)get_option('thread_comments_depth'),
            'page_comments' => (bool)get_option('page_comments'), // Break comments into pages
            'show_avatars' => (bool)get_option('show_avatars'),
        ]);
    }
}
