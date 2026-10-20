<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Knowledge;

/**
 * Splits text on paragraph boundaries into overlapping chunks, so a sentence
 * that answers a question is never cut in half.
 */
final class Chunker {

	public function __construct(
		private int $targetChars = 1200,
		private int $overlapChars = 150
	) {}

	/** @return array<int, string> */
	public function split( string $text ): array {
		$text = trim( preg_replace( '/\r\n?/', "\n", wp_strip_all_tags( $text ) ) ?? '' );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text ) ?? '';

		if ( '' === $text ) {
			return [];
		}
		if ( mb_strlen( $text ) <= $this->targetChars ) {
			return [ $text ];
		}

		$paragraphs = preg_split( '/\n\n+/', $text ) ?: [];
		$chunks     = [];
		$buffer     = '';

		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( '' === $paragraph ) {
				continue;
			}

			if ( mb_strlen( $paragraph ) > $this->targetChars ) {
				if ( '' !== $buffer ) {
					$chunks[] = $buffer;
					$buffer   = '';
				}
				foreach ( $this->splitLongParagraph( $paragraph ) as $piece ) {
					$chunks[] = $piece;
				}
				continue;
			}

			if ( mb_strlen( $buffer ) + mb_strlen( $paragraph ) + 2 > $this->targetChars ) {
				$chunks[] = $buffer;
				$buffer   = $this->tail( $buffer );
			}

			$buffer .= ( '' === $buffer ? '' : "\n\n" ) . $paragraph;
		}

		if ( '' !== trim( $buffer ) ) {
			$chunks[] = $buffer;
		}

		return array_values( array_filter( array_map( 'trim', $chunks ) ) );
	}

	/** @return array<int, string> */
	private function splitLongParagraph( string $paragraph ): array {
		$sentences = preg_split( '/(?<=[.!?।])\s+/u', $paragraph ) ?: [ $paragraph ];
		$out       = [];
		$buffer    = '';

		foreach ( $sentences as $sentence ) {
			if ( mb_strlen( $buffer ) + mb_strlen( $sentence ) > $this->targetChars && '' !== $buffer ) {
				$out[]  = $buffer;
				$buffer = $this->tail( $buffer );
			}
			$buffer .= ( '' === $buffer ? '' : ' ' ) . $sentence;
		}

		if ( '' !== trim( $buffer ) ) {
			$out[] = $buffer;
		}
		return $out;
	}

	private function tail( string $text ): string {
		return $this->overlapChars > 0 ? mb_substr( $text, -$this->overlapChars ) : '';
	}

	public static function estimateTokens( string $text ): int {
		return (int) ceil( mb_strlen( $text ) / 4 );
	}
}
