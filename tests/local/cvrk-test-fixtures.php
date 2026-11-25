<?php
/**
 * Plugin Name: Resekarta test fixtures (test only)
 * Description: Serves saved copies of the live list pages and redirects, so scans work offline.
 */

add_filter( 'cvrk_pre_fetch_list_page', function ( $pre, $url ) {
	$dir = getenv( 'CVRK_FIXTURES' ) ? getenv( 'CVRK_FIXTURES' ) : '/tmp/claude-0/live/lists';
	$p   = wp_parse_url( $url );
	parse_str( isset( $p['query'] ) ? $p['query'] : '', $q );
	$file = $dir . '/' . str_replace( '/', '_', trim( $p['path'], '/' ) ) . '_p' . ( isset( $q['_page'] ) ? (int) $q['_page'] : 1 ) . '.html';
	if ( ! is_readable( $file ) ) {
		return null;
	}
	return str_replace( 'https://cyklavandra.se', untrailingslashit( get_option( 'home' ) ), file_get_contents( $file ) );
}, 10, 2 );

add_filter( 'cvrk_pre_redirect_target', function ( $pre, $url ) {
	$map = array( '/vandra/guidade/vi-rioja-baskien/' => '/vandra/guidade/rioja-baskien/' );
	$path = wp_parse_url( $url, PHP_URL_PATH );
	return isset( $map[ $path ] ) ? home_url( $map[ $path ] ) : '';
}, 10, 2 );
