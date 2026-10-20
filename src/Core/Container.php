<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Minimal PSR-11 style service container with constructor autowiring.
 */
final class Container {

	/** @var array<string, Closure> */
	private array $bindings = [];

	/** @var array<string, mixed> */
	private array $instances = [];

	/** @var array<string, bool> */
	private array $shared = [];

	/** @var array<string, bool> */
	private array $resolving = [];

	public function bind( string $id, Closure $factory, bool $shared = false ): void {
		$this->bindings[ $id ] = $factory;
		$this->shared[ $id ]   = $shared;
		unset( $this->instances[ $id ] );
	}

	public function singleton( string $id, Closure $factory ): void {
		$this->bind( $id, $factory, true );
	}

	public function instance( string $id, mixed $value ): void {
		$this->instances[ $id ] = $value;
		$this->shared[ $id ]    = true;
	}

	public function has( string $id ): bool {
		return isset( $this->bindings[ $id ] ) || isset( $this->instances[ $id ] ) || class_exists( $id );
	}

	/**
	 * Resolve a service.
	 *
	 * @template T
	 * @param class-string<T>|string $id Service identifier.
	 * @return T|mixed
	 */
	public function get( string $id ): mixed {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( isset( $this->resolving[ $id ] ) ) {
			throw new RuntimeException( 'Circular dependency while resolving ' . $id );
		}
		$this->resolving[ $id ] = true;

		try {
			$object = isset( $this->bindings[ $id ] )
				? ( $this->bindings[ $id ] )( $this )
				: $this->build( $id );
		} finally {
			unset( $this->resolving[ $id ] );
		}

		if ( ! empty( $this->shared[ $id ] ) || ! isset( $this->bindings[ $id ] ) ) {
			$this->instances[ $id ] = $object;
		}

		return $object;
	}

	private function build( string $class ): object {
		if ( ! class_exists( $class ) ) {
			throw new RuntimeException( 'Service not found: ' . $class );
		}

		$reflector = new ReflectionClass( $class );
		if ( ! $reflector->isInstantiable() ) {
			throw new RuntimeException( 'Cannot instantiate ' . $class );
		}

		$constructor = $reflector->getConstructor();
		if ( null === $constructor ) {
			return new $class();
		}

		$args = [];
		foreach ( $constructor->getParameters() as $param ) {
			$type = $param->getType();
			if ( $type instanceof ReflectionNamedType && ! $type->isBuiltin() ) {
				$args[] = $this->get( $type->getName() );
				continue;
			}
			if ( $param->isDefaultValueAvailable() ) {
				$args[] = $param->getDefaultValue();
				continue;
			}
			throw new RuntimeException( 'Unresolvable parameter $' . $param->getName() . ' in ' . $class );
		}

		return $reflector->newInstanceArgs( $args );
	}
}
