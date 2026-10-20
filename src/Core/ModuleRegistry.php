<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Core;

/**
 * Discovers, gates and boots feature modules.
 */
final class ModuleRegistry {

	private const OPTION = 'vmsos_modules';

	/** @var array<string, ModuleInterface> */
	private array $modules = [];

	/** @var array<string, class-string<ModuleInterface>> */
	private array $available = [];

	public function __construct( private Container $container, private EventBus $events ) {}

	/**
	 * @param array<int, class-string<ModuleInterface>> $classes Module classes.
	 */
	public function discover( array $classes ): void {
		$classes = apply_filters( 'vmsos_module_classes', $classes );
		foreach ( $classes as $class ) {
			if ( is_subclass_of( $class, ModuleInterface::class ) ) {
				$this->available[ $class::slug() ] = $class;
			}
		}
	}

	/** @return array<string, class-string<ModuleInterface>> */
	public function available(): array {
		return $this->available;
	}

	public function isActive( string $slug ): bool {
		$state = get_option( self::OPTION, [] );
		// Modules default to on; operators opt out explicitly.
		return ! isset( $state[ $slug ] ) || (bool) $state[ $slug ];
	}

	public function setActive( string $slug, bool $active ): void {
		$state          = get_option( self::OPTION, [] );
		$state[ $slug ] = $active;
		update_option( self::OPTION, $state, false );
		$this->events->dispatch( 'module.toggled', [ 'slug' => $slug, 'active' => $active ] );
	}

	public function bootAll(): void {
		foreach ( $this->available as $slug => $class ) {
			if ( ! $this->isActive( $slug ) || ! $this->dependenciesMet( $class ) ) {
				continue;
			}
			$module                 = new $class();
			$this->modules[ $slug ] = $module;
			$module->register( $this->container );
		}

		foreach ( $this->modules as $slug => $module ) {
			$module->boot( $this->container );
			$this->events->dispatch( 'module.booted', [ 'slug' => $slug ] );
		}
	}

	/** @param class-string<ModuleInterface> $class Module class. */
	private function dependenciesMet( string $class ): bool {
		foreach ( $class::requires() as $dependency ) {
			if ( ! isset( $this->available[ $dependency ] ) || ! $this->isActive( $dependency ) ) {
				return false;
			}
		}
		return true;
	}

	public function get( string $slug ): ?ModuleInterface {
		return $this->modules[ $slug ] ?? null;
	}
}
