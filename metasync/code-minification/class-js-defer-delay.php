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
     */
    private function delay_script(string $tag, string $src): string {
        $this->has_delayed_scripts = true;

        // Replace type attribute or add one
        if (preg_match('/type\s*=\s*["\'][^"\']*["\']/', $tag)) {
            $tag = preg_replace('/type\s*=\s*["\'][^"\']*["\']/', 'type="metasync/delayed"', $tag);
        } else {
            $tag = str_replace('<script ', '<script type="metasync/delayed" ', $tag);
        }

        // Replace src with data-metasync-src
        $tag = preg_replace(
            '/\ssrc\s*=\s*(["\'])(' . preg_quote($src, '/') . ')\1/',
            ' data-metasync-src=$1$2$1',
            $tag
        );

        // Fallback: simpler replacement
        if (strpos($tag, 'data-metasync-src') === false) {
            $tag = str_replace("src='" . $src . "'", "data-metasync-src='" . $src . "'", $tag);
            $tag = str_replace('src="' . $src . '"', 'data-metasync-src="' . $src . '"', $tag);
        }

        return $tag;
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
            s.src=el.getAttribute('data-metasync-src');
            if(el.id)s.id=el.id;
            s.defer=true;
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
