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
		register_rest_route(
			self::NAMESPACE,
			'/linkedin/ingest',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'ingest' ],
				'permission_callback' => '__return_true', // Open for webhook integrations, throttled by IP
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/linkedin/leads',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'listLeads' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/linkedin/pitch',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'generatePitch' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/linkedin/status',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'updateStatus' ],
				'permission_callback' => '__return_true',
			]
		);

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

		// Optionally update lead with new pitch
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
