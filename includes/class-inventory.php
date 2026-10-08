<?php
/**
 * The site's page inventory: every public URL WordPress serves (posts,
 * pages, products, custom post types, taxonomy archives, the home page),
 * so "never crawled" can be answered, and how important each one is.
 *
 * Built in batches by the worker (a full pass when the plugin is activated
 * and weekly as a backstop) and kept current by content hooks — a save in
 * the editor only marks one row; the expensive work happens later.
 *
 * Importance (0–100) uses signals the site owner already gave WordPress:
 * front page and shop page, menus, cornerstone/pillar flags from SEO
 * plugins, internal links pointing at the page, sales, comments, visits
 * from AI assistants, freshness. The owner can pin a page as important or
 * exclude it.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Inventory {

	const STATE = 'rfy_inventory';
	const BATCH = 300;

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_status' ), 20, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'saved_term', array( __CLASS__, 'on_term' ), 20, 3 );
		add_action( 'delete_term', array( __CLASS__, 'on_term_delete' ), 20, 3 );
		add_action( 'wp_update_nav_menu', array( __CLASS__, 'forget_signals' ) );
	}

	// ── what counts as a page ──────────────────────────────────────────────

	public static function post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( apply_filters( 'rfy_post_types', $types ) );
	}

	public static function taxonomies() {
		$tax = get_taxonomies( array( 'public' => true, 'publicly_queryable' => true ), 'names' );
		unset( $tax['post_format'] );
		return array_values( apply_filters( 'rfy_taxonomies', $tax ) );
	}

	private static function post_is_page( $post ) {
		return $post instanceof \WP_Post
			&& 'publish' === $post->post_status
			&& '' === $post->post_password
			&& in_array( $post->post_type, self::post_types(), true )
			&& ( ! function_exists( 'is_post_publicly_viewable' ) || is_post_publicly_viewable( $post ) );
	}

	// ── hooks ──────────────────────────────────────────────────────────────

	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! Installer::ready() ) {
			return;
		}
		if ( self::post_is_page( $post ) ) {
			self::upsert_post( $post, null );
		} else {
			self::mark_deleted( 'post', $post_id );
		}
	}

	public static function on_status( $new, $old, $post ) {
		if ( 'publish' === $old && 'publish' !== $new && Installer::ready() ) {
			self::mark_deleted( 'post', $post->ID );
		}
	}

	public static function on_delete( $post_id ) {
		if ( Installer::ready() ) {
			self::mark_deleted( 'post', $post_id );
		}
	}

	public static function on_term( $term_id, $tt_id, $taxonomy ) {
		if ( Installer::ready() && in_array( $taxonomy, self::taxonomies(), true ) ) {
			$term = get_term( $term_id, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				self::upsert_term( $term, null );
			}
		}
	}

	public static function on_term_delete( $term_id, $tt_id, $taxonomy ) {
		if ( Installer::ready() ) {
			self::mark_deleted( 'term', $term_id );
		}
	}

	private static function mark_deleted( $type, $id ) {
		global $wpdb;
		$wpdb->update( Installer::table( 'pages' ), array( 'deleted' => 1 ), array( 'object_type' => $type, 'object_id' => (int) $id ) );
	}

	// ── upserts ────────────────────────────────────────────────────────────

	private static function upsert( array $row, $gen ) {
		global $wpdb;
		$t        = Installer::table( 'pages' );
		$row['url_hash'] = Util::url_hash( $row['path'] );
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, url_hash, modified, deleted FROM {$t} WHERE object_type = %s AND object_id = %d AND object_type <> 'home' LIMIT 1", $row['object_type'], $row['object_id'] ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( ! $existing ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, url_hash, modified, deleted FROM {$t} WHERE url_hash = %s", $row['url_hash'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$fields = array(
			'object_type' => $row['object_type'],
			'object_id'   => (int) $row['object_id'],
			'subtype'     => substr( $row['subtype'], 0, 40 ),
			'url_hash'    => $row['url_hash'],
			'path'        => $row['path'],
			'title'       => Util::clean( $row['title'], 255 ),
			'modified'    => $row['modified'],
			'deleted'     => 0,
		);
		if ( null !== $gen ) {
			$fields['gen'] = (int) $gen;
		}
		// A save from the editor ($gen null) always needs re-analysis; a rebuild
		// pass only re-analyses rows that are new, moved or modified since.
		$changed = null === $gen || ! $existing || $existing['url_hash'] !== $row['url_hash'] || (int) $existing['deleted']
			|| (string) $existing['modified'] !== (string) $row['modified'];
		if ( $changed ) {
			$fields['dirty'] = 1;
		}
		if ( $existing ) {
			if ( $existing['url_hash'] !== $row['url_hash'] ) {
				// The slug changed. Free the new URL if a stale row holds it.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE url_hash = %s AND id <> %d", $row['url_hash'], $existing['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$wpdb->update( $t, $fields, array( 'id' => (int) $existing['id'] ) );
			return (int) $existing['id'];
		}
		$wpdb->insert( $t, $fields );
		return (int) $wpdb->insert_id;
	}

	public static function upsert_post( \WP_Post $post, $gen ) {
		$url = get_permalink( $post );
		if ( ! $url ) {
			return 0;
		}
		return self::upsert(
			array(
				'object_type'  => 'post',
				'object_id'    => $post->ID,
				'subtype'      => $post->post_type,
				'path'         => Util::normalize_path( $url ),
				'title'        => get_the_title( $post ),
				'modified'     => $post->post_modified_gmt && '0000-00-00 00:00:00' !== $post->post_modified_gmt ? $post->post_modified_gmt : null,
			),
			$gen
		);
	}

	public static function upsert_term( \WP_Term $term, $gen ) {
		if ( (int) $term->count < 1 ) {
			self::mark_deleted( 'term', $term->term_id );
			return 0;
		}
		$url = get_term_link( $term );
		if ( is_wp_error( $url ) ) {
			return 0;
		}
		return self::upsert(
			array(
				'object_type' => 'term',
				'object_id'   => $term->term_id,
				'subtype'     => $term->taxonomy,
				'path'        => Util::normalize_path( $url ),
				'title'       => $term->name,
				'modified'    => null,
			),
			$gen
		);
	}

	private static function upsert_home( $gen ) {
		if ( 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ) {
			return 0; // the front page is a normal page row
		}
		return self::upsert(
			array(
				'object_type' => 'home',
				'object_id'   => 0,
				'subtype'     => 'home',
				'path'        => Util::normalize_path( home_url( '/' ) ),
				'title'       => get_bloginfo( 'name' ),
				'modified'    => null,
			),
			$gen
		);
	}

	// ── full rebuild, in batches ───────────────────────────────────────────

	public static function request_rebuild() {
		$st = get_option( self::STATE, array() );
		$st = is_array( $st ) ? $st : array();
		update_option(
			self::STATE,
			array(
				'gen'      => (int) ( $st['gen'] ?? 0 ) + 1,
				'phase'    => 'posts',
				'cursor'   => 0,
				'started'  => time(),
				'finished' => (int) ( $st['finished'] ?? 0 ),
				'running'  => true,
			),
			false
		);
		self::forget_signals();
	}

	public static function request_rebuild_if_stale() {
		$st = get_option( self::STATE, array() );
		if ( empty( $st['running'] ) && time() - (int) ( $st['finished'] ?? 0 ) > WEEK_IN_SECONDS ) {
			self::request_rebuild();
		}
	}

	public static function status() {
		$st = get_option( self::STATE, array() );
		return is_array( $st ) ? $st : array();
	}

	/** Advance a running rebuild. */
	public static function step( $budget = 4 ) {
		global $wpdb;
		$st = self::status();
		if ( empty( $st['running'] ) ) {
			return;
		}
		$deadline = microtime( true ) + $budget;
		$gen      = (int) $st['gen'];
		while ( microtime( true ) < $deadline && ! empty( $st['running'] ) ) {
			if ( 'posts' === $st['phase'] ) {
				$types = self::post_types();
				if ( ! $types ) {
					$st['phase']  = 'terms';
					$st['cursor'] = 0;
					continue;
				}
				$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN ({$in}) AND ID > %d ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						array_merge( $types, array( (int) $st['cursor'], self::BATCH ) )
					)
				);
				if ( ! $ids ) {
					$st['phase']  = 'terms';
					$st['cursor'] = 0;
					continue;
				}
				_prime_post_caches( array_map( 'intval', $ids ), false, false );
				foreach ( $ids as $id ) {
					$post = get_post( (int) $id );
					if ( self::post_is_page( $post ) ) {
						self::upsert_post( $post, $gen );
					}
					$st['cursor'] = (int) $id;
				}
			} elseif ( 'terms' === $st['phase'] ) {
				$tax = self::taxonomies();
				$terms = $tax ? $wpdb->get_results(
					$wpdb->prepare(
						"SELECT t.term_id, tt.taxonomy FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
						 WHERE tt.taxonomy IN (" . implode( ',', array_fill( 0, count( $tax ), '%s' ) ) . ') AND tt.count > 0 AND t.term_id > %d ORDER BY t.term_id ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						array_merge( $tax, array( (int) $st['cursor'], self::BATCH ) )
					),
					ARRAY_A
				) : array();
				if ( ! $terms ) {
					$st['phase'] = 'finish';
					continue;
				}
				foreach ( $terms as $tr ) {
					$term = get_term( (int) $tr['term_id'], $tr['taxonomy'] );
					if ( $term instanceof \WP_Term ) {
						self::upsert_term( $term, $gen );
					}
					$st['cursor'] = (int) $tr['term_id'];
				}
			} else {
				self::upsert_home( $gen );
				// Rows this pass did not see are gone (unpublished, deleted, renamed).
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . Installer::table( 'pages' ) . ' SET deleted = 1 WHERE gen < %d AND deleted = 0', $gen ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- table names come from the fixed rfy_ prefix (Installer::table), never from user input
				$st['running']  = false;
				$st['finished'] = time();
				Log::info( 'page inventory rebuilt', array( 'pages' => self::count() ) );
			}
		}
		update_option( self::STATE, $st, false );
	}

	/** The real URL of a page row (permalinks keep their trailing slash and query style). */
	public static function url( array $row ) {
		if ( 'post' === $row['object_type'] && $row['object_id'] ) {
			$u = get_permalink( (int) $row['object_id'] );
		} elseif ( 'term' === $row['object_type'] && $row['object_id'] ) {
			$u = get_term_link( (int) $row['object_id'], (string) $row['subtype'] );
		} else {
			$u = '/' === $row['path'] ? home_url( '/' ) : null;
		}
		return ( $u && ! is_wp_error( $u ) ) ? $u : home_url( user_trailingslashit( rawurldecode( $row['path'] ) ) );
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( 'pages' ) . ' WHERE deleted = 0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	// ── importance ─────────────────────────────────────────────────────────

	public static function forget_signals() {
		delete_transient( 'rfy_signals' );
	}

	/** Site-wide signals, computed once and cached. */
	public static function signals() {
		$s = get_transient( 'rfy_signals' );
		if ( is_array( $s ) ) {
			return $s;
		}
		$menu = array();
		foreach ( wp_get_nav_menus() as $m ) {
			foreach ( (array) wp_get_nav_menu_items( $m->term_id, array( 'update_post_term_cache' => false ) ) as $item ) {
				if ( 'post_type' === $item->type ) {
					$menu[ 'post:' . (int) $item->object_id ] = 1;
				} elseif ( 'taxonomy' === $item->type ) {
					$menu[ 'term:' . (int) $item->object_id ] = 1;
				}
			}
		}
		$s = array(
			'menu'       => $menu,
			'front'      => 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0,
			'posts_page' => (int) get_option( 'page_for_posts' ),
			'shop'       => function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0,
		);
		set_transient( 'rfy_signals', $s, 6 * HOUR_IN_SECONDS );
		return $s;
	}

	/**
	 * Importance of one page row.
	 *
	 * @param array $p     pages row (object_type, object_id, subtype, inlinks, word_count, modified, pinned, url_hash)
	 * @param array $extra referrals (AI-assistant visits, 30 days)
	 * @return array{0:int,1:string[]} score and reasons
	 */
	public static function importance( array $p, array $extra = array() ) {
		if ( (int) $p['pinned'] < 0 ) {
			return array( 0, array( 'excluded' ) );
		}
		if ( (int) $p['pinned'] > 0 ) {
			return array( 100, array( 'pinned' ) );
		}
		$s       = self::signals();
		$score   = 0;
		$reasons = array();
		$add     = static function ( $n, $why ) use ( &$score, &$reasons ) {
			$score    += $n;
			$reasons[] = $why;
		};
		$type = $p['object_type'];
		$id   = (int) $p['object_id'];
		if ( 'home' === $type || ( 'post' === $type && $id && $id === $s['front'] ) ) {
			$add( 60, 'home' );
		}
		if ( 'post' === $type && $id && ( $id === $s['shop'] || $id === $s['posts_page'] ) ) {
			$add( 30, 'hub' );
		}
		if ( isset( $s['menu'][ $type . ':' . $id ] ) ) {
			$add( 25, 'menu' );
		}
		if ( 'post' === $type && $id ) {
			if ( get_post_meta( $id, '_yoast_wpseo_is_cornerstone', true ) || 'on' === get_post_meta( $id, 'rank_math_pillar_content', true ) ) {
				$add( 30, 'cornerstone' );
			}
			if ( 'product' === $p['subtype'] ) {
				$sales = (int) get_post_meta( $id, 'total_sales', true );
				$add( 10, 'product' );
				if ( $sales > 0 ) {
					$add( min( 15, (int) round( log( 1 + $sales, 2 ) * 2 ) ), 'sales' );
				}
			}
			if ( 'page' === $p['subtype'] ) {
				$add( 10, 'page' );
			}
		}
		if ( 'term' === $type ) {
			$add( 5, 'archive' );
		}
		$in = (int) ( $p['inlinks'] ?? 0 );
		if ( $in > 0 ) {
			$add( min( 25, $in * 3 ), 'inlinks' );
		}
		$ai_visits = (int) ( $extra['referrals'] ?? 0 );
		if ( $ai_visits > 0 ) {
			$add( min( 20, 5 + $ai_visits ), 'ai_referrals' );
		}
		if ( (int) ( $p['word_count'] ?? 0 ) >= 1000 ) {
			$add( 5, 'in_depth' );
		}
		if ( ! empty( $p['modified'] ) && strtotime( $p['modified'] . ' UTC' ) > time() - 90 * DAY_IN_SECONDS ) {
			$add( 5, 'fresh' );
		}
		return array( max( 0, min( 99, $score ) ), $reasons );
	}

	public static function set_pin( $page_id, $pin ) {
		global $wpdb;
		$pin = max( -1, min( 1, (int) $pin ) );
		$wpdb->update( Installer::table( 'pages' ), array( 'pinned' => $pin, 'dirty' => 1 ), array( 'id' => (int) $page_id ) );
		Coverage::refresh_pages( array( (int) $page_id ) );
	}
}
