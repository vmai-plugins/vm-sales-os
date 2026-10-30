<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\REST;

use VMStudio\SalesOS\Core\Container;

/**
 * Collects controllers from core and from modules, then registers them once.
 */
final class RestRouter {

	/** @var array<int, class-string<AbstractController>> */
	private array $controllers = [ SystemController::class ];

	public function __construct( private Container $container ) {}

	/** @param class-string<AbstractController> $class Controller class. */
	public function add( string $class ): void {
		$this->controllers[] = $class;
	}

	public function boot(): void {
		add_filter(
			'rest_allowed_cors_headers',
			static function ( array $headers ): array {
				$headers[] = 'X-VMAI-API-Key';
				$headers[] = 'x-vmai-api-key';
				$headers[] = 'X-Mobile-Token';
				$headers[] = 'x-mobile-token';
				return array_unique( $headers );
			}
		);

		add_action(
			'rest_api_init',
			function (): void {
				foreach ( array_unique( apply_filters( 'vmsos_rest_controllers', $this->controllers ) ) as $class ) {
					$this->container->get( $class )->register();
				}
			}
		);
	}
}
