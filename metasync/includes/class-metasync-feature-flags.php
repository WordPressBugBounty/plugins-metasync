<?php

/**
 * Resolves the "Post/Page Editor Settings" disable switches.
 *
 * These six settings are presented to the user as "Disable <X> Meta Box". They
 * hide the editor meta box, and they also switch off the matching MetaSync
 * front-end behaviour: a disabled feature must not emit its own tags, and must
 * equally stop suppressing WordPress core or a third-party SEO plugin's tags.
 * Suppressing without emitting would strip the page of the tag altogether,
 * which is worse than either extreme.
 *
 * Nothing here deletes stored configuration. A disabled feature keeps its saved
 * post meta and options untouched, so re-enabling it restores the previous
 * behaviour exactly.
 *
 * @link       https://searchatlas.com
 * @since      2.7.0
 * @package    Metasync
 * @subpackage Metasync/includes
 * @author     Engineering Team <support@searchatlas.com>
 */

// Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

class Metasync_Feature_Flags
{
	/**
	 * Feature identifiers. Callers pass these rather than raw option keys so the
	 * stored key names stay an implementation detail of this class.
	 */
	const COMMON_ROBOTS  = 'common_robots';
	const ADVANCE_ROBOTS = 'advance_robots';
	const REDIRECTION    = 'redirection';
	const CANONICAL      = 'canonical';
	const SOCIAL_OG      = 'social_opengraph';
	const SCHEMA         = 'schema_markup';

	/**
	 * Maps each feature to the key it is stored under in the `general` option
	 * group. The option name still carries the historical "_metabox" suffix
	 * because the checkbox started life as an editor-only toggle.
	 *
	 * @var array<string, string>
	 */
	private static $option_keys = [
		self::COMMON_ROBOTS  => 'disable_common_robots_metabox',
		self::ADVANCE_ROBOTS => 'disable_advance_robots_metabox',
		self::REDIRECTION    => 'disable_redirection_metabox',
		self::CANONICAL      => 'disable_canonical_metabox',
		self::SOCIAL_OG      => 'disable_social_opengraph_metabox',
		self::SCHEMA         => 'disable_schema_markup_metabox',
	];

	/**
	 * Per-request memo of resolved values, keyed by feature.
	 *
	 * The OTTO output buffer re-reads these while walking the DOM, so the same
	 * flag can be consulted dozens of times in one render.
	 *
	 * Keyed "<blog id>:<feature>" so a switch_to_blog() loop cannot answer for
	 * the wrong site.
	 *
	 * @var array<string, bool>
	 */
	private static $memo = [];

	/**
	 * True when the feature has been switched off by the user.
	 *
	 * An unknown feature name is treated as enabled: a typo must never silently
	 * disable SEO output.
	 *
	 * @param  string $feature One of the class constants.
	 * @return bool
	 */
	public static function is_disabled($feature)
	{
		if (!isset(self::$option_keys[$feature])) {
			return false;
		}

		$key = self::memo_key($feature);
		if (!isset(self::$memo[$key])) {
			self::$memo[$key] = self::read($feature);
		}

		return self::$memo[$key];
	}

	/**
	 * Memo key for the current site.
	 *
	 * On multisite a switch_to_blog() loop reads a different site's options
	 * through the same static, so the blog id has to be part of the key or one
	 * site's settings would answer for another's.
	 *
	 * @param  string $feature One of the class constants.
	 * @return string
	 */
	private static function memo_key($feature)
	{
		$blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;

		return $blog_id . ':' . $feature;
	}

	/**
	 * Convenience inverse of is_disabled().
	 *
	 * @param  string $feature One of the class constants.
	 * @return bool
	 */
	public static function is_enabled($feature)
	{
		return !self::is_disabled($feature);
	}

	/**
	 * True when both robots features are off, i.e. MetaSync should leave the
	 * robots meta tag entirely alone — including core's own `wp_robots` output.
	 *
	 * Common and advanced directives share a single `<meta name="robots">` tag,
	 * so the tag is only surrendered when neither half wants to write to it.
	 *
	 * @return bool
	 */
	public static function robots_fully_disabled()
	{
		return self::is_disabled(self::COMMON_ROBOTS)
			&& self::is_disabled(self::ADVANCE_ROBOTS);
	}

	/**
	 * Reads the raw stored value for a feature.
	 *
	 * The class_exists() guard matters because this file is required directly
	 * from the plugin bootstrap, which runs before the main Metasync class is
	 * autoloaded; treating that window as "enabled" preserves current output.
	 *
	 * @param  string $feature One of the class constants.
	 * @return bool
	 */
	private static function read($feature)
	{
		if (!class_exists('Metasync')) {
			return false;
		}

		$general = Metasync::get_option('general', []);
		if (!is_array($general)) {
			return false;
		}

		return !empty($general[self::$option_keys[$feature]]);
	}

	/**
	 * Clears the per-request memo.
	 *
	 * Settings saves and the test suite both change the underlying option
	 * mid-request, after a flag may already have been read.
	 *
	 * @return void
	 */
	public static function reset_cache()
	{
		self::$memo = [];
	}

	/**
	 * Drops the memo whenever the settings option is written.
	 *
	 * Hooking the generic option actions covers every save path — the Settings
	 * API, the admin AJAX handler, REST and any direct update_option() call —
	 * rather than relying on each one to remember to invalidate.
	 *
	 * @return void
	 */
	public static function register_invalidation()
	{
		if (!function_exists('add_action')) {
			return;
		}

		$option = class_exists('Metasync') ? Metasync::option_name : 'metasync_options';

		// updated_option only fires when the stored value actually changed, so
		// a no-op save costs nothing.
		add_action('updated_option', static function ($name) use ($option) {
			if ($name === $option) {
				self::reset_cache();
			}
		});
		add_action('added_option', static function ($name) use ($option) {
			if ($name === $option) {
				self::reset_cache();
			}
		});
	}
}
