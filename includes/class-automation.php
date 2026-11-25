<?php
/**
 * Automation: new trips get a destination by themselves (rules, then the day programme),
 * destinations without coordinates are looked up, the night run keeps everything up to date,
 * and Pär gets an e-mail when something happened.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class Automation {

	const NIGHTLY       = 'cvrk_nightly';
	const SCAN_SOON     = 'cvrk_scan_soon';
	const LAST_RUN      = 'cvrk_last_run';
	const LOCK          = 'cvrk_run_lock';
	const REPORTED      = 'cvrk_reported_problems';
	const MAX_LOOKUPS   = 15; // Per trip, when reading the day programme.
	const MAX_GEOCODE   = 10; // Destinations per run.
	const NOTE_PROGRAM  = 'Destination hämtad från dagsprogrammet – kontrollera (dag %d)';
	const NOTE_NONE     = 'Ingen destination hittades – välj destination';
	const NOTE_GEOCODED = 'Koordinaterna för %s hämtades automatiskt – kontrollera';

	/** @var array New trips found in this request (filled by on_new_trips). */
	private static $new = array();

	public static function boot() {
		add_action( self::NIGHTLY, array( __CLASS__, 'nightly' ) );
		add_action( self::SCAN_SOON, array( __CLASS__, 'scan_soon' ) );
		add_action( 'cvrk_new_trips', array( __CLASS__, 'on_new_trips' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_publish' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 30 );
	}

	// ───────────── Scheduling ─────────────

	/** Next 03:00 in the site's time zone (Europe/Madrid on cyklavandra.se). */
	public static function next_night() {
		$tz   = wp_timezone();
		$next = new \DateTime( 'today 03:00', $tz );
		if ( $next->getTimestamp() <= time() ) {
			$next->modify( '+1 day' );
		}
		return $next->getTimestamp();
	}

	/** Schedule the night run (also after an update, when the activation hook does not run). */
	public static function ensure_schedule() {
		if ( ! wp_next_scheduled( self::NIGHTLY ) ) {
			wp_schedule_event( self::next_night(), 'daily', self::NIGHTLY );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::NIGHTLY );
		wp_clear_scheduled_hook( self::SCAN_SOON );
	}

	/**
	 * A Swedish page was published: read the lists in a couple of minutes, so a new trip comes
	 * on the map without waiting for the night (the list pages show it once it is published).
	 */
	public static function on_publish( $new, $old, $post ) {
		if ( 'publish' !== $new || 'publish' === $old || 'page' !== $post->post_type ) {
			return;
		}
		if ( Trips::is_trip( $post->ID ) || ! Languages::instance()->is_original( $post->ID ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::SCAN_SOON ) ) {
			wp_schedule_single_event( time() + 120, self::SCAN_SOON );
		}
	}

	public static function scan_soon() {
		self::run( array( 'mail' => true, 'geocode' => true, 'source' => 'publicering' ) );
	}

	public static function nightly() {
		self::run( array( 'mail' => true, 'geocode' => true, 'source' => 'natt' ) );
	}

	// ───────────── The run ─────────────

	/**
	 * Read the lists, give new trips a destination, look up missing coordinates, rebuild the map
	 * and (optionally) send an e-mail if something happened.
	 *
	 * @param array $opt mail (bool), geocode (bool), source (string, for the report).
	 * @return array Report.
	 */
	public static function run( array $opt = array() ) {
		$opt = array_merge( array( 'mail' => false, 'geocode' => true, 'source' => 'manuell' ), $opt );
		if ( get_transient( self::LOCK ) ) {
			return array( 'ok' => false, 'skipped' => true, 'errors' => array( 'En körning pågår redan.' ) );
		}
		set_transient( self::LOCK, 1, 15 * MINUTE_IN_SECONDS );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$report = array(
			'ok'        => true,
			'time'      => current_time( 'mysql' ),
			'source'    => $opt['source'],
			'scan'      => null,
			'new'       => array(), // Trip ID => details.
			'geocoded'  => array(), // Destination name => "lat, lng".
			'not_found' => array(), // Destination name => reason.
			'errors'    => array(),
		);
		try {
			self::$new      = array();
			$scan           = List_Scanner::run(); // Fires cvrk_new_trips → on_new_trips().
			$report['scan'] = $scan;
			if ( ! $scan['ok'] ) {
				$report['errors'][] = $scan['error'];
			}
			$report['new'] = self::$new;
			if ( $opt['geocode'] ) {
				$geo                 = self::geocode_missing();
				$report['geocoded']  = $geo['geocoded'];
				$report['not_found'] = $geo['not_found'];
				$report['errors']    = array_merge( $report['errors'], $geo['errors'] );
			}
			$build = Map_Data::build();
			foreach ( $build['errors'] as $e ) {
				$report['errors'][] = 'Kartan: ' . $e;
			}
		} catch ( \Throwable $e ) {
			$report['errors'][] = $e->getMessage();
		}
		$report['ok'] = ! $report['errors'];
		delete_transient( self::LOCK );

		if ( $opt['mail'] ) {
			$report['mailed'] = self::mail( $report );
		}
		update_option( self::LAST_RUN, $report, false );
		return $report;
	}

	/**
	 * Hook for List_Scanner: new trips get a destination and are described in the report.
	 *
	 * @param int[] $ids
	 */
	public static function on_new_trips( array $ids ) {
		foreach ( $ids as $id ) {
			$how                = self::auto_destination( $id );
			$trip               = Trips::get( $id );
			$dest               = Destinations::get( $trip['destination'] );
			self::$new[ $id ]   = array(
				'title'       => Map_Data::title( $id ),
				'category'    => $trip['category'],
				'destination' => $dest ? $dest['name'] : '',
				'how'         => $how,
				'check'       => $trip['check'],
				'url'         => (string) get_permalink( $id ),
				'edit'        => (string) get_edit_post_link( $id, 'raw' ),
			);
		}
	}

	/**
	 * Give a trip without destination one: rules first, then the day programme.
	 *
	 * @return string How: 'has', 'rule', 'program', 'none'.
	 */
	public static function auto_destination( $id ) {
		if ( (int) get_post_meta( $id, Trips::META_DESTINATION, true ) ) {
			return 'has';
		}
		$rule = Rules::match( Map_Data::title( $id ), Rules::all() );
		if ( $rule && Destinations::get( $rule['destination'] ) ) {
			update_post_meta( $id, Trips::META_DESTINATION, (int) $rule['destination'] );
			return 'rule';
		}
		$found = self::from_program( $id );
		if ( $found ) {
			update_post_meta( $id, Trips::META_DESTINATION, $found['destination'] );
			update_post_meta( $id, Trips::META_CHECK, sprintf( self::NOTE_PROGRAM, $found['day'] ) );
			return 'program';
		}
		if ( ! get_post_meta( $id, Trips::META_CHECK, true ) ) {
			update_post_meta( $id, Trips::META_CHECK, self::NOTE_NONE );
		}
		return 'none';
	}

	/**
	 * First place in the day programme that is a known destination with a point, or that the
	 * geocoder finds. Middle days first.
	 *
	 * @return array|null array( 'destination' => term ID, 'day' => int )
	 */
	public static function from_program( $id ) {
		$days    = Day_Program::order( Day_Program::read( self::rendered_content( $id ) ) );
		$lookups = 0;
		foreach ( $days as $d ) {
			foreach ( Day_Program::candidates( $d['title'] ) as $c ) {
				foreach ( Day_Program::variants( $c ) as $name ) {
					$known = Destinations::find_by_name( $name );
					if ( $known && Destinations::has_point( Destinations::get( $known ) ) ) {
						return array( 'destination' => $known, 'day' => $d['day'] );
					}
					if ( $lookups++ >= self::MAX_LOOKUPS ) {
						return null;
					}
					try {
						$point = Geocoder::lookup( $name );
					} catch ( \RuntimeException $e ) {
						return null; // Service down: try again next time (the trip stays flagged).
					}
					if ( $point ) {
						$data = array(
							'lat' => $point['lat'],
							'lng' => $point['lng'],
						);
						if ( $known ) {
							Destinations::update( $known, $data, false );
							return array( 'destination' => $known, 'day' => $d['day'] );
						}
						$new = Destinations::create( $name, $data );
						if ( ! is_wp_error( $new ) ) {
							return array( 'destination' => $new, 'day' => $d['day'] );
						}
					}
				}
			}
		}
		return null;
	}

	/** The page's content as visitors see it (Avada shortcodes rendered), for the day programme. */
	private static function rendered_content( $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return '';
		}
		$pre = apply_filters( 'cvrk_pre_rendered_content', null, $post );
		if ( null !== $pre ) {
			return (string) $pre;
		}
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );
		try {
			$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		} catch ( \Throwable $e ) {
			$html = $post->post_content;
		}
		wp_reset_postdata();
		return (string) $html;
	}

	/**
	 * Look up coordinates for destinations that trips on the map need. Only those – never all.
	 *
	 * @return array geocoded (name => "lat, lng"), not_found (name => reason), errors
	 */
	public static function geocode_missing() {
		$out   = array( 'geocoded' => array(), 'not_found' => array(), 'errors' => array() );
		$dests = Destinations::all();
		$need  = array(); // Destination ID => trip IDs.
		foreach ( Trips::ids() as $id ) {
			$trip = Trips::get( $id );
			$dest = isset( $dests[ $trip['destination'] ] ) ? $dests[ $trip['destination'] ] : null;
			if ( $dest && 'no_point' === Trips::map_status( $trip, $dest )['code'] ) {
				$need[ $dest['id'] ][] = $id;
			}
		}
		$done = 0;
		foreach ( $need as $dest_id => $trip_ids ) {
			if ( $done++ >= self::MAX_GEOCODE ) {
				break;
			}
			$d     = $dests[ $dest_id ];
			$query = '' !== $d['keywords'] ? $d['keywords'] : $d['name'];
			try {
				$point = Geocoder::lookup( $query );
			} catch ( \RuntimeException $e ) {
				$out['errors'][] = $e->getMessage();
				break;
			}
			if ( ! $point ) {
				$out['not_found'][ $d['name'] ] = 'hittades inte när "' . $query . '" slogs upp';
				continue;
			}
			Destinations::update( $dest_id, array( 'lat' => $point['lat'], 'lng' => $point['lng'] ), false );
			$out['geocoded'][ $d['name'] ] = $point['lat'] . ', ' . $point['lng'];
			foreach ( $trip_ids as $tid ) {
				if ( ! get_post_meta( $tid, Trips::META_CHECK, true ) ) {
					update_post_meta( $tid, Trips::META_CHECK, sprintf( self::NOTE_GEOCODED, $d['name'] ) );
				}
			}
		}
		if ( $out['geocoded'] ) {
			do_action( 'cvrk_trips_changed', 'geocode' );
		}
		return $out;
	}

	// ───────────── E-mail ─────────────

	/**
	 * Send the e-mail – only when something happened (new trips, errors, or a problem not
	 * reported before). Quiet nights send nothing.
	 *
	 * @return bool|string False when nothing was sent, otherwise the recipients.
	 */
	public static function mail( array $report ) {
		$message = self::message( $report, self::new_problems( $report ) );
		if ( ! $message ) {
			return false;
		}
		$to = (string) Settings::get( 'notify_email' );
		if ( '' === $to ) {
			$to = (string) get_option( 'admin_email' );
		}
		$from_name = function () {
			return 'Cykla & Vandra – resekartan';
		};
		add_filter( 'wp_mail_from_name', $from_name );
		$sent = wp_mail( array_map( 'trim', explode( ',', $to ) ), $message['subject'], $message['body'] );
		remove_filter( 'wp_mail_from_name', $from_name );
		return $sent ? $to : false;
	}

	/** Problems (destinations not found) that have not been mailed before. Remembers them. */
	private static function new_problems( array $report ) {
		$seen  = get_option( self::REPORTED, array() );
		$seen  = is_array( $seen ) ? $seen : array();
		$fresh = array();
		foreach ( $report['not_found'] as $name => $why ) {
			if ( ! isset( $seen[ $name ] ) ) {
				$fresh[ $name ] = $why;
				$seen[ $name ]  = current_time( 'mysql' );
			}
		}
		update_option( self::REPORTED, $seen, false );
		return $fresh;
	}

	/**
	 * Subject and body, or null when there is nothing to tell. Pure apart from URLs and names.
	 * Same content as nattligKorning in the old script.
	 */
	public static function message( array $report, array $problems ) {
		$new    = $report['new'];
		$errors = $report['errors'];
		if ( ! $new && ! $errors && ! $problems && empty( $report['geocoded'] ) ) {
			return null;
		}
		$n       = count( $new );
		$subject = $n
			? sprintf( 'Kartan: %d %s på cyklavandra.se', $n, 1 === $n ? 'ny resa' : 'nya resor' )
			: 'Kartan: något behöver kontrolleras';

		$parts = array();
		if ( $new ) {
			$lines   = array();
			$missing = false;
			foreach ( $new as $t ) {
				$cat  = isset( Trips::CATEGORIES[ $t['category'] ] ) ? Trips::CATEGORIES[ $t['category'] ] : 'ingen kategori';
				$dest = '' !== $t['destination'] ? $t['destination'] : 'INGEN DESTINATION';
				$note = '';
				if ( 'program' === $t['how'] ) {
					$note = preg_match( '/\(dag (\d+)\)/', $t['check'], $m ) ? ' (från dagsprogrammet, dag ' . $m[1] . ' – gissad, kontrollera)' : ' (gissad – kontrollera)';
				}
				if ( 'none' === $t['how'] ) {
					$missing = true;
				}
				$lines[] = '• ' . $t['title'] . ' (' . $cat . ') → ' . $dest . $note . "\n  " . $t['url'];
			}
			$parts[] = "Nya resor som lagts till på kartan:\n" . implode( "\n", $lines );
			if ( $missing ) {
				$parts[] = 'Resor utan destination visas inte på kartan förrän du valt en destination (i rutan Resekarta på resans sida, eller med Snabbredigera i översikten).';
			}
		}
		if ( ! empty( $report['geocoded'] ) ) {
			$lines = array();
			foreach ( $report['geocoded'] as $name => $point ) {
				$lines[] = '• ' . $name . ': ' . $point;
			}
			$parts[] = "Koordinater som hämtades automatiskt (kontrollera att punkten stämmer):\n" . implode( "\n", $lines );
		}
		if ( $problems ) {
			$lines = array();
			foreach ( $problems as $name => $why ) {
				$lines[] = '• ' . $name . ' – ' . $why;
			}
			$parts[] = "Destinationer som saknar koordinater (fyll i dem under Resekarta → Destinationer):\n" . implode( "\n", $lines );
		}
		if ( $errors ) {
			$parts[] = "Det här gick fel:\n" . implode( "\n", array_map( function ( $e ) { return '• ' . $e; }, $errors ) );
		}
		$parts[] = 'Översikten: ' . admin_url( 'admin.php?page=resekarta' );
		return array(
			'subject' => $subject,
			'body'    => implode( "\n\n", $parts ),
		);
	}

	/** Sample e-mail, from the settings page. */
	public static function send_test_mail() {
		$report = array(
			'new'       => array(
				array(
					'title'       => 'Andorra och östra Pyrenéerna',
					'category'    => 'guidad',
					'destination' => 'Andorra & östra Pyrenéerna',
					'how'         => 'rule',
					'check'       => '',
					'url'         => home_url( '/vandra/guidade/andorra/' ),
				),
				array(
					'title'       => 'Vandra i Dachstein',
					'category'    => 'vandring',
					'destination' => 'Gosau',
					'how'         => 'program',
					'check'       => sprintf( self::NOTE_PROGRAM, 4 ),
					'url'         => home_url( '/vandra/pa-egen-hand/dachstein/' ),
				),
			),
			'geocoded'  => array(),
			'not_found' => array(),
			'errors'    => array(),
		);
		$message = self::message( $report, array() );
		$to      = (string) Settings::get( 'notify_email' );
		$body    = "Det här är ett test. Så här ser det ut när nattkörningen hittar nya resor:\n\n" . $message['body'];
		return wp_mail( array_map( 'trim', explode( ',', $to ) ), 'Kartan: testmejl', $body ) ? $to : false;
	}
}
