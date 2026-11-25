<?php
/**
 * Reads the three list pages (with pagination) and updates which pages are trips,
 * their automatic category, and whether they are on the lists.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class List_Scanner {

	const LAST_SCAN_OPTION = 'cvrk_last_scan';

	/**
	 * Run a scan and apply the result.
	 *
	 * @param bool $dry_run Only report, change nothing.
	 * @return array Report: ok, error, found, new, removed, back, unresolved (url => name), time.
	 */
	public static function run( $dry_run = false ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$report = array(
			'ok'         => false,
			'error'      => '',
			'found'      => 0,
			'new'        => array(),
			'removed'    => array(),
			'back'       => array(),
			'unresolved' => array(),
			'time'       => current_time( 'mysql' ),
		);

		$lists = self::fetch_lists();
		if ( is_wp_error( $lists ) ) {
			$report['error'] = $lists->get_error_message();
			self::save_report( $report, $dry_run );
			return $report;
		}

		$combined = List_Parser::combine( $lists );
		$found    = array(); // post ID => array( category, name, url ).
		foreach ( $combined as $url => $info ) {
			$id = self::resolve_url( $url );
			if ( ! $id ) {
				$report['unresolved'][ $url ] = $info['name'];
				continue;
			}
			if ( isset( $found[ $id ] ) ) {
				// Same page twice (e.g. old address that redirects). "cykel" wins as on the lists.
				if ( 'cykel' === $info['category'] ) {
					$found[ $id ]['category'] = 'cykel';
				}
				continue;
			}
			$found[ $id ] = $info + array( 'url' => $url );
		}
		$report['found'] = count( $found );

		// Safety net: if the lists suddenly show far fewer trips (changed layout, error page),
		// change nothing rather than hiding most of the map.
		$listed_before = self::count_listed();
		if ( 0 === count( $found ) || ( $listed_before >= 20 && count( $found ) < $listed_before / 2 ) ) {
			$report['error'] = sprintf(
				'Listsidorna gav bara %d resor (förra gången %d). Inget ändrades. Kontrollera listsidorna och försök igen.',
				count( $found ),
				$listed_before
			);
			self::save_report( $report, $dry_run );
			return $report;
		}

		$now = current_time( 'mysql' );
		foreach ( $found as $id => $info ) {
			$was_trip   = Trips::is_trip( $id );
			$was_listed = $was_trip && '1' === get_post_meta( $id, Trips::META_LISTED, true );
			if ( ! $was_trip ) {
				$report['new'][ $id ] = $info['name'];
			} elseif ( ! $was_listed ) {
				$report['back'][ $id ] = $info['name'];
			}
			if ( $dry_run ) {
				continue;
			}
			Trips::register( $id );
			update_post_meta( $id, Trips::META_LISTED, '1' );
			update_post_meta( $id, Trips::META_LAST_SEEN, $now );
			self::set_auto_category( $id, $info['category'] );
		}

		foreach ( Trips::ids() as $id ) {
			if ( isset( $found[ $id ] ) ) {
				continue;
			}
			if ( '1' === get_post_meta( $id, Trips::META_LISTED, true ) ) {
				$report['removed'][ $id ] = get_the_title( $id );
				if ( ! $dry_run ) {
					update_post_meta( $id, Trips::META_LISTED, '0' );
				}
			}
		}

		$report['ok'] = true;
		self::save_report( $report, $dry_run );
		if ( ! $dry_run ) {
			if ( $report['new'] ) {
				// Destination (rules, day programme) and the e-mail: see Automation.
				do_action( 'cvrk_new_trips', array_keys( $report['new'] ) );
			}
			do_action( 'cvrk_trips_changed', 'scan' );
		}
		return $report;
	}

	/**
	 * Set the category from the lists. A category from the spreadsheet import that differs
	 * from the lists is kept as a manual choice (the sheet never overwrote Pär's choices).
	 */
	public static function set_auto_category( $id, $category ) {
		update_post_meta( $id, Trips::META_CATEGORY_AUTO, $category );
		$sheet = (string) get_post_meta( $id, Trips::META_CATEGORY_SHEET, true );
		if ( '' !== $sheet ) {
			if ( $sheet !== $category && ! get_post_meta( $id, Trips::META_CATEGORY, true ) ) {
				update_post_meta( $id, Trips::META_CATEGORY, $sheet );
			}
			delete_post_meta( $id, Trips::META_CATEGORY_SHEET );
		}
	}

	private static function count_listed() {
		$n = 0;
		foreach ( Trips::ids() as $id ) {
			if ( '1' === get_post_meta( $id, Trips::META_LISTED, true ) ) {
				$n++;
			}
		}
		return $n;
	}

	private static function save_report( array $report, $dry_run ) {
		if ( ! $dry_run ) {
			update_option( self::LAST_SCAN_OPTION, $report, false );
		}
	}

	/** List page URLs per category from the settings. */
	public static function list_urls() {
		$out = array();
		foreach ( (array) Settings::get( 'list_pages' ) as $cat => $path ) {
			if ( isset( Trips::CATEGORIES[ $cat ] ) && '' !== trim( (string) $path ) ) {
				$out[ $cat ] = self::home() . '/' . ltrim( $path, '/' );
			}
		}
		return $out;
	}

	/**
	 * Fetch and parse all list pages, including ?_page=N.
	 *
	 * @return array|\WP_Error category => (url => name)
	 */
	public static function fetch_lists() {
		$parser = new List_Parser( self::home() );
		$urls   = self::list_urls();
		if ( ! $urls ) {
			return new \WP_Error( 'cvrk_no_lists', 'Inga listsidor är inställda.' );
		}
		$first = self::fetch_many( array_values( $urls ) );
		$out   = array();
		$more  = array();
		$i     = 0;
		foreach ( $urls as $cat => $url ) {
			$res = $first[ $i++ ];
			if ( is_wp_error( $res ) ) {
				return self::fetch_error( $url, $res );
			}
			$out[ $cat ] = $parser->find_trips( $res, $url );
			$pages       = List_Parser::total_pages( $res );
			for ( $p = 2; $p <= $pages; $p++ ) {
				$more[] = array( $cat, add_query_arg( '_page', $p, $url ), $url );
			}
		}
		if ( $more ) {
			$results = self::fetch_many( wp_list_pluck( $more, 1 ) );
			foreach ( $more as $j => $m ) {
				if ( is_wp_error( $results[ $j ] ) ) {
					return self::fetch_error( $m[1], $results[ $j ] );
				}
				$out[ $m[0] ] += $parser->find_trips( $results[ $j ], $m[2] );
			}
		}
		return $out;
	}

	private static function fetch_error( $url, \WP_Error $e ) {
		return new \WP_Error(
			'cvrk_fetch',
			sprintf( 'Kunde inte hämta %s (%s). Inget ändrades – försök igen om en stund.', $url, $e->get_error_message() )
		);
	}

	/**
	 * Fetch several URLs (in parallel when possible). Returns HTML strings or WP_Error, same order.
	 *
	 * @param string[] $urls
	 * @return array
	 */
	public static function fetch_many( array $urls ) {
		$out     = array();
		$pending = array();
		foreach ( array_values( $urls ) as $i => $url ) {
			$pre = apply_filters( 'cvrk_pre_fetch_list_page', null, $url );
			if ( null !== $pre ) {
				$out[ $i ] = $pre;
			} else {
				$pending[ $i ] = $url;
			}
		}
		if ( $pending && class_exists( '\WpOrg\Requests\Requests' ) && count( $pending ) > 1 ) {
			$requests = array();
			foreach ( $pending as $i => $url ) {
				$requests[ $i ] = array(
					'url'     => $url,
					'type'    => 'GET',
					'headers' => array( 'Accept-Language' => 'sv-SE,sv;q=0.9' ),
				);
			}
			$options = array(
				'timeout'   => 30,
				'useragent' => self::user_agent(),
				'verify'    => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			);
			try {
				$responses = \WpOrg\Requests\Requests::request_multiple( $requests, $options );
				foreach ( $pending as $i => $url ) {
					$r = isset( $responses[ $i ] ) ? $responses[ $i ] : null;
					if ( $r instanceof \WpOrg\Requests\Response && 200 === (int) $r->status_code ) {
						$out[ $i ] = $r->body;
						unset( $pending[ $i ] );
					}
				}
			} catch ( \Exception $e ) {
				unset( $e ); // Fall back to one at a time below.
			}
		}
		foreach ( $pending as $i => $url ) {
			$out[ $i ] = self::fetch_one( $url );
		}
		ksort( $out );
		return $out;
	}

	/** One URL with up to three attempts. */
	public static function fetch_one( $url ) {
		$last = null;
		for ( $try = 1; $try <= 3; $try++ ) {
			$r = wp_remote_get(
				$url,
				array(
					'timeout'    => 30,
					'user-agent' => self::user_agent(),
					'headers'    => array( 'Accept-Language' => 'sv-SE,sv;q=0.9' ),
				)
			);
			if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
				return wp_remote_retrieve_body( $r );
			}
			$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			$last = is_wp_error( $r ) ? $r : new \WP_Error( 'cvrk_http', 'HTTP ' . $code );
			if ( $code && $code < 500 && 429 !== $code ) {
				break;
			}
			sleep( $try );
		}
		return $last;
	}

	/** The site's home URL without language prefix or trailing slash (not filtered by WPML). */
	public static function home() {
		return untrailingslashit( (string) get_option( 'home' ) );
	}

	public static function user_agent() {
		return 'Resekarta/' . VERSION . ' (WordPress; ' . self::home() . ')';
	}

	/**
	 * Swedish page ID for a URL on this site, or 0.
	 * Tries the URL, then the page path in any status, then one redirect (old addresses).
	 */
	public static function resolve_url( $url, $follow_redirect = true ) {
		$id = self::local_lookup( $url );
		if ( ! $id && $follow_redirect ) {
			$location = self::redirect_target( $url );
			if ( $location && $location !== $url ) {
				$id = self::resolve_url( $location, false );
			}
		}
		if ( ! $id ) {
			return 0;
		}
		$id = Languages::instance()->original_id( $id );
		return 'page' === get_post_type( $id ) ? (int) $id : 0;
	}

	private static function local_lookup( $url ) {
		$id = url_to_postid( $url );
		if ( $id && 'page' === get_post_type( $id ) ) {
			return (int) $id;
		}
		$path = self::relative_path( $url );
		if ( '' === $path ) {
			return 0;
		}
		$page = get_page_by_path( $path, OBJECT, 'page' );
		return $page ? (int) $page->ID : 0;
	}

	/** Path relative to the site's home ("vandra/guidade/andorra"), or '' for other hosts. */
	public static function relative_path( $url ) {
		$home      = wp_parse_url( self::home() . '/' );
		$parts     = wp_parse_url( $url );
		$home_host = isset( $home['host'] ) ? strtolower( preg_replace( '/^www\./', '', $home['host'] ) ) : '';
		$host      = isset( $parts['host'] ) ? strtolower( preg_replace( '/^www\./', '', $parts['host'] ) ) : $home_host;
		if ( $host !== $home_host ) {
			return '';
		}
		$path      = isset( $parts['path'] ) ? rawurldecode( $parts['path'] ) : '';
		$home_path = isset( $home['path'] ) ? rtrim( $home['path'], '/' ) : '';
		if ( '' !== $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		return trim( $path, '/' );
	}

	/** Location header of a redirect, or ''. */
	public static function redirect_target( $url ) {
		$pre = apply_filters( 'cvrk_pre_redirect_target', null, $url );
		if ( null !== $pre ) {
			return (string) $pre;
		}
		$r = wp_remote_head(
			$url,
			array(
				'redirection' => 0,
				'timeout'     => 15,
				'user-agent'  => self::user_agent(),
			)
		);
		if ( is_wp_error( $r ) ) {
			return '';
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( $code < 300 || $code >= 400 ) {
			return '';
		}
		$loc = wp_remote_retrieve_header( $r, 'location' );
		if ( is_array( $loc ) ) {
			$loc = reset( $loc );
		}
		$loc = (string) $loc;
		if ( '' !== $loc && 0 === strpos( $loc, '/' ) ) {
			$loc = self::home() . $loc;
		}
		return $loc;
	}
}
