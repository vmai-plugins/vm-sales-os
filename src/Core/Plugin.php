<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Core;

use VMStudio\SalesOS\Admin\AdminArea;
use VMStudio\SalesOS\Database\Migrator;
use VMStudio\SalesOS\Modules\Agents\AgentsModule;
use VMStudio\SalesOS\Modules\Analytics\AnalyticsModule;
use VMStudio\SalesOS\Modules\Automation\AutomationModule;
use VMStudio\SalesOS\Modules\Channels\ChannelsModule;
use VMStudio\SalesOS\Modules\Channels\ChatwootModule;
use VMStudio\SalesOS\Modules\Chat\ChatModule;
use VMStudio\SalesOS\Modules\Compat\CompatModule;
use VMStudio\SalesOS\Modules\CRM\CrmModule;
use VMStudio\SalesOS\Modules\Inbox\InboxModule;
use VMStudio\SalesOS\Modules\Knowledge\KnowledgeModule;
use VMStudio\SalesOS\Modules\Memory\MemoryModule;
use VMStudio\SalesOS\Modules\Sequences\SequencesModule;
use VMStudio\SalesOS\Queue\QueueManager;
use VMStudio\SalesOS\REST\RestRouter;
use VMStudio\SalesOS\Support\GitHubUpdater;

/**
 * Composition root. Owns the container and the boot sequence.
 */
final class Plugin {

	private static ?self $instance = null;
	private Container $container;
	private bool $booted = false;

	private function __construct() {
		$this->container = new Container();
	}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function container(): Container {
		return $this->container;
	}

	/** Shorthand resolver. */
	public function make( string $id ): mixed {
		return $this->container->get( $id );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'vm-sales-os', false, dirname( VMSOS_BASENAME ) . '/languages' );

		$this->container->instance( Container::class, $this->container );
		$this->container->singleton( EventBus::class, static fn() => new EventBus() );
		$this->container->singleton( \VMStudio\SalesOS\Support\IntegrationLog::class, static fn() => new \VMStudio\SalesOS\Support\IntegrationLog() );

		$registry = new ModuleRegistry( $this->container, $this->container->get( EventBus::class ) );
		$this->container->instance( ModuleRegistry::class, $registry );

		$registry->discover(
			[
				CrmModule::class,
				ChatModule::class,
				InboxModule::class,
				KnowledgeModule::class,
				MemoryModule::class,
				AgentsModule::class,
				SequencesModule::class,
				ChannelsModule::class,
				ChatwootModule::class,
				AutomationModule::class,
				AnalyticsModule::class,
				// Last: it only re-exposes what the modules above provide.
				CompatModule::class,
			]
		);

		// Infrastructure that is always on.
		$this->container->get( Migrator::class )->maybeMigrate();
		$this->container->get( QueueManager::class )->boot();
		$this->container->get( RestRouter::class )->boot();
		$this->container->get( GitHubUpdater::class )->boot();

		if ( is_admin() ) {
			$this->container->get( AdminArea::class )->boot();
		}

		$registry->bootAll();

		$this->container->get( EventBus::class )->dispatch( 'plugin.booted', [ 'version' => VMSOS_VERSION ] );
	}
}
