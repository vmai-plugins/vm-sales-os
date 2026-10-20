<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Core;

/**
 * Contract every feature module implements. Modules are independently
 * toggleable and must not assume any other module is loaded.
 */
interface ModuleInterface {

	/** Machine slug, e.g. "crm". */
	public static function slug(): string;

	/** Human label shown in the modules screen. */
	public static function label(): string;

	/** Slugs of modules that must be active for this one to load. */
	public static function requires(): array;

	/** Register bindings in the container. Runs before boot(). */
	public function register( Container $container ): void;

	/** Attach hooks, routes and listeners. */
	public function boot( Container $container ): void;
}
