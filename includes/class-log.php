<?php
/**
 * Diagnostic log: the last 100 events in a non-autoloaded option (shown on
 * the Settings screen) and error_log when WP_DEBUG_LOG is on. Context values
 * are cleaned of control characters (no forged log lines) and anything that
 * looks like a credential.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Log {

	const OPTION = 'rfy_log';
	const MAX    = 100;

	public static function info( $message, array $context = array() ) {
		self::write( 'info', $message, $context );
	}

	public static function warning( $message, array $context = array() ) {
		self::write( 'warning', $message, $context );
	}

	public static function error( $message, array $context = array() ) {
		self::write( 'error', $message, $context );
	}

	public static function entries() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	private static function scrub( array $context ) {
		$out = array();
		foreach ( $context as $k => $v ) {
			$k = Util::clean( $k, 40 );
			if ( preg_match( '/token|secret|password|key|authorization|cookie/i', $k ) ) {
				$out[ $k ] = '[redacted]';
			} elseif ( is_scalar( $v ) || null === $v ) {
				$out[ $k ] = is_string( $v ) ? Util::clean( $v, 300 ) : $v;
			} else {
				$out[ $k ] = '[' . gettype( $v ) . ']';
			}
		}
		return $out;
	}

	private static function write( $level, $message, array $context ) {
		$entry = array(
			't'   => time(),
			'l'   => $level,
			'm'   => Util::clean( $message, 300 ),
			'ctx' => self::scrub( $context ),
		);
		$log   = get_option( self::OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = $entry;
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}
		update_option( self::OPTION, $log, false );
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[rankyfy-ai-seo] ' . $level . ': ' . $entry['m'] . ' ' . wp_json_encode( $entry['ctx'] ) );
		}
	}
}
