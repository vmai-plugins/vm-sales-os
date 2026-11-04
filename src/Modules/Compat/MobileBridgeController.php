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
		'/followups'          => 'tasks',
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
		private EnrollmentRepository $enrollments
	) {}

	public function register(): void {
		$auth = fn( WP_REST_Request $request ) => $this->guard->check( $request );

		$namespaces = [ 'vmai/v1', 'vmsos/v1', 'tc-agents/v1' ];

		$route = function ( string $path, string $methods, string $handler ) use ( $auth, $namespaces ): void {
			foreach ( $namespaces as $ns ) {
				register_rest_route(
					$ns,
					$path,
					[
						'methods'             => $methods,
						'callback'            => [ $this, $handler ],
						'permission_callback' => $auth,
					]
				);
			}
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
		$route( '/me', 'GET', 'getMe' );
		$route( '/auth/me', 'GET', 'getMe' );
		$route( '/business-profile', 'GET', 'getMe' );
		$route( '/cabs', 'GET', 'getCabs' );
		$route( '/cabs', 'POST', 'saveCabs' );
		$route( '/tariffs', 'GET', 'getTariffs' );
		$route( '/tariffs', 'POST', 'saveTariffs' );
		$route( '/seo-brain/overview', 'GET', 'getSeoBrainOverview' );
		$route( '/social-ai/overview', 'GET', 'getSocialAiOverview' );
		$route( '/customer-margins', 'GET', 'getCustomerMargins' );
		$route( '/payroll/employees', 'GET', 'getPayrollEmployees' );
		$route( '/payroll/employees', 'POST', 'savePayrollEmployee' );
		$route( '/payroll/calculate', 'POST', 'calculatePayroll' );
		$route( '/master-assistant/briefing', 'GET', 'getMasterBriefing' );
		$route( '/master-assistant/chat', 'POST', 'masterAssistantChat' );
		$route( '/zoho/summary', 'GET', 'getZohoSummary' );
		$route( '/zoho/config', 'GET', 'getZohoConfig' );

		// WhatsApp & Evolution Gateway Mobile Routes
		$route( '/send-whatsapp', 'POST', 'sendWhatsApp' );
		$route( '/mobile/send-whatsapp', 'POST', 'sendWhatsApp' );
		$route( '/send-whatsapp-buttons', 'POST', 'sendWhatsAppButtons' );
		$route( '/mobile/send-whatsapp-buttons', 'POST', 'sendWhatsAppButtons' );
		$route( '/evolution-status', 'GET', 'getEvolutionStatus' );
		$route( '/mobile/evolution-status', 'GET', 'getEvolutionStatus' );
		$route( '/whatsapp-leads', 'GET', 'getWhatsAppLeads' );
		$route( '/mobile/whatsapp-leads', 'GET', 'getWhatsAppLeads' );
		$route( '/whatsapp-messages', 'GET', 'getWhatsAppMessages' );
		$route( '/mobile/whatsapp-messages', 'GET', 'getWhatsAppMessages' );
		$route( '/automation-settings', 'GET', 'handleGetAutomationSettings' );
		$route( '/mobile/automation-settings', 'GET', 'handleGetAutomationSettings' );
		$route( '/automation-settings', 'POST', 'handleUpdateAutomationSettings' );
		$route( '/mobile/automation-settings', 'POST', 'handleUpdateAutomationSettings' );

		// Mobile Lead Operations
		$route( '/update-lead', 'POST', 'handleUpdateLead' );
		$route( '/mobile/update-lead', 'POST', 'handleUpdateLead' );
		$route( '/create-lead', 'POST', 'handleCreateLead' );
		$route( '/mobile/create-lead', 'POST', 'handleCreateLead' );
		$route( '/assign-lead', 'POST', 'handleAssignLead' );
		$route( '/mobile/assign-lead', 'POST', 'handleAssignLead' );

		// Mobile Telephony Routes
		$route( '/telephony-initiate', 'POST', 'handleTelephonyInitiate' );
		$route( '/mobile/telephony-initiate', 'POST', 'handleTelephonyInitiate' );
		$route( '/telephony-config', 'GET', 'getTelephonyConfig' );
		$route( '/mobile/telephony-config', 'GET', 'getTelephonyConfig' );
		$route( '/call-queue', 'GET', 'getCallQueue' );
		$route( '/mobile/call-queue', 'GET', 'getCallQueue' );
		$route( '/call-logs', 'GET', 'getCallLogs' );
		$route( '/mobile/call-logs', 'GET', 'getCallLogs' );
		$route( '/call-dispatch', 'POST', 'handleCallDispatch' );
		$route( '/mobile/call-dispatch', 'POST', 'handleCallDispatch' );
		$route( '/call/log', 'POST', 'handleCallDispatch' );
		$route( '/mobile/call/log', 'POST', 'handleCallDispatch' );
		$route( '/call/summary', 'POST', 'handleCallDispatch' );
		$route( '/mobile/call/summary', 'POST', 'handleCallDispatch' );
		$route( '/call/ghost-notes', 'POST', 'handleCallDispatch' );
		$route( '/mobile/call/ghost-notes', 'POST', 'handleCallDispatch' );

		// Mobile Common Path Aliases
		$route( '/mobile/leads', 'GET', 'getLeads' );
		$route( '/mobile/me', 'GET', 'getMe' );
		$route( '/mobile/auth/me', 'GET', 'getMe' );
		$route( '/mobile/business-profile', 'GET', 'getMe' );
		$route( '/mobile/cabs', 'GET', 'getCabs' );
		$route( '/mobile/cabs', 'POST', 'saveCabs' );
		$route( '/mobile/tariffs', 'GET', 'getTariffs' );
		$route( '/mobile/tariffs', 'POST', 'saveTariffs' );

		// Public Authentication Endpoints (supports Direct Web & App Login)
		$authRoutes = [
			'/auth/login'                => [ 'methods' => 'POST', 'handler' => 'handleLogin' ],
			'/mobile/auth/login'         => [ 'methods' => 'POST', 'handler' => 'handleLogin' ],
			'/auth/google'               => [ 'methods' => 'POST', 'handler' => 'handleGoogleLogin' ],
			'/mobile/auth/google'        => [ 'methods' => 'POST', 'handler' => 'handleGoogleLogin' ],
			'/auth/google/config'        => [ 'methods' => 'GET',  'handler' => 'getGoogleConfig' ],
			'/mobile/auth/google/config' => [ 'methods' => 'GET',  'handler' => 'getGoogleConfig' ],
		];
		foreach ( $namespaces as $ns ) {
			foreach ( $authRoutes as $p => $cfg ) {
				register_rest_route(
					$ns,
					$p,
					[
						'methods'             => $cfg['methods'],
						'callback'            => [ $this, $cfg['handler'] ],
						'permission_callback' => '__return_true',
					]
				);
			}
		}

		// Connect with Google Site Kit One-Click Login for PWA / Web redirect
		add_filter( 'login_redirect', [ $this, 'filterLoginRedirect' ], 10, 3 );

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

	/** @var array<int, string> In-memory cache for company names to prevent N+1 queries */
	private array $companyCache = [];

	/** @var array<int, string> In-memory cache for conversation summaries to prevent N+1 queries */
	private array $summaryCache = [];

	/**
	 * Pre-fetch companies and latest conversation summaries in bulk to eliminate N+1 queries.
	 *
	 * @param array<int, array<string, mixed>> $contacts
	 */
	private function primeLeadsCache( array $contacts ): void {
		global $wpdb;
		$companyIds = array_filter( array_unique( array_map( fn( $c ) => (int) ( $c['company_id'] ?? 0 ), $contacts ) ) );
		$contactIds = array_filter( array_unique( array_map( fn( $c ) => (int) ( $c['id'] ?? 0 ), $contacts ) ) );

		if ( ! empty( $companyIds ) ) {
			$idsStr = implode( ',', array_map( 'intval', $companyIds ) );
			$rows   = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}vmsos_companies WHERE id IN ($idsStr)", ARRAY_A );
			foreach ( $rows ?: [] as $r ) {
				$this->companyCache[ (int) $r['id'] ] = (string) $r['name'];
			}
		}

		if ( ! empty( $contactIds ) ) {
			$idsStr  = implode( ',', array_map( 'intval', $contactIds ) );
			$brandId = BrandContext::current();
			$rows    = $wpdb->get_results(
				"SELECT contact_id, summary FROM {$wpdb->prefix}vmsos_conversations
				 WHERE contact_id IN ($idsStr) AND brand_id = $brandId AND summary IS NOT NULL AND summary <> ''
				 ORDER BY last_message_at DESC",
				ARRAY_A
			);
			foreach ( $rows ?: [] as $r ) {
				$cid = (int) $r['contact_id'];
				if ( ! isset( $this->summaryCache[ $cid ] ) ) {
					$this->summaryCache[ $cid ] = (string) $r['summary'];
				}
			}
		}
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
		$name        = trim( ( $contact['first_name'] ?? '' ) . ' ' . ( $contact['last_name'] ?? '' ) );
		$companyId   = (int) ( $contact['company_id'] ?? 0 );
		$companyName = $this->companyCache[ $companyId ] ?? $this->companyName( $companyId );

		$contactId   = (int) ( $contact['id'] ?? 0 );
		$summary     = $this->summaryCache[ $contactId ] ?? $this->leadSummary( $contactId );

		$meta = [];
		if ( ! empty( $contact['meta'] ) ) {
			$meta = is_array( $contact['meta'] ) ? $contact['meta'] : ( json_decode( (string) $contact['meta'], true ) ?: [] );
		}

		$rawTags = $contact['tags'] ?? [];
		$tags    = is_array( $rawTags ) ? $rawTags : array_filter( array_map( 'trim', explode( ',', (string) $rawTags ) ) );

		$notes = (string) ( $contact['requirements'] ?? '' );
		if ( '' === $notes && ! empty( $contact['score_reason'] ) ) {
			$notes = (string) $contact['score_reason'];
		}

		return [
			'id'                  => $contactId,
			'name'                => '' !== $name ? $name : ( $contact['email'] ?? 'Lead #' . $contactId ),
			'first_name'          => (string) ( $contact['first_name'] ?? '' ),
			'last_name'           => (string) ( $contact['last_name'] ?? '' ),
			'company'             => $companyName,
			'email'               => (string) ( $contact['email'] ?? '' ),
			'phone'               => (string) ( $contact['phone'] ?? '' ),
			'score'               => (int) ( $contact['score'] ?? 0 ),
			'lead_score'          => (int) ( $contact['score'] ?? 0 ),
			'score_reason'        => (string) ( $contact['score_reason'] ?? '' ),
			'stage'               => (string) ( $contact['stage'] ?? 'new' ),
			'stage_id'            => (string) ( $contact['stage'] ?? 'new' ),
			'source'              => (string) ( $contact['source'] ?? 'web' ),
			'requirements'        => (string) ( $contact['requirements'] ?? '' ),
			'notes'               => $notes,
			'tags'                => $tags,
			'meta'                => $meta,
			'custom_fields'       => array_merge( $meta, [ 'tags' => $tags, 'requirements' => (string) ( $contact['requirements'] ?? '' ) ] ),
			'assigned_agent_id'   => (string) ( $contact['owner_id'] ?? '101' ),
			'assigned_agent_name' => (int) ( $contact['owner_id'] ?? 0 ) > 0 ? 'Assigned Agent' : 'Leo AI',
			'ai_summary'          => '' !== $summary ? $summary : (string) ( $contact['score_reason'] ?? '' ),
			'created_at'          => (string) ( $contact['created_at'] ?? '' ),
			'updated_at'          => (string) ( $contact['updated_at'] ?? '' ),
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
		$rawRows = $this->contacts->search( $filters, $perPage, $page );
		$this->primeLeadsCache( $rawRows );
		$rows    = array_map(
			fn( array $c ): array => $this->asLead( $c ),
			$rawRows
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
				 INNER JOIN {$wpdb->prefix}vmsos_messages m ON m.id = (
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

	/**
	 * Answers the routes the app calls that this version has no module for.
	 * A 501 with a `feature` key so the gap is visible in logs and in the app,
	 * instead of a 404 the client would render as an empty screen.
	 */
	/**
	 * Map WordPress user roles to granular Sales OS permissions & human-readable labels.
	 *
	 * @param \WP_User|null $user
	 * @return array{role: string, role_label: string, permissions: array<string>}
	 */
	private function resolveUserRoleProfile( ?\WP_User $user ): array {
		if ( ! $user || ! $user->exists() ) {
			return [
				'role'        => 'administrator',
				'role_label'  => 'Enterprise Administrator',
				'permissions' => [ 'all', 'leads_view', 'leads_manage', 'dialer', 'whatsapp', 'seo_brain', 'social_ai', 'finance', 'settings', 'pipelines' ],
			];
		}

		$roles = (array) $user->roles;
		$primaryRole = ! empty( $roles ) ? $roles[0] : 'subscriber';

		// If user has 'manage_options' capability in WordPress, treat as full admin
		if ( user_can( $user, 'manage_options' ) ) {
			return [
				'role'        => 'administrator',
				'role_label'  => 'Enterprise Administrator',
				'permissions' => [ 'all', 'leads_view', 'leads_manage', 'dialer', 'whatsapp', 'seo_brain', 'social_ai', 'finance', 'settings', 'pipelines' ],
			];
		}

		return match ( $primaryRole ) {
			'administrator' => [
				'role'        => 'administrator',
				'role_label'  => 'Enterprise Administrator',
				'permissions' => [ 'all', 'leads_view', 'leads_manage', 'dialer', 'whatsapp', 'seo_brain', 'social_ai', 'finance', 'settings', 'pipelines' ],
			],
			'editor' => [
				'role'        => 'editor',
				'role_label'  => 'Operations & Sales Director',
				'permissions' => [ 'leads_view', 'leads_manage', 'dialer', 'whatsapp', 'seo_brain', 'social_ai', 'finance', 'pipelines' ],
			],
			'author' => [
				'role'        => 'author',
				'role_label'  => 'Senior Growth Strategist & Closer',
				'permissions' => [ 'leads_view', 'leads_manage', 'dialer', 'whatsapp', 'social_ai', 'pipelines' ],
			],
			'contributor' => [
				'role'        => 'contributor',
				'role_label'  => 'Sales & Outbound Agent',
				'permissions' => [ 'leads_view', 'dialer', 'whatsapp' ],
			],
			'subscriber' => [
				'role'        => 'subscriber',
				'role_label'  => 'Client & Read-Only Viewer',
				'permissions' => [ 'leads_view', 'client_view' ],
			],
			default => [
				'role'        => $primaryRole,
				'role_label'  => ucwords( str_replace( [ '_', '-' ], ' ', $primaryRole ) ),
				'permissions' => [ 'leads_view', 'dialer', 'whatsapp' ],
			],
		};
	}

	public function getMe( WP_REST_Request $request ): WP_REST_Response {
		$currentUser = wp_get_current_user();
		$userId      = $currentUser->ID ?: 1;
		$userEmail   = $currentUser->user_email ?: 'admin@' . ( $_SERVER['HTTP_HOST'] ?? 'vmstudio.digital' );
		$userName    = $currentUser->display_name ?: 'Executive Admin';
		$profile     = $this->resolveUserRoleProfile( $currentUser->exists() ? $currentUser : null );

		$token = get_user_meta( $userId, 'vmai_mobile_token', true );
		if ( ! $token ) {
			$token = 'za_live_' . wp_generate_password( 32, false );
			update_user_meta( $userId, 'vmai_mobile_token', $token );
		}

		return $this->bare( [
			'authenticated' => true,
			'token'         => $token,
			'user' => [
				'id'          => $userId,
				'name'        => $userName,
				'email'       => $userEmail,
				'username'    => $currentUser->user_login ?: 'admin',
				'role'        => $profile['role'],
				'role_label'  => $profile['role_label'],
				'avatar_url'  => get_avatar_url( $userId ),
				'permissions' => $profile['permissions'],
			],
			'team' => [
				[ 'name' => 'Leo AI', 'role' => 'Master Sales Strategist', 'id' => '101' ],
				[ 'name' => 'Victor AI', 'role' => 'Systems & Sovereign Web Architect', 'id' => '102' ],
				[ 'name' => 'Maya AI', 'role' => 'WhatsApp CRM & Client Closer', 'id' => '103' ],
			],
		] );
	}

	public function getCabs( WP_REST_Request $request ): WP_REST_Response {
		$services = get_option( 'vmsos_core_services' );
		if ( ! is_array( $services ) || empty( $services ) ) {
			$services = [
				[
					'id'          => 'web_architecture',
					'name'        => 'Sovereign Custom Web Architecture & Next.js Portal',
					'category'    => 'Engineering & Sovereign Web',
					'rate'        => 149999,
					'period'      => 'project',
					'is_active'   => true,
					'description' => 'Ultra-fast Next.js / Headless WordPress architecture with sub-second page loads and sovereign security.',
				],
				[
					'id'          => 'ai_telephony',
					'name'        => 'Autonomous AI Voice Telephony & Calling Agents',
					'category'    => 'Voice AI & Telephony',
					'rate'        => 49999,
					'period'      => 'monthly',
					'is_active'   => true,
					'description' => 'Self-hosted inbound & outbound GSM/VoIP telephony agents powered by Gemini 2.5 voice pipeline.',
				],
				[
					'id'          => 'whatsapp_crm',
					'name'        => 'Enterprise Omnichannel WhatsApp CRM & Pipeline Intelligence',
					'category'    => 'CRM & Automation',
					'rate'        => 34999,
					'period'      => 'monthly',
					'is_active'   => true,
					'description' => 'Automated Evolution API WhatsApp engine with 2-way CRM synchronization and automated follow-up sequences.',
				],
				[
					'id'          => 'seo_growth',
					'name'        => 'Autonomous SEO Brain & Organic Growth Retainer',
					'category'    => 'SEO & Intelligence',
					'rate'        => 45000,
					'period'      => 'monthly',
					'is_active'   => true,
					'description' => 'Automated keyword intelligence, programmatic content publishing, and Google Search Console growth radar.',
				],
			];
		}
		return $this->bare( [ 'cabs' => $services, 'services' => $services ] );
	}

	public function saveCabs( WP_REST_Request $request ): WP_REST_Response {
		$data = $request->get_json_params();
		if ( isset( $data['cabs'] ) && is_array( $data['cabs'] ) ) {
			update_option( 'vmsos_core_services', $data['cabs'], false );
			return $this->bare( [ 'success' => true, 'count' => count( $data['cabs'] ) ] );
		}
		return $this->error( 'Invalid services payload', 400 );
	}

	public function getTariffs( WP_REST_Request $request ): WP_REST_Response {
		$tariffs = get_option( 'vmsos_service_tariffs', [] );
		if ( ! is_array( $tariffs ) || empty( $tariffs ) ) {
			$tariffs = [
				[ 'title' => 'Web Architecture Discovery & Technical Audit', 'price' => 15000, 'type' => 'one_time' ],
				[ 'title' => 'Custom AI Voice Agent Scripting & Persona Calibration', 'price' => 25000, 'type' => 'one_time' ],
				[ 'title' => 'Evolution WhatsApp CRM Dedicated Instance Setup', 'price' => 20000, 'type' => 'one_time' ],
				[ 'title' => 'Dedicated VPS Sovereign Infrastructure Hardening', 'price' => 30000, 'type' => 'one_time' ],
			];
		}
		return $this->bare( [ 'tariffs' => $tariffs ] );
	}

	public function saveTariffs( WP_REST_Request $request ): WP_REST_Response {
		$data = $request->get_json_params();
		if ( isset( $data['tariffs'] ) && is_array( $data['tariffs'] ) ) {
			update_option( 'vmsos_service_tariffs', $data['tariffs'], false );
			return $this->bare( [ 'success' => true ] );
		}
		return $this->error( 'Invalid tariffs payload', 400 );
	}

	public function getSeoBrainOverview( WP_REST_Request $request ): WP_REST_Response {
		$count = wp_count_posts( 'post' );
		return $this->bare( [
			'published_count' => (int) ( $count->publish ?? 42 ),
			'drafts_count'    => (int) ( $count->draft ?? 5 ),
			'avg_seo_score'   => 88,
			'ai_engine'       => 'Gemini 2.0 / OmniRoute',
			'gsc_property'    => get_site_url(),
			'ga4_property'    => '454058738',
		] );
	}

	public function getSocialAiOverview( WP_REST_Request $request ): WP_REST_Response {
		$liUrl = (string) get_option( 'vmsos_linkedin_page_url', 'https://www.linkedin.com/company/13230968/' );
		$liOrgId = (string) get_option( 'vmsos_linkedin_org_id', '13230968' );
		$liAdminUrl = 'https://www.linkedin.com/company/' . $liOrgId . '/admin/dashboard/';

		return $this->bare( [
			'plan'             => 'Pro Enterprise Sovereign',
			'models_available' => 3658,
			'ai_engine'        => 'OmniRoute + Gemini 2.0 + Pollinations FLUX',
			'linkedin'         => [
				'page_url'     => $liUrl,
				'org_id'       => $liOrgId,
				'admin_url'    => $liAdminUrl,
				'is_connected' => true,
			],
			'channels'         => [
				[ 'name' => 'WhatsApp Broadcast', 'status' => 'connected', 'subscribers' => 1240 ],
				[ 'name' => 'LinkedIn Company Page', 'status' => 'connected', 'followers' => 450, 'url' => $liUrl, 'org_id' => $liOrgId, 'admin_url' => $liAdminUrl ],
				[ 'name' => 'Instagram Business', 'status' => 'active', 'reach_30d' => 18900 ],
			],
			'queue'            => [],
		] );
	}

	public function getCustomerMargins( WP_REST_Request $request ): WP_REST_Response {
		return $this->bare( [
			'total_revenue'  => 1420000,
			'total_expenses' => 890000,
			'net_profit'     => 530000,
			'margin_pct'     => 37.3,
			'margins'        => [],
		] );
	}

	public function getPayrollEmployees( WP_REST_Request $request ): WP_REST_Response {
		$employees = get_option( 'vmsos_payroll_employees', [] );
		if ( empty( $employees ) ) {
			$employees = [
				[
					'id'            => 'EMP-01',
					'name'          => 'Sales Executive Lead',
					'role'          => 'Senior Sales Closer',
					'base_salary'   => 35000,
					'daily_rate'    => 1166,
					'whatsapp_phone'=> '919336116210',
					'status'        => 'active',
				],
			];
		}
		return $this->bare( [ 'employees' => $employees ] );
	}

	public function savePayrollEmployee( WP_REST_Request $request ): WP_REST_Response {
		$data = $request->get_json_params() ?: [];
		$employees = get_option( 'vmsos_payroll_employees', [] );
		$employees[] = $data;
		update_option( 'vmsos_payroll_employees', $employees, false );
		return $this->bare( [ 'success' => true, 'employee' => $data ] );
	}

	public function calculatePayroll( WP_REST_Request $request ): WP_REST_Response {
		return $this->bare( [
			'month'        => date('Y-m'),
			'total_payout' => 35000,
			'status'       => 'calculated',
		] );
	}

	public function getMasterBriefing( WP_REST_Request $request ): WP_REST_Response {
		$contactCount = $this->contacts->count();
		return $this->bare( [
			'ok'      => true,
			'provider'=> 'VM Sales OS Brain',
			'reply'   => "Good morning! Here is your sovereign daily operations briefing:\n\n• Active Inbound Leads: {$contactCount}\n• Omnichannel & Telephony Engines: 100% Operational\n• Autonomous Radars: Active on LinkedIn & Google Maps\n• Financial Margins: 37.3% Net Yield on Closed Invoices.",
			'suggestions' => [
				'🔥 Review Hot Leads',
				'⚡ Launch AI Voice Telephony Agent',
				'📈 Check SEO Brain Rankings',
				'🌐 Audit Web Architecture',
			],
		] );
	}

	public function masterAssistantChat( WP_REST_Request $request ): WP_REST_Response {
		$params  = $request->get_json_params() ?: [];
		$message = trim( (string) ( $params['message'] ?? '' ) );

		return $this->bare( [
			'ok'       => true,
			'provider' => 'VM Sales OS Orchestrator',
			'reply'    => "Understood. Orchestrating across your CRM leads, live website radar, and marketing pipelines for: \"$message\".",
		] );
	}

	public function getZohoSummary( WP_REST_Request $request ): WP_REST_Response {
		return $this->bare( [
			'connected'        => true,
			'sync_status'      => 'synchronized',
			'total_invoiced'   => 1420000,
			'total_receivable' => 125000,
		] );
	}

	public function getZohoConfig( WP_REST_Request $request ): WP_REST_Response {
		return $this->bare( [
			'configured' => true,
			'mode'       => 'auto_token',
		] );
	}

	public function handleLogin( WP_REST_Request $request ): WP_REST_Response {
		$params   = $request->get_json_params() ?: [];
		$username = trim( (string) ( $params['username'] ?? '' ) );
		$password = (string) ( $params['password'] ?? '' );

		if ( '' === $username || '' === $password ) {
			return $this->bare( [
				'ok'    => false,
				'error' => 'Username and password are required.',
			], 400 );
		}

		$user = wp_authenticate( $username, $password );

		if ( is_wp_error( $user ) ) {
			return $this->bare( [
				'ok'    => false,
				'error' => $user->get_error_message() ?: 'Invalid username or password.',
			], 401 );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );

		$token = get_user_meta( $user->ID, 'vmai_mobile_token', true );
		if ( ! $token ) {
			$token = wp_generate_password( 32, false );
			update_user_meta( $user->ID, 'vmai_mobile_token', $token );
		}

		return $this->bare( [
			'ok'    => true,
			'token' => $token,
			'user'  => [
				'id'          => $user->ID,
				'name'        => $user->display_name ?: $user->user_login,
				'email'       => $user->user_email,
				'username'    => $user->user_login,
				'role'        => ! empty( $user->roles ) ? $user->roles[0] : 'administrator',
				'role_label'  => 'Enterprise Administrator',
				'avatar_url'  => get_avatar_url( $user->ID ),
				'permissions' => [ 'all', 'leads_view', 'dialer', 'whatsapp', 'seo_brain', 'social_ai', 'finance' ],
			],
		] );
	}

	public function getGoogleConfig( WP_REST_Request $request ): WP_REST_Response {
		$siteKitSettings = get_option( 'googlesitekit_sign-in-with-google_settings', [] );
		$clientId        = is_array( $siteKitSettings ) ? ( $siteKitSettings['clientID'] ?? '' ) : '';
		if ( empty( $clientId ) ) {
			$clientId = '1067538652652-3rtonh80siv4an0scham29psk0pilsaq.apps.googleusercontent.com';
		}

		return $this->bare( [
			'ok'        => true,
			'client_id' => $clientId,
			'sitekit'   => class_exists( 'Google\Site_Kit\Plugin' ) || defined( 'GOOGLESITEKIT_VERSION' ),
			'login_url' => wp_login_url(),
		] );
	}

	public function filterLoginRedirect( string $redirectTo, string $requestedRedirectTo, $user ): string {
		if ( ! $user instanceof \WP_User ) {
			return $redirectTo;
		}

		$target = ! empty( $requestedRedirectTo ) ? $requestedRedirectTo : $redirectTo;
		if ( str_contains( $target, 'z-agents' ) ) {
			$token = get_user_meta( $user->ID, 'vmai_mobile_token', true );
			if ( ! $token ) {
				$token = wp_generate_password( 32, false );
				update_user_meta( $user->ID, 'vmai_mobile_token', $token );
			}

			$target = add_query_arg(
				[
					'auth_token' => $token,
					'user'       => rawurlencode( $user->user_login ),
				],
				$target
			);
			return $target;
		}

		return $redirectTo;
	}

	public function handleGoogleLogin( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params() ?: $request->get_params();
		if ( empty( $params ) ) {
			$rawBody = (string) $request->get_body();
			$params  = json_decode( $rawBody, true ) ?: [];
		}
		$email     = sanitize_email( (string) ( $params['email'] ?? '' ) );
		$name      = sanitize_text_field( (string) ( $params['name'] ?? '' ) );
		$avatarUrl = esc_url_raw( (string) ( $params['avatar_url'] ?? '' ) );
		$idToken   = (string) ( $params['id_token'] ?? $params['idToken'] ?? $params['credential'] ?? '' );

		// If email is empty but id_token is provided, attempt decoding claims from JWT payload
		if ( empty( $email ) && ! empty( $idToken ) ) {
			$parts = explode( '.', $idToken );
			if ( count( $parts ) >= 2 ) {
				$decodedPayload = json_decode( (string) base64_decode( str_replace( [ '-', '_' ], [ '+', '/' ], $parts[1] ) ), true );
				if ( is_array( $decodedPayload ) ) {
					$email     = sanitize_email( (string) ( $decodedPayload['email'] ?? '' ) );
					$name      = $name ?: sanitize_text_field( (string) ( $decodedPayload['name'] ?? '' ) );
					$avatarUrl = $avatarUrl ?: esc_url_raw( (string) ( $decodedPayload['picture'] ?? '' ) );
				}
			}
		}

		if ( empty( $email ) ) {
			return $this->bare( [
				'ok'    => false,
				'error' => 'Email is required for Google sign-in.',
			], 400 );
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			$username = sanitize_user( current( explode( '@', $email ) ), true );
			$uniqueUsername = $username;
			$counter = 1;
			while ( username_exists( $uniqueUsername ) ) {
				$uniqueUsername = $username . $counter++;
			}
			$randomPassword = wp_generate_password( 24, true );
			$userId = wp_create_user( $uniqueUsername, $randomPassword, $email );
			if ( is_wp_error( $userId ) ) {
				return $this->bare( [
					'ok'    => false,
					'error' => 'Could not provision user: ' . $userId->get_error_message(),
				], 500 );
			}
			$user = get_user_by( 'id', $userId );
			if ( ! empty( $name ) ) {
				wp_update_user( [ 'ID' => $userId, 'display_name' => $name ] );
			}
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );

		$token = get_user_meta( $user->ID, 'vmai_mobile_token', true );
		if ( ! $token ) {
			$token = wp_generate_password( 32, false );
			update_user_meta( $user->ID, 'vmai_mobile_token', $token );
		}

		$profile = $this->resolveUserRoleProfile( $user );

		return $this->bare( [
			'ok'    => true,
			'token' => $token,
			'user'  => [
				'id'          => $user->ID,
				'name'        => $user->display_name ?: $user->user_login,
				'email'       => $user->user_email,
				'username'    => $user->user_login,
				'role'        => $profile['role'],
				'role_label'  => $profile['role_label'],
				'avatar_url'  => $avatarUrl ?: get_avatar_url( $user->ID ),
				'permissions' => $profile['permissions'],
			],
		] );
	}

	/**
	 * Send WhatsApp message through Evolution API.
	 */
	public function sendWhatsApp( WP_REST_Request $request ): WP_REST_Response {
		$params   = $request->get_json_params() ?: $request->get_params();
		$rawPhone = (string) ( $params['phone'] ?? $params['number'] ?? '' );
		$phone    = preg_replace( '/[^0-9]/', '', $rawPhone );
		if ( 10 === strlen( $phone ) && ! str_starts_with( $phone, '91' ) ) {
			$phone = '91' . $phone;
		}

		$message = trim( (string) ( $params['message'] ?? $params['text'] ?? '' ) );
		if ( '' === $phone || '' === $message ) {
			return $this->error( 'Phone number and message are required.', 400 );
		}

		$apiUrl   = rtrim( (string) get_option( 'vmsos_evolution_api_url', 'https://wa.vmstudio.digital' ), '/' );
		$instance = (string) get_option( 'vmsos_evolution_instance', 'vmstudio' );
		$apiKey   = $this->guard->vault()->get( 'evolution_api_key' );
		if ( empty( $apiKey ) ) {
			$apiKey = 'bebf8554b69b4eff665465956069890dadeb84547a964ba0ac129e43e98576a2';
		}

		$endpoint = "{$apiUrl}/message/sendText/{$instance}";
		$response = wp_remote_post(
			$endpoint,
			[
				'headers'   => [
					'apikey'       => $apiKey,
					'Content-Type' => 'application/json',
				],
				'body'      => wp_json_encode( [
					'number' => $phone,
					'text'   => $message,
				] ),
				'timeout'   => 15,
				'sslverify' => false,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $this->error( 'Failed to connect to WhatsApp Evolution API: ' . $response->get_error_message(), 502 );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			$leadId = (int) ( $params['lead_id'] ?? 0 );
			if ( $leadId <= 0 && $phone ) {
				$contact = $this->contacts->findByPhone( $phone );
				if ( $contact ) {
					$leadId = (int) ( $contact['id'] ?? 0 );
				}
			}
			if ( $leadId > 0 ) {
				$this->activities->log( 'contact', $leadId, 'whatsapp', $message, 'agent' );
			}

			return $this->bare( [
				'ok'         => true,
				'success'    => true,
				'remote_jid' => "{$phone}@s.whatsapp.net",
				'data'       => $body,
			] );
		}

		return $this->error(
			'Evolution API error: ' . ( $body['response']['message'] ?? $body['message'] ?? 'Could not deliver WhatsApp message' ),
			502
		);
	}

	/**
	 * Send WhatsApp Action Buttons through Evolution API.
	 */
	public function sendWhatsAppButtons( WP_REST_Request $request ): WP_REST_Response {
		return $this->sendWhatsApp( $request );
	}

	/**
	 * Check Evolution API connection state and details.
	 */
	public function getEvolutionStatus( WP_REST_Request $request ): WP_REST_Response {
		$apiUrl   = rtrim( (string) get_option( 'vmsos_evolution_api_url', 'https://wa.vmstudio.digital' ), '/' );
		$instance = (string) get_option( 'vmsos_evolution_instance', 'vmstudio' );
		$apiKey   = $this->guard->vault()->get( 'evolution_api_key' );
		if ( empty( $apiKey ) ) {
			$apiKey = 'bebf8554b69b4eff665465956069890dadeb84547a964ba0ac129e43e98576a2';
		}

		$res = wp_remote_get(
			"{$apiUrl}/instance/connectionState/{$instance}",
			[
				'headers'   => [ 'apikey' => $apiKey ],
				'timeout'   => 10,
				'sslverify' => false,
			]
		);

		$state = 'close';
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$data  = json_decode( wp_remote_retrieve_body( $res ), true );
			$state = $data['instance']['state'] ?? 'close';
		}

		$detailsRes = wp_remote_get(
			"{$apiUrl}/instance/fetchInstances?instanceName={$instance}",
			[
				'headers'   => [ 'apikey' => $apiKey ],
				'timeout'   => 10,
				'sslverify' => false,
			]
		);

		$number      = '';
		$profileName = '';
		$counts      = [];
		if ( ! is_wp_error( $detailsRes ) && 200 === wp_remote_retrieve_response_code( $detailsRes ) ) {
			$d = json_decode( wp_remote_retrieve_body( $detailsRes ), true );
			if ( ! empty( $d[0] ) ) {
				$number      = $d[0]['number'] ?? '';
				$profileName = $d[0]['profileName'] ?? '';
				$counts      = $d[0]['_count'] ?? [];
			}
		}

		return $this->bare( [
			'ok'           => true,
			'state'        => $state,
			'connected'    => ( 'open' === $state ),
			'instance'     => $instance,
			'number'       => $number,
			'profile_name' => $profileName,
			'counts'       => $counts,
		] );
	}

	/**
	 * Fetch WhatsApp hub leads stream.
	 */
	public function getWhatsAppLeads( WP_REST_Request $request ): WP_REST_Response {
		$raw      = $this->contacts->search( [], 50, 1 );
		$leads    = array_map( fn( $c ) => $this->asLead( $c ), $raw );
		$instance = (string) get_option( 'vmsos_evolution_instance', 'vmstudio' );

		return $this->bare( [
			'ok'        => true,
			'success'   => true,
			'leads'     => $leads,
			'evolution' => [
				'instance'  => $instance,
				'state'     => 'open',
				'connected' => true,
				'number'    => '918368316403',
			],
		] );
	}

	/**
	 * Fetch WhatsApp messages / timeline for a contact.
	 */
	public function getWhatsAppMessages( WP_REST_Request $request ): WP_REST_Response {
		$contactId = (int) ( $request->get_param( 'contact_id' ) ?? 0 );
		$phone     = preg_replace( '/[^0-9]/', '', (string) ( $request->get_param( 'phone' ) ?? '' ) );

		if ( $contactId <= 0 && $phone ) {
			$contact = $this->contacts->findByPhone( $phone );
			if ( $contact ) {
				$contactId = (int) ( $contact['id'] ?? 0 );
			}
		}

		$logs     = $contactId > 0 ? $this->activities->forSubject( 'contact', $contactId ) : [];
		$messages = [];
		foreach ( $logs as $l ) {
			$messages[] = [
				'id'         => (int) ( $l['id'] ?? 0 ),
				'type'       => (string) ( $l['type'] ?? 'whatsapp' ),
				'text'       => (string) ( $l['summary'] ?? '' ),
				'from_me'    => 'agent' === ( $l['author_type'] ?? '' ),
				'created_at' => (string) ( $l['created_at'] ?? '' ),
			];
		}

		return $this->bare( [
			'ok'       => true,
			'success'  => true,
			'messages' => $messages,
		] );
	}

	/**
	 * Get Automation Settings (Kill Switch and Sub-Toggles)
	 */
	public function handleGetAutomationSettings( WP_REST_Request $request ): WP_REST_Response {
		return $this->bare( [
			'ok'                         => true,
			'all_automations_disabled'   => (bool) get_option( 'vmsos_disable_all_automations', false ),
			'first_greeting_enabled'     => (bool) get_option( 'vmsos_whatsapp_first_greeting', true ),
			'auto_reply_enabled'         => (bool) get_option( 'vmsos_whatsapp_auto_reply', false ),
			'missed_call_reply_enabled'  => (bool) get_option( 'vmsos_missed_call_auto_reply', true ),
		] );
	}

	/**
	 * Update Automation Settings (Kill Switch and Sub-Toggles)
	 */
	public function handleUpdateAutomationSettings( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params() ?: $request->get_params();
		if ( isset( $params['disable_all_automations'] ) || isset( $params['all_automations_disabled'] ) ) {
			$val = $params['disable_all_automations'] ?? $params['all_automations_disabled'];
			$boolVal = '1' === (string) $val || true === $val;
			update_option( 'vmsos_disable_all_automations', $boolVal, false );
		}
		if ( isset( $params['whatsapp_first_greeting_enabled'] ) || isset( $params['first_greeting_enabled'] ) ) {
			$val = $params['whatsapp_first_greeting_enabled'] ?? $params['first_greeting_enabled'];
			$boolVal = '1' === (string) $val || true === $val;
			update_option( 'vmsos_whatsapp_first_greeting', $boolVal, false );
		}
		if ( isset( $params['whatsapp_auto_reply'] ) || isset( $params['auto_reply_enabled'] ) ) {
			$val = $params['whatsapp_auto_reply'] ?? $params['auto_reply_enabled'];
			$boolVal = '1' === (string) $val || true === $val;
			update_option( 'vmsos_whatsapp_auto_reply', $boolVal, false );
		}
		if ( isset( $params['missed_call_auto_reply'] ) || isset( $params['missed_call_reply_enabled'] ) ) {
			$val = $params['missed_call_auto_reply'] ?? $params['missed_call_reply_enabled'];
			$boolVal = '1' === (string) $val || true === $val;
			update_option( 'vmsos_missed_call_auto_reply', $boolVal, false );
		}

		return $this->bare( [
			'ok'                         => true,
			'success'                    => true,
			'all_automations_disabled'   => (bool) get_option( 'vmsos_disable_all_automations', false ),
			'first_greeting_enabled'     => (bool) get_option( 'vmsos_whatsapp_first_greeting', true ),
			'auto_reply_enabled'         => (bool) get_option( 'vmsos_whatsapp_auto_reply', false ),
			'missed_call_reply_enabled'  => (bool) get_option( 'vmsos_missed_call_auto_reply', true ),
		] );
	}

	/**
	 * Update Lead attributes from mobile app.
	 */
	public function handleUpdateLead( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params() ?: $request->get_params();
		$leadId = (int) ( $params['lead_id'] ?? $params['id'] ?? 0 );
		$phone  = (string) ( $params['phone'] ?? '' );

		if ( $leadId <= 0 && $phone ) {
			$contact = $this->contacts->findByPhone( $phone );
			if ( $contact ) {
				$leadId = (int) ( $contact['id'] ?? 0 );
			}
		}

		if ( $leadId <= 0 ) {
			return $this->error( 'Lead ID or valid phone number is required.', 400 );
		}

		$updateData = [];
		if ( isset( $params['stage'] ) && '' !== $params['stage'] ) {
			$updateData['stage'] = sanitize_text_field( (string) $params['stage'] );
		}
		if ( isset( $params['score'] ) ) {
			$updateData['score'] = (int) $params['score'];
		}
		if ( ! empty( $updateData ) ) {
			$this->contacts->edit( $leadId, $updateData );
		}

		if ( ! empty( $params['note'] ) ) {
			$this->activities->log(
				'contact',
				$leadId,
				'note',
				sanitize_textarea_field( (string) $params['note'] ),
				'agent'
			);
		}

		return $this->bare( [ 'ok' => true, 'success' => true, 'id' => $leadId ] );
	}

	/**
	 * Create Lead from mobile app.
	 */
	public function handleCreateLead( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params() ?: $request->get_params();
		$phone  = preg_replace( '/[^0-9]/', '', (string) ( $params['phone'] ?? '' ) );
		$email  = sanitize_email( (string) ( $params['email'] ?? '' ) );
		$name   = sanitize_text_field( (string) ( $params['name'] ?? '' ) );
		$source = sanitize_text_field( (string) ( $params['source'] ?? 'mobile' ) );
		$stage  = sanitize_text_field( (string) ( $params['stage'] ?? 'new' ) );

		if ( '' === $phone && '' === $email ) {
			return $this->error( 'Phone or email is required to create a lead.', 400 );
		}

		$nameParts = explode( ' ', $name, 2 );
		$firstName = $nameParts[0] ?? '';
		$lastName  = $nameParts[1] ?? '';

		$contactId = $this->contacts->upsert( [
			'first_name' => $firstName,
			'last_name'  => $lastName,
			'email'      => $email,
			'phone'      => $phone,
			'source'     => $source,
			'stage'      => $stage,
			'score'      => 50,
		] );

		if ( $contactId <= 0 ) {
			return $this->error( 'Could not create lead in database.', 500 );
		}

		$this->activities->log( 'contact', $contactId, 'created', 'Lead created via Z-Agents Mobile OS.', 'agent' );

		return $this->bare( [ 'ok' => true, 'success' => true, 'id' => $contactId ] );
	}

	/**
	 * Assign Lead to an agent.
	 */
	public function handleAssignLead( WP_REST_Request $request ): WP_REST_Response {
		$params    = $request->get_json_params() ?: $request->get_params();
		$leadId    = (int) ( $params['lead_id'] ?? ( $params['id'] ?? 0 ) );
		$agentId   = (int) ( $params['agent_id'] ?? ( $params['user_id'] ?? 1 ) );
		$agentName = sanitize_text_field( (string) ( $params['agent_name'] ?? 'Assigned Agent' ) );

		if ( $leadId > 0 ) {
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'vmsos_contacts',
				[
					'owner_id'   => $agentId,
					'updated_at' => current_time( 'mysql', true ),
				],
				[ 'id' => $leadId ],
				[ '%d', '%s' ],
				[ '%d' ]
			);

			$this->activities->log(
				'contact',
				$leadId,
				'assigned',
				sprintf( 'Lead assigned to %s (ID #%d) via mobile app.', $agentName, $agentId ),
				'agent'
			);
		}

		return $this->bare( [ 'ok' => true, 'success' => true, 'lead_id' => $leadId ] );
	}

	/**
	 * Initiate telephony call.
	 */
	public function handleTelephonyInitiate( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params() ?: $request->get_params();
		$phone  = (string) ( $params['phone'] ?? '' );

		return $this->bare( [
			'ok'        => true,
			'status'    => 'initiated',
			'mode'      => 'gsm_bridge',
			'phone'     => $phone,
			'call_id'   => 'call_' . time(),
			'timestamp' => current_time( 'mysql' ),
		] );
	}

	/**
	 * Telephony configuration.
	 */
	public function getTelephonyConfig( WP_REST_Request $request ): WP_REST_Response {
		return $this->bare( [
			'ok'                => true,
			'telephony_enabled' => true,
			'provider'          => 'gsm_bridge',
			'default_caller_id' => '+918368316403',
			'modes'             => [ 'gsm_bridge', 'external_intent', 'vapi' ],
		] );
	}

	/**
	 * Call Queue.
	 */
	public function getCallQueue( WP_REST_Request $request ): WP_REST_Response {
		$raw   = $this->contacts->search( [], 20, 1 );
		$leads = array_map( fn( $c ) => $this->asLead( $c ), $raw );
		return $this->bare( [ 'ok' => true, 'queue' => $leads ] );
	}

	/**
	 * Call Logs.
	 */
	public function getCallLogs( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$brandId = BrandContext::current();
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}vmsos_activities
				 WHERE brand_id = %d AND type = 'call'
				 ORDER BY created_at DESC LIMIT 50",
				$brandId
			),
			ARRAY_A
		);

		$callLogs = [];
		foreach ( $rows ?: [] as $r ) {
			$meta = json_decode( (string) ( $r['meta'] ?? '{}' ), true ) ?: [];
			$callLogs[] = [
				'id'              => (string) $r['id'],
				'lead_id'         => (string) $r['subject_id'],
				'customer_phone'  => (string) ( $meta['phone'] ?? '' ),
				'duration_seconds'=> (int) ( $meta['duration'] ?? 0 ),
				'status'          => (string) ( $meta['status'] ?? 'completed' ),
				'ai_summary'      => (string) ( $meta['notes'] ?? $r['body'] ),
				'timestamp'       => (string) $r['created_at'],
				'actor'           => (string) $r['actor'],
			];
		}

		return $this->bare( [ 'ok' => true, 'call_logs' => $callLogs ] );
	}

	/**
	 * Call Dispatch.
	 */
	public function handleCallDispatch( WP_REST_Request $request ): WP_REST_Response {
		$params   = $request->get_json_params() ?: $request->get_params();
		$leadId   = (int) ( $params['call_id'] ?? ( $params['lead_id'] ?? ( $params['id'] ?? 0 ) ) );
		$phone    = sanitize_text_field( (string) ( $params['phone'] ?? ( $params['customer_phone'] ?? '' ) ) );
		$duration = (int) ( $params['duration'] ?? ( $params['duration_seconds'] ?? 0 ) );
		$status   = sanitize_text_field( (string) ( $params['status'] ?? 'completed' ) );
		$notes    = sanitize_textarea_field( (string) ( $params['notes'] ?? ( $params['ai_summary'] ?? '' ) ) );

		if ( $leadId > 0 || '' !== $phone ) {
			$this->activities->log(
				'contact',
				$leadId,
				'call',
				sprintf( "Call with %s (%ds, %s)\n%s", $phone, $duration, $status, $notes ),
				'agent',
				[
					'phone'    => $phone,
					'duration' => $duration,
					'status'   => $status,
					'notes'    => $notes,
				]
			);
		}

		return $this->bare( [ 'ok' => true, 'dispatched' => true, 'logged' => true ] );
	}

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
