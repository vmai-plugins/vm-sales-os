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
				$authorName = 'Event Host';
			}
		}

		// Detect City / Location
		$location = 'India';
		if ( preg_match( '/\b(Delhi|Delhi NCR|Noida|Gurgaon|Gurugram|CP|Connaught Place)\b/i', $raw, $m ) ) {
			$location = 'Delhi NCR';
		} elseif ( preg_match( '/\b(Bengaluru|Bangalore)\b/i', $raw ) ) {
			$location = 'Bengaluru';
		} elseif ( preg_match( '/\b(Mumbai|Navi Mumbai|Thane)\b/i', $raw ) ) {
			$location = 'Mumbai';
		} elseif ( preg_match( '/\b(Chennai)\b/i', $raw ) ) {
			$location = 'Chennai';
		} elseif ( preg_match( '/\b(Hyderabad)\b/i', $raw ) ) {
			$location = 'Hyderabad';
		} elseif ( preg_match( '/\b(Goa|Jaipur|Udaipur|Pune|Kolkata)\b/i', $raw, $m ) ) {
			$location = ucfirst( strtolower( $m[1] ) );
		}

		// Detect Role / Requirement Type
		$role = 'Event Management & Execution';
		if ( preg_match( '/\b(?:freelance\s+)?event\s+coordinator\b/i', $raw ) ) {
			$role = 'Freelance Event Coordinator';
		} elseif ( preg_match( '/\b(?:freelance\s+)?event\s+manager\b/i', $raw ) ) {
			$role = 'Freelance Event Manager';
		} elseif ( preg_match( '/\b(?:corporate\s+event\s+planner|corporate\s+meetups?|global\s+company\s+meetups?)\b/i', $raw ) ) {
			$role = 'Corporate Event Agency / Planner';
		} elseif ( preg_match( '/\b(?:production\s+agency|exhibition\s+stand|brand\s+activations?|trade\s+shows?)\b/i', $raw ) ) {
			$role = 'Event Production & Fabrication Agency';
		} elseif ( preg_match( '/\bluxury\s+(?:brand\s+)?launch\b/i', $raw ) ) {
			$role = 'Luxury Event Producer';
		}

		// Detect Event Type
		$eventType = 'Corporate Event';
		if ( preg_match( '/\b(?:meetup|retreat|offsite|ted-style|speaker\s+session)\b/i', $raw ) ) {
			$eventType = 'Corporate Meetup & Offsite';
		} elseif ( preg_match( '/\b(?:luxury|brand\s+launch)\b/i', $raw ) ) {
			$eventType = 'Luxury Brand Launch';
		} elseif ( preg_match( '/\b(?:conference|trade\s+show|exhibition)\b/i', $raw ) ) {
			$eventType = 'Conference & Trade Show';
		} elseif ( preg_match( '/\b(?:on-ground|2-day|activation)\b/i', $raw ) ) {
			$eventType = 'On-Ground Brand Activation';
		}

		// Detect Dates
		$dates = 'Upcoming';
		if ( preg_match( '/\b(\d{1,2}(?:st|nd|rd|th)?(?:\s*&?\s*\d{1,2}(?:st|nd|rd|th)?)?\s+(?:January|February|March|April|May|June|July|August|September|October|November|December)(?:\s+\d{4})?)\b/i', $raw, $m ) ) {
			$dates = $m[1];
		}

		// Calculate Intent Score (0 - 100)
		$score = 75; // baseline for relevant post
		if ( preg_match( '/\b(DM|direct message|looking for|requirement|partner with us|connect)\b/i', $raw ) ) {
			$score += 15;
		}
		if ( 'Upcoming' !== $dates ) {
			$score += 5; // Has specific dates
		}
		if ( 'India' !== $location ) {
			$score += 5; // Has specific city
		}
		$score = min( 99, $score );

		return [
			'poster_name'        => $authorName,
			'poster_headline'    => $headline ?: 'Event Decision Maker',
			'poster_profile_url' => $profileUrl,
			'post_url'           => $postUrl,
			'event_role_needed'  => $role,
			'event_type'         => $eventType,
			'event_dates'        => $dates,
			'event_location'     => $location,
			'raw_snippet'        => mb_substr( $raw, 0, 500 ),
			'intent_score'       => $score,
			'urgency'            => ( 'Upcoming' !== $dates ) ? 'HIGH' : 'MEDIUM',
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
You are an expert B2B sales intelligence agent for an Event Management, Production & Corporate MICE Agency.
Analyze this LinkedIn post and output a strict JSON object with these keys:
{
  "poster_name": "Full name or best guess",
  "poster_headline": "Job title/headline if mentioned",
  "event_role_needed": "Exact service or role they are seeking (e.g. Corporate Event Agency, Freelance Coordinator, Exhibition Fabricator)",
  "event_type": "Event type (e.g. Global Corporate Meetup, Luxury Brand Launch, Trade Show)",
  "event_dates": "Extracted dates or 'Upcoming'",
  "event_location": "Target city/region or 'India'",
  "intent_score": Integer 0-100 indicating buyer seriousness,
  "recommended_pitch": "A 2-3 sentence ultra-crisp, professional LinkedIn DM pitch addressing them by first name, citing their specific requirements and offering a portfolio/call without being spammy"
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
		$role      = (string) ( $lead['event_role_needed'] ?? 'event planning' );
		$location  = (string) ( $lead['event_location'] ?? 'your location' );
		$eventType = (string) ( $lead['event_type'] ?? 'upcoming event' );
		$dates     = (string) ( $lead['event_dates'] ?? '' );

		if ( 'coordinator' === $angle || str_contains( strtolower( $role ), 'coordinator' ) || str_contains( strtolower( $role ), 'freelance' ) ) {
			$dateRef = ( $dates && 'Upcoming' !== $dates ) ? " on {$dates}" : '';
			return "Hi {$firstName}, saw your requirement for a {$role} in {$location}{$dateRef}. Our team handles on-ground event management, VIP delegation, and venue fabrication across {$location}. Would love to share our event deck and recent execution portfolio with you. Can I send that across?";
		}

		if ( 'luxury' === $angle || str_contains( strtolower( $eventType ), 'luxury' ) ) {
			return "Hi {$firstName}, reaching out regarding your {$eventType} in {$location}. We specialize in high-touch brand activations, curated stage design, and seamless on-ground production. Happy to share our luxury event credentials and case studies. Would you be open to a quick 5-min connect?";
		}

		// Default Corporate Agency / MICE Pitch
		return "Hi {$firstName}, saw your post seeking an agency for {$eventType} in {$location}. We manage end-to-end corporate gatherings, flight/hotel logistics, speaker sessions, and custom booth fabrication across India & globally. Would love to share a tailored proposal. What's the best email to send our credential deck?";
	}
}
