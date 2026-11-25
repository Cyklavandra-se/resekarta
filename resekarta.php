<?php
/**
 * Plugin Name:       Cykla & Vandra – Resekarta
 * Description:       Interaktiv karta över Cykla & Vandras resor på alla språk
 * Version:           0.4.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Cykla & Vandra
 * Text Domain:       resekarta
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/Cyklavandra-se/resekarta
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

// Skydd om tillägget råkar finnas installerat två gånger (t.ex. "Source code"-zip från GitHub).
if ( defined( __NAMESPACE__ . '\\VERSION' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p><strong>Resekarta</strong> är installerad två gånger. Avaktivera och radera den ena kopian under Tillägg.</p></div>';
		}
	);
	return;
}

const VERSION     = '0.4.0';
const PLUGIN_FILE = __FILE__;
const PLUGIN_DIR  = __DIR__;

require_once __DIR__ . '/cyklavandra-meny.php'; // Gemensam meny Cykla&Vandra, samma fil i alla våra tillägg.
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-languages.php';
require_once __DIR__ . '/includes/class-tags.php';
require_once __DIR__ . '/includes/class-destinations.php';
require_once __DIR__ . '/includes/class-rules.php';
require_once __DIR__ . '/includes/class-trips.php';
require_once __DIR__ . '/includes/class-list-parser.php';
require_once __DIR__ . '/includes/class-list-scanner.php';
require_once __DIR__ . '/includes/class-csv.php';
require_once __DIR__ . '/includes/class-importer.php';
require_once __DIR__ . '/includes/class-descriptions.php';
require_once __DIR__ . '/includes/class-map-data.php';
require_once __DIR__ . '/includes/class-map-compare.php';
require_once __DIR__ . '/includes/class-shortcode.php';
require_once __DIR__ . '/includes/class-day-program.php';
require_once __DIR__ . '/includes/class-geocoder.php';
require_once __DIR__ . '/includes/class-automation.php';
require_once __DIR__ . '/includes/class-plugin.php';
require_once __DIR__ . '/includes/updater.php'; // Uppdateringar via GitHub-releaser, som de andra egna tilläggen.

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin/class-admin.php';
	require_once __DIR__ . '/includes/admin/class-trip-box.php';
}

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

Plugin::boot();
