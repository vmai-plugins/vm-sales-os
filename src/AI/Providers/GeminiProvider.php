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
