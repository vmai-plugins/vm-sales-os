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
		$url = untrailingslashit( $this->setting( 'base_url', 'https://api.openai.com/v1' ) );
		if ( '' !== $url && ! str_ends_with( $url, '/v1' ) ) {
			$url .= '/v1';
		}
		return $url;
	}

	public function isConfigured(): bool {
		// Local runtimes (Ollama, vLLM) need no key.
		return '' !== $this->apiKey() || ! str_contains( $this->baseUrl(), 'api.openai.com' );
	}

	public function defaultModel(): string {
		$model = $this->setting( 'model', '' );
		if ( '' !== $model ) {
			return $model;
		}
		return str_contains( $this->baseUrl(), 'ai.vmstudio.digital' ) ? 'groq/qwen/qwen3.8-27b' : 'gpt-4o-mini';
	}

	/**
	 * Live-sync models from /models endpoint.
	 *
	 * @param bool $force Bypass cache.
	 * @param string|null $keyOverride Optional API key.
	 * @param string|null $baseOverride Optional base URL.
	 * @return list<string>
	 */
	public function getModels( bool $force = false, ?string $keyOverride = null, ?string $baseOverride = null ): array {
		$cacheKey = 'vmsos_models_' . $this->id();
		if ( ! $force && null === $keyOverride && null === $baseOverride ) {
			$cached = get_transient( $cacheKey );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$key = null !== $keyOverride && '' !== $keyOverride ? $keyOverride : $this->apiKey();
		$base = null !== $baseOverride && '' !== $baseOverride ? $baseOverride : $this->baseUrl();
		$base = untrailingslashit( $base );

		$url = $base . '/models';
		$headers = [];
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		$response = wp_remote_get(
			$url,
			[
				'headers'   => $headers,
				'timeout'   => 15,
				'sslverify' => false,
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new \VMStudio\SalesOS\AI\AiException( 'Could not reach ' . $url . ': ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = $body['error']['message'] ?? ( 'HTTP ' . $code );
			throw new \VMStudio\SalesOS\AI\AiException( 'Failed fetching models from ' . $url . ': ' . $msg );
		}

		$models = [];
		if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
			foreach ( $body['data'] as $item ) {
				if ( ! empty( $item['id'] ) && is_string( $item['id'] ) ) {
					$models[] = $item['id'];
				}
			}
		}

		sort( $models, SORT_NATURAL | SORT_FLAG_CASE );

		if ( ! empty( $models ) ) {
			set_transient( $cacheKey, $models, 12 * HOUR_IN_SECONDS );
			update_option( 'vmsos_ai_' . $this->id() . '_models_cache', $models, false );
		}

		return $models;
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

	/**
	 * Stream a chat response token-by-token.
	 *
	 * @param ChatRequest $request
	 * @param callable(string): void $onToken Invoked for each incoming text delta.
	 * @return ChatResponse
	 */
	public function streamChat( ChatRequest $request, callable $onToken ): ChatResponse {
		if ( ! empty( $request->tools ) ) {
			return $this->chat( $request );
		}

		$start = $this->now();
		$body = [
			'model'       => $request->model ?: $this->defaultModel(),
			'messages'    => $request->messagesArray(),
			'temperature' => $request->temperature,
			'max_tokens'  => $request->maxTokens,
			'stream'      => true,
		];

		if ( $request->jsonMode ) {
			$body['response_format'] = [ 'type' => 'json_object' ];
		}

		$url = $this->baseUrl() . '/chat/completions';
		$headers = $this->headers();
		$rawHeaders = [ 'Content-Type: application/json' ];
		foreach ( $headers as $k => $v ) {
			$rawHeaders[] = "$k: $v";
		}

		$fullContent = '';
		$modelName   = (string) $body['model'];

		$ch = curl_init( $url );
		if ( false === $ch ) {
			return $this->chat( $request );
		}

		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, wp_json_encode( $body ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $rawHeaders );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );

		$buffer = '';
		curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $curlHandle, $chunk ) use ( &$buffer, &$fullContent, &$modelName, $onToken ) {
			$buffer .= $chunk;
			while ( ( $pos = strpos( $buffer, "\n" ) ) !== false ) {
				$line = trim( substr( $buffer, 0, $pos ) );
				$buffer = substr( $buffer, $pos + 1 );

				if ( str_starts_with( $line, 'data: ' ) ) {
					$payload = trim( substr( $line, 6 ) );
					if ( '[DONE]' === $payload ) {
						continue;
					}
					$decoded = json_decode( $payload, true );
					if ( is_array( $decoded ) ) {
						if ( ! empty( $decoded['model'] ) ) {
							$modelName = (string) $decoded['model'];
						}
						$delta = $decoded['choices'][0]['delta']['content'] ?? '';
						if ( '' !== $delta ) {
							$fullContent .= $delta;
							$onToken( $delta );
						}
					}
				}
			}
			return strlen( $chunk );
		} );

		curl_exec( $ch );
		$err = curl_error( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( '' !== $err || $code >= 400 || '' === $fullContent ) {
			return $this->chat( $request );
		}

		return new ChatResponse(
			$fullContent,
			$this->id(),
			$modelName,
			[],
			0,
			0,
			$this->elapsed( $start )
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
