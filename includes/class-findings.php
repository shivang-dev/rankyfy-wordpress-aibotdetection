<?php
/**
 * Findings: issues found on a page (or site-wide, page_id 0) with a
 * lifecycle. A process re-states everything it owns for a page; what it no
 * longer reports is marked resolved (with the date), which is how "fixed
 * issues" history and "previously fixed" trends are kept.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Findings {

	/**
	 * Replace the open findings a scope owns for one page.
	 *
	 * @param int    $page_id 0 for site-wide.
	 * @param string $scope   Catalog scope that owns these codes.
	 * @param array  $current list of [code, data(array), bot(string)]
	 * @return array newly opened [code, bot]
	 */
	public static function sync( $page_id, $scope, array $current ) {
		global $wpdb;
		$t     = Installer::table( 'findings' );
		$codes = Catalog::codes_in_scope( $scope );
		if ( ! $codes ) {
			return array();
		}
		$in       = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
		$existing = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, code, bot, status FROM {$t} WHERE page_id = %d AND code IN ({$in})", array_merge( array( (int) $page_id ), $codes ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$by_key = array();
		foreach ( (array) $existing as $row ) {
			$by_key[ $row['code'] . '|' . $row['bot'] ] = $row;
		}
		$now    = time();
		$seen   = array();
		$opened = array();
		foreach ( $current as $f ) {
			list( $code, $data, $bot ) = array( $f[0], $f[1] ?? array(), (string) ( $f[2] ?? '' ) );
			$meta = Catalog::meta( $code );
			$key  = $code . '|' . $bot;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$severity     = $data['_severity'] ?? $meta['severity'];
			unset( $data['_severity'] );
			$row = $by_key[ $key ] ?? null;
			if ( $row ) {
				if ( 'ignored' === $row['status'] ) {
					$wpdb->update( $t, array( 'last_seen' => $now, 'data' => wp_json_encode( $data ) ), array( 'id' => $row['id'] ) );
					continue;
				}
				if ( 'resolved' === $row['status'] ) {
					$opened[] = array( $code, $bot ); // came back
				}
				$wpdb->update(
					$t,
					array(
						'status'      => 'open',
						'severity'    => $severity,
						'data'        => wp_json_encode( $data ),
						'last_seen'   => $now,
						'resolved_at' => null,
					),
					array( 'id' => $row['id'] )
				);
			} else {
				$wpdb->insert(
					$t,
					array(
						'page_id'    => (int) $page_id,
						'code'       => $code,
						'bot'        => $bot,
						'severity'   => $severity,
						'kind'       => $meta['kind'],
						'data'       => wp_json_encode( $data ),
						'status'     => 'open',
						'first_seen' => $now,
						'last_seen'  => $now,
					)
				);
				$opened[] = array( $code, $bot );
			}
		}
		foreach ( $by_key as $key => $row ) {
			if ( ! isset( $seen[ $key ] ) && 'open' === $row['status'] ) {
				$wpdb->update( $t, array( 'status' => 'resolved', 'resolved_at' => $now ), array( 'id' => $row['id'] ) );
			}
		}
		return $opened;
	}

	public static function set_status( $id, $status ) {
		global $wpdb;
		if ( ! in_array( $status, array( 'open', 'ignored' ), true ) ) {
			return false;
		}
		return false !== $wpdb->update( Installer::table( 'findings' ), array( 'status' => $status ), array( 'id' => (int) $id ) );
	}

	/** Finding rows → display records (with catalog text). */
	public static function present( array $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			$data = json_decode( (string) $r['data'], true );
			$data = is_array( $data ) ? $data : array();
			if ( $r['bot'] ) {
				$data['bot'] = $r['bot'];
			}
			$text  = Catalog::text( $r['code'], $data );
			$meta  = Catalog::meta( $r['code'] );
			$out[] = array(
				'id'          => (int) $r['id'],
				'page_id'     => (int) $r['page_id'],
				'code'        => $r['code'],
				'bot'         => $r['bot'],
				'severity'    => $r['severity'],
				'kind'        => $r['kind'],
				'group'       => $meta['group'],
				'status'      => $r['status'],
				'title'       => $text['title'],
				'why'         => $text['why'],
				'action'      => $text['action'],
				'items'       => array_slice( (array) ( $data['items'] ?? array() ), 0, 20 ),
				'first_seen'  => (int) $r['first_seen'],
				'last_seen'   => (int) $r['last_seen'],
				'resolved_at' => $r['resolved_at'] ? (int) $r['resolved_at'] : null,
				'path'        => $r['path'] ?? null,
				'page_title'  => $r['page_title'] ?? null,
				'importance'  => isset( $r['importance'] ) ? (int) $r['importance'] : null,
				'object_type' => $r['object_type'] ?? null,
				'object_id'   => isset( $r['object_id'] ) ? (int) $r['object_id'] : null,
			);
		}
		return $out;
	}

	public static function for_page( $page_id, $status = 'open' ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'findings' ) . ' WHERE page_id = %d AND status = %s ORDER BY FIELD(severity, \'critical\', \'warning\', \'info\'), id', (int) $page_id, $status ),
			ARRAY_A
		);
		return self::present( (array) $rows );
	}

	public static function site( $status = 'open' ) {
		return self::for_page( 0, $status );
	}

	public static function counts() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT f.severity, f.kind, COUNT(*) c FROM ' . Installer::table( 'findings' ) . ' f LEFT JOIN ' . Installer::table( 'pages' ) . " p ON p.id = f.page_id
			 WHERE f.status = 'open' AND (f.page_id = 0 OR p.deleted = 0) GROUP BY f.severity, f.kind", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		$out = array( 'critical' => 0, 'warning' => 0, 'info' => 0, 'observed' => 0, 'inferred' => 0, 'total' => 0 );
		foreach ( (array) $rows as $r ) {
			$out[ $r['severity'] ] += (int) $r['c'];
			$out[ $r['kind'] ]     += (int) $r['c'];
			$out['total']          += (int) $r['c'];
		}
		$out['resolved_30d'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::table( 'findings' ) . " WHERE status = 'resolved' AND resolved_at > %d", time() - 30 * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $out;
	}
}
