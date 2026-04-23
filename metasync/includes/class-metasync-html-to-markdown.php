<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * HTML to Markdown converter utility.
 *
 * Reusable utility class for converting WordPress/HTML content into
 * clean markdown. Used by the LLMs.txt generator and the
 * wordpress_get_post_markdown MCP tool.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */
class Metasync_Html_To_Markdown
{
    /**
     * Convert an HTML string to markdown.
     *
     * @param string $html Raw HTML.
     * @return string Markdown output.
     */
    public static function convert($html)
    {
        if (!is_string($html) || $html === '') {
            return '';
        }

        // Normalise whitespace and strip dangerous / noisy elements first.
        $html = preg_replace('/<!--(.|\s)*?-->/', '', $html);
        $html = preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $html);
        $html = preg_replace('#<style\b[^>]*>(.*?)</style>#is', '', $html);
        $html = preg_replace('#<noscript\b[^>]*>(.*?)</noscript>#is', '', $html);

        // Convert YouTube/Vimeo iframes to markdown links (must run before generic iframe strip).
        $html = preg_replace_callback(
            '#<iframe\b[^>]*src=["\']([^"\']+)["\'][^>]*(?:title=["\']([^"\']*)["\'])?[^>]*>.*?</iframe>#is',
            function ($m) {
                $src = $m[1];
                $title = isset($m[2]) && $m[2] !== '' ? $m[2] : 'Video';
                if (preg_match('#(youtube\.com|youtu\.be|vimeo\.com)#i', $src)) {
                    return "\n[" . $title . '](' . $src . ")\n";
                }
                return '';
            },
            $html
        );

        // Convert <br> to newline and <hr> to ---.
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#<hr\s*/?>#i', "\n\n---\n\n", $html);

        // Headings h1-h6.
        for ($i = 1; $i <= 6; $i++) {
            $hashes = str_repeat('#', $i);
            $html = preg_replace_callback(
                '#<h' . $i . '\b[^>]*>(.*?)</h' . $i . '>#is',
                function ($m) use ($hashes) {
                    $text = self::strip_tags_preserve(trim($m[1]));
                    return "\n\n" . $hashes . ' ' . $text . "\n\n";
                },
                $html
            );
        }

        // Bold.
        $html = preg_replace_callback(
            '#<(?:strong|b)\b[^>]*>(.*?)</(?:strong|b)>#is',
            function ($m) { return '**' . trim(self::strip_tags_preserve($m[1])) . '**'; },
            $html
        );

        // Italic.
        $html = preg_replace_callback(
            '#<(?:em|i)\b[^>]*>(.*?)</(?:em|i)>#is',
            function ($m) { return '*' . trim(self::strip_tags_preserve($m[1])) . '*'; },
            $html
        );

        // Images -> ![alt](src).
        $html = preg_replace_callback(
            '#<img\b([^>]*)/?>#i',
            function ($m) {
                $attrs = $m[1];
                $alt = '';
                $src = '';
                if (preg_match('#\balt=["\']([^"\']*)["\']#i', $attrs, $a)) {
                    $alt = $a[1];
                }
                if (preg_match('#\bsrc=["\']([^"\']+)["\']#i', $attrs, $s)) {
                    $src = $s[1];
                }
                if ($src === '') {
                    return '';
                }
                return '![' . $alt . '](' . $src . ')';
            },
            $html
        );

        // Links -> [text](url).
        $html = preg_replace_callback(
            '#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
            function ($m) {
                $href = $m[1];
                $text = trim(self::strip_tags_preserve($m[2]));
                if ($text === '') {
                    $text = $href;
                }
                return '[' . $text . '](' . $href . ')';
            },
            $html
        );

        // Fenced code blocks.
        $html = preg_replace_callback(
            '#<pre\b[^>]*>\s*<code\b[^>]*>(.*?)</code>\s*</pre>#is',
            function ($m) {
                $code = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return "\n\n```\n" . trim($code) . "\n```\n\n";
            },
            $html
        );
        $html = preg_replace_callback(
            '#<pre\b[^>]*>(.*?)</pre>#is',
            function ($m) {
                $code = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return "\n\n```\n" . trim($code) . "\n```\n\n";
            },
            $html
        );

        // Inline code.
        $html = preg_replace_callback(
            '#<code\b[^>]*>(.*?)</code>#is',
            function ($m) {
                $code = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return '`' . $code . '`';
            },
            $html
        );

        // Blockquotes.
        $html = preg_replace_callback(
            '#<blockquote\b[^>]*>(.*?)</blockquote>#is',
            function ($m) {
                $inner = trim(self::strip_tags_preserve($m[1]));
                $lines = preg_split('/\r?\n/', $inner);
                $lines = array_map(function ($l) { return '> ' . ltrim($l); }, $lines);
                return "\n\n" . implode("\n", $lines) . "\n\n";
            },
            $html
        );

        // Tables.
        $html = preg_replace_callback(
            '#<table\b[^>]*>(.*?)</table>#is',
            [self::class, 'convert_table'],
            $html
        );

        // Lists (nested supported via recursion).
        $html = self::convert_lists($html);

        // Paragraphs.
        $html = preg_replace_callback(
            '#<p\b[^>]*>(.*?)</p>#is',
            function ($m) {
                $text = trim(self::strip_tags_preserve($m[1]));
                if ($text === '') {
                    return '';
                }
                return "\n\n" . $text . "\n\n";
            },
            $html
        );

        // Strip any remaining tags but keep the inner text.
        $html = self::strip_tags_preserve($html);

        // Decode entities after stripping tags.
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse excessive blank lines.
        $html = preg_replace("/\n{3,}/", "\n\n", $html);

        return trim($html);
    }

    /**
     * Convert a WordPress post to markdown.
     *
     * Runs `the_content` filter and do_shortcode() first so page-builder
     * content (Elementor, Divi, Beaver Builder, etc.) is rendered before
     * conversion.
     *
     * @param int   $post_id Post ID.
     * @param array $options {
     *     Optional. {
     *       @type bool $include_frontmatter     Prepend YAML frontmatter. Default true.
     *       @type bool $include_featured_image  Prepend featured image as markdown. Default true.
     *     }
     * }
     * @return string Markdown output (empty string if post not found).
     */
    public static function convert_post($post_id, $options = [])
    {
        $post = get_post(absint($post_id));
        if (!$post) {
            return '';
        }

        $options = wp_parse_args($options, [
            'include_frontmatter'    => true,
            'include_featured_image' => true,
        ]);

        $raw_content = $post->post_content;
        $filtered = apply_filters('the_content', $raw_content);
        $filtered = do_shortcode($filtered);

        $markdown = self::convert($filtered);

        $prefix = '';

        if (!empty($options['include_frontmatter'])) {
            $author_name = '';
            if (!empty($post->post_author)) {
                $author = get_userdata($post->post_author);
                if ($author && !empty($author->display_name)) {
                    $author_name = $author->display_name;
                }
            }

            $frontmatter  = "---\n";
            $frontmatter .= 'title: ' . self::yaml_escape($post->post_title) . "\n";
            $frontmatter .= 'slug: ' . self::yaml_escape($post->post_name) . "\n";
            $frontmatter .= 'date: ' . self::yaml_escape($post->post_date) . "\n";
            if ($author_name !== '') {
                $frontmatter .= 'author: ' . self::yaml_escape($author_name) . "\n";
            }
            $frontmatter .= "---\n\n";
            $prefix .= $frontmatter;
        }

        if (!empty($options['include_featured_image'])) {
            $thumb_id = get_post_thumbnail_id($post_id);
            if ($thumb_id) {
                $src = wp_get_attachment_url($thumb_id);
                if ($src) {
                    $alt = get_post_meta($thumb_id, '_wp_attachment_image_alt', true);
                    if (!is_string($alt)) {
                        $alt = '';
                    }
                    if ($alt === '') {
                        $alt = $post->post_title;
                    }
                    $prefix .= '![' . $alt . '](' . $src . ")\n\n";
                }
            }
        }

        return $prefix . $markdown;
    }

    /**
     * Escape a string for safe use as a YAML scalar value.
     *
     * @param string $value
     * @return string
     */
    private static function yaml_escape($value)
    {
        $value = (string) $value;
        // Wrap in double quotes and escape backslashes and quotes.
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        return '"' . $escaped . '"';
    }

    /**
     * Strip HTML tags but leave a usable inline form (decodes entities later).
     *
     * @param string $html
     * @return string
     */
    private static function strip_tags_preserve($html)
    {
        // Replace block-ish containers with newlines so stripping doesn't mash content.
        $html = preg_replace('#</(?:div|section|article|aside|header|footer|main|nav|figure|figcaption)>#i', "\n", $html);
        $html = preg_replace('#<(?:div|section|article|aside|header|footer|main|nav|figure|figcaption)\b[^>]*>#i', '', $html);
        // Strip remaining tags.
        return strip_tags($html);
    }

    /**
     * Convert a <table> block into a pipe-style markdown table.
     *
     * @param array $matches Regex matches from convert(); index 1 is inner HTML.
     * @return string
     */
    private static function convert_table($matches)
    {
        $inner = $matches[1];

        // Flatten any thead/tbody wrappers for easier row extraction.
        $inner = preg_replace('#</?(?:thead|tbody|tfoot)\b[^>]*>#i', '', $inner);

        if (!preg_match_all('#<tr\b[^>]*>(.*?)</tr>#is', $inner, $rows_match)) {
            return '';
        }

        $rows = [];
        $header_used = false;
        foreach ($rows_match[1] as $row_index => $row_html) {
            $cells = [];
            $is_header_row = false;
            if (preg_match_all('#<(th|td)\b[^>]*>(.*?)</\1>#is', $row_html, $cell_match, PREG_SET_ORDER)) {
                foreach ($cell_match as $cm) {
                    $cell_text = trim(self::strip_tags_preserve($cm[2]));
                    $cell_text = html_entity_decode($cell_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $cell_text = str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], $cell_text);
                    $cells[] = $cell_text;
                    if (strtolower($cm[1]) === 'th') {
                        $is_header_row = true;
                    }
                }
            }
            if (empty($cells)) {
                continue;
            }
            $rows[] = ['cells' => $cells, 'is_header' => $is_header_row];
        }

        if (empty($rows)) {
            return '';
        }

        $output = "\n\n";
        $col_count = 0;
        foreach ($rows as $row) {
            if (count($row['cells']) > $col_count) {
                $col_count = count($row['cells']);
            }
        }

        // Header row: use first header row, else synthesise from first row.
        $header_row = null;
        foreach ($rows as $idx => $row) {
            if ($row['is_header']) {
                $header_row = $row;
                unset($rows[$idx]);
                break;
            }
        }
        if ($header_row === null) {
            $header_row = array_shift($rows);
        }

        $header_cells = array_pad($header_row['cells'], $col_count, '');
        $output .= '| ' . implode(' | ', $header_cells) . " |\n";
        $output .= '|' . str_repeat(' --- |', $col_count) . "\n";

        foreach ($rows as $row) {
            $cells = array_pad($row['cells'], $col_count, '');
            $output .= '| ' . implode(' | ', $cells) . " |\n";
        }

        return $output . "\n";
    }

    /**
     * Convert <ul>/<ol> lists to markdown, preserving nesting.
     *
     * Iteratively replaces the inner-most list until no lists remain.
     *
     * @param string $html
     * @return string
     */
    private static function convert_lists($html)
    {
        // Loop until there are no lists left (handles nesting).
        $guard = 0;
        while ($guard < 50 && preg_match('#<(ul|ol)\b[^>]*>((?:(?!<ul|<ol).)*?)</\1>#is', $html, $m, PREG_OFFSET_CAPTURE)) {
            $tag = strtolower($m[1][0]);
            $inner = $m[2][0];
            $full = $m[0][0];
            $offset = $m[0][1];

            $converted = self::render_list_items($inner, $tag, 0);
            $html = substr($html, 0, $offset) . "\n\n" . $converted . "\n\n" . substr($html, $offset + strlen($full));
            $guard++;
        }

        // Clean up anything left unconverted (defensive).
        $html = preg_replace('#</?(?:ul|ol)\b[^>]*>#i', '', $html);
        $html = preg_replace('#<li\b[^>]*>#i', "- ", $html);
        $html = preg_replace('#</li>#i', "\n", $html);

        return $html;
    }

    /**
     * Render <li> items for a given list tag.
     *
     * @param string $inner Inner HTML of the list.
     * @param string $tag   'ul' or 'ol'.
     * @param int    $depth Current nesting depth.
     * @return string
     */
    private static function render_list_items($inner, $tag, $depth)
    {
        if (!preg_match_all('#<li\b[^>]*>(.*?)</li>#is', $inner, $items)) {
            return '';
        }

        $out = '';
        $indent = str_repeat('  ', max(0, $depth));
        $counter = 1;

        foreach ($items[1] as $item_html) {
            // Recursively convert nested lists inside the <li>.
            $nested_converted = $item_html;
            while (preg_match('#<(ul|ol)\b[^>]*>((?:(?!<ul|<ol).)*?)</\1>#is', $nested_converted, $m, PREG_OFFSET_CAPTURE)) {
                $inner_nested = $m[2][0];
                $inner_tag = strtolower($m[1][0]);
                $sub = self::render_list_items($inner_nested, $inner_tag, $depth + 1);
                $nested_converted = substr($nested_converted, 0, $m[0][1])
                    . "\n" . $sub
                    . substr($nested_converted, $m[0][1] + strlen($m[0][0]));
            }

            $text = trim(self::strip_tags_preserve($nested_converted));
            if ($text === '') {
                continue;
            }

            $marker = ($tag === 'ol') ? ($counter . '.') : '-';
            // The line itself.
            $lines = preg_split('/\r?\n/', $text);
            $first = array_shift($lines);
            $out .= $indent . $marker . ' ' . ltrim($first) . "\n";
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $out .= $indent . '  ' . ltrim($line) . "\n";
            }
            $counter++;
        }

        return rtrim($out, "\n");
    }
}
