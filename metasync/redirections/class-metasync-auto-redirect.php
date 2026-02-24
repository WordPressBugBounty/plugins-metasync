<?php

/**
 * Automatic Redirect on Slug Change
 *
 * Creates 301 redirects automatically when post/page URL slugs are changed.
 * This preserves SEO link equity when content URLs are modified.
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

class Metasync_Auto_Redirect
{
    /**
     * Reference to the redirection database class
     *
     * @var Metasync_Redirection_Database
     */
    private $db_redirection;

    /**
     * Post types to monitor for slug changes
     *
     * @var array
     */
    private $monitored_post_types = array('post', 'page');

    /**
     * Constructor
     *
     * @param Metasync_Redirection_Database $db_redirection Database class reference
     */
    public function __construct($db_redirection)
    {
        $this->db_redirection = $db_redirection;
    }

    /**
     * Initialize hooks
     */
    public function init()
    {
        add_action('post_updated', array($this, 'check_slug_change'), 10, 3);
        add_action('admin_notices', array($this, 'display_auto_redirect_notice'));
    }

    /**
     * Get supported post types
     *
     * @return array
     */
    private function get_supported_post_types()
    {
        return apply_filters('metasync_auto_redirect_post_types', $this->monitored_post_types);
    }

    /**
     * Check if slug changed and create redirect if needed
     *
     * @param int     $post_id     Post ID
     * @param WP_Post $post_after  Post object after update
     * @param WP_Post $post_before Post object before update
     */
    public function check_slug_change($post_id, $post_after, $post_before)
    {
        // Skip autosaves
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Skip revisions
        if (wp_is_post_revision($post_id)) {
            return;
        }

        // Only check supported post types
        if (!in_array($post_after->post_type, $this->get_supported_post_types(), true)) {
            return;
        }

        // Only create redirects for published posts
        if ($post_after->post_status !== 'publish') {
            return;
        }

        // Skip if the post wasn't published before (new publish)
        if ($post_before->post_status !== 'publish') {
            return;
        }

        // Check if the slug actually changed
        if ($post_before->post_name === $post_after->post_name) {
            return;
        }

        // Skip if slug is empty
        if (empty($post_before->post_name) || empty($post_after->post_name)) {
            return;
        }

        // Build the old and new URLs
        $old_url = $this->build_relative_url($post_before);
        $new_url = $this->build_relative_url($post_after);

        // Skip if URLs are the same
        if ($old_url === $new_url) {
            return;
        }

        // Create the redirect
        $this->create_redirect($old_url, $new_url, $post_id);
    }

    /**
     * Build relative URL for a post
     *
     * @param WP_Post $post Post object
     * @return string Relative URL path
     */
    private function build_relative_url($post)
    {
        $permalink = get_permalink($post);
        $parsed = wp_parse_url($permalink);
        $path = isset($parsed['path']) ? $parsed['path'] : '/';
        return $path;
    }

    /**
     * Create a 301 redirect from old URL to new URL
     *
     * @param string $old_url Old URL path
     * @param string $new_url New URL path
     * @param int    $post_id Post ID (for reference)
     * @return bool Success or failure
     */
    private function create_redirect($old_url, $new_url, $post_id)
    {
        // Check for duplicate redirects
        $existing = $this->redirect_exists($old_url);
        if ($existing !== false) {
            // Update existing redirect instead of creating duplicate
            $this->update_existing_redirect($existing, $new_url);
            return true;
        }

        // Prepare redirect data
        $redirect_data = array(
            'sources_from'    => serialize(array($old_url => 'exact')),
            'url_redirect_to' => $new_url,
            'http_code'       => 301,
            'hits_count'      => 0,
            'status'          => 'active',
            'pattern_type'    => 'exact',
            'regex_pattern'   => null,
            'description'     => sprintf(
                'Auto-created: Slug change for post #%d on %s',
                $post_id,
                current_time('Y-m-d H:i:s')
            ),
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        );

        // Add the redirect
        $result = $this->db_redirection->add($redirect_data);

        if ($result) {
            // Set admin notice
            set_transient('metasync_auto_redirect_notice', array(
                'old_url' => $old_url,
                'new_url' => $new_url,
                'post_id' => $post_id,
            ), 60);

            do_action('metasync_auto_redirect_created', $old_url, $new_url, $post_id);
        }

        return $result !== false;
    }

    /**
     * Check if a redirect already exists for the given source URL
     *
     * @param string $source_url Source URL to check
     * @return object|false False if not found, redirect object if found
     */
    private function redirect_exists($source_url)
    {
        $all_redirects = $this->db_redirection->getAllActiveRecords();

        if (empty($all_redirects)) {
            return false;
        }

        foreach ($all_redirects as $redirect) {
            $sources = @unserialize($redirect->sources_from);
            if (is_array($sources) && isset($sources[$source_url])) {
                return $redirect;
            }
        }

        return false;
    }

    /**
     * Update an existing redirect's destination
     *
     * @param object $existing        Existing redirect object
     * @param string $new_destination New destination URL
     * @return bool Success or failure
     */
    private function update_existing_redirect($existing, $new_destination)
    {
        $updated_description = $existing->description . sprintf(
            "\nUpdated destination on %s",
            current_time('Y-m-d H:i:s')
        );

        $this->db_redirection->update(
            array(
                'url_redirect_to' => $new_destination,
                'description'     => $updated_description,
            ),
            $existing->id
        );

        return true;
    }

    /**
     * Display admin notice after auto-redirect creation
     */
    public function display_auto_redirect_notice()
    {
        $notice = get_transient('metasync_auto_redirect_notice');

        if (!$notice) {
            return;
        }

        delete_transient('metasync_auto_redirect_notice');

        $message = sprintf(
            'A 301 redirect was automatically created from %s to %s because the URL slug was changed.',
            '<code>' . esc_html($notice['old_url']) . '</code>',
            '<code>' . esc_html($notice['new_url']) . '</code>'
        );

        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>MetaSync Auto-Redirect:</strong> ' . wp_kses_post($message) . '</p>';
        echo '</div>';
    }
}



