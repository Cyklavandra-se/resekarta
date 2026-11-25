<?php
/**
 * All contact with WPML goes through this class, so that it can be replaced in tests
 * (Languages::set_instance()) and so that the plugin works without WPML (Swedish only).
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

class Languages {

	/** @var Languages|null */
	private static $instance = null;

	/** @var array|null */
	private $active_cache = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Replace the implementation (tests). Pass null to restore the default. */
	public static function set_instance( $instance ) {
		self::$instance = $instance;
	}

	public function has_wpml() {
		return defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_active_languages' );
	}

	/**
	 * Active languages, default language first.
	 *
	 * @return array code => array( 'code' => , 'name' => native name )
	 */
	public function active() {
		if ( null !== $this->active_cache ) {
			return $this->active_cache;
		}
		$default = $this->default_code();
		$out     = array();
		$langs   = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0, 'orderby' => 'id' ) );
		if ( is_array( $langs ) ) {
			foreach ( $langs as $code => $l ) {
				$code         = isset( $l['code'] ) ? $l['code'] : $code;
				$out[ $code ] = array(
					'code' => $code,
					'name' => isset( $l['native_name'] ) ? $l['native_name'] : $code,
				);
			}
		}
		if ( ! isset( $out[ $default ] ) ) {
			$out[ $default ] = array( 'code' => $default, 'name' => 'Svenska' );
		}
		// Default language first.
		$first             = array( $default => $out[ $default ] );
		$this->active_cache = $first + $out;
		return $this->active_cache;
	}

	/** Codes of active languages, default first. */
	public function codes() {
		return array_keys( $this->active() );
	}

	public function default_code() {
		$code = apply_filters( 'wpml_default_language', null );
		return $code ? (string) $code : 'sv';
	}

	public function current_code() {
		$code = apply_filters( 'wpml_current_language', null );
		return $code ? (string) $code : $this->default_code();
	}

	/**
	 * ID of the page translated to $lang, or 0 when there is no translation.
	 */
	public function translation_id( $post_id, $lang ) {
		if ( ! $this->has_wpml() ) {
			return $lang === $this->default_code() ? (int) $post_id : 0;
		}
		$id = apply_filters( 'wpml_object_id', (int) $post_id, 'page', false, $lang );
		return $id ? (int) $id : 0;
	}

	/** ID of the original (default language) page for any page ID. */
	public function original_id( $post_id ) {
		if ( ! $this->has_wpml() ) {
			return (int) $post_id;
		}
		$id = apply_filters( 'wpml_object_id', (int) $post_id, 'page', true, $this->default_code() );
		return $id ? (int) $id : (int) $post_id;
	}

	/** Language code of a page, or '' if unknown. */
	public function language_of( $post_id ) {
		if ( ! $this->has_wpml() ) {
			return $this->default_code();
		}
		$details = apply_filters( 'wpml_post_language_details', null, (int) $post_id );
		return is_array( $details ) && ! empty( $details['language_code'] ) ? (string) $details['language_code'] : '';
	}

	/** True if the page is in the default (Swedish) language. */
	public function is_original( $post_id ) {
		$lang = $this->language_of( $post_id );
		return '' === $lang || $lang === $this->default_code();
	}

	/**
	 * Language code used in the map's file names and texts. WPML may use "nb" for Norwegian;
	 * the map and the existing files use "no".
	 */
	public static function map_code( $code ) {
		$code = strtolower( (string) $code );
		if ( in_array( $code, array( 'nb', 'nn', 'no', 'nb-no' ), true ) ) {
			return 'no';
		}
		return preg_replace( '/[^a-z0-9_-]/', '', $code );
	}
}
