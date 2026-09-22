<?php
/**
 * Headless mode configuration.
 *
 * A headless WordPress install stores and edits content but does not render the
 * public site — a separate frontend (Next.js, Nuxt, Astro, ...) fetches content
 * over an API and serves the HTML that visitors and search engines actually see.
 *
 * That breaks two assumptions this plugin makes everywhere else:
 *
 *  1. Rewriting the HTML WordPress emits is pointless, because nothing crawls it.
 *  2. Every URL WordPress reports (get_permalink(), get_term_link(), home_url())
 *     names the WordPress host, not the host the page is served from — so
 *     canonicals, og:url and any URL in a payload come out wrong.
 *
 * This class owns the settings that describe the public site, so the rest of the
 * headless code has exactly one place to ask. It is deliberately read-mostly and
 * side-effect free: no hooks, no writes on read, no network.
 *
 * Storage lives under its own `headless` key in the main options array rather
 * than inside `general`, which keeps it out of the general-settings sanitiser and
 * makes "absent means off" true for every install that predates this feature.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

if (!defined('ABSPATH')) {
    exit; # Exit if accessed directly
}

class Metasync_Headless_Config
{
    /**
     * Key under the main plugin options array holding every headless setting.
     */
    const SETTINGS_KEY = 'headless';

    /**
     * Trailing-slash policies for generated public URLs.
     *
     * `preserve` is the default because it is the only value that cannot change
     * a URL: it hands back whatever the site's own permalink structure already
     * produced. `strip` suits frontends that route without trailing slashes;
     * `add` suits those that require them.
     */
    const SLASH_PRESERVE = 'preserve';
    const SLASH_STRIP    = 'strip';
    const SLASH_ADD      = 'add';

    /**
     * Safe bounds for the stale-while-revalidate refresh interval, in minutes.
     *
     * The lower bound keeps a misconfigured value from turning the background
     * refresh into something close to a per-request poll; the upper bound keeps
     * it from effectively disabling the safety net. Both are enforced in
     * normalize(), so a stored or submitted value can never sit outside them.
     */
    const REFRESH_INTERVAL_MIN_MINUTES = 5;
    const REFRESH_INTERVAL_MAX_MINUTES = 1440;

    /**
     * Default refresh interval, in minutes, per the approved design.
     */
    const REFRESH_INTERVAL_DEFAULT_MINUTES = 30;

    /**
     * Per-request memo of the resolved settings array.
     *
     * Headless callers ask these questions per object, so an uncached
     * implementation would re-read the option once per field per object. Reset
     * with flush() when settings are written mid-request.
     *
     * @var array|null
     */
    private static $settings = null;

    /**
     * True only while this class is writing the option itself.
     *
     * See update_settings() for why the Advanced tab's sanitiser needs to know.
     *
     * @var bool
     */
    private static $writing = false;

    /**
     * Every setting with its default. The shape of the stored value.
     *
     * @return array
     */
    public static function defaults()
    {
        return array(
            # Master switch. Off means every headless branch in the plugin is
            # skipped and execution follows the same path it always has.
            'enabled'         => false,

            # Scheme + host of the public site, no path, no trailing slash —
            # e.g. https://www.example.com. Empty until configured.
            'frontend_domain' => '',

            # One of the SLASH_* constants above.
            'slash_policy'    => self::SLASH_PRESERVE,

            # Optional path prefix per post type, keyed by post type name —
            # e.g. array('post' => 'blog') to serve posts under /blog/... .
            # Nothing here is a default: a frontend that mirrors WordPress's
            # paths exactly needs no entries at all.
            'path_prefixes'   => array(),

            # Minutes between background stale-while-revalidate refresh checks
            # (Metasync_Headless_Refresh_Job). Only consulted while headless mode
            # is active; the job is a no-op otherwise. See REFRESH_INTERVAL_MIN/MAX
            # for the enforced bounds.
            'refresh_interval_minutes' => self::REFRESH_INTERVAL_DEFAULT_MINUTES,
        );
    }

    /**
     * Resolved settings: stored values normalised over the defaults.
     *
     * @return array
     */
    public static function get_settings()
    {
        if (self::$settings === null) {
            $stored = Metasync::get_option(self::SETTINGS_KEY);
            self::$settings = self::normalize(is_array($stored) ? $stored : array());
        }

        return self::$settings;
    }

    /**
     * Drop the memo so the next read comes from the database.
     *
     * Call after writing settings within the same request.
     *
     * @return void
     */
    public static function flush()
    {
        self::$settings = null;
    }

    /**
     * Is headless mode switched on?
     *
     * This reports the flag alone. It is the right question for "should the
     * operator's intent be honoured" — for "can headless URLs actually be
     * built", ask is_active() instead.
     *
     * @return bool
     */
    public static function is_enabled()
    {
        $settings = self::get_settings();
        return !empty($settings['enabled']);
    }

    /**
     * Is headless mode on *and* usable?
     *
     * Gate on this wherever a **URL is emitted**: without a frontend domain there
     * is nothing to build a public URL from, and a half-configured install would
     * otherwise put the WordPress host in a canonical while claiming to be
     * headless — worse than staying on the existing path.
     *
     * It is **not** the gate for resolving data. The SEO surface deliberately
     * gates on is_enabled(), because most of a payload — title, description,
     * robots, schema — needs no domain, and its URL fields already come back
     * empty rather than wrong: the builder refuses without a domain. Withholding
     * the whole payload would discard the valid majority to avoid a few empty
     * strings, and withdrawing the GraphQL field the moment the domain is cleared
     * would break a live frontend exactly as switching the mode off does — the
     * case the settings UI puts a confirmation in front of.
     *
     * Two independent reviews read the old wording as an instruction to change
     * the surface. It is not; the split is deliberate.
     *
     * @return bool
     */
    public static function is_active()
    {
        return self::is_enabled() && self::get_frontend_domain() !== '';
    }

    /**
     * Scheme + host of the public site, with no trailing slash.
     *
     * @return string Empty string when unconfigured.
     */
    public static function get_frontend_domain()
    {
        $settings = self::get_settings();
        return $settings['frontend_domain'];
    }

    /**
     * Configured trailing-slash policy.
     *
     * @return string One of the SLASH_* constants.
     */
    public static function get_slash_policy()
    {
        $settings = self::get_settings();
        return $settings['slash_policy'];
    }

    /**
     * Every configured path prefix, keyed by post type.
     *
     * @return array<string,string>
     */
    public static function get_path_prefixes()
    {
        $settings = self::get_settings();
        return $settings['path_prefixes'];
    }

    /**
     * Path prefix configured for one post type.
     *
     * Callers pass whatever WordPress handed them — get_post_type() returns
     * false for a bad ID, and a null slips through just as easily — so a
     * non-string is treated as "no post type" rather than allowed to become an
     * array key.
     *
     * @param mixed $post_type Post type name.
     * @return string Empty string when the post type has no prefix.
     */
    public static function get_path_prefix($post_type)
    {
        $prefixes  = self::get_path_prefixes();
        $post_type = is_string($post_type) ? $post_type : '';

        return isset($prefixes[$post_type]) ? $prefixes[$post_type] : '';
    }

    /**
     * Configured stale-while-revalidate refresh interval, in minutes.
     *
     * Always within [REFRESH_INTERVAL_MIN_MINUTES, REFRESH_INTERVAL_MAX_MINUTES]
     * — normalize() clamps on the way in, so callers never have to re-check.
     *
     * @return int
     */
    public static function get_refresh_interval_minutes()
    {
        $settings = self::get_settings();
        return (int) $settings['refresh_interval_minutes'];
    }

    /**
     * Normalise and sanitise a settings array against the defaults.
     *
     * The single entry point for untrusted input, so a stored value can never be
     * a shape the getters do not expect: booleans are booleans, the policy is
     * always one of the three constants, and the prefix map is always
     * string => non-empty string.
     *
     * @param mixed $input Raw settings.
     * @return array Sanitised settings, complete with every key.
     */
    public static function normalize($input)
    {
        $defaults = self::defaults();

        if (!is_array($input)) {
            return $defaults;
        }

        $clean = $defaults;

        # Only a scalar can express this. filter_var() reads an array as false,
        # so a request carrying `enabled[]=1` — a stale form, a hand-built
        # post, an input name that gained a `[]` somewhere — would silently
        # switch the mode off. On a live headless site that removes the
        # GraphQL field and breaks every page, which is far too destructive
        # an outcome for a malformed value. A shape that cannot be read is
        # treated as absent instead, leaving whatever was already stored.
        if (isset($input['enabled']) && self::is_scalarish($input['enabled'])) {
            $clean['enabled'] = filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($input['frontend_domain']) && self::is_scalarish($input['frontend_domain'])) {
            $clean['frontend_domain'] = self::normalize_domain($input['frontend_domain']);
        }

        if (isset($input['slash_policy']) && self::is_scalarish($input['slash_policy'])) {
            $policy = is_string($input['slash_policy']) ? strtolower(trim($input['slash_policy'])) : '';
            if (in_array($policy, self::slash_policies(), true)) {
                $clean['slash_policy'] = $policy;
            }
        }

        # Only an array can express this one, so anything else is the same kind
        # of malformed request as above — and reading it as an empty map would
        # wipe every configured prefix.
        if (isset($input['path_prefixes']) && is_array($input['path_prefixes'])) {
            $clean['path_prefixes'] = self::normalize_path_prefixes($input['path_prefixes']);
        }

        if (isset($input['refresh_interval_minutes']) && self::is_scalarish($input['refresh_interval_minutes'])) {
            $clean['refresh_interval_minutes'] = self::normalize_refresh_interval_minutes(
                $input['refresh_interval_minutes']
            );
        }

        return $clean;
    }

    /**
     * Clamp a submitted refresh interval to the safe bounds.
     *
     * A non-numeric value (an empty field, a typo) falls back to the default
     * rather than being dropped — unlike the other settings, a blank interval
     * field is not a signal to leave the previous value alone, it is the
     * operator clearing the field, and the safest reading of "cleared" is
     * "use the shipped default", not "keep whatever the stale UI had".
     *
     * @param mixed $value Raw submitted value.
     * @return int Minutes, within [REFRESH_INTERVAL_MIN_MINUTES, REFRESH_INTERVAL_MAX_MINUTES].
     */
    private static function normalize_refresh_interval_minutes($value)
    {
        if (!is_numeric($value)) {
            return self::REFRESH_INTERVAL_DEFAULT_MINUTES;
        }

        $minutes = (int) round((float) $value);

        return max(self::REFRESH_INTERVAL_MIN_MINUTES, min(self::REFRESH_INTERVAL_MAX_MINUTES, $minutes));
    }

    /**
     * Reduce an incoming patch to the keys that can actually be applied.
     *
     * Unknown keys go, and so does any value whose shape no form could have
     * produced for that setting — an array where a scalar belongs, or the
     * reverse. Both are dropped rather than coerced, because coercing means
     * overwriting good configuration on the strength of a malformed request.
     *
     * @param mixed $input
     * @return array
     */
    private static function usable_patch($input)
    {
        if (!is_array($input)) {
            return array();
        }

        $patch = array();

        foreach (array('enabled', 'frontend_domain', 'slash_policy') as $key) {
            if (array_key_exists($key, $input) && self::is_scalarish($input[$key])) {
                $patch[$key] = $input[$key];
            }
        }

        if (array_key_exists('refresh_interval_minutes', $input) && self::is_scalarish($input['refresh_interval_minutes'])) {
            $patch['refresh_interval_minutes'] = $input['refresh_interval_minutes'];
        }

        if (array_key_exists('path_prefixes', $input) && is_array($input['path_prefixes'])) {
            $patch['path_prefixes'] = $input['path_prefixes'];
        }

        return $patch;
    }

    /**
     * Is this a value a single setting could plausibly have been submitted as?
     *
     * Guards the difference between "the operator said something invalid" and
     * "this arrived in a shape no form should produce". The first deserves a
     * default; the second deserves to be ignored, because acting on it means
     * overwriting good configuration on the strength of a malformed request.
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_scalarish($value)
    {
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value);
    }

    /**
     * Persist a settings array, sanitising it on the way in.
     *
     * Merges into the existing plugin options rather than replacing them, and
     * refreshes the memo so callers later in the same request see the new
     * values.
     *
     * @param mixed $input Raw settings.
     * @return array The sanitised settings that were stored.
     */
    public static function update_settings($input)
    {
        # A patch, not a replacement. Passing one key used to reset the rest to
        # their defaults, so an update that touched only the domain switched the
        # mode off as a side effect — and on a live headless site switching the
        # mode off removes the GraphQL field and breaks every page. Callers
        # legitimately want to change one setting.
        #
        # Keys arriving in a shape no form should produce are dropped before the
        # merge rather than after it. Dropping them afterwards would leave the
        # default in place, which is the same destructive outcome by a longer
        # route: `enabled[]=1` would still end up as false.
        $clean = self::normalize(array_merge(self::get_settings(), self::usable_patch($input)));

        $options = Metasync::get_option();
        if (!is_array($options)) {
            $options = array();
        }

        $options[self::SETTINGS_KEY] = $clean;

        # register_setting() installs the Advanced tab's sanitiser as a
        # `sanitize_option_metasync_options` filter, and WordPress runs that on
        # every update_option() call for the option — not only on Settings API
        # form posts. The sanitiser drops this key so a stale or hand-built main
        # form cannot clobber the block, which means it also drops the write
        # happening right here: the owner of the setting could never store it.
        # The flag tells the sanitiser that this particular write came from the
        # owner and should be left alone. try/finally so a raised sanitiser
        # cannot leave the flag standing and hand the main form a way through.
        self::$writing = true;

        try {
            Metasync::set_option($options);
        } finally {
            self::$writing = false;
        }

        self::$settings = $clean;

        return $clean;
    }

    /**
     * Is a write from this class in progress right now?
     *
     * Only the Advanced tab's sanitiser has any business asking. It exists
     * because a sanitiser registered on the whole option cannot otherwise tell
     * the owner of this block apart from an unrelated form that happens to be
     * carrying the key.
     *
     * @return bool
     */
    public static function is_writing()
    {
        return self::$writing;
    }

    /**
     * The valid trailing-slash policies.
     *
     * @return string[]
     */
    public static function slash_policies()
    {
        return array(self::SLASH_PRESERVE, self::SLASH_STRIP, self::SLASH_ADD);
    }

    /**
     * Reduce a user-supplied frontend domain to scheme://host[:port].
     *
     * Accepts what an operator will realistically paste — a bare host or a URL
     * with a trailing slash — and refuses a URL with a non-root path. A bare
     * host is assumed to be https, since a public site a search engine indexes
     * is not served over plain http in practice.
     *
     * @param mixed $value Raw domain or URL.
     * @return string Normalised origin, or empty string when unusable.
     */
    public static function normalize_domain($value)
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        # A bare host has no scheme to parse; assume https rather than inheriting
        # the WordPress install's scheme, which may well be http on a local box.
        if (strpos($value, '//') === false) {
            $value = 'https://' . ltrim($value, '/');
        }

        $parts = wp_parse_url($value);
        if (empty($parts['host'])) {
            return '';
        }

        $scheme = isset($parts['scheme']) && $parts['scheme'] !== '' ? strtolower($parts['scheme']) : 'https';
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }

        $host = strtolower($parts['host']);
        if (!self::is_plausible_host($host)) {
            return '';
        }

        # A frontend served from a subpath (https://example.com/blog) cannot be
        # expressed by this setting: only per-post-type prefixes exist, so terms,
        # archives and the homepage could never get the segment back. Silently
        # keeping just the origin would produce plausible URLs that are all
        # missing /blog, sitewide, with nothing to indicate why. Refusing leaves
        # is_active() false, which is visible.
        $path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';
        if ($path !== '') {
            return '';
        }

        $port = !empty($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    /**
     * Does this look like a host a browser could actually resolve?
     *
     * parse_url() is lenient: give it `https://not a domain at all` and it hands
     * back that whole phrase as the host. Without this check a typo in the
     * setting would satisfy is_active() and every canonical on the site would
     * point at a URL that cannot resolve — worse than staying on the WordPress
     * host, because it is silently wrong rather than visibly wrong.
     *
     * The check is deliberately shape-only, not a DNS lookup: it rejects what
     * cannot be a host while still accepting an IDN, a bracketed IPv6 literal,
     * and a single-label internal name such as `localhost`.
     *
     * @param string $host Lowercased host from wp_parse_url().
     * @return bool
     */
    private static function is_plausible_host($host)
    {
        if ($host === '') {
            return false;
        }

        # Bracketed IPv6 literal.
        if (strpos($host, '[') === 0) {
            return (bool) preg_match('/^\[[0-9a-f:.]+\]$/', $host);
        }

        # Letters (including non-ASCII, so an IDN survives), digits, dot, hyphen
        # and underscore. Anything else — a space, a slash, a stray delimiter —
        # means the operator pasted something that is not a host.
        if (!preg_match('/^[\p{L}\p{N}._-]+$/u', $host)) {
            return false;
        }

        # A leading or trailing separator, or an empty label, is never a real host.
        if (strpos($host, '..') !== false) {
            return false;
        }

        if (strpbrk(substr($host, 0, 1), '.-') !== false || strpbrk(substr($host, -1), '.-') !== false) {
            return false;
        }

        # Rules out a host made only of separators.
        return (bool) preg_match('/[\p{L}\p{N}]/u', $host);
    }

    /**
     * Is every character in this prefix usable inside a URL path?
     *
     * Permits letters (including non-ASCII, so a localised prefix survives),
     * digits, and the small set of separators a path legitimately contains.
     * Everything else — query and fragment introducers, whitespace, control
     * characters, percent signs that would double-encode — is refused rather
     * than escaped, because a prefix is operator configuration and a wrong
     * one should fail visibly instead of producing a subtly broken URL on
     * every page of the site.
     *
     * @param string $prefix
     * @return bool
     */
    private static function is_usable_path_prefix($prefix)
    {
        return (bool) preg_match('#^[\\p{L}\\p{N}/_.~-]+$#u', (string) $prefix);
    }

    /**
     * Does this path contain a `.` or `..` segment?
     *
     * Checked on the decoded value as well as the raw one, since `%2e%2e` is the
     * same segment to anything that resolves the URL later.
     *
     * @param mixed $path
     * @return bool
     */
    public static function has_relative_segment($path)
    {
        if (!is_string($path)) {
            return false;
        }

        # Decode to a fixed point rather than once. A single pass turns
        # `%252e%252e` into the literal `%2e%2e`, which matches no segment and
        # would sail through — yet a proxy, CDN or frontend router that performs
        # its own further decode still ends up resolving `..`. The iteration cap
        # keeps a pathological input from looping.
        $candidates = array($path);
        $decoded    = $path;

        for ($i = 0; $i < 8; $i++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }

            $decoded      = $next;
            $candidates[] = $decoded;
        }

        foreach ($candidates as $candidate) {
            $candidate = str_replace('\\', '/', $candidate);
            foreach (explode('/', $candidate) as $segment) {
                if ($segment === '.' || $segment === '..') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Sanitise the post-type => prefix map.
     *
     * Prefixes are stored bare — no leading or trailing slash — so the URL
     * builder can join them without having to guess what the operator typed.
     * Entries that would contribute nothing are dropped rather than stored as
     * empty strings, which keeps get_path_prefix() honest.
     *
     * @param mixed $value Raw map.
     * @return array<string,string>
     */
    private static function normalize_path_prefixes($value)
    {
        if (!is_array($value)) {
            return array();
        }

        $clean = array();

        foreach ($value as $post_type => $prefix) {
            $post_type = sanitize_key((string) $post_type);
            if ($post_type === '') {
                continue;
            }

            if (!is_string($prefix) && !is_numeric($prefix)) {
                continue;
            }

            # Keep interior slashes so a nested prefix like `news/posts` works,
            # but strip the edges and collapse repeats so the builder can rely on
            # a single separator between segments.
            $prefix = sanitize_text_field((string) $prefix);
            $prefix = str_replace('\\', '/', $prefix);
            $prefix = preg_replace('#/+#', '/', $prefix);
            $prefix = trim((string) $prefix, "/ \t\n\r\0\x0B");

            if ($prefix === '') {
                continue;
            }

            # A relative segment in a prefix is never a real path component, and
            # a browser or proxy would resolve it away — `../admin` would turn a
            # canonical into https://frontend/../admin/slug/ and then into
            # /admin/slug/. Drop the whole prefix rather than store something
            # that resolves somewhere else.
            if (self::has_relative_segment($prefix)) {
                continue;
            }

            # A prefix is path segments, nothing else. A `?`, `#` or `&` would
            # land in the middle of a canonical — `blog?x=1` produces
            # https://frontend/blog?x=1/my-post/, where everything after the
            # prefix becomes a query string. Whitespace and control characters
            # are equally not path segments. This mattered less while prefixes
            # were set by editing the database; it matters once anyone can type
            # one into a form.
            if (!self::is_usable_path_prefix($prefix)) {
                continue;
            }

            $clean[$post_type] = $prefix;
        }

        return $clean;
    }
}
