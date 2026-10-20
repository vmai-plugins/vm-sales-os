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
}