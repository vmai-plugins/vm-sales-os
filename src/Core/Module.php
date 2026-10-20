<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Core;

/**
 * Convenience base class for modules.
 */
abstract class Module implements ModuleInterface {

	public static function requires(): array {
		return [];
	}

	public function register( Container $container ): void {}

	public function boot( Container $container ): void {}
}
