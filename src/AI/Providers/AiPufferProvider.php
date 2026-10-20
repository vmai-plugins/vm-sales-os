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

	/**
	 * Live-sync chatbots/models from local or remote AIPKit/AI Power.
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

		$models = [];

		// 1. Check local WordPress database for AIPKit/AI Power chatbots
		if ( function_exists( 'get_posts' ) ) {
			$bots = get_posts( [
				'post_type'      => [ 'wpaicg_bots', 'aipkit_bot' ],
				'post_status'    => 'publish',
				'posts_per_page' => 100,
			] );
			foreach ( $bots as $bot ) {
				$models[] = (string) $bot->ID;
			}
		}

		// 2. Check remote site if specified
		$siteUrl = untrailingslashit( ( null !== $baseOverride && '' !== $baseOverride ) ? $baseOverride : $this->siteUrl() );
		$key = null !== $keyOverride && '' !== $keyOverride ? $keyOverride : $this->apiKey();
		if ( '' !== $siteUrl && '' !== $key ) {
			$resp = wp_remote_get(
				$siteUrl . '/wp-json/aipkit/v1/bots',
				[
					'headers'   => [ 'Authorization' => 'Bearer ' . $key ],
					'timeout'   => 12,
					'sslverify' => false,
				]
			);
			if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
				$data = json_decode( wp_remote_retrieve_body( $resp ), true );
				if ( is_array( $data ) ) {
					foreach ( $data as $item ) {
						if ( ! empty( $item['id'] ) ) {
							$models[] = (string) $item['id'];
						}
					}
				}
			}
		}

		$models = array_values( array_unique( array_filter( $models ) ) );
		if ( empty( $models ) ) {
			$models = [ 'default' ];
		}

		sort( $models, SORT_NATURAL | SORT_FLAG_CASE );

		set_transient( $cacheKey, $models, 12 * HOUR_IN_SECONDS );
		update_option( 'vmsos_ai_' . $this->id() . '_models_cache', $models, false );

		return $models;
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
					'provider'    => $this->setting( 'provider', 'openai' ),
					'model'       => $this->setting( 'model', 'gpt-4o-mini' ),
					'messages'    => $request->messagesArray(),
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
