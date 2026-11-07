<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Workspace;

use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Core\Module;
use VMStudio\SalesOS\REST\RestRouter;

final class WorkspaceModule extends Module {

	public static function slug(): string {
		return 'workspace';
	}

	public static function label(): string {
		return __( 'Sovereign Workspace', 'vm-sales-os' );
	}

	public function register( Container $container ): void {
		$container->singleton( PaperclipService::class, static fn() => new PaperclipService() );
		$container->singleton( ForecastingService::class, static fn() => new ForecastingService() );
		$container->singleton(
			BrainService::class,
			static fn( Container $c ) => new BrainService(
				$c->get( PaperclipService::class ),
				$c->get( ForecastingService::class )
			)
		);
		$container->singleton(
			WorkspaceController::class,
			static fn( Container $c ) => new WorkspaceController(
				$c->get( PaperclipService::class ),
				$c->get( ForecastingService::class ),
				$c->get( BrainService::class )
			)
		);
	}

	public function boot( Container $container ): void {
		$container->get( RestRouter::class )->add( WorkspaceController::class );

		// Shortcode for embedding the dashboard on any page: [vmsos_workspace]
		add_shortcode( 'vmsos_workspace', [ $this, 'renderShortcode' ] );
	}

	public function renderShortcode(): string {
		ob_start();
		$view = __DIR__ . '/views/workspace-dashboard.php';
		if ( file_exists( $view ) ) {
			include $view;
		} else {
			echo '<div style="color:red; padding:20px;">Workspace dashboard view template missing.</div>';
		}
		return (string) ob_get_clean();
	}
}
