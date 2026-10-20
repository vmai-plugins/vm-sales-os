<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Agents;

use VMStudio\SalesOS\Database\Repository;

final class AgentRepository extends Repository {

	protected function table(): string {
		return 'vmsos_agents';
	}

	/** @return array<string, mixed>|null */
	public function findBySlug( string $slug ): ?array {
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT * FROM `' . $this->name() . '` WHERE brand_id = %d AND slug = %s',
				$this->brand(),
				sanitize_key( $slug )
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/** @param array<string, mixed> $input Agent definition. */
	public function upsert( array $input ): int {
		$slug     = sanitize_key( (string) ( $input['slug'] ?? '' ) );
		$existing = $this->findBySlug( $slug );

		$fields = [
			'name'          => sanitize_text_field( (string) ( $input['name'] ?? $slug ) ),
			'role'          => sanitize_key( (string) ( $input['role'] ?? 'sales' ) ),
			'goal'          => mb_substr( wp_strip_all_tags( (string) ( $input['goal'] ?? '' ) ), 0, 2000 ),
			'system_prompt' => (string) ( $input['system_prompt'] ?? '' ),
			'tools'         => wp_json_encode( array_map( 'sanitize_key', (array) ( $input['tools'] ?? [] ) ) ),
			'model_policy'  => wp_json_encode( (array) ( $input['model_policy'] ?? [] ) ),
			'capabilities'  => wp_json_encode( array_map( 'sanitize_key', (array) ( $input['capabilities'] ?? [ 'internal' ] ) ) ),
			'status'        => sanitize_key( (string) ( $input['status'] ?? 'active' ) ),
			'updated_at'    => $this->now(),
		];

		if ( $existing ) {
			$this->update( (int) $existing['id'], $fields, array_fill( 0, count( $fields ), '%s' ) );
			return (int) $existing['id'];
		}

		$fields['slug']       = $slug;
		$fields['created_at'] = $this->now();

		return $this->insert( $fields, array_fill( 0, count( $fields ), '%s' ) );
	}

	/** @return array<int, array<string, mixed>> */
	public function active(): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT * FROM `' . $this->name() . '` WHERE brand_id = %d AND status = %s ORDER BY name ASC',
				$this->brand(),
				'active'
			),
			ARRAY_A
		) ?: [];
	}

	public function startRun( int $agentId, string $trigger, string $input ): int {
		$this->db->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->db->prefix . 'vmsos_agent_runs',
			[
				'brand_id'     => $this->brand(),
				'agent_id'     => $agentId,
				'trigger_type' => sanitize_key( $trigger ),
				'input'        => mb_substr( $input, 0, 8000 ),
				'status'       => 'running',
				'started_at'   => $this->now(),
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s' ]
		);
		return (int) $this->db->insert_id;
	}

	/** @param array<int, array<string, mixed>> $steps Step log. */
	public function finishRun( int $runId, string $status, string $output, array $steps ): void {
		$this->db->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->db->prefix . 'vmsos_agent_runs',
			[
				'status'      => sanitize_key( $status ),
				'output'      => mb_substr( $output, 0, 20000 ),
				'steps'       => wp_json_encode( $steps ),
				'finished_at' => $this->now(),
			],
			[ 'id' => $runId, 'brand_id' => $this->brand() ],
			[ '%s', '%s', '%s', '%s' ],
			[ '%d', '%d' ]
		);
	}

	/** @return array<int, array<string, mixed>> */
	public function recentRuns( int $limit = 20 ): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT * FROM `' . $this->db->prefix . 'vmsos_agent_runs` WHERE brand_id = %d ORDER BY started_at DESC LIMIT %d',
				$this->brand(),
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		) ?: [];
	}
}
