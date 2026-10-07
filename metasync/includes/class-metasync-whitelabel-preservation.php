<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralised white-label preservation helper.
 *
 * Both the Advanced "Clear All Settings" reset (Metasync_Debug_Manager) and the
 * separate "Reset Authentication" flow (Metasync_Connect_Manager) must clear
 * operational plugin data — API keys, authentication tokens, functional
 * settings, caches, logs, crawl data — without destroying the reseller
 * white-label identity stored in the main plugin option.
 *
 * The same extraction/restoration surface is also used by the ZIP export
 * (Metasync_Admin_Ajax::handle_export_whitelabel_settings) so that reset and
 * export can never drift apart: whatever the reset preserves is exactly what
 * the export serialises, and vice versa.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */
class Metasync_Whitelabel_Preservation
{
    /**
     * The general-option keys that carry white-label branding. These are the
     * only keys inside $options['general'] that are part of the white-label
     * identity and therefore the only ones the reset must spare.
     *
     * Kept in sync with the export handler and the plugin-file header map.
     */
    private static $general_whitelabel_keys = array(
        'white_label_plugin_name',
        'white_label_plugin_description',
        'white_label_plugin_author',
        'white_label_plugin_author_uri',
        'white_label_plugin_uri',
        'white_label_plugin_menu_slug',
        'white_label_plugin_menu_icon',
        'whitelabel_otto_name',
    );

    /**
     * Return the canonical list of general-option keys that carry white-label
     * branding. Exposed so tests and the export handler share one source.
     *
     * @return string[]
     */
    public static function general_whitelabel_keys()
    {
        return self::$general_whitelabel_keys;
    }

    /**
     * Capture the complete white-label identity from the live option store.
     *
     * Captures:
     *   - the full $options['whitelabel'] blob (logos, domains, custom OTTO
     *     name, visibility controls, access control, quick links, colour
     *     settings, password, recovery settings, …);
     *   - every general.* white-label branding key; and
     *   - the dedicated metasync_options_whitelabel_user option.
     *
     * The returned array is the single argument restore_whitelabel_state()
     * consumes; it is safe to call before a destructive reset and to keep
     * around for the duration of the request.
     *
     * @return array{whitelabel: array, general: array, whitelabel_user: mixed}
     */
    public static function extract_whitelabel_state()
    {
        $options = get_option(Metasync::option_name, array());
        $options = is_array($options) ? $options : array();

        $whitelabel = isset($options['whitelabel']) && is_array($options['whitelabel'])
            ? $options['whitelabel']
            : array();

        $general = isset($options['general']) && is_array($options['general'])
            ? $options['general']
            : array();

        $general_branding = array();
        foreach (self::$general_whitelabel_keys as $key) {
            if (array_key_exists($key, $general)) {
                $general_branding[$key] = $general[$key];
            }
        }

        $whitelabel_user = get_option(Metasync::option_name . '_whitelabel_user', null);

        return array(
            'whitelabel'      => $whitelabel,
            'general'         => $general_branding,
            'whitelabel_user' => $whitelabel_user,
        );
    }

    /**
     * Restore a previously captured white-label identity into the live option
     * store, using the recovery-lock / authorised-write pattern that the JSON
     * import and the password-recovery flow share.
     *
     * Writes back:
     *   - the whitelabel blob (merged over whatever the reset left behind);
     *   - the general branding keys; and
     *   - the dedicated whitelabel_user option.
     *
     * @param mixed $state A state array produced by extract_whitelabel_state().
     *                     Anything else fails the restore.
     * @return bool True on success, false if the option write did not stick.
     */
    public static function restore_whitelabel_state($state)
    {
        if (!is_array($state)) {
            return false;
        }

        $options = get_option(Metasync::option_name, array());
        if (!is_array($options)) {
            $options = array();
        }
        if (!isset($options['general']) || !is_array($options['general'])) {
            $options['general'] = array();
        }

        // Restore the whitelabel blob. Merge over whatever the reset left so a
        // partial reset cannot lose branding that was never targeted.
        $whitelabel = isset($state['whitelabel']) && is_array($state['whitelabel'])
            ? $state['whitelabel']
            : array();
        if (!empty($whitelabel)) {
            if (!isset($options['whitelabel']) || !is_array($options['whitelabel'])) {
                $options['whitelabel'] = array();
            }
            $options['whitelabel'] = array_merge($options['whitelabel'], $whitelabel);
            $options['whitelabel']['restored_at'] = time();

            // The settings password may arrive as plaintext (from a ZIP export
            // payload) or as the encrypted ciphertext captured before a reset.
            // encrypt_secret() short-circuits on an already-encrypted value, so
            // running it here is a no-op for the reset path and a re-encrypt for
            // the import path — the password is always stored encrypted at rest.
            if (!empty($options['whitelabel']['settings_password']) && is_string($options['whitelabel']['settings_password'])) {
                $options['whitelabel']['settings_password'] = Metasync::encrypt_secret($options['whitelabel']['settings_password']);
            }
        }

        // Restore the general branding keys.
        $general_branding = isset($state['general']) && is_array($state['general'])
            ? $state['general']
            : array();
        foreach ($general_branding as $key => $value) {
            $options['general'][$key] = $value;
        }

        // The whitelabel blob may carry a settings password. It is re-encrypted
        // above (a no-op when it was already the captured ciphertext), but the
        // recovery-protection filter still treats any unauthorised password
        // write as a conflict and swaps it back to the stored value. Mirror the
        // JSON import's persist path: take the lock and authorise the write for
        // the duration of the save.
        $carries_password = !empty($options['whitelabel']['settings_password']);
        $lock_owner = '';
        if ($carries_password) {
            if (!class_exists('Metasync_Admin_Ajax')) {
                require_once __DIR__ . '/class-metasync-admin-ajax.php';
            }
            if (!class_exists('Metasync_Settings_Registration')) {
                require_once __DIR__ . '/class-metasync-settings-registration.php';
            }
            if (!Metasync_Admin_Ajax::instance()->acquire_recovery_lock($lock_owner)) {
                // A recovery or settings save is mid-write; we cannot safely
                // restore the password-protected blob right now.
                return false;
            }
        }

        try {
            if ($carries_password) {
                Metasync_Settings_Registration::authorize_recovery_password_write(true);
            }
            update_option(Metasync::option_name, $options);
        } finally {
            if ($carries_password) {
                Metasync_Settings_Registration::authorize_recovery_password_write(false);
                Metasync_Admin_Ajax::instance()->release_recovery_lock($lock_owner);
            }
        }

        // Verify the write stuck. The recovery filter can still swap a password
        // back if the authorisation raced; compare the stored value.
        $stored = get_option(Metasync::option_name, array());
        if (!is_array($stored)) {
            return false;
        }

        // The whitelabel blob and every general branding key we restored must
        // be present in the stored option. A failed update_option leaves the
        // pre-restore value behind, so this catches a silent write failure.
        // The settings_password is intentionally re-encrypted during restore,
        // so it is verified separately below against the written value rather
        // than against the (possibly-plaintext) source value.
        if (!empty($whitelabel)) {
            if (!isset($stored['whitelabel']) || !is_array($stored['whitelabel'])) {
                return false;
            }
            foreach ($whitelabel as $key => $value) {
                if ($key === 'settings_password') {
                    continue;
                }
                if (!array_key_exists($key, $stored['whitelabel']) || $stored['whitelabel'][$key] !== $value) {
                    return false;
                }
            }
        }
        foreach ($general_branding as $key => $value) {
            if (!isset($stored['general']) || !array_key_exists($key, $stored['general']) || $stored['general'][$key] !== $value) {
                return false;
            }
        }

        if ($carries_password) {
            $stored_pw = $stored['whitelabel']['settings_password'] ?? '';
            $expected_pw = $options['whitelabel']['settings_password'] ?? '';
            if (!hash_equals((string) $expected_pw, (string) $stored_pw)) {
                return false;
            }
        }

        // Restore the dedicated whitelabel_user option.
        $whitelabel_user = array_key_exists('whitelabel_user', $state)
            ? $state['whitelabel_user']
            : null;
        if ($whitelabel_user !== null) {
            update_option(Metasync::option_name . '_whitelabel_user', $whitelabel_user);
        }

        return true;
    }

    /**
     * Build the white-label export payload shared by the ZIP export.
     *
     * The returned array has the exact shape the JSON importer consumes:
     *   - 'whitelabel_settings': the whitelabel blob, with the settings
     *     password exported as plaintext (so it can be re-encrypted on the
     *     destination site's salts);
     *   - 'general_settings': the general branding keys only.
     *
     * No API credentials (apikey / searchatlas_api_key / otto_pixel_uuid / …)
     * are ever serialised — only the keys in $general_whitelabel_keys and the
     * whitelabel blob are captured, so credentials cannot leak into the export.
     *
     * @return array{whitelabel_settings: array, general_settings: array}
     */
    public static function build_whitelabel_export_data()
    {
        $whitelabel_settings = Metasync::get_whitelabel_settings();

        // Export the settings password as plaintext (not the encrypted blob):
        // the encryption key derives from this site's salts, so the blob would
        // be undecryptable after import on a different site. The importer
        // re-encrypts it at rest with the destination site's salts.
        if (!empty($whitelabel_settings['settings_password'])) {
            $whitelabel_settings['settings_password'] = Metasync::get_whitelabel_password();
        }

        $general_settings = Metasync::get_option('general');
        $general_settings = is_array($general_settings) ? $general_settings : array();

        $whitelabel_related_general = array();
        foreach (self::$general_whitelabel_keys as $key) {
            if (array_key_exists($key, $general_settings)) {
                $whitelabel_related_general[$key] = $general_settings[$key];
            }
        }

        return array(
            'whitelabel_settings' => $whitelabel_settings,
            'general_settings'   => $whitelabel_related_general,
        );
    }
}
