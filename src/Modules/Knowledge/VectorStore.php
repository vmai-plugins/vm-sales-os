<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Knowledge;

use VMStudio\SalesOS\Database\Repository;

/**
 * Vectors live in MySQL as packed float32 blobs. This is fast enough into the
 * low hundreds of thousands of chunks; past that, swap the implementation for
 * a dedicated index via the vmsos_vector_store filter without touching callers.
 */
final class VectorStore extends Repository {

	protected function table(): string {
		return 'vmsos_kb_chunks';
	}

	/** @param array<int, float> $vector Embedding. */
	public static function pack( array $vector ): string {
		return pack( 'f*', ...array_map( 'floatval', $vector ) );
	}

	/** @return array<int, float> */
	public static function unpack( string $blob ): array {
		if ( '' === $blob ) {
			return [];
		}
		$values = unpack( 'f*', $blob );
		return false === $values ? [] : array_values( $values );
	}

	/**
	 * @param array<int, float> $a First vector.
	 * @param array<int, float> $b Second vector.
	 */
	public static function cosine( array $a, array $b ): float {
		$length = min( count( $a ), count( $b ) );
		if ( 0 === $length ) {
			return 0.0;
		}

		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;

		for ( $i = 0; $i < $length; $i++ ) {
			$dot += $a[ $i ] * $b[ $i ];
			$na  += $a[ $i ] ** 2;
			$nb  += $b[ $i ] ** 2;
		}

		$denominator = sqrt( $na ) * sqrt( $nb );
		return $denominator > 0.0 ? $dot / $denominator : 0.0;
	}

	/** @param array<int, float> $vector Embedding. */
	public function storeChunk( int $documentId, int $index, string $content, array $vector ): int {
		return $this->insert(
			[
				'document_id' => $documentId,
				'chunk_index' => $index,
				'content'     => $content,
				'embedding'   => $vector ? self::pack( $vector ) : null,
				'dimensions'  => count( $vector ),
				'token_count' => Chunker::estimateTokens( $content ),
				'created_at'  => $this->now(),
			],
			[ '%d', '%d', '%s', '%s', '%d', '%d', '%s' ]
		);
	}

	public function clearDocument( int $documentId ): void {
		$this->db->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->name(),
			[ 'document_id' => $documentId, 'brand_id' => $this->brand() ],
			[ '%d', '%d' ]
		);
	}

	/**
	 * Rows with embeddings for this brand, capped so memory stays bounded.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function embedded( int $limit = 5000 ): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT id, document_id, content, embedding FROM `' . $this->name() . '`
				 WHERE brand_id = %d AND dimensions > 0 LIMIT %d',
				$this->brand(),
				$limit
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Keyword fallback for when embeddings are unavailable.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function fulltext( string $query, int $limit = 8 ): array {
		$query = trim( preg_replace( '/[+\-><\(\)~*\"@]+/', ' ', $query ) ?? '' );
		if ( '' === $query ) {
			return [];
		}

		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT id, document_id, content, MATCH(content) AGAINST(%s IN NATURAL LANGUAGE MODE) AS score
				 FROM `' . $this->name() . '`
				 WHERE brand_id = %d AND MATCH(content) AGAINST(%s IN NATURAL LANGUAGE MODE)
				 ORDER BY score DESC LIMIT %d',
				$query,
				$this->brand(),
				$query,
				max( 1, min( 50, $limit ) )
			),
			ARRAY_A
		);

		if ( $rows ) {
			return $rows;
		}

		$like = '%' . $this->db->esc_like( $query ) . '%';
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT id, document_id, content, 0.4 AS score FROM `' . $this->name() . '`
				 WHERE brand_id = %d AND content LIKE %s LIMIT %d',
				$this->brand(),
				$like,
				$limit
			),
			ARRAY_A
		) ?: [];
	}
}
