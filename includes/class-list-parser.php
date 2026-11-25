<?php
/**
 * Finds trips (name + URL) in the HTML of a list page. Pure: no WordPress calls.
 * Ported from hittaResor/arResa/normUrl in the old Apps Script.
 *
 * The list pages are built with Content Views (and, for guided trips, a hand-made grid),
 * whose selection cannot be recreated with WP_Query – so the plugin reads the rendered lists.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class List_Parser {

	/** Path parts that are never single trips. */
	const NOT_TRIP = array(
		'/information/', '/hitta-resa/', '/vanliga-fragor/', '/svarighetsgrad/',
		'/bokningsprocess/', '/betalningsvillkor/', '/nyhetsbrev/', '/om-oss/',
		'/konferenser/', '/grupper/', '/sjalvguidade/', '/nyheter/', '/tag/',
		'/destinationer/', '/category/', '/wp-content/', '/wp-json/', '/feed/', '/kalender/',
	);

	/** @var string Site home URL without trailing slash, e.g. https://cyklavandra.se */
	private $base;

	public function __construct( $base_url ) {
		$this->base = rtrim( (string) $base_url, '/' );
	}

	/**
	 * Trips on one list page.
	 *
	 * @param string $html     The page's HTML.
	 * @param string $list_url The list page's own URL.
	 * @return array url => name, in page order.
	 */
	public function find_trips( $html, $list_url ) {
		$trips   = array();
		$heading = '';
		$list    = $this->norm( $list_url );
		$re      = '~<(h2|h3)\b[^>]*>(.*?)</\1>|<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is';

		if ( ! preg_match_all( $re, (string) $html, $matches, PREG_SET_ORDER ) ) {
			return $trips;
		}
		foreach ( $matches as $m ) {
			if ( ! empty( $m[1] ) ) {
				// A heading. Does it contain a link?
				$inner = $m[2];
				$link  = preg_match( '~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', $inner, $lm ) ? $lm : null;
				$text  = self::text( $link ? $link[2] : $inner );
				if ( $link ) {
					$url = $this->norm( $link[1] );
					if ( $this->is_trip_url( $url, $list ) && self::length( $text ) > 3 && ! isset( $trips[ $url ] ) ) {
						$trips[ $url ] = $text;
					}
				}
				$heading = $text;
			} else {
				// A plain link: only "Läs mer" links count, named after the nearest heading.
				if ( ! preg_match( '/läs\s*mer/iu', self::text( $m[4] ) ) ) {
					continue;
				}
				$url = $this->norm( $m[3] );
				if ( $this->is_trip_url( $url, $list ) && self::length( $heading ) > 3 && ! isset( $trips[ $url ] ) ) {
					$trips[ $url ] = $heading;
				}
			}
		}
		return $trips;
	}

	/** Number of pages in a Content Views list (data-totalpages), at most 50. */
	public static function total_pages( $html ) {
		if ( preg_match( '/data-totalpages=["\'](\d+)["\']/i', (string) $html, $m ) ) {
			return max( 1, min( 50, (int) $m[1] ) );
		}
		return 1;
	}

	/**
	 * Combine the trips of all lists. A trip on both the cycling and the walking list is "cykel".
	 *
	 * @param array $per_category category => (url => name).
	 * @return array url => array( 'category' => , 'name' => )
	 */
	public static function combine( array $per_category ) {
		$out = array();
		foreach ( $per_category as $cat => $trips ) {
			foreach ( $trips as $url => $name ) {
				if ( ! isset( $out[ $url ] ) ) {
					$out[ $url ] = array(
						'category' => $cat,
						'name'     => $name,
					);
				} elseif ( 'cykel' === $cat ) {
					$out[ $url ]['category'] = 'cykel';
				}
			}
		}
		return $out;
	}

	public function is_trip_url( $url, $list_url ) {
		if ( 0 !== strpos( $url, $this->base . '/' ) || $url === $list_url ) {
			return false;
		}
		$parts = array_values( array_filter( explode( '/', substr( $url, strlen( $this->base ) ) ), 'strlen' ) );
		if ( ! $parts ) {
			return false;
		}
		$min = 'ovrigt' === $parts[0] ? 2 : 3;
		if ( count( $parts ) < $min ) {
			return false;
		}
		$path = '/' . implode( '/', $parts ) . '/';
		foreach ( self::NOT_TRIP as $p ) {
			if ( false !== strpos( $path, $p ) ) {
				return false;
			}
		}
		return true;
	}

	/** Absolute URL, without query and fragment, with a trailing slash. */
	public function norm( $url ) {
		$u = trim( html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $u ) {
			return '';
		}
		if ( 0 === strpos( $u, '//' ) ) {
			$u = ( 0 === strpos( $this->base, 'https:' ) ? 'https:' : 'http:' ) . $u;
		} elseif ( 0 === strpos( $u, '/' ) ) {
			$u = $this->base . $u;
		}
		$u = preg_replace( '/[#?].*$/s', '', $u );
		return rtrim( $u, '/' ) . '/';
	}

	public static function text( $html ) {
		$t = html_entity_decode( preg_replace( '/<[^>]+>/', ' ', (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $t ) );
	}

	private static function length( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}
}
