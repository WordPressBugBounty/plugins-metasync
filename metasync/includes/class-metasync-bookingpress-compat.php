<?php
/**
 * BookingPress front-end session compatibility.
 *
 * BookingPress hooks `init` at priority 1 and calls session_start() on every
 * non-admin request. The PHPSESSID cookie this sets on every response makes
 * SiteGround (and most hosts) skip their page cache for the whole site, so
 * no public page is ever served from cache — everything renders in full PHP
 * on every visit.
 *
 * Nothing in BookingPress depends on that global session:
 *
 * - The spam-protection captcha it was built for writes its session value
 *   from an admin-ajax endpoint, where BookingPress's own is_admin() guard
 *   prevents the session from starting, so the write is discarded; and the
 *   validation filter that would read it is registered nowhere (its only
 *   add_filter line ships commented out in their plugin). Current versions
 *   exchange the captcha over transients and nonces instead.
 * - The remaining session uses are version-gated legacy support for old
 *   releases, living inside AJAX handlers that call session_start()
 *   themselves — except Pro's cart handshake, which does ride on the
 *   front-end session, but only for visitors holding a
 *   bookingpress_cart_id cookie (set by the separate cart module). Those
 *   visitors keep the session; see the cookie guard below.
 *
 * This shim removes the global session start on public HTML requests so host
 * page caching can engage again. Admin, AJAX, cron, CLI and REST requests
 * are untouched, and any code that genuinely needs a session still starts
 * its own.
 *
 * @package    MetaSync
 * @subpackage MetaSync/includes
 * @since      2.6.22
 */

if (!defined('ABSPATH')) {
	exit; # Exit if accessed directly
}

class Metasync_BookingPress_Compat
{
	/**
	 * Option value that disables the shim ("Auto" keeps it on).
	 */
	const DISABLED_VALUE = 'off';

	/**
	 * Runs on `init` at priority 0, ahead of BookingPress's priority-1
	 * session start, and unhooks it for this request.
	 *
	 * @return void
	 */
	public static function neutralize_bookingpress_session()
	{
		if (self::is_disabled_by_setting() || !self::is_public_html_request()) {
			return;
		}
		# Pro's cart handshake (cart-cookie expiry flag and cart prefill) rides
		# on the front-end session, but only for visitors the separate cart
		# module has given a bookingpress_cart_id cookie. Leave the session in
		# place for them so the handshake keeps working; once their cookie is
		# expired the shim engages again on its own.
		if (!empty($_COOKIE['bookingpress_cart_id'])) {
			return;
		}
		self::remove_spam_protection_session_hook();
	}

	/**
	 * The shim is on by default; only an explicit "off" disables it.
	 *
	 * @return bool True when the shim must not touch anything.
	 */
	private static function is_disabled_by_setting()
	{
		$options = get_option('metasync_options', array());
		$mode = $options['general']['bookingpress_session_compat'] ?? 'auto';
		return $mode === self::DISABLED_VALUE;
	}

	/**
	 * Only public page renders matter for host caching; every other request
	 * context is left exactly as WordPress finds it.
	 *
	 * @return bool True for front-end HTML requests.
	 */
	private static function is_public_html_request()
	{
		if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
			return false;
		}
		# WP_CLI is only ever defined (as true) by wp-cli itself, so the bare
		# defined() check carries the meaning; referencing the constant value
		# trips static analysis, which resolves the stubbed value to always-true.
		if (defined('WP_CLI')) {
			return false;
		}
		if (defined('REST_REQUEST') && REST_REQUEST) {
			return false;
		}
		return true;
	}

	/**
	 * Remove bookingpress_spam_protection::bookingpress_start_session from
	 * the init hook.
	 *
	 * The preferred path uses the global instance BookingPress exposes; a
	 * registry scan follows as fallback to catch renamed globals, subclasses
	 * or a changed registration priority.
	 *
	 * @return bool True when the callback was found and removed.
	 */
	private static function remove_spam_protection_session_hook()
	{
		$removed = false;

		if (!empty($GLOBALS['bookingpress_spam_protection']) && is_object($GLOBALS['bookingpress_spam_protection'])) {
			$removed = remove_action(
				'init',
				array($GLOBALS['bookingpress_spam_protection'], 'bookingpress_start_session'),
				1
			);
		}

		if ($removed) {
			return true;
		}

		if (!isset($GLOBALS['wp_filter']['init'])) {
			return false;
		}

		$init_hook = &$GLOBALS['wp_filter']['init'];

		# WP_Hook objects (WP 4.7+) keep callbacks in a public property; the
		# plain-array shape covers legacy WP and unit-test registries alike.
		if ($init_hook instanceof WP_Hook) {
			$callbacks = &$init_hook->callbacks;
		} elseif (is_array($init_hook)) {
			$callbacks = &$init_hook;
		} else {
			return false;
		}

		if (empty($callbacks)) {
			return false;
		}

		foreach ($callbacks as $priority => $entries) {
			if (!is_array($entries)) {
				continue;
			}
			foreach ($entries as $callback_id => $entry) {
				$fn = is_array($entry) && isset($entry['function']) ? $entry['function'] : $entry;
				if (
					is_array($fn) && count($fn) === 2 && is_object($fn[0])
					&& is_a($fn[0], 'bookingpress_spam_protection')
					&& $fn[1] === 'bookingpress_start_session'
				) {
					unset($callbacks[$priority][$callback_id]);
					return true;
				}
			}
		}

		return false;
	}
}
