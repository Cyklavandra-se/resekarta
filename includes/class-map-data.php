<?php
/**
 * Builds the map: one data file per language (resor-{lang}.js with window.TRIPS = […];) and
 * the map page itself (karta.html, from assets/map/karta.html) in uploads/resekarta/.
 * Static files are the fastest way to serve the map on a shared host.
 *
 * Record format (same as the old spreadsheet, see byggKartdata in referens/Kod.gs):
 * id, title, cat, region, url, img, desc, lat, lng, seasons, difficulty, dog, train, wine,
 * weekend, family, tags.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class Map_Data {

	const DIR           = 'resekarta';
	const BUILD_OPTION  = 'cvrk_map_build';
	const SPREAD        = 0.035; // Degrees: trips on exactly the same point are spread on a circle.
	const CATEGORY_SORT = array( 'guidad' => 0, 'cykel' => 1, 'vandring' => 2 );

	/** @var bool */
	private static $scheduled = false;

	public static function boot() {
		add_action( 'save_post_page', array( __CLASS__, 'on_save_page' ), 20, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'cvrk_trips_changed', array( __CLASS__, 'schedule' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'schedule' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_rebuild_after_update' ) );
	}

	// ───────────── When to rebuild ─────────────

	/** Rebuild once, at the end of the request (after AIOSEO, tags etc. have been saved). */
	public static function schedule() {
		if ( self::$scheduled ) {
			return;
		}
		self::$scheduled = true;
		add_action( 'shutdown', array( __CLASS__, 'build_quietly' ) );
	}

	public static function build_quietly() {
		try {
			self::build();
		} catch ( \Throwable $e ) {
			update_option( 'cvrk_map_error', $e->getMessage(), false );
		}
	}

	/** A trip page, or a translation of one, was saved. */
	public static function on_save_page( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$original = Languages::instance()->original_id( $post_id );
		if ( Trips::is_trip( $original ) || Trips::is_trip( $post_id ) ) {
			self::schedule();
		}
	}

	public static function on_delete( $post_id ) {
		if ( 'page' === get_post_type( $post_id ) && Trips::is_trip( Languages::instance()->original_id( $post_id ) ) ) {
			self::schedule();
		}
	}

	/** After a plugin update the map page (and its library versions) must be rewritten. */
	public static function maybe_rebuild_after_update() {
		$build = get_option( self::BUILD_OPTION );
		if ( ! is_array( $build ) || ! isset( $build['plugin_version'] ) || VERSION !== $build['plugin_version'] || ! file_exists( self::dir() . 'karta.html' ) ) {
			if ( Trips::ids() ) {
				self::schedule();
			}
		}
	}

	// ───────────── Paths ─────────────

	public static function dir() {
		$up = wp_upload_dir( null, false );
		return trailingslashit( $up['basedir'] ) . self::DIR . '/';
	}

	public static function url() {
		$up = wp_upload_dir( null, false );
		return self::site_scheme( trailingslashit( $up['baseurl'] ) . self::DIR . '/' );
	}

	/** Map language codes (no, da …) of the active WPML languages, default first. */
	public static function languages() {
		return array_values( array_unique( array_map( array( Languages::class, 'map_code' ), Languages::instance()->codes() ) ) );
	}

	// ───────────── Build ─────────────

	/**
	 * Build all files.
	 *
	 * @return array Report: ok, time, version, languages (code => counts), errors.
	 */
	public static function build() {
		$report = array(
			'ok'             => false,
			'time'           => current_time( 'mysql' ),
			'plugin_version' => VERSION,
			'version'        => '',
			'languages'      => array(),
			'errors'         => array(),
		);
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			$report['errors'][] = 'Kunde inte skapa mappen ' . $dir;
			update_option( self::BUILD_OPTION, $report, false );
			return $report;
		}

		$items   = self::collect();
		$langs   = Languages::instance();
		$codes   = array();
		$hashes  = '';
		foreach ( $langs->codes() as $wpml_code ) {
			$code = Languages::map_code( $wpml_code );
			if ( isset( $report['languages'][ $code ] ) ) {
				continue;
			}
			$records = self::spread( self::records( $items, $wpml_code ) );
			$json    = wp_json_encode( $records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$content = 'window.TRIPS = ' . $json . ';';
			if ( ! self::write( $dir . 'resor-' . $code . '.js', $content ) ) {
				$report['errors'][] = 'Kunde inte skriva resor-' . $code . '.js';
				continue;
			}
			$translated = 0;
			foreach ( $items as $item ) {
				if ( $wpml_code === $langs->default_code() || self::translation( $item['id'], $wpml_code ) ) {
					$translated++;
				}
			}
			$report['languages'][ $code ] = array(
				'trips'      => count( $records ),
				'translated' => $translated,
				'wpml_code'  => $wpml_code,
			);
			$codes[] = $code;
			$hashes .= md5( $content );
		}

		// The version also follows the map page itself, so browsers fetch a changed map after an update.
		$template          = (string) @md5_file( PLUGIN_DIR . '/assets/map/karta.html' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$report['version'] = substr( md5( $hashes . VERSION . Settings::get( 'map_style' ) . $template ), 0, 10 );
		$html              = self::render_html( $codes, $report['version'] );
		if ( '' === $html || ! self::write( $dir . 'karta.html', $html ) ) {
			$report['errors'][] = 'Kunde inte skriva karta.html';
		}
		self::write( $dir . 'index.html', '' ); // No directory listing.

		$report['ok'] = ! $report['errors'];
		update_option( self::BUILD_OPTION, $report, false );
		delete_option( 'cvrk_map_error' );
		do_action( 'cvrk_map_built', $report );
		return $report;
	}

	/**
	 * Everything about the trips on the map that does not depend on the language.
	 *
	 * @return array[]
	 */
	public static function collect() {
		$dests = Destinations::all();
		$items = array();
		foreach ( Trips::ids() as $id ) {
			$trip   = Trips::get( $id );
			$dest   = isset( $dests[ $trip['destination'] ] ) ? $dests[ $trip['destination'] ] : null;
			$status = Trips::map_status( $trip, $dest );
			if ( ! $status['on'] ) {
				continue;
			}
			$point   = Trips::point( $trip, $dest );
			$thumb   = get_post_thumbnail_id( $id );
			$img     = $thumb ? wp_get_attachment_image_url( $thumb, 'large' ) : '';
			$items[] = array(
				'id'    => $id,
				'title' => $trip['title'],
				'cat'   => $trip['category'],
				'dest'  => $dest,
				'lat'   => (float) $point[0],
				'lng'   => (float) $point[1],
				'img'   => $img ? self::site_scheme( (string) $img ) : '',
				'slugs' => Trips::tag_slugs( $id ),
			);
		}
		usort(
			$items,
			function ( $a, $b ) {
				return ( self::CATEGORY_SORT[ $a['cat'] ] - self::CATEGORY_SORT[ $b['cat'] ] )
					?: Destinations::compare_names( $a['title'], $b['title'] );
			}
		);
		return $items;
	}

	/** Published translation ID, or 0. */
	private static function translation( $post_id, $wpml_code ) {
		$tid = Languages::instance()->translation_id( $post_id, $wpml_code );
		return ( $tid && 'publish' === get_post_status( $tid ) ) ? $tid : 0;
	}

	/**
	 * Records for one language. Missing translation: Swedish title, description and link.
	 *
	 * @param array[] $items     From collect().
	 * @param string  $wpml_code WPML language code.
	 * @return array[]
	 */
	public static function records( array $items, $wpml_code ) {
		$default = Languages::instance()->default_code();
		$out     = array();
		foreach ( $items as $item ) {
			$page = $item['id'];
			if ( $wpml_code !== $default ) {
				$tid  = self::translation( $item['id'], $wpml_code );
				$page = $tid ? $tid : $item['id'];
			}
			$title = self::title( $page );
			$out[] = self::record(
				count( $out ) + 1,
				$item,
				array(
					'title'  => '' !== $title ? $title : self::title( $item['id'] ),
					'url'    => self::permalink( $page, $wpml_code ),
					'desc'   => Descriptions::get( $page ),
					'region' => $item['dest'] ? Destinations::name_in( $item['dest'], $wpml_code ) : '',
				)
			);
		}
		return $out;
	}

	/**
	 * The page title as written (without WordPress' typographic changes such as " - " → " – "),
	 * the same text the list pages show.
	 */
	public static function title( $page_id ) {
		return Descriptions::clean( get_post_field( 'post_title', $page_id, 'raw' ) );
	}

	/** One record in the map's format. Pure. */
	public static function record( $id, array $item, array $text ) {
		$flags = Tags::flags( $item['slugs'] );
		return array(
			'id'         => (int) $id,
			'title'      => $text['title'],
			'cat'        => $item['cat'],
			'region'     => '' !== $text['region'] ? $text['region'] : $text['title'],
			'url'        => $text['url'],
			'img'        => $item['img'],
			'desc'       => $text['desc'],
			'lat'        => round( $item['lat'], 6 ),
			'lng'        => round( $item['lng'], 6 ),
			'seasons'    => Tags::seasons( $item['slugs'] ),
			'difficulty' => Tags::levels( $item['slugs'] ),
			'dog'        => $flags['dog'],
			'train'      => $flags['train'],
			'wine'       => $flags['wine'],
			'weekend'    => $flags['weekend'],
			'family'     => $flags['family'],
			'tags'       => array_values( $item['slugs'] ),
		);
	}

	/** Permalink in the page's own language (WPML may otherwise use the admin's language). */
	private static function permalink( $page_id, $wpml_code ) {
		do_action( 'wpml_switch_language', $wpml_code );
		$url = get_permalink( $page_id );
		do_action( 'wpml_switch_language', null );
		return (string) $url;
	}

	/**
	 * Spread trips on exactly the same point on a small circle, so the markers do not cover
	 * each other. Same formula as byggKartdata in the old script. Pure.
	 *
	 * @param array[] $records
	 * @return array[]
	 */
	public static function spread( array $records ) {
		$groups = array();
		foreach ( $records as $i => $r ) {
			$groups[ sprintf( '%.5f,%.5f', $r['lat'], $r['lng'] ) ][] = $i;
		}
		foreach ( $groups as $members ) {
			$n = count( $members );
			if ( $n < 2 ) {
				continue;
			}
			foreach ( $members as $k => $i ) {
				$v                     = 2 * M_PI * $k / $n;
				$records[ $i ]['lat'] = round( $records[ $i ]['lat'] - self::SPREAD * cos( $v ), 6 );
				$records[ $i ]['lng'] = round( $records[ $i ]['lng'] + self::SPREAD * sin( $v ), 6 );
			}
		}
		return $records;
	}

	/** The map page with library paths, languages, default style and version filled in. */
	public static function render_html( array $codes, $version ) {
		$template = (string) file_get_contents( PLUGIN_DIR . '/assets/map/karta.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( '' === $template ) {
			return '';
		}
		$up     = wp_upload_dir( null, false );
		$vendor = self::vendor_url( plugins_url( 'assets/map/vendor/', PLUGIN_FILE ), $up['baseurl'], (string) get_option( 'home' ) );
		return strtr(
			$template,
			array(
				'{{VENDOR}}'  => esc_url( $vendor ),
				'{{SPRAK}}'   => wp_json_encode( array_values( $codes ) ),
				'{{STIL}}'    => preg_replace( '/[^a-z]/', '', (string) Settings::get( 'map_style' ) ),
				'{{VERSION}}' => preg_replace( '/[^a-z0-9]/', '', (string) $version ),
			)
		);
	}

	/**
	 * The libraries' address as written into karta.html. Pure.
	 *
	 * The map is often rebuilt by WP-Cron, which calls the site over http – so the scheme of
	 * the current request must not be used (an http:// script on an https page is blocked and
	 * the map stays empty). When the map file and the plugin are on the same host, a
	 * root-relative path is used; otherwise the site's own scheme.
	 */
	public static function vendor_url( $plugin_url, $uploads_url, $home ) {
		$p = wp_parse_url( $plugin_url );
		$u = wp_parse_url( $uploads_url );
		if ( ! empty( $p['host'] ) && ! empty( $u['host'] ) && strtolower( $p['host'] ) === strtolower( $u['host'] ) ) {
			return ( isset( $p['path'] ) ? $p['path'] : '/' );
		}
		return self::site_scheme( $plugin_url, $home );
	}

	/** Use the scheme of the site's address (Inställningar → Allmänt), not of the current request. */
	public static function site_scheme( $url, $home = null ) {
		$scheme = wp_parse_url( null === $home ? (string) get_option( 'home' ) : $home, PHP_URL_SCHEME );
		return preg_replace( '~^https?:~i', ( 'https' === $scheme ? 'https' : 'http' ) . ':', (string) $url );
	}

	/** Write atomically (temp file + rename), so visitors never get half a file. */
	private static function write( $file, $content ) {
		$tmp = $file . '.tmp';
		if ( false === file_put_contents( $tmp, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false;
		}
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}
		return true;
	}

	/** Latest build report, or null. */
	public static function last_build() {
		$b = get_option( self::BUILD_OPTION );
		return is_array( $b ) ? $b : null;
	}

	/** Current version string for the iframe address. */
	public static function version() {
		$b = self::last_build();
		return $b && ! empty( $b['version'] ) ? $b['version'] : VERSION;
	}

	/**
	 * Read a data file (new or old) back as an array of records, or null.
	 */
	public static function read_file( $file ) {
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$text = trim( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$text = preg_replace( '/^window\.TRIPS\s*=\s*/', '', $text );
		$text = preg_replace( '/;\s*$/', '', $text );
		$data = json_decode( $text, true );
		return is_array( $data ) ? $data : null;
	}
}
