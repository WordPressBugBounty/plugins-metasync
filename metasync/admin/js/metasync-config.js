/* global metasyncConfigData, jQuery */
/**
 * MetaSync Config & Admin Bar Status Sync
 *
 * Extracted for Phase 5, #887 — Part B JS extraction.
 * Sets window.MetasyncConfig from localized data and keeps the admin-bar
 * sync status indicator in sync with the plugin page status.
 *
 * Localized data object: metasyncConfigData
 *   - pluginName (string) — effective plugin display name
 *   - ottoName   (string) — whitelabel OTTO display name
 *
 * @since Phase 5
 * @see   wp_localize_script() call in class-metasync-admin.php
 */

// Pass localized variables to global config
window.MetasyncConfig = {
    pluginName: metasyncConfigData.pluginName,
    ottoName: metasyncConfigData.ottoName
};

jQuery(document).ready(function($) {

    // Function to sync admin bar status
    function syncAdminBarStatus() {
        var pluginPageStatus = $('.metasync-integration-status .status-text').text();
        var adminBarItem = $('#wp-admin-bar-searchatlas-status .ab-item');
        var adminBarContainer = $('#wp-admin-bar-searchatlas-status');
        var pluginName = window.MetasyncConfig.pluginName;

        if (pluginPageStatus && adminBarItem.length) {
            var allClasses = 'searchatlas-synced searchatlas-not-synced searchatlas-warning';

            // Helper to update emoji in admin bar
            var updateAdminBarEmoji = function(targetEmoji, targetSvgCode) {
                var emojiImg = adminBarItem.find('img.emoji');
                if (emojiImg.length > 0) {
                    emojiImg.attr('alt', targetEmoji);
                    var currentSrc = emojiImg.attr('src');
                    var updatedSrc = currentSrc.replace(/1f7e2\.svg|1f534\.svg|1f7e1\.svg/, targetSvgCode + '.svg');
                    emojiImg.attr('src', updatedSrc);
                } else {
                    var newHtml = adminBarItem.html().replace(/\uD83D\uDFE2|\uD83D\uDD34|\uD83D\uDFE1/, targetEmoji);
                    if (!newHtml.includes(targetEmoji) && newHtml.includes(pluginName)) {
                        newHtml = newHtml.replace(pluginName, pluginName + ' ' + targetEmoji);
                    }
                    adminBarItem.html(newHtml);
                }
            };

            if (pluginPageStatus.includes('Synced') && !pluginPageStatus.includes('Not Synced')) {
                // Update admin bar to synced (GREEN)
                updateAdminBarEmoji('\uD83D\uDFE2', '1f7e2');
                adminBarContainer.removeClass(allClasses).addClass('searchatlas-synced');
                var syncTitle = pluginName + ' - Synced (Heartbeat API connectivity verified)';
                adminBarContainer.attr('title', syncTitle);
                adminBarItem.attr('title', syncTitle);

            } else if (pluginPageStatus.includes('Warning')) {
                // Update admin bar to warning (YELLOW)
                updateAdminBarEmoji('\uD83D\uDFE1', '1f7e1');
                adminBarContainer.removeClass(allClasses).addClass('searchatlas-warning');
                var warnTitle = pluginName + ' - Connected but OTTO UUID is missing \u2014 deploys will not work. Please reconnect.';
                adminBarContainer.attr('title', warnTitle);
                adminBarItem.attr('title', warnTitle);

            } else if (pluginPageStatus.includes('Not Synced')) {
                // Update admin bar to not synced (RED)
                updateAdminBarEmoji('\uD83D\uDD34', '1f534');
                adminBarContainer.removeClass(allClasses).addClass('searchatlas-not-synced');
                var notSyncTitle = pluginName + ' - Not Synced (Heartbeat API not responding or unreachable)';
                adminBarContainer.attr('title', notSyncTitle);
                adminBarItem.attr('title', notSyncTitle);
            }
        }
    }

    // Sync when tabs are switched (for General/Advanced tabs)
    $(document).on('click', 'a[href*="tab="]', function() {
        setTimeout(syncAdminBarStatus, 200);
    });

    // Also check every 5 seconds to keep it in sync
    setInterval(syncAdminBarStatus, 5000);
});
