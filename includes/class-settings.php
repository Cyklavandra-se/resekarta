<?php
/**
 * Plugin settings (one option, array).
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'cvrk_settings';

	/** Map styles offered by the map (keys used by reskarta.html). */
	const MAP_STYLES = array(
		'bright'   => 'Ljus färgad',
		'liberty'  => 'Färgad',
		'positron' => 'Ljus',
		'klassisk' => 'Klassisk',
	);

	const GEOCODERS = array(
		'nominatim' => 'OpenStreetMap Nominatim, med Photon som reserv (gratis)',
		'photon'    => 'Photon (gratis, bygger på OpenStreetMap)',
		'google'    => 'Google Geocoding (kräver nyckel)',
	);

	public static function defaults() {
		return array(
			'notify_email'    => get_option( 'admin_email' ),
			'geocode_contact' => get_option( 'admin_email' ),
			'geocoder'        => 'nominatim',
			'google_key'      => '',
			'map_style'       => 'bright',
			// Category => list page path (relative to the site's home, Swedish).
			'list_pages'      => array(
				'guidad'   => '/vandra/guidade/',
				'cykel'    => '/cykla/cykla-pa-egen-hand/',
				'vandring' => '/vandra/pa-egen-hand/',
			),
			// The plugin's [resekarta] is off until the old Code Snippets snippet is switched off.
			'shortcode_enabled'   => false,
			'delete_on_uninstall' => false,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Sanitize raw form input into a settings array.
	 *
	 * @param array $in Raw input (already unslashed).
	 * @return array
	 */
	public static function sanitize( array $in ) {
		$out = self::all();

		$out['notify_email']    = self::sanitize_emails( isset( $in['notify_email'] ) ? $in['notify_email'] : '' );
		$out['geocode_contact'] = sanitize_email( isset( $in['geocode_contact'] ) ? $in['geocode_contact'] : '' );

		$geocoder        = isset( $in['geocoder'] ) ? sanitize_key( $in['geocoder'] ) : 'nominatim';
		$out['geocoder'] = isset( self::GEOCODERS[ $geocoder ] ) ? $geocoder : 'nominatim';

		$out['google_key'] = isset( $in['google_key'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $in['google_key'] ) : '';

		$style            = isset( $in['map_style'] ) ? sanitize_key( $in['map_style'] ) : 'bright';
		$out['map_style'] = isset( self::MAP_STYLES[ $style ] ) ? $style : 'bright';

		foreach ( array_keys( Trips::CATEGORIES ) as $cat ) {
			$path = isset( $in['list_pages'][ $cat ] ) ? trim( (string) $in['list_pages'][ $cat ] ) : '';
			if ( '' !== $path ) {
				$path = '/' . trim( (string) wp_parse_url( $path, PHP_URL_PATH ), '/' ) . '/';
				$path = str_replace( '//', '/', $path );
			}
			$out['list_pages'][ $cat ] = sanitize_text_field( $path );
		}

		$out['shortcode_enabled']   = ! empty( $in['shortcode_enabled'] );
		$out['delete_on_uninstall'] = ! empty( $in['delete_on_uninstall'] );

		return $out;
	}

	/** Comma separated list of e-mail addresses. */
	public static function sanitize_emails( $value ) {
		$ok = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $value ) as $mail ) {
			$mail = sanitize_email( $mail );
			if ( $mail && is_email( $mail ) ) {
				$ok[] = $mail;
			}
		}
		return implode( ', ', array_unique( $ok ) );
	}
}
