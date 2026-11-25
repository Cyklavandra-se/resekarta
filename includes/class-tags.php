<?php
/**
 * Pure helpers that turn a page's tag slugs into map fields. No WordPress calls.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class Tags {

	/** Tag slug => season name used by the map (Swedish keys, translated in the map). */
	const SEASONS = array(
		'var'    => 'Vår',
		'sommar' => 'Sommar',
		'host'   => 'Höst',
		'vinter' => 'Vinter',
	);

	/** Map field => tag slug. */
	const FLAGS = array(
		'dog'     => 'hund',
		'train'   => 'tag',
		'wine'    => 'vin',
		'weekend' => 'langhelg',
		'family'  => 'barn',
	);

	/**
	 * Normalize slugs: decoded (å/ä/ö rather than %c3%a5), lower case, unique.
	 *
	 * @param string[] $slugs
	 * @return string[]
	 */
	public static function normalize( array $slugs ) {
		$out = array();
		foreach ( $slugs as $slug ) {
			$slug = trim( rawurldecode( (string) $slug ) );
			$slug = function_exists( 'mb_strtolower' ) ? mb_strtolower( $slug, 'UTF-8' ) : strtolower( $slug );
			if ( '' !== $slug && ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/** Seasons in fixed order (Vår, Sommar, Höst, Vinter). */
	public static function seasons( array $slugs ) {
		$out = array();
		foreach ( self::SEASONS as $slug => $name ) {
			if ( in_array( $slug, $slugs, true ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/** Levels "1"–"4" from hike1–4 / bike1–4. A trip can have several. */
	public static function levels( array $slugs ) {
		$out = array();
		for ( $n = 1; $n <= 4; $n++ ) {
			if ( in_array( 'hike' . $n, $slugs, true ) || in_array( 'bike' . $n, $slugs, true ) ) {
				$out[] = (string) $n;
			}
		}
		return $out;
	}

	/** dog/train/wine/weekend/family => bool. */
	public static function flags( array $slugs ) {
		$out = array();
		foreach ( self::FLAGS as $field => $slug ) {
			$out[ $field ] = in_array( $slug, $slugs, true );
		}
		return $out;
	}
}
