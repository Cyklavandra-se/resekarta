<?php
/**
 * The shortcode [resekarta] – same name and attributes as the old Code Snippets snippet:
 *
 *   [resekarta]
 *   [resekarta tag="donau"]
 *   [resekarta tag="vandra+spanien+sjalvguidad" filters="no"]
 *   [resekarta tag="donau,rhen" hojd="600"]
 *   [resekarta kat="guidad" arstid="var" niva="2"]
 *
 *   tag      WordPress tag slugs. "+" = the trip must have ALL of them, "," = ANY of them.
 *   filters  "no" hides the filter row.
 *   kat      preselected category: guidad, cykel or vandring (several: "guidad,cykel").
 *   arstid   preselected season: var, sommar, host or vinter.
 *   niva     preselected level: 1–4 (tags hike1–4 / bike1–4).
 *   hojd     max height in pixels (default 750). The map is 80 % of the window, at least 480 px.
 *
 * The plugin's [resekarta] is only registered when switched on in the settings, so that it can
 * be installed while the snippet still serves the live pages. [resekarta_test] always works,
 * with the same attributes, for trying the new map on a draft page.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	const TAG      = 'resekarta';
	const TEST_TAG = 'resekarta_test';

	/** iframe title per language; other languages use English. */
	const TITLES = array(
		'sv' => 'Karta över våra resor',
		'no' => 'Kart over turene våre',
		'da' => 'Kort over vores rejser',
		'fi' => 'Kartta matkoistamme',
		'en' => 'Map of our tours',
	);

	/** @var string|null Callback that held [resekarta] before us, if any (e.g. the snippet). */
	private static $other = null;

	public static function boot() {
		// Code Snippets runs its snippets before init; register after them.
		add_action( 'init', array( __CLASS__, 'register' ), 20 );
	}

	public static function enabled() {
		return (bool) Settings::get( 'shortcode_enabled' );
	}

	public static function register() {
		global $shortcode_tags;
		if ( isset( $shortcode_tags[ self::TAG ] ) && ! self::is_ours( $shortcode_tags[ self::TAG ] ) ) {
			self::$other = self::describe( $shortcode_tags[ self::TAG ] );
		}
		if ( self::enabled() ) {
			add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
		}
		add_shortcode( self::TEST_TAG, array( __CLASS__, 'render' ) );
	}

	/** Something other than this plugin registers [resekarta] (description), or null. */
	public static function other_owner() {
		return self::$other;
	}

	private static function is_ours( $cb ) {
		return is_array( $cb ) && isset( $cb[0] ) && __CLASS__ === $cb[0];
	}

	private static function describe( $cb ) {
		if ( $cb instanceof \Closure ) {
			try {
				$ref  = new \ReflectionFunction( $cb );
				$file = (string) $ref->getFileName();
				if ( false !== stripos( $file, 'code-snippets' ) || false !== stripos( $file, 'eval()' ) ) {
					return 'ett kodstycke i Code Snippets';
				}
				return 'koden i ' . basename( $file );
			} catch ( \ReflectionException $e ) {
				return 'en annan del av sajten';
			}
		}
		if ( is_string( $cb ) ) {
			return 'funktionen ' . $cb;
		}
		return 'en annan del av sajten';
	}

	/**
	 * Build the iframe.
	 *
	 * @param array|string $atts
	 * @return string
	 */
	public static function render( $atts ) {
		$a = shortcode_atts(
			array(
				'tag'     => '',
				'filters' => '',
				'hojd'    => '750',
				'kat'     => '',
				'arstid'  => '',
				'niva'    => '',
			),
			$atts,
			self::TAG
		);

		if ( ! file_exists( Map_Data::dir() . 'karta.html' ) ) {
			Map_Data::build(); // First view after installation.
		}

		$lang = Languages::map_code( Languages::instance()->current_code() );
		$src  = Map_Data::url() . 'karta.html?' . self::query( $a, $lang, Map_Data::languages(), Map_Data::version() );
		$hojd = max( 300, min( 2000, intval( $a['hojd'] ) ) );

		// On phones the map gets more room (the filters wrap below it); printed once per page.
		static $style_done = false;
		$style = '';
		if ( ! $style_done ) {
			$style_done = true;
			$style      = '<style>@media (max-width:640px){iframe.cvrk-karta{height:85vh!important;min-height:560px!important;max-height:none!important}}</style>';
		}

		return $style . sprintf(
			'<iframe class="cvrk-karta" src="%s" title="%s" loading="lazy" style="display:block;width:100%%;height:80vh;min-height:480px;max-height:%dpx;border:0;"></iframe>',
			esc_url( $src ),
			esc_attr( isset( self::TITLES[ $lang ] ) ? self::TITLES[ $lang ] : self::TITLES['en'] ),
			$hojd
		);
	}

	/**
	 * Query string for the map. Pure.
	 *
	 * @param array    $a       Shortcode attributes.
	 * @param string   $lang    Map language of the page.
	 * @param string[] $langs   Languages that have map data.
	 * @param string   $version Data version (changes when the map is rebuilt).
	 */
	public static function query( array $a, $lang, array $langs, $version ) {
		$q = array( 'v=' . rawurlencode( (string) $version ) );
		if ( in_array( $lang, $langs, true ) ) {
			$q[] = 'lang=' . rawurlencode( $lang );
		}
		foreach ( preg_split( '/\s*\+\s*/', trim( (string) $a['tag'] ) ) as $group ) {
			$group = preg_replace( '/\s*,\s*/', ',', trim( $group ) );
			$group = function_exists( 'mb_strtolower' ) ? mb_strtolower( $group, 'UTF-8' ) : strtolower( $group );
			if ( '' !== $group ) {
				$q[] = 'tag=' . rawurlencode( $group );
			}
		}
		if ( in_array( strtolower( trim( (string) $a['filters'] ) ), array( 'no', 'nej', '0', 'false', 'off' ), true ) ) {
			$q[] = 'filters=no';
		}
		foreach ( array( 'kat', 'arstid', 'niva' ) as $name ) {
			$v = trim( (string) $a[ $name ] );
			if ( '' !== $v ) {
				$q[] = $name . '=' . rawurlencode( $v );
			}
		}
		return implode( '&', $q );
	}
}
