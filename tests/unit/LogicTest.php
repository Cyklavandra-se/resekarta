<?php

use CV_Resekarta\Csv;
use CV_Resekarta\Destinations;
use CV_Resekarta\Languages;
use CV_Resekarta\Rules;
use CV_Resekarta\Tags;
use CV_Resekarta\Trips;
use PHPUnit\Framework\TestCase;

final class LogicTest extends TestCase {

	public function test_first_matching_rule_wins_case_insensitive() {
		$rules = array(
			array( 'keyword' => 'Costa Rica', 'destination' => 1 ),
			array( 'keyword' => 'rica', 'destination' => 2 ),
			array( 'keyword' => 'Åland', 'destination' => 3 ),
		);
		$this->assertSame( 1, Rules::match( 'Naturvandring i COSTA RICA & Panama', $rules )['destination'] );
		$this->assertSame( 2, Rules::match( 'Rica', $rules )['destination'] );
		$this->assertSame( 3, Rules::match( 'Cykla på åland', $rules )['destination'] );
		$this->assertNull( Rules::match( 'Andorra', $rules ) );
	}

	public function test_levels_seasons_and_flags_from_tags() {
		$slugs = Tags::normalize( array( 'hike2', 'BIKE4', 'host', 'var', 'hund', 'tag', 'barn', '%c3%a5land', '2027' ) );
		$this->assertContains( 'åland', $slugs );
		$this->assertContains( '2027', $slugs );
		$this->assertSame( array( '2', '4' ), Tags::levels( $slugs ) );
		$this->assertSame( array( 'Vår', 'Höst' ), Tags::seasons( $slugs ) );
		$this->assertSame(
			array( 'dog' => true, 'train' => true, 'wine' => false, 'weekend' => false, 'family' => true ),
			Tags::flags( $slugs )
		);
		$this->assertSame( array(), Tags::levels( array( 'cykla' ) ) );
	}

	public function test_csv_with_bom_quotes_and_semicolons() {
		$rows = Csv::parse( "\xEF\xBB\xBFDestination,Lat,Lng,Sökord för karta\r\n\"Rhen & Nahe, Tyskland\",49.9,7.9,\r\n\r\n" );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Rhen & Nahe, Tyskland', Csv::col( $rows[0], 'Destination' ) );
		$this->assertSame( '', Csv::col( $rows[0], 'Sökord för karta' ) );

		$rows = Csv::parse( "Nyckelord;Destination\nKerkini;Kerkinisjön\n" );
		$this->assertSame( 'Kerkinisjön', Csv::col( $rows[0], 'destination' ) );
	}

	public function test_bundled_spreadsheet_files() {
		$dir   = dirname( __DIR__, 2 ) . '/data/';
		$trips = Csv::parse( file_get_contents( $dir . 'resor.csv' ) );
		$this->assertCount( 166, $trips );
		$active = array_filter( $trips, function ( $r ) { return 'Aktiv' === Csv::col( $r, 'Status' ); } );
		$this->assertCount( 160, $active );
		$points = array_filter( $trips, function ( $r ) { return '' !== Csv::col( $r, 'Lat (egen punkt)' ); } );
		$this->assertCount( 49, $points );
		$this->assertCount( 111, Csv::parse( file_get_contents( $dir . 'destinationer.csv' ) ) );
		$this->assertCount( 124, Csv::parse( file_get_contents( $dir . 'destinationsregler.csv' ) ) );
	}

	public function test_coordinates() {
		$this->assertSame( 43.25, Destinations::coordinate( '43,25', 90 ) );
		$this->assertSame( -3.5986, Destinations::coordinate( ' -3.5986 ', 180 ) );
		$this->assertNull( Destinations::coordinate( '', 90 ) );
		$this->assertNull( Destinations::coordinate( '95', 90 ) );
		$this->assertNull( Destinations::coordinate( 'abc', 90 ) );
	}

	public function test_norwegian_code_for_map_files() {
		$this->assertSame( 'no', Languages::map_code( 'nb' ) );
		$this->assertSame( 'no', Languages::map_code( 'no' ) );
		$this->assertSame( 'da', Languages::map_code( 'da' ) );
	}

	private function trip( array $over = array() ) {
		return array_merge(
			array(
				'id' => 1, 'title' => 'T', 'status' => 'publish', 'listed' => true,
				'category_auto' => 'cykel', 'category_manual' => '', 'category' => 'cykel',
				'visibility' => 'auto', 'destination' => 5, 'own_point' => null, 'check' => '',
				'first_seen' => '', 'last_seen' => '',
			),
			$over
		);
	}

	public function test_map_status_reasons() {
		$dest    = array( 'id' => 5, 'name' => 'D', 'lat' => 1.0, 'lng' => 2.0, 'keywords' => '', 'names' => array() );
		$nopoint = array_merge( $dest, array( 'lat' => null, 'lng' => null ) );

		$this->assertTrue( Trips::map_status( $this->trip(), $dest )['on'] );
		$this->assertSame( array( 1.0, 2.0 ), Trips::point( $this->trip(), $dest ) );
		$this->assertSame( 'hidden', Trips::map_status( $this->trip( array( 'visibility' => 'hide' ) ), $dest )['code'] );
		$this->assertSame( 'removed', Trips::map_status( $this->trip( array( 'listed' => false ) ), $dest )['code'] );
		$this->assertTrue( Trips::map_status( $this->trip( array( 'listed' => false, 'visibility' => 'show' ) ), $dest )['on'] );
		$this->assertSame( 'removed', Trips::map_status( $this->trip( array( 'status' => 'draft', 'visibility' => 'show' ) ), $dest )['code'] );
		$this->assertSame( 'no_destination', Trips::map_status( $this->trip(), null )['code'] );
		$this->assertSame( 'no_point', Trips::map_status( $this->trip(), $nopoint )['code'] );

		$own = $this->trip( array( 'own_point' => array( 3.0, 4.0 ) ) );
		$this->assertTrue( Trips::map_status( $own, $nopoint )['on'] );
		$this->assertTrue( Trips::map_status( $own, null )['on'] );
		$this->assertSame( array( 3.0, 4.0 ), Trips::point( $own, $dest ) );

		$this->assertTrue( Trips::needs_action( $this->trip(), Trips::map_status( $this->trip(), null ) ) );
		$this->assertTrue( Trips::needs_action( $this->trip( array( 'check' => 'x' ) ), Trips::map_status( $this->trip(), $dest ) ) );
		$this->assertFalse( Trips::needs_action( $this->trip(), Trips::map_status( $this->trip( array( 'visibility' => 'hide' ) ), null ) ) );
	}
}
