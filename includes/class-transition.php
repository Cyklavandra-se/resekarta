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
			$content   = self::decode_code_blocks( $r->post_content );
			$has_old   = false !== strpos( $content, 'karta/reskarta.html' );
			$has_short = false !== strpos( $content, '[resekarta' );
			if ( ! $has_old && ! $has_short ) {
				continue;
			}
			// The saved content can mention the old file without showing it (a hidden or disabled
			// element, old code kept in a block). Only the page as visitors see it counts.
			$kind = 'shortcode';
			if ( $has_old ) {
				$shown = self::page_shows_old_map( $r->ID );
				if ( true === $shown ) {
					$kind = 'iframe';
				} elseif ( null === $shown ) {
					$kind = 'unknown';
				} elseif ( ! $has_short ) {
					$kind = 'hidden_old';
				}
			}
			preg_match_all( '/\[resekarta(?:_test)?\b[^\]]*\]/', $content, $m );
			$lang  = $langs->language_of( $r->ID );
			$out[] = array(
				'id'    => (int) $r->ID,
				'title' => html_entity_decode( $r->post_title, ENT_QUOTES, 'UTF-8' ),
				'url'   => (string) get_permalink( $r->ID ),
				'lang'  => '' !== $lang ? Languages::map_code( $lang ) : '',
				'kind'  => $kind,
				'code'  => implode( ' ', array_unique( $m[0] ) ),
				'type'  => $r->post_type,
			);
		}
		return $out;
	}

	/**
	 * Does the published page, as visitors get it, embed the old map file? Null if the page
	 * could not be fetched. Cached for 10 minutes.
	 */
	public static function page_shows_old_map( $post_id ) {
		$pre = apply_filters( 'cvrk_pre_page_shows_old_map', null, $post_id );
		if ( null !== $pre ) {
			return $pre;
		}
		$key    = 'cvrk_oldmap_' . (int) $post_id;
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return 'yes' === $cached;
		}
		$res = wp_remote_get( get_permalink( $post_id ), array( 'timeout' => 20, 'user-agent' => List_Scanner::user_agent() ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$shows = (bool) preg_match( '~<iframe[^>]+src=["\'][^"\']*karta/reskarta\.html~i', preg_replace( '/<!--.*?-->/s', '', wp_remote_retrieve_body( $res ) ) );
		set_transient( $key, $shows ? 'yes' : 'no', 10 * MINUTE_IN_SECONDS );
		return $shows;
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
			$c   = ( null !== $new && null !== $old ) ? Map_Compare::compare( $new, $old, Map_Compare::wp_resolver() ) : null;
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
			if ( in_array( $p['kind'], array( 'iframe', 'unknown' ), true ) ) {
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
