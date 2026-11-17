<?php
/**
 * The AI Agents (MCP) admin page view: per-client MCP keys.
 *
 * Styles live in admin/css/metasync-mcp-keys.css and behaviour in
 * admin/js/metasync-mcp-keys.js; both are enqueued only on this page.
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

/**
 * Rows from Metasync_MCP_Keys::get_all_keys(), newest first.
 *
 * @var array $keys
 */

/**
 * Grouped tool inventory from Metasync_MCP_Tool_Inventory, keyed by display
 * label; each group holds 'read', 'write' counts and a 'tools' list.
 *
 * @var array $mcp_tools
 */

/**
 * Totals across all groups, from Metasync_MCP_Tool_Inventory::get_totals().
 *
 * @var array{total: int, read: int, write: int} $mcp_tool_totals
 */

$mcp_url = esc_url_raw(rest_url('metasync/v1/mcp'));
$is_secure = 0 === strpos($mcp_url, 'https://');

$key_total = count($keys);
$key_active_total = 0;
if ($key_total) {
    foreach ($keys as $counted_key) {
        if (Metasync_MCP_Keys::key_status($counted_key) === 'active') {
            $key_active_total++;
        }
    }
}
?>
<div class="metasync-mcp-keys-wrap">

    <!-- Intro: what this page is for, and the endpoint clients connect to -->
    <section class="dashboard-card metasync-mcp-intro" aria-labelledby="metasync-mcp-intro-title">
        <div class="metasync-mcp-card-head">
            <span class="metasync-mcp-card-icon dashicons dashicons-admin-network" aria-hidden="true"></span>
            <div>
                <h2 id="metasync-mcp-intro-title">Connect AI agents to this site</h2>
                <p class="metasync-mcp-lead">
                    The Model Context Protocol (MCP) lets an external AI client inspect or manage this WordPress site through a controlled connection.
                    Create a separate key for each client so you can limit what it can do and revoke it on its own.
                </p>
            </div>
        </div>

        <ol class="metasync-mcp-steps" aria-label="How connecting works">
            <li>
                <span class="metasync-mcp-step-num" aria-hidden="true">1</span>
                <span class="metasync-mcp-step-text"><strong>Create a key</strong> Name it after the AI app or person who will use it, then choose an access level.</span>
            </li>
            <li>
                <span class="metasync-mcp-step-num" aria-hidden="true">2</span>
                <span class="metasync-mcp-step-text"><strong>Copy it right away</strong> The full key is shown only once, as soon as it is created.</span>
            </li>
            <li>
                <span class="metasync-mcp-step-num" aria-hidden="true">3</span>
                <span class="metasync-mcp-step-text"><strong>Connect your AI app</strong> Paste the ready-made setup instructions shown with the key.</span>
            </li>
        </ol>

        <div class="metasync-mcp-endpoint">
            <div class="metasync-mcp-endpoint-head">
                <label class="metasync-mcp-label" for="metasync-mcp-endpoint">MCP endpoint</label>
                <?php if ($is_secure) : ?>
                    <span class="metasync-mcp-pill metasync-mcp-pill--success">HTTPS</span>
                <?php else : ?>
                    <span class="metasync-mcp-pill metasync-mcp-pill--caution">HTTP, not secure</span>
                <?php endif; ?>
            </div>
            <p class="metasync-mcp-help" id="metasync-mcp-endpoint-help">The server address your AI app asks for. It is the same for every key.</p>
            <div class="metasync-mcp-copy-field">
                <input type="text" id="metasync-mcp-endpoint" class="metasync-mcp-mono" readonly
                    aria-describedby="metasync-mcp-endpoint-help" value="<?php echo esc_attr($mcp_url); ?>" />
                <button type="button" class="button metasync-mcp-btn-secondary" data-mcp-copy="#metasync-mcp-endpoint">
                    <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                    <span class="metasync-mcp-btn-label">Copy</span>
                </button>
            </div>
        </div>

        <?php if (!$is_secure) : ?>
            <div class="metasync-mcp-callout metasync-mcp-callout--caution metasync-mcp-callout--strong metasync-mcp-transport-warning" role="alert">
                <span class="metasync-mcp-callout-icon dashicons dashicons-lock" aria-hidden="true"></span>
                <div class="metasync-mcp-callout-body">
                    <strong class="metasync-mcp-callout-title">Secure this connection before using a real key.</strong>
                    This endpoint uses HTTP, so a Bearer token could be exposed in transit. Enable HTTPS on this site before connecting an AI client.
                </div>
            </div>
        <?php endif; ?>
    </section>

    <div class="metasync-mcp-grid">

        <!-- Create key -->
        <section class="dashboard-card metasync-mcp-create" aria-labelledby="metasync-mcp-create-title">
            <div class="metasync-mcp-card-head">
                <span class="metasync-mcp-card-icon dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                <div>
                    <h2 id="metasync-mcp-create-title">Create a key</h2>
                    <p class="metasync-mcp-lead">Each key connects one AI app. You can revoke it at any time without affecting your other keys.</p>
                </div>
            </div>

            <form id="metasync-mcp-key-form" class="metasync-mcp-key-form" novalidate>
                <div class="metasync-mcp-field">
                    <label class="metasync-mcp-label" for="metasync-mcp-key-name">Key name</label>
                    <p class="metasync-mcp-help" id="metasync-mcp-key-name-help">Use a name you will recognize later, such as the person or app using it.</p>
                    <input type="text" id="metasync-mcp-key-name" name="name" maxlength="100"
                        placeholder="For example: Content team assistant" required
                        aria-describedby="metasync-mcp-key-name-help" />
                </div>

                <fieldset class="metasync-mcp-field metasync-mcp-access-field" aria-describedby="metasync-mcp-access-help">
                    <legend class="metasync-mcp-label">Access</legend>
                    <p id="metasync-mcp-access-help" class="metasync-mcp-help">Start with Read-only. Full access can change site content and settings.</p>
                    <div class="metasync-mcp-choices">
                        <label class="metasync-mcp-choice">
                            <input type="radio" name="access" value="read" checked />
                            <span class="metasync-mcp-choice-body">
                                <span class="metasync-mcp-choice-title">
                                    Read-only
                                    <span class="metasync-mcp-pill metasync-mcp-pill--info">Recommended</span>
                                </span>
                                <span class="metasync-mcp-choice-desc">Can view content and site data, but cannot change anything.</span>
                            </span>
                        </label>
                        <label class="metasync-mcp-choice">
                            <input type="radio" name="access" value="full" />
                            <span class="metasync-mcp-choice-body">
                                <span class="metasync-mcp-choice-title">Full (read and write)</span>
                                <span class="metasync-mcp-choice-desc">Can also create, update, or delete content and settings.</span>
                            </span>
                        </label>
                    </div>
                </fieldset>

                <div class="metasync-mcp-callout metasync-mcp-callout--caution metasync-mcp-callout--strong metasync-mcp-full-warning" id="metasync-mcp-full-warning" role="alert" hidden>
                    <span class="metasync-mcp-callout-icon dashicons dashicons-warning" aria-hidden="true"></span>
                    <div class="metasync-mcp-callout-body">
                        <strong class="metasync-mcp-callout-title">Full access is powerful.</strong>
                        This key can let an AI client change or delete content and settings as its owner. Confirm that this client needs write access.
                    </div>
                </div>

                <div class="metasync-mcp-field">
                    <label class="metasync-mcp-label" for="metasync-mcp-key-expiry">Expires</label>
                    <p class="metasync-mcp-help" id="metasync-mcp-key-expiry-help">Choose how long this key should work. Shorter expiry is safer.</p>
                    <select id="metasync-mcp-key-expiry" name="expiry" aria-describedby="metasync-mcp-key-expiry-help">
                        <option value="15">In 15 days</option>
                        <option value="30">In 30 days</option>
                        <option value="90" selected>In 90 days (recommended)</option>
                        <option value="365">In 1 year</option>
                        <option value="custom">Custom number of days</option>
                    </select>
                    <div id="metasync-mcp-custom-expiry" class="metasync-mcp-custom-expiry" hidden>
                        <label class="metasync-mcp-label" for="metasync-mcp-key-expiry-days">Custom expiry</label>
                        <p class="metasync-mcp-help" id="metasync-mcp-key-expiry-days-help">Enter a whole number from 1 to 365 days.</p>
                        <input type="number" id="metasync-mcp-key-expiry-days" name="expiry_days" min="1" max="365" step="1" inputmode="numeric" aria-describedby="metasync-mcp-key-expiry-days-help" />
                    </div>
                </div>

                <div class="metasync-mcp-callout metasync-mcp-callout--danger metasync-mcp-form-error" role="alert" hidden>
                    <span class="metasync-mcp-callout-icon dashicons dashicons-dismiss" aria-hidden="true"></span>
                    <div class="metasync-mcp-callout-body"><p></p></div>
                </div>

                <div class="metasync-mcp-actions">
                    <button type="submit" class="button button-primary" id="metasync-mcp-key-create">
                        <span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
                        Create key
                    </button>
                    <span class="spinner metasync-mcp-spinner"></span>
                    <span class="metasync-mcp-help metasync-mcp-actions-note">The full key is shown once, right after you create it.</span>
                </div>
            </form>
        </section>

        <!-- Safe setup guide -->
        <section class="dashboard-card metasync-mcp-guide" aria-labelledby="metasync-mcp-guide-title">
            <div class="metasync-mcp-card-head">
                <span class="metasync-mcp-card-icon dashicons dashicons-shield" aria-hidden="true"></span>
                <div>
                    <h2 id="metasync-mcp-guide-title">Safe setup</h2>
                    <p class="metasync-mcp-lead">Follow these steps for every key you create.</p>
                </div>
            </div>
            <ol class="metasync-mcp-checklist">
                <li><strong>Create one key per person or client.</strong> Separate keys let you see who has access and revoke one without disrupting the others.</li>
                <li><strong>Start with Read-only.</strong> Upgrade only when the client genuinely needs to make changes.</li>
                <li><strong>Set an expiry.</strong> Short-lived keys limit the damage if one leaks. When access is still needed, create a new key instead of leaving an old one active.</li>
                <li><strong>Copy the key once.</strong> The full token is shown only after creation and is never stored by WordPress.</li>
                <li><strong>Revoke it when finished.</strong> Revocation takes effect on the next request.</li>
            </ol>
            <div class="metasync-mcp-callout metasync-mcp-callout--caution" role="note">
                <span class="metasync-mcp-callout-icon dashicons dashicons-warning" aria-hidden="true"></span>
                <div class="metasync-mcp-callout-body">
                    <strong class="metasync-mcp-callout-title">AI agents can make real changes.</strong>
                    Full-access keys can create, update, or delete content and settings through the tools they are allowed to use.
                    Treat a full-access key like an administrator password. Use Read-only unless write access is necessary.
                </div>
            </div>
        </section>
    </div>

    <!-- Show-once panel (revealed by JS after a key is created) -->
    <section class="dashboard-card metasync-mcp-reveal" id="metasync-mcp-reveal" hidden aria-labelledby="metasync-mcp-reveal-title" tabindex="-1">
        <div class="metasync-mcp-card-head">
            <span class="metasync-mcp-card-icon metasync-mcp-card-icon--success dashicons dashicons-yes-alt" aria-hidden="true"></span>
            <div>
                <h2 id="metasync-mcp-reveal-title">Your new key</h2>
                <p class="metasync-mcp-lead">Your key is ready. Copy it, then add it to your AI app using the steps below.</p>
            </div>
        </div>

        <div class="metasync-mcp-callout metasync-mcp-callout--caution metasync-mcp-callout--strong metasync-mcp-reveal-warning" role="alert">
            <span class="metasync-mcp-callout-icon dashicons dashicons-lock" aria-hidden="true"></span>
            <div class="metasync-mcp-callout-body">
                <strong class="metasync-mcp-callout-title">This is the only time the full key is shown.</strong>
                Copy it now and store it somewhere safe.
                If you lose it, revoke the key and create a new one.
            </div>
        </div>

        <div class="metasync-mcp-field metasync-mcp-token-field">
            <label class="metasync-mcp-label" for="metasync-mcp-token">New MCP key</label>
            <div class="metasync-mcp-copy-field">
                <input type="text" id="metasync-mcp-token" class="metasync-mcp-mono metasync-mcp-token-input metasync-mcp-secret" readonly autocomplete="off" spellcheck="false" />
                <button type="button" class="button button-primary" data-mcp-copy="#metasync-mcp-token">
                    <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                    <span class="metasync-mcp-btn-label">Copy key</span>
                </button>
            </div>
        </div>

        <div class="metasync-mcp-connect">
            <h3>Connect your client</h3>
            <p class="metasync-mcp-help">Use one of these examples with the key you just copied. Never paste the key into chat, tickets, screenshots, or source control.</p>

            <div class="metasync-mcp-tabs" role="tablist" aria-label="AI app">
                <button type="button" class="metasync-mcp-tab" role="tab" id="metasync-mcp-tab-claude" aria-controls="metasync-mcp-panel-claude" aria-selected="true">Claude Code</button>
                <button type="button" class="metasync-mcp-tab" role="tab" id="metasync-mcp-tab-cursor" aria-controls="metasync-mcp-panel-cursor" aria-selected="false" tabindex="-1">Cursor</button>
                <button type="button" class="metasync-mcp-tab" role="tab" id="metasync-mcp-tab-other" aria-controls="metasync-mcp-panel-other" aria-selected="false" tabindex="-1">Other MCP apps</button>
            </div>

            <div class="metasync-mcp-tabpanel" role="tabpanel" id="metasync-mcp-panel-claude" aria-labelledby="metasync-mcp-tab-claude">
                <ol class="metasync-mcp-howto">
                    <li>Open a terminal on the computer where Claude Code is installed.</li>
                    <li>Paste this command and press Enter.</li>
                    <li>Start Claude Code and run <code>/mcp</code> to confirm the connection.</li>
                </ol>
                <div class="metasync-mcp-snippet-wrap">
                    <div class="metasync-mcp-snippet-bar">
                        <span>Terminal command</span>
                        <button type="button" class="button metasync-mcp-btn-secondary metasync-mcp-btn-small" data-mcp-copy="#metasync-mcp-claude-code">
                            <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                            <span class="metasync-mcp-btn-label">Copy command</span>
                        </button>
                    </div>
                    <pre class="metasync-mcp-snippet metasync-mcp-secret" id="metasync-mcp-claude-code"></pre>
                </div>
            </div>

            <div class="metasync-mcp-tabpanel" role="tabpanel" id="metasync-mcp-panel-cursor" aria-labelledby="metasync-mcp-tab-cursor" hidden>
                <ol class="metasync-mcp-howto">
                    <li>In Cursor, open <strong>Cursor Settings</strong>, go to the <strong>MCP</strong> section (called <strong>Tools &amp; MCP</strong> in newer versions) and add a new server. This opens <code>mcp.json</code>.</li>
                    <li>Paste this JSON. If the file already lists servers, add only the entry inside <code>mcpServers</code>.</li>
                    <li>Save the file. The server appears in Cursor's MCP list once it connects.</li>
                </ol>
                <div class="metasync-mcp-snippet-wrap">
                    <div class="metasync-mcp-snippet-bar">
                        <span>mcp.json</span>
                        <button type="button" class="button metasync-mcp-btn-secondary metasync-mcp-btn-small" data-mcp-copy="#metasync-mcp-cursor">
                            <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                            <span class="metasync-mcp-btn-label">Copy JSON</span>
                        </button>
                    </div>
                    <pre class="metasync-mcp-snippet metasync-mcp-secret" id="metasync-mcp-cursor"></pre>
                </div>
            </div>

            <div class="metasync-mcp-tabpanel" role="tabpanel" id="metasync-mcp-panel-other" aria-labelledby="metasync-mcp-tab-other" hidden>
                <p class="metasync-mcp-help">Any app that supports remote MCP servers over HTTP can connect. Enter these values where the app asks for a server URL and a request header.</p>
                <dl class="metasync-mcp-values">
                    <div class="metasync-mcp-value-row">
                        <dt><label for="metasync-mcp-other-url">Server URL</label></dt>
                        <dd class="metasync-mcp-copy-field">
                            <input type="text" id="metasync-mcp-other-url" class="metasync-mcp-mono" readonly value="<?php echo esc_attr($mcp_url); ?>" />
                            <button type="button" class="button metasync-mcp-btn-secondary" data-mcp-copy="#metasync-mcp-other-url">
                                <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                                <span class="metasync-mcp-btn-label">Copy</span>
                            </button>
                        </dd>
                    </div>
                    <div class="metasync-mcp-value-row">
                        <dt><label for="metasync-mcp-other-header-name">Header name</label></dt>
                        <dd class="metasync-mcp-copy-field">
                            <input type="text" id="metasync-mcp-other-header-name" class="metasync-mcp-mono" readonly value="Authorization" />
                            <button type="button" class="button metasync-mcp-btn-secondary" data-mcp-copy="#metasync-mcp-other-header-name">
                                <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                                <span class="metasync-mcp-btn-label">Copy</span>
                            </button>
                        </dd>
                    </div>
                    <div class="metasync-mcp-value-row">
                        <dt><label for="metasync-mcp-other-header-value">Header value</label></dt>
                        <dd class="metasync-mcp-copy-field">
                            <input type="text" id="metasync-mcp-other-header-value" class="metasync-mcp-mono metasync-mcp-secret" readonly autocomplete="off" spellcheck="false" />
                            <button type="button" class="button metasync-mcp-btn-secondary" data-mcp-copy="#metasync-mcp-other-header-value">
                                <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                                <span class="metasync-mcp-btn-label">Copy</span>
                            </button>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="metasync-mcp-reveal-footer">
            <p class="metasync-mcp-help">Closing this panel removes the key from the page for good.</p>
            <button type="button" class="button button-primary" id="metasync-mcp-reveal-done">I saved the key</button>
        </div>
    </section>

    <!-- Keys table -->
    <section class="dashboard-card metasync-mcp-list" aria-labelledby="metasync-mcp-list-title">
        <div class="metasync-mcp-card-head">
            <span class="metasync-mcp-card-icon dashicons dashicons-list-view" aria-hidden="true"></span>
            <div>
                <h2 id="metasync-mcp-list-title">Existing keys</h2>
                <?php if ($key_total) : ?>
                    <p class="metasync-mcp-lead">
                        <?php
                        echo esc_html(sprintf(
                            '%1$d %2$s, %3$d active',
                            $key_total,
                            1 === $key_total ? 'key' : 'keys',
                            $key_active_total
                        ));
                        ?>
                    </p>
                <?php else : ?>
                    <p class="metasync-mcp-lead">Keys you create appear here, along with when each was last used.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($keys)) : ?>
            <div class="metasync-mcp-empty">
                <span class="metasync-mcp-empty-icon dashicons dashicons-admin-network" aria-hidden="true"></span>
                <p class="metasync-mcp-empty-title">No MCP keys yet</p>
                <p>Create your first key above to connect an AI client to this site.</p>
                <a class="button metasync-mcp-btn-secondary" href="#metasync-mcp-key-name">Create your first key</a>
            </div>
        <?php else : ?>
            <div class="metasync-mcp-table-scroll">
                <table class="widefat metasync-mcp-keys-table">
                    <thead>
                        <tr>
                            <th scope="col" class="metasync-mcp-th-name">Name</th>
                            <th scope="col" class="metasync-mcp-th-access">Access</th>
                            <th scope="col" class="metasync-mcp-th-created">Created</th>
                            <th scope="col" class="metasync-mcp-th-used">Last used</th>
                            <th scope="col" class="metasync-mcp-th-expires">Expires</th>
                            <th scope="col" class="metasync-mcp-th-status">Status</th>
                            <th scope="col" class="metasync-mcp-th-actions"><span class="screen-reader-text">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($keys as $key_row) : ?>
                            <?php
                            $owner = get_userdata((int) $key_row->owner_id);
                            $key_status = Metasync_MCP_Keys::key_status($key_row);
                            $expiry_label = empty($key_row->expires_at) || $key_row->expires_at === '0000-00-00 00:00:00'
                                ? 'Never'
                                : mysql2date(get_option('date_format'), get_date_from_gmt($key_row->expires_at));
                            $last_used_date = 'Never';
                            $last_used_time = '';
                            if (!empty($key_row->last_used_at) && $key_row->last_used_at !== '0000-00-00 00:00:00') {
                                $last_used_local = get_date_from_gmt($key_row->last_used_at);
                                $last_used_date  = mysql2date(get_option('date_format'), $last_used_local);
                                $last_used_time  = mysql2date('H:i', $last_used_local);
                            }
                            $is_full = $key_row->access_level === Metasync_MCP_Keys::ACCESS_FULL;
                            if ($key_status === 'active') {
                                $status_label = 'Active';
                            } elseif ($key_status === 'expired') {
                                $status_label = 'Expired';
                            } else {
                                $status_label = 'Revoked';
                            }
                            ?>
                            <tr data-key-id="<?php echo esc_attr((int) $key_row->id); ?>" class="metasync-mcp-row-<?php echo esc_attr($key_status); ?>">
                                <td class="metasync-mcp-col-name" data-label="Name">
                                    <strong class="metasync-mcp-key-name"><?php echo esc_html($key_row->name); ?></strong>
                                    <code class="metasync-mcp-key-mask" title="Key (last characters only)"><?php echo esc_html(Metasync_MCP_Keys::key_mask($key_row)); ?></code>
                                </td>
                                <td data-label="Access">
                                    <span class="metasync-mcp-pill <?php echo esc_attr($is_full ? 'metasync-mcp-pill--caution' : 'metasync-mcp-pill--info'); ?> metasync-mcp-access-<?php echo esc_attr($key_row->access_level); ?>"><?php echo esc_html($is_full ? 'Full' : 'Read-only'); ?></span>
                                </td>
                                <td data-label="Created">
                                    <span class="metasync-mcp-cell"><?php echo esc_html(mysql2date(get_option('date_format'), get_date_from_gmt($key_row->created_at))); ?>
                                    <span class="metasync-mcp-sub">by <?php echo esc_html($owner ? $owner->display_name : 'Unknown'); ?></span></span>
                                </td>
                                <td data-label="Last used">
                                    <span class="metasync-mcp-cell"><?php echo esc_html($last_used_date); ?>
                                    <?php if ('' !== $last_used_time) : ?><span class="metasync-mcp-sub"><?php echo esc_html($last_used_time); ?></span><?php endif; ?></span>
                                </td>
                                <td data-label="Expires"><?php echo esc_html($expiry_label); ?></td>
                                <td data-label="Status">
                                    <span class="metasync-mcp-status metasync-mcp-status-<?php echo esc_attr($key_status); ?>"><?php echo esc_html($status_label); ?></span>
                                </td>
                                <?php /* No whitespace inside this cell: the mobile layout hides it with :empty. */ ?>
                                <td class="metasync-mcp-col-actions"><?php if ($key_status === 'active') : ?><button type="button" class="button metasync-mcp-btn-secondary metasync-mcp-btn-danger metasync-mcp-revoke"
                                            data-key-id="<?php echo esc_attr((int) $key_row->id); ?>"
                                            aria-label="<?php echo esc_attr('Revoke key: ' . $key_row->name); ?>">Revoke</button><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="metasync-mcp-help metasync-mcp-table-footnote">
                Read-only keys can list and inspect content but cannot create, change or delete anything.
                Revoking a key takes effect immediately: the client using it is disconnected on its next request.
            </p>
        <?php endif; ?>
    </section>

    <?php if (!empty($mcp_tools)) :
        $tool_total = (int) $mcp_tool_totals['total'];
        $tool_read_total = (int) $mcp_tool_totals['read'];
        $tool_write_total = (int) $mcp_tool_totals['write'];
    ?>
    <!-- Compact capability summary; individual tool names are intentionally not exposed. -->
    <section class="dashboard-card metasync-mcp-tools" aria-labelledby="metasync-mcp-tools-title">
        <div class="metasync-mcp-card-head">
            <span class="metasync-mcp-card-icon dashicons dashicons-screenoptions" aria-hidden="true"></span>
            <div>
                <h2 id="metasync-mcp-tools-title">Available capabilities</h2>
                <p class="metasync-mcp-lead">This site provides <?php echo (int) $tool_total; ?> MCP capabilities: <?php echo (int) $tool_read_total; ?> read-only and <?php echo (int) $tool_write_total; ?> write.</p>
            </div>
        </div>

        <div class="metasync-mcp-tools-stats" aria-label="MCP capability totals">
            <span class="metasync-mcp-stat"><strong><?php echo (int) $tool_total; ?></strong> total</span>
            <span class="metasync-mcp-stat metasync-mcp-stat--read"><strong><?php echo (int) $tool_read_total; ?></strong> read-only</span>
            <span class="metasync-mcp-stat metasync-mcp-stat--write"><strong><?php echo (int) $tool_write_total; ?></strong> write</span>
        </div>

        <div class="metasync-mcp-capability-groups" aria-label="Capability areas">
            <?php foreach ($mcp_tools as $group_label => $group_data) : ?>
                <div class="metasync-mcp-capability-group">
                    <span><?php echo esc_html($group_label); ?></span>
                    <strong><?php echo (int) count($group_data['tools']); ?></strong>
                    <small><?php echo (int) $group_data['read']; ?> read · <?php echo (int) $group_data['write']; ?> write</small>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <div class="screen-reader-text" id="metasync-mcp-live" aria-live="polite"></div>
</div>
