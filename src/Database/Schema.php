<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Database;

/**
 * Table definitions. Every business table carries brand_id as the first index
 * column so tenant scoping is index-backed rather than a filesort.
 */
final class Schema {

	public static function charset(): string {
		global $wpdb;
		return $wpdb->get_charset_collate();
	}

	private static function p( string $table ): string {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	/** @return array<int, string> */
	public static function core(): array {
		$c = self::charset();

		return [
			'CREATE TABLE ' . self::p( 'vmsos_brands' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(191) NOT NULL,
				slug VARCHAR(191) NOT NULL,
				site_url VARCHAR(255) NOT NULL DEFAULT '',
				settings LONGTEXT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY brand_slug (slug)
			) $c;",

			'CREATE TABLE ' . self::p( 'vmsos_settings' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				setting_key VARCHAR(191) NOT NULL,
				setting_value LONGTEXT NULL,
				autoload TINYINT(1) NOT NULL DEFAULT 1,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY brand_key (brand_id, setting_key)
			) $c;",

			'CREATE TABLE ' . self::p( 'vmsos_audit_log' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				level VARCHAR(20) NOT NULL DEFAULT 'info',
				channel VARCHAR(60) NOT NULL DEFAULT 'core',
				message VARCHAR(1000) NOT NULL DEFAULT '',
				context LONGTEXT NULL,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				ip_hash VARCHAR(64) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY brand_time (brand_id, created_at),
				KEY channel_level (channel, level)
			) $c;",

			'CREATE TABLE ' . self::p( 'vmsos_jobs' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				queue VARCHAR(60) NOT NULL DEFAULT 'default',
				handler VARCHAR(191) NOT NULL,
				payload LONGTEXT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
				available_at DATETIME NOT NULL,
				reserved_at DATETIME NULL,
				last_error TEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY claim (status, available_at),
				KEY brand_queue (brand_id, queue)
			) $c;",
		];
	}

	/** @return array<int, string> */
	public static function crm(): array {
		$c = self::charset();

		return [
			'CREATE TABLE ' . self::p( 'vmsos_companies' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				name VARCHAR(191) NOT NULL,
				domain VARCHAR(191) NOT NULL DEFAULT '',
				industry VARCHAR(120) NOT NULL DEFAULT '',
				size VARCHAR(40) NOT NULL DEFAULT '',
				meta LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY brand_name (brand_id, name(60)),
				KEY brand_domain (brand_id, domain)
			) $c;",

			'CREATE TABLE ' . self::p( 'vmsos_contacts' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				company_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				first_name VARCHAR(120) NOT NULL DEFAULT '',
				last_name VARCHAR(120) NOT NULL DEFAULT '',
				email VARCHAR(191) NOT NULL DEFAULT '',
				email_hash CHAR(32) NOT NULL DEFAULT '',
				phone VARCHAR(40) NOT NULL DEFAULT '',
				phone_hash CHAR(32) NOT NULL DEFAULT '',
				source VARCHAR(60) NOT NULL DEFAULT '',
				stage VARCHAR(40) NOT NULL DEFAULT 'new',
				score SMALLINT NOT NULL DEFAULT 0,
				score_reason VARCHAR(255) NOT NULL DEFAULT '',
				requirements TEXT NULL,
				consent TINYINT(1) NOT NULL DEFAULT 0,
				marketing_status VARCHAR(20) NOT NULL DEFAULT '',
				owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				tags VARCHAR(255) NOT NULL DEFAULT '',
				meta LONGTEXT NULL,
				last_seen_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY brand_email (brand_id, email_hash),
				KEY brand_phone (brand_id, phone_hash),
				KEY brand_stage_score (brand_id, stage, score),
				KEY brand_created (brand_id, created_at)
			) $c;",

			'CREATE TABLE ' . self::p( 'vmsos_pipelines' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				name VARCHAR(191) NOT NULL,
				stages LONGTEXT NULL,
				is_default TINYINT(1) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY brand_default (brand_id, is_default)
			) $c;",

			'CREATE TABLE ' . self::p( 'vmsos_deals' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				pipeline_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				company_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				title VARCHAR(191) NOT NULL,
				stage VARCHAR(40) NOT NULL DEFAULT 'new',
				value DECIMAL(18,2) NOT NULL DEFAULT 0.00,
				currency CHAR(3) NOT NULL DEFAULT 'INR',
				probability TINYINT UNSIGNED NOT NULL DEFAULT 0,
				expected_close DATE NULL,
				closed_at DATETIME NULL,
				meta LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY brand_stage (brand_id, stage),
				KEY brand_contact (brand_id, contact_id),
				KEY brand_close (brand_id, expected_close)
			) $c;",

			'CREATE TABLE ' . self::p( 'vmsos_activities' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				subject_type VARCHAR(40) NOT NULL DEFAULT 'contact',
				subject_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				type VARCHAR(40) NOT NULL DEFAULT 'note',
				body TEXT NULL,
				actor VARCHAR(60) NOT NULL DEFAULT 'system',
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				meta LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY brand_subject (brand_id, subject_type, subject_id, created_at)
			) $c;",
		];
	}

	/** @return array<int, string> */
	public static function aiAndOps(): array {
		$c = self::charset();

		return [
			'CREATE TABLE ' . self::p( 'vmsos_conversations' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				uuid CHAR(36) NOT NULL,
				channel VARCHAR(40) NOT NULL DEFAULT 'web',
				contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				visitor_hash VARCHAR(64) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'open',
				assigned_to BIGINT UNSIGNED NOT NULL DEFAULT 0,
				handover TINYINT(1) NOT NULL DEFAULT 0,
				sentiment VARCHAR(20) NOT NULL DEFAULT '',
				intent VARCHAR(60) NOT NULL DEFAULT '',
				summary TEXT NULL,
				meta LONGTEXT NULL,
				last_message_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY conv_uuid (uuid),
				KEY brand_status (brand_id, status, last_message_at),
				KEY brand_contact (brand_id, contact_id)
			) $c;",

			'CREATE TABLE ' . self::p( 'vmsos_messages' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				conversation_id BIGINT UNSIGNED NOT NULL,
				role VARCHAR(20) NOT NULL DEFAULT 'user',
				content LONGTEXT NULL,
				tool_calls LONGTEXT NULL,
				provider VARCHAR(40) NOT NULL DEFAULT '',
				model VARCHAR(80) NOT NULL DEFAULT '',
				tokens_in INT UNSIGNED NOT NULL DEFAULT 0,
				tokens_out INT UNSIGNED NOT NULL DEFAULT 0,
				latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY conv_time (conversation_id, created_at),
				KEY brand_time (brand_id, created_at)
			) $c;",

			'CREATE TABLE ' . self::p( 'vmsos_kb_documents' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				title VARCHAR(255) NOT NULL DEFAULT '',
				source_type VARCHAR(40) NOT NULL DEFAULT 'manual',
				source_ref VARCHAR(255) NOT NULL DEFAULT '',
				checksum CHAR(40) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				chunk_count INT UNSIGNED NOT NULL DEFAULT 0,
				version INT UNSIGNED NOT NULL DEFAULT 1,
				meta LONGTEXT NULL,
				indexed_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY brand_status (brand_id, status),
				KEY brand_checksum (brand_id, checksum)
			) $c;",

			'CREATE TABLE ' . self::p( 'vmsos_kb_chunks' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				document_id BIGINT UNSIGNED NOT NULL,
				chunk_index INT UNSIGNED NOT NULL DEFAULT 0,
				content LONGTEXT NULL,
				embedding LONGBLOB NULL,
				dimensions SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				token_count INT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY doc_chunk (document_id, chunk_index),
				KEY brand_doc (brand_id, document_id),
				FULLTEXT KEY chunk_ft (content)
			) $c;",

			'CREATE TABLE ' . self::p( 'vmsos_agents' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				slug VARCHAR(60) NOT NULL,
				name VARCHAR(191) NOT NULL,
				role VARCHAR(60) NOT NULL DEFAULT 'sales',
				goal TEXT NULL,
				system_prompt LONGTEXT NULL,
				tools LONGTEXT NULL,
				model_policy LONGTEXT NULL,
				capabilities LONGTEXT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY brand_slug (brand_id, slug)
			) $c;",

			'CREATE TABLE ' . self::p( 'vmsos_agent_runs' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				agent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				trigger_type VARCHAR(40) NOT NULL DEFAULT 'manual',
				input LONGTEXT NULL,
				steps LONGTEXT NULL,
				output LONGTEXT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'running',
				cost_usd DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
				started_at DATETIME NOT NULL,
				finished_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY brand_agent (brand_id, agent_id, started_at),
				KEY status_idx (status)
			) $c;",

			'CREATE TABLE ' . self::p( 'vmsos_ai_usage' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				provider VARCHAR(40) NOT NULL,
				model VARCHAR(80) NOT NULL DEFAULT '',
				operation VARCHAR(40) NOT NULL DEFAULT 'chat',
				tokens_in INT UNSIGNED NOT NULL DEFAULT 0,
				tokens_out INT UNSIGNED NOT NULL DEFAULT 0,
				cost_usd DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
				latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
				success TINYINT(1) NOT NULL DEFAULT 1,
				error_code VARCHAR(60) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY brand_time (brand_id, created_at),
				KEY provider_time (provider, created_at)
			) $c;",
		];
	}

	/**
	 * Schema v4 — outreach, lifecycle and channel plumbing.
	 *
	 * @return array<int, string>
	 */
	public static function growth(): array {
		$c = self::charset();

		return [
			'CREATE TABLE ' . self::p( 'vmsos_sequences' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				name VARCHAR(190) NOT NULL,
				slug VARCHAR(190) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'draft',
				trigger_event VARCHAR(60) NOT NULL DEFAULT '',
				goal_event VARCHAR(60) NOT NULL DEFAULT '',
				stop_on_reply TINYINT(1) NOT NULL DEFAULT 1,
				settings LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY brand_status (brand_id, status),
				KEY brand_trigger (brand_id, trigger_event)
			) $c;",

			'CREATE TABLE ' . self::p( 'vmsos_sequence_steps' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				sequence_id BIGINT UNSIGNED NOT NULL,
				position INT UNSIGNED NOT NULL DEFAULT 0,
				type VARCHAR(30) NOT NULL DEFAULT 'email',
				delay_minutes INT UNSIGNED NOT NULL DEFAULT 0,
				subject VARCHAR(255) NOT NULL DEFAULT '',
				body LONGTEXT NULL,
				personalise TINYINT(1) NOT NULL DEFAULT 0,
				settings LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY seq_order (sequence_id, position),
				KEY brand_seq (brand_id, sequence_id)
			) $c;",

			'CREATE TABLE ' . self::p( 'vmsos_enrollments' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				sequence_id BIGINT UNSIGNED NOT NULL,
				contact_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				current_step INT UNSIGNED NOT NULL DEFAULT 0,
				next_run_at DATETIME NULL,
				last_step_at DATETIME NULL,
				stop_reason VARCHAR(60) NOT NULL DEFAULT '',
				meta LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY seq_contact (sequence_id, contact_id),
				KEY due (brand_id, status, next_run_at),
				KEY brand_contact (brand_id, contact_id)
			) $c;",

			'CREATE TABLE ' . self::p( 'vmsos_email_events' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				enrollment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				sequence_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				step_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				type VARCHAR(20) NOT NULL DEFAULT 'sent',
				token CHAR(32) NOT NULL DEFAULT '',
				url VARCHAR(500) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY brand_type_time (brand_id, type, created_at),
				KEY token_lookup (token),
				KEY seq_step (sequence_id, step_id)
			) $c;",
		];
	}

	/**
	 * Schema v5 — durable lead memory and outbound observability.
	 *
	 * @return array<int, string>
	 */
	public static function intelligence(): array {
		$c = self::charset();

		return [
			'CREATE TABLE ' . self::p( 'vmsos_contact_memory' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				contact_id BIGINT UNSIGNED NOT NULL,
				summary TEXT NULL,
				facts LONGTEXT NULL,
				preferences LONGTEXT NULL,
				objections LONGTEXT NULL,
				next_best_action VARCHAR(500) NOT NULL DEFAULT '',
				buying_stage VARCHAR(40) NOT NULL DEFAULT '',
				turns_seen INT UNSIGNED NOT NULL DEFAULT 0,
				refreshed_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY brand_contact (brand_id, contact_id),
				KEY brand_refreshed (brand_id, refreshed_at)
			) $c;",

			'CREATE TABLE ' . self::p( 'vmsos_integration_log' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				brand_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
				integration VARCHAR(40) NOT NULL,
				event VARCHAR(80) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'success',
				message VARCHAR(1000) NOT NULL DEFAULT '',
				ref_type VARCHAR(40) NOT NULL DEFAULT '',
				ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
				duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY brand_integration_time (brand_id, integration, created_at),
				KEY brand_status_time (brand_id, status, created_at)
			) $c;",
		];
	}
}
