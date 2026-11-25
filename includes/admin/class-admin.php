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
use CV_Resekarta\Map_Data;
use CV_Resekarta\Shortcode;
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

	/** Sub pages (slug suffix => tab label, capability). The first is the menu row. */
	const PAGES = array(
		''              => array( 'Översikt', self::CAP_EDIT ),
		'karta'         => array( 'Karta', self::CAP_EDIT ),
		'destinationer' => array( 'Destinationer', self::CAP_EDIT ),
		'regler'        => array( 'Destinationsregler', self::CAP_EDIT ),
		'installningar' => array( 'Inställningar', self::CAP_SETTINGS ),
		'import'        => array( 'Import', self::CAP_SETTINGS ),
	);

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_head', array( __CLASS__, 'hide_menu_items' ) );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
		add_filter( 'cyklavandra_verktyg', array( __CLASS__, 'tool_card' ) );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer_text' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_cvrk_save_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_cvrk_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_cvrk_scan', array( __CLASS__, 'handle_scan' ) );
		add_action( 'admin_post_cvrk_rebuild', array( __CLASS__, 'handle_rebuild' ) );
		add_action( 'admin_notices', array( __CLASS__, 'shortcode_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * One row, "Resekarta", under the shared menu Cykla&Vandra. The other pages are
	 * reached through the tabs at the top of each page (same pattern as Kanalpublicering).
	 */
	public static function menu() {
		$callbacks = array(
			''              => 'page_overview',
			'karta'         => 'page_map',
			'destinationer' => 'page_destinations',
			'regler'        => 'page_rules',
			'installningar' => 'page_settings',
			'import'        => 'page_import',
		);
		foreach ( self::PAGES as $key => $page ) {
			add_submenu_page(
				\Cyklavandra_Meny::SLUG,
				'Resekarta – ' . mb_strtolower( $page[0] ),
				'' === $key ? 'Resekarta' : $page[0],
				$page[1],
				self::page_slug( $key ),
				array( __CLASS__, $callbacks[ $key ] )
			);
		}
	}

	/**
	 * Remove the tab pages from the menu. Done in admin_head: WordPress checks access against
	 * the menu before that, and a page removed earlier gives "Du har inte behörighet".
	 */
	public static function hide_menu_items() {
		foreach ( array_keys( self::PAGES ) as $key ) {
			if ( '' !== $key ) {
				remove_submenu_page( \Cyklavandra_Meny::SLUG, self::page_slug( $key ) );
			}
		}
	}

	/** Current plugin page key ('' = overview), or null when not on one of our pages. */
	private static function current_key() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		foreach ( array_keys( self::PAGES ) as $key ) {
			if ( self::page_slug( $key ) === $page ) {
				return $key;
			}
		}
		return null;
	}

	/** Keep Cykla&Vandra → Resekarta highlighted on the tab pages. */
	public static function parent_file( $parent_file ) {
		return null !== self::current_key() ? \Cyklavandra_Meny::SLUG : $parent_file;
	}

	public static function submenu_file( $submenu_file ) {
		return null !== self::current_key() ? self::SLUG : $submenu_file;
	}

	/** The tabs at the top of every page. */
	public static function tabs() {
		$active = self::current_key();
		echo '<nav class="nav-tab-wrapper cvrk-tabs" aria-label="Resekarta">';
		foreach ( self::PAGES as $key => $page ) {
			if ( ! current_user_can( $page[1] ) ) {
				continue;
			}
			printf(
				'<a href="%s" class="nav-tab%s"%s>%s</a>',
				esc_url( self::url( $key ) ),
				$key === $active ? ' nav-tab-active' : '',
				$key === $active ? ' aria-current="page"' : '',
				esc_html( $page[0] )
			);
		}
		echo '</nav>';
	}

	/** Card on the overview page Cykla&Vandra. */
	public static function tool_card( $tools ) {
		$tools[] = array(
			'titel'       => 'Resekarta',
			'beskrivning' => 'Interaktiv karta över resorna på alla språk: destinationer, regler och import från kalkylarket.',
			'url'         => self::url(),
			'version'     => VERSION,
			'ikon'        => plugins_url( 'assets/icon.svg', PLUGIN_FILE ),
		);
		return $tools;
	}

	public static function footer_text( $text ) {
		if ( null === self::current_key() ) {
			return $text;
		}
		return sprintf( 'Cykla &amp; Vandra – Resekarta, version %s', esc_html( VERSION ) );
	}

	private static function page_slug( $key ) {
		return '' === $key ? self::SLUG : self::SLUG . '-' . $key;
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
		return add_query_arg( array_merge( array( 'page' => self::page_slug( $page ) ), $args ), admin_url( 'admin.php' ) );
	}

	// ───────────── Pages ─────────────

	public static function page_overview() {
		self::require_cap( self::CAP_EDIT );
		include PLUGIN_DIR . '/includes/admin/views/overview.php';
	}

	public static function page_map() {
		self::require_cap( self::CAP_EDIT );
		include PLUGIN_DIR . '/includes/admin/views/map.php';
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

	public static function handle_rebuild() {
		self::require_cap( self::CAP_EDIT );
		check_admin_referer( 'cvrk_rebuild' );
		$report = Map_Data::build();
		$back   = isset( $_POST['cvrk_back'] ) && 'karta' === $_POST['cvrk_back'] ? 'karta' : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		self::redirect( self::url( $back, array( 'cvrk_msg' => $report['ok'] ? 'rebuilt' : 'rebuild_failed' ) ) );
	}

	/**
	 * Warn when [resekarta] is registered by something else (the old snippet) while the plugin's
	 * own shortcode is switched on – then both are active and the plugin's wins.
	 */
	public static function shortcode_notice() {
		if ( ! current_user_can( self::CAP_SETTINGS ) || ! Shortcode::enabled() || ! Shortcode::other_owner() ) {
			return;
		}
		self::notice(
			sprintf(
				'<strong>Resekarta:</strong> kortkoden [resekarta] registreras också av %s. Tillägget använder sin egen, men avaktivera det gamla kodstycket (det med <code>CYKLAVANDRA_KARTA_NYCKEL</code>) i Code Snippets så att de inte krockar.',
				esc_html( Shortcode::other_owner() )
			),
			'warning'
		);
	}

	/** "Bygg om kartan nu" button. */
	public static function rebuild_button( $back = '' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cvrk-inline">
			<?php wp_nonce_field( 'cvrk_rebuild' ); ?>
			<input type="hidden" name="action" value="cvrk_rebuild">
			<input type="hidden" name="cvrk_back" value="<?php echo esc_attr( $back ); ?>">
			<button class="button button-primary">Bygg om kartan nu</button>
		</form>
		<?php
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
