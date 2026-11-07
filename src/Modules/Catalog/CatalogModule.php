<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Catalog;

use VMStudio\SalesOS\AI\ToolRegistry;
use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Core\Module;
use VMStudio\SalesOS\REST\RestRouter;

final class CatalogModule extends Module {

	public static function slug(): string {
		return 'catalog';
	}

	public static function label(): string {
		return __( 'Catalog & Commerce', 'vm-sales-os' );
	}

	public function register( Container $container ): void {
		$container->singleton( CatalogRepository::class, static fn() => new CatalogRepository() );
		$container->singleton( CatalogController::class, static fn( Container $c ) => new CatalogController( $c->get( CatalogRepository::class ) ) );
	}

	public function boot( Container $container ): void {
		$router = $container->get( RestRouter::class );
		$router->add( CatalogController::class );

		$this->registerTools( $container );
	}

	private function registerTools( Container $container ): void {
		if ( ! $container->has( ToolRegistry::class ) ) {
			return;
		}

		/** @var ToolRegistry $tools */
		$tools = $container->get( ToolRegistry::class );

		// 1. Search Catalog Tool
		$tools->register(
			[
				'name'        => 'catalog_search',
				'description' => 'Search products, packages, and services in the commercial catalog by keyword or category. Returns official pricing, currency, SKU, and direct payment links.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'query'    => [ 'type' => 'string', 'description' => 'Keyword, product title, service name, or category.' ],
						'category' => [ 'type' => 'string', 'description' => 'Optional category filter.' ],
						'limit'    => [ 'type' => 'integer', 'description' => 'Maximum items to return (default 4).' ],
					],
					'required'   => [ 'query' ],
				],
			],
			static function ( array $args, array $context ) use ( $container ): array {
				/** @var CatalogRepository $repo */
				$repo = $container->get( CatalogRepository::class );

				$query    = sanitize_text_field( (string) ( $args['query'] ?? '' ) );
				$category = sanitize_text_field( (string) ( $args['category'] ?? '' ) );
				$limit    = min( 8, max( 1, (int) ( $args['limit'] ?? 4 ) ) );

				$items = $repo->all(
					[
						'search'   => $query,
						'category' => $category,
						'limit'    => $limit,
					]
				);

				if ( empty( $items ) && function_exists( 'wc_get_products' ) ) {
					// Fallback to WooCommerce if standalone table had no hits
					$wcItems = [];
					$posts   = get_posts(
						[
							'post_type'      => 'product',
							'post_status'    => 'publish',
							'posts_per_page' => $limit,
							's'              => $query,
						]
					);
					foreach ( $posts as $p ) {
						$price     = get_post_meta( $p->ID, '_price', true );
						$wcItems[] = [
							'id'          => $p->ID,
							'title'       => $p->post_title,
							'sku'         => get_post_meta( $p->ID, '_sku', true ) ?: 'WC-' . $p->ID,
							'price'       => (float) $price,
							'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'INR',
							'description' => wp_trim_words( $p->post_content, 25 ),
							'payment_url' => get_permalink( $p->ID ),
						];
					}
					$items = $wcItems;
				}

				return [
					'found' => count( $items ),
					'items' => $items,
				];
			},
			'public'
		);

		// 2. Get Catalog Item By SKU or ID
		$tools->register(
			[
				'name'        => 'catalog_get_item',
				'description' => 'Retrieve detailed specifications, pricing, features list, and checkout link for a single catalog item by SKU or numeric ID.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'sku' => [ 'type' => 'string', 'description' => 'Item SKU code.' ],
						'id'  => [ 'type' => 'integer', 'description' => 'Item database ID.' ],
					],
					'required'   => [],
				],
			],
			static function ( array $args, array $context ) use ( $container ): array {
				/** @var CatalogRepository $repo */
				$repo = $container->get( CatalogRepository::class );

				$item = null;
				if ( ! empty( $args['sku'] ) ) {
					$item = $repo->findBySku( sanitize_text_field( (string) $args['sku'] ) );
				} elseif ( ! empty( $args['id'] ) ) {
					$item = $repo->find( (int) $args['id'] );
				}

				if ( ! $item ) {
					return [ 'found' => false, 'error' => 'Item not found in catalog.' ];
				}

				return [ 'found' => true, 'item' => $item ];
			},
			'public'
		);

		// 3. Generate Itemized Quote & Proposal
		$tools->register(
			[
				'name'        => 'catalog_generate_quote',
				'description' => 'Build a formal itemized quote for multiple catalog products/services with group discounts, calculating subtotal, savings, and total.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'items'            => [
							'type'        => 'array',
							'description' => 'Array of items with sku (string) and quantity (integer).',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'sku'      => [ 'type' => 'string' ],
									'quantity' => [ 'type' => 'integer', 'default' => 1 ],
								],
								'required'   => [ 'sku' ],
							],
						],
						'discount_percent' => [ 'type' => 'number', 'description' => 'Optional discount percentage (0 to 20%).' ],
						'notes'            => [ 'type' => 'string', 'description' => 'Special notes or custom requirements.' ],
					],
					'required'   => [ 'items' ],
				],
			],
			static function ( array $args, array $context ) use ( $container ): array {
				/** @var CatalogRepository $repo */
				$repo = $container->get( CatalogRepository::class );

				$rawItems = is_array( $args['items'] ?? null ) ? $args['items'] : [];
				$discount = min( 20.0, max( 0.0, (float) ( $args['discount_percent'] ?? 0.0 ) ) );
				$notes    = sanitize_text_field( (string) ( $args['notes'] ?? '' ) );

				$lineItems = [];
				$subtotal  = 0.0;
				$currency  = 'INR';
				$payUrl    = '';

				foreach ( $rawItems as $ri ) {
					$sku  = sanitize_text_field( (string) ( $ri['sku'] ?? '' ) );
					$qty  = max( 1, (int) ( $ri['quantity'] ?? 1 ) );
					$item = $repo->findBySku( $sku );

					if ( $item ) {
						$unitPrice = (float) $item['effective_price'];
						$lineTotal = $unitPrice * $qty;
						$subtotal += $lineTotal;
						$currency  = $item['currency'] ?: $currency;
						if ( empty( $payUrl ) && ! empty( $item['payment_url'] ) ) {
							$payUrl = $item['payment_url'];
						}

						$lineItems[] = [
							'sku'        => $item['sku'],
							'title'      => $item['title'],
							'quantity'   => $qty,
							'unit_price' => $unitPrice,
							'line_total' => round( $lineTotal, 2 ),
						];
					}
				}

				if ( empty( $lineItems ) ) {
					return [ 'error' => 'None of the specified item SKUs were found in the catalog.' ];
				}

				$savings = $subtotal * ( $discount / 100.0 );
				$total   = $subtotal - $savings;

				return [
					'quote_reference'  => 'QT-' . strtoupper( substr( md5( uniqid( '', true ) ), 0, 8 ) ),
					'currency'         => $currency,
					'line_items'       => $lineItems,
					'subtotal'         => round( $subtotal, 2 ),
					'discount_percent' => $discount,
					'savings'          => round( $savings, 2 ),
					'total'            => round( $total, 2 ),
					'payment_url'      => $payUrl,
					'valid_days'       => 7,
					'notes'            => $notes ?: 'Standard proposal terms apply. Instant onboarding upon confirmation.',
				];
			},
			'public'
		);
	}
}
