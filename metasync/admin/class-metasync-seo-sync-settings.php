<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Sync to Other SEO Plugins" settings section.
 *
 * Renders the consent switch that decides whether MetaSync may copy its values
 * into Yoast / Rank Math / AIOSEO storage, plus a plain-language status line
 * saying what is being written and how many originals are on file.
 *
 * This lives in Settings → General rather than Advanced Settings on purpose.
 * Advanced is a troubleshooting page and can be hidden three separate ways —
 * the access-control feature flag, the whitelabel hide_advanced flag, and the
 * password lock. A switch that rewrites another plugin's data must never be
 * concealable from the site owner, which is the exact problem this section
 * exists to solve.
 *
 * @package    Metasync
 * @subpackage Metasync/admin
 */
class Metasync_Seo_Sync_Settings {

    /**
     * SEO plugins whose storage MetaSync writes into, matched the same way the
     * writers match them so the status line can never disagree with reality.
     *
     * @var array<string,array<int,string>>
     */
    const DETECTED_PLUGINS = array(
        'Rank Math'      => array('seo-by-rank-math/rank-math.php', 'seo-by-rankmath/rank-math.php'),
        'Yoast SEO'      => array('wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php'),
        'All in One SEO' => array('all-in-one-seo-pack/all_in_one_seo_pack.php', 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php'),
    );

    /**
     * Whether the section should appear at all.
     *
     * Shown when an SEO plugin is active, OR the switch is on, OR originals are
     * already on file. The last two conditions matter: without them the section
     * — and with it the only view onto the saved originals — would vanish the
     * day someone deactivated Rank Math.
     *
     * @return bool
     */
    public static function should_display() {
        if (!empty(self::get_active_plugin_names())) {
            return true;
        }

        if (class_exists('Metasync_Seo_Backup') && Metasync_Seo_Backup::is_enabled()) {
            return true;
        }

        $counts = self::get_backup_counts();

        return ($counts['posts'] + $counts['terms']) > 0;
    }

    /**
     * Render the section body.
     */
    public static function render() {
        $option_key  = Metasync_Admin::option_key;
        $field_name  = $option_key . '[general][' . Metasync_Seo_Backup::CONSENT_OPTION_KEY . ']';
        $field_id    = 'metasync_' . Metasync_Seo_Backup::CONSENT_OPTION_KEY;
        $enabled     = class_exists('Metasync_Seo_Backup') && Metasync_Seo_Backup::is_enabled();
        $product     = Metasync::get_effective_plugin_name();
        $otto        = Metasync::get_whitelabel_otto_name();
        $active      = self::get_active_plugin_names();
        $counts      = self::get_backup_counts();
        $total_saved = $counts['posts'] + $counts['terms'];
        $batch       = class_exists('Metasync_Seo_Restore') ? Metasync_Seo_Restore::get_progress() : ['status' => 'idle', 'total' => 0, 'processed' => 0, 'failed' => 0, 'fields_restored' => 0];
        $is_running  = ($batch['status'] === 'running');
        ?>
        <style>
        .metasync-seo-sync { background: var(--dashboard-card-bg); padding: 20px; border-radius: 8px; }
        .metasync-seo-sync p { margin: 0; }
        .metasync-seo-sync-intro { color: var(--dashboard-text-secondary); margin: 0 0 18px 0 !important; line-height: 1.6; }

        /* Consent row — the switch is the one control in this section, so it is
           given a card of its own rather than sitting in the body text. */
        .metasync-seo-sync-consent {
            display: flex; align-items: flex-start; gap: 14px;
            padding: 16px; border-radius: 8px; cursor: pointer;
            background: var(--dashboard-input-bg);
            border: 1px solid var(--dashboard-border);
            transition: border-color .15s ease, background .15s ease;
        }
        .metasync-seo-sync-consent:hover { border-color: var(--dashboard-accent); }
        .metasync-seo-sync-consent.is-on { border-color: var(--dashboard-accent); }

        .metasync-seo-sync-switch { position: relative; flex: 0 0 auto; width: 40px; height: 22px; margin-top: 1px; }
        .metasync-seo-sync-switch input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; z-index: 2; }
        .metasync-seo-sync-slider {
            position: absolute; inset: 0; border-radius: 999px;
            background: var(--dashboard-border); transition: background .2s ease; pointer-events: none;
        }
        .metasync-seo-sync-slider::before {
            content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px;
            border-radius: 50%; background: #fff; transition: transform .2s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,.35);
        }
        .metasync-seo-sync-switch input:checked + .metasync-seo-sync-slider { background: var(--dashboard-accent); }
        .metasync-seo-sync-switch input:checked + .metasync-seo-sync-slider::before { transform: translateX(18px); }
        .metasync-seo-sync-switch input:focus-visible + .metasync-seo-sync-slider { box-shadow: 0 0 0 2px var(--dashboard-accent); }

        .metasync-seo-sync-consent-label { color: var(--dashboard-text-primary); font-weight: 600; display: block; }
        .metasync-seo-sync-consent-state { color: var(--dashboard-text-secondary); font-size: 13px; display: block; margin-top: 4px; }

        /* Facts panel: what is being written, and what can be undone. */
        .metasync-seo-sync-facts {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; margin-top: 16px;
        }
        .metasync-seo-sync-fact {
            padding: 12px 14px; border-radius: 8px;
            background: var(--dashboard-input-bg); border: 1px solid var(--dashboard-border);
        }
        .metasync-seo-sync-fact-label {
            display: block; font-size: 11px; letter-spacing: .06em; text-transform: uppercase;
            color: var(--dashboard-text-secondary); margin-bottom: 8px;
        }
        .metasync-seo-sync-fact-value { color: var(--dashboard-text-primary); font-size: 14px; font-weight: 600; }
        .metasync-seo-sync-fact-value.is-muted { color: var(--dashboard-text-secondary); font-weight: 500; }
        .metasync-seo-sync-pill {
            display: inline-block; margin: 0 6px 6px 0; padding: 3px 10px; border-radius: 999px;
            font-size: 12px; font-weight: 600; color: var(--dashboard-text-primary);
            background: rgba(59, 130, 246, .16); border: 1px solid rgba(59, 130, 246, .38);
        }
        .metasync-seo-sync-count { font-size: 18px; font-weight: 700; color: var(--dashboard-text-primary); }
        .metasync-seo-sync-count-unit { font-size: 13px; font-weight: 500; color: var(--dashboard-text-secondary); }

        .metasync-seo-sync-restore { margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--dashboard-border); }
        .metasync-seo-sync-restore-hint { color: var(--dashboard-text-secondary); font-size: 13px; margin-top: 8px !important; line-height: 1.5; }

        /* The restore button inherits the core .button styling, which is close
           to invisible against the dark dashboard card. Restating it here keeps
           it legible in both themes without touching the shared button rules. */
        #metasync-seo-restore-btn.button {
            background: transparent;
            color: var(--dashboard-text-primary);
            border: 1px solid var(--dashboard-accent);
            border-radius: 6px;
            padding: 6px 16px;
            height: auto; line-height: 1.8; font-weight: 600;
        }
        #metasync-seo-restore-btn.button:hover:not(:disabled),
        #metasync-seo-restore-btn.button:focus:not(:disabled) {
            background: var(--dashboard-accent);
            border-color: var(--dashboard-accent);
            color: #fff;
        }
        #metasync-seo-restore-btn.button:disabled { opacity: .55; }

        .metasync-seo-sync-progress {
            margin-top: 16px; padding: 14px 16px; border-radius: 8px;
            background: var(--dashboard-input-bg); border: 1px solid var(--dashboard-border);
            border-left: 3px solid var(--dashboard-accent);
        }
        .metasync-seo-sync-progress-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 10px; }
        .metasync-seo-sync-progress-track { width: 100%; height: 6px; background: var(--dashboard-border); border-radius: 3px; overflow: hidden; margin-bottom: 8px; }
        .metasync-seo-sync-progress-fill { height: 100%; background: var(--dashboard-gradient-primary); border-radius: 3px; transition: width .25s ease; }
        .metasync-seo-sync-progress-meta { font-size: 12px; color: var(--dashboard-text-secondary); }

        .metasync-seo-sync-complete {
            margin-top: 16px; padding: 12px 16px; border-radius: 8px; font-size: 13px;
            color: var(--dashboard-text-primary);
            background: rgba(16, 185, 129, .14); border-left: 3px solid var(--dashboard-success);
        }
        </style>

        <div class="metasync-seo-sync">
            <p class="metasync-seo-sync-intro">
                <?php
                printf(
                    /* translators: 1: product name, 2: OTTO product name. */
                    esc_html__('%1$s can copy the SEO values %2$s generates into whichever SEO plugin your site uses, so that plugin renders them. This overwrites what those fields hold today, so it happens only while you allow it.', 'metasync'),
                    esc_html($product),
                    esc_html($otto)
                );
                ?>
            </p>

            <label for="<?php echo esc_attr($field_id); ?>"
                   class="metasync-seo-sync-consent<?php echo $enabled ? ' is-on' : ''; ?>"
                   id="metasync-seo-sync-consent">
                <span class="metasync-seo-sync-switch">
                    <input type="checkbox"
                           id="<?php echo esc_attr($field_id); ?>"
                           name="<?php echo esc_attr($field_name); ?>"
                           value="true"
                           <?php checked($enabled); ?> />
                    <span class="metasync-seo-sync-slider"></span>
                </span>
                <span>
                    <span class="metasync-seo-sync-consent-label">
                        <?php
                        printf(
                            /* translators: %s: OTTO product name. */
                            esc_html__('Sync the %s-suggested SEO values into other plugins', 'metasync'),
                            esc_html($otto)
                        );
                        ?>
                    </span>
                    <span class="metasync-seo-sync-consent-state">
                        <?php echo esc_html(self::get_intro_line($product, $enabled)); ?>
                    </span>
                </span>
            </label>

            <div class="metasync-seo-sync-facts">
                <div class="metasync-seo-sync-fact">
                    <span class="metasync-seo-sync-fact-label"><?php esc_html_e('Currently writing to', 'metasync'); ?></span>
                    <?php if (empty($active)): ?>
                        <span class="metasync-seo-sync-fact-value is-muted">
                            <?php esc_html_e('No supported SEO plugin detected', 'metasync'); ?>
                        </span>
                    <?php else: ?>
                        <?php foreach ($active as $plugin_name): ?>
                            <span class="metasync-seo-sync-pill"><?php echo esc_html($plugin_name); ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="metasync-seo-sync-fact">
                    <span class="metasync-seo-sync-fact-label"><?php esc_html_e('Originals saved for restore', 'metasync'); ?></span>
                    <span class="metasync-seo-sync-fact-value">
                        <span class="metasync-seo-sync-count"><?php echo esc_html((string) $counts['posts']); ?></span>
                        <span class="metasync-seo-sync-count-unit"><?php esc_html_e('posts', 'metasync'); ?></span>
                        &nbsp;
                        <span class="metasync-seo-sync-count"><?php echo esc_html((string) $counts['terms']); ?></span>
                        <span class="metasync-seo-sync-count-unit"><?php esc_html_e('terms', 'metasync'); ?></span>
                    </span>
                </div>
            </div>

            <!-- Restore Button & Action Area -->
            <div class="metasync-seo-sync-restore">
                <?php if ($total_saved > 0 || $is_running): ?>
                    <button type="button"
                            id="metasync-seo-restore-btn"
                            class="button button-secondary"
                            <?php disabled($is_running); ?>
                            style="cursor: pointer;">
                        <?php esc_html_e('Restore Original SEO Values', 'metasync'); ?>
                    </button>
                    <p class="metasync-seo-sync-restore-hint">
                        <?php
                        printf(
                            /* translators: %d: number of objects with saved originals. */
                            esc_html(_n(
                                'Puts the saved original value back on %d object and turns syncing off.',
                                'Puts the saved original values back on all %d objects and turns syncing off.',
                                $total_saved,
                                'metasync'
                            )),
                            (int) $total_saved
                        );
                        ?>
                    </p>
                <?php else: ?>
                    <p class="metasync-seo-sync-restore-hint" style="margin-top: 0 !important;">
                        <?php esc_html_e('Nothing to restore yet. Originals are saved the first time a value is overwritten, and the restore button appears here once there is something to put back.', 'metasync'); ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Restore Batch Progress Card -->
            <div id="metasync-seo-restore-progress"
                 class="metasync-seo-sync-progress"
                 <?php echo $is_running ? '' : 'style="display:none;"'; ?>>
                <div class="metasync-seo-sync-progress-head">
                    <span id="metasync-seo-restore-text" style="color: var(--dashboard-text-primary); font-size: 13px; font-weight: 600;">
                        <?php
                        if ($is_running) {
                            printf(
                                esc_html__('Restoring %1$d of %2$d items...', 'metasync'),
                                (int) $batch['processed'],
                                (int) $batch['total']
                            );
                        }
                        ?>
                    </span>
                    <button type="button" class="button button-small" id="metasync-seo-restore-cancel-btn">
                        <?php esc_html_e('Cancel', 'metasync'); ?>
                    </button>
                </div>
                <div class="metasync-seo-sync-progress-track">
                    <div id="metasync-seo-restore-fill"
                         class="metasync-seo-sync-progress-fill"
                         style="width: <?php
                            echo ($batch['total'] > 0) ? esc_attr((string) round(($batch['processed'] / $batch['total']) * 100)) : '0';
                         ?>%;"></div>
                </div>
                <div class="metasync-seo-sync-progress-meta">
                    <span id="metasync-seo-restore-processed"><?php echo esc_html($batch['processed']); ?></span> /
                    <span id="metasync-seo-restore-total"><?php echo esc_html($batch['total']); ?></span>
                    <?php esc_html_e('items processed', 'metasync'); ?>
                </div>
            </div>

            <!-- Restore Batch Complete Notice -->
            <div id="metasync-seo-restore-complete" class="metasync-seo-sync-complete" style="display:none;">
                <span id="metasync-seo-restore-complete-text"></span>
            </div>
        </div>

        <script type="text/javascript">
        (function () {
            var toggle = document.getElementById('<?php echo esc_js($field_id); ?>');
            var consentRow = document.getElementById('metasync-seo-sync-consent');
            var restoreBtn = document.getElementById('metasync-seo-restore-btn');
            var cancelBtn = document.getElementById('metasync-seo-restore-cancel-btn');
            var progressCard = document.getElementById('metasync-seo-restore-progress');
            var completeCard = document.getElementById('metasync-seo-restore-complete');
            var progressText = document.getElementById('metasync-seo-restore-text');
            var progressFill = document.getElementById('metasync-seo-restore-fill');
            var processedSpan = document.getElementById('metasync-seo-restore-processed');
            var totalSpan = document.getElementById('metasync-seo-restore-total');
            var completeText = document.getElementById('metasync-seo-restore-complete-text');

            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('metasync_seo_restore_nonce')); ?>;
            var BATCH_MAX_ERRORS = 5;
            var batchActive = <?php echo $is_running ? 'true' : 'false'; ?>;
            var batchErrorCount = 0;
            var fallbackPoll = null;

            if (toggle) {
                // The input sits inside its label for the larger click target.
                // Stop the input click from bubbling back to the label, which
                // would activate the checkbox a second time in some browsers.
                toggle.addEventListener('click', function (event) {
                    event.stopPropagation();
                });

                // Turning this on starts permanent writes into another plugin's
                // data, so it is confirmed before the form is even submitted.
                // Turning it off needs no confirmation: it only stops future writes.
                toggle.addEventListener('change', function () {
                    if (toggle.checked && !window.confirm(<?php echo wp_json_encode(self::get_confirmation_text($product, $otto)); ?>)) {
                        toggle.checked = false;
                    }

                    if (consentRow) {
                        consentRow.classList.toggle('is-on', toggle.checked);
                    }
                });
            }

            if (restoreBtn) {
                restoreBtn.addEventListener('click', function () {
                    var confirmMsg = <?php echo wp_json_encode(
                        sprintf(
                            __('This will restore the original SEO values on all %d objects and disable syncing to other plugins. Are you sure you want to proceed?', 'metasync'),
                            $total_saved
                        )
                    ); ?>;

                    if (!window.confirm(confirmMsg)) {
                        return;
                    }

                    restoreBtn.disabled = true;
                    if (toggle) {
                        toggle.checked = false;
                    }
                    if (consentRow) {
                        consentRow.classList.remove('is-on');
                    }

                    var params = new URLSearchParams();
                    params.append('action', 'metasync_seo_restore_start');
                    params.append('nonce', nonce);

                    fetch(ajaxUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: params.toString()
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        if (!resp.success) {
                            alert(resp.data || <?php echo wp_json_encode(__('Failed to start restoration.', 'metasync')); ?>);
                            restoreBtn.disabled = false;
                            return;
                        }
                        if (resp.data && resp.data.error) {
                            // e.g. consent could not be disabled — server refused to start.
                            alert(resp.data.error);
                            restoreBtn.disabled = false;
                            return;
                        }
                        batchActive = true;
                        batchErrorCount = 0;
                        updateProgressUI(resp.data);
                        startFallbackPoll();
                        processNextTick();
                    })
                    .catch(function () {
                        alert(<?php echo wp_json_encode(__('Network error starting restoration.', 'metasync')); ?>);
                        restoreBtn.disabled = false;
                    });
                });
            }

            if (cancelBtn) {
                cancelBtn.addEventListener('click', function () {
                    cancelBtn.disabled = true;
                    var params = new URLSearchParams();
                    params.append('action', 'metasync_seo_restore_cancel');
                    params.append('nonce', nonce);

                    fetch(ajaxUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: params.toString()
                    })
                    .then(function () {
                        batchActive = false;
                        stopFallbackPoll();
                        if (progressCard) progressCard.style.display = 'none';
                        if (restoreBtn) restoreBtn.disabled = false;
                        cancelBtn.disabled = false;
                        location.reload();
                    });
                });
            }

            function updateProgressUI(progress) {
                if (!progressCard) return;
                progressCard.style.display = 'block';
                if (completeCard) completeCard.style.display = 'none';

                var total = progress.total || 0;
                var processed = progress.processed || 0;
                var pct = total > 0 ? Math.round((processed / total) * 100) : 0;

                if (progressFill) progressFill.style.width = pct + '%';
                if (processedSpan) processedSpan.textContent = processed;
                if (totalSpan) totalSpan.textContent = total;
                if (progressText) {
                    progressText.textContent = <?php echo wp_json_encode(__('Restoring', 'metasync')); ?> + ' ' + processed + ' / ' + total + ' (' + pct + '%)';
                }
            }

            function processNextTick() {
                if (!batchActive) return;

                var params = new URLSearchParams();
                params.append('action', 'metasync_seo_restore_process_tick');
                params.append('nonce', nonce);

                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString()
                })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (!resp.success) {
                        batchErrorCount++;
                        if (batchErrorCount >= BATCH_MAX_ERRORS) {
                            onBatchError();
                            return;
                        }
                        setTimeout(processNextTick, 3000);
                        return;
                    }

                    batchErrorCount = 0;
                    var data = resp.data;
                    updateProgressUI(data);

                    if (data.status === 'completed' || data.status === 'cancelled' || data.processed >= data.total) {
                        onBatchFinished(data);
                    } else {
                        setTimeout(processNextTick, 300);
                    }
                })
                .catch(function () {
                    if (!batchActive) return;
                    batchErrorCount++;
                    if (batchErrorCount >= BATCH_MAX_ERRORS) {
                        onBatchError();
                        return;
                    }
                    setTimeout(processNextTick, 5000);
                });
            }

            function onBatchError() {
                batchActive = false;
                stopFallbackPoll();
                if (progressCard) progressCard.style.display = 'none';
                if (restoreBtn) restoreBtn.disabled = false;
                alert(<?php echo wp_json_encode(__('Restoration paused after repeated errors. Please reload the page to check status or resume.', 'metasync')); ?>);
            }

            function onBatchFinished(progress) {
                batchActive = false;
                stopFallbackPoll();
                if (progressCard) progressCard.style.display = 'none';

                if (progress.error) {
                    // e.g. consent could not be kept off, or could not be
                    // switched back on after an empty run.
                    alert(progress.error);
                }

                if (progress.status === 'completed') {
                    if (completeCard && completeText) {
                        completeText.textContent = <?php echo wp_json_encode(__('Restoration complete!', 'metasync')); ?> + ' ' +
                            progress.processed + ' ' + <?php echo wp_json_encode(__('objects restored.', 'metasync')); ?> +
                            (progress.failed > 0 ? ' (' + progress.failed + ' failed)' : '');
                        completeCard.style.display = 'block';
                    }
                }

                if (restoreBtn) restoreBtn.disabled = false;
                setTimeout(function () { location.reload(); }, 2000);
            }

            function startFallbackPoll() {
                if (fallbackPoll) return;
                fallbackPoll = setInterval(function () {
                    var params = new URLSearchParams();
                    params.append('action', 'metasync_seo_restore_progress');
                    params.append('nonce', nonce);

                    fetch(ajaxUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: params.toString()
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        if (!resp.success) return;
                        updateProgressUI(resp.data);
                        if (resp.data.status !== 'running') {
                            onBatchFinished(resp.data);
                        }
                    });
                }, 10000);
            }

            function stopFallbackPoll() {
                if (fallbackPoll) {
                    clearInterval(fallbackPoll);
                    fallbackPoll = null;
                }
            }

            if (batchActive) {
                startFallbackPoll();
                processNextTick();
            }
        })();
        </script>
        <?php
    }

    /**
     * One-line statement of the current consent state, shown under the switch.
     *
     * @param string $product Effective (whitelabel-aware) product name.
     * @param bool   $enabled Whether the switch is on.
     * @return string
     */
    private static function get_intro_line($product, $enabled) {
        if ($enabled) {
            /* translators: %s: product name. */
            return sprintf(__('%s is allowed to write into other SEO plugins.', 'metasync'), $product);
        }

        /* translators: %s: product name. */
        return sprintf(__('%s is not writing into other SEO plugins.', 'metasync'), $product);
    }

    /**
     * Text of the confirmation shown when the switch is turned on.
     *
     * Names the fields that are actually covered. Image alt text, heading
     * rewrites and link corrections are deliberately absent: they are edits to
     * WordPress's own attachment meta and to post content, never writes into
     * another SEO plugin, so this switch does not govern them and the copy must
     * not suggest that it does.
     *
     * @param string $product Effective (whitelabel-aware) product name.
     * @param string $otto    Effective (whitelabel-aware) OTTO name.
     * @return string
     */
    private static function get_confirmation_text($product, $otto) {
        return sprintf(
            /* translators: 1: product name, 2: OTTO product name. */
            __(
                "%1\$s will write the SEO title, meta description, focus keyword, Open Graph and Twitter values, canonical URL and schema %2\$s suggests into your active SEO plugin's fields, replacing what is there now.\n\n"
                . "The value each field held before is saved first, so it can be restored.\n\n"
                . "Turning this off later stops further writes. It does not undo writes already made — restoring the saved originals does that.\n\n"
                . "This does not cover image alt text, heading rewrites or link corrections. Those change WordPress content directly and are controlled separately.",
                'metasync'
            ),
            $product,
            $otto
        );
    }

    /**
     * Names of the SEO plugins currently active.
     *
     * @return array<int,string>
     */
    private static function get_active_plugin_names() {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $names = array();

        foreach (self::DETECTED_PLUGINS as $name => $files) {
            foreach ($files as $file) {
                if (is_plugin_active($file)) {
                    $names[] = $name;
                    break;
                }
            }
        }

        return $names;
    }

    /**
     * How many posts and terms hold saved originals.
     *
     * @return array{posts:int,terms:int}
     */
    private static function get_backup_counts() {
        if (!class_exists('Metasync_Seo_Backup')) {
            return array('posts' => 0, 'terms' => 0);
        }

        // Memoised for the request: should_display() and render() both need it,
        // and each call is two indexed COUNT queries we have no reason to repeat.
        static $counts = null;

        if ($counts === null) {
            $counts = Metasync_Seo_Backup::count_objects_with_backups();
        }

        return $counts;
    }
}
