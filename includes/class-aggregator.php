<?php
/**
 * Turns raw crawler requests into the tables every screen reads.
 *
 * Work is done in id-ranged chunks with set-based statements
 * (INSERT … SELECT … GROUP BY … ON DUPLICATE KEY UPDATE), so a chunk of
 * thousands of requests costs a handful of queries, and the request path
 * never pays for aggregation. A watermark records the last event folded in;
 * requests still waiting for a reverse-DNS or signature verdict hold the
 * watermark back (for at most 15 minutes) so they are counted with their
 * final classification.
 *
 * Genuine crawls (cls ai/search) feed page and bot history; impersonations
 * (cls spoofed) are only counted, never treated as crawls.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Aggregator {

	const WATERMARK   = 'rfaib_agg_watermark';
	const CHUNK       = 5000;
	const SESSION_GAP = 1800;     // 30 minutes without a request ends a crawl session
	const SESSION_MAX = 6 * 3600; // a crawler that never pauses starts a new session every 6 hours

	/** Events learned during this run, for the alert rules. */
	public static $new_bots     = array();
	public static $first_crawls = array();

	/**
	 * @return int events folded in
	 */
	public static function run( $budget = 10 ) {
		global $wpdb;
		$e        = Installer::table( 'events' );
		$deadline = microtime( true ) + $budget;
		self::ensure_watermark();
		wp_cache_delete( self::WATERMARK, 'options' );
		$wm       = (int) get_option( self::WATERMARK, 0 );
		$max      = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$e}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// A pending event whose check is no longer queued can never settle: repair it.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$e} e SET e.vstate = 'none' WHERE e.id > %d AND e.vstate = 'pending' AND NOT EXISTS (
				   SELECT 1 FROM " . Installer::table( 'verify_queue' ) . " q WHERE q.event_id = e.id OR (q.kind = 'rdns' AND q.bot = e.bot AND q.ip_hash = e.ip_hash))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wm
			)
		);
		// Every event still waiting for its verdict holds the watermark (live or
		// imported); the verifier settles or expires each check within 2 days.
		$hold     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(id) FROM {$e} WHERE vstate = 'pending' AND id > %d", $wm ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$upper    = $hold ? min( $max, $hold - 1 ) : $max;
		$total    = 0;

		self::$new_bots     = array();
		self::$first_crawls = array();

		while ( $wm < $upper && microtime( true ) < $deadline ) {
			$lo = $wm + 1;
			$hi = min( $wm + self::CHUNK, $upper );
			// One transaction per chunk: the watermark moves by compare-and-swap in
			// the same transaction as the rollups, so a chunk is folded in exactly
			// once even if two runners overlap (the second blocks on the row lock,
			// then finds the watermark moved and stops), and a crash mid-chunk
			// rolls back both. It also turns hundreds of small commits into one.
			$wpdb->query( 'START TRANSACTION' );
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $hi, self::WATERMARK, (string) $wm ) );
			if ( 1 !== (int) $claimed ) {
				$wpdb->query( 'ROLLBACK' );
				wp_cache_delete( self::WATERMARK, 'options' );
				break;
			}
			try {
				self::chunk( $lo, $hi );
				$total += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$e} WHERE id BETWEEN %d AND %d", $lo, $hi ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( 'COMMIT' );
			} catch ( \Throwable $ex ) {
				$wpdb->query( 'ROLLBACK' );
				wp_cache_delete( self::WATERMARK, 'options' );
				throw $ex;
			}
			wp_cache_delete( self::WATERMARK, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			$wm = $hi;
		}
		if ( $total ) {
			update_option( 'rfaib_agg_last', time(), false );
			Analytics::bust();
		}
		return $total;
	}

	/** A write that must succeed, or the chunk is rolled back (wpdb never throws). */
	private static function exec( $sql ) {
		global $wpdb;
		$r = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by callers
		self::check();
		return $r;
	}

	private static function check() {
		global $wpdb;
		if ( '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'rollup statement failed: ' . $wpdb->last_error );
		}
	}

	/** The watermark row must exist for the compare-and-swap. */
	public static function ensure_watermark() {
		add_option( self::WATERMARK, '0', '', 'no' );
	}

	/** Fold events lo..hi into every rollup. */
	public static function chunk( $lo, $hi ) {
		global $wpdb;
		$e       = Installer::table( 'events' );
		$genuine = "cls IN ('ai','search')";

		// What is new — read before the upserts change the answer.
		$new_bots = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT ev.bot FROM {$e} ev LEFT JOIN " . Installer::table( 'bots_seen' ) . " s ON s.bot = ev.bot
				 WHERE ev.id BETWEEN %d AND %d AND ev.cls = 'ai' AND s.bot IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lo,
				$hi
			)
		);
		self::check();
		foreach ( (array) $new_bots as $b ) {
			self::$new_bots[ $b ] = true;
		}
		$firsts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ev.url_hash, ev.bot, MIN(ev.ts) AS ts, MAX(ev.path) AS path FROM {$e} ev
				 LEFT JOIN " . Installer::table( 'page_bots' ) . " p ON p.url_hash = ev.url_hash AND p.bot = ev.bot
				 WHERE ev.id BETWEEN %d AND %d AND ev.cls = 'ai' AND ev.status < 400 AND p.url_hash IS NULL
				 GROUP BY ev.url_hash, ev.bot LIMIT 2000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lo,
				$hi
			),
			ARRAY_A
		);
		self::check();
		foreach ( (array) $firsts as $f ) {
			self::$first_crawls[] = $f;
		}

		// Day × bot × page.
		self::exec(
			$wpdb->prepare(
				'INSERT INTO ' . Installer::table( 'daily' ) . " (day, bot, url_hash, path, object_id, hits, errors, ms_total, ms_max, last_status)
				 SELECT day, bot, url_hash, MAX(path), MAX(object_id), COUNT(*), SUM(status >= 400), SUM(ms), MAX(ms),
				        CAST(SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY id DESC), ',', 1) AS UNSIGNED)
				 FROM {$e} WHERE id BETWEEN %d AND %d AND {$genuine}
				 GROUP BY day, bot, url_hash
				 ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits), errors = errors + VALUES(errors), ms_total = ms_total + VALUES(ms_total),
				   ms_max = GREATEST(ms_max, VALUES(ms_max)), last_status = VALUES(last_status), path = VALUES(path),
				   object_id = IF(VALUES(object_id) > 0, VALUES(object_id), object_id)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lo,
				$hi
			)
		);

		// Day × page totals for AI crawlers (what page lists sum over a date range).
		self::exec(
			$wpdb->prepare(
				'INSERT INTO ' . Installer::table( 'daily_pages' ) . " (day, url_hash, hits, errors, ms_total)
				 SELECT day, url_hash, COUNT(*), SUM(status >= 400), SUM(ms) FROM {$e} WHERE id BETWEEN %d AND %d AND cls = 'ai'
				 GROUP BY day, url_hash
				 ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits), errors = errors + VALUES(errors), ms_total = ms_total + VALUES(ms_total)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lo,
				$hi
			)
		);

		// Day × bot totals (impersonations counted under the bot they claimed).
		self::exec(
			$wpdb->prepare(
				'INSERT INTO ' . Installer::table( 'daily_bots' ) . " (day, bot, hits, verified, spoofed, errors, ms_total)
				 SELECT day, IF(bot = '', '_unknown', bot), SUM(cls <> 'spoofed'), SUM(vstate = 'verified' AND cls <> 'spoofed'), SUM(cls = 'spoofed'),
				        SUM(status >= 400 AND cls <> 'spoofed'), SUM(IF(cls <> 'spoofed', ms, 0))
				 FROM {$e} WHERE id BETWEEN %d AND %d
				 GROUP BY day, IF(bot = '', '_unknown', bot)
				 ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits), verified = verified + VALUES(verified), spoofed = spoofed + VALUES(spoofed),
				   errors = errors + VALUES(errors), ms_total = ms_total + VALUES(ms_total)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lo,
				$hi
			)
		);

		// First and last crawl per page × bot.
		self::exec(
			$wpdb->prepare(
				'INSERT INTO ' . Installer::table( 'page_bots' ) . " (url_hash, bot, path, object_id, first_seen, last_seen, hits, last_status)
				 SELECT url_hash, bot, MAX(path), MAX(object_id), MIN(ts), MAX(ts), COUNT(*),
				        CAST(SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY ts DESC, id DESC), ',', 1) AS UNSIGNED)
				 FROM {$e} WHERE id BETWEEN %d AND %d AND {$genuine}
				 GROUP BY url_hash, bot
				 ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits), first_seen = LEAST(first_seen, VALUES(first_seen)),
				   last_status = IF(VALUES(last_seen) >= last_seen, VALUES(last_status), last_status),
				   last_seen = GREATEST(last_seen, VALUES(last_seen)), path = VALUES(path),
				   object_id = IF(VALUES(object_id) > 0, VALUES(object_id), object_id)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lo,
				$hi
			)
		);

		// First and last visit per bot.
		self::exec(
			$wpdb->prepare(
				'INSERT INTO ' . Installer::table( 'bots_seen' ) . " (bot, first_seen, last_seen, hits, verified, spoofed)
				 SELECT bot, MIN(ts), MAX(ts), COUNT(*), SUM(vstate = 'verified'), 0
				 FROM {$e} WHERE id BETWEEN %d AND %d AND cls IN ('ai','search','potential') AND bot <> ''
				 GROUP BY bot
				 ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits), verified = verified + VALUES(verified),
				   first_seen = LEAST(first_seen, VALUES(first_seen)), last_seen = GREATEST(last_seen, VALUES(last_seen))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lo,
				$hi
			)
		);
		// Impersonations per claimed bot: aggregate over the id range first (primary
		// key), then a handful of single-row updates. (An UPDATE … JOIN on a derived
		// table lets the optimiser walk every event of the bot instead.)
		$spoofed = $wpdb->get_results(
			$wpdb->prepare( "SELECT bot, COUNT(*) c FROM {$e} WHERE id BETWEEN %d AND %d AND cls = 'spoofed' GROUP BY bot", $lo, $hi ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		self::check();
		foreach ( (array) $spoofed as $r ) {
			self::exec( $wpdb->prepare( 'UPDATE ' . Installer::table( 'bots_seen' ) . ' SET spoofed = spoofed + %d WHERE bot = %s', (int) $r['c'], $r['bot'] ) );
		}

		self::sessions( $lo, $hi );
	}

	/**
	 * Crawl sessions: consecutive requests from one bot with no gap longer
	 * than SESSION_GAP. Works for out-of-order (imported) events too, by
	 * merging into any session that overlaps the run.
	 */
	private static function sessions( $lo, $hi ) {
		global $wpdb;
		$e    = Installer::table( 'events' );
		$s    = Installer::table( 'sessions' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT bot, ts FROM {$e} WHERE id BETWEEN %d AND %d AND cls IN ('ai','search') ORDER BY bot, ts", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lo,
				$hi
			),
			ARRAY_N
		);
		self::check();
		if ( ! $rows ) {
			return;
		}
		// Collapse into runs.
		$runs = array();
		$cur  = null;
		foreach ( $rows as $r ) {
			list( $bot, $ts ) = array( $r[0], (int) $r[1] );
			if ( $cur && $cur['bot'] === $bot && $ts - $cur['end'] <= self::SESSION_GAP && $ts - $cur['start'] <= self::SESSION_MAX ) {
				$cur['end'] = $ts;
				$cur['n']++;
				continue;
			}
			if ( $cur ) {
				$runs[] = $cur;
			}
			$cur = array( 'bot' => $bot, 'start' => $ts, 'end' => $ts, 'n' => 1 );
		}
		$runs[] = $cur;

		foreach ( $runs as $run ) {
			$sess = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$s} WHERE bot = %s AND started <= %d AND ended >= %d ORDER BY ended DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$run['bot'],
					$run['end'] + self::SESSION_GAP,
					$run['start'] - self::SESSION_GAP
				),
				ARRAY_A
			);
			if ( $sess && max( (int) $sess['ended'], $run['end'] ) - min( (int) $sess['started'], $run['start'] ) <= self::SESSION_MAX ) {
				$start = min( (int) $sess['started'], $run['start'] );
				$end   = max( (int) $sess['ended'], $run['end'] );
				$id    = (int) $sess['id'];
			} else {
				$start = $run['start'];
				$end   = $run['end'];
				$wpdb->insert( $s, array( 'bot' => $run['bot'], 'started' => $start, 'ended' => $end ) );
				self::check();
				$id = (int) $wpdb->insert_id;
			}
			// Exact figures from the events (bot_ts index); bounded by SESSION_MAX.
			$stats = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) h, COUNT(DISTINCT url_hash) p, COUNT(DISTINCT ip_hash) i, SUM(status >= 400) er FROM {$e}
					 WHERE bot = %s AND ts BETWEEN %d AND %d AND cls IN ('ai','search')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$run['bot'],
					$start,
					$end
				),
				ARRAY_A
			);
			$wpdb->update(
				$s,
				array(
					'started' => $start,
					'ended'   => $end,
					'hits'    => (int) $stats['h'],
					'pages'   => (int) $stats['p'],
					'ips'     => (int) $stats['i'],
					'errors'  => (int) $stats['er'],
				),
				array( 'id' => $id )
			);
			self::check();
		}
	}

	/**
	 * Addresses sending more than a request a second for ten minutes without
	 * passing verification stop getting per-page rows for an hour. Real
	 * verified crawlers are never throttled.
	 */
	public static function throttle() {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT ip_hash FROM ' . Installer::table( 'events' ) . " WHERE ts > %d AND vstate <> 'verified' AND ip_hash <> '' GROUP BY ip_hash HAVING COUNT(*) > 600 LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				time() - 600
			)
		);
		$current = get_option( Tracker::THROTTLE );
		$ips     = array();
		if ( is_array( $current ) && (int) ( $current['until'] ?? 0 ) > time() ) {
			$ips = (array) $current['ips'];
		}
		foreach ( (array) $rows as $h ) {
			if ( ! isset( $ips[ $h ] ) ) {
				Log::warning( 'throttling a flooding unverified client', array( 'ip_hash' => $h ) );
			}
			$ips[ $h ] = 1;
		}
		update_option(
			Tracker::THROTTLE,
			$ips ? array( 'ips' => array_slice( $ips, -500, null, true ), 'until' => time() + HOUR_IN_SECONDS ) : array(),
			true
		);
	}

	/** Retention. Deletes in bounded batches so a large backlog never locks tables for long. */
	public static function prune() {
		global $wpdb;
		$wm      = (int) get_option( self::WATERMARK, 0 );
		$events  = time() - DAY_IN_SECONDS * (int) Settings::get( 'retention_events' );
		$history = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS * (int) Settings::get( 'retention_history' ) );
		$sess    = time() - DAY_IN_SECONDS * (int) Settings::get( 'retention_sessions' );

		for ( $i = 0; $i < 20; $i++ ) {
			$n = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Installer::table( 'events' ) . ' WHERE ts < %d AND id <= %d LIMIT 5000', $events, $wm ) );
			if ( $n < 5000 ) {
				break;
			}
		}
		foreach ( array( 'daily', 'daily_pages', 'daily_bots', 'referrals' ) as $t ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Installer::table( $t ) . ' WHERE day < %s LIMIT 50000', $history ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Installer::table( 'snapshots' ) . ' WHERE day < %s', $history ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Installer::table( 'sessions' ) . ' WHERE ended < %d LIMIT 50000', $sess ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Installer::table( 'agents' ) . " WHERE last_seen < %d AND state IN ('new','ignored')", time() - 180 * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Installer::table( 'alerts' ) . ' WHERE created_at < %d', time() - 180 * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Installer::table( 'findings' ) . " WHERE status = 'resolved' AND resolved_at < %d", time() - 365 * DAY_IN_SECONDS ) );
		// Page × bot rows for pages no bot has touched within the history window.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Installer::table( 'page_bots' ) . ' WHERE last_seen < %d LIMIT 50000', time() - DAY_IN_SECONDS * (int) Settings::get( 'retention_history' ) ) );
		Verifier::prune();
	}
}
