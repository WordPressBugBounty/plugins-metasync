<?php
/**
 * MetaSync Internal Link Suggestions
 *
 * Provides a REST API endpoint that returns relevant internal link suggestions
 * based on content similarity for the Gutenberg editor sidebar.
 *
 * @package    Metasync
 * @subpackage Metasync/admin
 * @since      2.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Link_Suggestions {

    /**
     * Common stop words to filter out from bigram matching
     */
    private static $stop_words = array(
        'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
        'of', 'with', 'by', 'from', 'is', 'it', 'as', 'be', 'was', 'are',
        'been', 'has', 'have', 'had', 'do', 'does', 'did', 'will', 'would',
        'could', 'should', 'may', 'might', 'can', 'this', 'that', 'these',
        'those', 'not', 'no', 'so', 'if', 'how', 'what', 'when', 'where',
        'who', 'which', 'why', 'all', 'each', 'every', 'both', 'few', 'more',
        'most', 'other', 'some', 'such', 'than', 'too', 'very', 'just', 'about',
        'above', 'after', 'again', 'also', 'any', 'because', 'before', 'between',
        'into', 'its', 'our', 'out', 'own', 'same', 'she', 'he', 'her', 'his',
        'him', 'my', 'your', 'they', 'them', 'their', 'we', 'you', 'up', 'get',
    );

    /**
     * Constructor
     */
    public function __construct() {
        $this->init();
    }

    /**
     * Initialize hooks
     */
    private function init() {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('save_post', array($this, 'invalidate_cache'), 10, 2);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('metasync/v1', '/link-suggestions', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_suggestions'),
            'permission_callback' => array($this, 'permission_check'),
            'args'                => array(
                'post_id' => array(
                    'required' => true,
                    'type'     => 'integer',
                    'sanitize_callback' => 'absint',
                ),
                'limit' => array(
                    'default' => 10,
                    'type'    => 'integer',
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));
    }

    /**
     * Permission callback - requires edit_posts capability
     *
     * @return bool
     */
    public function permission_check() {
        return current_user_can('edit_posts');
    }

    /**
     * Check if a bigram contains only stop words
     *
     * @param string $bigram Two-word phrase
     * @return bool True if the bigram is too generic
     */
    private function is_generic_bigram($bigram) {
        $words = preg_split('/\s+/', strtolower(trim($bigram)));
        if (count($words) < 2) {
            return true;
        }
        // Generic if BOTH words are stop words
        $word1_is_stop = in_array($words[0], self::$stop_words, true);
        $word2_is_stop = in_array($words[1], self::$stop_words, true);
        return ($word1_is_stop && $word2_is_stop);
    }

    /**
     * Calculate relevance score for a suggestion
     *
     * @param string $match_type Type of match (full_title, focus_keyword, bigram, tag)
     * @param string $matched_phrase The phrase that matched
     * @param string $candidate_title The candidate post title
     * @return float Score between 0 and 1
     */
    private function calculate_relevance_score($match_type, $matched_phrase, $candidate_title) {
        switch ($match_type) {
            case 'full_title':
                return 1.0;
            case 'focus_keyword':
                return 0.9;
            case 'tag':
                // Longer tag matches are more relevant
                $len = strlen($matched_phrase);
                return $len > 10 ? 0.7 : 0.6;
            case 'bigram':
                // Bigrams from longer titles that are more specific score higher
                $title_word_count = str_word_count($candidate_title);
                if ($title_word_count <= 3) {
                    return 0.5; // Short title = bigram covers most of it
                }
                return 0.4;
            default:
                return 0.3;
        }
    }

    /**
     * Get link suggestions for a post
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_suggestions($request) {
        $post_id = intval($request->get_param('post_id'));
        $limit   = min(intval($request->get_param('limit')), 10);

        if ($limit < 1) {
            $limit = 10;
        }

        // Check transient cache
        $cache_key = 'metasync_link_suggestions_' . $post_id;
        $cached    = get_transient($cache_key);
        if ($cached !== false) {
            return new WP_REST_Response($cached, 200);
        }

        // Verify the user can read this specific post (prevents IDOR)
        if (!current_user_can('read_post', $post_id)) {
            return new WP_REST_Response(array(
                'suggestions'          => array(),
                'yoast_premium_active' => class_exists('WPSEO_Premium'),
                'rankmath_active'      => defined('RANK_MATH_VERSION'),
            ), 200);
        }

        $post = get_post($post_id);
        if (!$post) {
            return new WP_REST_Response(array(
                'suggestions'          => array(),
                'yoast_premium_active' => class_exists('WPSEO_Premium'),
                'rankmath_active'      => defined('RANK_MATH_VERSION'),
            ), 200);
        }

        // Strip block comments and HTML tags to get clean text for matching
        $content = $post->post_content;
        $content_text = wp_strip_all_tags(strip_shortcodes($content));

        // Build list of already-linked URLs
        $already_linked = array();
        if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
            $already_linked = array_map('untrailingslashit', $matches[1]);
        }

        // Get public post types
        $public_post_types = get_post_types(array('public' => true), 'names');

        // Extract significant words from content for search query
        $content_words = array_filter(
            str_word_count(strtolower($content_text), 1),
            function($word) {
                return strlen($word) >= 4 && !in_array($word, self::$stop_words, true);
            }
        );
        // Get top frequent words for search
        $word_freq = array_count_values($content_words);
        arsort($word_freq);
        $search_terms = implode(' ', array_slice(array_keys($word_freq), 0, 5));

        // Two-pass query approach:
        // Pass 1: Search by content relevance using WordPress search
        // Pass 2: Recent posts as fallback
        $candidate_ids = array();

        // Pass 1: Relevance-based search
        if (!empty($search_terms)) {
            $search_query = new WP_Query(array(
                'post__not_in'   => array($post_id),
                'post_status'    => 'publish',
                'posts_per_page' => 100,
                'post_type'      => array_values($public_post_types),
                's'              => $search_terms,
                'no_found_rows'  => true,
                'fields'         => 'ids',
                'meta_query'     => array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_metasync_robots_index',
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key'     => '_metasync_robots_index',
                        'value'   => 'noindex',
                        'compare' => '!=',
                    ),
                ),
            ));
            $candidate_ids = $search_query->posts;
        }

        // Pass 2: Fill up to 200 candidates with recent posts
        $existing_count = count($candidate_ids);
        if ($existing_count < 200) {
            $exclude_ids = array_merge(array($post_id), $candidate_ids);
            $recent_query = new WP_Query(array(
                'post__not_in'   => $exclude_ids,
                'post_status'    => 'publish',
                'posts_per_page' => 200 - $existing_count,
                'post_type'      => array_values($public_post_types),
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'no_found_rows'  => true,
                'fields'         => 'ids',
                'meta_query'     => array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_metasync_robots_index',
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key'     => '_metasync_robots_index',
                        'value'   => 'noindex',
                        'compare' => '!=',
                    ),
                ),
            ));
            $candidate_ids = array_merge($candidate_ids, $recent_query->posts);
        }

        if (empty($candidate_ids)) {
            $data = array(
                'suggestions'          => array(),
                'yoast_premium_active' => class_exists('WPSEO_Premium'),
                'rankmath_active'      => defined('RANK_MATH_VERSION'),
            );
            set_transient($cache_key, $data, HOUR_IN_SECONDS);
            return new WP_REST_Response($data, 200);
        }

        // Batch pre-fetch: prime the post meta cache for all candidates at once
        update_meta_cache('post', $candidate_ids);

        // Batch pre-fetch: prime the term cache for all candidates (tags)
        update_object_term_cache($candidate_ids, array_values($public_post_types));

        // Now loop through candidates - meta and term queries will hit cache, not DB
        $suggestions = array();

        foreach ($candidate_ids as $candidate_id) {
            $candidate_post = get_post($candidate_id);
            if (!$candidate_post) {
                continue;
            }

            $candidate_title = $candidate_post->post_title;
            $candidate_url   = get_permalink($candidate_id);

            // Skip if already linked
            if (in_array(untrailingslashit($candidate_url), $already_linked, true)) {
                continue;
            }

            // Build match phrases with types for relevance scoring
            // Priority: full title > focus keyword > tags > bigrams
            $match_entries = array();

            // Full title match (highest priority)
            if (strlen($candidate_title) >= 3) {
                $match_entries[] = array('phrase' => $candidate_title, 'type' => 'full_title');
            }

            // Focus keyword match
            $candidate_focus = get_post_meta($candidate_id, '_metasync_focus_keyword', true);
            if (!empty($candidate_focus) && strlen(trim($candidate_focus)) >= 3) {
                $match_entries[] = array('phrase' => trim($candidate_focus), 'type' => 'focus_keyword');
            }

            // Tag matches
            $candidate_tags = wp_get_post_tags($candidate_id, array('fields' => 'names'));
            if (!empty($candidate_tags) && !is_wp_error($candidate_tags)) {
                foreach ($candidate_tags as $tag_name) {
                    if (strlen($tag_name) >= 3) {
                        $match_entries[] = array('phrase' => $tag_name, 'type' => 'tag');
                    }
                }
            }

            // Bigram matches from title (lowest priority, filtered)
            $title_words = preg_split('/\s+/', $candidate_title);
            if (count($title_words) >= 2) {
                for ($i = 0; $i < count($title_words) - 1; $i++) {
                    $bigram = $title_words[$i] . ' ' . $title_words[$i + 1];
                    // Skip bigrams that are too short or too generic
                    if (strlen($bigram) < 6 || $this->is_generic_bigram($bigram)) {
                        continue;
                    }
                    $match_entries[] = array('phrase' => $bigram, 'type' => 'bigram');
                }
            }

            // Check if any phrase appears in the current post content
            $matched_phrase = null;
            $match_type = null;
            foreach ($match_entries as $entry) {
                if (stripos($content_text, $entry['phrase']) !== false) {
                    $matched_phrase = $entry['phrase'];
                    $match_type = $entry['type'];
                    break;
                }
            }

            if ($matched_phrase !== null) {
                $relevance = $this->calculate_relevance_score($match_type, $matched_phrase, $candidate_title);
                $suggestions[] = array(
                    'post_id'         => $candidate_id,
                    'title'           => $candidate_title,
                    'url'             => $candidate_url,
                    'matched_phrase'  => $matched_phrase,
                    'relevance_score' => $relevance,
                );
            }
        }

        // Sort by relevance score descending
        usort($suggestions, function($a, $b) {
            return $b['relevance_score'] <=> $a['relevance_score'];
        });

        // Trim to limit
        $suggestions = array_slice($suggestions, 0, $limit);

        // Cross-plugin detection
        $yoast_active    = class_exists('WPSEO_Premium');
        $rankmath_active = defined('RANK_MATH_VERSION');

        $data = array(
            'suggestions'          => $suggestions,
            'yoast_premium_active' => $yoast_active,
            'rankmath_active'      => $rankmath_active,
        );

        set_transient($cache_key, $data, HOUR_IN_SECONDS);

        return new WP_REST_Response($data, 200);
    }

    /**
     * Invalidate link suggestions cache on post save
     * Only for actual published posts, not revisions or autosaves
     *
     * @param int $post_id
     * @param WP_Post $post
     */
    public function invalidate_cache($post_id, $post = null) {
        // Skip revisions and autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Only invalidate for published posts
        if ($post && $post->post_status !== 'publish') {
            return;
        }

        delete_transient('metasync_link_suggestions_' . $post_id);
    }
}
