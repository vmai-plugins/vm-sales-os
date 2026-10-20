<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Channels;

use VMStudio\SalesOS\AI\AiException;
use VMStudio\SalesOS\AI\DTO\ChatRequest;
use VMStudio\SalesOS\AI\DTO\Message;
use VMStudio\SalesOS\AI\ProviderManager;
use VMStudio\SalesOS\Modules\Chat\ChatService;
use VMStudio\SalesOS\Modules\Chat\ConversationRepository;
use VMStudio\SalesOS\REST\AbstractController;
use VMStudio\SalesOS\Support\Logger;
use WP_REST_Request;

/**
 * Inbound Chatwoot.
 *
 * Two directions matter here and they are deliberately kept apart:
 *
 * - An agent replies inside Chatwoot (or the Chatwoot mobile app). The thread
 *   is marked as human-owned in Sales OS so the assistant stops answering, and
 *   the reply is relayed out over whatever channel the visitor is actually on.
 * - A message arrives on a channel Chatwoot owns end to end (email, LinkedIn,
 *   Twitter). Sales OS answers it with the same assistant the website widget
 *   uses, so the visitor gets one voice regardless of where they wrote from.
 */
final class ChatwootWebhookController extends AbstractController {

	/** Channels we let the assistant answer on Chatwoot's behalf. */
	private const ADOPTABLE = [ 'channel/email', 'channel/linkedin', 'channel/twitter' ];

	public function __construct(
		private ChatwootClient $client,
		private ChatService $chat,
		private ConversationRepository $conversations,
		private ProviderManager $ai,
		private Logger $logger
	) {}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/channels/chatwoot',
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
	 * Chatwoot signs webhooks with a shared secret. Without one configured we
	 * refuse: an open endpoint that starts AI conversations is an invitation to
	 * burn the API budget.
	 */
	private function verifySignature( WP_REST_Request $request ) {
		$secret = $this->client->webhookSecret();

		if ( '' === $secret ) {
			return $this->fail( 'vmsos_no_secret', __( 'Chatwoot webhook secret is not configured.', 'vm-sales-os' ), 403 );
		}

		$given    = (string) $request->get_header( 'x_chatwoot_signature' );
		$expected = hash_hmac( 'sha256', (string) $request->get_body(), $secret );

		if ( '' === $given || ! hash_equals( $expected, $given ) ) {
			return $this->fail( 'vmsos_bad_signature', __( 'Signature mismatch.', 'vm-sales-os' ), 403 );
		}

		return true;
	}

	public function receive( WP_REST_Request $request ) {
		$payload = (array) $request->get_json_params();
		$event   = (string) ( $payload['event'] ?? '' );

		if ( in_array( $event, [ 'contact_updated', 'conversation_updated', 'conversation_status_changed' ], true ) ) {
			$this->syncState( $payload );
			return $this->ok( [ 'received' => true ] );
		}

		if ( 'message_created' === $event ) {
			$this->handleMessage( $payload );
		}

		// Always 200. Chatwoot retries on anything else and a duplicate AI turn
		// costs more than a dropped webhook.
		return $this->ok( [ 'received' => true ] );
	}

	/** @param array<string, mixed> $payload Chatwoot webhook body. */
	private function handleMessage( array $payload ): void {
		$chatwootId = (int) ( $payload['conversation']['id'] ?? 0 );
		$content    = trim( (string) ( $payload['content'] ?? '' ) );
		$type       = (string) ( $payload['message_type'] ?? '' );
		$isPrivate  = (bool) ( $payload['private'] ?? false );

		if ( $chatwootId <= 0 || '' === $content ) {
			return;
		}

		if ( 'outgoing' === $type ) {
			$this->handleAgentReply( $chatwootId, $content, $isPrivate );
			return;
		}

		if ( 'incoming' === $type ) {
			$this->handleVisitorMessage( $chatwootId, $content, $payload );
		}
	}

	/**
	 * An agent typed in Chatwoot. Hand the thread to the human and deliver the
	 * reply on the visitor's own channel.
	 */
	private function handleAgentReply( int $chatwootId, string $content, bool $isPrivate ): void {
		$conversation = $this->conversations->findByChatwootId( $chatwootId );

		if ( ! $conversation ) {
			return;
		}

		$conversationId = (int) $conversation['id'];

		// Private notes are agent-to-agent, never relayed. One command is
		// understood: give the thread back to the assistant.
		if ( $isPrivate ) {
			if ( in_array( strtolower( $content ), [ '/unmute', '/release', '/ai' ], true ) ) {
				$this->conversations->release( $conversationId );
				$this->client->addMessage(
					$chatwootId,
					__( 'The assistant is answering this thread again.', 'vm-sales-os' ),
					'outgoing',
					true
				);
			}
			return;
		}

		// A human is talking, so the assistant stops. This is the same handover
		// state the Sales OS inbox uses — no parallel mute flag to drift.
		if ( empty( $conversation['handover'] ) ) {
			$this->conversations->takeOver( $conversationId, 0 );
		}

		$this->conversations->addMessage( $conversationId, 'agent', $content );

		// Reuse the delivery path every channel already listens on, so WhatsApp
		// and anything added later work without touching this class.
		do_action( 'vmsos_outbound_message', $conversation, $content );

		$this->logger->info( 'chatwoot', 'Agent reply relayed', [ 'conversation_id' => $conversationId ] );
	}

	/**
	 * A visitor wrote in on a Chatwoot-owned channel.
	 *
	 * @param array<string, mixed> $payload Chatwoot webhook body.
	 */
	private function handleVisitorMessage( int $chatwootId, string $content, array $payload ): void {
		$conversation = $this->conversations->findByChatwootId( $chatwootId );

		if ( ! $conversation ) {
			$channel = (string) ( $payload['inbox']['channel_type'] ?? '' );

			// Threads that started in Sales OS already have a row. Anything else
			// is only adopted for channels we were told to answer on.
			if ( ! in_array( $channel, (array) apply_filters( 'vmsos_chatwoot_adoptable_channels', self::ADOPTABLE ), true ) ) {
				return;
			}

			$sender      = (array) ( $payload['sender'] ?? [] );
			$visitorHash = substr(
				hash_hmac( 'sha256', 'chatwoot:' . $chatwootId, wp_salt( 'auth' ) ),
				0,
				48
			);

			$started        = $this->conversations->start( str_replace( 'channel/', '', $channel ), $visitorHash );
			$conversationId = (int) $started['id'];

			$this->conversations->setMeta(
				$conversationId,
				[
					'chatwoot_conversation_id' => $chatwootId,
					'email'                    => sanitize_email( (string) ( $sender['email'] ?? '' ) ),
					'phone'                    => (string) ( $sender['phone_number'] ?? '' ),
				]
			);

			$conversation = $this->conversations->findByUuid( (string) $started['uuid'] );

			if ( ! $conversation ) {
				return;
			}
		}

		$conversationId = (int) $conversation['id'];

		// A reply of any kind stops marketing sequences chasing this contact.
		if ( ! empty( $conversation['contact_id'] ) ) {
			do_action( 'vmsos_contact_replied', (int) $conversation['contact_id'] );
		}

		// Human owns the thread: log it, help the agent, and stay quiet.
		if ( ! empty( $conversation['handover'] ) ) {
			$this->conversations->addMessage( $conversationId, 'user', $content );
			$this->postTranslation( $chatwootId, $content );
			return;
		}

		$result = $this->chat->reply(
			(string) $conversation['uuid'],
			$content,
			[
				'channel'      => (string) ( $conversation['channel'] ?? 'chatwoot' ),
				'visitor_hash' => (string) ( $conversation['visitor_hash'] ?? '' ),
			]
		);

		$reply = trim( (string) $result['reply'] );

		if ( '' !== $reply ) {
			$this->client->addMessage( $chatwootId, $reply );
		}

		if ( ! empty( $result['handover'] ) ) {
			$this->client->addMessage(
				$chatwootId,
				__( 'The assistant asked for a human on this thread.', 'vm-sales-os' ),
				'outgoing',
				true
			);
		}
	}

	/**
	 * Agents should never have to paste a message into a translator. If the
	 * visitor did not write in the site language, drop the English version in as
	 * a private note.
	 */
	private function postTranslation( int $chatwootId, string $content ): void {
		if ( ! apply_filters( 'vmsos_chatwoot_auto_translate', true ) ) {
			return;
		}

		try {
			$response = $this->ai->chat(
				new ChatRequest(
					[
						Message::system( 'Translate the user message into English. If it is already English, reply with exactly SKIP and nothing else.' ),
						Message::user( $content ),
					],
					temperature: 0.0,
					maxTokens: 400,
					operation: 'translate'
				)
			);
		} catch ( AiException $e ) {
			$this->logger->error( 'chatwoot', 'Translation failed', [ 'error' => $e->getMessage() ] );
			return;
		}

		$translation = trim( $response->content );

		if ( '' === $translation || str_contains( strtoupper( $translation ), 'SKIP' ) ) {
			return;
		}

		$this->client->addMessage(
			$chatwootId,
			sprintf( /* translators: %s: translated message */ __( "Translation: %s", 'vm-sales-os' ), $translation ),
			'outgoing',
			true
		);
	}

	/**
	 * Chatwoot is the operator's screen, so a stage set there is the truth.
	 * Labels are accepted as well as custom attributes, because labels are what
	 * agents actually use.
	 *
	 * @param array<string, mixed> $payload Chatwoot webhook body.
	 */
	private function syncState( array $payload ): void {
		$stage  = (string) ( $payload['custom_attributes']['stage'] ?? '' );
		$stages = (array) apply_filters(
			'vmsos_chatwoot_stage_labels',
			[ 'new', 'warm', 'hot', 'qualified', 'won', 'lost' ]
		);

		if ( '' === $stage ) {
			foreach ( (array) ( $payload['labels'] ?? [] ) as $label ) {
				if ( in_array( strtolower( (string) $label ), $stages, true ) ) {
					$stage = strtolower( (string) $label );
					break;
				}
			}
		}

		if ( '' === $stage ) {
			return;
		}

		$contactId = (int) ( $payload['custom_attributes']['vmsos_contact_id'] ?? 0 );
		$email     = sanitize_email( (string) ( $payload['email'] ?? $payload['meta']['sender']['email'] ?? '' ) );

		/**
		 * Applied when Chatwoot reports a stage change. The CRM module listens
		 * and writes it to the contact record.
		 *
		 * @param int    $contactId Sales OS contact id, 0 if unknown.
		 * @param string $email     Fallback identifier.
		 * @param string $stage     New stage slug.
		 */
		do_action( 'vmsos_chatwoot_stage_changed', $contactId, $email, $stage );
	}
}
