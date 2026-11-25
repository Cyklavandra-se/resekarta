<?php
/**
 * Destinations: a non-public taxonomy (one set for all languages – see wpml-config.xml)
 * with coordinates, geocoding keywords and names per language as term meta.
 *
 * Trips point to a destination with the post meta cvrk_destination (term ID); the taxonomy
 * is deliberately not attached to pages, so WPML never copies or translates the relation.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class Destinations {

	const TAXONOMY = 'cvrk_destination';

	const META_LAT      = 'cvrk_lat';
	const META_LNG      = 'cvrk_lng';
	const META_KEYWORDS = 'cvrk_keywords';
	const META_NAME     = 'cvrk_name_'; // + language code, e.g. cvrk_name_no.

	public static function register() {
		if ( taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}
		register_taxonomy(
			self::TAXONOMY,
			array(),
			array(
				'labels'            => array(
					'name'          => 'Destinationer',
					'singular_name' => 'Destination',
				),
				'public'            => false,
				'publicly_queryable' => false,
				'show_ui'           => false,
				'show_in_rest'      => false,
				'show_in_nav_menus' => false,
				'show_tagcloud'     => false,
				'hierarchical'      => false,
				'rewrite'           => false,
				'query_var'         => false,
			)
		);
	}

	/**
	 * All destinations, sorted by name.
	 *
	 * @return array[] See self::to_array().
	 */
	public static function all() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			$out[ $term->term_id ] = self::to_array( $term );
		}
		uasort(
			$out,
			function ( $a, $b ) {
				return self::compare_names( $a['name'], $b['name'] );
			}
		);
		return $out;
	}

	/** Swedish sort order (å, ä, ö last). */
	public static function compare_names( $a, $b ) {
		if ( class_exists( '\Collator' ) ) {
			static $collator = null;
			if ( null === $collator ) {
				$collator = new \Collator( 'sv_SE' );
			}
			return $collator->compare( $a, $b );
		}
		return strcasecmp( $a, $b );
	}

	/** @return array|null */
	public static function get( $term_id ) {
		$term = $term_id ? get_term( (int) $term_id, self::TAXONOMY ) : null;
		return ( $term && ! is_wp_error( $term ) ) ? self::to_array( $term ) : null;
	}

	public static function to_array( \WP_Term $term ) {
		$meta  = get_term_meta( $term->term_id );
		$one   = function ( $key ) use ( $meta ) {
			return isset( $meta[ $key ][0] ) ? (string) $meta[ $key ][0] : '';
		};
		$names = array();
		foreach ( $meta as $key => $values ) {
			if ( 0 === strpos( $key, self::META_NAME ) && '' !== trim( (string) $values[0] ) ) {
				$names[ substr( $key, strlen( self::META_NAME ) ) ] = (string) $values[0];
			}
		}
		ksort( $names );
		$lat = $one( self::META_LAT );
		$lng = $one( self::META_LNG );
		return array(
			'id'       => (int) $term->term_id,
			'name'     => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ), // WordPress stores "&" as "&amp;".
			'lat'      => '' === $lat ? null : (float) $lat,
			'lng'      => '' === $lng ? null : (float) $lng,
			'keywords' => $one( self::META_KEYWORDS ),
			'names'    => $names,
		);
	}

	/** Name in a language; the Swedish name when no translation is stored. */
	public static function name_in( array $dest, $lang ) {
		$lang = Languages::map_code( $lang );
		return ! empty( $dest['names'][ $lang ] ) ? $dest['names'][ $lang ] : $dest['name'];
	}

	public static function has_point( $dest ) {
		return is_array( $dest ) && null !== $dest['lat'] && null !== $dest['lng'];
	}

	/** Find by Swedish name, case-insensitive. Returns term ID or 0. */
	public static function find_by_name( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 0;
		}
		$term = get_term_by( 'name', $name, self::TAXONOMY );
		if ( $term ) {
			return (int) $term->term_id;
		}
		// get_term_by() compares with the database collation; do a strict fallback in PHP.
		$lower = self::lower( $name );
		foreach ( self::all() as $id => $d ) {
			if ( self::lower( $d['name'] ) === $lower ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Create a destination. Returns term ID, or WP_Error.
	 *
	 * @param string $name Swedish name.
	 * @param array  $data Optional lat, lng, keywords, names.
	 */
	public static function create( $name, array $data = array() ) {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new \WP_Error( 'cvrk_empty', 'Destinationen måste ha ett namn.' );
		}
		$res = wp_insert_term( $name, self::TAXONOMY, array( 'slug' => self::unique_slug( $name ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$id = (int) $res['term_id'];
		self::update( $id, $data, true );
		return $id;
	}

	/** Slugs are never shown; keep them unique even for names that only differ in å/ä/ö. */
	private static function unique_slug( $name ) {
		$base = sanitize_title( $name );
		if ( '' === $base ) {
			$base = 'destination';
		}
		$slug = $base;
		$n    = 2;
		while ( term_exists( $slug, self::TAXONOMY ) ) {
			$slug = $base . '-' . $n++;
		}
		return $slug;
	}

	/**
	 * Update fields. With $overwrite = false, only empty fields are filled.
	 *
	 * @param int   $id
	 * @param array $data lat, lng, keywords, names (lang => name).
	 * @param bool  $overwrite
	 * @return string[] Names of changed fields.
	 */
	public static function update( $id, array $data, $overwrite = true ) {
		$changed = array();
		$current = self::get( $id );
		if ( ! $current ) {
			return $changed;
		}
		if ( array_key_exists( 'lat', $data ) && array_key_exists( 'lng', $data ) ) {
			$lat = self::coordinate( $data['lat'], 90 );
			$lng = self::coordinate( $data['lng'], 180 );
			if ( $overwrite || ! self::has_point( $current ) ) {
				if ( null === $lat || null === $lng ) {
					if ( $overwrite && self::has_point( $current ) && '' === trim( (string) $data['lat'] ) && '' === trim( (string) $data['lng'] ) ) {
						delete_term_meta( $id, self::META_LAT );
						delete_term_meta( $id, self::META_LNG );
						$changed[] = 'punkt';
					}
				} elseif ( $lat !== $current['lat'] || $lng !== $current['lng'] ) {
					update_term_meta( $id, self::META_LAT, (string) $lat );
					update_term_meta( $id, self::META_LNG, (string) $lng );
					$changed[] = 'punkt';
				}
			}
		}
		if ( array_key_exists( 'keywords', $data ) ) {
			$kw = sanitize_text_field( (string) $data['keywords'] );
			if ( ( $overwrite || '' === $current['keywords'] ) && $kw !== $current['keywords'] && ( '' !== $kw || $overwrite ) ) {
				update_term_meta( $id, self::META_KEYWORDS, $kw );
				$changed[] = 'sökord';
			}
		}
		if ( ! empty( $data['names'] ) && is_array( $data['names'] ) ) {
			foreach ( $data['names'] as $lang => $name ) {
				$lang = Languages::map_code( $lang );
				$name = sanitize_text_field( (string) $name );
				$old  = isset( $current['names'][ $lang ] ) ? $current['names'][ $lang ] : '';
				if ( '' === $lang || $name === $old || ( ! $overwrite && '' !== $old ) ) {
					continue;
				}
				if ( '' === $name ) {
					if ( $overwrite ) {
						delete_term_meta( $id, self::META_NAME . $lang );
						$changed[] = 'namn ' . $lang;
					}
					continue;
				}
				update_term_meta( $id, self::META_NAME . $lang, $name );
				$changed[] = 'namn ' . $lang;
			}
		}
		return $changed;
	}

	/**
	 * Parse a coordinate ("43,25" or "43.25"). Returns float rounded to 6 decimals or null.
	 */
	public static function coordinate( $value, $max ) {
		$value = str_replace( array( ',', ' ' ), array( '.', '' ), trim( (string) $value ) );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		$f = round( (float) $value, 6 );
		return ( abs( $f ) <= $max ) ? $f : null;
	}

	/** Number of trips per destination: term ID => count. */
	public static function trip_counts() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.meta_value AS dest, COUNT(*) AS n
				FROM {$wpdb->postmeta} d
				INNER JOIN {$wpdb->postmeta} t ON t.post_id = d.post_id AND t.meta_key = %s AND t.meta_value = '1'
				WHERE d.meta_key = %s
				GROUP BY d.meta_value",
				Trips::META_TRIP,
				Trips::META_DESTINATION
			)
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->dest ] = (int) $r->n;
		}
		return $out;
	}

	private static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $s ), 'UTF-8' ) : strtolower( trim( $s ) );
	}
}
