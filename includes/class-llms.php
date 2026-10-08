<?php
/**
 * llms.txt, llms-full.txt and ai.txt, generated from the page inventory.
 *
 *   llms.txt       (llmstxt.org) the site's name, a one-line summary, optional
 *                  notes, then the pages worth reading as Markdown links with
 *                  their descriptions, most important first. Pages that are
 *                  noindex, erroring, canonicalised elsewhere or closed to AI
 *                  search crawlers by robots.txt are left out.
 *   llms-full.txt  the plain text of the top pages in one file.
 *   ai.txt         (Spawning) the site's policy on AI training, by file type.
 *                  Advisory: crawlers obey robots.txt, which stays the source
 *                  of truth for access.
 *
 * The files are public, so each is served only once the owner switches it
 * on. They are built on demand and cached; content changes mark the cache
 * stale. A physical file in the web root is served by the web server before
 * WordPress runs and always wins — the status screen says so.
 *
 * The request path costs one string test for every request that is not one
 * of these files.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Llms {

	const CACHE  = 'rfy_llms_cache';
	const OVERRIDE = 'rfy_llms_override'; // hand-written llms.txt; served instead of the generated one
	const STATUS = 'rfy_llms_status';
	const FILES  = array( 'llms.txt', 'llms-full.txt', 'ai.txt' );
	const HEADER = 'X-RankyFy-Generated';
	const MAX_AGE        = 12 * HOUR_IN_SECONDS;
	const FULL_MAX_BYTES = 1572864; // 1.5 MB
	const FULL_PAGE_WORDS = 3000;

	public static function init() {
		// After init, so custom post types and taxonomies exist when a file is built.
		add_action( 'wp_loaded', array( __CLASS__, 'serve' ), 1 );
		add_action( 'save_post', array( __CLASS__, 'on_change' ) );
		add_action( 'deleted_post', array( __CLASS__, 'stale' ) );
		add_action( 'update_option_blogname', array( __CLASS__, 'stale' ) );
		add_action( 'update_option_blogdescription', array( __CLASS__, 'stale' ) );
	}

	public static function on_change( $post_id ) {
		if ( ! wp_is_post_revision( $post_id ) && ! wp_is_post_autosave( $post_id ) ) {
			self::stale();
		}
	}

	public static function stale() {
		delete_option( self::CACHE );
	}

	/** Is this file switched on? */
	public static function active( $file ) {
		switch ( $file ) {
			case 'llms.txt':
				return (bool) Settings::get( 'llms_enabled' );
			case 'llms-full.txt':
				return Settings::get( 'llms_enabled' ) && Settings::get( 'llms_full' );
			case 'ai.txt':
				return (bool) Settings::get( 'ai_txt_enabled' );
		}
		return false;
	}

	/** Which of the files this request asks for, or ''. */
	public static function requested( $uri ) {
		if ( false === strpos( (string) $uri, '.txt' ) ) {
			return '';
		}
		$path = (string) wp_parse_url( (string) $uri, PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base ) {
			if ( 0 !== strpos( $path, $base . '/' ) ) {
				return '';
			}
			$path = substr( $path, strlen( $base ) );
		}
		$file = ltrim( $path, '/' );
		return in_array( $file, self::FILES, true ) ? $file : '';
	}

	// ── request path ───────────────────────────────────────────────────────

	public static function serve() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$file = self::requested( $uri );
		if ( '' === $file || ! Installer::ready() || ! self::active( $file ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}
		$built = self::get( $file );
		$over  = 'llms.txt' === $file ? self::override() : '';
		if ( '' !== $over ) {
			$built['body'] = $over; // the owner's own version always wins over a rebuild
		}
		$etag  = '"' . md5( $built['body'] ) . '"';
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' ); // a guide for language models, not a page for search results
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', (int) $built['built_at'] ) . ' GMT' );
		header( 'ETag: ' . $etag );
		header( self::HEADER . ': ' . $file );
		$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? (string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only
		if ( '' !== $inm && self::etag_matches( $inm, $etag ) ) {
			status_header( 304 );
			exit;
		}
		if ( 'HEAD' !== $method ) {
			echo $built['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/plain, built from sanitised data
		}
		exit;
	}

	/** If-None-Match may list several tags, be weak (W/), or carry the server's compression suffix ("…-gzip"). */
	private static function etag_matches( $header, $etag ) {
		foreach ( explode( ',', $header ) as $tag ) {
			$tag = preg_replace( '#^W/#', '', trim( $tag ) );
			$tag = preg_replace( '/-(gzip|br|deflate|zstd)"$/', '"', $tag );
			if ( '*' === $tag || $tag === $etag ) {
				return true;
			}
		}
		return false;
	}

	// ── building ───────────────────────────────────────────────────────────

	/** Everything that changes a file's content besides the pages themselves. */
	private static function key() {
		$s = Settings::all();
		return md5(
			wp_json_encode(
				array(
					$s['llms_summary'],
					$s['llms_intro'],
					$s['llms_max_links'],
					$s['llms_types'],
					$s['llms_min_words'],
					$s['ai_txt_policy'],
					$s['importance_min'],
					$s['priority_bots'],
					get_bloginfo( 'name' ),
					get_bloginfo( 'description' ),
					home_url( '/' ),
					RFY_VERSION,
				)
			)
		);
	}

	/** @return array{body:string,built_at:int,links:int} cached, rebuilt when stale */
	public static function get( $file, $force = false ) {
		$cache = get_option( self::CACHE );
		$cache = is_array( $cache ) ? $cache : array();
		$key   = self::key();
		$hit   = $cache[ $file ] ?? null;
		if ( ! $force && is_array( $hit ) && $hit['key'] === $key && (int) $hit['built_at'] > time() - self::MAX_AGE ) {
			return $hit;
		}
		$links = 0;
		if ( 'llms.txt' === $file ) {
			$body = self::build_llms( $links );
		} elseif ( 'llms-full.txt' === $file ) {
			$body = self::build_full( $links );
		} else {
			$body = self::build_ai_txt();
		}
		$cache[ $file ] = array(
			'body'     => $body,
			'built_at' => time(),
			'links'    => $links,
			'key'      => $key,
		);
		update_option( self::CACHE, $cache, false );
		return $cache[ $file ];
	}

	public static function override() {
		return (string) get_option( self::OVERRIDE, '' );
	}

	/** Save a hand-written llms.txt ('' goes back to the generated one). */
	public static function set_override( $text ) {
		$text = trim( str_replace( array( "\r\n", "\r" ), "\n", wp_strip_all_tags( (string) $text ) ) );
		if ( '' === $text ) {
			delete_option( self::OVERRIDE );
		} else {
			update_option( self::OVERRIDE, substr( $text, 0, 500000 ) . "\n", false );
		}
	}

	public static function rebuild() {
		foreach ( self::FILES as $f ) {
			if ( self::active( $f ) ) {
				self::get( $f, true );
			}
		}
	}

	/** One line of Markdown text: no line breaks, no stray link syntax. */
	private static function line( $s, $max = 300 ) {
		$s = html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = trim( preg_replace( '/\s+/u', ' ', $s ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $s, 'UTF-8' ) > $max ) {
			$s = rtrim( mb_substr( $s, 0, $max - 1, 'UTF-8' ) ) . '…';
		}
		return $s;
	}

	private static function summary() {
		$s = trim( (string) Settings::get( 'llms_summary' ) );
		if ( '' === $s ) {
			$s = (string) get_bloginfo( 'description' );
		}
		return self::line( $s, 400 );
	}

	/** Post types (and taxonomies) the owner chose; empty = every public post type, taxonomies as optional. */
	private static function types() {
		$chosen = array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'llms_types' ) ) ) );
		return $chosen ? $chosen : null;
	}

	/**
	 * Pages to list, most important first, without the ones crawlers should
	 * not be pointed at.
	 *
	 * @return array rows with url, title, description
	 */
	public static function candidates( $limit ) {
		global $wpdb;
		// Pages AI crawlers read most come first: the plugin knows what they care about.
		$min   = (int) Settings::get( 'llms_min_words' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.id, p.object_type, p.object_id, p.subtype, p.path, p.title, p.importance, p.pinned, p.http_status, p.facts, COALESCE(h.hits, 0) ai_hits
				 FROM ' . Installer::table( 'pages' ) . ' p
				 LEFT JOIN (SELECT url_hash, SUM(hits) hits FROM ' . Installer::table( 'daily_pages' ) . ' WHERE day >= %s GROUP BY url_hash) h ON h.url_hash = p.url_hash
				 WHERE p.deleted = 0 AND p.pinned >= 0 AND p.noindex = 0 AND (p.http_status = 0 OR p.http_status = 200)
				   AND (p.object_type <> %s OR p.word_count >= %d OR p.analyzed_at = 0)
				 ORDER BY p.pinned DESC, (p.object_type = %s) DESC, ai_hits DESC, p.importance DESC, p.id ASC LIMIT %d',
				wp_date( 'Y-m-d', time() - 89 * DAY_IN_SECONDS ),
				'post',
				$min,
				'home',
				$limit * 3
			),
			ARRAY_A
		);
		$types   = self::types();
		$search  = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'priority_bots' ) ) ) ) as $id ) {
			$b = Registry::get( $id );
			if ( $b && 'ai_search' === $b['category'] ) {
				$search[] = $id;
			}
		}
		$post_ids = array();
		foreach ( (array) $rows as $r ) {
			if ( 'post' === $r['object_type'] ) {
				$post_ids[] = (int) $r['object_id'];
			}
		}
		if ( $post_ids ) {
			_prime_post_caches( $post_ids, false, true );
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			if ( null !== $types && ! in_array( $r['subtype'], $types, true ) && ! ( 'home' === $r['object_type'] ) ) {
				continue;
			}
			$facts = json_decode( (string) $r['facts'], true );
			$facts = is_array( $facts ) ? $facts : array();
			if ( ! empty( $facts['canonical_elsewhere'] ) || ! empty( $facts['noindex'] ) ) {
				continue;
			}
			$blocked = false;
			foreach ( $search as $id ) {
				if ( ! Robots::allows( $id, $r['path'] )['allowed'] ) {
					$blocked = true;
					break;
				}
			}
			if ( $blocked ) {
				continue;
			}
			$desc = (string) ( $facts['description'] ?? '' );
			if ( '' === $desc && 'post' === $r['object_type'] ) {
				$post = get_post( (int) $r['object_id'] );
				if ( $post ) {
					$desc = $post->post_excerpt ? $post->post_excerpt : wp_trim_words( strip_shortcodes( preg_replace( '/<!--\s*\/?wp:[^>]*?-->/s', '', $post->post_content ) ), 30, '…' );
				}
			} elseif ( '' === $desc && 'term' === $r['object_type'] ) {
				$desc = (string) term_description( (int) $r['object_id'] );
			}
			$r['url']         = Inventory::url( $r );
			$r['description'] = self::line( $desc, 220 );
			$r['label']       = self::line( '' !== (string) $r['title'] ? $r['title'] : $r['path'], 120 );
			$out[]            = $r;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	private static function section_name( array $r ) {
		if ( 'home' === $r['object_type'] ) {
			return __( 'Main pages', 'rankyfy-ai-seo' );
		}
		if ( 'term' === $r['object_type'] ) {
			$tax = get_taxonomy( $r['subtype'] );
			return $tax ? $tax->labels->name : __( 'Topics', 'rankyfy-ai-seo' );
		}
		if ( 'page' === $r['subtype'] ) {
			return __( 'Main pages', 'rankyfy-ai-seo' );
		}
		$pt = get_post_type_object( $r['subtype'] );
		return $pt ? $pt->labels->name : ucfirst( $r['subtype'] );
	}

	private static function link_line( array $r ) {
		$label = str_replace( array( '[', ']' ), array( '(', ')' ), $r['label'] );
		$url   = str_replace( array( ' ', ')' ), array( '%20', '%29' ), esc_url_raw( $r['url'] ) );
		return '- [' . $label . '](' . $url . ')' . ( '' !== $r['description'] ? ': ' . $r['description'] : '' );
	}

	private static function header_lines() {
		$out     = array( '# ' . self::line( get_bloginfo( 'name' ), 120 ), '' );
		$summary = self::summary();
		if ( '' !== $summary ) {
			$out[] = '> ' . $summary;
			$out[] = '';
		}
		$intro = trim( (string) Settings::get( 'llms_intro' ) );
		if ( '' !== $intro ) {
			$out[] = preg_replace( "/\r\n?/", "\n", wp_strip_all_tags( $intro ) );
			$out[] = '';
		}
		return $out;
	}

	public static function build_llms( &$links = 0 ) {
		$max   = (int) Settings::get( 'llms_max_links' );
		$extra = (int) max( 10, floor( $max / 2 ) );
		$rows  = self::candidates( $max + $extra );
		$main  = array();
		$opt   = array();
		$types = self::types();
		$n     = 0;
		foreach ( $rows as $r ) {
			// Archives are navigation: listed as optional unless the owner chose their taxonomy.
			$is_term = 'term' === $r['object_type'] && null === $types;
			if ( ! $is_term && $n < $max ) {
				$main[ self::section_name( $r ) ][] = $r;
				$n++;
			} elseif ( count( $opt ) < $extra ) {
				$opt[] = $r;
			}
		}
		// "Main pages" first, then sections by how many important pages they hold.
		uksort( $main, static function ( $a, $b ) use ( $main ) {
			$m = __( 'Main pages', 'rankyfy-ai-seo' );
			if ( $a === $m || $b === $m ) {
				return $a === $m ? -1 : 1;
			}
			return count( $main[ $b ] ) <=> count( $main[ $a ] );
		} );
		$out = self::header_lines();
		foreach ( $main as $name => $items ) {
			$out[] = '## ' . self::line( $name, 80 );
			$out[] = '';
			foreach ( $items as $r ) {
				$out[] = self::link_line( $r );
				$links++;
			}
			$out[] = '';
		}
		if ( $opt ) {
			$out[] = '## Optional';
			$out[] = '';
			foreach ( $opt as $r ) {
				$out[] = self::link_line( $r );
				$links++;
			}
			$out[] = '';
		}
		if ( Settings::get( 'llms_full' ) ) {
			$out[] = '## ' . __( 'Full text', 'rankyfy-ai-seo' );
			$out[] = '';
			$out[] = '- [llms-full.txt](' . esc_url_raw( home_url( '/llms-full.txt' ) ) . '): ' . __( 'The text of the main pages in one file.', 'rankyfy-ai-seo' );
			$out[] = '';
		}
		return rtrim( implode( "\n", $out ) ) . "\n";
	}

	/** A post's content as plain Markdown-ish text: headings, lists and paragraphs kept. */
	public static function plain_text( \WP_Post $post ) {
		$html = preg_replace( '/<!--\s*\/?wp:[^>]*?-->/s', '', (string) $post->post_content );
		$html = strip_shortcodes( $html );
		$html = preg_replace( '#<(script|style|noscript)\b[^>]*>.*?</\1>#is', '', $html );
		$html = preg_replace_callback(
			'#<h([1-6])\b[^>]*>(.*?)</h\1>#is',
			static function ( $m ) {
				return "\n\n" . str_repeat( '#', min( 6, max( 3, (int) $m[1] + 1 ) ) ) . ' ' . trim( wp_strip_all_tags( $m[2] ) ) . "\n\n";
			},
			$html
		);
		$html = preg_replace( '#<li\b[^>]*>#i', "\n- ", $html );
		$html = preg_replace( '#</(p|div|ul|ol|table|tr|blockquote|section|figure)>|<br\s*/?>#i', "\n\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/u", ' ', $text );
		$text = preg_replace( "/ *\n */", "\n", $text );
		$text = trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
		$words = preg_split( '/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( count( $words ) > self::FULL_PAGE_WORDS * 2 ) {
			$text = rtrim( implode( '', array_slice( $words, 0, self::FULL_PAGE_WORDS * 2 ) ) ) . ' …';
		}
		return $text;
	}

	public static function build_full( &$links = 0 ) {
		$rows  = self::candidates( min( 60, (int) Settings::get( 'llms_max_links' ) ) );
		$out   = implode( "\n", self::header_lines() );
		$bytes = strlen( $out );
		foreach ( $rows as $r ) {
			if ( 'post' !== $r['object_type'] ) {
				continue;
			}
			$post = get_post( (int) $r['object_id'] );
			if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
				continue;
			}
			$text = self::plain_text( $post );
			if ( Text::word_count( $text ) < 50 ) {
				continue; // page-builder layouts and stubs: nothing useful to quote
			}
			$block = "\n## " . $r['label'] . "\n\nURL: " . esc_url_raw( $r['url'] ) . "\n" . __( 'Updated:', 'rankyfy-ai-seo' ) . ' ' . get_post_modified_time( 'Y-m-d', true, $post ) . "\n\n" . $text . "\n";
			if ( $bytes + strlen( $block ) > self::FULL_MAX_BYTES ) {
				break;
			}
			$out   .= $block;
			$bytes += strlen( $block );
			$links++;
		}
		return rtrim( $out ) . "\n";
	}

	const MEDIA = array( '*.jpg', '*.jpeg', '*.png', '*.gif', '*.webp', '*.avif', '*.svg', '*.bmp', '*.tif', '*.tiff', '*.mp3', '*.wav', '*.ogg', '*.flac', '*.m4a', '*.mp4', '*.mov', '*.avi', '*.webm', '*.mkv' );

	public static function build_ai_txt() {
		$policy = (string) Settings::get( 'ai_txt_policy' );
		$out    = array(
			'# ai.txt — ' . self::line( get_bloginfo( 'name' ), 120 ),
			'# ' . __( 'Policy for using this site\'s content to train AI models (format: https://site.spawning.ai/spawning-ai-txt).', 'rankyfy-ai-seo' ),
			/* translators: %s: robots.txt URL */
			'# ' . sprintf( __( 'Whether crawlers may read the site at all is set in %s.', 'rankyfy-ai-seo' ), home_url( '/robots.txt' ) ),
		);
		if ( Settings::get( 'llms_enabled' ) ) {
			/* translators: %s: llms.txt URL */
			$out[] = '# ' . sprintf( __( 'A guide to this site for language models: %s', 'rankyfy-ai-seo' ), home_url( '/llms.txt' ) );
		}
		$out[] = '';
		$out[] = 'User-Agent: *';
		if ( 'no_training' === $policy ) {
			$out[] = 'Disallow: /';
		} else {
			if ( 'no_media' === $policy ) {
				foreach ( self::MEDIA as $ext ) {
					$out[] = 'Disallow: ' . $ext;
				}
			}
			$out[] = 'Allow: /';
		}
		return implode( "\n", $out ) . "\n";
	}

	// ── status ─────────────────────────────────────────────────────────────

	/** Request each file the way a crawler would and note who answered. */
	public static function probe() {
		$out = array();
		foreach ( self::FILES as $f ) {
			$url = home_url( '/' . $f );
			$res = Probe::get( $url, Probe::BROWSER_UA, 8 );
			if ( is_wp_error( $res ) ) {
				$out[ $f ] = array( 'state' => 'error', 'status' => 0, 'error' => Util::clean( $res->get_error_message(), 200 ), 'checked_at' => time() );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$ours = (string) wp_remote_retrieve_header( $res, strtolower( self::HEADER ) );
			$type = (string) wp_remote_retrieve_header( $res, 'content-type' );
			$body = (string) wp_remote_retrieve_body( $res );
			if ( 200 === $code && '' !== $ours ) {
				$state = 'ours';
			} elseif ( 200 === $code && false === stripos( $type, 'html' ) && '' !== trim( $body ) ) {
				$state = 'other'; // a file in the web root, or another plugin
			} else {
				$state = 'missing';
			}
			$out[ $f ] = array( 'state' => $state, 'status' => $code, 'error' => '', 'bytes' => strlen( $body ), 'checked_at' => time() );
		}
		update_option( self::STATUS, $out, false );
		return $out;
	}

	/** llms.txt is reachable (ours or anyone's), per the last probe. */
	public static function llms_live() {
		$st = get_option( self::STATUS );
		$s  = is_array( $st ) ? ( $st['llms.txt']['state'] ?? '' ) : '';
		return in_array( $s, array( 'ours', 'other' ), true ) ? true : ( '' === $s ? null : false );
	}

	/** Other plugins that also generate llms.txt. */
	private static function conflicts() {
		$out = array();
		$y   = get_option( 'wpseo' );
		if ( defined( 'WPSEO_VERSION' ) && is_array( $y ) && ! empty( $y['enable_llms_txt'] ) ) {
			$out[] = 'Yoast SEO';
		}
		$rm = get_option( 'rank_math_modules' );
		if ( defined( 'RANK_MATH_VERSION' ) && is_array( $rm ) && in_array( 'llms-txt', $rm, true ) ) {
			$out[] = 'Rank Math';
		}
		$aio = get_option( 'aioseo_options' );
		if ( defined( 'AIOSEO_VERSION' ) && is_string( $aio ) && false !== strpos( $aio, '"llms"' ) && preg_match( '/"llms"\s*:\s*\{[^}]*"enable"\s*:\s*true/', $aio ) ) {
			$out[] = 'All in One SEO';
		}
		return $out;
	}

	/** AI crawler requests for each file over the last 30 days (observed). */
	private static function fetches() {
		global $wpdb;
		$base   = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$hashes = array();
		foreach ( self::FILES as $f ) {
			$hashes[ Util::url_hash( Util::normalize_path( $base . '/' . $f ) ) ] = $f;
		}
		$in   = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT url_hash, bot, SUM(hits) h, MAX(day) last FROM ' . Installer::table( 'daily' ) . " WHERE url_hash IN ({$in}) AND day >= %s GROUP BY url_hash, bot ORDER BY h DESC", array_merge( array_keys( $hashes ), array( wp_date( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$out = array_fill_keys( self::FILES, array( 'hits' => 0, 'last' => null, 'bots' => array() ) );
		foreach ( (array) $rows as $r ) {
			$f                    = $hashes[ $r['url_hash'] ];
			$out[ $f ]['hits']   += (int) $r['h'];
			$out[ $f ]['last']    = max( (string) $out[ $f ]['last'], (string) $r['last'] );
			$out[ $f ]['bots'][]  = array( 'id' => $r['bot'], 'name' => Registry::label( $r['bot'] ), 'hits' => (int) $r['h'] );
		}
		return $out;
	}

	/** Everything the AI files screen shows. */
	public static function status() {
		$probe   = get_option( self::STATUS );
		$probe   = is_array( $probe ) ? $probe : array();
		$fetches = self::fetches();
		$root    = rtrim( ABSPATH, '/' ) . '/';
		$files   = array();
		foreach ( self::FILES as $f ) {
			$active = self::active( $f );
			$built  = $active ? self::get( $f ) : null;
			$body   = $built ? $built['body'] : ( 'ai.txt' === $f ? self::build_ai_txt() : '' );
			if ( 'llms.txt' === $f && '' !== self::override() ) {
				$body = self::override();
			}
			$files[] = array(
				'file'      => $f,
				'url'       => home_url( '/' . $f ),
				'enabled'   => $active,
				'physical'  => file_exists( $root . $f ),
				'built_at'  => $built ? (int) $built['built_at'] : null,
				'bytes'     => strlen( $body ),
				'links'     => $built ? (int) $built['links'] : 0,
				'preview'   => substr( $body, 0, 30000 ),
				'truncated' => strlen( $body ) > 30000,
				'probe'     => $probe[ $f ] ?? null,
				'fetches'   => $fetches[ $f ],
			);
		}
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		return array(
			'files'     => $files,
			'subdir'    => '' !== $base,
			'conflicts' => self::conflicts(),
			'override'  => '' !== self::override(),
			'generated' => Settings::get( 'llms_enabled' ) ? self::get( 'llms.txt' )['body'] : self::build_llms(),
			'settings'  => array_intersect_key( Settings::all(), array_flip( array( 'llms_enabled', 'llms_full', 'llms_summary', 'llms_intro', 'llms_max_links', 'llms_types', 'llms_min_words', 'ai_txt_enabled', 'ai_txt_policy' ) ) ),
			'types'     => array_values(
				array_merge(
					array_map( static function ( $t ) {
						$o = get_post_type_object( $t );
						return array( 'id' => $t, 'label' => $o ? $o->labels->name : $t, 'kind' => 'post_type' );
					}, Inventory::post_types() ),
					array_map( static function ( $t ) {
						$o = get_taxonomy( $t );
						return array( 'id' => $t, 'label' => $o ? $o->labels->name : $t, 'kind' => 'taxonomy' );
					}, Inventory::taxonomies() )
				)
			),
			'tagline'   => (string) get_bloginfo( 'description' ),
		);
	}
}
