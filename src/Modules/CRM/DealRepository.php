<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\CRM;

use VMStudio\SalesOS\Database\Repository;

final class DealRepository extends Repository {

	protected function table(): string {
		return 'vmsos_deals';
	}

	/** @param array<string, mixed> $input Deal fields. */
	public function create( array $input ): int {
		return $this->insert(
			[
				'pipeline_id'    => (int) ( $input['pipeline_id'] ?? 0 ),
				'contact_id'     => (int) ( $input['contact_id'] ?? 0 ),
				'company_id'     => (int) ( $input['company_id'] ?? 0 ),
				'title'          => sanitize_text_field( (string) ( $input['title'] ?? 'Untitled deal' ) ),
				'stage'          => sanitize_key( (string) ( $input['stage'] ?? 'new' ) ),
				'value'          => (float) ( $input['value'] ?? 0 ),
				'currency'       => strtoupper( substr( (string) ( $input['currency'] ?? 'INR' ), 0, 3 ) ),
				'probability'    => (int) ( $input['probability'] ?? 0 ),
				'expected_close' => ! empty( $input['expected_close'] ) ? gmdate( 'Y-m-d', strtotime( (string) $input['expected_close'] ) ) : null,
				'created_at'     => $this->now(),
				'updated_at'     => $this->now(),
			],
			[ '%d', '%d', '%d', '%s', '%s', '%f', '%s', '%d', '%s', '%s', '%s' ]
		);
	}

	public function moveStage( int $id, string $stage, int $probability ): bool {
		$data = [
			'stage'       => sanitize_key( $stage ),
			'probability' => max( 0, min( 100, $probability ) ),
			'updated_at'  => $this->now(),
		];
		if ( in_array( $stage, [ 'won', 'lost' ], true ) ) {
			$data['closed_at'] = $this->now();
		}
		return $this->update( $id, $data, array_fill( 0, count( $data ), '%s' ) );
	}

	/** Weighted pipeline value by stage. @return array<int, array<string, mixed>> */
	public function forecast(): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT stage, COUNT(*) AS deals, SUM(value) AS total, SUM(value * probability / 100) AS weighted
				 FROM `' . $this->name() . '`
				 WHERE brand_id = %d AND closed_at IS NULL
				 GROUP BY stage',
				$this->brand()
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Every open deal in a pipeline, grouped by stage — the board query.
	 *
	 * One query, grouped in PHP: a kanban with six stages should not be six
	 * round trips.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function forPipeline( int $pipelineId = 0, bool $includeClosed = false ): array {
		$sql  = 'SELECT d.*, c.first_name, c.last_name, c.email, c.score
			 FROM `' . $this->name() . '` d
			 LEFT JOIN `' . $this->db->prefix . 'vmsos_contacts` c ON c.id = d.contact_id
			 WHERE d.brand_id = %d';
		$args = [ $this->brand() ];

		if ( $pipelineId > 0 ) {
			$sql   .= ' AND d.pipeline_id = %d';
			$args[] = $pipelineId;
		}

		if ( ! $includeClosed ) {
			$sql .= ' AND d.closed_at IS NULL';
		}

		$sql .= ' ORDER BY d.updated_at DESC LIMIT 500';

		return $this->db->get_results( $this->db->prepare( $sql, $args ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB
	}

	/** @param array<string, mixed> $input Editable fields. */
	public function edit( int $id, array $input ): bool {
		$data    = [ 'updated_at' => $this->now() ];
		$formats = [ '%s' ];

		$map = [
			'title'          => '%s',
			'value'          => '%f',
			'currency'       => '%s',
			'probability'    => '%d',
			'contact_id'     => '%d',
			'expected_close' => '%s',
		];

		foreach ( $map as $field => $format ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$value = $input[ $field ];

			$data[ $field ] = match ( $field ) {
				'title'          => sanitize_text_field( (string) $value ),
				'value'          => (float) $value,
				'currency'       => strtoupper( substr( (string) $value, 0, 3 ) ),
				'probability'    => max( 0, min( 100, (int) $value ) ),
				'contact_id'     => (int) $value,
				'expected_close' => $value ? gmdate( 'Y-m-d', strtotime( (string) $value ) ) : null,
				default          => $value,
			};

			$formats[] = $format;
		}

		return $this->update( $id, $data, $formats );
	}

	/** Reopen a deal that was closed by mistake. */
	public function reopen( int $id, string $stage, int $probability ): bool {
		return $this->update(
			$id,
			[
				'stage'       => sanitize_key( $stage ),
				'probability' => max( 0, min( 100, $probability ) ),
				'closed_at'   => null,
				'updated_at'  => $this->now(),
			],
			[ '%s', '%d', '%s', '%s' ]
		);
	}

	/** @return array<string, mixed> */
	public function wonLost( int $days = 30 ): array {
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT
					SUM(CASE WHEN stage = %s THEN 1 ELSE 0 END) AS won,
					SUM(CASE WHEN stage = %s THEN 1 ELSE 0 END) AS lost,
					SUM(CASE WHEN stage = %s THEN value ELSE 0 END) AS won_value
				 FROM `' . $this->name() . '`
				 WHERE brand_id = %d AND closed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)',
				'won',
				'lost',
				'won',
				$this->brand(),
				max( 1, $days )
			),
			ARRAY_A
		) ?: [];

		return [
			'won'       => (int) ( $row['won'] ?? 0 ),
			'lost'      => (int) ( $row['lost'] ?? 0 ),
			'won_value' => (float) ( $row['won_value'] ?? 0 ),
		];
	}
}
