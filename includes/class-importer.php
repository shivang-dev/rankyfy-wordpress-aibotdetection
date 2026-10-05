<?php
/**
 * Server access-log import.
 *
 * Pages served from a full-page cache or CDN never reach WordPress, so the
 * live tracker cannot see them. The site owner can import an access log
 * instead: the browser reads and parses the file locally (assets/js/
 * log-worker.js — the file is never uploaded), keeps only lines from
 * automated clients and sends them here in batches.
 *
 * Nothing the browser says is trusted: every line is re-validated and
 * re-classified with the same detector and verification as live requests,
 * and lines already recorded live are skipped by the events dedup key.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Importer {

	const BATCH = 2000;

	/** @return array counts */
	public static function batch( array $lines ) {
		global $wpdb;
		// One transaction per batch: one disk sync instead of one per line.
		$wpdb->query( 'START TRANSACTION' );
		try {
			$out = self::batch_tx( $lines );
			$wpdb->query( 'COMMIT' );
			return $out;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	private static function batch_tx( array $lines ) {
		global $wpdb;
		$m      = Registry::matcher();
		$oldest = time() - DAY_IN_SECONDS * (int) Settings::get( 'retention_history' );
		$out    = array( 'received' => count( $lines ), 'events' => 0, 'counted' => 0, 'duplicates' => 0, 'humans' => 0, 'referrals' => 0, 'invalid' => 0, 'too_old' => 0 );
		foreach ( $lines as $l ) {
			if ( ! is_array( $l ) ) {
				$out['invalid']++;
				continue;
			}
			$ts     = (int) ( $l['ts'] ?? 0 );
			$ip     = (string) ( $l['ip'] ?? '' );
			$path   = (string) ( $l['path'] ?? '' );
			$status = (int) ( $l['status'] ?? 0 );
			$ua     = (string) ( $l['ua'] ?? '' );
			if ( $ts <= 0 || $ts > time() + 300 || ! filter_var( $ip, FILTER_VALIDATE_IP ) || '' === $path || $status < 100 || $status > 999 || strlen( $ua ) > 2000 || strlen( $path ) > 4000 ) {
				$out['invalid']++;
				continue;
			}
			if ( $ts < $oldest ) {
				$out['too_old']++;
				continue;
			}
			$norm = Util::normalize_path( $path );
			$ctx  = array(
				'path'   => $norm,
				'hash'   => Util::url_hash( $norm ),
				'kind'   => Util::path_kind( $norm ),
				'status' => $status,
				'ms'     => max( 0, min( 600000, (int) ( $l['ms'] ?? 0 ) ) ),
				'otype'  => '',
				'oid'    => 0,
				'method' => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $l['method'] ?? 'GET' ) ), 0, 7 ) ) ?: 'GET',
				'ts'     => $ts,
			);
			$r = Detector::match( $ua, $m, $ip, '' );
			if ( 'human' === $r['cls'] ) {
				$out['humans']++;
				$ref = (string) ( $l['referer'] ?? '' );
				if ( $ref && Settings::get( 'track_referrals' ) && $status < 400 && 'GET' === $ctx['method'] && '' === $ctx['kind'] ) {
					$host = strtolower( (string) wp_parse_url( $ref, PHP_URL_HOST ) );
					if ( isset( $m['ref'][ $host ] ) ) {
						$wpdb->query(
							$wpdb->prepare(
								'INSERT INTO ' . Installer::table( 'referrals' ) . ' (day, source, url_hash, path, object_id, hits) VALUES (%s, %s, %s, %s, 0, 1) ON DUPLICATE KEY UPDATE hits = hits + 1',
								Util::day( $ts ),
								$m['ref'][ $host ],
								$ctx['hash'],
								$norm
							)
						);
						$out['referrals']++;
					}
				}
				continue;
			}
			$r['ua'] = $ua;
			$res     = Tracker::store( $r, $ctx, $ip, 'log' );
			if ( 'event' === $res ) {
				$out['events']++;
			} elseif ( 'counted' === $res ) {
				$out['counted']++;
			} else {
				$out['duplicates']++;
			}
		}
		$st = get_option( 'rfaib_import', array() );
		$st = is_array( $st ) ? $st : array();
		update_option(
			'rfaib_import',
			array(
				'at'     => time(),
				'events' => (int) ( $st['events'] ?? 0 ) + $out['events'],
				'lines'  => (int) ( $st['lines'] ?? 0 ) + $out['received'],
			),
			false
		);
		return $out;
	}
}
