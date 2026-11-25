<?php
/**
 * Admin menu "Resekarta" and its form handlers. Everything shown here is in Swedish.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta\Admin;

use CV_Resekarta\Destinations;
use CV_Resekarta\Importer;
use CV_Resekarta\Languages;
use CV_Resekarta\List_Scanner;
use CV_Resekarta\Settings;
use CV_Resekarta\Tags;
use CV_Resekarta\Trips;
use const CV_Resekarta\PLUGIN_DIR;
use const CV_Resekarta\PLUGIN_FILE;
use const CV_Resekarta\VERSION;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const CAP_EDIT     = 'edit_pages';
	const CAP_SETTINGS = 'manage_options';
	const SLUG         = 'resekarta';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_cvrk_save_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_cvrk_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_cvrk_scan', array( __CLASS__, 'handle_scan' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_menu_page( 'Resekarta', 'Resekarta', self::CAP_EDIT, self::SLUG, array( __CLASS__, 'page_overview' ), 'dashicons-location-alt', 58 );
		add_submenu_page( self::SLUG, 'Resekarta – översikt', 'Översikt', self::CAP_EDIT, self::SLUG, array( __CLASS__, 'page_overview' ) );
		add_submenu_page( self::SLUG, 'Resekarta – destinationer', 'Destinationer', self::CAP_EDIT, self::SLUG . '-destinationer', array( __CLASS__, 'page_destinations' ) );
		add_submenu_page( self::SLUG, 'Resekarta – destinationsregler', 'Destinationsregler', self::CAP_EDIT, self::SLUG . '-regler', array( __CLASS__, 'page_rules' ) );
		add_submenu_page( self::SLUG, 'Resekarta – inställningar', 'Inställningar', self::CAP_SETTINGS, self::SLUG . '-installningar', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( self::SLUG, 'Resekarta – import', 'Import', self::CAP_SETTINGS, self::SLUG . '-import', array( __CLASS__, 'page_import' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'cvrk-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Översikt</a>' );
		return $links;
	}

	/** URL of an admin page of the plugin. */
	public static function url( $page = '', array $args = array() ) {
		$slug = '' === $page ? self::SLUG : self::SLUG . '-' . $page;
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}

	// ───────────── Pages ─────────────

	public static function page_overview() {
		self::require_cap( self::CAP_EDIT );
		include PLUGIN_DIR . '/includes/admin/views/overview.php';
	}

	public static function page_destinations() {
		self::require_cap( self::CAP_EDIT );
		include PLUGIN_DIR . '/includes/admin/views/destinations.php';
	}

	public static function page_rules() {
		self::require_cap( self::CAP_EDIT );
		include PLUGIN_DIR . '/includes/admin/views/rules.php';
	}

	public static function page_settings() {
		self::require_cap( self::CAP_SETTINGS );
		include PLUGIN_DIR . '/includes/admin/views/settings.php';
	}

	public static function page_import() {
		self::require_cap( self::CAP_SETTINGS );
		include PLUGIN_DIR . '/includes/admin/views/import.php';
	}

	// ───────────── Form handlers ─────────────

	public static function handle_settings() {
		self::require_cap( self::CAP_SETTINGS );
		check_admin_referer( 'cvrk_save_settings' );
		$in = isset( $_POST['cvrk'] ) && is_array( $_POST['cvrk'] ) ? wp_unslash( $_POST['cvrk'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in Settings::sanitize().
		update_option( Settings::OPTION, Settings::sanitize( $in ), false );
		self::redirect( self::url( 'installningar', array( 'cvrk_msg' => 'saved' ) ) );
	}

	public static function handle_import() {
		self::require_cap( self::CAP_SETTINGS );
		check_admin_referer( 'cvrk_import' );

		$files = Importer::bundled_files();
		$texts = array();
		$own   = array();
		foreach ( $files as $key => $path ) {
			$upload = isset( $_FILES[ 'cvrk_file_' . $key ] ) ? $_FILES[ 'cvrk_file_' . $key ] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( $upload && ! empty( $upload['tmp_name'] ) && UPLOAD_ERR_OK === (int) $upload['error'] && is_uploaded_file( $upload['tmp_name'] ) ) {
				$texts[ $key ] = (string) file_get_contents( $upload['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$own[]         = sanitize_file_name( $upload['name'] );
			} elseif ( ! empty( $_POST[ 'cvrk_use_' . $key ] ) && is_readable( $path ) ) {
				$texts[ $key ] = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		$overwrite = ! empty( $_POST['cvrk_overwrite'] );
		$report    = Importer::run( $texts, $overwrite );
		$report['uploaded'] = $own;

		if ( ! empty( $_POST['cvrk_scan_after'] ) ) {
			$report['scan'] = List_Scanner::run();
		}
		update_option( Importer::REPORT_OPTION, $report, false );
		self::redirect( self::url( 'import', array( 'cvrk_msg' => 'imported' ) ) );
	}

	public static function handle_scan() {
		self::require_cap( self::CAP_EDIT );
		check_admin_referer( 'cvrk_scan' );
		$report = List_Scanner::run();
		self::redirect( self::url( '', array( 'cvrk_msg' => $report['ok'] ? 'scanned' : 'scan_failed' ) ) );
	}

	// ───────────── Helpers for the views ─────────────

	/**
	 * Rows for the overview table.
	 *
	 * @return array[]
	 */
	public static function overview_rows() {
		$langs    = Languages::instance();
		$codes    = $langs->codes();
		$default  = $langs->default_code();
		$dest_all = Destinations::all();
		$rows     = array();
		foreach ( Trips::ids() as $id ) {
			$trip   = Trips::get( $id );
			$dest   = isset( $dest_all[ $trip['destination'] ] ) ? $dest_all[ $trip['destination'] ] : null;
			$status = Trips::map_status( $trip, $dest );
			$slugs  = Trips::tag_slugs( $id );
			$cover  = array();
			foreach ( $codes as $code ) {
				if ( $code === $default ) {
					continue;
				}
				$tid            = $langs->translation_id( $id, $code );
				$cover[ $code ] = $tid && 'publish' === get_post_status( $tid ) ? $tid : 0;
			}
			$rows[] = array(
				'trip'   => $trip,
				'dest'   => $dest,
				'status' => $status,
				'levels' => Tags::levels( $slugs ),
				'langs'  => $cover,
				'action' => Trips::needs_action( $trip, $status ),
			);
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return Destinations::compare_names( $a['trip']['title'], $b['trip']['title'] );
			}
		);
		return $rows;
	}

	public static function notice( $text, $type = 'success' ) {
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), wp_kses_post( $text ) );
	}

	private static function require_cap( $cap ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Du har inte behörighet att göra det här.', 'resekarta' ), 403 );
		}
	}

	private static function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}
}
