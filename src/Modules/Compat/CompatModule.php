<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Compat;

use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Core\Module;
use VMStudio\SalesOS\Modules\Analytics\MetricsService;
use VMStudio\SalesOS\Modules\Chat\ConversationRepository;
use VMStudio\SalesOS\Modules\CRM\ActivityRepository;
use VMStudio\SalesOS\Modules\CRM\ContactRepository;
use VMStudio\SalesOS\Modules\Inbox\InboxService;
use VMStudio\SalesOS\Modules\Sequences\EnrollmentRepository;
use VMStudio\SalesOS\REST\RestRouter;
use VMStudio\SalesOS\Support\Vault;

/**
 * Backwards compatibility for the Superfone mobile app.
 *
 * The app talks to the legacy `vmai/v1` namespace with a shared API key. This
 * module serves that surface from vm-sales-os services so the app keeps working
 * without shipping a new build. It registers no admin UI and touches nothing in
 * the native `vmsos/v1` surface.
 *
 * Deactivate it (Modules screen) once the app has moved to `vmsos/v1`.
 */
final class CompatModule extends Module {

	public static function slug(): string {
		return 'compat';
	}

	public static function label(): string {
		return __( 'Mobile app compatibility', 'vm-sales-os' );
	}

	/**
	 * Every service the bridge reaches for. Without these the routes would
	 * register and then fatal on first call, so the registry skips the whole
	 * module instead if any is missing.
	 *
	 * @return array<int, string>
	 */
	public static function requires(): array {
		return [ 'crm', 'chat', 'inbox', 'sequences', 'analytics' ];
	}

	public function register( Container $container ): void {
		$container->singleton(
			ApiKeyGuard::class,
			static fn( Container $c ) => new ApiKeyGuard( $c->get( Vault::class ) )
		);

		$container->singleton(
			MobileBridgeController::class,
			static fn( Container $c ) => new MobileBridgeController(
				$c->get( ApiKeyGuard::class ),
				$c->get( ContactRepository::class ),
				$c->get( ConversationRepository::class ),
				$c->get( InboxService::class ),
				$c->get( MetricsService::class ),
				$c->get( ActivityRepository::class ),
				$c->get( EnrollmentRepository::class )
			)
		);
	}

	public function boot( Container $container ): void {
		$container->get( RestRouter::class )->add( MobileBridgeController::class );
	}
}
