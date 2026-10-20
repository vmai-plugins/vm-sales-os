<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Channels;

use VMStudio\SalesOS\Support\IntegrationLog;
use VMStudio\SalesOS\Support\Logger;
use VMStudio\SalesOS\Support\Vault;

/**
 * WhatsApp Cloud API transport. Text only, deliberately: templates and media
 * are a separate approval surface and belong in their own iteration.
 */
final class WhatsAppClient {

	private const GRAPH = 'https://graph.facebook.com/v20.0/';

	public function __construct(
		private Vault $vault,
		private IntegrationLog $integrations,
		private Logger $logger,
		private ?EvolutionClient $evolution = null
	) {}

	public function configured(): bool {
		$provider = (string) get_option( 'vmsos_whatsapp_provider', 'meta' );
		if ( 'evolution' === $provider && $this->evolution ) {
			return $this->evolution->configured();
		}

		return '' !== $this->phoneNumberId() && '' !== $this->token();
	}

	private function phoneNumberId(): string {
		return (string) get_option( 'vmsos_whatsapp_phone_id', '' );
	}

	private function token(): string {
		return $this->vault->get( 'whatsapp_token' );
	}

	public function verifyToken(): string {
		return $this->vault->get( 'whatsapp_verify_token' );
	}

	public function appSecret(): string {
		return $this->vault->get( 'whatsapp_app_secret' );
	}

	public function send( string $to, string $message ): bool {
		$provider = (string) get_option( 'vmsos_whatsapp_provider', 'meta' );
		if ( 'evolution' === $provider && $this->evolution && $this->evolution->configured() ) {
			return $this->evolution->send( $to, $message );
		}

		if ( ! $this->configured() ) {
			$this->logger->error( 'whatsapp', 'Send skipped: channel not configured' );
			return false;
		}

		$to = preg_replace( '/[^0-9]/', '', $to ) ?? '';

		if ( '' === $to ) {
			return false;
		}

		$startedAt = microtime( true );

		$response = wp_remote_post(
			self::GRAPH . rawurlencode( $this->phoneNumberId() ) . '/messages',
			[
				'timeout' => 20,
				'headers' => [
					'Authorization' => 'Bearer ' . $this->token(),
					'Content-Type'  => 'application/json',
				],
				'body'    => (string) wp_json_encode(
					[
						'messaging_product' => 'whatsapp',
						'to'                => $to,
						'type'              => 'text',
						'text'              => [ 'body' => mb_substr( $message, 0, 4000 ) ],
					]
				),
			]
		);

		$durationMs = (int) round( ( microtime( true ) - $startedAt ) * 1000 );
		$trace      = [ 'duration_ms' => $durationMs ];

		if ( is_wp_error( $response ) ) {
			$this->integrations->failure( 'whatsapp', 'send', $response->get_error_message(), $trace );
			$this->logger->error( 'whatsapp', 'Send failed', [ 'error' => $response->get_error_message() ] );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$body = mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 500 );

			$this->integrations->failure( 'whatsapp', 'send', 'HTTP ' . $code . ': ' . $body, $trace );
			$this->logger->error( 'whatsapp', 'Send rejected', [ 'status' => $code, 'body' => $body ] );
			return false;
		}

		$this->integrations->success( 'whatsapp', 'send', $trace );

		return true;
	}
}
