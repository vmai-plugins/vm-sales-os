<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Channels;

use VMStudio\SalesOS\Support\IntegrationLog;
use VMStudio\SalesOS\Support\Logger;
use VMStudio\SalesOS\Support\Vault;

/**
 * Outbound Voice Telephony Client (Vapi / Retell / Twilio).
 * Initiates AI-led sales discovery calls, booking follow-ups, and urgent escalations.
 */
final class VoiceClient {

	public function __construct(
		private Vault $vault,
		private IntegrationLog $integrationLog,
		private Logger $logger
	) {}

	public function isEnabled(): bool {
		return (bool) get_option( 'vmsos_voice_enabled', false );
	}

	public function apiKey(): string {
		return $this->vault->get( 'voice_api_key' ) ?: (string) get_option( 'vmsos_voice_api_key', '' );
	}

	public function assistantId(): string {
		return (string) get_option( 'vmsos_voice_assistant_id', '' );
	}

	public function phoneNumberId(): string {
		return (string) get_option( 'vmsos_voice_phone_number_id', '' );
	}

	/**
	 * Place an AI outbound call to a prospect.
	 *
	 * @param string $phone Customer phone number in E.164.
	 * @param string $customerName Prospect name.
	 * @param string $reason Purpose e.g. "sales_inquiry_followup".
	 * @return array{ok: bool, call_id?: string, error?: string}
	 */
	public function call( string $phone, string $customerName = '', string $reason = '' ): array {
		if ( ! $this->isEnabled() ) {
			return [ 'ok' => false, 'error' => 'Voice telephony is disabled in settings.' ];
		}

		$key = $this->apiKey();
		if ( '' === $key ) {
			return [ 'ok' => false, 'error' => 'Voice API key is not configured.' ];
		}

		$cleanPhone = preg_replace( '/[^\d+]/', '', $phone ) ?? '';
		if ( '' === $cleanPhone ) {
			return [ 'ok' => false, 'error' => 'Invalid destination phone number.' ];
		}

		$defaultNotice = sprintf(
			__( 'Hello %s, this is an automated assistant call regarding your inquiry: ', 'vm-sales-os' ),
			$customerName ?: 'there'
		);
		$firstMessage = (string) get_option( 'vmsos_voice_disclosure', $defaultNotice ) . ( '' !== $reason ? " {$reason}." : '' );

		$endpoint = 'https://api.vapi.ai/call/phone';
		$body = [
			'phoneNumberId'      => $this->phoneNumberId(),
			'assistantId'        => $this->assistantId(),
			'customer'           => [
				'number' => $cleanPhone,
				'name'   => $customerName,
			],
			'assistantOverrides' => [
				'firstMessage'   => $firstMessage,
				'variableValues' => [
					'customer_name' => $customerName,
					'reason'        => $reason,
				],
			],
		];

		$start    = microtime( true );
		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$err = $response->get_error_message();
			$this->integrationLog->record( 'voice', 'call', 'error', $err, $duration );
			$this->logger->error( 'voice', 'Call failed to dispatch', [ 'phone' => $cleanPhone, 'error' => $err ] );
			return [ 'ok' => false, 'error' => $err ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];

		if ( $code >= 200 && $code < 300 ) {
			$callId = (string) ( $data['id'] ?? ( 'call_' . time() ) );
			$this->integrationLog->record( 'voice', 'call', 'ok', "Call {$callId} dispatched to {$cleanPhone}", $duration );
			return [ 'ok' => true, 'call_id' => $callId ];
		}

		$errorMsg = (string) ( $data['message'] ?? "Voice gateway returned HTTP {$code}" );
		$this->integrationLog->record( 'voice', 'call', 'error', $errorMsg, $duration );
		return [ 'ok' => false, 'error' => $errorMsg ];
	}
}