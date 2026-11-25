<?php

use CV_Resekarta\Transition;
use PHPUnit\Framework\TestCase;

final class TransitionTest extends TestCase {

	public function test_finds_shortcode_inside_avada_code_block() {
		$content = '[fusion_builder_container][fusion_code]' . base64_encode( '[resekarta tag="donau" filters="no"]' ) . '[/fusion_code][/fusion_builder_container]';
		$decoded = Transition::decode_code_blocks( $content );
		$this->assertStringContainsString( '[resekarta tag="donau" filters="no"]', $decoded );
		// Plain text inside a code block is left as it is.
		$this->assertSame( '[fusion_code]<p>hej</p>[/fusion_code]', Transition::decode_code_blocks( '[fusion_code]<p>hej</p>[/fusion_code]' ) );
	}
}
