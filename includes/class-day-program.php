<?php
/**
 * Place names from a trip's day programme ("Dag 4 – Sorteny", "Dag 2: Passau till Linz").
 * Pure: no WordPress calls. Ported from lasDagsprogram, platsKandidater and the ordering in
 * platsFranDagsprogram in the old Apps Script (referens/Kod.gs).
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class Day_Program {

	/** Words in day headings and trip names that are not places. */
	const NOT_PLACE = 'cykla cykling vandra vandring vinvandring vincykling familjevandring familjecykling '
		. 'familjeresa långhelg upptäck njutbar njutbara vacker vackra unik härlig härliga drömresa naturresa fågelresa '
		. 'fågelskådning slottsvandring kustvandring gravel norra södra östra västra mellersta övre nedre genuina '
		. 'storslagna vilda vild fantastiska fantastiskt lyxig båtkryssning tre fyra två den det de en ett med och '
		. 'guidad guidade självguidad elcykel ledig vilodag vila hemresa hemfärd ankomst avresa välkommen transfer '
		. 'dag utflykt båttur frukost middag lunch resa slutet start avslutning heldag halvdag rundtur tågresa '
		. 'transferdag egen valfri fri sista första kvällen morgon';

	const ACTIVITY     = '/(cykling|cykla|vandring|vandra|resa|resor|turen|tur|skådning|kryssning|helg|semester|dag)$/iu';
	const LINK_WORDS   = array( 'de', 'del', 'della', 'di', 'da', 'do', 'dos', 'la', 'le', 'les', 'am', 'an', 'im', 'sur', 'y', "d'" );
	const PREPOSITIONS = array( 'i', 'på', 'längs', 'vid', 'runt', 'kring', 'genom', 'från', 'till', 'över', 'mot', 'mellan' );
	const CAPITAL      = '/^[A-ZÅÄÖÆØÉÈÁÀÜÑČŠŽ]/u';

	/**
	 * The days of the programme, one per day number, in order.
	 *
	 * @param string $html Rendered page content.
	 * @return array[] list of array( 'day' => int, 'title' => string )
	 */
	public static function read( $html ) {
		$text = preg_replace( '~</(h\d|p|div|li|tr|td|strong|b)>|<br\s*/?>~i', "\n", (string) $html );
		$text = preg_replace( '/<[^>]+>/', ' ', $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$days = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$line = trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $line ) );
			if ( preg_match( '/^Dag\s+(\d{1,2})\s*[–—:\-.]?\s*(.{2,90})$/iu', $line, $m ) ) {
				$n = (int) $m[1];
				if ( ! isset( $days[ $n ] ) ) {
					$days[ $n ] = trim( $m[2] );
				}
			}
		}
		ksort( $days );
		$out = array();
		foreach ( $days as $n => $title ) {
			$out[] = array(
				'day'   => $n,
				'title' => $title,
			);
		}
		return $out;
	}

	/**
	 * The order in which to try the days: closest to the middle first; the first and last day
	 * (travel days) last. An 8-day trip gives 4, 5, 3, 6, 2, 7, 1, 8.
	 *
	 * @param array[] $days From read().
	 * @return array[]
	 */
	public static function order( array $days ) {
		if ( ! $days ) {
			return $days;
		}
		$first = $days[0]['day'];
		$last  = $days[ count( $days ) - 1 ]['day'];
		$mid   = ( $first + $last ) / 2;
		$edge  = function ( $d ) use ( $first, $last ) {
			return ( $d['day'] === $first || $d['day'] === $last ) ? 1 : 0;
		};
		usort(
			$days,
			function ( $a, $b ) use ( $edge, $mid ) {
				$c = $edge( $a ) - $edge( $b );
				if ( 0 !== $c ) {
					return $c;
				}
				$c = abs( $a['day'] - $mid ) <=> abs( $b['day'] - $mid );
				return 0 !== $c ? $c : $a['day'] - $b['day'];
			}
		);
		return $days;
	}

	/**
	 * Place name candidates in a text: runs of capitalised words ("Vall d'Incles", "St. Moritz").
	 * The goal of a stage ("A till B") comes before the start.
	 *
	 * @param string $text
	 * @return array[] list of array( 'text', 'goal', 'prep', 'genitive' )
	 */
	public static function candidates( $text ) {
		static $not_place = null;
		if ( null === $not_place ) {
			$not_place = array_flip( explode( ' ', self::NOT_PLACE ) );
		}
		$text  = str_replace( array( '’', '`' ), "'", (string) $text );
		$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$clean = function ( $w ) {
			return preg_replace( '/[,;:!?()]/u', '', $w );
		};
		$cap   = function ( $w ) {
			return (bool) preg_match( self::CAPITAL, $w );
		};
		$out   = array();
		$count = count( $words );
		for ( $i = 0; $i < $count; $i++ ) {
			$w = $clean( $words[ $i ] );
			// Capitalised prepositions ("Från Canillo …" at the start of a heading) are not part of the
			// name – an improvement on the old script, which looked up "Från Canillo".
			if ( ! $cap( $w ) || isset( $not_place[ self::lower( $w ) ] ) || preg_match( self::ACTIVITY, $w ) || in_array( self::lower( $w ), self::PREPOSITIONS, true ) ) {
				continue;
			}
			$parts = array( $w );
			$j     = $i + 1;
			// A comma or semicolon ends a name: "Caputh, Petzow och Werder" is three places.
			$ends  = function ( $raw ) {
				return (bool) preg_match( '/[,;:]$/u', $raw );
			};
			while ( $j < $count && ! $ends( $words[ $j - 1 ] ) ) {
				$n = $clean( $words[ $j ] );
				if ( $cap( $n ) && ! isset( $not_place[ self::lower( $n ) ] ) ) {
					$parts[] = $n;
					$j++;
					continue;
				}
				if ( ( in_array( self::lower( $n ), self::LINK_WORDS, true ) || preg_match( "/^d'/i", $n ) ) && $j + 1 < $count && $cap( $words[ $j + 1 ] ) ) {
					$parts[] = $n;
					$j++;
					continue;
				}
				if ( preg_match( "/^d'[A-ZÅÄÖ]/u", $n ) ) {
					$parts[] = $n;
					$j++;
					continue;
				}
				break;
			}
			$before = array();
			foreach ( array( $i - 1, $i - 2 ) as $k ) {
				if ( $k >= 0 ) {
					$before[] = self::lower( $words[ $k ] );
				}
			}
			$after    = isset( $words[ $j ] ) ? $words[ $j ] : '';
			$last     = $parts[ count( $parts ) - 1 ];
			$out[]    = array(
				'text'     => preg_replace( '/[.,]$/', '', implode( ' ', $parts ) ),
				'goal'     => in_array( 'till', $before, true ),
				'prep'     => (bool) array_intersect( $before, self::PREPOSITIONS ),
				'genitive' => (bool) preg_match( '/[^s]s$/u', $last ) && (bool) preg_match( '/^[a-zåäö]/u', $after ),
			);
			$i = $j - 1;
		}
		// Stable sort: goal first, then after a preposition, otherwise text order.
		$indexed = array();
		foreach ( $out as $k => $c ) {
			$indexed[] = array( $k, $c );
		}
		usort(
			$indexed,
			function ( $a, $b ) {
				return ( (int) $b[1]['goal'] - (int) $a[1]['goal'] )
					?: ( (int) $b[1]['prep'] - (int) $a[1]['prep'] )
					?: ( $a[0] - $b[0] );
			}
		);
		return array_map(
			function ( $x ) {
				return $x[1];
			},
			$indexed
		);
	}

	/**
	 * Names to try, in order: names that occur on several days first (the trip's base, e.g.
	 * "Potsdam" on day 1, 2 and 6), then the days from the middle out as in order().
	 *
	 * @param array[] $days From read().
	 * @return array[] list of array( 'name' => string, 'day' => int ), each name once.
	 */
	public static function names_to_try( array $days ) {
		$seen_days = array(); // name => set of days.
		$ordered   = array();
		foreach ( self::order( $days ) as $d ) {
			foreach ( self::candidates( $d['title'] ) as $c ) {
				foreach ( self::variants( $c ) as $name ) {
					$seen_days[ $name ][ $d['day'] ] = true;
					$ordered[]                       = array( 'name' => $name, 'day' => $d['day'] );
				}
			}
		}
		$out  = array();
		$done = array();
		$freq = array_filter(
			array_map( 'count', $seen_days ),
			function ( $n ) {
				return $n >= 2;
			}
		);
		arsort( $freq ); // Stable for equal counts since PHP 8; order among ties is not important.
		foreach ( array_keys( $freq ) as $name ) {
			$day = min( array_keys( $seen_days[ $name ] ) );
			foreach ( $ordered as $o ) {
				if ( $o['name'] === $name ) {
					$day = $o['day'];
					break;
				}
			}
			$out[]          = array( 'name' => (string) $name, 'day' => $day );
			$done[ $name ] = true;
		}
		foreach ( $ordered as $o ) {
			if ( ! isset( $done[ $o['name'] ] ) ) {
				$out[]               = $o;
				$done[ $o['name'] ] = true;
			}
		}
		return $out;
	}

	/** The names to look up for a candidate: "Alpernas" is tried as "Alperna" first. */
	public static function variants( array $candidate ) {
		$list = $candidate['genitive'] ? array( preg_replace( '/s$/u', '', $candidate['text'] ), $candidate['text'] ) : array( $candidate['text'] );
		return array_values(
			array_filter(
				$list,
				function ( $v ) {
					return ( function_exists( 'mb_strlen' ) ? mb_strlen( $v, 'UTF-8' ) : strlen( $v ) ) >= 3;
				}
			)
		);
	}

	private static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
	}
}
