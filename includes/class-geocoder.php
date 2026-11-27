<?php
/**
 * Looks up coordinates for a place name. Provider chosen in the settings:
 * OpenStreetMap Nominatim (free, no key) or Google Geocoding (needs an API key).
 *
 * Nominatim's rules are followed: at most one request per second, a real User-Agent with
 * the contact address from the settings, and cached answers (30 days, 7 days for "not found").
 * Only new places are looked up – never in bulk.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class Geocoder {

	const CACHE_PREFIX = 'cvrk_geo_';
	const LAST_CALL    = 'cvrk_geo_last_call';

	/** Result types that are not a place (a street, a building …). */
	const NOT_PLACE_CLASSES = array( 'highway', 'building', 'amenity', 'shop', 'office', 'craft', 'emergency', 'railway', 'aeroway' );
	const NOT_PLACE_TYPES   = array( 'road', 'house', 'house_number', 'postcode', 'building', 'street_address', 'route', 'premise', 'subpremise', 'postal_code' );

	/**
	 * Coordinates for a name.
	 *
	 * @param string $query
	 * @return array|null array( 'lat' => float, 'lng' => float, 'label' => string ) or null if not found.
	 * @throws \RuntimeException When the service cannot be reached (so the caller can retry later).
	 */
	public static function lookup( $query, $places_only = false ) {
		$query = trim( preg_replace( '/\s+/u', ' ', (string) $query ) );
		if ( '' === $query ) {
			return null;
		}
		$pre = apply_filters( 'cvrk_pre_geocode', null, $query, $places_only );
		if ( null !== $pre ) {
			return false === $pre ? null : $pre;
		}
		$provider = (string) Settings::get( 'geocoder' );
		$key      = self::CACHE_PREFIX . md5( $provider . '|' . ( $places_only ? 'p|' : '' ) . self::lower( $query ) );
		$cached   = get_transient( $key );
		if ( is_array( $cached ) ) {
			return empty( $cached['found'] ) ? null : $cached['result'];
		}

		if ( 'google' === $provider ) {
			$result = self::google( $query, $places_only );
		} elseif ( 'photon' === $provider ) {
			$result = self::photon( $query, $places_only );
		} else {
			$result = self::nominatim_with_reserve( $query, $places_only );
		}
		set_transient( $key, array( 'found' => null !== $result, 'result' => $result ), null !== $result ? 30 * DAY_IN_SECONDS : 7 * DAY_IN_SECONDS );
		return $result;
	}

	/**
	 * Nominatim, with Photon as reserve. Nominatim often refuses requests from shared web hosts
	 * (many sites behind one IP address – cyklavandra.se got HTTP 629); it is then paused for an
	 * hour and Photon is asked instead.
	 */
	private static function nominatim_with_reserve( $query, $places_only ) {
		$first = '';
		if ( ! get_transient( 'cvrk_geo_nominatim_paused' ) ) {
			try {
				return self::nominatim( $query, $places_only );
			} catch ( \RuntimeException $e ) {
				$first = $e->getMessage();
				set_transient( 'cvrk_geo_nominatim_paused', $first, HOUR_IN_SECONDS );
			}
		}
		try {
			return self::photon( $query, $places_only );
		} catch ( \RuntimeException $e ) {
			throw new \RuntimeException( trim( ( '' !== $first ? $first : (string) get_transient( 'cvrk_geo_nominatim_paused' ) ) . ' ' . $e->getMessage() ) );
		}
	}

	private static function photon( $query, $places_only = false ) {
		$url = add_query_arg(
			array(
				'q'     => rawurlencode( $query ),
				'limit' => 5,
			),
			'https://photon.komoot.io/api/'
		);
		$res = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'Resekarta/' . VERSION . ' (' . untrailingslashit( (string) get_option( 'home' ) ) . ')',
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			throw new \RuntimeException( 'Photon svarade inte (' . ( is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) . ').' );
		}
		return self::parse_photon( wp_remote_retrieve_body( $res ), $query, $places_only );
	}

	/**
	 * First usable result from a Photon answer (GeoJSON). Pure.
	 * Photon also returns similar names ("Sorteny" → Sorrento), so the name must match the query
	 * (the part before a comma: "Potsdam, Tyskland" matches Potsdam), ignoring case and accents.
	 */
	public static function parse_photon( $json, $query, $places_only = false ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || empty( $data['features'] ) ) {
			return null;
		}
		$wanted = self::plain( strtok( (string) $query, ',' ) );
		foreach ( $data['features'] as $f ) {
			$p = isset( $f['properties'] ) ? $f['properties'] : array();
			$c = isset( $f['geometry']['coordinates'] ) ? $f['geometry']['coordinates'] : null;
			if ( ! is_array( $c ) || count( $c ) < 2 || empty( $p['name'] ) ) {
				continue;
			}
			$key  = isset( $p['osm_key'] ) ? $p['osm_key'] : '';
			$type = isset( $p['type'] ) ? $p['type'] : '';
			if ( in_array( $key, self::NOT_PLACE_CLASSES, true ) || in_array( $type, array( 'street', 'house' ), true ) ) {
				continue;
			}
			if ( $places_only && ! in_array( $key, array( 'place', 'boundary' ), true ) ) {
				continue;
			}
			if ( self::plain( $p['name'] ) !== $wanted ) {
				continue;
			}
			$label = implode( ', ', array_filter( array( $p['name'], isset( $p['state'] ) ? $p['state'] : '', isset( $p['country'] ) ? $p['country'] : '' ) ) );
			return array(
				'lat'   => round( (float) $c[1], 4 ),
				'lng'   => round( (float) $c[0], 4 ),
				'label' => $label,
			);
		}
		return null;
	}

	/** Lower case without accents, for comparing names. */
	private static function plain( $s ) {
		$s = self::lower( trim( (string) $s ) );
		if ( function_exists( 'transliterator_transliterate' ) ) {
			$t = transliterator_transliterate( 'NFD; [:Nonspacing Mark:] Remove; NFC', $s );
			if ( false !== $t ) {
				$s = $t;
			}
		}
		return preg_replace( '/\s+/u', ' ', $s );
	}

	private static function nominatim( $query, $places_only = false ) {
		self::wait_turn();
		$contact = (string) Settings::get( 'geocode_contact' );
		$url     = add_query_arg(
			array(
				'format'          => 'jsonv2',
				'limit'           => 3,
				'q'               => rawurlencode( $query ),
				'accept-language' => 'sv',
				'email'           => rawurlencode( $contact ),
			),
			'https://nominatim.openstreetmap.org/search'
		);
		$res = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'Resekarta/' . VERSION . ' (' . untrailingslashit( (string) get_option( 'home' ) ) . ( $contact ? '; ' . $contact : '' ) . ')',
				'headers'    => array( 'Accept-Language' => 'sv' ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			throw new \RuntimeException( 'OpenStreetMap svarade inte (' . ( is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) . ').' );
		}
		return self::parse_nominatim( wp_remote_retrieve_body( $res ), $places_only );
	}

	private static function google( $query, $places_only = false ) {
		$key = (string) Settings::get( 'google_key' );
		if ( '' === $key ) {
			throw new \RuntimeException( 'Google Geocoding är valt men nyckeln saknas (Resekarta → Inställningar).' );
		}
		$url = add_query_arg(
			array(
				'address'  => rawurlencode( $query ),
				'language' => 'sv',
				'key'      => rawurlencode( $key ),
			),
			'https://maps.googleapis.com/maps/api/geocode/json'
		);
		$res = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			throw new \RuntimeException( 'Google Geocoding svarade inte.' );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( is_array( $data ) && isset( $data['status'] ) && ! in_array( $data['status'], array( 'OK', 'ZERO_RESULTS' ), true ) ) {
			throw new \RuntimeException( 'Google Geocoding: ' . $data['status'] . ( isset( $data['error_message'] ) ? ' – ' . $data['error_message'] : '' ) );
		}
		return self::parse_google( wp_remote_retrieve_body( $res ), $places_only );
	}

	/**
	 * First usable result from a Nominatim answer (jsonv2). Pure.
	 * $places_only: only settlements and areas (category place/boundary) – not rivers, lakes,
	 * hotels … Used for names from the day programme, where "Havel" should not win over a town.
	 */
	public static function parse_nominatim( $json, $places_only = false ) {
		$list = json_decode( (string) $json, true );
		if ( ! is_array( $list ) ) {
			return null;
		}
		foreach ( $list as $r ) {
			if ( ! isset( $r['lat'], $r['lon'] ) ) {
				continue;
			}
			$class = isset( $r['category'] ) ? $r['category'] : ( isset( $r['class'] ) ? $r['class'] : '' );
			$type  = isset( $r['addresstype'] ) ? $r['addresstype'] : ( isset( $r['type'] ) ? $r['type'] : '' );
			if ( in_array( $class, self::NOT_PLACE_CLASSES, true ) || in_array( $type, self::NOT_PLACE_TYPES, true ) ) {
				continue;
			}
			if ( $places_only && ! in_array( $class, array( 'place', 'boundary' ), true ) ) {
				continue;
			}
			return array(
				'lat'   => round( (float) $r['lat'], 4 ),
				'lng'   => round( (float) $r['lon'], 4 ),
				'label' => isset( $r['display_name'] ) ? (string) $r['display_name'] : '',
			);
		}
		return null;
	}

	/** First usable result from a Google Geocoding answer. Pure. */
	public static function parse_google( $json, $places_only = false ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || empty( $data['results'] ) ) {
			return null;
		}
		foreach ( $data['results'] as $r ) {
			$types = isset( $r['types'] ) ? (array) $r['types'] : array();
			if ( array_intersect( $types, self::NOT_PLACE_TYPES ) ) {
				continue;
			}
			if ( $places_only && ! preg_grep( '/^(locality|sublocality|postal_town|neighborhood|colloquial_area|administrative_area_level_\d|country|archipelago)$/', $types ) ) {
				continue;
			}
			if ( ! isset( $r['geometry']['location']['lat'], $r['geometry']['location']['lng'] ) ) {
				continue;
			}
			return array(
				'lat'   => round( (float) $r['geometry']['location']['lat'], 4 ),
				'lng'   => round( (float) $r['geometry']['location']['lng'], 4 ),
				'label' => isset( $r['formatted_address'] ) ? (string) $r['formatted_address'] : '',
			);
		}
		return null;
	}

	/** At most one request per second to Nominatim, also across requests. */
	private static function wait_turn() {
		$last = (float) get_option( self::LAST_CALL, 0 );
		$wait = $last + 1.1 - microtime( true );
		if ( $wait > 0 && $wait < 5 ) {
			usleep( (int) ( $wait * 1000000 ) );
		}
		update_option( self::LAST_CALL, (string) microtime( true ), false );
	}

	private static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}
}
