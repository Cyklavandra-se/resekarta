<?php
/**
 * Plugin Name:       Cykla & Vandra – Resekarta
 * Description:       Interaktiv karta över Cykla & Vandras resor på alla språk
 * Version:           0.1.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Cykla & Vandra
 * Text Domain:       resekarta
 * License:           GPL-2.0-or-later
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.2';
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
require_once __DIR__ . '/includes/class-plugin.php';

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin/class-admin.php';
}

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

Plugin::boot();
