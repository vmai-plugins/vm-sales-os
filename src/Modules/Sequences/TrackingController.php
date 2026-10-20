<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Sequences;

use VMStudio\SalesOS\Core\EventBus;
use VMStudio\SalesOS\Modules\CRM\ContactRepository;
use VMStudio\SalesOS\REST\AbstractController;
use WP_REST_Request;

/**
 * Open, click and unsubscribe endpoints.
 *
 * These are public by necessity — they are opened from an email client. The
 * token is the only credential and it maps to a single send, so a leaked
 * token exposes nothing beyond that one message.
 */
final class TrackingController extends AbstractController {

	/** Transparent 1x1 GIF. */
	private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

	public function __construct(
		private EnrollmentRepository $enrollments,
		private ContactRepository $contacts,
		private EventBus $events
	) {}

	public function register(): void {
		$open = '__return_true';

		register_rest_route(
			self::NAMESPACE,
			'/track/o/(?P<token>[a-f0-9]{32})',
			[ 'methods' => 'GET', 'callback' => [ $this, 'open' ], 'permission_callback' => $open ]
		);

		register_rest_route(
			self::NAMESPACE,
			'/track/c/(?P<token>[a-f0-9]{32})',
			[ 'methods' => 'GET', 'callback' => [ $this, 'click' ], 'permission_callback' => $open ]
		);

		register_rest_route(
			self::NAMESPACE,
			'/track/u/(?P<token>[a-f0-9]{32})',
			[ 'methods' => 'GET', 'callback' => [ $this, 'unsubscribe' ], 'permission_callback' => $open ]
		);
	}

	public function open( WP_REST_Request $request ) {
		$send = $this->enrollments->findByToken( (string) $request->get_param( 'token' ) );

		if ( $send ) {
			$this->record( 'open', $send );
		}

		// Always return the pixel, even for an unknown token: never confirm
		// to a scanner whether a token is real.
		nocache_headers();
		header( 'Content-Type: image/gif' );
		header( 'Content-Length: 43' );
		echo base64_decode( self::PIXEL ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public function click( WP_REST_Request $request ) {
		$send = $this->enrollments->findByToken( (string) $request->get_param( 'token' ) );
		$url  = (string) $request->get_param( 'u' );
		$url  = '' !== $url ? rawurldecode( $url ) : '';

		if ( $send ) {
			$this->record( 'click', $send, $url );
		}

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		// External destinations are expected here, so this is a deliberate
		// unsafe redirect — the URL came from the email we composed.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	public function unsubscribe( WP_REST_Request $request ) {
		$send = $this->enrollments->findByToken( (string) $request->get_param( 'token' ) );

		if ( $send ) {
			$contactId = (int) $send['contact_id'];

			$this->record( 'unsubscribe', $send );
			$this->contacts->setMarketingStatus( $contactId, 'unsubscribed' );
			$this->enrollments->stopAllForContact( $contactId, 'unsubscribed' );

			$this->events->dispatch( 'contact.unsubscribed', [ 'contact_id' => $contactId ] );
		}

		wp_die(
			esc_html__( 'You have been unsubscribed. You will not receive further emails from us.', 'vm-sales-os' ),
			esc_html__( 'Unsubscribed', 'vm-sales-os' ),
			[ 'response' => 200, 'back_link' => false ]
		);
	}

	/** @param array<string, mixed> $send The original sent event row. */
	private function record( string $type, array $send, string $url = '' ): void {
		$this->enrollments->logEvent(
			$type,
			[
				'enrollment_id' => (int) $send['enrollment_id'],
				'sequence_id'   => (int) $send['sequence_id'],
				'step_id'       => (int) $send['step_id'],
				'contact_id'    => (int) $send['contact_id'],
				'token'         => (string) $send['token'],
				'url'           => $url,
			]
		);
	}
}
