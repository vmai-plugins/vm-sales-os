<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Inbox;

use VMStudio\SalesOS\Core\EventBus;
use VMStudio\SalesOS\Modules\Chat\ConversationRepository;
use VMStudio\SalesOS\Support\Logger;

/**
 * Human takeover. The assistant answers by default; this is how a person
 * steps in, answers, and hands the thread back.
 *
 * Delivery is deliberately not this class's problem: it writes the turn and
 * fires vmsos_outbound_message. The web widget picks it up by polling, and a
 * channel adapter (WhatsApp and friends) delivers it wherever the thread lives.
 */
final class InboxService {

	public function __construct(
		private ConversationRepository $conversations,
		private EventBus $events,
		private Logger $logger
	) {}

	/** @return array<string, mixed>|null */
	public function thread( int $conversationId ): ?array {
		$conversation = $this->conversations->find( $conversationId );

		if ( ! $conversation ) {
			return null;
		}

		return [
			'conversation' => $conversation,
			'messages'     => $this->conversations->messages( $conversationId ),
			'assignee'     => $this->assigneeName( (int) $conversation['assigned_to'] ),
		];
	}

	public function takeOver( int $conversationId, int $userId ): bool {
		if ( ! $this->conversations->find( $conversationId ) ) {
			return false;
		}

		$this->conversations->takeOver( $conversationId, $userId );
		$this->events->dispatch( 'conversation.taken_over', [ 'conversation_id' => $conversationId, 'user_id' => $userId ] );

		return true;
	}

	public function release( int $conversationId ): bool {
		if ( ! $this->conversations->find( $conversationId ) ) {
			return false;
		}

		$this->conversations->release( $conversationId );
		$this->events->dispatch( 'conversation.released', [ 'conversation_id' => $conversationId ] );

		return true;
	}

	public function assign( int $conversationId, int $userId ): bool {
		if ( ! $this->conversations->find( $conversationId ) ) {
			return false;
		}

		$this->conversations->assign( $conversationId, $userId );
		return true;
	}

	/**
	 * Post a human reply into the thread.
	 *
	 * @return array<string, mixed>|null
	 */
	public function reply( int $conversationId, string $body, int $userId ): ?array {
		$conversation = $this->conversations->find( $conversationId );
		$body         = trim( $body );

		if ( ! $conversation || '' === $body ) {
			return null;
		}

		// Answering implies ownership — nobody wants the bot talking over them.
		if ( empty( $conversation['handover'] ) ) {
			$this->conversations->takeOver( $conversationId, $userId );
		} elseif ( 0 === (int) $conversation['assigned_to'] ) {
			$this->conversations->assign( $conversationId, $userId );
		}

		$messageId = $this->conversations->addMessage(
			$conversationId,
			'agent',
			$body,
			[ 'provider' => 'human', 'model' => (string) $userId ]
		);

		$conversation = $this->conversations->find( $conversationId ) ?? $conversation;

		/**
		 * Deliver the reply on whatever channel the thread belongs to.
		 *
		 * @param array<string, mixed> $conversation Conversation row.
		 * @param string               $body         Message body.
		 */
		do_action( 'vmsos_outbound_message', $conversation, $body );

		$this->events->dispatch(
			'conversation.human_replied',
			[
				'conversation_id' => $conversationId,
				'user_id'         => $userId,
				'channel'         => (string) $conversation['channel'],
			]
		);

		$this->logger->info( 'inbox', 'Human reply sent', [ 'conversation_id' => $conversationId, 'user_id' => $userId ] );

		return [ 'id' => $messageId, 'role' => 'agent', 'content' => $body ];
	}

	/** Internal note: visible to the team, never to the visitor, never to the model. */
	public function note( int $conversationId, string $body, int $userId ): int {
		if ( '' === trim( $body ) || ! $this->conversations->find( $conversationId ) ) {
			return 0;
		}

		return $this->conversations->addMessage(
			$conversationId,
			'note',
			trim( $body ),
			[ 'provider' => 'human', 'model' => (string) $userId ]
		);
	}

	/**
	 * @param array<string, mixed> $filters Inbox filters.
	 * @return array<string, mixed>
	 */
	public function inbox( array $filters, int $perPage, int $page ): array {
		$rows     = $this->conversations->search( $filters, $perPage, $page );
		$previews = $this->conversations->previews( array_column( $rows, 'id' ) );

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['assignee'] = $this->assigneeName( (int) $row['assigned_to'] );
			$rows[ $index ]['preview']  = $previews[ (int) $row['id'] ] ?? '';
		}

		return [ 'conversations' => $rows, 'counts' => $this->conversations->counts() ];
	}

	private function assigneeName( int $userId ): string {
		if ( $userId <= 0 ) {
			return '';
		}

		$user = get_userdata( $userId );
		return $user ? $user->display_name : '';
	}
}
