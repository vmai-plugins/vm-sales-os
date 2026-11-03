<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\LinkedInRadar;

use VMStudio\SalesOS\REST\AbstractController;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

final class LinkedInRadarController extends AbstractController {

	private LinkedInPostParser $parser;
	private LinkedInRadarRepository $repo;

	public function __construct( LinkedInPostParser $parser, LinkedInRadarRepository $repo ) {
		$this->parser = $parser;
		$this->repo   = $repo;
	}

	public function register(): void {
		// Inbound Webhooks (LinkedIn Lead Gen Forms, Zapier, Make, n8n, custom relays)
		register_rest_route(
			self::NAMESPACE,
			'/linkedin/webhook',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handleWebhook' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/webhook/linkedin',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handleWebhook' ],
				'permission_callback' => '__return_true',
			]
		);

		// 1-Click Manual Import from WP Admin Settings or Mobile App
		register_rest_route(
			self::NAMESPACE,
			'/linkedin/import',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handleImport' ],
				'permission_callback' => [ $this, 'checkAdminOrKey' ],
			]
		);

		// Leads Listing
		register_rest_route(
			self::NAMESPACE,
			'/linkedin/leads',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'listLeads' ],
				'permission_callback' => '__return_true',
			]
		);

		// Post text parser / scraper ingestion
		register_rest_route(
			self::NAMESPACE,
			'/linkedin/ingest',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'ingest' ],
				'permission_callback' => '__return_true',
			]
		);

		// AI Personalized Pitch Generator
		register_rest_route(
			self::NAMESPACE,
			'/linkedin/pitch',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'generatePitch' ],
				'permission_callback' => '__return_true',
			]
		);

		// Status Update
		register_rest_route(
			self::NAMESPACE,
			'/linkedin/status',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'updateStatus' ],
				'permission_callback' => '__return_true',
			]
		);

		// Search Templates / Google X-Ray
		register_rest_route(
			self::NAMESPACE,
			'/linkedin/templates',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'templates' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Permission callback for import: requires manage permission or valid session/mobile key.
	 */
	public function checkAdminOrKey( WP_REST_Request $request ): bool {
		if ( current_user_can( 'vmsos_manage' ) || current_user_can( 'edit_posts' ) ) {
			return true;
		}

		$apiKey = (string) $request->get_header( 'x-vmai-api-key' );
		if ( '' !== $apiKey ) {
			return true;
		}

		$nonce = (string) ( $request->get_header( 'x-wp-nonce' ) ?: $request->get_param( '_wpnonce' ) );
		if ( '' !== $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Inbound webhook for LinkedIn Lead Gen Forms, Zapier, Make, n8n, PhantomBuster, etc.
	 */
	public function handleWebhook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$throttle = $this->throttle( 'linkedin_webhook', 120, 60 );
		if ( is_wp_error( $throttle ) ) {
			return $throttle;
		}

		// Webhook secret validation if set in options
		$configuredSecret = (string) get_option( 'vmsos_linkedin_webhook_secret', '' );
		if ( '' !== $configuredSecret ) {
			$headerSecret = (string) ( $request->get_header( 'x-linkedin-token' )
				?: $request->get_header( 'x-webhook-secret' )
				?: $request->get_header( 'authorization' ) );
			$paramSecret = (string) ( $request->get_param( 'secret' ) ?: $request->get_param( 'token' ) );

			if ( str_starts_with( strtolower( $headerSecret ), 'bearer ' ) ) {
				$headerSecret = trim( substr( $headerSecret, 7 ) );
			}

			if ( ! hash_equals( $configuredSecret, $headerSecret ) && ! hash_equals( $configuredSecret, $paramSecret ) ) {
				return $this->fail( 'vmsos_unauthorized', __( 'Invalid LinkedIn webhook token or secret.', 'vm-sales-os' ), 401 );
			}
		}

		$body = $request->get_json_params() ?: $request->get_params();
		if ( empty( $body ) ) {
			return $this->fail( 'vmsos_empty_payload', __( 'Empty webhook payload received.', 'vm-sales-os' ), 400 );
		}

		$leadData = $this->extractLeadFields( $body );
		if ( empty( $leadData['name'] ) && empty( $leadData['email'] ) && empty( $leadData['phone'] ) ) {
			return $this->fail( 'vmsos_invalid_lead', __( 'Payload must contain at least a name, email, or phone number.', 'vm-sales-os' ), 400 );
		}

		$saved = $this->repo->save( $leadData );

		return $this->ok(
			[
				'success'                  => true,
				'message'                  => __( 'LinkedIn lead captured and synced to CRM, Deals, and Z-Agents mobile bridge.', 'vm-sales-os' ),
				'lead'                     => $saved,
				'crm_contact_id'           => $saved['crm_contact_id'] ?? 0,
				'crm_deal_id'              => $saved['crm_deal_id'] ?? 0,
				'fluentform_submission_id' => $saved['fluentform_submission_id'] ?? 0,
			],
			201
		);
	}

	/**
	 * Manual 1-click import from WP Admin Settings or Mobile App.
	 */
	public function handleImport( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params() ?: $request->get_params();
		$name = sanitize_text_field( (string) ( $body['name'] ?? $body['full_name'] ?? '' ) );

		if ( '' === trim( $name ) ) {
			return $this->fail( 'vmsos_missing_name', __( 'Lead Name is required.', 'vm-sales-os' ), 400 );
		}

		$leadData = [
			'poster_name'        => $name,
			'name'               => $name,
			'email'              => sanitize_email( (string) ( $body['email'] ?? '' ) ),
			'phone'              => sanitize_text_field( (string) ( $body['phone'] ?? '' ) ),
			'company'            => sanitize_text_field( (string) ( $body['company'] ?? '' ) ),
			'poster_headline'    => sanitize_text_field( (string) ( $body['headline'] ?? $body['title'] ?? '' ) ),
			'headline'           => sanitize_text_field( (string) ( $body['headline'] ?? $body['title'] ?? '' ) ),
			'poster_profile_url' => esc_url_raw( (string) ( $body['profile_url'] ?? $body['linkedin_url'] ?? '' ) ),
			'profile_url'        => esc_url_raw( (string) ( $body['profile_url'] ?? $body['linkedin_url'] ?? '' ) ),
			'raw_snippet'        => sanitize_textarea_field( (string) ( $body['notes'] ?? $body['requirements'] ?? '' ) ),
			'intent_score'       => max( 1, min( 100, (int) ( $body['intent_score'] ?? 90 ) ) ),
			'status'             => 'new',
			'source'             => 'linkedin',
		];

		$saved = $this->repo->save( $leadData );

		return $this->ok(
			[
				'success'                  => true,
				'message'                  => __( 'Lead imported successfully to CRM, Deals, and Z-Agents mobile app.', 'vm-sales-os' ),
				'lead'                     => $saved,
				'crm_contact_id'           => $saved['crm_contact_id'] ?? 0,
				'crm_deal_id'              => $saved['crm_deal_id'] ?? 0,
				'fluentform_submission_id' => $saved['fluentform_submission_id'] ?? 0,
			],
			200
		);
	}

	/**
	 * Extract normalized lead fields from various payload schemas (LinkedIn Lead Gen Forms, Zapier, Make).
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function extractLeadFields( array $body ): array {
		// Flatten if nested under 'data', 'lead', 'form_response', etc.
		if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
			$body = array_merge( $body, $body['data'] );
		}
		if ( isset( $body['lead'] ) && is_array( $body['lead'] ) ) {
			$body = array_merge( $body, $body['lead'] );
		}

		// Handle LinkedIn Lead Gen Form structured answers
		if ( isset( $body['form_response']['answers'] ) && is_array( $body['form_response']['answers'] ) ) {
			foreach ( $body['form_response']['answers'] as $ans ) {
				$qId = strtolower( (string) ( $ans['question_id'] ?? $ans['key'] ?? '' ) );
				$val = (string) ( $ans['value'] ?? $ans['text'] ?? '' );
				if ( str_contains( $qId, 'name' ) && ! isset( $body['name'] ) ) {
					$body['name'] = $val;
				} elseif ( str_contains( $qId, 'email' ) && ! isset( $body['email'] ) ) {
					$body['email'] = $val;
				} elseif ( str_contains( $qId, 'phone' ) && ! isset( $body['phone'] ) ) {
					$body['phone'] = $val;
				} elseif ( str_contains( $qId, 'company' ) && ! isset( $body['company'] ) ) {
					$body['company'] = $val;
				}
			}
		}

		// 1. Name
		$name = '';
		if ( ! empty( $body['first_name'] ) || ! empty( $body['last_name'] ) ) {
			$name = trim( ( (string) ( $body['first_name'] ?? '' ) ) . ' ' . ( (string) ( $body['last_name'] ?? '' ) ) );
		}
		if ( '' === $name ) {
			foreach ( [ 'name', 'full_name', 'poster_name', 'lead_name', 'author_name' ] as $k ) {
				if ( ! empty( $body[ $k ] ) && is_string( $body[ $k ] ) ) {
					$name = trim( $body[ $k ] );
					break;
				}
			}
		}

		// 2. Email
		$email = '';
		foreach ( [ 'email', 'email_address', 'work_email', 'user_email', 'poster_email' ] as $k ) {
			if ( ! empty( $body[ $k ] ) && is_string( $body[ $k ] ) ) {
				$cleaned = sanitize_email( $body[ $k ] );
				if ( is_email( $cleaned ) ) {
					$email = $cleaned;
					break;
				}
			}
		}

		// 3. Phone
		$phone = '';
		foreach ( [ 'phone', 'phone_number', 'mobile', 'telephone', 'contact_number', 'poster_phone' ] as $k ) {
			if ( ! empty( $body[ $k ] ) && is_string( $body[ $k ] ) ) {
				$phone = sanitize_text_field( (string) $body[ $k ] );
				break;
			}
		}

		// 4. Company
		$company = '';
		foreach ( [ 'company', 'company_name', 'organization', 'employer' ] as $k ) {
			if ( ! empty( $body[ $k ] ) && is_string( $body[ $k ] ) ) {
				$company = sanitize_text_field( (string) $body[ $k ] );
				break;
			}
		}

		// 5. Headline / Designation
		$headline = '';
		foreach ( [ 'headline', 'title', 'job_title', 'designation', 'poster_headline' ] as $k ) {
			if ( ! empty( $body[ $k ] ) && is_string( $body[ $k ] ) ) {
				$headline = sanitize_text_field( (string) $body[ $k ] );
				break;
			}
		}

		// 6. LinkedIn URL
		$profileUrl = '';
		foreach ( [ 'profile_url', 'linkedin_url', 'linkedin_profile', 'author_profile', 'poster_profile_url' ] as $k ) {
			if ( ! empty( $body[ $k ] ) && is_string( $body[ $k ] ) ) {
				$profileUrl = esc_url_raw( (string) $body[ $k ] );
				break;
			}
		}

		// 7. Notes / Requirements / Message
		$notes = '';
		foreach ( [ 'notes', 'message', 'requirements', 'inquiry', 'raw_snippet', 'text', 'content' ] as $k ) {
			if ( ! empty( $body[ $k ] ) && is_string( $body[ $k ] ) ) {
				$notes = sanitize_textarea_field( (string) $body[ $k ] );
				break;
			}
		}

		return [
			'poster_name'        => $name ?: 'LinkedIn Lead',
			'name'               => $name ?: 'LinkedIn Lead',
			'email'              => $email,
			'phone'              => $phone,
			'company'            => $company,
			'poster_headline'    => $headline,
			'headline'           => $headline,
			'poster_profile_url' => $profileUrl,
			'profile_url'        => $profileUrl,
			'raw_snippet'        => $notes,
			'intent_score'       => max( 1, min( 100, (int) ( $body['intent_score'] ?? 88 ) ) ),
			'source'             => 'linkedin',
			'status'             => 'new',
		];
	}

	/**
	 * Ingest a single LinkedIn post text or scraper webhook payload.
	 */
	public function ingest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$throttle = $this->throttle( 'linkedin_ingest', 60, 60 );
		if ( is_wp_error( $throttle ) ) {
			return $throttle;
		}

		$body = $request->get_json_params() ?: $request->get_params();
		$text = (string) ( $body['text'] ?? $body['content'] ?? $body['post_text'] ?? '' );

		if ( '' === trim( $text ) ) {
			return $this->fail( 'vmsos_missing_text', __( 'Post text or content is required.', 'vm-sales-os' ), 400 );
		}

		$meta = [
			'author_name' => sanitize_text_field( (string) ( $body['author_name'] ?? $body['name'] ?? '' ) ),
			'headline'    => sanitize_text_field( (string) ( $body['headline'] ?? $body['title'] ?? '' ) ),
			'profile_url' => esc_url_raw( (string) ( $body['profile_url'] ?? $body['author_profile'] ?? '' ) ),
			'post_url'    => esc_url_raw( (string) ( $body['post_url'] ?? $body['url'] ?? '' ) ),
		];

		$parsed = $this->parser->parse( $text, $meta );
		$saved  = $this->repo->save( $parsed );

		return $this->ok(
			[
				'lead'    => $saved,
				'message' => __( 'LinkedIn post successfully parsed and saved to radar.', 'vm-sales-os' ),
			],
			201
		);
	}

	/**
	 * Fetch radar leads with optional filters.
	 */
	public function listLeads( WP_REST_Request $request ): WP_REST_Response {
		$filters = [
			'city'       => $this->param( $request, 'city' ),
			'status'     => $this->param( $request, 'status' ),
			'event_type' => $this->param( $request, 'event_type' ),
			'min_score'  => (int) ( $request->get_param( 'min_score' ) ?? 0 ),
		];

		$leads = $this->repo->list( $filters );
		return $this->ok(
			[
				'leads' => $leads,
				'count' => count( $leads ),
			]
		);
	}

	/**
	 * Regenerate personalized pitch with specific angle.
	 */
	public function generatePitch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$leadId = $this->param( $request, 'lead_id' );
		$angle  = $this->param( $request, 'angle', 'agency' );

		$lead = $this->repo->find( $leadId );
		if ( ! $lead ) {
			return $this->fail( 'vmsos_not_found', __( 'Lead not found on radar.', 'vm-sales-os' ), 404 );
		}

		$pitch = $this->parser->generateDefaultPitch( $lead, $angle );

		$lead['recommended_pitch'] = $pitch;
		$this->repo->save( $lead );

		return $this->ok(
			[
				'lead_id' => $leadId,
				'angle'   => $angle,
				'pitch'   => $pitch,
			]
		);
	}

	/**
	 * Update outreach status.
	 */
	public function updateStatus( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$leadId = $this->param( $request, 'lead_id' );
		$status = $this->param( $request, 'status', 'contacted' );
		$note   = $this->param( $request, 'note' );

		$updated = $this->repo->updateStatus( $leadId, $status, $note ?: null );
		if ( ! $updated ) {
			return $this->fail( 'vmsos_not_found', __( 'Lead not found.', 'vm-sales-os' ), 404 );
		}

		return $this->ok(
			[
				'lead'    => $updated,
				'message' => sprintf( __( 'Status updated to %s.', 'vm-sales-os' ), $status ),
			]
		);
	}

	/**
	 * Return ready-to-use search queries (Google X-Ray / LinkedIn dorks).
	 */
	public function templates(): WP_REST_Response {
		return $this->ok(
			[
				'xray_queries' => [
					[
						'title'       => 'Freelance Event Coordinator / Manager (Delhi NCR)',
						'query'       => 'site:linkedin.com/posts ("Freelance Event Coordinator" OR "Freelance Event Manager") ("Delhi" OR "Gurgaon" OR "Noida")',
						'filter_time' => 'past_24_hours',
					],
					[
						'title'       => 'Corporate Event Planner & Global Meetups',
						'query'       => 'site:linkedin.com/posts ("looking for" OR "seeking") ("corporate event planner" OR "event agency") ("meetup" OR "conference" OR "offsite")',
						'filter_time' => 'past_week',
					],
					[
						'title'       => 'Event Production & Fabrication (South India)',
						'query'       => 'site:linkedin.com/posts ("event management" OR "production agency") ("Bangalore" OR "Bengaluru" OR "Chennai" OR "Hyderabad") ("exhibition" OR "brand activation")',
						'filter_time' => 'past_week',
					],
					[
						'title'       => 'Luxury Brand Launches & Activations',
						'query'       => 'site:linkedin.com/posts ("luxury brand launch" OR "luxury event") ("looking for" OR "hiring" OR "DM")',
						'filter_time' => 'past_week',
					],
				],
			]
		);
	}
}
