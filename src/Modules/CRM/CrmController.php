<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\CRM;

use VMStudio\SalesOS\REST\AbstractController;
use WP_REST_Request;

final class CrmController extends AbstractController {

	public function __construct(
		private ContactRepository $contacts,
		private ActivityRepository $activities,
		private DealRepository $deals,
		private LeadScorer $scorer
	) {}

	public function register(): void {
		$auth = fn() => $this->canOperate();

		register_rest_route(
			self::NAMESPACE,
			'/contacts',
			[
				[ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => $auth ],
				[ 'methods' => 'POST', 'callback' => [ $this, 'store' ], 'permission_callback' => $auth ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/contacts/(?P<id>\d+)',
			[
				[ 'methods' => 'GET', 'callback' => [ $this, 'show' ], 'permission_callback' => $auth ],
				[ 'methods' => 'POST', 'callback' => [ $this, 'update' ], 'permission_callback' => $auth ],
				[ 'methods' => 'DELETE', 'callback' => [ $this, 'destroy' ], 'permission_callback' => fn() => $this->canManage() ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/contacts/(?P<id>\d+)/score',
			[ 'methods' => 'POST', 'callback' => [ $this, 'rescore' ], 'permission_callback' => $auth ]
		);

		register_rest_route(
			self::NAMESPACE,
			'/contacts/(?P<id>\d+)/notes',
			[ 'methods' => 'POST', 'callback' => [ $this, 'addNote' ], 'permission_callback' => $auth ]
		);

		register_rest_route(
			self::NAMESPACE,
			'/crm/summary',
			[ 'methods' => 'GET', 'callback' => [ $this, 'summary' ], 'permission_callback' => $auth ]
		);
	}

	public function index( WP_REST_Request $request ) {
		$rows = $this->contacts->search(
			[
				'stage'     => $this->param( $request, 'stage' ),
				'search'    => $this->param( $request, 'search' ),
				'min_score' => (int) $request->get_param( 'min_score' ),
			],
			(int) ( $request->get_param( 'per_page' ) ?: 25 ),
			(int) ( $request->get_param( 'page' ) ?: 1 )
		);

		return $this->ok( [ 'contacts' => $rows, 'stages' => $this->contacts->stageCounts() ] );
	}

	public function store( WP_REST_Request $request ) {
		$id = $this->contacts->upsert(
			[
				'first_name'   => $this->param( $request, 'first_name' ),
				'last_name'    => $this->param( $request, 'last_name' ),
				'email'        => $this->param( $request, 'email' ),
				'phone'        => $this->param( $request, 'phone' ),
				'requirements' => $this->textParam( $request, 'requirements', 2000 ),
				'source'       => $this->param( $request, 'source', 'admin' ),
				'consent'      => (bool) $request->get_param( 'consent' ),
			]
		);

		if ( 0 === $id ) {
			return $this->fail( 'vmsos_contact_failed', __( 'The contact could not be saved.', 'vm-sales-os' ) );
		}

		return $this->ok( [ 'id' => $id, 'contact' => $this->contacts->find( $id ) ], 201 );
	}

	public function show( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$contact = $this->contacts->find( $id );

		if ( ! $contact ) {
			return $this->fail( 'vmsos_not_found', __( 'Contact not found.', 'vm-sales-os' ), 404 );
		}

		return $this->ok(
			[
				'contact'  => $contact,
				'timeline' => $this->activities->timeline( 'contact', $id ),
				/**
				 * Cross-session memory for this person, when the module is on.
				 *
				 * @param string $memory    Rendered memory.
				 * @param int    $contactId Contact id.
				 */
				'memory'   => (string) apply_filters( 'vmsos_contact_memory_summary', '', $id ),
			]
		);
	}

	public function update( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! $this->contacts->find( $id ) ) {
			return $this->fail( 'vmsos_not_found', __( 'Contact not found.', 'vm-sales-os' ), 404 );
		}

		$input = [];
		foreach ( [ 'first_name', 'last_name', 'email', 'phone', 'stage', 'requirements', 'consent' ] as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$input[ $field ] = $request->get_param( $field );
			}
		}

		if ( [] === $input ) {
			return $this->fail( 'vmsos_nothing_to_do', __( 'Nothing to update.', 'vm-sales-os' ) );
		}

		if ( ! $this->contacts->edit( $id, $input ) ) {
			return $this->fail( 'vmsos_update_failed', __( 'The contact could not be saved.', 'vm-sales-os' ) );
		}

		$this->activities->log( 'contact', $id, 'edited', __( 'Details updated by an operator.', 'vm-sales-os' ), 'human' );

		return $this->ok( [ 'contact' => $this->contacts->find( $id ) ] );
	}

	public function destroy( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		return $this->ok( [ 'deleted' => $this->contacts->delete( $id ) ] );
	}

	public function rescore( WP_REST_Request $request ) {
		return $this->ok( $this->scorer->score( (int) $request->get_param( 'id' ) ) );
	}

	public function addNote( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$body = $this->textParam( $request, 'body', 5000 );

		if ( '' === $body ) {
			return $this->fail( 'vmsos_empty_note', __( 'Write something before saving the note.', 'vm-sales-os' ) );
		}

		$noteId = $this->activities->log( 'contact', $id, 'note', $body, 'human' );
		return $this->ok( [ 'id' => $noteId ], 201 );
	}

	public function summary( WP_REST_Request $request ) {
		return $this->ok(
			[
				'stages'   => $this->contacts->stageCounts(),
				'forecast' => $this->deals->forecast(),
			]
		);
	}
}
