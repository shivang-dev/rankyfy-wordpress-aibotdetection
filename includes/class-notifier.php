<?php
/**
 * Delivers alerts outside the dashboard: email (immediately for critical
 * alerts, everything, or a daily digest — the owner chooses) and an optional
 * webhook (Slack-compatible JSON) to an https URL. Webhook targets are
 * validated as public https URLs and called with wp_safe_remote_post, so a
 * setting cannot be used to reach internal addresses.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Notifier {

	const MAX_EMAILS_PER_DAY = 12;

	public static function deliver() {
		global $wpdb;
		$t      = Installer::table( 'alerts' );
		$unsent = $wpdb->get_results( "SELECT * FROM {$t} WHERE sent_at = 0 ORDER BY id ASC LIMIT 50", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$mode   = Settings::get( 'notify_mode' );
		$email  = array();
		foreach ( (array) $unsent as $a ) {
			if ( 'all' === $mode || ( 'critical' === $mode && 'critical' === $a['severity'] ) ) {
				$email[] = $a;
			}
		}
		if ( $email ) {
			self::email( $email, false );
		}
		self::webhook( (array) $unsent );
		if ( $unsent ) {
			$ids = implode( ',', array_map( 'intval', array_column( $unsent, 'id' ) ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET sent_at = %d WHERE id IN ({$ids})", time() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( 'digest' === $mode ) {
			self::digest();
		}
	}

	private static function digest() {
		global $wpdb;
		$last = (int) get_option( 'rfy_digest_at', 0 );
		if ( time() - $last < DAY_IN_SECONDS ) {
			return;
		}
		update_option( 'rfy_digest_at', time(), false );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'alerts' ) . ' WHERE created_at > %d ORDER BY FIELD(severity, \'critical\', \'warning\', \'info\'), id DESC LIMIT 30', max( $last, time() - 2 * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- table names come from the fixed rfy_ prefix (Installer::table), never from user input
		if ( $rows ) {
			self::email( $rows, true );
		}
	}

	private static function under_daily_cap() {
		$key = 'rfy_mails_' . wp_date( 'Ymd' );
		$n   = (int) get_transient( $key );
		if ( $n >= self::MAX_EMAILS_PER_DAY ) {
			return false;
		}
		set_transient( $key, $n + 1, DAY_IN_SECONDS );
		return true;
	}

	/** One email for a batch of alerts. */
	public static function email( array $alerts, $digest ) {
		$to = Settings::get( 'notify_email' );
		if ( ! $to || ! is_email( $to ) || ! self::under_daily_cap() ) {
			return false;
		}
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$first = Alerts::present( $alerts[0] );
		if ( $digest ) {
			/* translators: 1: site, 2: count */
			$subject = sprintf( __( '[%1$s] AI crawler digest: %2$d updates', 'rankyfy-ai-seo' ), $site, count( $alerts ) );
		} elseif ( 1 === count( $alerts ) ) {
			$subject = sprintf( '[%s] %s', $site, $first['title'] );
		} else {
			/* translators: 1: site, 2: count, 3: first title */
			$subject = sprintf( __( '[%1$s] %2$d AI crawler alerts: %3$s', 'rankyfy-ai-seo' ), $site, count( $alerts ), $first['title'] );
		}
		$base  = admin_url( 'admin.php?page=rankyfy-ai-seo' );
		$lines = array();
		foreach ( $alerts as $row ) {
			$a       = Alerts::present( $row );
			$lines[] = strtoupper( $a['severity'] ) . ' — ' . $a['title'];
			$lines[] = __( 'What happened:', 'rankyfy-ai-seo' ) . ' ' . $a['what'];
			$lines[] = __( 'Why it matters:', 'rankyfy-ai-seo' ) . ' ' . $a['why'];
			if ( $a['affected'] ) {
				$lines[] = __( 'Affected:', 'rankyfy-ai-seo' );
				foreach ( array_slice( $a['affected'], 0, 8 ) as $p ) {
					$lines[] = '  • ' . ( $p['label'] ?? '' ) . ' — ' . home_url( rawurldecode( (string) ( $p['path'] ?? '' ) ) ) . ( ! empty( $p['detail'] ) ? ' (' . $p['detail'] . ')' : '' );
				}
			}
			$lines[] = __( 'What to do:', 'rankyfy-ai-seo' ) . ' ' . $a['action'];
			$lines[] = $base . $a['route'];
			$lines[] = '';
		}
		$lines[] = __( 'You receive these emails because of the notification settings in RankyFy AI SEO.', 'rankyfy-ai-seo' ) . ' ' . $base . '#/settings';
		return wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	private static function webhook( array $alerts ) {
		$url = Settings::get( 'webhook_url' );
		if ( ! $url || ! $alerts ) {
			return;
		}
		$min  = Catalog::SEVERITY_WEIGHT[ Settings::get( 'webhook_level' ) ] ?? 2;
		$sent = 0;
		foreach ( $alerts as $row ) {
			if ( ( Catalog::SEVERITY_WEIGHT[ $row['severity'] ] ?? 1 ) < $min || $sent >= 10 ) {
				continue;
			}
			self::post( $url, Alerts::present( $row ) );
			$sent++;
		}
	}

	/** @return true|\WP_Error */
	public static function post( $url, array $a ) {
		$link = admin_url( 'admin.php?page=rankyfy-ai-seo' ) . $a['route'];
		// Slack mrkdwn: &, < and > are control characters (links, @channel) — escape everything we did not write.
		$e    = static function ( $t ) {
			return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), (string) $t );
		};
		$text = '*' . $e( $a['title'] ) . "*\n" . $e( $a['what'] ) . "\n_" . __( 'Why it matters:', 'rankyfy-ai-seo' ) . '_ ' . $e( $a['why'] ) . "\n_" . __( 'What to do:', 'rankyfy-ai-seo' ) . '_ ' . $e( $a['action'] ) . "\n<{$link}|" . __( 'Open in WordPress', 'rankyfy-ai-seo' ) . '>';
		$res  = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode(
					array(
						'text'     => $text, // Slack / Mattermost / Google Chat
						'site'     => home_url( '/' ),
						'severity' => $a['severity'],
						'title'    => $a['title'],
						'what'     => $a['what'],
						'why'      => $a['why'],
						'affected' => $a['affected'],
						'action'   => $a['action'],
						'link'     => $link,
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			Log::warning( 'webhook failed', array( 'error' => $res->get_error_message() ) );
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 300 ) {
			Log::warning( 'webhook refused', array( 'status' => $code ) );
			return new \WP_Error( 'rfy_webhook', sprintf( /* translators: %d: status */ __( 'The webhook answered with HTTP %d.', 'rankyfy-ai-seo' ), $code ) );
		}
		return true;
	}
}
