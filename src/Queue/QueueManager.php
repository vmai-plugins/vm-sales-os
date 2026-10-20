<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Queue;

use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Support\BrandContext;
use VMStudio\SalesOS\Support\Logger;

/**
 * Database-backed job queue drained by cron (or WP-CLI on busier sites).
 * Jobs are claimed atomically so parallel workers cannot double-run one job.
 */
final class QueueManager {

	private const BATCH   = 20;
	private const BACKOFF = [ 60, 300, 900, 3600, 21600 ];

	/** @var array<string, class-string<JobHandler>> */
	private array $handlers = [];

	public function __construct( private Container $container, private Logger $logger ) {}

	public function boot(): void {
		add_filter( 'cron_schedules', [ $this, 'addSchedule' ] ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( 'vmsos_queue_tick', [ $this, 'work' ] );

		if ( ! wp_next_scheduled( 'vmsos_queue_tick' ) ) {
			wp_schedule_event( time() + 60, 'vmsos_minute', 'vmsos_queue_tick' );
		}

		do_action( 'vmsos_register_jobs', $this );
	}

	/** @param array<string, mixed> $schedules Existing schedules. */
	public function addSchedule( array $schedules ): array {
		$schedules['vmsos_minute'] = [ 'interval' => 60, 'display' => __( 'Every minute (VM Sales OS)', 'vm-sales-os' ) ];
		return $schedules;
	}

	/** @param class-string<JobHandler> $class Handler class. */
	public function registerHandler( string $class ): void {
		$this->handlers[ $class::name() ] = $class;
	}

	/**
	 * @param array<string, mixed> $payload Job payload.
	 */
	public function push( string $handler, array $payload = [], int $delaySeconds = 0, string $queue = 'default' ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'vmsos_jobs',
			[
				'brand_id'     => BrandContext::current(),
				'queue'        => $queue,
				'handler'      => $handler,
				'payload'      => wp_json_encode( $payload ),
				'status'       => 'pending',
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + max( 0, $delaySeconds ) ),
				'created_at'   => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	public function work( int $limit = self::BATCH ): int {
		global $wpdb;
		$table     = $wpdb->prefix . 'vmsos_jobs';
		$processed = 0;

		for ( $i = 0; $i < $limit; $i++ ) {
			$job = $wpdb->get_row( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					'SELECT * FROM `' . $table . '` WHERE status = %s AND available_at <= UTC_TIMESTAMP() ORDER BY id ASC LIMIT 1',
					'pending'
				),
				ARRAY_A
			);

			if ( ! $job ) {
				break;
			}

			// Atomic claim: only the worker that flips the row runs it.
			$claimed = $wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					'UPDATE `' . $table . '` SET status = %s, reserved_at = UTC_TIMESTAMP(), attempts = attempts + 1 WHERE id = %d AND status = %s',
					'running',
					(int) $job['id'],
					'pending'
				)
			);

			if ( ! $claimed ) {
				continue;
			}

			$this->run( $job );
			$processed++;
		}

		$this->reapStuckJobs();
		return $processed;
	}

	/** @param array<string, mixed> $job Job row. */
	private function run( array $job ): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'vmsos_jobs';
		$id      = (int) $job['id'];
		$payload = json_decode( (string) $job['payload'], true ) ?: [];

		try {
			$class = $this->handlers[ $job['handler'] ] ?? null;
			if ( null === $class ) {
				throw new \RuntimeException( 'No handler registered for ' . $job['handler'] );
			}

			BrandContext::withBrand(
				(int) $job['brand_id'],
				function () use ( $class, $payload ): void {
					$this->container->get( $class )->handle( $payload );
				}
			);

			$wpdb->update( $table, [ 'status' => 'done', 'last_error' => '' ], [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			$attempts = (int) $job['attempts'] + 1;
			$max      = (int) $job['max_attempts'];
			$failed   = $attempts >= $max;
			$delay    = self::BACKOFF[ min( $attempts - 1, count( self::BACKOFF ) - 1 ) ];

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				[
					'status'       => $failed ? 'failed' : 'pending',
					'available_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
					'last_error'   => mb_substr( $e->getMessage(), 0, 500 ),
				],
				[ 'id' => $id ],
				[ '%s', '%s', '%s' ],
				[ '%d' ]
			);

			$this->logger->error( 'queue', 'Job failed', [ 'handler' => $job['handler'], 'attempt' => $attempts, 'error' => $e->getMessage() ] );
		}
	}

	/** Return jobs abandoned by a crashed worker to the pending pool. */
	private function reapStuckJobs(): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'UPDATE `' . $wpdb->prefix . 'vmsos_jobs` SET status = %s WHERE status = %s AND reserved_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)',
				'pending',
				'running'
			)
		);
	}

	/** @return array<string, int> */
	public function stats(): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			'SELECT status, COUNT(*) AS total FROM `' . $wpdb->prefix . 'vmsos_jobs` GROUP BY status',
			ARRAY_A
		);

		$out = [ 'pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0 ];
		foreach ( $rows ?: [] as $row ) {
			$out[ $row['status'] ] = (int) $row['total'];
		}
		return $out;
	}
}
