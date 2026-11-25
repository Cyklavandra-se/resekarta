<?php
/**
 * Minimal CSV reader for the spreadsheet exports. Pure: no WordPress calls.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class Csv {

	/**
	 * Parse CSV text into rows keyed by (normalized) header.
	 * Handles a UTF-8 BOM, Windows line endings and comma or semicolon as separator.
	 *
	 * @param string $text
	 * @return array[] Each row: normalized header => trimmed value.
	 */
	public static function parse( $text ) {
		$text = (string) $text;
		if ( 0 === strpos( $text, "\xEF\xBB\xBF" ) ) {
			$text = substr( $text, 3 );
		}
		if ( ! preg_match( '//u', $text ) && function_exists( 'mb_convert_encoding' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' ); // Excel export.
		}
		$first     = strtok( $text, "\n" );
		$separator = ( substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ) ? ';' : ',';

		$fh = fopen( 'php://temp', 'r+' );
		fwrite( $fh, $text );
		rewind( $fh );

		$header = null;
		$rows   = array();
		while ( false !== ( $cols = fgetcsv( $fh, 0, $separator, '"', '\\' ) ) ) {
			if ( null === $header ) {
				$header = array_map( array( __CLASS__, 'key' ), $cols );
				continue;
			}
			if ( 1 === count( $cols ) && ( null === $cols[0] || '' === trim( (string) $cols[0] ) ) ) {
				continue; // Empty line.
			}
			$row = array();
			foreach ( $header as $i => $key ) {
				if ( '' !== $key ) {
					$row[ $key ] = isset( $cols[ $i ] ) ? trim( str_replace( "\r", '', (string) $cols[ $i ] ) ) : '';
				}
			}
			$rows[] = $row;
		}
		fclose( $fh );
		return $rows;
	}

	/** Normalized column name: lower case, single spaces. "Lat (egen punkt)" => "lat (egen punkt)". */
	public static function key( $name ) {
		$name = trim( preg_replace( '/\s+/u', ' ', (string) $name ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
	}

	/** Value of a column, '' if missing. */
	public static function col( array $row, $name ) {
		$key = self::key( $name );
		return isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
	}
}
