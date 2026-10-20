<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\CRM;

use VMStudio\SalesOS\Database\Repository;

final class PipelineRepository extends Repository {

	/** Used when a site has somehow lost its seeded pipeline. */
	private const FALLBACK_STAGES = [
		[ 'key' => 'new', 'label' => 'New', 'probability' => 10 ],
		[ 'key' => 'qualified', 'label' => 'Qualified', 'probability' => 30 ],
		[ 'key' => 'proposal', 'label' => 'Proposal sent', 'probability' => 60 ],
		[ 'key' => 'negotiation', 'label' => 'Negotiation', 'probability' => 80 ],
		[ 'key' => 'won', 'label' => 'Won', 'probability' => 100 ],
		[ 'key' => 'lost', 'label' => 'Lost', 'probability' => 0 ],
	];

	protected function table(): string {
		return 'vmsos_pipelines';
	}

	/** @return array<int, array<string, mixed>> */
	public function all(): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare( 'SELECT * FROM `' . $this->name() . '` WHERE brand_id = %d ORDER BY is_default DESC, id ASC', $this->brand() ),
			ARRAY_A
		) ?: [];
	}

	/** @return array<string, mixed>|null */
	public function defaultPipeline(): ?array {
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT * FROM `' . $this->name() . '` WHERE brand_id = %d ORDER BY is_default DESC, id ASC LIMIT 1',
				$this->brand()
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Stage definitions for a pipeline, always usable even if the row is gone.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function stages( int $pipelineId = 0 ): array {
		$pipeline = $pipelineId > 0 ? $this->find( $pipelineId ) : $this->defaultPipeline();
		$stages   = $pipeline ? json_decode( (string) $pipeline['stages'], true ) : null;

		if ( ! is_array( $stages ) || [] === $stages ) {
			return self::FALLBACK_STAGES;
		}

		return array_values(
			array_filter( $stages, static fn( $stage ): bool => is_array( $stage ) && ! empty( $stage['key'] ) )
		);
	}

	public function probabilityFor( string $stageKey, int $pipelineId = 0 ): int {
		foreach ( $this->stages( $pipelineId ) as $stage ) {
			if ( (string) $stage['key'] === $stageKey ) {
				return (int) ( $stage['probability'] ?? 0 );
			}
		}

		return 0;
	}

	/**
	 * @param array<int, array<string, mixed>> $stages Stage definitions.
	 */
	public function saveStages( int $pipelineId, array $stages ): bool {
		$clean = [];

		foreach ( $stages as $stage ) {
			$key = sanitize_key( (string) ( $stage['key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}
			$clean[] = [
				'key'         => $key,
				'label'       => sanitize_text_field( (string) ( $stage['label'] ?? $key ) ),
				'probability' => max( 0, min( 100, (int) ( $stage['probability'] ?? 0 ) ) ),
			];
		}

		if ( [] === $clean ) {
			return false;
		}

		return $this->update( $pipelineId, [ 'stages' => (string) wp_json_encode( $clean ) ], [ '%s' ] );
	}
}
