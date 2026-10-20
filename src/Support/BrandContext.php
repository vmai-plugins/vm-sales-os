<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Support;

/**
 * Every row in this plugin is scoped to a brand (tenant). Agency and multisite
 * installs get real isolation instead of a shared soup of records.
 */
final class BrandContext {

	private static ?int $current = null;

	public static function current(): int {
		if ( null !== self::$current ) {
			return self::$current;
		}
		$brand = (int) apply_filters( 'vmsos_current_brand_id', (int) get_option( 'vmsos_default_brand_id', 1 ) );
		return self::$current = max( 1, $brand );
	}

	public static function withBrand( int $brandId, callable $callback ): mixed {
		$previous      = self::$current;
		self::$current = max( 1, $brandId );
		try {
			return $callback();
		} finally {
			self::$current = $previous;
		}
	}

	public static function reset(): void {
		self::$current = null;
	}
}
