<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Support;

final class Str {

	public static function uuid(): string {
		return wp_generate_uuid4();
	}

	public static function token( int $bytes = 32 ): string {
		return bin2hex( random_bytes( $bytes ) );
	}

	/** Constant-time comparison helper for webhook signatures. */
	public static function hashEquals( string $known, string $given ): bool {
		return hash_equals( $known, $given );
	}

	public static function clean( string $value, int $max = 5000 ): string {
		return mb_substr( trim( wp_strip_all_tags( $value ) ), 0, $max );
	}
}
