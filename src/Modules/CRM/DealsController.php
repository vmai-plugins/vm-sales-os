<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\CRM;

use VMStudio\SalesOS\Core\EventBus;
use VMStudio\SalesOS\REST\AbstractController;
use WP_REST_Request;

final class DealsController extends AbstractController {

	public function __construct(
		private DealRepository $deals,
		private PipelineRepository $pipelines,
		private ActivityRepository $activities,
		private EventBus $events
	) {}

	public function register(): void {
		$auth = fn() => $this->canOperate();

		register_rest_route(
			self::NAMESPACE,
			'/deals',
			[
				[ 'methods' => 'GET', 'callback' => [ $this, 'board' ], 'permission_callback' => $auth ],
				[ 'methods' => 'POST', 'callback' => [ $this, 'store' ], 'permission_callback' => $auth ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/deals/(?P<id>\d+)',
			[
				[ 'methods' => 'POST', 'callback' => [ $this, 'update' ], 'permission_callback' => $auth ],
				[ 'methods' => 'DELETE', 'callback' => [ $this, 'destroy' ], 'permission_callback' => fn() => $this->canManage() ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/deals/(?P<id>\d+)/stage',
			[ 'methods' => 'POST', 'callback' => [ $this, 'move' ], 'permission_callback' => $auth ]
		);

		register_rest_route(
			self::NAMESPACE,
			'/pipelines',
			[ 'methods' => 'GET', 'callback' => [ $this, 'pipelines' ], 'permission_callback' => $auth ]
		);
	}

	public function board( WP_REST_Request $request ) {
		$pipelineId = (int) $request->get_param( 'pipeline_id' );
		$pipeline   = $pipelineId > 0 ? $this->pipelines->find( $pipelineId ) : $this->pipelines->defaultPipeline();
		$pipelineId = $pipeline ? (int) $pipeline['id'] : 0;

		$stages  = $this->pipelines->stages( $pipelineId );
		$deals   = $this->deals->forPipeline( $pipelineId, (bool) $request->get_param( 'include_closed' ) );
		$columns = [];

		foreach ( $stages as $stage ) {
			$columns[ (string) $stage['key'] ] = [
				'stage' => $stage,
				'deals' => [],
				'total' => 0.0,
			];
		}

		foreach ( $deals as $deal ) {
			$key = (string) $deal['stage'];

			// A deal on a stage that was later renamed still has to appear somewhere.
			if ( ! isset( $columns[ $key ] ) ) {
				$columns[ $key ] = [
					'stage' => [ 'key' => $key, 'label' => ucfirst( str_replace( '_', ' ', $key ) ), 'probability' => (int) $deal['probability'] ],
					'deals' => [],
					'total' => 0.0,
				];
			}

			$columns[ $key ]['deals'][] = $deal;
			$columns[ $key ]['total']  += (float) $deal['value'];
		}

		return $this->ok(
			[
				'pipeline' => $pipeline,
				'columns'  => array_values( $columns ),
				'forecast' => $this->deals->forecast(),
				'won_lost' => $this->deals->wonLost( 30 ),
			]
		);
	}

	public function store( WP_REST_Request $request ) {
		$pipeline = $this->pipelines->defaultPipeline();
		$stage    = $this->param( $request, 'stage', 'new' );

		$id = $this->deals->create(
			[
				'pipeline_id'    => (int) ( $request->get_param( 'pipeline_id' ) ?: ( $pipeline['id'] ?? 0 ) ),
				'contact_id'     => (int) $request->get_param( 'contact_id' ),
				'title'          => $this->param( $request, 'title', 'Untitled deal' ),
				'stage'          => $stage,
				'value'          => (float) $request->get_param( 'value' ),
				'currency'       => $this->param( $request, 'currency', (string) get_option( 'vmsos_currency', 'INR' ) ),
				'probability'    => $this->pipelines->probabilityFor( $stage, (int) ( $pipeline['id'] ?? 0 ) ),
				'expected_close' => $this->param( $request, 'expected_close' ),
			]
		);

		if ( 0 === $id ) {
			return $this->fail( 'vmsos_deal_failed', __( 'The deal could not be saved.', 'vm-sales-os' ) );
		}

		$this->events->dispatch( 'deal.created', [ 'deal_id' => $id, 'stage' => $stage ] );

		return $this->ok( [ 'deal' => $this->deals->find( $id ) ], 201 );
	}

	public function update( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! $this->deals->find( $id ) ) {
			return $this->fail( 'vmsos_not_found', __( 'Deal not found.', 'vm-sales-os' ), 404 );
		}

		$input = [];
		foreach ( [ 'title', 'value', 'currency', 'probability', 'contact_id', 'expected_close' ] as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$input[ $field ] = $request->get_param( $field );
			}
		}

		$this->deals->edit( $id, $input );

		return $this->ok( [ 'deal' => $this->deals->find( $id ) ] );
	}

	public function move( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$deal = $this->deals->find( $id );

		if ( ! $deal ) {
			return $this->fail( 'vmsos_not_found', __( 'Deal not found.', 'vm-sales-os' ), 404 );
		}

		$stage       = $this->param( $request, 'stage' );
		$pipelineId  = (int) $deal['pipeline_id'];
		$known       = array_column( $this->pipelines->stages( $pipelineId ), 'key' );

		if ( ! in_array( $stage, $known, true ) ) {
			return $this->fail( 'vmsos_bad_stage', __( 'That stage does not exist in this pipeline.', 'vm-sales-os' ) );
		}

		$probability = $this->pipelines->probabilityFor( $stage, $pipelineId );

		if ( $deal['closed_at'] && ! in_array( $stage, [ 'won', 'lost' ], true ) ) {
			$this->deals->reopen( $id, $stage, $probability );
		} else {
			$this->deals->moveStage( $id, $stage, $probability );
		}

		$this->activities->log(
			'deal',
			$id,
			'stage_change',
			sprintf( 'Moved from %s to %s', (string) $deal['stage'], $stage ),
			'human'
		);

		$this->events->dispatch(
			'deal.stage_changed',
			[
				'deal_id'    => $id,
				'contact_id' => (int) $deal['contact_id'],
				'from'       => (string) $deal['stage'],
				'stage'      => $stage,
				'value'      => (float) $deal['value'],
			]
		);

		return $this->ok( [ 'deal' => $this->deals->find( $id ) ] );
	}

	public function destroy( WP_REST_Request $request ) {
		return $this->ok( [ 'deleted' => $this->deals->delete( (int) $request->get_param( 'id' ) ) ] );
	}

	public function pipelines( WP_REST_Request $request ) {
		$pipelines = $this->pipelines->all();

		foreach ( $pipelines as $index => $pipeline ) {
			$pipelines[ $index ]['stages'] = $this->pipelines->stages( (int) $pipeline['id'] );
		}

		return $this->ok( [ 'pipelines' => $pipelines ] );
	}
}
