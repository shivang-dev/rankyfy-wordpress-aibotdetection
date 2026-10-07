<?php
/**
 * Posts list column: AI crawler requests in the last 30 days and the page's
 * readiness, sortable by requests. One narrow column, not four.
 *
 * The figures for every row of the screen come from one query, made when the
 * first row is drawn.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Columns {

	const KEY = 'rfaib_ai';

	private static $data = null;

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'posts_clauses', array( __CLASS__, 'sort' ), 10, 2 );
		add_action( 'admin_head-edit.php', array( __CLASS__, 'style' ) );
	}

	public static function register() {
		if ( ! Installer::ready() || ! current_user_can( Rest::capability() ) ) {
			return;
		}
		foreach ( Inventory::post_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'columns' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'render' ), 10, 2 );
			add_filter( "manage_edit-{$type}_sortable_columns", array( __CLASS__, 'sortable' ) );
		}
	}

	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out[ self::KEY ] = '<span title="' . esc_attr__( 'AI crawler requests in the last 30 days, and AI readiness', 'rankyfy-ai-crawlers' ) . '">' . esc_html__( 'AI', 'rankyfy-ai-crawlers' ) . '</span>';
			}
		}
		if ( ! isset( $out[ self::KEY ] ) ) {
			$out[ self::KEY ] = esc_html__( 'AI', 'rankyfy-ai-crawlers' );
		}
		return $out;
	}

	public static function sortable( $cols ) {
		$cols[ self::KEY ] = array( self::KEY, true );
		return $cols;
	}

	/** Figures for every post on the current list screen, in one query. */
	private static function data() {
		global $wpdb, $wp_query;
		if ( null !== self::$data ) {
			return self::$data;
		}
		self::$data = array();
		$ids        = array_map( 'intval', wp_list_pluck( (array) ( $wp_query->posts ?? array() ), 'ID' ) );
		if ( ! $ids ) {
			return self::$data;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.object_id, p.aeo_score, COALESCE(SUM(d.hits), 0) hits FROM ' . Installer::table( 'pages' ) . ' p
				 LEFT JOIN ' . Installer::table( 'daily_pages' ) . " d ON d.url_hash = p.url_hash AND d.day >= %s
				 WHERE p.object_type = 'post' AND p.deleted = 0 AND p.object_id IN (" . implode( ',', $ids ) . ')
				 GROUP BY p.object_id, p.aeo_score', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are integers
				wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			self::$data[ (int) $r['object_id'] ] = $r;
		}
		return self::$data;
	}

	public static function render( $col, $post_id ) {
		if ( self::KEY !== $col ) {
			return;
		}
		$r = self::data()[ (int) $post_id ] ?? null;
		if ( ! $r ) {
			echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Not checked yet', 'rankyfy-ai-crawlers' ) . '</span>';
			return;
		}
		$score = null === $r['aeo_score'] ? null : (int) $r['aeo_score'];
		$level = null === $score ? 'none' : ( $score >= 70 ? 'good' : ( $score >= 40 ? 'warn' : 'crit' ) );
		$word  = array(
			'good' => __( 'Good', 'rankyfy-ai-crawlers' ),
			'warn' => __( 'Needs work', 'rankyfy-ai-crawlers' ),
			'crit' => __( 'Poor', 'rankyfy-ai-crawlers' ),
			'none' => __( 'Not scored yet', 'rankyfy-ai-crawlers' ),
		)[ $level ];
		/* translators: 1: requests, 2: score, 3: word */
		$title = null === $score ? sprintf( __( '%s AI crawler requests in 30 days', 'rankyfy-ai-crawlers' ), number_format_i18n( (int) $r['hits'] ) ) : sprintf( __( '%1$s AI crawler requests in 30 days · readiness %2$d (%3$s)', 'rankyfy-ai-crawlers' ), number_format_i18n( (int) $r['hits'] ), $score, $word );
		echo '<span class="rfaib-col" title="' . esc_attr( $title ) . '"><i class="rfaib-dot rfaib-dot-' . esc_attr( $level ) . '" aria-hidden="true"></i>' . esc_html( number_format_i18n( (int) $r['hits'] ) ) . '<span class="screen-reader-text"> ' . esc_html( $title ) . '</span></span>';
	}

	/** Sort the list by AI crawler requests in the last 30 days. */
	public static function sort( $clauses, $query ) {
		global $wpdb;
		if ( ! is_admin() || ! $query->is_main_query() || self::KEY !== $query->get( 'orderby' ) || ! Installer::ready() ) {
			return $clauses;
		}
		$order              = 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC';
		$clauses['join']   .= ' LEFT JOIN ' . Installer::table( 'pages' ) . " rfaib_p ON rfaib_p.object_type = 'post' AND rfaib_p.object_id = {$wpdb->posts}.ID AND rfaib_p.deleted = 0"
			. ' LEFT JOIN (SELECT url_hash, SUM(hits) hits FROM ' . Installer::table( 'daily_pages' ) . $wpdb->prepare( ' WHERE day >= %s', wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS ) ) . ' GROUP BY url_hash) rfaib_h ON rfaib_h.url_hash = rfaib_p.url_hash';
		$clauses['orderby'] = "COALESCE(rfaib_h.hits, 0) {$order}, {$wpdb->posts}.post_date DESC";
		return $clauses;
	}

	public static function style() {
		echo '<style>.column-rfaib_ai{width:64px}.rfaib-col{display:inline-flex;align-items:center;gap:6px;font-variant-numeric:tabular-nums}.rfaib-dot{width:8px;height:8px;border-radius:50%;background:#c3c4c7;display:inline-block}.rfaib-dot-good{background:#0ca30c}.rfaib-dot-warn{background:#c98500}.rfaib-dot-crit{background:#d03b3b}</style>';
	}
}
