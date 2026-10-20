<?php
/**
 * Uninstall routine. Destructive drops only run when the operator has opted in.
 *
 * @package VMStudio\SalesOS
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'vmsos_erase_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

$tables = [
	'vmsos_brands', 'vmsos_contacts', 'vmsos_companies', 'vmsos_deals', 'vmsos_pipelines',
	'vmsos_activities', 'vmsos_conversations', 'vmsos_messages', 'vmsos_kb_documents',
	'vmsos_kb_chunks', 'vmsos_agents', 'vmsos_agent_runs', 'vmsos_jobs', 'vmsos_ai_usage',
	'vmsos_audit_log', 'vmsos_settings', 'vmsos_sequences', 'vmsos_sequence_steps',
	'vmsos_enrollments', 'vmsos_email_events', 'vmsos_contact_memory', 'vmsos_integration_log',
];

foreach ( $tables as $table ) {
	// Table names cannot be parameterised; they are hardcoded above.
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . $table . '`' ); // phpcs:ignore WordPress.DB
}

foreach ( [ 'vmsos_version', 'vmsos_db_version', 'vmsos_modules', 'vmsos_settings_cache', 'vmsos_erase_data_on_uninstall' ] as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'vmsos_queue_tick' );
wp_clear_scheduled_hook( 'vmsos_brain_tick' );
wp_clear_scheduled_hook( 'vmsos_sequences_tick' );
