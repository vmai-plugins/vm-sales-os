<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Sequences;

use VMStudio\SalesOS\Database\Repository;

final class SequenceRepository extends Repository {

	protected function table(): string {
		return 'vmsos_sequences';
	}

	private function stepsTable(): string {
		return $this->db->prefix . 'vmsos_sequence_steps';
	}

	/** @return array<int, array<string, mixed>> */
	public function all( string $status = '' ): array {
		$sql  = 'SELECT * FROM `' . $this->name() . '` WHERE brand_id = %d';
		$args = [ $this->brand() ];

		if ( '' !== $status ) {
			$sql   .= ' AND status = %s';
			$args[] = sanitize_key( $status );
		}

		$sql .= ' ORDER BY id DESC LIMIT 200';

		return $this->db->get_results( $this->db->prepare( $sql, $args ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB
	}

	/** @return array<int, array<string, mixed>> */
	public function activeFor( string $event ): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT * FROM `' . $this->name() . '` WHERE brand_id = %d AND status = %s AND trigger_event = %s',
				$this->brand(),
				'active',
				sanitize_key( $event )
			),
			ARRAY_A
		) ?: [];
	}

	/** @param array<string, mixed> $input Sequence fields. */
	public function create( array $input ): int {
		$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );

		if ( '' === $name ) {
			return 0;
		}

		return $this->insert(
			[
				'name'          => $name,
				'slug'          => sanitize_title( (string) ( $input['slug'] ?? $name ) ),
				'status'        => sanitize_key( (string) ( $input['status'] ?? 'draft' ) ),
				'trigger_event' => sanitize_key( (string) ( $input['trigger_event'] ?? '' ) ),
				'goal_event'    => sanitize_key( (string) ( $input['goal_event'] ?? '' ) ),
				'stop_on_reply' => empty( $input['stop_on_reply'] ) ? 0 : 1,
				'settings'      => (string) wp_json_encode( (array) ( $input['settings'] ?? [] ) ),
				'created_at'    => $this->now(),
				'updated_at'    => $this->now(),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
		);
	}

	/** @param array<string, mixed> $input Sequence fields. */
	public function edit( int $id, array $input ): bool {
		$data    = [ 'updated_at' => $this->now() ];
		$formats = [ '%s' ];

		$map = [
			'name'          => [ '%s', 'sanitize_text_field' ],
			'status'        => [ '%s', 'sanitize_key' ],
			'trigger_event' => [ '%s', 'sanitize_key' ],
			'goal_event'    => [ '%s', 'sanitize_key' ],
		];

		foreach ( $map as $field => [ $format, $filter ] ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $field ] = $filter( (string) $input[ $field ] );
				$formats[]      = $format;
			}
		}

		if ( array_key_exists( 'stop_on_reply', $input ) ) {
			$data['stop_on_reply'] = empty( $input['stop_on_reply'] ) ? 0 : 1;
			$formats[]             = '%d';
		}

		return $this->update( $id, $data, $formats );
	}

	/** @return array<int, array<string, mixed>> */
	public function steps( int $sequenceId ): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT * FROM `' . $this->stepsTable() . '` WHERE sequence_id = %d AND brand_id = %d ORDER BY position ASC, id ASC',
				$sequenceId,
				$this->brand()
			),
			ARRAY_A
		) ?: [];
	}

	/** @return array<string, mixed>|null */
	public function stepAt( int $sequenceId, int $position ): ?array {
		$steps = $this->steps( $sequenceId );
		return $steps[ $position ] ?? null;
	}

	public function stepCount( int $sequenceId ): int {
		return (int) $this->db->get_var( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT COUNT(*) FROM `' . $this->stepsTable() . '` WHERE sequence_id = %d AND brand_id = %d',
				$sequenceId,
				$this->brand()
			)
		);
	}

	/** @param array<string, mixed> $input Step fields. */
	public function addStep( int $sequenceId, array $input ): int {
		$this->db->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->stepsTable(),
			[
				'brand_id'      => $this->brand(),
				'sequence_id'   => $sequenceId,
				'position'      => (int) ( $input['position'] ?? $this->stepCount( $sequenceId ) ),
				'type'          => sanitize_key( (string) ( $input['type'] ?? 'email' ) ),
				'delay_minutes' => max( 0, (int) ( $input['delay_minutes'] ?? 0 ) ),
				'subject'       => sanitize_text_field( (string) ( $input['subject'] ?? '' ) ),
				'body'          => wp_kses_post( (string) ( $input['body'] ?? '' ) ),
				'personalise'   => empty( $input['personalise'] ) ? 0 : 1,
				'settings'      => (string) wp_json_encode( (array) ( $input['settings'] ?? [] ) ),
				'created_at'    => $this->now(),
			],
			[ '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s' ]
		);

		return (int) $this->db->insert_id;
	}

	/** @param array<string, mixed> $input Step fields. */
	public function editStep( int $stepId, array $input ): bool {
		$data    = [];
		$formats = [];

		$map = [
			'position'      => '%d',
			'type'          => '%s',
			'delay_minutes' => '%d',
			'subject'       => '%s',
			'body'          => '%s',
			'personalise'   => '%d',
		];

		foreach ( $map as $field => $format ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$data[ $field ] = match ( $field ) {
				'position'      => max( 0, (int) $input[ $field ] ),
				'type'          => sanitize_key( (string) $input[ $field ] ),
				'delay_minutes' => max( 0, (int) $input[ $field ] ),
				'subject'       => sanitize_text_field( (string) $input[ $field ] ),
				'body'          => wp_kses_post( (string) $input[ $field ] ),
				'personalise'   => empty( $input[ $field ] ) ? 0 : 1,
				default         => $input[ $field ],
			};

			$formats[] = $format;
		}

		if ( [] === $data ) {
			return false;
		}

		return false !== $this->db->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->stepsTable(),
			$data,
			[ 'id' => $stepId, 'brand_id' => $this->brand() ],
			$formats,
			[ '%d', '%d' ]
		);
	}

	public function deleteStep( int $stepId ): bool {
		return (bool) $this->db->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->stepsTable(),
			[ 'id' => $stepId, 'brand_id' => $this->brand() ],
			[ '%d', '%d' ]
		);
	}
}
