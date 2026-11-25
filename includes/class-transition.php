<?php
/**
 * The switch-over from the spreadsheet + Code Snippets snippet to the plugin: status for the
 * checklist in Resekarta → Övergång, and removal of the old map files afterwards.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class Transition {

	/** Files the old solution wrote to uploads/karta/ (nothing else there is touched). */
	const OLD_FILES = array( 'resor-sv.js', 'resor-no.js', 'reskarta.html' );

	public static function old_dir() {
		$up = wp_upload_dir( null, false );
		return trailingslashit( $up['basedir'] ) . 'karta/';
	}

	/**
	 * The old files that still exist: name => modified (timestamp).
	 */
	public static function old_files() {
		$out = array();
		foreach ( self::OLD_FILES as $f ) {
			if ( file_exists( self::old_dir() . $f ) ) {
				$out[ $f ] = (int) filemtime( self::old_dir() . $f );
			}
		}
		return $out;
	}

	/**
	 * Published content that shows the map: the shortcode, or an iframe pointing straight at the
	 * old map file (those need editing by hand).
	 *
	 * @return array[] list of array( id, title, url, lang, kind => 'shortcode'|'iframe', code )
	 */
	public static function pages_with_map() {
		global $wpdb;
		// Avada's Code Block stores its content base64-encoded: [fusion_code]W3Jlc2VrYXJ0YV0=[/fusion_code].
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts}
			WHERE post_status = 'publish' AND post_type NOT IN ('revision', 'nav_menu_item')
			AND ( post_content LIKE '%[resekarta%' OR post_content LIKE '%karta/reskarta.html%' OR post_content LIKE '%[fusion_code%' )
			ORDER BY post_title"
		);
		$langs = Languages::instance();
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$content = self::decode_code_blocks( $r->post_content );
			$is_iframe = false !== strpos( $content, 'karta/reskarta.html' );
			if ( ! $is_iframe && false === strpos( $content, '[resekarta' ) ) {
				continue;
			}
			preg_match_all( '/\[resekarta(?:_test)?\b[^\]]*\]/', $content, $m );
			$lang  = $langs->language_of( $r->ID );
			$out[] = array(
				'id'    => (int) $r->ID,
				'title' => html_entity_decode( $r->post_title, ENT_QUOTES, 'UTF-8' ),
				'url'   => (string) get_permalink( $r->ID ),
				'lang'  => '' !== $lang ? Languages::map_code( $lang ) : '',
				'kind'  => $is_iframe ? 'iframe' : 'shortcode',
				'code'  => implode( ' ', array_unique( $m[0] ) ),
				'type'  => $r->post_type,
			);
		}
		return $out;
	}

	/** Content with Avada Code Blocks decoded, so shortcodes inside them can be found. Pure. */
	public static function decode_code_blocks( $content ) {
		return preg_replace_callback(
			'~\[fusion_code([^\]]*)\](.*?)\[/fusion_code\]~s',
			function ( $m ) {
				$raw     = trim( $m[2] );
				$decoded = base64_decode( $raw, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				return '[fusion_code' . $m[1] . ']' . ( false !== $decoded && preg_match( '//u', $decoded ) ? $decoded : $raw ) . '[/fusion_code]';
			},
			(string) $content
		);
	}

	/**
	 * Per language: does the plugin's data cover every trip of the old map?
	 *
	 * @return array code => array( 'new' => n, 'old' => n|null, 'missing' => n )
	 */
	public static function comparison() {
		$out   = array();
		$build = Map_Data::last_build();
		if ( ! $build ) {
			return $out;
		}
		foreach ( array_keys( $build['languages'] ) as $code ) {
			$new = Map_Data::read_file( Map_Data::dir() . 'resor-' . $code . '.js' );
			$old = Map_Data::read_file( Map_Compare::old_file( $code ) );
			$c   = ( null !== $new && null !== $old ) ? Map_Compare::compare( $new, $old ) : null;
			$out[ $code ] = array(
				'new'     => null === $new ? 0 : count( $new ),
				'old'     => null === $old ? null : count( $old ),
				'missing' => $c ? count( $c['only_old'] ) : 0,
			);
		}
		return $out;
	}

	/**
	 * Remove the old map files. Only when the plugin's shortcode is on, no snippet registers
	 * [resekarta], and no page embeds the old file directly.
	 *
	 * @return array|\WP_Error Deleted file names.
	 */
	public static function delete_old_files() {
		if ( ! Shortcode::enabled() || Shortcode::other_owner() ) {
			return new \WP_Error( 'cvrk_not_yet', 'Slå först på tilläggets kortkod och stäng av kodstycket.' );
		}
		foreach ( self::pages_with_map() as $p ) {
			if ( 'iframe' === $p['kind'] ) {
				return new \WP_Error( 'cvrk_in_use', 'Sidan "' . $p['title'] . '" visar fortfarande den gamla kartfilen direkt. Byt till [resekarta] där först.' );
			}
		}
		$deleted = array();
		foreach ( array_keys( self::old_files() ) as $f ) {
			if ( @unlink( self::old_dir() . $f ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
				$deleted[] = $f;
			}
		}
		return $deleted;
	}
}
