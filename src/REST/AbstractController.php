<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\REST;

use VMStudio\SalesOS\Support\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

abstract class AbstractController {

	public const NAMESPACE = 'vmsos/v1';

	/** Register this controller's routes. */
	abstract public function register(): void;

	/** Capability gate for admin-facing routes. */
	protected function canManage(): bool|WP_Error {
		if ( current_user_can( 'vmsos_manage' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'vmsos_forbidden', __( 'You do not have permission to do that.', 'vm-sales-os' ), [ 'status' => 403 ] );
	}

	/** Capability gate for agent-facing routes (view + act on leads). */
	protected function canOperate(): bool|WP_Error {
		if ( current_user_can( 'vmsos_operate' ) || current_user_can( 'edit_posts' ) ) {
			return true;
		}
		return $this->canManage();
	}

	/**
	 * Resolves real client IP behind Cloudflare, Nginx, or reverse proxies.
	 */
	protected function clientIp(): string {
		$headers = [
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		];

		foreach ( $headers as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$raw = (string) wp_unslash( $_SERVER[ $h ] );
				$first = trim( explode( ',', $raw )[0] );
				if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
					return $first;
				}
			}
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '127.0.0.1';
	}

	/**
	 * Throttle a public endpoint by hashed IP.
	 */
	protected function throttle( string $bucket, int $max, int $window ): bool|WP_Error {
		$ip      = $this->clientIp();
		$key     = $bucket . ':' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 24 );
		$limiter = new RateLimiter();

		if ( $limiter->tooManyAttempts( $key, $max, $window ) ) {
			return new WP_Error(
				'vmsos_rate_limited',
				__( 'Too many requests. Wait a moment and try again.', 'vm-sales-os' ),
				[ 'status' => 429 ]
			);
		}

		$limiter->hit( $key, $window );
		return true;
	}

	/** @param array<string, mixed>|array<int, mixed> $data Response body. */
	protected function ok( array $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( [ 'ok' => true, 'data' => $data ], $status );
	}

	protected function fail( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}

	protected function param( WP_REST_Request $request, string $key, string $default = '' ): string {
		return sanitize_text_field( (string) ( $request->get_param( $key ) ?? $default ) );
	}

	protected function textParam( WP_REST_Request $request, string $key, int $max = 4000 ): string {
		return mb_substr( wp_strip_all_tags( (string) $request->get_param( $key ) ), 0, $max );
	}
}
