<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Knowledge;

use VMStudio\SalesOS\AI\ProviderManager;
use VMStudio\SalesOS\Support\Logger;

/**
 * Turns raw text into stored, embedded chunks. Embedding failures downgrade to
 * keyword-only search rather than losing the content.
 */
final class Indexer {

	public function __construct(
		private DocumentRepository $documents,
		private VectorStore $vectors,
		private Chunker $chunker,
		private ProviderManager $ai,
		private Logger $logger
	) {}

	public function index( int $documentId, string $content ): int {
		$chunks = $this->chunker->split( $content );

		if ( ! $chunks ) {
			$this->documents->markFailed( $documentId, 'No readable text found.' );
			return 0;
		}

		$this->vectors->clearDocument( $documentId );

		$useEmbeddings = $this->ai->hasEmbeddings();
		$stored        = 0;

		foreach ( array_chunk( $chunks, 16 ) as $batchIndex => $batch ) {
			$vectors = [];

			if ( $useEmbeddings ) {
				try {
					$vectors = $this->ai->embed( $batch );
				} catch ( \Throwable $e ) {
					$this->logger->error( 'knowledge', 'Embedding batch failed, storing text only', [ 'error' => $e->getMessage() ] );
					$useEmbeddings = false;
				}
			}

			foreach ( $batch as $offset => $text ) {
				$this->vectors->storeChunk(
					$documentId,
					( $batchIndex * 16 ) + $offset,
					$text,
					$vectors[ $offset ] ?? []
				);
				$stored++;
			}
		}

		$this->documents->markIndexed( $documentId, $stored );
		return $stored;
	}
}
