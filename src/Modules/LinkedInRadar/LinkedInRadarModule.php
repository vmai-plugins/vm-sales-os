<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\LinkedInRadar;

use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Core\Module;
use VMStudio\SalesOS\REST\RestRouter;
use VMStudio\SalesOS\AI\ProviderManager;
use VMStudio\SalesOS\Modules\CRM\ContactRepository;
use VMStudio\SalesOS\Modules\CRM\DealRepository;
use VMStudio\SalesOS\Modules\CRM\ActivityRepository;
use VMStudio\SalesOS\Support\Logger;

final class LinkedInRadarModule extends Module {

	public static function slug(): string {
		return 'linkedin_radar';
	}

	public static function label(): string {
		return __( 'LinkedIn Intent Lead Radar', 'vm-sales-os' );
	}

	public static function requires(): array {
		return [ 'crm' ];
	}

	public function register( Container $container ): void {
		$container->singleton(
			LinkedInPostParser::class,
			static fn( Container $c ) => new LinkedInPostParser(
				$c->has( ProviderManager::class ) ? $c->get( ProviderManager::class ) : null
			)
		);

		$container->singleton(
			LinkedInRadarRepository::class,
			static fn( Container $c ) => new LinkedInRadarRepository(
				$c->has( ContactRepository::class ) ? $c->get( ContactRepository::class ) : null,
				$c->has( DealRepository::class ) ? $c->get( DealRepository::class ) : null,
				$c->has( ActivityRepository::class ) ? $c->get( ActivityRepository::class ) : null,
				$c->has( Logger::class ) ? $c->get( Logger::class ) : null
			)
		);

		$container->singleton(
			LinkedInRadarController::class,
			static fn( Container $c ) => new LinkedInRadarController(
				$c->get( LinkedInPostParser::class ),
				$c->get( LinkedInRadarRepository::class )
			)
		);
	}

	public function boot( Container $container ): void {
		$container->get( RestRouter::class )->add( LinkedInRadarController::class );
	}
}
