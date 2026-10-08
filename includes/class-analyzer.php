<?php
/**
 * Page analysis: what a crawler finds on each page.
 *
 * For important pages (and pages whose stored content is too short to judge,
 * such as page-builder layouts) the worker reads the page as served — status
 * code, X-Robots-Tag, robots meta, canonical, structured data, title and
 * description come from the real response. Otherwise the stored content is
 * rendered from blocks without running shortcodes or theme code, which is
 * fast and has no side effects.
 *
 * Results are facts about the page (observed), stored as JSON on the page
 * row, plus key terms (for topics and link suggestions) and the internal
 * link graph. Findings and scores are derived from them by Coverage.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Analyzer {

	const FETCH_PER_STEP = 8;
	const STALE_DAYS     = 7;

	private static $fetches = 0;

	/** Analyse dirty pages (most important first), then re-check the stalest important ones. */
	public static function step( $budget = 6 ) {
		global $wpdb;
		$t        = Installer::table( 'pages' );
		$deadline = microtime( true ) + $budget;
		self::$fetches = 0;
		$done     = array();
		$rows     = $wpdb->get_results( "SELECT * FROM {$t} WHERE dirty = 1 AND deleted = 0 ORDER BY importance DESC, id ASC LIMIT 40", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( count( (array) $rows ) < 10 ) {
			$stale = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$t} WHERE deleted = 0 AND dirty = 0 AND importance >= %d AND analyzed_at < %d ORDER BY analyzed_at ASC LIMIT 10", (int) Settings::get( 'importance_min' ), time() - self::STALE_DAYS * DAY_IN_SECONDS ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			$rows = array_merge( (array) $rows, (array) $stale );
		}
		foreach ( (array) $rows as $row ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			try {
				self::analyze( $row );
			} catch ( \Throwable $e ) {
				Log::warning( 'page analysis failed', array( 'page' => $row['id'], 'error' => $e->getMessage() ) );
				if ( false === strpos( $e->getMessage(), 'not saved' ) ) {
					// A page that cannot be analysed is not retried on every run; a
					// database conflict (deadlock, lock wait) is, on the next run.
					$wpdb->update( $t, array( 'dirty' => 0, 'analyzed_at' => time() ), array( 'id' => $row['id'] ) );
				}
			}
			$done[] = (int) $row['id'];
		}
		if ( $done ) {
			Coverage::refresh_pages( $done );
			Analytics::bust();
		}
		return count( $done );
	}

	/** Analyse one page row now. */
	public static function analyze( array $row, $force_fetch = false ) {
		global $wpdb;
		$url   = Inventory::url( $row );
		$local = '';
		$post  = null;
		if ( 'post' === $row['object_type'] ) {
			$post = get_post( (int) $row['object_id'] );
			if ( ! $post || 'publish' !== $post->post_status ) {
				$wpdb->update( Installer::table( 'pages' ), array( 'deleted' => 1, 'dirty' => 0 ), array( 'id' => $row['id'] ) );
				return null;
			}
			$local = self::render_local( $post );
		} elseif ( 'term' === $row['object_type'] ) {
			$local = wpautop( (string) term_description( (int) $row['object_id'] ) );
		}

		$important  = (int) $row['importance'] >= (int) Settings::get( 'importance_min' ) || (int) $row['pinned'] > 0;
		$need_fetch = $force_fetch || 'post' !== $row['object_type'] || $important || Text::word_count( wp_strip_all_tags( $local ) ) < 80;
		$fetched    = null;
		if ( $need_fetch && ( $force_fetch || self::$fetches < self::FETCH_PER_STEP ) ) {
			self::$fetches++;
			$fetched = self::fetch( $url );
		}

		$head = $fetched && $fetched['html'] ? self::parse_head( $fetched['html'] ) : array();
		// Body to measure: the stored content when it is substantial, else the served page's main region.
		$body_html = $local;
		if ( $fetched && $fetched['html'] && Text::word_count( wp_strip_all_tags( $local ) ) < 80 ) {
			$body_html = self::main_region( $fetched['html'] );
		}
		$facts = self::measure( $body_html, $url );

		$facts['title']       = $head['title'] ?? ( $post ? get_the_title( $post ) : $row['title'] );
		$facts['description'] = $head['description'] ?? null;
		$facts['h1']          = $head['h1'] ?? null;
		$facts['lang']        = $head['lang'] ?? get_bloginfo( 'language' );
		$facts['schema']      = $head['schema'] ?? self::schema_hint( $post );
		$facts['fetched']     = (bool) $fetched;
		$facts['status']      = $fetched ? $fetched['status'] : null;
		$facts['location']    = $fetched ? $fetched['location'] : null;
		$facts['robots_meta'] = $head['robots'] ?? array();
		$facts['x_robots']    = $fetched ? $fetched['x_robots'] : '';
		$facts['canonical']   = $head['canonical'] ?? null;
		$facts['noindex']     = self::noindex( $facts, $post );
		$facts['noai']        = (bool) preg_match( '/\bno(?:image)?ai\b/i', implode( ' ', $facts['robots_meta'] ) . ' ' . $facts['x_robots'] );
		$facts['canonical_elsewhere'] = self::canonical_elsewhere( $facts['canonical'], $row['path'] );
		$facts['author']      = $post ? self::author( $post ) : null;
		$facts['modified_days'] = $row['modified'] ? (int) floor( ( time() - strtotime( $row['modified'] . ' UTC' ) ) / DAY_IN_SECONDS ) : null;
		$facts['intent']      = Text::intent( (string) $facts['title'], $facts['headings'], $facts['_text'], (string) $row['subtype'], $facts['schema'] );
		$facts['entities']    = Text::entities( $facts['_text'] );

		$emph   = $facts['title'] . ' ' . implode( ' ', $facts['headings'] );
		$counts = Text::term_counts( $facts['_text'], $emph, 40 );
		$links  = $facts['_links'];
		unset( $facts['_text'], $facts['_links'] );

		$page_id = (int) $row['id'];
		// The page's terms, links and facts change together, or not at all; a
		// concurrent analysis of the same page (manual re-check while the worker
		// runs) waits on the row locks instead of interleaving.
		$wpdb->query( 'START TRANSACTION' );
		try {
			self::store_terms( $page_id, $counts );
			self::store_links( $page_id, $links );

			$wpdb->update(
				Installer::table( 'pages' ),
				array(
					'word_count'   => (int) $facts['words'],
					'outlinks'     => count( $links ),
					'noindex'      => $facts['noindex'] ? 1 : 0,
					'http_status'  => (int) ( $facts['status'] ?? 0 ),
					'fetch_at'     => $fetched ? time() : (int) $row['fetch_at'],
					'analyzed_at'  => time(),
					'facts'        => wp_json_encode( $facts ),
					'content_hash' => md5( $body_html ),
					// The WordPress title (the HTML <title> adds the site name); kept in facts too.
					'title'        => Util::clean( $post ? get_the_title( $post ) : ( 'term' === $row['object_type'] ? (string) $row['title'] : (string) $facts['title'] ), 255 ),
					'dirty'        => 0,
				),
				array( 'id' => $page_id )
			);
			self::db_ok();
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
		return $facts;
	}

	/** wpdb never throws: turn a failed write into an exception so the transaction is rolled back. */
	private static function db_ok() {
		global $wpdb;
		if ( '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'page analysis not saved: ' . $wpdb->last_error );
		}
	}

	/** Post content as HTML: blocks rendered, shortcodes removed, no theme code. */
	private static function render_local( \WP_Post $post ) {
		// Static blocks already store their HTML; dropping the block comments is
		// enough. Dynamic blocks (query loops, shop blocks) are not rendered: their
		// callbacks can be slow or have side effects, and important pages are read
		// as served anyway.
		$html = preg_replace( '/<!--\s*\/?wp:[^>]*?-->/s', '', (string) $post->post_content );
		$html = strip_shortcodes( $html );
		if ( 'product' === $post->post_type && $post->post_excerpt ) {
			$html = wpautop( $post->post_excerpt ) . $html;
		}
		return $html;
	}

	/** @return array{status:int,html:string,x_robots:string,location:string}|null */
	private static function fetch( $url ) {
		$res = Probe::get( $url, Probe::BROWSER_UA, 10, 0 );
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		$type   = (string) wp_remote_retrieve_header( $res, 'content-type' );
		$xr     = wp_remote_retrieve_header( $res, 'x-robots-tag' );
		return array(
			'status'   => $status,
			'html'     => ( $status < 300 && false !== stripos( $type, 'html' ) ) ? (string) wp_remote_retrieve_body( $res ) : '',
			'x_robots' => Util::clean( is_array( $xr ) ? implode( ', ', $xr ) : (string) $xr, 300 ),
			'location' => Util::clean( (string) wp_remote_retrieve_header( $res, 'location' ), 300 ),
		);
	}

	private static function dom( $html ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $doc;
	}

	/** Head data from a served page. */
	public static function parse_head( $html ) {
		$doc = self::dom( $html );
		if ( ! $doc ) {
			return array();
		}
		$xp  = new \DOMXPath( $doc );
		$out = array( 'robots' => array(), 'schema' => array() );
		$t   = $xp->query( '//title' );
		if ( $t && $t->length ) {
			$out['title'] = Util::clean( $t->item( 0 )->textContent, 255 );
		}
		foreach ( $xp->query( '//meta[@name]' ) as $m ) {
			$name = strtolower( $m->getAttribute( 'name' ) );
			$val  = Util::clean( $m->getAttribute( 'content' ), 300 );
			if ( 'description' === $name ) {
				$out['description'] = $val;
			} elseif ( in_array( $name, array( 'robots', 'googlebot', 'bingbot' ), true ) || Registry::get( $name ) || preg_match( '/bot|gpt|claude|perplexity/i', $name ) ) {
				$out['robots'][] = $name . ': ' . strtolower( $val );
			}
		}
		$c = $xp->query( '//link[@rel="canonical"]' );
		if ( $c && $c->length ) {
			$out['canonical'] = esc_url_raw( $c->item( 0 )->getAttribute( 'href' ) );
		}
		$html_el = $xp->query( '//html' );
		if ( $html_el && $html_el->length ) {
			$out['lang'] = Util::clean( $html_el->item( 0 )->getAttribute( 'lang' ), 20 );
		}
		$h1 = $xp->query( '//h1' );
		$out['h1'] = $h1 && $h1->length ? Util::clean( $h1->item( 0 )->textContent, 200 ) : '';
		$types = array();
		foreach ( $xp->query( '//script[@type="application/ld+json"]' ) as $s ) {
			$json = json_decode( trim( $s->textContent ), true );
			self::schema_types( $json, $types );
		}
		foreach ( $xp->query( '//*[@itemtype]' ) as $el ) {
			$types[ preg_replace( '#^https?://schema\.org/#i', '', $el->getAttribute( 'itemtype' ) ) ] = true;
		}
		$out['schema'] = array_slice( array_keys( $types ), 0, 20 );
		return $out;
	}

	private static function schema_types( $node, array &$types ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( isset( $node['@type'] ) ) {
			foreach ( (array) $node['@type'] as $ty ) {
				if ( is_string( $ty ) && strlen( $ty ) < 60 ) {
					$types[ preg_replace( '#^https?://schema\.org/#i', '', $ty ) ] = true;
				}
			}
		}
		foreach ( $node as $v ) {
			if ( is_array( $v ) ) {
				self::schema_types( $v, $types );
			}
		}
	}

	/** The page's main content region (main, article, or body without chrome). */
	private static function main_region( $html ) {
		$doc = self::dom( $html );
		if ( ! $doc ) {
			return '';
		}
		$xp = new \DOMXPath( $doc );
		foreach ( array( '//main', '//article', '//*[@role="main"]', '//body' ) as $q ) {
			$n = $xp->query( $q );
			if ( $n && $n->length ) {
				$node = $n->item( 0 );
				foreach ( array( 'script', 'style', 'noscript', 'nav', 'header', 'footer', 'aside', 'form', 'svg' ) as $tag ) {
					foreach ( iterator_to_array( $node->getElementsByTagName( $tag ) ) as $el ) {
						$el->parentNode->removeChild( $el );
					}
				}
				return $doc->saveHTML( $node );
			}
		}
		return '';
	}

	/** Structure, text and links of a content fragment. */
	public static function measure( $html, $page_url ) {
		$f   = array(
			'words'            => 0,
			'headings'         => array(),
			'h2'               => 0,
			'h3'               => 0,
			'question_headings' => array(),
			'lists'            => 0,
			'tables'           => 0,
			'images'           => 0,
			'images_no_alt'    => 0,
			'external_links'   => 0,
			'first_para_words' => 0,
			'avg_sentence'     => 0,
			'questions'        => array(),
			'faq'              => false,
			'_text'            => '',
			'_links'           => array(),
		);
		$doc = self::dom( '<div id="rfy-root">' . $html . '</div>' );
		if ( ! $doc ) {
			$text       = wp_strip_all_tags( $html );
			$f['words'] = Text::word_count( $text );
			$f['_text'] = $text;
			return $f;
		}
		$xp   = new \DOMXPath( $doc );
		$root = $xp->query( '//*[@id="rfy-root"]' )->item( 0 );
		foreach ( array( 'script', 'style', 'noscript' ) as $tag ) {
			foreach ( iterator_to_array( $root->getElementsByTagName( $tag ) ) as $el ) {
				$el->parentNode->removeChild( $el );
			}
		}
		foreach ( $xp->query( './/h2|.//h3|.//h4', $root ) as $h ) {
			$txt = Util::clean( $h->textContent, 200 );
			if ( '' === $txt ) {
				continue;
			}
			$f[ 'h2' === strtolower( $h->nodeName ) ? 'h2' : 'h3' ]++;
			if ( count( $f['headings'] ) < 40 ) {
				$f['headings'][] = $txt;
			}
			if ( Text::is_question( $txt ) && count( $f['question_headings'] ) < 20 ) {
				$f['question_headings'][] = $txt;
			}
		}
		$f['lists']  = $xp->query( './/ul|.//ol|.//dl', $root )->length;
		$f['tables'] = $xp->query( './/table', $root )->length;
		foreach ( $xp->query( './/img', $root ) as $img ) {
			$f['images']++;
			if ( '' === trim( (string) $img->getAttribute( 'alt' ) ) && 'presentation' !== $img->getAttribute( 'role' ) ) {
				$f['images_no_alt']++;
			}
		}
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$self      = Util::normalize_path( $page_url );
		foreach ( $xp->query( './/a[@href]', $root ) as $a ) {
			$href = trim( (string) $a->getAttribute( 'href' ) );
			if ( '' === $href || '#' === $href[0] || preg_match( '#^(mailto|tel|javascript):#i', $href ) ) {
				continue;
			}
			$host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
			if ( '' === $host || $host === $home_host || 'www.' . $host === $home_host || $host === 'www.' . $home_host ) {
				$p = Util::normalize_path( $href );
				if ( $p !== $self && '' === Util::path_kind( $p ) ) {
					$f['_links'][ $p ] = true;
				}
			} else {
				$f['external_links']++;
			}
		}
		$f['_links'] = array_slice( array_keys( $f['_links'] ), 0, 300 );
		$f['faq']    = $xp->query( './/details|.//*[contains(@class,"faq")]|.//*[contains(@class,"schema-faq")]|.//*[contains(@class,"rank-math-faq")]', $root )->length > 0
			|| count( $f['question_headings'] ) >= 3;

		// Text: block elements separated so words do not run together.
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( preg_replace( '#</(p|div|li|h[1-6]|td|th|br|section|article)>#i', "\n", $doc->saveHTML( $root ) ) ) ) );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$f['words'] = Text::word_count( $text );
		$f['_text'] = $text;

		$p = $xp->query( './/p', $root );
		foreach ( $p as $para ) {
			$w = Text::word_count( $para->textContent );
			if ( $w >= 5 ) {
				$f['first_para_words'] = $w;
				break;
			}
		}
		$sentences = Text::sentences( $text );
		if ( $sentences ) {
			$f['avg_sentence'] = (int) round( $f['words'] / count( $sentences ) );
		}
		foreach ( $sentences as $s ) {
			if ( '?' === substr( $s, -1 ) && strlen( $s ) < 200 && count( $f['questions'] ) < 15 ) {
				$f['questions'][] = Util::clean( $s, 200 );
			}
		}
		return $f;
	}

	/** Schema hints for pages that were not fetched: what the SEO/shop plugins would output. */
	private static function schema_hint( $post ) {
		$types = array();
		if ( ! $post ) {
			return $types;
		}
		if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) ) {
			$types[] = 'WebPage';
			if ( 'post' === $post->post_type ) {
				$types[] = 'Article';
			}
		}
		if ( 'product' === $post->post_type && class_exists( 'WooCommerce' ) ) {
			$types[] = 'Product';
		}
		if ( false !== strpos( $post->post_content, 'wp:yoast/faq-block' ) || false !== strpos( $post->post_content, 'wp:rank-math/faq-block' ) ) {
			$types[] = 'FAQPage';
		}
		return $types;
	}

	/** noindex from the response (header/meta) or, unfetched, from the SEO plugin's setting. */
	private static function noindex( array $facts, $post ) {
		$directives = strtolower( implode( ' ', $facts['robots_meta'] ) . ' ' . $facts['x_robots'] );
		if ( preg_match( '/(^|[\s:,])(noindex|none)\b/', $directives ) ) {
			return true;
		}
		if ( $facts['fetched'] || ! $post ) {
			return false;
		}
		if ( Guard::seo_meta( $post )['noindex'] ) {
			return true;
		}
		return '0' === (string) get_option( 'blog_public' );
	}

	private static function canonical_elsewhere( $canonical, $path ) {
		if ( ! $canonical ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $canonical, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $host && $host !== $home && 'www.' . $host !== $home && $host !== 'www.' . $home ) {
			return true;
		}
		return strtolower( Util::normalize_path( $canonical ) ) !== strtolower( $path );
	}

	private static function author( \WP_Post $post ) {
		if ( 'post' !== $post->post_type ) {
			return null; // pages and products rarely show an author; not judged
		}
		$u = get_userdata( (int) $post->post_author );
		if ( ! $u ) {
			return array( 'name' => '', 'bio' => false );
		}
		return array(
			'name' => Util::clean( $u->display_name, 80 ),
			'bio'  => strlen( trim( (string) get_the_author_meta( 'description', $u->ID ) ) ) >= 60,
		);
	}

	// ── terms and links ────────────────────────────────────────────────────

	private static function store_terms( $page_id, array $counts ) {
		global $wpdb;
		$pt   = Installer::table( 'page_terms' );
		$tt   = Installer::table( 'terms' );
		$old  = $wpdb->get_col( $wpdb->prepare( "SELECT term FROM {$pt} WHERE page_id = %d", $page_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$old  = array_flip( (array) $old );
		$new  = array();
		foreach ( $counts as $term => $c ) {
			$term = function_exists( 'mb_substr' ) ? mb_substr( $term, 0, 64 ) : substr( $term, 0, 64 );
			$new[ $term ] = $c;
		}
		$removed = array_diff_key( $old, $new );
		$added   = array_diff_key( $new, $old );
		if ( $removed ) {
			$keys = array_keys( $removed );
			$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$tt} SET df = GREATEST(df, 1) - 1 WHERE term IN ({$in})", $keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::db_ok();
		}
		if ( $added ) {
			$vals = array();
			$args = array();
			foreach ( array_keys( $added ) as $term ) {
				$vals[] = '(%s, 1)';
				$args[] = $term;
			}
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$tt} (term, df) VALUES " . implode( ',', $vals ) . ' ON DUPLICATE KEY UPDATE df = df + 1', $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::db_ok();
		}
		$wpdb->delete( $pt, array( 'page_id' => $page_id ) );
		self::db_ok();
		if ( $new ) {
			$vals = array();
			$args = array();
			foreach ( $new as $term => $c ) {
				$vals[] = '(%d, %s, %f)';
				array_push( $args, $page_id, $term, (float) $c );
			}
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$pt} (page_id, term, weight) VALUES " . implode( ',', $vals ) . ' ON DUPLICATE KEY UPDATE weight = VALUES(weight)', $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::db_ok();
		}
	}

	private static function store_links( $page_id, array $paths ) {
		global $wpdb;
		$lt  = Installer::table( 'links' );
		$old = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT to_id FROM {$lt} WHERE from_id = %d", $page_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array();
		if ( $paths ) {
			$hashes = array_map( array( Util::class, 'url_hash' ), $paths );
			foreach ( array_chunk( $hashes, 200 ) as $chunk ) {
				$in  = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
				$ids = array_merge( $ids, array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'pages' ) . " WHERE url_hash IN ({$in}) AND deleted = 0", $chunk ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		$ids = array_values( array_diff( array_unique( $ids ), array( $page_id ) ) );
		$wpdb->delete( $lt, array( 'from_id' => $page_id ) );
		self::db_ok();
		if ( $ids ) {
			$vals = array();
			$args = array();
			foreach ( $ids as $to ) {
				$vals[] = '(%d, %d)';
				array_push( $args, $page_id, $to );
			}
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$lt} (from_id, to_id) VALUES " . implode( ',', $vals ), $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::db_ok();
		}
		$touched = array_values( array_unique( array_merge( $old, $ids ) ) );
		if ( $touched ) {
			$in = implode( ',', array_map( 'intval', $touched ) );
			$wpdb->query( 'UPDATE ' . Installer::table( 'pages' ) . " p SET p.inlinks = (SELECT COUNT(*) FROM {$lt} l WHERE l.to_id = p.id) WHERE p.id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::db_ok();
		}
	}

	/** Key terms of a page, ranked by TF-IDF against the site. */
	public static function key_terms( $page_id, $limit = 12 ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT pt.term, pt.weight, COALESCE(t.df, 0) df FROM ' . Installer::table( 'page_terms' ) . ' pt LEFT JOIN ' . Installer::table( 'terms' ) . ' t ON t.term = pt.term WHERE pt.page_id = %d', $page_id ),
			ARRAY_A
		);
		$counts = array();
		$df     = array();
		foreach ( (array) $rows as $r ) {
			$counts[ $r['term'] ] = (float) $r['weight'];
			$df[ $r['term'] ]     = (int) $r['df'];
		}
		$ranked = Text::rank( $counts, $df, self::analyzed_count() );
		$out    = array();
		foreach ( array_slice( $ranked, 0, $limit, true ) as $t => $score ) {
			$out[] = array(
				'term'  => $t,
				'count' => (int) $counts[ $t ],
				'pages' => $df[ $t ] ?? 0,
				'score' => round( $score, 4 ),
			);
		}
		return $out;
	}

	public static function analyzed_count() {
		global $wpdb;
		static $n = null, $at = 0;
		if ( null === $n || time() - $at > 60 ) {
			$at = time();
			$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( 'pages' ) . ' WHERE deleted = 0 AND analyzed_at > 0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return max( 1, $n );
	}
}
