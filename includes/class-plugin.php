<?php
/**
 * Bootstrap: registers hooks for all parts of the plugin.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot() {
		add_action( 'init', array( Destinations::class, 'register' ) );
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		Map_Data::boot();
		Shortcode::boot();
		Automation::boot();
		Page_Cache::boot();

		if ( is_admin() ) {
			Admin\Admin::boot();
		}
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'resekarta', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
	}

	public static function activate() {
		Destinations::register();
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
		if ( false === get_option( Rules::OPTION ) ) {
			add_option( Rules::OPTION, array(), '', false );
		}
		Automation::ensure_schedule();
	}

	public static function deactivate() {
		Automation::unschedule();
	}
}
