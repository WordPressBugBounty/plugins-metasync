<?php
/**
 * Per-page selector helper for Metasync admin list tables.
 *
 * Centralises the "results per page" control shared by the Redirections,
 * 404 Monitor, Changes Log and Media Library (Media Optimization) lists.
 *
 * Responsibilities:
 *   - Define the allow-list of page sizes (10 / 20 / 50 / 100). No
 *     arbitrary user-supplied value ever reaches a query — anything outside
 *     this list is rejected and the per-page default for the page is used.
 *   - Resolve the active page size for a given admin list, preferring an
 *     explicit submission on that list's own request key, then the user's
 *     persisted preference (stored in user_meta), and finally the
 *     page-specific default. A submitted valid value is persisted back to
 *     user_meta so it survives across page loads and sessions.
 *   - Render an inline `<select>` with the allowed options, wired to refresh
 *     the current page with the new page size.
 *
 * Meta key scheme: `metasync_per_page_<page_key>` (one preference per page per
 * user), e.g. `metasync_per_page_redirections`, `metasync_per_page_404_monitor`,
 * `metasync_per_page_sync_log`, `metasync_per_page_media_library`.
 *
 * Request key scheme: `per_page_<page_key>`, one per list. A single shared
 * `per_page` key cannot work here: the Redirections screen renders BOTH the
 * Redirections and the 404 Monitor table in the same request (see
 * Metasync_Redirections_Admin::render_tab_content()), so a shared key made each
 * tab's selector overwrite the other tab's stored preference.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Per_Page_Helper
{
    /**
     * Allowed results-per-page values. Anything else is rejected.
     */
    public const ALLOWED_VALUES = [10, 20, 50, 100];

    /**
     * Get the allow-list of page sizes.
     *
     * @return int[]
     */
    public static function allowed_values()
    {
        return self::ALLOWED_VALUES;
    }

    /**
     * The request key carrying a submitted page size for a given list.
     *
     * One key per list, so two tables rendered onto the same screen (the
     * Redirections page renders Redirections AND 404 Monitor in one request)
     * cannot clobber each other's persisted preference.
     *
     * @param string $page_key Unique list identifier (e.g. 'redirections').
     * @return string
     */
    public static function request_key($page_key)
    {
        return 'per_page_' . $page_key;
    }

    /**
     * The pagination parameter belonging to a given list.
     *
     * Each list paginates on its own parameter, because the Redirections screen
     * shows two tables at once and a shared `paged` would move both. Changing
     * one list's page size must reset THAT list to page 1 and leave the other
     * table's page alone.
     *
     * @param string $page_key Unique list identifier.
     * @return string
     */
    public static function paged_param($page_key)
    {
        $map = [
            'redirections'  => 'paged_redir',
            '404_monitor'   => 'paged_404',
            'media_library' => 'paged_media',
            'sync_log'      => 'paged',
        ];

        return isset($map[$page_key]) ? $map[$page_key] : 'paged';
    }

    /**
     * Query parameters that describe the visible state of each list.
     *
     * Keeping this allow-list beside the per-page contract ensures browser
     * navigation never copies bulk actions, selected rows or nonces into URLs.
     *
     * @param string $page_key Unique list identifier.
     * @return string[]
     */
    public static function state_params($page_key)
    {
        $map = [
            'redirections'  => ['status_filter', 'pattern_filter', 'http_code_filter', 's_redir', 'orderby_redir', 'order_redir'],
            '404_monitor'   => ['date_from', 'date_to', 'min_hits', 's_404', 'orderby_404', 'order_404'],
            'media_library' => ['status_filter', 's', 'orderby_media', 'order_media'],
            'sync_log'      => ['date_range', 'status'],
        ];

        return isset($map[$page_key]) ? $map[$page_key] : [];
    }

    /**
     * Return sanitized, allow-listed request state for links and redirects.
     *
     * @param string     $page_key Unique list identifier.
     * @param array|null $request  Request source; defaults to $_REQUEST.
     * @return array<string,string>
     */
    public static function request_state($page_key, $request = null)
    {
        $request = is_array($request) ? $request : $_REQUEST;
        $keys    = array_merge(self::state_params($page_key), [self::request_key($page_key)]);
        $state   = [];

        foreach ($keys as $key) {
            if (!isset($request[$key]) || !is_scalar($request[$key])) {
                continue;
            }

            $value = (string) $request[$key];
            if ($value === '') {
                continue;
            }

            if ($key === self::request_key($page_key) && !in_array((int) $value, self::ALLOWED_VALUES, true)) {
                continue;
            }

            if (function_exists('wp_unslash')) {
                $value = wp_unslash($value);
            }
            $state[$key] = sanitize_text_field($value);
        }

        // Older links used WordPress's shared `s` parameter. Canonicalize it
        // into the list-specific key without allowing the two tables rendered
        // on the combined screen to overwrite one another on new requests.
        $search_keys = [
            'redirections' => 's_redir',
            '404_monitor'  => 's_404',
        ];
        if (isset($search_keys[$page_key])) {
            $search_key = $search_keys[$page_key];
            if (!isset($state[$search_key]) && isset($request['s']) && is_scalar($request['s'])) {
                $value = (string) $request['s'];
                if ($value !== '') {
                    if (function_exists('wp_unslash')) {
                        $value = wp_unslash($value);
                    }
                    $state[$search_key] = sanitize_text_field($value);
                }
            }
        }

        return $state;
    }

    /**
     * Resolve the active per-page value for a given admin list.
     *
     * Resolution order:
     *   1. `$_REQUEST[$request_key]` when present AND a valid allow-list
     *      integer — also persisted to user_meta so the choice survives
     *      future sessions.
     *   2. The user's persisted preference (`metasync_per_page_<page_key>`),
     *      when it is a valid allow-list integer.
     *   3. The supplied `$default`.
     *
     * Server-side validation guarantees no arbitrary user-supplied value can
     * reach a query's LIMIT.
     *
     * @param string      $page_key     Unique list identifier (e.g. 'redirections').
     * @param int         $default      Fallback page size for this list.
     * @param string|null $request_key  Query parameter carrying the submitted
     *                                  value. Defaults to this list's own key.
     * @return int Validated page size from the allow-list.
     */
    public static function resolve($page_key, $default, $request_key = null)
    {
        $default     = self::clamp_default($default);
        $request_key = $request_key === null ? self::request_key($page_key) : $request_key;
        $user_id     = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        $meta_key    = 'metasync_per_page_' . $page_key;

        // 1) A freshly submitted value wins and is persisted.
        if (isset($_REQUEST[$request_key])) {
            $submitted = (int) $_REQUEST[$request_key];
            if (in_array($submitted, self::ALLOWED_VALUES, true)) {
                if ($user_id > 0 && function_exists('update_user_meta')) {
                    update_user_meta($user_id, $meta_key, $submitted);
                }
                return $submitted;
            }
        }

        // 2) Fall back to the user's saved preference.
        if ($user_id > 0 && function_exists('get_user_meta')) {
            $saved = get_user_meta($user_id, $meta_key, true);
            if ($saved !== '' && $saved !== null && $saved !== false) {
                $saved = (int) $saved;
                if (in_array($saved, self::ALLOWED_VALUES, true)) {
                    return $saved;
                }
            }
        }

        // 3) Finally, the page default.
        return $default;
    }

    /**
     * Render an inline rows-per-page `<select>` for the given list.
     *
     * The external admin change handler reloads the current URL with the new
     * page size, so this works without a surrounding <form> and preserves the
     * rest of the query string. It resets only THIS list's
     * pagination parameter: page numbers for this list stop meaning anything
     * once its page size changes, but the other table on a combined screen has
     * not resized and must keep its page.
     *
     * WP_List_Table renders the table nav twice (top and bottom), so callers
     * must pass `$instance` (the `$which` they were given) to keep the `id`
     * unique — a repeated `id` is invalid HTML and makes the `<label for>`
     * ambiguous.
     *
     * @param string      $page_key     Unique list identifier.
     * @param int         $current      Currently active page size (selected option).
     * @param string      $instance     Position/instance suffix ('top', 'bottom', ...).
     *                                  Omit for a list that renders one selector.
     * @param string|null $request_key  Query parameter to set on change.
     *                                  Defaults to this list's own key.
     * @return string HTML markup for the selector (not echoed).
     */
    public static function render_selector($page_key, $current, $instance = '', $request_key = null)
    {
        $current     = (int) $current;
        $request_key = $request_key === null ? self::request_key($page_key) : $request_key;
        $paged_param = self::paged_param($page_key);

        $id = 'metasync-per-page-' . $page_key;
        if ($instance !== '') {
            $id .= '-' . $instance;
        }

        $options = '';
        foreach (self::ALLOWED_VALUES as $value) {
            $options .= sprintf(
                '<option value="%d"%s>%d</option>',
                $value,
                selected($value, $current, false),
                $value
            );
        }

        return sprintf(
            '<span class="metasync-per-page" style="margin-left:8px;">'
            . '<label for="%1$s" class="screen-reader-text">%2$s</label>'
            . '<select id="%1$s" name="%3$s" class="metasync-per-page-select" '
            . 'data-per-page-key="%3$s" data-paged-param="%4$s" '
            . 'data-page-key="%5$s" data-state-params="%6$s">'
            . '%7$s'
            . '</select>'
            . '</span>',
            esc_attr($id),
            esc_html__('Rows per page', 'metasync'),
            esc_attr($request_key),
            esc_attr($paged_param),
            esc_attr($page_key),
            esc_attr(wp_json_encode(self::state_params($page_key))),
            $options
        );
    }

    /**
     * Ensure the supplied default is itself a valid allow-list value,
     * otherwise coerce to the first allow-list entry. Keeps callers safe
     * even if a custom default slips through.
     *
     * @param int $default
     * @return int
     */
    private static function clamp_default($default)
    {
        $default = (int) $default;
        return in_array($default, self::ALLOWED_VALUES, true) ? $default : self::ALLOWED_VALUES[0];
    }
}
