<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Support;

/**
 * Fixed-window rate limiter backed by the object cache, falling back to transients.
 */
final class RateLimiter {

	public function __construct( private string $group = 'vmsos_rl' ) {}

	public function tooManyAttempts( string $key, int $max, int $windowSeconds ): bool {
		return $this->attempts( $key ) >= $max;
	}

	public function hit( string $key, int $windowSeconds ): int {
		$current = $this->attempts( $key ) + 1;
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, $current, $this->group, $windowSeconds );
		} else {
			set_transient( $this->group . '_' . md5( $key ), $current, $windowSeconds );
		}
		return $current;
	}

	public function attempts( string $key ): int {
		$value = wp_using_ext_object_cache()
			? wp_cache_get( $key, $this->group )
			: get_transient( $this->group . '_' . md5( $key ) );
		return false === $value ? 0 : (int) $value;
	}

	public function clear( string $key ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, $this->group );
		} else {
			delete_transient( $this->group . '_' . md5( $key ) );
		}
	}
}
