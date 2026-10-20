<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\AI\Providers;

use VMStudio\SalesOS\AI\DTO\ChatRequest;
use VMStudio\SalesOS\AI\DTO\ChatResponse;

final class GeminiProvider extends AbstractProvider {

	public function id(): string {
		return 'gemini';
	}

	public function label(): string {
		return 'Google Gemini';
	}

	public function isConfigured(): bool {
		return '' !== $this->apiKey();
	}

	/**
	 * Live-sync models from Google Gemini API.
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
			throw new \VMStudio\SalesOS\AI\AiException( 'Gemini API key is required to sync models.' );
		}

		$base = untrailingslashit( ( null !== $baseOverride && '' !== $baseOverride ) ? $baseOverride : $this->setting( 'base_url', 'https://generativelanguage.googleapis.com/v1beta' ) );
		$url = $base . '/models?key=' . rawurlencode( $key );

		$response = wp_remote_get(
			$url,
			[
				'timeout'   => 15,
				'sslverify' => false,
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new \VMStudio\SalesOS\AI\AiException( 'Gemini connection error: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = $body['error']['message'] ?? ( 'HTTP ' . $code );
			throw new \VMStudio\SalesOS\AI\AiException( 'Gemini API error: ' . $msg );
		}

		$models = [];
		if ( isset( $body['models'] ) && is_array( $body['models'] ) ) {
			foreach ( $body['models'] as $item ) {
				$name = $item['name'] ?? '';
				if ( '' !== $name ) {
					$name = preg_replace( '#^models/#', '', $name );
					if ( isset( $item['supportedGenerationMethods'] ) && is_array( $item['supportedGenerationMethods'] ) ) {
						if ( ! in_array( 'generateContent', $item['supportedGenerationMethods'], true ) ) {
							continue;
						}
					}
					$models[] = $name;
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
		$model = $request->model ?: $this->setting( 'model', 'gemini-2.0-flash' );

		$contents = [];
		foreach ( $request->messages as $message ) {
			if ( 'system' === $message->role ) {
				continue;
			}
			$contents[] = [
				'role'  => 'assistant' === $message->role ? 'model' : 'user',
				'parts' => [ [ 'text' => $message->content ] ],
			];
		}

		$body = [
			'contents'         => $contents,
			'generationConfig' => [
				'temperature'     => $request->temperature,
				'maxOutputTokens' => $request->maxTokens,
			],
		];

		$system = $request->systemPrompt();
		if ( '' !== $system ) {
			$body['systemInstruction'] = [ 'parts' => [ [ 'text' => $system ] ] ];
		}
		if ( $request->jsonMode ) {
			$body['generationConfig']['responseMimeType'] = 'application/json';
		}

		$url = sprintf(
			'%s/models/%s:generateContent?key=%s',
			untrailingslashit( $this->setting( 'base_url', 'https://generativelanguage.googleapis.com/v1beta' ) ),
			rawurlencode( $model ),
			rawurlencode( $this->apiKey() )
		);

		$data = $this->post( $url, [], $body );
		$text = '';
		foreach ( $data['candidates'][0]['content']['parts'] ?? [] as $part ) {
			$text .= $part['text'] ?? '';
		}

		return new ChatResponse(
			$text,
			$this->id(),
			$model,
			[],
			(int) ( $data['usageMetadata']['promptTokenCount'] ?? 0 ),
			(int) ( $data['usageMetadata']['candidatesTokenCount'] ?? 0 ),
			$this->elapsed( $start ),
			$data
		);
	}

	public function supportsTools(): bool {
		return false; // Handled by the manager's prompt-based fallback.
	}
}
