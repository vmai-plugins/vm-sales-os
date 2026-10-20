<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Sequences;

use VMStudio\SalesOS\Modules\CRM\ContactRepository;
use VMStudio\SalesOS\REST\AbstractController;
use WP_REST_Request;

final class SequencesController extends AbstractController {

	public function __construct(
		private SequenceRepository $sequences,
		private EnrollmentRepository $enrollments,
		private ContactRepository $contacts,
		private Mailer $mailer
	) {}

	public function register(): void {
		$auth   = fn() => $this->canOperate();
		$manage = fn() => $this->canManage();

		register_rest_route(
			self::NAMESPACE,
			'/sequences',
			[
				[ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => $auth ],
				[ 'methods' => 'POST', 'callback' => [ $this, 'store' ], 'permission_callback' => $manage ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/sequences/(?P<id>\d+)',
			[
				[ 'methods' => 'GET', 'callback' => [ $this, 'show' ], 'permission_callback' => $auth ],
				[ 'methods' => 'POST', 'callback' => [ $this, 'update' ], 'permission_callback' => $manage ],
				[ 'methods' => 'DELETE', 'callback' => [ $this, 'destroy' ], 'permission_callback' => $manage ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/sequences/(?P<id>\d+)/steps',
			[ 'methods' => 'POST', 'callback' => [ $this, 'addStep' ], 'permission_callback' => $manage ]
		);

		register_rest_route(
			self::NAMESPACE,
			'/sequences/steps/(?P<step>\d+)',
			[
				[ 'methods' => 'POST', 'callback' => [ $this, 'editStep' ], 'permission_callback' => $manage ],
				[ 'methods' => 'DELETE', 'callback' => [ $this, 'deleteStep' ], 'permission_callback' => $manage ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/sequences/(?P<id>\d+)/enroll',
			[ 'methods' => 'POST', 'callback' => [ $this, 'enroll' ], 'permission_callback' => $auth ]
		);

		register_rest_route(
			self::NAMESPACE,
			'/sequences/(?P<id>\d+)/test',
			[ 'methods' => 'POST', 'callback' => [ $this, 'test' ], 'permission_callback' => $manage ]
		);
	}

	public function index( WP_REST_Request $request ) {
		$rows = $this->sequences->all( $this->param( $request, 'status' ) );

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['steps'] = $this->sequences->stepCount( (int) $row['id'] );
			$rows[ $index ]['stats'] = $this->enrollments->stats( (int) $row['id'] );
		}

		return $this->ok( [ 'sequences' => $rows, 'triggers' => $this->triggers() ] );
	}

	public function show( WP_REST_Request $request ) {
		$id       = (int) $request->get_param( 'id' );
		$sequence = $this->sequences->find( $id );

		if ( ! $sequence ) {
			return $this->fail( 'vmsos_not_found', __( 'Sequence not found.', 'vm-sales-os' ), 404 );
		}

		return $this->ok(
			[
				'sequence'    => $sequence,
				'steps'       => $this->sequences->steps( $id ),
				'stats'       => $this->enrollments->stats( $id ),
				'enrollments' => $this->enrollments->forSequence( $id, 50 ),
				'triggers'    => $this->triggers(),
			]
		);
	}

	public function store( WP_REST_Request $request ) {
		$id = $this->sequences->create(
			[
				'name'          => $this->param( $request, 'name' ),
				'status'        => $this->param( $request, 'status', 'draft' ),
				'trigger_event' => $this->param( $request, 'trigger_event' ),
				'stop_on_reply' => null === $request->get_param( 'stop_on_reply' ) ? true : (bool) $request->get_param( 'stop_on_reply' ),
			]
		);

		if ( 0 === $id ) {
			return $this->fail( 'vmsos_sequence_failed', __( 'Give the sequence a name.', 'vm-sales-os' ) );
		}

		return $this->ok( [ 'sequence' => $this->sequences->find( $id ) ], 201 );
	}

	public function update( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! $this->sequences->find( $id ) ) {
			return $this->fail( 'vmsos_not_found', __( 'Sequence not found.', 'vm-sales-os' ), 404 );
		}

		$input = [];
		foreach ( [ 'name', 'status', 'trigger_event', 'goal_event', 'stop_on_reply' ] as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$input[ $field ] = $request->get_param( $field );
			}
		}

		// Activating a sequence with no steps just fills the log with noise.
		if ( 'active' === ( $input['status'] ?? '' ) && 0 === $this->sequences->stepCount( $id ) ) {
			return $this->fail( 'vmsos_no_steps', __( 'Add at least one step before activating.', 'vm-sales-os' ) );
		}

		$this->sequences->edit( $id, $input );

		return $this->ok( [ 'sequence' => $this->sequences->find( $id ) ] );
	}

	public function destroy( WP_REST_Request $request ) {
		return $this->ok( [ 'deleted' => $this->sequences->delete( (int) $request->get_param( 'id' ) ) ] );
	}

	public function addStep( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! $this->sequences->find( $id ) ) {
			return $this->fail( 'vmsos_not_found', __( 'Sequence not found.', 'vm-sales-os' ), 404 );
		}

		$stepId = $this->sequences->addStep(
			$id,
			[
				'type'          => $this->param( $request, 'type', 'email' ),
				'delay_minutes' => (int) $request->get_param( 'delay_minutes' ),
				'subject'       => $this->param( $request, 'subject' ),
				'body'          => (string) $request->get_param( 'body' ),
				'personalise'   => (bool) $request->get_param( 'personalise' ),
			]
		);

		return $this->ok( [ 'steps' => $this->sequences->steps( $id ), 'id' => $stepId ], 201 );
	}

	public function editStep( WP_REST_Request $request ) {
		$stepId = (int) $request->get_param( 'step' );
		$input  = [];

		foreach ( [ 'type', 'delay_minutes', 'subject', 'body', 'personalise', 'position' ] as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$input[ $field ] = $request->get_param( $field );
			}
		}

		if ( ! $this->sequences->editStep( $stepId, $input ) ) {
			return $this->fail( 'vmsos_step_failed', __( 'Nothing to update.', 'vm-sales-os' ) );
		}

		return $this->ok( [ 'updated' => true ] );
	}

	public function deleteStep( WP_REST_Request $request ) {
		return $this->ok( [ 'deleted' => $this->sequences->deleteStep( (int) $request->get_param( 'step' ) ) ] );
	}

	public function enroll( WP_REST_Request $request ) {
		$sequenceId = (int) $request->get_param( 'id' );
		$contactId  = (int) $request->get_param( 'contact_id' );
		$contact    = $this->contacts->find( $contactId );

		if ( ! $contact ) {
			return $this->fail( 'vmsos_not_found', __( 'Contact not found.', 'vm-sales-os' ), 404 );
		}

		if ( ! $this->contacts->mayEmail( $contact ) ) {
			return $this->fail( 'vmsos_no_consent', __( 'That contact has not opted in to email.', 'vm-sales-os' ) );
		}

		$steps = $this->sequences->steps( $sequenceId );

		if ( [] === $steps ) {
			return $this->fail( 'vmsos_no_steps', __( 'This sequence has no steps yet.', 'vm-sales-os' ) );
		}

		$id = $this->enrollments->enroll( $sequenceId, $contactId, (int) $steps[0]['delay_minutes'] );

		if ( 0 === $id ) {
			return $this->fail( 'vmsos_already_done', __( 'That contact already went through this sequence.', 'vm-sales-os' ) );
		}

		return $this->ok( [ 'enrollment_id' => $id ], 201 );
	}

	/** Send one step to the logged-in user so they can see it in a real inbox. */
	public function test( WP_REST_Request $request ) {
		$sequenceId = (int) $request->get_param( 'id' );
		$stepId     = (int) $request->get_param( 'step_id' );
		$user       = wp_get_current_user();

		$step = null;
		foreach ( $this->sequences->steps( $sequenceId ) as $candidate ) {
			if ( 0 === $stepId || (int) $candidate['id'] === $stepId ) {
				$step = $candidate;
				break;
			}
		}

		if ( ! $step ) {
			return $this->fail( 'vmsos_not_found', __( 'Step not found.', 'vm-sales-os' ), 404 );
		}

		$sent = $this->mailer->send(
			$step,
			[
				'id'           => 0,
				'email'        => $user->user_email,
				'first_name'   => $user->first_name ?: $user->display_name,
				'last_name'    => $user->last_name,
				'requirements' => __( 'This is a test send.', 'vm-sales-os' ),
			],
			[ 'id' => 0, 'sequence_id' => $sequenceId ]
		);

		if ( ! $sent ) {
			return $this->fail( 'vmsos_mail_failed', __( 'WordPress could not send the test email. Check your SMTP setup.', 'vm-sales-os' ) );
		}

		return $this->ok( [ 'sent_to' => $user->user_email ] );
	}

	/** @return array<int, array<string, string>> */
	private function triggers(): array {
		return (array) apply_filters(
			'vmsos_sequence_triggers',
			[
				[ 'key' => '', 'label' => __( 'Manual only', 'vm-sales-os' ) ],
				[ 'key' => 'contact.captured', 'label' => __( 'New lead captured', 'vm-sales-os' ) ],
				[ 'key' => 'contact.hot', 'label' => __( 'Lead becomes hot', 'vm-sales-os' ) ],
				[ 'key' => 'conversation.closed', 'label' => __( 'Conversation closed', 'vm-sales-os' ) ],
				[ 'key' => 'deal.stage_changed', 'label' => __( 'Deal changes stage', 'vm-sales-os' ) ],
			]
		);
	}
}
