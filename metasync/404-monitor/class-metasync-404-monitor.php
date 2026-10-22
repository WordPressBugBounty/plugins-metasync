<?php

/**
 * The 404 error monitor functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/404-monitor
 * @author     Engineering Team <support@searchatlas.com>
 */

// Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Error_Monitor
{
    private $database;

    public function __construct(&$database)
    {
        $this->database = $database;
    }

    /**
     * Format a 404 URI into a short, human-readable label for chart bars and
     * list-table rows.
     *
     * Absolute URLs (scheme + host) are reduced to just the path (+ query),
     * because the host is shared across every row on a given site and is the
     * part that eats the entire label budget under a naive head-truncation.
     * Paths that still exceed $max_length are truncated from the middle so the
     * distinguishing tail (the last path segment that identifies the page)
     * stays visible.
     *
     * The caller is responsible for escaping the returned string for output.
     *
     * @param string $uri        The stored URI (absolute URL or relative path).
     * @param int    $max_length Maximum label length in characters.
     * @return string The formatted label (unescaped).
     */
    public static function format_uri_label($uri, $max_length = 60)
    {
        $uri = (string) $uri;

        // Strip scheme + host from absolute URLs, keeping only the path and
        // query string (the parts that differ between 404 rows on one site).
        if (strpos($uri, 'http') === 0) {
            $parsed = wp_parse_url($uri);
            $path = isset($parsed['path']) ? $parsed['path'] : '';
            if (isset($parsed['query']) && $parsed['query'] !== '') {
                $path .= '?' . $parsed['query'];
            }
            $uri = $path !== '' ? $path : $uri;
        }

        // Ensure the result starts with a leading slash so relative paths
        // ('.env', 'api/graphql') render as '/.env', '/api/graphql'.
        if ($uri !== '' && $uri[0] !== '/') {
            $uri = '/' . $uri;
        }

        // Middle-truncate over-long paths so the distinguishing tail stays
        // visible (head-truncation would drop the last segment entirely).
        // Count and cut by characters, not bytes: a byte cut through a
        // multibyte character yields invalid UTF-8, which esc_html() turns
        // into an empty string (blank chart label / table cell).
        $use_mb = function_exists('mb_strlen') && function_exists('mb_substr');
        $length = $use_mb ? mb_strlen($uri, 'UTF-8') : strlen($uri);
        if ($length > $max_length) {
            $ellipsis = '...';
            $keep = $max_length - strlen($ellipsis);
            if ($keep <= 0) {
                // $max_length too small for head + tail; keep the tail only.
                $uri = $ellipsis . ($use_mb ? mb_substr($uri, -$max_length, null, 'UTF-8') : substr($uri, -$max_length));
            } else {
                $head_len = (int) floor($keep / 2);
                $tail_len = $keep - $head_len;
                if ($use_mb) {
                    $uri = mb_substr($uri, 0, $head_len, 'UTF-8') . $ellipsis . mb_substr($uri, -$tail_len, null, 'UTF-8');
                } else {
                    $uri = substr($uri, 0, $head_len) . $ellipsis . substr($uri, -$tail_len);
                }
            }
        }

        return $uri;
    }

   

    public function create_admin_plugin_interface()
    {
        if (!class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }
        require dirname(__FILE__, 2) . '/404-monitor/class-metasync-404-monitor-list-table.php';

        $Metasync404Monitor = new Metasync_Error_Monitor_List_Table();
        $Metasync404Monitor->setDatabaseResource($this->database);
        $Metasync404Monitor->prepare_items();

        // Include the enhanced view markup.
        include dirname(__FILE__, 2) . '/views/metasync-404-monitor.php';
    }




    /**
     * Create redirection from 404 suggestion
     */
    public function create_redirection_from_404($uri, $redirect_to, $description = '')
    {
        if (empty($uri) || empty($redirect_to)) {
            return false;
        }
        
        // Initialize redirection database
        require_once dirname(__FILE__, 2) . '/redirections/class-metasync-redirection-database.php';
        $db_redirection = new Metasync_Redirection_Database();

        // Destination guard — same checks as the admin form and MCP tools.
        // Backslashes and protocol-relative hosts bypass host validation in
        // browsers while looking internal to wp_validate_redirect. Return
        // false on rejection, mirroring the loop guard below.
        if (!class_exists('Metasync_Redirection_Validator')) {
            require_once dirname(__FILE__, 2) . '/redirections/class-metasync-redirection-validator.php';
        }
        if (!Metasync_Redirection_Validator::is_safe_destination_syntax($redirect_to)
            || (!get_option('metasync_allow_external_redirects', 0) && wp_validate_redirect($redirect_to, '') !== $redirect_to)) {
            return false;
        }

        // Loop guard: a 404 whose suggested target is itself redirected back
        // to the 404ing URI would ping-pong forever. Mirrors the admin form.
        if (!class_exists('Metasync_Redirection')) {
            require_once dirname(__FILE__, 2) . '/redirections/class-metasync-redirection.php';
        }
        $db_ref = $db_redirection;
        $redirection_helper = new Metasync_Redirection($db_ref);
        if ($redirection_helper->validate_no_loop($uri, $redirect_to) !== null) {
            return false;
        }
        
        $data = [
            'sources_from' => serialize([$uri => 'exact']),
            'url_redirect_to' => $redirect_to,
            'http_code' => 301,
            'status' => 'active',
            'pattern_type' => 'exact',
            'description' => $description ?: 'Created from 404 suggestion'
        ];

        $added = $db_redirection->add($data);

        if ($added !== false) {
            Metasync_PostHog::feature('redirect_created_from_404', array());
        }

        return $added;
    }
}
