<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\AI;

use VMStudio\SalesOS\AI\Contracts\ChatProvider;
use VMStudio\SalesOS\AI\Contracts\EmbeddingProvider;
use VMStudio\SalesOS\AI\DTO\ChatRequest;
use VMStudio\SalesOS\AI\DTO\ChatResponse;
use VMStudio\SalesOS\AI\Providers\AiPufferProvider;
use VMStudio\SalesOS\AI\Providers\AnthropicProvider;
use VMStudio\SalesOS\AI\Providers\GeminiProvider;
use VMStudio\SalesOS\AI\Providers\OpenAiCompatibleProvider;
use VMStudio\SalesOS\Core\EventBus;
use VMStudio\SalesOS\Support\Logger;
use VMStudio\SalesOS\Support\Vault;

/**
 * Routes every AI call through an ordered provider chain with automatic
 * failover and a circuit breaker, so one dead vendor never takes the site down.
 */
final class ProviderManager {

	/** @var array<string, ChatProvider> */
	private array $providers = [];

	private const BREAKER_THRESHOLD = 3;
	private const BREAKER_COOLDOWN  = 300;

	public function __construct(
		private Vault $vault,
		private UsageMeter $meter,
		private Logger $logger,
		private EventBus $events
	) {
		$this->registerDefaults();
	}

	private function registerDefaults(): void {
		foreach (
			[
				new AiPufferProvider( $this->vault ),
				new OpenAiCompatibleProvider( $this->vault ),
				new AnthropicProvider( $this->vault ),
				new GeminiProvider( $this->vault ),
			] as $provider
		) {
			$this->register( $provider );
		}

		do_action( 'vmsos_register_ai_providers', $this );
	}

	public function register( ChatProvider $provider ): void {
		$this->providers[ $provider->id() ] = $provider;
	}

	/** @return array<string, ChatProvider> */
	public function all(): array {
		return $this->providers;
	}

	public function get( string $id ): ?ChatProvider {
		return $this->providers[ $id ] ?? null;
	}

	/** @return array<int, string> */
	public function chain(): array {
		$chain = get_option( 'vmsos_ai_chain', [ 'aipuffer', 'openai', 'anthropic', 'gemini' ] );
		return array_values( array_filter( (array) apply_filters( 'vmsos_ai_chain', $chain ) ) );
	}

	/**
	 * Run a chat completion against the first healthy provider that can serve it.
	 *
	 * @throws AiException When every candidate fails.
	 */
	public function chat( ChatRequest $request ): ChatResponse {
		$attempts   = [];
		$candidates = $this->candidates( $request );

		if ( ! $candidates ) {
			throw new AiException( 'No AI provider is configured. Add a key under VM Sales OS → AI.' );
		}

		foreach ( $candidates as $id ) {
			$provider = $this->providers[ $id ];

			if ( $this->breakerOpen( $id ) ) {
				$attempts[ $id ] = 'circuit open';
				continue;
			}

			try {
				$response = $provider->chat( $this->prepare( $request, $provider ) );
				$this->meter->record( $response, $request->operation );
				$this->resetBreaker( $id );
				$this->events->dispatch( 'ai.completed', [ 'provider' => $id, 'operation' => $request->operation ] );
				return $response;
			} catch ( \Throwable $e ) {
				$attempts[ $id ] = $e->getMessage();
				$this->tripBreaker( $id );
				$this->meter->recordFailure( $id, $request->operation, substr( $e->getMessage(), 0, 60 ) );
				$this->logger->error( 'ai', 'Provider failed, trying next', [ 'provider' => $id, 'error' => $e->getMessage() ] );
				$this->events->dispatch( 'ai.provider_failed', [ 'provider' => $id, 'error' => $e->getMessage() ] );
			}
		}

		throw ( new AiException( 'All AI providers failed.' ) )->withAttempts( $attempts );
	}

	/**
	 * Providers eligible for this request, in priority order.
	 *
	 * @return array<int, string>
	 */
	private function candidates( ChatRequest $request ): array {
		$forced = (string) ( $request->meta['provider'] ?? '' );
		$chain  = '' !== $forced ? [ $forced ] : $this->chain();

		$eligible = [];
		foreach ( $chain as $id ) {
			$provider = $this->providers[ $id ] ?? null;
			if ( ! $provider instanceof ChatProvider || ! $provider->isConfigured() ) {
				continue;
			}
			if ( $request->tools && ! $provider->supportsTools() ) {
				continue; // Tool-using turns need a tool-capable model.
			}
			$eligible[] = $id;
		}

		return $eligible;
	}

	/** Apply per-provider model overrides before dispatch. */
	private function prepare( ChatRequest $request, ChatProvider $provider ): ChatRequest {
		if ( '' !== $request->model ) {
			return $request; // Caller pinned a model.
		}

		$overrides = (array) get_option( 'vmsos_ai_model_map', [] );
		$model     = (string) ( $overrides[ $provider->id() . ':' . $request->operation ] ?? $overrides[ $provider->id() ] ?? '' );

		return '' === $model ? $request : $request->withModel( $model );
	}

	/**
	 * @param array<int, string> $texts Texts to embed.
	 * @return array<int, array<int, float>>
	 */
	public function embed( array $texts ): array {
		foreach ( $this->chain() as $id ) {
			$provider = $this->providers[ $id ] ?? null;
			if ( $provider instanceof EmbeddingProvider && $provider->isConfigured() ) {
				try {
					return $provider->embed( $texts );
				} catch ( \Throwable $e ) {
					$this->logger->error( 'ai', 'Embedding failed', [ 'provider' => $id, 'error' => $e->getMessage() ] );
				}
			}
		}
		return [];
	}

	public function hasEmbeddings(): bool {
		foreach ( $this->chain() as $id ) {
			$provider = $this->providers[ $id ] ?? null;
			if ( $provider instanceof EmbeddingProvider && $provider->isConfigured() ) {
				return true;
			}
		}
		return false;
	}

	private function breakerKey( string $id ): string {
		return 'vmsos_breaker_' . $id;
	}

	private function breakerOpen( string $id ): bool {
		return (int) get_transient( $this->breakerKey( $id ) ) >= self::BREAKER_THRESHOLD;
	}

	private function tripBreaker( string $id ): void {
		$count = (int) get_transient( $this->breakerKey( $id ) ) + 1;
		set_transient( $this->breakerKey( $id ), $count, self::BREAKER_COOLDOWN );
	}

	private function resetBreaker( string $id ): void {
		delete_transient( $this->breakerKey( $id ) );
	}

	/** Health snapshot for the dashboard. */
	public function status(): array {
		$out = [];
		foreach ( $this->providers as $id => $provider ) {
			$out[] = [
				'id'         => $id,
				'label'      => $provider->label(),
				'configured' => $provider->isConfigured(),
				'tools'      => $provider->supportsTools(),
				'healthy'    => ! $this->breakerOpen( $id ),
				'in_chain'   => in_array( $id, $this->chain(), true ),
			];
		}
		return $out;
	}
}
