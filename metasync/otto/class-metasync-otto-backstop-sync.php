<?php
/**
 * Postmeta backstop for OTTO transient cache misses.
 *
 * The crawl-notify webhook (otto_crawl_notify -> metasync_process_otto_crawl_url_job
 * -> metasync_process_otto_seo_data) is the primary path that writes fresh OTTO
 * suggestions into post meta. It is a webhook plus WP-Cron, so it can fail —
 * and when it fails repeatedly the stored meta goes stale indefinitely.
 *
 * A transient cache MISS is the one moment the render path ALREADY holds fresh
 * OTTO data: get_suggestions() fetched it live to build the page. This class
 * reuses that fetch — no second API call — and runs the existing compare-then-
 * write sync (metasync_process_otto_seo_data) for it, after the response HTML
 * has been built.
 *
 * Deliberate non-goals: no reconciliation sweep, no hashing scheme, no queue,
 * and no WP-Cron. Every one of those would reintroduce the reliability gap
 * this backstop exists to close.
 *
 * @package Search Atlas SEO
 */

if (!defined('ABSPATH')) {
	exit;
}

final class Metasync_Otto_Backstop_Sync {

	/**
	 * Prefix of the per-URL guard that dedupes concurrent MISS syncs.
	 */
	const GUARD_PREFIX = 'metasync_otto_backstop_';

	/**
	 * Guard lifetime in seconds. Mirrors the 120s TTL of the per-URL SEO lock
	 * inside metasync_process_otto_seo_data() so the two claims on a URL
	 * cannot outlive each other.
	 */
	const GUARD_TTL = 120;

	/**
	 * Decide whether this request owes a postmeta sync, and defer it to shutdown.
	 *
	 * Called from Metasync_otto_pixel::render_route_html() with the cache status
	 * get_suggestions() just produced. Everything a steady-state request does —
	 * a transient HIT — bails on the first guard below and costs nothing.
	 *
	 * @param string $route        Fully-qualified URL of the page being rendered.
	 * @param array  $suggestions  Suggestions fetched by this request's MISS.
	 * @param string $cache_status Cache status from Metasync_Otto_Transient_Cache.
	 * @return bool True when a shutdown sync was scheduled.
	 */
	public static function maybe_defer_sync($route, array $suggestions, $cache_status) {
		# Only a genuine MISS may sync. HIT (the steady state), STALE,
		# NO_SUGGESTIONS, RATE_LIMITED, LOCKED, BREAKER_OPEN and API_ERROR all
		# served data this request did not fetch, so none of them owes a write.
		if ($cache_status !== Metasync_Otto_Transient_Cache::STATUS_MISS) {
			return false;
		}

		# The PHPDoc promises a string, but the render path hands over whatever
		# it holds; the runtime check stays for callers PHPStan cannot see
		# (same idiom as class-metasync-otto-render-strategy.php).
		# @phpstan-ignore-next-line function.alreadyNarrowedType
		if (!is_string($route) || $route === '') {
			return false;
		}

		# Kill switch. This behaviour hangs off the highest-risk files in the
		# plugin, so support must be able to switch it off per site without a
		# release.
		if (!self::is_enabled()) {
			return false;
		}

		$cache = new Metasync_Otto_Transient_Cache(Metasync_Otto_Config::get_otto_uuid());

		# An empty payload is an answer ("OTTO holds nothing for this URL"),
		# not a reason to touch postmeta. has_payload() also filters the
		# whitespace-only wrappers an undeployed response ships.
		if (!$cache->has_payload($suggestions)) {
			return false;
		}

		# A loaded server never gets more work from a backstop. Skipping is
		# cheap and self-healing: the next MISS, one cache TTL later, retries.
		# Nothing is scheduled on this path — see the class docblock.
		if (class_exists('Metasync_CPU_Monitor') && !Metasync_CPU_Monitor::is_load_safe()) {
			return false;
		}

		# Once per URL per miss window. get_suggestions() has already released
		# its fetch lock by the time the render path calls this, so a second
		# worker can legitimately be in the same MISS for the same URL; the
		# guard uses the SAME atomic primitive and only one of them wins.
		# metasync_process_otto_seo_data() re-checks its own per-URL lock too,
		# so a sync write is at most one per URL even across windows.
		if (!$cache->acquire_lock(self::guard_key($route), self::GUARD_TTL)) {
			return false;
		}

		# Shutdown callbacks run after the response HTML has been built (and,
		# under FPM, after finish_request() has flushed it to the client), so
		# the served page is untouched and the sync adds no measurable latency.
		register_shutdown_function(array(__CLASS__, 'run'), $route, $suggestions);

		self::debug_log('defer_scheduled', [
			'route' => $route,
			'status' => 'MISS',
			'extra' => ['guard_key' => self::guard_key($route)],
		]);

		return true;
	}

	/**
	 * Run the deferred postmeta sync. Registered by maybe_defer_sync().
	 *
	 * Public because register_shutdown_function() needs a callable; it is not
	 * meant to be called from request code.
	 *
	 * @param string $route       Fully-qualified URL.
	 * @param array  $suggestions Suggestions fetched by this request's MISS.
	 */
	public static function run($route, array $suggestions) {
		$guard_key = self::guard_key($route);
		$started_at = microtime(true);
		self::debug_log('sync_started', ['route' => $route]);

		try {
			# Hand the finished response to the client before touching the
			# database. See finish_request() for what happens where this is a
			# no-op.
			self::finish_request();

			if (!function_exists('metasync_process_otto_seo_data')) {
				# The state constant is resolved at this call site, not inside
				# record(), so it needs its own guard: in an upgrade window the
				# pipeline file and the status class can both be missing, and
				# an unguarded constant access would relabel this honest
				# 'sync_missing' outcome as 'backstop:exception'.
				if (class_exists('Metasync_Otto_Job_Status')) {
					self::record($route, Metasync_Otto_Job_Status::STATE_FAILED, 'backstop:sync_missing');
				}
				return;
			}

			# Reuse the crawl-notify pipeline verbatim. It owns the per-URL SEO
			# lock, redirect resolution, the 404/exclusion guards, and the
			# compare-then-write updates for posts, categories, product
			# categories, the home page and the posts page. The freshly fetched
			# suggestions are handed in as the prefetch, so the sync makes no
			# second API call. allow_defer=false keeps this path from ever
			# scheduling cron work of its own — WP-Cron is the unreliable thing
			# this backstop compensates for.
			$outcome = metasync_process_otto_seo_data($route, false, 0, $suggestions);

			# Success and no-change are the only outcomes that count as a repaired
			# (or already-correct) URL; everything else is reported as a failure.
			$terminal = ($outcome === Metasync_Otto_Job_Status::OUTCOME_SUCCESS
				|| $outcome === Metasync_Otto_Job_Status::OUTCOME_NO_CHANGE);

			$elapsed = (int) round((microtime(true) - $started_at) * 1000);
			self::debug_log('sync_finished', [
				'route' => $route,
				'status' => $outcome,
				'elapsed_ms' => $elapsed,
				'extra' => ['terminal' => $terminal],
			]);

			# PERMANENT means OTTO tracks a URL WordPress cannot map — author
			# and date archives, excluded and 404'd URLs: an expected answer
			# that owes no repair, on every miss cycle forever. It is neither
			# recorded nor logged: a failed-status entry per cycle would bury
			# real failures in the shared, bounded job-status store, and a log
			# line per cycle would be unbounded noise.
			if ($outcome !== Metasync_Otto_Job_Status::OUTCOME_PERMANENT) {
				self::record(
					$route,
					$terminal ? Metasync_Otto_Job_Status::STATE_COMPLETED : Metasync_Otto_Job_Status::STATE_FAILED,
					'backstop:' . $outcome
				);

				if (!$terminal) {
					error_log('MetaSync OTTO: postmeta backstop did not sync ' . $route . ' (outcome: ' . $outcome . ')'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- genuine failure path, bounded, no secrets
				}
			}
		} catch (Throwable $e) {
			if (class_exists('Metasync_Otto_Job_Status')) {
				self::record($route, Metasync_Otto_Job_Status::STATE_FAILED, 'backstop:exception');
			}
			error_log('MetaSync OTTO: postmeta backstop failed for ' . $route . ' - ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- genuine failure path, bounded, no secrets
		} finally {
			# Always hand the URL back, even on a fatal-path Throwable, so a
			# crashed sync cannot hold the guard for the full TTL.
			delete_transient($guard_key);
		}
	}

	/**
	 * Flush the response to the client, if this SAPI can.
	 *
	 * fastcgi_finish_request() exists only under PHP-FPM (php_sapi_name()
	 * 'fpm-fcgi'), and probing for the function is more reliable than matching
	 * the SAPI name: what we depend on is the behaviour, not the label.
	 *
	 * KNOWN CAVEAT (documented in the tests as well): on hosts where the
	 * function is absent — mod_php, some LSAPI setups, CLI — the sync still
	 * runs after the response is BUILT, but the client waits for the process
	 * to exit before it sees the last byte, so the sync is not fully free
	 * there. The backstop is still correct and still bounded on those hosts;
	 * it is just not latency-invisible. Switch the feature off per site if
	 * that trade-off is unacceptable.
	 *
	 * @return bool True when the response was flushed before the sync ran.
	 */
	public static function finish_request() {
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
			return true;
		}

		return false;
	}

	/**
	 * Whether the backstop is switched on for this site.
	 *
	 * On by default. It is an internal reliability mechanism, so there is
	 * deliberately no user-facing setting; support can switch it off per site
	 * through the filter below.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		/**
		 * Kill switch for the render-path postmeta backstop. Return false to
		 * stop MISS-triggered syncs.
		 *
		 * @param bool $enabled Whether the backstop is enabled.
		 */
		return (bool) apply_filters('metasync_otto_backstop_sync_enabled', true);
	}

	/**
	 * Write optional JSONL diagnostics for QA/support. Enabled only when the
	 * site defines METASYNC_OTTO_BACKSTOP_DEBUG=true.
	 *
	 * The file lives under uploads (metasync/otto-backstop-debug.log), never
	 * inside the plugin folder — the plugin directory is wiped on every
	 * upgrade, and Plugin Check hard-fails plugin-dir writes.
	 *
	 * @param string $message Event name.
	 * @param array  $context Diagnostic context.
	 */
	private static function debug_log($message, $context = []) {
		if (!defined('METASYNC_OTTO_BACKSTOP_DEBUG') || !METASYNC_OTTO_BACKSTOP_DEBUG) {
			return;
		}

		$uploads = wp_upload_dir();
		if (!empty($uploads['error'])) {
			return;
		}

		$entry = array_merge(
			[
				'ts' => gmdate('c'),
				'pid' => getmypid(),
				'event' => $message,
			],
			$context
		);
		@file_put_contents(
			trailingslashit($uploads['basedir']) . 'metasync/otto-backstop-debug.log',
			wp_json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n",
			FILE_APPEND | LOCK_EX
		);
	}

	/**
	 * Guard key for a URL. Same md5-of-route shape the SEO job lock uses.
	 *
	 * @param string $route Fully-qualified URL.
	 * @return string
	 */
	private static function guard_key($route) {
		return self::GUARD_PREFIX . md5((string) $route);
	}

	/**
	 * Record a backstop outcome in the bounded per-URL status store, so "did
	 * the meta ever get repaired" stays answerable from the same surface the
	 * crawl-notify pipeline reports through.
	 *
	 * @param string $route Fully-qualified URL.
	 * @param string $state Metasync_Otto_Job_Status state constant.
	 * @param string $reason Machine-readable reason.
	 */
	private static function record($route, $state, $reason) {
		if (!class_exists('Metasync_Otto_Job_Status')) {
			return;
		}

		Metasync_Otto_Job_Status::record($route, $state, 'backstop', 0, $reason, array('via' => 'backstop'));
	}
}
