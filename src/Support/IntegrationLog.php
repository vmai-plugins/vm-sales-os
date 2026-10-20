<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Support;

use wpdb;

/**
 * Observability for every outbound call the plugin makes.
 *
 * A failed WhatsApp send or a bounced SMTP handoff should be visible on a
 * screen, not buried in a server error log nobody reads. Each attempt is
 * recorded with a status and a duration; the health summary is what the
 * Reports tab shows.
 */
final class IntegrationLog {

	private wpdb $db;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	private function table(): string {
		return $this->db->prefix . 'vmsos_integration_log';
	}

	/** @param array<string, mixed> $context ref_type, ref_id, retry_count, duration_ms. */
	public function record( string $integration, string $event, string $status, string $message = '', array $context = [] ): void {
		$status = in_array( $status, [ 'success', 'failure', 'retrying' ], true ) ? $status : 'failure';

		$this->db->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			[
				'brand_id'    => BrandContext::current(),
				'integration' => sanitize_key( $integration ),
				'event'       => sanitize_text_field( $event ),
				'status'      => $status,
				'message'     => mb_substr( wp_strip_all_tags( $message ), 0, 1000 ),
				'ref_type'    => sanitize_key( (string) ( $context['ref_type'] ?? '' ) ),
				'ref_id'      => (int) ( $context['ref_id'] ?? 0 ),
				'retry_count' => (int) ( $context['retry_count'] ?? 0 ),
				'duration_ms' => (int) ( $context['duration_ms'] ?? 0 ),
				'created_at'  => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ]
		);

		// Keep the table bounded, sampled so logging never adds a DELETE to
		// every single call.
		if ( 1 === wp_rand( 1, 50 ) ) {
			$this->db->query( // phpcs:ignore WordPress.DB
				$this->db->prepare(
					'DELETE FROM `' . $this->table() . '` WHERE created_at < %s',
					gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) )
				)
			);
		}
	}

	/** @param array<string, mixed> $context Extra fields. */
	public function success( string $integration, string $event, array $context = [] ): void {
		$this->record( $integration, $event, 'success', '', $context );
	}

	/** @param array<string, mixed> $context Extra fields. */
	public function failure( string $integration, string $event, string $message, array $context = [] ): void {
		$this->record( $integration, $event, 'failure', $message, $context );
	}

	/**
	 * Per-integration success/failure counts for a recent window.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function health( int $hours = 24 ): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT integration,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS ok,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS failed,
					ROUND(AVG(duration_ms)) AS avg_ms,
					MAX(CASE WHEN status = %s THEN created_at END) AS last_success,
					MAX(CASE WHEN status = %s THEN created_at END) AS last_failure
				 FROM `' . $this->table() . '`
				 WHERE brand_id = %d AND created_at >= %s
				 GROUP BY integration
				 ORDER BY integration ASC',
				'success',
				'failure',
				'success',
				'failure',
				BrandContext::current(),
				gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $hours ) * HOUR_IN_SECONDS ) )
			),
			ARRAY_A
		) ?: [];
	}

	/** @return array<int, array<string, mixed>> */
	public function recentFailures( int $limit = 20 ): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT integration, event, message, ref_type, ref_id, created_at
				 FROM `' . $this->table() . '`
				 WHERE brand_id = %d AND status = %s
				 ORDER BY id DESC LIMIT %d',
				BrandContext::current(),
				'failure',
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		) ?: [];
	}
}
