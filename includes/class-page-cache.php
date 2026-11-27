<?php
/**
 * Empties the page cache of the pages that show the map, when the map has changed.
 *
 * The map's data is in static files, but the page around it holds the iframe address with the
 * map's version number. A cached copy of the page (WP Fastest Cache on cyklavandra.se) keeps
 * the old number, so returning visitors could see the old map until the cache expires.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class Page_Cache {

	public static function boot() {
		add_action( 'cvrk_map_built', array( __CLASS__, 'on_map_built' ), 10, 2 );
	}

	/**
	 * After a build: if the version changed, clear the cache of the pages with the map.
	 *
	 * @param array      $report   The new build report.
	 * @param array|null $previous The previous build report.
	 */
	public static function on_map_built( $report, $previous = null ) {
		if ( empty( $report['ok'] ) || ( is_array( $previous ) && isset( $previous['version'] ) && $previous['version'] === $report['version'] ) ) {
			return;
		}
		$ids = self::pages_with_map();
		$how = self::clear( $ids );
		if ( '' !== $how ) {
			$report['cache_cleared'] = array(
				'how'   => $how,
				'pages' => count( $ids ),
			);
			update_option( Map_Data::BUILD_OPTION, $report, false );
		}
	}

	/**
	 * Published pages (all languages) whose content has [resekarta …], also inside Avada's
	 * base64-encoded Code Blocks. Cheap: one query, no rendering.
	 *
	 * @return int[]
	 */
	public static function pages_with_map() {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT ID, post_content FROM {$wpdb->posts}
			WHERE post_status = 'publish' AND post_type NOT IN ('revision', 'nav_menu_item', 'attachment')
			AND ( post_content LIKE '%[resekarta%' OR post_content LIKE '%[fusion_code%' )"
		);
		$ids = array();
		foreach ( (array) $rows as $r ) {
			if ( false !== strpos( Transition::decode_code_blocks( $r->post_content ), '[resekarta' ) ) {
				$ids[] = (int) $r->ID;
			}
		}
		return $ids;
	}

	/**
	 * Clear the cache of these pages in the page cache plugins we know. Pages with the map can
	 * be many (one per destination page), so per-page clearing is used where available.
	 *
	 * @param int[] $ids
	 * @return string Which cache was cleared ('' if none was found).
	 */
	public static function clear( array $ids ) {
		$done = array();

		// WP Fastest Cache (used on cyklavandra.se).
		if ( function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
			foreach ( $ids as $id ) {
				wpfc_clear_post_cache_by_id( $id );
			}
			$done[] = 'WP Fastest Cache';
		} elseif ( has_action( 'wpfc_clear_post_cache_by_id' ) ) {
			foreach ( $ids as $id ) {
				do_action( 'wpfc_clear_post_cache_by_id', false, $id );
			}
			$done[] = 'WP Fastest Cache';
		} elseif ( function_exists( 'wpfc_clear_all_cache' ) || has_action( 'wpfc_clear_all_cache' ) ) {
			if ( function_exists( 'wpfc_clear_all_cache' ) ) {
				wpfc_clear_all_cache();
			} else {
				do_action( 'wpfc_clear_all_cache' );
			}
			$done[] = 'WP Fastest Cache (allt)';
		}

		// Other common page caches, in case the site changes.
		if ( function_exists( 'rocket_clean_post' ) ) {
			foreach ( $ids as $id ) {
				rocket_clean_post( $id );
			}
			$done[] = 'WP Rocket';
		}
		if ( has_action( 'litespeed_purge_post' ) ) {
			foreach ( $ids as $id ) {
				do_action( 'litespeed_purge_post', $id );
			}
			$done[] = 'LiteSpeed Cache';
		}
		if ( function_exists( 'w3tc_flush_post' ) ) {
			foreach ( $ids as $id ) {
				w3tc_flush_post( $id );
			}
			$done[] = 'W3 Total Cache';
		}
		if ( function_exists( 'wpsc_delete_post_cache' ) ) {
			foreach ( $ids as $id ) {
				wpsc_delete_post_cache( $id );
			}
			$done[] = 'WP Super Cache';
		}
		do_action( 'cvrk_clear_page_cache', $ids );
		return implode( ', ', $done );
	}
}
