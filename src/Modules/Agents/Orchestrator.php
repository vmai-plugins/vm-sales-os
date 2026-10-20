<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Agents;

use VMStudio\SalesOS\AI\DTO\ChatRequest;
use VMStudio\SalesOS\AI\DTO\Message;
use VMStudio\SalesOS\AI\ProviderManager;
use VMStudio\SalesOS\AI\ToolRegistry;
use VMStudio\SalesOS\Core\EventBus;
use VMStudio\SalesOS\Support\Logger;

/**
 * Runs one agent to completion: reason, call tools, observe, repeat, answer.
 * Every step is written to the run log, so an agent's work is auditable
 * instead of being a black box.
 */
final class Orchestrator {

	private const MAX_STEPS = 6;

	public function __construct(
		private AgentRepository $agents,
		private ProviderManager $ai,
		private ToolRegistry $tools,
		private EventBus $events,
		private Logger $logger
	) {}

	/**
	 * @param array<string, mixed> $context Extra context passed to tools.
	 * @return array{ok: bool, output: string, steps: array<int, array<string, mixed>>, run_id: int}
	 */
	public function run( string $agentSlug, string $input, string $trigger = 'manual', array $context = [] ): array {
		$agent = $this->agents->findBySlug( $agentSlug );

		if ( ! $agent ) {
			return [ 'ok' => false, 'output' => 'No agent called ' . $agentSlug, 'steps' => [], 'run_id' => 0 ];
		}

		$runId  = $this->agents->startRun( (int) $agent['id'], $trigger, $input );
		$grants = json_decode( (string) $agent['capabilities'], true ) ?: [ 'internal' ];
		$allow  = json_decode( (string) $agent['tools'], true ) ?: [];

		$schemas = array_values(
			array_filter(
				$this->tools->schemasFor( $grants ),
				static fn( array $schema ) => ! $allow || in_array( $schema['name'] ?? '', $allow, true )
			)
		);

		$messages = [
			Message::system( $this->systemPrompt( $agent ) ),
			Message::user( $input ),
		];

		$steps  = [];
		$output = '';

		try {
			for ( $step = 0; $step < self::MAX_STEPS; $step++ ) {
				$response = $this->ai->chat(
					new ChatRequest(
						$messages,
						temperature: 0.3,
						maxTokens: 1200,
						tools: $schemas,
						operation: 'agent',
						meta: [ 'agent' => $agentSlug ]
					)
				);

				if ( ! $response->hasToolCalls() ) {
					$output  = $response->content;
					$steps[] = [ 'type' => 'answer', 'provider' => $response->provider, 'content' => mb_substr( $output, 0, 500 ) ];
					break;
				}

				$messages[] = $response->assistantEcho();

				foreach ( $response->toolCalls as $call ) {
					$result = $this->tools->call( (string) $call['name'], (array) $call['arguments'], $grants, $context + [ 'agent' => $agentSlug ] );

					$steps[]    = [
						'type'   => 'tool',
						'tool'   => $call['name'],
						'args'   => $call['arguments'],
						'result' => $result,
					];
					$messages[] = Message::tool( (string) $call['id'], (string) $call['name'], (string) wp_json_encode( $result ) );
				}
			}

			if ( '' === $output ) {
				$output = 'The agent ran out of steps before reaching an answer.';
			}

			$this->agents->finishRun( $runId, 'done', $output, $steps );
			$this->events->dispatch( 'agent.completed', [ 'agent' => $agentSlug, 'run_id' => $runId ] );

			return [ 'ok' => true, 'output' => $output, 'steps' => $steps, 'run_id' => $runId ];
		} catch ( \Throwable $e ) {
			$this->logger->error( 'agents', 'Agent run failed', [ 'agent' => $agentSlug, 'error' => $e->getMessage() ] );
			$this->agents->finishRun( $runId, 'failed', $e->getMessage(), $steps );

			return [ 'ok' => false, 'output' => $e->getMessage(), 'steps' => $steps, 'run_id' => $runId ];
		}
	}

	/** @param array<string, mixed> $agent Agent row. */
	private function systemPrompt( array $agent ): string {
		$prompt = (string) $agent['system_prompt'];

		return trim(
			$prompt . "\n\n" . sprintf(
				"Your goal: %s\nBusiness: %s\nToday: %s\n\nWork in small steps. Use a tool when you need real data — never guess at numbers, names or prices. When you have the answer, state it plainly and stop.",
				(string) $agent['goal'],
				get_bloginfo( 'name' ),
				gmdate( 'Y-m-d' )
			)
		);
	}
}
