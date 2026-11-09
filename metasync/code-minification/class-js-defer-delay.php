<?php
/**
 * Metasync_JS_Defer_Delay
 * Orchestrates JS defer/async/delay for non-essential scripts.
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.5.23
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_JS_Defer_Delay {

    /** @var array */
    private $settings;

    /** @var array Handles that must never be deferred or delayed. */
    private $defer_excluded_handles = [];

    /** @var array Explicit handles to delay. */
    private $delay_handles = [];

    /** @var array URL patterns that trigger delay. */
    private $delay_patterns = [];

    /** @var bool Whether the delay loader has been enqueued. */
    private $loader_enqueued = false;

    /** @var bool Whether JS delay is active for this request. */
    private $has_delayed_scripts = false;

    /** @var array Handles never to defer/delay (core + OTTO). */
    private static $system_excluded = [
        'jquery',
        'jquery-core',
        'jquery-migrate',
        'wp-i18n',
        'wp-hooks',
        'wp-element',
        'wp-polyfill',
        'admin-bar',
        'metasync-tracker',
        'metasync',
    ];

    public function __construct(array $settings) {
        $this->settings = $settings;

        // Parse defer exclusions
        $user_excludes = array_filter(
            array_map('trim', explode(',', $settings['js_defer_exclude_handles'] ?? ''))
        );
        $this->defer_excluded_handles = array_unique(array_merge(self::$system_excluded, $user_excludes));

        // Parse delay handles
        $this->delay_handles = array_filter(
            array_map('trim', explode(',', $settings['js_delay_handles'] ?? ''))
        );

        // Parse delay URL patterns
        $this->delay_patterns = array_filter(
            array_map('trim', explode(',', $settings['js_delay_patterns'] ?? ''))
        );

        if (!is_admin()) {
            // Priority 20: after OTTO defer at priority 10
            add_filter('script_loader_tag', [$this, 'process_script_tag'], 20, 3);
            add_action('wp_footer', [$this, 'maybe_output_loader'], 999);
        }
    }

    /**
     * Process each script tag for defer or delay.
     *
     * @param string $tag    The script tag.
     * @param string $handle The script handle.
     * @param string $src    The script source URL.
     * @return string Modified script tag.
     */
    public function process_script_tag(string $tag, string $handle, string $src): string {
        // Never modify excluded handles
        if ($this->is_excluded($handle)) {
            return $tag;
        }

        // Check if this script should be delayed
        if ($this->should_delay($handle, $src)) {
            return $this->delay_script($tag, $src);
        }

        // Otherwise, defer if enabled
        if (!empty($this->settings['enable_js_defer'])) {
            return $this->defer_script($tag);
        }

        return $tag;
    }

    /**
     * Add defer attribute to a script tag.
     */
    private function defer_script(string $tag): string {
        // Don't add if already has defer or async
        if (strpos($tag, 'defer') !== false || strpos($tag, 'async') !== false) {
            return $tag;
        }

        return str_replace(' src=', ' defer src=', $tag);
    }

    /**
     * Convert a script tag to a delayed script.
     * Replaces type with metasync/delayed and src with data-metasync-src.
     * The original type attribute (e.g. "module") is stashed as
     * data-metasync-type so the delay loader can restore it, and every
     * other attribute (nonce, integrity, crossorigin, async, data-*)
     * is left in place for the loader to copy over.
     *
     * WordPress passes inline before/after scripts, translations and the
     * main tag through the filter as one concatenated string, so BOTH
     * rewrites (type placeholder and src rename) are scoped to the
     * script open tag that actually carries this script's src attribute.
     */
    private function delay_script(string $tag, string $src): string {
        $this->has_delayed_scripts = true;

        // Walk top-level script elements only (open tag + body + close).
        // A script-open-tag string inside an inline block's JavaScript is
        // part of that block's body and must never become a candidate of
        // its own.
        if (!preg_match_all('~<script\b.*?</script>~is', $tag, $elements, PREG_OFFSET_CAPTURE)) {
            return $tag;
        }

        // WP reports $src with raw ampersands while the tag markup carries
        // them entity-encoded (esc_url emits &#038; before 6.x-era builds
        // and htmlspecialchars-style &amp; in others), so match every
        // spelling.
        $src_pattern = '~[\s/]src\s*=\s*(["\'])(' . implode('|', array_map(static function ($v) {
            return preg_quote($v, '~');
        }, $this->src_spellings($src))) . ')\1~';

        $open = null;
        $src_carrier = null;
        foreach ($elements[0] as $element) {
            // Quote-aware open tag at the element start: quoted attribute
            // values may legally contain > characters.
            if (!preg_match('~^<script\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~i', $element[0], $om)) {
                continue;
            }
            // A real open tag never contains a closing tag; one that does
            // is a dangling quote swallowing the element's closer.
            if (strpos($om[0], '</script') !== false) {
                continue;
            }
            if (preg_match($src_pattern, $om[0])) {
                $open = [$om[0], $element[1]];
                break;
            }
            // Remember the last candidate carrying any src attribute at
            // all. Third-party filters sometimes rewrite the URL, and the
            // external tag is still the only candidate with a src while
            // inline before/after blocks carry none.
            if (preg_match('~[\s/]src\s*=~i', $om[0])) {
                $src_carrier = [$om[0], $element[1]];
            }
        }
        // Fallback when no candidate carries $src in any spelling: the
        // last candidate with any src attribute (a rewritten URL). When
        // no candidate carries a src at all, delaying is meaningless for
        // a loader keyed on data-metasync-src: leave the markup untouched
        // rather than placeholder-izing an inline block whose code the
        // loader would destroy (it copies attributes, never text).
        if ($open === null) {
            $open = $src_carrier;
        }
        if ($open === null) {
            return $tag;
        }

        $new_open = $this->stash_type_in_open_tag($open[0]);
        $new_open = $this->rename_src_in_open_tag($new_open, $src);

        return substr_replace($tag, $new_open, $open[1], strlen($open[0]));
    }

    /**
     * Spellings of a src URL as it may appear in tag markup.
     *
     * @param string $src Source URL as reported by WordPress.
     * @return string[] Raw, htmlspecialchars-escaped and esc_url (&#038;) variants.
     */
    private function src_spellings(string $src): array {
        return array_values(array_unique([
            $src,
            htmlspecialchars($src, ENT_QUOTES),
            str_replace('&', '&#038;', $src),
        ]));
    }

    /**
     * Rename src to data-metasync-src within ONE open tag. Returns the
     * open tag unchanged when the src cannot be matched in any spelling,
     * so the placeholder keeps a live src and the loader's attribute
     * copy still delivers it.
     *
     * @param string $open The script open tag markup.
     * @param string $src Source URL as reported by WordPress.
     * @return string Rewritten open tag.
     */
    private function rename_src_in_open_tag(string $open, string $src): string {
        foreach ($this->src_spellings($src) as $spelling) {
            // The [\s/] boundary keeps data-src= and friends intact while
            // still catching defer/src= spellings.
            $out = preg_replace(
                '~([\s/])src\s*=\s*(["\'])(' . preg_quote($spelling, '~') . ')\2~',
                '$1data-metasync-src=$2$3$2',
                $open,
                1
            );
            if (is_string($out) && strpos($out, 'data-metasync-src') !== false) {
                return $out;
            }
        }
        return $open;
    }

    /**
     * Within a single <script ...> open tag, stash the existing type
     * attribute (if any) as data-metasync-type and set the delayed
     * placeholder type. Attributes are tokenized as name/value units so
     * attribute VALUES that merely contain "type=" are never rewritten,
     * and quoted values may safely contain newlines and > characters.
     *
     * @param string $open The script open tag markup.
     * @return string Rewritten open tag.
     */
    private function stash_type_in_open_tag(string $open): string {
        if (!preg_match_all('~([^\s=/>"\'<]+)(\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?~', $open, $attrs, PREG_OFFSET_CAPTURE)) {
            return $open;
        }

        foreach ($attrs[0] as $i => $match) {
            if (strtolower($attrs[1][$i][0]) !== 'type' || empty($attrs[2][$i][0])) {
                continue;
            }
            $value = $attrs[3][$i][0];
            // Strip the surrounding quotes from quoted values.
            if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
                $value = substr($value, 1, -1);
            } elseif (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
                $value = substr($value, 1, -1);
            }
            $stashed = 'data-metasync-type="' . esc_attr($value) . '" type="metasync/delayed"';
            // Replace only this attribute's span, not every textual
            // occurrence of it across the tag.
            return substr_replace($open, $stashed, $match[1], strlen($match[0]));
        }

        // No type attribute: add the placeholder type right after <script.
        return preg_replace('/^<script\b/i', '<script type="metasync/delayed"', $open, 1);
    }

    /**
     * Output the delay loader script if we have delayed scripts.
     */
    public function maybe_output_loader(): void {
        if (!$this->has_delayed_scripts || $this->loader_enqueued) {
            return;
        }

        $this->loader_enqueued = true;
        ?>
<script id="metasync-delay-loader">
(function(){
    var loaded=false;
    var events=['mousemove','scroll','keydown','touchstart','click'];
    function load(){
        if(loaded)return;
        loaded=true;
        events.forEach(function(e){document.removeEventListener(e,load);});
        document.querySelectorAll('script[type="metasync/delayed"]').forEach(function(el){
            var s=document.createElement('script');
            var origType=el.getAttribute('data-metasync-type');
            var src=el.getAttribute('data-metasync-src');
            for(var i=0;i<el.attributes.length;i++){
                var attr=el.attributes[i];
                if(attr.name==='type'||attr.name==='data-metasync-type'||attr.name==='data-metasync-src')continue;
                s.setAttribute(attr.name,attr.value);
            }
            if(src!==null){s.setAttribute('src',src);}
            if(origType!==null){s.setAttribute('type',origType);}
            // Browsers scrub the nonce content attribute after parse; the
            // nonce property still exposes it, so re-assign from there.
            if(el.nonce){s.nonce=el.nonce;}
            // Set defer only for classic scripts that had no async. Note
            // dynamically-inserted scripts execute in fetch-completion
            // order regardless; defer is kept for tag parity.
            if(!s.hasAttribute('async')&&origType!=='module'){s.defer=true;}
            el.parentNode.replaceChild(s,el);
        });
    }
    events.forEach(function(e){document.addEventListener(e,load,{once:true,passive:true});});
    setTimeout(load,5000);
})();
</script>
        <?php
    }

    /**
     * Check if a handle is excluded from defer/delay.
     */
    private function is_excluded(string $handle): bool {
        return in_array($handle, $this->defer_excluded_handles, true);
    }

    /**
     * Check if a script should be delayed (not just deferred).
     */
    private function should_delay(string $handle, string $src): bool {
        if (empty($this->settings['enable_js_delay'])) {
            return false;
        }

        // Check explicit delay handles
        if (in_array($handle, $this->delay_handles, true)) {
            return true;
        }

        // Check URL pattern matches
        if (!empty($src)) {
            foreach ($this->delay_patterns as $pattern) {
                if (!empty($pattern) && strpos($src, $pattern) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}
