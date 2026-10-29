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
