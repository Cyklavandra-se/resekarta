<?php
/**
 * Destination rules: keyword in the trip's Swedish name => destination.
 * The first matching rule wins. Stored in order in one option.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class Rules {

	const OPTION = 'cvrk_rules';

	/**
	 * @return array[] list of array( 'keyword' => string, 'destination' => int term ID )
	 */
	public static function all() {
		$rules = get_option( self::OPTION, array() );
		return is_array( $rules ) ? array_values( $rules ) : array();
	}

	/**
	 * Save rules in the given order. Empty or incomplete rows are dropped.
	 *
	 * @param array[] $rules
	 */
	public static function save( array $rules ) {
		$clean = array();
		foreach ( $rules as $r ) {
			$kw   = isset( $r['keyword'] ) ? sanitize_text_field( $r['keyword'] ) : '';
			$dest = isset( $r['destination'] ) ? (int) $r['destination'] : 0;
			if ( '' !== trim( $kw ) && $dest > 0 ) {
				$clean[] = array(
					'keyword'     => trim( $kw ),
					'destination' => $dest,
				);
			}
		}
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * First rule whose keyword occurs in the name (case-insensitive). Pure function.
	 *
	 * @param string  $name  Trip name (Swedish).
	 * @param array[] $rules Rules in order.
	 * @return array|null The matching rule.
	 */
	public static function match( $name, array $rules ) {
		$name = self::lower( $name );
		foreach ( $rules as $r ) {
			$kw = self::lower( isset( $r['keyword'] ) ? $r['keyword'] : '' );
			if ( '' !== $kw && false !== strpos( $name, $kw ) ) {
				return $r;
			}
		}
		return null;
	}

	private static function lower( $s ) {
		$s = trim( (string) $s );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}
}
