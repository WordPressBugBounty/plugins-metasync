<?php
/**
 * Host Blocking Check
 *
 * Single owner of the "can this site and Search Atlas reach each other?" probe.
 *
 * The probe itself is not new — it has existed as two AJAX-only handlers behind the
 * Compatibility page's "Host Blocking Test" button. This class extracts that logic so
 * exactly one code path serves both callers:
 *
 *   1. the manual button on the Compatibility page (via Metasync_Admin_Ajax), and
 *   2. an automatic check that runs ~10 minutes after activation and weekly thereafter.
 *
 * WHAT THE PROBE ACTUALLY MEASURES (important, and easy to get wrong):
 * `wp-check.searchatlas.com/ping` reads the `X-WordPress-Site` header and then calls
 * `{site}/wp-json/metasync/v1/ping` back with both a GET and a POST, returning the status
 * code of each leg. So a single call exercises TWO directions:
 *
 *   - OUTBOUND (this site -> Search Atlas): did our own wp_remote_get/post reach the
 *     checker at all? A WP_Error here means outbound HTTP is failing.
 *   - INBOUND (Search Atlas -> this site): did the checker's callback reach
 *     `metasync/v1/ping`? `results.{get,post}.statusCode !== 200` means something in
 *     front of WordPress (firewall, WAF, CDN rule, security plugin) is blocking it.
 *
 * Both directions break the same user-visible features, so both are reported — but the
 * details string always says which direction failed, because the fix differs.
 *
 * FALSE POSITIVES ARE THE PRIMARY RISK. A wrong warning erodes trust in the plugin more
 * than a missed one, so the classifier only reports `blocked` on positive evidence:
 *
 *   - transport error + control probe to Search Atlas ALSO fails -> blocked (outbound)
 *   - transport error + control probe SUCCEEDS                   -> checker is down, NOT blocked
 *   - non-200 from the checker itself                            -> inconclusive, NOT blocked
 *   - 200 but unparseable/unexpected body                        -> inconclusive, NOT blocked
 *   - 200 + parsed leg statusCode !== 200                        -> blocked (inbound)
 *
 * Everything inconclusive fails open (no warning) and is logged instead.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 * @since      2.8.x
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Metasync_Host_Blocking_Check
 */
class Metasync_Host_Blocking_Check
{
    /**
     * Singleton instance.
     *
     * @var Metasync_Host_Blocking_Check|null
     */
    private static $instance = null;

    /**
     * External connectivity checker.
     */
    const CHECK_ENDPOINT = 'https://wp-check.searchatlas.com/ping';

    /**
     * Minimal stored result: get_blocked, post_blocked, checked_at. Nothing else —
     * response bodies and headers are deliberately never persisted.
     */
    const RESULT_OPTION = 'metasync_last_host_blocking_check';

    /**
     * Stores the `checked_at` of the result the user dismissed the notice for.
     * Because each check writes a fresh `checked_at`, a new cycle invalidates an old
     * dismissal automatically — no cleanup pass, no per-page-load re-evaluation.
     */
    const NOTICE_DISMISSED_OPTION = 'metasync_host_blocking_notice_dismissed_cycle';

    /**
     * One-shot check scheduled ~10 minutes after activation/upgrade.
     */
    const HOOK_INITIAL = 'metasync_host_blocking_check';

    /**
     * Dedicated weekly recheck. Intentionally NOT attached to the heartbeat cron:
     * that job is paused and rescheduled by connection state, so piggybacking on it
     * would silently stop covering host blocking on exactly the sites that need it.
     */
    const HOOK_WEEKLY = 'metasync_host_blocking_weekly_check';

    /**
     * Delay before the post-activation check runs.
     */
    const INITIAL_DELAY = 600; // 10 * MINUTE_IN_SECONDS

    /**
     * Request timeout, shared by the probe and the control probe so a merely-slow host
     * cannot be classified as blocked by one leg timing out earlier than the other.
     */
    const REQUEST_TIMEOUT = 30;

    /**
     * Memoised control-probe outcome per HTTP method (per PHP request).
     *
     * Keyed by method, because a host can allow one verb and block the other — that is
     * precisely the case this check exists to catch.
     *
     * @var array<string,bool>
     */
    private $control_probe_ok = [];

    /**
     * Constructor. Protected rather than private so tests can subclass the HTTP seams
     * (perform_request / perform_control_request) without a live network.
     */
    protected function __construct()
    {
        $this->init_hooks();
    }

    /**
     * Get singleton instance.
     *
     * @return Metasync_Host_Blocking_Check
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register WordPress hooks.
     */
    private function init_hooks()
    {
        // Both cron hooks run the same check.
        add_action(self::HOOK_INITIAL, [$this, 'run_scheduled_check']);
        add_action(self::HOOK_WEEKLY, [$this, 'run_scheduled_check']);

        // Self-heal scheduling on every load (also covers plugin updates, where the
        // activation hook does not fire).
        add_action('init', [$this, 'maybe_schedule_checks']);

        if (is_admin()) {
            add_action('admin_notices', [$this, 'display_notice']);
            add_action('wp_ajax_metasync_dismiss_host_blocking_notice', [$this, 'ajax_dismiss_notice']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        }
    }

    // ------------------------------------------------------------------
    //  Scheduling
    // ------------------------------------------------------------------

    /**
     * Ensure both the one-shot and the weekly check are scheduled.
     *
     * The one-shot event is only scheduled when no result has ever been stored, so an
     * established install does not re-run it on every page load.
     */
    public function maybe_schedule_checks()
    {
        if (!wp_next_scheduled(self::HOOK_WEEKLY)) {
            // First recurrence a week out; the one-shot below covers "soon".
            wp_schedule_event(time() + self::INITIAL_DELAY + WEEK_IN_SECONDS, 'metasync_weekly', self::HOOK_WEEKLY);
        }

        if (get_option(self::RESULT_OPTION, false) === false && !wp_next_scheduled(self::HOOK_INITIAL)) {
            self::schedule_initial_check();
        }
    }

    /**
     * Schedule the post-activation one-shot check.
     *
     * Called from Metasync_Activator::activate(). The check is NEVER run synchronously
     * during activation: blocking HTTP calls in the activation request have taken sites
     * down on slow hosts before (see the flush_rewrite_rules note in the activator).
     */
    public static function schedule_initial_check()
    {
        if (!wp_next_scheduled(self::HOOK_INITIAL)) {
            wp_schedule_single_event(time() + self::INITIAL_DELAY, self::HOOK_INITIAL);
        }
    }

    // ------------------------------------------------------------------
    //  The shared probe
    // ------------------------------------------------------------------

    /**
     * Run one leg of the host blocking check.
     *
     * This is the single shared implementation used by the manual Compatibility-page
     * button and by the automatic check. The returned array is a superset of the shape
     * the Compatibility page's JS already consumes, so the manual UI keeps working.
     *
     * @param string $method 'GET' or 'POST'.
     * @return array{
     *     method:string, status:string, blocked:bool, checker_unreachable:bool,
     *     details:string, response_time:string
     * }
     */
    public function run_check($method = 'GET')
    {
        $method   = strtoupper((string) $method) === 'POST' ? 'POST' : 'GET';

        $args = [
            'timeout'    => self::REQUEST_TIMEOUT,
            'user-agent' => 'MetaSync Plugin Host Test',
            'headers'    => [
                'Accept'           => 'application/json',
                'Content-Type'     => 'application/json',
                'Origin'           => home_url(),
                'Referer'          => admin_url(),
                'X-WordPress-Site' => home_url(),
            ],
        ];

        $sent_data = null;
        if ($method === 'POST') {
            $sent_data = [
                'test'      => 'host_blocking_test',
                'timestamp' => current_time('mysql'),
                'source'    => 'metasync_plugin',
                'method'    => 'POST',
            ];
            $args['body'] = wp_json_encode($sent_data);
        }

        $start    = microtime(true);
        $response = $this->perform_request($method, $args);
        $elapsed  = round((microtime(true) - $start) * 1000, 2);

        $result = $this->classify_response($response, $method);

        $result['method']        = $method;
        $result['response_time'] = $elapsed . 'ms';
        if ($sent_data !== null) {
            $result['sent_data'] = $sent_data;
        }

        return $result;
    }

    /**
     * Issue the probe request. Isolated so tests can substitute canned responses.
     *
     * @param string $method 'GET' or 'POST'.
     * @param array  $args   wp_remote_* arguments.
     * @return array|WP_Error
     */
    protected function perform_request($method, array $args)
    {
        return ($method === 'POST')
            ? wp_remote_post(self::CHECK_ENDPOINT, $args)
            : wp_remote_get(self::CHECK_ENDPOINT, $args);
    }

    /**
     * Issue the outbound control request. Isolated for the same reason.
     *
     * @param string $url Control endpoint.
     * @return array|WP_Error
     */
    protected function perform_control_request($url, $method = 'GET')
    {
        $args = [
            'timeout'     => self::REQUEST_TIMEOUT,
            'user-agent'  => 'MetaSync Plugin Host Test',
            'redirection' => 0,
        ];

        if ($method === 'POST') {
            $args['headers'] = ['Content-Type' => 'application/json'];
            $args['body']    = wp_json_encode(['source' => 'metasync_plugin', 'test' => 'control']);

            return wp_remote_post($url, $args);
        }

        return wp_remote_get($url, $args);
    }

    /**
     * Normalise the headers bag from wp_remote_retrieve_headers() into a plain array.
     *
     * Modern WordPress returns a CaseInsensitiveDictionary; very old versions and some
     * HTTP transports hand back a plain array.
     *
     * @param mixed $headers Whatever wp_remote_retrieve_headers() returned.
     * @return array
     */
    private function headers_to_array($headers)
    {
        if (is_array($headers)) {
            return $headers;
        }

        if (is_object($headers) && is_callable([$headers, 'getAll'])) {
            return (array) call_user_func([$headers, 'getAll']);
        }

        return [];
    }

    /**
     * Write a diagnostic line. Isolated so tests can capture it instead of polluting
     * output, and so a future logger swap has one call site.
     *
     * @param string $message Message to log.
     */
    protected function log($message)
    {
        error_log('[MetaSync HOST_BLOCKING] ' . $message);
    }

    /**
     * Turn a wp_remote_* return value into a blocked / not-blocked / inconclusive verdict.
     *
     * Kept separate from the HTTP call so the decision table can be unit tested without
     * network access.
     *
     * @param array|WP_Error $response Raw wp_remote_* result.
     * @param string         $method   'GET' or 'POST'.
     * @return array
     */
    public function classify_response($response, $method)
    {
        $method = strtoupper((string) $method) === 'POST' ? 'POST' : 'GET';
        $leg    = strtolower($method);

        // ── Transport failure: outbound blocked, or the checker itself is down? ──
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();

            if ($this->control_probe_succeeds($method)) {
                // Search Atlas answers this same verb, so outbound HTTP works for it and
                // the checker service is simply unavailable. Not a host problem.
                return [
                    'status'              => 'warning',
                    'blocked'             => false,
                    'checker_unreachable' => true,
                    'error'               => $error_message,
                    'details'             => sprintf(
                        'Could not reach the connectivity checker (%1$s). A %2$s request to %3$s succeeded, so this is NOT being reported as host blocking — the checker service was unavailable. Error: %4$s',
                        self::CHECK_ENDPOINT,
                        $method,
                        $this->get_control_endpoint(),
                        $error_message
                    ),
                ];
            }

            return [
                'status'              => 'error',
                'blocked'             => true,
                'checker_unreachable' => false,
                'error'               => $error_message,
                'details'             => sprintf(
                    'Outbound %1$s requests are failing. Neither the connectivity checker nor %2$s could be reached from this server with a %1$s request, which means this host is blocking outbound %1$s traffic. Error: %3$s',
                    $method,
                    $this->get_control_endpoint(),
                    $error_message
                ),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body        = wp_remote_retrieve_body($response);
        $headers     = wp_remote_retrieve_headers($response);

        $base = [
            'status_code' => $status_code,
            'body'        => $body,
            'headers'     => $this->headers_to_array($headers),
        ];

        // ── The checker answered, but not with a usable result ──
        if ((int) $status_code !== 200) {
            return array_merge($base, [
                'status'              => 'warning',
                'blocked'             => false,
                'checker_unreachable' => true,
                'details'             => sprintf(
                    'The connectivity checker returned HTTP %1$d, so the %2$s check is inconclusive. The request did leave this server, so this is NOT being reported as host blocking.',
                    (int) $status_code,
                    $method
                ),
            ]);
        }

        $parsed = json_decode($body, true);

        if (!is_array($parsed) || !isset($parsed['results'][$leg]) || !is_array($parsed['results'][$leg])) {
            return array_merge($base, [
                'status'              => 'warning',
                'blocked'             => false,
                'checker_unreachable' => true,
                'parsed_response'     => is_array($parsed) ? $parsed : null,
                'details'             => sprintf(
                    'The connectivity checker responded but its payload did not contain a "%1$s" result, so the check is inconclusive. This is NOT being reported as host blocking.',
                    $leg
                ),
            ]);
        }

        $inner_code = isset($parsed['results'][$leg]['statusCode'])
            ? (int) $parsed['results'][$leg]['statusCode']
            : 0;

        if ($inner_code === 200) {
            return array_merge($base, [
                'status'              => 'success',
                'blocked'             => false,
                'checker_unreachable' => false,
                'parsed_response'     => $parsed,
                'details'             => sprintf('%s request completed successfully in both directions.', $method),
            ]);
        }

        // Positive evidence of blocking: we reached Search Atlas, and its callback to
        // this site did not reach WordPress cleanly.
        return array_merge($base, [
            'status'              => 'error',
            'blocked'             => true,
            'checker_unreachable' => false,
            'parsed_response'     => $parsed,
            'details'             => sprintf(
                '%1$s reached this site with a %2$s request but received HTTP %3$s from %4$s instead of 200 — the request is being blocked before it reaches WordPress (firewall, WAF, CDN rule, or security plugin).',
                Metasync::get_effective_plugin_name(),
                $method,
                $inner_code > 0 ? (string) $inner_code : 'no response',
                home_url('/wp-json/metasync/v1/ping')
            ),
        ]);
    }

    /**
     * Control probe: can this server reach Search Atlas at all, using this same verb?
     *
     * The verb matters. A host that permits GET but blocks outbound POST is a real and
     * common configuration, and probing it with GET would "prove" outbound works and
     * misfile the blocked POST as a checker outage. Any HTTP response — including 4xx
     * and 405 — proves the verb gets out, so only a transport-level failure counts as
     * unreachable. Memoised per verb.
     *
     * @param string $method 'GET' or 'POST'.
     * @return bool True when Search Atlas is reachable with this verb.
     */
    private function control_probe_succeeds($method)
    {
        if (array_key_exists($method, $this->control_probe_ok)) {
            return $this->control_probe_ok[$method];
        }

        $response = $this->perform_control_request($this->get_control_endpoint(), $method);

        $this->control_probe_ok[$method] = !is_wp_error($response);

        return $this->control_probe_ok[$method];
    }

    /**
     * The endpoint used as the outbound control. Uses the same CA domain the plugin
     * relies on for announce/heartbeat, so "reachable" means what the plugin needs.
     *
     * @return string
     */
    private function get_control_endpoint()
    {
        $base = 'https://ca.searchatlas.com';

        if (class_exists('Metasync_Endpoint_Manager')) {
            $base = Metasync_Endpoint_Manager::get_endpoint('CA_API_DOMAIN');
        } elseif (class_exists('Metasync')) {
            $base = Metasync::CA_API_DOMAIN;
        }

        return rtrim($base, '/') . '/';
    }

    // ------------------------------------------------------------------
    //  Automatic check
    // ------------------------------------------------------------------

    /**
     * Cron callback for both the post-activation and the weekly check.
     *
     * Runs the same shared probe the manual button uses, then stores a minimal result.
     */
    public function run_scheduled_check()
    {
        if (!$this->site_is_publicly_reachable()) {
            $this->log('Skipped automatic check: site host is not publicly reachable (local/private environment).');
            return;
        }

        $get  = $this->run_check('GET');
        $post = $this->run_check('POST');

        $result = [
            'get_blocked'  => !empty($get['blocked']),
            'post_blocked' => !empty($post['blocked']),
            'checked_at'   => current_time('mysql'),
        ];

        update_option(self::RESULT_OPTION, $result, true);

        if (!empty($get['checker_unreachable']) || !empty($post['checker_unreachable'])) {
            $this->log(sprintf(
                'Inconclusive automatic check (checker unavailable) — no warning shown. GET: %s | POST: %s',
                $get['details'],
                $post['details']
            ));
        }

        if ($result['get_blocked'] || $result['post_blocked']) {
            $this->log(sprintf(
                'Blocking detected (GET blocked: %s, POST blocked: %s). GET: %s | POST: %s',
                $result['get_blocked'] ? 'yes' : 'no',
                $result['post_blocked'] ? 'yes' : 'no',
                $get['details'],
                $post['details']
            ));
        }

        return $result;
    }

    /**
     * Whether the inbound leg of the check can possibly succeed.
     *
     * On localhost, *.local/*.test, or a private-range IP the checker cannot call back,
     * so every automatic run would report blocking. The manual button stays available on
     * those sites — the developer running it knows what the result means.
     *
     * @param string|null $home_url Site URL to evaluate; defaults to this site's home_url().
     * @return bool
     */
    public function site_is_publicly_reachable($home_url = null)
    {
        if ($home_url === null) {
            if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local') {
                return false;
            }
            $home_url = home_url();
        }

        $host = strtolower((string) wp_parse_url($home_url, PHP_URL_HOST));

        if ($host === '' || $host === 'localhost' || $host === '::1') {
            return false;
        }

        foreach (['.local', '.test', '.localhost', '.invalid', '.example', '.internal'] as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return false;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            // Reject loopback/private/reserved ranges; a public IP is fine.
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Last stored automatic result, or null when the check has never run.
     *
     * @return array|null
     */
    public function get_last_result()
    {
        $stored = get_option(self::RESULT_OPTION, false);

        if (!is_array($stored) || !isset($stored['checked_at'])) {
            return null;
        }

        return [
            'get_blocked'  => !empty($stored['get_blocked']),
            'post_blocked' => !empty($stored['post_blocked']),
            'checked_at'   => (string) $stored['checked_at'],
        ];
    }

    // ------------------------------------------------------------------
    //  Admin notice
    // ------------------------------------------------------------------

    /**
     * Whether the warning notice should render.
     *
     * Two autoloaded option reads and a string comparison — the blocking verdict itself
     * is only ever computed by the cron, never on a page load.
     *
     * @return bool
     */
    public function should_display_notice()
    {
        if (!current_user_can('manage_options')) {
            return false;
        }

        $result = $this->get_last_result();

        if ($result === null) {
            return false;
        }

        if (!$result['get_blocked'] && !$result['post_blocked']) {
            return false;
        }

        // Dismissals are recorded against the check cycle they were made for, so the
        // notice stays gone until a later check produces a new `checked_at`.
        return get_option(self::NOTICE_DISMISSED_OPTION, '') !== $result['checked_at'];
    }

    /**
     * Whether the current admin screen is one of this plugin's own pages.
     *
     * Matched against Metasync_Admin::$page_slug rather than a hardcoded 'metasync'
     * string, because that slug is whitelabellable — it defaults to `searchatlas` and
     * a whitelabelled install renames it. Sub-pages are `{slug}-compatibility` and so on.
     *
     * @return bool
     */
    public function is_plugin_admin_page()
    {
        if (empty($_GET['page'])) {
            return false;
        }

        $page = sanitize_text_field(wp_unslash($_GET['page']));
        $slug = class_exists('Metasync_Admin') && !empty(Metasync_Admin::$page_slug)
            ? Metasync_Admin::$page_slug
            : 'searchatlas';

        return $page === $slug || strpos($page, $slug . '-') === 0;
    }

    /**
     * admin_notices callback.
     *
     * Scoped to this plugin's own admin pages, matching the placement of the API-backoff
     * notice, so the warning never appears on unrelated screens.
     */
    public function display_notice()
    {
        if (!$this->is_plugin_admin_page() || !$this->should_display_notice()) {
            return;
        }

        $this->render_notice($this->get_last_result());
    }

    /**
     * Render the warning notice.
     *
     * Follows the dismissible-notice convention used by Metasync_API_Backoff_Notices:
     * a `notice notice-warning is-dismissible` wrapper plus an AJAX call on dismiss.
     *
     * @param array $result Stored check result.
     */
    private function render_notice(array $result)
    {
        $blocked_methods = [];
        if ($result['get_blocked']) {
            $blocked_methods[] = 'GET';
        }
        if ($result['post_blocked']) {
            $blocked_methods[] = 'POST';
        }

        $plugin_name = Metasync::get_effective_plugin_name();
        $otto_name   = Metasync::get_whitelabel_otto_name();
        $sync_name   = $this->get_article_sync_name();

        $compatibility_url = $this->get_compatibility_url();
        ?>
        <div id="metasync-host-blocking-notice" class="notice notice-warning is-dismissible metasync-host-blocking-notice">
            <div style="display: flex; align-items: flex-start; gap: 12px; padding: 4px 0;">
                <div style="font-size: 24px; line-height: 1;">⚠️</div>
                <div style="flex: 1;">
                    <p style="margin: 0 0 8px 0; font-weight: 600; font-size: 14px;">
                        <?php echo esc_html(sprintf(
                            /* translators: 1: plugin name, 2: blocked HTTP methods, e.g. "GET and POST" */
                            __('%1$s: this server is blocking %2$s requests to and from our services', 'metasync'),
                            $plugin_name,
                            implode(' and ', $blocked_methods)
                        )); ?>
                    </p>
                    <p style="margin: 0 0 8px 0; font-size: 13px;">
                        <?php echo esc_html(sprintf(
                            /* translators: 1: article-sync feature name, 2: OTTO product name */
                            __('Some plugin functionality may not work correctly, such as %1$s or %2$s applying SEO suggestions. This is usually caused by a firewall, WAF, or security rule on your hosting account, and your hosting provider can allow the requests.', 'metasync'),
                            $sync_name,
                            $otto_name
                        )); ?>
                    </p>
                    <p style="margin: 0; font-size: 13px; color: #646970;">
                        <?php if ($compatibility_url !== '') : ?>
                            <a href="<?php echo esc_url($compatibility_url); ?>"><?php esc_html_e('View details and re-run the connectivity test', 'metasync'); ?></a>
                            &nbsp;·&nbsp;
                        <?php endif; ?>
                        <?php echo esc_html(sprintf(__('Last checked: %s', 'metasync'), $result['checked_at'])); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * How to refer to the article-sync feature in user-facing copy.
     *
     * "Content Genius" is a Search Atlas product name. There is no whitelabel field for
     * it (only `white_label_plugin_name` and `whitelabel_otto_name` exist), so on a
     * whitelabelled install naming it would leak the vendor the reseller is hiding.
     * Fall back to a neutral description there.
     *
     * @return string
     */
    private function get_article_sync_name()
    {
        $is_whitelabelled = Metasync::get_effective_plugin_name() !== 'Search Atlas';

        return $is_whitelabelled
            ? __('article syncing', 'metasync')
            : __('Content Genius syncing articles', 'metasync');
    }

    /**
     * URL of the Compatibility page, or '' when this install cannot reach it.
     *
     * The slug is whitelabellable (`white_label_plugin_menu_slug` -> Metasync_Admin::$page_slug),
     * and a whitelabelled install can also hide the page outright via `hide_compatibility`.
     * Linking to a hidden page would land the user on a permissions error, so the caller
     * drops the link when this returns ''. Uses the same predicate the navigation uses so
     * the two cannot drift apart.
     *
     * @return string
     */
    private function get_compatibility_url()
    {
        if (class_exists('Metasync_Access_Control') && !Metasync_Access_Control::user_can_access('hide_compatibility')) {
            return '';
        }

        $slug = class_exists('Metasync_Admin') && !empty(Metasync_Admin::$page_slug)
            ? Metasync_Admin::$page_slug
            : 'searchatlas';

        return admin_url('admin.php?page=' . $slug . '-compatibility');
    }

    /**
     * Enqueue the dismiss handler.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_scripts($hook)
    {
        if (!$this->is_plugin_admin_page() || !$this->should_display_notice()) {
            return;
        }

        wp_add_inline_script('jquery', $this->get_notice_script(), 'after');
    }

    /**
     * Inline JS for the dismiss button.
     *
     * @return string
     */
    private function get_notice_script()
    {
        return "
        jQuery(document).ready(function($) {
            $(document).on('click', '.metasync-host-blocking-notice .notice-dismiss', function() {
                $.post(ajaxurl, {
                    action: 'metasync_dismiss_host_blocking_notice',
                    nonce: '" . wp_create_nonce('metasync_host_blocking_notice') . "'
                });
            });
        });
        ";
    }

    /**
     * AJAX handler: remember the dismissal against the current check cycle.
     */
    public function ajax_dismiss_notice()
    {
        check_ajax_referer('metasync_host_blocking_notice', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $result = $this->get_last_result();

        if ($result === null) {
            wp_send_json_error(['message' => 'No stored check result']);
        }

        update_option(self::NOTICE_DISMISSED_OPTION, $result['checked_at'], true);

        wp_send_json_success(['message' => 'Notice dismissed until the next scheduled check']);
    }
}
