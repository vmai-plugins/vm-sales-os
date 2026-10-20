<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\AI\DTO;

final class ChatRequest {

	/**
	 * @param array<int, Message>        $messages    Conversation so far.
	 * @param array<int, array<mixed>>   $tools       JSON-schema tool definitions.
	 * @param array<string, mixed>       $meta        Free-form routing metadata.
	 */
	public function __construct(
		public array $messages,
		public string $model = '',
		public float $temperature = 0.4,
		public int $maxTokens = 1024,
		public array $tools = [],
		public bool $jsonMode = false,
		public string $operation = 'chat',
		public array $meta = []
	) {}

	public function withModel( string $model ): self {
		$clone        = clone $this;
		$clone->model = $model;
		return $clone;
	}

	public function withMessages( array $messages ): self {
		$clone           = clone $this;
		$clone->messages = $messages;
		return $clone;
	}

	/** @return array<int, array<string, mixed>> */
	public function messagesArray(): array {
		return array_map( static fn( Message $m ) => $m->jsonSerialize(), $this->messages );
	}

	public function systemPrompt(): string {
		foreach ( $this->messages as $message ) {
			if ( 'system' === $message->role ) {
				return $message->content;
			}
		}
		return '';
	}

	/** Messages excluding the system turn, for providers that take it separately. */
	public function conversationArray(): array {
		return array_values(
			array_map(
				static fn( Message $m ) => $m->jsonSerialize(),
				array_filter( $this->messages, static fn( Message $m ) => 'system' !== $m->role )
			)
		);
	}
}
