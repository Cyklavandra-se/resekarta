<?php
/**
 * Unit tests for the pure logic. No WordPress needed.
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$cvrk_dir = dirname( __DIR__ ) . '/includes/';
foreach ( array( 'class-languages.php', 'class-tags.php', 'class-destinations.php', 'class-rules.php', 'class-trips.php', 'class-list-parser.php', 'class-csv.php', 'class-settings.php', 'class-descriptions.php', 'class-map-data.php', 'class-map-compare.php', 'class-shortcode.php' ) as $cvrk_file ) {
	require_once $cvrk_dir . $cvrk_file;
}
