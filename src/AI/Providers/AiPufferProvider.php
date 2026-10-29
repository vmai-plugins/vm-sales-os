<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\AI\Providers;

use VMStudio\SalesOS\AI\AiException;
use VMStudio\SalesOS\AI\DTO\ChatRequest;
use VMStudio\SalesOS\AI\DTO\ChatResponse;

/**
 * AI Power (AIPKit / "AI Puffer") running on this or another WordPress site.
 *
 * It is not an OpenAI-compatible endpoint: it exposes its own REST API at
 * {site}/wp-json/aipkit/v1 with a bearer key. POST /generate returns `content`;
 * POST /chat/{bot_id}/message returns `reply`.
 */
final class AiPufferProvider extends AbstractProvider {

	public function id(): string {
		return 'aipuffer';
	}

	public function label(): string {
		return 'AI Puffer (AI Power)';
	}

	public function isConfigured(): bool {
		return '' !== $this->apiKey() && '' !== $this->siteUrl();
	}

	public function supportsTools(): bool {
		return false;
	}

	private function siteUrl(): string {
		return untrailingslashit( $this->setting( 'site_url', home_url() ) );
	}

	private function base(): string {
		return $this->siteUrl() . '/wp-json/aipkit/v1';
	}

	public function chat( ChatRequest $request ): ChatResponse {
		$start   = $this->now();
		$botId   = $this->setting( 'bot_id' );
		$headers = [ 'Authorization' => 'Bearer ' . $this->apiKey() ];

		if ( '' !== $botId ) {
			$data  = $this->post(
				$this->base() . '/chat/' . rawurlencode( $botId ) . '/message',
				$headers,
				[
					'message'         => $this->flatten( $request ),
					'session_id'      => (string) ( $request->meta['session_id'] ?? '' ),
					'system_override' => $request->systemPrompt(),
				]
			);
			$text = (string) ( $data['reply'] ?? '' );
		} else {
			$data = $this->post(
				$this->base() . '/generate',
				$headers,
				[
					'prompt'      => $this->flatten( $request ),
					'temperature' => $request->temperature,
					'max_tokens'  => $request->maxTokens,
				]
			);
			$text = (string) ( $data['content'] ?? '' );
		}

		if ( '' === $text ) {
			throw new AiException( 'aipuffer: empty response' );
		}

		return new ChatResponse(
			$text,
			$this->id(),
			(string) ( $data['model'] ?? 'aipuffer' ),
			[],
			(int) ( $data['usage']['prompt_tokens'] ?? 0 ),
			(int) ( $data['usage']['completion_tokens'] ?? 0 ),
			$this->elapsed( $start ),
			$data
		);
	}

	/** Collapse a message list into a single prompt for a single-turn endpoint. */
	private function flatten( ChatRequest $request ): string {
		$lines = [];
		foreach ( $request->messages as $message ) {
			if ( 'system' === $message->role || '' === $message->content ) {
				continue;
			}
			$lines[] = ( 'assistant' === $message->role ? 'Assistant: ' : 'User: ' ) . $message->content;
		}
		return implode( "\n", $lines );
	}
}
