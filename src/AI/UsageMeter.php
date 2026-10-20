<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\AI;

use VMStudio\SalesOS\AI\DTO\ChatResponse;
use VMStudio\SalesOS\Support\BrandContext;

/**
 * Records every AI call so spend, latency and failure rates are auditable
 * per brand, per provider, per model.
 */
final class UsageMeter {

	/** USD per 1M tokens: [input, output]. Override with the vmsos_ai_prices filter. */
	private const PRICES = [
		'gpt-4o'                   => [ 2.50, 10.00 ],
		'gpt-4o-mini'              => [ 0.15, 0.60 ],
		'claude-sonnet-4-6'        => [ 3.00, 15.00 ],
		'claude-haiku-4-5'         => [ 0.80, 4.00 ],
		'gemini-2.0-flash'         => [ 0.10, 0.40 ],
		'text-embedding-3-small'   => [ 0.02, 0.00 ],
	];

	public function record( ChatResponse $response, string $operation = 'chat', bool $success = true, string $errorCode = '' ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'vmsos_ai_usage',
			[
				'brand_id'   => BrandContext::current(),
				'provider'   => $response->provider,
				'model'      => $response->model,
				'operation'  => $operation,
				'tokens_in'  => $response->tokensIn,
				'tokens_out' => $response->tokensOut,
				'cost_usd'   => $this->cost( $response->model, $response->tokensIn, $response->tokensOut ),
				'latency_ms' => $response->latencyMs,
				'success'    => $success ? 1 : 0,
				'error_code' => mb_substr( $errorCode, 0, 60 ),
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%d', '%d', '%f', '%d', '%d', '%s', '%s' ]
		);
	}

	public function recordFailure( string $provider, string $operation, string $errorCode ): void {
		$this->record( new ChatResponse( '', $provider, '', [], 0, 0, 0 ), $operation, false, $errorCode );
	}

	public function cost( string $model, int $tokensIn, int $tokensOut ): float {
		$prices = apply_filters( 'vmsos_ai_prices', self::PRICES );
		$rate   = $prices[ $model ] ?? null;

		if ( null === $rate ) {
			foreach ( $prices as $key => $value ) {
				if ( str_starts_with( $model, $key ) ) {
					$rate = $value;
					break;
				}
			}
		}

		if ( null === $rate ) {
			return 0.0;
		}

		return round( ( $tokensIn / 1000000 * $rate[0] ) + ( $tokensOut / 1000000 * $rate[1] ), 6 );
	}

	/** @return array<string, mixed> */
	public function summary( int $days = 7 ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT provider, COUNT(*) AS calls, SUM(tokens_in) AS tin, SUM(tokens_out) AS tout,
				        SUM(cost_usd) AS cost, AVG(latency_ms) AS latency,
				        SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failures
				 FROM `' . $wpdb->prefix . 'vmsos_ai_usage`
				 WHERE brand_id = %d AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 GROUP BY provider',
				BrandContext::current(),
				$days
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ) => [
				'provider'    => $row['provider'],
				'calls'       => (int) $row['calls'],
				'tokens_in'   => (int) $row['tin'],
				'tokens_out'  => (int) $row['tout'],
				'cost_usd'    => round( (float) $row['cost'], 4 ),
				'avg_latency' => (int) $row['latency'],
				'failures'    => (int) $row['failures'],
			],
			$rows ?: []
		);
	}
}
