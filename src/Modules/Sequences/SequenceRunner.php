<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Sequences;

use VMStudio\SalesOS\Core\EventBus;
use VMStudio\SalesOS\Modules\CRM\ContactRepository;
use VMStudio\SalesOS\Queue\QueueManager;
use VMStudio\SalesOS\Support\Logger;
use VMStudio\SalesOS\Support\SendGovernor;

/**
 * The clock of the outreach engine.
 *
 * Runs every minute, picks up enrolments whose next step is due, and hands
 * the actual sending to the queue so a slow mail server never stalls the tick.
 */
final class SequenceRunner {

	private const BATCH = 25;

	public function __construct(
		private SequenceRepository $sequences,
		private EnrollmentRepository $enrollments,
		private ContactRepository $contacts,
		private QueueManager $queue,
		private EventBus $events,
		private Logger $logger
	) {}

	public function tick(): int {
		$processed = 0;

		foreach ( $this->enrollments->due( self::BATCH ) as $enrollment ) {
			$processed += $this->step( $enrollment ) ? 1 : 0;
		}

		return $processed;
	}

	/** @param array<string, mixed> $enrollment Enrolment row. */
	private function step( array $enrollment ): bool {
		$enrollmentId = (int) $enrollment['id'];
		$sequence     = $this->sequences->find( (int) $enrollment['sequence_id'] );

		if ( ! $sequence || 'active' !== $sequence['status'] ) {
			$this->enrollments->stop( $enrollmentId, 'sequence_inactive' );
			return false;
		}

		$contact = $this->contacts->find( (int) $enrollment['contact_id'] );

		if ( ! $contact ) {
			$this->enrollments->stop( $enrollmentId, 'contact_missing' );
			return false;
		}

		$position = (int) $enrollment['current_step'];
		$steps    = $this->sequences->steps( (int) $sequence['id'] );
		$step     = $steps[ $position ] ?? null;

		if ( ! $step ) {
			$this->enrollments->complete( $enrollmentId );
			$this->events->dispatch(
				'sequence.completed',
				[ 'sequence_id' => (int) $sequence['id'], 'contact_id' => (int) $contact['id'] ]
			);
			return false;
		}

		$this->execute( $step, $contact, $enrollment );

		$next      = $steps[ $position + 1 ] ?? null;
		$nextDelay = $next ? (int) $next['delay_minutes'] : 0;

		if ( $next ) {
			$this->enrollments->advance( $enrollmentId, $position + 1, $nextDelay );
		} else {
			$this->enrollments->complete( $enrollmentId );
			$this->events->dispatch(
				'sequence.completed',
				[ 'sequence_id' => (int) $sequence['id'], 'contact_id' => (int) $contact['id'] ]
			);
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $step       Step row.
	 * @param array<string, mixed> $contact    Contact row.
	 * @param array<string, mixed> $enrollment Enrolment row.
	 */
	private function execute( array $step, array $contact, array $enrollment ): void {
		$type = (string) $step['type'];

		if ( 'email' === $type ) {
			if ( ! $this->contacts->mayEmail( $contact ) ) {
				$this->enrollments->stop( (int) $enrollment['id'], 'not_mailable' );
				return;
			}

			// Quiet hours and daily caps postpone the send; they never drop it
			// and never count as a failure.
			$verdict = SendGovernor::check( 'email' );

			if ( ! $verdict['allowed'] ) {
				$this->enrollments->advance(
					(int) $enrollment['id'],
					(int) $enrollment['current_step'],
					(int) ceil( $verdict['retry_after'] / MINUTE_IN_SECONDS )
				);

				$this->logger->info(
					'sequences',
					'Step postponed by the send governor',
					[ 'enrollment_id' => (int) $enrollment['id'], 'reason' => $verdict['reason'] ]
				);

				return;
			}

			$this->queue->push(
				SendStepJob::name(),
				[ 'enrollment_id' => (int) $enrollment['id'], 'step_id' => (int) $step['id'] ],
				0,
				'mail'
			);
			return;
		}

		if ( 'task' === $type ) {
			$this->events->dispatch(
				'sequence.task_due',
				[
					'contact_id'  => (int) $contact['id'],
					'sequence_id' => (int) $enrollment['sequence_id'],
					'subject'     => (string) $step['subject'],
					'body'        => (string) $step['body'],
				]
			);
			return;
		}

		if ( 'tag' === $type ) {
			$this->events->dispatch(
				'contact.tagged',
				[ 'contact_id' => (int) $contact['id'], 'tag' => sanitize_key( (string) $step['subject'] ) ]
			);
			return;
		}

		if ( 'wait' === $type ) {
			return;
		}

		/**
		 * Custom step types plug in here.
		 *
		 * @param array<string, mixed> $step    Step row.
		 * @param array<string, mixed> $contact Contact row.
		 */
		do_action( 'vmsos_sequence_step_' . sanitize_key( $type ), $step, $contact );

		$this->logger->info( 'sequences', 'Custom step executed', [ 'type' => $type, 'step_id' => (int) $step['id'] ] );
	}

	/** Trigger enrolment from an event, respecting per-sequence entry rules. */
	public function enrollFromEvent( string $event, int $contactId ): void {
		if ( $contactId <= 0 ) {
			return;
		}

		foreach ( $this->sequences->activeFor( $event ) as $sequence ) {
			$steps = $this->sequences->steps( (int) $sequence['id'] );

			if ( [] === $steps ) {
				continue;
			}

			$this->enrollments->enroll(
				(int) $sequence['id'],
				$contactId,
				(int) $steps[0]['delay_minutes']
			);
		}
	}
}
