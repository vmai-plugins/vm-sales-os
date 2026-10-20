<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Sequences;

use InvalidArgumentException;
use VMStudio\SalesOS\Modules\CRM\ContactRepository;
use VMStudio\SalesOS\Queue\JobHandler;

final class SendStepJob implements JobHandler {

	public function __construct(
		private EnrollmentRepository $enrollments,
		private SequenceRepository $sequences,
		private ContactRepository $contacts,
		private Mailer $mailer
	) {}

	public static function name(): string {
		return 'sequences.send';
	}

	/** @param array<string, mixed> $payload enrollment_id + step_id. */
	public function handle( array $payload ): void {
		$enrollmentId = (int) ( $payload['enrollment_id'] ?? 0 );
		$stepId       = (int) ( $payload['step_id'] ?? 0 );

		if ( $enrollmentId <= 0 || $stepId <= 0 ) {
			throw new InvalidArgumentException( 'Missing enrollment_id or step_id.' );
		}

		$enrollment = $this->enrollments->find( $enrollmentId );

		if ( ! $enrollment ) {
			// The enrolment was deleted between queueing and running. Nothing to do.
			return;
		}

		$step = null;
		foreach ( $this->sequences->steps( (int) $enrollment['sequence_id'] ) as $candidate ) {
			if ( (int) $candidate['id'] === $stepId ) {
				$step = $candidate;
				break;
			}
		}

		if ( ! $step ) {
			return;
		}

		$contact = $this->contacts->find( (int) $enrollment['contact_id'] );

		// Consent is checked again here: it can change while the job waits.
		if ( ! $contact || ! $this->contacts->mayEmail( $contact ) ) {
			$this->enrollments->stop( $enrollmentId, 'not_mailable' );
			return;
		}

		$this->mailer->send( $step, $contact, $enrollment );
	}
}
