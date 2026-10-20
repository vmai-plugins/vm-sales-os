<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Chat;

use VMStudio\SalesOS\Database\Repository;

final class ConversationRepository extends Repository {

	protected function table(): string {
		return 'vmsos_conversations';
	}

	private function messagesTable(): string {
		return $this->db->prefix . 'vmsos_messages';
	}

	/** @return array<string, mixed>|null */
	public function findByUuid( string $uuid ): ?array {
		if ( ! wp_is_uuid( $uuid ) ) {
			return null;
		}

		$row = $this->db->get_row( // phpcs:ignore WordPress.DB
			$this->db->prepare( 'SELECT * FROM `' . $this->name() . '` WHERE brand_id = %d AND uuid = %s', $this->brand(), $uuid ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function start( string $channel, string $visitorHash ): array {
		$uuid = wp_generate_uuid4();

		$id = $this->insert(
			[
				'uuid'            => $uuid,
				'channel'         => sanitize_key( $channel ),
				'visitor_hash'    => $visitorHash,
				'status'          => 'open',
				'last_message_at' => $this->now(),
				'created_at'      => $this->now(),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return [ 'id' => $id, 'uuid' => $uuid ];
	}

	public function addMessage( int $conversationId, string $role, string $content, array $meta = [] ): int {
		$this->db->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->messagesTable(),
			[
				'brand_id'        => $this->brand(),
				'conversation_id' => $conversationId,
				'role'            => sanitize_key( $role ),
				'content'         => $content,
				'tool_calls'      => ! empty( $meta['tool_calls'] ) ? wp_json_encode( $meta['tool_calls'] ) : null,
				'provider'        => sanitize_key( (string) ( $meta['provider'] ?? '' ) ),
				'model'           => sanitize_text_field( (string) ( $meta['model'] ?? '' ) ),
				'tokens_in'       => (int) ( $meta['tokens_in'] ?? 0 ),
				'tokens_out'      => (int) ( $meta['tokens_out'] ?? 0 ),
				'latency_ms'      => (int) ( $meta['latency_ms'] ?? 0 ),
				'created_at'      => $this->now(),
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ]
		);

		$this->update( $conversationId, [ 'last_message_at' => $this->now() ], [ '%s' ] );

		return (int) $this->db->insert_id;
	}

	/** @return array<int, array<string, mixed>> */
	public function history( int $conversationId, int $limit = 20 ): array {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT role, content FROM `' . $this->messagesTable() . '`
				 WHERE conversation_id = %d AND brand_id = %d AND role IN (%s, %s, %s)
				 ORDER BY id DESC LIMIT %d',
				$conversationId,
				$this->brand(),
				'user',
				'assistant',
				'agent',
				max( 1, min( 60, $limit ) )
			),
			ARRAY_A
		) ?: [];

		// A human reply is still an assistant turn as far as the model is concerned.
		foreach ( $rows as $index => $row ) {
			if ( 'agent' === $row['role'] ) {
				$rows[ $index ]['role'] = 'assistant';
			}
		}

		return array_reverse( $rows );
	}

	public function transcript( int $conversationId, int $limit = 40 ): string {
		$lines = [];
		foreach ( $this->history( $conversationId, $limit ) as $row ) {
			$lines[] = ( 'assistant' === $row['role'] ? 'Assistant: ' : 'Visitor: ' ) . $row['content'];
		}
		return implode( "\n", $lines );
	}

	public function attachContact( int $conversationId, int $contactId ): void {
		$this->update( $conversationId, [ 'contact_id' => $contactId ], [ '%d' ] );
	}

	public function requestHandover( int $conversationId ): void {
		$this->update( $conversationId, [ 'handover' => 1, 'status' => 'waiting' ], [ '%d', '%s' ] );
	}

	public function close( int $conversationId, string $summary = '', string $intent = '', string $sentiment = '' ): void {
		$this->update(
			$conversationId,
			[
				'status'    => 'closed',
				'summary'   => mb_substr( $summary, 0, 2000 ),
				'intent'    => sanitize_text_field( $intent ),
				'sentiment' => sanitize_key( $sentiment ),
			],
			[ '%s', '%s', '%s', '%s' ]
		);
	}

	/** @return array<int, array<string, mixed>> */
	public function recent( int $limit = 25, string $status = '' ): array {
		$sql  = 'SELECT * FROM `' . $this->name() . '` WHERE brand_id = %d';
		$args = [ $this->brand() ];

		if ( '' !== $status ) {
			$sql   .= ' AND status = %s';
			$args[] = sanitize_key( $status );
		}

		$sql   .= ' ORDER BY last_message_at DESC LIMIT %d';
		$args[] = max( 1, min( 100, $limit ) );

		return $this->db->get_results( $this->db->prepare( $sql, $args ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB
	}

	/**
	 * Full thread for the operator inbox: every visible turn, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function messages( int $conversationId, int $limit = 200 ): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT id, role, content, provider, model, latency_ms, created_at
				 FROM `' . $this->messagesTable() . '`
				 WHERE conversation_id = %d AND brand_id = %d AND role <> %s
				 ORDER BY id ASC LIMIT %d',
				$conversationId,
				$this->brand(),
				'tool',
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Turns newer than a known id — the widget and the inbox both poll this.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function messagesSince( int $conversationId, int $afterId, int $limit = 30 ): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT id, role, content, created_at
				 FROM `' . $this->messagesTable() . '`
				 WHERE conversation_id = %d AND brand_id = %d AND id > %d AND role IN (%s, %s, %s)
				 ORDER BY id ASC LIMIT %d',
				$conversationId,
				$this->brand(),
				max( 0, $afterId ),
				'user',
				'assistant',
				'agent',
				max( 1, min( 50, $limit ) )
			),
			ARRAY_A
		) ?: [];
	}

	public function lastMessageId( int $conversationId ): int {
		return (int) $this->db->get_var( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT MAX(id) FROM `' . $this->messagesTable() . '` WHERE conversation_id = %d AND brand_id = %d',
				$conversationId,
				$this->brand()
			)
		);
	}

	/** A human takes the wheel: the AI stops answering this thread. */
	public function takeOver( int $conversationId, int $userId ): void {
		$this->update(
			$conversationId,
			[ 'handover' => 1, 'status' => 'human', 'assigned_to' => $userId ],
			[ '%d', '%s', '%d' ]
		);
	}

	/** Give the thread back to the assistant. */
	public function release( int $conversationId ): void {
		$this->update( $conversationId, [ 'handover' => 0, 'status' => 'open' ], [ '%d', '%s' ] );
	}

	public function assign( int $conversationId, int $userId ): void {
		$this->update( $conversationId, [ 'assigned_to' => $userId ], [ '%d' ] );
	}

	public function reopen( int $conversationId ): void {
		$this->update( $conversationId, [ 'status' => 'open' ], [ '%s' ] );
	}

	/** @param array<string, mixed> $meta Arbitrary channel metadata. */
	public function setMeta( int $conversationId, array $meta ): void {
		$this->update( $conversationId, [ 'meta' => (string) wp_json_encode( $meta ) ], [ '%s' ] );
	}

	/** Channel adapters (WhatsApp and friends) resolve their thread this way. */
	public function findOpenByVisitor( string $visitorHash, string $channel ): ?array {
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT * FROM `' . $this->name() . '`
				 WHERE brand_id = %d AND visitor_hash = %s AND channel = %s AND status <> %s
				 ORDER BY id DESC LIMIT 1',
				$this->brand(),
				$visitorHash,
				sanitize_key( $channel ),
				'closed'
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/** @return array<string, int> */
	public function counts(): array {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT status, COUNT(*) AS total FROM `' . $this->name() . '` WHERE brand_id = %d GROUP BY status',
				$this->brand()
			),
			ARRAY_A
		) ?: [];

		$counts = [ 'open' => 0, 'waiting' => 0, 'human' => 0, 'closed' => 0 ];
		foreach ( $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}
		$counts['needs_reply'] = $counts['waiting'] + $counts['human'];

		return $counts;
	}

	/**
	 * @param array<string, mixed> $filters status, channel, assigned_to, search.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( array $filters = [], int $perPage = 30, int $page = 1 ): array {
		$sql  = 'SELECT * FROM `' . $this->name() . '` WHERE brand_id = %d';
		$args = [ $this->brand() ];

		if ( ! empty( $filters['status'] ) ) {
			if ( 'needs_reply' === $filters['status'] ) {
				$sql   .= ' AND status IN (%s, %s)';
				$args[] = 'waiting';
				$args[] = 'human';
			} else {
				$sql   .= ' AND status = %s';
				$args[] = sanitize_key( (string) $filters['status'] );
			}
		}

		if ( ! empty( $filters['channel'] ) ) {
			$sql   .= ' AND channel = %s';
			$args[] = sanitize_key( (string) $filters['channel'] );
		}

		if ( ! empty( $filters['assigned_to'] ) ) {
			$sql   .= ' AND assigned_to = %d';
			$args[] = (int) $filters['assigned_to'];
		}

		$perPage = max( 1, min( 100, $perPage ) );
		$offset  = max( 0, ( max( 1, $page ) - 1 ) * $perPage );

		$sql   .= ' ORDER BY last_message_at DESC LIMIT %d OFFSET %d';
		$args[] = $perPage;
		$args[] = $offset;

		return $this->db->get_results( $this->db->prepare( $sql, $args ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB
	}

	/**
	 * Last visible line for a batch of conversations.
	 *
	 * One query for the whole list. Fetching a preview per row turns an inbox
	 * page into thirty round trips, which is exactly how admin screens get
	 * slow without anyone noticing why.
	 *
	 * @param array<int, int> $conversationIds Conversation ids.
	 * @return array<int, string>
	 */
	public function previews( array $conversationIds ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $conversationIds ) ) ) );

		if ( [] === $ids ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = $this->messagesTable();

		$sql = 'SELECT m.conversation_id, m.content
			FROM `' . $table . '` m
			INNER JOIN (
				SELECT conversation_id, MAX(id) AS last_id
				FROM `' . $table . '`
				WHERE brand_id = %d AND role <> %s AND conversation_id IN (' . $placeholders . ')
				GROUP BY conversation_id
			) latest ON latest.last_id = m.id';

		$args = array_merge( [ $this->brand(), 'tool' ], $ids );
		$rows = $this->db->get_results( $this->db->prepare( $sql, $args ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB

		$previews = [];
		foreach ( $rows as $row ) {
			$previews[ (int) $row['conversation_id'] ] = mb_substr( (string) $row['content'], 0, 120 );
		}

		return $previews;
	}
}
