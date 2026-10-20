<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Analytics;

use VMStudio\SalesOS\REST\AbstractController;
use WP_REST_Request;

final class AnalyticsController extends AbstractController {

	public function __construct( private MetricsService $metrics ) {}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/analytics/overview',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'overview' ],
				'permission_callback' => fn() => $this->canOperate(),
				'args'                => [ 'days' => [ 'type' => 'integer' ] ],
			]
		);
	}

	public function overview( WP_REST_Request $request ) {
		return $this->ok( $this->metrics->overview( (int) ( $request->get_param( 'days' ) ?: 30 ) ) );
	}
}
