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

    /** @var array|null Lazy-built exact-match lookup: normalized_path => row object */
    private $exact_index = null;
    /** @var array|null Pattern-based rows (wildcard, regex, contain, start, end) */
    private $pattern_index = null;

    public function __construct(&$db_redirection)
    {
        $this->db_redirection = $db_redirection;
        $this->common = new Metasync_Common();

        # Load importer class
        require_once dirname(__FILE__) . '/class-metasync-redirection-importer.php';
        $this->importer = new Metasync_Redirection_Importer($db_redirection);
    }

    /**
     * Build the redirect lookup index (lazy, once per request).
     * Exact-match sources go into a hashmap for O(1) lookup.
     * Pattern-based sources (wildcard, regex, contain, start, end) stay in a list.
     */
    private function ensure_redirect_index()
    {
        if ($this->exact_index !== null) {
            return;
        }

        $this->exact_index = array();
        $this->pattern_index = array();

        $redirections = $this->db_redirection->getAllActiveRecords();
        if (empty($redirections)) {
            return;
        }

        foreach ($redirections as $row) {
            $sources_from = !empty($row->sources_from)
                ? unserialize($row->sources_from, array('allowed_classes' => false))
                : array();
            $source_urls = is_array($sources_from) ? $sources_from : array();
            $global_pattern_type = isset($row->pattern_type) ? $row->pattern_type : null;
            $is_pattern = false;

            foreach ($source_urls as $source_key => $source_value) {
                $pattern_type = in_array($source_value, array('exact', 'contain', 'start', 'end', 'wildcard', 'regex'))
                    ? $source_value
                    : ($global_pattern_type ? $global_pattern_type : 'exact');

                if ($pattern_type === 'exact' && strpos((string) $source_key, '*') === false) {
                    // Normalize: extract path from full URLs, ensure leading slash, strip trailing slash
                    $norm = (string) $source_key;
                    if (strpos($norm, 'http') === 0) {
                        $parsed = parse_url($norm);
                        $norm = isset($parsed['path']) ? $parsed['path'] : '/';
                    }
                    if ($norm === '' || $norm[0] !== '/') {
                        $norm = '/' . $norm;
                    }
                    $norm = rtrim($norm, '/') ?: '/';
                    $this->exact_index[$norm] = $row;
                } else {
                    $is_pattern = true;
                }
            }

            if ($is_pattern) {
                $this->pattern_index[] = $row;
            }
        }
    }

    function contains($haystack, $needle, $caseSensitive = false)
    {
        return $caseSensitive ?
            (strpos($haystack, $needle) === FALSE ? FALSE : TRUE) : (stripos($haystack, $needle) === FALSE ? FALSE : TRUE);
    }

    public function create_admin_redirection_interface()
    {
        # Check if we should show import interface
        $request_data = metasync_sanitize_input_array($_REQUEST);
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
        $server_data =  metasync_sanitize_input_array($_SERVER);
        $link = '://' . $server_data['HTTP_HOST'] . $server_data['REQUEST_URI'];
        $link = (is_ssl() ? 'https' : 'http') . $link;
        return sanitize_url($link);
    }

    public function source_url_redirection(object $row, string $uri)
    {
        // Optimize: unserialize only once
        $sources_from = unserialize($row->sources_from, ['allowed_classes' => false]); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
        $source_urls = is_array($sources_from) ? $sources_from : [];
        $global_pattern_type = isset($row->pattern_type) ? $row->pattern_type : null;
        $regex_pattern = isset($row->regex_pattern) ? $row->regex_pattern : null;

        foreach ($source_urls as $source_key => $source_value) {
            $match_found = false;
            $captured_path = ''; // Store captured path for wildcard replacement

            // Ensure source_key is always a string (handles numeric array indexes)
            $source_key = (string) $source_key;

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
            # Convert to string to handle cases where source_key might be an integer
            $normalized_source = (string) $normalized_source;
            if (!empty($normalized_source) && $normalized_source[0] !== '/') {
                $normalized_source = '/' . $normalized_source;
            }
            if (!empty($normalized_uri) && $normalized_uri[0] !== '/') {
                $normalized_uri = '/' . $normalized_uri;
            }

            // Normalize trailing slashes so /path and /path/ match equivalently
            $normalized_source = rtrim($normalized_source, '/') ?: '/';
            $normalized_uri = rtrim($normalized_uri, '/') ?: '/';

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
     * Resolve a URL through the redirect table to its final destination (follows redirect chains).
     * Used by OTTO and other backend processing so the final canonical URL is used before 404 checks.
     *
     * @param string $url Full URL (e.g. https://example.com/old-page)
     * @param int $max_hops Maximum redirect hops to follow (default 10, prevents infinite loops)
     * @return string Final destination URL, or original $url if no redirect matches
     */
    public function resolve_url_to_final_destination($url, $max_hops = 10)
    {
        if (empty($url) || !is_string($url)) {
            return $url;
        }
        $parsed = parse_url($url);
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';
        $host = isset($parsed['host']) ? $parsed['host'] : '';
        $base = $scheme . '://' . $host;
        $uri = isset($parsed['path']) ? $parsed['path'] : '/';
        if (!empty($parsed['query'])) {
            $uri .= '?' . $parsed['query'];
        }
        $seen = array();
        for ($i = 0; $i < $max_hops; $i++) {
            $uri_key = $uri;
            if (isset($seen[$uri_key])) {
                break; // cycle detected
            }
            $seen[$uri_key] = true;
            $dest = $this->get_redirect_destination_for_uri($uri);
            if ($dest === null) {
                break;
            }
            if (strpos($dest, 'http') === 0) {
                $url = $dest;
                $parsed = parse_url($url);
                $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';
                $host = isset($parsed['host']) ? $parsed['host'] : '';
                $base = $scheme . '://' . $host;
                $uri = isset($parsed['path']) ? $parsed['path'] : '/';
                if (!empty($parsed['query'])) {
                    $uri .= '?' . $parsed['query'];
                }
            } else {
                $uri = (isset($dest[0]) && $dest[0] === '/') ? $dest : '/' . $dest;
                $url = $base . $uri;
            }
        }
        return $url;
    }

    /**
     * Get redirect destination for a URI without redirecting (no wp_redirect, no counter update).
     * Returns the destination URL/path if this URI matches a redirect source, else null.
     * Used by resolve_url_to_final_destination. 410/451 are treated as "no destination".
     *
     * @param string $uri URI path (and optional query), e.g. /old-page or /old?x=1
     * @return string|null Destination URL or path, or null if no match
     */
    private function get_redirect_destination_for_uri($uri)
    {
        $redirections = $this->db_redirection->getAllActiveRecords();
        if (empty($redirections)) {
            return null;
        }
        foreach ($redirections as $row) {
            if (isset($row->http_code) && in_array((int) $row->http_code, array(410, 451), true)) {
                continue; // Gone / Unavailable – no destination to follow
            }
            if (empty($row->url_redirect_to)) {
                continue;
            }
            $dest = $this->get_destination_for_row_and_uri($row, $uri);
            if ($dest !== null) {
                return $dest;
            }
        }
        return null;
    }

    /**
     * Determine whether creating a redirect from $source to $destination would
     * produce a redirect loop or chain longer than the configured hop budget.
     *
     * Read-only: no DB writes, no side effects. Reuses
     * get_redirect_destination_for_uri() so chain traversal matches the read path.
     *
     * @param string     $source      Source URL or path being created
     * @param string     $destination Destination URL or path being created
     * @param array|null $chain       Out param populated with the visited URI chain
     * @param int|null   $max_hops    Optional hop budget; defaults to filter `metasync_redirect_loop_max_hops` (5)
     * @return bool True if a loop or budget overrun is detected
     */
    public function would_create_loop($source, $destination, &$chain = null, $max_hops = null)
    {
        if ($max_hops === null) {
            $max_hops = (int) apply_filters('metasync_redirect_loop_max_hops', 5);
        }
        if ($max_hops < 1) {
            $max_hops = 1;
        }

        $source_uri = $this->normalize_uri_path($source);
        $current = $this->normalize_uri_path($destination);
        $chain = array($source_uri, $current);

        // Trivial direct loop: redirect points back at itself
        if ($current === $source_uri) {
            return true;
        }

        $seen = array();
        for ($i = 0; $i < $max_hops; $i++) {
            if (isset($seen[$current])) {
                // Pre-existing cycle that does not involve the new source — not our concern
                return false;
            }
            $seen[$current] = true;

            // Try matching with and without trailing slash to handle inconsistent storage
            $dest = $this->get_redirect_destination_for_uri($current);
            if ($dest === null) {
                $alt = (substr($current, -1) === '/') ? rtrim($current, '/') : $current . '/';
                if ($alt !== '' && $alt !== $current) {
                    $dest = $this->get_redirect_destination_for_uri($alt);
                }
            }
            if ($dest === null) {
                return false;
            }

            $current = $this->normalize_uri_path($dest);

            $chain[] = $current;

            if ($current === $source_uri) {
                return true;
            }
        }

        // Hop budget exhausted without resolving — treat as a loop-equivalent warning
        return true;
    }

    /**
     * Validate that a redirect would not create a loop.
     *
     * @param string $source      Source URL or path
     * @param string $destination Destination URL or path
     * @return string|null Null if no loop, error message string if loop detected
     */
    public function validate_no_loop($source, $destination) {
        $chain = [];
        if ($this->would_create_loop($source, $destination, $chain)) {
            return 'Redirect would create a loop: ' . implode(' → ', $chain);
        }
        return null;
    }

    /**
     * Normalise a URL or path to a leading-slash URI path used for chain comparison.
     *
     * @param string $url
     * @return string
     */
    private function normalize_uri_path($url)
    {
        if (!is_string($url) || $url === '') {
            return '/';
        }
        if (strpos($url, 'http') === 0) {
            $parsed = parse_url($url);
            $path = isset($parsed['path']) ? $parsed['path'] : '/';
        } else {
            $path = $url;
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $path = rtrim($path, '/') ?: '/';
        return $path;
    }

    /**
     * Check health of one or all active redirects.
     *
     * Returns per-redirect diagnostics: loop, chain_too_long, dead_end, or ok.
     * Uses a prebuilt lookup index for O(1) exact-match chain walking instead
     * of re-scanning all records per hop.
     *
     * @param int|null $redirect_id Optional single redirect ID to check.
     * @param int      $max_hops    Hops beyond which a chain is flagged (default 3).
     * @return array Array of health result objects.
     */
    public function check_redirect_health($redirect_id = null, $max_hops = 3)
    {
        // Preload all active records once and build a lookup index
        $all_records = $this->db_redirection->getAllActiveRecords();
        $exact_map = array();     // normalized_path => destination (O(1) lookup)
        $pattern_rows = array();  // non-exact rows requiring linear scan

        foreach ($all_records as $row) {
            if (isset($row->http_code) && in_array((int) $row->http_code, array(410, 451), true)) {
                continue;
            }
            if (empty($row->url_redirect_to)) {
                continue;
            }
            $sources_from = !empty($row->sources_from)
                ? unserialize($row->sources_from, array('allowed_classes' => false))
                : array();
            $source_urls = is_array($sources_from) ? $sources_from : array();
            $global_pattern_type = isset($row->pattern_type) ? $row->pattern_type : null;

            foreach ($source_urls as $source_key => $source_value) {
                $pattern_type = in_array($source_value, array('exact', 'contain', 'start', 'end', 'wildcard', 'regex'))
                    ? $source_value
                    : ($global_pattern_type ? $global_pattern_type : 'exact');

                if ($pattern_type === 'exact' && strpos((string) $source_key, '*') === false) {
                    $norm = $this->normalize_uri_path($source_key);
                    $exact_map[$norm] = $row->url_redirect_to;
                } else {
                    $pattern_rows[] = $row;
                    break; // row already added, no need to check other sources
                }
            }
        }

        // Determine which records to check
        if ($redirect_id !== null) {
            $record = $this->db_redirection->find((int) $redirect_id);
            $records = $record ? array($record) : array();
        } else {
            $records = $all_records;
        }

        $results = array();

        foreach ($records as $row) {
            $id = isset($row->id) ? (int) $row->id : 0;
            $destination = isset($row->url_redirect_to) ? $row->url_redirect_to : '';
            $http_code = isset($row->http_code) ? (int) $row->http_code : 301;

            // Extract first source path for display
            $sources_from = !empty($row->sources_from)
                ? unserialize($row->sources_from, array('allowed_classes' => false))
                : array();
            $source_keys = is_array($sources_from) ? array_keys($sources_from) : array();
            $source = !empty($source_keys) ? $source_keys[0] : '';
            $source_path = $this->normalize_uri_path($source);

            // 410/451 have no destination — always ok
            if (in_array($http_code, array(410, 451), true)) {
                $results[] = array(
                    'id'                => $id,
                    'source'            => $source_path,
                    'destination'       => $destination,
                    'final_destination' => null,
                    'chain_length'      => 0,
                    'chain'             => array($source_path),
                    'status'            => 'ok',
                );
                continue;
            }

            // Walk chain using the prebuilt index
            $current = $this->normalize_uri_path($destination);
            $chain = array($source_path, $current);
            $seen = array();
            $is_loop = false;
            $hard_limit = 20;

            for ($i = 0; $i < $hard_limit; $i++) {
                if (isset($seen[$current])) {
                    $is_loop = true;
                    break;
                }
                $seen[$current] = true;

                // O(1) exact-match lookup first
                $dest = isset($exact_map[$current]) ? $exact_map[$current] : null;
                if ($dest === null) {
                    // Try trailing slash variant
                    $alt = (substr($current, -1) === '/') ? rtrim($current, '/') : $current . '/';
                    if ($alt !== '' && $alt !== $current) {
                        $dest = isset($exact_map[$alt]) ? $exact_map[$alt] : null;
                    }
                }
                // Fallback: scan pattern-based rows only (small set)
                if ($dest === null && !empty($pattern_rows)) {
                    foreach ($pattern_rows as $prow) {
                        $dest = $this->get_destination_for_row_and_uri($prow, $current);
                        if ($dest !== null) {
                            break;
                        }
                        // Try trailing slash alt for patterns too
                        if (isset($alt)) {
                            $dest = $this->get_destination_for_row_and_uri($prow, $alt);
                            if ($dest !== null) {
                                break;
                            }
                        }
                    }
                }

                if ($dest === null) {
                    break;
                }

                $current = $this->normalize_uri_path($dest);
                $chain[] = $current;
            }

            // Classify: loop > chain_too_long > dead_end > ok
            $chain_hops = count($chain) - 1;
            if ($is_loop) {
                $status = 'loop';
            } elseif ($chain_hops > $max_hops) {
                $status = 'chain_too_long';
            } else {
                // Check if terminal destination resolves to a real page
                $is_dead = false;
                if (function_exists('url_to_postid')) {
                    $post_id = url_to_postid(site_url($current));
                    if ($post_id === 0) {
                        $post_id = url_to_postid(site_url($current . '/'));
                    }
                    $is_dead = ($post_id === 0);
                }
                $status = $is_dead ? 'dead_end' : 'ok';
            }

            $results[] = array(
                'id'                => $id,
                'source'            => $source_path,
                'destination'       => $this->normalize_uri_path($destination),
                'final_destination' => $current,
                'chain_length'      => $chain_hops,
                'chain'             => $chain,
                'status'            => $status,
            );
        }

        return $results;
    }

    /**
     * Handle AJAX health check request from admin UI.
     */
    public function handle_health_check_ajax()
    {
        check_ajax_referer('metasync_redirect_health_check', 'nonce');

        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
            return;
        }

        $redirect_id = isset($_POST['redirect_id']) ? intval($_POST['redirect_id']) : null;
        if ($redirect_id === 0) {
            $redirect_id = null;
        }

        $results = $this->check_redirect_health($redirect_id);
        wp_send_json_success(array('results' => $results));
    }

    /**
     * Get destination for a single row and URI if it matches. Same matching logic as source_url_redirection.
     *
     * @param object $row Redirect row
     * @param string $uri URI to match
     * @return string|null Destination URL/path or null
     */
    private function get_destination_for_row_and_uri($row, $uri)
    {
        // Parse stored redirect sources (serialized: source path/URL => pattern type per source, or single list)
        $sources_from = unserialize($row->sources_from, ['allowed_classes' => false]); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
        $source_urls = is_array($sources_from) ? $sources_from : array();
        $global_pattern_type = isset($row->pattern_type) ? $row->pattern_type : null;
        $regex_pattern = isset($row->regex_pattern) ? $row->regex_pattern : null;

        foreach ($source_urls as $source_key => $source_value) {
            $match_found = false;
            $captured_path = ''; // Used for wildcard/regex replacement in destination (e.g. * or $1)

            // Resolve pattern type: per-source value (exact, contain, start, end, wildcard, regex) or row-level default
            $pattern_type = in_array($source_value, array('exact', 'contain', 'start', 'end', 'wildcard', 'regex'))
                ? $source_value
                : ($global_pattern_type ? $global_pattern_type : 'exact');

            $normalized_uri = $uri;
            $normalized_source = $source_key;

            // If source is a full URL, use only the path for matching (consistent with front-end redirect behavior)
            if (strpos($source_key, 'http') === 0) {
                $parsed_src = parse_url($source_key);
                $normalized_source = isset($parsed_src['path']) ? $parsed_src['path'] : '';
            }

            // Ensure leading slash for reliable path comparison
            if (!empty($normalized_source) && $normalized_source[0] !== '/') {
                $normalized_source = '/' . $normalized_source;
            }
            if (!empty($normalized_uri) && $normalized_uri[0] !== '/') {
                $normalized_uri = '/' . $normalized_uri;
            }

            // Normalize trailing slashes so /path and /path/ match equivalently
            $normalized_source = rtrim($normalized_source, '/') ?: '/';
            $normalized_uri = rtrim($normalized_uri, '/') ?: '/';

            // --- Matching: regex, wildcard, or legacy pattern types ---

            if ($pattern_type === 'regex' && $regex_pattern) {
                // Regex: validate and normalize pattern, then match; capture group 1 for $1 in destination
                if (!$this->validate_regex_pattern($regex_pattern)) {
                    continue;
                }
                $normalized_pattern = $this->normalize_regex_pattern($regex_pattern);
                $matches = array();
                if (@preg_match($normalized_pattern, $normalized_uri, $matches) === 1) {
                    $match_found = true;
                    $captured_path = isset($matches[1]) ? $matches[1] : '';
                }
            } else {
                // Non-regex: check for * in source (wildcard) or use exact/contain/start/end
                $has_wildcard = strpos($normalized_source, '*') !== false;
                if ($has_wildcard) {
                    // Wildcard: e.g. /old/* matches /old/page and captures "page" for destination
                    $match_result = $this->match_wildcard($normalized_source, $normalized_uri);
                    if ($match_result !== false) {
                        $match_found = true;
                        $captured_path = $match_result;
                    }
                } else {
                    // Legacy pattern: source_value can be the pattern type when key is the path
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
                            // Fallback to row-level pattern_type when source_value is not a known type
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

            // First match wins: return destination with * / $1 replaced by captured_path
            if ($match_found && !empty($row->url_redirect_to)) {
                return $this->process_destination_url($row->url_redirect_to, $captured_path);
            }
        }

        return null;
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
        // Sanitize: escape the entire pattern for safe regex use, then restore wildcards
        $escaped = preg_quote($pattern, '/');
        // preg_quote escapes *, so replace the escaped \* back with a capturing group
        $regex = '/^' . str_replace('\\*', '(.*)', $escaped) . '$/';

        $matches = [];
        $result = @preg_match($regex, $uri, $matches);

        if ($result && $result !== false) {
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

        // Build the lookup index (lazy, once per request)
        $this->ensure_redirect_index();

        // O(1) exact-match lookup first
        $normalized_uri = $uri;
        if (!empty($normalized_uri) && $normalized_uri[0] !== '/') {
            $normalized_uri = '/' . $normalized_uri;
        }
        $normalized_uri = rtrim($normalized_uri, '/') ?: '/';

        if (isset($this->exact_index[$normalized_uri])) {
            if ($this->source_url_redirection($this->exact_index[$normalized_uri], $uri)) {
                return;
            }
        }

        // Fallback: scan only pattern-based rows (wildcard, regex, contain, start, end)
        foreach ($this->pattern_index as $redirection) {
            if ($this->source_url_redirection($redirection, $uri)) {
                return;
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
    public static function normalize_regex_pattern($pattern)
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
