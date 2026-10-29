<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\AI\Providers;

use VMStudio\SalesOS\AI\AiException;
use VMStudio\SalesOS\AI\Contracts\ChatProvider;
use VMStudio\SalesOS\Support\Vault;

abstract class AbstractProvider implements ChatProvider {

	public function __construct( protected Vault $vault ) {}

	public function supportsTools(): bool {
		return true;
	}

	protected function setting( string $key, string $default = '' ): string {
		$value = get_option( 'vmsos_ai_' . $this->id() . '_' . $key, '' );
		return '' === $value ? $default : (string) $value;
	}

	protected function apiKey(): string {
		return $this->vault->get( 'ai.' . $this->id() . '.key' );
	}

	/**
	 * POST JSON and return the decoded body.
	 *
	 * @param array<string, string> $headers Request headers.
	 * @param array<string, mixed>  $body    Request body.
	 * @return array<string, mixed>
	 */
	protected function post( string $url, array $headers, array $body, int $timeout = 60 ): array {
		$response = wp_remote_post(
			$url,
			[
				'timeout'     => $timeout,
				'headers'     => array_merge( [ 'Content-Type' => 'application/json' ], $headers ),
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new AiException( $this->id() . ': ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data )
				? ( $data['error']['message'] ?? $data['message'] ?? substr( $raw, 0, 200 ) )
				: substr( $raw, 0, 200 );
			throw new AiException( sprintf( '%s: HTTP %d %s', $this->id(), $code, (string) $message ) );
		}

		return is_array( $data ) ? $data : [];
	}

	protected function now(): float {
		return microtime( true );
	}

	protected function elapsed( float $start ): int {
		return (int) round( ( microtime( true ) - $start ) * 1000 );
	}
}
