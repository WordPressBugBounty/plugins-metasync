<?php

/**
 * Oxygen Builder Compatibility – auto re-signs [oxygen] dynamic shortcodes
 * when their HMAC signatures are invalid (e.g. after design-set import or migration).
 *
 * Runs once on `admin_init`. Skips entirely when Oxygen is inactive or signature
 * validation is disabled. Uses a two-tier fingerprint so it only re-processes when
 * the private key or template content actually changes.
 *
 * Uses WordPress shortcode_parse_atts() to correctly parse ALL shortcode attributes
 * (data, format, size, taxonomy, separator, etc.) — matching Oxygen's own signing
 * approach in ct_sign_oxy_dynamic_shortcode().
 *
 * @package MetaSync
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Oxygen_Compat
{
    const OPTION_KEY = 'metasync_oxygen_signatures_hash';

    /**
     * Regex: captures the full inner attribute string of any [oxygen …] shortcode.
     * Works for both normal quotes and backslash-escaped quotes (\') used inside
     * ct_options JSON in _ct_builder_shortcodes.
     */
    const OXY_PATTERN = '/\[oxygen\s+([^\]]+)\]/';

    /**
     * Entry point – hooked to admin_init.
     */
    public static function maybe_resign_shortcodes()
    {
        if (!self::should_run()) {
            return;
        }

        // Tier 1: lightweight check — private key + template count + latest modification.
        // Avoids loading all JSON blobs on every admin page load.
        $light_fp = self::build_light_fingerprint();
        $stored   = get_option(self::OPTION_KEY, '');

        if (!empty($stored) && strpos($stored, '|') !== false) {
            list($stored_light) = explode('|', $stored, 2);
            if ($stored_light === $light_fp) {
                return; // Nothing changed since last run
            }
        }

        // Tier 2: full check — load JSON, verify signatures, re-sign if needed.
        $full_fp = self::build_full_fingerprint();
        if (!empty($stored)) {
            list(, $stored_full) = array_pad(explode('|', $stored, 2), 2, '');
            if ($stored_full === $full_fp) {
                // Templates changed (e.g. CSS edit) but signatures are still valid.
                // Update tier-1 to reflect the new lightweight fingerprint.
                update_option(self::OPTION_KEY, $light_fp . '|' . $full_fp, true);
                return;
            }
        }

        $updated = self::resign_templates();

        if ($updated > 0) {
            error_log("MetaSync Oxygen Compat: Re-signed shortcodes in {$updated} template(s).");
            $full_fp = self::build_full_fingerprint(); // Recompute after DB writes
        }

        update_option(self::OPTION_KEY, $light_fp . '|' . $full_fp, true);
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    private static function should_run()
    {
        if (!class_exists('OXYGEN_VSB_Signature')) {
            return false;
        }

        $enabled = get_option('oxygen_vsb_enable_signature_validation');
        return $enabled && $enabled !== 'false';
    }

    /**
     * Get or create an Oxygen signature helper.
     */
    private static function get_signer()
    {
        global $oxygen_signature;

        if (isset($oxygen_signature) && $oxygen_signature instanceof OXYGEN_VSB_Signature) {
            return $oxygen_signature;
        }

        if (class_exists('OXYGEN_VSB_Signature')) {
            return new OXYGEN_VSB_Signature();
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Fingerprints
    // ------------------------------------------------------------------

    /**
     * Tier 1: lightweight fingerprint — private key + template count + latest modification.
     * No JSON blobs loaded; just a single aggregate query.
     */
    private static function build_light_fingerprint()
    {
        global $wpdb;

        $key = get_option('oxygen_private_key', '');

        $row = $wpdb->get_row(
            "SELECT COUNT(*) AS cnt, MAX(p.post_modified_gmt) AS latest
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_ct_builder_json'
             WHERE p.post_type = 'ct_template'
               AND p.post_status = 'publish'"
        );

        $cnt    = $row ? $row->cnt : 0;
        $latest = $row ? $row->latest : '';

        return hash('sha256', $key . '|' . $cnt . '|' . $latest);
    }

    /**
     * Tier 2: full fingerprint — SHA-256 of private key + all template JSON content.
     * Only called when the lightweight check detects a change.
     */
    private static function build_full_fingerprint()
    {
        global $wpdb;

        $key = get_option('oxygen_private_key', '');

        $json_rows = $wpdb->get_col(
            "SELECT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'ct_template'
               AND p.post_status = 'publish'
               AND pm.meta_key = '_ct_builder_json'
             ORDER BY pm.post_id ASC"
        );

        return hash('sha256', $key . implode('|', $json_rows));
    }

    // ------------------------------------------------------------------
    // Re-sign
    // ------------------------------------------------------------------

    private static function resign_templates()
    {
        global $wpdb;

        $signer = self::get_signer();
        if (!$signer) {
            return 0;
        }

        $template_ids = $wpdb->get_col(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'ct_template'
               AND p.post_status = 'publish'
             ORDER BY p.ID ASC"
        );

        if (empty($template_ids)) {
            return 0;
        }

        $updated = 0;

        foreach ($template_ids as $tpl_id) {
            $changed = false;

            // _ct_builder_json — primary rendering source
            $json = get_post_meta($tpl_id, '_ct_builder_json', true);
            if (!empty($json)) {
                $new_json = self::resign_oxygen_in_string($json, $signer);
                if ($new_json !== $json) {
                    update_post_meta($tpl_id, '_ct_builder_json', wp_slash($new_json));
                    $changed = true;
                }
            }

            // _ct_builder_shortcodes — secondary format
            $sc = get_post_meta($tpl_id, '_ct_builder_shortcodes', true);
            if (!empty($sc)) {
                $new_sc = self::resign_oxygen_in_string($sc, $signer);
                if ($new_sc !== $sc) {
                    update_post_meta($tpl_id, '_ct_builder_shortcodes', wp_slash($new_sc));
                    $changed = true;
                }
            }

            if ($changed) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Find every [oxygen …] shortcode in a string and re-sign any whose
     * signature doesn't match the current private key. Preserves ALL attributes.
     *
     * Handles both normal quotes: data='title'
     * and escaped quotes:        data=\'title\'
     *
     * @param string               $content Raw meta value.
     * @param OXYGEN_VSB_Signature $signer  Oxygen's signature helper.
     * @return string              Content with corrected signatures.
     */
    private static function resign_oxygen_in_string($content, $signer)
    {
        return preg_replace_callback(self::OXY_PATTERN, function ($match) use ($signer) {
            $inner = $match[1];

            // Detect whether this shortcode uses escaped quotes (\')
            $uses_escaped_quotes = strpos($inner, "\\'") !== false;

            // Normalize escaped quotes for shortcode_parse_atts()
            $normalized = $uses_escaped_quotes ? str_replace("\\'", "'", $inner) : $inner;
            $attr = shortcode_parse_atts(trim($normalized));

            if (!is_array($attr) || empty($attr['ct_sign_sha256'])) {
                return $match[0]; // Not a signed oxygen shortcode, leave as-is
            }

            $stored_sig = $attr['ct_sign_sha256'];

            // Build the attributes WITHOUT the signature for verification
            $attr_without_sig = $attr;
            unset($attr_without_sig['ct_sign_sha256']);

            $expected = $signer->generate_signature('oxygen', $attr_without_sig, null);

            // If signature is already valid, skip
            if (hash_equals($expected, $stored_sig)) {
                return $match[0];
            }

            // Rebuild the shortcode with the correct signature + all original attributes
            $q = $uses_escaped_quotes ? "\\'" : "'";
            $parts = "ct_sign_sha256={$q}{$expected}{$q}";
            foreach ($attr_without_sig as $key => $val) {
                $parts .= " {$key}={$q}{$val}{$q}";
            }

            return "[oxygen {$parts} ]";
        }, $content);
    }
}
