<?php
/**
 * The one place that decides which stored SEO value wins.
 *
 * MetaSync keeps several values for the same field and has to pick one. The
 * order was previously restated at every call site — the render filters, the
 * outbound sync payload, the admin columns, SEO Health, the meta box and the
 * unified SEO suite each carried their own copy. They had already drifted, and
 * a bulk import landing one tier too high is invisible until a customer notices
 * their pages stopped taking OTTO's recommendation.
 *
 * The order lives here now, once. Call sites ask for a chain or a resolved
 * value; they no longer restate what beats what.
 *
 * ── Posts ────────────────────────────────────────────────────────────────
 *   _metasync_seo_title           the customer typed it in the sidebar or box
 *   _metasync_metatitle           OTTO's value, persisted by the sync
 *   _metasync_otto_title          OTTO's value for this request
 *   _metasync_imported_seo_title  brought in from another SEO plugin
 *
 * ── Terms ────────────────────────────────────────────────────────────────
 *   _metasync_metatitle           set deliberately, by the MCP taxonomy tool
 *   _metasync_imported_seo_title  brought in from another SEO plugin
 *
 * The same key sits in a different tier on each object type, which is safe
 * because the two chains never read each other and terms carry no OTTO meta at
 * all: OTTO reaches a taxonomy archive through the output-buffer pass, which
 * runs after the render filters rather than through a stored value.
 *
 * The invariant the whole ticket rests on: an imported value is last. It is
 * migration data, not a per-post decision by the customer, so it renders only
 * where nothing else has anything to say.
 *
 * @package Search Atlas SEO
 */

// Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

final class Metasync_Seo_Precedence
{
    const TYPE_POST = 'post';
    const TYPE_TERM = 'term';

    const FIELD_TITLE       = 'title';
    const FIELD_DESCRIPTION = 'description';

    /**
     * Social fields.
     *
     * These carry their own values rather than deriving from the page title and
     * description, so they need their own chains: a post can have an OG title
     * and no SEO title, and asking the page-title chain about it answers "we
     * hold nothing" for a value we do hold.
     */
    const FIELD_OG_TITLE            = 'og_title';
    const FIELD_OG_DESCRIPTION      = 'og_description';
    const FIELD_TWITTER_TITLE       = 'twitter_title';
    const FIELD_TWITTER_DESCRIPTION = 'twitter_description';
    const FIELD_OG_IMAGE            = 'og_image';
    const FIELD_TWITTER_IMAGE       = 'twitter_image';

    /**
     * Source labels, as the admin surfaces render them. An empty label means a
     * value MetaSync itself owns and needs no attribution.
     */
    const SOURCE_METASYNC = '';
    const SOURCE_OTTO     = 'OTTO';
    const SOURCE_IMPORTED = 'Imported';

    /**
     * Tier that holds OTTO's value once the sync has persisted it.
     *
     * Named because one caller deliberately leaves it out — see chain().
     */
    const KEY_PERSISTED_OTTO_TITLE = '_metasync_metatitle';
    const KEY_PERSISTED_OTTO_DESC  = '_metasync_metadesc';

    /**
     * The one tier whose printing is owned by another emitter:
     * metasync_output_otto_meta_description() prints this key on the wp_head
     * paths, while the sidebar emitter prints every other tier. Naming the
     * key lets both emitters state that ownership rule against the class
     * instead of restating the literal. See the guard comments in
     * Metasync_SEO_Sidebar::output_seo_meta_description() and in
     * metasync_output_otto_meta_description().
     */
    const KEY_OTTO_DESC = '_metasync_otto_description';

    /**
     * The lowest tier: values brought in from another SEO plugin.
     *
     * Named so the importer writes "the last tier" rather than a literal it
     * could get wrong, and so the sidebar can expose the same key to JS without
     * a second copy of the string.
     */
    const KEY_IMPORTED_TITLE = '_metasync_imported_seo_title';
    const KEY_IMPORTED_DESC  = '_metasync_imported_seo_desc';

    /**
     * The tier the customer typed into the sidebar or meta box.
     *
     * Named because the global priority setting moves exactly these two keys,
     * and only these two. The other key labelled SOURCE_METASYNC on a post —
     * the persisted _metasync_metatitle / _metasync_metadesc pair — holds
     * OTTO's own value written by the sync, so demoting it under "prioritize
     * OTTO" would push an OTTO value below OTTO. See demote_custom_tier().
     */
    const KEY_CUSTOM_TITLE = '_metasync_seo_title';
    const KEY_CUSTOM_DESC  = '_metasync_seo_desc';

    /**
     * Option value that hands precedence to OTTO. Anything else, including an
     * unset or malformed option, means custom-first — the behaviour every site
     * had before the setting existed.
     */
    const PRIORITY_OTTO = 'otto';

    /**
     * Social values brought in from another SEO plugin.
     *
     * Social fields need their own imported keys for the same reason the page
     * title and description do: an import is not a per-post decision by the
     * customer, so it must not land on the key that means one. Re-running an
     * import with "overwrite existing" replaces these, never what the customer
     * typed.
     */
    const KEY_IMPORTED_OG_TITLE      = '_metasync_imported_og_title';
    const KEY_IMPORTED_OG_DESC       = '_metasync_imported_og_desc';
    const KEY_IMPORTED_OG_IMAGE      = '_metasync_imported_og_image';
    const KEY_IMPORTED_TWITTER_TITLE = '_metasync_imported_twitter_title';
    const KEY_IMPORTED_TWITTER_DESC  = '_metasync_imported_twitter_desc';
    const KEY_IMPORTED_TWITTER_IMAGE = '_metasync_imported_twitter_image';

    /**
     * The ordered key => source label map for every object type and field.
     *
     * @return array<string,array<string,array<string,string>>>
     */
    private static function table() {
        return [
            self::TYPE_POST => [
                self::FIELD_TITLE => [
                    '_metasync_seo_title'          => self::SOURCE_METASYNC,
                    '_metasync_metatitle'          => self::SOURCE_METASYNC,
                    '_metasync_otto_title'         => self::SOURCE_OTTO,
                    self::KEY_IMPORTED_TITLE       => self::SOURCE_IMPORTED,
                ],
                self::FIELD_DESCRIPTION => [
                    '_metasync_seo_desc'          => self::SOURCE_METASYNC,
                    '_metasync_metadesc'          => self::SOURCE_METASYNC,
                    '_metasync_otto_description'  => self::SOURCE_OTTO,
                    self::KEY_IMPORTED_DESC       => self::SOURCE_IMPORTED,
                ],
                // Social chains mirror the order Metasync_OpenGraph resolves in
                // — what the customer set, then OTTO's staging key, then a value
                // brought in from another SEO plugin — so the tag we hand a
                // third-party plugin is the one we would have emitted ourselves.
                self::FIELD_OG_TITLE => [
                    '_metasync_og_title'      => self::SOURCE_METASYNC,
                    '_metasync_otto_og_title' => self::SOURCE_OTTO,
                    self::KEY_IMPORTED_OG_TITLE => self::SOURCE_IMPORTED,
                ],
                self::FIELD_OG_DESCRIPTION => [
                    '_metasync_og_description'      => self::SOURCE_METASYNC,
                    '_metasync_otto_og_description' => self::SOURCE_OTTO,
                    self::KEY_IMPORTED_OG_DESC      => self::SOURCE_IMPORTED,
                ],
                self::FIELD_TWITTER_TITLE => [
                    '_metasync_twitter_title'      => self::SOURCE_METASYNC,
                    '_metasync_otto_twitter_title' => self::SOURCE_OTTO,
                    self::KEY_IMPORTED_TWITTER_TITLE => self::SOURCE_IMPORTED,
                ],
                self::FIELD_TWITTER_DESCRIPTION => [
                    '_metasync_twitter_description'      => self::SOURCE_METASYNC,
                    '_metasync_otto_twitter_description' => self::SOURCE_OTTO,
                    self::KEY_IMPORTED_TWITTER_DESC      => self::SOURCE_IMPORTED,
                ],
                // Images carry no OTTO staging key; the emitter's own featured-image
                // fallback sits below these.
                self::FIELD_OG_IMAGE => [
                    '_metasync_og_image'       => self::SOURCE_METASYNC,
                    self::KEY_IMPORTED_OG_IMAGE => self::SOURCE_IMPORTED,
                ],
                self::FIELD_TWITTER_IMAGE => [
                    '_metasync_twitter_image'       => self::SOURCE_METASYNC,
                    self::KEY_IMPORTED_TWITTER_IMAGE => self::SOURCE_IMPORTED,
                ],
            ],
            self::TYPE_TERM => [
                // OTTO does write per-term values — _metasync_otto_title and
                // _metasync_otto_description exist in term meta — so terms carry
                // the same three tiers a post does. Leaving OTTO out put an
                // imported value above an OTTO one, the exact inversion this
                // class exists to prevent.
                self::FIELD_TITLE => [
                    self::KEY_PERSISTED_OTTO_TITLE => self::SOURCE_METASYNC,
                    '_metasync_otto_title'         => self::SOURCE_OTTO,
                    self::KEY_IMPORTED_TITLE       => self::SOURCE_IMPORTED,
                ],
                self::FIELD_DESCRIPTION => [
                    self::KEY_PERSISTED_OTTO_DESC => self::SOURCE_METASYNC,
                    '_metasync_otto_description'  => self::SOURCE_OTTO,
                    self::KEY_IMPORTED_DESC       => self::SOURCE_IMPORTED,
                ],
            ],
        ];
    }

    /**
     * The key an import writes to — always the lowest tier.
     *
     * @param string $field FIELD_TITLE or FIELD_DESCRIPTION.
     * @return string       '' for fields that have no imported tier.
     */
    public static function imported_key($field) {
        $map = [
            self::FIELD_TITLE               => self::KEY_IMPORTED_TITLE,
            self::FIELD_DESCRIPTION         => self::KEY_IMPORTED_DESC,
            self::FIELD_OG_TITLE            => self::KEY_IMPORTED_OG_TITLE,
            self::FIELD_OG_DESCRIPTION      => self::KEY_IMPORTED_OG_DESC,
            self::FIELD_OG_IMAGE            => self::KEY_IMPORTED_OG_IMAGE,
            self::FIELD_TWITTER_TITLE       => self::KEY_IMPORTED_TWITTER_TITLE,
            self::FIELD_TWITTER_DESCRIPTION => self::KEY_IMPORTED_TWITTER_DESC,
            self::FIELD_TWITTER_IMAGE       => self::KEY_IMPORTED_TWITTER_IMAGE,
        ];

        return isset($map[$field]) ? $map[$field] : '';
    }

    /**
     * The ordered chain for a field, highest tier first.
     *
     * Options, all defaulting to the full chain:
     *   'include_otto'           false drops both OTTO tiers. Used where OTTO
     *                            has stood down for the object, so naming its
     *                            stored value would report one the page does
     *                            not use.
     *   'include_persisted_otto' false drops only the persisted tier. The admin
     *                            columns have always read the volatile key
     *                            alone; kept as an explicit opt-out rather than
     *                            changed silently as part of consolidating.
     *   'include_imported'       false drops the imported tier.
     *
     * @param string $field       FIELD_TITLE or FIELD_DESCRIPTION.
     * @param string $object_type TYPE_POST or TYPE_TERM.
     * @param array  $options     See above.
     * @return array<string,string> Ordered meta key => source label.
     */
    public static function chain($field, $object_type = self::TYPE_POST, array $options = []) {
        $table = self::table();

        if (!isset($table[$object_type][$field])) {
            return [];
        }

        $chain = $table[$object_type][$field];

        $include_otto      = array_key_exists('include_otto', $options) ? (bool) $options['include_otto'] : true;
        $include_metasync  = array_key_exists('include_metasync', $options) ? (bool) $options['include_metasync'] : true;
        $include_persisted = array_key_exists('include_persisted_otto', $options) ? (bool) $options['include_persisted_otto'] : true;
        $include_imported  = array_key_exists('include_imported', $options) ? (bool) $options['include_imported'] : true;

        $persisted_key = $field === self::FIELD_TITLE
            ? self::KEY_PERSISTED_OTTO_TITLE
            : self::KEY_PERSISTED_OTTO_DESC;

        foreach ($chain as $key => $source) {
            // A tier labelled OTTO is OTTO's on either object type. The
            // persisted key is only OTTO's on a post — on a term it holds the
            // deliberately-set value, so the OTTO opt-out must not reach it.
            $is_otto_tier = $source === self::SOURCE_OTTO
                || ($object_type === self::TYPE_POST && $key === $persisted_key);

            if (!$include_otto && $is_otto_tier) {
                unset($chain[$key]);
                continue;
            }

            if (!$include_persisted && $object_type === self::TYPE_POST && $key === $persisted_key) {
                unset($chain[$key]);
                continue;
            }

            if (!$include_imported && $source === self::SOURCE_IMPORTED) {
                unset($chain[$key]);
                continue;
            }

            // Drops the tier MetaSync itself owns. Used where a non-empty value
            // does not prove intent: the OG meta box pre-fills its fields from the
            // post and persists those defaults on save, so the caller checks that
            // separately and asks for the rest of the chain.
            if (!$include_metasync && $source === self::SOURCE_METASYNC) {
                unset($chain[$key]);
            }
        }

        return self::demote_custom_tier($chain, $field, $object_type, $options);
    }

    /**
     * Move the customer's own value below OTTO's when the site asks for it.
     *
     * The global "SEO Title & Description Priority" setting exists because a
     * page holding both a custom value and an approved OTTO suggestion renders
     * the custom one, which reads as a failed OTTO deployment. Flipping the
     * setting re-orders this chain; it never deletes anything. The custom key
     * stays in the chain, one tier lower, so it still renders whenever OTTO has
     * nothing for the object — which is the whole point of demoting rather than
     * dropping it.
     *
     * Only the page title and meta description move. OG and social tags carry
     * their own chains and are deliberately left alone for now.
     *
     * @param array<string,string> $chain       Ordered key => source, already filtered.
     * @param string               $field
     * @param string               $object_type
     * @param array                $options     'seo_priority' overrides the stored option,
     *                                          so callers and tests can ask for a specific
     *                                          order without touching global state.
     * @return array<string,string>
     */
    private static function demote_custom_tier(array $chain, $field, $object_type, array $options = []) {
        if ($object_type !== self::TYPE_POST) {
            return $chain;
        }

        if ($field !== self::FIELD_TITLE && $field !== self::FIELD_DESCRIPTION) {
            return $chain;
        }

        $priority = array_key_exists('seo_priority', $options)
            ? $options['seo_priority']
            : self::stored_priority();

        if ($priority !== self::PRIORITY_OTTO) {
            return $chain;
        }

        $custom_key = $field === self::FIELD_TITLE ? self::KEY_CUSTOM_TITLE : self::KEY_CUSTOM_DESC;

        // Nothing to move when the caller already dropped the custom tier, or
        // when OTTO's tier is gone — with no OTTO key left there is nothing for
        // the custom value to sit behind, and re-ordering would only churn.
        if (!array_key_exists($custom_key, $chain)) {
            return $chain;
        }

        $otto_key = $field === self::FIELD_TITLE ? '_metasync_otto_title' : '_metasync_otto_description';
        if (!array_key_exists($otto_key, $chain)) {
            return $chain;
        }

        // Rebuild rather than sort: the remaining tiers keep their documented
        // order, and the custom key lands immediately after OTTO's — above the
        // imported tier, which must stay last.
        $custom_source = $chain[$custom_key];
        $rebuilt       = [];

        foreach ($chain as $key => $source) {
            if ($key === $custom_key) {
                continue;
            }

            $rebuilt[$key] = $source;

            if ($key === $otto_key) {
                $rebuilt[$custom_key] = $custom_source;
            }
        }

        return $rebuilt;
    }

    /**
     * The stored global priority, or '' when the plugin option is unreadable.
     *
     * Guarded more tightly than otto_is_disabled(): that helper only has to
     * survive the main plugin class being absent, but this one also runs under
     * unit tests and admin surfaces that declare a minimal Metasync stub with
     * no get_option(). A bare class_exists() passes there and then fatals on
     * the call, so the method itself is checked too.
     *
     * The check goes through callable_on() rather than a literal
     * method_exists('Metasync', 'get_option'): PHPStan resolves the literal
     * form against the real class, decides it is always true, and fails the
     * build on function.alreadyNarrowedType — and this repo allows neither
     * baseline entries nor inline ignores.
     *
     * @return string
     */
    private static function stored_priority() {
        if (!self::callable_on('Metasync', 'get_option')) {
            return '';
        }

        $general = Metasync::get_option('general');

        if (!is_array($general) || !isset($general['seo_priority'])) {
            return '';
        }

        return is_string($general['seo_priority']) ? $general['seo_priority'] : '';
    }

    /**
     * Whether a static method is actually callable on a class right now.
     *
     * Takes the class and method as parameters so the check is opaque to
     * static analysis — see stored_priority() for why that matters.
     *
     * @param string $class
     * @param string $method
     * @return bool
     */
    private static function callable_on($class, $method) {
        return class_exists($class) && method_exists($class, $method);
    }

    /**
     * The ordered meta keys for a field, highest tier first.
     *
     * @param string $field
     * @param string $object_type
     * @param array  $options
     * @return string[]
     */
    public static function keys($field, $object_type = self::TYPE_POST, array $options = []) {
        return array_keys(self::chain($field, $object_type, $options));
    }

    /**
     * Resolve the value that actually applies to an object.
     *
     * When 'include_otto' is not given, it is answered from the per-post
     * "Disable OTTO" toggle: with OTTO switched off its stored suggestion is
     * dead data, and serving it would leak SEO OTTO has stood down from.
     *
     * @param int    $object_id
     * @param string $field
     * @param string $object_type
     * @param array  $options
     * @return array{key:string,value:string,source:string} Empty strings when
     *               MetaSync holds nothing for this field.
     */
    public static function resolve($object_id, $field, $object_type = self::TYPE_POST, array $options = []) {
        $object_id = (int) $object_id;
        $empty     = ['key' => '', 'value' => '', 'source' => ''];

        if ($object_id <= 0) {
            return $empty;
        }

        if (!array_key_exists('include_otto', $options) && $object_type === self::TYPE_POST) {
            $options['include_otto'] = !self::otto_is_disabled($object_id);
        }

        foreach (self::chain($field, $object_type, $options) as $key => $source) {
            $value = self::read($object_id, $object_type, $key);

            if ($value !== '') {
                return ['key' => $key, 'value' => $value, 'source' => $source];
            }
        }

        return $empty;
    }

    /**
     * Whether a resolved result is OTTO's value, for provenance marking.
     *
     * The source label alone cannot answer this. The persisted tier
     * (_metasync_metatitle / _metasync_metadesc) is labelled SOURCE_METASYNC
     * because it is ALSO the legacy native field a customer could have typed
     * into directly — SEO Health depends on that distinction and deliberately
     * leaves such a value uncredited. What separates the two is OTTO's staging
     * key: the sync writes its text to both, so a persisted value that matches
     * the staging key came from OTTO, and one that does not was typed.
     *
     * Rendering needs the same answer, so the test lives here once rather than
     * being restated by every emitter that stamps a data-metasync-* marker.
     *
     * @param int   $object_id
     * @param string $field    FIELD_TITLE or FIELD_DESCRIPTION.
     * @param array  $resolved A resolve() result.
     * @return bool
     */
    public static function is_otto_value($object_id, $field, array $resolved) {
        if (empty($resolved['key']) || !isset($resolved['source'])) {
            return false;
        }

        // The volatile tier is unambiguous.
        if ($resolved['source'] === self::SOURCE_OTTO) {
            return true;
        }

        // Only the persisted tier is ambiguous, and only on a post: on a term
        // the same key holds a deliberately-set value that is not OTTO's.
        $persisted = $field === self::FIELD_TITLE
            ? self::KEY_PERSISTED_OTTO_TITLE
            : self::KEY_PERSISTED_OTTO_DESC;

        if ($resolved['key'] !== $persisted) {
            return false;
        }

        $staging = $field === self::FIELD_TITLE
            ? '_metasync_otto_title'
            : '_metasync_otto_description';

        $staged = self::read((int) $object_id, self::TYPE_POST, $staging);

        return $staged !== '' && $staged === $resolved['value'];
    }

    /**
     * The value that applies, or '' when MetaSync holds nothing.
     *
     * @param int    $object_id
     * @param string $field
     * @param string $object_type
     * @param array  $options
     * @return string
     */
    public static function value($object_id, $field, $object_type = self::TYPE_POST, array $options = []) {
        $resolved = self::resolve($object_id, $field, $object_type, $options);

        return $resolved['value'];
    }

    /**
     * The value that would apply if the customer had not set one.
     *
     * This is the greyed-out placeholder the editing surfaces show: OTTO's
     * suggestion, or the imported value where OTTO is silent. It is what the
     * page will actually render once the field is left blank, so it belongs on
     * the same chain rather than being assembled per screen.
     *
     * @param int    $object_id
     * @param string $field
     * @param string $object_type
     * @return array{key:string,value:string,source:string}
     */
    public static function fallback($object_id, $field, $object_type = self::TYPE_POST) {
        $empty = ['key' => '', 'value' => '', 'source' => ''];

        $object_id = (int) $object_id;
        if ($object_id <= 0) {
            return $empty;
        }

        $chain = self::chain($field, $object_type);

        // Drop the tier the customer owns and resolve the rest. It is not
        // always the first one: under "prioritize OTTO" the custom key is
        // demoted below OTTO's, so shifting blindly would drop OTTO's value and
        // offer the customer their own text back as the placeholder for what
        // renders when they clear the field.
        $custom_key = self::custom_key_for($field, $object_type);

        if ($custom_key !== '' && array_key_exists($custom_key, $chain)) {
            unset($chain[$custom_key]);
        } else {
            array_shift($chain);
        }

        if (empty($chain)) {
            return $empty;
        }

        $include_otto = $object_type !== self::TYPE_POST || !self::otto_is_disabled($object_id);

        // Only a post can have OTTO switched off, so reaching this with
        // $include_otto false already means TYPE_POST — no need to re-check it.
        foreach ($chain as $key => $source) {
            if (!$include_otto && $source !== self::SOURCE_IMPORTED) {
                continue;
            }

            $value = self::read($object_id, $object_type, $key);
            if ($value !== '') {
                return ['key' => $key, 'value' => $value, 'source' => $source];
            }
        }

        return $empty;
    }

    /**
     * The key holding the customer's own value for a field, or '' when the
     * field has no such tier.
     *
     * Only posts have one: a term's top tier is the deliberately-set
     * _metasync_metatitle, which fallback() already treats as shiftable.
     *
     * @param string $field
     * @param string $object_type
     * @return string
     */
    private static function custom_key_for($field, $object_type) {
        if ($object_type !== self::TYPE_POST) {
            return '';
        }

        if ($field === self::FIELD_TITLE) {
            return self::KEY_CUSTOM_TITLE;
        }

        if ($field === self::FIELD_DESCRIPTION) {
            return self::KEY_CUSTOM_DESC;
        }

        return '';
    }

    /**
     * Read one meta value for either object type.
     *
     * @param int    $object_id
     * @param string $object_type
     * @param string $key
     * @return string '' when unset or not a string.
     */
    private static function read($object_id, $object_type, $key) {
        $value = $object_type === self::TYPE_TERM
            ? get_term_meta($object_id, $key, true)
            : get_post_meta($object_id, $key, true);

        if (empty($value) || !is_string($value)) {
            return '';
        }

        return self::strip_placeholder($object_id, $object_type, $key, $value);
    }

    /**
     * Collapse stored values that only echo what the meta box pre-filled.
     *
     * Two shapes of that, both on the social keys the meta box owns:
     *
     *   - the "Auto Draft" placeholder WordPress gives a still-untitled post,
     *     which the pre-fill persisted verbatim; and
     *   - a social title that is a verbatim snapshot of the post title as it
     *     was on save day — stale the moment the post is renamed, and
     *     indistinguishable from a typed title by content alone.
     *
     * Either would stop the walk at the tier that holds it, putting a value
     * the customer never chose above OTTO's — the inversion this class exists
     * to prevent. Collapsing to '' here, rather than at each consumer, keeps
     * one chain that every call site sees the same way.
     *
     * Posts only: the prone keys are meta box keys and terms carry none of them.
     *
     * method_exists (not just class_exists) because this runs on front-end requests
     * and a partially updated install can pair a newer includes/ file with an older
     * class-metasync-opengraph.php, where calling a method the loaded class doesn't
     * define would fatal the page rather than degrade.
     *
     * @param int    $object_id
     * @param string $object_type
     * @param string $key
     * @param string $value
     * @return string
     */
    private static function strip_placeholder($object_id, $object_type, $key, $value) {
        if ($object_type !== self::TYPE_POST) {
            return $value;
        }

        // @phpstan-ignore-next-line function.alreadyNarrowedType
        if (method_exists('Metasync_OpenGraph', 'strip_auto_draft_title')
            && defined('Metasync_OpenGraph::AUTO_DRAFT_PRONE_KEYS')
            && in_array($key, Metasync_OpenGraph::AUTO_DRAFT_PRONE_KEYS, true)
        ) {
            $value = Metasync_OpenGraph::strip_auto_draft_title($value);
        }

        // @phpstan-ignore-next-line function.alreadyNarrowedType
        if (method_exists('Metasync_OpenGraph', 'strip_title_snapshot')
            && defined('Metasync_OpenGraph::TITLE_DEFAULTED_KEYS')
            && in_array($key, Metasync_OpenGraph::TITLE_DEFAULTED_KEYS, true)
        ) {
            $value = Metasync_OpenGraph::strip_title_snapshot($object_id, $key, $value);
        }

        // @phpstan-ignore-next-line function.alreadyNarrowedType
        if (method_exists('Metasync_OpenGraph', 'strip_description_snapshot')
            && defined('Metasync_OpenGraph::DESCRIPTION_DEFAULTED_KEYS')
            && in_array($key, Metasync_OpenGraph::DESCRIPTION_DEFAULTED_KEYS, true)
        ) {
            $value = Metasync_OpenGraph::strip_description_snapshot($object_id, $key, $value);
        }

        return $value;
    }

    /**
     * Whether the per-post "Disable OTTO" toggle is set.
     *
     * @param int $post_id
     * @return bool
     */
    private static function otto_is_disabled($post_id) {
        return class_exists('Metasync_Otto_Frontend_Toolbar')
            && Metasync_Otto_Frontend_Toolbar::is_otto_disabled((int) $post_id);
    }
}
