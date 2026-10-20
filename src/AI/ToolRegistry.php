<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\AI;

use VMStudio\SalesOS\Support\Logger;

/**
 * Tools an agent may call. Every tool declares a capability; the registry
 * refuses to expose a tool to a context that has not been granted it. This is
 * the gate that stops a public chat widget from reaching privileged actions.
 */
final class ToolRegistry {

	/** @var array<string, array{schema: array<string, mixed>, handler: callable, capability: string}> */
	private array $tools = [];

	public function __construct( private Logger $logger ) {}

	/**
	 * @param array<string, mixed> $schema     JSON schema: name, description, parameters.
	 * @param callable             $handler    fn(array $args, array $context): array
	 * @param string               $capability Context grant required to see this tool.
	 */
	public function register( array $schema, callable $handler, string $capability = 'public' ): void {
		$name = (string) ( $schema['name'] ?? '' );
		if ( '' === $name ) {
			return;
		}

		$this->tools[ $name ] = [
			'schema'     => $schema,
			'handler'    => $handler,
			'capability' => $capability,
		];
	}

	/**
	 * Schemas visible to a context.
	 *
	 * @param array<int, string> $grants Capabilities the caller holds.
	 * @return array<int, array<string, mixed>>
	 */
	public function schemasFor( array $grants ): array {
		$out = [];
		foreach ( $this->tools as $tool ) {
			if ( in_array( $tool['capability'], $grants, true ) ) {
				$out[] = $tool['schema'];
			}
		}
		return $out;
	}

	/**
	 * Execute a tool call.
	 *
	 * @param array<string, mixed> $arguments Model-supplied arguments.
	 * @param array<int, string>   $grants    Capabilities the caller holds.
	 * @param array<string, mixed> $context   Conversation context.
	 * @return array<string, mixed>
	 */
	public function call( string $name, array $arguments, array $grants, array $context = [] ): array {
		$tool = $this->tools[ $name ] ?? null;

		if ( null === $tool ) {
			return [ 'error' => 'Unknown tool: ' . $name ];
		}

		if ( ! in_array( $tool['capability'], $grants, true ) ) {
			$this->logger->error( 'tools', 'Blocked tool call outside granted capability', [ 'tool' => $name, 'capability' => $tool['capability'] ] );
			return [ 'error' => 'That action is not available here.' ];
		}

		try {
			$result = ( $tool['handler'] )( $arguments, $context );
			return is_array( $result ) ? $result : [ 'result' => $result ];
		} catch ( \Throwable $e ) {
			$this->logger->error( 'tools', 'Tool threw', [ 'tool' => $name, 'error' => $e->getMessage() ] );
			return [ 'error' => 'The action failed. Tell the user you will follow up.' ];
		}
	}

	public function has( string $name ): bool {
		return isset( $this->tools[ $name ] );
	}

	/** @return array<int, string> */
	public function names(): array {
		return array_keys( $this->tools );
	}
}
