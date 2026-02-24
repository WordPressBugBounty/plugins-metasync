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

<div class="wrap metasync-dashboard-wrap">

    <?php $this->render_plugin_header('XML Sitemap'); ?>

    <?php $this->render_navigation_menu('xml-sitemap'); ?>

        <div class="metasync-sitemap-container">

        <!-- Sitemap Status Card -->
        <div class="dashboard-card metasync-sitemap-status-card">
            <h2>📊 Sitemap Status</h2>
            <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Monitor your sitemap generation and status.</p>

            <?php
            $sitemap_files = $sitemap_generator->get_sitemap_files();
            $sitemap_count = count($sitemap_files);
            ?>

            <div class="metasync-sitemap-status-grid">
                <div class="metasync-sitemap-stat">
                    <div class="stat-icon">📄</div>
                    <div class="stat-content">
                        <div class="stat-label">Status</div>
                        <div class="stat-value <?php echo $sitemap_exists ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $sitemap_exists ? 'Generated' : 'Not Generated'; ?>
                        </div>
                    </div>
                </div>

                <div class="metasync-sitemap-stat">
                    <div class="stat-icon">🔗</div>
                    <div class="stat-content">
                        <div class="stat-label">Total URLs</div>
                        <div class="stat-value"><?php echo number_format($url_count); ?></div>
                    </div>
                </div>

                <div class="metasync-sitemap-stat">
                    <div class="stat-icon">📑</div>
                    <div class="stat-content">
                        <div class="stat-label">Sitemap Files</div>
                        <div class="stat-value"><?php echo $sitemap_count; ?></div>
                    </div>
                </div>

                <div class="metasync-sitemap-stat">
                    <div class="stat-icon">🔄</div>
                    <div class="stat-content">
                        <div class="stat-label">Auto-Update</div>
                        <div class="stat-value <?php echo $auto_update_enabled ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $auto_update_enabled ? 'Enabled' : 'Disabled'; ?>
                        </div>
                    </div>
                </div>

                <div class="metasync-sitemap-stat">
                    <div class="stat-icon">⏰</div>
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
                <span class="dashicons dashicons-info" style="color: #0073aa; font-size: 20px;"></span>
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

        <!-- Generate Sitemap Card -->
        <div class="dashboard-card metasync-sitemap-actions-card">
            <h2>⚡ Sitemap Actions</h2>
            <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">
                Generate or manage your XML sitemap with the controls below.
            </p>



            <div class="metasync-sitemap-actions-grid">
                <form method="post" action="" class="metasync-sitemap-action-form">
                    <?php wp_nonce_field('metasync_sitemap_action', 'metasync_sitemap_nonce'); ?>
                    <button type="submit" name="generate_sitemap" class="button button-primary button-hero metasync-sitemap-button-large">
                        <span class="dashicons dashicons-update"></span>
                        Generate Sitemap Now
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

                <?php if ($sitemap_exists): ?>
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
                <a href="<?php echo esc_url(admin_url('admin.php?page=metasync-import-external&tab=sitemap')); ?>" class="button button-secondary button-hero metasync-sitemap-button-large">
                    <span>📥</span> Import from SEO Plugins
                </a>
                <p class="metasync-button-description">
                    Import sitemap settings from other SEO plugins like Yoast, Rank Math, or AIOSEO.
                </p>
            </div>
            </div>

        </div>

        <?php if ($sitemap_exists && !empty($sitemap_files)): ?>
        <!-- Sitemap Files List Card -->
        <div class="dashboard-card metasync-sitemap-files-card">
            <h2>📑 Sitemap Files</h2>
            <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">
                Your sitemap is split into <?php echo $sitemap_count; ?> file<?php echo $sitemap_count > 1 ? 's' : ''; ?> (up to <?php echo number_format($sitemap_generator->get_urls_per_sitemap()); ?> URLs each).
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
                    <?php
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
                            if (!empty($sitemap_file['lastmod'])) {
                                echo esc_html(date('M j, Y g:i A', strtotime($sitemap_file['lastmod'])));
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
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Sitemap Index Preview Card -->
        <div class="dashboard-card metasync-sitemap-preview-card">
            <h2>👀 Sitemap Index Preview</h2>
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
