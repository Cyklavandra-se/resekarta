<?php
/**
 * Plugin Name: Fake WPML (test only)
 * Description: Minimal WPML stand-in for local tests of Resekarta. Languages per page in the
 * post meta _fake_lang, translations share _fake_trid. Not for production.
 */

const FAKE_WPML_LANGS = array(
	'sv' => 'Svenska',
	'no' => 'Norsk bokmål',
	'da' => 'Dansk',
	'fi' => 'Suomi',
	'en' => 'English',
);

add_action( 'init', function () {
	register_taxonomy_for_object_type( 'post_tag', 'page' );
} );

add_filter( 'wpml_active_languages', function ( $v, $args = array() ) {
	$out = array();
	$i   = 1;
	foreach ( FAKE_WPML_LANGS as $code => $name ) {
		$out[ $code ] = array( 'id' => $i++, 'code' => $code, 'native_name' => $name, 'language_code' => $code, 'url' => home_url( 'sv' === $code ? '/' : "/$code/" ) );
	}
	return $out;
}, 10, 2 );

add_filter( 'wpml_default_language', function () { return 'sv'; } );

add_filter( 'wpml_current_language', function () {
	if ( isset( $GLOBALS['fake_wpml_lang'] ) ) {
		return $GLOBALS['fake_wpml_lang'];
	}
	$path = isset( $_SERVER['REQUEST_URI'] ) ? parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '/';
	if ( preg_match( '~^/(no|da|fi|en)/~', (string) $path, $m ) ) {
		return $m[1];
	}
	if ( is_singular() ) {
		$l = get_post_meta( get_queried_object_id(), '_fake_lang', true );
		if ( $l ) {
			return $l;
		}
	}
	return 'sv';
} );

function fake_wpml_lang_of( $id ) {
	$l = get_post_meta( $id, '_fake_lang', true );
	return $l ? $l : 'sv';
}

add_filter( 'wpml_post_language_details', function ( $v, $id ) {
	return array( 'language_code' => fake_wpml_lang_of( $id ) );
}, 10, 2 );

add_filter( 'wpml_object_id', function ( $id, $type = 'page', $return_original = false, $lang = null ) {
	$id   = (int) $id;
	$lang = $lang ? $lang : apply_filters( 'wpml_current_language', null );
	if ( fake_wpml_lang_of( $id ) === $lang ) {
		return $id;
	}
	$trid = get_post_meta( $id, '_fake_trid', true );
	if ( $trid ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_fake_trid' AND meta_value = %s", $trid ) );
		foreach ( $ids as $other ) {
			if ( fake_wpml_lang_of( $other ) === $lang && 'trash' !== get_post_status( $other ) ) {
				return (int) $other;
			}
		}
	}
	return $return_original ? $id : null;
}, 10, 4 );

// Test only: attachments created by build-site.php point at the live site's images.
add_filter( 'wp_get_attachment_url', function ( $url, $id ) {
	$live = get_post_meta( $id, '_live_url', true );
	return $live ? $live : $url;
}, 10, 2 );
add_filter( 'wp_get_attachment_image_src', function ( $image, $id ) {
	$live = get_post_meta( $id, '_live_url', true );
	return $live ? array( $live, 1024, 683, false ) : $image;
}, 10, 2 );

// WPML's language switch for permalinks.
add_action( 'wpml_switch_language', function ( $lang ) {
	if ( null === $lang ) {
		unset( $GLOBALS['fake_wpml_lang'] );
	} else {
		$GLOBALS['fake_wpml_lang'] = $lang;
	}
} );
