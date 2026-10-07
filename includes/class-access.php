<?php
/**
 * Crawler Access Manager: per-crawler allow / block, written to robots.txt.
 *
 * The owner's choices are kept in one option. Blocks are added to what
 * WordPress serves at /robots.txt through the core `robots_txt` filter,
 * inside a fenced block, after whatever WordPress and the SEO plugin wrote:
 *
 *   ## BEGIN RankyFy AI rules
 *   User-agent: Bytespider
 *   Disallow: /
 *   ## END RankyFy AI rules
 *
 * Nothing else in the file is touched, and a real robots.txt file in the web
 * root (which the web server serves before WordPress runs) is never written:
 * the screen then offers the lines to copy instead.
 *
 * "Allowed" means RankyFy adds no rule. A crawler blocked by a rule outside
 * RankyFy's block stays blocked whatever is chosen here; the screen says so
 * and names the rule rather than pretending the toggle can lift it.
 * robots.txt is advisory: crawlers that ignore it are not stopped by it.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Access {

	const OPTION = 'rfaib_access'; // bot id => 'allow' | 'block'
	const BEGIN  = '## BEGIN RankyFy AI rules';
	const END    = '## END RankyFy AI rules';

	/** Operators in display order; the rest follow alphabetically under "Others". */
	const OPERATORS = array( 'OpenAI', 'Anthropic', 'Google', 'Perplexity', 'Microsoft', 'Apple', 'Meta' );

	public static function init() {
		add_filter( 'robots_txt', array( __CLASS__, 'filter' ), 100, 2 );
	}

	public static function choices() {
		$c = get_option( self::OPTION, array() );
		return is_array( $c ) ? $c : array();
	}

	/** Crawlers that can be managed: AI crawlers that robots.txt can address. */
	public static function manageable() {
		$out = array();
		foreach ( Registry::bots() as $id => $b ) {
			if ( ! empty( $b['ai'] ) && ! empty( $b['robots_tokens'] ) ) {
				$out[ $id ] = $b;
			}
		}
		return $out;
	}

	/** The fenced block for the current choices ('' when nothing is blocked). */
	public static function block( array $choices = null ) {
		$choices = null === $choices ? self::choices() : $choices;
		$bots    = self::manageable();
		$lines   = array();
		foreach ( $choices as $id => $state ) {
			if ( 'block' !== $state || ! isset( $bots[ $id ] ) ) {
				continue;
			}
			foreach ( $bots[ $id ]['robots_tokens'] as $token ) {
				$lines[] = 'User-agent: ' . $token;
			}
			$lines[] = 'Disallow: /';
			$lines[] = '';
		}
		if ( ! $lines ) {
			return '';
		}
		return self::BEGIN . "\n" . implode( "\n", $lines ) . self::END . "\n";
	}

	/** robots_txt filter: append the block (after any other plugin's output). */
	public static function filter( $output, $public ) {
		if ( '0' === (string) $public ) {
			return $output; // the whole site is already closed
		}
		$block = self::block();
		if ( '' === $block ) {
			return $output;
		}
		return rtrim( (string) $output ) . "\n\n" . $block;
	}

	/** Who serves robots.txt: a real file wins over WordPress (and over this filter). */
	public static function physical_file() {
		return file_exists( rtrim( ABSPATH, '/' ) . '/robots.txt' );
	}

	/**
	 * Validate and store choices.
	 *
	 * @param array $in bot id => 'allow' | 'block' | '' (unreviewed)
	 */
	public static function save( array $in ) {
		$bots    = self::manageable();
		$choices = self::choices();
		foreach ( $in as $id => $state ) {
			$id = sanitize_key( (string) $id );
			if ( ! isset( $bots[ $id ] ) ) {
				continue;
			}
			if ( in_array( $state, array( 'allow', 'block' ), true ) ) {
				$choices[ $id ] = $state;
			} else {
				unset( $choices[ $id ] );
			}
		}
		update_option( self::OPTION, $choices, false );
		return $choices;
	}

	/** Product people know the operator by, for "you disappear from …" sentences. */
	public static function product( $provider ) {
		$map = array(
			'OpenAI'     => 'ChatGPT',
			'Anthropic'  => 'Claude',
			'Perplexity' => 'Perplexity',
			'Google'     => 'Google',
			'Microsoft'  => 'Copilot',
			'Meta'       => 'Meta AI',
			'DuckDuckGo' => 'DuckDuckGo',
			'You.com'    => 'You.com',
			'Mistral AI' => 'Le Chat',
		);
		return $map[ $provider ] ?? $provider;
	}

	/**
	 * What blocking a crawler costs, in plain words.
	 *
	 * @return array{text:string,serious:bool}
	 */
	public static function consequence( array $b ) {
		$p = self::product( (string) $b['provider'] );
		switch ( $b['category'] ) {
			case 'ai_training':
				if ( 'google-extended' === $b['id'] ) {
					return array( 'text' => __( 'Google stops using your pages for Gemini training. Google Search and AI Overviews are unaffected — those use Googlebot.', 'rankyfy-ai-crawlers' ), 'serious' => false );
				}
				$has_search = false;
				foreach ( Registry::bots() as $o ) {
					if ( $o['provider'] === $b['provider'] && in_array( $o['category'], array( 'ai_search', 'ai_user', 'search' ), true ) ) {
						$has_search = true;
					}
				}
				return array(
					'text'    => $has_search
						/* translators: %s: product, e.g. ChatGPT */
						? sprintf( __( 'Your content stops being used for training. No effect on %s answers.', 'rankyfy-ai-crawlers' ), $p )
						: __( 'Your content stops being used for training. This operator has no AI search product, so blocking costs you nothing visible.', 'rankyfy-ai-crawlers' ),
					'serious' => false,
				);
			case 'ai_search':
				/* translators: %s: product */
				return array( 'text' => sprintf( __( 'You disappear from %s\'s answers. Rarely what you want.', 'rankyfy-ai-crawlers' ), $p ), 'serious' => true );
			case 'ai_user':
			case 'ai_agent':
				/* translators: %s: product */
				return array( 'text' => sprintf( __( 'People who paste your link into %s get nothing back.', 'rankyfy-ai-crawlers' ), $p ), 'serious' => true );
		}
		return array( 'text' => __( 'Little visible effect.', 'rankyfy-ai-crawlers' ), 'serious' => false );
	}

	public static function purpose( $category ) {
		$map = array(
			'ai_training' => __( 'Model training', 'rankyfy-ai-crawlers' ),
			'ai_search'   => __( 'Search — powers answers', 'rankyfy-ai-crawlers' ),
			'ai_user'     => __( 'User-triggered fetch', 'rankyfy-ai-crawlers' ),
			'ai_agent'    => __( 'Agent acting for a user', 'rankyfy-ai-crawlers' ),
			'ai_other'    => __( 'Other AI use', 'rankyfy-ai-crawlers' ),
			'search'      => __( 'Search engine', 'rankyfy-ai-crawlers' ),
		);
		return $map[ $category ] ?? $category;
	}

	/**
	 * Everything the Access Manager screen shows.
	 */
	public static function view() {
		global $wpdb;
		$choices = self::choices();
		$hits    = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT bot, SUM(hits) h FROM ' . Installer::table( 'daily_bots' ) . ' WHERE day >= %s GROUP BY bot', wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS ) ), ARRAY_A ) as $r ) {
			$hits[ $r['bot'] ] = (int) $r['h'];
		}
		// What the file says without RankyFy's block: blocks there are not ours to lift.
		$current  = (string) Robots::current()['body'];
		$base     = Robots::parse( self::strip( $current ) );
		$groups   = array();
		foreach ( self::manageable() as $id => $b ) {
			$outside = Robots::check( $base, $b['robots_tokens'][0], '/' );
			$c       = self::consequence( $b );
			$op      = in_array( $b['provider'], self::OPERATORS, true ) ? $b['provider'] : __( 'Others', 'rankyfy-ai-crawlers' );
			$groups[ $op ][] = array(
				'id'          => $id,
				'name'        => $b['name'],
				'provider'    => $b['provider'],
				'category'    => $b['category'],
				'purpose'     => self::purpose( $b['category'] ),
				'tokens'      => $b['robots_tokens'],
				'hits'        => $hits[ $id ] ?? 0,
				'choice'      => $choices[ $id ] ?? '',
				'blocked_elsewhere' => ! $outside['allowed'],
				'elsewhere_rule'    => $outside['allowed'] ? '' : $outside['rule'] . ( $outside['agent'] ? ' (User-agent: ' . $outside['agent'] . ')' : '' ),
				'respects'    => $b['respects_robots'],
				'consequence' => $c['text'],
				'serious'     => $c['serious'],
			);
		}
		$order = array_merge( self::OPERATORS, array( __( 'Others', 'rankyfy-ai-crawlers' ) ) );
		$out   = array();
		foreach ( $order as $op ) {
			if ( empty( $groups[ $op ] ) ) {
				continue;
			}
			usort( $groups[ $op ], static function ( $a, $b ) {
				return ( $b['hits'] <=> $a['hits'] ) ?: strcasecmp( $a['name'], $b['name'] );
			} );
			$out[] = array( 'operator' => $op, 'bots' => $groups[ $op ] );
		}
		$robots = Robots::current();
		return array(
			'groups'   => $out,
			'count'    => count( self::manageable() ),
			'block'    => self::block(),
			'physical' => self::physical_file(),
			'public'   => '0' !== (string) get_option( 'blog_public' ),
			'robots'   => array(
				'url'        => home_url( '/robots.txt' ),
				'source'     => $robots['source'],
				'fetched_at' => (int) $robots['fetched_at'],
				'writer'     => self::robots_writer(),
			),
		);
	}

	/** The robots.txt text without RankyFy's fenced block. */
	public static function strip( $txt ) {
		return (string) preg_replace( '/\n*' . preg_quote( self::BEGIN, '/' ) . '.*?' . preg_quote( self::END, '/' ) . '\n?/s', "\n", (string) $txt );
	}

	/** Which plugin writes the rest of robots.txt, for "X serves robots.txt — RankyFy appends only these lines". */
	public static function robots_writer() {
		if ( self::physical_file() ) {
			return __( 'A robots.txt file on the server', 'rankyfy-ai-crawlers' );
		}
		foreach ( Compat::seo_plugins() as $p ) {
			return $p;
		}
		return 'WordPress';
	}
}
