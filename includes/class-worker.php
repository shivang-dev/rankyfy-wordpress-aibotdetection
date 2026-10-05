<?php
/**
 * Background work, run by WP-Cron every five minutes (and on demand when an
 * administrator opens the dashboard and the last run is overdue). One run
 * holds a lock, works within a time budget and does, in order:
 *
 *   every run   verify queued crawlers → fold events into history → stream
 *               alerts (new crawler, first crawl of an important page) →
 *               throttle flooding impostors → inventory and page analysis
 *               batches
 *   hourly      coverage/score refresh, periodic alert rules, robots.txt
 *               check, RankyFy registry/ranges sync, publisher range refresh
 *   daily       retention, history snapshot, probes, link suggestions,
 *               unknown-agent classification, email digest
 *
 * Nothing here runs in a visitor's request.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Worker {

	const HOOK   = 'rfaib_tick';
	const LOCK   = 'rfaib_worker_lock';
	const BUDGET = 25;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
	}

	public static function schedules( $s ) {
		$s['rfaib_5min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (AI Crawler Monitor)', 'rankyfy-ai-crawlers' ),
		);
		return $s;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'rfaib_5min', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	private static function lock() {
		$now = time();
		if ( add_option( self::LOCK, $now, '', 'no' ) ) {
			return true;
		}
		$held = (int) get_option( self::LOCK );
		if ( $now - $held > 5 * MINUTE_IN_SECONDS ) {
			// A crashed run left the lock behind. Take it over atomically.
			global $wpdb;
			$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $now, self::LOCK, (string) $held ) );
			wp_cache_delete( self::LOCK, 'options' );
			return 1 === (int) $taken;
		}
		return false;
	}

	private static function unlock() {
		delete_option( self::LOCK );
	}

	private static function due( $key, $every ) {
		$last = (int) get_option( 'rfaib_last_' . $key, 0 );
		if ( time() - $last < $every ) {
			return false;
		}
		update_option( 'rfaib_last_' . $key, time(), false );
		return true;
	}

	/** One worker run. Safe to call from cron, the CLI or the status endpoint. */
	public static function tick( $budget = self::BUDGET ) {
		if ( ! Installer::ready() || ! self::lock() ) {
			return false;
		}
		$deadline = microtime( true ) + $budget;
		$left     = static function () use ( $deadline ) {
			return max( 0, $deadline - microtime( true ) );
		};
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $budget + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		ignore_user_abort( true );
		try {
			Verifier::run( min( 6, $left() ) );
			Aggregator::run( min( 8, $left() ) );
			Alerts::stream( Aggregator::$new_bots, Aggregator::$first_crawls );
			Aggregator::throttle();

			if ( $left() > 3 ) {
				Inventory::step( min( 4, $left() ) );
			}
			if ( $left() > 3 ) {
				Analyzer::step( min( 6, $left() ) );
			}
			if ( $left() > 2 && self::due( 'hourly', HOUR_IN_SECONDS ) ) {
				Robots::refresh();
				Coverage::run( max( 3, min( 15, $left() ) ) );
				Alerts::periodic();
				Rankyfy::sync_registry();
				Ranges::refresh_direct( min( 10, $left() ) );
			}
			if ( $left() > 2 && self::due( 'daily', DAY_IN_SECONDS ) ) {
				Aggregator::prune();
				Probe::run( min( 10, $left() ) );
				Linker::rebuild( min( 8, $left() ) );
				Rankyfy::share_unknown_agents();
				Rankyfy::auto_ai_analysis();
				Inventory::request_rebuild_if_stale();
			}
			// History snapshot once per calendar day, after the day's work.
			$today = Util::day();
			if ( get_option( 'rfaib_snapshot_day' ) !== $today ) {
				Analytics::snapshot();
				update_option( 'rfaib_snapshot_day', $today, false );
			}
			Notifier::deliver();
			update_option( 'rfaib_worker_last', time(), false );
		} catch ( \Throwable $e ) {
			Log::error( 'worker run failed', array( 'error' => $e->getMessage(), 'at' => basename( $e->getFile() ) . ':' . $e->getLine() ) );
		} finally {
			self::unlock();
		}
		return true;
	}

	/** Seconds since the last completed run, or null if never. */
	public static function age() {
		$last = (int) get_option( 'rfaib_worker_last', 0 );
		return $last ? time() - $last : null;
	}
}
