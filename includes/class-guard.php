<?php
/**
 * Publish-time guard: catches, before or as a page goes live, the mistakes
 * that keep it out of AI search.
 *
 *   indexing     noindex from the SEO plugin (page or post type) or the
 *                site-wide "discourage search engines" setting
 *   robots       robots.txt rules that block priority AI crawlers from the URL
 *   canonical    a canonical URL pointing at another page
 *   url_change   a published URL that changes (slug or parent): AI crawlers
 *                and assistants still have the old one, so a 301 is added
 *   content      thin content, or an important page that lost most of its text
 *
 * Where it runs:
 *   - block editor: a pre-publish panel (mode "confirm" holds the Publish
 *     button until critical problems are acknowledged), a sidebar panel and
 *     a notice after an update;
 *   - classic editor: a meta box and a notice after saving;
 *   - every publish (editor, REST, scheduled): a server-side check after the
 *     post and its SEO settings are saved; critical problems on important
 *     pages become alerts.
 *
 * Redirects are the only thing the guard changes, and only for URLs that
 * stop existing: they are read when WordPress is about to answer 404, so a
 * page that now lives at an old URL always wins.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Guard {

	const NOTICE = 'rfy_guard_';
	const LOCK   = 'rfy-guard';
	const CHOICE = 'rfy_redirect_choice_'; // + post id: the editor's answer for the next save, 'yes' (create a 301) or 'no' (change anyway)

	public static function init() {
		// After meta is saved, so the editor's redirect choice is known.
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_post_updated' ), 10, 4 );
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 30, 2 );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'after_save' ), 20, 4 );
		// Before core's canonical guessing (10): a recorded move is exact, a guess is not.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 9 );
		if ( is_admin() ) {
			add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_assets' ) );
			add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ), 10, 2 );
			add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		}
	}

	private static function enabled() {
		return 'off' !== Settings::get( 'guard_mode' ) && Installer::ready();
	}

	private static function guarded_type( $type ) {
		return in_array( $type, Inventory::post_types(), true );
	}

	// ── checks ─────────────────────────────────────────────────────────────

	/**
	 * Run every check for a post, optionally with unsaved editor values.
	 *
	 * @param \WP_Post $post
	 * @param array    $edits title, content, slug, parent (any subset)
	 * @return array{post_id:int,url:string,old_url:string,important:bool,mode:string,checks:array,counts:array,needs_ack:bool,redirects:array}
	 */
	public static function check( \WP_Post $post, array $edits = array() ) {
		$content = array_key_exists( 'content', $edits ) ? (string) $edits['content'] : (string) $post->post_content;
		$saved   = 'publish' === $post->post_status ? (string) get_permalink( $post ) : '';
		$url     = self::predicted_url( $post, $edits );
		$path    = Util::normalize_path( $url );
		$row     = self::page_row( $post->ID );
		$min     = (int) Settings::get( 'importance_min' );
		$imp     = $row && ( (int) $row['pinned'] > 0 || ( (int) $row['importance'] >= $min && (int) $row['pinned'] >= 0 ) );

		$checks = array(
			self::check_indexing( $post ),
			self::check_robots( $path ),
			self::check_canonical( $post, $url, $row ),
			self::check_url_change( $post, $saved, $url ),
			self::check_content( $post, $content, $row, $imp ),
		);
		$counts = array( 'fail' => 0, 'warn' => 0, 'pass' => 0, 'na' => 0 );
		foreach ( $checks as $c ) {
			$counts[ $c['status'] ]++;
		}
		$mode = (string) Settings::get( 'guard_mode' );
		return array(
			'post_id'   => (int) $post->ID,
			'url'       => $url,
			'old_url'   => $saved && Util::normalize_path( $saved ) !== $path ? $saved : '',
			'important' => (bool) $imp,
			'mode'      => $mode,
			'checks'    => $checks,
			'counts'    => $counts,
			'needs_ack' => 'confirm' === $mode && $counts['fail'] > 0,
			'redirects' => self::redirects_for( $post->ID ),
			// The saved slug, so the editor can offer "keep the old URL".
			'old_slug'  => $saved && Util::normalize_path( $saved ) !== $path ? (string) $post->post_name : '',
			// What AI crawlers did with this post's live URL (observed).
			'crawl'     => $saved ? self::crawl_history( Util::normalize_path( $saved ) ) : null,
			'score'     => $row && null !== $row['aeo_score'] ? (int) $row['aeo_score'] : null,
			'redirects_on' => (bool) Settings::get( 'guard_redirects' ),
			'seo'       => self::seo_owner(),
		);
	}

	private static function result( $id, $status, $title, $detail = '', $action = '' ) {
		return array(
			'id'     => $id,
			'status' => $status,
			'title'  => $title,
			'detail' => $detail,
			'action' => $action,
		);
	}

	private static function check_indexing( \WP_Post $post ) {
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			return self::result(
				'indexing',
				'fail',
				__( 'The whole site asks search engines not to index it', 'rankyfy-ai-seo' ),
				__( '"Discourage search engines from indexing this site" is on, so this page will be left out of search indexes and the AI search built on them.', 'rankyfy-ai-seo' ),
				__( 'Turn it off in Settings → Reading unless the site is not meant to be public yet.', 'rankyfy-ai-seo' )
			);
		}
		$seo = self::seo_meta( $post );
		if ( $seo['noindex'] ) {
			return self::result(
				'indexing',
				'fail',
				__( 'This page is set to noindex', 'rankyfy-ai-seo' ),
				/* translators: %s: where the setting comes from, e.g. "Yoast SEO (this page)" */
				sprintf( __( 'Set in %s. ChatGPT search, Copilot, Google AI Overviews and Perplexity rely on search indexes, so a noindex page is rarely found or cited.', 'rankyfy-ai-seo' ), $seo['noindex_source'] ),
				__( 'If the page should be found, switch it to "index" in your SEO plugin\'s advanced settings for this page (or for this content type).', 'rankyfy-ai-seo' )
			);
		}
		return self::result( 'indexing', 'pass', __( 'Search engines may index this page', 'rankyfy-ai-seo' ) );
	}

	private static function check_robots( $path ) {
		$blocked = array();
		$hard    = false;
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'priority_bots' ) ) ) ) as $id ) {
			$b = Registry::get( $id );
			if ( ! $b ) {
				continue;
			}
			$al = Robots::allows( $id, $path );
			if ( ! $al['allowed'] ) {
				$blocked[] = $b['name'] . ( $al['rule'] ? ' (' . $al['rule'] . ')' : '' );
				$hard      = $hard || in_array( $b['category'], array( 'ai_search', 'search' ), true );
			}
		}
		if ( ! $blocked ) {
			return self::result( 'robots', 'pass', __( 'robots.txt lets AI crawlers read this URL', 'rankyfy-ai-seo' ) );
		}
		return self::result(
			'robots',
			$hard ? 'fail' : 'warn',
			/* translators: %d: number of crawlers */
			sprintf( _n( 'robots.txt blocks %d priority crawler from this URL', 'robots.txt blocks %d priority crawlers from this URL', count( $blocked ), 'rankyfy-ai-seo' ), count( $blocked ) ),
			implode( ', ', $blocked ),
			__( 'If this page should appear in AI answers, narrow or remove the rule in robots.txt (or in the SEO or security plugin that writes it).', 'rankyfy-ai-seo' )
		);
	}

	private static function check_canonical( \WP_Post $post, $url, $row ) {
		$seo = self::seo_meta( $post );
		if ( $seo['canonical'] ) {
			if ( self::points_elsewhere( $seo['canonical'], $url ) ) {
				return self::result(
					'canonical',
					'warn',
					__( 'The canonical URL points to another page', 'rankyfy-ai-seo' ),
					/* translators: 1: canonical URL, 2: SEO plugin */
					sprintf( __( '%1$s (set in %2$s). Search engines and AI crawlers will treat that page as the real one and may ignore this one.', 'rankyfy-ai-seo' ), $seo['canonical'], $seo['canonical_source'] ),
					__( 'If this page has its own content, clear the canonical field so it points to itself.', 'rankyfy-ai-seo' )
				);
			}
			return self::result( 'canonical', 'pass', __( 'The canonical URL points to this page', 'rankyfy-ai-seo' ) );
		}
		$facts = $row ? json_decode( (string) $row['facts'], true ) : null;
		if ( is_array( $facts ) && ! empty( $facts['fetched'] ) && ! empty( $facts['canonical_elsewhere'] ) && ! empty( $facts['canonical'] ) ) {
			return self::result(
				'canonical',
				'warn',
				__( 'The live page declares a canonical URL elsewhere', 'rankyfy-ai-seo' ),
				/* translators: %s: canonical URL */
				sprintf( __( 'When this page was last read, its canonical tag pointed to %s. A theme or plugin may be setting it.', 'rankyfy-ai-seo' ), $facts['canonical'] ),
				__( 'Check the page source after publishing; the canonical tag should point to the page itself.', 'rankyfy-ai-seo' )
			);
		}
		return self::result( 'canonical', 'pass', __( 'No conflicting canonical URL', 'rankyfy-ai-seo' ) );
	}

	private static function check_url_change( \WP_Post $post, $saved, $url ) {
		if ( ! $saved ) {
			return self::result( 'url_change', 'na', __( 'New URL', 'rankyfy-ai-seo' ), $url );
		}
		$old = Util::normalize_path( $saved );
		if ( $old === Util::normalize_path( $url ) ) {
			return self::result( 'url_change', 'pass', __( 'The URL stays the same', 'rankyfy-ai-seo' ) );
		}
		$seen = self::crawl_history( $old );
		/* translators: 1: old URL, 2: new URL */
		$detail = sprintf( __( 'From %1$s to %2$s.', 'rankyfy-ai-seo' ), $saved, $url );
		if ( $seen['hits'] ) {
			/* translators: 1: requests, 2: crawlers, 3: date */
			$detail .= ' ' . sprintf( __( 'AI crawlers requested the old URL %1$s times (%2$s), last on %3$s. AI answers and citations still point there.', 'rankyfy-ai-seo' ), number_format_i18n( $seen['hits'] ), implode( ', ', $seen['bots'] ), wp_date( get_option( 'date_format' ), $seen['last'] ) );
		}
		if ( $seen['referrals'] ) {
			/* translators: %s: visits */
			$detail .= ' ' . sprintf( __( '%s visits arrived at the old URL from AI assistants in the last 30 days.', 'rankyfy-ai-seo' ), number_format_i18n( $seen['referrals'] ) );
		}
		if ( Settings::get( 'guard_redirects' ) ) {
			return self::result(
				'url_change',
				'pass',
				__( 'The URL changes — a permanent redirect will be added', 'rankyfy-ai-seo' ),
				$detail,
				is_post_type_hierarchical( $post->post_type ) ? __( 'Pages below this one move too; they are redirected as well.', 'rankyfy-ai-seo' ) : ''
			);
		}
		return self::result(
			'url_change',
			$seen['hits'] || $seen['referrals'] ? 'fail' : 'warn',
			__( 'The URL changes and nothing will redirect the old one', 'rankyfy-ai-seo' ),
			$detail,
			__( 'Keep the old slug, or add a 301 redirect from the old URL (or switch on automatic redirects in AI Crawlers → Settings).', 'rankyfy-ai-seo' )
		);
	}

	private static function check_content( \WP_Post $post, $content, $row, $important ) {
		if ( self::uses_builder( $post, $content ) ) {
			return self::result( 'content', 'na', __( 'Content length is measured after publishing', 'rankyfy-ai-seo' ), __( 'This page is built with a page builder, so its text is only known once it is served.', 'rankyfy-ai-seo' ) );
		}
		$html  = strip_shortcodes( preg_replace( '/<!--\s*\/?wp:[^>]*?-->/s', '', $content ) );
		$words = Text::word_count( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$min   = (int) Settings::get( 'guard_thin_words' );
		if ( 'product' === $post->post_type ) {
			$min = (int) round( $min * 0.4 ); // product pages are judged on less text, as in the page analysis
		}
		$before = $row ? (int) $row['word_count'] : 0;
		if ( $before >= 300 && $words < $before / 2 ) {
			return self::result(
				'content',
				$important ? 'fail' : 'warn',
				/* translators: 1: words before, 2: words now */
				sprintf( __( 'Most of the text is gone (%1$s → %2$s words)', 'rankyfy-ai-seo' ), number_format_i18n( $before ), number_format_i18n( $words ) ),
				$important ? __( 'This is one of your important pages. AI crawlers that re-read it will replace what they know with the shorter version.', 'rankyfy-ai-seo' ) : __( 'AI crawlers that re-read it will replace what they know with the shorter version.', 'rankyfy-ai-seo' ),
				__( 'Check that no block or section was deleted by accident before updating.', 'rankyfy-ai-seo' )
			);
		}
		if ( $words < $min ) {
			return self::result(
				'content',
				'warn',
				/* translators: 1: words, 2: minimum */
				sprintf( __( 'Thin content: %1$s words (aim for %2$s or more)', 'rankyfy-ai-seo' ), number_format_i18n( $words ), number_format_i18n( $min ) ),
				__( 'AI assistants cite pages that answer a question completely. Short pages rarely contain the specific facts they quote.', 'rankyfy-ai-seo' ),
				__( 'Add the details a reader would ask about: specifics, examples, numbers, steps.', 'rankyfy-ai-seo' )
			);
		}
		/* translators: %s: words */
		return self::result( 'content', 'pass', sprintf( __( '%s words of content', 'rankyfy-ai-seo' ), number_format_i18n( $words ) ) );
	}

	// ── helpers ────────────────────────────────────────────────────────────

	/** The URL the post will have once the editor's slug/parent are saved. */
	private static function predicted_url( \WP_Post $post, array $edits ) {
		$slug   = isset( $edits['slug'] ) ? sanitize_title( (string) $edits['slug'] ) : '';
		$parent = isset( $edits['parent'] ) ? (int) $edits['parent'] : (int) $post->post_parent;
		if ( '' === $slug && $parent === (int) $post->post_parent && 'publish' === $post->post_status ) {
			return (string) get_permalink( $post );
		}
		$clone              = clone $post;
		$clone->post_parent = $parent;
		$clone->post_status = 'publish';
		$name               = '' !== $slug ? $slug : ( $post->post_name ? $post->post_name : sanitize_title( $edits['title'] ?? $post->post_title ) );
		if ( '' === $name ) {
			return (string) get_permalink( $post );
		}
		$clone->post_name = wp_unique_post_slug( $name, $post->ID, 'publish', $post->post_type, $parent );
		$url              = get_permalink( $clone );
		return $url ? (string) $url : (string) get_permalink( $post );
	}

	/** The SEO plugin that writes this post's title and meta description, if any. */
	private static function seo_owner() {
		$others = array_values( array_diff( Compat::seo_plugins(), array( 'RankyFy SEO' ) ) );
		return $others ? $others[0] : '';
	}

	private static function page_row( $post_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'pages' ) . " WHERE object_type = 'post' AND object_id = %d AND deleted = 0", (int) $post_id ), ARRAY_A );
	}

	/** Observed AI crawls of, and AI-assistant visits to, one path. */
	private static function crawl_history( $path ) {
		global $wpdb;
		$hash = Util::url_hash( $path );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT bot, hits, last_seen FROM ' . Installer::table( 'page_bots' ) . ' WHERE url_hash = %s', $hash ), ARRAY_A );
		$out  = array( 'hits' => 0, 'last' => 0, 'bots' => array(), 'by_bot' => array(), 'referrals' => 0 );
		foreach ( (array) $rows as $r ) {
			$b = Registry::get( $r['bot'] );
			if ( ! $b || ! $b['ai'] ) {
				continue;
			}
			$out['hits']    += (int) $r['hits'];
			$out['last']     = max( $out['last'], (int) $r['last_seen'] );
			$out['by_bot'][] = array( 'name' => $b['name'], 'hits' => (int) $r['hits'], 'last' => (int) $r['last_seen'] );
		}
		usort( $out['by_bot'], static function ( $a, $b ) {
			return $b['hits'] <=> $a['hits'];
		} );
		$out['by_bot']    = array_slice( $out['by_bot'], 0, 6 );
		$out['bots']      = array_column( $out['by_bot'], 'name' );
		$out['referrals'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(hits) FROM ' . Installer::table( 'referrals' ) . ' WHERE url_hash = %s AND day >= %s', $hash, wp_date( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) ) );
		return $out;
	}

	private static function points_elsewhere( $canonical, $url ) {
		$host = strtolower( (string) wp_parse_url( $canonical, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $host && $host !== $home && 'www.' . $host !== $home && $host !== 'www.' . $home ) {
			return true;
		}
		return strtolower( Util::normalize_path( $canonical ) ) !== strtolower( Util::normalize_path( $url ) );
	}

	private static function uses_builder( \WP_Post $post, $content ) {
		return 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true )
			|| 'on' === get_post_meta( $post->ID, '_et_pb_use_builder', true )
			|| (bool) get_post_meta( $post->ID, '_fl_builder_enabled', true )
			|| false !== strpos( $content, '[vc_row' )
			|| false !== strpos( $content, '[et_pb_section' );
	}

	/**
	 * Robots and canonical settings from the SEO plugin in use (Yoast SEO,
	 * Rank Math, SEOPress, All in One SEO), per page and per post type.
	 *
	 * @return array{noindex:bool,noindex_source:string,canonical:string,canonical_source:string}
	 */
	public static function seo_meta( \WP_Post $post ) {
		global $wpdb;
		$out = array( 'noindex' => false, 'noindex_source' => '', 'canonical' => '', 'canonical_source' => '' );
		$id  = (int) $post->ID;
		$set = static function ( $key, $value, $source ) use ( &$out ) {
			if ( ! $out[ $key ] && $value ) {
				$out[ $key ]             = $value;
				$out[ $key . '_source' ] = $source;
			}
		};

		// Yoast SEO: per page "1" = noindex, "2" = index, "" = the post type default.
		$y = (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true );
		if ( '1' === $y ) {
			$set( 'noindex', true, __( 'Yoast SEO (this page)', 'rankyfy-ai-seo' ) );
		} elseif ( '' === $y && defined( 'WPSEO_VERSION' ) ) {
			$titles = get_option( 'wpseo_titles' );
			if ( is_array( $titles ) && ! empty( $titles[ 'noindex-' . $post->post_type ] ) ) {
				$set( 'noindex', true, __( 'Yoast SEO (all items of this content type)', 'rankyfy-ai-seo' ) );
			}
		}
		$set( 'canonical', esc_url_raw( (string) get_post_meta( $id, '_yoast_wpseo_canonical', true ) ), 'Yoast SEO' );

		// Rank Math.
		$rm = get_post_meta( $id, 'rank_math_robots', true );
		if ( is_array( $rm ) && $rm ) {
			$set( 'noindex', in_array( 'noindex', $rm, true ), __( 'Rank Math (this page)', 'rankyfy-ai-seo' ) );
		} elseif ( defined( 'RANK_MATH_VERSION' ) ) {
			$o = get_option( 'rank-math-options-titles' );
			$k = 'pt_' . $post->post_type;
			if ( is_array( $o ) && 'on' === ( $o[ $k . '_custom_robots' ] ?? '' ) && in_array( 'noindex', (array) ( $o[ $k . '_robots' ] ?? array() ), true ) ) {
				$set( 'noindex', true, __( 'Rank Math (all items of this content type)', 'rankyfy-ai-seo' ) );
			}
		}
		$set( 'canonical', esc_url_raw( (string) get_post_meta( $id, 'rank_math_canonical_url', true ) ), 'Rank Math' );

		// SEOPress stores "yes" when noindex is ticked.
		$set( 'noindex', 'yes' === get_post_meta( $id, '_seopress_robots_index', true ), __( 'SEOPress (this page)', 'rankyfy-ai-seo' ) );
		$set( 'canonical', esc_url_raw( (string) get_post_meta( $id, '_seopress_robots_canonical', true ) ), 'SEOPress' );

		// All in One SEO keeps its settings in its own table.
		if ( defined( 'AIOSEO_VERSION' ) ) {
			$t = $wpdb->prefix . 'aioseo_posts';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
				$a = $wpdb->get_row( $wpdb->prepare( "SELECT robots_default, robots_noindex, canonical_url FROM {$t} WHERE post_id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $a ) {
					$set( 'noindex', ! (int) $a['robots_default'] && (int) $a['robots_noindex'], __( 'All in One SEO (this page)', 'rankyfy-ai-seo' ) );
					$set( 'canonical', esc_url_raw( (string) $a['canonical_url'] ), 'All in One SEO' );
				}
			}
		}
		$out['noindex'] = (bool) $out['noindex'];
		return $out;
	}

	// ── after saving ───────────────────────────────────────────────────────

	/**
	 * After every insert or update, once meta and terms are saved: check
	 * published posts, keep the result for the editor's notice and raise an
	 * alert for critical problems on important pages.
	 */
	public static function after_save( $post_id, $post, $update, $before ) {
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! self::enabled() || ! self::guarded_type( $post->post_type )
			|| wp_is_post_revision( $post_id ) || ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) ) {
			return;
		}
		$res = self::check( $post );
		if ( get_current_user_id() ) {
			set_transient( self::NOTICE . get_current_user_id() . '_' . $post_id, $res, 10 * MINUTE_IN_SECONDS );
		}
		if ( ! $res['counts']['fail'] ) {
			return;
		}
		$first = ! $before instanceof \WP_Post || 'publish' !== $before->post_status;
		if ( ! $res['important'] && ! $first ) {
			return;
		}
		$fails = array_values( array_filter( $res['checks'], static function ( $c ) {
			return 'fail' === $c['status'];
		} ) );
		$row   = self::page_row( $post_id );
		Alerts::raise(
			'publish_guard',
			$post_id . ':' . implode( ',', array_column( $fails, 'id' ) ),
			$res['important'] ? 'critical' : 'warning',
			/* translators: %s: page title */
			sprintf( __( '"%s" was published with problems that keep it out of AI search', 'rankyfy-ai-seo' ), get_the_title( $post ) ),
			implode( ' ', array_column( $fails, 'title' ) ),
			implode( ' ', array_filter( array_column( $fails, 'detail' ) ) ),
			array(
				array(
					'label'   => get_the_title( $post ),
					'path'    => Util::normalize_path( $res['url'] ),
					'page_id' => $row ? (int) $row['id'] : 0,
				),
			),
			implode( ' ', array_filter( array_column( $fails, 'action' ) ) ),
			$row ? '#/pages/' . (int) $row['id'] : '#/recommendations',
			DAY_IN_SECONDS
		);
	}

	// ── redirects ──────────────────────────────────────────────────────────

	/** Remember the editor's answer for the next save of this post ('' forgets it). */
	public static function set_choice( $post_id, $choice ) {
		if ( in_array( $choice, array( 'yes', 'no' ), true ) ) {
			set_transient( self::CHOICE . (int) $post_id, $choice, HOUR_IN_SECONDS );
		} else {
			delete_transient( self::CHOICE . (int) $post_id );
		}
	}

	/**
	 * A published post's URL changed: redirect the old URL (and its children's).
	 * Automatic when the setting is on, unless the editor chose "Change anyway";
	 * with the setting off, only when the editor chose "Create 301 redirect".
	 */
	public static function on_post_updated( $post_id, $after, $update = true, $before = null ) {
		if ( ! Installer::ready() || wp_is_post_revision( $post_id ) || ! $after instanceof \WP_Post ) {
			return;
		}
		$choice = (string) get_transient( self::CHOICE . (int) $post_id );
		if ( '' !== $choice && ! wp_is_post_autosave( $post_id ) ) {
			delete_transient( self::CHOICE . (int) $post_id ); // one answer, for this save only
		}
		$wanted = 'yes' === $choice || ( Settings::get( 'guard_redirects' ) && 'no' !== $choice );
		if ( ! $wanted || ! $before instanceof \WP_Post
			|| 'publish' !== $before->post_status || 'publish' !== $after->post_status || ! self::guarded_type( $after->post_type ) ) {
			return;
		}
		$old = Util::normalize_path( (string) get_permalink( $before ) );
		$new = Util::normalize_path( (string) get_permalink( $after ) );
		if ( $old === $new || '/' === $old || '/' === $new ) {
			return;
		}
		self::add_redirect( $old, $post_id, 'slug' );
		if ( is_post_type_hierarchical( $after->post_type ) ) {
			$children = get_pages(
				array(
					'child_of'    => $post_id,
					'post_type'   => $after->post_type,
					'post_status' => 'publish',
					'number'      => 500,
				)
			);
			foreach ( (array) $children as $child ) {
				$cnew = Util::normalize_path( (string) get_permalink( $child ) );
				if ( 0 === strpos( $cnew, $new . '/' ) ) {
					self::add_redirect( $old . substr( $cnew, strlen( $new ) ), (int) $child->ID, 'parent' );
				}
			}
		}
	}

	/** Whatever is published at a URL now takes precedence over a redirect from it. */
	public static function on_save( $post_id, $post ) {
		if ( ! Installer::ready() || ! $post instanceof \WP_Post || 'publish' !== $post->post_status || wp_is_post_revision( $post_id ) || ! self::guarded_type( $post->post_type ) ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( Installer::table( 'redirects' ), array( 'source_hash' => Util::url_hash( Util::normalize_path( (string) get_permalink( $post ) ) ) ) );
	}

	public static function add_redirect( $source, $post_id, $reason = 'slug' ) {
		global $wpdb;
		$source = Util::normalize_path( $source );
		if ( '/' === $source ) {
			return false;
		}
		return false !== $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Installer::table( 'redirects' ) . ' (source_hash, source, post_id, reason, created_at) VALUES (%s, %s, %d, %s, %d)
				 ON DUPLICATE KEY UPDATE post_id = VALUES(post_id), reason = VALUES(reason), created_at = VALUES(created_at)',
				Util::url_hash( $source ),
				$source,
				(int) $post_id,
				$reason,
				time()
			)
		);
	}

	/** On a 404 only: is this an old URL of a published post? */
	public static function maybe_redirect() {
		if ( ! is_404() || ! Settings::get( 'guard_redirects' ) || ! Installer::ready() ) {
			return;
		}
		global $wpdb;
		$t    = Installer::table( 'redirects' );
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = Util::normalize_path( $uri );
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT id, post_id FROM {$t} WHERE source_hash = %s", Util::url_hash( $path ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return;
		}
		$post = get_post( (int) $row['post_id'] );
		if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return; // the target is gone or private: a 404 is the honest answer
		}
		$target = (string) get_permalink( $post );
		if ( '' === $target || Util::normalize_path( $target ) === $path ) {
			$wpdb->delete( $t, array( 'id' => (int) $row['id'] ) ); // would loop
			return;
		}
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		if ( '' !== $query ) {
			$target .= ( false === strpos( $target, '?' ) ? '?' : '&' ) . $query;
		}
		$bot = '' !== Tracker::current_bot() ? 1 : 0;
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET hits = hits + 1, bot_hits = bot_hits + %d, last_hit = %d WHERE id = %d", $bot, time(), (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( wp_safe_redirect( $target, 301, 'RankyFy AI SEO' ) ) {
			exit;
		}
	}

	private static function redirects_for( $post_id ) {
		global $wpdb;
		return array_map(
			static function ( $r ) {
				return array( 'source' => $r['source'], 'created_at' => (int) $r['created_at'], 'hits' => (int) $r['hits'] );
			},
			(array) $wpdb->get_results( $wpdb->prepare( 'SELECT source, created_at, hits FROM ' . Installer::table( 'redirects' ) . ' WHERE post_id = %d ORDER BY created_at DESC LIMIT 20', (int) $post_id ), ARRAY_A )
		);
	}

	/** All redirects, newest first, for the admin screen. */
	public static function redirects( $page = 1, $per = 50 ) {
		global $wpdb;
		$t     = Installer::table( 'redirects' );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $per, ( max( 1, $page ) - 1 ) * $per ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = array();
		foreach ( (array) $rows as $r ) {
			$post    = get_post( (int) $r['post_id'] );
			$live    = $post && 'publish' === $post->post_status;
			$items[] = array(
				'id'         => (int) $r['id'],
				'source'     => $r['source'],
				'target'     => $live ? Util::normalize_path( (string) get_permalink( $post ) ) : '',
				'title'      => $post ? get_the_title( $post ) : '',
				'post_id'    => (int) $r['post_id'],
				'active'     => $live,
				'reason'     => $r['reason'],
				'created_at' => (int) $r['created_at'],
				'hits'       => (int) $r['hits'],
				'bot_hits'   => (int) $r['bot_hits'],
				'last_hit'   => (int) $r['last_hit'] ?: null,
			);
		}
		return array( 'items' => $items, 'total' => $total, 'page' => max( 1, $page ), 'pages' => max( 1, (int) ceil( $total / $per ) ) );
	}

	public static function delete_redirect( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( Installer::table( 'redirects' ), array( 'id' => (int) $id ) );
	}

	// ── editor UI ──────────────────────────────────────────────────────────

	public static function editor_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! self::enabled() || ! $screen || ! self::guarded_type( (string) $screen->post_type ) ) {
			return;
		}
		$file = 'assets/js/guard.js';
		wp_enqueue_script( 'rfy-guard', RFY_URL . $file, array( 'wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-edit-post', 'wp-editor', 'wp-notices' ), RFY_VERSION . '.' . filemtime( RFY_DIR . $file ), true );
		wp_set_script_translations( 'rfy-guard', 'rankyfy-ai-seo' );
		wp_localize_script(
			'rfy-guard',
			'RFY_GUARD',
			array(
				'path' => '/' . Rest::NS . '/guard',
				'mode' => (string) Settings::get( 'guard_mode' ),
				'lock' => self::LOCK,
			)
		);
	}

	public static function meta_box( $post_type, $post ) {
		if ( ! self::enabled() || ! self::guarded_type( $post_type ) || ! $post instanceof \WP_Post
			|| ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) ) {
			return;
		}
		add_meta_box( 'rfy-guard', __( 'AI crawler check', 'rankyfy-ai-seo' ), array( __CLASS__, 'meta_box_render' ), $post_type, 'side', 'high' );
	}

	public static function meta_box_render( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p class="description">' . esc_html__( 'Save a draft to check this page before publishing.', 'rankyfy-ai-seo' ) . '</p>';
			return;
		}
		$res = self::check( $post );
		self::render_list( $res );
		echo '<p class="description">' . esc_html__( 'Checked against the last saved version. Save the draft again to re-check.', 'rankyfy-ai-seo' ) . '</p>';
	}

	private static function render_list( array $res ) {
		$icons = array( 'fail' => '✕', 'warn' => '!', 'pass' => '✓', 'na' => '–' );
		$label = array(
			'fail' => __( 'Problem', 'rankyfy-ai-seo' ),
			'warn' => __( 'Warning', 'rankyfy-ai-seo' ),
			'pass' => __( 'OK', 'rankyfy-ai-seo' ),
			'na'   => __( 'Not checked', 'rankyfy-ai-seo' ),
		);
		$color = array( 'fail' => '#b32d2e', 'warn' => '#996800', 'pass' => '#00702a', 'na' => '#646970' );
		echo '<ul style="margin:0">';
		foreach ( $res['checks'] as $c ) {
			echo '<li style="margin:0 0 8px"><strong style="color:' . esc_attr( $color[ $c['status'] ] ) . '"><span aria-hidden="true">' . esc_html( $icons[ $c['status'] ] ) . '</span> <span class="screen-reader-text">' . esc_html( $label[ $c['status'] ] ) . ': </span>' . esc_html( $c['title'] ) . '</strong>';
			if ( $c['detail'] && 'pass' !== $c['status'] ) {
				echo '<br><span>' . esc_html( $c['detail'] ) . '</span>';
			}
			if ( $c['action'] && in_array( $c['status'], array( 'fail', 'warn' ), true ) ) {
				echo '<br><em>' . esc_html( $c['action'] ) . '</em>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/** Classic editor: the result of the check that ran when the post was saved. */
	public static function notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || empty( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$key = self::NOTICE . get_current_user_id() . '_' . (int) $_GET['post']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res = get_transient( $key );
		if ( ! is_array( $res ) ) {
			return;
		}
		delete_transient( $key );
		if ( ! $res['counts']['fail'] && ! $res['counts']['warn'] ) {
			return;
		}
		echo '<div class="notice ' . ( $res['counts']['fail'] ? 'notice-error' : 'notice-warning' ) . ' is-dismissible"><p><strong>' . esc_html__( 'AI crawler check after saving', 'rankyfy-ai-seo' ) . '</strong></p>';
		self::render_list(
			array(
				'checks' => array_values( array_filter( $res['checks'], static function ( $c ) {
					return in_array( $c['status'], array( 'fail', 'warn' ), true );
				} ) ),
			)
		);
		echo '</div>';
	}
}
