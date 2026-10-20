<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Knowledge;

use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Core\Module;
use VMStudio\SalesOS\Queue\QueueManager;
use VMStudio\SalesOS\REST\RestRouter;

final class KnowledgeModule extends Module {

	public static function slug(): string {
		return 'knowledge';
	}

	public static function label(): string {
		return __( 'Knowledge base', 'vm-sales-os' );
	}

	public function register( Container $container ): void {
		$container->singleton( DocumentRepository::class, static fn() => new DocumentRepository() );
		$container->singleton( VectorStore::class, static fn() => new VectorStore() );
		$container->singleton(
			Chunker::class,
			static fn() => new Chunker(
				(int) get_option( 'vmsos_kb_chunk_chars', 1200 ),
				(int) get_option( 'vmsos_kb_overlap_chars', 150 )
			)
		);
	}

	public function boot( Container $container ): void {
		$container->get( RestRouter::class )->add( KnowledgeController::class );
		$container->get( QueueManager::class )->registerHandler( IndexDocumentJob::class );

		// Chat asks for context through a filter, so neither module depends on the other.
		add_filter(
			'vmsos_chat_knowledge_context',
			static function ( string $context, string $message ) use ( $container ): string {
				if ( '' !== $context ) {
					return $context;
				}
				return $container->get( Retriever::class )->context(
					$message,
					(int) get_option( 'vmsos_kb_hits', 5 )
				);
			},
			10,
			2
		);
	}
}
