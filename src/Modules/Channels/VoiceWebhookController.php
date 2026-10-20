<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Channels;

use VMStudio\SalesOS\Modules\Chat\ConversationRepository;
use VMStudio\SalesOS\REST\AbstractController;
use VMStudio\SalesOS\Support\IntegrationLog;
use VMStudio\SalesOS\Support\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound Voice Telephony Webhook Controller (Vapi / Retell / Twilio).
 * Receives real-time call status updates, live transcripts, and post-call analysis.
 */
final class VoiceWebhookController extends AbstractController {

	public function __construct(
		private VoiceClient $client,
		private ConversationRepository $conversations,
		private Logger $logger,
		private IntegrationLog $integrationLog
	) {}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/channels/voice/webhook',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'receive' ],
					'permission_callback' => fn( WP_REST_Request $request ) => $this->verifySignature( $request ),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/channels/voice',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'receive' ],
					'permission_callback' => fn( WP_REST_Request $request ) => $this->verifySignature( $request ),
				],
			]
		);
	}

	/**
	 * Verify webhook secret if configured.
	 */
	private function verifySignature( WP_REST_Request $request ): bool|WP_REST_Response {
		$secret = (string) get_option( 'vmsos_voice_webhook_secret', '' );
		if ( '' === $secret ) {
			return true;
		}

		$headerSecret = (string) ( $request->get_header( 'x_vapi_secret' ) ?: $request->get_header( 'x_webhook_secret' ) );
		$paramSecret  = (string) $request->get_param( 'secret' );
		$authHeader   = (string) $request->get_header( 'authorization' );
		$bearerToken  = str_starts_with( $authHeader, 'Bearer ' ) ? trim( substr( $authHeader, 7 ) ) : '';

		if (
			hash_equals( $secret, $headerSecret ) ||
			hash_equals( $secret, $paramSecret ) ||
			hash_equals( $secret, $bearerToken )
		) {
			return true;
		}

		return $this->fail( 'vmsos_voice_unauthorized', __( 'Invalid voice webhook signature or secret.', 'vm-sales-os' ), 403 );
	}

	/**
	 * Process call webhook data.
	 */
	public function receive( WP_REST_Request $request ): WP_REST_Response {
		$params = (array) $request->get_json_params();
		if ( empty( $params ) ) {
			$params = (array) $request->get_params();
		}

		// Support nested Vapi message structure or flat telephony payloads.
		$message = is_array( $params['message'] ?? null ) ? $params['message'] : [];
		$callObj = is_array( $message['call'] ?? null ) ? $message['call'] : ( is_array( $params['call'] ?? null ) ? $params['call'] : [] );

		$callId = sanitize_text_field(
			(string) ( $callObj['id'] ?? ( $message['callId'] ?? ( $params['call_id'] ?? ( $params['id'] ?? '' ) ) ) )
		);

		$rawPhone = (string) (
			$callObj['customer']['number'] ?? (
				$params['customer']['number'] ?? (
					$params['phone'] ?? (
						$params['to'] ?? ( $params['from'] ?? '' )
					)
				)
			)
		);
		$cleanPhone = preg_replace( '/[^\d+]/', '', $rawPhone ) ?? '';

		$transcript = (string) (
			$message['transcript'] ?? (
				$callObj['transcript'] ?? (
					$params['transcript'] ?? ''
				)
			)
		);

		$summary = (string) (
			$message['summary'] ?? (
				$message['analysis']['summary'] ?? (
					$params['summary'] ?? ''
				)
			)
		);

		$recordingUrl = esc_url_raw(
			(string) (
				$message['recordingUrl'] ?? (
					$callObj['recordingUrl'] ?? (
						$params['recording_url'] ?? ( $params['recordingUrl'] ?? '' )
					)
				)
			)
		);

		$duration = (int) (
			$callObj['duration'] ?? (
				$callObj['durationSeconds'] ?? (
					$params['duration'] ?? 0
				)
			)
		);

		$endedReason = sanitize_text_field(
			(string) (
				$message['endedReason'] ?? (
					$callObj['endedReason'] ?? (
						$params['ended_reason'] ?? 'completed'
					)
				)
			)
		);

		$this->logger->info( 'voice', 'Webhook received for call ' . $callId, [
			'phone'    => $cleanPhone,
			'duration' => $duration,
			'reason'   => $endedReason,
		] );

		if ( '' !== $cleanPhone ) {
			$visitorHash  = substr( hash_hmac( 'sha256', $cleanPhone, wp_salt( 'auth' ) ), 0, 48 );
			$conversation = $this->conversations->findOpenByVisitor( $visitorHash, 'voice' );

			if ( ! $conversation ) {
				$started      = $this->conversations->start( 'voice', $visitorHash );
				$conversation = $this->conversations->findByUuid( $started['uuid'] );
			}

			if ( $conversation ) {
				$convId = (int) $conversation['id'];

				// Update metadata.
				$this->conversations->setMeta(
					$convId,
					[
						'phone'         => $cleanPhone,
						'call_id'       => $callId,
						'recording_url' => $recordingUrl,
						'duration'      => $duration,
						'ended_reason'  => $endedReason,
					]
				);

				// Log transcript turn.
				if ( '' !== trim( $transcript ) ) {
					$content = 'AI Voice Discovery Call Completed (' . $duration . "s):\n";
					if ( '' !== $recordingUrl ) {
						$content .= 'Recording: ' . $recordingUrl . "\n\n";
					}
					$content .= "Transcript:\n" . trim( $transcript );

					$this->conversations->addMessage(
						$convId,
						'assistant',
						$content,
						[
							'provider' => 'voice',
							'model'    => 'telephony-vapi',
						]
					);
				}

				// If call summary is provided, attach and close conversation.
				if ( '' !== trim( $summary ) ) {
					$this->conversations->close( $convId, $summary, 'voice_discovery', 'neutral' );
				}
			}
		}

		$this->integrationLog->record(
			'voice',
			'webhook',
			'ok',
			'Call ' . $callId . ' status processed (' . $cleanPhone . ')',
			0
		);

		do_action(
			'vmsos_voice_call_completed',
			[
				'call_id'       => $callId,
				'phone'         => $cleanPhone,
				'transcript'    => $transcript,
				'summary'       => $summary,
				'recording_url' => $recordingUrl,
				'duration'      => $duration,
				'ended_reason'  => $endedReason,
			]
		);

		return $this->ok( [
			'received' => true,
			'call_id'  => $callId,
		] );
	}
}
