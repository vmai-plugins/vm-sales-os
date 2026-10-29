<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\LinkedInRadar;

use VMStudio\SalesOS\Modules\CRM\ContactRepository;
use VMStudio\SalesOS\Modules\CRM\DealRepository;
use VMStudio\SalesOS\Modules\CRM\ActivityRepository;
use VMStudio\SalesOS\Support\Logger;
use wpdb;

final class LinkedInRadarRepository {

	private const OPTION_KEY = 'vmsos_linkedin_radar_leads';

	private wpdb $db;
	private ?ContactRepository $contacts;
	private ?DealRepository $deals;
	private ?ActivityRepository $activities;
	private ?Logger $logger;

	public function __construct(
		?ContactRepository $contacts = null,
		?DealRepository $deals = null,
		?ActivityRepository $activities = null,
		?Logger $logger = null
	) {
		global $wpdb;
		$this->db         = $wpdb;
		$this->contacts   = $contacts;
		$this->deals      = $deals;
		$this->activities = $activities;
		$this->logger     = $logger;
	}

	/**
	 * Get all stored LinkedIn radar leads.
	 *
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function list( array $filters = [] ): array {
		$leads = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $leads ) ) {
			$leads = [];
		}

		// Preload initial sample leads if none exist yet, matching user's real screenshots
		if ( empty( $leads ) ) {
			$leads = $this->seedInitialLeads();
			update_option( self::OPTION_KEY, $leads, false );
		}

		// Apply filters
		$city       = strtolower( (string) ( $filters['city'] ?? '' ) );
		$status     = (string) ( $filters['status'] ?? '' );
		$eventType  = strtolower( (string) ( $filters['event_type'] ?? '' ) );
		$minScore   = (int) ( $filters['min_score'] ?? 0 );

		$filtered = array_filter(
			$leads,
			static function ( array $l ) use ( $city, $status, $eventType, $minScore ): bool {
				if ( '' !== $city && ! str_contains( strtolower( (string) ( $l['event_location'] ?? '' ) ), $city ) ) {
					return false;
				}
				if ( '' !== $status && ( $l['status'] ?? 'new' ) !== $status ) {
					return false;
				}
				if ( '' !== $eventType && ! str_contains( strtolower( (string) ( $l['event_type'] ?? '' ) ), $eventType ) ) {
					return false;
				}
				if ( $minScore > 0 && (int) ( $l['intent_score'] ?? 0 ) < $minScore ) {
					return false;
				}
				return true;
			}
		);

		// Sort by intent score descending, then created_at descending
		usort(
			$filtered,
			static function ( array $a, array $b ): int {
				$scoreDiff = ( (int) ( $b['intent_score'] ?? 0 ) ) <=> ( (int) ( $a['intent_score'] ?? 0 ) );
				if ( 0 !== $scoreDiff ) {
					return $scoreDiff;
				}
				return strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) );
			}
		);

		return array_values( $filtered );
	}

	/**
	 * Find lead by ID.
	 */
	public function find( string $id ): ?array {
		$leads = $this->list();
		foreach ( $leads as $l ) {
			if ( (string) ( $l['id'] ?? '' ) === $id ) {
				return $l;
			}
		}
		return null;
	}

	/**
	 * Save / ingest a new radar lead.
	 */
	public function save( array $lead ): array {
		$leads = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $leads ) ) {
			$leads = [];
		}

		$id = ! empty( $lead['id'] ) ? (string) $lead['id'] : 'li_' . wp_generate_password( 10, false );
		$lead['id']         = $id;
		$lead['created_at'] = $lead['created_at'] ?? current_time( 'mysql' );
		$lead['status']     = $lead['status'] ?? 'new';

		// Sync with core CRM contacts and deals if repositories are available
		$contactId = $this->syncToCrm( $lead );
		if ( $contactId > 0 ) {
			$lead['crm_contact_id'] = $contactId;
		}

		// Update or insert
		$found = false;
		foreach ( $leads as $idx => $existing ) {
			if ( ( $existing['id'] ?? '' ) === $id ) {
				$leads[ $idx ] = array_merge( $existing, $lead );
				$found         = true;
				break;
			}
		}
		if ( ! $found ) {
			array_unshift( $leads, $lead );
		}

		update_option( self::OPTION_KEY, $leads, false );
		return $lead;
	}

	/**
	 * Update lead status (e.g. contacted, replied, converted).
	 */
	public function updateStatus( string $id, string $status, ?string $note = null ): ?array {
		$leads = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $leads ) ) {
			return null;
		}

		$updated = null;
		foreach ( $leads as $idx => $lead ) {
			if ( ( $lead['id'] ?? '' ) === $id ) {
				$leads[ $idx ]['status']       = $status;
				$leads[ $idx ]['updated_at']   = current_time( 'mysql' );
				if ( null !== $note ) {
					$leads[ $idx ]['last_note'] = $note;
				}
				$updated = $leads[ $idx ];
				break;
			}
		}

		if ( $updated ) {
			update_option( self::OPTION_KEY, $leads, false );
			if ( $this->activities && ! empty( $updated['crm_contact_id'] ) ) {
				$this->activities->record(
					(int) $updated['crm_contact_id'],
					'outreach_' . $status,
					$note ?: sprintf( 'LinkedIn outreach status updated to %s', $status )
				);
			}
		}

		return $updated;
	}

	/**
	 * Bridge to CRM: upsert contact and open a deal.
	 */
	private function syncToCrm( array $lead ): int {
		if ( ! $this->contacts ) {
			return 0;
		}

		$nameParts = explode( ' ', (string) ( $lead['poster_name'] ?? 'LinkedIn Lead' ), 2 );
		$firstName = $nameParts[0] ?? 'LinkedIn';
		$lastName  = $nameParts[1] ?? 'Lead';

		$contactId = $this->contacts->upsert(
			[
				'first_name' => $firstName,
				'last_name'  => $lastName,
				'email'      => '',
				'phone'      => '',
				'source'     => 'linkedin_radar',
				'score'      => (int) ( $lead['intent_score'] ?? 80 ),
				'tags'       => [ 'linkedin-intent', 'event-lead', (string) ( $lead['event_location'] ?? 'india' ) ],
				'meta'       => [
					'profile_url' => $lead['poster_profile_url'] ?? '',
					'post_url'    => $lead['post_url'] ?? '',
					'headline'    => $lead['poster_headline'] ?? '',
					'event_type'  => $lead['event_type'] ?? '',
					'event_dates' => $lead['event_dates'] ?? '',
				],
			]
		);

		return $contactId;
	}

	/**
	 * Pre-seed realistic leads matching user's real LinkedIn screenshots for immediate live testing.
	 */
	private function seedInitialLeads(): array {
		return [
			[
				'id'                 => 'li_neha_u',
				'poster_name'        => 'Neha U',
				'poster_headline'    => 'Global Recruitment and HR Operations Leadership | Business-Aligned',
				'poster_profile_url' => 'https://www.linkedin.com/in/neha-u-events',
				'post_url'           => 'https://www.linkedin.com/feed/update/urn:li:activity:7256192837192',
				'event_role_needed'  => 'Corporate Event Planner / Agency',
				'event_type'         => 'Global Company Meetups & Retreats',
				'event_dates'        => 'Q4 2026',
				'event_location'     => 'India & International',
				'raw_snippet'        => 'I am looking for an experienced corporate event planner or agency that can organize global company meetups for both in India and internationally. Scope: end-to-end event planning, venue and travel coordination, team engagement, TED-style talks. Please DM or share details.',
				'intent_score'       => 98,
				'urgency'            => 'HIGH',
				'status'             => 'new',
				'source'             => 'linkedin_radar',
				'created_at'         => current_time( 'mysql' ),
				'recommended_pitch'  => 'Hi Neha, saw your post seeking an agency partner for global company meetups. At TripCosmos / VM Studio, we manage end-to-end corporate offsites, flight/hotel logistics, speaker curation, and experiential activities across India & Southeast Asia. Would love to share our MICE deck. What is the best email to send across our corporate credentials?',
			],
			[
				'id'                 => 'li_dwayne_x',
				'poster_name'        => 'Dwayne X.',
				'poster_headline'    => 'Marketing & Strategy | Enabling Global Brands in India',
				'poster_profile_url' => 'https://www.linkedin.com/in/dwayne-x-strategy',
				'post_url'           => 'https://www.linkedin.com/feed/update/urn:li:activity:7256182910283',
				'event_role_needed'  => 'Event Production & Fabrication Agency',
				'event_type'         => 'Conferences, Trade Shows & Brand Activations',
				'event_dates'        => 'November 2026',
				'event_location'     => 'Chennai, Bengaluru, Hyderabad',
				'raw_snippet'        => 'Looking to connect with an event management and production agency based in Chennai, Bengaluru, or Hyderabad to partner with us on conferences, trade shows, and brand activations across South India. Scope: stand design, fabrication, branding, logistics. Please DM me.',
				'intent_score'       => 96,
				'urgency'            => 'HIGH',
				'status'             => 'new',
				'source'             => 'linkedin_radar',
				'created_at'         => date( 'Y-m-d H:i:s', time() - 3600 * 2 ),
				'recommended_pitch'  => 'Hi Dwayne, reaching out regarding your requirement for an event production & fabrication partner in South India (BLR/MAA/HYD). We own complete in-house fabrication, modular booth design, and on-ground technical crew for tech conferences and trade expos. Can I send you a 1-page overview of our recent South India setups?',
			],
			[
				'id'                 => 'li_shriyesh_s',
				'poster_name'        => 'Shriyesh Srivastava',
				'poster_headline'    => 'Founder & Director, Manikarnika Events | Pan-India Event Production',
				'poster_profile_url' => 'https://www.linkedin.com/in/shriyesh-srivastava',
				'post_url'           => 'https://www.linkedin.com/feed/update/urn:li:activity:7256149182910',
				'event_role_needed'  => 'Freelance Event Coordinator',
				'event_type'         => '2-Day Corporate Event',
				'event_dates'        => '7th & 8th October 2026',
				'event_location'     => 'Delhi NCR',
				'raw_snippet'        => 'REQUIREMENT – FREELANCE EVENT COORDINATOR | DELHI NCR. We are looking for an experienced Freelance Event Coordinator for a 2-day event in Delhi. Dates: 7th & 8th Oct. Responsibilities: On-ground coordination, vendor & manpower management. Interested candidates can DM.',
				'intent_score'       => 92,
				'urgency'            => 'HIGH',
				'status'             => 'new',
				'source'             => 'linkedin_radar',
				'created_at'         => date( 'Y-m-d H:i:s', time() - 3600 * 5 ),
				'recommended_pitch'  => 'Hi Shriyesh, saw your requirement for an on-ground Event Coordinator for the 2-day Delhi event on Oct 7-8. Our seasoned Delhi NCR production supervisors handle venue management, vendor logistics, and crisis resolution. Would be delighted to support Manikarnika Events. Can I share our coordinator profiles and day-rates?',
			],
			[
				'id'                 => 'li_arushi_r',
				'poster_name'        => 'Arushi Reniwal',
				'poster_headline'    => 'Handling Human Resources at Warehouse47 | Talent Acquisition',
				'poster_profile_url' => 'https://www.linkedin.com/in/arushi-reniwal',
				'post_url'           => 'https://www.linkedin.com/feed/update/urn:li:activity:7256110293812',
				'event_role_needed'  => 'Freelance Event Manager',
				'event_type'         => 'Luxury Brand Launch',
				'event_dates'        => '31st October 2026',
				'event_location'     => 'CP, Delhi',
				'raw_snippet'        => 'We are Hiring | Freelance Event Manager | Delhi. Warehouse47 is Looking For A Freelance Event Manager For An Upcoming Luxury Brand Launch. Date: 31st October 2026. Location: CP, Delhi. Experience in luxury events and on-ground execution preferred. DM your profile.',
				'intent_score'       => 94,
				'urgency'            => 'HIGH',
				'status'             => 'new',
				'source'             => 'linkedin_radar',
				'created_at'         => date( 'Y-m-d H:i:s', time() - 3600 * 8 ),
				'recommended_pitch'  => 'Hi Arushi, reaching out regarding the Luxury Brand Launch on Oct 31st at Connaught Place. We specialize in luxury hospitality protocols, VIP guest flow, lighting ambiance, and flawless brand execution in Central Delhi. Happy to share our portfolio of premium brand launches. Would love to connect over DM!',
			],
		];
	}
}
