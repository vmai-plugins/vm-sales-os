<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\AI\Providers;

use VMStudio\SalesOS\AI\DTO\ChatRequest;
use VMStudio\SalesOS\AI\DTO\ChatResponse;

final class AnthropicProvider extends AbstractProvider {

	public function id(): string {
		return 'anthropic';
	}

	public function label(): string {
		return 'Anthropic Claude';
	}

	public function isConfigured(): bool {
		return '' !== $this->apiKey();
	}

	/**
	 * Live-sync models from Anthropic API.
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
		if ( '' === $key ) {
			throw new \VMStudio\SalesOS\AI\AiException( 'Anthropic API key is required to sync models.' );
		}

		$base = untrailingslashit( ( null !== $baseOverride && '' !== $baseOverride ) ? $baseOverride : $this->setting( 'base_url', 'https://api.anthropic.com/v1' ) );
		$url = $base . '/models';

		$response = wp_remote_get(
			$url,
			[
				'headers'   => [
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
				],
				'timeout'   => 15,
				'sslverify' => false,
			]
		);

		$models = [];
		if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
				foreach ( $body['data'] as $item ) {
					if ( ! empty( $item['id'] ) && is_string( $item['id'] ) ) {
						$models[] = $item['id'];
					}
				}
			}
		}

		// Curated fallback if models endpoint is unavailable on legacy accounts
		if ( empty( $models ) ) {
			$models = [
				'claude-3-7-sonnet-20250219',
				'claude-3-5-sonnet-20241022',
				'claude-3-5-haiku-20241022',
				'claude-3-opus-20240229',
			];
		}

		sort( $models, SORT_NATURAL | SORT_FLAG_CASE );

		set_transient( $cacheKey, $models, 12 * HOUR_IN_SECONDS );
		update_option( 'vmsos_ai_' . $this->id() . '_models_cache', $models, false );

		return $models;
	}

	public function chat( ChatRequest $request ): ChatResponse {
		$start = $this->now();

		$body = [
			'model'      => $request->model ?: $this->setting( 'model', 'claude-sonnet-4-6' ),
			'max_tokens' => $request->maxTokens,
			'messages'   => $this->normaliseMessages( $request ),
		];

		$system = $request->systemPrompt();
		if ( '' !== $system ) {
			$body['system'] = $system;
		}

		if ( $request->tools ) {
			$body['tools'] = array_map(
				static fn( array $tool ) => [
					'name'         => $tool['name'] ?? '',
					'description'  => $tool['description'] ?? '',
					'input_schema' => $tool['parameters'] ?? [ 'type' => 'object', 'properties' => (object) [] ],
				],
				$request->tools
			);
		}

		$data = $this->post(
			untrailingslashit( $this->setting( 'base_url', 'https://api.anthropic.com/v1' ) ) . '/messages',
			[
				'x-api-key'         => $this->apiKey(),
				'anthropic-version' => '2023-06-01',
			],
			$body
		);

		$text  = '';
		$calls = [];
		foreach ( $data['content'] ?? [] as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= $block['text'] ?? '';
			} elseif ( 'tool_use' === ( $block['type'] ?? '' ) ) {
				$calls[] = [
					'id'        => (string) ( $block['id'] ?? '' ),
					'name'      => (string) ( $block['name'] ?? '' ),
					'arguments' => (array) ( $block['input'] ?? [] ),
				];
			}
		}

		return new ChatResponse(
			$text,
			$this->id(),
			(string) ( $data['model'] ?? $body['model'] ),
			$calls,
			(int) ( $data['usage']['input_tokens'] ?? 0 ),
			(int) ( $data['usage']['output_tokens'] ?? 0 ),
			$this->elapsed( $start ),
			$data
		);
	}

	/** @return array<int, array<string, mixed>> */
	private function normaliseMessages( ChatRequest $request ): array {
		$out = [];
		foreach ( $request->messages as $message ) {
			if ( 'system' === $message->role ) {
				continue;
			}
			$native = $message->nativePayload();
			if ( null !== $native ) {
				$out[] = $native;
				continue;
			}
			if ( 'tool' === $message->role ) {
				$out[] = [
					'role'    => 'user',
					'content' => [
						[
							'type'        => 'tool_result',
							'tool_use_id' => $message->toolCallId,
							'content'     => $message->content,
						],
					],
				];
				continue;
			}
			$out[] = [ 'role' => $message->role, 'content' => $message->content ];
		}
		return $out;
	}
}
