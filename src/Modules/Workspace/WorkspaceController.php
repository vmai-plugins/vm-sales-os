<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Workspace;

use VMStudio\SalesOS\REST\AbstractController;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class WorkspaceController extends AbstractController {

	public function __construct(
		private PaperclipService $paperclip,
		private ForecastingService $forecasting,
		private BrainService $brain
	) {}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/workspace/overview',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'overview' ],
				'permission_callback' => [ $this, 'canManage' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/workspace/agents',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'agents' ],
				'permission_callback' => [ $this, 'canManage' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/workspace/tasks',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'tasks' ],
				'permission_callback' => [ $this, 'canManage' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/workspace/dispatch',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'dispatch' ],
				'permission_callback' => [ $this, 'canManage' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/workspace/forecast',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'forecast' ],
				'permission_callback' => [ $this, 'canManage' ],
			]
		);

		// Sovereign Brain Endpoints
		register_rest_route(
			self::NAMESPACE,
			'/workspace/brain/telemetry',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'brainTelemetry' ],
				'permission_callback' => [ $this, 'canManage' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/workspace/brain/heal',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'brainHeal' ],
				'permission_callback' => [ $this, 'canManage' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/workspace/brain/ask',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'brainAsk' ],
				'permission_callback' => [ $this, 'canManage' ],
			]
		);
	}

	public function overview( WP_REST_Request $request ): WP_REST_Response {
		$agents   = $this->paperclip->getAgents();
		$issues   = $this->paperclip->getIssues();
		$growing  = $this->forecasting->whatsGrowing();
		$forecast = $this->forecasting->getForecast( 90 );

		$taskCounts = [
			'total'       => count( $issues ),
			'todo'        => 0,
			'in_progress' => 0,
			'done'        => 0,
		];
		foreach ( $issues as $issue ) {
			$s = (string) ( $issue['status'] ?? 'todo' );
			if ( isset( $taskCounts[ $s ] ) ) {
				$taskCounts[ $s ]++;
			}
		}

		return $this->ok(
			[
				'daemon_health' => $this->paperclip->health(),
				'agents'        => $agents,
				'tasks'         => array_slice( $issues, 0, 20 ),
				'task_counts'   => $taskCounts,
				'growing'       => $growing,
				'forecast'      => $forecast,
				'brain'         => $this->brain->getTelemetry(),
				'timestamp'     => current_time( 'mysql' ),
			]
		);
	}

	public function agents( WP_REST_Request $request ): WP_REST_Response {
		$agents = $this->paperclip->getAgents();
		return $this->ok( [ 'agents' => $agents ] );
	}

	public function tasks( WP_REST_Request $request ): WP_REST_Response {
		$status = $this->param( $request, 'status' );
		$issues = $this->paperclip->getIssues( $status ?: null );
		return $this->ok( [ 'tasks' => $issues ] );
	}

	public function dispatch( WP_REST_Request $request ): WP_REST_Response {
		$title       = $this->param( $request, 'title' );
		$description = $this->textParam( $request, 'description' );
		$agentId     = $this->param( $request, 'agent_id' );
		$priority    = $this->param( $request, 'priority', 'medium' );

		if ( empty( $title ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Title is required.' ], 400 );
		}

		$result = $this->paperclip->dispatchTask( $title, $description, $agentId ?: null, $priority );
		if ( empty( $result['success'] ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => $result['error'] ?? 'Dispatch failed.' ], 500 );
		}

		return $this->ok( [ 'dispatched' => true, 'task' => $result['data'] ?? [] ] );
	}

	public function forecast( WP_REST_Request $request ): WP_REST_Response {
		$horizon = (int) ( $request->get_param( 'horizon' ) ?: 90 );
		$data    = $this->forecasting->getForecast( $horizon );
		return $this->ok( $data );
	}

	public function brainTelemetry( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( $this->brain->getTelemetry() );
	}

	public function brainHeal( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( $this->brain->heal() );
	}

	public function brainAsk( WP_REST_Request $request ): WP_REST_Response {
		$query = $this->textParam( $request, 'query' );
		if ( empty( $query ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Query cannot be empty.' ], 400 );
		}

		$result = $this->brain->ask( $query );
		return $this->ok( $result );
	}
}
