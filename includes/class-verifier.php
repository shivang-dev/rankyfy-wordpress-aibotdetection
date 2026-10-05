<?php
/**
 * Verifications too slow for a page request, done by the worker:
 *
 *  • Forward-confirmed reverse DNS (Google, Bing, Apple, Amazon…): the
 *    address must resolve to a host under the operator's domain, and that
 *    host must resolve back to the same address. One verdict per (address,
 *    bot) is cached for a week, so each crawler address is looked up once.
 *
 *  • Web Bot Auth request signatures (HTTP Message Signatures, RFC 9421,
 *    Ed25519): the agent names its key directory in Signature-Agent; the
 *    signature over the covered request components must verify with a key
 *    published there. Checked per request.
 *
 * The full address is kept only in the queue row and deleted as soon as the
 * check is done; events keep the network and a keyed hash.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Verifier {

	const VERDICT_TTL = WEEK_IN_SECONDS;

	private static $cache = array();

	public static function cached( $bot, $ip_hash ) {
		global $wpdb;
		$k = $bot . '|' . $ip_hash;
		if ( ! array_key_exists( $k, self::$cache ) ) {
			self::$cache[ $k ] = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT verdict FROM ' . Installer::table( 'ip_verdicts' ) . ' WHERE ip_hash = %s AND bot = %s AND checked_at > %d',
					$ip_hash,
					$bot,
					time() - self::VERDICT_TTL
				)
			);
		}
		return self::$cache[ $k ] ? self::$cache[ $k ] : '';
	}

	public static function flush_cache() {
		self::$cache = array();
	}

	public static function enqueue( $kind, $event_id, $bot, $ip, $ip_hash, array $payload = array() ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . Installer::table( 'verify_queue' ) . ' (event_id, kind, bot, ip, ip_hash, payload, created_at) VALUES (%d, %s, %s, %s, %s, %s, %d)',
				'sig' === $kind ? $event_id : 0,
				$kind,
				$bot,
				$ip,
				$ip_hash,
				$payload ? wp_json_encode( $payload ) : '',
				time()
			)
		);
	}

	/** Process queued checks for up to $budget seconds. @return int checks done */
	public static function run( $budget = 15 ) {
		global $wpdb;
		$q        = Installer::table( 'verify_queue' );
		$deadline = microtime( true ) + $budget;
		$done     = 0;

		// Give up on checks that could not be completed in two days.
		$stale = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$q} WHERE created_at < %d LIMIT 500", time() - 2 * DAY_IN_SECONDS ), ARRAY_A );
		foreach ( (array) $stale as $row ) {
			self::settle( $row, 'none', '', 'expired' );
		}

		while ( microtime( true ) < $deadline ) {
			$rows = $wpdb->get_results( "SELECT * FROM {$q} ORDER BY attempts ASC, id ASC LIMIT 10", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				if ( microtime( true ) >= $deadline ) {
					break 2;
				}
				if ( 'rdns' === $row['kind'] ) {
					list( $verdict, $host ) = self::check_rdns( $row['bot'], $row['ip'] );
				} else {
					list( $verdict, $host ) = self::check_signature( $row );
				}
				if ( 'retry' === $verdict ) {
					$wpdb->update( $q, array( 'attempts' => (int) $row['attempts'] + 1 ), array( 'id' => $row['id'] ) );
					if ( (int) $row['attempts'] >= 3 ) {
						self::settle( $row, 'none', '', 'unresolved' );
					}
					continue;
				}
				self::settle( $row, $verdict, $host, $row['kind'] );
				$done++;
			}
		}
		return $done;
	}

	/** Record a verdict, update the waiting events, drop the queue row (and the address). */
	private static function settle( array $row, $verdict, $host, $method ) {
		global $wpdb;
		$events = Installer::table( 'events' );
		if ( 'rdns' === $row['kind'] ) {
			if ( in_array( $verdict, array( 'verified', 'failed' ), true ) ) {
				self::$cache[ $row['bot'] . '|' . $row['ip_hash'] ] = $verdict;
				$wpdb->replace(
					Installer::table( 'ip_verdicts' ),
					array(
						'ip_hash'    => $row['ip_hash'],
						'bot'        => $row['bot'],
						'verdict'    => $verdict,
						'method'     => $method,
						'host'       => substr( (string) $host, 0, 190 ),
						'checked_at' => time(),
					)
				);
			}
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$events} SET vstate = %s, cls = IF(%s = 'failed', 'spoofed', cls) WHERE bot = %s AND ip_hash = %s AND vstate = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$verdict,
					$verdict,
					$row['bot'],
					$row['ip_hash']
				)
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$events} SET vstate = %s, cls = IF(%s = 'failed', 'spoofed', cls) WHERE id = %d AND vstate = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$verdict,
					$verdict,
					$row['event_id']
				)
			);
		}
		$wpdb->delete( Installer::table( 'verify_queue' ), array( 'id' => $row['id'] ) );
	}

	// ── reverse DNS ────────────────────────────────────────────────────────

	/** @return array{0:string,1:string} [verified|failed|retry, host] */
	public static function check_rdns( $bot_id, $ip ) {
		$bot = Registry::get( $bot_id );
		if ( ! $bot || ! $bot['verify']['rdns'] || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return array( 'failed', '' );
		}
		$host = self::reverse( $ip );
		if ( null === $host ) {
			return array( 'retry', '' );
		}
		if ( '' === $host ) {
			return array( 'failed', '' ); // no PTR record: real crawlers always have one
		}
		$host = strtolower( rtrim( $host, '.' ) );
		$ok   = false;
		foreach ( $bot['verify']['rdns'] as $suffix ) {
			$suffix = strtolower( $suffix );
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				$ok = true;
				break;
			}
		}
		if ( ! $ok ) {
			return array( 'failed', $host );
		}
		return array( in_array( $ip, self::forward( $host, $ip ), true ) ? 'verified' : 'failed', $host );
	}

	/** PTR name; '' when none exists; null on a lookup failure worth retrying. */
	protected static function reverse( $ip ) {
		$filtered = apply_filters( 'rfaib_reverse_dns', null, $ip ); // tests and unusual hosts
		if ( null !== $filtered ) {
			return (string) $filtered;
		}
		$host = @gethostbyaddr( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $host ) {
			return null;
		}
		return $host === $ip ? '' : $host;
	}

	protected static function forward( $host, $ip ) {
		$filtered = apply_filters( 'rfaib_forward_dns', null, $host );
		if ( is_array( $filtered ) ) {
			return $filtered;
		}
		if ( false !== strpos( $ip, ':' ) ) {
			$recs = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$out  = array();
			foreach ( (array) $recs as $r ) {
				if ( ! empty( $r['ipv6'] ) ) {
					$out[] = inet_ntop( inet_pton( $r['ipv6'] ) );
				}
			}
			$norm = inet_ntop( inet_pton( $ip ) );
			return in_array( $norm, $out, true ) ? array( $ip ) : $out;
		}
		$list = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return is_array( $list ) ? $list : array();
	}

	// ── Web Bot Auth ───────────────────────────────────────────────────────

	/** Split a structured-field dictionary into label => raw member text. */
	public static function sf_dictionary( $value ) {
		$out   = array();
		$depth = 0;
		$quote = false;
		$cur   = '';
		$len   = strlen( $value );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $value[ $i ];
			if ( '"' === $ch && ( 0 === $i || '\\' !== $value[ $i - 1 ] ) ) {
				$quote = ! $quote;
			} elseif ( ! $quote && '(' === $ch ) {
				$depth++;
			} elseif ( ! $quote && ')' === $ch ) {
				$depth--;
			}
			if ( ',' === $ch && ! $quote && 0 === $depth ) {
				$out[] = $cur;
				$cur   = '';
				continue;
			}
			$cur .= $ch;
		}
		$out[] = $cur;
		$dict  = array();
		foreach ( $out as $member ) {
			$member = trim( $member );
			$eq     = strpos( $member, '=' );
			if ( false === $eq ) {
				continue;
			}
			$dict[ trim( substr( $member, 0, $eq ) ) ] = trim( substr( $member, $eq + 1 ) );
		}
		return $dict;
	}

	/** Parse one Signature-Input member: components, params, raw params text. */
	public static function parse_signature_params( $raw ) {
		if ( ! preg_match( '/^\(([^)]*)\)(.*)$/s', $raw, $m ) ) {
			return null;
		}
		preg_match_all( '/"([^"]+)"/', $m[1], $c );
		$params = array();
		foreach ( explode( ';', $m[2] ) as $p ) {
			$p = trim( $p );
			if ( '' === $p ) {
				continue;
			}
			$kv                       = explode( '=', $p, 2 );
			$params[ strtolower( $kv[0] ) ] = isset( $kv[1] ) ? trim( $kv[1], '"' ) : true;
		}
		return array(
			'components' => $c[1],
			'params'     => $params,
			'raw'        => $raw,
		);
	}

	/** RFC 9421 signature base. Null when a covered component is missing. */
	public static function signature_base( array $parsed, array $request ) {
		$lines = array();
		foreach ( $parsed['components'] as $name ) {
			$key = strtolower( $name );
			if ( ! isset( $request[ $key ] ) ) {
				return null;
			}
			$lines[] = '"' . $key . '": ' . trim( (string) $request[ $key ] );
		}
		$lines[] = '"@signature-params": ' . $parsed['raw'];
		return implode( "\n", $lines );
	}

	public static function jwk_thumbprint( $x ) {
		$json = '{"crv":"Ed25519","kty":"OKP","x":"' . $x . '"}';
		return rtrim( strtr( base64_encode( hash( 'sha256', $json, true ) ), '+/', '-_' ), '=' );
	}

	private static function b64url_decode( $s ) {
		$s = strtr( (string) $s, '-_', '+/' );
		return base64_decode( $s . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
	}

	/** Ed25519 public keys (raw 32 bytes) published by a signing agent, keyed by thumbprint and kid. */
	protected static function directory_keys( $host ) {
		$filtered = apply_filters( 'rfaib_signature_keys', null, $host );
		if ( is_array( $filtered ) ) {
			return $filtered;
		}
		$cache_key = 'rfaib_sigdir_' . md5( $host );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res  = wp_safe_remote_get(
			'https://' . $host . '/.well-known/http-message-signatures-directory',
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'limit_response_size' => 256 * KB_IN_BYTES,
				'headers'             => array( 'Accept' => 'application/http-message-signatures-directory+json, application/json' ),
			)
		);
		$keys = array();
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$doc = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			foreach ( (array) ( $doc['keys'] ?? array() ) as $k ) {
				if ( 'OKP' !== ( $k['kty'] ?? '' ) || 'Ed25519' !== ( $k['crv'] ?? '' ) || empty( $k['x'] ) ) {
					continue;
				}
				$raw = self::b64url_decode( $k['x'] );
				if ( false === $raw || 32 !== strlen( $raw ) ) {
					continue;
				}
				$keys[ self::jwk_thumbprint( $k['x'] ) ] = base64_encode( $raw );
				if ( ! empty( $k['kid'] ) && is_string( $k['kid'] ) ) {
					$keys[ substr( $k['kid'], 0, 100 ) ] = base64_encode( $raw );
				}
			}
			set_transient( $cache_key, $keys, DAY_IN_SECONDS );
		} else {
			set_transient( $cache_key, $keys, HOUR_IN_SECONDS );
		}
		return $keys;
	}

	/** @return array{0:string,1:string} [verified|failed|none, signer host] */
	public static function check_signature( array $row ) {
		$req = json_decode( (string) $row['payload'], true );
		if ( ! is_array( $req ) || empty( $req['signature-input'] ) || empty( $req['signature'] ) ) {
			return array( 'none', '' );
		}
		$host = Detector::signature_host( $req['signature-agent'] ?? '' );
		if ( '' === $host || 0 !== stripos( trim( (string) $req['signature-agent'], '"' ), 'https://' ) ) {
			return array( 'none', '' ); // a key directory must be served over https
		}
		$inputs = self::sf_dictionary( $req['signature-input'] );
		$sigs   = self::sf_dictionary( $req['signature'] );
		$label  = '';
		foreach ( $inputs as $l => $raw ) {
			if ( false !== strpos( $raw, 'tag="web-bot-auth"' ) ) {
				$label = $l;
				break;
			}
		}
		if ( '' === $label ) {
			$label = (string) array_key_first( $inputs );
		}
		if ( '' === $label || ! isset( $sigs[ $label ] ) ) {
			return array( 'none', $host );
		}
		$parsed = self::parse_signature_params( $inputs[ $label ] );
		if ( ! $parsed || ( isset( $parsed['params']['alg'] ) && 'ed25519' !== strtolower( (string) $parsed['params']['alg'] ) ) ) {
			return array( 'none', $host );
		}
		$created = (int) ( $parsed['params']['created'] ?? 0 );
		$expires = (int) ( $parsed['params']['expires'] ?? 0 );
		$at      = (int) $row['created_at'];
		if ( ( $created && $created > $at + 60 ) || ( $expires && $expires < $at - 60 ) ) {
			return array( 'failed', $host ); // replayed or forged timing
		}
		$base = self::signature_base( $parsed, $req );
		$sig  = base64_decode( trim( $sigs[ $label ], ':' ), true );
		if ( null === $base || false === $sig || 64 !== strlen( $sig ) ) {
			return array( 'none', $host );
		}
		$keys  = self::directory_keys( $host );
		$keyid = (string) ( $parsed['params']['keyid'] ?? '' );
		if ( ! $keys ) {
			return array( 'none', $host );
		}
		$candidates = ( '' !== $keyid && isset( $keys[ $keyid ] ) ) ? array( $keys[ $keyid ] ) : array_values( array_unique( $keys ) );
		foreach ( $candidates as $pk ) {
			try {
				if ( sodium_crypto_sign_verify_detached( $sig, $base, base64_decode( $pk ) ) ) {
					return array( 'verified', $host );
				}
			} catch ( \Throwable $e ) {
				return array( 'none', $host );
			}
		}
		return array( 'failed', $host );
	}

	/** Housekeeping: verdicts are re-checked after a week. */
	public static function prune() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Installer::table( 'ip_verdicts' ) . ' WHERE checked_at < %d', time() - 2 * self::VERDICT_TTL ) );
	}
}
