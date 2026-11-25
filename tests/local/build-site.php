<?php
/**
 * Builds a local copy of cyklavandra.se's page structure for testing Resekarta.
 *
 * Usage: php build-site.php /path/to/wordpress /path/to/live-snapshot
 * The snapshot folder holds pages-sv.json, pages-no.json and tags.json from the public REST API
 * (see README in this folder). Pages get the same paths, titles, excerpts and tags as on the
 * live site; Norwegian pages are linked to the Swedish ones via the spreadsheet's "Norsk URL".
 */

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php build-site.php WP_DIR SNAPSHOT_DIR\n" );
	exit( 1 );
}
$wp_dir   = rtrim( $argv[1], '/' );
$snap_dir = rtrim( $argv[2], '/' );
$repo     = dirname( __DIR__, 2 );

define( 'WP_INSTALLING', ! file_exists( $wp_dir . '/.installed' ) );
$_SERVER['HTTP_HOST']   = 'localhost:8890';
$_SERVER['REQUEST_URI'] = '/';
require $wp_dir . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! is_blog_installed() ) {
	wp_install( 'Cykla & Vandra (test)', 'admin', 'admin@example.com', true, '', 'admin' );
	touch( $wp_dir . '/.installed' );
	echo "WordPress installed (admin / admin)\n";
}
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'timezone_string', 'Europe/Madrid' );
update_option( 'blogdescription', 'Lokal testkopia' );

// mu-plugins.
@mkdir( WP_CONTENT_DIR . '/mu-plugins' );
foreach ( array( 'fake-wpml.php', 'cvrk-test-fixtures.php' ) as $f ) {
	@unlink( WP_CONTENT_DIR . '/mu-plugins/' . $f );
	symlink( __DIR__ . '/' . $f, WP_CONTENT_DIR . '/mu-plugins/' . $f );
}
register_taxonomy_for_object_type( 'post_tag', 'page' );

$json = function ( $name ) use ( $snap_dir ) {
	return json_decode( file_get_contents( $snap_dir . '/' . $name ), true );
};

// Tags.
$tag_slug = array();
foreach ( $json( 'tags.json' ) as $t ) {
	$tag_slug[ $t['id'] ] = rawurldecode( $t['slug'] );
	if ( ! term_exists( $t['slug'], 'post_tag' ) ) {
		wp_insert_term( html_entity_decode( $t['name'] ), 'post_tag', array( 'slug' => $t['slug'] ) );
	}
}

// Existing pages by live id, so the script can be run again.
$existing = array();
foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_live_id', 'fields' => 'ids' ) ) as $id ) {
	$existing[ get_post_meta( $id, '_fake_lang', true ) . ':' . get_post_meta( $id, '_live_id', true ) ] = $id;
}

$create = function ( array $pages, $lang, $root_parent ) use ( &$existing, $tag_slug ) {
	$by_id = array();
	foreach ( $pages as $p ) {
		$by_id[ $p['id'] ] = $p;
	}
	$made = 0;
	$make = function ( $p ) use ( &$make, &$existing, $by_id, $lang, $root_parent, $tag_slug, &$made ) {
		$key = $lang . ':' . $p['id'];
		if ( isset( $existing[ $key ] ) ) {
			return $existing[ $key ];
		}
		$parent = $root_parent;
		if ( $p['parent'] && isset( $by_id[ $p['parent'] ] ) ) {
			$parent = $make( $by_id[ $p['parent'] ] );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => html_entity_decode( $p['title']['rendered'], ENT_QUOTES, 'UTF-8' ),
				'post_name'    => $p['slug'],
				'post_parent'  => $parent,
				'post_excerpt' => trim( wp_strip_all_tags( $p['excerpt']['rendered'] ) ),
				'post_content' => '<p>' . esc_html( trim( wp_strip_all_tags( $p['excerpt']['rendered'] ) ) ) . '</p>',
				'menu_order'   => (int) $p['menu_order'],
			)
		);
		update_post_meta( $id, '_live_id', $p['id'] );
		update_post_meta( $id, '_fake_lang', $lang );
		update_post_meta( $id, '_fake_trid', 'sv' === $lang ? 'p' . $id : 'x' . $id );
		if ( ! empty( $p['aioseo_head_json']['description'] ) ) {
			update_post_meta( $id, '_test_seo_description', html_entity_decode( $p['aioseo_head_json']['description'], ENT_QUOTES, 'UTF-8' ) );
		}
		$slugs = array();
		foreach ( (array) $p['tags'] as $tid ) {
			if ( isset( $tag_slug[ $tid ] ) ) {
				$slugs[] = $tag_slug[ $tid ];
			}
		}
		if ( $slugs ) {
			wp_set_post_terms( $id, $slugs, 'post_tag' );
		}
		$existing[ $key ] = $id;
		$made++;
		return $id;
	};
	foreach ( $pages as $p ) {
		$make( $p );
	}
	return $made;
};

echo 'Swedish pages created: ' . $create( $json( 'pages-sv.json' ), 'sv', 0 ) . "\n";

// Norwegian pages live under /no/ – a language root page stands in for WPML's prefix.
$no_root = get_page_by_path( 'no' );
if ( ! $no_root ) {
	$no_root_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Norsk', 'post_name' => 'no' ) );
	update_post_meta( $no_root_id, '_fake_lang', 'no' );
} else {
	$no_root_id = $no_root->ID;
}
echo 'Norwegian pages created: ' . $create( $json( 'pages-no.json' ), 'no', $no_root_id ) . "\n";
flush_rewrite_rules();

// Link translations using the spreadsheet (Swedish URL => Norwegian URL).
$rows   = array_map( 'str_getcsv', file( $repo . '/data/resor.csv' ) );
$header = array_shift( $rows );
$iu     = array_search( 'URL', $header, true );
$in     = array_search( 'Norsk URL', $header, true );
$linked = 0;
$local  = function ( $url ) {
	$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
	$page = $path ? get_page_by_path( $path ) : null;
	return $page ? $page->ID : 0;
};
foreach ( $rows as $r ) {
	if ( empty( $r[ $in ] ) || ! preg_match( '~^https?://~', $r[ $in ] ) ) {
		continue;
	}
	$sv = $local( $r[ $iu ] );
	$no = $local( $r[ $in ] );
	if ( $sv && $no ) {
		update_post_meta( $no, '_fake_trid', get_post_meta( $sv, '_fake_trid', true ) );
		$linked++;
	}
}
echo "Norwegian translations linked: $linked\n";

// A few Danish, Finnish and English translations, so the language columns have something to show.
$extra = array(
	'da' => array( 'vandra/guidade/andorra' => 'Andorra og de østlige Pyrenæer', 'cykla/cykla-pa-egen-hand/rioja' => 'Cykling i Rioja' ),
	'fi' => array( 'vandra/guidade/andorra' => 'Andorra ja itäiset Pyreneet' ),
	'en' => array( 'vandra/guidade/andorra' => 'Andorra and the eastern Pyrenees', 'cykla/cykla-pa-egen-hand/rioja' => 'Cycling in Rioja', 'vandra/pa-egen-hand/andorra' => 'Self-guided walking in Andorra' ),
);
foreach ( $extra as $lang => $list ) {
	$root = get_page_by_path( $lang );
	$root_id = $root ? $root->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $lang, 'post_name' => $lang ) );
	update_post_meta( $root_id, '_fake_lang', $lang );
	foreach ( $list as $path => $title ) {
		$sv = get_page_by_path( $path );
		if ( ! $sv || apply_filters( 'wpml_object_id', $sv->ID, 'page', false, $lang ) ) {
			continue;
		}
		$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => sanitize_title( $title ), 'post_parent' => $root_id, 'post_excerpt' => $title . ' – test.' ) );
		update_post_meta( $id, '_fake_lang', $lang );
		update_post_meta( $id, '_fake_trid', get_post_meta( $sv->ID, '_fake_trid', true ) );
	}
}

if ( ! is_plugin_active( 'resekarta/resekarta.php' ) ) {
	$res = activate_plugin( 'resekarta/resekarta.php' );
	echo is_wp_error( $res ) ? 'Activation failed: ' . $res->get_error_message() . "\n" : "Resekarta activated\n";
}
echo "Done.\n";
