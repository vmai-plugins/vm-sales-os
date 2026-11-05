<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\REST;

use VMStudio\SalesOS\AI\ProviderManager;
use VMStudio\SalesOS\AI\UsageMeter;
use VMStudio\SalesOS\Core\ModuleRegistry;
use VMStudio\SalesOS\Database\Migrator;
use VMStudio\SalesOS\Queue\QueueManager;
use WP_REST_Request;

/**
 * Health, modules and AI status for the admin dashboard.
 */
final class SystemController extends AbstractController {

	public function __construct(
		private ProviderManager $providers,
		private UsageMeter $meter,
		private QueueManager $queue,
		private ModuleRegistry $modules
	) {}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/system/health',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'health' ],
				'permission_callback' => fn() => $this->canOperate(),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/system/blueprints',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'listBlueprints' ],
					'permission_callback' => fn() => $this->canOperate(),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'applyBlueprint' ],
					'permission_callback' => fn() => $this->canManage(),
					'args'                => [
						'blueprint' => [ 'required' => true, 'type' => 'string' ],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/system/modules',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'listModules' ],
					'permission_callback' => fn() => $this->canManage(),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'toggleModule' ],
					'permission_callback' => fn() => $this->canManage(),
					'args'                => [
						'slug'   => [ 'required' => true, 'type' => 'string' ],
						'active' => [ 'required' => true, 'type' => 'boolean' ],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/master/chat',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'masterChat' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			'vmai/v1',
			'/master/chat',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'masterChat' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function health( WP_REST_Request $request ) {
		return $this->ok(
			[
				'version'      => VMSOS_VERSION,
				'schema'       => (int) get_option( 'vmsos_db_version', 0 ),
				'schema_target'=> Migrator::SCHEMA_VERSION,
				'php'          => PHP_VERSION,
				'wp'           => get_bloginfo( 'version' ),
				'object_cache' => wp_using_ext_object_cache(),
				'cron_next'    => (int) wp_next_scheduled( 'vmsos_queue_tick' ),
				'providers'    => $this->providers->status(),
				'queue'        => $this->queue->stats(),
				'ai_usage'     => $this->meter->summary( 7 ),
			]
		);
	}

	public function listModules( WP_REST_Request $request ) {
		$out = [];
		foreach ( $this->modules->available() as $slug => $class ) {
			$out[] = [
				'slug'     => $slug,
				'label'    => $class::label(),
				'requires' => $class::requires(),
				'active'   => $this->modules->isActive( $slug ),
			];
		}
		return $this->ok( $out );
	}

	public function toggleModule( WP_REST_Request $request ) {
		$slug = $this->param( $request, 'slug' );
		if ( ! isset( $this->modules->available()[ $slug ] ) ) {
			return $this->fail( 'vmsos_unknown_module', __( 'That module does not exist.', 'vm-sales-os' ), 404 );
		}

		$this->modules->setActive( $slug, (bool) $request->get_param( 'active' ) );
		return $this->ok( [ 'slug' => $slug, 'active' => $this->modules->isActive( $slug ) ] );
	}

	public function listBlueprints( WP_REST_Request $request ) {
		return $this->ok(
			[
				'current'    => (string) get_option( 'vmsos_industry_preset', 'creative_agency' ),
				'blueprints' => array_values( \VMStudio\SalesOS\Modules\Agents\IndustryPresets::all() ),
			]
		);
	}

	public function applyBlueprint( WP_REST_Request $request ) {
		$key = sanitize_key( (string) $request->get_param( 'blueprint' ) );
		$success = \VMStudio\SalesOS\Modules\Agents\IndustryPresets::apply( $key );

		if ( ! $success ) {
			return $this->fail( 'vmsos_invalid_blueprint', __( 'Invalid industry blueprint key.', 'vm-sales-os' ), 400 );
		}

		return $this->ok(
			[
				'applied' => $key,
				'message' => __( 'Industry blueprint applied successfully.', 'vm-sales-os' ),
			]
		);
	}

	public function masterChat( WP_REST_Request $request ) {
		$message = sanitize_text_field( (string) ( $request->get_param( 'message' ) ?? '' ) );
		if ( '' === trim( $message ) ) {
			return $this->fail( 'empty_message', __( 'Message cannot be empty.', 'vm-sales-os' ), 400 );
		}

		$businessName    = (string) get_option( 'vmsos_business_name', get_bloginfo( 'name' ) );
		$activeBlueprint = (string) get_option( 'vmsos_active_blueprint', 'creative_agency' );
		$context         = (string) get_option( 'vmsos_business_context', '' );
		$cronNext        = wp_next_scheduled( \VMStudio\SalesOS\Modules\LeadGen\LeadGenModule::DAILY_TICK );
		$cronDiff        = $cronNext ? human_time_diff( time(), $cronNext ) : 'inactive';

		$systemPrompt = "You are the Master AI Assistant and Suite Commander for {$businessName}.
Active Archetype Blueprint: {$activeBlueprint}.
Business Context: {$context}
System Status: Daily B2B Google Maps lead gen is active (next run in {$cronDiff}).
Multi-channel pipelines active: WhatsApp Cloud/Evolution API, Voice Telephony, Web Live Chat, Email Governor.
Specialized bots active: In-database AI Puffer Sales Closer (Bot 1169), Fleet/Logistics Dispatcher, Concierge Support.

Your goal is to assist the commander directly, concisely, and strategically.
If the commander asks for a briefing, summarize operational metrics, hot inquiries, and recommended moves.
Always maintain high confidence, clarity, and precision.";

		try {
			$chatReq = new \VMStudio\SalesOS\AI\DTO\ChatRequest( [
				\VMStudio\SalesOS\AI\DTO\Message::system( $systemPrompt ),
				\VMStudio\SalesOS\AI\DTO\Message::user( $message ),
			] );
			$chatRes = $this->providers->chat( $chatReq );

			$text     = $chatRes->content ?: 'Commander, directives received.';
			$provider = $chatRes->provider ?: 'aipuffer';
			$model    = $chatRes->model ?: 'default';

			// Generate contextual action chips based on message
			$actions = [];
			$lower   = strtolower( $message );
			if ( str_contains( $lower, 'lead' ) || str_contains( $lower, 'prospect' ) ) {
				$actions[] = [ 'label' => 'Open Leads CRM', 'action' => 'open_leads' ];
				$actions[] = [ 'label' => 'Run B2B Google Maps Batch', 'action' => 'b2b_import' ];
			}
			if ( str_contains( $lower, 'call' ) || str_contains( $lower, 'dial' ) || str_contains( $lower, 'phone' ) ) {
				$actions[] = [ 'label' => 'Open Smart Dialer', 'action' => 'open_dialer' ];
			}
			if ( str_contains( $lower, 'chat' ) || str_contains( $lower, 'whatsapp' ) || str_contains( $lower, 'message' ) ) {
				$actions[] = [ 'label' => 'Open Omnichannel Inbox', 'action' => 'open_chats' ];
			}
			if ( str_contains( $lower, 'agent' ) || str_contains( $lower, 'bot' ) ) {
				$actions[] = [ 'label' => 'Manage Specialized Agents', 'action' => 'open_agents' ];
			}
			if ( empty( $actions ) ) {
				$actions[] = [ 'label' => 'View High-Priority Leads', 'action' => 'open_leads' ];
				$actions[] = [ 'label' => 'Check WhatsApp Chats', 'action' => 'open_chats' ];
				$actions[] = [ 'label' => 'Run B2B Discovery', 'action' => 'b2b_import' ];
			}

			return $this->ok( [
				'response'  => $text,
				'provider'  => $provider,
				'model'     => $model,
				'telemetry' => [
					'blueprint'    => $activeBlueprint,
					'b2b_cron'     => $cronDiff,
					'ai_provider'  => $provider,
				],
				'actions'   => $actions,
			] );
		} catch ( \Throwable $e ) {
			return $this->ok( [
				'response'  => "Directive acknowledged, Commander. Operating under local executive mode while failover circuits synchronize: " . $e->getMessage(),
				'provider'  => 'fallback',
				'model'     => 'local-rule-engine',
				'telemetry' => [ 'status' => 'local-fallback' ],
				'actions'   => [
					[ 'label' => 'View Leads CRM', 'action' => 'open_leads' ],
					[ 'label' => 'Open Dialer', 'action' => 'open_dialer' ],
				],
			] );
		}
	}
}