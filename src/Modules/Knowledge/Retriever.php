<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Knowledge;

use VMStudio\SalesOS\AI\ProviderManager;

/**
 * Hybrid retrieval: semantic similarity fused with keyword matches, so exact
 * product names and paraphrased questions both land.
 */
final class Retriever {

	private const CACHE_GROUP = 'vmsos_kb';

	public function __construct( private VectorStore $vectors, private ProviderManager $ai ) {}

	/**
	 * @return array<int, array{id:int, content:string, score:float, source:string}>
	 */
	public function retrieve( string $query, int $limit = 5 ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return [];
		}

		$semantic = $this->semantic( $query, $limit * 2 );
		$keyword  = $this->keyword( $query, $limit * 2 );

		// Reciprocal rank fusion — robust when the two scores are not comparable.
		$fused = [];
		foreach ( [ $semantic, $keyword ] as $list ) {
			foreach ( array_values( $list ) as $rank => $hit ) {
				$id = (int) $hit['id'];
				$fused[ $id ]['content'] = $hit['content'];
				$fused[ $id ]['id']      = $id;
				$fused[ $id ]['source']  = $hit['source'];
				$fused[ $id ]['score']   = ( $fused[ $id ]['score'] ?? 0 ) + 1 / ( 60 + $rank + 1 );
			}
		}

		usort( $fused, static fn( array $a, array $b ) => $b['score'] <=> $a['score'] );

		return array_slice( array_values( $fused ), 0, $limit );
	}

	/** Format retrieved chunks for prompt injection. */
	public function context( string $query, int $limit = 5, int $maxChars = 6000 ): string {
		$out  = '';
		$hits = $this->retrieve( $query, $limit );

		foreach ( $hits as $index => $hit ) {
			$block = sprintf( "[%d] %s\n\n", $index + 1, trim( (string) $hit['content'] ) );
			if ( mb_strlen( $out ) + mb_strlen( $block ) > $maxChars ) {
				break;
			}
			$out .= $block;
		}

		return trim( $out );
	}

	/** @return array<int, array<string, mixed>> */
	private function semantic( string $query, int $limit ): array {
		if ( ! $this->ai->hasEmbeddings() ) {
			return [];
		}

		$vector = $this->queryVector( $query );
		if ( ! $vector ) {
			return [];
		}

		$scored = [];
		foreach ( $this->vectors->embedded() as $row ) {
			$score = VectorStore::cosine( $vector, VectorStore::unpack( (string) $row['embedding'] ) );
			if ( $score > 0.15 ) {
				$scored[] = [
					'id'      => (int) $row['id'],
					'content' => (string) $row['content'],
					'score'   => $score,
					'source'  => 'semantic',
				];
			}
		}

		usort( $scored, static fn( array $a, array $b ) => $b['score'] <=> $a['score'] );
		return array_slice( $scored, 0, $limit );
	}

	/** @return array<int, array<string, mixed>> */
	private function keyword( string $query, int $limit ): array {
		return array_map(
			static fn( array $row ) => [
				'id'      => (int) $row['id'],
				'content' => (string) $row['content'],
				'score'   => (float) $row['score'],
				'source'  => 'keyword',
			],
			$this->vectors->fulltext( $query, $limit )
		);
	}

	/**
	 * Query embeddings are cached: the same question gets asked constantly and
	 * each miss is a paid API call.
	 *
	 * @return array<int, float>
	 */
	private function queryVector( string $query ): array {
		$key    = 'q_' . md5( $query );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$vectors = $this->ai->embed( [ $query ] );
		$vector  = $vectors[0] ?? [];

		if ( $vector ) {
			wp_cache_set( $key, $vector, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}

		return $vector;
	}
}
