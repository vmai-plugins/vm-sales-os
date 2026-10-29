<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Automation;

use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Core\EventBus;
use VMStudio\SalesOS\Core\Module;
use VMStudio\SalesOS\Queue\QueueManager;

/**
 * Event-driven automation. Rules are declarative: when an event fires and the
 * conditions hold, queue an action. The visual builder writes these rules; the
 * runtime here is what executes them.
 */
final class AutomationModule extends Module {

	public const OPTION = 'vmsos_automation_rules';

	public static function slug(): string {
		return 'automation';
	}

	public static function label(): string {
		return __( 'Automation', 'vm-sales-os' );
	}

	public static function requires(): array {
		return [ 'crm' ];
	}

	public function boot( Container $container ): void {
		$events = $container->get( EventBus::class );
		$queue  = $container->get( QueueManager::class );

		foreach ( $this->rules() as $rule ) {
			$events->listen(
				(string) $rule['on'],
				static function ( array $payload ) use ( $rule, $queue ): void {
					if ( ! self::matches( $rule['conditions'] ?? [], $payload ) ) {
						return;
					}

					$queue->push(
						(string) $rule['action'],
						array_merge( (array) ( $rule['args'] ?? [] ), $payload ),
						(int) ( $rule['delay'] ?? 0 )
					);
				}
			);
		}
	}

	/** @return array<int, array<string, mixed>> */
	private function rules(): array {
		return (array) apply_filters( 'vmsos_automation_rules', get_option( self::OPTION, [] ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $conditions Rule conditions.
	 * @param array<string, mixed>             $payload    Event payload.
	 */
	public static function matches( array $conditions, array $payload ): bool {
		foreach ( $conditions as $condition ) {
			$field    = (string) ( $condition['field'] ?? '' );
			$operator = (string) ( $condition['op'] ?? '=' );
			$expected = $condition['value'] ?? null;
			$actual   = $payload[ $field ] ?? null;

			$passes = match ( $operator ) {
				'='         => $actual == $expected, // phpcs:ignore WordPress.PHP.StrictComparisons
				'!='        => $actual != $expected, // phpcs:ignore WordPress.PHP.StrictComparisons
				'>'         => (float) $actual > (float) $expected,
				'<'         => (float) $actual < (float) $expected,
				'contains'  => is_string( $actual ) && str_contains( $actual, (string) $expected ),
				'not_empty' => ! empty( $actual ),
				default     => false,
			};

			if ( ! $passes ) {
				return false;
			}
		}

		return true;
	}
}
