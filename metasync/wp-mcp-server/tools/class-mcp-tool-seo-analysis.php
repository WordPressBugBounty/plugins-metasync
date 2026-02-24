<?php
/**
 * MCP Tool: SEO Analysis
 *
 * Provides tools for analyzing SEO aspects of WordPress posts.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Analyze SEO Tool
 */
class MCP_Tool_Analyze_SEO extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_analyze_seo';
    }

    public function get_description() {
        return 'Analyze SEO aspects of a post (title length, description, keyword usage, content analysis)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'WordPress post or page ID',
                    'minimum' => 1
                ]
            ],
            'required' => ['post_id']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('read');

        $post_id = $this->sanitize_integer($params['post_id']);

        // Verify post exists
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(sprintf("Post not found: %d", absint($post_id)));
        }

        // Get SEO meta
        $meta_title = get_post_meta($post_id, '_metasync_metatitle', true);
        $meta_desc = get_post_meta($post_id, '_metasync_metadesc', true);
        $focus_keyword = get_post_meta($post_id, '_metasync_focus_keyword', true);
        $robots_index = get_post_meta($post_id, '_metasync_robots_index', true);

        // Use post title if no meta title
        $effective_title = !empty($meta_title) ? $meta_title : $post->post_title;
        $title_length = mb_strlen($effective_title);
        $desc_length = mb_strlen($meta_desc);

        // Analyze content
        $content = $post->post_content;
        $content_text = wp_strip_all_tags($content);
        $word_count = str_word_count($content_text);

        // Check keyword usage
        $keyword_in_title = false;
        $keyword_in_content = false;
        $keyword_in_description = false;

        if (!empty($focus_keyword)) {
            $keyword_lower = mb_strtolower($focus_keyword);
            $keyword_in_title = mb_stripos($effective_title, $focus_keyword) !== false;
            $keyword_in_content = mb_stripos($content_text, $focus_keyword) !== false;
            $keyword_in_description = mb_stripos($meta_desc, $focus_keyword) !== false;
        }

        // Build analysis
        $analysis = [
            'post_info' => [
                'id' => $post_id,
                'title' => $post->post_title,
                'type' => $post->post_type,
                'status' => $post->post_status,
                'url' => get_permalink($post_id)
            ],
            'meta_title' => [
                'value' => $effective_title,
                'length' => $title_length,
                'status' => $this->get_title_status($title_length),
                'recommendation' => $this->get_title_recommendation($title_length)
            ],
            'meta_description' => [
                'value' => $meta_desc,
                'length' => $desc_length,
                'status' => $this->get_description_status($desc_length),
                'recommendation' => $this->get_description_recommendation($desc_length)
            ],
            'focus_keyword' => [
                'value' => $focus_keyword,
                'in_title' => $keyword_in_title,
                'in_description' => $keyword_in_description,
                'in_content' => $keyword_in_content,
                'status' => $this->get_keyword_status($focus_keyword, $keyword_in_title, $keyword_in_content)
            ],
            'content' => [
                'word_count' => $word_count,
                'status' => $this->get_content_status($word_count)
            ],
            'indexability' => [
                'is_indexable' => $robots_index !== 'noindex',
                'robots_setting' => $robots_index ?: 'index'
            ],
            'overall_score' => $this->calculate_seo_score(
                $title_length,
                $desc_length,
                $focus_keyword,
                $keyword_in_title,
                $keyword_in_content,
                $word_count
            )
        ];

        return $this->success($analysis);
    }

    private function get_title_status($length) {
        if ($length === 0) return 'missing';
        if ($length < 30) return 'too_short';
        if ($length > 60) return 'too_long';
        return 'good';
    }

    private function get_title_recommendation($length) {
        if ($length === 0) return 'Add a meta title (30-60 characters recommended)';
        if ($length < 30) return 'Title is too short. Aim for 30-60 characters.';
        if ($length > 60) return 'Title is too long. Keep it under 60 characters to avoid truncation in search results.';
        return 'Title length is optimal';
    }

    private function get_description_status($length) {
        if ($length === 0) return 'missing';
        if ($length < 120) return 'too_short';
        if ($length > 160) return 'too_long';
        return 'good';
    }

    private function get_description_recommendation($length) {
        if ($length === 0) return 'Add a meta description (120-160 characters recommended)';
        if ($length < 120) return 'Description is too short. Aim for 120-160 characters.';
        if ($length > 160) return 'Description is too long. Keep it under 160 characters to avoid truncation.';
        return 'Description length is optimal';
    }

    private function get_keyword_status($keyword, $in_title, $in_content) {
        if (empty($keyword)) return 'not_set';
        if (!$in_title || !$in_content) return 'not_optimized';
        return 'good';
    }

    private function get_content_status($word_count) {
        if ($word_count === 0) return 'empty';
        if ($word_count < 300) return 'too_short';
        if ($word_count > 2000) return 'excellent';
        return 'good';
    }

    private function calculate_seo_score($title_len, $desc_len, $keyword, $kw_in_title, $kw_in_content, $word_count) {
        $score = 0;

        // Title (25 points)
        if ($title_len >= 30 && $title_len <= 60) $score += 25;
        elseif ($title_len > 0) $score += 10;

        // Description (25 points)
        if ($desc_len >= 120 && $desc_len <= 160) $score += 25;
        elseif ($desc_len > 0) $score += 10;

        // Keyword usage (30 points)
        if (!empty($keyword)) {
            $score += 10; // Has keyword
            if ($kw_in_title) $score += 10;
            if ($kw_in_content) $score += 10;
        }

        // Content (20 points)
        if ($word_count >= 300) $score += 20;
        elseif ($word_count > 0) $score += 10;

        return [
            'score' => $score,
            'max_score' => 100,
            'percentage' => $score,
            'rating' => $this->get_rating($score)
        ];
    }

    private function get_rating($score) {
        if ($score >= 80) return 'excellent';
        if ($score >= 60) return 'good';
        if ($score >= 40) return 'fair';
        return 'needs_improvement';
    }
}

/**
 * Check Indexability Tool
 */
class MCP_Tool_Check_Indexability extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_check_indexability';
    }

    public function get_description() {
        return 'Check if a post is set to be indexed by search engines';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'WordPress post or page ID',
                    'minimum' => 1
                ]
            ],
            'required' => ['post_id']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('read');

        $post_id = $this->sanitize_integer($params['post_id']);

        // Verify post exists
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(sprintf("Post not found: %d", absint($post_id)));
        }

        $robots_index = get_post_meta($post_id, '_metasync_robots_index', true);
        $is_indexable = ($robots_index !== 'noindex');

        return $this->success([
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'is_indexable' => $is_indexable,
            'robots_setting' => $robots_index ?: 'index',
            'status' => $post->post_status,
            'url' => get_permalink($post_id)
        ]);
    }
}
