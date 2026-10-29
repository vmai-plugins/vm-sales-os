<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\AI\Providers;

use VMStudio\SalesOS\AI\Contracts\EmbeddingProvider;
use VMStudio\SalesOS\AI\DTO\ChatRequest;
use VMStudio\SalesOS\AI\DTO\ChatResponse;

/**
 * One driver for every OpenAI-shaped endpoint: OpenAI, Azure gateways,
 * OpenRouter, DeepSeek, Groq, Mistral, Together, LM Studio, vLLM and Ollama's
 * /v1 shim. Point base_url at the host and it works.
 */
class OpenAiCompatibleProvider extends AbstractProvider implements EmbeddingProvider {

	public function id(): string {
		return 'openai';
	}

	public function label(): string {
		return 'OpenAI compatible';
	}

	protected function baseUrl(): string {
		return untrailingslashit( $this->setting( 'base_url', 'https://api.openai.com/v1' ) );
	}

	public function isConfigured(): bool {
		// Local runtimes (Ollama, vLLM) need no key.
		return '' !== $this->apiKey() || ! str_contains( $this->baseUrl(), 'api.openai.com' );
	}

	public function defaultModel(): string {
		return $this->setting( 'model', 'gpt-4o-mini' );
	}

	public function chat( ChatRequest $request ): ChatResponse {
		$start = $this->now();

		$body = [
			'model'       => $request->model ?: $this->defaultModel(),
			'messages'    => $request->messagesArray(),
			'temperature' => $request->temperature,
			'max_tokens'  => $request->maxTokens,
		];

		if ( $request->tools ) {
			$body['tools']       = array_map(
				static fn( array $tool ) => [ 'type' => 'function', 'function' => $tool ],
				$request->tools
			);
			$body['tool_choice'] = 'auto';
		}

		if ( $request->jsonMode ) {
			$body['response_format'] = [ 'type' => 'json_object' ];
		}

		$data    = $this->post( $this->baseUrl() . '/chat/completions', $this->headers(), $body );
		$choice  = $data['choices'][0]['message'] ?? [];
		$usage   = $data['usage'] ?? [];
		$calls   = [];

		foreach ( $choice['tool_calls'] ?? [] as $call ) {
			$calls[] = [
				'id'        => (string) ( $call['id'] ?? '' ),
				'name'      => (string) ( $call['function']['name'] ?? '' ),
				'arguments' => json_decode( (string) ( $call['function']['arguments'] ?? '{}' ), true ) ?: [],
			];
		}

		return new ChatResponse(
			(string) ( $choice['content'] ?? '' ),
			$this->id(),
			(string) ( $data['model'] ?? $body['model'] ),
			$calls,
			(int) ( $usage['prompt_tokens'] ?? 0 ),
			(int) ( $usage['completion_tokens'] ?? 0 ),
			$this->elapsed( $start ),
			$data
		);
	}

	/** @return array<int, array<int, float>> */
	public function embed( array $texts ): array {
		$data = $this->post(
			$this->baseUrl() . '/embeddings',
			$this->headers(),
			[
				'model' => $this->setting( 'embed_model', 'text-embedding-3-small' ),
				'input' => array_values( $texts ),
			]
		);

		$vectors = [];
		foreach ( $data['data'] ?? [] as $item ) {
			$vectors[] = array_map( 'floatval', $item['embedding'] ?? [] );
		}
		return $vectors;
	}

	/** @return array<string, string> */
	protected function headers(): array {
		$key     = $this->apiKey();
		$headers = [];
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}
		if ( str_contains( $this->baseUrl(), 'openrouter' ) ) {
			$headers['HTTP-Referer'] = home_url();
			$headers['X-Title']      = get_bloginfo( 'name' );
		}
		return $headers;
	}
}
