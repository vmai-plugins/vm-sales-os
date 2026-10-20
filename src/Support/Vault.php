<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Support;

/**
 * Encrypted credential store. Keys never sit in the options table in plain text
 * and are never echoed back to the browser once saved.
 */
final class Vault {

	private const OPTION = 'vmsos_vault';
	private const CIPHER = 'aes-256-gcm';

	public function put( string $key, string $value ): void {
		$vault         = get_option( self::OPTION, [] );
		$vault[ $key ] = '' === $value ? '' : $this->encrypt( $value );
		update_option( self::OPTION, $vault, false );
	}

	public function get( string $key ): string {
		$constant = 'VMSOS_' . strtoupper( str_replace( [ '.', '-' ], '_', $key ) );
		if ( defined( $constant ) ) {
			return (string) constant( $constant ); // wp-config always wins.
		}

		$vault = get_option( self::OPTION, [] );
		$blob  = $vault[ $key ] ?? '';
		return '' === $blob ? '' : $this->decrypt( $blob );
	}

	public function has( string $key ): bool {
		return '' !== $this->get( $key );
	}

	public function forget( string $key ): void {
		$vault = get_option( self::OPTION, [] );
		unset( $vault[ $key ] );
		update_option( self::OPTION, $vault, false );
	}

	/** Masked preview for the settings screen. */
	public function hint( string $key ): string {
		$value = $this->get( $key );
		if ( '' === $value ) {
			return '';
		}
		return str_repeat( '•', 8 ) . substr( $value, -4 );
	}

	private function secret(): string {
		return hash( 'sha256', wp_salt( 'secure_auth' ) . 'vmsos', true );
	}

	private function encrypt( string $plain ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'plain:' . base64_encode( $plain ); // phpcs:ignore
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, self::CIPHER, $this->secret(), OPENSSL_RAW_DATA, $iv, $tag );
		return 'v1:' . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore
	}

	private function decrypt( string $blob ): string {
		if ( str_starts_with( $blob, 'plain:' ) ) {
			return (string) base64_decode( substr( $blob, 6 ) ); // phpcs:ignore
		}
		if ( ! str_starts_with( $blob, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw    = base64_decode( substr( $blob, 3 ) ); // phpcs:ignore
		$iv     = substr( $raw, 0, 12 );
		$tag    = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );
		$plain  = openssl_decrypt( $cipher, self::CIPHER, $this->secret(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $plain ? '' : $plain;
	}
}
