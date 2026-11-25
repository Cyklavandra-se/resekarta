<?php
/**
 * Removes the plugin's data only if "Ta bort all data" is ticked in the settings.
 *
 * @package CV_Resekarta
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cvrk_settings = get_option( 'cvrk_settings', array() );
if ( empty( $cvrk_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Trip fields on pages.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'cvrk\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Destinations (taxonomy terms and their meta).
$cvrk_terms = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'cvrk_destination' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( $cvrk_terms as $cvrk_term_id ) {
	$wpdb->delete( $wpdb->termmeta, array( 'term_id' => $cvrk_term_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $wpdb->term_taxonomy, array( 'term_id' => $cvrk_term_id, 'taxonomy' => 'cvrk_destination' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $wpdb->terms, array( 'term_id' => $cvrk_term_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

foreach ( array( 'cvrk_settings', 'cvrk_rules', 'cvrk_last_scan', 'cvrk_import_report' ) as $cvrk_option ) {
	delete_option( $cvrk_option );
}
