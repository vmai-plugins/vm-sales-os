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
		$inst = (string) get_option( 'vmsos_evolution_instance', '' );
		if ( '' !== trim( $inst ) ) {
			return trim( $inst );
		}
		$slug = sanitize_title( get_bloginfo( 'name' ) );
		return ! empty( $slug ) ? $slug : 'vmsos';
	}

	public function send( string $to, string $message ): bool {
		$result = $this->sendMessage( $to, $message );
		return $result['success'] ?? false;
	}

	/**
	 * Send WhatsApp message returning detailed status array.
	 *
	 * @return array{success: bool, data?: array, error?: string}
	 */
	public function sendMessage( string $to, string $message ): array {
		if ( ! $this->configured() ) {
			$this->logger->error( 'evolution', 'Send skipped: Evolution API not configured' );
			return [
				'success' => false,
				'error'   => 'Evolution API not configured',
			];
		}

		$to = preg_replace( '/[^0-9]/', '', $to ) ?? '';
		if ( '' === $to ) {
			return [
				'success' => false,
				'error'   => 'Recipient phone number is missing or invalid',
			];
		}
		if ( 10 === strlen( $to ) && ! str_starts_with( $to, '91' ) ) {
			$to = '91' . $to;
		}

		$encInstance = rawurlencode( $this->instance() );
		$endpoint    = sprintf( '%s/message/sendText/%s', $this->apiUrl(), $encInstance );
		$startedAt   = microtime( true );

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout'   => 20,
				'headers'   => [
					'apikey'       => $this->apiKey(),
					'Content-Type' => 'application/json',
				],
				'body'      => (string) wp_json_encode(
					[
						'number' => $to,
						'text'   => mb_substr( $message, 0, 4000 ),
					]
				),
				'sslverify' => false,
			]
		);

		$durationMs = (int) round( ( microtime( true ) - $startedAt ) * 1000 );
		$trace      = [ 'duration_ms' => $durationMs, 'endpoint' => $endpoint, 'instance' => $this->instance() ];

		if ( is_wp_error( $response ) ) {
			$this->integrations->failure( 'evolution', 'send', $response->get_error_message(), $trace );
			$this->logger->error( 'evolution', 'Send failed', [ 'error' => $response->get_error_message() ] );
			return [
				'success' => false,
				'error'   => $response->get_error_message(),
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			$this->integrations->success( 'evolution', 'send', $trace );
			return [
				'success'    => true,
				'remote_jid' => "{$to}@s.whatsapp.net",
				'data'       => $body,
			];
		}

		$errMsg = $body['response']['message'] ?? $body['message'] ?? 'Could not deliver WhatsApp message';
		$this->integrations->failure( 'evolution', 'send', 'HTTP ' . $code . ': ' . $errMsg, $trace );
		$this->logger->error( 'evolution', 'Send rejected', [ 'status' => $code, 'body' => $body ] );
		return [
			'success' => false,
			'error'   => (string) $errMsg,
			'data'    => $body,
		];
	}

	/**
	 * Check live connection status of Evolution API instance with profile metadata.
	 *
	 * @return array{connected: bool, state: string, instance: string, number: string, profile: string, message: string}
	 */
	public function checkConnection(): array {
		if ( ! $this->configured() ) {
			return [
				'connected' => false,
				'state'     => 'unconfigured',
				'instance'  => $this->instance(),
				'number'    => '',
				'profile'   => '',
				'message'   => 'Evolution API key or instance name not configured.',
			];
		}

		$encInstance = rawurlencode( $this->instance() );
		$endpoint    = sprintf( '%s/instance/connectionState/%s', $this->apiUrl(), $encInstance );
		$response    = wp_remote_get(
			$endpoint,
			[
				'timeout'   => 8,
				'headers'   => [
					'apikey' => $this->apiKey(),
				],
				'sslverify' => false,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'connected' => false,
				'state'     => 'error',
				'instance'  => $this->instance(),
				'number'    => '',
				'profile'   => '',
				'message'   => $response->get_error_message(),
			];
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$data  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$state = strtolower( (string) ( $data['instance']['state'] ?? $data['state'] ?? '' ) );

		$number      = '';
		$profileName = '';
		if ( 'open' === $state ) {
			$detailsRes = wp_remote_get(
				sprintf( '%s/instance/fetchInstances?instanceName=%s', $this->apiUrl(), $encInstance ),
				[
					'timeout'   => 8,
					'headers'   => [ 'apikey' => $this->apiKey() ],
					'sslverify' => false,
				]
			);
			if ( ! is_wp_error( $detailsRes ) && 200 === (int) wp_remote_retrieve_response_code( $detailsRes ) ) {
				$d = json_decode( (string) wp_remote_retrieve_body( $detailsRes ), true );
				if ( ! empty( $d[0] ) && is_array( $d[0] ) ) {
					$number      = (string) ( $d[0]['number'] ?? '' );
					$profileName = (string) ( $d[0]['profileName'] ?? '' );
				}
			}
		}

		if ( 200 === $code && 'open' === $state ) {
			return [
				'connected' => true,
				'state'     => 'open',
				'instance'  => $this->instance(),
				'number'    => $number,
				'profile'   => $profileName,
				'message'   => 'WhatsApp instance is connected and live.',
			];
		}

		return [
			'connected' => false,
			'state'     => $state ?: 'closed',
			'instance'  => $this->instance(),
			'number'    => $number,
			'profile'   => $profileName,
			'message'   => sprintf( 'Instance state: %s (HTTP %d)', $state ?: 'disconnected', $code ),
		];
	}
}
