<?php

/**
 * Robots.txt Management Class
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.5.6
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Metasync_Robots_Txt
{
    /**
     * Instance of this class
     *
     * @var Metasync_Robots_Txt
     */
    private static $instance = null;

    /**
     * Database instance
     *
     * @var Metasync_Robots_Txt_Database
     */
    private $database;

    /**
     * Robots.txt file path
     *
     * @var string
     */
    private $robots_file_path;

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->robots_file_path = ABSPATH . 'robots.txt';

        // Load database class
        require_once plugin_dir_path(__FILE__) . 'class-metasync-robots-txt-database.php';
        $this->database = Metasync_Robots_Txt_Database::get_instance();

        // Register hook to serve virtual robots.txt
        add_filter('robots_txt', array($this, 'serve_virtual_robots_txt'), 10, 2);
    }

    /**
     * Serve virtual robots.txt content via WordPress filter
     *
     * @param string $output The robots.txt output
     * @param bool $public Whether the site is public
     * @return string The robots.txt content
     */
    public function serve_virtual_robots_txt($output, $public)
    {
        // Only serve virtual content if physical file doesn't exist
        if (!file_exists($this->robots_file_path)) {
            // Check if we have virtual content
            if ($this->database->is_virtual_mode()) {
                $virtual_content = $this->database->get_virtual_content();
                if (false !== $virtual_content) {
                    return $virtual_content;
                }
            }
        }

        // Return default output (WordPress will handle it)
        return $output;
    }

    /**
     * Read robots.txt file
     *
     * @return string|WP_Error File contents or error
     */
    public function read_robots_file()
    {
        // Check virtual content first if virtual mode is active
        if ($this->database->is_virtual_mode()) {
            $virtual_content = $this->database->get_virtual_content();
            if (false !== $virtual_content) {
                return $virtual_content;
            }
        }

        // Check if physical file exists
        if (!file_exists($this->robots_file_path)) {
            // Check if we have virtual content as fallback
            $virtual_content = $this->database->get_virtual_content();
            if (false !== $virtual_content) {
                return $virtual_content;
            }
            // Return default robots.txt content
            return $this->get_default_robots_content();
        }

        // Try WP_Filesystem first
        if (function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
            global $wp_filesystem;

            if ($wp_filesystem && $wp_filesystem->exists($this->robots_file_path)) {
                $content = $wp_filesystem->get_contents($this->robots_file_path);
                if (false !== $content) {
                    return $content;
                }
            }
        }

        // Fallback to native PHP file operations
        $content = @file_get_contents($this->robots_file_path);

        if (false === $content) {
            // Check virtual content as last resort
            $virtual_content = $this->database->get_virtual_content();
            if (false !== $virtual_content) {
                return $virtual_content;
            }
            return new WP_Error('read_error', esc_html__('Could not read robots.txt file.', 'metasync'));
        }

        return $content;
    }

    /**
     * Write robots.txt file
     *
     * @param string $content Content to write
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function write_robots_file($content)
    {
        // Create backup before saving
        $current_content = $this->read_robots_file();
        if (!is_wp_error($current_content) && !empty(trim($current_content))) {
            $this->database->create_backup($current_content);
        }

        // Try WP_Filesystem first
        if (function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
            global $wp_filesystem;

            if ($wp_filesystem) {
                $result = $wp_filesystem->put_contents(
                    $this->robots_file_path,
                    $content,
                    FS_CHMOD_FILE
                );

                if ($result) {
                    // Clear virtual mode if file was successfully written
                    $this->database->clear_virtual_content();
                    return true;
                }
            }
        }

        // Fallback to native PHP file operations
        $result = @file_put_contents($this->robots_file_path, $content);

        if (false !== $result) {
            // Set secure permissions: 0644 (rw-r--r--)
            // Owner can read/write, group and others can only read
            // This is the WordPress standard for files
            @chmod($this->robots_file_path, 0644);
            // Clear virtual mode if file was successfully written
            $this->database->clear_virtual_content();
            return true;
        }

        // File write failed - fallback to virtual file storage
        $virtual_stored = $this->database->store_virtual_content($content);
        if ($virtual_stored) {
            $this->database->set_virtual_mode(true);
            // Return success but indicate it's virtual
            return true;
        }

        return new WP_Error('write_error', esc_html__('Could not write to robots.txt file. Please check file permissions.', 'metasync'));
    }

    /**
     * Validate robots.txt content
     *
     * @param string $content Content to validate
     * @return array Array of validation results
     */
    public function validate_content($content)
    {
        $warnings = array();
        $errors = array();

        // Normalize content for easier checking
        $normalized_content = preg_replace('/\s+/', ' ', strtolower($content));

        // Check for complete site disallow (blocking everything)
        if (preg_match('/user-agent:\s*\*.*?disallow:\s*\/\s/i', $normalized_content)) {
            // Check if it's ONLY blocking / without any other paths
            if (!preg_match('/disallow:\s*\/\w+/i', $content)) {
                $warnings[] = esc_html__('Warning: You are blocking all crawlers from your entire site (Disallow: /). This will prevent search engines from indexing your content.', 'metasync');
            }
        }

        // Check for wp-admin disallow without admin-ajax.php exception
        $has_wp_admin_disallow = preg_match('/Disallow:\s*\/wp-admin\/?$/im', $content);
        $has_admin_ajax_allow = preg_match('/Allow:\s*\/wp-admin\/admin-ajax\.php/i', $content);

        if ($has_wp_admin_disallow && !$has_admin_ajax_allow) {
            $warnings[] = esc_html__('Warning: Blocking /wp-admin/ without allowing /wp-admin/admin-ajax.php may interfere with AJAX functionality on your site.', 'metasync');
        }

        // Check for basic syntax errors
        $lines = explode("\n", $content);
        $user_agent_found = false;

        foreach ($lines as $line_num => $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            // Check for valid directives
            if (!preg_match('/^(User-agent|Disallow|Allow|Sitemap|Crawl-delay):/i', $line)) {
                $errors[] = sprintf(
                    esc_html__('Line %d: Invalid directive. Valid directives are: User-agent, Disallow, Allow, Sitemap, Crawl-delay', 'metasync'),
                    $line_num + 1
                );
            }

            // Track if User-agent is present
            if (preg_match('/^User-agent:/i', $line)) {
                $user_agent_found = true;
            }
        }

        // Check if content has at least one User-agent
        if (!empty(trim($content)) && !$user_agent_found) {
            $errors[] = esc_html__('robots.txt must contain at least one User-agent directive.', 'metasync');
        }

        return array(
            'valid' => empty($errors),
            'warnings' => $warnings,
            'errors' => $errors
        );
    }

    /**
     * Get default robots.txt content
     *
     * @return string Default content
     */
    public function get_default_robots_content()
    {
        $site_url = get_site_url();

        return "User-agent: *\n" .
               "Disallow: /wp-admin/\n" .
               "Allow: /wp-admin/admin-ajax.php\n" .
               "Disallow: /wp-includes/\n\n" .
               "Sitemap: {$site_url}/sitemap_index.xml";
    }

    /**
     * Get backup history
     *
     * @param int $limit Number of backups to retrieve
     * @return array Array of backups
     */
    public function get_backup_history($limit = 10)
    {
        return $this->database->get_backups($limit);
    }

    /**
     * Restore from backup
     *
     * @param int $backup_id Backup ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function restore_backup($backup_id)
    {
        $backup = $this->database->get_backup($backup_id);

        if (!$backup) {
            return new WP_Error('backup_not_found', esc_html__('Backup not found.', 'metasync'));
        }

        return $this->write_robots_file($backup['content']);
    }

    /**
     * Delete a backup
     *
     * @param int $backup_id Backup ID
     * @return bool True on success, false on failure
     */
    public function delete_backup($backup_id)
    {
        return $this->database->delete_backup($backup_id);
    }

    /**
     * Check if robots.txt file exists (physical or virtual)
     *
     * @return bool True if exists (physical or virtual), false otherwise
     */
    public function file_exists()
    {
        // Check physical file first
        if (file_exists($this->robots_file_path)) {
            return true;
        }
        
        // Check virtual content
        if ($this->database->is_virtual_mode()) {
            $virtual_content = $this->database->get_virtual_content();
            if (false !== $virtual_content) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get file permissions
     *
     * @return string|bool File permissions or false
     */
    public function get_file_permissions()
    {
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();
        global $wp_filesystem;

        if (!$wp_filesystem || !$wp_filesystem->exists($this->robots_file_path)) {
            return false;
        }

        return substr(sprintf('%o', fileperms($this->robots_file_path)), -4);
    }

    /**
     * Check if file is writable
     *
     * @return bool True if writable, false otherwise
     */
    public function is_writable()
    {
        // If file exists, check if it's writable
        if (file_exists($this->robots_file_path)) {
            return is_writable($this->robots_file_path);
        }

        // If file doesn't exist, check if parent directory is writable
        // If not writable, virtual mode will be used
        return is_writable(ABSPATH);
    }

    /**
     * Check if virtual mode is active
     *
     * @return bool True if virtual mode is active
     */
    public function is_virtual_mode()
    {
        return $this->database->is_virtual_mode();
    }

    /**
     * Update or add sitemap URL in robots.txt
     *
     * @param string $sitemap_url The sitemap URL to add/update
     * @return array Result with 'success' boolean and 'action' string ('added', 'updated', 'unchanged', 'created', or 'error')
     */
    public function update_sitemap_url($sitemap_url)
    {
        // Check if we can write to robots.txt
        if (!$this->is_writable()) {
            return [
                'success' => false,
                'action' => 'error',
                'message' => esc_html__('Cannot write to robots.txt. Please check file permissions.', 'metasync')
            ];
        }

        // Check if file exists
        $file_exists = $this->file_exists();

        // Read current content
        $current_content = $this->read_robots_file();
        
        if (is_wp_error($current_content)) {
            // If error reading, use default content
            $current_content = $this->get_default_robots_content();
        }

        // Normalize the sitemap URL
        $sitemap_url = esc_url($sitemap_url);
        $sitemap_line = "Sitemap: {$sitemap_url}";

        // Check if there's already a Sitemap line
        $has_sitemap = preg_match('/^Sitemap:\s*.+$/im', $current_content);

        if ($has_sitemap) {
            // Check if the sitemap URL is already correct
            if (preg_match('/^Sitemap:\s*' . preg_quote($sitemap_url, '/') . '\s*$/im', $current_content)) {
                // If file doesn't exist, we still need to create it even if content matches
                if (!$file_exists) {
                    $result = $this->write_robots_file($current_content);
                    if (is_wp_error($result)) {
                        return [
                            'success' => false,
                            'action' => 'error',
                            'message' => $result->get_error_message()
                        ];
                    }
                    return [
                        'success' => true,
                        'action' => 'created',
                        'message' => esc_html__('robots.txt file has been created with sitemap URL.', 'metasync')
                    ];
                }
                return [
                    'success' => true,
                    'action' => 'unchanged',
                    'message' => esc_html__('Sitemap URL already exists in robots.txt.', 'metasync')
                ];
            }

            // Update existing Sitemap line(s) - replace all with the new one
            $new_content = preg_replace('/^Sitemap:\s*.+$/im', $sitemap_line, $current_content);
            
            // Remove duplicate sitemap lines (keep only the first one)
            $lines = explode("\n", $new_content);
            $sitemap_found = false;
            $filtered_lines = [];
            foreach ($lines as $line) {
                if (preg_match('/^Sitemap:/i', trim($line))) {
                    if (!$sitemap_found) {
                        $filtered_lines[] = $sitemap_line;
                        $sitemap_found = true;
                    }
                    // Skip duplicate sitemap lines
                } else {
                    $filtered_lines[] = $line;
                }
            }
            $new_content = implode("\n", $filtered_lines);
            $action = 'updated';
        } else {
            // Add sitemap line at the end
            $new_content = rtrim($current_content) . "\n\n" . $sitemap_line;
            $action = 'added';
        }

        // Write the updated content
        $result = $this->write_robots_file($new_content);

        if (is_wp_error($result)) {
            return [
                'success' => false,
                'action' => 'error',
                'message' => $result->get_error_message()
            ];
        }

        $messages = [
            'added' => esc_html__('Sitemap URL has been added to robots.txt.', 'metasync'),
            'updated' => esc_html__('Sitemap URL has been updated in robots.txt.', 'metasync'),
            'created' => esc_html__('robots.txt file has been created with sitemap URL.', 'metasync'),
        ];

        return [
            'success' => true,
            'action' => $action,
            'message' => $messages[$action]
        ];
    }

    /**
     * Check if robots.txt contains the sitemap URL
     *
     * @param string $sitemap_url The sitemap URL to check for
     * @return bool True if the sitemap URL exists in robots.txt
     */
    public function has_sitemap_url($sitemap_url)
    {
        $content = $this->read_robots_file();
        
        if (is_wp_error($content)) {
            return false;
        }

        return (bool) preg_match('/^Sitemap:\s*' . preg_quote($sitemap_url, '/') . '\s*$/im', $content);
    }

    /**
     * Get the current sitemap URL from robots.txt
     *
     * @return string|false The sitemap URL or false if not found
     */
    public function get_sitemap_url_from_robots()
    {
        $content = $this->read_robots_file();
        
        if (is_wp_error($content)) {
            return false;
        }

        if (preg_match('/^Sitemap:\s*(.+)$/im', $content, $matches)) {
            return trim($matches[1]);
        }

        return false;
    }

    /**
     * Render the admin page
     *
     * @param object $admin Admin class instance
     * @param string $current_content Current robots.txt content
     * @param array $backups Backup history
     * @param bool $file_exists Whether the file exists
     * @param bool $is_writable Whether the file is writable
     */
    public function render($admin, $current_content, $backups, $file_exists, $is_writable)
    {
        // Load the view template
        require_once plugin_dir_path(__FILE__) . 'views/admin-page.php';
    }
}
