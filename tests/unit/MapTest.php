<?php

use CV_Resekarta\Map_Compare;
use CV_Resekarta\Map_Data;
use CV_Resekarta\Shortcode;
use PHPUnit\Framework\TestCase;

final class MapTest extends TestCase {

	private function item( array $over = array() ) {
		return array_merge(
			array(
				'id'    => 7,
				'title' => 'Cykla i Rioja',
				'cat'   => 'cykel',
				'dest'  => null,
				'lat'   => 42.4651,
				'lng'   => -2.4456,
				'img'   => 'https://example.org/bild.jpg',
				'slugs' => array( 'bike2', 'hike3', 'var', 'host', 'vin', 'langhelg', 'spanien', '2027', 'åland' ),
			),
			$over
		);
	}

	public function test_record_has_the_same_fields_as_the_old_map() {
		$r = Map_Data::record( 3, $this->item(), array( 'title' => 'Cycling in Rioja', 'url' => 'https://example.org/en/rioja/', 'desc' => 'Wine.', 'region' => 'Rioja' ) );
		$this->assertSame(
			array( 'id', 'title', 'cat', 'region', 'url', 'img', 'desc', 'lat', 'lng', 'seasons', 'difficulty', 'dog', 'train', 'wine', 'weekend', 'family', 'tags' ),
			array_keys( $r )
		);
		$this->assertSame( 3, $r['id'] );
		$this->assertSame( 'cykel', $r['cat'] );
		$this->assertSame( array( 'Vår', 'Höst' ), $r['seasons'] );
		$this->assertSame( array( '2', '3' ), $r['difficulty'] );
		$this->assertTrue( $r['wine'] );
		$this->assertTrue( $r['weekend'] );
		$this->assertFalse( $r['dog'] );
		$this->assertContains( '2027', $r['tags'] );
		$this->assertContains( 'åland', $r['tags'] );

		// Without destination the region is the title, as before.
		$r = Map_Data::record( 1, $this->item(), array( 'title' => 'T', 'url' => 'u', 'desc' => '', 'region' => '' ) );
		$this->assertSame( 'T', $r['region'] );
		$this->assertSame( array(), Map_Data::record( 1, $this->item( array( 'slugs' => array() ) ), array( 'title' => 'T', 'url' => 'u', 'desc' => '', 'region' => '' ) )['difficulty'] );
	}

	public function test_trips_on_the_same_point_are_spread() {
		$recs = array(
			array( 'lat' => 10.0, 'lng' => 20.0 ),
			array( 'lat' => 10.0, 'lng' => 20.0 ),
			array( 'lat' => 10.0, 'lng' => 20.0 ),
			array( 'lat' => 11.0, 'lng' => 20.0 ),
		);
		$out = Map_Data::spread( $recs );
		$this->assertSame( array( 9.965, 20.0 ), array( $out[0]['lat'], $out[0]['lng'] ) );
		$this->assertEqualsWithDelta( 10.0175, $out[1]['lat'], 1e-6 );
		$this->assertEqualsWithDelta( 20.030311, $out[1]['lng'], 1e-6 );
		$this->assertSame( array( 11.0, 20.0 ), array( $out[3]['lat'], $out[3]['lng'] ) );
		foreach ( array( 0, 1, 2 ) as $i ) {
			$d = sqrt( pow( $out[ $i ]['lat'] - 10, 2 ) + pow( $out[ $i ]['lng'] - 20, 2 ) );
			$this->assertEqualsWithDelta( 0.035, $d, 1e-5 );
		}
	}

	public function test_shortcode_query_matches_the_old_snippet() {
		$langs = array( 'sv', 'no', 'da', 'fi', 'en' );
		$this->assertSame(
			'v=abc&lang=no&tag=vandra&tag=spanien&tag=sjalvguidad&filters=no',
			Shortcode::query( array( 'tag' => 'vandra+spanien + sjalvguidad', 'filters' => 'no', 'kat' => '', 'arstid' => '', 'niva' => '' ), 'no', $langs, 'abc' )
		);
		$this->assertSame(
			'v=1&lang=sv&tag=donau%2Crhen&kat=guidad&arstid=v%C3%A5r&niva=2',
			Shortcode::query( array( 'tag' => 'Donau , Rhen', 'filters' => '', 'kat' => 'guidad', 'arstid' => 'vår', 'niva' => '2' ), 'sv', $langs, '1' )
		);
		// A language without map data: no lang=, the map chooses itself.
		$this->assertSame( 'v=1', Shortcode::query( array( 'tag' => '', 'filters' => 'ja', 'kat' => '', 'arstid' => '', 'niva' => '' ), 'de', $langs, '1' ) );
	}

	public function test_compare_with_the_old_files() {
		$old = array(
			array( 'title' => 'A', 'url' => 'https://cyklavandra.se/a/b/c/', 'lat' => 10.0, 'lng' => 20.0, 'cat' => 'cykel', 'tags' => array( 'x', 'y' ) ),
			array( 'title' => 'Gone', 'url' => 'https://cyklavandra.se/gone/', 'lat' => 1, 'lng' => 1 ),
			array( 'title' => 'Moved', 'url' => 'https://cyklavandra.se/m/', 'lat' => 5, 'lng' => 5 ),
		);
		$new = array(
			array( 'title' => 'A', 'url' => 'http://localhost/a/b/c', 'lat' => 10.03, 'lng' => 19.97, 'cat' => 'cykel', 'tags' => array( 'y', 'x' ) ),
			array( 'title' => 'New', 'url' => 'https://cyklavandra.se/new/', 'lat' => 1, 'lng' => 1 ),
			array( 'title' => 'Moved!', 'url' => 'https://cyklavandra.se/m/', 'lat' => 5.2, 'lng' => 5 ),
		);
		$c = Map_Compare::compare( $new, $old );
		$this->assertSame( 1, $c['same'] ); // Spreading and tag order are not differences.
		$this->assertSame( array( '/gone/' => 'Gone' ), $c['only_old'] );
		$this->assertSame( array( '/new/' => 'New' ), $c['only_new'] );
		$this->assertCount( 1, $c['changed']['point'] );
		$this->assertSame( 'Moved', $c['changed']['title'][0]['old'] );
	}

	public function test_compare_matches_a_trip_whose_link_changed_to_its_translation() {
		$old = array( array( 'title' => 'Cinque Terre', 'url' => 'https://cyklavandra.se/vandra/pa-egen-hand/cinque-terre/', 'lat' => 44.1, 'lng' => 9.7 ) );
		$new = array( array( 'title' => 'Cinque Terre', 'url' => 'https://cyklavandra.se/no/vandring/cinque-terre-no/', 'lat' => 44.1, 'lng' => 9.7 ) );
		$this->assertCount( 1, Map_Compare::compare( $new, $old )['only_old'] ); // Without resolver: by link.

		$ids = array( '/vandra/pa-egen-hand/cinque-terre/' => 42, '/no/vandring/cinque-terre-no/' => 42 );
		$c   = Map_Compare::compare(
			$new,
			$old,
			function ( $url ) use ( $ids ) {
				$k = Map_Compare::key( array( 'url' => $url ) );
				return isset( $ids[ $k ] ) ? $ids[ $k ] : 0;
			}
		);
		$this->assertSame( array(), $c['only_old'] );
		$this->assertSame( array(), $c['only_new'] );
		$this->assertCount( 1, $c['changed']['url'] );
		$this->assertSame( '/no/vandring/cinque-terre-no/', $c['changed']['url'][0]['new'] );
	}
}
