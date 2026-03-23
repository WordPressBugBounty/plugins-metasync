<?php
/**
 * Metasync_Media_Library_List_Table
 * WP_List_Table for displaying media library images with optimization status.
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Metasync_Media_Library_List_Table extends WP_List_Table {

    private const PER_PAGE       = 20;
    private const PAGED_PARAM    = 'paged_media';
    private const ORDERBY_PARAM  = 'orderby_media';
    private const ORDER_PARAM    = 'order_media';

    /**
     * Resolved attachment-to-post relationships for the current page.
     * Keys are attachment IDs, values are arrays of post IDs.
     *
     * @var array<int, int[]>
     */
    private array $attachment_posts = [];

    public function __construct() {
        parent::__construct([
            'singular' => 'image',
            'plural'   => 'images',
            'ajax'     => false,
        ]);
    }

    /**
     * Get table columns.
     */
    public function get_columns(): array {
        return [
            'cb'      => '<input type="checkbox" />',
            'image'   => __('Image', 'metasync'),
            'post'    => __('Post', 'metasync'),
            'status'  => __('Status', 'metasync'),
            'actions' => __('Actions', 'metasync'),
        ];
    }

    /**
     * Get sortable columns.
     */
    protected function get_sortable_columns(): array {
        return [
            'image' => ['title', false],
        ];
    }

    /**
     * Checkbox column for bulk actions.
     */
    protected function column_cb($item): string {
        return sprintf(
            '<input type="checkbox" name="image_ids[]" value="%d" />',
            $item->ID
        );
    }

    /**
     * Image column: thumbnail + filename + URL with copy button.
     */
    protected function column_image($item): string {
        $thumb = wp_get_attachment_image($item->ID, [50, 50], true, ['style' => 'border-radius:4px;']);
        $url   = wp_get_attachment_url($item->ID);
        $file  = basename(get_attached_file($item->ID) ?: '');

        return sprintf(
            '<div class="metasync-image-cell">
                <div class="metasync-image-thumb">%s</div>
                <div class="metasync-image-info">
                    <strong class="metasync-image-filename">%s</strong>
                    <span class="metasync-image-url">
                        <a href="%s" target="_blank" title="%s">%s</a>
                        <button type="button" class="metasync-copy-btn" data-url="%s" title="%s">
                            <span class="dashicons dashicons-clipboard"></span>
                        </button>
                    </span>
                </div>
            </div>',
            $thumb,
            esc_html($file),
            esc_url($url),
            esc_attr($url),
            esc_html($this->truncate_url($url)),
            esc_attr($url),
            esc_attr__('Copy URL', 'metasync')
        );
    }

    /**
     * Post column: linked posts or "Unattached".
     *
     * Shows all posts that use this attachment (via post_parent, featured image, or content embedding).
     */
    protected function column_post($item): string {
        $post_ids = $this->attachment_posts[$item->ID] ?? [];

        if (empty($post_ids)) {
            return '<span class="metasync-text-muted">' . esc_html__('Unattached', 'metasync') . '</span>';
        }

        $links = [];
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                continue;
            }

            $permalink = get_permalink($post->ID);
            $title     = $post->post_title ?: __('(no title)', 'metasync');

            $links[] = sprintf(
                '<div class="metasync-post-link-row">
                    <a href="%s" target="_blank" title="%s">%s</a>
                    <button type="button" class="metasync-copy-btn" data-url="%s" title="%s">
                        <span class="dashicons dashicons-clipboard"></span>
                    </button>
                </div>',
                esc_url($permalink),
                esc_attr($title),
                esc_html($this->truncate_text($title, 40)),
                esc_attr($permalink),
                esc_attr__('Copy post URL', 'metasync')
            );
        }

        if (empty($links)) {
            return '<span class="metasync-text-muted">' . esc_html__('Unattached', 'metasync') . '</span>';
        }

        return implode('', $links);
    }

    /**
     * Status column: optimized badge with savings or unoptimized.
     */
    protected function column_status($item): string {
        return self::render_status_html($item->ID);
    }

    /**
     * Render status HTML for a given attachment.
     * Reusable by AJAX handlers (DRY).
     */
    public static function render_status_html(int $attachment_id): string {
        $format = get_post_meta($attachment_id, '_metasync_converted_format', true);

        if (!$format) {
            $file = get_attached_file($attachment_id);
            $size = ($file && file_exists($file)) ? size_format((int) filesize($file), 1) : '';
            $size_html = $size ? sprintf(
                '<div class="metasync-size-info"><span class="metasync-size-detail">%s</span></div>',
                esc_html($size)
            ) : '';

            return sprintf(
                '<span class="metasync-status-badge metasync-status-unoptimized">%s</span>%s',
                esc_html__('Unoptimized', 'metasync'),
                $size_html
            );
        }

        $badge = sprintf(
            '<span class="metasync-status-badge metasync-status-optimized">%s (%s)</span>',
            esc_html__('Optimized', 'metasync'),
            esc_html(strtoupper($format))
        );

        $savings = self::get_size_savings($attachment_id);
        if (!$savings) {
            return $badge;
        }

        return $badge . sprintf(
            '<div class="metasync-size-savings">'
            . '<span class="metasync-savings-pct">&darr; %s%%</span>'
            . '<span class="metasync-savings-detail">%s &rarr; %s</span>'
            . '</div>',
            esc_html($savings['percentage']),
            esc_html($savings['original_human']),
            esc_html($savings['converted_human'])
        );
    }

    /**
     * Calculate size savings for an optimized attachment.
     */
    private static function get_size_savings(int $attachment_id): ?array { // phpcs:ignore WordPress.NamingConventions
        $file   = get_attached_file($attachment_id);
        $format = get_post_meta($attachment_id, '_metasync_converted_format', true);

        if (!$file || !$format) {
            return null;
        }

        // Original size: prefer stored meta, fall back to reading original file from disk
        $original_size = (int) get_post_meta($attachment_id, '_metasync_original_filesize', true);
        if (!$original_size && file_exists($file)) {
            $original_size = (int) filesize($file);
            // Backfill meta for future lookups
            if ($original_size) {
                update_post_meta($attachment_id, '_metasync_original_filesize', $original_size);
            }
        }

        if (!$original_size) {
            return null;
        }

        $ext            = $format === 'avif' ? '.avif' : '.webp';
        $converted_path = preg_replace('/\.(jpe?g|png)$/i', $ext, $file);
        $converted_size = ($converted_path && file_exists($converted_path)) ? (int) filesize($converted_path) : 0;

        if (!$converted_size) {
            return null;
        }

        return [
            'percentage'      => max(0, (int) round((($original_size - $converted_size) / $original_size) * 100)),
            'original_human'  => size_format($original_size, 1),
            'converted_human' => size_format($converted_size, 1),
        ];
    }

    /**
     * Actions column: optimize or revert button.
     */
    protected function column_actions($item): string {
        $format = get_post_meta($item->ID, '_metasync_converted_format', true);

        if ($format) {
            return sprintf(
                '<button type="button" class="button button-small metasync-revert-btn" data-id="%d">
                    <span class="dashicons dashicons-undo" style="margin-top:3px;"></span> %s
                </button>',
                $item->ID,
                esc_html__('Revert', 'metasync')
            );
        }

        return sprintf(
            '<button type="button" class="button button-small button-primary metasync-optimize-btn" data-id="%d">
                <span class="dashicons dashicons-performance" style="margin-top:3px;"></span> %s
            </button>',
            $item->ID,
            esc_html__('Optimize', 'metasync')
        );
    }

    /**
     * Default column handler.
     */
    protected function column_default($item, $column_name): string {
        return '';
    }

    /**
     * Bulk actions dropdown.
     */
    protected function get_bulk_actions(): array {
        return [
            'bulk_optimize' => __('Optimize Selected', 'metasync'),
        ];
    }

    /**
     * Extra controls: status filter dropdown.
     */
    protected function extra_tablenav($which): void {
        if ($which !== 'top') {
            return;
        }

        $current_status = isset($_REQUEST['status_filter']) ? sanitize_text_field($_REQUEST['status_filter']) : '';
        ?>
        <div class="alignleft actions">
            <select name="status_filter">
                <option value=""><?php esc_html_e('All Images', 'metasync'); ?></option>
                <option value="optimized" <?php selected($current_status, 'optimized'); ?>>
                    <?php esc_html_e('Optimized', 'metasync'); ?>
                </option>
                <option value="unoptimized" <?php selected($current_status, 'unoptimized'); ?>>
                    <?php esc_html_e('Unoptimized', 'metasync'); ?>
                </option>
            </select>
            <?php submit_button(__('Filter', 'metasync'), '', 'filter_action', false); ?>
        </div>
        <?php
    }

    /**
     * Prepare items for display.
     */
    public function prepare_items(): void {
        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];

        $current_page = $this->get_pagenum();
        $filters      = $this->get_filters();

        $query_args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png'],
            'posts_per_page' => self::PER_PAGE,
            'paged'          => $current_page,
            'orderby'        => $filters['orderby'],
            'order'          => $filters['order'],
        ];

        if (!empty($filters['search'])) {
            $query_args['s'] = $filters['search'];
        }

        if ($filters['status'] === 'optimized') {
            $query_args['meta_query'] = [
                ['key' => '_metasync_converted_format', 'compare' => 'EXISTS'],
            ];
        } elseif ($filters['status'] === 'unoptimized') {
            $query_args['meta_query'] = [
                ['key' => '_metasync_converted_format', 'compare' => 'NOT EXISTS'],
            ];
        }

        $query = new WP_Query($query_args);

        $this->items = $query->posts;
        $this->resolve_attachment_posts();

        $this->set_pagination_args([
            'total_items' => $query->found_posts,
            'per_page'    => self::PER_PAGE,
            'total_pages' => $query->max_num_pages,
        ]);
    }

    /**
     * Override get_pagenum to use custom paged param.
     */
    public function get_pagenum(): int {
        $pagenum = isset($_REQUEST[self::PAGED_PARAM]) ? absint($_REQUEST[self::PAGED_PARAM]) : 0;

        if (isset($this->_pagination_args['total_pages']) && $pagenum > $this->_pagination_args['total_pages']) {
            $pagenum = $this->_pagination_args['total_pages'];
        }

        return max(1, $pagenum);
    }

    /**
     * Override pagination to use custom paged param and preserve tab.
     */
    protected function pagination($which): void {
        if (empty($this->_pagination_args)) {
            return;
        }

        $total_items = (int) $this->_pagination_args['total_items'];
        $total_pages = (int) $this->_pagination_args['total_pages'];
        $current     = $this->get_pagenum();

        if ($total_pages <= 1) {
            return;
        }

        $output = '<span class="displaying-num">' . sprintf(
            _n('%s item', '%s items', $total_items, 'metasync'),
            number_format_i18n($total_items)
        ) . '</span>';

        $removable_args = [
            'paged', 'paged_redir', 'paged_404',
            '_wpnonce', '_wp_http_referer',
            'action', 'action2',
        ];

        $current_url = set_url_scheme('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
        $current_url = remove_query_arg($removable_args, $current_url);
        $current_url = add_query_arg('tab', 'image-library', $current_url);

        // First page
        if ($current === 1) {
            $first_link = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>';
        } else {
            $first_link = sprintf(
                '<a class="first-page button" href="%s"><span aria-hidden="true">%s</span></a>',
                esc_url(add_query_arg(self::PAGED_PARAM, 1, $current_url)),
                '&laquo;'
            );
        }

        // Prev page
        if ($current === 1) {
            $prev_link = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>';
        } else {
            $prev_link = sprintf(
                '<a class="prev-page button" href="%s"><span aria-hidden="true">%s</span></a>',
                esc_url(add_query_arg(self::PAGED_PARAM, max(1, $current - 1), $current_url)),
                '&lsaquo;'
            );
        }

        // Page input
        $html_current_page = sprintf(
            '<input class="current-page" id="current-page-selector" type="text" name="%s" value="%s" size="%d" aria-describedby="table-paging" />',
            self::PAGED_PARAM,
            $current,
            strlen($total_pages)
        );

        $html_total_pages = sprintf('<span class="total-pages">%s</span>', number_format_i18n($total_pages));

        // Next page
        if ($current >= $total_pages) {
            $next_link = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
        } else {
            $next_link = sprintf(
                '<a class="next-page button" href="%s"><span aria-hidden="true">%s</span></a>',
                esc_url(add_query_arg(self::PAGED_PARAM, min($total_pages, $current + 1), $current_url)),
                '&rsaquo;'
            );
        }

        // Last page
        if ($current >= $total_pages) {
            $last_link = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
        } else {
            $last_link = sprintf(
                '<a class="last-page button" href="%s"><span aria-hidden="true">%s</span></a>',
                esc_url(add_query_arg(self::PAGED_PARAM, $total_pages, $current_url)),
                '&raquo;'
            );
        }

        $pagination_links_class = 'pagination-links';
        $output .= "\n<span class='$pagination_links_class'>" .
            $first_link . $prev_link .
            '<span class="paging-input">' .
            sprintf(
                _x('%1$s of %2$s', 'paging', 'metasync'),
                $html_current_page,
                $html_total_pages
            ) .
            '</span>' .
            $next_link . $last_link .
            '</span>';

        echo "<div class='tablenav-pages'>$output</div>";
    }

    /**
     * Override print_column_headers for custom sort params.
     */
    public function print_column_headers($with_id = true): void {
        list($columns, $hidden, $sortable, $primary) = $this->get_column_info();

        $current_url = set_url_scheme('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
        $current_url = remove_query_arg(['paged', self::PAGED_PARAM], $current_url);

        $current_orderby = isset($_GET[self::ORDERBY_PARAM]) ? sanitize_text_field($_GET[self::ORDERBY_PARAM]) : '';
        $current_order   = isset($_GET[self::ORDER_PARAM]) ? sanitize_text_field($_GET[self::ORDER_PARAM]) : 'asc';

        foreach ($columns as $column_key => $column_display_name) {
            $class = ['manage-column', "column-$column_key"];

            if (in_array($column_key, $hidden, true)) {
                $class[] = 'hidden';
            }

            if ($column_key === 'cb') {
                $class[] = 'check-column';
            }

            $attr = '';

            if (isset($sortable[$column_key])) {
                list($orderby, $desc_first) = $sortable[$column_key];

                if ($current_orderby === $orderby) {
                    $order   = ($current_order === 'asc') ? 'desc' : 'asc';
                    $class[] = 'sorted';
                    $class[] = $current_order;
                } else {
                    $order   = $desc_first ? 'desc' : 'asc';
                    $class[] = 'sortable';
                    $class[] = $desc_first ? 'asc' : 'desc';
                }

                $sort_url = add_query_arg([
                    self::ORDERBY_PARAM => $orderby,
                    self::ORDER_PARAM   => $order,
                ], $current_url);

                $column_display_name = sprintf(
                    '<a href="%s"><span>%s</span><span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span></a>',
                    esc_url($sort_url),
                    $column_display_name
                );
            }

            $tag = ($column_key === 'cb') ? 'td' : 'th';
            $id  = $with_id ? "id='$column_key'" : '';

            echo "<$tag $id class='" . implode(' ', $class) . "' $attr>$column_display_name</$tag>";
        }
    }

    /**
     * Batch-resolve all post relationships for the current page of attachments.
     *
     * Checks three sources:
     * 1. post_parent (direct attachment parent)
     * 2. _thumbnail_id post meta (featured image)
     * 3. Post content references (wp-image-{ID} class used by WordPress editor)
     */
    private function resolve_attachment_posts(): void {
        $this->attachment_posts = [];

        if (empty($this->items)) {
            return;
        }

        global $wpdb;

        $ids = wp_list_pluck($this->items, 'ID');
        $id_set = implode(',', array_map('intval', $ids));
        $valid_statuses = "'publish','draft','private','pending','future'";

        // 1. post_parent relationships
        foreach ($this->items as $item) {
            if (!empty($item->post_parent)) {
                $parent = get_post($item->post_parent);
                if ($parent && $parent->post_type !== 'attachment') {
                    $this->attachment_posts[$item->ID][] = (int) $item->post_parent;
                }
            }
        }

        // 2. Featured image relationships (_thumbnail_id meta)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $featured_rows = $wpdb->get_results(
            "SELECT CAST(pm.meta_value AS UNSIGNED) AS attachment_id, pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_thumbnail_id'
               AND pm.meta_value IN ({$id_set})
               AND p.post_status IN ({$valid_statuses})
               AND p.post_type NOT IN ('attachment','revision')"
        );

        foreach ($featured_rows as $row) {
            $att_id  = (int) $row->attachment_id;
            $post_id = (int) $row->post_id;
            if (!isset($this->attachment_posts[$att_id]) || !in_array($post_id, $this->attachment_posts[$att_id], true)) {
                $this->attachment_posts[$att_id][] = $post_id;
            }
        }

        // 3. Content references: wp-image-{ID} class AND image filename in URLs
        // Build a lookup of search tokens per attachment ID.
        $search_map = []; // attachment_id => ['wp-image-123', 'filename.jpg']
        $like_clauses = [];

        foreach ($this->items as $item) {
            $id      = (int) $item->ID;
            $tokens  = [];

            // wp-image-{ID} class (WordPress editor / Gutenberg blocks)
            $tokens[] = 'wp-image-' . $id;

            // Image filename from the attachment file path
            $file = get_attached_file($id);
            if ($file) {
                $basename = basename($file);
                if ($basename) {
                    $tokens[] = $basename;
                }
            }

            $search_map[$id] = $tokens;

            foreach ($tokens as $token) {
                $escaped = $wpdb->esc_like($token);
                $like_clauses[] = $wpdb->prepare('p.post_content LIKE %s', '%' . $escaped . '%');
            }
        }

        if (!empty($like_clauses)) {
            // Deduplicate clauses to avoid redundant OR conditions
            $like_clauses = array_unique($like_clauses);
            $like_sql     = implode(' OR ', $like_clauses);

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $content_rows = $wpdb->get_results(
                "SELECT p.ID AS post_id, p.post_content
                 FROM {$wpdb->posts} p
                 WHERE ({$like_sql})
                   AND p.post_type NOT IN ('attachment','revision')
                   AND p.post_status IN ({$valid_statuses})"
            );

            foreach ($content_rows as $row) {
                $post_id = (int) $row->post_id;
                foreach ($search_map as $att_id => $tokens) {
                    foreach ($tokens as $token) {
                        if (strpos($row->post_content, $token) !== false) {
                            if (!isset($this->attachment_posts[$att_id]) || !in_array($post_id, $this->attachment_posts[$att_id], true)) {
                                $this->attachment_posts[$att_id][] = $post_id;
                            }
                            break; // One match is enough for this attachment+post pair
                        }
                    }
                }
            }
        }
    }

    /**
     * Get sanitized filter values from request.
     */
    private function get_filters(): array {
        return [
            'search'  => isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '',
            'status'  => isset($_REQUEST['status_filter']) ? sanitize_text_field($_REQUEST['status_filter']) : '',
            'orderby' => isset($_REQUEST[self::ORDERBY_PARAM]) ? sanitize_sql_orderby($_REQUEST[self::ORDERBY_PARAM]) ?: 'date' : 'date',
            'order'   => isset($_REQUEST[self::ORDER_PARAM]) && strtolower($_REQUEST[self::ORDER_PARAM]) === 'asc' ? 'ASC' : 'DESC',
        ];
    }

    /**
     * Get image optimization statistics.
     */
    public static function get_stats(): array {
        $total = (new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]))->found_posts;

        $optimized = (new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                ['key' => '_metasync_converted_format', 'compare' => 'EXISTS'],
            ],
        ]))->found_posts;

        return [
            'total'       => $total,
            'optimized'   => $optimized,
            'unoptimized' => $total - $optimized,
            'percentage'  => $total > 0 ? round(($optimized / $total) * 100) : 0,
        ];
    }

    /**
     * Truncate a URL for display.
     */
    private function truncate_url(string $url, int $max = 50): string {
        if (strlen($url) <= $max) {
            return $url;
        }
        return substr($url, 0, $max - 3) . '...';
    }

    /**
     * Truncate text for display.
     */
    private function truncate_text(string $text, int $max = 40): string {
        if (strlen($text) <= $max) {
            return $text;
        }
        return substr($text, 0, $max - 3) . '...';
    }
}
