<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\LinkedInRadar;

use VMStudio\SalesOS\AI\ProviderManager;
use VMStudio\SalesOS\AI\DTO\ChatRequest;
use VMStudio\SalesOS\AI\DTO\Message;
use Throwable;

final class LinkedInPostParser {

	private ?ProviderManager $ai;

	public function __construct( ?ProviderManager $ai = null ) {
		$this->ai = $ai;
	}

	/**
	 * Parse raw post content and extract structured lead attributes.
	 *
	 * @param string $rawText The post caption or OCR/text content.
	 * @param array<string, mixed> $meta Optional hints (author_name, headline, profile_url, post_url).
	 * @return array<string, mixed>
	 */
	public function parse( string $rawText, array $meta = [] ): array {
		$parsed = $this->extractByRules( $rawText, $meta );

		// If AI is available and configured, let AI enhance the extraction and generate contextual pitch
		if ( $this->ai instanceof ProviderManager && $this->ai->hasWorkingProvider() ) {
			try {
				$aiResult = $this->extractByAi( $rawText, $parsed );
				if ( ! empty( $aiResult ) && is_array( $aiResult ) ) {
					$parsed = array_merge( $parsed, array_filter( $aiResult ) );
				}
			} catch ( Throwable $e ) {
				// Fall back to rule-based parsed output cleanly
			}
		}

		if ( empty( $parsed['recommended_pitch'] ) ) {
			$parsed['recommended_pitch'] = $this->generateDefaultPitch( $parsed );
		}

		return $parsed;
	}

	/**
	 * Fast rule-based extraction for high accuracy and instant offline parsing.
	 */
	private function extractByRules( string $raw, array $meta ): array {
		$authorName = sanitize_text_field( (string) ( $meta['author_name'] ?? '' ) );
		$headline   = sanitize_text_field( (string) ( $meta['headline'] ?? '' ) );
		$profileUrl = esc_url_raw( (string) ( $meta['profile_url'] ?? '' ) );
		$postUrl    = esc_url_raw( (string) ( $meta['post_url'] ?? '' ) );

		// Fallback author name detection if not supplied
		if ( '' === $authorName ) {
			if ( preg_match( '/^([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\b/m', $raw, $m ) ) {
				$authorName = $m[1];
			} else {
				$authorName = 'Tech Lead / Founder';
			}
		}

		// Detect City / Location
		$location = 'India / Global';
		if ( preg_match( '/\b(Delhi|Delhi NCR|Noida|Gurgaon|Gurugram)\b/i', $raw, $m ) ) {
			$location = 'Delhi NCR';
		} elseif ( preg_match( '/\b(Bengaluru|Bangalore)\b/i', $raw ) ) {
			$location = 'Bengaluru';
		} elseif ( preg_match( '/\b(Mumbai|Navi Mumbai|Pune)\b/i', $raw ) ) {
			$location = 'Mumbai / Pune';
		} elseif ( preg_match( '/\b(Hyderabad|Chennai)\b/i', $raw, $m ) ) {
			$location = ucfirst( strtolower( $m[1] ) );
		} elseif ( preg_match( '/\b(USA|US|UK|UAE|Dubai|Singapore|Europe)\b/i', $raw, $m ) ) {
			$location = strtoupper( $m[1] );
		}

		// Detect Role / Service Needed (VM Studio core competencies)
		$role = 'Custom Web Architecture & Enterprise AI Automation';
		if ( preg_match( '/\b(?:web\s*developer|web\s*development|next\.?js|react|frontend|custom\s*website|web\s*app|saas\s*portal|wordpress\s*custom)\b/i', $raw ) ) {
			$role = 'Sovereign Custom Web Architecture & Next.js Portal';
		} elseif ( preg_match( '/\b(?:telephony|calling\s*agent|voice\s*ai|ai\s*caller|superfone|vapi|retell|inbound\s*calls?|cold\s*calling)\b/i', $raw ) ) {
			$role = 'Autonomous AI Voice Telephony & Calling Agent';
		} elseif ( preg_match( '/\b(?:whatsapp\s*crm|whatsapp\s*automation|lead\s*routing|crm\s*pipeline|funnel\s*automation|hubspot\s*alternative)\b/i', $raw ) ) {
			$role = 'Enterprise WhatsApp CRM & Funnel Automation';
		} elseif ( preg_match( '/\b(?:seo\s*agency|seo\s*expert|organic\s*traffic|content\s*engine|growth\s*audit)\b/i', $raw ) ) {
			$role = 'High-Performance SEO & Autonomous Content Engine';
		}

		// Detect Project Scope / Category
		$eventType = 'Enterprise Digital Acceleration';
		if ( preg_match( '/\b(?:mvp|rebuild|portal|platform|dashboard|saas)\b/i', $raw ) ) {
			$eventType = 'SaaS Portal & Custom Web Application';
		} elseif ( preg_match( '/\b(?:voice|phone|calling|appointment\s*setting)\b/i', $raw ) ) {
			$eventType = 'Autonomous AI Voice Telephony & CRM';
		} elseif ( preg_match( '/\b(?:whatsapp|messaging|lead\s*qualification)\b/i', $raw ) ) {
			$eventType = 'Enterprise WhatsApp CRM Automation';
		} elseif ( preg_match( '/\b(?:seo|traffic|rankings|organic)\b/i', $raw ) ) {
			$eventType = 'Autonomous SEO & Inbound Lead Engine';
		}

		// Detect Timeline / Dates
		$dates = 'Immediate / Q4';
		if ( preg_match( '/\b(immediately|urgent|asap|next\s+week|this\s+month|q4|q1)\b/i', $raw, $m ) ) {
			$dates = ucfirst( strtolower( $m[1] ) );
		}

		// Calculate Intent Score (0 - 100)
		$score = 80;
		if ( preg_match( '/\b(DM|direct message|looking for|hiring|agency|developer|partner with us|recommendations?|budget)\b/i', $raw ) ) {
			$score += 15;
		}
		if ( 'India / Global' !== $location ) {
			$score += 4;
		}
		$score = min( 99, $score );

		return [
			'poster_name'        => $authorName,
			'poster_headline'    => $headline ?: 'Technology Decision Maker',
			'poster_profile_url' => $profileUrl,
			'post_url'           => $postUrl,
			'event_role_needed'  => $role,
			'event_type'         => $eventType,
			'event_dates'        => $dates,
			'event_location'     => $location,
			'raw_snippet'        => mb_substr( $raw, 0, 500 ),
			'intent_score'       => $score,
			'urgency'            => ( 'Immediate' === $dates || 'Asap' === $dates ) ? 'HIGH' : 'MEDIUM',
			'source'             => 'linkedin_radar',
		];
	}

	/**
	 * Extract attributes with LLM for nuanced understanding and zero false positives.
	 */
	private function extractByAi( string $raw, array $baseline ): array {
		if ( ! $this->ai ) {
			return [];
		}

		$prompt = <<<PROMPT
You are an expert B2B sales intelligence agent for VM Studio (Specializing in Sovereign Custom Web Architecture, Next.js, Autonomous AI Voice Telephony & Calling Agents, and Enterprise WhatsApp CRM).
Analyze this LinkedIn post and output a strict JSON object with these keys:
{
  "poster_name": "Full name or best guess",
  "poster_headline": "Job title or headline",
  "event_role_needed": "Exact service needed (e.g. Sovereign Custom Web Architecture, Autonomous AI Voice Telephony, Enterprise WhatsApp CRM, AI SEO Growth)",
  "event_type": "Project category (e.g. SaaS Web App Rebuild, AI Voice Telephony & Lead Qualification, WhatsApp CRM Funnel)",
  "event_dates": "Timeline or 'Immediate'",
  "event_location": "Target city/region or 'India / Global'",
  "intent_score": Integer 0-100 indicating buyer seriousness,
  "recommended_pitch": "A 2-3 sentence ultra-crisp, professional LinkedIn DM pitch addressing them by first name, citing their specific requirements and offering a live demo or technical overview without sounding like spam"
}

LinkedIn Post Content:
{$raw}

Output ONLY valid JSON.
PROMPT;

		$req = new ChatRequest(
			messages: [
				new Message( 'user', $prompt ),
			],
			temperature: 0.2
		);

		$resp = $this->ai->chat( $req );
		$body = trim( (string) $resp->message->content );

		// Clean JSON code fences
		$body = preg_replace( '/^```(?:json)?/i', '', $body ) ?? $body;
		$body = preg_replace( '/```$/i', '', $body ) ?? $body;
		$body = trim( $body );

		$decoded = json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Generate a high-converting personalized pitch based on role and angle.
	 */
	public function generateDefaultPitch( array $lead, string $angle = 'agency' ): string {
		$firstName = explode( ' ', (string) ( $lead['poster_name'] ?? 'there' ) )[0];
		$role      = (string) ( $lead['event_role_needed'] ?? 'Custom Web Architecture' );
		$location  = (string) ( $lead['event_location'] ?? 'your region' );
		$eventType = (string) ( $lead['event_type'] ?? 'digital architecture' );

		// 1. AI Telephony / Calling Agent Pitch
		if ( 'telephony' === $angle || str_contains( strtolower( $role ), 'telephony' ) || str_contains( strtolower( $role ), 'voice' ) ) {
			return "Hi {$firstName}, reaching out regarding your requirement for autonomous voice telephony. At VM Studio, we engineer sub-second AI voice calling agents that qualify inbound leads 24/7 and route priority bookings into WhatsApp CRM. Happy to share our 1-page architecture overview and a live demo.";
		}

		// 2. WhatsApp CRM / Automation Pitch
		if ( 'crm' === $angle || str_contains( strtolower( $role ), 'crm' ) || str_contains( strtolower( $role ), 'whatsapp' ) ) {
			return "Hi {$firstName}, saw your post regarding CRM and lead management. At VM Studio, our sovereign Sales OS CRM integrates enterprise WhatsApp automation, multi-agent chat, and real-time lead qualification. Would love to send a quick walkthrough of our system.";
		}

		// 3. Web Architecture / Next.js / SaaS Pitch (Default)
		return "Hi {$firstName}, saw your requirement for {$role}. At VM Studio, we specialize in sovereign custom web architecture, ultra-fast Next.js applications, and scalable digital infrastructure. Would love to share our technical credentials and recent case studies. Can I send that across to your email?";
	}
}