<?php
/**
 * MetaSync HTML Visual Editor Page Template
 *
 * @package    Metasync
 * @subpackage Metasync/admin/partials
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="metasync-html-editor-wrapper">
    <!-- Editor Header -->
    <div class="metasync-editor-header">
        <div class="metasync-editor-header-left">
            <a href="<?php echo esc_url(admin_url('edit.php?post_type=page')); ?>" class="metasync-back-button">
                <span class="dashicons dashicons-arrow-left-alt2"></span>
                <?php _e('Back to Pages', 'metasync'); ?>
            </a>
            <div class="metasync-page-title">
                <strong><?php echo esc_html($post->post_title); ?></strong>
                <span class="metasync-editor-badge">⚡ <?php echo esc_html($label); ?></span>
            </div>
        </div>

        <div class="metasync-editor-header-center">
            <div class="metasync-editor-status">
                <span class="metasync-status-indicator"></span>
                <span class="metasync-status-text"><?php _e('Ready', 'metasync'); ?></span>
            </div>
        </div>

        <div class="metasync-editor-header-right">
            <button type="button" class="button metasync-preview-button" title="<?php esc_attr_e('Preview', 'metasync'); ?>">
                <span class="dashicons dashicons-visibility"></span>
                <?php _e('Preview', 'metasync'); ?>
            </button>
            <button type="button" class="button button-primary metasync-save-button" title="<?php esc_attr_e('Save Changes', 'metasync'); ?>">
                <span class="dashicons dashicons-saved"></span>
                <?php _e('Save', 'metasync'); ?>
            </button>
        </div>
    </div>

    <!-- Editor Container -->
    <div class="metasync-editor-workspace">
        <div class="metasync-editor-container" id="metasync-gjs-editor">
            <!-- GrapesJS will initialize here -->
        </div>

        <!--
            Sidebar for the editor's own managers. GrapesJS empties its
            container element when it initializes, so these mount points live
            outside it and are handed to the editor as `appendTo` targets. Each
            manager renders itself into its own mount, which keeps the panels in
            the DOM instead of relying on timed manual re-parenting.
        -->
        <div class="metasync-editor-sidebar" id="metasync-editor-sidebar">
            <div class="metasync-selected-element" id="metasync-selected-element" hidden>
                <div class="metasync-selected-element-label"><?php esc_html_e('Editing Element', 'metasync'); ?></div>
                <div class="metasync-selected-element-name" id="metasync-element-name"></div>
            </div>

            <div class="metasync-editor-panel-switcher" id="metasync-editor-panel-switcher"></div>

            <div class="metasync-editor-panel" id="metasync-panel-styles" data-metasync-panel="styles">
                <div class="metasync-editor-selectors" id="metasync-editor-selectors"></div>
                <div class="metasync-editor-styles" id="metasync-editor-styles"></div>
            </div>
            <div class="metasync-editor-panel" id="metasync-panel-traits" data-metasync-panel="traits" hidden>
                <div class="metasync-editor-traits" id="metasync-editor-traits"></div>
            </div>
            <div class="metasync-editor-panel" id="metasync-panel-layers" data-metasync-panel="layers" hidden>
                <div class="metasync-editor-layers" id="metasync-editor-layers"></div>
            </div>
            <div class="metasync-editor-panel" id="metasync-panel-blocks" data-metasync-panel="blocks" hidden>
                <div class="metasync-editor-blocks" id="metasync-editor-blocks"></div>
            </div>
        </div>
    </div>

    <!--
        Transient action feedback (upload/save failures, expired session).
        Written by the editor script; the live region lets assistive
        technology announce it without blocking interaction the way
        alert() does.
    -->
    <div class="metasync-editor-notice" id="metasync-editor-notice" role="alert" aria-live="assertive" hidden></div>

    <!--
        Shown only when an editor dependency fails to load or the canvas fails
        to start, so the editor explains itself instead of rendering blank.
    -->
    <div class="metasync-load-failure" id="metasync-editor-load-failure" role="alert" aria-live="assertive" hidden>
        <div class="metasync-load-failure-inner">
            <span class="dashicons dashicons-warning metasync-load-failure-icon" aria-hidden="true"></span>
            <h2 class="metasync-load-failure-title"></h2>
            <p class="metasync-load-failure-message"></p>
            <p class="metasync-load-failure-detail"></p>
            <div class="metasync-load-failure-actions">
                <button type="button" class="button button-primary metasync-load-failure-reload"></button>
                <button type="button" class="button metasync-load-failure-dismiss" style="display:none;"></button>
                <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=page')); ?>"><?php _e('Back to Pages', 'metasync'); ?></a>
            </div>
        </div>
    </div>

    <!-- Hidden data -->
    <textarea id="metasync-html-content" style="display:none;"><?php echo esc_textarea($html_content); ?></textarea>
</div>

<script>
/*
 * Record editor assets that fail to load. Runs before the footer scripts so a
 * blocked or missing dependency can be named in the on-page diagnostic rather
 * than surfacing as an empty canvas. Only records names; shows nothing itself.
 */
(function() {
    'use strict';

    window.metasyncEditorAssets = window.metasyncEditorAssets || { failed: [] };

    document.addEventListener('error', function(event) {
        var el = event.target;
        if (!el || !el.tagName) {
            return;
        }

        var src = el.tagName === 'SCRIPT' ? el.src : (el.tagName === 'LINK' ? el.href : '');
        if (!src) {
            return;
        }

        var name = '';
        if (src.indexOf('grapesjs-blocks-basic') !== -1) {
            name = 'grapesjs-blocks-basic';
        } else if (src.indexOf('grapes.min') !== -1) {
            name = 'grapesjs';
        } else {
            return;
        }

        if (window.metasyncEditorAssets.failed.indexOf(name) === -1) {
            window.metasyncEditorAssets.failed.push(name);
        }
    }, true);
})();
</script>

<style>
/* Inline critical styles to prevent flash */
.metasync-html-editor-wrapper {
    position: fixed;
    top: 32px;
    left: 0;
    right: 0;
    bottom: 0;
    background: #1e1e1e;
    z-index: 99999;
}

@media screen and (max-width: 782px) {
    .metasync-html-editor-wrapper {
        top: 46px;
    }
}

#wpadminbar {
    z-index: 100000;
}

/* Dependency / start-up failure notice. Hidden until the editor reports one. */
.metasync-load-failure[hidden] {
    display: none;
}

.metasync-load-failure {
    position: absolute;
    top: 56px;
    left: 0;
    right: 0;
    display: flex;
    justify-content: center;
    padding: 24px;
    z-index: 100001;
}

.metasync-load-failure-fatal {
    top: 0;
    bottom: 0;
    align-items: center;
    background: #1e1e1e;
}

.metasync-load-failure-inner {
    max-width: 560px;
    padding: 24px;
    border: 1px solid #3c434a;
    border-radius: 6px;
    background: #23282d;
    color: #f0f0f1;
    text-align: center;
}

.metasync-load-failure-icon {
    font-size: 32px;
    width: 32px;
    height: 32px;
    color: #dba617;
}

.metasync-load-failure-title {
    margin: 12px 0 8px;
    font-size: 18px;
    color: #f0f0f1;
}

.metasync-load-failure-message {
    margin: 0 0 8px;
    line-height: 1.6;
    color: #c3c4c7;
}

.metasync-load-failure-detail {
    margin: 0 0 16px;
    font-family: Menlo, Consolas, monospace;
    font-size: 12px;
    color: #a7aaad;
}

.metasync-load-failure-actions {
    display: flex;
    gap: 8px;
    justify-content: center;
    flex-wrap: wrap;
}
</style>
