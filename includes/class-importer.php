<?php
/**
 * One-time import of the spreadsheet (data/*.csv + destinationsnamn.json).
 * Can be run again: destinations are matched by name, rules are replaced, trips matched by URL.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class Importer {

	const REPORT_OPTION = 'cvrk_import_report';

	/** Files shipped with the plugin. */
	public static function bundled_files() {
		$dir = PLUGIN_DIR . '/data/';
		return array(
			'destinations' => $dir . 'destinationer.csv',
			'names'        => $dir . 'destinationsnamn.json',
			'rules'        => $dir . 'destinationsregler.csv',
			'trips'        => $dir . 'resor.csv',
		);
	}

	/**
	 * Run the whole import.
	 *
	 * @param array $texts     destinations/names/rules/trips => file contents.
	 * @param bool  $overwrite Overwrite values already set in WordPress (default: only fill empty).
	 * @return array Report.
	 */
	public static function run( array $texts, $overwrite = false ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$report = array(
			'time'         => current_time( 'mysql' ),
			'overwrite'    => (bool) $overwrite,
			'errors'       => array(),
			'destinations' => null,
			'rules'        => null,
			'trips'        => null,
			'no_point'     => array(),
		);

		$names = array();
		if ( ! empty( $texts['names'] ) ) {
			$names = json_decode( $texts['names'], true );
			if ( ! is_array( $names ) ) {
				$report['errors'][] = 'destinationsnamn.json kunde inte läsas (ogiltig JSON).';
				$names              = array();
			}
		}
		if ( ! empty( $texts['destinations'] ) ) {
			$report['destinations'] = self::import_destinations( Csv::parse( $texts['destinations'] ), $names, $overwrite );
		}
		if ( ! empty( $texts['rules'] ) ) {
			$report['rules'] = self::import_rules( Csv::parse( $texts['rules'] ) );
		}
		if ( ! empty( $texts['trips'] ) ) {
			$report['trips'] = self::import_trips( Csv::parse( $texts['trips'] ), $overwrite );
		}

		foreach ( Destinations::all() as $d ) {
			if ( ! Destinations::has_point( $d ) ) {
				$report['no_point'][ $d['id'] ] = $d['name'];
			}
		}

		update_option( self::REPORT_OPTION, $report, false );
		do_action( 'cvrk_trips_changed', 'import' );
		return $report;
	}

	/**
	 * Columns: Destination, Lat, Lng, Sökord för karta, Norskt namn.
	 *
	 * @param array[] $rows
	 * @param array   $names lang => (Swedish name => translated name), from destinationsnamn.json.
	 */
	public static function import_destinations( array $rows, array $names, $overwrite ) {
		$r = array(
			'rows'    => 0,
			'created' => array(),
			'updated' => array(),
			'errors'  => array(),
		);
		foreach ( $rows as $row ) {
			$name = Csv::col( $row, 'Destination' );
			if ( '' === $name ) {
				continue;
			}
			$r['rows']++;
			$data = array(
				'lat'      => Csv::col( $row, 'Lat' ),
				'lng'      => Csv::col( $row, 'Lng' ),
				'keywords' => Csv::col( $row, 'Sökord för karta' ),
				'names'    => array(),
			);
			$no = Csv::col( $row, 'Norskt namn' );
			if ( '' !== $no ) {
				$data['names']['no'] = $no;
			}
			foreach ( $names as $lang => $list ) {
				$lang = Languages::map_code( $lang );
				if ( is_array( $list ) && isset( $list[ $name ] ) && '' !== trim( (string) $list[ $name ] ) && empty( $data['names'][ $lang ] ) ) {
					$data['names'][ $lang ] = (string) $list[ $name ];
				}
			}

			$id = Destinations::find_by_name( $name );
			if ( ! $id ) {
				$id = Destinations::create( $name, $data );
				if ( is_wp_error( $id ) ) {
					$r['errors'][] = $name . ': ' . $id->get_error_message();
					continue;
				}
				$r['created'][] = $name;
				continue;
			}
			$changed = Destinations::update( $id, $data, $overwrite );
			if ( $changed ) {
				$r['updated'][ $name ] = $changed;
			}
		}
		return $r;
	}

	/** Columns: Nyckelord, Destination. Replaces all rules, in file order. */
	public static function import_rules( array $rows ) {
		$r     = array(
			'rows'                 => 0,
			'saved'                => 0,
			'created_destinations' => array(),
		);
		$rules = array();
		foreach ( $rows as $row ) {
			$kw   = Csv::col( $row, 'Nyckelord' );
			$name = Csv::col( $row, 'Destination' );
			if ( '' === $kw || '' === $name ) {
				continue;
			}
			$r['rows']++;
			$id = self::destination_id( $name, $r['created_destinations'] );
			if ( $id ) {
				$rules[] = array(
					'keyword'     => $kw,
					'destination' => $id,
				);
			}
		}
		$r['saved'] = count( Rules::save( $rules ) );
		return $r;
	}

	/**
	 * Columns: Kategori, Resans namn, URL, Destination, Status, Anteckning,
	 * Lat (egen punkt), Lng (egen punkt). Other columns are read from the pages instead.
	 */
	public static function import_trips( array $rows, $overwrite ) {
		$r    = array(
			'rows'                 => 0,
			'matched'              => 0,
			'active'               => 0,
			'own_points'           => 0,
			'hidden'               => 0,
			'flagged'              => 0,
			'not_found'            => array(), // url => name.
			'duplicates'           => array(), // url => name.
			'created_destinations' => array(),
			'by_status'            => array(),
		);
		$seen = array();
		foreach ( $rows as $row ) {
			$url = Csv::col( $row, 'URL' );
			if ( '' === $url ) {
				continue;
			}
			$r['rows']++;
			$name   = Csv::col( $row, 'Resans namn' );
			$status = Csv::col( $row, 'Status' );
			$r['by_status'][ $status ] = isset( $r['by_status'][ $status ] ) ? $r['by_status'][ $status ] + 1 : 1;

			$id = List_Scanner::resolve_url( self::rebase_url( $url ) );
			if ( ! $id ) {
				$point = Csv::col( $row, 'Lat (egen punkt)' );
				if ( '' !== $point ) {
					$name .= ' [' . $status . ', egen punkt ' . $point . ', ' . Csv::col( $row, 'Lng (egen punkt)' ) . ']';
				} elseif ( '' !== $status ) {
					$name .= ' [' . $status . ']';
				}
				$r['not_found'][ $url ] = $name;
				continue;
			}
			if ( isset( $seen[ $id ] ) ) {
				$r['duplicates'][ $url ] = $name;
				continue;
			}
			$seen[ $id ] = true;
			$r['matched']++;
			if ( 'Aktiv' === $status ) {
				$r['active']++;
			}

			Trips::register( $id );
			$scanned = '' !== (string) get_post_meta( $id, Trips::META_LAST_SEEN, true );

			// Category.
			$cat = Csv::key( Csv::col( $row, 'Kategori' ) );
			if ( isset( Trips::CATEGORIES[ $cat ] ) ) {
				$auto = (string) get_post_meta( $id, Trips::META_CATEGORY_AUTO, true );
				if ( '' === $auto ) {
					update_post_meta( $id, Trips::META_CATEGORY_AUTO, $cat );
				}
				if ( ! $scanned ) {
					update_post_meta( $id, Trips::META_CATEGORY_SHEET, $cat );
				} elseif ( '' !== $auto && $cat !== $auto && ( $overwrite || ! get_post_meta( $id, Trips::META_CATEGORY, true ) ) ) {
					update_post_meta( $id, Trips::META_CATEGORY, $cat );
				}
			}

			// Destination.
			$dest_name = Csv::col( $row, 'Destination' );
			if ( '' !== $dest_name && ( $overwrite || ! get_post_meta( $id, Trips::META_DESTINATION, true ) ) ) {
				$dest = self::destination_id( $dest_name, $r['created_destinations'] );
				if ( $dest ) {
					update_post_meta( $id, Trips::META_DESTINATION, $dest );
				}
			}

			// Own point.
			$lat = Destinations::coordinate( Csv::col( $row, 'Lat (egen punkt)' ), 90 );
			$lng = Destinations::coordinate( Csv::col( $row, 'Lng (egen punkt)' ), 180 );
			if ( null !== $lat && null !== $lng ) {
				if ( $overwrite || '' === (string) get_post_meta( $id, Trips::META_LAT, true ) ) {
					update_post_meta( $id, Trips::META_LAT, (string) $lat );
					update_post_meta( $id, Trips::META_LNG, (string) $lng );
				}
			}
			if ( '' !== (string) get_post_meta( $id, Trips::META_LAT, true ) ) {
				$r['own_points']++;
			}

			// Status. "Dold" => hide; Aktiv/Borttagen => auto (the lists decide).
			$visibility = (string) get_post_meta( $id, Trips::META_VISIBILITY, true );
			if ( 'Dold' === $status ) {
				if ( $overwrite || '' === $visibility || 'auto' === $visibility ) {
					update_post_meta( $id, Trips::META_VISIBILITY, 'hide' );
				}
			} elseif ( $overwrite || '' === $visibility ) {
				update_post_meta( $id, Trips::META_VISIBILITY, 'auto' );
			}
			if ( 'hide' === get_post_meta( $id, Trips::META_VISIBILITY, true ) ) {
				$r['hidden']++;
			}
			if ( ! $scanned ) {
				update_post_meta( $id, Trips::META_LISTED, 'Borttagen' === $status ? '0' : '1' );
			}

			// "Kontrollera" flag from guessed destinations.
			$note = Csv::col( $row, 'Anteckning' );
			if ( preg_match( '/^Destination (gissad|hämtad)/u', $note ) && ( $overwrite || '' === (string) get_post_meta( $id, Trips::META_CHECK, true ) ) ) {
				update_post_meta( $id, Trips::META_CHECK, $note );
			}
			if ( '' !== (string) get_post_meta( $id, Trips::META_CHECK, true ) ) {
				$r['flagged']++;
			}
		}
		$r['created_destinations'] = array_values( array_unique( $r['created_destinations'] ) );
		return $r;
	}

	/** Find a destination by name, creating it (without coordinates) if missing. */
	private static function destination_id( $name, array &$created ) {
		$id = Destinations::find_by_name( $name );
		if ( $id ) {
			return $id;
		}
		$id = Destinations::create( $name );
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		$created[] = $name;
		return $id;
	}

	/**
	 * The spreadsheet's URLs are absolute (https://cyklavandra.se/...). Move them onto this
	 * site's address, so the import also works on a test copy of the site.
	 */
	public static function rebase_url( $url ) {
		$parts = wp_parse_url( trim( $url ) );
		if ( empty( $parts['path'] ) ) {
			return $url;
		}
		return List_Scanner::home() . '/' . ltrim( $parts['path'], '/' );
	}
}
