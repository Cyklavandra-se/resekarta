<?php
/**
 * Trips: Swedish pages marked with the post meta cvrk_trip = 1, and their fields.
 * Name, image, SEO description and tags are always read from the page itself.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class Trips {

	const META_TRIP           = 'cvrk_trip';           // '1' = the page is a trip.
	const META_LISTED         = 'cvrk_listed';         // '1' = found on a list page in the latest scan.
	const META_CATEGORY_AUTO  = 'cvrk_category_auto';  // Category from the list pages.
	const META_CATEGORY       = 'cvrk_category';       // Manual category ('' = auto).
	const META_CATEGORY_SHEET = 'cvrk_category_sheet'; // Category from the spreadsheet import, until the first scan.
	const META_VISIBILITY     = 'cvrk_visibility';     // auto / hide / show.
	const META_DESTINATION    = 'cvrk_destination';    // Term ID in cvrk_destination.
	const META_LAT            = 'cvrk_lat';            // Own point.
	const META_LNG            = 'cvrk_lng';
	const META_CHECK          = 'cvrk_check';          // Non-empty = "Kontrollera", with the reason.
	const META_FIRST_SEEN     = 'cvrk_first_seen';
	const META_LAST_SEEN      = 'cvrk_last_seen';

	const CATEGORIES = array(
		'guidad'   => 'Guidad',
		'cykel'    => 'Cykel',
		'vandring' => 'Vandring',
	);

	const VISIBILITIES = array(
		'auto' => 'Auto',
		'hide' => 'Dölj alltid',
		'show' => 'Visa alltid',
	);

	/** All trip page IDs, any status (also drafts and trash, so the overview can explain). */
	public static function ids() {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => 'page',
					'post_status'      => 'any',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'meta_key'         => self::META_TRIP, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'       => '1',             // phpcs:ignore WordPress.DB.SlowDBQuery
					'suppress_filters' => true,            // No WPML language filter.
					'orderby'          => 'title',
					'order'            => 'ASC',
					'no_found_rows'    => true,
				)
			)
		);
	}

	public static function is_trip( $post_id ) {
		return '1' === get_post_meta( $post_id, self::META_TRIP, true );
	}

	/** Mark a page as a trip. Returns true if it was new. */
	public static function register( $post_id ) {
		if ( self::is_trip( $post_id ) ) {
			return false;
		}
		update_post_meta( $post_id, self::META_TRIP, '1' );
		if ( ! get_post_meta( $post_id, self::META_FIRST_SEEN, true ) ) {
			update_post_meta( $post_id, self::META_FIRST_SEEN, current_time( 'mysql' ) );
		}
		if ( ! get_post_meta( $post_id, self::META_VISIBILITY, true ) ) {
			update_post_meta( $post_id, self::META_VISIBILITY, 'auto' );
		}
		return true;
	}

	/**
	 * All stored fields of a trip.
	 *
	 * @return array
	 */
	public static function get( $post_id ) {
		$post_id = (int) $post_id;
		$m       = function ( $key ) use ( $post_id ) {
			return (string) get_post_meta( $post_id, $key, true );
		};
		$cat_auto   = $m( self::META_CATEGORY_AUTO );
		$cat_manual = $m( self::META_CATEGORY );
		$visibility = $m( self::META_VISIBILITY );
		$lat        = Destinations::coordinate( $m( self::META_LAT ), 90 );
		$lng        = Destinations::coordinate( $m( self::META_LNG ), 180 );

		return array(
			'id'              => $post_id,
			'title'           => get_the_title( $post_id ),
			'status'          => (string) get_post_status( $post_id ),
			'listed'          => '1' === $m( self::META_LISTED ),
			'category_auto'   => isset( self::CATEGORIES[ $cat_auto ] ) ? $cat_auto : '',
			'category_manual' => isset( self::CATEGORIES[ $cat_manual ] ) ? $cat_manual : '',
			'category'        => isset( self::CATEGORIES[ $cat_manual ] ) ? $cat_manual : ( isset( self::CATEGORIES[ $cat_auto ] ) ? $cat_auto : '' ),
			'visibility'      => isset( self::VISIBILITIES[ $visibility ] ) ? $visibility : 'auto',
			'destination'     => (int) $m( self::META_DESTINATION ),
			'own_point'       => ( null !== $lat && null !== $lng ) ? array( $lat, $lng ) : null,
			'check'           => $m( self::META_CHECK ),
			'first_seen'      => $m( self::META_FIRST_SEEN ),
			'last_seen'       => $m( self::META_LAST_SEEN ),
		);
	}

	/**
	 * The trip's point on the map: own point, else the destination's point, else null.
	 *
	 * @param array      $trip From self::get().
	 * @param array|null $dest From Destinations::get().
	 */
	public static function point( array $trip, $dest ) {
		if ( $trip['own_point'] ) {
			return $trip['own_point'];
		}
		if ( Destinations::has_point( $dest ) ) {
			return array( $dest['lat'], $dest['lng'] );
		}
		return null;
	}

	/**
	 * Is the trip on the map, and if not, why. Pure apart from the inputs.
	 *
	 * @param array      $trip From self::get().
	 * @param array|null $dest From Destinations::get().
	 * @return array( 'on' => bool, 'reason' => string, 'code' => string )
	 */
	public static function map_status( array $trip, $dest ) {
		if ( 'hide' === $trip['visibility'] ) {
			return array( 'on' => false, 'code' => 'hidden', 'reason' => 'Dold' );
		}
		if ( 'publish' !== $trip['status'] ) {
			$label = 'trash' === $trip['status'] ? 'i papperskorgen' : 'inte publicerad';
			return array( 'on' => false, 'code' => 'removed', 'reason' => 'Borttagen (' . $label . ')' );
		}
		if ( 'show' !== $trip['visibility'] && ! $trip['listed'] ) {
			return array( 'on' => false, 'code' => 'removed', 'reason' => 'Borttagen (finns inte på listsidorna)' );
		}
		if ( '' === $trip['category'] ) {
			return array( 'on' => false, 'code' => 'no_category', 'reason' => 'Saknar kategori' );
		}
		if ( ! $trip['own_point'] ) {
			if ( ! $dest ) {
				return array( 'on' => false, 'code' => 'no_destination', 'reason' => 'Saknar destination' );
			}
			if ( ! Destinations::has_point( $dest ) ) {
				return array( 'on' => false, 'code' => 'no_point', 'reason' => 'Saknar koordinater' );
			}
		}
		return array( 'on' => true, 'code' => 'ok', 'reason' => '' );
	}

	/** Needs Pär's attention: flagged, or should be on the map but cannot be. */
	public static function needs_action( array $trip, array $status ) {
		return '' !== $trip['check'] || in_array( $status['code'], array( 'no_category', 'no_destination', 'no_point' ), true );
	}

	/** The page's tag slugs (decoded, lower case). Read directly from the page. */
	public static function tag_slugs( $post_id ) {
		$slugs = wp_get_object_terms( (int) $post_id, 'post_tag', array( 'fields' => 'slugs' ) );
		return is_wp_error( $slugs ) ? array() : Tags::normalize( $slugs );
	}

	/**
	 * Update a trip's editable fields. Only the keys present in $data are changed.
	 *
	 * @param int   $post_id
	 * @param array $data category ('' = auto), visibility, destination (term ID, 0 = none),
	 *                    lat + lng (own point, both '' = remove), check_done (true = clear the flag).
	 * @return array|\WP_Error Names of the changed fields, or an error (nothing is changed then).
	 */
	public static function update( $post_id, array $data ) {
		$post_id = (int) $post_id;
		$trip    = self::get( $post_id );
		$changes = array();
		$errors  = array();

		if ( array_key_exists( 'category', $data ) ) {
			$cat = sanitize_key( (string) $data['category'] );
			if ( '' !== $cat && ! isset( self::CATEGORIES[ $cat ] ) ) {
				$errors[] = 'Okänd kategori.';
			} elseif ( $cat !== $trip['category_manual'] ) {
				$changes[ self::META_CATEGORY ] = $cat;
			}
		}
		if ( array_key_exists( 'visibility', $data ) ) {
			$vis = sanitize_key( (string) $data['visibility'] );
			if ( ! isset( self::VISIBILITIES[ $vis ] ) ) {
				$errors[] = 'Okänd synlighet.';
			} elseif ( $vis !== $trip['visibility'] ) {
				$changes[ self::META_VISIBILITY ] = $vis;
			}
		}
		if ( array_key_exists( 'destination', $data ) ) {
			$dest = (int) $data['destination'];
			if ( $dest && ! Destinations::get( $dest ) ) {
				$errors[] = 'Destinationen finns inte.';
			} elseif ( $dest !== $trip['destination'] ) {
				$changes[ self::META_DESTINATION ] = $dest ? $dest : '';
			}
		}
		if ( array_key_exists( 'lat', $data ) || array_key_exists( 'lng', $data ) ) {
			$raw_lat = trim( (string) ( isset( $data['lat'] ) ? $data['lat'] : '' ) );
			$raw_lng = trim( (string) ( isset( $data['lng'] ) ? $data['lng'] : '' ) );
			if ( '' === $raw_lat && '' === $raw_lng ) {
				if ( $trip['own_point'] ) {
					$changes[ self::META_LAT ] = '';
					$changes[ self::META_LNG ] = '';
				}
			} else {
				$lat = Destinations::coordinate( $raw_lat, 90 );
				$lng = Destinations::coordinate( $raw_lng, 180 );
				if ( null === $lat || null === $lng ) {
					$errors[] = 'Egen punkt: fyll i både Lat (−90 till 90) och Lng (−180 till 180), t.ex. 42.5063 och 1.5218.';
				} elseif ( array( $lat, $lng ) !== $trip['own_point'] ) {
					$changes[ self::META_LAT ] = (string) $lat;
					$changes[ self::META_LNG ] = (string) $lng;
				}
			}
		}
		if ( ! empty( $data['check_done'] ) && '' !== $trip['check'] ) {
			$changes[ self::META_CHECK ] = '';
		}

		if ( $errors ) {
			return new \WP_Error( 'cvrk_invalid', implode( ' ', $errors ) );
		}
		foreach ( $changes as $key => $value ) {
			self::set_meta( $post_id, $key, $value );
		}
		if ( $changes ) {
			do_action( 'cvrk_trips_changed', 'edit' );
		}
		return array_keys( $changes );
	}

	public static function set_meta( $post_id, $key, $value ) {
		if ( '' === $value || null === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}
}
