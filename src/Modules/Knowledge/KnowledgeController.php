<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Knowledge;

use VMStudio\SalesOS\Queue\QueueManager;
use VMStudio\SalesOS\REST\AbstractController;
use WP_REST_Request;

final class KnowledgeController extends AbstractController {

	public function __construct(
		private DocumentRepository $documents,
		private Retriever $retriever,
		private QueueManager $queue
	) {}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/knowledge/documents',
			[
				[ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => fn() => $this->canOperate() ],
				[ 'methods' => 'POST', 'callback' => [ $this, 'store' ], 'permission_callback' => fn() => $this->canManage() ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/documents/(?P<id>\d+)',
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'destroy' ], 'permission_callback' => fn() => $this->canManage() ]
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/search',
			[ 'methods' => 'GET', 'callback' => [ $this, 'search' ], 'permission_callback' => fn() => $this->canOperate() ]
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/import-posts',
			[ 'methods' => 'POST', 'callback' => [ $this, 'importPosts' ], 'permission_callback' => fn() => $this->canManage() ]
		);
	}

	public function index( WP_REST_Request $request ) {
		return $this->ok( [ 'documents' => $this->documents->all() ] );
	}

	public function store( WP_REST_Request $request ) {
		$title   = $this->param( $request, 'title' );
		$content = (string) $request->get_param( 'content' );

		if ( mb_strlen( trim( $content ) ) < 20 ) {
			return $this->fail( 'vmsos_content_too_short', __( 'Add at least a short paragraph of content.', 'vm-sales-os' ) );
		}

		if ( $this->documents->existsWithChecksum( $content ) ) {
			return $this->fail( 'vmsos_duplicate', __( 'That exact content is already in the knowledge base.', 'vm-sales-os' ), 409 );
		}

		$id = $this->documents->create(
			$title ?: __( 'Untitled document', 'vm-sales-os' ),
			$this->param( $request, 'source_type', 'manual' ),
			$this->param( $request, 'source_ref' ),
			$content
		);

		$this->queue->push( IndexDocumentJob::name(), [ 'document_id' => $id, 'content' => $content ] );

		return $this->ok( [ 'id' => $id, 'status' => 'queued' ], 201 );
	}

	public function destroy( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		return $this->ok( [ 'deleted' => $this->documents->delete( $id ) ] );
	}

	public function search( WP_REST_Request $request ) {
		$query = $this->textParam( $request, 'q', 500 );
		return $this->ok( [ 'hits' => $this->retriever->retrieve( $query, (int) ( $request->get_param( 'limit' ) ?: 5 ) ) ] );
	}

	/** Pull published pages and posts into the knowledge base. */
	public function importPosts( WP_REST_Request $request ) {
		$types = array_filter( array_map( 'sanitize_key', (array) ( $request->get_param( 'post_types' ) ?: [ 'page', 'post' ] ) ) );

		$posts = get_posts(
			[
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => (int) ( $request->get_param( 'limit' ) ?: 50 ),
			]
		);

		$queued = 0;
		foreach ( $posts as $post ) {
			$content = wp_strip_all_tags( (string) apply_filters( 'the_content', $post->post_content ) );
			if ( mb_strlen( trim( $content ) ) < 50 || $this->documents->existsWithChecksum( $content ) ) {
				continue;
			}

			$id = $this->documents->create( $post->post_title, 'wp_post', get_permalink( $post ), $content );
			$this->queue->push( IndexDocumentJob::name(), [ 'document_id' => $id, 'content' => $content ] );
			$queued++;
		}

		return $this->ok( [ 'queued' => $queued ] );
	}
}
