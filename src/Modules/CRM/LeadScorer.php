<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\CRM;

use VMStudio\SalesOS\AI\DTO\ChatRequest;
use VMStudio\SalesOS\AI\DTO\Message;
use VMStudio\SalesOS\AI\ProviderManager;
use VMStudio\SalesOS\AI\PromptRegistry;
use VMStudio\SalesOS\Core\EventBus;
use VMStudio\SalesOS\Support\Logger;

/**
 * Two-layer scoring: deterministic rules always run; the AI pass refines them
 * when a provider is available. The rule score is the floor, so scoring never
 * silently breaks when the AI is down.
 */
final class LeadScorer {

	public function __construct(
		private ContactRepository $contacts,
		private ActivityRepository $activities,
		private ProviderManager $ai,
		private PromptRegistry $prompts,
		private EventBus $events,
		private Logger $logger
	) {}

	/** @param array<string, mixed> $contact Contact row. */
	public function ruleScore( array $contact ): int {
		$score = 0;

		$score += '' !== (string) ( $contact['email'] ?? '' ) ? 20 : 0;
		$score += '' !== (string) ( $contact['phone'] ?? '' ) ? 20 : 0;
		$score += '' !== trim( (string) ( $contact['requirements'] ?? '' ) ) ? 15 : 0;
		$score += ! empty( $contact['company_id'] ) ? 10 : 0;
		$score += ! empty( $contact['consent'] ) ? 5 : 0;

		$messages = (int) ( $contact['meta_message_count'] ?? 0 );
		$score   += min( 20, $messages * 4 );

		if ( ! empty( $contact['last_seen_at'] ) ) {
			$days   = ( time() - strtotime( (string) $contact['last_seen_at'] ) ) / DAY_IN_SECONDS;
			$score += $days < 1 ? 10 : ( $days < 7 ? 5 : 0 );
		}

		return (int) max( 0, min( 100, apply_filters( 'vmsos_rule_score', $score, $contact ) ) );
	}

	/**
	 * Score a contact and persist the result.
	 *
	 * @return array{score:int, stage:string, reason:string, next_action:string}
	 */
	public function score( int $contactId, string $conversationText = '' ): array {
		$contact = $this->contacts->find( $contactId );
		if ( ! $contact ) {
			return [ 'score' => 0, 'stage' => 'new', 'reason' => 'Contact not found', 'next_action' => '' ];
		}

		$rule   = $this->ruleScore( $contact );
		$result = [
			'score'       => $rule,
			'stage'       => $this->stageFor( $rule ),
			'reason'      => 'Rule-based score',
			'next_action' => '',
		];

		try {
			$request = new ChatRequest(
				[
					Message::system( 'You are a precise sales analyst. Reply with JSON only.' ),
					Message::user(
						$this->prompts->render(
							'lead_scoring',
							[
								'lead'         => wp_json_encode( $this->safeFields( $contact ) ),
								'conversation' => mb_substr( $conversationText, 0, 4000 ),
							]
						)
					),
				],
				temperature: 0.1,
				maxTokens: 300,
				jsonMode: true,
				operation: 'scoring'
			);

			$json = $this->ai->chat( $request )->json();

			if ( is_array( $json ) && isset( $json['score'] ) ) {
				$result = [
					'score'       => (int) max( $rule, (int) $json['score'] ),
					'stage'       => sanitize_key( (string) ( $json['stage'] ?? $result['stage'] ) ),
					'reason'      => sanitize_text_field( (string) ( $json['reason'] ?? '' ) ),
					'next_action' => sanitize_text_field( (string) ( $json['next_action'] ?? '' ) ),
				];
			}
		} catch ( \Throwable $e ) {
			$this->logger->error( 'crm', 'AI scoring unavailable, using rule score', [ 'error' => $e->getMessage() ] );
		}

		$this->contacts->setScore( $contactId, $result['score'], $result['stage'], $result['reason'] );
		$this->activities->log( 'contact', $contactId, 'score', sprintf( 'Scored %d — %s', $result['score'], $result['reason'] ), 'ai', $result );

		$this->events->dispatch( 'contact.scored', [ 'contact_id' => $contactId ] + $result );

		if ( $result['score'] >= 70 ) {
			$this->events->dispatch( 'contact.hot', [ 'contact_id' => $contactId ] + $result );
		}

		return $result;
	}

	private function stageFor( int $score ): string {
		return match ( true ) {
			$score >= 70 => 'hot',
			$score >= 40 => 'qualified',
			default      => 'new',
		};
	}

	/**
	 * Only send the AI what it needs to judge intent.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 * @return array<string, mixed>
	 */
	private function safeFields( array $contact ): array {
		return [
			'has_email'    => '' !== (string) ( $contact['email'] ?? '' ),
			'has_phone'    => '' !== (string) ( $contact['phone'] ?? '' ),
			'source'       => $contact['source'] ?? '',
			'requirements' => $contact['requirements'] ?? '',
			'stage'        => $contact['stage'] ?? 'new',
			'created_at'   => $contact['created_at'] ?? '',
		];
	}
}
