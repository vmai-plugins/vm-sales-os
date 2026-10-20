<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Channels;

use VMStudio\SalesOS\Modules\Chat\ChatService;
use VMStudio\SalesOS\Modules\Chat\ConversationRepository;
use VMStudio\SalesOS\REST\AbstractController;
use VMStudio\SalesOS\Support\Logger;
use WP_REST_Request;

/**
 * Inbound WhatsApp. Meta calls this; nobody else should be able to.
 */
final class WhatsAppWebhookController extends AbstractController {

	public function __construct(
		private WhatsAppClient $client,
		private ChatService $chat,
		private ConversationRepository $conversations,
		private Logger $logger
	) {}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/channels/whatsapp',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'verify' ],
					'permission_callback' => '__return_true',
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'receive' ],
					'permission_callback' => fn( WP_REST_Request $request ) => $this->verifySignature( $request ),
				],
			]
		);
	}

	/** Meta's subscription handshake. */
	public function verify( WP_REST_Request $request ) {
		$expected = $this->client->verifyToken();
		$given    = (string) $request->get_param( 'hub_verify_token' );

		if ( '' === $expected || ! hash_equals( $expected, $given ) ) {
			return $this->fail( 'vmsos_bad_token', __( 'Verification failed.', 'vm-sales-os' ), 403 );
		}

		// Meta expects the raw challenge string, not JSON.
		header( 'Content-Type: text/plain' );
		echo esc_html( (string) $request->get_param( 'hub_challenge' ) );
		exit;
	}

	/**
	 * Payloads are signed with the app secret. Without one configured we
	 * refuse rather than trust the open internet.
	 */
	private function verifySignature( WP_REST_Request $request ) {
		$secret = $this->client->appSecret();

		if ( '' === $secret ) {
			return $this->fail( 'vmsos_no_secret', __( 'Webhook secret is not configured.', 'vm-sales-os' ), 403 );
		}

		$signature = (string) $request->get_header( 'x_hub_signature_256' );
		$expected  = 'sha256=' . hash_hmac( 'sha256', (string) $request->get_body(), $secret );

		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			return $this->fail( 'vmsos_bad_signature', __( 'Signature mismatch.', 'vm-sales-os' ), 403 );
		}

		return true;
	}

	public function receive( WP_REST_Request $request ) {
		$payload = (array) $request->get_json_params();

		foreach ( (array) ( $payload['entry'] ?? [] ) as $entry ) {
			foreach ( (array) ( $entry['changes'] ?? [] ) as $change ) {
				$value = (array) ( $change['value'] ?? [] );

				foreach ( (array) ( $value['messages'] ?? [] ) as $message ) {
					$this->handleMessage( (array) $message );
				}
			}
		}

		// Always 200: Meta retries aggressively on anything else.
		return $this->ok( [ 'received' => true ] );
	}

	/** @param array<string, mixed> $message Inbound message object. */
	private function handleMessage( array $message ): void {
		$from = preg_replace( '/[^0-9]/', '', (string) ( $message['from'] ?? '' ) ) ?? '';
		$text = (string) ( $message['text']['body'] ?? '' );

		if ( '' === $from || '' === trim( $text ) ) {
			return;
		}

		$visitorHash  = substr( hash_hmac( 'sha256', $from, wp_salt( 'auth' ) ), 0, 48 );
		$conversation = $this->conversations->findOpenByVisitor( $visitorHash, 'whatsapp' );
		$uuid         = $conversation ? (string) $conversation['uuid'] : '';

		$result = $this->chat->reply(
			$uuid,
			$text,
			[ 'channel' => 'whatsapp', 'visitor_hash' => $visitorHash ]
		);

		// Store the phone number once, so a human replying from the inbox
		// knows where the message has to go back to.
		if ( ! $conversation ) {
			$fresh = $this->conversations->findByUuid( (string) $result['uuid'] );
			if ( $fresh ) {
				$this->conversations->setMeta( (int) $fresh['id'], [ 'phone' => $from ] );
			}
		}

		if ( '' !== trim( (string) $result['reply'] ) ) {
			$this->client->send( $from, (string) $result['reply'] );
		}

		$this->logger->info( 'whatsapp', 'Inbound handled', [ 'handover' => ! empty( $result['handover'] ) ] );
	}
}
