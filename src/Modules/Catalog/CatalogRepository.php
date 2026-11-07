<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Catalog;

use wpdb;

/**
 * Repository for Catalog Products & Services.
 * Supports standalone items as well as two-way sync with WooCommerce.
 */
class CatalogRepository {

	private wpdb $db;
	private string $table;
	private string $dealItemsTable;

	public function __construct() {
		global $wpdb;
		$this->db             = $wpdb;
		$this->table          = $wpdb->prefix . 'vmsos_catalog_items';
		$this->dealItemsTable = $wpdb->prefix . 'vmsos_deal_items';
	}

	/**
	 * @param array{brand_id?:int,category?:string,type?:string,status?:string,search?:string,limit?:int,offset?:int} $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function all( array $filters = [] ): array {
		$where  = [ '1=1' ];
		$params = [];

		if ( isset( $filters['brand_id'] ) ) {
			$where[]  = 'brand_id = %d';
			$params[] = (int) $filters['brand_id'];
		}

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_text_field( $filters['status'] );
		} else {
			$where[] = "status != 'deleted'";
		}

		if ( ! empty( $filters['category'] ) && 'all' !== strtolower( $filters['category'] ) ) {
			$where[]  = 'category = %s';
			$params[] = sanitize_text_field( $filters['category'] );
		}

		if ( ! empty( $filters['type'] ) ) {
			$where[]  = 'type = %s';
			$params[] = sanitize_text_field( $filters['type'] );
		}

		if ( ! empty( $filters['search'] ) ) {
			$where[]  = '(title LIKE %s OR sku LIKE %s OR description LIKE %s)';
			$like     = '%' . $this->db->esc_like( sanitize_text_field( $filters['search'] ) ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$limit  = min( 100, max( 1, (int) ( $filters['limit'] ?? 20 ) ) );
		$offset = max( 0, (int) ( $filters['offset'] ?? 0 ) );

		$sql = "SELECT * FROM `{$this->table}` WHERE " . implode( ' AND ', $where ) . " ORDER BY is_featured DESC, id DESC LIMIT {$limit} OFFSET {$offset}";

		if ( ! empty( $params ) ) {
			$sql = $this->db->prepare( $sql, ...$params ); // phpcs:ignore WordPress.DB
		}

		$rows = $this->db->get_results( $sql, ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB

		return array_map( [ $this, 'formatItem' ], $rows );
	}

	public function find( int $id ): ?array {
		$sql = $this->db->prepare( "SELECT * FROM `{$this->table}` WHERE id = %d LIMIT 1", $id );
		$row = $this->db->get_row( $sql, ARRAY_A );

		return $row ? $this->formatItem( $row ) : null;
	}

	public function findBySku( string $sku ): ?array {
		$sku = trim( $sku );
		if ( '' === $sku ) {
			return null;
		}
		$sql = $this->db->prepare( "SELECT * FROM `{$this->table}` WHERE sku = %s LIMIT 1", $sku );
		$row = $this->db->get_row( $sql, ARRAY_A );

		return $row ? $this->formatItem( $row ) : null;
	}

	public function create( array $data ): int {
		$now   = current_time( 'mysql', true );
		$title = sanitize_text_field( $data['title'] ?? 'New Item' );
		$slug  = sanitize_title( $data['slug'] ?? $title );

		// Ensure unique slug
		$slug = $this->uniqueSlug( $slug );

		$features = isset( $data['features'] ) ? ( is_array( $data['features'] ) ? wp_json_encode( $data['features'] ) : (string) $data['features'] ) : '[]';

		$inserted = $this->db->insert(
			$this->table,
			[
				'brand_id'         => (int) ( $data['brand_id'] ?? 1 ),
				'title'            => $title,
				'slug'             => $slug,
				'sku'              => sanitize_text_field( $data['sku'] ?? 'SKU-' . strtoupper( substr( md5( uniqid( '', true ) ), 0, 8 ) ) ),
				'type'             => sanitize_text_field( $data['type'] ?? 'service' ),
				'category'         => sanitize_text_field( $data['category'] ?? 'General' ),
				'price'            => (float) ( $data['price'] ?? 0.00 ),
				'sale_price'       => isset( $data['sale_price'] ) && '' !== $data['sale_price'] ? (float) $data['sale_price'] : null,
				'currency'         => sanitize_text_field( $data['currency'] ?? 'INR' ),
				'billing_interval' => sanitize_text_field( $data['billing_interval'] ?? 'one_time' ),
				'description'      => sanitize_textarea_field( $data['description'] ?? '' ),
				'features'         => $features,
				'image_url'        => esc_url_raw( $data['image_url'] ?? '' ),
				'payment_url'      => esc_url_raw( $data['payment_url'] ?? '' ),
				'status'           => sanitize_text_field( $data['status'] ?? 'active' ),
				'is_featured'      => ! empty( $data['is_featured'] ) ? 1 : 0,
				'created_at'       => $now,
				'updated_at'       => $now,
			]
		);

		return $inserted ? (int) $this->db->insert_id : 0;
	}

	public function update( int $id, array $data ): bool {
		$fields = [ 'updated_at' => current_time( 'mysql', true ) ];

		$stringKeys = [ 'title', 'sku', 'type', 'category', 'currency', 'billing_interval', 'description', 'status' ];
		foreach ( $stringKeys as $k ) {
			if ( isset( $data[ $k ] ) ) {
				$fields[ $k ] = sanitize_text_field( (string) $data[ $k ] );
			}
		}

		if ( isset( $data['price'] ) ) {
			$fields['price'] = (float) $data['price'];
		}
		if ( array_key_exists( 'sale_price', $data ) ) {
			$fields['sale_price'] = ( null !== $data['sale_price'] && '' !== $data['sale_price'] ) ? (float) $data['sale_price'] : null;
		}
		if ( isset( $data['features'] ) ) {
			$fields['features'] = is_array( $data['features'] ) ? wp_json_encode( $data['features'] ) : (string) $data['features'];
		}
		if ( isset( $data['image_url'] ) ) {
			$fields['image_url'] = esc_url_raw( (string) $data['image_url'] );
		}
		if ( isset( $data['payment_url'] ) ) {
			$fields['payment_url'] = esc_url_raw( (string) $data['payment_url'] );
		}
		if ( isset( $data['is_featured'] ) ) {
			$fields['is_featured'] = ! empty( $data['is_featured'] ) ? 1 : 0;
		}

		$updated = $this->db->update(
			$this->table,
			$fields,
			[ 'id' => $id ]
		);

		return false !== $updated;
	}

	public function delete( int $id ): bool {
		// Soft delete
		return false !== $this->db->update(
			$this->table,
			[ 'status' => 'deleted', 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $id ]
		);
	}

	/** @return array<int, string> */
	public function categories(): array {
		$sql = "SELECT DISTINCT category FROM `{$this->table}` WHERE status != 'deleted' AND category != '' ORDER BY category ASC";
		return $this->db->get_col( $sql ) ?: []; // phpcs:ignore WordPress.DB
	}

	/**
	 * Import and sync items from WooCommerce if active.
	 * @return array{synced:int,errors:int}
	 */
	public function syncFromWooCommerce(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [ 'synced' => 0, 'errors' => 0, 'message' => 'WooCommerce is not active.' ];
		}

		$products = wc_get_products( [ 'limit' => -1, 'status' => 'publish' ] );
		$synced   = 0;

		foreach ( $products as $product ) {
			$sku = $product->get_sku() ?: 'WC-' . $product->get_id();
			$existing = $this->findBySku( $sku );

			$catNames = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );
			$category = ! empty( $catNames ) && is_array( $catNames ) ? $catNames[0] : 'Products';

			$imgId  = $product->get_image_id();
			$imgUrl = $imgId ? (string) wp_get_attachment_url( $imgId ) : '';

			$itemData = [
				'title'       => $product->get_name(),
				'sku'         => $sku,
				'type'        => $product->is_virtual() ? 'service' : 'product',
				'category'    => $category,
				'price'       => (float) $product->get_regular_price() ?: (float) $product->get_price(),
				'sale_price'  => $product->get_sale_price() ? (float) $product->get_sale_price() : null,
				'currency'    => get_woocommerce_currency() ?: 'INR',
				'description' => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
				'image_url'   => $imgUrl,
				'payment_url' => get_permalink( $product->get_id() ),
				'status'      => 'active',
			];

			if ( $existing ) {
				$this->update( (int) $existing['id'], $itemData );
			} else {
				$this->create( $itemData );
			}
			$synced++;
		}

		return [ 'synced' => $synced, 'errors' => 0 ];
	}

	private function uniqueSlug( string $slug ): string {
		$baseSlug = $slug;
		$counter  = 1;
		while ( (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM `{$this->table}` WHERE slug = %s", $slug ) ) > 0 ) {
			$slug = $baseSlug . '-' . $counter;
			$counter++;
		}
		return $slug;
	}

	private function formatItem( array $row ): array {
		$features = json_decode( (string) ( $row['features'] ?? '[]' ), true );
		if ( ! is_array( $features ) ) {
			$features = [];
		}

		$price     = (float) ( $row['price'] ?? 0.00 );
		$salePrice = isset( $row['sale_price'] ) && null !== $row['sale_price'] ? (float) $row['sale_price'] : null;

		return [
			'id'               => (int) $row['id'],
			'brand_id'         => (int) $row['brand_id'],
			'title'            => (string) $row['title'],
			'slug'             => (string) $row['slug'],
			'sku'              => (string) $row['sku'],
			'type'             => (string) $row['type'],
			'category'         => (string) $row['category'],
			'price'            => $price,
			'base_price'       => null !== $salePrice ? $salePrice : $price,
			'sale_price'       => $salePrice,
			'effective_price'  => null !== $salePrice ? $salePrice : $price,
			'currency'         => (string) ( $row['currency'] ?? 'INR' ),
			'billing_interval' => (string) ( $row['billing_interval'] ?? 'one_time' ),
			'pricing_unit'     => ( $row['billing_interval'] ?? 'one_time' ) === 'monthly' ? 'per_month' : 'fixed',
			'description'      => (string) ( $row['description'] ?? '' ),
			'features'         => $features,
			'image_url'        => (string) ( $row['image_url'] ?? '' ),
			'payment_url'      => (string) ( $row['payment_url'] ?? '' ),
			'status'           => (string) ( $row['status'] ?? 'active' ),
			'is_featured'      => 1 === (int) ( $row['is_featured'] ?? 0 ),
			'created_at'       => (string) ( $row['created_at'] ?? '' ),
			'updated_at'       => (string) ( $row['updated_at'] ?? '' ),
		];
	}
}
