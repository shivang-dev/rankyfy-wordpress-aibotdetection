<?php
/**
 * Fixed-window limits per WordPress user and action, so a script or a stuck
 * button cannot hammer RankyFy, spend credits or fill the database.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class Rate_Limiter {

	/** action => [max, window seconds] */
	const LIMITS = array(
		'read'       => array( 1200, HOUR_IN_SECONDS ),
		'write'      => array( 300, HOUR_IN_SECONDS ),
		'analyze'    => array( 60, HOUR_IN_SECONDS ),   // local re-analysis of a page
		'ai_analyze' => array( 20, HOUR_IN_SECONDS ),   // RankyFy Content AI (credits)
		'sync'       => array( 12, HOUR_IN_SECONDS ),
		'import'     => array( 2000, HOUR_IN_SECONDS ), // batches of parsed log lines
		'export'     => array( 30, HOUR_IN_SECONDS ),
		'test'       => array( 10, HOUR_IN_SECONDS ),   // test notification
		'guard'      => array( 600, HOUR_IN_SECONDS ),  // publish-time checks from the editor
	);

	/** @return true|WP_Error */
	public static function hit( $action ) {
		list( $max, $window ) = self::LIMITS[ $action ] ?? array( 60, HOUR_IN_SECONDS );
		$max                  = (int) apply_filters( 'rfy_rate_limit', $max, $action );
		$who                  = get_current_user_id();
		$key                  = 'rfy_rl_' . md5( $action . '|' . $who . '|' . (int) floor( time() / $window ) );
		$count                = (int) get_transient( $key );
		if ( $count >= $max ) {
			return new WP_Error(
				'rfy_rate_limited',
				__( 'Too many requests. Please wait a few minutes and try again.', 'rankyfy-ai-seo' ),
				array(
					'status'      => 429,
					'retry_after' => $window - ( time() % $window ),
				)
			);
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}
}
