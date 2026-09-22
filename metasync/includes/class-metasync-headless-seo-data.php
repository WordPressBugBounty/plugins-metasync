<?php
/**
 * A resolved SEO payload for one WordPress object.
 *
 * This is the object every headless delivery surface hands back. It is
 * deliberately inert: the resolution work happens once, in
 * Metasync_Headless_Seo_Surface, and what lands here is the finished value for
 * every field. Getters read that state and nothing else.
 *
 * That split is the point. A field-by-field design — one function per field,
 * each re-walking the fallback chain — costs a meta lookup per field and, worse,
 * lets two fields disagree: a title resolved from OTTO alongside a canonical
 * resolved from Yoast, because each looked independently. Resolving once and
 * reading many times makes the payload internally consistent by construction,
 * and matches how a GraphQL server resolves a type: parent once, then fields off
 * the parent.
 *
 * Every value is already a string (or, for schema, decoded JSON), so a delivery
 * layer never has to know which storage a value came from.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

if (!defined('ABSPATH')) {
    exit; # Exit if accessed directly
}

class Metasync_Headless_Seo_Data
{
    /**
     * Resolved values, keyed by field name. Always complete — see fields().
     *
     * @var array
     */
    private $values;

    /**
     * Every field this object carries, with the empty value for its type.
     *
     * A caller can rely on every key being present, so a delivery layer never
     * has to distinguish "field missing" from "field empty". Nothing here is
     * nullable: a missing string is '', a missing schema is an empty array.
     *
     * @return array
     */
    public static function fields()
    {
        return array(
            # Core meta.
            'title'                 => '',
            'description'           => '',
            'keywords'              => '',
            'canonical'             => '',
            'robots'                => '',

            # Open Graph.
            'og_title'              => '',
            'og_description'        => '',
            'og_image'              => '',
            'og_url'                => '',
            'og_type'               => '',
            'og_site_name'          => '',
            'og_locale'             => '',

            # Twitter.
            'twitter_card'          => '',
            'twitter_title'         => '',
            'twitter_description'   => '',
            'twitter_image'         => '',

            # Structured data, decoded so a consumer does not have to parse a
            # string that came out of post meta.
            'schema'                => array(),

            # Where the frontend serves this object.
            'public_url'            => '',

            # What this payload describes. Object type and id only — enough for a
            # frontend to key a cache, and nothing a public endpoint should not
            # be handing out.
            'object_type'           => '',
            'object_id'             => 0,
            'object_sub_type'       => '',
        );
    }

    /**
     * @param array $values Resolved values; missing keys fall back to the empty
     *                      value for their field, unknown keys are dropped.
     */
    public function __construct(array $values = array())
    {
        $this->values = array();

        foreach (self::fields() as $field => $empty) {
            $this->values[$field] = array_key_exists($field, $values) ? $values[$field] : $empty;
        }
    }

    /**
     * One resolved field.
     *
     * @param string $field Field name from fields().
     * @return mixed The resolved value, or null when the field does not exist.
     */
    public function get($field)
    {
        return array_key_exists($field, $this->values) ? $this->values[$field] : null;
    }

    /**
     * Every resolved field.
     *
     * @return array
     */
    public function to_array()
    {
        return $this->values;
    }

    /* -----------------------------------------------------------------
     *  Named getters. Thin readers over already-resolved state — none of
     *  them consults post meta or re-runs a fallback chain.
     * ----------------------------------------------------------------- */

    /** @return string */
    public function get_title()
    {
        return (string) $this->values['title'];
    }

    /** @return string */
    public function get_description()
    {
        return (string) $this->values['description'];
    }

    /** @return string */
    public function get_keywords()
    {
        return (string) $this->values['keywords'];
    }

    /** @return string */
    public function get_canonical()
    {
        return (string) $this->values['canonical'];
    }

    /** @return string */
    public function get_robots()
    {
        return (string) $this->values['robots'];
    }

    /** @return string */
    public function get_og_title()
    {
        return (string) $this->values['og_title'];
    }

    /** @return string */
    public function get_og_description()
    {
        return (string) $this->values['og_description'];
    }

    /** @return string */
    public function get_og_image()
    {
        return (string) $this->values['og_image'];
    }

    /** @return string */
    public function get_og_url()
    {
        return (string) $this->values['og_url'];
    }

    /** @return string */
    public function get_twitter_title()
    {
        return (string) $this->values['twitter_title'];
    }

    /** @return string */
    public function get_twitter_description()
    {
        return (string) $this->values['twitter_description'];
    }

    /** @return string */
    public function get_twitter_image()
    {
        return (string) $this->values['twitter_image'];
    }

    /**
     * Decoded JSON-LD graph.
     *
     * @return array
     */
    public function get_schema()
    {
        return is_array($this->values['schema']) ? $this->values['schema'] : array();
    }

    /** @return string */
    public function get_public_url()
    {
        return (string) $this->values['public_url'];
    }
}
