<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\CRM;

use VMStudio\SalesOS\Core\EventBus;
use VMStudio\SalesOS\REST\AbstractController;
use WP_REST_Request;

final class FormController extends AbstractController {

	public function __construct(
		private ContactRepository $contacts,
		private LeadScorer $scorer,
		private EventBus $events
	) {}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/forms/submit',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'submit' ],
				'permission_callback' => fn( WP_REST_Request $request ) => $this->guard( $request ),
				'args'                => [
					'email' => [ 'required' => true, 'type' => 'string' ],
				],
			]
		);
	}

	private function guard( WP_REST_Request $request ) {
		// Honeypot first: bots fill every field they can see.
		if ( '' !== (string) $request->get_param( 'company_website' ) ) {
			return $this->fail( 'vmsos_rejected', __( 'Rejected.', 'vm-sales-os' ), 403 );
		}

		return $this->throttle( 'form', 8, MINUTE_IN_SECONDS * 10 );
	}

	public function submit( WP_REST_Request $request ) {
		$email = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( ! is_email( $email ) ) {
			return $this->fail( 'vmsos_bad_email', __( 'Enter a valid email address.', 'vm-sales-os' ) );
		}

		$contactId = $this->contacts->upsert(
			[
				'first_name'   => $this->param( $request, 'first_name' ),
				'last_name'    => $this->param( $request, 'last_name' ),
				'email'        => $email,
				'phone'        => $this->param( $request, 'phone' ),
				'requirements' => $this->textParam( $request, 'requirements', 2000 ),
				'source'       => $this->param( $request, 'source', 'form' ),
				'consent'      => (bool) $request->get_param( 'consent' ),
			]
		);

		if ( 0 === $contactId ) {
			return $this->fail( 'vmsos_form_failed', __( 'We could not save that. Try again.', 'vm-sales-os' ) );
		}

		$this->events->dispatch(
			'contact.captured',
			[
				'contact_id' => $contactId,
				'source'     => $this->param( $request, 'source', 'form' ),
				'email'      => $email,
			]
		);

		// Scoring can be slow and the visitor should not wait for it.
		$this->scorer->score( $contactId, $this->textParam( $request, 'requirements', 2000 ) );

		return $this->ok( [ 'saved' => true ], 201 );
	}
}
