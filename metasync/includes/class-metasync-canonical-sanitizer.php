<?php
/**
 * Canonical URL sanitizer.
 *
 * Central guard against the legacy meta_canonical corruption where array
 * values were cast to the literal string "Array" and later emitted as
 * <link rel="canonical" href="http://Array">. Every read, write,
 * and sync of a canonical value routes through this class so a corrupted
 * database row can never reach page output or third-party SEO storage.
 *
 * @package Search Atlas SEO
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('Metasync_Canonical_Sanitizer')) {

	class Metasync_Canonical_Sanitizer {

		/**
		 * Dig the first scalar out of a possibly-nested array value.
		 *
		 * Legacy sanitize_array() storage sometimes produced nested arrays;
		 * a single reset() on those returns another array, and a subsequent
		 * (string) cast produces the literal "Array" — the original source
		 * of the corruption. This walks down to the first scalar instead.
		 *
		 * @param mixed $value Raw meta / input value.
		 * @return string|int|float|bool|'' First scalar found, or '' when none exists.
		 */
		public static function extract_scalar($value) {
			$depth = 10; // hard cap — corrupt data must never loop forever
			while (is_array($value) && $depth-- > 0) {
				$value = reset($value);
			}
			// reset([]) yields false — booleans are never a usable canonical.
			return (is_scalar($value) && !is_bool($value)) ? $value : '';
		}

		/**
		 * Whether a value is one of the known corruption artifacts.
		 *
		 * "Array" is what PHP produces when an array is cast to string;
		 * "http://Array" / "https://Array" are what esc_url_raw() makes of it.
		 *
		 * @param mixed $value Value to test (non-strings are never corrupted literals).
		 * @return bool
		 */
		public static function is_corrupted($value) {
			if (!is_string($value)) {
				return false;
			}
			$normalized = strtolower(rtrim(trim($value), '/'));
			return in_array($normalized, array('array', 'http://array', 'https://array'), true);
		}

		/**
		 * Validate a stored canonical value for output or sync.
		 *
		 * Accepts absolute http/https URLs and site-relative paths ("/about/"),
		 * both of which rel=canonical allows and prior plugin versions emitted.
		 * Rejects arrays (after first-scalar extraction), the corruption
		 * literals, and anything that is not a well-formed URL — returning ''
		 * so callers fall back to their default (usually the permalink),
		 * matching pre-2.6.16 behavior for posts with no usable canonical.
		 *
		 * Deliberately NOT wp_http_validate_url(): that helper is an SSRF
		 * guard for outbound requests and rejects legitimate canonical hosts
		 * (non-standard ports, intranet hostnames) that sites do publish.
		 *
		 * @param mixed $value Raw meta / input value.
		 * @return string Valid canonical URL, or '' when the value is unusable.
		 */
		public static function sanitize($value) {
			$value = self::extract_scalar($value);
			if (!is_string($value) && !is_numeric($value)) {
				return '';
			}
			$value = trim((string) $value);
			if ($value === '' || self::is_corrupted($value)) {
				return '';
			}

			// Site-relative canonical ("/path/") — allowed; exclude
			// protocol-relative "//host" which FILTER_VALIDATE_URL can't vet.
			if ($value[0] === '/') {
				return (!isset($value[1]) || $value[1] !== '/') ? $value : '';
			}

			// Legacy rows stored before scheme normalization ("example.com/x",
			// e.g. via the old REST sanitize_text_field path): emit them the way
			// esc_url() always did — http:// prepended — but only for values
			// with a dotted, colon-free host so bare words stay rejected.
			if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $value)) {
				$host_part = strstr($value, '/', true);
				$host_part = ($host_part === false) ? $value : $host_part;
				if (strpos($host_part, ':') !== false || strpos($host_part, '.') === false) {
					return '';
				}
				$value = 'http://' . $value;
			}

			return self::is_valid_http_url($value) ? $value : '';
		}

		/**
		 * Structural URL check tolerant of the UTF-8 canonicals esc_url() emits.
		 *
		 * FILTER_VALIDATE_URL is ASCII-only, so IDN hosts and unencoded UTF-8
		 * paths (both previously stored and emitted by this plugin) would fail
		 * it. Validate a copy with high bytes mapped to a safe ASCII letter;
		 * genuinely malformed ASCII (spaces, missing host, bad scheme) still
		 * fails as it should.
		 *
		 * @param string $value Candidate URL (scheme already present).
		 * @return bool
		 */
		private static function is_valid_http_url($value) {
			$ascii = preg_replace('/[\x80-\xFF]/', 'x', $value);
			if (!filter_var($ascii, FILTER_VALIDATE_URL)) {
				return false;
			}
			$scheme = strtolower((string) parse_url($ascii, PHP_URL_SCHEME));
			return $scheme === 'http' || $scheme === 'https';
		}

		/**
		 * Sanitize user/API input before persisting it as a canonical.
		 *
		 * Same rules as sanitize(); esc_url_raw() (when available) additionally
		 * normalizes/cleans the value the way the meta box always did, and
		 * sanitize()'s own schemeless handling keeps behavior deterministic in
		 * environments without WordPress loaded. Returns '' for unusable input —
		 * callers should then delete the meta rather than store garbage.
		 *
		 * @param mixed $value Raw user/API input.
		 * @return string Storable canonical URL, or '' when input is unusable.
		 */
		public static function sanitize_for_save($value) {
			$value = self::extract_scalar($value);
			if (!is_string($value) && !is_numeric($value)) {
				return '';
			}
			$value = trim((string) $value);
			if ($value === '' || self::is_corrupted($value)) {
				return '';
			}
			if (function_exists('esc_url_raw')) {
				$value = esc_url_raw($value);
			}
			return self::sanitize($value);
		}
	}
}
