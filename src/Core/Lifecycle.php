<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Core;

use VMStudio\SalesOS\Database\Migrator;

/**
 * Activation and deactivation. Kept deliberately thin: schema work is owned by
 * the migrator so a version bump never depends on the activation hook firing.
 */
final class Lifecycle {

	public static function activate(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		( new Migrator() )->migrate();

		add_option( 'vmsos_version', VMSOS_VERSION, '', false );

		if ( ! wp_next_scheduled( 'vmsos_queue_tick' ) ) {
			wp_schedule_event( time() + 60, 'vmsos_minute', 'vmsos_queue_tick' );
		}
		if ( ! wp_next_scheduled( 'vmsos_brain_tick' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'vmsos_brain_tick' );
		}

		set_transient( 'vmsos_activated', 1, 60 );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'vmsos_queue_tick' );
		wp_clear_scheduled_hook( 'vmsos_brain_tick' );
		wp_clear_scheduled_hook( 'vmsos_sequences_tick' );
	}
}
