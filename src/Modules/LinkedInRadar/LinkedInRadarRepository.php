<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\LinkedInRadar;

use VMStudio\SalesOS\Modules\CRM\ContactRepository;
use VMStudio\SalesOS\Modules\CRM\DealRepository;
use VMStudio\SalesOS\Modules\CRM\ActivityRepository;
use VMStudio\SalesOS\Support\Logger;
use wpdb;

final class LinkedInRadarRepository {

	public const OPTION_KEY = 'vmsos_linkedin_radar_leads';

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
	 * Get all stored LinkedIn radar and inbound leads.
	 *
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function list( array $filters = [] ): array {
		$leads = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $leads ) ) {
			$leads = [];
		}

		// Preload initial sample leads if none exist yet
		if ( empty( $leads ) ) {
			$leads = $this->seedInitialLeads();
			update_option( self::OPTION_KEY, $leads, false );
		}

		// Apply filters
		$city      = strtolower( (string) ( $filters['city'] ?? '' ) );
		$status    = (string) ( $filters['status'] ?? '' );
		$eventType = strtolower( (string) ( $filters['event_type'] ?? '' ) );
		$minScore  = (int) ( $filters['min_score'] ?? 0 );

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

		// Sort by created_at descending
		usort(
			$filtered,
			static function ( array $a, array $b ): int {
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
	 * Save / ingest a new LinkedIn inbound lead or radar lead.
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

		// Preserve already generated CRM IDs to prevent duplicate deals & forms
		foreach ( $leads as $existing ) {
			if ( ( $existing['id'] ?? '' ) === $id ) {
				if ( ! empty( $existing['crm_contact_id'] ) && empty( $lead['crm_contact_id'] ) ) {
					$lead['crm_contact_id'] = $existing['crm_contact_id'];
				}
				if ( ! empty( $existing['crm_deal_id'] ) && empty( $lead['crm_deal_id'] ) ) {
					$lead['crm_deal_id'] = $existing['crm_deal_id'];
				}
				if ( ! empty( $existing['fluentform_submission_id'] ) && empty( $lead['fluentform_submission_id'] ) ) {
					$lead['fluentform_submission_id'] = $existing['fluentform_submission_id'];
				}
				if ( ! empty( $existing['crm_activity_logged'] ) ) {
					$lead['crm_activity_logged'] = 1;
				}
				break;
			}
		}

		// Normalise lead fields for unified display & mobile bridge
		$lead['poster_name']        = (string) ( $lead['poster_name'] ?? $lead['name'] ?? $lead['full_name'] ?? 'LinkedIn Lead' );
		$lead['name']               = $lead['poster_name'];
		$lead['poster_headline']    = (string) ( $lead['poster_headline'] ?? $lead['headline'] ?? $lead['title'] ?? $lead['job_title'] ?? '' );
		$lead['headline']           = $lead['poster_headline'];
		$lead['poster_profile_url'] = (string) ( $lead['poster_profile_url'] ?? $lead['profile_url'] ?? $lead['linkedin_url'] ?? '' );
		$lead['profile_url']        = $lead['poster_profile_url'];
		$lead['company']            = (string) ( $lead['company'] ?? $lead['company_name'] ?? $lead['organization'] ?? '' );
		$lead['email']              = (string) ( $lead['email'] ?? $lead['poster_email'] ?? $lead['email_address'] ?? '' );
		$lead['phone']              = (string) ( $lead['phone'] ?? $lead['poster_phone'] ?? $lead['phone_number'] ?? $lead['mobile'] ?? '' );
		$lead['raw_snippet']        = (string) ( $lead['raw_snippet'] ?? $lead['notes'] ?? $lead['message'] ?? $lead['requirements'] ?? $lead['inquiry'] ?? '' );
		$lead['intent_score']       = (int) ( $lead['intent_score'] ?? 88 );
		$lead['source']             = (string) ( $lead['source'] ?? 'linkedin' );

		// Sync with core CRM contacts, deals, activities, and Fluent Forms
		$contactId = $this->syncToCrm( $lead );
		if ( $contactId > 0 ) {
			$lead['crm_contact_id'] = $contactId;
		}

		// Update or insert in repository memory
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

		if ( count( $leads ) > 250 ) {
			$leads = array_slice( $leads, 0, 250 );
		}

		update_option( self::OPTION_KEY, $leads, false );
		return $lead;
	}

	/**
	 * Update lead status (e.g. contacted, replied, converted, qualified).
	 */
	public function updateStatus( string $id, string $status, ?string $note = null ): ?array {
		$leads = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $leads ) ) {
			return null;
		}

		$updated = null;
		foreach ( $leads as $idx => $lead ) {
			if ( ( $lead['id'] ?? '' ) === $id ) {
				$leads[ $idx ]['status']     = $status;
				$leads[ $idx ]['updated_at'] = current_time( 'mysql' );
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
				$this->activities->log(
					'contact',
					(int) $updated['crm_contact_id'],
					'outreach_' . sanitize_key( $status ),
					$note ?: sprintf( 'LinkedIn outreach status updated to %s', $status )
				);
			}
		}

		return $updated;
	}

	/**
	 * Bridge to CRM: upsert contact, create deal, log activity, and mirror to Fluent Forms.
	 */
	private function syncToCrm( array &$lead ): int {
		if ( ! $this->contacts ) {
			return 0;
		}

		$fullName  = trim( (string) ( $lead['poster_name'] ?? $lead['name'] ?? 'LinkedIn Lead' ) );
		$nameParts = explode( ' ', $fullName, 2 );
		$firstName = $nameParts[0] ?? 'LinkedIn';
		$lastName  = $nameParts[1] ?? 'Lead';

		$email      = sanitize_email( (string) ( $lead['email'] ?? '' ) );
		$phone      = ContactRepository::normalisePhone( (string) ( $lead['phone'] ?? '' ) );
		$company    = sanitize_text_field( (string) ( $lead['company'] ?? '' ) );
		$headline   = sanitize_text_field( (string) ( $lead['poster_headline'] ?? '' ) );
		$profileUrl = esc_url_raw( (string) ( $lead['poster_profile_url'] ?? '' ) );
		$postUrl    = esc_url_raw( (string) ( $lead['post_url'] ?? '' ) );
		$notes      = sanitize_textarea_field( (string) ( $lead['raw_snippet'] ?? '' ) );
		$score      = (int) ( $lead['intent_score'] ?? 85 );

		$contactId = $this->contacts->upsert(
			[
				'first_name'   => $firstName,
				'last_name'    => $lastName,
				'email'        => $email,
				'phone'        => $phone,
				'source'       => 'linkedin',
				'score'        => $score,
				'requirements' => $notes ?: sprintf( '%s at %s', $headline, $company ),
				'tags'         => [ 'linkedin', 'linkedin-leads', (string) ( $lead['event_location'] ?? 'inbound' ) ],
				'meta'         => [
					'profile_url' => $profileUrl,
					'post_url'    => $postUrl,
					'headline'    => $headline,
					'company'     => $company,
				],
			]
		);

		if ( $contactId <= 0 ) {
			return 0;
		}

		// 2. Open a Deal in pipeline only once if enabled and not already created
		if ( empty( $lead['crm_deal_id'] ) && $this->deals && get_option( 'vmsos_linkedin_create_deal', true ) ) {
			$dealTitle = sprintf( 'LinkedIn: %s%s', $fullName, $company ? " ({$company})" : '' );
			$dealValue = (float) get_option( 'vmsos_linkedin_default_deal_value', 25000 );
			$dealId    = $this->deals->create(
				[
					'contact_id'  => $contactId,
					'title'       => $dealTitle,
					'stage'       => 'new',
					'value'       => $dealValue,
					'currency'    => 'INR',
					'probability' => 40,
				]
			);
			if ( $dealId > 0 ) {
				$lead['crm_deal_id'] = $dealId;
			}
		}

		// 3. Record Activity once on initial ingestion
		if ( empty( $lead['crm_activity_logged'] ) && $this->activities ) {
			$this->activities->log(
				'contact',
				$contactId,
				'linkedin_lead_captured',
				sprintf( 'LinkedIn lead %s (%s) ingested into Sales OS CRM & Mobile Bridge', $fullName, $company ?: 'Direct' )
			);
			$lead['crm_activity_logged'] = 1;
		}

		// 4. Mirror to Fluent Forms only once
		if ( empty( $lead['fluentform_submission_id'] ) ) {
			$submissionId = $this->syncToFluentForms( $lead );
			if ( $submissionId > 0 ) {
				$lead['fluentform_submission_id'] = $submissionId;
			}
		}

		return $contactId;
	}

	/**
	 * Mirror lead to Fluent Forms submissions table if installed and enabled.
	 */
	public function syncToFluentForms( array $lead ): int {
		if ( ! get_option( 'vmsos_linkedin_sync_fluentform', true ) ) {
			return 0;
		}

		$tableName = $this->db->prefix . 'fluentform_submissions';
		if ( $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $tableName ) ) !== $tableName ) {
			return 0;
		}

		$formId = (int) get_option( 'vmsos_linkedin_target_form_id', 1 );
		if ( $formId <= 0 ) {
			$formId = 1;
		}

		$fullName  = trim( (string) ( $lead['name'] ?? $lead['poster_name'] ?? 'LinkedIn Lead' ) );
		$nameParts = explode( ' ', $fullName, 2 );
		$firstName = $nameParts[0] ?? 'LinkedIn';
		$lastName  = $nameParts[1] ?? 'Lead';
		$email     = sanitize_email( (string) ( $lead['email'] ?? '' ) );
		$phone     = (string) ( $lead['phone'] ?? '' );
		$company   = (string) ( $lead['company'] ?? '' );
		$message   = (string) ( $lead['raw_snippet'] ?? $lead['notes'] ?? 'Imported LinkedIn Lead' );
		$profile   = (string) ( $lead['profile_url'] ?? $lead['poster_profile_url'] ?? '' );

		$response = [
			'names'            => [
				'first_name' => $firstName,
				'last_name'  => $lastName,
			],
			'name'             => $fullName,
			'email'            => $email,
			'phone'            => $phone,
			'company'          => $company,
			'message'          => $message,
			'linkedin_profile' => $profile,
			'source'           => 'linkedin',
		];

		$serialNumber = ( (int) $this->db->get_var(
			$this->db->prepare( "SELECT MAX(serial_number) FROM {$tableName} WHERE form_id = %d", $formId )
		) ) + 1;

		$now      = current_time( 'mysql' );
		$inserted = $this->db->insert(
			$tableName,
			[
				'form_id'       => $formId,
				'serial_number' => $serialNumber,
				'response'      => wp_json_encode( $response ),
				'source_url'    => ! empty( $profile ) ? mb_substr( $profile, 0, 250 ) : 'https://www.linkedin.com',
				'user_id'       => get_current_user_id() ?: 0,
				'status'        => 'unread',
				'is_favourite'  => 0,
				'browser'       => 'SalesOS-LinkedIn-Bridge',
				'device'        => 'Server/API',
				'ip'            => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ),
				'created_at'    => $now,
				'updated_at'    => $now,
			],
			[ '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return 0;
		}

		$submissionId = (int) $this->db->insert_id;

		// Entry details table
		$detailsTable = $this->db->prefix . 'fluentform_entry_details';
		if ( $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $detailsTable ) ) === $detailsTable ) {
			$fields = [
				'names'            => wp_json_encode( $response['names'] ),
				'email'            => $email,
				'phone'            => $phone,
				'company'          => $company,
				'message'          => $message,
				'linkedin_profile' => $profile,
			];
			foreach ( $fields as $key => $val ) {
				if ( '' !== $val && null !== $val ) {
					$this->db->insert(
						$detailsTable,
						[
							'form_id'       => $formId,
							'submission_id' => $submissionId,
							'field_name'    => $key,
							'field_value'   => $val,
						],
						[ '%d', '%d', '%s', '%s' ]
					);
				}
			}
		}

		// Fire fluentform hook so third-party listeners or CRM sync know about it
		$formObj = null;
		if ( function_exists( 'wpFluent' ) ) {
			try {
				$formObj = wpFluent()->table( 'fluentform_forms' )->find( $formId );
			} catch ( \Throwable ) {
				$formObj = null;
			}
		}
		if ( ! $formObj ) {
			$formObj = (object) [
				'id'          => $formId,
				'title'       => 'LinkedIn Lead Form',
				'form_fields' => '[]',
			];
		}
		do_action( 'fluentform/submission_inserted', $submissionId, $response, $formObj );

		return $submissionId;
	}

	/**
	 * Pre-seed realistic leads matching user's real LinkedIn screenshots for immediate live testing.
	 */
	private function seedInitialLeads(): array {
		return [
			[
				'id'                 => 'li_pooja_m',
				'poster_name'        => 'Pooja Malhotra',
				'name'               => 'Pooja Malhotra',
				'poster_headline'    => 'VP Digital & Technology, CloudScale SaaS | Enterprise Architecture',
				'headline'           => 'VP Digital & Technology, CloudScale SaaS | Enterprise Architecture',
				'poster_profile_url' => 'https://www.linkedin.com/in/pooja-malhotra-tech',
				'profile_url'        => 'https://www.linkedin.com/in/pooja-malhotra-tech',
				'post_url'           => 'https://www.linkedin.com/feed/update/urn:li:activity:7256192837192',
				'company'            => 'CloudScale Technologies',
				'email'              => 'pooja.m@cloudscale.io',
				'phone'              => '+919811002233',
				'event_role_needed'  => 'Sovereign Custom Web Architecture & Next.js Portal',
				'event_type'         => 'Next.js & Custom Web App',
				'event_dates'        => 'Q4 2026',
				'event_location'     => 'Delhi NCR / Global',
				'raw_snippet'        => 'Looking for an experienced custom web engineering partner to rebuild our customer SaaS portal into a lightning-fast Next.js architecture with sovereign API security and automated CRM routing. Please DM portfolio & case studies.',
				'intent_score'       => 98,
				'urgency'            => 'HIGH',
				'status'             => 'new',
				'source'             => 'linkedin',
				'created_at'         => current_time( 'mysql' ),
				'recommended_pitch'  => 'Hi Pooja, saw your post seeking a web engineering partner for CloudScale\'s SaaS portal. At VM Studio, we architect sovereign, ultra-fast custom web applications, Next.js portals, and enterprise digital infrastructure. Would love to share our technical credentials.',
			],
			[
				'id'                 => 'li_arjun_s',
				'poster_name'        => 'Arjun Singhania',
				'name'               => 'Arjun Singhania',
				'poster_headline'    => 'Chief Revenue Officer | Scaling High-Growth B2B Ventures',
				'headline'           => 'Chief Revenue Officer | Scaling High-Growth B2B Ventures',
				'poster_profile_url' => 'https://www.linkedin.com/in/arjun-singhania-cro',
				'profile_url'        => 'https://www.linkedin.com/in/arjun-singhania-cro',
				'post_url'           => 'https://www.linkedin.com/feed/update/urn:li:activity:7256182910283',
				'company'            => 'VentureScale Global',
				'email'              => 'arjun.s@venturescale.in',
				'phone'              => '+919822334455',
				'event_role_needed'  => 'Autonomous AI Voice Telephony & Calling Agent',
				'event_type'         => 'AI Telephony & Voice CRM',
				'event_dates'        => 'November 2026',
				'event_location'     => 'Bengaluru / Mumbai',
				'raw_snippet'        => 'Looking to deploy an Autonomous AI Voice Calling & Telephony Agent to qualify inbound leads 24/7 and route priority appointments into our WhatsApp CRM. Looking for an engineering studio with live production telephony deployments.',
				'intent_score'       => 96,
				'urgency'            => 'HIGH',
				'status'             => 'new',
				'source'             => 'linkedin',
				'created_at'         => date( 'Y-m-d H:i:s', time() - 3600 * 2 ),
				'recommended_pitch'  => 'Hi Arjun, reaching out regarding your requirement for an Autonomous AI Voice Agent. At VM Studio, we engineer sub-second AI telephony calling agents and unified WhatsApp CRM pipelines that qualify leads and close appointments 24/7. Happy to send a 1-page architecture breakdown and live audio demo.',
			],
			[
				'id'                 => 'li_vikram_a',
				'poster_name'        => 'Dr. Vikram Anand',
				'name'               => 'Dr. Vikram Anand',
				'poster_headline'    => 'Director of Operations | Healthcare & Clinic Networks',
				'headline'           => 'Director of Operations | Healthcare & Clinic Networks',
				'poster_profile_url' => 'https://www.linkedin.com/in/dr-vikram-anand',
				'profile_url'        => 'https://www.linkedin.com/in/dr-vikram-anand',
				'post_url'           => 'https://www.linkedin.com/feed/update/urn:li:activity:7256149182910',
				'company'            => 'MedVantage Clinic Network',
				'email'              => 'vikram@medvantage.in',
				'phone'              => '+919911223344',
				'event_role_needed'  => 'Enterprise WhatsApp CRM & Funnel Automation',
				'event_type'         => 'Enterprise WhatsApp CRM',
				'event_dates'        => 'Immediate',
				'event_location'     => 'Delhi NCR',
				'raw_snippet'        => 'Seeking an enterprise WhatsApp Automation & Multi-Channel CRM solution for patient appointment booking, instant follow-ups, and lead tracking across our clinics in Delhi NCR. Must sync with our database. Please DM.',
				'intent_score'       => 94,
				'urgency'            => 'HIGH',
				'status'             => 'new',
				'source'             => 'linkedin',
				'created_at'         => date( 'Y-m-d H:i:s', time() - 3600 * 5 ),
				'recommended_pitch'  => 'Hi Vikram, saw your requirement for enterprise WhatsApp automation across your clinics. At VM Studio, our sovereign Sales OS CRM and WhatsApp workflow engine automates appointment booking, instant multi-agent chat, and real-time lead routing. Would love to demo this for MedVantage.',
			],
		];
	}
}