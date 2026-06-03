<?php
/**
 * The XML Sitemap admin page view
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 *
 * @package    Metasync
 * @subpackage Metasync/views
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
?>

<?php $this->render_layout_open('XML Sitemap', 'xml_sitemap', 'Manage your XML sitemap settings and status.'); ?>

    <div class="metasync-sitemap-tabs">
        <ul class="metasync-tab-nav">
            <li>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-xml-sitemap&tab=general')); ?>"
                   data-tab="general">
                    <?php esc_html_e('General', 'metasync'); ?>
                </a>
            </li>
            <li>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-xml-sitemap&tab=news')); ?>"
                   data-tab="news">
                    <?php esc_html_e('News Sitemap', 'metasync'); ?>
                </a>
            </li>
            <li>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-xml-sitemap&tab=video')); ?>"
                   data-tab="video">
                    <?php esc_html_e('Video Sitemap', 'metasync'); ?>
                </a>
            </li>
        </ul>
    </div>

    <!-- General Tab -->
    <div id="metasync-sitemap-general" class="metasync-sitemap-tab-content">

        <div class="metasync-sitemap-outer">
        <div class="metasync-sitemap-container">

        <!-- Sitemap Status Card -->
        <div class="dashboard-card metasync-sitemap-status-card">
            <h2>Sitemap Status</h2>
            <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Monitor your sitemap generation and status.</p>

            <?php
            $sitemap_files = $sitemap_generator->get_sitemap_files();
            $sitemap_count = count($sitemap_files);

            // Check news/video sitemap status
            $news_sm_enabled = !empty($news_settings['enabled']);
            $video_sm_enabled = !empty($video_settings['enabled']);
            $news_sm_exists = false !== get_transient('metasync_vsm_' . md5('news-sitemap.xml'))
                || file_exists(ABSPATH . 'news-sitemap.xml');
            $video_sm_exists = false !== get_transient('metasync_vsm_' . md5('video-sitemap.xml'))
                || file_exists(ABSPATH . 'video-sitemap.xml');
            $extra_sitemap_count = ($news_sm_exists ? 1 : 0) + ($video_sm_exists ? 1 : 0);
            $total_sitemap_count = $sitemap_count + $extra_sitemap_count;

            // Count URLs in news/video sitemaps for total
            $news_url_count = 0;
            $video_url_count = 0;
            if ($news_sm_exists) {
                $news_xml = get_transient('metasync_vsm_' . md5('news-sitemap.xml'));
                if (!$news_xml && file_exists(ABSPATH . 'news-sitemap.xml')) {
                    $news_xml = file_get_contents(ABSPATH . 'news-sitemap.xml');
                }
                if ($news_xml) {
                    $news_url_count = substr_count($news_xml, '<url>');
                }
            }
            if ($video_sm_exists) {
                $video_xml = get_transient('metasync_vsm_' . md5('video-sitemap.xml'));
                if (!$video_xml && file_exists(ABSPATH . 'video-sitemap.xml')) {
                    $video_xml = file_get_contents(ABSPATH . 'video-sitemap.xml');
                }
                if ($video_xml) {
                    $video_url_count = substr_count($video_xml, '<url>');
                }
            }
            $total_url_count = $url_count + $news_url_count + $video_url_count;
            $any_sitemap_exists = $sitemap_exists || $news_sm_exists || $video_sm_exists;
            ?>

            <div class="metasync-sitemap-status-grid">
                <div class="metasync-sitemap-stat">
                    <div class="stat-icon"><span class="dashicons dashicons-media-default"></span></div>
                    <div class="stat-content">
                        <div class="stat-label">Status</div>
                        <div class="stat-value <?php echo $any_sitemap_exists ? 'status-active' : 'status-inactive'; ?>">
                            <?php
                            echo $any_sitemap_exists ? 'Generated' : 'Not Generated';
                            if ($sitemap_exists && $sitemap_generator->is_virtual_mode()): ?>
                                <span style="color: #2271b1; font-size: 12px; margin-left: 5px;">(Virtual)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="metasync-sitemap-stat">
                    <div class="stat-icon"><span class="dashicons dashicons-admin-links"></span></div>
                    <div class="stat-content">
                        <div class="stat-label">Total URLs</div>
                        <div class="stat-value"><?php echo number_format($total_url_count); ?></div>
                    </div>
                </div>

                <div class="metasync-sitemap-stat">
                    <div class="stat-icon"><span class="dashicons dashicons-list-view"></span></div>
                    <div class="stat-content">
                        <div class="stat-label">Sitemap Files</div>
                        <div class="stat-value"><?php echo $total_sitemap_count; ?></div>
                    </div>
                </div>

                <div class="metasync-sitemap-stat">
                    <div class="stat-icon"><span class="dashicons dashicons-update"></span></div>
                    <div class="stat-content">
                        <div class="stat-label">Auto-Update</div>
                        <div class="stat-value <?php echo $auto_update_enabled ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $auto_update_enabled ? 'Enabled' : 'Disabled'; ?>
                        </div>
                    </div>
                </div>

                <div class="metasync-sitemap-stat">
                    <div class="stat-icon"><span class="dashicons dashicons-clock"></span></div>
                    <div class="stat-content">
                        <div class="stat-label">Last Generated</div>
                        <div class="stat-value stat-value-small">
                            <?php
                            if ($last_generated) {
                                $time_diff = human_time_diff(strtotime($last_generated), current_time('timestamp'));
                                echo esc_html($time_diff) . ' ago';
                            } else {
                                echo 'Never';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($sitemap_exists): ?>
            <div class="metasync-sitemap-url-box">
                <strong>Sitemap Index URL:</strong>
                <a href="<?php echo esc_url($sitemap_url); ?>" target="_blank" class="metasync-sitemap-link">
                    <?php echo esc_url($sitemap_url); ?>
                    <span class="dashicons dashicons-external"></span>
                </a>
                <p style="margin: 10px 0 0 0; color: var(--dashboard-text-secondary); font-size: 13px;">
                    <em>Submit this URL to Google Search Console for indexing.</em>
                </p>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($active_sitemap_plugins)): ?>
        <!-- Info notice about other sitemap plugins -->
        <div class="dashboard-card metasync-sitemap-info-notice">
            <div class="metasync-info-notice-content">
                <span class="dashicons dashicons-info" style="font-size: 20px; color: var(--dashboard-text-secondary);"></span>
                <div>
                    <strong>Other Sitemap Plugins Detected:</strong>
                    <p style="margin: 5px 0 0 0; color: var(--dashboard-text-secondary); font-size: 14px;">
                        <?php
                        $plugin_names = array_values($active_sitemap_plugins);
                        echo esc_html(implode(', ', $plugin_names));
                        ?>
                        <?php if (count($active_sitemap_plugins) === 1): ?>
                            is active. This plugin's sitemap will be automatically disabled when you generate the sitemap below.
                        <?php else: ?>
                            are active. These plugins' sitemaps will be automatically disabled when you generate the sitemap below.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Content Settings Card -->
        <div class="dashboard-card metasync-sitemap-content-settings-card">
            <h2><?php esc_html_e('Content Settings', 'metasync'); ?></h2>
            <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">
                <?php esc_html_e('Control which content types and URLs are included in the main sitemap.', 'metasync'); ?>
            </p>

            <form method="post">
                <?php wp_nonce_field('metasync_sitemap_settings_action', 'metasync_sitemap_settings_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Post Types', 'metasync'); ?></th>
                        <td>
                            <?php
                            $all_public_pts = get_post_types(['public' => true], 'objects');
                            $general_filtered_pts = [];
                            $general_excluded = ['attachment', 'revision', 'nav_menu_item', 'elementor_library', 'ct_template', 'oxy_user_library', 'brizy-template', 'fusion_template', 'fusion_tb_section', 'ae_global_templates', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'acf-field-group', 'acf-field', 'fl-builder-template', 'fl-theme-layout'];
                            foreach ($all_public_pts as $pt) {
                                if (!in_array($pt->name, $general_excluded, true)) {
                                    $general_filtered_pts[] = $pt;
                                }
                            }
                            $sitemap_configured = !empty($sitemap_settings['_configured']);
                            $selected_sitemap_pts = !empty($sitemap_settings['post_types']) ? (array) $sitemap_settings['post_types'] : [];
                            $gen_pts_scrollable = count($general_filtered_pts) > 10;
                            if ($gen_pts_scrollable) : ?>
                                <input type="text" class="metasync-checkbox-search" placeholder="<?php esc_attr_e('Filter post types...', 'metasync'); ?>">
                            <?php endif; ?>
                            <div class="<?php echo $gen_pts_scrollable ? 'metasync-checkbox-scroll' : ''; ?>">
                                <?php foreach ($general_filtered_pts as $pt) : ?>
                                    <label>
                                        <input type="checkbox" name="sitemap_post_types[]" value="<?php echo esc_attr($pt->name); ?>"
                                            <?php checked(!$sitemap_configured || in_array($pt->name, $selected_sitemap_pts, true)); ?> />
                                        <?php echo esc_html($pt->label); ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="metasync-no-results"><?php esc_html_e('No post types match your search.', 'metasync'); ?></p>
                            </div>
                            <p class="description"><?php esc_html_e('Select which post types to include. All are included by default.', 'metasync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Categories', 'metasync'); ?>
                            <?php $gen_categories = get_categories(['hide_empty' => false]); ?>
                            <span class="metasync-checkbox-count">(<?php echo count($gen_categories); ?>)</span>
                        </th>
                        <td>
                            <?php
                            $selected_gen_cats = isset($sitemap_settings['categories']) ? (array) $sitemap_settings['categories'] : [];
                            $gen_cats_scrollable = count($gen_categories) > 10;
                            if ($gen_cats_scrollable) : ?>
                                <input type="text" class="metasync-checkbox-search" placeholder="<?php esc_attr_e('Filter categories...', 'metasync'); ?>">
                            <?php endif; ?>
                            <div class="<?php echo $gen_cats_scrollable ? 'metasync-checkbox-scroll' : ''; ?>">
                                <?php foreach ($gen_categories as $cat) : ?>
                                    <label>
                                        <input type="checkbox" name="sitemap_categories[]" value="<?php echo esc_attr($cat->term_id); ?>"
                                            <?php checked(in_array($cat->term_id, $selected_gen_cats)); ?> />
                                        <?php echo esc_html($cat->name); ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="metasync-no-results"><?php esc_html_e('No categories match your search.', 'metasync'); ?></p>
                            </div>
                            <p class="description"><?php esc_html_e('Leave empty to include all categories.', 'metasync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Tags', 'metasync'); ?>
                            <?php $gen_tags = get_tags(['hide_empty' => false]); ?>
                            <span class="metasync-checkbox-count">(<?php echo count($gen_tags); ?>)</span>
                        </th>
                        <td>
                            <?php
                            $selected_gen_tags = isset($sitemap_settings['tags']) ? (array) $sitemap_settings['tags'] : [];
                            if (!empty($gen_tags)) :
                                $gen_tags_scrollable = count($gen_tags) > 10;
                                if ($gen_tags_scrollable) : ?>
                                    <input type="text" class="metasync-checkbox-search" placeholder="<?php esc_attr_e('Filter tags...', 'metasync'); ?>">
                                <?php endif; ?>
                                <div class="<?php echo $gen_tags_scrollable ? 'metasync-checkbox-scroll' : ''; ?>">
                                    <?php foreach ($gen_tags as $tag) : ?>
                                        <label>
                                            <input type="checkbox" name="sitemap_tags[]" value="<?php echo esc_attr($tag->term_id); ?>"
                                                <?php checked(in_array($tag->term_id, $selected_gen_tags)); ?> />
                                            <?php echo esc_html($tag->name); ?>
                                        </label>
                                    <?php endforeach; ?>
                                    <p class="metasync-no-results"><?php esc_html_e('No tags match your search.', 'metasync'); ?></p>
                                </div>
                            <?php else : ?>
                                <p class="description"><?php esc_html_e('No tags found.', 'metasync'); ?></p>
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e('Leave empty to include all tags.', 'metasync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Taxonomies', 'metasync'); ?></th>
                        <td>
                            <?php
                            $all_taxonomies = get_taxonomies(['public' => true], 'objects');
                            $selected_sitemap_taxes = !empty($sitemap_settings['taxonomies']) ? (array) $sitemap_settings['taxonomies'] : [];
                            $gen_tax_scrollable = count($all_taxonomies) > 10;
                            if ($gen_tax_scrollable) : ?>
                                <input type="text" class="metasync-checkbox-search" placeholder="<?php esc_attr_e('Filter taxonomies...', 'metasync'); ?>">
                            <?php endif; ?>
                            <div class="<?php echo $gen_tax_scrollable ? 'metasync-checkbox-scroll' : ''; ?>">
                                <?php foreach ($all_taxonomies as $tax) : ?>
                                    <label>
                                        <input type="checkbox" name="sitemap_taxonomies[]" value="<?php echo esc_attr($tax->name); ?>"
                                            <?php checked(!$sitemap_configured || in_array($tax->name, $selected_sitemap_taxes, true)); ?> />
                                        <?php echo esc_html($tax->label); ?> <code><?php echo esc_html($tax->name); ?></code>
                                    </label>
                                <?php endforeach; ?>
                                <p class="metasync-no-results"><?php esc_html_e('No taxonomies match your search.', 'metasync'); ?></p>
                            </div>
                            <p class="description"><?php esc_html_e('Select which taxonomy archive pages to include. All are included by default.', 'metasync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Exclude URLs', 'metasync'); ?></th>
                        <td>
                            <textarea name="sitemap_excluded_urls" rows="5" class="large-text code" placeholder="<?php esc_attr_e("https://example.com/page-to-exclude/\nhttps://example.com/another-page/", 'metasync'); ?>"><?php echo esc_textarea($sitemap_settings['excluded_urls'] ?? ''); ?></textarea>
                            <p class="description"><?php esc_html_e('Enter one URL per line. These URLs will be excluded from the main sitemap.', 'metasync'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(esc_html__('Save & Generate Sitemap', 'metasync'), 'primary', 'save_sitemap_settings'); ?>
            </form>
        </div>

        <!-- Generate Sitemap Card -->
        <div class="dashboard-card metasync-sitemap-actions-card">
            <h2>Sitemap Actions</h2>
            <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">
                Generate or manage your XML sitemap with the controls below.
            </p>



            <div class="metasync-sitemap-actions-grid">
                <form method="post" action="" class="metasync-sitemap-action-form">
                    <?php wp_nonce_field('metasync_sitemap_action', 'metasync_sitemap_nonce'); ?>
                    <button type="submit" name="generate_sitemap" class="button button-primary button-hero metasync-sitemap-button-large">
                        <span class="dashicons dashicons-update"></span>
                        Generate Sitemaps Now
                    </button>
                    <p class="metasync-button-description">
                        <?php
                        $urls_per_sitemap = $sitemap_generator->get_urls_per_sitemap();
                        if (!empty($active_sitemap_plugins)): ?>
                            Creates sitemap files (split into <?php echo number_format($urls_per_sitemap); ?> URLs each) and disables conflicting sitemaps.
                        <?php else: ?>
                            Creates sitemap index and individual sitemap files (split into <?php echo number_format($urls_per_sitemap); ?> URLs each).
                        <?php endif; ?>
                    </p>
                </form>

                <form method="post" action="" class="metasync-sitemap-action-form">
                    <?php wp_nonce_field('metasync_sitemap_action', 'metasync_sitemap_nonce'); ?>
                    <?php if ($auto_update_enabled): ?>
                        <button type="submit" name="disable_auto_update" class="button button-secondary button-hero metasync-sitemap-button-large">
                            <span class="dashicons dashicons-no"></span>
                            Disable Auto-Update
                        </button>
                        <p class="metasync-button-description">
                            Currently enabled - sitemap updates automatically when posts change.
                        </p>
                    <?php else: ?>
                        <button type="submit" name="enable_auto_update" class="button button-secondary button-hero metasync-sitemap-button-large">
                            <span class="dashicons dashicons-yes"></span>
                            Enable Auto-Update
                        </button>
                        <p class="metasync-button-description">
                            Automatically regenerate sitemap when posts are created or updated.
                        </p>
                    <?php endif; ?>
                </form>

                <?php if ($any_sitemap_exists): ?>
                <form method="post" action="" class="metasync-sitemap-action-form" onsubmit="return confirm('Are you sure you want to delete all sitemap files? This action cannot be undone.');">
                    <?php wp_nonce_field('metasync_sitemap_action', 'metasync_sitemap_nonce'); ?>
                    <button type="submit" name="delete_sitemap" class="button button-secondary button-hero metasync-sitemap-button-large" style="border-color: #dc3232; color: #dc3232;">
                        <span class="dashicons dashicons-trash"></span>
                        Delete All Sitemaps
                    </button>
                    <p class="metasync-button-description">
                        Permanently delete all sitemap files from your server.
                    </p>
                </form>
                <?php endif; ?>

                <?php if (!empty($active_sitemap_plugins)): ?>
                <form method="post" action="" class="metasync-sitemap-action-form">
                    <?php wp_nonce_field('metasync_sitemap_action', 'metasync_sitemap_nonce'); ?>
                    <button type="submit" name="enable_other_sitemaps" class="button button-secondary button-hero metasync-sitemap-button-large" style="border-color: #00a32a; color: #00a32a;">
                        <span class="dashicons dashicons-controls-repeat"></span>
                        Re-enable Other Sitemaps
                    </button>
                    <p class="metasync-button-description">
                        Re-enable sitemap generation from <?php echo esc_html(implode(', ', array_values($active_sitemap_plugins))); ?>.
                    </p>
                </form>
                <?php endif; ?>

            <!-- Import from SEO Plugins Button (Always visible) -->
            <div >
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-import-external&tab=sitemap')); ?>" class="button button-secondary button-hero metasync-sitemap-button-large">
                    <span class="dashicons dashicons-download" style="margin-top:3px;font-size:15px;width:15px;height:15px;"></span> Import from SEO Plugins
                </a>
                <p class="metasync-button-description">
                    Import sitemap settings from other SEO plugins like Yoast, Rank Math, or AIOSEO.
                </p>
            </div>
            </div>

        </div>

        <?php if (($sitemap_exists && !empty($sitemap_files)) || $news_sm_exists || $video_sm_exists || $news_sm_enabled || $video_sm_enabled): ?>
        <!-- Sitemap Files List Card -->
        <div class="dashboard-card metasync-sitemap-files-card">
            <h2>Sitemap Files</h2>
            <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">
                <?php if ($total_sitemap_count > 0): ?>
                Your sitemap has <?php echo $total_sitemap_count; ?> file<?php echo $total_sitemap_count > 1 ? 's' : ''; ?> — <?php echo $sitemap_count; ?> main (up to <?php echo number_format($sitemap_generator->get_urls_per_sitemap()); ?> URLs each)<?php
                    if ($news_sm_exists || $video_sm_exists) {
                        $extras = [];
                        if ($news_sm_exists) $extras[] = 'news';
                        if ($video_sm_exists) $extras[] = 'video';
                        echo ', plus ' . implode(' &amp; ', $extras);
                    }
                    ?>.
                <?php else: ?>
                <?php esc_html_e('No sitemap files generated yet. Use "Generate Sitemaps Now" above to create them.', 'metasync'); ?>
                <?php endif; ?>
            </p>

            <table class="metasync-sitemap-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Sitemap File', 'metasync'); ?></th>
                        <th><?php esc_html_e('URLs', 'metasync'); ?></th>
                        <th><?php esc_html_e('Last Modified', 'metasync'); ?></th>
                        <th><?php esc_html_e('Actions', 'metasync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sitemap_exists && !empty($sitemap_files)):
                    $urls_per_file = $sitemap_generator->get_urls_per_sitemap();
                    $total_urls = $url_count;
                    foreach ($sitemap_files as $index => $sitemap_file):
                        // Calculate URLs in this file
                        $start_url = ($index * $urls_per_file) + 1;
                        $end_url = min(($index + 1) * $urls_per_file, $total_urls);
                        $urls_in_file = $end_url - $start_url + 1;
                    ?>
                    <tr>
                        <td>
                            <div class="sitemap-filename"><?php echo esc_html($sitemap_file['filename']); ?></div>
                            <div class="sitemap-url-range">
                                URLs <?php echo number_format($start_url); ?> - <?php echo number_format($end_url); ?>
                            </div>
                        </td>
                        <td><?php echo number_format($urls_in_file); ?></td>
                        <td>
                            <?php
                            if ($last_generated) {
                                echo esc_html(date('M j, Y g:i A', strtotime($last_generated)));
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($sitemap_file['url']); ?>" target="_blank" class="button-view">
                                <span class="dashicons dashicons-external"></span>
                                View
                            </a>
                            <?php if ($index === 0): ?>
                            <form method="post" action="" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete the General sitemap? This action cannot be undone.');">
                                <?php wp_nonce_field('metasync_sitemap_action', 'metasync_sitemap_nonce'); ?>
                                <button type="submit" name="delete_general_sitemap" class="button-delete">
                                    <span class="dashicons dashicons-trash"></span>
                                    <?php esc_html_e('Delete', 'metasync'); ?>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>

                    <?php if ($news_sm_enabled): ?>
                    <tr>
                        <td>
                            <div class="sitemap-filename">news-sitemap.xml</div>
                            <div class="sitemap-url-range"><?php esc_html_e('Google News Sitemap', 'metasync'); ?></div>
                        </td>
                        <td><?php echo $news_sm_exists ? $news_url_count : '<em style="color:var(--dashboard-text-secondary);">Not generated</em>'; ?></td>
                        <td><?php echo ($news_sm_exists && $last_generated) ? esc_html(date('M j, Y g:i A', strtotime($last_generated))) : '—'; ?></td>
                        <td>
                            <?php if ($news_sm_exists): ?>
                            <a href="<?php echo esc_url(home_url('/news-sitemap.xml')); ?>" target="_blank" class="button-view">
                                <span class="dashicons dashicons-external"></span>
                                View
                            </a>
                            <form method="post" action="" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete the News sitemap? This action cannot be undone.');">
                                <?php wp_nonce_field('metasync_sitemap_action', 'metasync_sitemap_nonce'); ?>
                                <button type="submit" name="delete_news_sitemap" class="button-delete">
                                    <span class="dashicons dashicons-trash"></span>
                                    <?php esc_html_e('Delete', 'metasync'); ?>
                                </button>
                            </form>
                            <?php else: ?>
                            <span style="color:var(--dashboard-text-secondary); font-style:italic;"><?php esc_html_e('Enabled — generate to create file', 'metasync'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if ($video_sm_enabled): ?>
                    <tr>
                        <td>
                            <div class="sitemap-filename">video-sitemap.xml</div>
                            <div class="sitemap-url-range"><?php esc_html_e('Video Sitemap', 'metasync'); ?></div>
                        </td>
                        <td><?php echo $video_sm_exists ? $video_url_count : '<em style="color:var(--dashboard-text-secondary);">Not generated</em>'; ?></td>
                        <td><?php echo ($video_sm_exists && $last_generated) ? esc_html(date('M j, Y g:i A', strtotime($last_generated))) : '—'; ?></td>
                        <td>
                            <?php if ($video_sm_exists): ?>
                            <a href="<?php echo esc_url(home_url('/video-sitemap.xml')); ?>" target="_blank" class="button-view">
                                <span class="dashicons dashicons-external"></span>
                                View
                            </a>
                            <form method="post" action="" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete the Video sitemap? This action cannot be undone.');">
                                <?php wp_nonce_field('metasync_sitemap_action', 'metasync_sitemap_nonce'); ?>
                                <button type="submit" name="delete_video_sitemap" class="button-delete">
                                    <span class="dashicons dashicons-trash"></span>
                                    <?php esc_html_e('Delete', 'metasync'); ?>
                                </button>
                            </form>
                            <?php else: ?>
                            <span style="color:var(--dashboard-text-secondary); font-style:italic;"><?php esc_html_e('Enabled — generate to create file', 'metasync'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Sitemap Index Preview Card -->
        <div class="dashboard-card metasync-sitemap-preview-card">
            <h2>Sitemap Index Preview</h2>
            <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">
                Preview the sitemap index file that references all individual sitemaps.
            </p>

            <div class="metasync-sitemap-preview-container">
                <div class="metasync-sitemap-preview-header">
                    <span class="metasync-preview-label">sitemap_index.xml</span>
                    <a href="<?php echo esc_url($sitemap_url); ?>" target="_blank" class="button button-small">
                        <span class="dashicons dashicons-external"></span>
                        View Sitemap Index
                    </a>
                </div>
                <div class="metasync-sitemap-code-block">
                    <pre><?php
                        $content = $sitemap_generator->get_sitemap_content();
                        if ($content) {
                            $lines = explode("\n", $content);
                            $preview_lines = array_slice($lines, 0, 50);
                            echo esc_html(implode("\n", $preview_lines));
                            if (count($lines) > 50) {
                                echo "\n... [" . (count($lines) - 50) . " more lines]";
                            }
                        } else {
                            echo "Unable to read sitemap index content.";
                        }
                    ?></pre>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
    </div>
    </div><!-- /#metasync-sitemap-general -->

    <!-- News Sitemap Tab -->
    <div id="metasync-sitemap-news" class="metasync-sitemap-tab-content">
    <div class="metasync-sitemap-outer">
    <div class="metasync-sitemap-container">

        <?php
        require_once plugin_dir_path(dirname(__FILE__)) . 'sitemap/class-metasync-sitemap-news.php';
        $news_checker = new Metasync_Sitemap_News();
        $news_conflicts = $news_checker->get_conflict_notices();
        $news_has_content = false !== get_transient('metasync_vsm_' . md5('news-sitemap.xml'))
            || file_exists(ABSPATH . 'news-sitemap.xml');
        ?>

        <!-- News Sitemap Status -->
        <div class="dashboard-card" style="margin-bottom: 20px;">
            <h2><?php esc_html_e('News Sitemap Status', 'metasync'); ?></h2>
            <div style="display: flex; gap: 24px; align-items: center; margin-top: 16px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 20px;">📄</span>
                    <div>
                        <div style="color: var(--dashboard-text-secondary); font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;"><?php esc_html_e('Status', 'metasync'); ?></div>
                        <?php if (!empty($news_settings['enabled']) && $news_has_content && empty($news_conflicts)): ?>
                            <div style="color: var(--dashboard-success, #10b981); font-weight: 600;"><?php esc_html_e('Generated', 'metasync'); ?></div>
                        <?php elseif (!empty($news_settings['enabled']) && !$news_has_content && empty($news_conflicts)): ?>
                            <div style="color: var(--dashboard-warning, #f59e0b); font-weight: 600;"><?php esc_html_e('Not Generated', 'metasync'); ?></div>
                        <?php elseif (!empty($news_conflicts)): ?>
                            <div style="color: var(--dashboard-error, #ef4444); font-weight: 600;"><?php esc_html_e('Conflict Detected', 'metasync'); ?></div>
                        <?php else: ?>
                            <div style="color: var(--dashboard-text-secondary); font-weight: 600;"><?php esc_html_e('Disabled', 'metasync'); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($news_settings['enabled']) && $news_has_content && empty($news_conflicts)): ?>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 20px;">🔗</span>
                    <div>
                        <div style="color: var(--dashboard-text-secondary); font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;"><?php esc_html_e('URL', 'metasync'); ?></div>
                        <a href="<?php echo esc_url(home_url('/news-sitemap.xml')); ?>" target="_blank" style="color: var(--dashboard-accent, #3b82f6); font-weight: 500; text-decoration: none;">
                            <?php echo esc_html(home_url('/news-sitemap.xml')); ?>
                            <span class="dashicons dashicons-external" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($news_settings['enabled']) && empty($news_conflicts)): ?>
            <div style="margin-top: 20px;">
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('metasync_news_sitemap_action', 'metasync_news_sitemap_nonce'); ?>
                    <button type="submit" name="generate_news_sitemap" class="button button-primary button-hero metasync-sitemap-button-large">
                        <span class="dashicons dashicons-update"></span>
                        <?php $news_has_content ? esc_html_e('Regenerate News Sitemap', 'metasync') : esc_html_e('Generate News Sitemap Now', 'metasync'); ?>
                    </button>
                </form>
                <p style="margin-top: 8px; color: var(--dashboard-text-secondary); font-size: 13px;">
                    <?php esc_html_e('Generates the news sitemap with articles from the last 2 days (max 1,000 URLs).', 'metasync'); ?>
                </p>
            </div>
            <?php endif; ?>
        </div>

        <?php foreach ($news_conflicts as $notice) {
            $notice_type = (strpos($notice, 'can coexist') !== false) ? 'notice-info' : 'notice-warning';
            echo '<div class="notice ' . $notice_type . ' inline" style="margin-bottom: 12px; padding: 12px 16px; border-radius: 8px; background: rgba(255, 152, 0, 0.1); border-left: 4px solid var(--dashboard-warning, #f59e0b); color: var(--dashboard-text-primary, #fff);"><p style="margin: 0;">&#9888; ' . esc_html($notice) . '</p></div>';
        } ?>

        <div class="dashboard-card">
            <h2><?php esc_html_e('News Sitemap Settings', 'metasync'); ?></h2>
            <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">
                <?php esc_html_e('Configure the Google News Sitemap. Only articles published within the last 2 days will be included (max 1,000 URLs).', 'metasync'); ?>
            </p>

            <form method="post">
                <?php wp_nonce_field('metasync_news_sitemap_action', 'metasync_news_sitemap_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable News Sitemap', 'metasync'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="news_enabled" value="1" <?php checked(!empty($news_settings['enabled'])); ?> />
                                <?php esc_html_e('Enable Google News Sitemap at /news-sitemap.xml', 'metasync'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Post Types', 'metasync'); ?></th>
                        <td>
                            <?php
                            $public_post_types = get_post_types(['public' => true], 'objects');
                            $filtered_pts = [];
                            foreach ($public_post_types as $pt) {
                                if ($pt->name !== 'attachment') $filtered_pts[] = $pt;
                            }
                            $selected_post_types = isset($news_settings['post_types']) ? (array) $news_settings['post_types'] : ['post'];
                            $pts_scrollable = count($filtered_pts) > 10;
                            if ($pts_scrollable) : ?>
                                <input type="text" class="metasync-checkbox-search" placeholder="<?php esc_attr_e('Filter post types...', 'metasync'); ?>">
                            <?php endif; ?>
                            <div class="<?php echo $pts_scrollable ? 'metasync-checkbox-scroll' : ''; ?>">
                                <?php foreach ($filtered_pts as $pt) : ?>
                                    <label>
                                        <input type="checkbox" name="news_post_types[]" value="<?php echo esc_attr($pt->name); ?>"
                                            <?php checked(in_array($pt->name, $selected_post_types, true)); ?> />
                                        <?php echo esc_html($pt->label); ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="metasync-no-results"><?php esc_html_e('No post types match your search.', 'metasync'); ?></p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Categories', 'metasync'); ?>
                            <?php $categories = get_categories(['hide_empty' => false]); ?>
                            <span class="metasync-checkbox-count">(<?php echo count($categories); ?>)</span>
                        </th>
                        <td>
                            <?php
                            $selected_cats = isset($news_settings['categories']) ? (array) $news_settings['categories'] : [];
                            $cats_scrollable = count($categories) > 10;
                            if ($cats_scrollable) : ?>
                                <input type="text" class="metasync-checkbox-search" placeholder="<?php esc_attr_e('Filter categories...', 'metasync'); ?>">
                            <?php endif; ?>
                            <div class="<?php echo $cats_scrollable ? 'metasync-checkbox-scroll' : ''; ?>">
                                <?php foreach ($categories as $cat) : ?>
                                    <label>
                                        <input type="checkbox" name="news_categories[]" value="<?php echo esc_attr($cat->term_id); ?>"
                                            <?php checked(in_array($cat->term_id, $selected_cats)); ?> />
                                        <?php echo esc_html($cat->name); ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="metasync-no-results"><?php esc_html_e('No categories match your search.', 'metasync'); ?></p>
                            </div>
                            <p class="description"><?php esc_html_e('Leave empty to include all categories.', 'metasync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Tags', 'metasync'); ?>
                            <?php $tags = get_tags(['hide_empty' => false]); ?>
                            <span class="metasync-checkbox-count">(<?php echo count($tags); ?>)</span>
                        </th>
                        <td>
                            <?php
                            $selected_tags = isset($news_settings['tags']) ? (array) $news_settings['tags'] : [];
                            if (!empty($tags)) :
                                $tags_scrollable = count($tags) > 10;
                                if ($tags_scrollable) : ?>
                                    <input type="text" class="metasync-checkbox-search" placeholder="<?php esc_attr_e('Filter tags...', 'metasync'); ?>">
                                <?php endif; ?>
                                <div class="<?php echo $tags_scrollable ? 'metasync-checkbox-scroll' : ''; ?>">
                                    <?php foreach ($tags as $tag) : ?>
                                        <label>
                                            <input type="checkbox" name="news_tags[]" value="<?php echo esc_attr($tag->term_id); ?>"
                                                <?php checked(in_array($tag->term_id, $selected_tags)); ?> />
                                            <?php echo esc_html($tag->name); ?>
                                        </label>
                                    <?php endforeach; ?>
                                    <p class="metasync-no-results"><?php esc_html_e('No tags match your search.', 'metasync'); ?></p>
                                </div>
                            <?php else : ?>
                                <p class="description"><?php esc_html_e('No tags found.', 'metasync'); ?></p>
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e('Leave empty to include all tags.', 'metasync'); ?></p>
                        </td>
                    </tr>
                    <?php
                    // Generic taxonomy filters for news sitemap
                    $news_custom_taxonomies = get_taxonomies(['public' => true, '_builtin' => false], 'objects');
                    $news_saved_taxonomies = isset($news_settings['taxonomies']) ? (array) $news_settings['taxonomies'] : [];
                    if (!empty($news_custom_taxonomies)) :
                        foreach ($news_custom_taxonomies as $tax) :
                            $tax_terms = get_terms(['taxonomy' => $tax->name, 'hide_empty' => false]);
                            if (empty($tax_terms) || is_wp_error($tax_terms)) continue;
                            $selected_term_ids = isset($news_saved_taxonomies[$tax->name]) ? (array) $news_saved_taxonomies[$tax->name] : [];
                    ?>
                    <tr>
                        <th scope="row">
                            <?php echo esc_html($tax->label); ?>
                            <span class="metasync-checkbox-count">(<?php echo count($tax_terms); ?>)</span>
                        </th>
                        <td>
                            <?php
                            $tax_scrollable = count($tax_terms) > 10;
                            if ($tax_scrollable) : ?>
                                <input type="text" class="metasync-checkbox-search" placeholder="<?php echo esc_attr(sprintf(__('Filter %s...', 'metasync'), strtolower($tax->label))); ?>">
                            <?php endif; ?>
                            <div class="<?php echo $tax_scrollable ? 'metasync-checkbox-scroll' : ''; ?>">
                                <?php foreach ($tax_terms as $term) : ?>
                                    <label>
                                        <input type="checkbox" name="news_taxonomies[<?php echo esc_attr($tax->name); ?>][]" value="<?php echo esc_attr($term->term_id); ?>"
                                            <?php checked(in_array($term->term_id, $selected_term_ids)); ?> />
                                        <?php echo esc_html($term->name); ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="metasync-no-results"><?php esc_html_e('No items match your search.', 'metasync'); ?></p>
                            </div>
                            <p class="description"><?php esc_html_e('Leave empty to include all.', 'metasync'); ?></p>
                        </td>
                    </tr>
                    <?php
                        endforeach;
                    endif;
                    ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Exclude URLs', 'metasync'); ?></th>
                        <td>
                            <textarea name="news_excluded_urls" rows="4" class="large-text code" placeholder="<?php esc_attr_e("https://example.com/post-to-exclude/\nhttps://example.com/another-post/", 'metasync'); ?>"><?php echo esc_textarea($news_settings['excluded_urls'] ?? ''); ?></textarea>
                            <p class="description"><?php esc_html_e('Enter one URL per line. These URLs will be excluded from the news sitemap.', 'metasync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Publication Name', 'metasync'); ?></th>
                        <td>
                            <input type="text" name="publication_name" class="regular-text"
                                   value="<?php echo esc_attr(!empty($news_settings['publication_name']) ? $news_settings['publication_name'] : get_bloginfo('name')); ?>" />
                            <p class="description"><?php esc_html_e('Defaults to your site name.', 'metasync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Publication Language', 'metasync'); ?></th>
                        <td>
                            <input type="text" name="publication_language" class="small-text"
                                   value="<?php echo esc_attr(!empty($news_settings['publication_language']) ? $news_settings['publication_language'] : substr(get_locale(), 0, 2)); ?>" />
                            <p class="description"><?php esc_html_e('ISO 639 language code (e.g. en, fr, de). Defaults to site language.', 'metasync'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(esc_html__('Save & Generate Sitemap', 'metasync'), 'primary', 'save_news_sitemap'); ?>
            </form>
        </div>
    </div>
    </div>
    </div><!-- /#metasync-sitemap-news -->

    <!-- Video Sitemap Tab -->
    <div id="metasync-sitemap-video" class="metasync-sitemap-tab-content">
    <div class="metasync-sitemap-outer">
    <div class="metasync-sitemap-container">

        <?php
        require_once plugin_dir_path(dirname(__FILE__)) . 'sitemap/class-metasync-sitemap-video.php';
        $video_checker = new Metasync_Sitemap_Video();
        $video_conflicts = $video_checker->get_conflict_notices();
        $video_has_content = false !== get_transient('metasync_vsm_' . md5('video-sitemap.xml'))
            || file_exists(ABSPATH . 'video-sitemap.xml');
        ?>

        <!-- Video Sitemap Status -->
        <div class="dashboard-card" style="margin-bottom: 20px;">
            <h2><?php esc_html_e('Video Sitemap Status', 'metasync'); ?></h2>
            <div style="display: flex; gap: 24px; align-items: center; margin-top: 16px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 20px;">📄</span>
                    <div>
                        <div style="color: var(--dashboard-text-secondary); font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;"><?php esc_html_e('Status', 'metasync'); ?></div>
                        <?php if (!empty($video_settings['enabled']) && $video_has_content && empty($video_conflicts)): ?>
                            <div style="color: var(--dashboard-success, #10b981); font-weight: 600;"><?php esc_html_e('Generated', 'metasync'); ?></div>
                        <?php elseif (!empty($video_settings['enabled']) && !$video_has_content && empty($video_conflicts)): ?>
                            <div style="color: var(--dashboard-warning, #f59e0b); font-weight: 600;"><?php esc_html_e('Not Generated', 'metasync'); ?></div>
                        <?php elseif (!empty($video_conflicts)): ?>
                            <div style="color: var(--dashboard-error, #ef4444); font-weight: 600;"><?php esc_html_e('Conflict Detected', 'metasync'); ?></div>
                        <?php else: ?>
                            <div style="color: var(--dashboard-text-secondary); font-weight: 600;"><?php esc_html_e('Disabled', 'metasync'); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($video_settings['enabled']) && $video_has_content && empty($video_conflicts)): ?>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 20px;">🔗</span>
                    <div>
                        <div style="color: var(--dashboard-text-secondary); font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;"><?php esc_html_e('URL', 'metasync'); ?></div>
                        <a href="<?php echo esc_url(home_url('/video-sitemap.xml')); ?>" target="_blank" style="color: var(--dashboard-accent, #3b82f6); font-weight: 500; text-decoration: none;">
                            <?php echo esc_html(home_url('/video-sitemap.xml')); ?>
                            <span class="dashicons dashicons-external" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($video_settings['enabled']) && empty($video_conflicts)): ?>
            <div style="margin-top: 20px;">
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('metasync_video_sitemap_action', 'metasync_video_sitemap_nonce'); ?>
                    <button type="submit" name="generate_video_sitemap" class="button button-primary button-hero metasync-sitemap-button-large">
                        <span class="dashicons dashicons-update"></span>
                        <?php $video_has_content ? esc_html_e('Regenerate Video Sitemap', 'metasync') : esc_html_e('Generate Video Sitemap Now', 'metasync'); ?>
                    </button>
                </form>
                <p style="margin-top: 8px; color: var(--dashboard-text-secondary); font-size: 13px;">
                    <?php esc_html_e('Scans all posts for embedded videos and generates the video sitemap.', 'metasync'); ?>
                </p>
            </div>
            <?php endif; ?>
        </div>

        <?php foreach ($video_conflicts as $notice) {
            $notice_type = (strpos($notice, 'can coexist') !== false) ? 'notice-info' : 'notice-warning';
            echo '<div class="notice ' . $notice_type . ' inline" style="margin-bottom: 12px; padding: 12px 16px; border-radius: 8px; background: rgba(255, 152, 0, 0.1); border-left: 4px solid var(--dashboard-warning, #f59e0b); color: var(--dashboard-text-primary, #fff);"><p style="margin: 0;">&#9888; ' . esc_html($notice) . '</p></div>';
        } ?>

        <div class="dashboard-card">
            <h2><?php esc_html_e('Video Sitemap Settings', 'metasync'); ?></h2>
            <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">
                <?php esc_html_e('Configure the Video Sitemap. Auto-detects YouTube, Vimeo, VideoPress, and self-hosted videos in your content.', 'metasync'); ?>
            </p>

            <form method="post">
                <?php wp_nonce_field('metasync_video_sitemap_action', 'metasync_video_sitemap_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Video Sitemap', 'metasync'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="video_enabled" value="1" <?php checked(!empty($video_settings['enabled'])); ?> />
                                <?php esc_html_e('Enable Video Sitemap at /video-sitemap.xml', 'metasync'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Post Types', 'metasync'); ?></th>
                        <td>
                            <?php
                            $public_post_types = get_post_types(['public' => true], 'objects');
                            $vid_filtered_pts = [];
                            foreach ($public_post_types as $pt) {
                                if ($pt->name !== 'attachment') $vid_filtered_pts[] = $pt;
                            }
                            $selected_video_pts = isset($video_settings['post_types']) ? (array) $video_settings['post_types'] : ['post', 'page'];
                            $vid_pts_scrollable = count($vid_filtered_pts) > 10;
                            if ($vid_pts_scrollable) : ?>
                                <input type="text" class="metasync-checkbox-search" placeholder="<?php esc_attr_e('Filter post types...', 'metasync'); ?>">
                            <?php endif; ?>
                            <div class="<?php echo $vid_pts_scrollable ? 'metasync-checkbox-scroll' : ''; ?>">
                                <?php foreach ($vid_filtered_pts as $pt) : ?>
                                    <label>
                                        <input type="checkbox" name="video_post_types[]" value="<?php echo esc_attr($pt->name); ?>"
                                            <?php checked(in_array($pt->name, $selected_video_pts, true)); ?> />
                                        <?php echo esc_html($pt->label); ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="metasync-no-results"><?php esc_html_e('No post types match your search.', 'metasync'); ?></p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Auto-Detect Videos', 'metasync'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_detect" value="1" <?php checked(!empty($video_settings['auto_detect'])); ?> />
                                <?php esc_html_e('Automatically detect embedded videos from post content', 'metasync'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Detects YouTube, Vimeo, VideoPress, and self-hosted &lt;video&gt; tags.', 'metasync'); ?></p>
                        </td>
                    </tr>
                    <?php
                    // Taxonomy filters for video sitemap
                    $video_all_taxonomies = get_taxonomies(['public' => true], 'objects');
                    $video_saved_taxonomies = isset($video_settings['taxonomies']) ? (array) $video_settings['taxonomies'] : [];
                    // Show categories, tags, and custom taxonomies
                    foreach ($video_all_taxonomies as $tax) :
                        $tax_terms = get_terms(['taxonomy' => $tax->name, 'hide_empty' => false]);
                        if (empty($tax_terms) || is_wp_error($tax_terms)) continue;
                        $selected_term_ids = isset($video_saved_taxonomies[$tax->name]) ? (array) $video_saved_taxonomies[$tax->name] : [];
                    ?>
                    <tr>
                        <th scope="row">
                            <?php echo esc_html($tax->label); ?>
                            <span class="metasync-checkbox-count">(<?php echo count($tax_terms); ?>)</span>
                        </th>
                        <td>
                            <?php
                            $vtax_scrollable = count($tax_terms) > 10;
                            if ($vtax_scrollable) : ?>
                                <input type="text" class="metasync-checkbox-search" placeholder="<?php echo esc_attr(sprintf(__('Filter %s...', 'metasync'), strtolower($tax->label))); ?>">
                            <?php endif; ?>
                            <div class="<?php echo $vtax_scrollable ? 'metasync-checkbox-scroll' : ''; ?>">
                                <?php foreach ($tax_terms as $term) : ?>
                                    <label>
                                        <input type="checkbox" name="video_taxonomies[<?php echo esc_attr($tax->name); ?>][]" value="<?php echo esc_attr($term->term_id); ?>"
                                            <?php checked(in_array($term->term_id, $selected_term_ids)); ?> />
                                        <?php echo esc_html($term->name); ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="metasync-no-results"><?php esc_html_e('No items match your search.', 'metasync'); ?></p>
                            </div>
                            <p class="description"><?php esc_html_e('Leave empty to include all.', 'metasync'); ?></p>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Exclude URLs', 'metasync'); ?></th>
                        <td>
                            <textarea name="video_excluded_urls" rows="4" class="large-text code" placeholder="<?php esc_attr_e("https://example.com/post-to-exclude/\nhttps://example.com/another-post/", 'metasync'); ?>"><?php echo esc_textarea($video_settings['excluded_urls'] ?? ''); ?></textarea>
                            <p class="description"><?php esc_html_e('Enter one URL per line. These URLs will be excluded from the video sitemap.', 'metasync'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(esc_html__('Save & Generate Sitemap', 'metasync'), 'primary', 'save_video_sitemap'); ?>
            </form>
        </div>
    </div>
    </div>
    </div><!-- /#metasync-sitemap-video -->

<?php $this->render_layout_close(); ?>
