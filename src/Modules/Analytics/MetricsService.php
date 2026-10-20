<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Analytics;

use VMStudio\SalesOS\Support\BrandContext;
use VMStudio\SalesOS\Support\IntegrationLog;
use VMStudio\SalesOS\Support\SendGovernor;
use wpdb;

/**
 * Read-only reporting across the whole plugin.
 *
 * Every series is returned already gap-filled by day, because a chart with
 * missing days lies about trend.
 */
final class MetricsService {

	private wpdb $db;

	public function __construct( private IntegrationLog $integrations ) {
		global $wpdb;
		$this->db = $wpdb;
	}

	private function brand(): int {
		return BrandContext::current();
	}

	private function table( string $name ): string {
		return $this->db->prefix . 'vmsos_' . $name;
	}

	/** @return array<string, mixed> */
	public function overview( int $days = 30 ): array {
		$days = max( 1, min( 180, $days ) );

		return [
			'days'          => $days,
			'conversations' => $this->series( 'conversations', $days ),
			'leads'         => $this->series( 'contacts', $days ),
			'spend'         => $this->spendSeries( $days ),
			'providers'     => $this->byProvider( $days ),
			'funnel'        => $this->funnel(),
			'email'         => $this->emailTotals( $days ),
			'totals'        => $this->totals( $days ),
			'integrations'  => $this->integrations->health( 24 ),
			'failures'      => $this->integrations->recentFailures( 10 ),
			'budget'        => [
				'email'    => self::budget( 'email' ),
				'whatsapp' => self::budget( 'whatsapp' ),
			],
		];
	}

	/**
	 * Rows per day for any table with created_at.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function series( string $table, int $days ): array {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT DATE(created_at) AS day, COUNT(*) AS total
				 FROM `' . $this->table( $table ) . '`
				 WHERE brand_id = %d AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 GROUP BY DATE(created_at) ORDER BY day ASC',
				$this->brand(),
				$days
			),
			ARRAY_A
		) ?: [];

		return $this->fill( $rows, $days );
	}

	/** @return array<int, array<string, mixed>> */
	private function spendSeries( int $days ): array {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT DATE(created_at) AS day, ROUND(SUM(cost_usd), 4) AS total
				 FROM `' . $this->table( 'ai_usage' ) . '`
				 WHERE brand_id = %d AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 GROUP BY DATE(created_at) ORDER BY day ASC',
				$this->brand(),
				$days
			),
			ARRAY_A
		) ?: [];

		return $this->fill( $rows, $days );
	}

	/** @return array<int, array<string, mixed>> */
	private function byProvider( int $days ): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT provider,
					COUNT(*) AS calls,
					SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failures,
					ROUND(AVG(latency_ms)) AS avg_latency,
					ROUND(SUM(cost_usd), 4) AS cost
				 FROM `' . $this->table( 'ai_usage' ) . '`
				 WHERE brand_id = %d AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 GROUP BY provider ORDER BY calls DESC',
				$this->brand(),
				$days
			),
			ARRAY_A
		) ?: [];
	}

	/** @return array<int, array<string, mixed>> */
	private function funnel(): array {
		return $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT stage, COUNT(*) AS total FROM `' . $this->table( 'contacts' ) . '`
				 WHERE brand_id = %d GROUP BY stage ORDER BY total DESC',
				$this->brand()
			),
			ARRAY_A
		) ?: [];
	}

	/** @return array<string, int> */
	private function emailTotals( int $days ): array {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT type, COUNT(*) AS total FROM `' . $this->table( 'email_events' ) . '`
				 WHERE brand_id = %d AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 GROUP BY type',
				$this->brand(),
				$days
			),
			ARRAY_A
		) ?: [];

		$totals = [ 'sent' => 0, 'open' => 0, 'click' => 0, 'unsubscribe' => 0, 'failed' => 0 ];

		foreach ( $rows as $row ) {
			$totals[ (string) $row['type'] ] = (int) $row['total'];
		}

		return $totals;
	}

	/** @return array<string, mixed> */
	private function totals( int $days ): array {
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB
			$this->db->prepare(
				'SELECT
					(SELECT COUNT(*) FROM `' . $this->table( 'conversations' ) . '` WHERE brand_id = %d AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)) AS conversations,
					(SELECT COUNT(*) FROM `' . $this->table( 'contacts' ) . '` WHERE brand_id = %d AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)) AS leads,
					(SELECT COUNT(*) FROM `' . $this->table( 'contacts' ) . '` WHERE brand_id = %d AND stage = %s) AS hot,
					(SELECT ROUND(SUM(cost_usd), 4) FROM `' . $this->table( 'ai_usage' ) . '` WHERE brand_id = %d AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)) AS spend',
				$this->brand(),
				$days,
				$this->brand(),
				$days,
				$this->brand(),
				'hot',
				$this->brand(),
				$days
			),
			ARRAY_A
		) ?: [];

		$conversations = (int) ( $row['conversations'] ?? 0 );
		$leads         = (int) ( $row['leads'] ?? 0 );

		return [
			'conversations' => $conversations,
			'leads'         => $leads,
			'hot'           => (int) ( $row['hot'] ?? 0 ),
			'spend'         => (float) ( $row['spend'] ?? 0 ),
			// Capture rate only means something once there is traffic to divide by.
			'capture_rate'  => $conversations > 0 ? round( ( $leads / $conversations ) * 100, 1 ) : null,
			'cost_per_lead' => $leads > 0 ? round( ( (float) ( $row['spend'] ?? 0 ) ) / $leads, 4 ) : null,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Sparse daily rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function fill( array $rows, int $days ): array {
		$byDay = [];

		foreach ( $rows as $row ) {
			$byDay[ (string) $row['day'] ] = (float) $row['total'];
		}

		$series = [];

		for ( $offset = $days - 1; $offset >= 0; $offset-- ) {
			$day      = gmdate( 'Y-m-d', time() - ( $offset * DAY_IN_SECONDS ) );
			$series[] = [ 'day' => $day, 'total' => $byDay[ $day ] ?? 0 ];
		}

		return $series;
	}

	/**
	 * Today's remaining marketing send budget for a channel.
	 *
	 * @return array<string, mixed>
	 */
	private static function budget( string $channel ): array {
		$cap = SendGovernor::cap( $channel );

		return [
			'cap'       => $cap,
			'sent'      => SendGovernor::sentToday( $channel ),
			'remaining' => $cap <= 0 ? null : SendGovernor::remainingToday( $channel ),
		];
	}
}
