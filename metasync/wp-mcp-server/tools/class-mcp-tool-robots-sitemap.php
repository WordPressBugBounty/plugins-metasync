<?php
/**
 * MCP Tool: Robots.txt and Sitemap
 *
 * Provides tools for managing robots.txt and sitemap.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get Robots.txt Tool
 */
class MCP_Tool_Get_Robots_Txt extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_robots_txt';
    }

    public function get_description() {
        return 'Get the current robots.txt content';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => (object)[],
            'required' => []
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        // Load MetaSync Robots.txt class
        if (!class_exists('Metasync_Robots_Txt')) {
            require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'robots-txt/class-metasync-robots-txt.php';
        }

        $robots_txt = Metasync_Robots_Txt::get_instance();

        // Read robots.txt using plugin's method
        $content = $robots_txt->read_robots_file();

        if (is_wp_error($content)) {
            throw new Exception($content->get_error_message());
        }

        return $this->success([
            'content' => $content,
            'file_exists' => $robots_txt->file_exists(),
            'file_path' => ABSPATH . 'robots.txt',
            'url' => site_url('robots.txt'),
            'is_writable' => $robots_txt->is_writable()
        ]);
    }
}

/**
 * Update Robots.txt Tool
 */
class MCP_Tool_Update_Robots_Txt extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_robots_txt';
    }

    public function get_description() {
        return 'Update the robots.txt file content';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'content' => [
                    'type' => 'string',
                    'description' => 'New robots.txt content'
                ]
            ],
            'required' => ['content']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $content = $params['content']; // Don't sanitize - preserve exact formatting

        // Load MetaSync Robots.txt class
        if (!class_exists('Metasync_Robots_Txt')) {
            require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'robots-txt/class-metasync-robots-txt.php';
        }

        $robots_txt = Metasync_Robots_Txt::get_instance();

        // Write using plugin's method (automatically creates backup)
        $result = $robots_txt->write_robots_file($content);

        if (is_wp_error($result)) {
            throw new Exception($result->get_error_message());
        }

        return $this->success([
            'file_path' => ABSPATH . 'robots.txt',
            'bytes_written' => strlen($content),
            'url' => site_url('robots.txt'),
            'backup_created' => true
        ], 'Robots.txt updated successfully (backup created)');
    }
}

/**
 * Get Sitemap Status Tool
 */
class MCP_Tool_Get_Sitemap_Status extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_sitemap_status';
    }

    public function get_description() {
        return 'Get MetaSync sitemap status and configuration';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => (object)[],
            'required' => []
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('read');

        // Get MetaSync sitemap info
        $sitemap_files = get_option('metasync_sitemap_files', []);
        $total_urls = get_option('metasync_sitemap_total_urls', 0);
        $last_generated = get_option('metasync_sitemap_last_generated', false);
        $auto_update = get_option('metasync_sitemap_auto_update', false);

        $sitemap_info = [
            'sitemap_index_url' => home_url('/sitemap_index.xml'),
            'sitemap_files' => $sitemap_files,
            'total_urls' => $total_urls,
            'last_generated' => $last_generated,
            'auto_update_enabled' => $auto_update,
            'sitemap_exists' => file_exists(ABSPATH . 'sitemap_index.xml')
        ];

        // Check if WordPress XML sitemaps are enabled (WP 5.5+)
        $wp_sitemaps_enabled = function_exists('wp_sitemaps_get_server');
        $wp_sitemap_disabled = get_option('metasync_disable_wp_sitemap', false);

        $sitemap_info['wordpress_core_sitemap_disabled'] = $wp_sitemap_disabled;
        $sitemap_info['wordpress_core_sitemap_enabled'] = $wp_sitemaps_enabled && !$wp_sitemap_disabled;

        if ($wp_sitemaps_enabled && !$wp_sitemap_disabled) {
            // Get post types in sitemap
            $post_types = get_post_types(['public' => true], 'names');
            $sitemap_info['post_types'] = array_values($post_types);

            // Check if sitemaps are disabled
            $sitemap_info['disabled'] = (bool) get_option('blog_public') === false;
        }

        // Check for common sitemap plugins
        $sitemap_plugins = [
            'yoast' => defined('WPSEO_VERSION'),
            'rank_math' => defined('RANK_MATH_VERSION'),
            'all_in_one_seo' => defined('AIOSEO_VERSION')
        ];

        $sitemap_info['plugins'] = $sitemap_plugins;
        $sitemap_info['has_sitemap_plugin'] = in_array(true, $sitemap_plugins, true);

        return $this->success($sitemap_info);
    }
}

/**
 * Regenerate Sitemap Tool
 */
class MCP_Tool_Regenerate_Sitemap extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_regenerate_sitemap';
    }

    public function get_description() {
        return 'Regenerate MetaSync XML sitemap (generates sitemap_index.xml and individual sitemap files)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => (object)[],
            'required' => []
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        // Load MetaSync sitemap generator
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'sitemap/class-metasync-sitemap-generator.php';

        if (!class_exists('Metasync_Sitemap_Generator')) {
            throw new Exception('MetaSync sitemap generator is not available');
        }

        $sitemap_generator = new Metasync_Sitemap_Generator();

        // Generate the sitemap
        $result = $sitemap_generator->generate_sitemap();

        if (is_wp_error($result)) {
            throw new Exception($result->get_error_message());
        }

        // Get sitemap info
        $sitemap_url = home_url('/sitemap_index.xml');
        $last_generated = $sitemap_generator->get_last_generated_time();

        return $this->success([
            'regenerated' => true,
            'sitemap_url' => $sitemap_url,
            'sitemap_index' => $sitemap_url,
            'last_generated' => $last_generated,
            'message' => 'MetaSync sitemap generated successfully'
        ], 'Sitemap regenerated successfully');
    }
}

/**
 * Exclude from Sitemap Tool
 */
class MCP_Tool_Exclude_From_Sitemap extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_exclude_from_sitemap';
    }

    public function get_description() {
        return 'Exclude a post or page from the sitemap by setting noindex';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'Post or page ID to exclude',
                    'minimum' => 1
                ],
                'exclude' => [
                    'type' => 'boolean',
                    'description' => 'True to exclude, false to include',
                    'default' => true
                ]
            ],
            'required' => ['post_id']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_posts');

        $post_id = $this->sanitize_integer($params['post_id']);
        $exclude = isset($params['exclude']) ? (bool)$params['exclude'] : true;

        // Verify post exists
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(sprintf("Post not found: %d", absint($post_id)));
        }

        // Set robots meta (noindex excludes from sitemap)
        $robots_value = $exclude ? 'noindex' : '';
        update_post_meta($post_id, '_metasync_robots_index', $robots_value);

        return $this->success([
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'excluded_from_sitemap' => $exclude,
            'robots_setting' => $robots_value ?: 'index'
        ], $exclude ? 'Post excluded from sitemap' : 'Post included in sitemap');
    }
}

/**
 * Add Robots Rule Tool
 */
class MCP_Tool_Add_Robots_Rule extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_add_robots_rule';
    }

    public function get_description() {
        return 'Add a specific rule to robots.txt';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'rule' => [
                    'type' => 'string',
                    'description' => 'The robots.txt rule to add (e.g., "Disallow: /admin/")',
                ],
                'user_agent' => [
                    'type' => 'string',
                    'description' => 'User-agent for the rule (default: *)',
                ],
            ],
            'required' => ['rule']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $rule = trim($params['rule']);
        $user_agent = isset($params['user_agent']) ? trim($params['user_agent']) : '*';

        // Get current content
        $robots_file = ABSPATH . 'robots.txt';
        $content = file_exists($robots_file) ? file_get_contents($robots_file) : '';

        // Parse content to find or create user-agent block
        $lines = explode("\n", $content);
        $new_lines = [];
        $found_user_agent = false;
        $added = false;

        foreach ($lines as $line) {
            $new_lines[] = $line;

            // Check if we found the matching user-agent
            if (stripos($line, "User-agent: $user_agent") !== false) {
                $found_user_agent = true;
            }

            // If we found the user-agent and hit a blank line or another user-agent, add rule before
            if ($found_user_agent && !$added) {
                if (trim($line) === '' || (stripos($line, 'User-agent:') !== false && $line !== "User-agent: $user_agent")) {
                    array_pop($new_lines); // Remove the blank/user-agent line temporarily
                    $new_lines[] = $rule;
                    $new_lines[] = $line; // Add it back
                    $added = true;
                    $found_user_agent = false;
                }
            }
        }

        // If user-agent not found, add new block
        if (!$added) {
            if (!empty($content) && substr($content, -1) !== "\n") {
                $new_lines[] = '';
            }
            $new_lines[] = "User-agent: $user_agent";
            $new_lines[] = $rule;
        }

        $new_content = implode("\n", $new_lines);

        // Write to file
        $result = file_put_contents($robots_file, $new_content);

        if ($result === false) {
            throw new Exception('Failed to write robots.txt file');
        }

        return $this->success([
            'rule' => $rule,
            'user_agent' => $user_agent,
            'robots_txt_length' => strlen($new_content),
            'message' => 'Rule added to robots.txt successfully',
        ]);
    }
}

/**
 * Remove Robots Rule Tool
 */
class MCP_Tool_Remove_Robots_Rule extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_remove_robots_rule';
    }

    public function get_description() {
        return 'Remove a specific rule from robots.txt';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'rule' => [
                    'type' => 'string',
                    'description' => 'The exact rule to remove (e.g., "Disallow: /admin/")',
                ],
            ],
            'required' => ['rule']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $rule = trim($params['rule']);

        // Get current content
        $robots_file = ABSPATH . 'robots.txt';

        if (!file_exists($robots_file)) {
            throw new Exception('robots.txt file does not exist');
        }

        $content = file_get_contents($robots_file);
        $lines = explode("\n", $content);
        $new_lines = [];
        $removed = false;

        foreach ($lines as $line) {
            if (trim($line) === trim($rule)) {
                $removed = true;
                continue; // Skip this line
            }
            $new_lines[] = $line;
        }

        if (!$removed) {
            throw new Exception('Rule not found in robots.txt');
        }

        $new_content = implode("\n", $new_lines);

        // Write to file
        $result = file_put_contents($robots_file, $new_content);

        if ($result === false) {
            throw new Exception('Failed to write robots.txt file');
        }

        return $this->success([
            'rule' => $rule,
            'robots_txt_length' => strlen($new_content),
            'message' => 'Rule removed from robots.txt successfully',
        ]);
    }
}

/**
 * Parse Robots.txt Tool
 */
class MCP_Tool_Parse_Robots_Txt extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_parse_robots_txt';
    }

    public function get_description() {
        return 'Parse robots.txt into structured format';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => (object)[],
            'required' => []
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        // Get current content
        $robots_file = ABSPATH . 'robots.txt';
        $exists = file_exists($robots_file);

        if (!$exists) {
            return $this->success([
                'exists' => false,
                'parsed' => [],
                'message' => 'robots.txt file does not exist',
            ]);
        }

        $content = file_get_contents($robots_file);
        $lines = explode("\n", $content);

        $parsed = [];
        $current_agent = null;

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if (empty($line) || $line[0] === '#') {
                continue;
            }

            // Check for User-agent
            if (stripos($line, 'User-agent:') === 0) {
                $agent = trim(substr($line, 11));
                $current_agent = $agent;
                if (!isset($parsed[$agent])) {
                    $parsed[$agent] = [
                        'allow' => [],
                        'disallow' => [],
                        'sitemap' => [],
                        'other' => [],
                    ];
                }
                continue;
            }

            // If no user-agent yet, treat as global
            if ($current_agent === null) {
                $current_agent = 'global';
                $parsed[$current_agent] = [
                    'allow' => [],
                    'disallow' => [],
                    'sitemap' => [],
                    'other' => [],
                ];
            }

            // Parse directives
            if (stripos($line, 'Allow:') === 0) {
                $parsed[$current_agent]['allow'][] = trim(substr($line, 6));
            } elseif (stripos($line, 'Disallow:') === 0) {
                $parsed[$current_agent]['disallow'][] = trim(substr($line, 9));
            } elseif (stripos($line, 'Sitemap:') === 0) {
                $parsed[$current_agent]['sitemap'][] = trim(substr($line, 8));
            } else {
                $parsed[$current_agent]['other'][] = $line;
            }
        }

        return $this->success([
            'exists' => true,
            'raw_content' => $content,
            'parsed' => $parsed,
            'user_agents' => array_keys($parsed),
        ]);
    }
}

/**
 * Validate Robots.txt Tool
 */
class MCP_Tool_Validate_Robots_Txt extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_validate_robots_txt';
    }

    public function get_description() {
        return 'Validate robots.txt syntax and check for common issues';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'content' => [
                    'type' => 'string',
                    'description' => 'robots.txt content to validate (optional, validates existing file if not provided)',
                ],
            ],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        // Get content to validate
        if (isset($params['content'])) {
            $content = $params['content'];
        } else {
            $robots_file = ABSPATH . 'robots.txt';
            if (!file_exists($robots_file)) {
                return $this->success([
                    'valid' => true,
                    'errors' => [],
                    'warnings' => [],
                    'message' => 'robots.txt file does not exist',
                ]);
            }
            $content = file_get_contents($robots_file);
        }

        $lines = explode("\n", $content);
        $errors = [];
        $warnings = [];
        $has_user_agent = false;

        foreach ($lines as $line_num => $line) {
            $line = trim($line);
            $line_number = $line_num + 1;

            // Skip empty lines and comments
            if (empty($line) || $line[0] === '#') {
                continue;
            }

            // Check for User-agent
            if (stripos($line, 'User-agent:') === 0) {
                $has_user_agent = true;
                $agent = trim(substr($line, 11));
                if (empty($agent)) {
                    $errors[] = "Line {$line_number}: User-agent cannot be empty";
                }
                continue;
            }

            // Check for valid directives
            $valid_directives = ['Allow:', 'Disallow:', 'Sitemap:', 'Crawl-delay:'];
            $is_valid = false;
            foreach ($valid_directives as $directive) {
                if (stripos($line, $directive) === 0) {
                    $is_valid = true;
                    break;
                }
            }

            if (!$is_valid) {
                $warnings[] = "Line {$line_number}: Unrecognized directive: {$line}";
            }

            // Check if directive appears before User-agent
            if (!$has_user_agent && $is_valid) {
                $warnings[] = "Line {$line_number}: Directive appears before any User-agent declaration";
            }
        }

        // Check for common issues
        if (!$has_user_agent && !empty(array_filter($lines))) {
            $warnings[] = 'No User-agent directive found';
        }

        $is_valid = empty($errors);

        return $this->success([
            'valid' => $is_valid,
            'errors' => $errors,
            'warnings' => $warnings,
            'line_count' => count($lines),
            'message' => $is_valid ? 'robots.txt is valid' : 'robots.txt has validation errors',
        ]);
    }
}
