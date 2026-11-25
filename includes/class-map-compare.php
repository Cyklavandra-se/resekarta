<?php
/**
 * Compares the plugin's map data with the old files from the spreadsheet
 * (uploads/karta/resor-{lang}.js), for the switch-over. Pure apart from reading files.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class Map_Compare {

	/** Fields compared for trips found in both, with a label for the report. */
	const FIELDS = array(
		'title'      => 'namn',
		'cat'        => 'kategori',
		'region'     => 'destination',
		'desc'       => 'beskrivning',
		'seasons'    => 'årstider',
		'difficulty' => 'nivå',
		'tags'       => 'taggar',
		'point'      => 'punkt',
	);

	/** Old files written by the spreadsheet via the Code Snippets snippet. */
	public static function old_file( $code ) {
		$up = wp_upload_dir( null, false );
		return trailingslashit( $up['basedir'] ) . 'karta/resor-' . $code . '.js';
	}

	/**
	 * Compare two lists of records.
	 *
	 * @param array[] $new The plugin's records.
	 * @param array[] $old The spreadsheet's records.
	 * @return array only_new, only_old (key => title), changed (field => list of "title: old → new"), same
	 */
	public static function compare( array $new, array $old ) {
		$by_new = array();
		foreach ( $new as $r ) {
			$by_new[ self::key( $r ) ] = $r;
		}
		$by_old = array();
		foreach ( $old as $r ) {
			$by_old[ self::key( $r ) ] = $r;
		}
		$out = array(
			'new_count' => count( $new ),
			'old_count' => count( $old ),
			'only_new'  => array(),
			'only_old'  => array(),
			'changed'   => array(),
			'same'      => 0,
		);
		foreach ( $by_new as $k => $r ) {
			if ( ! isset( $by_old[ $k ] ) ) {
				$out['only_new'][ $k ] = (string) $r['title'];
				continue;
			}
			$o    = $by_old[ $k ];
			$diff = false;
			foreach ( array_keys( self::FIELDS ) as $f ) {
				$a = self::value( $o, $f );
				$b = self::value( $r, $f );
				if ( 'point' === $f ? self::moved( $o, $r ) : $a !== $b ) {
					$out['changed'][ $f ][] = array(
						'title' => (string) $r['title'],
						'old'   => $a,
						'new'   => $b,
					);
					$diff = true;
				}
			}
			if ( ! $diff ) {
				$out['same']++;
			}
		}
		foreach ( $by_old as $k => $r ) {
			if ( ! isset( $by_new[ $k ] ) ) {
				$out['only_old'][ $k ] = (string) $r['title'];
			}
		}
		return $out;
	}

	/** Trips are matched on the path of their link (host and trailing slash ignored). */
	public static function key( array $r ) {
		$path = (string) parse_url( isset( $r['url'] ) ? (string) $r['url'] : '', PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return '/' . trim( rawurldecode( $path ), '/' ) . '/';
	}

	/**
	 * Moved more than the spreading can explain? Spreading moves a trip at most 0.035°, in a
	 * possibly different direction in the two files, so up to 0.07° apart is the same point.
	 */
	public static function moved( array $a, array $b ) {
		$d = function ( $r, $k ) {
			return isset( $r[ $k ] ) ? (float) $r[ $k ] : 0.0;
		};
		return abs( $d( $a, 'lat' ) - $d( $b, 'lat' ) ) > 0.075 || abs( $d( $a, 'lng' ) - $d( $b, 'lng' ) ) > 0.075;
	}

	/** Comparable string for a field. */
	private static function value( array $r, $field ) {
		if ( 'point' === $field ) {
			return sprintf( '%.4f, %.4f', isset( $r['lat'] ) ? (float) $r['lat'] : 0, isset( $r['lng'] ) ? (float) $r['lng'] : 0 );
		}
		$v = isset( $r[ $field ] ) ? $r[ $field ] : '';
		if ( is_array( $v ) ) {
			$v = array_map( 'strval', $v );
			sort( $v );
			return implode( ', ', $v );
		}
		return trim( preg_replace( '/\s+/u', ' ', (string) $v ) );
	}
}
