<?php

use CV_Resekarta\Automation;
use CV_Resekarta\Day_Program;
use CV_Resekarta\Geocoder;
use PHPUnit\Framework\TestCase;

final class AutomationTest extends TestCase {

	const PROGRAM = '<h2>Tidsplan</h2>
		<h4>Dag 1 – Välkommen till Pyrenéerna</h4><p>Ankomst till Andorra la Vella.</p>
		<h4><strong>Dag 2 – Vall d&#8217;Incles</strong></h4><p>Text</p>
		<p>Dag 3: Från Canillo till Ordino</p>
		<h4>Dag 4 – Sorteny</h4>
		<h4>Dag 4 – dubblett som ska ignoreras</h4>
		<h4>Dag 5 &#8211; Ledig dag i Andorra la Vella</h4>
		<h4>Dag 6 – Hemresa</h4>';

	public function test_reads_days_in_order_once_each() {
		$days = Day_Program::read( self::PROGRAM );
		$this->assertSame( array( 1, 2, 3, 4, 5, 6 ), array_column( $days, 'day' ) );
		$this->assertSame( "Vall d’Incles", $days[1]['title'] );
		$this->assertSame( 'Från Canillo till Ordino', $days[2]['title'] );
		$this->assertSame( 'Sorteny', $days[3]['title'] );
	}

	public function test_middle_days_first_travel_days_last() {
		$days = array();
		for ( $d = 1; $d <= 8; $d++ ) {
			$days[] = array( 'day' => $d, 'title' => 'x' );
		}
		$this->assertSame( array( 4, 5, 3, 6, 2, 7, 1, 8 ), array_column( Day_Program::order( $days ), 'day' ) );
		$this->assertSame( array( 3, 4, 2, 5, 1, 6 ), array_column( Day_Program::order( Day_Program::read( self::PROGRAM ) ), 'day' ) );
	}

	public function test_candidates_goal_first_and_skip_words() {
		$texts = function ( $t ) {
			return array_column( Day_Program::candidates( $t ), 'text' );
		};
		$this->assertSame( array( 'Ordino', 'Canillo' ), $texts( 'Från Canillo till Ordino' ) );
		$this->assertSame( array( "Vall d'Incles" ), $texts( 'Vall d’Incles' ) );
		$this->assertSame( array( 'Andorra la Vella' ), $texts( 'Ledig dag i Andorra la Vella' ) );
		$this->assertSame( array(), $texts( 'Hemresa' ) );
		$this->assertSame( array( 'Pyrenéerna' ), $texts( 'Välkommen till Pyrenéerna' ) );
		$this->assertSame( array( 'St. Moritz' ), $texts( 'Transfer till St. Moritz' ) );
		$this->assertSame( array( 'Lago di Como' ), $texts( 'Cykling runt Lago di Como' ) );

		$c = Day_Program::candidates( 'Alpernas toppar' );
		$this->assertTrue( $c[0]['genitive'] );
		$this->assertSame( array( 'Alperna', 'Alpernas' ), Day_Program::variants( $c[0] ) );
		$this->assertSame( array(), Day_Program::variants( array( 'text' => 'Ax', 'genitive' => false ) ) );
	}

	public function test_nominatim_answer_skips_streets() {
		$json = json_encode(
			array(
				array( 'lat' => '42.1', 'lon' => '1.1', 'category' => 'highway', 'type' => 'residential', 'addresstype' => 'road', 'display_name' => 'Carrer Sorteny' ),
				array( 'lat' => '42.62501', 'lon' => '1.57549', 'category' => 'place', 'type' => 'hamlet', 'addresstype' => 'hamlet', 'display_name' => 'Sorteny, Ordino, Andorra' ),
			)
		);
		$this->assertSame( array( 'lat' => 42.625, 'lng' => 1.5755, 'label' => 'Sorteny, Ordino, Andorra' ), Geocoder::parse_nominatim( $json ) );
		$this->assertNull( Geocoder::parse_nominatim( '[]' ) );
		$this->assertNull( Geocoder::parse_nominatim( 'not json' ) );
	}

	public function test_google_answer() {
		$json = json_encode(
			array(
				'status'  => 'OK',
				'results' => array(
					array( 'types' => array( 'route' ), 'geometry' => array( 'location' => array( 'lat' => 1, 'lng' => 2 ) ) ),
					array( 'types' => array( 'locality', 'political' ), 'formatted_address' => 'Gosau, Österrike', 'geometry' => array( 'location' => array( 'lat' => 47.58612, 'lng' => 13.53111 ) ) ),
				),
			)
		);
		$this->assertSame( array( 'lat' => 47.5861, 'lng' => 13.5311, 'label' => 'Gosau, Österrike' ), Geocoder::parse_google( $json ) );
	}

	public function test_mail_only_when_something_happened() {
		$quiet = array( 'new' => array(), 'geocoded' => array(), 'not_found' => array(), 'errors' => array() );
		$this->assertNull( Automation::message( $quiet, array() ) );

		$report        = $quiet;
		$report['new'] = array(
			array( 'title' => 'Vandra i Dachstein', 'category' => 'vandring', 'destination' => 'Gosau', 'how' => 'program', 'check' => sprintf( Automation::NOTE_PROGRAM, 4 ), 'url' => 'https://x/d/' ),
			array( 'title' => 'Okänd', 'category' => 'cykel', 'destination' => '', 'how' => 'none', 'check' => Automation::NOTE_NONE, 'url' => 'https://x/o/' ),
		);
		$m = Automation::message( $report, array() );
		$this->assertSame( 'Kartan: 2 nya resor på cyklavandra.se', $m['subject'] );
		$this->assertStringContainsString( '• Vandra i Dachstein (Vandring) → Gosau (från dagsprogrammet, dag 4 – gissad, kontrollera)', $m['body'] );
		$this->assertStringContainsString( '• Okänd (Cykel) → INGEN DESTINATION', $m['body'] );
		$this->assertStringContainsString( 'Resor utan destination visas inte på kartan', $m['body'] );

		$err           = $quiet;
		$err['errors'] = array( 'Kunde inte hämta listan.' );
		$this->assertSame( 'Kartan: något behöver kontrolleras', Automation::message( $err, array() )['subject'] );
		$this->assertSame( 'Kartan: något behöver kontrolleras', Automation::message( $quiet, array( 'Capri' => 'hittades inte' ) )['subject'] );
	}

	public function test_names_on_several_days_first_and_commas_split() {
		$html = '<h4>Dag 1: Ankomst till Potsdam</h4><h4>Dag 2: Potsdam runt – slott, kolonier och kvarter</h4>'
			. '<h4>Dag 3: Havel och Wannsee – över spionbron till Gatow</h4>'
			. '<h4>Dag 4: Templiner See och Schwielowsee – Caputh, Petzow och Werder</h4>'
			. '<h4>Dag 5: Utflykt till Berlin</h4><h4>Dag 6: Hemresa från Potsdam</h4>';
		$names = array_column( Day_Program::names_to_try( Day_Program::read( $html ) ), 'name' );
		$this->assertSame( 'Potsdam', $names[0] );
		$this->assertContains( 'Caputh', $names );
		$this->assertContains( 'Petzow', $names );
		$this->assertNotContains( 'Caputh Petzow', $names );
		$this->assertSame( count( $names ), count( array_unique( $names ) ) );
		$this->assertSame( 'Gatow', $names[1] ); // Then the middle days as before (goal of day 3 first).
	}

	public function test_day_programme_lookups_only_accept_towns_and_areas() {
		$json = json_encode(
			array(
				array( 'lat' => '52.47', 'lon' => '12.81', 'category' => 'waterway', 'type' => 'river', 'addresstype' => 'river', 'display_name' => 'Havel' ),
				array( 'lat' => '52.40', 'lon' => '13.05', 'category' => 'boundary', 'type' => 'administrative', 'addresstype' => 'city', 'display_name' => 'Potsdam' ),
			)
		);
		$this->assertSame( 52.47, Geocoder::parse_nominatim( $json )['lat'] );
		$this->assertSame( 52.4, Geocoder::parse_nominatim( $json, true )['lat'] );
		$this->assertNull( Geocoder::parse_nominatim( json_encode( array( array( 'lat' => '1', 'lon' => '2', 'category' => 'waterway', 'type' => 'river' ) ) ), true ) );
	}
}
