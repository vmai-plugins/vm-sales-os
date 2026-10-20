<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Channels;

use VMStudio\SalesOS\AI\ProviderManager;
use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Core\Module;
use VMStudio\SalesOS\Modules\Chat\ChatService;
use VMStudio\SalesOS\Modules\Chat\ConversationRepository;
use VMStudio\SalesOS\Modules\CRM\ContactRepository;
use VMStudio\SalesOS\REST\RestRouter;
use VMStudio\SalesOS\Support\IntegrationLog;
use VMStudio\SalesOS\Support\Logger;
use VMStudio\SalesOS\Support\Vault;

/**
 * Chatwoot as the operator surface.
 *
 * Sales OS keeps the assistant, the lead record, the knowledge and the
 * sequences. Chatwoot keeps the inbox the team already works in and the mobile
 * app they already have on their phones. Neither side reimplements the other:
 * when the assistant needs a human, the thread appears in Chatwoot with its
 * transcript, and when a human answers there it comes straight back out on the
 * channel the visitor is on.
 */
final class ChatwootModule extends Module {

	public static function slug(): string {
		return 'chatwoot';
	}

	public static function label(): string {
		return __( 'Chatwoot inbox', 'vm-sales-os' );
	}

	public static function requires(): array {
		return [ 'chat', 'crm' ];
	}

	public function register( Container $container ): void {
		$container->singleton(
			ChatwootClient::class,
			static fn( Container $c ) => new ChatwootClient(
				$c->get( Vault::class ),
				$c->get( IntegrationLog::class ),
				$c->get( Logger::class )
			)
		);
	}

	public function boot( Container $container ): void {
		$container->get( RestRouter::class )->add( ChatwootWebhookController::class );

		// The assistant gave up on a thread: put it in front of a human with
		// everything they need to answer without reading backwards.
		add_action(
			'vmsos_event_conversation.awaiting_human',
			fn( array $payload ) => $this->pushHandover( $container, $payload )
		);

		// A stage set in Chatwoot is the operator's judgement. Trust it.
		add_action(
			'vmsos_chatwoot_stage_changed',
			static function ( int $contactId, string $email, string $stage ) use ( $container ): void {
				$contacts = $container->get( ContactRepository::class );
				$contact  = $contactId > 0 ? [ 'id' => $contactId ] : $contacts->findByEmail( $email );

				if ( ! $contact || empty( $contact['id'] ) ) {
					return;
				}

				$contacts->setScore(
					(int) $contact['id'],
					(int) ( $contact['score'] ?? 0 ),
					$stage,
					__( 'Set by an agent in Chatwoot.', 'vm-sales-os' )
				);
			},
			10,
			3
		);
	}

	/**
	 * Mirror a handed-over conversation into Chatwoot.
	 *
	 * @param array<string, mixed> $payload Event payload from ChatService.
	 */
	private function pushHandover( Container $container, array $payload ): void {
		$client = $container->get( ChatwootClient::class );

		if ( ! $client->configured() ) {
			return;
		}

		$conversations  = $container->get( ConversationRepository::class );
		$conversationId = (int) ( $payload['conversation_id'] ?? 0 );
		$conversation   = $conversationId > 0 ? $conversations->find( $conversationId ) : null;

		if ( ! $conversation ) {
			return;
		}

		$meta = json_decode( (string) ( $conversation['meta'] ?? '' ), true );
		$meta = is_array( $meta ) ? $meta : [];

		// Already mirrored: just add the new turn and let the existing thread
		// carry it, rather than opening a second conversation for one visitor.
		if ( ! empty( $meta['chatwoot_conversation_id'] ) ) {
			$client->addMessage(
				(int) $meta['chatwoot_conversation_id'],
				(string) ( $payload['message'] ?? '' ),
				'incoming'
			);
			return;
		}

		$contactId = (int) ( $payload['contact_id'] ?? 0 );
		$contact   = $contactId > 0 ? $container->get( ContactRepository::class )->find( $contactId ) : null;

		$remote = $client->upsertContact(
			[
				'id'    => $contactId,
				'name'  => (string) ( $contact['name'] ?? __( 'Visitor', 'vm-sales-os' ) ),
				'email' => (string) ( $contact['email'] ?? $meta['email'] ?? '' ),
				'phone' => (string) ( $contact['phone'] ?? $meta['phone'] ?? '' ),
			]
		);

		if ( ! $remote || empty( $remote['id'] ) ) {
			return;
		}

		$thread = $client->createConversation(
			(int) $remote['id'],
			[
				'vmsos_uuid'    => (string) ( $conversation['uuid'] ?? '' ),
				'vmsos_channel' => (string) ( $conversation['channel'] ?? 'web' ),
			]
		);

		if ( ! $thread || empty( $thread['id'] ) ) {
			return;
		}

		$chatwootId = (int) $thread['id'];

		$conversations->setMeta( $conversationId, $meta + [ 'chatwoot_conversation_id' => $chatwootId ] );

		// The agent opens this thread cold. Give them the reason and the
		// transcript before they have to ask for either.
		$client->addMessage(
			$chatwootId,
			__( 'Handed over by the assistant. Reply here and it goes back to the visitor on their own channel.', 'vm-sales-os' ),
			'outgoing',
			true
		);

		$transcript = $conversations->transcript( $conversationId );

		if ( '' !== $transcript ) {
			$client->addMessage( $chatwootId, $transcript, 'outgoing', true );
		}

		$client->addLabels( $chatwootId, [ 'vmsos-handover', (string) ( $conversation['channel'] ?? 'web' ) ] );
	}
}
