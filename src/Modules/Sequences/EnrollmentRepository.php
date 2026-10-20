<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Sequences;

use VMStudio\SalesOS\Database\Repository;

final class EnrollmentRepository extends Repository {

	protected function table(): string {
		return 'vmsos_enrollments';
	}

	private function eventsTable(): string {
		return $this->db->prefix . 'vmsos_email_events';
	}

	/**
	 * Enrol a contact. Re-enrolling someone already in the sequence is a
	 * no-op rather than an error — triggers fire more than once in real life.
	 */
	public function enroll( int $sequenceId, int $contactId, int $delayMinutes = 0 ): int {
		$existing = $this->db->get_row( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT * FROM `' . $this->name() . '` WHERE sequence_id = %d AND contact_id = %d',
				$sequenceId,
				$contactId
			),
			ARRAY_A
		);

		if ( $existing ) {
			return 'active' === $existing['status'] ? (int) $existing['id'] : 0;
		}

		return $this->insert(
			[
				'sequence_id'  => $sequenceId,
				'contact_id'   => $contactId,
				'status'       => 'active',
				'current_step' => 0,
				'next_run_at'  => gmdate( 'Y-m-d H:i:s', time() + ( $delayMinutes * MINUTE_IN_SECONDS ) ),
				'created_at'   => $this->now(),
			],
			[ '%d', '%d', '%s', '%d', '%s', '%s' ]
		);
	}

	/**
	 * Enrolments whose next step is due.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function due( int $limit = 25 ): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT * FROM `' . $this->name() . '`
				 WHERE brand_id = %d AND status = %s AND next_run_at IS NOT NULL AND next_run_at <= %s
				 ORDER BY next_run_at ASC LIMIT %d',
				$this->brand(),
				'active',
				$this->now(),
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		) ?: [];
	}

	public function advance( int $id, int $nextStep, int $delayMinutes ): bool {
		return $this->update(
			$id,
			[
				'current_step' => $nextStep,
				'next_run_at'  => gmdate( 'Y-m-d H:i:s', time() + ( $delayMinutes * MINUTE_IN_SECONDS ) ),
				'last_step_at' => $this->now(),
			],
			[ '%d', '%s', '%s' ]
		);
	}

	public function complete( int $id ): bool {
		return $this->update(
			$id,
			[ 'status' => 'completed', 'next_run_at' => null, 'last_step_at' => $this->now() ],
			[ '%s', '%s', '%s' ]
		);
	}

	public function stop( int $id, string $reason ): bool {
		return $this->update(
			$id,
			[ 'status' => 'stopped', 'stop_reason' => sanitize_key( $reason ), 'next_run_at' => null ],
			[ '%s', '%s', '%s' ]
		);
	}

	/** Stop every active enrolment for a contact — used by reply and unsubscribe. */
	public function stopAllForContact( int $contactId, string $reason ): int {
		return (int) $this->db->query( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'UPDATE `' . $this->name() . '`
				 SET status = %s, stop_reason = %s, next_run_at = NULL
				 WHERE brand_id = %d AND contact_id = %d AND status = %s',
				'stopped',
				sanitize_key( $reason ),
				$this->brand(),
				$contactId,
				'active'
			)
		);
	}

	/** @return array<int, array<string, mixed>> */
	public function forSequence( int $sequenceId, int $limit = 50 ): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT e.*, c.email, c.first_name, c.last_name
				 FROM `' . $this->name() . '` e
				 LEFT JOIN `' . $this->db->prefix . 'vmsos_contacts` c ON c.id = e.contact_id
				 WHERE e.brand_id = %d AND e.sequence_id = %d
				 ORDER BY e.id DESC LIMIT %d',
				$this->brand(),
				$sequenceId,
				max( 1, min( 200, $limit ) )
			),
			ARRAY_A
		) ?: [];
	}

	public function logEvent( string $type, array $context ): int {
		$this->db->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->eventsTable(),
			[
				'brand_id'      => $this->brand(),
				'enrollment_id' => (int) ( $context['enrollment_id'] ?? 0 ),
				'sequence_id'   => (int) ( $context['sequence_id'] ?? 0 ),
				'step_id'       => (int) ( $context['step_id'] ?? 0 ),
				'contact_id'    => (int) ( $context['contact_id'] ?? 0 ),
				'type'          => sanitize_key( $type ),
				'token'         => substr( (string) ( $context['token'] ?? '' ), 0, 32 ),
				'url'           => esc_url_raw( (string) ( $context['url'] ?? '' ) ),
				'created_at'    => $this->now(),
			],
			[ '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
		);

		return (int) $this->db->insert_id;
	}

	/** @return array<string, mixed>|null */
	public function findByToken( string $token ): ?array {
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT * FROM `' . $this->eventsTable() . '` WHERE token = %s AND type = %s ORDER BY id ASC LIMIT 1',
				substr( $token, 0, 32 ),
				'sent'
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Counts per event type for a sequence — sent, opened, clicked, unsubscribed.
	 *
	 * @return array<string, int>
	 */
	public function stats( int $sequenceId ): array {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT type, COUNT(DISTINCT enrollment_id) AS total
				 FROM `' . $this->eventsTable() . '`
				 WHERE brand_id = %d AND sequence_id = %d
				 GROUP BY type',
				$this->brand(),
				$sequenceId
			),
			ARRAY_A
		) ?: [];

		$stats = [ 'sent' => 0, 'open' => 0, 'click' => 0, 'unsubscribe' => 0, 'failed' => 0 ];

		foreach ( $rows as $row ) {
			$stats[ (string) $row['type'] ] = (int) $row['total'];
		}

		$stats['active']    = $this->count( 'sequence_id = %d AND status = %s', [ $sequenceId, 'active' ] );
		$stats['completed'] = $this->count( 'sequence_id = %d AND status = %s', [ $sequenceId, 'completed' ] );
		$stats['stopped']   = $this->count( 'sequence_id = %d AND status = %s', [ $sequenceId, 'stopped' ] );

		return $stats;
	}

	/**
	 * Stop enrolments for a contact, but only in sequences that asked to
	 * stop when the person engages. A nurture drip should go quiet the
	 * moment someone actually replies; an onboarding series should not.
	 */
	public function stopOnEngagement( int $contactId, string $reason = 'replied' ): int {
		$sequences = $this->db->prefix . 'vmsos_sequences';

		return (int) $this->db->query( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'UPDATE `' . $this->name() . '` e
				 INNER JOIN `' . $sequences . '` s ON s.id = e.sequence_id
				 SET e.status = %s, e.stop_reason = %s, e.next_run_at = NULL
				 WHERE e.brand_id = %d AND e.contact_id = %d AND e.status = %s AND s.stop_on_reply = 1',
				'stopped',
				sanitize_key( $reason ),
				$this->brand(),
				$contactId,
				'active'
			)
		);
	}
}
