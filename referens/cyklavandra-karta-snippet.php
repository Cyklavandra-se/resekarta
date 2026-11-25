<?php
/**
 * Cykla & Vandra – tar emot kartdata från kalkylarket.
 *
 * Kalkylarkets skript skickar resorna hit, och de sparas som
 *   /wp-content/uploads/karta/resor-sv.js
 *   /wp-content/uploads/karta/resor-no.js
 * som kartan (reskarta.html) läser. Besökarna hämtar då ingenting från Google.
 *
 * Kalkylarket skickar med en hemlig nyckel (samma som nedan). Ingen WordPress-inloggning behövs.
 * Endast de två filnamnen ovan kan skrivas, och innehållet sparas alltid som ren
 * JSON-data – nyckeln ger ingen annan åtkomst till sajten.
 *
 * Vill du byta nyckel: ändra den här OCH i kalkylarket
 * (menyn Cyklavandra → Ställ in nyckel för kartan).
 */
define( 'CYKLAVANDRA_KARTA_NYCKEL', 'HEMLIG-NYCKEL-BORTTAGEN' );

add_action( 'rest_api_init', function () {
	register_rest_route( 'cyklavandra/v1', '/karta', array(
		'methods'             => 'POST',
		'permission_callback' => function ( WP_REST_Request $req ) {
			$nyckel = (string) ( $req->get_header( 'x_karta_nyckel' ) ?: $req->get_param( 'nyckel' ) );
			return '' !== $nyckel && hash_equals( CYKLAVANDRA_KARTA_NYCKEL, $nyckel );
		},
		'callback'            => 'cyklavandra_spara_kartdata',
	) );
} );

function cyklavandra_spara_kartdata( WP_REST_Request $req ) {
	$lang = $req->get_param( 'lang' );
	if ( ! in_array( $lang, array( 'sv', 'no' ), true ) ) {
		return new WP_Error( 'fel_sprak', 'Okänt språk.', array( 'status' => 400 ) );
	}

	$data = $req->get_param( 'data' );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'fel_data', 'Data saknas eller är inte en lista.', array( 'status' => 400 ) );
	}

	$mapp = trailingslashit( wp_upload_dir()['basedir'] ) . 'karta';
	if ( ! wp_mkdir_p( $mapp ) ) {
		return new WP_Error( 'ingen_mapp', 'Kunde inte skapa mappen uploads/karta.', array( 'status' => 500 ) );
	}

	$json = wp_json_encode( array_values( $data ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	$fil  = $mapp . '/resor-' . $lang . '.js';
	$tmp  = $fil . '.tmp';

	if ( false === file_put_contents( $tmp, 'window.TRIPS = ' . $json . ';' ) || ! rename( $tmp, $fil ) ) {
		return new WP_Error( 'kunde_inte_spara', 'Kunde inte skriva filen.', array( 'status' => 500 ) );
	}

	return array(
		'ok'    => true,
		'fil'   => trailingslashit( wp_upload_dir()['baseurl'] ) . 'karta/resor-' . $lang . '.js',
		'resor' => count( $data ),
	);
}

/**
 * Kortkod för att visa kartan på en sida:
 *
 *   [resekarta]
 *   [resekarta tag="donau"]
 *   [resekarta tag="vandra+spanien+sjalvguidad" filters="no"]
 *   [resekarta tag="donau,rhen" hojd="600"]
 *   [resekarta kat="guidad" arstid="var" niva="2"]
 *
 *   tag      WordPress-taggarnas kortnamn. "+" = resan måste ha ALLA taggarna,
 *            "," = det räcker med NÅGON av dem.
 *   filters  "no" döljer filterraden.
 *   kat      förvald kategori: guidad, cykel eller vandring (flera: "guidad,cykel").
 *   arstid   förvald årstid: var, sommar, host eller vinter.
 *   niva     förvald nivå: 1, 2, 3 eller 4 (WordPress-taggarna hike1–4 / bike1–4).
 *            (Förvalen kan besökaren ändra i filtren. Å, ä och ö går också bra.)
 *   hojd     högsta höjd i pixlar (standard 750). Kartan är 80 % av fönstrets höjd,
 *            minst 480 px.
 *
 * Kortkoden översätts inte av WPML, så samma kod fungerar på den norska sidan – kartan
 * väljer språk själv. Versionsnumret sätts automatiskt efter när reskarta.html laddades upp.
 */
add_shortcode( 'resekarta', function ( $atts ) {
	$a = shortcode_atts( array( 'tag' => '', 'filters' => '', 'hojd' => '750', 'kat' => '', 'arstid' => '', 'niva' => '' ), $atts, 'resekarta' );

	$up  = wp_upload_dir();
	$fil = trailingslashit( $up['basedir'] ) . 'karta/reskarta.html';
	$q   = array( 'v=' . ( file_exists( $fil ) ? filemtime( $fil ) : '1' ) );

	foreach ( preg_split( '/\s*\+\s*/', trim( (string) $a['tag'] ) ) as $grupp ) {
		$grupp = strtolower( preg_replace( '/\s*,\s*/', ',', trim( $grupp ) ) );
		if ( '' !== $grupp ) {
			$q[] = 'tag=' . rawurlencode( $grupp );
		}
	}
	if ( in_array( strtolower( trim( (string) $a['filters'] ) ), array( 'no', 'nej', '0', 'false', 'off' ), true ) ) {
		$q[] = 'filters=no';
	}
	foreach ( array( 'kat', 'arstid', 'niva' ) as $namn ) {
		$v = trim( (string) $a[ $namn ] );
		if ( '' !== $v ) {
			$q[] = $namn . '=' . rawurlencode( $v );
		}
	}

	$src   = trailingslashit( $up['baseurl'] ) . 'karta/reskarta.html?' . implode( '&', $q );
	$hojd  = max( 300, min( 2000, intval( $a['hojd'] ) ) );
	$norsk = 'no' === apply_filters( 'wpml_current_language', null );
	$titel = $norsk ? 'Kart over turene våre' : 'Karta över våra resor';

	return sprintf(
		'<iframe src="%s" title="%s" loading="lazy" style="display:block;width:100%%;height:80vh;min-height:480px;max-height:%dpx;border:0;"></iframe>',
		esc_url( $src ),
		esc_attr( $titel ),
		$hojd
	);
} );
