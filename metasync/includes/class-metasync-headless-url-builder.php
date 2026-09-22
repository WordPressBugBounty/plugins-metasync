<?php
/**
 * Public URL builder for headless mode.
 *
 * On a headless site every URL WordPress reports names the WordPress host, not
 * the host the page is actually served from. get_permalink(), get_term_link(),
 * get_author_posts_url() and home_url() all do this, so canonicals, og:url and
 * every URL in a payload come out pointing at a backend nobody should reach.
 *
 * The fix is to stop asking WordPress for the host: keep the path it produced,
 * throw the host away, and prefix the stored frontend domain. That is all this
 * class does. It has no opinion about which object the path belongs to — the
 * per-object-type resolvers own that and hand a WordPress URL down here.
 *
 * Three rules are applied on the way through, all of them stored settings:
 *
 *  - the frontend domain replaces whatever host the input carried;
 *  - a per-post-type path prefix can be inserted, for a frontend that serves a
 *    post type under a path WordPress does not use;
 *  - a trailing-slash policy is applied last, because the frontend router — not
 *    WordPress — decides whether a trailing slash is correct.
 *
 * Every method fails closed: given an unconfigured install, or input it cannot
 * make sense of, it returns an empty string rather than a half-built URL. A
 * caller that gets '' must fall back to its existing behaviour, which keeps a
 * misconfigured headless site on the path it would have taken anyway.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

if (!defined('ABSPATH')) {
    exit; # Exit if accessed directly
}

class Metasync_Headless_Url_Builder
{
    /**
     * Build the public URL for a URL WordPress produced.
     *
     * The normal entry point: hand it whatever get_permalink() / get_term_link()
     * / home_url() returned and it comes back on the public host.
     *
     * @param mixed  $wp_url    A URL or path from WordPress.
     * @param string $post_type Post type the URL belongs to, for the prefix
     *                          rule. Pass '' for anything that is not a single
     *                          post — terms, archives, the homepage.
     * @return string Public URL, or '' when one cannot be built.
     */
    public static function from_wp_url($wp_url, $post_type = '')
    {
        $path = self::extract_path($wp_url);
        if ($path === null) {
            return '';
        }

        return self::from_path($path, $post_type);
    }

    /**
     * Build the public URL for a site-relative path.
     *
     * @param mixed  $path      Site-relative path, with or without a leading slash.
     * @param string $post_type Post type the path belongs to, for the prefix rule.
     * @return string Public URL, or '' when one cannot be built.
     */
    public static function from_path($path, $post_type = '')
    {
        # Gated on is_active(), not merely on a stored domain. Two independent
        # reviews of this branch pointed at the same hazard: a builder that
        # answers whenever a domain happens to be stored leaves flag-off
        # correctness resting on every caller remembering to check first, and a
        # domain left behind from an earlier experiment is enough to hand back a
        # frontend URL. Refusing here makes the whole subsystem inert by
        # construction rather than by caller discipline.
        if (!Metasync_Headless_Config::is_active()) {
            return '';
        }

        $domain = Metasync_Headless_Config::get_frontend_domain();

        if (!is_string($path) && !is_numeric($path)) {
            return '';
        }

        $path = self::normalize_path((string) $path);

        # Fail closed on a relative segment rather than resolve it. A permalink
        # has no legitimate reason to contain one, and joining it to the frontend
        # origin would produce a URL that resolves somewhere else entirely —
        # https://frontend/a/../b/ is https://frontend/b/ to every client that
        # normalises it. Silently emitting a canonical for a different page is
        # worse than emitting none.
        if (Metasync_Headless_Config::has_relative_segment($path)) {
            return '';
        }

        $path = self::apply_prefix($path, $post_type);

        return $domain . self::apply_slash_policy($path);
    }

    /**
     * The public homepage URL.
     *
     * @return string Public URL, or '' when one cannot be built.
     */
    public static function home()
    {
        return self::from_path('/');
    }

    /**
     * Reduce a WordPress URL to the path the frontend serves it under.
     *
     * Two things are dropped beyond the host. The query string and fragment go
     * because this builds addresses for canonical-style output, where a
     * parameterised URL is not the address of the page. The WordPress install's
     * own subdirectory goes because it belongs to WordPress: a backend at
     * https://backend.example.com/wp/ serves /wp/about/, but the frontend serves
     * /about/ — carrying the /wp across would produce a path that does not exist
     * there.
     *
     * A path handed in without a host is accepted as-is, which is what makes
     * from_path() and from_wp_url() interchangeable for callers that already
     * hold a path.
     *
     * @param mixed $wp_url URL or path from WordPress.
     * @return string|null Path with a leading slash, or null when unusable.
     */
    private static function extract_path($wp_url)
    {
        if (!is_string($wp_url)) {
            return null;
        }

        $wp_url = trim($wp_url);
        if ($wp_url === '') {
            return null;
        }

        $parts = wp_parse_url($wp_url);
        if ($parts === false) {
            return null;
        }

        $path = isset($parts['path']) ? $parts['path'] : '';

        # A plain-permalink install produces https://host/?p=123 — host, empty
        # path, everything identifying the post in the query string this builder
        # deliberately discards. Treating that as the root would give every post
        # on the site the homepage as its canonical, which is a de-indexing
        # signal emitted silently. Fail closed instead; the caller keeps its own
        # behaviour. The rendered path reaches the same conclusion about `?p=`
        # permalinks and rebuilds them rather than trusting them.
        if (self::identifies_object_by_query($parts)) {
            return null;
        }

        # A bare host with no path and no query ("https://example.com") really
        # does address the root.
        if ($path === '' && !empty($parts['host'])) {
            $path = '/';
        }

        if ($path === '') {
            return null;
        }

        return self::strip_site_subdirectory($path);
    }

    /**
     * Is the object identified by the query string rather than the path?
     *
     * A plain-permalink install produces `https://host/?p=123`: the path is just
     * `/` and everything naming the post sits in the query, which this builder
     * discards. Left alone that yields the frontend root as the canonical for
     * every post on the site — a sitewide canonical-to-homepage, which is a
     * de-indexing signal emitted silently.
     *
     * Only a query that actually identifies an object counts, so an ordinary URL
     * carrying tracking parameters still resolves to its path. The parameter list
     * mirrors the checks the rendered path already makes before deciding a
     * permalink is unusable.
     *
     * @param array $parts Parsed URL.
     * @return bool
     */
    private static function identifies_object_by_query($parts)
    {
        if (empty($parts['query'])) {
            return false;
        }

        $path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';
        if ($path !== '') {
            return false;
        }

        $query = array();
        parse_str((string) $parts['query'], $query);

        foreach (self::object_identifying_query_vars() as $param) {
            if (isset($query[$param]) && $query[$param] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Query variables that supply an address rather than decorate one.
     *
     * Each of these appears in a URL WordPress produces when no permalink
     * structure is in use, and in every case the address lives in the query
     * rather than the path — so joining the path alone to the frontend domain
     * yields the frontend root, handing a page the homepage as its canonical.
     *
     * The taxonomy pair matters as much as the post ones. get_term_link()
     * returns `?cat=` only for categories; every other taxonomy comes back as
     * `?taxonomy=genre&term=jazz`, so listing the post variables alone left
     * every custom-taxonomy term on such a site resolving to the root.
     *
     * Anything absent from this list — utm_*, fbclid, a session id — merely
     * decorates an address the path already carries, so a URL bearing only
     * those still resolves normally.
     *
     * @return string[]
     */
    private static function object_identifying_query_vars()
    {
        return array(
            # A single post, page or attachment.
            'p',
            'page_id',
            'pagename',
            'name',
            'attachment',
            'attachment_id',
            'post_type',

            # A taxonomy archive.
            'cat',
            'category_name',
            'tag',
            'tag_id',
            'taxonomy',
            'term',
            'term_id',

            # An author archive.
            'author',
            'author_name',

            # A date archive.
            'year',
            'monthnum',
            'day',
            'm',
            'w',

            # A search results page, which is not an addressable page at all.
            's',
        );
    }

    /**
     * Remove the WordPress install's subdirectory from the front of a path.
     *
     * A no-op on the overwhelmingly common root install, where home_url() has no
     * path component.
     *
     * @param string $path Path with a leading slash.
     * @return string
     */
    private static function strip_site_subdirectory($path)
    {
        $home = wp_parse_url(home_url('/'));
        if (!is_array($home) || empty($home['path'])) {
            return $path;
        }

        $base = '/' . trim($home['path'], '/');
        if ($base === '/') {
            return $path;
        }

        # Only strip a whole leading segment: a base of /wp must not eat the
        # front of /wpengine-something.
        if (strpos($path, $base . '/') === 0) {
            return substr($path, strlen($base));
        }

        if ($path === $base) {
            return '/';
        }

        return $path;
    }

    /**
     * Normalise a path to a single leading slash and no duplicate separators.
     *
     * @param string $path Raw path.
     * @return string Path with a leading slash.
     */
    private static function normalize_path($path)
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);
        $path = '/' . ltrim((string) $path, '/');

        return $path;
    }

    /**
     * Insert the configured path prefix for a post type.
     *
     * Skipped when the path already starts with the prefix. That is not
     * defensive padding: a site can filter post_link so get_permalink() already
     * returns the public path, prefix included, and prefixing again would
     * produce /blog/blog/my-post. Since the guard makes the operation
     * idempotent, both kinds of install can share one code path.
     *
     * @param string $path      Normalised path.
     * @param string $post_type Post type name, or '' for none.
     * @return string
     */
    private static function apply_prefix($path, $post_type)
    {
        $prefix = Metasync_Headless_Config::get_path_prefix($post_type);
        if ($prefix === '') {
            return $path;
        }

        $prefixed = '/' . $prefix;

        if ($path === $prefixed || strpos($path, $prefixed . '/') === 0) {
            return $path;
        }

        return $prefixed . $path;
    }

    /**
     * Apply the configured trailing-slash policy.
     *
     * The root path is exempt from `strip`: reducing '/' to '' would leave the
     * bare origin, and while a browser treats that as the root, it is a
     * different string from every other canonical the site emits — enough to
     * read as a distinct URL to anything comparing them.
     *
     * @param string $path Normalised, prefixed path.
     * @return string
     */
    private static function apply_slash_policy($path)
    {
        $policy = Metasync_Headless_Config::get_slash_policy();

        if ($policy === Metasync_Headless_Config::SLASH_STRIP) {
            return $path === '/' ? '/' : rtrim($path, '/');
        }

        if ($policy === Metasync_Headless_Config::SLASH_ADD) {
            return substr($path, -1) === '/' ? $path : $path . '/';
        }

        # SLASH_PRESERVE — hand back whatever the permalink structure produced.
        return $path;
    }
}
