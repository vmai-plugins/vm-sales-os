<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Channels;

use VMStudio\SalesOS\Modules\Chat\ChatService;
use VMStudio\SalesOS\Modules\Chat\ConversationRepository;
use VMStudio\SalesOS\REST\AbstractController;
use VMStudio\SalesOS\Support\Logger;
use WP_REST_Request;

/**
 * Inbound Webhook handler for Evolution API.
 * Receives messages.upsert events from Evolution API container.
 */
final class EvolutionWebhookController extends AbstractController {

	public function __construct(
		private EvolutionClient $client,
		private ChatService $chat,
		private ConversationRepository $conversations,
		private Logger $logger
	) {}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/channels/evolution',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'receive' ],
					'permission_callback' => '__return_true',
				],
			]
		);
	}

	public function receive( WP_REST_Request $request ) {
		$payload = (array) $request->get_json_params();
		$event   = (string) ( $payload['event'] ?? '' );

		if ( 'messages.upsert' !== $event && 'messages' !== $event ) {
			return $this->ok( [ 'ignored' => true, 'event' => $event ] );
		}

		$data    = (array) ( $payload['data'] ?? [] );
		$key     = (array) ( $data['key'] ?? [] );
		$fromMe  = ! empty( $key['fromMe'] );

		// Ignore messages sent by ourselves.
		if ( $fromMe ) {
			return $this->ok( [ 'from_me' => true ] );
		}

		$remoteJid = (string) ( $key['remoteJid'] ?? '' );
		if ( str_contains( $remoteJid, '@g.us' ) ) {
			// Ignore group chats for sales CRM.
			return $this->ok( [ 'group' => true ] );
		}

		$from = preg_replace( '/[^0-9]/', '', explode( '@', $remoteJid )[0] ) ?? '';
		if ( '' === $from ) {
			return $this->ok( [ 'empty_sender' => true ] );
		}

		$message = (array) ( $data['message'] ?? [] );
		$text    = (string) (
			$message['conversation']
			?? ( $message['extendedTextMessage']['text'] ?? '' )
		);

		if ( '' === trim( $text ) ) {
			return $this->ok( [ 'empty_body' => true ] );
		}

		$visitorHash  = substr( hash_hmac( 'sha256', $from, wp_salt( 'auth' ) ), 0, 48 );
		$conversation = $this->conversations->findOpenByVisitor( $visitorHash, 'whatsapp' );
		$uuid         = $conversation ? (string) $conversation['uuid'] : '';

		$result = $this->chat->reply(
			$uuid,
			$text,
			[ 'channel' => 'whatsapp', 'visitor_hash' => $visitorHash ]
		);

		if ( ! $conversation ) {
			$fresh = $this->conversations->findByUuid( (string) $result['uuid'] );
			if ( $fresh ) {
				$this->conversations->setMeta( (int) $fresh['id'], [ 'phone' => $from, 'provider' => 'evolution' ] );
			}
		}

		if ( '' !== trim( (string) ( $result['reply'] ?? '' ) ) ) {
			$this->client->send( $from, (string) $result['reply'] );
		}

		$this->logger->info( 'evolution', 'Inbound handled', [ 'from' => $from, 'handover' => ! empty( $result['handover'] ) ] );

		return $this->ok( [ 'received' => true ] );
	}
}
