<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\CRM;

use VMStudio\SalesOS\AI\DTO\ChatRequest;
use VMStudio\SalesOS\AI\DTO\Message;
use VMStudio\SalesOS\AI\ProviderManager;
use VMStudio\SalesOS\Modules\Channels\EvolutionClient;
use VMStudio\SalesOS\Modules\Channels\WhatsAppClient;
use VMStudio\SalesOS\Modules\Chat\ConversationRepository;
use VMStudio\SalesOS\Support\Logger;

/**
 * Autonomous BANT (Budget, Authority, Need, Timeline) Lead Qualification Engine.
 * Analyzes conversation transcripts and triggers instant priority alerts for hot leads.
 */
final class LeadScoreEngine {

	public function __construct(
		private ProviderManager $ai,
		private ConversationRepository $conversations,
		private ContactRepository $contacts,
		private WhatsAppClient $whatsapp,
		private EvolutionClient $evolution,
		private Logger $logger
	) {}

	/**
	 * Inspect a conversation after a reply turn and calculate BANT qualification.
	 *
	 * @param int $conversationId
	 * @return void
	 */
	public function evaluate( int $conversationId ): void {
		$messages = $this->conversations->messages( $conversationId );
		if ( count( $messages ) < 3 ) {
			return; // Need at least 2 visitor turns for meaningful evaluation.
		}

		// Don't re-evaluate too frequently
		$cacheKey = 'vmsos_bant_evaluated_' . $conversationId;
		if ( get_transient( $cacheKey ) ) {
			return;
		}
		set_transient( $cacheKey, 1, 10 * MINUTE_IN_SECONDS );

		$transcript = '';
		$userMessagesCount = 0;
		foreach ( $messages as $msg ) {
			$role = (string) ( $msg['role'] ?? 'user' );
			$text = (string) ( $msg['content'] ?? '' );
			if ( 'user' === $role ) {
				$userMessagesCount++;
			}
			$transcript .= strtoupper( $role ) . ': ' . $text . "\n";
		}

		if ( $userMessagesCount < 2 ) {
			return;
		}

		$prompt = <<<EOT
Analyze this sales chat transcript and evaluate BANT qualification (Budget, Authority, Need, Timeline).
Respond ONLY in valid raw JSON with this exact schema:
{
  "score": integer (0 to 100, where 70+ is high intent hot lead, 40-69 warm lead, <40 casual),
  "budget": string (e.g. "<$1k", "$1k-$5k", "$5k-$15k", "$15k+", or "unspecified"),
  "timeline": string (e.g. "immediate", "1-4 weeks", "1-3 months", "exploring"),
  "authority": string ("decision_maker", "influencer", "evaluator", or "unknown"),
  "summary": string (maximum 15 words synopsis of what the prospect needs),
  "is_hot": boolean (true if ready to buy, has urgency, or asks for pricing/proposal with phone/email)
}

Transcript:
$transcript
EOT;

		try {
			$response = $this->ai->chat(
				new ChatRequest(
					[ Message::user( $prompt ) ],
					temperature: 0.1,
					maxTokens: 250,
					jsonMode: true,
					operation: 'lead_score'
				)
			);

			$data = json_decode( $response->content, true );
			if ( ! is_array( $data ) || ! isset( $data['score'] ) ) {
				return;
			}

			$score    = max( 0, min( 100, (int) $data['score'] ) );
			$summary  = sanitize_text_field( (string) ( $data['summary'] ?? '' ) );
			$budget   = sanitize_text_field( (string) ( $data['budget'] ?? 'unspecified' ) );
			$timeline = sanitize_text_field( (string) ( $data['timeline'] ?? 'exploring' ) );
			$isHot    = ! empty( $data['is_hot'] ) || $score >= 70;

			// Update conversation record
			$convo = $this->conversations->find( $conversationId );
			$contactId = (int) ( $convo['contact_id'] ?? 0 );

			if ( $contactId > 0 ) {
				$this->contacts->update(
					$contactId,
					[
						'score'        => $score,
						'score_reason' => mb_substr( $summary, 0, 255 ),
						'requirements' => $summary,
						'stage'        => $isHot ? 'qualified' : 'contacted',
					]
				);

				// Fire priority WhatsApp/Email alert if hot lead detected
				if ( $isHot ) {
					$this->notifyHotLead( $contactId, $score, $budget, $timeline, $summary, $conversationId );
				}
			}

		} catch ( \Throwable $e ) {
			$this->logger->error( 'lead_score', 'Failed BANT scoring', [ 'error' => $e->getMessage() ] );
		}
	}

	/**
	 * Send high-priority WhatsApp and Email notifications to sales leadership.
	 */
	private function notifyHotLead( int $contactId, int $score, string $budget, string $timeline, string $summary, int $conversationId ): void {
		$alertKey = 'vmsos_hot_alerted_' . $contactId;
		if ( get_transient( $alertKey ) ) {
			return; // Don't duplicate alert for same lead
		}
		set_transient( $alertKey, 1, 24 * HOUR_IN_SECONDS );

		$contact = $this->contacts->find( $contactId );
		if ( ! $contact ) {
			return;
		}

		$name  = trim( ( (string) ( $contact['first_name'] ?? '' ) ) . ' ' . ( (string) ( $contact['last_name'] ?? '' ) ) );
		$phone = (string) ( $contact['phone'] ?? 'None provided' );
		$email = (string) ( $contact['email'] ?? 'None provided' );
		$site  = get_bloginfo( 'name' );

		$inboxUrl = admin_url( 'admin.php?page=vm-sales-os' );

		$waMsg = "🚨 *HOT DEAL ALERT — {$site}*\n\n" .
			"👤 *Contact:* " . ( $name ?: 'Website Prospect' ) . "\n" .
			"📞 *Phone:* {$phone}\n" .
			"✉️ *Email:* {$email}\n" .
			"🎯 *BANT Score:* {$score}/100 (HIGH INTENT)\n" .
			"💰 *Budget:* {$budget}\n" .
			"⏱ *Timeline:* {$timeline}\n" .
			"📋 *Requirements:* {$summary}\n\n" .
			"👉 *Take Over Conversation:* {$inboxUrl}";

		// 1. Send via WhatsApp to configured sales admin phone if set
		$adminWa = (string) get_option( 'vmsos_widget_whatsapp', '' );
		if ( '' !== $adminWa ) {
			$cleanWa = preg_replace( '/[^0-9]/', '', $adminWa ) ?? '';
			if ( '' !== $cleanWa ) {
				$waEngine = (string) get_option( 'vmsos_whatsapp_provider', 'meta' );
				try {
					if ( 'evolution' === $waEngine ) {
						$this->evolution->sendMessage( $cleanWa, $waMsg );
					} else {
						$this->whatsapp->sendText( $cleanWa, $waMsg );
					}
				} catch ( \Throwable $e ) {
					$this->logger->error( 'lead_score', 'WhatsApp hot lead dispatch failed', [ 'error' => $e->getMessage() ] );
				}
			}
		}

		// 2. Send Priority Email Alert
		$notifyEmail = (string) get_option( 'vmsos_notify_email', get_option( 'admin_email' ) );
		if ( '' !== $notifyEmail && is_email( $notifyEmail ) ) {
			$subject = sprintf( '[Hot Deal %d/100] %s on %s', $score, ( $name ?: 'New Prospect' ), $site );
			$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
			@wp_mail( $notifyEmail, $subject, $waMsg, $headers );
		}
	}
}
