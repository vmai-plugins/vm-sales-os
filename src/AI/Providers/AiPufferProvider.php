<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\AI\Providers;

use VMStudio\SalesOS\AI\AiException;
use VMStudio\SalesOS\AI\DTO\ChatRequest;
use VMStudio\SalesOS\AI\DTO\ChatResponse;

/**
 * AI Power (AIPKit / "AI Puffer") running on this or another WordPress site.
 *
 * It communicates with the AIPKit REST API at {site}/wp-json/aipkit/v1 with a bearer key.
 * Primary: POST /chat/{bot_id}/message with `messages` array -> returns `reply`.
 * Fallback: POST /generate with `messages` array -> returns `content`.
 */
final class AiPufferProvider extends AbstractProvider {

	public function id(): string {
		return 'aipuffer';
	}

	public function label(): string {
		return 'AI Puffer (AI Power Bots)';
	}

	public function isConfigured(): bool {
		return '' !== $this->apiKey() && '' !== $this->siteUrl();
	}

	public function supportsTools(): bool {
		return false;
	}

	protected function apiKey(): string {
		$key = parent::apiKey();
		if ( '' !== $key ) {
			return $key;
		}

		// Auto-resolve from local AIPKit options if running on the same site
		$opts = get_option( 'aipkit_options', [] );
		$apiKeys = isset( $opts['api_keys'] ) && is_array( $opts['api_keys'] ) ? $opts['api_keys'] : [];
		return trim( (string) ( $apiKeys['public_api_key'] ?? '' ) );
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

		// 1. Check local WordPress database for AIPKit/AI Power chatbots (aipkit_chatbot)
		if ( function_exists( 'get_posts' ) ) {
			$bots = get_posts( [
				'post_type'      => [ 'aipkit_chatbot', 'wpaicg_bots', 'aipkit_bot' ],
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'ID',
				'order'          => 'DESC',
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
				$siteUrl . '/wp-json/aipkit/v1/chatbots',
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
			$models = [ '1169', '145' ];
		}

		sort( $models, SORT_NATURAL | SORT_FLAG_CASE );

		set_transient( $cacheKey, $models, 12 * HOUR_IN_SECONDS );
		update_option( 'vmsos_ai_' . $this->id() . '_models_cache', $models, false );

		return $models;
	}

	private function siteUrl(): string {
		$url = $this->setting( 'base_url', '' );
		if ( '' === $url ) {
			$url = $this->setting( 'site_url', home_url() );
		}
		return untrailingslashit( $url );
	}

	private function isLocal(): bool {
		$url  = $this->siteUrl();
		$home = untrailingslashit( home_url() );
		return '' === $url || $url === $home || str_contains( $url, 'localhost' ) || str_contains( $url, '127.0.0.1' );
	}

	private function base(): string {
		return $this->siteUrl() . '/wp-json/aipkit/v1';
	}

	/**
	 * Resolve the primary default bot ID:
	 * Prefers explicitly selected model, then Sales Agent (1169), then VMStudio AI (145), then newest.
	 */
	public function resolveDefaultBotId(): string {
		$configured = trim( $this->setting( 'model' ) );
		if ( '' !== $configured && 'default' !== $configured ) {
			return $configured;
		}

		$botId = trim( $this->setting( 'bot_id' ) );
		if ( '' !== $botId && 'default' !== $botId ) {
			return $botId;
		}

		if ( function_exists( 'get_posts' ) ) {
			$bots = get_posts( [
				'post_type'      => [ 'aipkit_chatbot', 'wpaicg_bots', 'aipkit_bot' ],
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			] );
			foreach ( $bots as $b ) {
				if ( 1169 === (int) $b->ID || false !== stripos( $b->post_title, 'sales' ) ) {
					return (string) $b->ID;
				}
			}
			if ( ! empty( $bots ) ) {
				return (string) $bots[0]->ID;
			}
		}

		return '1169';
	}

	public function chat( ChatRequest $request ): ChatResponse {
		$start   = $this->now();
		$botId   = (string) ( $request->meta['bot_id'] ?? '' );
		if ( '' === $botId || 'default' === $botId ) {
			$botId = $this->resolveDefaultBotId();
		}

		$headers = [ 'Authorization' => 'Bearer ' . $this->apiKey() ];

		// Format messages array compatible with AIPKit_REST_Chat_Handler
		$messages = [];
		foreach ( $request->messages as $m ) {
			if ( 'system' === $m->role ) {
				continue;
			}
			$messages[] = [
				'role'    => 'assistant' === $m->role ? 'assistant' : 'user',
				'content' => $m->content,
			];
		}
		if ( empty( $messages ) ) {
			$messages[] = [
				'role'    => 'user',
				'content' => $this->flatten( $request ),
			];
		}

		$text      = '';
		$modelUsed = 'aipuffer';
		$usageData = [];
		$data      = [];
		$botError  = '';

		if ( '' !== $botId && 'default' !== $botId ) {
			// Fast-path: When running on the same site, invoke AIPKit directly via rest_do_request()
			// This completely eliminates loopback network latency, Cloudflare timeouts, and cURL error 28!
			if ( $this->isLocal() && function_exists( 'rest_do_request' ) ) {
				$restReq = new \WP_REST_Request( 'POST', '/aipkit/v1/chat/' . rawurlencode( $botId ) . '/message' );
				$restReq->set_header( 'Content-Type', 'application/json' );
				$restReq->set_header( 'Authorization', 'Bearer ' . $this->apiKey() );
				$restReq->set_body( (string) wp_json_encode( [
					'messages'          => $messages,
					'conversation_uuid' => (string) ( $request->meta['session_id'] ?? '' ),
				] ) );
				$restResp = rest_do_request( $restReq );
				if ( $restResp->get_status() >= 200 && $restResp->get_status() < 300 ) {
					$data      = (array) $restResp->get_data();
					$text      = (string) ( $data['reply'] ?? $data['content'] ?? '' );
					$modelUsed = (string) ( $data['model'] ?? 'bot-' . $botId );
					$usageData = (array) ( $data['usage'] ?? [] );
				} else {
					$errData  = $restResp->get_data();
					$botError = is_array( $errData ) ? ( $errData['message'] ?? 'HTTP ' . $restResp->get_status() ) : 'HTTP ' . $restResp->get_status();
				}
			}

			// Remote or fallback cURL
			if ( '' === $text ) {
				try {
					$data = $this->post(
						$this->base() . '/chat/' . rawurlencode( $botId ) . '/message',
						$headers,
						[
							'messages'          => $messages,
							'conversation_uuid' => (string) ( $request->meta['session_id'] ?? '' ),
						],
						25
					);
					$text      = (string) ( $data['reply'] ?? $data['content'] ?? '' );
					$modelUsed = (string) ( $data['model'] ?? 'bot-' . $botId );
					$usageData = (array) ( $data['usage'] ?? [] );
				} catch ( \Throwable $e ) {
					$botError = $e->getMessage();
				}
			}
		}

		// Fallback to /generate if bot response was empty or threw
		if ( '' === $text ) {
			$fallbackModel = $this->setting( 'model', 'auto/best-free' );
			if ( is_numeric( $fallbackModel ) || 'default' === $fallbackModel || '' === $fallbackModel ) {
				$fallbackModel = 'auto/best-free';
			}

			try {
				if ( $this->isLocal() && function_exists( 'rest_do_request' ) ) {
					$restReq = new \WP_REST_Request( 'POST', '/aipkit/v1/generate' );
					$restReq->set_header( 'Content-Type', 'application/json' );
					$restReq->set_header( 'Authorization', 'Bearer ' . $this->apiKey() );
					$restReq->set_body( (string) wp_json_encode( [
						'provider'    => $this->setting( 'provider', 'openai' ),
						'model'       => $fallbackModel,
						'messages'    => $request->messagesArray(),
						'prompt'      => $this->flatten( $request ),
						'temperature' => $request->temperature,
						'max_tokens'  => $request->maxTokens,
					] ) );
					$restResp = rest_do_request( $restReq );
					if ( $restResp->get_status() >= 200 && $restResp->get_status() < 300 ) {
						$data      = (array) $restResp->get_data();
						$text      = (string) ( $data['content'] ?? $data['reply'] ?? '' );
						$modelUsed = (string) ( $data['model'] ?? 'aipuffer-generate' );
						$usageData = (array) ( $data['usage'] ?? [] );
					}
				}

				if ( '' === $text ) {
					$data = $this->post(
						$this->base() . '/generate',
						$headers,
						[
							'provider'    => $this->setting( 'provider', 'openai' ),
							'model'       => $fallbackModel,
							'messages'    => $request->messagesArray(),
							'prompt'      => $this->flatten( $request ),
							'temperature' => $request->temperature,
							'max_tokens'  => $request->maxTokens,
						],
						25
					);
					$text      = (string) ( $data['content'] ?? $data['reply'] ?? '' );
					$modelUsed = (string) ( $data['model'] ?? 'aipuffer-generate' );
					$usageData = (array) ( $data['usage'] ?? [] );
				}
			} catch ( \Throwable $e ) {
				throw new AiException( 'aipuffer: ' . ( '' !== $botError ? "bot {$botId} failed ({$botError}); generate failed: " : '' ) . $e->getMessage() );
			}
		}

		if ( '' === $text ) {
			throw new AiException( 'aipuffer: empty response' . ( '' !== $botError ? " (bot error: {$botError})" : '' ) );
		}

		return new ChatResponse(
			$text,
			$this->id(),
			$modelUsed,
			[],
			(int) ( $usageData['prompt_tokens'] ?? $usageData['input_tokens'] ?? 0 ),
			(int) ( $usageData['completion_tokens'] ?? $usageData['output_tokens'] ?? 0 ),
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
