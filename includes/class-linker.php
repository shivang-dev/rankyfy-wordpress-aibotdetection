<?php
/**
 * Internal-link suggestions: for an important page, which existing pages
 * talk about the same things but do not link to it yet, and with what
 * anchor text. Uses the term index (term → pages), so it never compares all
 * pages with each other — one indexed query per target page.
 *
 * Computed from the site's own content (observed), not by an AI model.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Linker {

	const OPTION = 'rfaib_link_ops';

	/** Suggested source pages for one target page. */
	public static function for_page( $page_id, $limit = 5 ) {
		global $wpdb;
		$n     = Analyzer::analyzed_count();
		$terms = array();
		foreach ( Analyzer::key_terms( $page_id, 12 ) as $t ) {
			// Terms used by one page only cannot connect pages; ones on most pages say nothing.
			if ( $t['pages'] >= 2 && $t['pages'] <= max( 3, $n * 0.3 ) ) {
				$terms[ $t['term'] ] = $t['score'];
			}
			if ( count( $terms ) >= 6 ) {
				break;
			}
		}
		if ( ! $terms ) {
			return array();
		}
		$in   = implode( ',', array_fill( 0, count( $terms ), '%s' ) );
		$pt   = Installer::table( 'page_terms' );
		$p    = Installer::table( 'pages' );
		$l    = Installer::table( 'links' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pt.page_id, SUM(pt.weight) s, GROUP_CONCAT(pt.term ORDER BY pt.weight DESC SEPARATOR '|') shared, p.path, p.title, p.object_type, p.object_id
				 FROM {$pt} pt JOIN {$p} p ON p.id = pt.page_id
				 LEFT JOIN {$l} l ON l.from_id = pt.page_id AND l.to_id = %d
				 WHERE pt.term IN ({$in}) AND pt.page_id <> %d AND p.deleted = 0 AND l.from_id IS NULL
				 GROUP BY pt.page_id ORDER BY COUNT(*) DESC, s DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( (int) $page_id ), array_keys( $terms ), array( (int) $page_id, (int) $limit ) )
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$shared = explode( '|', (string) $r['shared'] );
			// Prefer a phrase as anchor text.
			usort( $shared, static function ( $a, $b ) use ( $terms ) {
				return ( substr_count( $b, ' ' ) <=> substr_count( $a, ' ' ) ) ?: ( ( $terms[ $b ] ?? 0 ) <=> ( $terms[ $a ] ?? 0 ) );
			} );
			$out[] = array(
				'from_id'     => (int) $r['page_id'],
				'from_path'   => $r['path'],
				'from_title'  => $r['title'],
				'edit'        => 'post' === $r['object_type'] ? get_edit_post_link( (int) $r['object_id'], 'raw' ) : null,
				'anchor'      => $shared[0],
				'shared'      => array_slice( $shared, 0, 4 ),
			);
		}
		return $out;
	}

	/** Daily: suggestions for the important pages that need links most. */
	public static function rebuild( $budget = 8 ) {
		global $wpdb;
		$deadline = microtime( true ) + $budget;
		$targets  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, path, title, inlinks, importance FROM ' . Installer::table( 'pages' ) . " WHERE deleted = 0 AND analyzed_at > 0 AND (importance >= %d OR pinned > 0) AND inlinks < 5 AND object_type = 'post' ORDER BY importance DESC, inlinks ASC LIMIT 60", (int) Settings::get( 'importance_min' ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$ops = array();
		foreach ( (array) $targets as $t ) {
			if ( microtime( true ) > $deadline ) {
				break;
			}
			$s = self::for_page( (int) $t['id'], 3 );
			if ( $s ) {
				$ops[] = array(
					'page_id'     => (int) $t['id'],
					'path'        => $t['path'],
					'title'       => $t['title'],
					'inlinks'     => (int) $t['inlinks'],
					'suggestions' => $s,
				);
			}
		}
		update_option( self::OPTION, array( 'at' => time(), 'items' => $ops ), false );
		return count( $ops );
	}

	public static function opportunities() {
		$o = get_option( self::OPTION );
		return is_array( $o ) ? $o : array( 'at' => 0, 'items' => array() );
	}
}
