<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Memory;

use VMStudio\SalesOS\AI\ProviderManager;
use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Core\EventBus;
use VMStudio\SalesOS\Core\Module;
use VMStudio\SalesOS\Modules\Chat\ConversationRepository;
use VMStudio\SalesOS\Queue\QueueManager;
use VMStudio\SalesOS\Support\Logger;

/**
 * Cross-session lead memory. Reads into every prompt, refreshes in the
 * background, and never blocks a reply.
 */
final class MemoryModule extends Module {

	public static function slug(): string {
		return 'memory';
	}

	public static function label(): string {
		return __( 'Lead memory', 'vm-sales-os' );
	}

	public static function requires(): array {
		return [ 'chat', 'crm' ];
	}

	public function register( Container $container ): void {
		$container->singleton( MemoryRepository::class, static fn() => new MemoryRepository() );

		$container->singleton(
			MemoryEngine::class,
			static fn( Container $c ) => new MemoryEngine(
				$c->get( MemoryRepository::class ),
				$c->get( ProviderManager::class ),
				$c->get( EventBus::class ),
				$c->get( Logger::class )
			)
		);
	}

	public function boot( Container $container ): void {
		$container->get( QueueManager::class )->registerHandler( RefreshMemoryJob::class );

		// Read side: append what we know to the system prompt.
		add_filter(
			'vmsos_chat_system_prompt',
			static function ( string $prompt, int $conversationId, array $context ) use ( $container ): string {
				$contactId = (int) ( $context['contact_id'] ?? 0 );

				if ( $contactId <= 0 ) {
					return $prompt;
				}

				$memory = $container->get( MemoryEngine::class )->forPrompt( $contactId );

				return '' === $memory ? $prompt : $prompt . "\n\n" . $memory;
			},
			10,
			3
		);

		add_filter(
			'vmsos_contact_memory_summary',
			static function ( string $memory, int $contactId ) use ( $container ): string {
				return $container->get( MemoryEngine::class )->forPrompt( $contactId );
			},
			10,
			2
		);

		// Write side: count turns, refresh every few.
		$container->get( EventBus::class )->listen(
			'chat.replied',
			static function ( array $payload ) use ( $container ): void {
				$conversationId = (int) ( $payload['conversation_id'] ?? 0 );

				if ( $conversationId <= 0 ) {
					return;
				}

				$conversation = $container->get( ConversationRepository::class )->find( $conversationId );
				$contactId    = $conversation ? (int) $conversation['contact_id'] : 0;

				if ( $contactId <= 0 ) {
					return;
				}

				$turns = $container->get( MemoryRepository::class )->countTurn( $contactId );

				if ( 0 !== $turns % MemoryEngine::refreshEvery() ) {
					return;
				}

				$container->get( QueueManager::class )->push(
					RefreshMemoryJob::name(),
					[ 'contact_id' => $contactId, 'conversation_id' => $conversationId ]
				);
			}
		);

		// A closed conversation is the most informative moment there is.
		$container->get( EventBus::class )->listen(
			'conversation.closed',
			static function ( array $payload ) use ( $container ): void {
				$contactId = (int) ( $payload['contact_id'] ?? 0 );

				if ( $contactId > 0 ) {
					$container->get( QueueManager::class )->push(
						RefreshMemoryJob::name(),
						[
							'contact_id'      => $contactId,
							'conversation_id' => (int) ( $payload['conversation_id'] ?? 0 ),
						]
					);
				}
			}
		);
	}
}
