<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Analytics;

use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Core\Module;
use VMStudio\SalesOS\REST\RestRouter;
use VMStudio\SalesOS\Support\IntegrationLog;

final class AnalyticsModule extends Module {

	public static function slug(): string {
		return 'analytics';
	}

	public static function label(): string {
		return __( 'Reports', 'vm-sales-os' );
	}

	public function register( Container $container ): void {
		$container->singleton(
			MetricsService::class,
			static fn( Container $c ) => new MetricsService( $c->get( IntegrationLog::class ) )
		);
	}

	public function boot( Container $container ): void {
		$container->get( RestRouter::class )->add( AnalyticsController::class );
	}
}
