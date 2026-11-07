<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Catalog;

use VMStudio\SalesOS\REST\AbstractController;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class CatalogController extends AbstractController {

	public function __construct( private CatalogRepository $repository ) {}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/catalog',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list' ],
					'permission_callback' => '__return_true', // Public catalog access for Z-Agents, Chatbot & Web
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create' ],
					'permission_callback' => [ $this, 'canOperate' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/catalog/categories',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'categories' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/catalog/sync-woocommerce',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'syncWooCommerce' ],
				'permission_callback' => [ $this, 'canManage' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/catalog/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get' ],
					'permission_callback' => '__return_true',
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update' ],
					'permission_callback' => [ $this, 'canOperate' ],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete' ],
					'permission_callback' => [ $this, 'canOperate' ],
				],
			]
		);
	}

	public function list( WP_REST_Request $request ): WP_REST_Response {
		$filters = [
			'category' => $this->param( $request, 'category' ),
			'type'     => $this->param( $request, 'type' ),
			'search'   => $this->param( $request, 'search' ) ?: $this->param( $request, 'q' ),
			'limit'    => (int) ( $request->get_param( 'limit' ) ?: 50 ),
			'offset'   => (int) ( $request->get_param( 'offset' ) ?: 0 ),
		];

		$items = $this->repository->all( $filters );

		return $this->ok(
			[
				'items' => $items,
				'count' => count( $items ),
			]
		);
	}

	public function get( WP_REST_Request $request ): WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$item = $this->repository->find( $id );

		if ( ! $item ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Item not found' ], 404 );
		}

		return $this->ok( $item );
	}

	public function categories(): WP_REST_Response {
		return $this->ok( $this->repository->categories() );
	}

	public function create( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params() ?: [];

		if ( empty( $body['title'] ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Title is required.' ], 400 );
		}

		$id = $this->repository->create( $body );
		if ( 0 === $id ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Could not create catalog item.' ], 500 );
		}

		return $this->ok( $this->repository->find( $id ) ?: [ 'id' => $id ], 201 );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$body = $request->get_json_params() ?: [];

		$existing = $this->repository->find( $id );
		if ( ! $existing ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Item not found' ], 404 );
		}

		$success = $this->repository->update( $id, $body );

		return $this->ok( [ 'updated' => $success, 'item' => $this->repository->find( $id ) ] );
	}

	public function delete( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request->get_param( 'id' );

		$existing = $this->repository->find( $id );
		if ( ! $existing ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Item not found' ], 404 );
		}

		$success = $this->repository->delete( $id );

		return $this->ok( [ 'deleted' => $success, 'id' => $id ] );
	}

	public function syncWooCommerce(): WP_REST_Response {
		$res = $this->repository->syncFromWooCommerce();
		return $this->ok( $res );
	}
}
