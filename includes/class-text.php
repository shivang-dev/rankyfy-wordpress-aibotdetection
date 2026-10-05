<?php
/**
 * Deterministic text helpers for page analysis: tokenising, key terms,
 * entity mentions, questions, search intent. Everything here describes what
 * the page itself contains — it is observed, not generated — and it costs a
 * few milliseconds per page.
 *
 * Term weighting uses document frequencies across the site (TF-IDF), so
 * words every page uses (including stop words of any language) fall to the
 * bottom without a per-language list; a short English list removes the
 * noise before the site has enough pages for IDF to work.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Text {

	const STOP = 'a,about,above,after,again,against,all,also,am,an,and,any,are,as,at,be,because,been,before,being,below,between,both,but,by,can,could,did,do,does,doing,down,during,each,even,every,few,for,from,further,get,got,had,has,have,having,he,her,here,hers,herself,him,himself,his,how,however,i,if,in,into,is,it,its,itself,just,let,like,make,many,may,me,might,more,most,much,must,my,myself,need,new,no,nor,not,now,of,off,on,once,one,only,or,other,our,ours,ourselves,out,over,own,per,really,same,see,she,should,so,some,still,such,than,that,the,their,theirs,them,themselves,then,there,these,they,thing,things,this,those,though,through,to,too,two,under,until,up,upon,us,use,used,using,very,via,want,was,way,we,well,were,what,when,where,whether,which,while,who,whom,whose,why,will,with,within,without,would,yes,yet,you,your,yours,yourself,yourselves,read,more,click,here,share,post,posts,page,menu,skip,content,home,search,copyright,reserved,rights,cookie,cookies,privacy,policy';

	private static $stop = null;

	public static function stop() {
		if ( null === self::$stop ) {
			self::$stop = array_flip( explode( ',', self::STOP ) );
		}
		return self::$stop;
	}

	/** Lower-cased word tokens (letters and digits of any script). */
	public static function words( $text ) {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $text, $m );
		return $m[0];
	}

	public static function word_count( $text ) {
		return (int) preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $text );
	}

	private static function usable( $w ) {
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $w, 'UTF-8' ) : strlen( $w );
		return $len >= 3 && $len <= 40 && ! isset( self::stop()[ $w ] ) && ! preg_match( '/^\d+$/', $w );
	}

	/**
	 * Term counts (unigrams and bigrams). Title and heading text counts three
	 * times — that is where a page says what it is about.
	 *
	 * @return array<string,int> top $limit terms by count
	 */
	public static function term_counts( $body, $emphasis = '', $limit = 40 ) {
		$counts = array();
		foreach ( array( array( $body, 1 ), array( $emphasis, 3 ) ) as $pair ) {
			list( $text, $weight ) = $pair;
			$prev = null;
			foreach ( self::words( $text ) as $w ) {
				$w = trim( $w, "-'’" );
				if ( ! self::usable( $w ) ) {
					$prev = null;
					continue;
				}
				$counts[ $w ] = ( $counts[ $w ] ?? 0 ) + $weight;
				if ( $prev ) {
					$bi            = $prev . ' ' . $w;
					$counts[ $bi ] = ( $counts[ $bi ] ?? 0 ) + $weight;
				}
				$prev = $w;
			}
		}
		// A bigram seen once is noise.
		foreach ( $counts as $t => $c ) {
			if ( false !== strpos( $t, ' ' ) && $c < 2 ) {
				unset( $counts[ $t ] );
			}
		}
		arsort( $counts );
		return array_slice( $counts, 0, $limit, true );
	}

	/** Rank a page's term counts by TF-IDF against the site's document frequencies. */
	public static function rank( array $counts, array $df, $n_docs ) {
		$total  = max( 1, array_sum( $counts ) );
		$scored = array();
		foreach ( $counts as $t => $c ) {
			$idf          = log( ( $n_docs + 1 ) / ( ( $df[ $t ] ?? 0 ) + 1 ) ) + 1;
			$bonus        = false !== strpos( $t, ' ' ) ? 1.3 : 1.0; // phrases are more specific
			$scored[ $t ] = ( $c / $total ) * $idf * $bonus;
		}
		arsort( $scored );
		return $scored;
	}

	/** Sentences (rough), for answer-first and readability checks. */
	public static function sentences( $text ) {
		$parts = preg_split( '/(?<=[.!?])\s+(?=[\p{Lu}\p{N}"“])/u', trim( $text ) );
		return array_values( array_filter( array_map( 'trim', (array) $parts ) ) );
	}

	public static function is_question( $s ) {
		$s = trim( $s );
		return (bool) preg_match( '/\?\s*$/u', $s )
			|| (bool) preg_match( '/^(how|what|why|when|where|which|who|whom|whose|can|could|does|do|did|is|are|should|will|would)\b/i', $s );
	}

	/**
	 * Names the page mentions (capitalised phrases not at a sentence start,
	 * repeated). A heuristic — shown as "names found in your content".
	 */
	public static function entities( $text, $limit = 15 ) {
		$found = array();
		foreach ( self::sentences( $text ) as $s ) {
			if ( preg_match_all( '/(?<=\s)(\p{Lu}[\p{L}\p{N}&\.\'’-]+(?:\s+(?:of|the|de|la|and|&)?\s*\p{Lu}[\p{L}\p{N}&\.\'’-]+){0,3})/u', ' ' . $s, $m ) ) {
				foreach ( $m[1] as $i => $phrase ) {
					$phrase = trim( $phrase, " .'’-" );
					// Skip the sentence's first word unless the phrase is multi-word.
					if ( 0 === strpos( ltrim( $s ), $phrase ) && false === strpos( $phrase, ' ' ) ) {
						continue;
					}
					if ( isset( self::stop()[ strtolower( $phrase ) ] ) || mb_strlen( $phrase ) < 3 ) {
						continue;
					}
					$found[ $phrase ] = ( $found[ $phrase ] ?? 0 ) + 1;
				}
			}
		}
		$found = array_filter( $found, static function ( $c, $p ) {
			return $c >= 2 || false !== strpos( $p, ' ' );
		}, ARRAY_FILTER_USE_BOTH );
		arsort( $found );
		return array_slice( array_keys( $found ), 0, $limit );
	}

	/**
	 * Likely search intent from the page type and its wording. Rules, not a
	 * model, so the reason can be shown next to the label.
	 *
	 * @return array{intent:string,reason:string}
	 */
	public static function intent( $title, $headings, $body, $subtype, array $schema_types ) {
		$t = strtolower( $title . ' ' . implode( ' ', $headings ) );
		$b = strtolower( substr( $body, 0, 4000 ) );
		if ( 'product' === $subtype || in_array( 'Product', $schema_types, true ) || preg_match( '/\b(buy|price|pricing|order now|add to cart|shop|discount|coupon|free trial|sign up|book now|get a quote)\b/', $t ) ) {
			return array( 'intent' => 'transactional', 'reason' => 'product_or_purchase_wording' );
		}
		if ( in_array( 'LocalBusiness', $schema_types, true ) || preg_match( '/\b(near me|opening hours|directions|our location|visit us|in [A-Z][a-z]+)\b/', $title . ' ' . $b ) && preg_match( '/\b(address|phone|call us|opening hours)\b/', $b ) ) {
			return array( 'intent' => 'local', 'reason' => 'location_and_contact_details' );
		}
		if ( preg_match( '/\b(best|top \d+|vs\.?|versus|review|reviews|compared?|comparison|alternatives?|pros and cons)\b/', $t ) ) {
			return array( 'intent' => 'commercial', 'reason' => 'comparison_or_review_wording' );
		}
		if ( preg_match( '/\b(how|what|why|when|guide|tutorial|tips|ways|steps|explained|meaning|definition|examples?)\b/', $t ) ) {
			return array( 'intent' => 'informational', 'reason' => 'question_or_guide_wording' );
		}
		if ( preg_match( '/\b(contact|about us|login|account|support)\b/', $t ) ) {
			return array( 'intent' => 'navigational', 'reason' => 'site_navigation_page' );
		}
		return array( 'intent' => 'informational', 'reason' => 'default' );
	}
}
