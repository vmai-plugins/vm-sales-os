<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Sequences;

use VMStudio\SalesOS\Support\IntegrationLog;
use VMStudio\SalesOS\Support\Logger;
use VMStudio\SalesOS\Support\SendGovernor;

/**
 * Sends one sequence step and wires up tracking.
 *
 * Tracking is per-send: one random token identifies the send, and open,
 * click and unsubscribe all resolve back through it. No recipient identifier
 * ever appears in a URL.
 */
final class Mailer {

	public function __construct(
		private EnrollmentRepository $enrollments,
		private Personalizer $personalizer,
		private IntegrationLog $integrations,
		private Logger $logger
	) {}

	/**
	 * @param array<string, mixed> $step       Step row.
	 * @param array<string, mixed> $contact    Contact row.
	 * @param array<string, mixed> $enrollment Enrolment row.
	 */
	public function send( array $step, array $contact, array $enrollment ): bool {
		$token = bin2hex( random_bytes( 16 ) );

		$subject = $this->personalizer->merge( (string) $step['subject'], $contact );
		$body    = $this->personalizer->merge( (string) $step['body'], $contact );

		if ( ! empty( $step['personalise'] ) ) {
			$body = $this->personalizer->personalise( $body, $contact );
		}

		$html = $this->wrap( $body, $token, $contact );

		add_filter( 'wp_mail_content_type', [ $this, 'htmlContentType' ] );

		$startedAt = microtime( true );

		$sent = wp_mail(
			(string) $contact['email'],
			$subject,
			$html,
			$this->headers()
		);

		remove_filter( 'wp_mail_content_type', [ $this, 'htmlContentType' ] );

		$durationMs = (int) round( ( microtime( true ) - $startedAt ) * 1000 );

		$context = [
			'enrollment_id' => (int) $enrollment['id'],
			'sequence_id'   => (int) $enrollment['sequence_id'],
			'step_id'       => (int) $step['id'],
			'contact_id'    => (int) $contact['id'],
			'token'         => $token,
		];

		$this->enrollments->logEvent( $sent ? 'sent' : 'failed', $context );

		$trace = [
			'ref_type'    => 'enrollment',
			'ref_id'      => (int) $enrollment['id'],
			'duration_ms' => $durationMs,
		];

		if ( $sent ) {
			// Only real sends count against the cap, so a broken SMTP host
			// cannot silently eat the whole day's budget.
			SendGovernor::recordSend( 'email' );
			$this->integrations->success( 'email', 'sequence_step', $trace );
		} else {
			$this->integrations->failure( 'email', 'sequence_step', 'wp_mail() returned false', $trace );
			$this->logger->error( 'sequences', 'Step email failed to send', $context );
		}

		return (bool) $sent;
	}

	public function htmlContentType(): string {
		return 'text/html';
	}

	/** @return array<int, string> */
	private function headers(): array {
		$from  = (string) get_option( 'vmsos_sender_email', get_option( 'admin_email' ) );
		$name  = (string) get_option( 'vmsos_sender_name', get_bloginfo( 'name' ) );
		$reply = (string) get_option( 'vmsos_reply_to', $from );

		$headers = [];

		if ( is_email( $from ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $name, $from );
		}

		if ( is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		return $headers;
	}

	/**
	 * @param array<string, mixed> $contact Contact row.
	 */
	private function wrap( string $body, string $token, array $contact ): string {
		$html = wpautop( $body );
		$html = $this->rewriteLinks( $html, $token );

		$unsubscribe = $this->trackingUrl( 'u', $token );
		$pixel       = $this->trackingUrl( 'o', $token );

		$footer = sprintf(
			'<p style="margin-top:28px;font-size:12px;color:#7b8496">%s &middot; <a href="%s" style="color:#7b8496">%s</a></p>',
			esc_html( (string) get_option( 'vmsos_sender_name', get_bloginfo( 'name' ) ) ),
			esc_url( $unsubscribe ),
			esc_html__( 'Unsubscribe', 'vm-sales-os' )
		);

		$tracker = get_option( 'vmsos_track_opens', true )
			? sprintf( '<img src="%s" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0">', esc_url( $pixel ) )
			: '';

		return '<div style="font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1c2333">'
			. $html . $footer . $tracker . '</div>';
	}

	/** Swap outbound links for tracked redirects, leaving mailto and anchors alone. */
	private function rewriteLinks( string $html, string $token ): string {
		if ( ! get_option( 'vmsos_track_clicks', true ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'/href=(["\'])(https?:\/\/[^"\']+)\1/i',
			function ( array $matches ) use ( $token ): string {
				$url = $matches[2];

				// Never rewrite our own tracking links into themselves.
				if ( str_contains( $url, 'vmsos/v1/track' ) ) {
					return $matches[0];
				}

				$tracked = add_query_arg( 'u', rawurlencode( $url ), $this->trackingUrl( 'c', $token ) );

				return 'href="' . esc_url( $tracked ) . '"';
			},
			$html
		);
	}

	private function trackingUrl( string $kind, string $token ): string {
		return rest_url( 'vmsos/v1/track/' . $kind . '/' . $token );
	}
}
