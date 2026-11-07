<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Workspace;

use WP_Error;

/**
 * Paperclip Autonomous Agent Fleet Integration for VM Sales OS.
 * Bridges Sales OS with the Paperclip AI daemon running at agents.vmstudio.digital / localhost:3300.
 */
final class PaperclipService {

	public const LOCAL_URL  = 'http://127.0.0.1:3300/api';
	public const PUBLIC_URL = 'https://agents.vmstudio.digital/api';
	public const COMPANY_ID = 'fb04286e-c64e-45da-b050-5491282f4965';
	public const TOKEN      = 'pcp_board_bb45b8a0b5b96f7a6226a892245f60878ef1663c50aee3ef';

	/** Send authenticated HTTP request to Paperclip daemon */
	public function request( string $endpoint, string $method = 'GET', mixed $body = null ): array {
		$url  = rtrim( self::LOCAL_URL, '/' ) . '/' . ltrim( $endpoint, '/' );
		$args = [
			'method'  => $method,
			'timeout' => 12,
			'headers' => [
				'Authorization' => 'Bearer ' . self::TOKEN,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
		];

		if ( $body !== null && in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
			$args['body'] = is_string( $body ) ? $body : wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		// Fallback to public domain if local daemon port 3300 fails
		if ( is_wp_error( $response ) ) {
			$alt_url  = rtrim( self::PUBLIC_URL, '/' ) . '/' . ltrim( $endpoint, '/' );
			$response = wp_remote_request( $alt_url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'error'   => $response->get_error_message(),
				'data'    => [],
			];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		return [
			'success' => ( $code >= 200 && $code < 300 ),
			'code'    => $code,
			'data'    => is_array( $data ) ? $data : [],
		];
	}

	/** Check Paperclip daemon health */
	public function health(): array {
		$res = $this->request( 'health' );
		return [
			'online'  => $res['success'] && ( $res['data']['status'] ?? '' ) === 'ok',
			'details' => $res['data'] ?? [],
		];
	}

	/** Get all agents in the fleet */
	public function getAgents(): array {
		$res = $this->request( 'companies/' . self::COMPANY_ID . '/agents' );
		if ( ! $res['success'] || ! is_array( $res['data'] ) ) {
			return [];
		}

		$fleet = [];
		foreach ( $res['data'] as $a ) {
			$fleet[] = [
				'id'              => (string) ( $a['id'] ?? '' ),
				'name'            => (string) ( $a['name'] ?? 'Unnamed Agent' ),
				'title'           => (string) ( $a['title'] ?? '' ),
				'role'            => (string) ( $a['role'] ?? 'specialist' ),
				'status'          => (string) ( $a['status'] ?? 'idle' ),
				'lifecycle_state' => (string) ( $a['lifecycleState'] ?? 'ready' ),
				'model'           => (string) ( $a['adapterConfig']['model'] ?? 'omniroute/auto/best-coding-fast' ),
				'palette'         => (string) ( $a['appearance']['paletteId'] ?? 'electric-grove' ),
			];
		}

		return $fleet;
	}

	/** Get all issues/workflow tasks */
	public function getIssues( ?string $status = null ): array {
		$endpoint = 'companies/' . self::COMPANY_ID . '/issues';
		if ( $status !== null && $status !== '' ) {
			$endpoint .= '?status=' . rawurlencode( $status );
		}

		$res = $this->request( $endpoint );
		return $res['success'] && is_array( $res['data'] ) ? $res['data'] : [];
	}

	/** Dispatch an issue / mission to a Paperclip agent */
	public function dispatchTask( string $title, string $description, ?string $agentId = null, string $priority = 'medium' ): array {
		$payload = [
			'title'           => sanitize_text_field( $title ),
			'description'     => sanitize_textarea_field( $description ),
			'status'          => 'todo',
			'priority'        => in_array( $priority, [ 'low', 'medium', 'high', 'critical' ], true ) ? $priority : 'medium',
			'assigneeAgentId' => $agentId ?: null,
		];

		return $this->request( 'companies/' . self::COMPANY_ID . '/issues', 'POST', $payload );
	}
}
