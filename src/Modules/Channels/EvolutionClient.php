<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Channels;

use VMStudio\SalesOS\Support\IntegrationLog;
use VMStudio\SalesOS\Support\Logger;
use VMStudio\SalesOS\Support\Vault;

/**
 * Evolution API v2 Transport.
 *
 * Connects directly to local or remote Evolution API instance (e.g. http://127.0.0.1:18080)
 * for native WhatsApp message dispatch and webhook reception.
 */
final class EvolutionClient {

	public function __construct(
		private Vault $vault,
		private IntegrationLog $integrations,
		private Logger $logger
	) {}

	public function configured(): bool {
		return '' !== $this->apiKey() && '' !== $this->instance();
	}

	public function apiUrl(): string {
		$url = (string) get_option( 'vmsos_evolution_api_url', 'http://127.0.0.1:18080' );
		return rtrim( '' !== $url ? $url : 'http://127.0.0.1:18080', '/' );
	}

	public function apiKey(): string {
		return $this->vault->get( 'evolution_api_key' );
	}

	public function instance(): string {
		return (string) get_option( 'vmsos_evolution_instance', 'mynearby' );
	}

	public function send( string $to, string $message ): bool {
		if ( ! $this->configured() ) {
			$this->logger->error( 'evolution', 'Send skipped: Evolution API not configured' );
			return false;
		}

		$to = preg_replace( '/[^0-9]/', '', $to ) ?? '';
		if ( '' === $to ) {
			return false;
		}
		if ( 10 === strlen( $to ) ) {
			$to = '91' . $to;
		}

		$endpoint  = sprintf( '%s/message/sendText/%s', $this->apiUrl(), rawurlencode( $this->instance() ) );
		$startedAt = microtime( true );

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => 20,
				'headers' => [
					'apikey'       => $this->apiKey(),
					'Content-Type' => 'application/json',
				],
				'body'    => (string) wp_json_encode(
					[
						'number' => $to,
						'text'   => mb_substr( $message, 0, 4000 ),
					]
				),
			]
		);

		$durationMs = (int) round( ( microtime( true ) - $startedAt ) * 1000 );
		$trace      = [ 'duration_ms' => $durationMs, 'endpoint' => $endpoint, 'instance' => $this->instance() ];

		if ( is_wp_error( $response ) ) {
			$this->integrations->failure( 'evolution', 'send', $response->get_error_message(), $trace );
			$this->logger->error( 'evolution', 'Send failed', [ 'error' => $response->get_error_message() ] );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$body = mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 500 );
			$this->integrations->failure( 'evolution', 'send', 'HTTP ' . $code . ': ' . $body, $trace );
			$this->logger->error( 'evolution', 'Send rejected', [ 'status' => $code, 'body' => $body ] );
			return false;
		}

		$this->integrations->success( 'evolution', 'send', $trace );
		return true;
	}

	/**
	 * Check live connection status of Evolution API instance.
	 *
	 * @return array{connected: bool, state: string, instance: string, message: string}
	 */
	public function checkConnection(): array {
		if ( ! $this->configured() ) {
			return [
				'connected' => false,
				'state'     => 'unconfigured',
				'instance'  => $this->instance(),
				'message'   => 'Evolution API key or instance name not configured.',
			];
		}

		$endpoint = sprintf( '%s/instance/connectionState/%s', $this->apiUrl(), rawurlencode( $this->instance() ) );
		$response = wp_remote_get(
			$endpoint,
			[
				'timeout' => 8,
				'headers' => [
					'apikey' => $this->apiKey(),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'connected' => false,
				'state'     => 'error',
				'instance'  => $this->instance(),
				'message'   => $response->get_error_message(),
			];
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$data  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$state = strtolower( (string) ( $data['instance']['state'] ?? $data['state'] ?? '' ) );

		if ( 200 === $code && 'open' === $state ) {
			return [
				'connected' => true,
				'state'     => 'open',
				'instance'  => $this->instance(),
				'message'   => 'WhatsApp instance is connected and live.',
			];
		}

		return [
			'connected' => false,
			'state'     => $state ?: 'closed',
			'instance'  => $this->instance(),
			'message'   => sprintf( 'Instance state: %s (HTTP %d)', $state ?: 'disconnected', $code ),
		];
	}
}
