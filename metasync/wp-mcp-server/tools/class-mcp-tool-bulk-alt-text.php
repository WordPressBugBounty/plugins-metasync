<?php
/**
 * MCP Tools for Bulk Alt Text Operations
 *
 * Provides MCP tools for auditing and bulk editing image alt text.
 * Addresses accessibility and image SEO at scale.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 * @since      2.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(dirname(__FILE__)) . 'class-mcp-tool-base.php';

/**
 * Audit Alt Text Tool
 *
 * Finds images without alt text or with poor quality alt text
 */
class MCP_Tool_Audit_Alt_Text extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_audit_alt_text';
    }

    public function get_description() {
        return 'Audit images for missing or poor quality alt text. Returns images that need attention for accessibility and SEO.';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['missing', 'short', 'long', 'all'],
                    'description' => 'Filter by alt text status: missing (no alt text), short (<10 chars), long (>125 chars), all (return all images)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of images to return (default: 100, max: 500)',
                    'minimum' => 1,
                    'maximum' => 500,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Number of images to skip (for pagination)',
                    'minimum' => 0,
                ],
                'mime_type' => [
                    'type' => 'string',
                    'description' => 'Filter by MIME type (e.g., "image/jpeg", "image/png")',
                ],
            ],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('upload_files');

        $status = isset($params['status']) ? sanitize_text_field($params['status']) : 'missing';
        $limit = isset($params['limit']) ? min(intval($params['limit']), 500) : 100;
        $offset = isset($params['offset']) ? intval($params['offset']) : 0;
        $mime_type = isset($params['mime_type']) ? sanitize_text_field($params['mime_type']) : '';

        // Query arguments
        $query_args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'post_mime_type' => $mime_type ?: 'image',
        ];

        $query = new WP_Query($query_args);
        $images = [];
        $counts = [
            'missing' => 0,
            'short' => 0,
            'long' => 0,
            'good' => 0,
        ];

        foreach ($query->posts as $attachment) {
            $alt_text = get_post_meta($attachment->ID, '_wp_attachment_image_alt', true);
            $alt_length = mb_strlen($alt_text);

            // Determine status
            $image_status = 'good';
            if (empty($alt_text)) {
                $image_status = 'missing';
                $counts['missing']++;
            } elseif ($alt_length < 10) {
                $image_status = 'short';
                $counts['short']++;
            } elseif ($alt_length > 125) {
                $image_status = 'long';
                $counts['long']++;
            } else {
                $counts['good']++;
            }

            // Filter by requested status
            if ($status !== 'all' && $image_status !== $status) {
                continue;
            }

            $metadata = wp_get_attachment_metadata($attachment->ID);
            $file_size = filesize(get_attached_file($attachment->ID));

            $images[] = [
                'attachment_id' => $attachment->ID,
                'url' => wp_get_attachment_url($attachment->ID),
                'filename' => basename($attachment->guid),
                'title' => $attachment->post_title,
                'alt_text' => $alt_text,
                'alt_length' => $alt_length,
                'status' => $image_status,
                'mime_type' => $attachment->post_mime_type,
                'width' => isset($metadata['width']) ? $metadata['width'] : null,
                'height' => isset($metadata['height']) ? $metadata['height'] : null,
                'file_size' => $file_size,
                'uploaded' => $attachment->post_date,
            ];
        }

        // Get total counts
        $total_query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'post_mime_type' => $mime_type ?: 'image',
            'fields' => 'ids',
        ]);
        $total_images = $total_query->found_posts;

        return $this->success([
            'total_images' => $total_images,
            'images_analyzed' => count($query->posts),
            'images_returned' => count($images),
            'counts' => $counts,
            'filter' => $status,
            'images' => $images,
            'recommendations' => $this->get_recommendations($counts, $total_images),
        ]);
    }

    /**
     * Generate recommendations based on audit results
     */
    private function get_recommendations($counts, $total) {
        $recommendations = [];

        $missing_pct = ($counts['missing'] / max($total, 1)) * 100;
        $short_pct = ($counts['short'] / max($total, 1)) * 100;
        $long_pct = ($counts['long'] / max($total, 1)) * 100;

        if ($missing_pct > 10) {
            $recommendations[] = [
                'severity' => 'high',
                'issue' => "{$counts['missing']} images ({$missing_pct}%) have no alt text",
                'action' => 'Add descriptive alt text to improve accessibility and SEO',
            ];
        }

        if ($short_pct > 5) {
            $recommendations[] = [
                'severity' => 'medium',
                'issue' => "{$counts['short']} images ({$short_pct}%) have very short alt text (<10 chars)",
                'action' => 'Expand alt text to be more descriptive (10-125 characters recommended)',
            ];
        }

        if ($long_pct > 5) {
            $recommendations[] = [
                'severity' => 'low',
                'issue' => "{$counts['long']} images ({$long_pct}%) have very long alt text (>125 chars)",
                'action' => 'Shorten alt text to improve readability (125 characters max recommended)',
            ];
        }

        if (empty($recommendations)) {
            $recommendations[] = [
                'severity' => 'info',
                'issue' => 'Alt text quality is good',
                'action' => 'Continue maintaining quality alt text for all images',
            ];
        }

        return $recommendations;
    }
}

/**
 * Bulk Update Alt Text Tool
 *
 * Updates alt text for multiple images at once
 */
class MCP_Tool_Bulk_Update_Alt_Text extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_bulk_update_alt_text';
    }

    public function get_description() {
        return 'Update alt text for multiple images at once (max 100 images per request)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'updates' => [
                    'type' => 'array',
                    'description' => 'Array of alt text updates',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'attachment_id' => [
                                'type' => 'integer',
                                'description' => 'Attachment ID',
                            ],
                            'alt_text' => [
                                'type' => 'string',
                                'description' => 'New alt text',
                            ],
                        ],
                        'required' => ['attachment_id', 'alt_text'],
                    ],
                    'maxItems' => 100,
                ],
            ],
            'required' => ['updates'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('upload_files');

        if (!is_array($params['updates'])) {
            throw new Exception('updates must be an array');
        }

        $updates = $params['updates'];

        if (count($updates) > 100) {
            throw new Exception('Maximum 100 images can be updated at once');
        }

        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($updates as $update) {
            try {
                if (!isset($update['attachment_id']) || !isset($update['alt_text'])) {
                    throw new Exception('Missing required fields: attachment_id, alt_text');
                }

                $attachment_id = intval($update['attachment_id']);
                $alt_text = sanitize_text_field($update['alt_text']);

                // Verify attachment exists
                $attachment = get_post($attachment_id);
                if (!$attachment || $attachment->post_type !== 'attachment') {
                    $results['failed'][] = [
                        'attachment_id' => $attachment_id,
                        'error' => 'Attachment not found',
                    ];
                    continue;
                }

                // Get old alt text
                $old_alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

                // Update alt text
                update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);

                $results['success'][] = [
                    'attachment_id' => $attachment_id,
                    'filename' => basename($attachment->guid),
                    'old_alt_text' => $old_alt_text,
                    'new_alt_text' => $alt_text,
                    'alt_length' => mb_strlen($alt_text),
                ];

            } catch (Exception $e) {
                $results['failed'][] = [
                    'attachment_id' => isset($update['attachment_id']) ? $update['attachment_id'] : null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $this->success([
            'total_requested' => count($updates),
            'success_count' => count($results['success']),
            'failed_count' => count($results['failed']),
            'results' => $results,
            'message' => count($results['success']) . ' image(s) alt text updated successfully',
        ]);
    }
}

/**
 * Generate Alt Text Tool
 *
 * Generates alt text suggestions based on image filename and context
 */
class MCP_Tool_Generate_Alt_Text extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_generate_alt_text';
    }

    public function get_description() {
        return 'Generate alt text suggestions for images based on filename, title, and context. Useful as a starting point for manual editing.';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'attachment_id' => [
                    'type' => 'integer',
                    'description' => 'Attachment ID to generate alt text for',
                ],
                'context' => [
                    'type' => 'string',
                    'description' => 'Optional context (e.g., post title, surrounding text) to improve suggestions',
                ],
            ],
            'required' => ['attachment_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('upload_files');

        $attachment_id = intval($params['attachment_id']);
        $context = isset($params['context']) ? sanitize_text_field($params['context']) : '';

        // Verify attachment exists
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            throw new Exception(sprintf("Attachment not found: %d", $attachment_id));
        }

        // Get existing data
        $current_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $title = $attachment->post_title;
        $filename = basename($attachment->guid);
        $caption = $attachment->post_excerpt;
        $description = $attachment->post_content;

        // Generate suggestions
        $suggestions = [];

        // Suggestion 1: Based on title
        if (!empty($title) && $title !== $filename) {
            $suggestions[] = [
                'source' => 'title',
                'text' => $this->clean_text($title),
                'confidence' => 'high',
            ];
        }

        // Suggestion 2: Based on filename
        $filename_cleaned = $this->clean_filename($filename);
        if (!empty($filename_cleaned)) {
            $suggestions[] = [
                'source' => 'filename',
                'text' => $filename_cleaned,
                'confidence' => 'medium',
            ];
        }

        // Suggestion 3: Based on caption
        if (!empty($caption)) {
            $suggestions[] = [
                'source' => 'caption',
                'text' => $this->clean_text($caption),
                'confidence' => 'high',
            ];
        }

        // Suggestion 4: Based on description
        if (!empty($description)) {
            $suggestions[] = [
                'source' => 'description',
                'text' => wp_trim_words($this->clean_text($description), 15),
                'confidence' => 'medium',
            ];
        }

        // Suggestion 5: Based on context
        if (!empty($context)) {
            $suggestions[] = [
                'source' => 'context',
                'text' => $this->generate_contextual_alt($filename_cleaned, $context),
                'confidence' => 'medium',
            ];
        }

        // Remove duplicates
        $suggestions = $this->deduplicate_suggestions($suggestions);

        // Truncate to recommended length
        foreach ($suggestions as &$suggestion) {
            if (mb_strlen($suggestion['text']) > 125) {
                $suggestion['text'] = mb_substr($suggestion['text'], 0, 125);
                $suggestion['truncated'] = true;
            }
        }

        return $this->success([
            'attachment_id' => $attachment_id,
            'filename' => $filename,
            'current_alt_text' => $current_alt,
            'needs_alt_text' => empty($current_alt),
            'suggestions' => $suggestions,
            'recommendation' => !empty($suggestions) ? $suggestions[0]['text'] : '',
        ]);
    }

    /**
     * Clean filename for alt text
     */
    private function clean_filename($filename) {
        // Remove extension
        $filename = preg_replace('/\.[^.]+$/', '', $filename);

        // Replace separators with spaces
        $filename = str_replace(['-', '_', '.'], ' ', $filename);

        // Remove numbers if they're just IDs
        $filename = preg_replace('/\b\d{4,}\b/', '', $filename);

        // Clean up whitespace
        $filename = trim(preg_replace('/\s+/', ' ', $filename));

        // Capitalize words
        $filename = ucwords(strtolower($filename));

        return $filename;
    }

    /**
     * Clean text for alt text
     */
    private function clean_text($text) {
        // Strip HTML tags
        $text = wp_strip_all_tags($text);

        // Clean up whitespace
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return $text;
    }

    /**
     * Generate contextual alt text
     */
    private function generate_contextual_alt($filename, $context) {
        $context_words = explode(' ', strtolower($context));
        $filename_words = explode(' ', strtolower($filename));

        // Find common words (simple relevance check)
        $common = array_intersect($context_words, $filename_words);

        if (!empty($common)) {
            return $filename . ' for ' . wp_trim_words($context, 8);
        }

        return $filename . ' related to ' . wp_trim_words($context, 8);
    }

    /**
     * Remove duplicate suggestions
     */
    private function deduplicate_suggestions($suggestions) {
        $seen = [];
        $unique = [];

        foreach ($suggestions as $suggestion) {
            $normalized = strtolower(trim($suggestion['text']));
            if (!in_array($normalized, $seen)) {
                $seen[] = $normalized;
                $unique[] = $suggestion;
            }
        }

        return $unique;
    }
}

/**
 * Validate Alt Text Tool
 *
 * Validates alt text quality and provides improvement suggestions
 */
class MCP_Tool_Validate_Alt_Text extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_validate_alt_text';
    }

    public function get_description() {
        return 'Validate alt text quality and get specific improvement suggestions based on accessibility and SEO best practices';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'attachment_id' => [
                    'type' => 'integer',
                    'description' => 'Attachment ID to validate',
                ],
            ],
            'required' => ['attachment_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('upload_files');

        $attachment_id = intval($params['attachment_id']);

        // Verify attachment exists
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            throw new Exception(sprintf("Attachment not found: %d", $attachment_id));
        }

        $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $filename = basename($attachment->guid);

        // Run validation checks
        $issues = [];
        $warnings = [];
        $passed = [];
        $score = 100;

        // Check 1: Alt text exists
        if (empty($alt_text)) {
            $issues[] = [
                'severity' => 'error',
                'check' => 'alt_text_exists',
                'message' => 'Alt text is missing',
                'recommendation' => 'Add descriptive alt text that describes the image content and purpose',
            ];
            $score -= 100;
        } else {
            $passed[] = ['check' => 'alt_text_exists', 'message' => 'Alt text is present'];

            $alt_length = mb_strlen($alt_text);

            // Check 2: Length
            if ($alt_length < 10) {
                $issues[] = [
                    'severity' => 'error',
                    'check' => 'length_too_short',
                    'message' => "Alt text is too short ({$alt_length} characters)",
                    'recommendation' => 'Expand alt text to be more descriptive (10-125 characters recommended)',
                ];
                $score -= 30;
            } elseif ($alt_length > 125) {
                $warnings[] = [
                    'severity' => 'warning',
                    'check' => 'length_too_long',
                    'message' => "Alt text is too long ({$alt_length} characters)",
                    'recommendation' => 'Shorten alt text for better readability (125 characters max recommended)',
                ];
                $score -= 10;
            } else {
                $passed[] = ['check' => 'length_appropriate', 'message' => 'Alt text length is appropriate'];
            }

            // Check 3: Not just filename
            if (strtolower($alt_text) === strtolower(pathinfo($filename, PATHINFO_FILENAME))) {
                $warnings[] = [
                    'severity' => 'warning',
                    'check' => 'is_filename',
                    'message' => 'Alt text appears to be just the filename',
                    'recommendation' => 'Replace with descriptive text about the image content',
                ];
                $score -= 20;
            } else {
                $passed[] = ['check' => 'not_filename', 'message' => 'Alt text is not just the filename'];
            }

            // Check 4: Starts with redundant phrases
            $redundant_starts = ['image of', 'picture of', 'photo of', 'graphic of', 'icon of'];
            foreach ($redundant_starts as $phrase) {
                if (stripos($alt_text, $phrase) === 0) {
                    $warnings[] = [
                        'severity' => 'warning',
                        'check' => 'redundant_phrase',
                        'message' => "Alt text starts with redundant phrase: \"{$phrase}\"",
                        'recommendation' => 'Remove redundant phrases - screen readers already announce it\'s an image',
                    ];
                    $score -= 10;
                    break;
                }
            }

            // Check 5: Contains special characters that screen readers struggle with
            if (preg_match('/[<>{}[\]\\|]/', $alt_text)) {
                $warnings[] = [
                    'severity' => 'warning',
                    'check' => 'special_characters',
                    'message' => 'Alt text contains special characters that may cause issues',
                    'recommendation' => 'Remove special characters like <, >, {, }, [, ], \\, |',
                ];
                $score -= 5;
            }

            // Check 6: All caps
            if ($alt_text === strtoupper($alt_text) && $alt_length > 5) {
                $warnings[] = [
                    'severity' => 'warning',
                    'check' => 'all_caps',
                    'message' => 'Alt text is in all caps',
                    'recommendation' => 'Use sentence case for better readability',
                ];
                $score -= 10;
            }
        }

        // Determine overall quality
        $quality = 'excellent';
        if ($score < 50) {
            $quality = 'poor';
        } elseif ($score < 70) {
            $quality = 'needs_improvement';
        } elseif ($score < 90) {
            $quality = 'good';
        }

        return $this->success([
            'attachment_id' => $attachment_id,
            'filename' => $filename,
            'alt_text' => $alt_text,
            'alt_length' => mb_strlen($alt_text),
            'quality' => $quality,
            'score' => max($score, 0),
            'issues' => $issues,
            'warnings' => $warnings,
            'passed' => $passed,
            'total_checks' => count($issues) + count($warnings) + count($passed),
        ]);
    }
}
