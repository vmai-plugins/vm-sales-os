<?php
/**
 * Test bootstrap.
 *
 * These are unit tests, not integration tests: they cover the pieces whose
 * correctness does not depend on WordPress. The handful of WP functions those
 * classes touch are stubbed here so the suite runs without a WP install.
 *
 * Repository and REST behaviour needs a real database and is covered by
 * integration tests, not by this file.
 */

declare(strict_types=1);

define( 'VMSOS_TESTING', true );

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
		$text = strip_tags( $text );
		return $remove_breaks ? trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $text ) ) : trim( $text );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['vmsos_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		return 'Test Business';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.test' . $path;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type = 'mysql', int $gmt = 0 ) {
		if ( 'timestamp' === $type ) {
			return $GLOBALS['vmsos_test_now'] ?? time();
		}
		return gmdate( 'Y-m-d' === $type ? 'Y-m-d' : 'Y-m-d H:i:s', $GLOBALS['vmsos_test_now'] ?? time() );
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['vmsos_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}

$GLOBALS['vmsos_test_options'] = [];

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'VMStudio\\SalesOS\\';

		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
		$file     = dirname( __DIR__ ) . '/src/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
