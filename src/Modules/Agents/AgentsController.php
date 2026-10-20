<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Agents;

use VMStudio\SalesOS\REST\AbstractController;
use WP_REST_Request;

final class AgentsController extends AbstractController {

	public function __construct( private AgentRepository $agents, private Orchestrator $orchestrator ) {}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/agents',
			[
				[ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => fn() => $this->canOperate() ],
				[ 'methods' => 'POST', 'callback' => [ $this, 'save' ], 'permission_callback' => fn() => $this->canManage() ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/agents/(?P<slug>[a-z0-9_\-]+)/run',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'run' ],
				'permission_callback' => fn() => $this->canManage(),
				'args'                => [ 'input' => [ 'required' => true, 'type' => 'string' ] ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/agents/runs',
			[ 'methods' => 'GET', 'callback' => [ $this, 'runs' ], 'permission_callback' => fn() => $this->canOperate() ]
		);

		register_rest_route(
			self::NAMESPACE,
			'/agents/brain',
			[ 'methods' => 'GET', 'callback' => [ $this, 'brain' ], 'permission_callback' => fn() => $this->canOperate() ]
		);
	}

	public function index( WP_REST_Request $request ) {
		return $this->ok( [ 'agents' => $this->agents->active() ] );
	}

	public function save( WP_REST_Request $request ) {
		$slug = sanitize_key( $this->param( $request, 'slug' ) );

		if ( '' === $slug ) {
			return $this->fail( 'vmsos_missing_slug', __( 'Give the agent a slug.', 'vm-sales-os' ) );
		}

		$id = $this->agents->upsert(
			[
				'slug'          => $slug,
				'name'          => $this->param( $request, 'name' ),
				'role'          => $this->param( $request, 'role', 'sales' ),
				'goal'          => $this->textParam( $request, 'goal', 2000 ),
				'system_prompt' => $this->textParam( $request, 'system_prompt', 8000 ),
				'tools'         => (array) $request->get_param( 'tools' ),
				'capabilities'  => (array) ( $request->get_param( 'capabilities' ) ?: [ 'internal' ] ),
			]
		);

		return $this->ok( [ 'id' => $id ] );
	}

	public function run( WP_REST_Request $request ) {
		$throttle = $this->throttle( 'agent_run', 30, HOUR_IN_SECONDS );
		if ( true !== $throttle ) {
			return $throttle;
		}

		$result = $this->orchestrator->run(
			(string) $request->get_param( 'slug' ),
			$this->textParam( $request, 'input', 8000 ),
			'manual'
		);

		return $this->ok( $result, $result['ok'] ? 200 : 422 );
	}

	public function runs( WP_REST_Request $request ) {
		return $this->ok( [ 'runs' => $this->agents->recentRuns() ] );
	}

	public function brain( WP_REST_Request $request ) {
		return $this->ok( [ 'brain' => BrainTick::directive() ] );
	}
}
