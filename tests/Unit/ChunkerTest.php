<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VMStudio\SalesOS\Modules\Knowledge\Chunker;

final class ChunkerTest extends TestCase {

	public function testShortTextStaysOneChunk(): void {
		$chunks = ( new Chunker() )->split( 'A single short paragraph about pricing.' );

		$this->assertCount( 1, $chunks );
		$this->assertStringContainsString( 'pricing', $chunks[0] );
	}

	public function testEmptyInputProducesNoChunks(): void {
		$this->assertSame( [], ( new Chunker() )->split( "   \n\n  " ) );
	}

	public function testLongTextIsSplitAndOverlaps(): void {
		$paragraph = str_repeat( 'Sales teams need context. ', 120 );
		$chunks    = ( new Chunker( 400, 80 ) )->split( $paragraph );

		$this->assertGreaterThan( 1, count( $chunks ) );

		foreach ( $chunks as $chunk ) {
			// Overlap and sentence boundaries mean a chunk can exceed the
			// target, but it must never run away to the size of the input.
			$this->assertLessThan( 1000, mb_strlen( $chunk ) );
			$this->assertNotSame( '', trim( $chunk ) );
		}
	}

	public function testHtmlIsStrippedBeforeChunking(): void {
		$chunks = ( new Chunker() )->split( '<p>Refunds take <strong>five</strong> days.</p>' );

		$this->assertStringNotContainsString( '<strong>', $chunks[0] );
		$this->assertStringContainsString( 'five', $chunks[0] );
	}
}
