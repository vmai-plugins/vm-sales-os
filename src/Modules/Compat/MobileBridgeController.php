<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Compat;

use VMStudio\SalesOS\Modules\Analytics\MetricsService;
use VMStudio\SalesOS\Modules\Chat\ConversationRepository;
use VMStudio\SalesOS\Modules\CRM\ActivityRepository;
use VMStudio\SalesOS\Modules\CRM\ContactRepository;
use VMStudio\SalesOS\Modules\Inbox\InboxService;
use VMStudio\SalesOS\Modules\Sequences\EnrollmentRepository;
use VMStudio\SalesOS\REST\AbstractController;
use VMStudio\SalesOS\Support\BrandContext;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Serves the legacy `vmai/v1` REST surface that the Superfone mobile app is
 * hard-coded against (see VMAIService.ts), backed by vm-sales-os services.
 *
 * Two deliberate departures from the rest of this plugin's controllers:
 *
 *  1. Responses are bare arrays/objects, NOT the `{ ok, data }` envelope
 *     AbstractController::ok() produces. The app does `Array.isArray(data)`
 *     directly on the body, so an envelope would read as an empty screen.
 *
 *  2. Auth is by shared API key (ApiKeyGuard), not WP capabilities.
 *
 * Routes the app calls that vm-sales-os 0.3.0 has no counterpart for answer
 * 501 with a machine-readable `feature` key. That is deliberate: the app
 * swallows transport errors and renders an empty list, so a silent 404 looks
 * identical to "no data". A 501 makes the gap visible instead of invisible.
 */
final class MobileBridgeController extends AbstractController {

	/**
	 * Legacy namespace, deliberately NOT AbstractController::NAMESPACE — the
	 * whole point of this controller is to answer on the old path.
	 */
	private const NS = 'vmai/v1';

	/**
	 * Endpoints the app calls which have no module behind them yet. Each maps
	 * to the vm-sales-os capability that would need to exist first.
	 *
	 * @var array<string, string>
	 */
	private const UNIMPLEMENTED = [
		'/discovery/search'   => 'places-discovery',
		'/discovery/import'   => 'places-discovery',
		'/actions'            => 'action-queue',
		'/action/process'     => 'action-queue',
		'/leaderboard'        => 'gamification',
		'/checkout/create'    => 'payments',
		'/push/register'      => 'push-notifications',
		'/signal/health'      => 'signal-farm',
		'/internal/chat'      => 'internal-chat',
	];

	public function __construct(
		private ApiKeyGuard $guard,
		private ContactRepository $contacts,
		private ConversationRepository $conversations,
		private InboxService $inbox,
		private MetricsService $metrics,
		private ActivityRepository $activities,
		private EnrollmentRepository $enrollments,
		private ?\VMStudio\SalesOS\AI\ProviderManager $ai = null
	) {}

	public function register(): void {
		$auth = fn( WP_REST_Request $request ) => $this->guard->check( $request );

		$route = function ( string $path, string $methods, string $handler ) use ( $auth ): void {
			register_rest_route(
				self::NS,
				$path,
				[
					'methods'             => $methods,
					'callback'            => [ $this, $handler ],
					'permission_callback' => $auth,
				]
			);
		};

		$route( '/leads', 'GET', 'getLeads' );
		$route( '/dashboard', 'GET', 'getDashboard' );
		$route( '/lead/(?P<id>\d+)', 'GET', 'getLead' );
		$route( '/lead/(?P<id>\d+)', 'PATCH, PUT', 'updateLead' );
		$route( '/lead/(?P<id>\d+)/control', 'POST', 'controlAgent' );
		$route( '/lead/(?P<id>\d+)/rocket', 'POST', 'rocketLaunch' );
		$route( '/inbox/conversations', 'GET', 'getConversations' );
		$route( '/inbox/thread/(?P<id>\d+)', 'GET', 'getThread' );
		$route( '/inbox/reply', 'POST', 'sendReply' );
		$route( '/live/chats', 'GET', 'getLiveChats' );
		$route( '/live/hijack', 'POST', 'hijackLiveChat' );
		$route( '/brands', 'GET', 'getBrands' );
		$route( '/call/log', 'POST', 'logCall' );
		$route( '/call/summary', 'GET, POST', 'summarizeCall' );
		$route( '/call/ghost-notes', 'POST', 'ghostNotes' );
		$route( '/followups', 'GET', 'getFollowups' );

		// Everything the app calls that this version cannot serve yet.
		foreach ( array_keys( self::UNIMPLEMENTED ) as $path ) {
			register_rest_route(
				self::NS,
				$path,
				[
					'methods'             => 'GET, POST',
					'callback'            => [ $this, 'notImplemented' ],
					'permission_callback' => $auth,
				]
			);
		}
	}

	// ---------------------------------------------------------------- helpers

	/** @param array<string, mixed>|array<int, mixed> $data Bare response body. */
	private function bare( array $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( $data, $status );
	}

	private function error( string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response( [ 'error' => $message ], $status );
	}

	/**
	 * Reshapes a vmsos contact row into the lead shape the app's screens read
	 * (`name`, `company`, `ai_summary`, …), which does not match the column
	 * names one-for-one.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 * @return array<string, mixed>
	 */
	private function asLead( array $contact ): array {
		$name = trim( ( $contact['first_name'] ?? '' ) . ' ' . ( $contact['last_name'] ?? '' ) );

		return [
			'id'         => (int) ( $contact['id'] ?? 0 ),
			'name'       => '' !== $name ? $name : ( $contact['email'] ?? '' ),
			'company'    => $this->companyName( (int) ( $contact['company_id'] ?? 0 ) ),
			'email'      => (string) ( $contact['email'] ?? '' ),
			'phone'      => (string) ( $contact['phone'] ?? '' ),
			'score'      => (int) ( $contact['score'] ?? 0 ),
			'stage'      => (string) ( $contact['stage'] ?? '' ),
			'source'     => (string) ( $contact['source'] ?? '' ),
			// The app shows this as the at-a-glance blurb. vmsos keeps the AI
			// summary on the conversation, not the contact, so fall back to the
			// scoring rationale when there is no conversation summary.
			'ai_summary' => $this->leadSummary( (int) ( $contact['id'] ?? 0 ) )
				?: (string) ( $contact['score_reason'] ?? '' ),
			'created_at' => (string) ( $contact['created_at'] ?? '' ),
		];
	}

	private function companyName( int $companyId ): string {
		if ( $companyId <= 0 ) {
			return '';
		}

		global $wpdb;

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT name FROM {$wpdb->prefix}vmsos_companies WHERE id = %d",
				$companyId
			)
		);
	}

	private function leadSummary( int $contactId ): string {
		if ( $contactId <= 0 ) {
			return '';
		}

		global $wpdb;

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT summary FROM {$wpdb->prefix}vmsos_conversations
				 WHERE contact_id = %d AND brand_id = %d AND summary IS NOT NULL AND summary <> ''
				 ORDER BY last_message_at DESC LIMIT 1",
				$contactId,
				BrandContext::current()
			)
		);
	}

	/** Most recent conversation for a contact, or null. */
	private function latestConversation( int $contactId ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}vmsos_conversations
				 WHERE contact_id = %d AND brand_id = %d
				 ORDER BY COALESCE( last_message_at, created_at ) DESC LIMIT 1",
				$contactId,
				BrandContext::current()
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/** Loads a contact scoped to the current brand, or null if not visible. */
	private function contact( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}vmsos_contacts WHERE id = %d AND brand_id = %d",
				$id,
				BrandContext::current()
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	// ----------------------------------------------------------------- routes

	public function getLeads( WP_REST_Request $request ): WP_REST_Response {
		$perPage = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 25 ) ) );
		$page    = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );

		$filters = [];

		$stage = (string) $request->get_param( 'stage' );
		if ( '' !== $stage ) {
			$filters['stage'] = sanitize_text_field( $stage );
		}

		$search = (string) $request->get_param( 'search' );
		if ( '' !== $search ) {
			$filters['search'] = sanitize_text_field( $search );
		}

		// search() returns a flat list of rows, not a paginated envelope.
		$rows = array_map(
			fn( array $c ): array => $this->asLead( $c ),
			$this->contacts->search( $filters, $perPage, $page )
		);

		// The app reads `data.rows`; anything else renders an empty list.
		return $this->bare(
			[
				'rows'  => $rows,
				'total' => $this->countLeads( $filters ),
			]
		);
	}

	/**
	 * Total matching leads, for the list header. Mirrors the filters
	 * ContactRepository::search() applies.
	 *
	 * @param array<string, string> $filters Stage/search filters.
	 */
	private function countLeads( array $filters ): int {
		global $wpdb;

		$where = [ 'brand_id = %d' ];
		$args  = [ BrandContext::current() ];

		if ( ! empty( $filters['stage'] ) ) {
			$where[] = 'stage = %s';
			$args[]  = sanitize_key( $filters['stage'] );
		}

		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}

		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}vmsos_contacts WHERE " . implode( ' AND ', $where );

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	public function getDashboard( WP_REST_Request $request ): WP_REST_Response {
		$days = min( 365, max( 1, (int) ( $request->get_param( 'days' ) ?: 30 ) ) );

		return $this->bare(
			[
				'summary'  => $this->metrics->overview( $days ),
				'funnel'   => $this->contacts->stageCounts(),
				'currency' => (string) get_option( 'vmsos_currency', 'INR' ),
			]
		);
	}

	public function getLead( WP_REST_Request $request ): WP_REST_Response {
		$contact = $this->contact( (int) $request->get_param( 'id' ) );

		if ( null === $contact ) {
			return $this->error( 'Lead not found.', 404 );
		}

		$lead             = $this->asLead( $contact );
		$lead['timeline'] = $this->activities->timeline( 'contact', (int) $contact['id'], 50 );

		return $this->bare( $lead );
	}

	public function updateLead( WP_REST_Request $request ): WP_REST_Response {
		$id      = (int) $request->get_param( 'id' );
		$contact = $this->contact( $id );

		if ( null === $contact ) {
			return $this->error( 'Lead not found.', 404 );
		}

		// Only fields the app actually edits, so a stray key cannot write a
		// column the caller was never meant to touch.
		$allowed = [ 'first_name', 'last_name', 'email', 'phone', 'stage', 'score', 'tags', 'owner_id' ];
		$input   = [];

		foreach ( $allowed as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$input[ $field ] = $value;
			}
		}

		// The app sends a single `name`; vmsos stores it split.
		$name = (string) $request->get_param( 'name' );
		if ( '' !== $name ) {
			$parts               = preg_split( '/\s+/', trim( $name ), 2 ) ?: [ '' ];
			$input['first_name'] = $parts[0];
			$input['last_name']  = $parts[1] ?? '';
		}

		if ( [] === $input ) {
			return $this->error( 'No supported fields to update.', 400 );
		}

		$this->contacts->edit( $id, $input );

		$fresh = $this->contact( $id );

		return $this->bare(
			[
				'success' => true,
				'lead'    => null !== $fresh ? $this->asLead( $fresh ) : null,
			]
		);
	}

	/**
	 * Mute/unmute the AI on a lead's live conversation. "Mute" is a human
	 * takeover in vmsos terms — the bot stops answering while a person holds
	 * the thread.
	 */
	public function controlAgent( WP_REST_Request $request ): WP_REST_Response {
		$contactId = (int) $request->get_param( 'id' );
		$action    = sanitize_text_field( (string) $request->get_param( 'agent_action' ) );

		if ( ! in_array( $action, [ 'mute', 'unmute' ], true ) ) {
			return $this->error( 'agent_action must be mute or unmute.', 400 );
		}

		if ( null === $this->contact( $contactId ) ) {
			return $this->error( 'Lead not found.', 404 );
		}

		$conversation = $this->latestConversation( $contactId );

		if ( null === $conversation ) {
			return $this->error( 'That lead has no conversation to control.', 404 );
		}

		$conversationId = (int) $conversation['id'];

		if ( 'mute' === $action ) {
			$this->inbox->takeOver( $conversationId, get_current_user_id() );
		} else {
			$this->inbox->release( $conversationId );
		}

		return $this->bare( [ 'success' => true, 'agent_action' => $action ] );
	}

	/**
	 * One row per conversation, newest first — the app's inbox list. It keys
	 * navigation off `lead_id`, so conversations with no contact attached are
	 * skipped rather than rendered as untappable rows.
	 */
	public function getConversations( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$limit = min( 200, max( 1, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id AS conversation_id, c.channel, c.contact_id,
				        ct.first_name, ct.last_name, ct.email,
				        m.role, m.content, m.created_at
				 FROM {$wpdb->prefix}vmsos_conversations c
				 INNER JOIN {$wpdb->prefix}vmsos_contacts ct ON ct.id = c.contact_id
				 LEFT JOIN {$wpdb->prefix}vmsos_messages m ON m.id = (
				     SELECT id FROM {$wpdb->prefix}vmsos_messages
				     WHERE conversation_id = c.id
				     ORDER BY created_at DESC, id DESC LIMIT 1
				 )
				 WHERE c.brand_id = %d AND c.contact_id > 0
				 ORDER BY COALESCE( c.last_message_at, c.created_at ) DESC
				 LIMIT %d",
				BrandContext::current(),
				$limit
			),
			ARRAY_A
		);

		$out = [];

		foreach ( (array) $rows as $row ) {
			$name = trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );

			$out[] = [
				'id'         => (int) $row['conversation_id'],
				'lead_id'    => (int) $row['contact_id'],
				'lead_name'  => '' !== $name ? $name : null,
				'email'      => $row['email'] ?: null,
				'channel'    => $row['channel'],
				'role'       => $row['role'],
				'message'    => (string) $row['content'],
				'created_at' => $row['created_at'],
			];
		}

		return $this->bare( $out );
	}

	/**
	 * Full transcript for a lead. The app routes by lead id, so this resolves
	 * the lead's most recent conversation and returns its messages.
	 */
	public function getThread( WP_REST_Request $request ): WP_REST_Response {
		$contactId = (int) $request->get_param( 'id' );

		if ( null === $this->contact( $contactId ) ) {
			return $this->error( 'Lead not found.', 404 );
		}

		$conversation = $this->latestConversation( $contactId );

		if ( null === $conversation ) {
			return $this->bare( [] );
		}

		$messages = $this->conversations->messages( (int) $conversation['id'], 500 );

		$out = [];

		foreach ( (array) $messages as $m ) {
			$out[] = [
				'id'         => (int) ( $m['id'] ?? 0 ),
				'role'       => (string) ( $m['role'] ?? 'user' ),
				'message'    => (string) ( $m['content'] ?? '' ),
				'created_at' => (string) ( $m['created_at'] ?? '' ),
				'channel'    => (string) $conversation['channel'],
			];
		}

		return $this->bare( $out );
	}

	public function sendReply( WP_REST_Request $request ): WP_REST_Response {
		$contactId = (int) $request->get_param( 'lead_id' );
		$message   = trim( wp_strip_all_tags( (string) $request->get_param( 'message' ) ) );

		if ( $contactId <= 0 || '' === $message ) {
			return $this->error( 'lead_id and message are both required.', 400 );
		}

		if ( null === $this->contact( $contactId ) ) {
			return $this->error( 'Lead not found.', 404 );
		}

		$conversation = $this->latestConversation( $contactId );

		if ( null === $conversation ) {
			return $this->error( 'That lead has no conversation to reply to.', 404 );
		}

		$sent = $this->inbox->reply(
			(int) $conversation['id'],
			$message,
			get_current_user_id()
		);

		if ( null === $sent ) {
			return $this->error( 'The reply could not be delivered.', 502 );
		}

		return $this->bare(
			[
				'success' => true,
				'channel' => (string) $conversation['channel'],
			]
		);
	}

	/**
	 * Open conversations with recent activity, each with its transcript —
	 * the app's live-visitor board. It polls this every 15s, so the window is
	 * deliberately narrow.
	 */
	public function getLiveChats( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.uuid, c.channel, c.contact_id,
				        ct.first_name, ct.last_name, ct.phone
				 FROM {$wpdb->prefix}vmsos_conversations c
				 LEFT JOIN {$wpdb->prefix}vmsos_contacts ct ON ct.id = c.contact_id
				 WHERE c.brand_id = %d
				   AND c.status = 'open'
				   AND c.last_message_at >= DATE_SUB( NOW(), INTERVAL 5 MINUTE )
				 ORDER BY c.last_message_at DESC
				 LIMIT 50",
				BrandContext::current()
			),
			ARRAY_A
		);

		$out = [];

		foreach ( (array) $rows as $row ) {
			$name     = trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );
			$messages = $this->conversations->messages( (int) $row['id'], 100 );

			$out[] = [
				'session' => [
					// The app takes over by this id, so it must be what
					// /live/hijack accepts back.
					'session_id'   => (string) $row['uuid'],
					'lead_name'    => '' !== $name ? $name : null,
					'phone'        => $row['phone'] ?: null,
					'current_page' => null,
				],
				'messages' => array_map(
					static fn( array $m ): array => [
						'role'       => (string) ( $m['role'] ?? 'user' ),
						'message'    => (string) ( $m['content'] ?? '' ),
						'created_at' => (string) ( $m['created_at'] ?? '' ),
					],
					(array) $messages
				),
			];
		}

		return $this->bare( $out );
	}

	public function hijackLiveChat( WP_REST_Request $request ): WP_REST_Response {
		$uuid    = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$message = trim( wp_strip_all_tags( (string) $request->get_param( 'message' ) ) );

		if ( '' === $uuid || '' === $message ) {
			return $this->error( 'session_id and message are both required.', 400 );
		}

		$conversation = $this->conversations->findByUuid( $uuid );

		if ( null === $conversation ) {
			return $this->error( 'Session not found.', 404 );
		}

		if ( (int) $conversation['brand_id'] !== BrandContext::current() ) {
			return $this->error( 'Not permitted for this brand.', 403 );
		}

		$conversationId = (int) $conversation['id'];
		$userId         = get_current_user_id();

		// Taking over first is what stops the bot replying underneath the human.
		$this->inbox->takeOver( $conversationId, $userId );

		$sent = $this->inbox->reply( $conversationId, $message, $userId );

		if ( null === $sent ) {
			return $this->error( 'The takeover message could not be delivered.', 502 );
		}

		return $this->bare( [ 'success' => true ] );
	}

	/**
	 * Brands available to switch between. A brand-scoped key already *is* one
	 * tenant, so it gets an empty list rather than a view of its siblings —
	 * matching what the app's VMAIBrandSwitcher expects.
	 */
	public function getBrands( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->guard->isAccountWide() ) {
			return $this->bare( [] );
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT id, name, site_url, settings FROM {$wpdb->prefix}vmsos_brands
			 WHERE status = 'active' ORDER BY name ASC",
			ARRAY_A
		);

		$out = [];

		foreach ( (array) $rows as $row ) {
			$settings = json_decode( (string) ( $row['settings'] ?? '' ), true );

			$out[] = [
				'id'       => (int) $row['id'],
				'name'     => (string) $row['name'],
				'site_url' => $row['site_url'] ?: null,
				// The app switches tenant by swapping to this key.
				'external_api_key' => is_array( $settings )
					? (string) ( $settings['mobile_api_key'] ?? '' )
					: '',
			];
		}

		return $this->bare( $out );
	}

	/** Enrol the lead in the configured re-engagement sequence. */
	public function rocketLaunch( WP_REST_Request $request ): WP_REST_Response {
		$contactId = (int) $request->get_param( 'id' );

		if ( null === $this->contact( $contactId ) ) {
			return $this->error( 'Lead not found.', 404 );
		}

		$sequenceId = (int) get_option( 'vmsos_reengagement_sequence_id', 0 );

		if ( $sequenceId <= 0 ) {
			return $this->error(
				'No re-engagement sequence is configured. Set vmsos_reengagement_sequence_id first.',
				409
			);
		}

		$enrollmentId = $this->enrollments->enroll( $sequenceId, $contactId );

		if ( $enrollmentId <= 0 ) {
			return $this->error( 'That lead could not be enrolled.', 500 );
		}

		$this->activities->log(
			'contact',
			$contactId,
			'sequence',
			'Enrolled in the re-engagement sequence from the mobile app.',
			'agent'
		);

		return $this->bare(
			[
				'success'       => true,
				'mode'          => 'sequence',
				'sequence_id'   => $sequenceId,
				'enrollment_id' => $enrollmentId,
			]
		);
	}

	/** Log a phone call against a contact / lead. */
	public function logCall( WP_REST_Request $request ): WP_REST_Response {
		$contactId = (int) ( $request->get_param( 'lead_id' ) ?: $request->get_param( 'contact_id' ) ?: $request->get_param( 'id' ) );
		$phone     = sanitize_text_field( (string) ( $request->get_param( 'phone' ) ?: '' ) );
		$duration  = max( 0, (int) $request->get_param( 'duration' ) );
		$direction = sanitize_key( (string) ( $request->get_param( 'direction' ) ?: 'outbound' ) );
		$outcome   = sanitize_text_field( (string) ( $request->get_param( 'outcome' ) ?: 'completed' ) );
		$recording = esc_url_raw( (string) ( $request->get_param( 'recording_url' ) ?: '' ) );
		$notes     = sanitize_textarea_field( (string) ( $request->get_param( 'notes' ) ?: '' ) );

		if ( $contactId <= 0 && '' !== $phone ) {
			$found = $this->contacts->findByPhone( $phone );
			if ( $found && ! empty( $found['id'] ) ) {
				$contactId = (int) $found['id'];
			}
		}

		if ( $contactId <= 0 ) {
			return $this->error( 'Lead ID or recognizable phone number is required.', 400 );
		}

		$body = '' !== $notes
			? $notes
			: sprintf( 'Call %s (%ds) — Outcome: %s', $direction, $duration, $outcome );

		$meta = [
			'duration'      => $duration,
			'direction'     => $direction,
			'outcome'       => $outcome,
			'phone'         => $phone,
			'recording_url' => $recording,
		];

		$activityId = $this->activities->log(
			'contact',
			$contactId,
			'call',
			$body,
			'telephony',
			$meta
		);

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'vmsos_contacts',
			[ 'last_seen_at' => current_time( 'mysql' ) ],
			[ 'id' => $contactId ],
			[ '%s' ],
			[ '%d' ]
		);

		return $this->bare(
			[
				'success'     => true,
				'activity_id' => $activityId,
				'lead_id'     => $contactId,
				'duration'    => $duration,
				'outcome'     => $outcome,
			]
		);
	}

	/** Retrieve or generate a call summary. */
	public function summarizeCall( WP_REST_Request $request ): WP_REST_Response {
		$contactId = (int) ( $request->get_param( 'lead_id' ) ?: $request->get_param( 'contact_id' ) ?: $request->get_param( 'id' ) );
		if ( $contactId <= 0 ) {
			return $this->error( 'Lead ID is required.', 400 );
		}

		if ( 'GET' === $request->get_method() ) {
			global $wpdb;
			$latest = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}vmsos_activities
					 WHERE subject_type = 'contact' AND subject_id = %d AND type IN ('call_summary', 'ghost_notes', 'call')
					 ORDER BY created_at DESC LIMIT 1",
					$contactId
				),
				ARRAY_A
			);

			return $this->bare(
				[
					'success' => true,
					'lead_id' => $contactId,
					'summary' => $latest['body'] ?? 'No call summary recorded yet.',
					'meta'    => ! empty( $latest['meta'] ) ? json_decode( (string) $latest['meta'], true ) : [],
				]
			);
		}

		$transcript = sanitize_textarea_field( (string) ( $request->get_param( 'transcript' ) ?: $request->get_param( 'notes' ) ?: '' ) );
		if ( '' === $transcript ) {
			return $this->error( 'Transcript or notes parameter is required for summarization.', 400 );
		}

		$summary = $transcript;
		if ( $this->ai ) {
			try {
				$prompt = "Summarize the following call transcript concisely for a sales CRM. Extract key discussion points and client needs:\n\n" . $transcript;
				$chatReq = new \VMStudio\SalesOS\AI\DTO\ChatRequest(
					messages: [
						new \VMStudio\SalesOS\AI\DTO\Message( role: 'system', content: 'You are an executive CRM sales assistant. Output a concise 2-3 sentence call summary.' ),
						new \VMStudio\SalesOS\AI\DTO\Message( role: 'user', content: $prompt ),
					],
					temperature: 0.3
				);
				$res = $this->ai->chat( $chatReq );
				if ( ! empty( $res->content ) ) {
					$summary = trim( $res->content );
				}
			} catch ( \Throwable $e ) {
				// Fallback to transcript
			}
		}

		$activityId = $this->activities->log(
			'contact',
			$contactId,
			'call_summary',
			$summary,
			'ai',
			[ 'transcript_length' => strlen( $transcript ) ]
		);

		return $this->bare(
			[
				'success'     => true,
				'activity_id' => $activityId,
				'lead_id'     => $contactId,
				'summary'     => $summary,
			]
		);
	}

	/** AI-generated structured sales ghost notes from a call transcript or bullet points. */
	public function ghostNotes( WP_REST_Request $request ): WP_REST_Response {
		$contactId  = (int) ( $request->get_param( 'lead_id' ) ?: $request->get_param( 'contact_id' ) ?: $request->get_param( 'id' ) );
		$transcript = sanitize_textarea_field( (string) ( $request->get_param( 'transcript' ) ?: $request->get_param( 'notes' ) ?: '' ) );

		if ( $contactId <= 0 ) {
			return $this->error( 'Lead ID is required.', 400 );
		}

		if ( '' === $transcript ) {
			return $this->error( 'Transcript or rough notes are required to generate ghost notes.', 400 );
		}

		$summary     = 'Call logged with client.';
		$notes       = $transcript;
		$actionItems = [];
		$sentiment   = 'neutral';

		if ( $this->ai ) {
			try {
				$systemPrompt = 'You are a top-tier Sales Engineer assistant. Analyze the raw call notes/transcript and output a JSON object with:
- "summary": (string, 1-2 concise sentences summarizing the call outcome)
- "notes": (string, structured bullet points covering: Discussion, Client Pain Points, Budget/Timeline)
- "action_items": (array of strings, concrete immediate next steps)
- "sentiment": ("positive", "neutral", or "negative")
Output valid JSON ONLY with no Markdown formatting or code fences.';

				$chatReq = new \VMStudio\SalesOS\AI\DTO\ChatRequest(
					messages: [
						new \VMStudio\SalesOS\AI\DTO\Message( role: 'system', content: $systemPrompt ),
						new \VMStudio\SalesOS\AI\DTO\Message( role: 'user', content: $transcript ),
					],
					temperature: 0.2
				);

				$res     = $this->ai->chat( $chatReq );
				$content = trim( (string) $res->content );
				$content = preg_replace( '/^```json\s*|\s*```$/i', '', $content );
				$parsed  = json_decode( (string) $content, true );

				if ( is_array( $parsed ) ) {
					$summary     = (string) ( $parsed['summary'] ?? $summary );
					$notes       = (string) ( $parsed['notes'] ?? $notes );
					$actionItems = (array) ( $parsed['action_items'] ?? [] );
					$sentiment   = (string) ( $parsed['sentiment'] ?? $sentiment );
				}
			} catch ( \Throwable $e ) {
				// Use raw transcript
			}
		}

		$formattedBody = "Summary: " . $summary . "\n\nNotes:\n" . $notes;
		if ( ! empty( $actionItems ) ) {
			$formattedBody .= "\n\nNext Steps:\n- " . implode( "\n- ", $actionItems );
		}

		$activityId = $this->activities->log(
			'contact',
			$contactId,
			'ghost_notes',
			$formattedBody,
			'ai',
			[
				'sentiment'    => $sentiment,
				'action_items' => $actionItems,
			]
		);

		return $this->bare(
			[
				'success'      => true,
				'activity_id'  => $activityId,
				'lead_id'      => $contactId,
				'summary'      => $summary,
				'notes'        => $notes,
				'action_items' => $actionItems,
				'sentiment'    => $sentiment,
			]
		);
	}

	/** List of leads / contacts requiring immediate follow-up. */
	public function getFollowups( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$limit = min( 100, max( 1, (int) ( $request->get_param( 'limit' ) ?: 30 ) ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, first_name, last_name, email, phone, stage, score, score_reason, last_seen_at, created_at
				 FROM {$wpdb->prefix}vmsos_contacts
				 WHERE brand_id = %d AND stage NOT IN ('won', 'lost', 'abandoned')
				 ORDER BY score DESC, COALESCE(last_seen_at, created_at) ASC
				 LIMIT %d",
				BrandContext::current(),
				$limit
			),
			ARRAY_A
		);

		$followups = [];
		foreach ( (array) $rows as $row ) {
			$name = trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );
			$followups[] = [
				'id'           => (int) $row['id'],
				'lead_id'      => (int) $row['id'],
				'title'        => '' !== $name ? 'Follow up with ' . $name : 'Follow up with Lead #' . $row['id'],
				'name'         => '' !== $name ? $name : null,
				'email'        => $row['email'] ?: null,
				'phone'        => $row['phone'] ?: null,
				'stage'        => $row['stage'],
				'score'        => (int) $row['score'],
				'score_reason' => $row['score_reason'],
				'due_date'     => current_time( 'Y-m-d' ),
				'last_seen_at' => $row['last_seen_at'],
			];
		}

		return $this->bare( $followups );
	}

	/**
	 * Answers the routes the app calls that this version has no module for.
	 * A 501 with a `feature` key so the gap is visible in logs and in the app,
	 * instead of a 404 the client would render as an empty screen.
	 */
	public function notImplemented( WP_REST_Request $request ): WP_REST_Response {
		$route   = (string) $request->get_route();
		$feature = 'unknown';

		foreach ( self::UNIMPLEMENTED as $path => $name ) {
			if ( str_ends_with( $route, $path ) ) {
				$feature = $name;
				break;
			}
		}

		return $this->bare(
			[
				'error'   => 'This endpoint is not available on vm-sales-os yet.',
				'feature' => $feature,
				'route'   => $route,
			],
			501
		);
	}
}
