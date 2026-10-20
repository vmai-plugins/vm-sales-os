<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Knowledge;

use VMStudio\SalesOS\Database\Repository;

final class DocumentRepository extends Repository {

	protected function table(): string {
		return 'vmsos_kb_documents';
	}

	/** @param array<string, mixed> $input Document fields. */
	public function create( string $title, string $sourceType, string $sourceRef, string $content ): int {
		return $this->insert(
			[
				'title'       => mb_substr( sanitize_text_field( $title ), 0, 255 ),
				'source_type' => sanitize_key( $sourceType ),
				'source_ref'  => esc_url_raw( $sourceRef ) ?: sanitize_text_field( $sourceRef ),
				'checksum'    => sha1( $content ),
				'status'      => 'pending',
				'meta'        => wp_json_encode( [ 'length' => mb_strlen( $content ) ] ),
				'created_at'  => $this->now(),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	public function markIndexed( int $id, int $chunkCount ): void {
		$this->update(
			$id,
			[ 'status' => 'indexed', 'chunk_count' => $chunkCount, 'indexed_at' => $this->now() ],
			[ '%s', '%d', '%s' ]
		);
	}

	public function markFailed( int $id, string $reason ): void {
		$this->update( $id, [ 'status' => 'failed', 'meta' => wp_json_encode( [ 'error' => $reason ] ) ], [ '%s', '%s' ] );
	}

	/** @return array<int, array<string, mixed>> */
	public function all( int $limit = 100 ): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT id, title, source_type, source_ref, status, chunk_count, indexed_at, created_at
				 FROM `' . $this->name() . '` WHERE brand_id = %d ORDER BY created_at DESC LIMIT %d',
				$this->brand(),
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		) ?: [];
	}

	public function existsWithChecksum( string $content ): bool {
		return (bool) $this->db->get_var( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT id FROM `' . $this->name() . '` WHERE brand_id = %d AND checksum = %s LIMIT 1',
				$this->brand(),
				sha1( $content )
			)
		);
	}
}
