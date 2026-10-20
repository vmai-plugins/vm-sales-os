<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Database;

use VMStudio\SalesOS\Support\BrandContext;
use wpdb;

/**
 * Base repository. Centralises brand scoping and prepared statements so no
 * module writes raw interpolated SQL.
 */
abstract class Repository {

	protected wpdb $db;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	abstract protected function table(): string;

	protected function name(): string {
		return $this->db->prefix . $this->table();
	}

	protected function brand(): int {
		return BrandContext::current();
	}

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM `' . $this->name() . '` WHERE id = %d AND brand_id = %d', $id, $this->brand() ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	/**
	 * @param array<string, mixed> $data    Column values.
	 * @param array<int, string>   $formats Column formats.
	 */
	public function insert( array $data, array $formats ): int {
		$brand = (int) ( $data['brand_id'] ?? $this->brand() );
		unset( $data['brand_id'] );

		$payload = array_merge( [ 'brand_id' => $brand ], $data );
		$formats = array_merge( [ '%d' ], $formats );

		$this->db->insert( $this->name(), $payload, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $this->db->insert_id;
	}

	/**
	 * @param array<string, mixed> $data    Column values.
	 * @param array<int, string>   $formats Column formats.
	 */
	public function update( int $id, array $data, array $formats ): bool {
		$result = $this->db->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->name(),
			$data,
			[ 'id' => $id, 'brand_id' => $this->brand() ],
			$formats,
			[ '%d', '%d' ]
		);
		return false !== $result;
	}

	public function delete( int $id ): bool {
		return (bool) $this->db->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->name(),
			[ 'id' => $id, 'brand_id' => $this->brand() ],
			[ '%d', '%d' ]
		);
	}

	public function count( string $where = '', array $args = [] ): int {
		$sql  = 'SELECT COUNT(*) FROM `' . $this->name() . '` WHERE brand_id = %d';
		$args = array_merge( [ $this->brand() ], $args );
		if ( '' !== $where ) {
			$sql .= ' AND ' . $where;
		}
		return (int) $this->db->get_var( $this->db->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB
	}

	protected function now(): string {
		return current_time( 'mysql', true );
	}
}
