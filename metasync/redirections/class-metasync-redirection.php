<?php

/**
 * The Urls Redirection functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/redirections
 * @author     Engineering Team <support@searchatlas.com>
 */

// Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Redirection
{

    private $db_redirection;
    private $common;
    private $importer;
    public function __construct(&$db_redirection)
    {
        $this->db_redirection = $db_redirection;
        $this->common = new Metasync_Common();
        
        # Load importer class
        require_once dirname(__FILE__) . '/class-metasync-redirection-importer.php';
        $this->importer = new Metasync_Redirection_Importer($db_redirection);
    }

    function contains($haystack, $needle, $caseSensitive = false)
    {
        return $caseSensitive ?
            (strpos($haystack, $needle) === FALSE ? FALSE : TRUE) : (stripos($haystack, $needle) === FALSE ? FALSE : TRUE);
    }

    public function create_admin_redirection_interface()
    {
        # Check if we should show import interface
        $request_data = sanitize_post($_REQUEST);
        if (isset($request_data['action']) && $request_data['action'] === 'import') {
            $this->show_import_interface();
            return;
        }

        if (!class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }
        require dirname(__FILE__, 2) . '/redirections/class-metasync-redirection-list-table.php';

        $MetasyncRedirection = new Metasync_Redirection_List_Table();

        $MetasyncRedirection->setDatabaseResource($this->db_redirection);

        $MetasyncRedirection->prepare_items();

        // Include the view markup.
        include dirname(__FILE__, 2) . '/views/metasync-redirection.php';
    }

    /**
     * Show import interface
     */
    public function show_import_interface()
    {
        $importer = $this->importer;
        include dirname(__FILE__, 2) . '/views/metasync-import-redirections.php';
    }

    /**
     * Handle AJAX import request
     */
    public function handle_import_ajax()
    {
        # Verify nonce
        check_ajax_referer('metasync_import_redirections', 'nonce');

        # Check user capabilities
        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
            return;
        }

        $plugin = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';

        if (empty($plugin)) {
            wp_send_json_error(['message' => 'No plugin specified.']);
            return;
        }

        try {
            # Perform import
            $result = $this->importer->import_from_plugin($plugin);

            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => 'Import failed. Please try again or contact support.',
                'imported' => 0,
                'skipped' => 0
            ]);
        }
    }

    public function get_current_page_url()
    {
        $server_data =  sanitize_post($_SERVER);
        $link = '://' . $server_data['HTTP_HOST'] . $server_data['REQUEST_URI'];
        $link = (is_ssl() ? 'https' : 'http') . $link;
        return sanitize_url($link);
    }

    public function source_url_redirection(object $row, string $uri)
    {
        // Optimize: unserialize only once
        $sources_from = unserialize($row->sources_from);
        $source_urls = is_array($sources_from) ? $sources_from : [];
        $global_pattern_type = isset($row->pattern_type) ? $row->pattern_type : null;
        $regex_pattern = isset($row->regex_pattern) ? $row->regex_pattern : null;

        foreach ($source_urls as $source_key => $source_value) {
            $match_found = false;
            $captured_path = ''; // Store captured path for wildcard replacement

            // Determine pattern type: use source value if it's a valid pattern, otherwise use global pattern_type
            $pattern_type = in_array($source_value, ['exact', 'contain', 'start', 'end', 'wildcard', 'regex'])
                ? $source_value
                : ($global_pattern_type ? $global_pattern_type : 'exact');

            // Normalize both URI and source for comparison
            $normalized_uri = $uri;
            $normalized_source = $source_key;

            // If source is a full URL, extract just the path part
            if (strpos($source_key, 'http') === 0) {
                $parsed_url = parse_url($source_key);
                $normalized_source = $parsed_url['path'] ?? '';
            }

            # Ensure both have leading slashes for proper comparison
            # This handles cases where database stores URLs with or without leading slash
            if (!empty($normalized_source) && $normalized_source[0] !== '/') {
                $normalized_source = '/' . $normalized_source;
            }
            if (!empty($normalized_uri) && $normalized_uri[0] !== '/') {
                $normalized_uri = '/' . $normalized_uri;
            }

            // Keep full URI path for matching (don't strip leading slash)
            // This allows matching against full URLs like /wordpress/index.php/category/article/*

            // Handle regex patterns
            if ($pattern_type === 'regex' && $regex_pattern) {
                // Validate regex pattern before using it
                if (!$this->validate_regex_pattern($regex_pattern)) {
                    // Skip invalid regex patterns to prevent errors
                    continue;
                }

                # Normalize pattern (add delimiters if missing)
                $normalized_pattern = $this->normalize_regex_pattern($regex_pattern);
                

                $matches = [];
                // Suppress warnings for invalid regex and check result
                # $result = @preg_match($regex_pattern, $normalized_uri, $matches);
                $result = @preg_match($normalized_pattern, $normalized_uri, $matches);

                if ($result === 1) {
                    $match_found = true;
                    // Store captured groups for replacement
                    if (isset($matches[1])) {
                        $captured_path = $matches[1];
                    }
                }
                // If $result === false, regex is invalid - skip silently
            } else {
                // Check if source has wildcard
                $has_wildcard = strpos($normalized_source, '*') !== false;

                if ($has_wildcard) {
                    // Handle wildcard pattern
                    $match_result = $this->match_wildcard($normalized_source, $normalized_uri);
                    if ($match_result !== false) {
                        $match_found = true;
                        $captured_path = $match_result;
                    }
                } else {
                    // Handle legacy pattern matching (non-wildcard)
                    switch ($source_value) {
                        case 'exact':
                            if ($normalized_source === $normalized_uri) {
                                $match_found = true;
                            }
                            break;
                        case 'contain':
                            if ($this->contains($normalized_uri, $normalized_source)) {
                                $match_found = true;
                            }
                            break;
                        case 'start':
                            if (str_starts_with($normalized_uri, $normalized_source)) {
                                $match_found = true;
                            }
                            break;
                        case 'end':
                            if (str_ends_with($normalized_uri, $normalized_source)) {
                                $match_found = true;
                            }
                            break;
                        default:
                            // Handle new pattern_type field
                            switch ($pattern_type) {
                                case 'exact':
                                    if ($normalized_source === $normalized_uri) {
                                        $match_found = true;
                                    }
                                    break;
                                case 'contain':
                                    if ($this->contains($normalized_uri, $normalized_source)) {
                                        $match_found = true;
                                    }
                                    break;
                                case 'start':
                                    if (str_starts_with($normalized_uri, $normalized_source)) {
                                        $match_found = true;
                                    }
                                    break;
                                case 'end':
                                    if (str_ends_with($normalized_uri, $normalized_source)) {
                                        $match_found = true;
                                    }
                                    break;
                                case 'wildcard':
                                    $match_result = $this->match_wildcard($normalized_source, $normalized_uri);
                                    if ($match_result !== false) {
                                        $match_found = true;
                                        $captured_path = $match_result;
                                    }
                                    break;
                            }
                            break;
                    }
                }
            }

            if ($match_found) {
                $this->db_redirection->update_counter($row);

                if ($row->http_code === '410') {
                    status_header(410);
                    die;
                }
                if ($row->http_code === '451') {
                    status_header(451, 'Unavailable For Legal Reasons');
                    die;
                }
                if ($row->url_redirect_to) {
                    // Replace wildcards or $1 placeholders in destination URL
                    $destination = $this->process_destination_url($row->url_redirect_to, $captured_path);
                    wp_redirect($destination, $row->http_code);
                    die;
                }
                // Match found and processed, return true to stop checking other rules
                return true;
            }
        }

        // No match found
        return false;
    }

    /**
     * Match wildcard pattern against URI
     *
     * @param string $pattern Pattern with * wildcard 
     * @param string $uri URI to match against
     * @return string|false Returns captured path on match, false otherwise
     */
    private function match_wildcard($pattern, $uri)
    {
        // Escape special regex characters except *
        $pattern = str_replace(['\\', '/', '.', '+', '?', '[', '^', ']', '$', '(', ')', '{', '}', '=', '!', '<', '>', '|', ':', '-'],
                              ['\\\\', '\\/', '\\.', '\\+', '\\?', '\\[', '\\^', '\\]', '\\$', '\\(', '\\)', '\\{', '\\}', '\\=', '\\!', '\\<', '\\>', '\\|', '\\:', '\\-'],
                              $pattern);

        // Replace * with capturing group
        $pattern = str_replace('*', '(.*)', $pattern);

        // Create regex pattern
        $regex = '/^' . $pattern . '$/';

        $matches = [];
        if (preg_match($regex, $uri, $matches)) {
            // Return the captured path (first capturing group)
            return isset($matches[1]) ? $matches[1] : '';
        }

        return false;
    }

    /**
     * Process destination URL with captured path
     *
     * @param string $destination Destination URL (may contain * or $1)
     * @param string $captured_path Captured path from source
     * @return string Processed destination URL
     */
    private function process_destination_url($destination, $captured_path)
    {
        // Replace * wildcard with captured path
        if (strpos($destination, '*') !== false) {
            $destination = str_replace('*', $captured_path ?? '', $destination);
        }

        // Replace $1 placeholder with captured path (for regex compatibility)
        if (strpos($destination, '$1') !== false) {
            $destination = str_replace('$1', $captured_path ?? '', $destination);
        }

        return $destination;
    }

    /**
     * Handle template redirect for frontend redirections
     */
    public function handle_template_redirect()
    {
        // Only process on frontend
        if (is_admin()) {
            return;
        }

        // Get current URI
        $uri = $_SERVER['REQUEST_URI'];

        // Remove query string for matching
        $uri = strtok($uri, '?');

        // Get all active redirections (cached for 1 hour)
        $redirections = $this->db_redirection->getAllActiveRecords();

        // Early exit if no redirections configured
        if (empty($redirections)) {
            return;
        }

        // Process each redirection until a match is found
        foreach ($redirections as $redirection) {
            // Stop processing once a match is found and redirect is executed
            if ($this->source_url_redirection($redirection, $uri)) {
                break; // Early exit - no need to check remaining rules
            }
        }
    }

    /**
     * Prevent WordPress from redirecting to draft posts
     * This stops WordPress from auto-redirecting URLs to ?p=POST_ID for draft posts
     * 
     * @param string $redirect_url The redirect URL
     * @param string $requested_url The requested URL
     * @return string|false The redirect URL or false to cancel redirect
     */
    public function prevent_draft_post_redirects($redirect_url, $requested_url)
    {
        # If no redirect is happening, return as-is
        if (empty($redirect_url)) {
            return $redirect_url;
        }

        # Check if WordPress is trying to redirect to a ?p= or ?page_id= URL
        if (strpos($redirect_url, '?p=') !== false || strpos($redirect_url, '?page_id=') !== false) {
            # Extract the post ID
            $post_id = null;
            if (preg_match('/[?&]p=(\d+)/', $redirect_url, $matches)) {
                $post_id = intval($matches[1]);
            } elseif (preg_match('/[?&]page_id=(\d+)/', $redirect_url, $matches)) {
                $post_id = intval($matches[1]);
            }

            # If we found a post ID, check if it's a draft
            if ($post_id) {
                $post = get_post($post_id);
                
                # If post is draft, auto-draft, pending, or private, prevent the redirect
                if ($post && in_array($post->post_status, ['draft', 'auto-draft', 'pending', 'private'])) {
                    # Return false to cancel the redirect and show 404 instead
                    return false;
                }
            }
        }

        # Allow the redirect for published posts
        return $redirect_url;
    }

    /**
     * Prevent wp_old_slug_redirect from redirecting to draft posts
     * 
     * This uses WordPress's built-in 'old_slug_redirect_post_id' filter to selectively
     * block redirects ONLY to unpublished posts, while allowing redirects to published posts.
     * - It doesn't break existing WordPress functionality
     * - Published posts can still use old slug redirects (good for SEO)
     * - Only protects unpublished content from exposure
     * - Non-invasive and backwards compatible
     * 
     * @param int $post_id The post ID that WordPress wants to redirect to
     * @return int|false The post ID to redirect to, or false to prevent redirect
     */
    public function prevent_old_slug_redirect_to_drafts($post_id)
    {
        # If no post ID provided, don't redirect
        if (empty($post_id)) {
            return false;
        }

        # Get the post
        $post = get_post($post_id);
        
        # If post doesn't exist, don't redirect
        if (!$post) {
            return false;
        }

        // Check if this post is unpublished (draft, pending, private, auto-draft)
        if (in_array($post->post_status, ['draft', 'auto-draft', 'pending', 'private'])) {
            # Return false to prevent the redirect to unpublished content
            # This will cause WordPress to show 404 instead, protecting draft content
            return false;
        }

        # Allow redirect for published posts (preserves normal WordPress functionality)
        return $post_id;
    }

    /**
    * Normalize regex pattern by adding delimiters if missing
    * 
    * @param string $pattern The regex pattern
    * @return string Pattern with delimiters
    */
    private function normalize_regex_pattern($pattern)
    {
        if (empty($pattern)) {
            return $pattern;
        }
        
        // Check if pattern starts with a common delimiter
        $common_delimiters = ['/', '#', '~', '%', '@'];
        $starts_with_delimiter = in_array($pattern[0], $common_delimiters);
        
        if ($starts_with_delimiter) {
            // Pattern starts with delimiter, check if it has proper structure
            $first_char = $pattern[0];
            $last_delimiter_pos = strrpos($pattern, $first_char);
            
            // If there's a closing delimiter at a different position, pattern likely has delimiters
            if ($last_delimiter_pos !== false && $last_delimiter_pos > 0) {
                // Check if what comes after the last delimiter are valid modifiers
                $after_last_delimiter = substr($pattern, $last_delimiter_pos + 1);
                // Valid modifiers: i, m, s, x, A, D, S, U, X, J, u
                if (empty($after_last_delimiter) || preg_match('/^[imsxADSUXJu]*$/', $after_last_delimiter)) {
                    // Pattern appears to have proper delimiters, return as-is
                    return $pattern;
                }
            }
            // Pattern starts with delimiter but is malformed - treat as if no delimiters
            // We'll wrap it with a different delimiter
        }
        
        // Pattern doesn't have delimiters or is malformed, add them
        // Choose delimiter that's not in the pattern
        $delimiters = ['/', '#', '~', '%', '@'];
        $delimiter = '/';
        
        foreach ($delimiters as $test_delimiter) {
            if (strpos($pattern, $test_delimiter) === false) {
                $delimiter = $test_delimiter;
                break;
            }
        }
        
        return $delimiter . $pattern . $delimiter;
    }

    /**
     * Validate regex pattern
     */
    public function validate_regex_pattern($pattern)
    {
        if (empty($pattern)) {
            return true; // Empty pattern is valid (not required)
        }
        
        // Normalize pattern (add delimiters if missing, fix malformed patterns)
        $normalized_pattern = $this->normalize_regex_pattern($pattern);

        // Test if the regex pattern is valid
       # $test_result = @preg_match($pattern, '');
       # return $test_result !== false;

        // Use error handler to catch warnings from malformed patterns
        $error_occurred = false;
        set_error_handler(function() use (&$error_occurred) {
            $error_occurred = true;
            return true; // Suppress the error
        }, E_WARNING);
        
        $test_result = preg_match($normalized_pattern, '');
        
        restore_error_handler();
        
        // Return false if preg_match failed or if an error occurred
        return $test_result !== false && !$error_occurred;
    }

    /**
     * Sanitize URL for redirection
     */
    public function sanitize_redirect_url($url)
    {
        // Remove any dangerous protocols
        $url = str_replace(['javascript:', 'data:', 'vbscript:'], '', $url);
        
        // Ensure it's a valid URL
        if (!filter_var($url, FILTER_VALIDATE_URL) && !str_starts_with($url, '/')) {
            // If it's not a full URL and doesn't start with /, assume it's a relative path
            $url = '/' . ltrim($url, '/');
        }
        
        return esc_url_raw($url);
    }
}
