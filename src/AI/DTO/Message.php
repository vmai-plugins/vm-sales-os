<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\AI\DTO;

final class Message implements \JsonSerializable {

	/**
	 * @param string                    $role      system|user|assistant|tool
	 * @param string                    $content   Message text.
	 * @param array<int, array<mixed>>  $toolCalls Tool call payloads.
	 * @param string                    $toolCallId Tool call being answered.
	 * @param string                    $name      Tool or participant name.
	 */
	/** @var array<string, mixed>|null Provider-native payload, echoed back verbatim. */
	private ?array $payload = null;

	public function __construct(
		public readonly string $role,
		public readonly string $content = '',
		public readonly array $toolCalls = [],
		public readonly string $toolCallId = '',
		public readonly string $name = ''
	) {}

	/**
	 * Wrap a provider's own assistant turn so it can be replayed exactly.
	 * Tool loops break when an assistant message is paraphrased instead of echoed.
	 *
	 * @param array<string, mixed> $payload Provider-native message.
	 */
	public static function native( string $role, array $payload ): self {
		$message          = new self( $role, is_string( $payload['content'] ?? null ) ? $payload['content'] : '' );
		$message->payload = $payload;
		return $message;
	}

	/** @return array<string, mixed>|null */
	public function nativePayload(): ?array {
		return $this->payload;
	}

	public static function system( string $content ): self {
		return new self( 'system', $content );
	}

	public static function user( string $content ): self {
		return new self( 'user', $content );
	}

	public static function assistant( string $content, array $toolCalls = [] ): self {
		return new self( 'assistant', $content, $toolCalls );
	}

	public static function tool( string $toolCallId, string $name, string $content ): self {
		return new self( 'tool', $content, [], $toolCallId, $name );
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		if ( null !== $this->payload ) {
			return $this->payload;
		}

		$payload = [ 'role' => $this->role, 'content' => $this->content ];
		if ( $this->toolCalls ) {
			$payload['tool_calls'] = $this->toolCalls;
		}
		if ( '' !== $this->toolCallId ) {
			$payload['tool_call_id'] = $this->toolCallId;
		}
		if ( '' !== $this->name ) {
			$payload['name'] = $this->name;
		}
		return $payload;
	}
}
