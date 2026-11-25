<?php

use CV_Resekarta\List_Parser;
use PHPUnit\Framework\TestCase;

final class ListParserTest extends TestCase {

	private function fixture( $name ) {
		return file_get_contents( dirname( __DIR__ ) . '/fixtures/' . $name . '.html' );
	}

	public function test_guided_list_from_live_site() {
		$p     = new List_Parser( 'https://cyklavandra.se' );
		$html  = $this->fixture( 'lista-guidade' );
		$trips = $p->find_trips( $html, 'https://cyklavandra.se/vandra/guidade/' );

		$this->assertCount( 25, $trips );
		$this->assertSame( 'Patagonien', $trips['https://cyklavandra.se/vandra/guidade/patagonien/'] );
		// Trips under another parent than the list ("Övriga") are included.
		$this->assertArrayHasKey( 'https://cyklavandra.se/ovrigt/colombia-fagel/', $trips );
		// The list page itself and information pages are not trips.
		$this->assertArrayNotHasKey( 'https://cyklavandra.se/vandra/guidade/', $trips );
		foreach ( array_keys( $trips ) as $url ) {
			$this->assertStringNotContainsString( '/information/', $url );
			$this->assertStringEndsWith( '/', $url );
		}
		$this->assertSame( 1, List_Parser::total_pages( $html ) );
	}

	public function test_paged_content_views_list() {
		$p     = new List_Parser( 'https://cyklavandra.se' );
		$html  = $this->fixture( 'lista-cykel-sida1' );
		$trips = $p->find_trips( $html, 'https://cyklavandra.se/cykla/cykla-pa-egen-hand/' );

		$this->assertCount( 9, $trips );
		$this->assertSame( 'Apulien på cykel - 6 dagar', $trips['https://cyklavandra.se/cykla/cykla-pa-egen-hand/apulien-kort/'] );
		$this->assertSame( 9, List_Parser::total_pages( $html ) );
	}

	public function test_read_more_link_uses_nearest_heading() {
		$p    = new List_Parser( 'https://example.org' );
		$html = '<h3>Cykla i Rioja</h3><p>Text</p><a href="/cykla/egen/rioja/?ref=1#x">Läs&nbsp;mer</a>'
			. '<a href="https://example.org/cykla/egen/annan/">Boka</a>'
			. '<h2><a href="https://example.org/vandra/guidade/andorra">Andorra &amp; Pyrenéerna</a></h2>'
			. '<a href="https://other.org/a/b/c/">Läs mer</a>';
		$trips = $p->find_trips( $html, 'https://example.org/cykla/egen/' );
		$this->assertSame(
			array(
				'https://example.org/cykla/egen/rioja/'      => 'Cykla i Rioja',
				'https://example.org/vandra/guidade/andorra/' => 'Andorra & Pyrenéerna',
			),
			$trips
		);
	}

	public function test_trip_url_depth_rules() {
		$p    = new List_Parser( 'https://cyklavandra.se' );
		$list = 'https://cyklavandra.se/vandra/guidade/';
		$this->assertTrue( $p->is_trip_url( 'https://cyklavandra.se/ovrigt/kuba-fagel/', $list ) );
		$this->assertFalse( $p->is_trip_url( 'https://cyklavandra.se/vandra/guidade/', $list ) );
		$this->assertFalse( $p->is_trip_url( 'https://cyklavandra.se/cykla/', $list ) );
		$this->assertFalse( $p->is_trip_url( 'https://cyklavandra.se/ovrigt/grupper/', $list ) );
		$this->assertFalse( $p->is_trip_url( 'https://cyklavandra.se/information/om-oss/sarah/', $list ) );
	}

	public function test_cycling_wins_when_on_both_lists() {
		$combined = List_Parser::combine(
			array(
				'guidad'   => array( 'https://x/a/b/g/' => 'G' ),
				'cykel'    => array( 'https://x/a/b/both/' => 'Both' ),
				'vandring' => array(
					'https://x/a/b/both/' => 'Both',
					'https://x/a/b/w/'    => 'W',
				),
			)
		);
		$this->assertSame( 'cykel', $combined['https://x/a/b/both/']['category'] );
		$this->assertSame( 'vandring', $combined['https://x/a/b/w/']['category'] );
		$this->assertSame( 'guidad', $combined['https://x/a/b/g/']['category'] );

		// Also when the walking list is read first.
		$combined = List_Parser::combine(
			array(
				'vandring' => array( 'https://x/a/b/both/' => 'Both' ),
				'cykel'    => array( 'https://x/a/b/both/' => 'Both' ),
			)
		);
		$this->assertSame( 'cykel', $combined['https://x/a/b/both/']['category'] );
	}
}
