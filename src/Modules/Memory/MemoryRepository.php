<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Memory;

use VMStudio\SalesOS\Database\Repository;

final class MemoryRepository extends Repository {

	protected function table(): string {
		return 'vmsos_contact_memory';
	}

	/** @return array<string, mixed>|null */
	public function forContact( int $contactId ): ?array {
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT * FROM `' . $this->name() . '` WHERE brand_id = %d AND contact_id = %d',
				$this->brand(),
				$contactId
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Increment the turn counter, creating the row if this is the first turn.
	 *
	 * Returns the running total so the caller can decide whether a refresh is
	 * due without a second read.
	 */
	public function countTurn( int $contactId ): int {
		$existing = $this->forContact( $contactId );

		if ( ! $existing ) {
			$this->insert(
				[
					'contact_id' => $contactId,
					'turns_seen' => 1,
					'created_at' => $this->now(),
				],
				[ '%d', '%d', '%s' ]
			);

			return 1;
		}

		$turns = (int) $existing['turns_seen'] + 1;
		$this->update( (int) $existing['id'], [ 'turns_seen' => $turns ], [ '%d' ] );

		return $turns;
	}

	/**
	 * @param array<string, mixed> $memory Synthesised memory fields.
	 */
	public function store( int $contactId, array $memory ): bool {
		$data = [
			'summary'          => mb_substr( (string) ( $memory['summary'] ?? '' ), 0, 2000 ),
			'facts'            => (string) wp_json_encode( array_values( (array) ( $memory['facts'] ?? [] ) ) ),
			'preferences'      => (string) wp_json_encode( array_values( (array) ( $memory['preferences'] ?? [] ) ) ),
			'objections'       => (string) wp_json_encode( array_values( (array) ( $memory['objections'] ?? [] ) ) ),
			'next_best_action' => mb_substr( sanitize_text_field( (string) ( $memory['next_best_action'] ?? '' ) ), 0, 500 ),
			'buying_stage'     => sanitize_key( (string) ( $memory['buying_stage'] ?? '' ) ),
			'refreshed_at'     => $this->now(),
		];

		$formats  = [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];
		$existing = $this->forContact( $contactId );

		if ( $existing ) {
			return $this->update( (int) $existing['id'], $data, $formats );
		}

		$data['contact_id'] = $contactId;
		$data['turns_seen'] = 0;
		$data['created_at'] = $this->now();

		return $this->insert( $data, array_merge( $formats, [ '%d', '%d', '%s' ] ) ) > 0;
	}

	/** @return array<int, array<string, mixed>> */
	public function recentlyRefreshed( int $limit = 20 ): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT m.*, c.first_name, c.last_name, c.email
				 FROM `' . $this->name() . '` m
				 LEFT JOIN `' . $this->db->prefix . 'vmsos_contacts` c ON c.id = m.contact_id
				 WHERE m.brand_id = %d AND m.refreshed_at IS NOT NULL
				 ORDER BY m.refreshed_at DESC LIMIT %d',
				$this->brand(),
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		) ?: [];
	}
}
