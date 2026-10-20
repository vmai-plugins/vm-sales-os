<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\CRM;

use VMStudio\SalesOS\Database\Repository;

final class ActivityRepository extends Repository {

	protected function table(): string {
		return 'vmsos_activities';
	}

	/** @param array<string, mixed> $meta Extra data. */
	public function log( string $subjectType, int $subjectId, string $type, string $body, string $actor = 'system', array $meta = [] ): int {
		return $this->insert(
			[
				'subject_type' => sanitize_key( $subjectType ),
				'subject_id'   => $subjectId,
				'type'         => sanitize_key( $type ),
				'body'         => mb_substr( wp_strip_all_tags( $body ), 0, 5000 ),
				'actor'        => sanitize_key( $actor ),
				'user_id'      => get_current_user_id(),
				'meta'         => wp_json_encode( $meta ),
				'created_at'   => $this->now(),
			],
			[ '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);
	}

	/** @return array<int, array<string, mixed>> */
	public function timeline( string $subjectType, int $subjectId, int $limit = 50 ): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT * FROM `' . $this->name() . '` WHERE brand_id = %d AND subject_type = %s AND subject_id = %d ORDER BY created_at DESC LIMIT %d',
				$this->brand(),
				sanitize_key( $subjectType ),
				$subjectId,
				max( 1, min( 200, $limit ) )
			),
			ARRAY_A
		) ?: [];
	}
}
