<?php
/**
 * Who is this request? Pure classification over the compiled matcher; no
 * database access except the address-range lookup in verify(), which runs
 * only for requests that already claimed to be a crawler.
 *
 * Classes (cls):
 *   ai        a registered AI crawler or assistant (see vstate for how sure we are)
 *   search    a registered classic search crawler
 *   known     another registered automated client (SEO tool, link preview, monitor)
 *   potential an unregistered bot whose user agent suggests AI use
 *   unknown   an unregistered bot
 *   spoofed   claims to be a registered crawler but failed verification
 *   human     a browser
 *
 * Verification (vstate):
 *   verified  address in the operator's published ranges, forward-confirmed
 *             reverse DNS, or a valid request signature
 *   failed    checked and did not match (→ cls spoofed)
 *   pending   reverse-DNS or signature check queued for the background worker
 *   none      the operator publishes no way to verify it; user agent only
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Detector {

	/**
	 * Classify a user agent (plus the optional Signature-Agent header and
	 * address for agents that use a browser user agent).
	 *
	 * @return array{bot:string,cls:string,ai:bool}
	 */
	public static function match( $ua, array $m, $ip = '', $signature_agent = '' ) {
		$ua = (string) $ua;
		if ( '' !== $m['re'] && '' !== $ua && preg_match_all( $m['re'], $ua, $hits ) ) {
			$best = '';
			$bp   = -1;
			foreach ( $hits[0] as $tok ) {
				$id = $m['tok'][ strtolower( $tok ) ] ?? '';
				$p  = $id ? (int) ( $m['info'][ $id ]['p'] ?? 0 ) : -1;
				if ( $p > $bp ) {
					$best = $id;
					$bp   = $p;
				}
			}
			if ( $best ) {
				return self::result( $best, $m );
			}
		}
		if ( '' !== $signature_agent ) {
			$host = self::signature_host( $signature_agent );
			$id   = $host && isset( $m['sig'][ $host ] ) ? $m['sig'][ $host ] : '_signed';
			return array(
				'bot' => $id,
				'cls' => 'ai',
				'ai'  => true,
				'sig' => true,
			);
		}
		if ( ! self::browser_shaped( $ua ) && @preg_match( $m['bot'], $ua ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$potential = @preg_match( $m['hint'], $ua ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return array(
				'bot' => $potential ? '_potential' : '_unknown',
				'cls' => $potential ? 'potential' : 'unknown',
				'ai'  => (bool) $potential,
			);
		}
		if ( '' !== $ip ) {
			$id = Ranges::ip_only_match( $ip );
			if ( $id ) {
				return self::result( $id, $m, true );
			}
		}
		return array(
			'bot' => '',
			'cls' => 'human',
			'ai'  => false,
		);
	}

	/**
	 * A complete browser user agent ("Mozilla/5.0 (Android 10; CUBOT X30)
	 * AppleWebKit/… Chrome/… Mobile Safari/…") is a browser even when a device
	 * name happens to contain "bot". Registered crawlers are matched before this
	 * by their own tokens; clients that say "compatible;" are not browsers.
	 */
	public static function browser_shaped( $ua ) {
		return (bool) preg_match( '~^Mozilla/5\.0 \((?:Windows|Macintosh|X11|Linux|iPhone|iPad|iPod|Android)[^)]*\) (?:AppleWebKit/[\d.]+ \(KHTML, like Gecko\)|Gecko/\d+)[^()]*(?:Chrome|CriOS|Safari|Firefox|FxiOS|Edg|OPR|Version)/[\d.]+~', $ua )
			&& false === stripos( $ua, 'compatible;' )
			&& ! preg_match( '~HeadlessChrome|PhantomJS|Lighthouse|Puppeteer|Playwright~i', $ua );
	}

	private static function result( $id, array $m, $by_address = false ) {
		$info = $m['info'][ $id ] ?? array( 'ai' => 0, 'c' => 'other' );
		if ( $info['ai'] ) {
			$cls = 'ai';
		} elseif ( 'search' === $info['c'] ) {
			$cls = 'search';
		} else {
			$cls = 'known';
		}
		$out = array(
			'bot' => $id,
			'cls' => $cls,
			'ai'  => (bool) $info['ai'],
		);
		if ( $by_address ) {
			$out['by_address'] = true; // matched by published range: already verified
		}
		return $out;
	}

	/** Host named by a Signature-Agent header ("https://chatgpt.com" or sig1="https://…"). */
	public static function signature_host( $value ) {
		if ( preg_match( '#https?://([a-z0-9.-]+)#i', (string) $value, $mm ) ) {
			return strtolower( $mm[1] );
		}
		return '';
	}

	/**
	 * Verify a registered crawler by address now (cheap), or say what the
	 * background worker must do.
	 *
	 * @return array{vstate:string,needs:string}
	 */
	public static function verify( array $r, $ip, $live = false ) {
		if ( ! empty( $r['by_address'] ) ) {
			return array( 'vstate' => 'verified', 'needs' => '' );
		}
		if ( ! empty( $r['sig'] ) ) {
			return Settings::get( 'verify_signatures' )
				? array( 'vstate' => 'pending', 'needs' => 'sig' )
				: array( 'vstate' => 'none', 'needs' => '' );
		}
		$bot = Registry::get( $r['bot'] );
		if ( ! $bot || '' === $ip ) {
			return array( 'vstate' => 'none', 'needs' => '' );
		}
		$by_range = Ranges::check( $bot['id'], $ip );
		if ( 'verified' === $by_range ) {
			return array( 'vstate' => 'verified', 'needs' => '' );
		}
		if ( $live && Util::proxy_suspected() ) {
			// We would be checking a proxy's address, not the crawler's: never call it an impersonation.
			Tracker::note_proxy();
			return array( 'vstate' => 'none', 'needs' => '' );
		}
		if ( $bot['verify']['rdns'] && Settings::get( 'verify_rdns' ) ) {
			$cached = Verifier::cached( $bot['id'], Util::ip_hash( $ip ) );
			if ( $cached ) {
				return array( 'vstate' => $cached, 'needs' => '' );
			}
			// Ranges say no, but the operator also allows DNS proof: let DNS decide.
			return array( 'vstate' => 'pending', 'needs' => 'rdns' );
		}
		if ( 'failed' === $by_range ) {
			return array( 'vstate' => 'failed', 'needs' => '' );
		}
		return array( 'vstate' => 'none', 'needs' => '' );
	}

	/** Confidence that the label is right, for the UI. */
	public static function confidence( $cls, $vstate, $bot_id ) {
		if ( 'verified' === $vstate ) {
			return 'high';
		}
		if ( 'spoofed' === $cls || 'failed' === $vstate ) {
			return 'high'; // high confidence that it is NOT who it claims
		}
		if ( in_array( $cls, array( 'potential', 'unknown' ), true ) ) {
			return 'low';
		}
		$b = Registry::get( $bot_id );
		if ( $b && 'high' === $b['confidence'] ) {
			return 'medium';
		}
		return 'low';
	}
}
