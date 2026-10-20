<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Database;

/**
 * Versioned migrations.
 *
 * dbDelta only runs when the stored schema version is behind the code version,
 * guarded by a lock so two simultaneous requests cannot both migrate. This is
 * the fix for the classic "schema runs on every admin page load" bug.
 */
final class Migrator {

	public const SCHEMA_VERSION = 5;

	private const OPTION = 'vmsos_db_version';
	private const LOCK   = 'vmsos_migrating';

	public function maybeMigrate(): void {
		if ( (int) get_option( self::OPTION, 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}
		$this->migrate();
	}

	public function migrate(): void {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$from = (int) get_option( self::OPTION, 0 );

			foreach ( $this->migrations() as $version => $statements ) {
				if ( $version <= $from ) {
					continue;
				}
				foreach ( $statements as $sql ) {
					dbDelta( $sql );
				}
				update_option( self::OPTION, $version, false );
			}

			$this->seedDefaults();
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/** @return array<int, array<int, string>> */
	private function migrations(): array {
		return [
			1 => Schema::core(),
			2 => Schema::crm(),
			3 => Schema::aiAndOps(),
			// v4 re-runs the CRM tables so dbDelta adds contacts.marketing_status.
			4 => array_merge( Schema::crm(), Schema::growth() ),
			5 => Schema::intelligence(),
		];
	}

	private function seedDefaults(): void {
		global $wpdb;

		$brands = $wpdb->prefix . 'vmsos_brands';
		$count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$brands}`" ); // phpcs:ignore WordPress.DB
		if ( 0 === $count ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$brands,
				[
					'name'       => get_bloginfo( 'name' ) ?: 'Default brand',
					'slug'       => 'default',
					'site_url'   => home_url(),
					'status'     => 'active',
					'created_at' => current_time( 'mysql', true ),
				],
				[ '%s', '%s', '%s', '%s', '%s' ]
			);
			update_option( 'vmsos_default_brand_id', (int) $wpdb->insert_id, false );
		}

		$pipelines = $wpdb->prefix . 'vmsos_pipelines';
		if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$pipelines}`" ) ) { // phpcs:ignore WordPress.DB
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$pipelines,
				[
					'brand_id'   => (int) get_option( 'vmsos_default_brand_id', 1 ),
					'name'       => 'Sales',
					'stages'     => wp_json_encode(
						[
							[ 'key' => 'new', 'label' => 'New', 'probability' => 10 ],
							[ 'key' => 'qualified', 'label' => 'Qualified', 'probability' => 30 ],
							[ 'key' => 'proposal', 'label' => 'Proposal sent', 'probability' => 60 ],
							[ 'key' => 'negotiation', 'label' => 'Negotiation', 'probability' => 80 ],
							[ 'key' => 'won', 'label' => 'Won', 'probability' => 100 ],
							[ 'key' => 'lost', 'label' => 'Lost', 'probability' => 0 ],
						]
					),
					'is_default' => 1,
					'created_at' => current_time( 'mysql', true ),
				],
				[ '%d', '%s', '%s', '%d', '%s' ]
			);
		}
	}
}
