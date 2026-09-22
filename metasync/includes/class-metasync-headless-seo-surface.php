<?php
/**
 * Headless SEO surface — the resolvers that build a payload for one object.
 *
 * Mirrors the shape Yoast exposes: an entry point per object type, each
 * returning one object carrying every field, built in a single pass. Posts,
 * terms, the homepage, the posts page, post-type archives and authors each have
 * their own entry point; the bulk resolver follows the same contract.
 *
 * Resolution order for every field:
 *
 *     manual override -> OTTO -> Yoast -> WordPress default
 *
 * The override and OTTO keys, and the order between them, are not invented
 * here — they are the same chains Metasync_Plugin_Sync,
 * Metasync_Term_Plugin_Sync and Metasync_SEO_Conflict_Handler already walk.
 * OTTO values are durable meta (`_metasync_otto_*`, including the JSON-LD), so
 * this reads existing storage rather than introducing any.
 *
 * Which rungs exist is a property of the object type, not an oversight. Only
 * posts and terms have MetaSync and OTTO storage. Only posts, terms and authors
 * have per-object Yoast storage, and Yoast keeps all three in different places:
 * post meta, one option row, and user meta respectively. Post-type archives and
 * a homepage with no page assigned to it have no per-object storage anywhere, so
 * their chains are short by nature. Each resolver below documents its own.
 *
 * Three properties matter more than the field list:
 *
 *  - **One pass over the object's meta.** The meta table is read once and every
 *    field is resolved from that array, so a payload costs one meta read rather
 *    than one per field. It also guarantees the fields agree with each other:
 *    a title and a canonical resolved in the same pass cannot come from
 *    different sources.
 *  - **Every URL goes through Metasync_Headless_Url_Builder.** Not one field
 *    returns get_permalink(), get_term_link(), get_author_posts_url(),
 *    get_post_type_archive_link() or home_url() directly. Those all name the
 *    WordPress host, and terms, the homepage and archives emitting the backend
 *    host is the defect this feature exists to remove.
 *  - **Nothing throws.** The delivery layer is expected to be nullable and fail
 *    closed, but that is a thin last line of defence. This layer does not lean
 *    on it: an unknown id, a taxonomy that has been unregistered, a deleted
 *    user, a corrupted meta row all produce null or a partial payload rather
 *    than an exception.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

if (!defined('ABSPATH')) {
    exit; # Exit if accessed directly
}

class Metasync_Headless_Seo_Surface
{
    /**
     * Request-scoped memo of Yoast's `wpseo_taxonomy_meta` option.
     *
     * One row holds every term's Yoast data, so a request resolving many
     * terms should read it once rather than once per term.
     *
     * @var array|null
     */
    private static $yoast_taxonomy_meta = null;

    /**
     * Request-scoped payload memo, keyed by object type and id.
     *
     * @var array<string,Metasync_Headless_Seo_Data|null>
     */
    private static $payload_cache = array();

    /**
     * Build the SEO payload for one post, page or custom post type entry.
     *
     * Accepts an object as well as an id, matching get_post(), so a caller that
     * already holds the post does not pay for a second lookup.
     *
     * @param int|string|WP_Post $post Post ID or object.
     * @return Metasync_Headless_Seo_Data|null Null when there is no such post.
     */
    public static function for_post($post)
    {
        $post = self::resolve_post($post);
        if ($post === null) {
            return null;
        }

        # Gated here as well as in each transport. The docblock above anticipates
        # a REST route sharing this entry point, and leaving flag-off correctness
        # to whichever caller arrives next is the same shape the URL builder
        # deliberately refuses. Inert by construction, not by discipline.
        if (!Metasync_Headless_Config::is_enabled()) {
            return null;
        }

        $cache_key = self::payload_cache_key('post', (int) $post->ID);
        if (array_key_exists($cache_key, self::$payload_cache)) {
            return self::$payload_cache[$cache_key];
        }

        # This payload is built for a public endpoint, so publication state is a
        # precondition rather than a detail. See is_publicly_viewable().
        if (!self::is_publicly_viewable($post)) {
            self::$payload_cache[$cache_key] = null;

            return null;
        }

        # One read; every field below resolves out of this array.
        $meta = self::normalize_meta(static::read_post_meta($post->ID));

        $post_type  = (string) $post->post_type;
        $public_url = Metasync_Headless_Url_Builder::from_wp_url(static::read_permalink($post), $post_type);

        $title       = self::resolve_title($meta, $post);
        $description = self::resolve_description($meta, $post);
        $canonical   = self::resolve_canonical($meta, $public_url, $post_type);

        # OG and Twitter cascade into each other: an unset Twitter title falls
        # back to the OG title, which falls back to the SEO title. Resolving in
        # that order means each fallback reads a finished value rather than
        # re-walking the chain underneath it.
        $og_title       = self::resolve_og_title($meta, $post, $title);
        $og_description = self::resolve_og_description($meta, $description, $post);
        $og_image       = self::resolve_og_image($meta, $post);

        $payload = new Metasync_Headless_Seo_Data(array(
            'title'               => $title,
            'description'         => $description,
            'keywords'            => self::resolve_keywords($meta),
            'canonical'           => $canonical,
            'robots'              => self::resolve_robots($meta),

            'og_title'            => $og_title,
            'og_description'      => $og_description,
            'og_image'            => $og_image,
            # og:url is the same address as the canonical. Emitting the two
            # differently is a classic way to tell a crawler two things at once.
            'og_url'              => $canonical,
            'og_type'             => self::resolve_og_type($meta, $post),
            'og_site_name'        => static::read_site_name(),
            'og_locale'           => static::read_site_locale(),

            'twitter_card'        => self::resolve_twitter_card($meta),
            'twitter_title'       => self::resolve_twitter_title($meta, $og_title, $post),
            'twitter_description' => self::resolve_twitter_description($meta, $og_description, $post),
            'twitter_image'       => self::resolve_twitter_image($meta, $og_image),

            'schema'              => self::resolve_schema($meta),

            'public_url'          => $public_url,

            'object_type'         => 'post',
            'object_id'           => (int) $post->ID,
            'object_sub_type'     => $post_type,
        ));

        self::$payload_cache[$cache_key] = $payload;

        return $payload;
    }

    /**
     * Resolve several posts after priming their metadata cache once.
     *
     * Each object is isolated so one malformed post cannot discard the rest of
     * a listing. Results retain the caller's order and use the same payloads as
     * for_post(), including its request-scoped memoisation.
     *
     * @param array $ids Post IDs.
     * @return array<int,Metasync_Headless_Seo_Data|null>
     */
    public static function for_posts(array $ids)
    {
        if (!Metasync_Headless_Config::is_enabled()) {
            return array_fill(0, count($ids), null);
        }

        $normalised = array();
        foreach ($ids as $id) {
            $id = is_numeric($id) ? (int) $id : 0;
            if ($id > 0) {
                $normalised[] = $id;
            } else {
                $normalised[] = 0;
            }
        }

        $prime_ids = array();
        foreach (array_values(array_unique(array_filter($normalised))) as $id) {
            if (!array_key_exists(self::payload_cache_key('post', $id), self::$payload_cache)) {
                $prime_ids[] = $id;
            }
        }
        if (!empty($prime_ids)) {
            try {
                static::prime_post_meta($prime_ids);
            } catch (Throwable $e) {
                # A cache backend failure must not turn one bad primer into a
                # failed listing; individual resolution still has its normal
                # fail-soft boundary below.
            }
        }

        $payloads = array();
        foreach ($normalised as $id) {
            if ($id <= 0) {
                $payloads[] = null;
                continue;
            }

            try {
                $payloads[] = static::for_post($id);
            } catch (Throwable $e) {
                $payloads[] = null;
            }
        }

        return $payloads;
    }

    /**
     * Build the SEO payload for one taxonomy term.
     *
     * Terms are the type this feature exists for. The rendered site derives a
     * term canonical from term meta when an operator supplied one, otherwise
     * from get_term_link(), which names the WordPress host until rehosted.
     *
     * Accepts an object as well as an id, matching get_term().
     *
     * @param int|string|WP_Term $term Term ID or object.
     * @return Metasync_Headless_Seo_Data|null Null when there is no such term,
     *                                         or when it is not public.
     */
    public static function for_term($term)
    {
        # Checked before anything is looked up. for_post() resolves its argument
        # first because it may be handed an object it can use as-is; here there
        # is nothing to gain by doing any work while the mode is off.
        if (!Metasync_Headless_Config::is_enabled()) {
            return null;
        }

        $term = self::resolve_term($term);
        if ($term === null) {
            return null;
        }

        $cache_key = self::payload_cache_key('term', (int) $term->term_id);
        if (array_key_exists($cache_key, self::$payload_cache)) {
            return self::$payload_cache[$cache_key];
        }

        if (!self::is_term_publicly_viewable($term)) {
            self::$payload_cache[$cache_key] = null;

            return null;
        }

        # One term-meta read; every MetaSync and OTTO field resolves out of it.
        $meta = self::normalize_meta(static::read_term_meta((int) $term->term_id));

        # Yoast's term data is NOT in term meta. It lives in a single
        # `wpseo_taxonomy_meta` option keyed [taxonomy][term_id][key], with the
        # short `wpseo_` prefix rather than the `_yoast_wpseo_` prefix used for
        # posts. Reading it as term meta finds nothing, silently — a Yoast rung
        # that never fires. Metasync_Term_Plugin_Sync writes this same store, so
        # the key names here are its field map read backwards.
        $yoast = self::yoast_term_values($term);

        $public_url = Metasync_Headless_Url_Builder::from_wp_url(static::read_term_link($term), '');

        $title       = self::resolve_term_title($meta, $yoast, $term);
        $description = self::resolve_term_description($meta, $yoast, $term);
        $canonical   = self::resolve_term_canonical($meta, $yoast, $public_url, $term);

        $og_title       = self::resolve_term_og_title($meta, $yoast, $term, $title);
        $og_description = self::resolve_term_og_description($meta, $yoast, $term, $description);
        $og_image       = self::resolve_term_og_image($meta, $yoast);

        $payload = new Metasync_Headless_Seo_Data(array(
            'title'               => $title,
            'description'         => $description,
            'keywords'            => self::resolve_keywords($meta),
            'canonical'           => $canonical,
            'robots'              => self::resolve_term_robots($meta, $yoast),

            'og_title'            => $og_title,
            'og_description'      => $og_description,
            'og_image'            => $og_image,
            'og_url'              => $canonical,
            'og_type'             => self::resolve_section_og_type($meta, 'website'),
            'og_site_name'        => static::read_site_name(),
            'og_locale'           => static::read_site_locale(),

            'twitter_card'        => self::resolve_twitter_card($meta),
            'twitter_title'       => self::resolve_term_twitter_title($meta, $yoast, $term, $og_title),
            'twitter_description' => self::resolve_term_twitter_description($meta, $yoast, $term, $og_description),
            'twitter_image'       => self::resolve_term_twitter_image($meta, $yoast, $og_image),

            'schema'              => self::resolve_schema($meta),

            'public_url'          => $public_url,

            'object_type'         => 'term',
            'object_id'           => (int) $term->term_id,
            'object_sub_type'     => (string) $term->taxonomy,
        ));

        self::$payload_cache[$cache_key] = $payload;

        return $payload;
    }

    /**
     * Build the SEO payload for the site front page.
     *
     * Two different objects wear this name, and WordPress decides which by
     * `show_on_front`:
     *
     *  - `page` with a page assigned — the front page is a real post, so it has
     *    the whole post chain: sidebar override, OTTO, Yoast post meta. Only its
     *    address differs, because the frontend serves it at the root rather than
     *    at the page's own path.
     *  - `posts` — there is no object at all. Nothing in this plugin, in OTTO or
     *    in Yoast stores per-object SEO for it, so the payload is built from the
     *    site's own name and tagline.
     *
     * @return Metasync_Headless_Seo_Data|null
     */
    public static function for_home_page()
    {
        if (!Metasync_Headless_Config::is_enabled()) {
            return null;
        }

        $front = self::front_page_settings();

        # Never get_permalink() and never home_url(): the front page's address on
        # the public site is the frontend root, with the configured slash policy
        # applied. No post type is passed, so a `page` path prefix cannot push
        # the homepage down to /pages/ — the root is the root.
        $public_url = Metasync_Headless_Url_Builder::home();

        if ($front['front_page'] > 0) {
            $page = static::read_post($front['front_page']);
            if (!($page instanceof WP_Post)) {
                return null;
            }

            # An operator who assigned a draft or protected page as the front
            # page has a broken homepage either way; building a payload out of
            # its meta would publish the draft's title and description. Refuse
            # for the same reason for_post() refuses.
            if (!self::is_publicly_viewable($page)) {
                return null;
            }

            return self::payload_for_page($page, 'home', $public_url);
        }

        return self::payload_for_site($public_url, 'home');
    }

    /**
     * Build the SEO payload for the blog index when a static page holds it.
     *
     * Deliberately narrow: a posts page exists only when a static page is on the
     * front and a *different* page is assigned to hold the posts. With
     * `show_on_front = posts` the blog index and the front page are the same
     * address, and for_home_page() already describes it — returning a second
     * payload for it would give one URL two object types.
     *
     * The assigned page is a real post, so the full post chain applies. That
     * also matches what the rendered site already does: its archive canonical
     * honours `_metasync_canonical_url` on this page's post meta.
     *
     * @return Metasync_Headless_Seo_Data|null Null when there is no posts page.
     */
    public static function for_posts_page()
    {
        if (!Metasync_Headless_Config::is_enabled()) {
            return null;
        }

        $front = self::front_page_settings();

        if ($front['front_page'] <= 0 || $front['posts_page'] <= 0) {
            return null;
        }

        # A site with both settings pointing at one page has no posts page —
        # WordPress serves that page as the front page. Answering here as well
        # would hand the same URL out under two object types.
        if ($front['posts_page'] === $front['front_page']) {
            return null;
        }

        $page = static::read_post($front['posts_page']);
        if (!($page instanceof WP_Post) || !self::is_publicly_viewable($page)) {
            return null;
        }

        # The page's own path, re-hosted. No post type passed: the blog index is
        # a route of the frontend's own, not a single page served under a
        # per-post-type prefix.
        $public_url = Metasync_Headless_Url_Builder::from_wp_url(static::read_permalink($page), '');

        return self::payload_for_page($page, 'posts_page', $public_url);
    }

    /**
     * Build the SEO payload for whatever WordPress serves as the blog index.
     *
     * This exists because a caller cannot tell, from a null, *why* there is no
     * posts page. for_posts_page() returns null for several different reasons —
     * the site shows posts on the front, no page is assigned, the assigned page
     * is also the front page, or the assigned page exists but is a draft and so
     * is refused. The first three mean "the front page is the blog index"; the
     * last does not. A caller that treats them alike answers a request about the
     * blog index with the front page's title and canonical, which is a payload
     * for a different object.
     *
     * So the decision lives here, where the settings are already read, rather
     * than in a transport that would have to guess from a null.
     *
     * @return Metasync_Headless_Seo_Data|null
     */
    public static function for_blog_index()
    {
        if (!Metasync_Headless_Config::is_enabled()) {
            return null;
        }

        return self::blog_index_is_front_page()
            ? self::for_home_page()
            : self::for_posts_page();
    }

    /**
     * Does the front page double as the blog index?
     *
     * True when the site shows posts on the front, when no separate page is
     * assigned to hold the index, or when the same page is assigned to both.
     * False when a distinct page is assigned — whether or not that page turns
     * out to be publicly viewable, which is the distinction that matters: a
     * draft posts page means the blog index has no public payload, not that the
     * front page is standing in for it.
     *
     * @return bool
     */
    private static function blog_index_is_front_page()
    {
        $front = self::front_page_settings();

        if ($front['posts_page'] <= 0) {
            return true;
        }

        return $front['posts_page'] === $front['front_page'];
    }
    /**
     * Build the SEO payload for a custom post type's archive.
     *
     * The thinnest of the entry points, and honestly so: nothing in this plugin,
     * in OTTO, or in Yoast's per-object storage holds SEO data for a post-type
     * archive. There is no id to hang meta off. So the payload is derived from
     * the registered post type — its plural label and its description — and the
     * value this entry point actually adds is the URL, which the rendered site
     * does not emit for a post-type archive at all.
     *
     * @param mixed $post_type Post type name.
     * @return Metasync_Headless_Seo_Data|null
     */
    public static function for_post_type_archive($post_type)
    {
        if (!Metasync_Headless_Config::is_enabled()) {
            return null;
        }

        $post_type = is_string($post_type) ? trim($post_type) : '';
        if ($post_type === '') {
            return null;
        }

        # `post` is excluded on purpose. It has no archive of its own — its
        # listing is the blog index — and get_post_type_archive_link('post')
        # special-cases it to return the homepage or the posts page. Without this
        # guard an install that had filtered has_archive onto `post` would get a
        # homepage URL back labelled as a post-type archive.
        if ($post_type === 'post') {
            return null;
        }

        $type = static::read_post_type_object($post_type);
        if (!is_object($type)) {
            return null;
        }

        if (!self::is_post_type_archive_publicly_viewable($type)) {
            return null;
        }

        $public_url = Metasync_Headless_Url_Builder::from_wp_url(
            static::read_post_type_archive_link($post_type),
            ''
        );

        $title       = self::post_type_archive_title($type, $post_type);
        # Deliberately NOT the registered post type description. That field is
        # WordPress's own admin-facing summary of what the post type is for —
        # it is shown in wp-admin and rendered nowhere on the public archive.
        # Publishing it as the meta description would break this payload's own
        # rule that everything in it is already visible to a visitor, and on an
        # endpoint with no auth gate an internal note such as "internal records,
        # do not expose" would become the page's description. An archive has no
        # per-object description storage anywhere, so the honest answer is none.
        $description = '';

        return new Metasync_Headless_Seo_Data(array(
            'title'               => $title,
            'description'         => $description,
            'keywords'            => '',
            'canonical'           => $public_url,
            # No robots claim. A term delegates to the shared resolver because
            # `_metasync_robots_index` is real term storage; nothing anywhere
            # stores robots for a post-type archive, so asserting a serving
            # default over an object we know nothing about would be inventing a
            # directive rather than reporting one.
            'robots'              => '',

            'og_title'            => $title,
            'og_description'      => $description,
            'og_image'            => '',
            'og_url'              => $public_url,
            'og_type'             => 'website',
            'og_site_name'        => static::read_site_name(),
            'og_locale'           => static::read_site_locale(),

            'twitter_card'        => self::resolve_twitter_card(array()),
            'twitter_title'       => $title,
            'twitter_description' => $description,
            'twitter_image'       => '',

            'schema'              => array(),

            'public_url'          => $public_url,

            'object_type'         => 'post_type_archive',
            'object_id'           => 0,
            'object_sub_type'     => $post_type,
        ));
    }

    /**
     * Build the SEO payload for an author archive.
     *
     * The one type where Yoast is the only third-party rung and it lives in user
     * meta — `wpseo_title` and `wpseo_metadesc`, again without the
     * `_yoast_wpseo_` prefix the post keys carry. This plugin stores no author
     * SEO of its own and OTTO does not suggest any, so the chain is genuinely
     * Yoast then WordPress: the display name and the biography.
     *
     * @param int|string|WP_User $user User ID or object.
     * @return Metasync_Headless_Seo_Data|null
     */
    public static function for_author($user)
    {
        if (!Metasync_Headless_Config::is_enabled()) {
            return null;
        }

        $user = self::resolve_user($user);
        if ($user === null) {
            return null;
        }

        $cache_key = self::payload_cache_key('author', (int) $user->ID);
        if (array_key_exists($cache_key, self::$payload_cache)) {
            return self::$payload_cache[$cache_key];
        }

        if (!self::is_author_publicly_viewable($user)) {
            self::$payload_cache[$cache_key] = null;

            return null;
        }

        $meta = self::normalize_meta(static::read_user_meta((int) $user->ID));

        $public_url = Metasync_Headless_Url_Builder::from_wp_url(
            static::read_author_posts_url((int) $user->ID),
            ''
        );

        $title = self::yoast_value($meta, 'wpseo_title', $user);
        if ($title === '') {
            $title = self::author_display_name($user);
        }

        $description = self::yoast_value($meta, 'wpseo_metadesc', $user);
        if ($description === '') {
            # The biography, which the plugin's own Person schema node already
            # publishes for this same archive.
            $description = self::plain_text_summary(self::first($meta, array('description')));
        }

        $payload = new Metasync_Headless_Seo_Data(array(
            'title'               => $title,
            'description'         => $description,
            'keywords'            => '',
            'canonical'           => $public_url,
            # As for a post-type archive: no author robots storage exists, so the
            # payload makes no claim rather than inventing one.
            'robots'              => '',

            'og_title'            => $title,
            'og_description'      => $description,
            'og_image'            => '',
            'og_url'              => $public_url,
            # An author archive describes a person, which is what og:type
            # profile is for.
            'og_type'             => 'profile',
            'og_site_name'        => static::read_site_name(),
            'og_locale'           => static::read_site_locale(),

            'twitter_card'        => self::resolve_twitter_card(array()),
            'twitter_title'       => $title,
            'twitter_description' => $description,
            'twitter_image'       => '',

            'schema'              => array(),

            'public_url'          => $public_url,

            'object_type'         => 'author',
            'object_id'           => (int) $user->ID,
            'object_sub_type'     => '',
        ));

        self::$payload_cache[$cache_key] = $payload;

        return $payload;
    }

    /* -----------------------------------------------------------------
     *  Payload builders shared by the entry points above
     * ----------------------------------------------------------------- */

    /**
     * The payload for an entry point whose object really is a page.
     *
     * The front page and the posts page are pages: they have post meta, so the
     * sidebar override, OTTO and Yoast post chains all apply to them exactly as
     * they do to any other page, and restating those chains here would be a
     * second implementation that could drift. What is genuinely different is the
     * address — the frontend serves these at the root and at the blog index,
     * not at the page's own prefixed path — and what the payload says it is. So
     * only those are passed in.
     *
     * This is not the post path with a flag on it: the caller has already
     * decided which object it is describing, established that it exists and is
     * public, and built the URL. This assembles the fields for it.
     *
     * @param WP_Post $page        The assigned page.
     * @param string  $object_type What the payload describes: home or posts_page.
     * @param string  $public_url  Already-built frontend URL.
     * @return Metasync_Headless_Seo_Data
     */
    private static function payload_for_page($page, $object_type, $public_url)
    {
        $meta = self::normalize_meta(static::read_post_meta($page->ID));

        $title       = self::resolve_title($meta, $page);
        $description = self::resolve_description($meta, $page);

        # No post type passed to the canonical resolver either: a Yoast canonical
        # re-hosted for the front page must land on the root, not under a `page`
        # path prefix.
        $canonical = self::resolve_canonical($meta, $public_url, '');

        $og_title       = self::resolve_og_title($meta, $page, $title);
        $og_description = self::resolve_og_description($meta, $description, $page);
        $og_image       = self::resolve_og_image($meta, $page);

        return new Metasync_Headless_Seo_Data(array(
            'title'               => $title,
            'description'         => $description,
            'keywords'            => self::resolve_keywords($meta),
            'canonical'           => $canonical,
            'robots'              => self::resolve_robots($meta),

            'og_title'            => $og_title,
            'og_description'      => $og_description,
            'og_image'            => $og_image,
            'og_url'              => $canonical,
            # Always website, never article: the front page and the blog index
            # are site sections whatever post type happens to be behind them.
            'og_type'             => self::resolve_section_og_type($meta, 'website'),
            'og_site_name'        => static::read_site_name(),
            'og_locale'           => static::read_site_locale(),

            'twitter_card'        => self::resolve_twitter_card($meta),
            'twitter_title'       => self::resolve_twitter_title($meta, $og_title, $page),
            'twitter_description' => self::resolve_twitter_description($meta, $og_description, $page),
            'twitter_image'       => self::resolve_twitter_image($meta, $og_image),

            'schema'              => self::resolve_schema($meta),

            'public_url'          => $public_url,

            'object_type'         => (string) $object_type,
            'object_id'           => (int) $page->ID,
            'object_sub_type'     => (string) $page->post_type,
        ));
    }

    /**
     * The payload for the front page when no page is assigned to it.
     *
     * There is no object, so there is no storage: not a MetaSync key, not an
     * OTTO suggestion, not a Yoast per-object value. Everything comes from the
     * site itself, which is also what the rendered site falls back to.
     *
     * @param string $public_url  Already-built frontend URL.
     * @param string $object_type What the payload describes.
     * @return Metasync_Headless_Seo_Data
     */
    private static function payload_for_site($public_url, $object_type)
    {
        $title       = static::read_site_name();
        $description = static::read_site_tagline();

        return new Metasync_Headless_Seo_Data(array(
            'title'               => $title,
            'description'         => $description,
            'keywords'            => '',
            'canonical'           => $public_url,
            'robots'              => '',

            'og_title'            => $title,
            'og_description'      => $description,
            'og_image'            => '',
            'og_url'              => $public_url,
            'og_type'             => 'website',
            'og_site_name'        => $title,
            'og_locale'           => static::read_site_locale(),

            'twitter_card'        => self::resolve_twitter_card(array()),
            'twitter_title'       => $title,
            'twitter_description' => $description,
            'twitter_image'       => '',

            'schema'              => array(),

            'public_url'          => $public_url,

            'object_type'         => (string) $object_type,
            'object_id'           => 0,
            'object_sub_type'     => '',
        ));
    }

    /* -----------------------------------------------------------------
     *  Read seams
     *
     *  Every place this class touches WordPress storage, kept separate so
     *  the bulk resolver can pre-fetch for many objects in one query and
     *  hand the results down rather than having each object repeat a
     *  lookup. Called through static:: so a subclass can substitute them.
     *
     *  They also keep the tests off the ambient WordPress stubs. Around 47
     *  test files in this suite each declare their own get_post_meta,
     *  get_term_meta, get_term_link, get_author_posts_url and home_url
     *  behind function_exists() guards, so whichever loads first wins for
     *  the whole process. A resolver that called those directly would pass
     *  alone and fail in a full run, on load order alone.
     * ----------------------------------------------------------------- */

    /**
     * All post meta for one post, in get_post_meta($id) shape.
     *
     * @param int $post_id
     * @return array
     */
    protected static function read_post_meta($post_id)
    {
        return get_post_meta($post_id);
    }

    /**
     * Prime all post metadata needed by a bulk resolution.
     *
     * @param int[] $post_ids
     * @return void
     */
    protected static function prime_post_meta(array $post_ids)
    {
        if (function_exists('update_meta_cache')) {
            update_meta_cache('post', $post_ids);
        }
    }

    /**
     * One post by id.
     *
     * @param int $post_id
     * @return mixed WP_Post, or anything else when there is no such post.
     */
    protected static function read_post($post_id)
    {
        return get_post($post_id);
    }

    /**
     * One term by id.
     *
     * Returned as WordPress gives it: a WP_Term, null for an id that does not
     * exist, or a WP_Error when the taxonomy is not registered. The caller
     * decides, so neither outcome is flattened away here.
     *
     * @param int $term_id
     * @return mixed
     */
    protected static function read_term($term_id)
    {
        return get_term($term_id);
    }

    /**
     * All term meta for one term, in get_term_meta($id) shape.
     *
     * @param int $term_id
     * @return array
     */
    protected static function read_term_meta($term_id)
    {
        return get_term_meta($term_id);
    }

    /**
     * The WordPress archive link for one term.
     *
     * Returned as WordPress gives it, including the WP_Error core produces for a
     * term it cannot build a link for. The URL builder already treats anything
     * that is not a usable string as "no URL", so re-checking here would only
     * duplicate that.
     *
     * @param WP_Term $term
     * @return mixed
     */
    protected static function read_term_link($term)
    {
        return get_term_link($term);
    }

    /**
     * Yoast's whole taxonomy-meta option.
     *
     * One option row for every term on the site, which is why it is read behind
     * a seam and only once per payload: a bulk resolver reads it once for a
     * hundred terms rather than a hundred times.
     *
     * @return mixed
     */
    protected static function read_yoast_taxonomy_meta()
    {
        # Memoised for the request. This option holds every term's Yoast data
        # in one row, so a query resolving many terms would otherwise walk the
        # same structure once per term.
        if (self::$yoast_taxonomy_meta !== null) {
            return self::$yoast_taxonomy_meta;
        }

        self::$yoast_taxonomy_meta = self::read_yoast_taxonomy_meta_uncached();

        return self::$yoast_taxonomy_meta;
    }

    /**
     * Drop the request-scoped memo of Yoast's taxonomy option.
     *
     * @return void
     */
    public static function flush_request_cache()
    {
        self::$yoast_taxonomy_meta = null;
        self::$payload_cache = array();
    }

    /**
     * Build a collision-proof request-cache key.
     *
     * @param string $object_type
     * @param int    $object_id
     * @return string
     */
    private static function payload_cache_key($object_type, $object_id)
    {
        return (string) $object_type . ':' . (int) $object_id;
    }

    /**
     * The uncached read, so the memo above is its only caller while a
     * subclass can still substitute the storage.
     *
     * @return array
     */
    protected static function read_yoast_taxonomy_meta_uncached()
    {
        return get_option('wpseo_taxonomy_meta');
    }

    /**
     * The three options that decide what the front page and blog index are.
     *
     * @return mixed Array of the three options; a subclass may substitute it.
     */
    protected static function read_front_page_options()
    {
        return array(
            'show_on_front'  => get_option('show_on_front'),
            'page_on_front'  => get_option('page_on_front'),
            'page_for_posts' => get_option('page_for_posts'),
        );
    }

    /**
     * The registered post type object, or null.
     *
     * @param string $post_type
     * @return mixed
     */
    protected static function read_post_type_object($post_type)
    {
        return get_post_type_object($post_type);
    }

    /**
     * The WordPress archive link for a post type.
     *
     * @param string $post_type
     * @return mixed
     */
    protected static function read_post_type_archive_link($post_type)
    {
        return get_post_type_archive_link($post_type);
    }

    /**
     * One user by id.
     *
     * @param int $user_id
     * @return mixed WP_User, or false when there is no such user.
     */
    protected static function read_user($user_id)
    {
        return get_userdata($user_id);
    }

    /**
     * All user meta for one user, in get_user_meta($id) shape.
     *
     * @param int $user_id
     * @return array
     */
    protected static function read_user_meta($user_id)
    {
        return get_user_meta($user_id);
    }

    /**
     * The WordPress author archive URL for one user.
     *
     * @param int $user_id
     * @return mixed
     */
    protected static function read_author_posts_url($user_id)
    {
        return get_author_posts_url($user_id);
    }

    /**
     * How many published entries this author has, across every public post type.
     *
     * Counted as a superset rather than just `post`: a headless frontend defines
     * its own author route and may well list a custom post type there, so
     * counting only `post` would withhold a payload for a page the frontend is
     * really serving. Attachments are left out because a media item is not
     * authored content in this sense.
     *
     * @param int $user_id
     * @return int
     */
    protected static function read_author_published_count($user_id)
    {
        if (!function_exists('count_user_posts')) {
            # Nothing to count with. Treated as "has content" rather than "has
            # none", so an unexpected environment withholds nothing.
            return 1;
        }

        $types = array('post');

        if (function_exists('get_post_types')) {
            $public = get_post_types(array('public' => true), 'names');
            if ($public !== array()) {
                unset($public['attachment']);
                $types = array_values($public);
            }
        }

        if ($types === array()) {
            return 0;
        }

        return (int) count_user_posts($user_id, $types, true);
    }

    /**
     * The site's name, for og:site_name.
     *
     * @return string
     */
    protected static function read_site_name()
    {
        return (string) get_bloginfo('name');
    }

    /**
     * The site's tagline, used as the homepage description when no page holds it.
     *
     * @return string
     */
    protected static function read_site_tagline()
    {
        return (string) get_bloginfo('description');
    }

    /**
     * The site's locale, for og:locale.
     *
     * @return string
     */
    protected static function read_site_locale()
    {
        return (string) get_locale();
    }

    /**
     * Is this taxonomy one the public site serves archives for?
     *
     * @param string $taxonomy
     * @return bool
     */
    protected static function read_taxonomy_is_viewable($taxonomy)
    {
        if (function_exists('is_taxonomy_viewable')) {
            return (bool) is_taxonomy_viewable($taxonomy);
        }

        # WordPress < 5.1. Mirrors what core's helper does.
        $object = function_exists('get_taxonomy') ? get_taxonomy($taxonomy) : false;
        if (!is_object($object)) {
            return false;
        }

        return !empty($object->publicly_queryable) || !empty($object->public);
    }


    /**
     * The WordPress permalink for one post.
     *
     * Returned as WordPress gives it, including the `false` core produces for a
     * post it cannot build a link for. The URL builder already treats anything
     * that is not a usable string as "no URL", so re-checking here would only
     * duplicate that.
     *
     * @param WP_Post $post
     * @return mixed
     */
    protected static function read_permalink($post)
    {
        return get_permalink($post);
    }

    /**
     * The featured image URL for one post, or '' when there is none.
     *
     * wp_get_attachment_url() is the call media-offload plugins filter, so this
     * resolves correctly when the file lives in remote object storage rather
     * than on local disk. Deliberately no disk access and no HTTP validation: a
     * missing local file is not evidence of a missing attachment.
     *
     * @param WP_Post $post
     * @return string
     */
    protected static function read_featured_image_url($post)
    {
        $thumbnail_id = get_post_thumbnail_id($post);
        if (!$thumbnail_id) {
            return '';
        }

        $url = wp_get_attachment_url($thumbnail_id);

        return is_string($url) ? $url : '';
    }

    /* -----------------------------------------------------------------
     *  Field resolution
     * ----------------------------------------------------------------- */

    /**
     * Accept an id or an object and return the post, or null.
     *
     * @param int|string|WP_Post $post
     * @return WP_Post|null
     */
    private static function resolve_post($post)
    {
        if ($post instanceof WP_Post) {
            return $post;
        }

        $post_id = is_numeric($post) ? (int) $post : 0;
        if ($post_id <= 0) {
            return null;
        }

        $found = static::read_post($post_id);

        return $found instanceof WP_Post ? $found : null;
    }

    /**
     * May this object's SEO data be handed to an anonymous caller?
     *
     * The delivery layers in front of this are public: a headless GraphQL
     * endpoint frequently has no auth gate at all. So the check belongs here,
     * where every entry point shares it, rather than in each transport.
     *
     * Refusing outright — rather than returning a payload with the private parts
     * blanked — is deliberate. The description falls back to post content, the
     * schema graph embeds the title, and robots settings describe an unpublished
     * editorial decision; a partial payload for a draft still leaks the draft.
     *
     * Password-protected posts are refused for the same reason: the password
     * gates the content, and the description fallback is built from that content.
     *
     * @param WP_Post $post
     * @return bool
     */
    private static function is_publicly_viewable($post)
    {
        # The content behind a password must not leak through a description
        # derived from it.
        if ((string) $post->post_password !== '') {
            return false;
        }

        # An attachment carries its own (almost always empty) password field, so
        # the check above says nothing about the post it belongs to. Core's
        # is_attachment_publicly_viewable() consults the parent's *status* but
        # not its password, which would leave a media item attached to a
        # password-protected post publicly resolvable. Close that here.
        if ((string) $post->post_type === 'attachment') {
            $parent_id = (int) $post->post_parent;
            if ($parent_id > 0) {
                $parent = static::read_post($parent_id);
                if (!($parent instanceof WP_Post) || !self::is_publicly_viewable($parent)) {
                    return false;
                }
            }
        }

        if (function_exists('is_post_publicly_viewable')) {
            return (bool) is_post_publicly_viewable($post);
        }

        # WordPress < 5.7. Mirrors what core's helper does: the status must be
        # publicly viewable and the post type must be viewable at all.
        $status = get_post_status_object((string) $post->post_status);
        $type   = get_post_type_object((string) $post->post_type);

        if (!is_object($status) || !is_object($type)) {
            return false;
        }

        return !empty($status->public) && !empty($type->public);
    }

    /**
     * Accept an id or an object and return the term, or null.
     *
     * get_term() has three failure shapes and they mean different things: null
     * for an id that is not there, a WP_Error when the taxonomy is not
     * registered, and a plain object on some filtered installs. All three become
     * null, because none of them is a term this can describe.
     *
     * @param int|string|WP_Term $term
     * @return WP_Term|null
     */
    private static function resolve_term($term)
    {
        if (!($term instanceof WP_Term)) {
            $term_id = is_numeric($term) ? (int) $term : 0;
            if ($term_id <= 0) {
                return null;
            }

            $term = static::read_term($term_id);
        }

        if (!($term instanceof WP_Term)) {
            return null;
        }

        # A term object with no taxonomy cannot be placed in the registry, so
        # neither its visibility nor its link can be established.
        if (self::text($term->taxonomy) === '') {
            return null;
        }

        return $term;
    }

    /**
     * Accept an id or an object and return the user, or null.
     *
     * @param int|string|WP_User $user
     * @return WP_User|null
     */
    private static function resolve_user($user)
    {
        if (!($user instanceof WP_User)) {
            $user_id = is_numeric($user) ? (int) $user : 0;
            if ($user_id <= 0) {
                return null;
            }

            $user = static::read_user($user_id);
        }

        if (!($user instanceof WP_User) || (int) $user->ID <= 0) {
            return null;
        }

        return $user;
    }

    /**
     * May this term's SEO data be handed to an anonymous caller?
     *
     * Two conditions, and one deliberate omission.
     *
     * The taxonomy must still be registered, and it must be one the public site
     * serves archives for. A term in an unregistered taxonomy has no route at
     * all — get_term_link() falls back to a `?taxonomy=…&term=…` query URL,
     * which the URL builder refuses — and a private taxonomy (an internal
     * `nav_menu` or `wp_theme` term, say) is not a page anybody can visit. Both
     * would otherwise produce a payload for an address that does not exist.
     *
     * A term with no posts is deliberately NOT refused. Its name and description
     * are already public — they appear in term lists, widgets and sitemaps — and
     * `count` is a denormalised cache that goes stale routinely: it is not
     * updated while term counting is deferred, and it does not count every
     * object type attached to the taxonomy. Refusing on a stale zero would blank
     * the SEO data of a page the frontend is really serving, and the payload
     * would flicker in and out as counts drift. An empty archive is a robots
     * question, not a "withhold everything" question.
     *
     * @param WP_Term $term
     * @return bool
     */
    private static function is_term_publicly_viewable($term)
    {
        return static::read_taxonomy_is_viewable((string) $term->taxonomy);
    }

    /**
     * May this post type's archive be described publicly?
     *
     * The post type has to be viewable at all, and it has to actually have an
     * archive. `has_archive` false means WordPress registers no archive route,
     * so there is no page to describe — and get_post_type_archive_link() returns
     * false for it, which would leave a payload with no URL.
     *
     * @param object $type Post type object.
     * @return bool
     */
    private static function is_post_type_archive_publicly_viewable($type)
    {
        if (empty($type->has_archive)) {
            return false;
        }

        if (function_exists('is_post_type_viewable')) {
            return (bool) is_post_type_viewable($type);
        }

        # WordPress < 4.4. Mirrors what core's helper does.
        return !empty($type->publicly_queryable) || !empty($type->public);
    }

    /**
     * May this author's archive be described publicly?
     *
     * Refused when the author has published nothing. Unlike a term, an author
     * archive's only content is an account — and the slug in its URL is
     * routinely the login name. An author with nothing published is very often an
     * administrator or a subscriber who should never have had a public URL at
     * all, so on an endpoint with no auth gate, listing them would hand out
     * account names that appear nowhere else on the site. There is also nothing
     * to describe: no posts, so no archive worth a title.
     *
     * The asymmetry with terms is deliberate. A term is authored content that
     * someone created on purpose and that the site already publishes, and its
     * count is a stale cache; an author's published count is queried live, so a
     * zero here is a fact rather than a drifting cache value.
     *
     * @param WP_User $user
     * @return bool
     */
    private static function is_author_publicly_viewable($user)
    {
        return static::read_author_published_count((int) $user->ID) > 0;
    }

    /**
     * What the front page and the blog index are, as ids.
     *
     * `page_on_front` and `page_for_posts` are both ignored by WordPress unless
     * `show_on_front` is `page`, so they are zeroed here rather than left for
     * each caller to remember. That is what makes "no static front page" and
     * "blog index at the root" one condition instead of two.
     *
     * @return array{front_page:int,posts_page:int,shows_posts_on_front:bool}
     */
    private static function front_page_settings()
    {
        $options = static::read_front_page_options();
        if (!is_array($options)) {
            $options = array();
        }

        $show = isset($options['show_on_front']) ? $options['show_on_front'] : '';
        $show = is_string($show) ? strtolower(trim($show)) : '';

        if ($show !== 'page') {
            return array(
                'front_page'           => 0,
                'posts_page'           => 0,
                'shows_posts_on_front' => true,
            );
        }

        $front = isset($options['page_on_front']) ? $options['page_on_front'] : 0;
        $posts = isset($options['page_for_posts']) ? $options['page_for_posts'] : 0;

        return array(
            'front_page'           => is_numeric($front) ? max(0, (int) $front) : 0,
            'posts_page'           => is_numeric($posts) ? max(0, (int) $posts) : 0,
            'shows_posts_on_front' => false,
        );
    }

    /* -----------------------------------------------------------------
     *  Term field resolution
     *
     *  The same chains as the post fields, over the two stores a term
     *  actually uses: MetaSync and OTTO values in term meta, Yoast values
     *  in its `wpseo_taxonomy_meta` option entry. Kept as their own
     *  resolvers rather than parameterising the post ones, because each
     *  reads exactly one store and the key names genuinely differ — a
     *  shared resolver taking "which store, which key" would read as
     *  configuration rather than as the chain it is.
     *
     *  There is no `_metasync_seo_*` rung: those two keys are the post
     *  sidebar's, and nothing writes them to term meta. `_metasync_metatitle`
     *  is the top rung for a term, which is what the MCP taxonomy tool and
     *  the third-party importer both write.
     * ----------------------------------------------------------------- */

    /**
     * This term's Yoast entry, flattened to key => value.
     *
     * Yoast strips its own defaults before saving, so a term that has never been
     * edited has no entry at all and a term that has been edited carries only the
     * fields that differ. Both come back as an array the chains can read.
     *
     * @param WP_Term $term
     * @return array
     */
    private static function yoast_term_values($term)
    {
        $option = static::read_yoast_taxonomy_meta();
        if (!is_array($option)) {
            return array();
        }

        $taxonomy = (string) $term->taxonomy;
        if (!isset($option[$taxonomy]) || !is_array($option[$taxonomy])) {
            return array();
        }

        $term_id = (int) $term->term_id;
        if (!isset($option[$taxonomy][$term_id]) || !is_array($option[$taxonomy][$term_id])) {
            return array();
        }

        return $option[$taxonomy][$term_id];
    }

    /**
     * Term SEO title: override -> OTTO -> imported -> Yoast -> the term name.
     *
     * @param array   $meta  Raw term meta.
     * @param array   $yoast This term's Yoast entry.
     * @param WP_Term $term
     * @return string
     */
    private static function resolve_term_title($meta, $yoast, $term)
    {
        $resolved = self::first($meta, array(
            '_metasync_metatitle',
            '_metasync_otto_title',
            '_metasync_imported_seo_title',
        ));

        if ($resolved === '') {
            $resolved = self::yoast_value($yoast, 'wpseo_title', $term);
        }

        return $resolved !== '' ? $resolved : self::text($term->name);
    }

    /**
     * Term meta description: override -> OTTO -> imported -> Yoast -> the term description.
     *
     * The term description is editorial rich text on many sites, so it gets the
     * same shortcode-stripping, tag-stripping, length-limited treatment a post's
     * content fallback gets rather than being emitted as stored.
     *
     * @param array   $meta  Raw term meta.
     * @param array   $yoast This term's Yoast entry.
     * @param WP_Term $term
     * @return string
     */
    private static function resolve_term_description($meta, $yoast, $term)
    {
        $resolved = self::first($meta, array(
            '_metasync_metadesc',
            '_metasync_otto_description',
            '_metasync_imported_seo_desc',
        ));

        if ($resolved === '') {
            $resolved = self::yoast_value($yoast, 'wpseo_desc', $term);
        }

        if ($resolved !== '') {
            return $resolved;
        }

        return self::plain_text_summary($term->description);
    }

    /**
     * Term canonical: override -> Yoast -> the public URL.
     *
     * `_metasync_canonical_url` in term meta is not invented here — the MCP
     * taxonomy tool and the third-party importer both write it, and
     * Metasync_Term_Plugin_Sync mirrors it outward into Yoast, Rank Math and
     * AIOSEO. Nothing in the plugin has ever read it back for a term, so this is
     * the first path that honours it; the rendered site still derives a term
     * canonical from get_term_link() and overwrites the operator's intent.
     *
     * Same two rules as the post canonical: an override is taken verbatim
     * because an absolute URL an operator typed is a deliberate statement, and a
     * Yoast canonical keeps only its path so a stale backend host cannot come
     * back through it.
     *
     * @param array   $meta       Raw term meta.
     * @param array   $yoast      This term's Yoast entry.
     * @param string  $public_url Already-built frontend URL.
     * @param WP_Term $term
     * @return string
     */
    private static function resolve_term_canonical($meta, $yoast, $public_url, $term)
    {
        $override = self::first($meta, array('_metasync_canonical_url', 'meta_canonical'));
        if ($override !== '' && class_exists('Metasync_Canonical_Sanitizer')) {
            $override = (string) Metasync_Canonical_Sanitizer::sanitize($override);
        }

        if ($override !== '') {
            return $override;
        }

        $yoast_canonical = self::yoast_value($yoast, 'wpseo_canonical', $term);
        if ($yoast_canonical !== '' && class_exists('Metasync_Canonical_Sanitizer')) {
            $yoast_canonical = (string) Metasync_Canonical_Sanitizer::sanitize($yoast_canonical);
        }

        if ($yoast_canonical !== '') {
            # No post type: a term archive is not served under a per-post-type
            # path prefix, so re-hosting must not insert one.
            $rehosted = Metasync_Headless_Url_Builder::from_wp_url($yoast_canonical, '');
            if ($rehosted !== '') {
                return $rehosted;
            }
        }

        return $public_url;
    }

    /**
     * Term og:title: override -> OTTO -> Yoast -> the SEO title.
     *
     * No equal-to-the-name guard here, unlike the post resolver. That guard
     * exists because the post OG meta box pre-fills its title from the post
     * title and persists the default on save, so a value equal to the post title
     * proves nothing. There is no term-side box that does that — the only writers
     * are the MCP tool and the importer, and both write only what they were
     * given — so a stored value is intent, even when it matches the term name.
     *
     * @param array   $meta  Raw term meta.
     * @param array   $yoast This term's Yoast entry.
     * @param WP_Term $term
     * @param string  $title Already-resolved SEO title.
     * @return string
     */
    private static function resolve_term_og_title($meta, $yoast, $term, $title)
    {
        $resolved = self::first($meta, array(
            '_metasync_og_title',
            '_metasync_otto_og_title',
        ));

        if ($resolved === '') {
            $resolved = self::yoast_value($yoast, 'wpseo_opengraph-title', $term);
        }

        return $resolved !== '' ? $resolved : $title;
    }

    /**
     * Term og:description: override -> OTTO -> Yoast -> the meta description.
     *
     * @param array   $meta        Raw term meta.
     * @param array   $yoast       This term's Yoast entry.
     * @param WP_Term $term
     * @param string  $description Already-resolved meta description.
     * @return string
     */
    private static function resolve_term_og_description($meta, $yoast, $term, $description)
    {
        $resolved = self::first($meta, array(
            '_metasync_og_description',
            '_metasync_otto_og_description',
        ));

        if ($resolved === '') {
            $resolved = self::yoast_value($yoast, 'wpseo_opengraph-description', $term);
        }

        return $resolved !== '' ? $resolved : $description;
    }

    /**
     * Term og:image: override -> Yoast.
     *
     * No OTTO rung and no featured-image fallback: OTTO suggests no social image
     * at any level, and a term has no featured image to fall back to.
     *
     * @param array $meta  Raw term meta.
     * @param array $yoast This term's Yoast entry.
     * @return string
     */
    private static function resolve_term_og_image($meta, $yoast)
    {
        $resolved = self::first($meta, array('_metasync_og_image'));

        # Not run through yoast_flat_value(): an image URL is not a template, and
        # a `%%` in one is part of the path rather than a token to expand.
        return $resolved !== '' ? $resolved : self::first($yoast, array('wpseo_opengraph-image'));
    }

    /**
     * Term twitter:title: override -> OTTO -> Yoast -> the OG title.
     *
     * @param array   $meta     Raw term meta.
     * @param array   $yoast    This term's Yoast entry.
     * @param WP_Term $term
     * @param string  $og_title Already-resolved OG title.
     * @return string
     */
    private static function resolve_term_twitter_title($meta, $yoast, $term, $og_title)
    {
        $resolved = self::first($meta, array(
            '_metasync_twitter_title',
            '_metasync_otto_twitter_title',
        ));

        if ($resolved === '') {
            $resolved = self::yoast_value($yoast, 'wpseo_twitter-title', $term);
        }

        return $resolved !== '' ? $resolved : $og_title;
    }

    /**
     * Term twitter:description: override -> OTTO -> Yoast -> the OG description.
     *
     * @param array   $meta           Raw term meta.
     * @param array   $yoast          This term's Yoast entry.
     * @param WP_Term $term
     * @param string  $og_description Already-resolved OG description.
     * @return string
     */
    private static function resolve_term_twitter_description($meta, $yoast, $term, $og_description)
    {
        $resolved = self::first($meta, array(
            '_metasync_twitter_description',
            '_metasync_otto_twitter_description',
        ));

        if ($resolved === '') {
            $resolved = self::yoast_value($yoast, 'wpseo_twitter-description', $term);
        }

        return $resolved !== '' ? $resolved : $og_description;
    }

    /**
     * Term twitter:image: override -> Yoast -> the OG image.
     *
     * @param array  $meta     Raw term meta.
     * @param array  $yoast    This term's Yoast entry.
     * @param string $og_image Already-resolved OG image.
     * @return string
     */
    private static function resolve_term_twitter_image($meta, $yoast, $og_image)
    {
        $resolved = self::first($meta, array('_metasync_twitter_image'));
        if ($resolved === '') {
            $resolved = self::first($yoast, array('wpseo_twitter-image'));
        }

        return $resolved !== '' ? $resolved : $og_image;
    }

    /* -----------------------------------------------------------------
     *  Fields for the types with no per-object storage
     * ----------------------------------------------------------------- */

    /**
     * og:type for a site section — a term archive, the homepage, the blog index.
     *
     * An explicit `_metasync_og_type` still wins, matching the post resolver, but
     * the derived value is not "article or website" the way it is for a single
     * post: none of these is an article.
     *
     * @param array  $meta    Raw meta for the object, or an empty array.
     * @param string $default Derived type when nothing is stored.
     * @return string
     */
    private static function resolve_section_og_type($meta, $default)
    {
        $override = self::first($meta, array('_metasync_og_type'));

        return $override !== '' ? $override : $default;
    }

    /**
     * The title for a post-type archive.
     *
     * The registered plural label, which is what WordPress itself puts in the
     * document title for an archive. Falls back through the singular label to the
     * post type name so this cannot come back empty for a type registered with
     * no labels at all.
     *
     * @param object $type      Post type object.
     * @param string $post_type Post type name.
     * @return string
     */
    private static function post_type_archive_title($type, $post_type)
    {
        if (isset($type->labels) && is_object($type->labels)) {
            foreach (array('name', 'singular_name') as $label) {
                if (isset($type->labels->$label) && is_string($type->labels->$label)) {
                    $value = trim($type->labels->$label);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        if (isset($type->label) && is_string($type->label) && trim($type->label) !== '') {
            return trim($type->label);
        }

        return $post_type;
    }

    /**
     * An author's display name.
     *
     * @param WP_User $user
     * @return string
     */
    private static function author_display_name($user)
    {
        $name = self::text($user->display_name);
        if ($name !== '') {
            return $name;
        }

        return self::text($user->user_nicename);
    }

    /**
     * A trimmed string for anything that can sensibly become one.
     *
     * Used on properties read straight off a WordPress object. Those are typed
     * as strings, but this class is handed objects by callers and by seams a
     * subclass may substitute, and a corrupted row can put an array where a
     * string belongs — casting that would emit a warning and yield the literal
     * "Array".
     *
     * @param mixed $value
     * @return string
     */
    private static function text($value)
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * Force post meta into the shape get_post_meta($id) documents.
     *
     * Real WordPress always returns `key => [value]`, and the robots resolver
     * this class delegates to reads element 0 directly. Coercing here means a
     * caller — the bulk resolver pre-fetching rows, say — can hand over a
     * flatter array without the delegation quietly resolving to nothing.
     *
     * @param mixed $meta
     * @return array
     */
    private static function normalize_meta($meta)
    {
        if (!is_array($meta)) {
            return array();
        }

        $normalized = array();

        foreach ($meta as $key => $value) {
            $normalized[$key] = is_array($value) ? array_values($value) : array($value);
        }

        return $normalized;
    }

    /**
     * SEO title: override -> OTTO -> imported -> Yoast -> the post title.
     *
     * `_metasync_metatitle` sits between the sidebar value and OTTO because the
     * importer and MCP write there; the same order Metasync_Plugin_Sync uses.
     *
     * @param array   $meta Raw post meta.
     * @param WP_Post $post
     * @return string
     */
    private static function resolve_title($meta, $post)
    {
        $resolved = self::first($meta, array(
            '_metasync_seo_title',
            '_metasync_metatitle',
            '_metasync_otto_title',
            '_metasync_imported_seo_title',
        ));

        if ($resolved === '') {
            $resolved = self::yoast_value($meta, '_yoast_wpseo_title', $post);
        }

        return $resolved !== '' ? $resolved : (string) $post->post_title;
    }

    /**
     * Meta description: override -> OTTO -> imported -> Yoast -> a trimmed excerpt.
     *
     * @param array   $meta Raw post meta.
     * @param WP_Post $post
     * @return string
     */
    private static function resolve_description($meta, $post)
    {
        $resolved = self::first($meta, array(
            '_metasync_seo_desc',
            '_metasync_metadesc',
            '_metasync_otto_description',
            '_metasync_imported_seo_desc',
        ));

        if ($resolved === '') {
            $resolved = self::yoast_value($meta, '_yoast_wpseo_metadesc', $post);
        }

        return $resolved !== '' ? $resolved : self::excerpt_from_post($post);
    }

    /**
     * Keywords: override -> OTTO -> Yoast focus keyword.
     *
     * OTTO persists its keyword to both `_metasync_focus_keyword` and
     * `_metasync_otto_keywords`, so the first is not strictly an override — but
     * it is the one a user edit lands in, so it is read first either way.
     *
     * @param array $meta Raw post meta.
     * @return string
     */
    private static function resolve_keywords($meta)
    {
        # Deliberately no Yoast rung. `_yoast_wpseo_focuskw` is an editor's
        # internal target keyword and is rendered nowhere on the public page, so
        # publishing it would break this feature's own rule that everything in
        # the payload is already visible to a visitor — on an endpoint with no
        # auth gate, one query would hand over every editor's keyword research.
        # The importer copies keywords an operator has actually accepted into
        # `_metasync_focus_keyword`, which is the explicit act that should make
        # them public.
        return self::first($meta, array(
            '_metasync_focus_keyword',
            '_metasync_otto_keywords',
        ));
    }

    /**
     * Canonical: override -> Yoast -> the public URL.
     *
     * OTTO does not suggest canonicals, so there is no OTTO step. The final
     * fallback is the frontend URL rather than get_permalink(): on a headless
     * site the permalink names the WordPress host, which is the whole defect
     * this feature exists to fix.
     *
     * An override is taken as written. If an operator has typed an absolute URL
     * it is a deliberate statement about where the canonical points, and
     * rewriting its host would silently overrule them.
     *
     * @param array  $meta       Raw post meta.
     * @param string $public_url Already-built frontend URL.
     * @param string $post_type  Post type, for the prefix rule when re-hosting.
     * @return string
     */
    private static function resolve_canonical($meta, $public_url, $post_type = '')
    {
        $override = self::first($meta, array('_metasync_canonical_url', 'meta_canonical'));
        if ($override !== '' && class_exists('Metasync_Canonical_Sanitizer')) {
            # Legacy rows corrupted to the literal "Array" must never be emitted.
            $override = (string) Metasync_Canonical_Sanitizer::sanitize($override);
        }

        if ($override !== '') {
            return $override;
        }

        # A Yoast canonical is not an operator statement about the headless
        # topology — it is third-party data, very often a stale absolute URL
        # naming the host WordPress served when it was still the public site.
        # Returned verbatim it would put the backend host back into canonical and
        # og:url, which is the exact defect this feature exists to remove. So
        # keep only its path and re-host it on the frontend, and sanitise it the
        # same way the operator override is sanitised — the rendered path escapes
        # canonicals, and this payload is rendered verbatim by the frontend.
        $yoast = self::first($meta, array('_yoast_wpseo_canonical'));
        if ($yoast !== '' && class_exists('Metasync_Canonical_Sanitizer')) {
            $yoast = (string) Metasync_Canonical_Sanitizer::sanitize($yoast);
        }

        if ($yoast !== '') {
            $rehosted = Metasync_Headless_Url_Builder::from_wp_url($yoast, $post_type);
            if ($rehosted !== '') {
                return $rehosted;
            }
        }

        return $public_url;
    }

    /**
     * Robots directives for a term.
     *
     * MetaSync's own term storage is resolved by the shared implementation, as
     * for a post. Yoast's term noindex is honoured as the rung beneath it,
     * because Yoast keeps that flag in its taxonomy option rather than in term
     * meta — so the shared resolver, which only ever sees term meta, cannot
     * see it. Without this a term marked noindex in Yoast was reported as
     * indexable, and this plugin's own term sync writes that very key.
     *
     * MetaSync's value wins where it exists, matching the order used
     * everywhere else. Serving directives are dropped alongside a noindex for
     * the same reason the shared resolver drops them: they describe how to
     * present a page that is not being indexed at all.
     *
     * @param array $meta  Raw term meta.
     * @param array $yoast Yoast's stored entry for this term.
     * @return string
     */
    private static function resolve_term_robots($meta, $yoast)
    {
        $resolved = self::resolve_robots($meta);

        if (stripos($resolved, 'noindex') !== false) {
            return $resolved;
        }

        $yoast_noindex = isset($yoast['wpseo_noindex']) ? (string) $yoast['wpseo_noindex'] : '';
        if (strtolower(trim($yoast_noindex)) !== 'noindex') {
            return $resolved;
        }

        # Yoast says noindex and MetaSync has said nothing. Emit noindex, and
        # keep only the directives that still mean something beside it.
        $directives = array('noindex');

        foreach (explode(',', $resolved) as $directive) {
            if (strtolower(trim($directive)) === 'nofollow') {
                $directives[] = 'nofollow';
            }
        }

        return implode(', ', array_unique($directives));
    }
    /**
     * Robots directives.
     *
     * Delegates to the existing resolver rather than restating it: four storage
     * formats, a rule that drops serving directives once noindex is present, and
     * a max-image-preview default. A second implementation of that would drift
     * from the one the rendered site uses.
     *
     * @param array $meta Raw post meta.
     * @return string
     */
    private static function resolve_robots($meta)
    {
        if (!class_exists('Metasync_Seo_Output')) {
            return '';
        }

        return (string) Metasync_Seo_Output::resolve_robots_value_raw($meta);
    }

    /**
     * og:title: a customised OG title -> OTTO -> imported -> Yoast -> the SEO title.
     *
     * The OG meta box pre-fills its title from the post title and persists that
     * default on save, so a non-empty `_metasync_og_title` is not on its own
     * evidence of intent. Treating it as one would let an auto-filled post title
     * quietly outrank a deliberately-set SEO title on every ordinary edit. The
     * value counts only when it differs from that default — the same comparison
     * Metasync_SEO_Conflict_Handler makes.
     *
     * @param array   $meta  Raw post meta.
     * @param WP_Post $post
     * @param string  $title Already-resolved SEO title.
     * @return string
     */
    private static function resolve_og_title($meta, $post, $title)
    {
        $og_title = self::first($meta, array('_metasync_og_title'));
        if ($og_title !== '' && $og_title !== (string) $post->post_title) {
            return $og_title;
        }

        $resolved = self::first($meta, array(
            '_metasync_otto_og_title',
            '_metasync_imported_og_title',
        ));

        if ($resolved === '') {
            $resolved = self::yoast_value($meta, '_yoast_wpseo_opengraph-title', $post);
        }

        return $resolved !== '' ? $resolved : $title;
    }

    /**
     * og:description: override -> OTTO -> imported -> Yoast -> the meta description.
     *
     * @param array  $meta        Raw post meta.
     * @param string $description Already-resolved meta description.
     * @return string
     */
    private static function resolve_og_description($meta, $description, $post = null)
    {
        $resolved = self::first($meta, array(
            '_metasync_og_description',
            '_metasync_otto_og_description',
            '_metasync_imported_og_desc',
        ));

        if ($resolved === '') {
            $resolved = self::yoast_value($meta, '_yoast_wpseo_opengraph-description', $post);
        }

        return $resolved !== '' ? $resolved : $description;
    }

    /**
     * og:image: override -> imported -> Yoast -> the featured image.
     *
     * OTTO does not suggest a social image.
     *
     * @param array   $meta Raw post meta.
     * @param WP_Post $post
     * @return string
     */
    private static function resolve_og_image($meta, $post)
    {
        $resolved = self::first($meta, array(
            '_metasync_og_image',
            '_metasync_imported_og_image',
            '_yoast_wpseo_opengraph-image',
        ));
        if ($resolved !== '') {
            return $resolved;
        }

        return (string) static::read_featured_image_url($post);
    }

    /**
     * og:type.
     *
     * @param array   $meta Raw post meta.
     * @param WP_Post $post
     * @return string
     */
    private static function resolve_og_type($meta, $post)
    {
        $override = self::first($meta, array('_metasync_og_type'));
        if ($override !== '') {
            return $override;
        }

        return $post->post_type === 'page' ? 'website' : 'article';
    }

    /**
     * twitter:card.
     *
     * @param array $meta Raw post meta.
     * @return string
     */
    private static function resolve_twitter_card($meta)
    {
        $override = self::first($meta, array('_metasync_twitter_card'));
        if ($override !== '') {
            return $override;
        }

        $configured = Metasync::get_option('twitter_card_type');

        return is_string($configured) && $configured !== '' ? $configured : 'summary_large_image';
    }

    /**
     * twitter:title: override -> OTTO -> imported -> Yoast -> the OG title.
     *
     * @param array  $meta     Raw post meta.
     * @param string $og_title Already-resolved OG title.
     * @return string
     */
    private static function resolve_twitter_title($meta, $og_title, $post = null)
    {
        $resolved = self::first($meta, array(
            '_metasync_twitter_title',
            '_metasync_otto_twitter_title',
            '_metasync_imported_twitter_title',
        ));

        if ($resolved === '') {
            $resolved = self::yoast_value($meta, '_yoast_wpseo_twitter-title', $post);
        }

        return $resolved !== '' ? $resolved : $og_title;
    }

    /**
     * twitter:description: override -> OTTO -> imported -> Yoast -> the OG description.
     *
     * @param array  $meta           Raw post meta.
     * @param string $og_description Already-resolved OG description.
     * @return string
     */
    private static function resolve_twitter_description($meta, $og_description, $post = null)
    {
        $resolved = self::first($meta, array(
            '_metasync_twitter_description',
            '_metasync_otto_twitter_description',
            '_metasync_imported_twitter_desc',
        ));

        if ($resolved === '') {
            $resolved = self::yoast_value($meta, '_yoast_wpseo_twitter-description', $post);
        }

        return $resolved !== '' ? $resolved : $og_description;
    }

    /**
     * twitter:image: override -> imported -> Yoast -> the OG image.
     *
     * @param array  $meta     Raw post meta.
     * @param string $og_image Already-resolved OG image.
     * @return string
     */
    private static function resolve_twitter_image($meta, $og_image)
    {
        $resolved = self::first($meta, array(
            '_metasync_twitter_image',
            '_metasync_imported_twitter_image',
            '_yoast_wpseo_twitter-image',
        ));

        return $resolved !== '' ? $resolved : $og_image;
    }

    /**
     * The OTTO JSON-LD graph, decoded.
     *
     * Returned decoded rather than as a string so a delivery layer hands a
     * consumer structured data instead of a string it has to parse. A row that
     * does not decode yields an empty array: shipping a malformed graph is worse
     * than shipping none, because a validator reports it as broken markup rather
     * than absent markup.
     *
     * The value is always wrapped in a list, so a consumer iterates one shape
     * whether the stored graph was a single node or several.
     *
     * @param array $meta Raw post meta.
     * @return array
     */
    private static function resolve_schema($meta)
    {
        $raw = self::first($meta, array('_metasync_otto_structured_data'));
        if ($raw === '') {
            return array();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === array()) {
            return array();
        }

        # A graph stored as a single node arrives as an associative array; one
        # stored as several arrives as a list. Normalise to a list.
        return isset($decoded[0]) ? array_values($decoded) : array($decoded);
    }

    /* -----------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------- */

    /**
     * A Yoast-sourced value, with its template variables dealt with.
     *
     * Yoast stores templates, not finished strings: `_yoast_wpseo_title` is
     * routinely `%%title%% %%sep%% %%sitename%%`, and Yoast expands those at
     * render time through wpseo_replace_vars(). Every other consumer in this
     * plugin lets Yoast render itself, so this is the first place raw Yoast meta
     * would become output — shipped verbatim, a frontend would put the literal
     * `%%title%%` in the document title.
     *
     * Expanded when Yoast is present to do it. When it is not, a value still
     * carrying tokens is treated as absent so resolution falls through to the
     * next rung, which yields the plain post title rather than a broken one.
     *
     * `$values` is whichever store the caller read, because Yoast keeps its data
     * in three different places: post meta for a post (`key => [value]`), the
     * `wpseo_taxonomy_meta` option entry for a term (`key => value`), and user
     * meta for an author. first() normalises both shapes, so the chain does not
     * have to know which one it is reading.
     *
     * @param array  $values  Values from whichever Yoast store applies.
     * @param string $key     Yoast key.
     * @param mixed  $context The object Yoast should resolve tokens from.
     * @return string
     */
    private static function yoast_value($values, $key, $context = null)
    {
        $value = self::first($values, array($key));
        if ($value === '') {
            return '';
        }

        if (strpos($value, '%%') === false) {
            return $value;
        }

        # The args are built here, not behind the seam. This choice IS the bug
        # that shipped, so it has to sit in code a test actually executes — a
        # seam that both decides the args and makes the call would hide it again.
        #
        # Any object is passed through, not only a WP_Post. Yoast casts what it
        # is given to an array and merges it over its own defaults, so a WP_Term
        # supplies the `name`, `taxonomy` and `term_id` slots that %%term_title%%
        # and %%term_description%% resolve from — and a `$post instanceof
        # WP_Post` test would coerce that term to an empty array and reproduce
        # the exact same stripped-token failure for every term archive.
        $args = is_object($context) ? $context : array();

        $expanded = static::expand_yoast_template($value, $args);

        if (is_string($expanded) && strpos($expanded, '%%') === false) {
            $expanded = trim($expanded);

            # Because unresolved tokens are stripped rather than flagged, an
            # expansion can collapse to nothing but leftover punctuation — a
            # separator with both sides missing. That is not a title, so fall
            # through to the next rung instead of publishing it.
            if (self::has_renderable_text($expanded)) {
                return $expanded;
            }
        }

        # Still templated, or nothing renderable survived: better to fall through
        # than to render a token or a stray separator.
        return '';
    }

    /**
     * Expand Yoast's template tokens, when Yoast is present to do it.
     *
     * The post must be handed over. Yoast resolves post-bound tokens
     * (%%title%%, %%excerpt%%, %%category%%, %%parent_title%%) out of the $args
     * it is given, and from an empty array it resolves none of them — then
     * STRIPS them rather than leaving the %% markers behind. So
     * `%%title%% %%sep%% %%sitename%%` came back as ' - sitename', with no %%
     * left for any guard to notice, and that fragment was published as the
     * document title. Passing the post is what makes this match what Yoast
     * itself renders on a normal page.
     *
     * Separated from yoast_value() so both outcomes are testable: PHP cannot
     * un-define a function, so a test that declared a wpseo_replace_vars() stub
     * would make Yoast permanently "present" for every other test in the run.
     *
     * Deliberately thin: it decides only whether an expander exists and passes
     * the arguments straight through. Everything worth getting wrong — above all
     * what gets handed to Yoast — lives in yoast_value() where tests reach it.
     *
     * @param string         $value Template string containing %% tokens.
     * @param WP_Post|array  $args  What Yoast should resolve tokens from.
     * @return string|null Expanded string, or null when there is no expander.
     */
    protected static function expand_yoast_template($value, $args)
    {
        if (!function_exists('wpseo_replace_vars')) {
            return null;
        }

        return wpseo_replace_vars($value, $args);
    }

    /**
     * Does this string contain anything a reader would recognise as text?
     *
     * At least one letter or digit. Used to reject an expansion that reduced to
     * punctuation, which is what a template leaves behind when its tokens do not
     * resolve.
     *
     * @param string $value
     * @return bool
     */
    private static function has_renderable_text($value)
    {
        return preg_match('/[\p{L}\p{N}]/u', (string) $value) === 1;
    }

    /**
     * The first non-empty value among a list of meta keys.
     *
     * get_post_meta($id) returns every key as a list of values, so each lookup
     * has to reach into element 0. Centralising that also centralises the
     * definition of "empty", which is what the whole fallback order rests on:
     * OTTO never emits a blank suggestion, so treating '' as absent is safe and
     * makes a rollback — clearing the OTTO value — fall through to Yoast, which
     * is the wanted behaviour.
     *
     * @param array    $meta Raw post meta, as returned by get_post_meta($id).
     * @param string[] $keys Keys to try, in order.
     * @return string The first non-empty value, or ''.
     */
    private static function first($meta, array $keys)
    {
        foreach ($keys as $key) {
            if (!isset($meta[$key])) {
                continue;
            }

            $value = $meta[$key];
            if (is_array($value)) {
                $value = isset($value[0]) ? $value[0] : '';
            }

            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * A description built from the post itself.
     *
     * Prefers a hand-written excerpt. Falling back to the content mirrors what
     * the rendered site does, including stripping shortcodes before tags rather
     * than executing them — running shortcodes here would fire third-party code
     * during what should be a read.
     *
     * @param WP_Post $post
     * @return string
     */
    private static function excerpt_from_post($post)
    {
        $excerpt = trim((string) $post->post_excerpt);
        if ($excerpt !== '') {
            return $excerpt;
        }

        return self::plain_text_summary($post->post_content);
    }

    /**
     * Reduce stored rich text to a short plain-text description.
     *
     * Shared by the post content fallback, the term description fallback, the
     * post-type archive description and the author biography, so all four get the
     * same treatment: shortcodes stripped rather than executed — running one here
     * would fire third-party code during what is supposed to be a read — then
     * tags removed, whitespace collapsed, and the result cut to a length that
     * fits a meta description.
     *
     * @param mixed $text Stored rich text.
     * @return string
     */
    private static function plain_text_summary($text)
    {
        if (!is_string($text) && !is_numeric($text)) {
            return '';
        }

        $text = (string) $text;
        if (trim($text) === '') {
            return '';
        }

        $text = strip_shortcodes($text);
        $text = wp_strip_all_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);

        return wp_trim_words(trim((string) $text), 30, '');
    }
}
