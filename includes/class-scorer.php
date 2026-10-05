<?php
/**
 * AI search readiness (AEO) score. Transparent on purpose: every point maps
 * to something on the page or in the crawl data, and the breakdown is shown
 * with the score. It is not a prediction of rankings or citations.
 *
 *   Access     30  crawlers may and can read the page (robots, noindex, status, canonical)
 *   Discovery  15  AI crawlers actually reach it (observed requests), internal links
 *   Structure  20  headings, question headings, direct answer, lists/tables, FAQ
 *   Depth      15  substance for the page type
 *   Trust      12  structured data, author, freshness
 *   Linking     8  pages linking to it
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Scorer {

	const MAX = array(
		'access'    => 30,
		'discovery' => 15,
		'structure' => 20,
		'depth'     => 15,
		'trust'     => 12,
		'linking'   => 8,
	);

	/**
	 * @param array $p     pages row
	 * @param array $f     facts
	 * @param array $crawl ['ai_search' => bool, 'ai_any' => bool, 'learning' => bool]
	 * @param array $blocked priority bots blocked by robots.txt for this page
	 * @return array{score:int,parts:array}
	 */
	public static function page( array $p, array $f, array $crawl, array $blocked ) {
		$parts = array();

		// Access.
		$a = 30;
		if ( ! empty( $f['noindex'] ) ) {
			$a -= 25;
		}
		if ( ! empty( $f['status'] ) && $f['status'] >= 400 ) {
			$a = 0;
		} elseif ( ! empty( $f['status'] ) && $f['status'] >= 300 ) {
			$a -= 10;
		}
		if ( ! empty( $f['canonical_elsewhere'] ) ) {
			$a -= 10;
		}
		$a                -= min( 20, count( $blocked ) * 7 );
		$parts['access']   = max( 0, $a );

		// Discovery (observed crawl).
		if ( ! empty( $crawl['ai_search'] ) ) {
			$d = 15;
		} elseif ( ! empty( $crawl['ai_any'] ) ) {
			$d = 10;
		} elseif ( ! empty( $crawl['learning'] ) ) {
			$d = 8; // not enough history yet to judge
		} else {
			$d = 0;
		}
		$parts['discovery'] = $d;

		// Structure.
		$words = (int) ( $f['words'] ?? 0 );
		$s     = 0;
		$s    += ( ( $f['h2'] ?? 0 ) + ( $f['h3'] ?? 0 ) ) >= 2 || $words < 300 ? 5 : 0;
		$s    += ! empty( $f['question_headings'] ) ? 4 : 0;
		$fp    = (int) ( $f['first_para_words'] ?? 0 );
		$s    += ( $fp >= 10 && $fp <= 80 ) ? 4 : 0;
		$s    += ( ( $f['lists'] ?? 0 ) + ( $f['tables'] ?? 0 ) ) > 0 ? 3 : 0;
		$s    += ! empty( $f['faq'] ) || in_array( 'FAQPage', (array) ( $f['schema'] ?? array() ), true ) ? 4 : 0;
		$parts['structure'] = $s;

		// Depth, relative to the page type.
		$short = in_array( $p['subtype'], array( 'product' ), true ) || 'term' === $p['object_type'];
		$steps = $short ? array( 400 => 15, 250 => 12, 150 => 9, 80 => 5 ) : array( 1200 => 15, 800 => 12, 500 => 9, 300 => 5 );
		$dep   = 2;
		foreach ( $steps as $min => $pts ) {
			if ( $words >= $min ) {
				$dep = $pts;
				break;
			}
		}
		$parts['depth'] = $dep;

		// Trust.
		$schema = (array) ( $f['schema'] ?? array() );
		$t      = $schema ? 5 : 0;
		$author = $f['author'] ?? null;
		$t     += ( null === $author || ! empty( $author['bio'] ) ) ? 3 : 0;
		$age    = $f['modified_days'] ?? null;
		$t     += ( null === $age || $age <= 365 ) ? 4 : ( $age <= 730 ? 2 : 0 );
		$parts['trust'] = $t;

		// Linking.
		$in               = (int) ( $p['inlinks'] ?? 0 );
		$home             = 'home' === $p['object_type'] || in_array( 'home', explode( ',', (string) $p['imp_reasons'] ), true );
		$parts['linking'] = $home ? 8 : ( $in >= 3 ? 8 : ( $in >= 1 ? 4 : 0 ) );

		return array(
			'score' => (int) array_sum( $parts ),
			'parts' => $parts,
		);
	}

	/**
	 * Site score: importance-weighted mean of page scores, minus site-wide
	 * blocks that keep AI search crawlers out entirely.
	 */
	public static function site() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT importance, aeo_score, aeo FROM ' . Installer::table( 'pages' ) . ' WHERE deleted = 0 AND aeo_score IS NOT NULL AND pinned >= 0', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $rows ) {
			return null;
		}
		$sum   = 0;
		$wsum  = 0;
		$parts = array_fill_keys( array_keys( self::MAX ), 0 );
		foreach ( $rows as $r ) {
			$w     = 1 + (int) $r['importance'];
			$sum  += $w * (int) $r['aeo_score'];
			$wsum += $w;
			$b     = json_decode( (string) $r['aeo'], true );
			foreach ( $parts as $k => $_ ) {
				$parts[ $k ] += $w * (float) ( $b[ $k ] ?? 0 );
			}
		}
		foreach ( $parts as $k => $v ) {
			$parts[ $k ] = round( $v / $wsum, 1 );
		}
		$score   = $sum / $wsum;
		$penalty = 0;
		$matrix  = Robots::matrix();
		foreach ( (array) ( $matrix['bots'] ?? array() ) as $id => $m ) {
			$b = Registry::get( $id );
			if ( $b && ! $m['site_allowed'] && in_array( $b['category'], array( 'ai_search', 'search' ), true ) && self::is_priority( $id ) ) {
				$penalty += 10;
			}
		}
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			$penalty += 30;
		}
		return array(
			'score'   => (int) max( 0, round( $score - min( 40, $penalty ) ) ),
			'parts'   => $parts,
			'max'     => self::MAX,
			'pages'   => count( $rows ),
			'penalty' => min( 40, $penalty ),
		);
	}

	public static function is_priority( $bot ) {
		static $list = null;
		if ( null === $list ) {
			$list = array_flip( array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'priority_bots' ) ) ) ) );
		}
		return isset( $list[ $bot ] );
	}
}
