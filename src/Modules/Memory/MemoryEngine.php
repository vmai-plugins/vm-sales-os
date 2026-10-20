<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Memory;

use VMStudio\SalesOS\AI\AiException;
use VMStudio\SalesOS\AI\DTO\ChatRequest;
use VMStudio\SalesOS\AI\DTO\Message;
use VMStudio\SalesOS\AI\ProviderManager;
use VMStudio\SalesOS\Core\EventBus;
use VMStudio\SalesOS\Support\Logger;

/**
 * Durable, cross-session memory of a person.
 *
 * The chat history gives the model the last twenty turns of one conversation.
 * That is continuity, not memory: come back next week on a different channel
 * and the assistant has forgotten you exist. This keeps one evolving record
 * per contact — what they told us, what they want, what they pushed back on,
 * and what we should do next — and injects it into every future prompt for
 * that person regardless of channel.
 *
 * It refreshes every few turns rather than every turn. Re-summarising on each
 * message would roughly double the cost of a conversation for very little
 * extra fidelity.
 */
final class MemoryEngine {

	public function __construct(
		private MemoryRepository $memories,
		private ProviderManager $ai,
		private EventBus $events,
		private Logger $logger
	) {}

	public static function refreshEvery(): int {
		return max( 2, (int) apply_filters( 'vmsos_memory_refresh_every', (int) get_option( 'vmsos_memory_every', 4 ) ) );
	}

	/**
	 * Prompt-ready rendering. Returns an empty string when there is nothing
	 * worth saying, so the caller can append it unconditionally.
	 */
	public function forPrompt( int $contactId ): string {
		if ( $contactId <= 0 ) {
			return '';
		}

		$memory = $this->memories->forContact( $contactId );

		if ( ! $memory || '' === trim( (string) $memory['summary'] ) ) {
			return '';
		}

		$lines = [ 'What you already know about this person (from earlier conversations — do not ask them to repeat it):' ];
		$lines[] = '- Summary: ' . $memory['summary'];

		foreach ( [ 'facts' => 'Known facts', 'preferences' => 'Preferences', 'objections' => 'Concerns raised' ] as $key => $label ) {
			$items = $this->decode( (string) $memory[ $key ] );

			if ( [] !== $items ) {
				$lines[] = '- ' . $label . ': ' . implode( '; ', array_slice( $items, 0, 8 ) );
			}
		}

		if ( '' !== (string) $memory['buying_stage'] ) {
			$lines[] = '- Buying stage: ' . $memory['buying_stage'];
		}

		if ( '' !== (string) $memory['next_best_action'] ) {
			$lines[] = '- Best next move: ' . $memory['next_best_action'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * Merge the previous memory with a fresh transcript into a new record.
	 *
	 * Failure is non-fatal by design: the old memory stays in place and the
	 * conversation carries on. A memory refresh is an optimisation, never a
	 * precondition for answering someone.
	 */
	public function refresh( int $contactId, string $transcript ): bool {
		$transcript = trim( $transcript );

		if ( $contactId <= 0 || '' === $transcript ) {
			return false;
		}

		$previous = $this->memories->forContact( $contactId );
		$existing = $previous
			? wp_json_encode(
				[
					'summary'          => (string) $previous['summary'],
					'facts'            => $this->decode( (string) $previous['facts'] ),
					'preferences'      => $this->decode( (string) $previous['preferences'] ),
					'objections'       => $this->decode( (string) $previous['objections'] ),
					'next_best_action' => (string) $previous['next_best_action'],
					'buying_stage'     => (string) $previous['buying_stage'],
				]
			)
			: '{}';

		$system = 'You maintain a sales CRM memory record for one person. '
			. 'Merge the existing record with the new conversation into an updated record. '
			. 'Keep durable facts the person actually stated; drop nothing that is still true. '
			. 'Never invent details, never guess a budget, a company size or a timeline that was not said. '
			. 'buying_stage must be one of: unknown, browsing, researching, comparing, ready, stalled. '
			. 'Respond with a single JSON object and nothing else, using exactly these keys: '
			. 'summary (string, under 60 words), facts (array of short strings), preferences (array of short strings), '
			. 'objections (array of short strings), next_best_action (string, one sentence), buying_stage (string).';

		$prompt = "Existing record:\n" . $existing . "\n\nNew conversation:\n" . mb_substr( $transcript, 0, 6000 );

		try {
			$response = $this->ai->chat(
				new ChatRequest(
					[ Message::system( $system ), Message::user( $prompt ) ],
					temperature: 0.2,
					maxTokens: 600,
					operation: 'memory_refresh'
				)
			);
		} catch ( AiException $e ) {
			$this->logger->error( 'memory', 'Refresh failed, keeping previous record', [ 'contact_id' => $contactId, 'error' => $e->getMessage() ] );
			return false;
		}

		$parsed = $this->parse( $response->content );

		if ( null === $parsed ) {
			$this->logger->error( 'memory', 'Refresh returned unparseable JSON', [ 'contact_id' => $contactId ] );
			return false;
		}

		$stored = $this->memories->store( $contactId, $parsed );

		if ( $stored ) {
			$this->events->dispatch(
				'contact.memory_updated',
				[
					'contact_id'   => $contactId,
					'buying_stage' => (string) ( $parsed['buying_stage'] ?? '' ),
				]
			);
		}

		return $stored;
	}

	/**
	 * Models wrap JSON in prose and code fences no matter how firmly you ask
	 * them not to, so pull out the object rather than trusting the envelope.
	 *
	 * @return array<string, mixed>|null
	 */
	private function parse( string $content ): ?array {
		$content = trim( $content );
		$content = (string) preg_replace( '/^```(?:json)?|```$/m', '', $content );

		$start = strpos( $content, '{' );
		$end   = strrpos( $content, '}' );

		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}

		$decoded = json_decode( substr( $content, $start, $end - $start + 1 ), true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/** @return array<int, string> */
	private function decode( string $json ): array {
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_map(
					static fn( $item ): string => is_scalar( $item ) ? trim( (string) $item ) : '',
					$decoded
				),
				static fn( string $item ): bool => '' !== $item
			)
		);
	}
}
