<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Chat;

use VMStudio\SalesOS\AI\AiException;
use VMStudio\SalesOS\AI\DTO\ChatRequest;
use VMStudio\SalesOS\AI\DTO\Message;
use VMStudio\SalesOS\AI\ProviderManager;
use VMStudio\SalesOS\AI\PromptRegistry;
use VMStudio\SalesOS\AI\ToolRegistry;
use VMStudio\SalesOS\Core\EventBus;
use VMStudio\SalesOS\Support\Logger;

/**
 * The reply engine. Everything a visitor-facing turn needs: spam filtering,
 * retrieval, the tool loop, persistence and events.
 */
final class ChatService {

	private const MAX_TOOL_ITERATIONS = 3;

	public function __construct(
		private ConversationRepository $conversations,
		private ProviderManager $ai,
		private PromptRegistry $prompts,
		private ToolRegistry $tools,
		private SpamGuard $guard,
		private EventBus $events,
		private Logger $logger
	) {}

	/**
	 * @param array<string, mixed> $context channel, visitor_hash, page_url.
	 * @return array{uuid:string, reply:string, handover:bool, blocked?:string}
	 */
	public function reply( string $uuid, string $message, array $context = [] ): array {
		$verdict = $this->guard->inspect( $message );

		if ( ! $verdict['allowed'] ) {
			$this->events->dispatch( 'chat.blocked', [ 'reason' => $verdict['reason'] ] );
			return [
				'uuid'     => $uuid,
				'reply'    => __( 'Send a short message about what you need and I will help.', 'vm-sales-os' ),
				'handover' => false,
				'blocked'  => $verdict['reason'],
			];
		}

		$conversation = '' !== $uuid ? $this->conversations->findByUuid( $uuid ) : null;

		if ( ! $conversation ) {
			$started      = $this->conversations->start(
				(string) ( $context['channel'] ?? 'web' ),
				(string) ( $context['visitor_hash'] ?? '' )
			);
			$conversation = [ 'id' => $started['id'], 'uuid' => $started['uuid'], 'contact_id' => 0 ];
		}

		$conversationId = (int) $conversation['id'];
		$this->conversations->addMessage( $conversationId, 'user', $message );

		// A human owns this thread. Store the turn, raise it in the inbox, stay quiet.
		if ( ! empty( $conversation['handover'] ) && 'closed' !== (string) ( $conversation['status'] ?? '' ) ) {
			$this->events->dispatch(
				'conversation.awaiting_human',
				[
					'conversation_id' => $conversationId,
					'contact_id'      => (int) ( $conversation['contact_id'] ?? 0 ),
					'message'         => $message,
					'channel'         => (string) ( $context['channel'] ?? 'web' ),
				]
			);

			return [
				'uuid'     => (string) $conversation['uuid'],
				'reply'    => '',
				'handover' => true,
			];
		}

		// Downstream filters (memory, personalisation) need to know who this is.
		$context['conversation_id'] = $conversationId;
		$context['contact_id']      = (int) ( $conversation['contact_id'] ?? 0 );

		$messages = $this->buildMessages( $conversationId, $message, $context );
		$grants   = (array) apply_filters( 'vmsos_chat_tool_grants', [ 'public' ], $context );
		$schemas  = $this->tools->schemasFor( $grants );

		try {
			$response = $this->runToolLoop( $messages, $schemas, $grants, $conversationId, $conversation );
		} catch ( AiException $e ) {
			$this->logger->error( 'chat', 'Reply failed', [ 'error' => $e->getMessage(), 'attempts' => $e->attempts() ] );
			$this->conversations->requestHandover( $conversationId );

			return [
				'uuid'     => (string) $conversation['uuid'],
				'reply'    => $this->fallbackLine(),
				'handover' => true,
			];
		}

		$this->conversations->addMessage(
			$conversationId,
			'assistant',
			$response->content,
			[
				'provider'   => $response->provider,
				'model'      => $response->model,
				'tokens_in'  => $response->tokensIn,
				'tokens_out' => $response->tokensOut,
				'latency_ms' => $response->latencyMs,
			]
		);

		$this->events->dispatch(
			'chat.replied',
			[
				'conversation_id' => $conversationId,
				'provider'        => $response->provider,
				'channel'         => (string) ( $context['channel'] ?? 'web' ),
			]
		);

		return [
			'uuid'     => (string) $conversation['uuid'],
			'reply'    => $response->content,
			'handover' => false,
		];
	}

	/**
	 * Stream a visitor reply token-by-token.
	 *
	 * @param string $uuid Conversation UUID.
	 * @param string $message Visitor text.
	 * @param array<string, mixed> $context Channel, visitor hash, page URL.
	 * @param callable(string): void $onToken Invoked for each token chunk.
	 * @return array{uuid:string, reply:string, handover:bool, blocked?:string}
	 */
	public function streamReply( string $uuid, string $message, array $context, callable $onToken ): array {
		$verdict = $this->guard->inspect( $message );

		if ( ! $verdict['allowed'] ) {
			$this->events->dispatch( 'chat.blocked', [ 'reason' => $verdict['reason'] ] );
			$blockedReply = __( 'Send a short message about what you need and I will help.', 'vm-sales-os' );
			$onToken( $blockedReply );
			return [
				'uuid'     => $uuid,
				'reply'    => $blockedReply,
				'handover' => false,
				'blocked'  => $verdict['reason'],
			];
		}

		$conversation = '' !== $uuid ? $this->conversations->findByUuid( $uuid ) : null;

		if ( ! $conversation ) {
			$started      = $this->conversations->start(
				(string) ( $context['channel'] ?? 'web' ),
				(string) ( $context['visitor_hash'] ?? '' )
			);
			$conversation = [ 'id' => $started['id'], 'uuid' => $started['uuid'], 'contact_id' => 0 ];
		}

		$conversationId = (int) $conversation['id'];
		$this->conversations->addMessage( $conversationId, 'user', $message );

		if ( ! empty( $conversation['handover'] ) && 'closed' !== (string) ( $conversation['status'] ?? '' ) ) {
			$this->events->dispatch(
				'conversation.awaiting_human',
				[
					'conversation_id' => $conversationId,
					'contact_id'      => (int) ( $conversation['contact_id'] ?? 0 ),
					'message'         => $message,
					'channel'         => (string) ( $context['channel'] ?? 'web' ),
				]
			);

			return [
				'uuid'     => (string) $conversation['uuid'],
				'reply'    => '',
				'handover' => true,
			];
		}

		$context['conversation_id'] = $conversationId;
		$context['contact_id']      = (int) ( $conversation['contact_id'] ?? 0 );

		$messages = $this->buildMessages( $conversationId, $message, $context );
		$grants   = (array) apply_filters( 'vmsos_chat_tool_grants', [ 'public' ], $context );
		$schemas  = $this->tools->schemasFor( $grants );

		try {
			if ( ! empty( $schemas ) ) {
				$checkResponse = $this->ai->chat(
					new ChatRequest(
						$messages,
						temperature: (float) get_option( 'vmsos_chat_temperature', 0.4 ),
						maxTokens: (int) get_option( 'vmsos_chat_max_tokens', 700 ),
						tools: $schemas,
						operation: 'chat'
					)
				);

				if ( $checkResponse->hasToolCalls() ) {
					$messages[] = $checkResponse->assistantEcho();

					foreach ( $checkResponse->toolCalls as $call ) {
						$result = $this->tools->call(
							(string) $call['name'],
							(array) $call['arguments'],
							$grants,
							array_merge( $context, [ 'conversation_id' => $conversationId ] )
						);
						$messages[] = Message::tool( (string) $call['id'], (string) $call['name'], $result );
					}
				}
			}

			$request = new ChatRequest(
				$messages,
				temperature: (float) get_option( 'vmsos_chat_temperature', 0.4 ),
				maxTokens: (int) get_option( 'vmsos_chat_max_tokens', 700 ),
				tools: [],
				operation: 'chat'
			);

			$response = $this->ai->streamChat( $request, $onToken );

		} catch ( AiException $e ) {
			$this->logger->error( 'chat', 'Streaming reply failed', [ 'error' => $e->getMessage() ] );
			$fallback = $this->fallbackLine();
			$onToken( $fallback );
			$this->conversations->requestHandover( $conversationId );

			return [
				'uuid'     => (string) $conversation['uuid'],
				'reply'    => $fallback,
				'handover' => true,
			];
		}

		$this->conversations->addMessage(
			$conversationId,
			'assistant',
			$response->content,
			[
				'provider'   => $response->provider,
				'model'      => $response->model,
				'tokens_in'  => $response->tokensIn,
				'tokens_out' => $response->tokensOut,
				'latency_ms' => $response->latencyMs,
			]
		);

		$this->events->dispatch(
			'chat.replied',
			[
				'conversation_id' => $conversationId,
				'provider'        => $response->provider,
				'channel'         => (string) ( $context['channel'] ?? 'web' ),
			]
		);

		return [
			'uuid'     => (string) $conversation['uuid'],
			'reply'    => $response->content,
			'handover' => false,
		];
	}

	/**
	 * @param array<int, Message>              $messages Conversation.
	 * @param array<int, array<string, mixed>> $schemas  Tool schemas.
	 * @param array<int, string>               $grants   Capabilities.
	 * @param array<string, mixed>             $conversation Conversation row.
	 */
	private function runToolLoop( array $messages, array $schemas, array $grants, int $conversationId, array $conversation ) {
		for ( $iteration = 0; $iteration < self::MAX_TOOL_ITERATIONS; $iteration++ ) {
			$response = $this->ai->chat(
				new ChatRequest(
					$messages,
					temperature: (float) get_option( 'vmsos_chat_temperature', 0.4 ),
					maxTokens: (int) get_option( 'vmsos_chat_max_tokens', 700 ),
					tools: $schemas,
					operation: 'chat'
				)
			);

			if ( ! $response->hasToolCalls() ) {
				return $response;
			}

			$messages[] = $response->assistantEcho();

			foreach ( $response->toolCalls as $call ) {
				$result = $this->tools->call(
					(string) $call['name'],
					(array) $call['arguments'],
					$grants,
					[
						'conversation_id' => $conversationId,
						'contact_id'      => (int) ( $conversation['contact_id'] ?? 0 ),
						'uuid'            => (string) ( $conversation['uuid'] ?? '' ),
					]
				);

				$messages[] = Message::tool(
					(string) $call['id'],
					(string) $call['name'],
					(string) wp_json_encode( $result )
				);
			}
		}

		// Ran out of iterations: ask for a plain answer with no tools.
		return $this->ai->chat(
			new ChatRequest( $messages, temperature: 0.3, maxTokens: 500, operation: 'chat' )
		);
	}

	/**
	 * @param array<string, mixed> $context Request context.
	 * @return array<int, Message>
	 */
	private function buildMessages( int $conversationId, string $message, array $context ): array {
		$knowledge = (string) apply_filters( 'vmsos_chat_knowledge_context', '', $message, $context );

		$system = $this->prompts->render(
			'sales_agent',
			[
				'agent_name'       => (string) get_option( 'vmsos_agent_name', 'Ava' ),
				'business_name'    => get_bloginfo( 'name' ),
				'business_context' => (string) get_option( 'vmsos_business_context', '' ),
				'knowledge'        => '' !== $knowledge ? $knowledge : __( '(no knowledge base entries matched — say you will check and follow up)', 'vm-sales-os' ),
			]
		);

		if ( ! empty( $context['page_url'] ) ) {
			$system .= "\n\nThe visitor is on: " . esc_url_raw( (string) $context['page_url'] );
		}

		/**
		 * Last word on the system prompt. Memory, brand voice and anything
		 * else that needs to speak to the model hooks in here.
		 *
		 * @param string               $system         Assembled system prompt.
		 * @param int                  $conversationId Conversation id.
		 * @param array<string, mixed> $context        Request context.
		 */
		$system = (string) apply_filters( 'vmsos_chat_system_prompt', $system, $conversationId, $context );

		$messages = [ Message::system( $system ) ];

		foreach ( $this->conversations->history( $conversationId, 20 ) as $row ) {
			$messages[] = new Message( (string) $row['role'], (string) $row['content'] );
		}

		// history() already includes the message we just stored.
		if ( ! $messages || end( $messages )->content !== $message ) {
			$messages[] = Message::user( $message );
		}

		return $messages;
	}

	private function fallbackLine(): string {
		return (string) get_option(
			'vmsos_fallback_reply',
			__( 'I cannot reach my assistant right now. Leave your email and someone will get back to you today.', 'vm-sales-os' )
		);
	}

	/** Close a conversation and hand the transcript to whoever is listening. */
	public function close( int $conversationId, int $contactId = 0 ): void {
		$transcript = $this->conversations->transcript( $conversationId );

		$this->conversations->close( $conversationId );
		$this->events->dispatch(
			'conversation.closed',
			[
				'conversation_id' => $conversationId,
				'contact_id'      => $contactId,
				'transcript'      => $transcript,
			]
		);
	}
}
