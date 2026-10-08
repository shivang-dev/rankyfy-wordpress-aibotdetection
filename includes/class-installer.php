<?php
/**
 * Database tables and lifecycle.
 *
 * Write path (one row per AI-crawler request, nothing for ordinary visitors):
 *   events        raw requests from AI and other monitored crawlers; short retention
 *   verify_queue  addresses/signatures waiting for reverse-DNS or signature checks
 *   ip_verdicts   cached verification results per (address hash, bot)
 *   agents        unrecognised bot user agents, for new-crawler discovery
 *   referrals     daily visits that arrived from an AI assistant (counts only)
 *
 * Read path (built from events by the worker, set-based, in chunks):
 *   daily         day × bot × page counts — the history behind every chart
 *   daily_pages   day × page totals for AI crawlers (fast range sums for page lists)
 *   daily_bots    day × bot totals (also counters for crawlers we do not log per page)
 *   page_bots     first/last crawl per page × bot — crawled vs. not crawled
 *   bots_seen     first/last visit per bot — new-bot and stopped-bot alerts
 *   sessions      crawl sessions per bot (start, end, requests, pages)
 *
 * Content intelligence:
 *   pages, page_terms, terms, links, findings
 *
 * Notifications and history:
 *   alerts, snapshots
 *
 * Publish guard:
 *   redirects     old URL → post, written when a published URL changes (slug or parent)
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Installer {

	const TABLES = array( 'events', 'verify_queue', 'ip_verdicts', 'agents', 'referrals', 'daily', 'daily_pages', 'daily_bots', 'page_bots', 'bots_seen', 'sessions', 'pages', 'page_terms', 'terms', 'links', 'findings', 'alerts', 'snapshots', 'redirects' );

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'rfy_' . $name;
	}

	public static function activate() {
		self::install();
		Registry::compile();
		Worker::schedule();
		if ( ! get_option( 'rfy_monitoring_since' ) ) {
			update_option( 'rfy_monitoring_since', time(), false );
		}
		Inventory::request_rebuild();
	}

	public static function deactivate() {
		Worker::unschedule();
	}

	public static function maybe_upgrade() {
		$from = (string) get_option( 'rfy_db_version' );
		if ( $from !== RFY_DB_VERSION ) {
			self::install();
			self::migrate( $from );
			Registry::compile();
			if ( ! get_option( 'rfy_monitoring_since' ) ) {
				update_option( 'rfy_monitoring_since', time(), false );
			}
		}
	}

	/** Data migrations after dbDelta has brought the tables up to date. */
	private static function migrate( $from ) {
		global $wpdb;
		if ( '' !== $from && version_compare( $from, '2', '<' ) ) {
			// 2: day × page rollup, backfilled from the day × bot × page history.
			$ai = array();
			foreach ( Registry::bots() as $id => $b ) {
				if ( $b['ai'] ) {
					$ai[] = $wpdb->prepare( '%s', $id );
				}
			}
			if ( $ai ) {
				$wpdb->query(
					'INSERT IGNORE INTO ' . self::table( 'daily_pages' ) . ' (day, url_hash, hits, errors, ms_total)
					 SELECT day, url_hash, SUM(hits), SUM(errors), SUM(ms_total) FROM ' . self::table( 'daily' ) . ' WHERE bot IN (' . implode( ',', $ai ) . ') GROUP BY day, url_hash' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				);
			}
		}
	}

	public static function ready() {
		return get_option( 'rfy_db_version' ) === RFY_DB_VERSION;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = array();
		foreach ( self::TABLES as $name ) {
			$t[ $name ] = self::table( $name );
		}

		// Raw requests. Append-only; the worker reads them by id range.
		dbDelta(
			"CREATE TABLE {$t['events']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ts int(10) unsigned NOT NULL,
			day date NOT NULL,
			bot varchar(40) NOT NULL DEFAULT '',
			cls varchar(16) NOT NULL DEFAULT '',
			vstate varchar(10) NOT NULL DEFAULT 'none',
			method varchar(7) NOT NULL DEFAULT 'GET',
			path varchar(512) NOT NULL DEFAULT '',
			url_hash char(32) NOT NULL DEFAULT '',
			kind varchar(12) NOT NULL DEFAULT '',
			object_type varchar(20) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status smallint(5) unsigned NOT NULL DEFAULT 0,
			ms int(10) unsigned NOT NULL DEFAULT 0,
			ip_net varchar(45) NOT NULL DEFAULT '',
			ip_hash char(16) NOT NULL DEFAULT '',
			ua varchar(255) NOT NULL DEFAULT '',
			source varchar(6) NOT NULL DEFAULT 'live',
			dedup char(32) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY dedup (dedup),
			KEY bot_ts (bot,ts),
			KEY url_ts (url_hash,ts),
			KEY vstate (vstate),
			KEY ts (ts)
		) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$t['verify_queue']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(10) NOT NULL DEFAULT 'rdns',
			bot varchar(40) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			ip_hash char(16) NOT NULL DEFAULT '',
			payload text NULL,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			created_at int(10) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY one_check (kind,bot,ip_hash,event_id)
		) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$t['ip_verdicts']} (
			ip_hash char(16) NOT NULL,
			bot varchar(40) NOT NULL,
			verdict varchar(10) NOT NULL,
			method varchar(10) NOT NULL DEFAULT '',
			host varchar(190) NOT NULL DEFAULT '',
			checked_at int(10) unsigned NOT NULL,
			PRIMARY KEY  (ip_hash,bot),
			KEY checked (checked_at)
		) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$t['agents']} (
			ua_hash char(32) NOT NULL,
			ua varchar(255) NOT NULL DEFAULT '',
			cls varchar(16) NOT NULL DEFAULT '',
			state varchar(10) NOT NULL DEFAULT 'new',
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			first_seen int(10) unsigned NOT NULL,
			last_seen int(10) unsigned NOT NULL,
			shared_at int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (ua_hash),
			KEY cls_seen (cls,last_seen)
		) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$t['referrals']} (
			day date NOT NULL,
			source varchar(20) NOT NULL,
			url_hash char(32) NOT NULL,
			path varchar(512) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			hits int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,source,url_hash),
			KEY url (url_hash)
		) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$t['daily']} (
			day date NOT NULL,
			bot varchar(40) NOT NULL,
			url_hash char(32) NOT NULL,
			path varchar(512) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			hits int(10) unsigned NOT NULL DEFAULT 0,
			errors int(10) unsigned NOT NULL DEFAULT 0,
			ms_total bigint(20) unsigned NOT NULL DEFAULT 0,
			ms_max int(10) unsigned NOT NULL DEFAULT 0,
			last_status smallint(5) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,bot,url_hash),
			KEY bot_day (bot,day),
			KEY url_day (url_hash,day)
		) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$t['daily_pages']} (
			day date NOT NULL,
			url_hash char(32) NOT NULL,
			hits int(10) unsigned NOT NULL DEFAULT 0,
			errors int(10) unsigned NOT NULL DEFAULT 0,
			ms_total bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,url_hash),
			KEY url_day (url_hash,day)
		) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$t['daily_bots']} (
			day date NOT NULL,
			bot varchar(40) NOT NULL,
			hits int(10) unsigned NOT NULL DEFAULT 0,
			verified int(10) unsigned NOT NULL DEFAULT 0,
			spoofed int(10) unsigned NOT NULL DEFAULT 0,
			errors int(10) unsigned NOT NULL DEFAULT 0,
			ms_total bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,bot),
			KEY bot (bot,day)
		) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$t['page_bots']} (
			url_hash char(32) NOT NULL,
			bot varchar(40) NOT NULL,
			path varchar(512) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			first_seen int(10) unsigned NOT NULL,
			last_seen int(10) unsigned NOT NULL,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_status smallint(5) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (url_hash,bot),
			KEY bot_seen (bot,last_seen),
			KEY seen (last_seen)
		) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$t['bots_seen']} (
			bot varchar(40) NOT NULL,
			first_seen int(10) unsigned NOT NULL,
			last_seen int(10) unsigned NOT NULL,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			verified bigint(20) unsigned NOT NULL DEFAULT 0,
			spoofed bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (bot)
		) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$t['sessions']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bot varchar(40) NOT NULL,
			started int(10) unsigned NOT NULL,
			ended int(10) unsigned NOT NULL,
			hits int(10) unsigned NOT NULL DEFAULT 0,
			pages int(10) unsigned NOT NULL DEFAULT 0,
			ips int(10) unsigned NOT NULL DEFAULT 0,
			errors int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY bot_end (bot,ended),
			KEY started (started)
		) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$t['pages']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(20) NOT NULL DEFAULT 'post',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			subtype varchar(40) NOT NULL DEFAULT '',
			url_hash char(32) NOT NULL,
			path varchar(512) NOT NULL DEFAULT '',
			title varchar(255) NOT NULL DEFAULT '',
			importance tinyint(3) unsigned NOT NULL DEFAULT 0,
			imp_reasons varchar(255) NOT NULL DEFAULT '',
			pinned tinyint(4) NOT NULL DEFAULT 0,
			word_count int(10) unsigned NOT NULL DEFAULT 0,
			modified datetime NULL,
			inlinks int(10) unsigned NOT NULL DEFAULT 0,
			outlinks int(10) unsigned NOT NULL DEFAULT 0,
			noindex tinyint(1) NOT NULL DEFAULT 0,
			http_status smallint(5) unsigned NOT NULL DEFAULT 0,
			fetch_at int(10) unsigned NOT NULL DEFAULT 0,
			analyzed_at int(10) unsigned NOT NULL DEFAULT 0,
			content_hash char(32) NOT NULL DEFAULT '',
			aeo_score tinyint(3) unsigned NULL,
			aeo longtext NULL,
			facts longtext NULL,
			terms text NULL,
			assist longtext NULL,
			assist_at int(10) unsigned NOT NULL DEFAULT 0,
			dirty tinyint(1) NOT NULL DEFAULT 1,
			deleted tinyint(1) NOT NULL DEFAULT 0,
			gen int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY object (object_type,object_id),
			KEY importance (deleted,importance),
			KEY dirty (dirty,importance),
			KEY score (aeo_score)
		) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$t['page_terms']} (
			page_id bigint(20) unsigned NOT NULL,
			term varchar(64) NOT NULL,
			weight float NOT NULL DEFAULT 0,
			PRIMARY KEY  (page_id,term),
			KEY term (term,weight)
		) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$t['terms']} (
			term varchar(64) NOT NULL,
			df int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (term)
		) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$t['links']} (
			from_id bigint(20) unsigned NOT NULL,
			to_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (from_id,to_id),
			KEY to_id (to_id)
		) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$t['findings']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			page_id bigint(20) unsigned NOT NULL DEFAULT 0,
			code varchar(40) NOT NULL,
			bot varchar(40) NOT NULL DEFAULT '',
			severity varchar(10) NOT NULL DEFAULT 'info',
			kind varchar(10) NOT NULL DEFAULT 'observed',
			data text NULL,
			status varchar(10) NOT NULL DEFAULT 'open',
			first_seen int(10) unsigned NOT NULL,
			last_seen int(10) unsigned NOT NULL,
			resolved_at int(10) unsigned NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY page_code (page_id,code,bot),
			KEY status (status,severity),
			KEY resolved (resolved_at)
		) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$t['alerts']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rule varchar(40) NOT NULL,
			dedupe varchar(190) NOT NULL,
			severity varchar(10) NOT NULL DEFAULT 'info',
			title varchar(255) NOT NULL DEFAULT '',
			what text NULL,
			why text NULL,
			affected text NULL,
			action text NULL,
			link varchar(255) NOT NULL DEFAULT '',
			status varchar(10) NOT NULL DEFAULT 'unread',
			created_at int(10) unsigned NOT NULL,
			sent_at int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY dedupe (dedupe,created_at),
			KEY status (status,id),
			KEY unsent (sent_at,severity)
		) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$t['snapshots']} (
			day date NOT NULL,
			metrics longtext NULL,
			PRIMARY KEY  (day)
		) {$c};"
		);

		// Redirects for published URLs that changed. Read only when WordPress is
		// about to answer 404, so ordinary page views never touch this table.
		dbDelta(
			"CREATE TABLE {$t['redirects']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_hash char(32) NOT NULL,
			source varchar(512) NOT NULL DEFAULT '',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reason varchar(10) NOT NULL DEFAULT 'slug',
			created_at int(10) unsigned NOT NULL,
			hits int(10) unsigned NOT NULL DEFAULT 0,
			bot_hits int(10) unsigned NOT NULL DEFAULT 0,
			last_hit int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY source_hash (source_hash),
			KEY post (post_id)
		) {$c};"
		);

		// Options the request path reads on every page view must exist and be
		// autoloaded, or WordPress queries the database for each missing one.
		add_option( Settings::OPTION, array(), '', 'yes' );
		add_option( Tracker::THROTTLE, array(), '', 'yes' );
		add_option( Ranges::IP_ONLY, array(), '', 'yes' );
		add_option( 'rfy_cf_ranges', Util::CLOUDFLARE, '', 'yes' ); // read when a request carries CF-Connecting-IP
		Aggregator::ensure_watermark();

		update_option( 'rfy_db_version', RFY_DB_VERSION, true );
		if ( ! get_option( 'rfy_secret' ) ) {
			update_option( 'rfy_secret', wp_generate_password( 48, false, false ), true );
		}
	}

	/** Drop everything this plugin created (uninstall). */
	public static function drop() {
		global $wpdb;
		foreach ( self::TABLES as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
