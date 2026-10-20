<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\AI\DTO;

final class ChatResponse {

	/**
	 * @param array<int, array<string, mixed>> $toolCalls Normalised tool calls.
	 * @param array<string, mixed>             $raw       Provider payload.
	 */
	public function __construct(
		public readonly string $content,
		public readonly string $provider,
		public readonly string $model,
		public readonly array $toolCalls = [],
		public readonly int $tokensIn = 0,
		public readonly int $tokensOut = 0,
		public readonly int $latencyMs = 0,
		public readonly array $raw = []
	) {}

	/**
	 * The assistant turn exactly as the provider produced it, ready to append
	 * to the message list before tool results.
	 */
	public function assistantEcho(): Message {
		if ( isset( $this->raw['choices'][0]['message'] ) && is_array( $this->raw['choices'][0]['message'] ) ) {
			return Message::native( 'assistant', $this->raw['choices'][0]['message'] );
		}

		if ( isset( $this->raw['content'] ) && is_array( $this->raw['content'] ) ) {
			return Message::native( 'assistant', [ 'role' => 'assistant', 'content' => $this->raw['content'] ] );
		}

		return Message::assistant( $this->content );
	}

	public function hasToolCalls(): bool {
		return ! empty( $this->toolCalls );
	}

	/** Decode a JSON-mode reply, tolerating fenced output. */
	public function json(): ?array {
		$text = trim( preg_replace( '/^```(?:json)?|```$/m', '', $this->content ) ?? '' );
		$data = json_decode( $text, true );
		return is_array( $data ) ? $data : null;
	}
}
