<?php
/**
 * WPGraphQL delivery for the headless SEO payload.
 *
 * Registers one read-only field on every node type WPGraphQL exposes that this
 * plugin can describe, returning the object Metasync_Headless_Seo_Surface
 * resolved. No resolution logic lives here — this file is transport.
 *
 * **Where each entry point is attached, and why.**
 *
 * The surface has six entry points and WPGraphQL has a node for four of them.
 * Wherever a node exists the field goes on the node, because a node is an
 * address the frontend already has: it fetches a page with `nodeByUri(uri: …)`
 * and reads the SEO off whatever came back, in the same request, without
 * knowing which WordPress object type is behind the route. Attaching to the
 * node also means WPGraphQL has already decided the caller may see that object
 * before this field is ever reached.
 *
 *  - a post type's `graphql_single_name`  -> for_post()
 *  - a taxonomy's `graphql_single_name`   -> for_term()
 *  - `User`                               -> for_author()
 *  - `ContentType`                        -> for_post_type_archive()
 *
 * The front page and the blog index are the two that genuinely have no node of
 * their own — with `show_on_front = posts` there is no object at all — so they
 * get root fields on `RootQuery`:
 *
 *  - `metasyncSeoForHomePage`  -> for_home_page()
 *  - `metasyncSeoForPostsPage` -> for_posts_page()
 *
 * Both take **no arguments**, which is the point rather than a convenience.
 * There is no id, name or slug a caller can hand a root field here, so no root
 * field can be pointed at an object the node path would have refused; every
 * caller-supplied identifier still arrives as a node WPGraphQL access-checked
 * first. See register_root_fields() for the alternatives that were rejected.
 *
 * **The field must never be able to break a page.** On a headless site the
 * frontend fetches content and SEO in a single GraphQL request, so a resolver
 * that throws does not degrade the SEO — it fails the whole content query and
 * the page renders as an error. Worse, a frontend using persisted (locked) query
 * documents cannot be quietly patched afterwards; every stored operation would
 * have to be rebuilt and redeployed. So:
 *
 *  - the field and every child field are nullable;
 *  - the resolver catches Throwable and returns null;
 *  - registration itself is wrapped, so a malformed schema call cannot take the
 *    whole schema down with it.
 *
 * Failing closed to null is the deliberate choice over an error a caller could
 * detect. A frontend that gets null renders the page with whatever SEO it can
 * derive itself; a frontend that gets an error renders nothing.
 *
 * The field is named `metasyncSeo` rather than `seo`. A headless install of the
 * kind this targets is very likely already serving a `seo` field from another
 * plugin, and colliding with it would break the site rather than extend it. The
 * root fields carry the same prefix for the same reason.
 *
 * Only render-facing values are exposed. No deployment revision, no internal
 * ids, no configuration — a headless GraphQL endpoint frequently has no auth
 * gate in front of it, so everything here should be safe to read anonymously.
 * Everything on the type is already public on the rendered page.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

if (!defined('ABSPATH')) {
    exit; # Exit if accessed directly
}

require_once dirname(__DIR__) . '/headless-refresh-history/class-metasync-headless-refresh-history-database.php';

class Metasync_Headless_Graphql
{
    /**
     * GraphQL object type name for the payload.
     */
    const TYPE_NAME = 'MetaSyncSeo';

    /**
     * Field name added to each node type.
     */
    const FIELD_NAME = 'metasyncSeo';

    /**
     * WPGraphQL's root query type, where the two archive-shaped entry points
     * that have no node of their own are attached.
     */
    const ROOT_TYPE_NAME = 'RootQuery';

    /**
     * WPGraphQL's author node. Fixed rather than derived: unlike post types and
     * taxonomies, users are not a registry with a configurable GraphQL name.
     */
    const USER_TYPE_NAME = 'User';

    /**
     * WPGraphQL's post-type node — the node `nodeByUri` returns for a post type
     * archive, and the one it returns for `/` on a site that shows posts there.
     */
    const CONTENT_TYPE_NAME = 'ContentType';

    /**
     * Root field for the site front page.
     */
    const HOME_FIELD_NAME = 'metasyncSeoForHomePage';

    /**
     * Root field for the blog index, when a static page holds it.
     */
    const POSTS_PAGE_FIELD_NAME = 'metasyncSeoForPostsPage';

    /**
     * Most objects one GraphQL request may refresh from OTTO synchronously.
     *
     * A request asking for more than this many objects makes no live call at
     * all — not "the first five and then stop". Which is why the count cannot
     * be taken as resolvers fire: by the time the sixth object arrived, five
     * calls would already have gone out. See settle() for how the whole
     * request is counted before any of them runs.
     */
    const MAX_SYNC_REFRESH_OBJECTS = 5;

    /**
     * How many consecutive quiet turns prove a request has stopped growing.
     *
     * One is not enough. A lazy loader can be sitting in the deferred queue
     * behind us, so a single turn where nothing new arrived may simply be the
     * turn before that loader runs and introduces a whole new level of
     * objects. Two consecutive quiet turns mean we have been all the way round
     * the queue without the count moving.
     */
    const SETTLE_QUIET_TURNS = 2;

    /**
     * Every object this request asked to publish, keyed so that the same
     * object named twice — through a node and a connection, or through an
     * alias — counts once.
     *
     * @var array<string,bool>
     */
    private static $requested = array();

    /**
     * The request's verdict: null until resolution settles, then whether the
     * request is small enough to refresh at all.
     *
     * Decided once, by whichever waiter first sees a settled request, and read
     * by every other waiter. Deciding per waiter would let the answer depend
     * on the order the client happened to write its selection set.
     *
     * @var bool|null
     */
    private static $verdict = null;

    /**
     * Live refreshes this request has started, across every pass.
     *
     * @var int
     */
    private static $refresh_calls = 0;

    /**
     * Objects already attempted this request, so a second selection of the
     * same object neither queues nor calls again.
     *
     * @var array<string,bool>
     */
    private static $attempted = array();

    /**
     * What a refreshed object published, keyed the same way.
     *
     * Without this, a query naming one object twice would publish the
     * refreshed values for the first selection and the values captured before
     * the refresh for the second — two answers for one object in one response.
     * Only refreshed objects are recorded; everything else already publishes
     * what the resolver read.
     *
     * @var array<string,array>
     */
    private static $published = array();

    /**
     * Monotonic start times for per-object history durations.
     *
     * @var array<string,float>
     */
    private static $history_started = array();

    /**
     * Objects already written to refresh history in this GraphQL request.
     *
     * @var array<string,bool>
     */
    private static $history_recorded = array();

    /**
     * Number of deferred settling callbacks waiting in the executor queue.
     *
     * @var int
     */
    private static $pending_rechecks = 0;

    /** @var int|null Current WPGraphQL request context identity. */
    private static $request_context_id = null;

    /** @var array<int,array> Saved outer contexts for nested GraphQL calls. */
    private static $request_context_stack = array();

    /**
     * Hook registration into WPGraphQL's schema build.
     *
     * Safe to call unconditionally: `graphql_register_types` only ever fires
     * when WPGraphQL is active, so on a site without it this adds a callback
     * that is never invoked and changes nothing.
     *
     * @return void
     */
    public static function init()
    {
        # Gated here as well as inside register(), so a non-headless site does not
        # even carry the hook. The ticket's rule is that with the mode off
        # execution follows exactly the path it does today, and an extra callback
        # on a WPGraphQL schema build is an observable difference even when it
        # returns immediately. The option read this costs is memoised and the
        # plugin already reads that option several times per request.
        if (!Metasync_Headless_Config::is_enabled()) {
            return;
        }

        add_action('graphql_register_types', array(__CLASS__, 'register'));
        add_action('graphql_before_execute', array(__CLASS__, 'begin_request_context'), 10, 1);
        # reset_request_state() takes no arguments; graphql_execute passes six,
        # so accept none and let WordPress hand the callback an empty arg list.
        add_action('graphql_execute', array(__CLASS__, 'reset_request_state'), 10, 0);
        add_action('graphql_after_execute', array(__CLASS__, 'end_request_context'), 10, 2);
    }

    /**
     * Forget everything the previous GraphQL request recorded about refreshes.
     *
     * @return void
     */
    public static function reset_request_state()
    {
        self::$requested     = array();
        self::$verdict       = null;
        self::$refresh_calls = 0;
        self::$attempted     = array();
        self::$published     = array();
        self::$history_started = array();
        self::$history_recorded = array();
        self::$pending_rechecks = 0;
    }

    public static function begin_request_context($request)
    {
        self::$request_context_stack[] = array(
            'requested' => self::$requested,
            'verdict' => self::$verdict,
            'refresh_calls' => self::$refresh_calls,
            'attempted' => self::$attempted,
            'published' => self::$published,
            'history_started' => self::$history_started,
            'history_recorded' => self::$history_recorded,
            'pending_rechecks' => self::$pending_rechecks,
        );
        self::reset_request_state();
        self::$request_context_id = is_object($request) ? spl_object_id($request) : null;
    }

    public static function end_request_context($response, $request)
    {
        $context = array_pop(self::$request_context_stack);
        if ($context === null) {
            self::reset_request_state();
            return;
        }

        self::$requested = $context['requested'];
        self::$verdict = $context['verdict'];
        self::$refresh_calls = $context['refresh_calls'];
        self::$attempted = $context['attempted'];
        self::$published = $context['published'];
        self::$history_started = $context['history_started'];
        self::$history_recorded = $context['history_recorded'];
        self::$pending_rechecks = $context['pending_rechecks'];
        self::$request_context_id = null;
    }

    /**
     * Register the type and its fields.
     *
     * The headless check happens here rather than at hook time so switching the
     * mode takes effect on the next request instead of needing a reload. With
     * the mode off nothing is registered and the schema is byte-identical to a
     * site without this plugin.
     *
     * @return void
     */
    public static function register()
    {
        if (!Metasync_Headless_Config::is_enabled()) {
            return;
        }

        if (!function_exists('register_graphql_object_type') || !function_exists('register_graphql_field')) {
            return;
        }

        try {
            self::register_type();
            static::register_fields();
            add_filter('graphql_connection_nodes', array(__CLASS__, 'prime_post_connection_nodes'), 10, 2);
        } catch (Throwable $e) {
            # A failed registration must not take down the whole schema — that
            # would break every query on the site, not just this field.
            #
            # Logged as an error rather than a warning because the consequence is
            # worse than a failed resolve: the field is absent from the schema, so
            # a persisted query asking for it fails validation with an unknown
            # field and takes the whole request with it. A resolve failure only
            # yields a null. This one needs to be noticed.
            self::log_failure('registration', $e, true);
        }
    }

    /**
     * The payload type.
     *
     * Every field is nullable, and the descriptions are written for the frontend
     * developer reading them in a schema explorer rather than for us.
     *
     * @return void
     */
    private static function register_type()
    {
        register_graphql_object_type(self::TYPE_NAME, array(
            'description' => 'SEO values resolved by Search Atlas for this object, ready to render.',
            'fields'      => array(
                'title'              => array(
                    'type'        => 'String',
                    'description' => 'Document title.',
                ),
                'description'        => array(
                    'type'        => 'String',
                    'description' => 'Meta description.',
                ),
                'keywords'           => array(
                    'type'        => 'String',
                    'description' => 'Focus keyword or keywords.',
                ),
                'canonical'          => array(
                    'type'        => 'String',
                    'description' => 'Canonical URL, on the public frontend domain.',
                ),
                'robots'             => array(
                    'type'        => 'String',
                    'description' => 'Robots directives, ready for a meta robots tag.',
                ),
                'ogTitle'            => array(
                    'type'        => 'String',
                    'description' => 'og:title.',
                ),
                'ogDescription'      => array(
                    'type'        => 'String',
                    'description' => 'og:description.',
                ),
                'ogImage'            => array(
                    'type'        => 'String',
                    'description' => 'og:image URL.',
                ),
                'ogUrl'              => array(
                    'type'        => 'String',
                    'description' => 'og:url. Always the same value as canonical.',
                ),
                'ogType'             => array(
                    'type'        => 'String',
                    'description' => 'og:type.',
                ),
                'ogSiteName'         => array(
                    'type'        => 'String',
                    'description' => 'og:site_name.',
                ),
                'ogLocale'           => array(
                    'type'        => 'String',
                    'description' => 'og:locale.',
                ),
                'twitterCard'        => array(
                    'type'        => 'String',
                    'description' => 'twitter:card type.',
                ),
                'twitterTitle'       => array(
                    'type'        => 'String',
                    'description' => 'twitter:title.',
                ),
                'twitterDescription' => array(
                    'type'        => 'String',
                    'description' => 'twitter:description.',
                ),
                'twitterImage'       => array(
                    'type'        => 'String',
                    'description' => 'twitter:image URL.',
                ),
                'schema'             => array(
                    'type'        => 'String',
                    'description' => 'JSON-LD for this object, serialised. Render verbatim inside a application/ld+json script tag; do not re-serialise it.',
                ),
                'publicUrl'          => array(
                    'type'        => 'String',
                    'description' => 'URL this object is served from on the public frontend.',
                ),
            ),
        ));
    }

    /**
     * Add the field everywhere it belongs.
     *
     * The node types are collected into one map before anything is registered,
     * because a GraphQL type name may only be offered the field once: asking
     * WPGraphQL to add the same field to the same type twice raises. Two post
     * types, or two taxonomies, or a post type and a taxonomy can all share a
     * `graphql_single_name` — an install like that has other problems, but this
     * must not be one of them.
     *
     * The map is built with `+=` so the first claim on a name wins and the
     * registries are consulted in a fixed order. That makes this the single place
     * de-duplication happens; a second guard further down would look like
     * belt-and-braces but would mean neither could be tested, since either one
     * alone would satisfy the assertion.
     *
     * @return void
     */
    protected static function register_fields()
    {
        $node_fields = array();

        foreach (self::graphql_post_types() as $graphql_single_name) {
            $node_fields += array($graphql_single_name => array(
                'resolve'     => array(__CLASS__, 'resolve'),
                'description' => 'SEO values resolved by Search Atlas for this object.',
            ));
        }

        # Terms are the type this feature exists for, and the registry is read
        # the same way the post types are — show_in_graphql and
        # graphql_single_name — so a custom taxonomy is covered without a code
        # change here. On a headless build the custom taxonomies are usually the
        # ones carrying the site's navigation.
        foreach (self::graphql_taxonomies() as $graphql_single_name) {
            $node_fields += array($graphql_single_name => array(
                'resolve'     => array(__CLASS__, 'resolve_term'),
                'description' => 'SEO values resolved by Search Atlas for this term archive.',
            ));
        }

        # `User` is the node nodeByUri() returns for an author archive, so the
        # author payload goes there rather than on a root field taking an id.
        $node_fields += array(self::USER_TYPE_NAME => array(
            'resolve'     => array(__CLASS__, 'resolve_author'),
            'description' => 'SEO values resolved by Search Atlas for this author archive.',
        ));

        # `ContentType` is the node nodeByUri() returns for a post type archive.
        $node_fields += array(self::CONTENT_TYPE_NAME => array(
            'resolve'     => array(__CLASS__, 'resolve_content_type'),
            'description' => 'SEO values resolved by Search Atlas for the archive this post type is served at.',
        ));

        foreach ($node_fields as $type_name => $spec) {
            if ($type_name === '') {
                continue;
            }

            self::register_field($type_name, self::FIELD_NAME, $spec['resolve'], $spec['description']);
        }

        self::register_root_fields();
    }

    /**
     * The two entry points with no node, as root fields.
     *
     * Neither takes an argument, and that is the whole design. The rejected
     * alternatives all did:
     *
     *  - `metasyncSeoForAuthor(id: ID!)` and `metasyncSeoForArchive(postType:
     *    String!)`. Both describe objects WPGraphQL already exposes as nodes it
     *    access-checks, so a root field taking an identifier would add a second
     *    way in that this plugin would have to keep as strict as WPGraphQL's own
     *    — for ever, on both sides. The resolvers do refuse non-public objects,
     *    so the two happen to agree today; agreeing by coincidence is not a
     *    property worth depending on when the endpoint may have no auth gate.
     *  - one root field with a discriminator, say `metasyncSeo(type: HOME |
     *    ARCHIVE | AUTHOR, id: ID)`. GraphQL cannot express "this argument is
     *    required only for that value of that other argument", so validation
     *    would move into the resolver — where the only legal outcome is null,
     *    because this field may never raise. A caller who passed the wrong
     *    combination would get a silent null indistinguishable from "no data".
     *  - a URI-resolving field of our own. The frontend does fetch by URI, but
     *    `nodeByUri` already does that routing and it is a large, version-
     *    sensitive re-implementation of WordPress's rewrite rules. Putting the
     *    field on the nodes it returns reaches the same place in the same
     *    request without owning any of it.
     *
     * @return void
     */
    private static function register_root_fields()
    {
        self::register_field(
            self::ROOT_TYPE_NAME,
            self::HOME_FIELD_NAME,
            array(__CLASS__, 'resolve_home_page'),
            'SEO values resolved by Search Atlas for the site front page. Takes no arguments: there is exactly one front page, and on a site that shows posts there it is not backed by any node.'
        );

        self::register_field(
            self::ROOT_TYPE_NAME,
            self::POSTS_PAGE_FIELD_NAME,
            array(__CLASS__, 'resolve_posts_page'),
            'SEO values resolved by Search Atlas for the blog index when a static page holds it. Null when the blog index is the front page; metasyncSeoForHomePage describes it in that case.'
        );
    }

    /**
     * Register one field, containing any failure to that field alone.
     *
     * register_graphql_field() raises when the type does not exist or the field
     * name is already taken, and there are now several calls where there was
     * one. Left to register()'s single catch, the first bad name would abort the
     * loop and cost every field after it — and an *absent* field is the
     * expensive failure, not a failed resolve: a persisted query naming a field
     * the schema does not have fails validation and takes the whole request with
     * it, where a failed resolve only yields a null. So each registration is
     * isolated here, and register()'s catch stays as the backstop for everything
     * outside these calls.
     *
     * @param string   $type_name
     * @param string   $field_name
     * @param callable $resolver
     * @param string   $description
     * @return void
     */
    private static function register_field($type_name, $field_name, $resolver, $description)
    {
        try {
            register_graphql_field($type_name, $field_name, array(
                'type'        => self::TYPE_NAME,
                'description' => $description,
                'resolve'     => $resolver,
            ));
        } catch (Throwable $e) {
            self::log_failure('registration of ' . $field_name . ' on ' . $type_name, $e, true);
        }
    }

    /**
     * The post type objects WPGraphQL exposes.
     *
     * The seam is this raw read rather than the derived name list, so the
     * validation and de-duplication below stay real logic that tests actually
     * execute. Overriding the derived list instead would leave both untested.
     *
     * @return array
     */
    protected static function read_post_type_objects()
    {
        return get_post_types(array('show_in_graphql' => true), 'objects');
    }

    /**
     * The taxonomy objects WPGraphQL exposes.
     *
     * A seam for the same reason as the post types: the filtering below is real
     * logic, and a test that replaced the derived names would prove nothing
     * about it.
     *
     * @return array
     */
    protected static function read_taxonomy_objects()
    {
        return get_taxonomies(array('show_in_graphql' => true), 'objects');
    }

    /**
     * GraphQL type names of every post type exposed to GraphQL.
     *
     * @return string[]
     */
    private static function graphql_post_types()
    {
        return self::graphql_type_names(self::read_registry('read_post_type_objects'));
    }

    /**
     * GraphQL type names of every taxonomy exposed to GraphQL.
     *
     * @return string[]
     */
    private static function graphql_taxonomies()
    {
        return self::graphql_type_names(self::read_registry('read_taxonomy_objects'));
    }

    /**
     * Read one registry seam, treating a failure as an empty registry.
     *
     * Same reasoning as register_field(): a pass that cannot even read its
     * registry must cost only its own fields. Left to register()'s single catch,
     * a broken get_taxonomies() would abort before the root fields were reached
     * — and those depend on no registry at all, so there is no reason for them
     * to share its fate.
     *
     * @param string $seam Name of the protected read method to call.
     * @return mixed Whatever the seam returned, or an empty array on failure.
     */
    private static function read_registry($seam)
    {
        try {
            # Called through static::class so a subclass substituting the seam is
            # honoured, exactly as a direct static:: call would be.
            return call_user_func(array(static::class, $seam));
        } catch (Throwable $e) {
            self::log_failure('registry read via ' . $seam, $e, true);

            return array();
        }
    }

    /**
     * Pull usable `graphql_single_name` values out of a registry read.
     *
     * Names are returned as they were found, duplicates and all. De-duplication
     * belongs to register_fields(), which is the only place that can see across
     * both registries and the fixed names; doing it here as well would mean
     * neither guard could be tested, because either alone would satisfy the
     * assertion.
     *
     * Shared by both registries because the property, the validation and the
     * failure mode are identical; the two registries differ in which function
     * reads them, which is what the seams above are for.
     *
     * @param mixed $objects Registry objects, as WordPress returns them.
     * @return string[]
     */
    private static function graphql_type_names($objects)
    {
        if (!is_array($objects) && !($objects instanceof Traversable)) {
            return array();
        }

        $names = array();

        foreach ($objects as $object) {
            if (!is_object($object) || empty($object->graphql_single_name)) {
                continue;
            }

            if (!is_string($object->graphql_single_name)) {
                continue;
            }

            $names[] = $object->graphql_single_name;
        }

        return $names;
    }

    /**
     * Resolve the field on a post node.
     *
     * Returns null rather than raising for anything unexpected. A GraphQL error
     * here would fail the entire query the frontend used to fetch its content,
     * not just this field.
     *
     * @param mixed $source Node model WPGraphQL is resolving.
     * @return array|null
     */
    public static function resolve($source)
    {
        try {
            $post_id = self::database_id_from_source($source);
            if ($post_id <= 0) {
                return null;
            }

            $payload = static::payload_for_post($post_id);

            return self::answer('post', $post_id, null, $payload, static function () use ($post_id) {
                return static::payload_for_post($post_id);
            });
        } catch (Throwable $e) {
            self::log_failure('resolve', $e);

            return null;
        }
    }

    /**
     * Resolve the field on a term node.
     *
     * @param mixed $source Node model WPGraphQL is resolving.
     * @return array|null
     */
    public static function resolve_term($source)
    {
        try {
            # `term_id` is accepted alongside the model's databaseId because a
            # filtered install can hand a resolver a plain WP_Term, and the
            # taxonomy does not have to be passed: for_term() looks the term up
            # by id and reads the taxonomy off what it finds.
            $term_id = self::database_id_from_source($source, array('databaseId', 'term_id', 'ID', 'id'));
            if ($term_id <= 0) {
                return null;
            }

            $payload = static::payload_for_term($term_id);

            # The taxonomy is read off the payload rather than off the node: the
            # surface already looked the term up to build this, and it is the
            # only place the taxonomy is known for certain.
            $taxonomy = $payload instanceof Metasync_Headless_Seo_Data
                ? (string) $payload->get('object_sub_type')
                : '';

            return self::answer('term', $term_id, $taxonomy, $payload, static function () use ($term_id) {
                return static::payload_for_term($term_id);
            });
        } catch (Throwable $e) {
            self::log_failure('resolve_term', $e);

            return null;
        }
    }

    /**
     * Resolve the field on a user node.
     *
     * @param mixed $source Node model WPGraphQL is resolving.
     * @return array|null
     */
    public static function resolve_author($source)
    {
        try {
            # `userId` is WPGraphQL's older name for the same value and is still
            # present on the model, deprecated; reading both means an install
            # pinned to an older release resolves rather than silently nulling.
            $user_id = self::database_id_from_source($source, array('databaseId', 'userId', 'ID', 'id'));
            if ($user_id <= 0) {
                return null;
            }

            return self::shape_payload(static::payload_for_author($user_id));
        } catch (Throwable $e) {
            self::log_failure('resolve_author', $e);

            return null;
        }
    }

    /**
     * Resolve the field on a post-type node.
     *
     * @param mixed $source Node model WPGraphQL is resolving.
     * @return array|null
     */
    public static function resolve_content_type($source)
    {
        try {
            $post_type = self::post_type_from_source($source);
            if ($post_type === '') {
                return null;
            }

            # `post` has no archive route of its own. WordPress serves its
            # listing as the blog index, and get_post_type_archive_link('post')
            # returns that address rather than an archive of its own — which is
            # exactly why for_post_type_archive() refuses `post`.
            #
            # Routing it here is what stops the homepage being unreachable by
            # the path the frontend actually uses: on a site showing posts on
            # the front, `nodeByUri(uri: "/")` returns this very node, and
            # leaving it null would mean the customer's URI-driven query gets no
            # SEO for the site's most important page.
            #
            # Asked in this order, and neither call needs a config read here:
            # for_posts_page() answers only when a *distinct* static page holds
            # the index, and otherwise the index is the front page, which
            # for_home_page() owns. Each entry point already makes that decision
            # for itself, so this stays routing rather than a second copy of it.
            if ($post_type === 'post') {
                # One call, because the choice between the posts page and the
                # front page needs the reason behind a null and this layer
                # cannot see it. Asking for_posts_page() first and falling back
                # on null conflated "the front page is the blog index" with
                # "the assigned posts page is a draft", and answered the second
                # with the front page's own title and canonical.
                return self::shape_payload(static::payload_for_blog_index());
            }

            return self::shape_payload(static::payload_for_post_type_archive($post_type));
        } catch (Throwable $e) {
            self::log_failure('resolve_content_type', $e);

            return null;
        }
    }

    /**
     * Resolve the front page root field.
     *
     * @return array|null
     */
    public static function resolve_home_page()
    {
        try {
            return self::shape_payload(static::payload_for_home_page());
        } catch (Throwable $e) {
            self::log_failure('resolve_home_page', $e);

            return null;
        }
    }

    /**
     * Resolve the blog index root field.
     *
     * @return array|null
     */
    public static function resolve_posts_page()
    {
        try {
            return self::shape_payload(static::payload_for_posts_page());
        } catch (Throwable $e) {
            self::log_failure('resolve_posts_page', $e);

            return null;
        }
    }

    /**
     * Shape a resolved payload, or null when there is not one.
     *
     * Every resolver funnels through here so "the surface refused" and "the
     * surface returned something unexpected" become the same null in one place
     * rather than six.
     *
     * @param mixed $data
     * @return array|null
     */
    private static function shape_payload($data)
    {
        if (!($data instanceof Metasync_Headless_Seo_Data)) {
            return null;
        }

        return self::shape($data);
    }

    /* -----------------------------------------------------------------
     *  Request-driven refresh
     *
     *  Stored values are what GraphQL publishes. This block is only ever
     *  allowed to make them *newer* — never slower to arrive than the
     *  configured timeout, never absent because a refresh went wrong, and
     *  never accompanied by a reason it went wrong. Anything that fails,
     *  is blocked, or is simply not permitted yields the same answer the
     *  resolver would have given without it.
     * ----------------------------------------------------------------- */

    /**
     * Publish one object's SEO, refreshing it first when that is permitted.
     *
     * @param string      $type     'post' or 'term'.
     * @param int         $id       WordPress object ID.
     * @param string|null $taxonomy Taxonomy name, terms only.
     * @param mixed       $payload  Stored payload as already resolved.
     * @param callable    $reread   Re-resolves the payload after a write.
     * @return array|\GraphQL\Deferred|null
     */
    private static function answer($type, $id, $taxonomy, $payload, callable $reread)
    {
        if (!($payload instanceof Metasync_Headless_Seo_Data)) {
            # Nothing stored to publish, so nothing to refresh either — a
            # refresh writes over stored values, it does not invent them.
            return null;
        }

        $stored = self::shape($payload);

        # Asked before anything is queued, so a site with the live path off
        # carries none of the bookkeeping below.
        if (!static::live_refresh_available()) {
            return $stored;
        }

        $key = $type . ':' . (int) $id . (($taxonomy !== null && $taxonomy !== '') ? ':' . $taxonomy : '');

        if (isset(self::$attempted[$key])) {
            # Already handled once in this request. A query naming the same
            # object twice — through a node and through a connection, say —
            # gets that answer again rather than a second call. Note it is the
            # *published* answer, not the one just re-read: one object must not
            # appear with two different titles in one response.
            return self::$published[$key] ?? $stored;
        }

        self::$requested[$key] = true;

        return static::defer(static function () use ($key, $type, $id, $taxonomy, $stored, $reread) {
            return self::settle($key, $type, $id, $taxonomy, $stored, $reread, -1, 0);
        });
    }

    /**
     * Wait for the request to stop growing, then refresh if it is small enough.
     *
     * The rule is about the request, not about a pass: more than the ceiling
     * of objects and *none* of them is refreshed. That means the count has to
     * be final before the first call goes out, and under lazy resolution it is
     * not final when the first deferred callback runs.
     *
     * graphql-php keeps one FIFO queue of deferred callbacks, and completing
     * one can resolve fields that queue more. A connection is the ordinary
     * case: the parent's SEO field and the child loader are queued together,
     * so when the SEO callback runs, the children it will pull in have not
     * been counted yet. Answering there would refresh three objects out of a
     * query that turns out to name nine, and would do it or not depending on
     * whether the client wrote the connection before or after the SEO field.
     *
     * So a waiter that finds the count still moving hands its turn back to the
     * queue and looks again. When the count has held still for
     * SETTLE_QUIET_TURNS turns, resolution has produced everything it is going
     * to and the request can be judged. Costs about two extra turns per object
     * — no network, no database, just the queue that was going to drain anyway.
     *
     * @param string      $key
     * @param string      $type
     * @param int         $id
     * @param string|null $taxonomy
     * @param array       $stored
     * @param callable    $reread
     * @param int         $seen   Object count at this waiter's previous turn.
     * @param int         $quiet  Consecutive turns the count has not moved.
     * @return array|\GraphQL\Deferred
     */
    private static function settle($key, $type, $id, $taxonomy, $stored, callable $reread, $seen, $quiet)
    {
        if (self::$verdict === null) {
            $total = count(self::$requested);

            if ($total !== $seen) {
                # Still arriving. Nothing can be decided yet, by us or anyone.
                return static::defer(static function () use ($key, $type, $id, $taxonomy, $stored, $reread, $total) {
                    return self::settle($key, $type, $id, $taxonomy, $stored, $reread, $total, 0);
                });
            }

            if ($quiet < self::SETTLE_QUIET_TURNS - 1
                && (!static::queue_is_empty() || self::$pending_rechecks > 0)) {
                # Held still, but there is still work queued that could bring
                # more objects. Take another turn before trusting the count.
                $next = $quiet + 1;

                return static::defer(static function () use ($key, $type, $id, $taxonomy, $stored, $reread, $total, $next) {
                    return self::settle($key, $type, $id, $taxonomy, $stored, $reread, $total, $next);
                });
            }

            # Settled. Judge the request once, for every object in it, so the
            # answer cannot depend on selection order.
            self::$verdict = ($total <= self::MAX_SYNC_REFRESH_OBJECTS);
        }

        return self::refresh_then_publish($key, $type, $id, $taxonomy, $stored, $reread);
    }

    /**
     * Run the refresh this object queued, then publish whatever is stored.
     *
     * @param string      $key
     * @param string      $type
     * @param int         $id
     * @param string|null $taxonomy
     * @param array       $stored
     * @param callable    $reread
     * @return array
     */
    private static function refresh_then_publish($key, $type, $id, $taxonomy, $stored, callable $reread)
    {
        try {
            if (isset(self::$attempted[$key])) {
                # Claimed by an earlier callback in this same request. answer()
                # cannot catch this case: at resolve time no callback has run
                # yet, so both selections of the object look unclaimed.
                return self::$published[$key] ?? $stored;
            }

            if (!self::$verdict || self::$refresh_calls >= self::MAX_SYNC_REFRESH_OBJECTS) {
                # Either the request named more objects than may be refreshed
                # synchronously, or the budget is already spent. Both publish
                # what is stored.
                self::$attempted[$key] = true;
                self::record_history(
                    '__request__',
                    'request',
                    0,
                    !self::$verdict ? 'request_too_large' : 'budget_exhausted',
                    count(self::$requested)
                );

                return $stored;
            }

            # Marked before the call, not after: if the call throws, this object
            # must still not be retried later in the same request.
            self::$attempted[$key] = true;
            self::$history_started[$key] = microtime(true);

            # Charged before the call, not after. A throw must still cost the
            # budget, or a request whose refreshes all fail could attempt an
            # unbounded number of them.
            self::$refresh_calls++;

            $outcome = static::refresh_one($type, (int) $id, $taxonomy);
            self::record_history($key, $type, $id, $outcome, count(self::$requested));

            if ($outcome === Metasync_Headless_Refresh_Job::OUTCOME_FRESH
                || $outcome === Metasync_Headless_Refresh_Job::OUTCOME_DISABLED
                || $outcome === Metasync_Headless_Refresh_Job::OUTCOME_NO_URL
                || $outcome === Metasync_Headless_Refresh_Job::OUTCOME_BREAKER_OPEN
                || $outcome === Metasync_Headless_Refresh_Job::OUTCOME_LOCKED
                || $outcome === Metasync_Headless_Refresh_Job::OUTCOME_RATE_LIMITED) {
                # None of these reached the network: each returns before the
                # fetch. Refund the charge, or a run of cooldown hits would
                # crowd out the objects that would actually refresh.
                self::$refresh_calls--;
            }

            if ($outcome !== Metasync_Headless_Refresh_Job::OUTCOME_REFRESHED) {
                # Fresh, empty, unchanged, locked, rate-limited, breaker-open,
                # failed: in every one of those the stored values are still the
                # right answer, and which one it was is ours to know, not the
                # client's. They are deliberately indistinguishable from here.
                return $stored;
            }

            $refreshed = $reread();
            $answer    = $refreshed instanceof Metasync_Headless_Seo_Data ? self::shape($refreshed) : $stored;

            self::$published[$key] = $answer;

            return $answer;
        } catch (Throwable $e) {
            # This runs from graphql-php's deferred queue, outside the
            # resolver's own try. Without this catch a refresh failure would
            # reach the executor and fail the whole content query — the one
            # thing this class must never do.
            #
            # static:: rather than self:: because this is the one failure path
            # a subclass has to be able to observe: it is the only one that
            # does not surface as a null the caller can see.
            static::log_failure('refresh of ' . $type . ' ' . (int) $id, $e);

            return $stored;
        }
    }

    /**
     * Persist one bounded, privacy-safe refresh history row.
     *
     * @param string $key
     * @param string $type
     * @param int    $id
     * @param string $outcome
     * @param int    $object_count
     * @return void
     */
    private static function record_history($key, $type, $id, $outcome, $object_count)
    {
        if ($outcome === Metasync_Headless_Refresh_Job::OUTCOME_FRESH
            || isset(self::$history_recorded[$key])) {
            return;
        }

        self::$history_recorded[$key] = true;
        $started = self::$history_started[$key] ?? microtime(true);
        $duration = (int) round((microtime(true) - $started) * 1000);
        $reason_map = array(
            Metasync_Headless_Refresh_Job::OUTCOME_FRESH => 'timestamp_fresh',
            Metasync_Headless_Refresh_Job::OUTCOME_REFRESHED => 'live_success',
            Metasync_Headless_Refresh_Job::OUTCOME_UNCHANGED => 'live_unchanged',
            Metasync_Headless_Refresh_Job::OUTCOME_EMPTY => 'empty_response',
            Metasync_Headless_Refresh_Job::OUTCOME_NO_URL => 'no_frontend_url',
            Metasync_Headless_Refresh_Job::OUTCOME_LOCKED => 'refresh_locked',
            Metasync_Headless_Refresh_Job::OUTCOME_RATE_LIMITED => 'rate_limited',
            Metasync_Headless_Refresh_Job::OUTCOME_BREAKER_OPEN => 'breaker_open',
            Metasync_Headless_Refresh_Job::OUTCOME_FAILED => 'transport_failed',
            Metasync_Headless_Refresh_Job::OUTCOME_DISABLED => 'refresh_disabled',
            'request_too_large' => 'request_too_large',
            'budget_exhausted' => 'budget_exhausted',
        );
        $reason = $reason_map[$outcome] ?? 'refresh_outcome';

        Metasync_Headless_Refresh_History::record(
            $type,
            (int) $id,
            $outcome,
            $reason,
            $duration,
            (int) $object_count
        );
    }

    /**
     * Has graphql-php run out of deferred work?
     *
     * A seam because the transport tests drive settling without the real
     * must still be able to answer the question.
     *
     * @return bool
     */
    protected static function queue_is_empty()
    {
        if (!class_exists('GraphQL\Executor\Promise\Adapter\SyncPromiseQueue')) {
            # Without the queue we cannot tell whether more work is pending, so
            # we assume it is: the extra turn is cheap and the alternative is
            # judging a request that has not finished arriving.
            return false;
        }

        return \GraphQL\Executor\Promise\Adapter\SyncPromiseQueue::isEmpty();
    }

    /**
     * Is the request-driven refresh path usable at all?
     *
     * Deferral is part of the answer, not an optimisation: without it the
     * object count cannot be known before the first call, and a path that
     * cannot enforce the ceiling must not run. A site whose GraphQL cannot
     * defer serves stored values, which is what it does today.
     *
     * @return bool
     */
    protected static function live_refresh_available()
    {
        if (!class_exists('Metasync_Headless_Refresh_Job') || !class_exists('GraphQL\Deferred')) {
            return false;
        }

        return (bool) apply_filters('metasync_headless_graphql_live_refresh', true);
    }

    /**
     * Wrap a callback so graphql-php runs it after the current pass is counted.
     *
     * @param callable $callback
     * @return \GraphQL\Deferred
     */
    protected static function defer(callable $callback)
    {
        self::$pending_rechecks++;

        return new \GraphQL\Deferred(function () use ($callback) {
            self::$pending_rechecks = max(0, self::$pending_rechecks - 1);
            return $callback();
        });
    }

    /**
     * Refresh one object, the single point where transport calls the job.
     *
     * A seam for the same reason as the resolution seams above: the transport
     * tests drive counting, deferral, deduplication and fallback, and none of
     * those need a live OTTO round trip to be real.
     *
     * @param string      $type
     * @param int         $id
     * @param string|null $taxonomy
     * @return string One of the job's OUTCOME_* constants.
     */
    protected static function refresh_one($type, $id, $taxonomy)
    {
        return Metasync_Headless_Refresh_Job::refresh_single_object($type, (int) $id, $taxonomy, 2);
    }

    /* -----------------------------------------------------------------
     *  Resolution seams
     *
     *  The single points where transport meets resolution, one per entry
     *  point. Kept separate so a REST route can share them, so a bulk
     *  resolver can be substituted for a listing query without touching
     *  the shaping below, and so the transport tests can drive registration,
     *  gating, routing and fail-closed behaviour without dragging the whole
     *  resolver — and the ambient WordPress stubs — in behind them.
     * ----------------------------------------------------------------- */

    /**
     * The resolved payload for one post.
     *
     * @param int $post_id
     * @return Metasync_Headless_Seo_Data|null
     */
    protected static function payload_for_post($post_id)
    {
        return Metasync_Headless_Seo_Surface::for_post($post_id);
    }

    /**
     * Resolve a page of post payloads in one pass.
     *
     * @param int[] $post_ids
     * @return array
     */
    protected static function payload_for_posts(array $post_ids)
    {
        return Metasync_Headless_Seo_Surface::for_posts($post_ids);
    }

    /**
     * Resolve a post connection's SEO payloads after WPGraphQL has loaded its
     * nodes, so the per-node field resolver can reuse the request memo.
     *
     * WPGraphQL exposes connection IDs/nodes filters, but no field-specific
     * batch resolver. This nodes filter is the practical boundary: it sees the
     * complete connection page and runs only when metasyncSeo was requested.
     *
     * @param mixed $nodes    Whatever the filter chain passes; guarded at runtime
     *                        because a third-party filter is free to hand back
     *                        something other than the node array.
     * @param mixed $resolver
     * @return array
     */
    public static function prime_post_connection_nodes($nodes, $resolver)
    {
        if (!is_array($nodes) || !is_object($resolver)) {
            return $nodes;
        }

        try {
            if (!method_exists($resolver, 'get_loader_name') || $resolver->get_loader_name() !== 'post') {
                return $nodes;
            }

            if (!self::connection_requests_seo($resolver)) {
                return $nodes;
            }

            $ids = array();
            foreach ($nodes as $node) {
                $id = self::database_id_from_source($node);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }

            if (!empty($ids)) {
                static::payload_for_posts($ids);
            }
        } catch (Throwable $e) {
            self::log_failure('connection priming', $e);
        }

        return $nodes;
    }

    /**
     * @param mixed $resolver
     * @return bool
     */
    private static function connection_requests_seo($resolver)
    {
        if (!method_exists($resolver, 'get_info')) {
            return false;
        }

        $info = $resolver->get_info();
        if (!is_object($info) || !method_exists($info, 'getFieldSelection')) {
            return false;
        }

        return self::selection_contains_field($info->getFieldSelection(5), self::FIELD_NAME);
    }

    /**
     * @param mixed  $selection
     * @param string $field
     * @return bool
     */
    private static function selection_contains_field($selection, $field)
    {
        if (!is_array($selection)) {
            return false;
        }

        foreach ($selection as $name => $children) {
            if ((string) $name === $field || self::selection_contains_field($children, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The resolved payload for one term.
     *
     * @param int $term_id
     * @return Metasync_Headless_Seo_Data|null
     */
    protected static function payload_for_term($term_id)
    {
        return Metasync_Headless_Seo_Surface::for_term($term_id);
    }

    /**
     * The resolved payload for one author archive.
     *
     * @param int $user_id
     * @return Metasync_Headless_Seo_Data|null
     */
    protected static function payload_for_author($user_id)
    {
        return Metasync_Headless_Seo_Surface::for_author($user_id);
    }

    /**
     * The resolved payload for one post type archive.
     *
     * @param string $post_type
     * @return Metasync_Headless_Seo_Data|null
     */
    protected static function payload_for_post_type_archive($post_type)
    {
        return Metasync_Headless_Seo_Surface::for_post_type_archive($post_type);
    }

    /**
     * The resolved payload for the front page.
     *
     * @return Metasync_Headless_Seo_Data|null
     */
    protected static function payload_for_home_page()
    {
        return Metasync_Headless_Seo_Surface::for_home_page();
    }

    /**
     * The resolved payload for the blog index.
     *
     * @return Metasync_Headless_Seo_Data|null
     */
    /**
     * The resolved payload for whatever serves as the blog index.
     *
     * @return Metasync_Headless_Seo_Data|null
     */
    protected static function payload_for_blog_index()
    {
        return Metasync_Headless_Seo_Surface::for_blog_index();
    }

    protected static function payload_for_posts_page()
    {
        return Metasync_Headless_Seo_Surface::for_posts_page();
    }

    /**
     * Map the payload onto the GraphQL field names.
     *
     * Written out rather than derived from the payload keys so adding a field to
     * the resolver cannot silently publish it: anything new has to be added here
     * and to the type deliberately. On an endpoint that may have no auth gate,
     * that is the property worth having.
     *
     * @param Metasync_Headless_Seo_Data $data
     * @return array
     */
    private static function shape(Metasync_Headless_Seo_Data $data)
    {
        $schema = $data->get_schema();

        return array(
            'title'              => $data->get_title(),
            'description'        => $data->get_description(),
            'keywords'           => $data->get_keywords(),
            'canonical'          => $data->get_canonical(),
            'robots'             => $data->get_robots(),

            'ogTitle'            => $data->get_og_title(),
            'ogDescription'      => $data->get_og_description(),
            'ogImage'            => $data->get_og_image(),
            'ogUrl'              => $data->get_og_url(),
            'ogType'             => (string) $data->get('og_type'),
            'ogSiteName'         => (string) $data->get('og_site_name'),
            'ogLocale'           => (string) $data->get('og_locale'),

            'twitterCard'        => (string) $data->get('twitter_card'),
            'twitterTitle'       => $data->get_twitter_title(),
            'twitterDescription' => $data->get_twitter_description(),
            'twitterImage'       => $data->get_twitter_image(),

            # Null rather than "[]" when there is no graph: a frontend testing
            # truthiness before writing a script tag should not have to know that
            # an empty JSON array means "nothing to render".
            'schema'             => $schema === array() ? null : wp_json_encode($schema),

            'publicUrl'          => $data->get_public_url(),
        );
    }

    /**
     * Pull a database ID out of whatever WPGraphQL hands the resolver.
     *
     * The model exposes `databaseId`; older shapes and plain WordPress objects
     * expose `ID`. Both are accepted rather than assuming one, since guessing
     * wrong means the field silently resolves to null on every request — the
     * failure mode that looks exactly like "there is no data".
     *
     * The property list is a parameter because each node type spells it
     * differently, and because a name that means one thing on one model can mean
     * something else on another: `id` on a WPGraphQL model is the relay global
     * id, a base64 string, so it is read last and only survives the is_numeric()
     * test on a plain object that happens to use it.
     *
     * @param mixed    $source
     * @param string[] $properties Property names to try, in order.
     * @return int
     */
    private static function database_id_from_source($source, $properties = array('databaseId', 'ID', 'id'))
    {
        if (is_numeric($source)) {
            return (int) $source;
        }

        if (!is_object($source)) {
            return 0;
        }

        foreach ($properties as $property) {
            if (!isset($source->$property)) {
                continue;
            }

            $value = $source->$property;
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return 0;
    }

    /**
     * Pull a post type name out of a ContentType node.
     *
     * `name` is the WordPress post type key on WPGraphQL's model — not the
     * GraphQL type name, which is `graphqlSingleName`. `post_type` is accepted
     * as well so a plain registered post type object also works.
     *
     * A plain string is accepted too, so this reads the same way as the id
     * extraction above: whatever came in, either it names a post type or it does
     * not.
     *
     * @param mixed $source
     * @return string
     */
    private static function post_type_from_source($source)
    {
        if (is_string($source)) {
            return trim($source);
        }

        if (!is_object($source)) {
            return '';
        }

        foreach (array('name', 'post_type') as $property) {
            if (!isset($source->$property)) {
                continue;
            }

            $value = $source->$property;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * Record a failure without surfacing it to the caller.
     *
     * The whole point of this class is that the frontend never sees an error, so
     * a failure that is invisible to us as well would be undiagnosable.
     *
     * Logging is itself wrapped, and that is not belt-and-braces. This runs
     * inside a catch block, and a throw from inside a catch is not caught by the
     * try it belongs to — so a logger that fails for any reason (an unwritable
     * log path, a missing constant, a full disk) would send the original
     * exception straight on to WPGraphQL and fail the entire content query. The
     * one thing this class must never do.
     *
     * The severity arrives as a boolean rather than as a logger constant for the
     * same reason. A caller writing `Metasync_Error_Logger::SEVERITY_ERROR` in
     * its argument list resolves that class *before* entering this method, so on
     * an install where the class is somehow absent the constant lookup throws
     * from inside the caller's catch block — the exact shape this method exists
     * to prevent, reintroduced by the call to it. Passing a bool means every
     * mention of the class stays inside the try below.
     *
     * @param string    $stage    Where it happened.
     * @param Throwable $e
     * @param bool      $is_error Log as an error rather than a warning.
     * @return void
     */
    protected static function log_failure($stage, $e, $is_error = false)
    {
        try {
            if (!class_exists('Metasync_Error_Logger')) {
                return;
            }

            Metasync_Error_Logger::log(
                Metasync_Error_Logger::CATEGORY_HEADLESS_DELIVERY,
                $is_error ? Metasync_Error_Logger::SEVERITY_ERROR : Metasync_Error_Logger::SEVERITY_WARNING,
                'Headless GraphQL SEO field failed during ' . $stage,
                array(
                    'stage'     => $stage,
                    'exception' => $e->getMessage(),
                )
            );
        } catch (Throwable $ignored) {
            # Nothing left to do: reporting the reporting failure would have the
            # same problem. Losing a log line is survivable; breaking the page
            # the visitor asked for is not.
            return;
        }
    }
}
