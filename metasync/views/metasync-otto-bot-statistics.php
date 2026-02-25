<?php
/**
 * Bot Statistics Dashboard View
 *
 * Displays bot detection statistics, breakdown by type, and unique bot entries
 * with hit counts.
 *
 * @package    Metasync
 * @subpackage Metasync/views
 * @since      1.0.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

// Load required classes
require_once plugin_dir_path(dirname(__FILE__)) . 'otto/class-metasync-otto-bot-detector.php';
require_once plugin_dir_path(dirname(__FILE__)) . 'otto/class-metasync-otto-bot-statistics-database.php';

// Get database instance
$db = Metasync_Otto_Bot_Statistics_Database::get_instance();

// Get statistics
$stats = $db->get_statistics();

// Get recent requests (unique entries)
$recent_requests = $db->get_recent_requests(100);

// Get whitelabel OTTO name
$whitelabel_otto_name = Metasync::get_whitelabel_otto_name();

// Get current settings
$general_options = Metasync::get_option('general') ?? array();
$bot_filtering_enabled = isset($general_options['otto_disable_for_bots']) ? (bool)$general_options['otto_disable_for_bots'] : false;

// Breakdown label config (reused in table)
$breakdown_labels = array(
    'search_engine' => array('label' => 'Search Engines', 'icon' => '🔎', 'color' => '#4285f4'),
    'seo_tool'      => array('label' => 'SEO Tools',      'icon' => '📈', 'color' => '#34a853'),
    'social_media'  => array('label' => 'Social Media',   'icon' => '📱', 'color' => '#ea4335'),
    'archiver'      => array('label' => 'Archivers',      'icon' => '📦', 'color' => '#fbbc05'),
    'generic'       => array('label' => 'Generic Bots',   'icon' => '🤖', 'color' => '#9c27b0'),
    'other'         => array('label' => 'Other',          'icon' => '❓', 'color' => '#607d8b'),
    'unknown'       => array('label' => 'Unknown',        'icon' => '❔', 'color' => '#9e9e9e'),
    'ip_based'      => array('label' => 'IP-based',       'icon' => '🌐', 'color' => '#795548')
);

?>

<div class="wrap metasync-dashboard-wrap" data-theme="<?php echo esc_attr(get_option('metasync_theme', 'dark')); ?>">

    <?php $this->render_plugin_header('Bot Statistics'); ?>
    <?php $this->render_navigation_menu('bot_statistics'); ?>

    <!-- Bot Detection Status Card -->
    <div class="dashboard-card">
        <h2>🤖 Bot Detection Status</h2>
        <div style="margin-bottom: 20px;">
            <?php if ($bot_filtering_enabled): ?>
                <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 4px;">
                    <strong>✅ Bot Filtering Active:</strong> <?php echo esc_html($whitelabel_otto_name); ?> is currently disabled for detected bot traffic.
                </div>
            <?php else: ?>
                <div style="background: #fff3cd; border: 1px solid #ffc107; color: #856404; padding: 12px; border-radius: 4px;">
                    <strong>⚠️ Bot Filtering Inactive:</strong> Bot detection is tracking bots, but <?php echo esc_html($whitelabel_otto_name); ?> is still processing bot traffic.
                    <a href="?page=<?php echo esc_attr(Metasync_Admin::$page_slug); ?>&tab=general" style="margin-left: 10px;">Enable in Settings →</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Statistics Overview -->
    <div class="dashboard-card">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
            <div>
                <h2 style="margin: 0;">📊 Bot Detection Overview</h2>
                <p style="color: var(--dashboard-text-secondary); margin: 5px 0 0 0;">
                    Overall statistics for bot detection and API optimization.
                </p>
            </div>
            <button type="button" id="reset-bot-stats" class="button" style="color: #ffffff;">
                🗑️ Reset All Statistics
            </button>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
            <!-- Total Detections -->
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 8px; color: white;">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">Total Bot Hits</div>
                <div style="font-size: 32px; font-weight: 700;"><?php echo number_format($stats['total_detections']); ?></div>
            </div>

            <!-- API Calls Saved -->
            <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); padding: 20px; border-radius: 8px; color: white;">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">API Calls Saved</div>
                <div style="font-size: 32px; font-weight: 700;"><?php echo number_format($stats['api_calls_saved']); ?></div>
            </div>

            <!-- Unique Bots -->
            <div style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); padding: 20px; border-radius: 8px; color: white;">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">Unique Bots Seen</div>
                <div style="font-size: 32px; font-weight: 700;"><?php echo number_format(count($recent_requests)); ?></div>
            </div>
        </div>
    </div>

    <!-- Bot Type Breakdown -->
    <div class="dashboard-card">
        <h2>🔍 Breakdown by Bot Type</h2>
        <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">
            Distribution of detected bots by category.
        </p>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
            <?php foreach ($breakdown_labels as $type => $info):
                if ($type === 'ip_based') continue; // ip_based rolls into "other" in stats
                $count = $stats['breakdown'][$type] ?? 0;
            ?>
                <div style="border: 2px solid <?php echo esc_attr($info['color']); ?>; padding: 15px; border-radius: 6px; text-align: center;">
                    <div style="font-size: 24px; margin-bottom: 5px;"><?php echo $info['icon']; ?></div>
                    <div style="font-size: 20px; font-weight: 700; color: var(--dashboard-text-primary);"><?php echo number_format($count); ?></div>
                    <div style="font-size: 12px; color: var(--dashboard-text-secondary); margin-top: 5px;"><?php echo esc_html($info['label']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Unique Bot Entries -->
    <div class="dashboard-card">
        <h2>📋 Unique Bot Entries (Last 100)</h2>
        <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">
            Each row is a unique bot+IP combination. The <strong>Hits</strong> column shows how many times that bot visited your site.
        </p>

        <?php if (empty($recent_requests)): ?>
            <div style="background: var(--dashboard-card-bg); border: 1px solid var(--dashboard-border, #e2e8f0); padding: 30px; border-radius: 8px; text-align: center; color: var(--dashboard-text-secondary);">
                <div style="font-size: 48px; margin-bottom: 10px;">🤖</div>
                <p style="margin: 0;">No bot requests detected yet. Bot detection will automatically log requests when bots visit your site.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="wp-list-table widefat fixed striped" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th style="padding: 12px; text-align: left; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary); width: 80px;">Hits</th>
                            <th style="padding: 12px; text-align: left; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary);">Bot Name</th>
                            <th style="padding: 12px; text-align: left; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary); width: 120px;">Type</th>
                            <th style="padding: 12px; text-align: left; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary);">User Agent</th>
                            <th style="padding: 12px; text-align: left; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary); width: 130px;">IP Address</th>
                            <th style="padding: 12px; text-align: left; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary); width: 150px;">First Seen</th>
                            <th style="padding: 12px; text-align: left; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary); width: 150px;">Last Seen</th>
                            <th style="padding: 12px; text-align: left; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary);">Last URL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_requests as $request):
                            $bot_type_info = $breakdown_labels[$request['bot_type']] ?? array('icon' => '❓', 'color' => '#9e9e9e');
                            $hit_count = (int)($request['hit_count'] ?? 1);
                        ?>
                            <tr>
                                <td style="padding: 12px; text-align: center;">
                                    <span style="display: inline-block; min-width: 40px; padding: 4px 10px; border-radius: 12px; font-size: 13px; font-weight: 700;
                                        <?php if ($hit_count >= 100): ?>
                                            background: #ef444420; color: #ef4444;
                                        <?php elseif ($hit_count >= 10): ?>
                                            background: #f59e0b20; color: #f59e0b;
                                        <?php else: ?>
                                            background: #10b98120; color: #10b981;
                                        <?php endif; ?>
                                    ">
                                        <?php echo number_format($hit_count); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px; color: var(--dashboard-text-primary); font-weight: 600;">
                                    <?php echo esc_html($request['bot_name']); ?>
                                </td>
                                <td style="padding: 12px;">
                                    <span style="display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; background: <?php echo esc_attr($bot_type_info['color']); ?>20; color: <?php echo esc_attr($bot_type_info['color']); ?>; font-weight: 600;">
                                        <?php echo $bot_type_info['icon']; ?> <?php echo esc_html(ucfirst(str_replace('_', ' ', $request['bot_type']))); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px; color: var(--dashboard-text-secondary); font-family: monospace; font-size: 11px; max-width: 250px;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1;" title="<?php echo esc_attr($request['user_agent']); ?>">
                                            <?php echo esc_html($request['user_agent']); ?>
                                        </span>
                                        <button type="button" class="copy-btn" data-copy="<?php echo esc_attr($request['user_agent']); ?>" aria-label="Copy User Agent">
                                            <svg class="copy-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                            </svg>
                                            <svg class="check-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="20 6 9 17 4 12"></polyline>
                                            </svg>
                                            <span class="copy-tooltip">Copy</span>
                                        </button>
                                    </div>
                                </td>
                                <td style="padding: 12px; color: var(--dashboard-text-secondary); font-family: monospace; font-size: 12px;">
                                    <?php echo esc_html($request['ip_address'] ?: 'N/A'); ?>
                                </td>
                                <td style="padding: 12px; color: var(--dashboard-text-secondary); font-size: 12px;">
                                    <?php echo esc_html($request['first_seen_at'] ? date('Y-m-d H:i', strtotime($request['first_seen_at'])) : 'N/A'); ?>
                                </td>
                                <td style="padding: 12px; color: var(--dashboard-text-primary); font-size: 12px; font-weight: 500;">
                                    <?php echo esc_html($request['last_seen_at'] ? date('Y-m-d H:i', strtotime($request['last_seen_at'])) : 'N/A'); ?>
                                </td>
                                <td style="padding: 12px; color: var(--dashboard-text-secondary); font-size: 11px; max-width: 180px;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1;" title="<?php echo esc_attr($request['url']); ?>">
                                            <?php echo esc_html($request['url'] ?: 'N/A'); ?>
                                        </span>
                                        <?php if (!empty($request['url'])): ?>
                                        <button type="button" class="copy-btn" data-copy="<?php echo esc_attr($request['url']); ?>" aria-label="Copy URL">
                                            <svg class="copy-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                            </svg>
                                            <svg class="check-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="20 6 9 17 4 12"></polyline>
                                            </svg>
                                            <span class="copy-tooltip">Copy</span>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Copy button functionality
    $('.copy-btn').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var $btn = $(this);
        var textToCopy = $btn.data('copy');

        function showSuccess() {
            $btn.addClass('copied');
            $btn.find('.copy-tooltip').text('Copied!');
            setTimeout(function() {
                $btn.removeClass('copied');
                $btn.find('.copy-tooltip').text('Copy');
            }, 2000);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(textToCopy).then(function() {
                showSuccess();
            }).catch(function() {
                fallbackCopy(textToCopy, $btn, showSuccess);
            });
        } else {
            fallbackCopy(textToCopy, $btn, showSuccess);
        }
    });

    function fallbackCopy(text, $btn, successCallback) {
        var $temp = $('<textarea>');
        $temp.css({ position: 'absolute', left: '-9999px' });
        $('body').append($temp);
        $temp.val(text).select();
        try {
            document.execCommand('copy');
            successCallback();
        } catch (err) {
            alert('Failed to copy. Please copy manually.');
        }
        $temp.remove();
    }

    $('#reset-bot-stats').on('click', function(e) {
        e.preventDefault();

        if (!confirm('Are you sure you want to reset all bot detection statistics? This action cannot be undone.')) {
            return;
        }

        var $button = $(this);
        var originalText = $button.html();

        $button.prop('disabled', true).html('⏳ Resetting...');

        $.post(ajaxurl, {
            action: 'metasync_reset_bot_stats',
            nonce: '<?php echo wp_create_nonce('metasync_reset_bot_stats'); ?>'
        })
        .done(function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                $button.prop('disabled', false).html(originalText);
            }
        })
        .fail(function() {
            alert('Network error while resetting statistics');
            $button.prop('disabled', false).html(originalText);
        });
    });
});
</script>

<style>
.dashboard-card table tr:hover {
    background-color: rgba(0, 0, 0, 0.02);
}
[data-theme="dark"] .dashboard-card table tr:hover {
    background-color: rgba(255, 255, 255, 0.05);
}
.dashboard-card table td {
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}
[data-theme="dark"] .dashboard-card table td {
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

/* Copy Button Styles */
.copy-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border: 1px solid var(--dashboard-border, #e2e8f0);
    border-radius: 6px;
    background: var(--dashboard-card-bg, #ffffff);
    color: var(--dashboard-text-secondary, #64748b);
    cursor: pointer;
    flex-shrink: 0;
    transition: all 0.2s ease;
}
.copy-btn:hover {
    background: var(--dashboard-hover-bg, #f1f5f9);
    border-color: var(--dashboard-text-secondary, #94a3b8);
    color: var(--dashboard-text-primary, #1e293b);
}
.copy-btn:active {
    transform: scale(0.95);
}
.copy-btn:focus {
    outline: none;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.3);
}

/* Icon visibility states */
.copy-btn .copy-icon {
    display: block;
}
.copy-btn .check-icon {
    display: none;
    color: #10b981;
}
.copy-btn.copied .copy-icon {
    display: none;
}
.copy-btn.copied .check-icon {
    display: block;
}
.copy-btn.copied {
    border-color: #10b981;
    background: rgba(16, 185, 129, 0.1);
}

/* Tooltip */
.copy-tooltip {
    position: absolute;
    bottom: calc(100% + 6px);
    left: 50%;
    transform: translateX(-50%);
    padding: 4px 8px;
    font-size: 11px;
    font-weight: 500;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    color: #fff;
    background: #1e293b;
    border-radius: 4px;
    white-space: nowrap;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.2s ease, visibility 0.2s ease;
    pointer-events: none;
    z-index: 10;
}
.copy-tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    border: 4px solid transparent;
    border-top-color: #1e293b;
}
.copy-btn:hover .copy-tooltip,
.copy-btn.copied .copy-tooltip {
    opacity: 1;
    visibility: visible;
}

/* Dark theme adjustments */
[data-theme="dark"] .copy-btn {
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(255, 255, 255, 0.1);
    color: rgba(255, 255, 255, 0.6);
}
[data-theme="dark"] .copy-btn:hover {
    background: rgba(255, 255, 255, 0.1);
    border-color: rgba(255, 255, 255, 0.2);
    color: rgba(255, 255, 255, 0.9);
}
[data-theme="dark"] .copy-btn.copied {
    border-color: #10b981;
    background: rgba(16, 185, 129, 0.15);
}
[data-theme="dark"] .copy-tooltip {
    background: #f1f5f9;
    color: #1e293b;
}
[data-theme="dark"] .copy-tooltip::after {
    border-top-color: #f1f5f9;
}
</style>
