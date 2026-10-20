<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Support;

/**
 * Structured logger. Writes to the audit table and, in debug mode, error_log.
 */
final class Logger {

	public const DEBUG   = 'debug';
	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	/** @param array<string, mixed> $context Extra data. */
	public function log( string $level, string $channel, string $message, array $context = [] ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'vmsos_audit_log',
			[
				'brand_id'   => BrandContext::current(),
				'level'      => $level,
				'channel'    => $channel,
				'message'    => mb_substr( $message, 0, 1000 ),
				'context'    => wp_json_encode( self::redact( $context ) ),
				'user_id'    => get_current_user_id(),
				'ip_hash'    => self::ipHash(),
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && self::ERROR === $level ) {
			error_log( sprintf( '[vmsos:%s] %s %s', $channel, $message, wp_json_encode( self::redact( $context ) ) ) ); // phpcs:ignore
		}
	}

	public function info( string $channel, string $message, array $context = [] ): void {
		$this->log( self::INFO, $channel, $message, $context );
	}

	public function error( string $channel, string $message, array $context = [] ): void {
		$this->log( self::ERROR, $channel, $message, $context );
	}

	/**
	 * Strip anything that looks like a credential before it reaches storage.
	 *
	 * @param array<string, mixed> $context Context.
	 * @return array<string, mixed>
	 */
	private static function redact( array $context ): array {
		$sensitive = [ 'api_key', 'apikey', 'authorization', 'token', 'secret', 'password', 'bearer' ];
		foreach ( $context as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), $sensitive, true ) ) {
				$context[ $key ] = '***';
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = self::redact( $value );
			}
		}
		return $context;
	}

	private static function ipHash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return '' === $ip ? '' : substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
	}
}
