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
        // Hard off-switch. Bulk slug operations and migrations need a way to
        // stop rule creation entirely; the post-types filter was the only
        // control before and required code. Default on preserves behavior.
        if (!get_option('metasync_auto_redirect_enabled', 1)) {
            return;
        }

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

        // Skip if slug is empty
        if (empty($post_before->post_name) || empty($post_after->post_name)) {
            return;
        }

        // Compare full URLs, not just the slug: moving a page under a new
        // parent keeps the slug identical but changes its path, and that path
        // change strands every existing link exactly like a rename does.
        $old_url = $this->build_relative_url($post_before);
        $new_url = $this->build_relative_url($post_after);

        // Skip if URLs are the same
        if ($old_url === $new_url) {
            return;
        }

        // Create the redirect
        $this->create_redirect($old_url, $new_url, $post_id);

        // post_updated fires only for the edited post, but a hierarchical
        // move renames every descendant's URL too — create theirs as well.
        $this->create_redirects_for_descendants($post_id, $old_url, $new_url);
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
     * Create redirects for the published descendants of a renamed or moved
     * post. A child's new URL comes from its now-updated permalink; its old
     * URL is the same tail under the old parent path.
     *
     * @param int    $post_id        Parent post whose URL changed.
     * @param string $old_parent_url Parent's previous relative URL.
     * @param string $new_parent_url Parent's current relative URL.
     */
    private function create_redirects_for_descendants($post_id, $old_parent_url, $new_parent_url)
    {
        if ($old_parent_url === $new_parent_url) {
            return;
        }

        $children = get_posts(array(
            'post_type' => $this->get_supported_post_types(),
            'post_parent' => $post_id,
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ));

        foreach ($children as $child_id) {
            $child_permalink = get_permalink($child_id);
            if (!is_string($child_permalink) || $child_permalink === '') {
                continue;
            }
            $parsed = wp_parse_url($child_permalink);
            $new_child_url = isset($parsed['path']) ? $parsed['path'] : '/';
            // The child's live URL extends the parent's NEW path; swap that
            // prefix for the old one to reconstruct where it used to live.
            $tail = (strpos($new_child_url, $new_parent_url) === 0)
                ? substr($new_child_url, strlen($new_parent_url))
                : false;
            if ($tail === false || $tail === '') {
                continue;
            }
            $old_child_url = $old_parent_url . $tail;
            if ($old_child_url !== $new_child_url) {
                $this->create_redirect($old_child_url, $new_child_url, $child_id);
            }
            $this->create_redirects_for_descendants($child_id, $old_child_url, $new_child_url);
        }
    }

    /**
     * Deactivate active rules whose source matches a now-live permalink, so a
     * rename-back doesn't leave a rule bouncing visitors off a real page
     * (e.g. /a→/b survives the post being renamed back to /a, 301-ing every
     * visitor of the live /a into a 404). Rules intentionally configured on a
     * live URL (the post-edit redirection meta box) are left alone.
     *
     * @param string $source_url The permalink that just went live.
     */
    private function deactivate_rules_for_source($source_url)
    {
        $source_url = trim((string) $source_url);
        if ($source_url === '') {
            return;
        }
        $source_norm = '/' . trim($source_url, '/');
        if ($source_norm === '/') {
            return; // never touch rules on the home URL
        }

        $all_redirects = $this->db_redirection->getAllActiveRecords();
        if (empty($all_redirects)) {
            return;
        }

        foreach ($all_redirects as $redirect) {
            // The metabox legitimately points a live permalink elsewhere —
            // that's its feature — so those rows are out of scope here.
            if (strpos((string) $redirect->description, 'Post redirection meta box') === 0) {
                continue;
            }
            $sources = unserialize($redirect->sources_from, array('allowed_classes' => false)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
            if (!is_array($sources)) {
                continue;
            }
            foreach ($sources as $stored_key => $stored_value) {
                // Legacy list rows keep the URL as the value under a numeric key.
                $stored = is_int($stored_key) ? (string) $stored_value : (string) $stored_key;
                if ($stored === '') {
                    continue;
                }
                $stored = '/' . trim($stored, '/');
                if (rtrim($stored, '/') === rtrim($source_norm, '/')) {
                    $this->db_redirection->update_status(array((int) $redirect->id), 'inactive');
                    break;
                }
            }
        }
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
        // The new URL is a live permalink now, so any active rule still
        // redirecting FROM it is stale. Deactivate it before wiring the new
        // rule.
        $this->deactivate_rules_for_source($new_url);

        // Check for duplicate redirects
        $existing = $this->redirect_exists($old_url);
        if ($existing !== false) {
            // Update existing redirect instead of creating duplicate
            return $this->update_existing_redirect($existing, $new_url);
        }

        // Loop detection — silently skip and log if creating this redirect would produce a chain back to $old_url
        if (!class_exists('Metasync_Redirection')) {
            require_once dirname(__FILE__) . '/class-metasync-redirection.php';
        }
        $db_ref = $this->db_redirection;
        $redirection_helper = new Metasync_Redirection($db_ref);
        $loop_chain = array();
        if ($redirection_helper->would_create_loop($old_url, $new_url, $loop_chain)) {
            error_log('[MetaSync] Auto-redirect skipped: loop detected — ' . implode(' → ', $loop_chain));
            return false;
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
            $sources = unserialize($redirect->sources_from, ['allowed_classes' => false]); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
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
        // Same loop guard as create_redirect(): a slug change can move the
        // destination onto a path that is itself redirected back to this
        // source, silently wiring up an A↔B ping-pong. 410/451 rules send
        // no Location header, so they are exempt.
        if (!in_array((int) $existing->http_code, [410, 451], true)) {
            $sources = unserialize($existing->sources_from, ['allowed_classes' => false]); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
            $source_url = '';
            if (is_array($sources) && !empty($sources)) {
                $keys = array_keys($sources);
                $source_url = is_int($keys[0]) ? (string) $sources[$keys[0]] : (string) $keys[0];
            }
            if ($source_url !== '') {
                if (!class_exists('Metasync_Redirection')) {
                    require_once dirname(__FILE__) . '/class-metasync-redirection.php';
                }
                $db_ref = $this->db_redirection;
                $redirection_helper = new Metasync_Redirection($db_ref);
                $loop_chain = array();
                if ($redirection_helper->would_create_loop($source_url, $new_destination, $loop_chain)) {
                    error_log('[MetaSync] Auto-redirect update skipped: loop detected — ' . implode(' → ', $loop_chain));
                    return false;
                }
            }
        }

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



