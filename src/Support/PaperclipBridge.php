<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Support;

/**
 * Autonomous bridge connecting VM Sales OS events to Paperclip Autonomous Business Suite.
 */
final class PaperclipBridge {

	public const BASE_URL   = 'https://agents.vmstudio.digital/api';
	public const LOCAL_URL  = 'http://127.0.0.1:3300/api';
	public const COMPANY_ID = 'fb04286e-c64e-45da-b050-5491282f4965';
	public const TOKEN      = 'pcp_board_vmstudio_7c8d19a11451e09643b0eab79a6253551e1a87eb';

	// Agent IDs
	public const AGENT_CHIEF_OF_STAFF = '70d6fd5d-36f8-4871-9d5b-587131c9f4e6';
	public const AGENT_LEO_GROWTH     = '6b891406-da0d-492b-ada2-60127efba6c2';
	public const AGENT_SEO_SNIPER     = '476d1030-fbf5-40e4-8c41-567b9958586f';
	public const AGENT_TECH_LEAD      = '2225ef14-bfed-4e59-b341-e3dc77c705db';
	public const AGENT_CLIENT_QA      = 'bf03582b-7c93-4bcd-99f5-f92caf0fa6a5';

	/**
	 * Dispatch a high-priority mission / issue to Paperclip.
	 *
	 * @param string      $title         Short summary of mission.
	 * @param string      $description   Full context, contact details, requirements.
	 * @param string      $agentId       Target agent UUID. Defaults to Leo (Growth).
	 * @param string      $priority      'low' | 'medium' | 'high' | 'urgent'.
	 * @param bool        $notifyFounder Whether to trigger an instant WhatsApp alert to Founder.
	 * @return array<string, mixed>|null Issue object from Paperclip or null on failure.
	 */
	public static function dispatchIssue(
		string $title,
		string $description,
		string $agentId = self::AGENT_LEO_GROWTH,
		string $priority = 'high',
		bool $notifyFounder = true
	): ?array {
		$res = self::request( '/issues', 'POST', [
			'title'           => $title,
			'description'     => $description,
			'priority'        => $priority,
			'assigneeAgentId' => $agentId,
		] );

		if ( $notifyFounder && $res && ! empty( $res['identifier'] ) ) {
			$waMessage = sprintf(
				"🎯 *New Lead Dispatched to Paperclip (%s)*\n\n" .
				"• *Task*: %s\n" .
				"• *Priority*: %s\n" .
				"• *Assigned Agent*: Leo (Growth Strategist)\n\n" .
				"Leo is analyzing client intent and drafting your tailored proposal.",
				$res['identifier'],
				$title,
				strtoupper( $priority )
			);
			self::notifyFounderWhatsApp( $waMessage );
		}

		return $res;
	}

	/**
	 * Send an instant WhatsApp notification to the Founder via Evolution API.
	 */
	public static function notifyFounderWhatsApp( string $message ): bool {
		$evoUrl = 'http://127.0.0.1:18080/message/sendText/vmstudio';
		$apiKey = 'bebf8554b69b4eff665465956069890dadeb84547a964ba0ac129e43e98576a2';
		$founderPhone = get_option( 'vmsos_widget_whatsapp', '918368316403' );

		$res = wp_remote_post( $evoUrl, [
			'timeout'   => 8,
			'sslverify' => false,
			'headers'   => [
				'apikey'       => $apiKey,
				'Content-Type' => 'application/json',
			],
			'body'      => wp_json_encode( [
				'number' => preg_replace( '/[^0-9]/', '', (string) $founderPhone ),
				'text'   => $message,
			] ),
		] );

		return ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) >= 200 && wp_remote_retrieve_response_code( $res ) < 300;
	}

	/**
	 * Perform an authenticated request against the Paperclip API.
	 */
	public static function request( string $endpoint, string $method = 'GET', ?array $body = null ): ?array {
		if ( preg_match( '#^/(heartbeat-runs|agents)/[a-f0-9-]+#i', $endpoint ) ) {
			$url = self::BASE_URL . $endpoint;
		} else {
			$url = self::BASE_URL . '/companies/' . self::COMPANY_ID . $endpoint;
		}
		$args = [
			'method'    => $method,
			'timeout'   => 10,
			'sslverify' => false,
			'headers'   => [
				'Authorization' => 'Bearer ' . self::TOKEN,
				'Content-Type'  => 'application/json',
			],
		];
		if ( $body !== null && in_array( $method, [ 'POST', 'PATCH', 'PUT' ], true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		// Local loopback fallback if external hairpin NAT or public DNS fails
		if ( is_wp_error( $response ) ) {
			$localUrl = preg_match( '#^/(heartbeat-runs|agents)/[a-f0-9-]+#i', $endpoint )
				? self::LOCAL_URL . $endpoint
				: self::LOCAL_URL . '/companies/' . self::COMPANY_ID . $endpoint;
			$args['headers']['Host'] = 'agents.vmstudio.digital';
			$response = wp_remote_request( $localUrl, $args );
			if ( is_wp_error( $response ) ) {
				return null;
			}
		}

		$code = wp_remote_retrieve_response_code( $response );
		$rawBody = wp_remote_retrieve_body( $response );
		$data = json_decode( $rawBody, true );
		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			return $data;
		}

		return null;
	}

	/**
	 * Pause a specific agent by ID.
	 */
	public static function pauseAgent( string $agentId, string $reason = 'manual' ): ?array {
		return self::request( '/agents/' . rawurlencode( $agentId ), 'PATCH', [
			'status'      => 'paused',
			'pauseReason' => $reason,
		] );
	}

	/**
	 * Resume a specific agent by ID.
	 */
	public static function resumeAgent( string $agentId ): ?array {
		return self::request( '/agents/' . rawurlencode( $agentId ), 'PATCH', [
			'status' => 'active',
		] );
	}

	/**
	 * Freeze all agents across the company immediately.
	 */
	public static function emergencyHalt( string $reason = 'Emergency halt triggered from Z-Agents' ): array {
		$agents = self::request( '/agents' ) ?? [];
		$halted = [];
		foreach ( $agents as $agent ) {
			$id = $agent['id'] ?? '';
			if ( ! empty( $id ) && ( $agent['status'] ?? '' ) !== 'paused' ) {
				$res = self::pauseAgent( $id, $reason );
				if ( $res ) {
					$halted[] = $agent['name'] ?? $id;
				}
			}
		}
		return $halted;
	}

	/**
	 * Resume all paused agents across the company.
	 */
	public static function emergencyResume(): array {
		$agents = self::request( '/agents' ) ?? [];
		$resumed = [];
		foreach ( $agents as $agent ) {
			$id = $agent['id'] ?? '';
			if ( ! empty( $id ) && ( $agent['status'] ?? '' ) === 'paused' ) {
				$res = self::resumeAgent( $id );
				if ( $res ) {
					$resumed[] = $agent['name'] ?? $id;
				}
			}
		}
		return $resumed;
	}
}
