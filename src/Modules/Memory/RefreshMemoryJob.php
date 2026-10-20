<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Memory;

use InvalidArgumentException;
use VMStudio\SalesOS\Modules\Chat\ConversationRepository;
use VMStudio\SalesOS\Queue\JobHandler;

final class RefreshMemoryJob implements JobHandler {

	public function __construct(
		private MemoryEngine $engine,
		private ConversationRepository $conversations
	) {}

	public static function name(): string {
		return 'memory.refresh';
	}

	/** @param array<string, mixed> $payload contact_id + conversation_id. */
	public function handle( array $payload ): void {
		$contactId      = (int) ( $payload['contact_id'] ?? 0 );
		$conversationId = (int) ( $payload['conversation_id'] ?? 0 );

		if ( $contactId <= 0 || $conversationId <= 0 ) {
			throw new InvalidArgumentException( 'Missing contact_id or conversation_id.' );
		}

		$transcript = $this->conversations->transcript( $conversationId, 40 );

		if ( '' === trim( $transcript ) ) {
			return;
		}

		$this->engine->refresh( $contactId, $transcript );
	}
}
