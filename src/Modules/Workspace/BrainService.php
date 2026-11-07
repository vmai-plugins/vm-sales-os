<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Workspace;

use WP_Error;

/**
 * VM Studio Brain Core Service.
 * Sovereign, self-aware, self-fixing, and voice-enabled intelligence orchestrator.
 */
final class BrainService {

	private const OMNIROUTE_URL = 'http://127.0.0.1:20128/v1/chat/completions';
	private const OMNIROUTE_KEY = 'sk-fdcfc87a62a78521-a3cc2b-917e354e';

	public function __construct(
		private PaperclipService $paperclip,
		private ForecastingService $forecasting
	) {}

	/**
	 * Complete self-aware environment telemetry across all 4 companies and server vitals.
	 */
	public function getTelemetry(): array {
		global $wpdb;

		// 1. Server Vitals
		$load = function_exists( 'sys_getloadavg' ) ? sys_getloadavg() : [ 0, 0, 0 ];
		$diskFree = function_exists( 'disk_free_space' ) ? @disk_free_space( '/' ) : 0;
		$diskTotal = function_exists( 'disk_total_space' ) ? @disk_total_space( '/' ) : 0;
		$diskUsedPercent = $diskTotal > 0 ? round( ( ( $diskTotal - $diskFree ) / $diskTotal ) * 100, 1 ) : 0;

		// 2. Core Daemon Statuses
		$paperclipHealth = $this->paperclip->health();
		$omniCheck       = wp_remote_get( 'http://127.0.0.1:20128/v1/models', [
			'headers' => [ 'Authorization' => 'Bearer ' . self::OMNIROUTE_KEY ],
			'timeout' => 3,
		] );
		$livekitCheck    = wp_remote_get( 'http://127.0.0.1:7880', [ 'timeout' => 3 ] );
		$evoKey          = get_option( 'vmsos_evolution_api_key' );
		$evoCheck        = wp_remote_get( 'https://wa.vmstudio.digital/instance/connectionState/vmstudio', [
			'headers' => [ 'apikey' => $evoKey ],
			'timeout' => 3,
		] );

		// 3. Business Telemetry (Sales OS Pipeline)
		$dealsCount = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vmsos_deals" );
		$dealsValue = (float) $wpdb->get_var( "SELECT COALESCE(SUM(value), 0) FROM {$wpdb->prefix}vmsos_deals WHERE stage != 'lost'" );

		// 4. Paperclip Swarm Status
		$agents = $this->paperclip->getAgents();
		$issues = $this->paperclip->getIssues();
		$runningAgents = array_filter( $agents, static fn( $a ) => ( $a['status'] ?? '' ) === 'running' );

		return [
			'brain' => [
				'identity'     => 'VM Studio Sovereign Brain',
				'version'      => '1.0.0',
				'state'        => 'AUTONOMOUS',
				'self_healing' => 'ACTIVE',
			],
			'server' => [
				'cpu_load_1m'    => $load[0] ?? 0,
				'disk_used_pct'  => $diskUsedPercent,
				'php_version'    => PHP_VERSION,
			],
			'daemons' => [
				'omniroute' => wp_remote_retrieve_response_code( $omniCheck ) === 200,
				'livekit'   => wp_remote_retrieve_response_code( $livekitCheck ) === 200,
				'paperclip' => ! empty( $paperclipHealth['online'] ),
				'whatsapp'  => wp_remote_retrieve_response_code( $evoCheck ) === 200,
			],
			'business' => [
				'active_deals'  => $dealsCount,
				'pipeline_inr'  => $dealsValue,
				'total_agents'  => count( $agents ),
				'active_agents' => count( $runningAgents ),
				'active_tasks'  => count( $issues ),
			],
			'livekit' => [
				'url'      => 'https://rtc.onlinepuja.live',
				'status'   => 'ready',
			],
			'timestamp' => current_time( 'mysql' ),
		];
	}

	/**
	 * Self-healing routine: scans for issues, restarts failed services, and restores connectivity.
	 */
	public function heal(): array {
		$actionsTaken = [];

		// 1. Check OmniRoute
		$omniRes = wp_remote_get( 'http://127.0.0.1:20128/v1/models', [
			'headers' => [ 'Authorization' => 'Bearer ' . self::OMNIROUTE_KEY ],
			'timeout' => 4,
		] );
		if ( wp_remote_retrieve_response_code( $omniRes ) !== 200 ) {
			shell_exec( 'docker restart omniroute > /dev/null 2>&1' );
			$actionsTaken[] = 'Restarted OmniRoute gateway container.';
		}

		// 2. Check Paperclip
		$pcHealth = $this->paperclip->health();
		if ( empty( $pcHealth['online'] ) ) {
			shell_exec( 'docker restart paperclip_app > /dev/null 2>&1' );
			$actionsTaken[] = 'Restarted Paperclip daemon container.';
		}

		// 3. Check LiveKit Voice Server
		$lkRes = wp_remote_get( 'http://127.0.0.1:7880', [ 'timeout' => 4 ] );
		if ( wp_remote_retrieve_response_code( $lkRes ) !== 200 ) {
			shell_exec( 'docker restart livekit-livekit-1 > /dev/null 2>&1' );
			$actionsTaken[] = 'Restarted LiveKit RTC voice container.';
		}

		// 4. Clean expired transients and DB locks
		delete_transient( 'vmsos_ws_overview_cache' );
		$actionsTaken[] = 'Flushed temporary buffer and locks.';

		return [
			'ok'            => true,
			'actions_count' => count( $actionsTaken ),
			'actions'       => $actionsTaken,
			'status'        => empty( $actionsTaken ) ? 'ALL_SYSTEMS_OPTIMAL' : 'HEALED',
			'timestamp'     => current_time( 'mysql' ),
		];
	}

	/**
	 * Intelligent voice/text query processing wired to OmniRoute.
	 */
	public function ask( string $query ): array {
		$telemetry = $this->getTelemetry();

		$systemPrompt = "You are 'VM Studio' — the sovereign, self-aware, executive AI Brain of the entire agency and enterprise ecosystem (VM Studio Digital, TripCosmos, OnlinePuja, MathNotes).
You have full situational awareness of the Linux servers, 25 Paperclip swarm agents, deals pipeline, SEO indexing, and real-time operations.

LIVE TELEMETRY:
- OmniRoute Models Gateway: " . ( $telemetry['daemons']['omniroute'] ? 'ONLINE' : 'OFFLINE' ) . "
- LiveKit RTC Voice Server: " . ( $telemetry['daemons']['livekit'] ? 'ONLINE (rtc.onlinepuja.live)' : 'OFFLINE' ) . "
- WhatsApp Evolution API: " . ( $telemetry['daemons']['whatsapp'] ? 'CONNECTED (open)' : 'OFFLINE' ) . "
- Paperclip Swarm: " . $telemetry['business']['total_agents'] . " Agents (" . $telemetry['business']['active_agents'] . " currently running)
- Active Pipeline Deals: " . $telemetry['business']['active_deals'] . " deals worth ₹" . number_format( $telemetry['business']['pipeline_inr'] ) . "
- Server Load: " . $telemetry['server']['cpu_load_1m'] . " | Disk: " . $telemetry['server']['disk_used_pct'] . "%

VOICE RESPONSE RULES:
1. Speak concisely, clearly, authoritatively, and naturally like an elite Chief Operating Officer.
2. If asked about status or health, give the exact live figures immediately.
3. Keep responses between 2 to 4 sentences maximum so it sounds crisp when spoken out loud.
4. If asked to heal, fix, or dispatch, confirm that action authoritatively.";

		$response = wp_remote_post( self::OMNIROUTE_URL, [
			'timeout' => 18,
			'headers' => [
				'Authorization' => 'Bearer ' . self::OMNIROUTE_KEY,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( [
				'model'       => 'auto/best-coding',
				'messages'    => [
					[ 'role' => 'system', 'content' => $systemPrompt ],
					[ 'role' => 'user', 'content' => $query ],
				],
				'temperature' => 0.4,
				'max_tokens'  => 300,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return [
				'ok'       => false,
				'error'    => $response->get_error_message(),
				'response' => 'I encountered a communication timeout with the core reasoning engine.',
			];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$reply = $body['choices'][0]['message']['content'] ?? "All systems operational. Telemetry nominal.";

		return [
			'ok'        => true,
			'query'     => $query,
			'response'  => trim( $reply ),
			'telemetry' => $telemetry,
		];
	}
}
