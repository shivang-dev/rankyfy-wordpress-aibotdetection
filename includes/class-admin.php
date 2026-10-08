<?php
/**
 * Admin screens: one app page (plain-DOM single-page UI), the menu badge
 * with unread alerts, a small WordPress dashboard widget and CSV export.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Admin {

	const SLUG = 'rankyfy-ai-crawlers';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'widget' ) );
		add_action( 'admin_post_rfaib_export', array( __CLASS__, 'export' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RFAIB_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_text' ) );
	}

	/** Suggested text for the site's privacy policy (Settings → Privacy). */
	public static function privacy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'This site records requests made by automated crawlers (for example search engines and AI crawlers) to understand how they use its content. For those requests we store the time, the page requested, the server response, the user agent and the network the request came from (the address with its last part removed). Full addresses are kept only for a few minutes while the crawler\'s identity is verified, then discarded. Ordinary visitors are not recorded; when a visitor arrives from an AI assistant such as ChatGPT, only a daily count per page is kept, without any information about the visitor.', 'rankyfy-ai-crawlers' ) . '</p>';
		wp_add_privacy_policy_content( __( 'RankyFy AI Crawler Monitor', 'rankyfy-ai-crawlers' ), wp_kses_post( $text ) );
	}

	private static function unread() {
		$n = get_transient( 'rfaib_unread' );
		if ( false === $n ) {
			$n = Installer::ready() ? Alerts::unread_count() : 0;
			set_transient( 'rfaib_unread', $n, 5 * MINUTE_IN_SECONDS );
		}
		return (int) $n;
	}

	public static function menu() {
		$n     = self::unread();
		$badge = $n ? ' <span class="awaiting-mod count-' . (int) $n . '"><span class="pending-count">' . number_format_i18n( $n ) . '</span></span>' : '';
		add_menu_page(
			__( 'RankyFy', 'rankyfy-ai-crawlers' ),
			__( 'RankyFy', 'rankyfy-ai-crawlers' ) . $badge,
			Rest::capability(),
			self::SLUG,
			array( __CLASS__, 'render' ),
			'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 2a3 3 0 0 1 3 3v1h2a2 2 0 0 1 2 2v6a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V8a2 2 0 0 1 2-2h2V5a3 3 0 0 1 3-3zm0 1.6A1.4 1.4 0 0 0 8.6 5v1h2.8V5A1.4 1.4 0 0 0 10 3.6zM7.5 10a1.25 1.25 0 1 0 0 2.5 1.25 1.25 0 0 0 0-2.5zm5 0a1.25 1.25 0 1 0 0 2.5 1.25 1.25 0 0 0 0-2.5zM7 14.5v1h6v-1z"/></svg>' ),
			81 // just below Settings: a plugin that pushes itself up the menu is the most-complained-about behaviour
		);
		// One top-level entry, seven children. The first is the app page itself;
		// the others link to its routes (#/…), so moving between them does not
		// reload the page. admin.js keeps the highlight in step.
		foreach ( self::sections() as $route => $label ) {
			add_submenu_page(
				self::SLUG,
				__( 'RankyFy', 'rankyfy-ai-crawlers' ),
				$label,
				Rest::capability(),
				'' === $route ? self::SLUG : 'admin.php?page=' . self::SLUG . '#' . $route,
				'' === $route ? array( __CLASS__, 'render' ) : null
			);
		}
	}

	/** Menu sections: route => label. */
	public static function sections() {
		return array(
			''           => __( 'Dashboard', 'rankyfy-ai-crawlers' ),
			'/crawlers'  => __( 'AI Crawlers', 'rankyfy-ai-crawlers' ),
			'/referrals' => __( 'AI Referrals', 'rankyfy-ai-crawlers' ),
			'/readiness' => __( 'Readiness Score', 'rankyfy-ai-crawlers' ),
			'/access'    => __( 'Access Manager', 'rankyfy-ai-crawlers' ),
			'/llms'      => __( 'llms.txt', 'rankyfy-ai-crawlers' ),
			'/opportunities' => __( 'Opportunities', 'rankyfy-ai-crawlers' ),
			'/settings'  => __( 'Settings', 'rankyfy-ai-crawlers' ),
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Dashboard', 'rankyfy-ai-crawlers' ) . '</a>' );
		return $links;
	}

	public static function render() {
		echo '<div class="wrap rfaib-wrap"><h1 class="screen-reader-text">' . esc_html__( 'RankyFy', 'rankyfy-ai-crawlers' ) . '</h1><hr class="wp-header-end"><div id="rfaib-app" class="rfaib-app" aria-live="polite"><p class="rfaib-boot">' . esc_html__( 'Loading…', 'rankyfy-ai-crawlers' ) . '</p></div></div>';
	}

	private static function version( $file ) {
		$path = RFAIB_DIR . $file;
		return RFAIB_VERSION . '.' . ( is_readable( $path ) ? filemtime( $path ) : 0 );
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'rfaib-admin', RFAIB_URL . 'assets/css/admin.css', array(), self::version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'rfaib-admin', RFAIB_URL . 'assets/js/admin.js', array( 'wp-i18n' ), self::version( 'assets/js/admin.js' ), true );
		wp_set_script_translations( 'rfaib-admin', 'rankyfy-ai-crawlers' );
		wp_localize_script(
			'rfaib-admin',
			'RFAIB',
			array(
				'root'      => esc_url_raw( rest_url( Rest::NS ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'adminUrl'  => admin_url( 'admin.php?page=' . self::SLUG ),
				'exportUrl' => wp_nonce_url( admin_url( 'admin-post.php?action=rfaib_export' ), 'rfaib_export' ),
				'workerUrl' => RFAIB_URL . 'assets/js/log-worker.js?ver=' . rawurlencode( self::version( 'assets/js/log-worker.js' ) ),
				'siteUrl'   => home_url( '/' ),
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'version'   => RFAIB_VERSION,
				'slug'      => self::SLUG,
				'importBatch' => Importer::BATCH,
			)
		);
	}

	public static function widget() {
		if ( ! current_user_can( Rest::capability() ) || ! Installer::ready() ) {
			return;
		}
		wp_add_dashboard_widget( 'rfaib_widget', __( 'AI crawlers — last 7 days', 'rankyfy-ai-crawlers' ), array( __CLASS__, 'widget_render' ) );
	}

	public static function widget_render() {
		$o   = Analytics::overview( 7 );
		$obs = $o['observed'];
		echo '<ul class="rfaib-widget">';
		/* translators: %s: number */
		echo '<li>' . esc_html( sprintf( __( '%s requests from AI crawlers', 'rankyfy-ai-crawlers' ), number_format_i18n( $obs['ai_requests'] ) ) ) . '</li>';
		/* translators: %s: number */
		echo '<li>' . esc_html( sprintf( __( '%s different AI crawlers', 'rankyfy-ai-crawlers' ), number_format_i18n( $obs['ai_bots'] ) ) ) . '</li>';
		/* translators: %s: number */
		echo '<li>' . esc_html( sprintf( __( '%s fetches made for AI assistant users', 'rankyfy-ai-crawlers' ), number_format_i18n( $obs['user_fetches'] ) ) ) . '</li>';
		if ( $o['coverage']['important'] ) {
			/* translators: 1: crawled, 2: total */
			echo '<li>' . esc_html( sprintf( __( '%1$s of %2$s important pages crawled', 'rankyfy-ai-crawlers' ), number_format_i18n( $o['coverage']['important_crawled'] ), number_format_i18n( $o['coverage']['important'] ) ) ) . '</li>';
		}
		echo '</ul>';
		$n = Alerts::unread_count();
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Open AI Crawler Monitor', 'rankyfy-ai-crawlers' ) . '</a>';
		if ( $n ) {
			/* translators: %d: count */
			echo ' · <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) . '#/alerts' ) . '">' . esc_html( sprintf( _n( '%d unread alert', '%d unread alerts', $n, 'rankyfy-ai-crawlers' ), $n ) ) . '</a>';
		}
		echo '</p>';
	}

	/** CSV download of crawler requests, pages or crawlers. */
	public static function export() {
		if ( ! current_user_can( Rest::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'rankyfy-ai-crawlers' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'rfaib_export' );
		$ok = Rate_Limiter::hit( 'export' );
		if ( is_wp_error( $ok ) ) {
			wp_die( esc_html( $ok->get_error_message() ), '', array( 'response' => 429 ) );
		}
		global $wpdb;
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'events'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above
		$days = isset( $_GET['days'] ) ? max( 1, min( 365, (int) $_GET['days'] ) ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ai-crawlers-' . $type . '-' . gmdate( 'Ymd' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$put = static function ( array $row ) use ( $out ) {
			fputcsv( $out, array_map( array( Util::class, 'csv_cell' ), $row ) );
		};
		if ( 'referrals' === $type ) {
			$put( array( 'landing_page', 'engine', 'visits_' . $days . 'd' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT MAX(path) path, source, SUM(hits) h FROM ' . Installer::table( 'referrals' ) . ' WHERE day >= %s GROUP BY url_hash, source ORDER BY h DESC LIMIT 100000', wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS ) ), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$put( array( $r['path'], Registry::referrer_name( $r['source'] ), $r['h'] ) );
			}
		} elseif ( 'pages' === $type ) {
			$put( array( 'path', 'title', 'type', 'importance', 'aeo_score', 'words', 'internal_links_in', 'last_ai_crawl_utc', 'ai_requests_' . $days . 'd', 'crawlers' ) );
			$offset = 0;
			do {
				$res = Analytics::pages( array( 'filter' => 'all', 'sort' => 'importance', 'page' => ++$offset, 'days' => $days ) );
				foreach ( $res['items'] as $p ) {
					$put( array( $p['path'], $p['title'], $p['subtype'], $p['importance'], $p['score'], $p['words'], $p['inlinks'], $p['last_ai'] ? gmdate( 'Y-m-d H:i:s', $p['last_ai'] ) : '', $p['hits'], implode( ' ', $p['bots'] ) ) );
				}
			} while ( $offset < $res['pages'] && $offset < 400 );
		} elseif ( 'bots' === $type ) {
			$put( array( 'crawler', 'company', 'category', 'requests', 'verified', 'impersonations', 'errors', 'avg_ms', 'pages', 'first_seen_utc', 'last_seen_utc' ) );
			foreach ( Analytics::bots( $days ) as $b ) {
				$put( array( $b['name'], $b['provider'], $b['category'], $b['requests'], $b['verified'], $b['impersonations'], $b['errors'], $b['avg_ms'], $b['pages'], $b['first_seen'] ? gmdate( 'Y-m-d H:i:s', $b['first_seen'] ) : '', $b['last_seen'] ? gmdate( 'Y-m-d H:i:s', $b['last_seen'] ) : '' ) );
			}
		} else {
			$put( array( 'time_utc', 'crawler', 'class', 'verification', 'method', 'path', 'status', 'ms', 'network', 'user_agent', 'source' ) );
			$last = PHP_INT_MAX;
			$from = time() - $days * DAY_IN_SECONDS;
			for ( $i = 0; $i < 200; $i++ ) {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'events' ) . ' WHERE id < %d AND ts >= %d ORDER BY id DESC LIMIT 5000', $last, $from ), ARRAY_A );
				if ( ! $rows ) {
					break;
				}
				foreach ( $rows as $e ) {
					$put( array( gmdate( 'Y-m-d H:i:s', (int) $e['ts'] ), Registry::label( $e['bot'] ), $e['cls'], $e['vstate'], $e['method'], $e['path'], $e['status'], $e['ms'], $e['ip_net'], $e['ua'], $e['source'] ) );
					$last = (int) $e['id'];
				}
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
