<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Chat;

/**
 * Cheap pre-AI filter. Every message it rejects is an API call not paid for and
 * a junk lead not created.
 */
final class SpamGuard {

	private const BLOCK_PATTERNS = [
		'/\b(?:viagra|casino|crypto\s*giveaway|forex\s*signals|seo\s*services\s*cheap)\b/i',
		'/(?:https?:\/\/[^\s]+){3,}/i',
	];

	/**
	 * @return array{allowed: bool, reason: string}
	 */
	public function inspect( string $message ): array {
		$trimmed = trim( $message );

		if ( '' === $trimmed ) {
			return [ 'allowed' => false, 'reason' => 'empty' ];
		}

		if ( mb_strlen( $trimmed ) > 4000 ) {
			return [ 'allowed' => false, 'reason' => 'too_long' ];
		}

		foreach ( self::BLOCK_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $trimmed ) ) {
				return [ 'allowed' => false, 'reason' => 'blocked_pattern' ];
			}
		}

		// Keyboard mashing: low character variety over a long string.
		if ( mb_strlen( $trimmed ) > 40 && $this->entropy( $trimmed ) < 2.2 ) {
			return [ 'allowed' => false, 'reason' => 'low_entropy' ];
		}

		// A single repeated character or word.
		if ( preg_match( '/(.)\1{15,}/u', $trimmed ) ) {
			return [ 'allowed' => false, 'reason' => 'repetition' ];
		}

		return [ 'allowed' => true, 'reason' => '' ];
	}

	private function entropy( string $text ): float {
		$length = mb_strlen( $text );
		if ( 0 === $length ) {
			return 0.0;
		}

		$counts = count_chars( strtolower( $text ), 1 );
		$sum    = array_sum( $counts );
		$value  = 0.0;

		foreach ( $counts as $count ) {
			$p     = $count / $sum;
			$value -= $p * log( $p, 2 );
		}

		return $value;
	}
}
