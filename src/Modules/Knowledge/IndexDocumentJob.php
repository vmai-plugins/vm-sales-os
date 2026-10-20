<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Knowledge;

use VMStudio\SalesOS\Queue\JobHandler;

final class IndexDocumentJob implements JobHandler {

	public function __construct( private Indexer $indexer, private DocumentRepository $documents ) {}

	public static function name(): string {
		return 'knowledge.index';
	}

	/** @param array<string, mixed> $payload document_id + content. */
	public function handle( array $payload ): void {
		$documentId = (int) ( $payload['document_id'] ?? 0 );
		$content    = (string) ( $payload['content'] ?? '' );

		if ( $documentId <= 0 || '' === $content ) {
			throw new \InvalidArgumentException( 'Missing document_id or content.' );
		}

		$this->indexer->index( $documentId, $content );
	}
}
