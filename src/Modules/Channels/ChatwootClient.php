<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Channels;

use VMStudio\SalesOS\Support\IntegrationLog;
use VMStudio\SalesOS\Support\Logger;
use VMStudio\SalesOS\Support\Vault;

/**
 * Chatwoot platform transport.
 *
 * Chatwoot owns the operator inbox and the mobile app; Sales OS owns the
 * assistant, the lead record and the knowledge. This class is the seam between
 * them and does nothing else: it speaks Chatwoot's REST API and reports every
 * call to the integration log so a broken token shows up on a screen instead of
 * in a silent failure.
 */
final class ChatwootClient {

	public function __construct(
		private Vault $vault,
		private IntegrationLog $integrations,
		private Logger $logger
	) {}

	public function configured(): bool {
		return '' !== $this->baseUrl() && '' !== $this->token() && '' !== $this->accountId();
	}

	public function baseUrl(): string {
		return rtrim( (string) get_option( 'vmsos_chatwoot_url', '' ), '/' );
	}

	public function accountId(): string {
		return (string) get_option( 'vmsos_chatwoot_account_id', '' );
	}

	public function inboxId(): string {
		return (string) get_option( 'vmsos_chatwoot_inbox_id', '' );
	}

	private function token(): string {
		return $this->vault->get( 'chatwoot_token' );
	}

	/** Shared secret for inbound webhook verification. */
	public function webhookSecret(): string {
		return $this->vault->get( 'chatwoot_webhook_secret' );
	}

	/**
	 * One place for every HTTP call, so timing, logging and error shape are
	 * identical no matter which endpoint is being hit.
	 *
	 * @param array<string, mixed> $body Request payload.
	 * @return array<string, mixed>|null Decoded response, or null on failure.
	 */
	private function request( string $method, string $path, array $body = [] ): ?array {
		if ( ! $this->configured() ) {
			$this->logger->error( 'chatwoot', 'Call skipped: channel not configured' );
			return null;
		}

		$url  = $this->baseUrl() . '/api/v1/accounts/' . rawurlencode( $this->accountId() ) . $path;
		$args = [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'api_access_token' => $this->token(),
				'Content-Type'     => 'application/json',
			],
		];

		if ( [] !== $body ) {
			$args['body'] = (string) wp_json_encode( $body );
		}

		$startedAt = microtime( true );
		$response  = wp_remote_request( $url, $args );
		$trace     = [ 'duration_ms' => (int) round( ( microtime( true ) - $startedAt ) * 1000 ) ];
		$event     = trim( $method . ' ' . preg_replace( '#/\d+#', '/:id', $path ) );

		if ( is_wp_error( $response ) ) {
			$this->integrations->failure( 'chatwoot', $event, $response->get_error_message(), $trace );
			$this->logger->error( 'chatwoot', 'Request failed', [ 'error' => $response->get_error_message() ] );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$snippet = mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 500 );
			$this->integrations->failure( 'chatwoot', $event, 'HTTP ' . $code . ': ' . $snippet, $trace );
			$this->logger->error( 'chatwoot', 'Request rejected', [ 'status' => $code, 'body' => $snippet ] );
			return null;
		}

		$this->integrations->success( 'chatwoot', $event, $trace );

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * @return array<string, mixed>|null First matching contact.
	 */
	public function findContact( string $identifier ): ?array {
		if ( '' === trim( $identifier ) ) {
			return null;
		}

		$data = $this->request( 'GET', '/contacts/search?q=' . rawurlencode( $identifier ) );
		$hit  = $data['payload'][0] ?? null;

		return is_array( $hit ) ? $hit : null;
	}

	/**
	 * Create or update the Chatwoot contact for a Sales OS contact row.
	 *
	 * @param array<string, mixed> $contact Sales OS contact (name, email, phone, id).
	 * @return array<string, mixed>|null Chatwoot contact.
	 */
	public function upsertContact( array $contact ): ?array {
		$email = sanitize_email( (string) ( $contact['email'] ?? '' ) );
		$phone = (string) ( $contact['phone'] ?? '' );

		$existing = '' !== $email ? $this->findContact( $email ) : null;

		if ( ! $existing && '' !== $phone ) {
			$existing = $this->findContact( $phone );
		}

		$payload = [
			'name'              => (string) ( $contact['name'] ?? __( 'Visitor', 'vm-sales-os' ) ),
			'email'             => $email,
			'phone_number'      => $phone,
			'custom_attributes' => [
				'vmsos_contact_id' => (int) ( $contact['id'] ?? 0 ),
				'source'           => 'VM Sales OS',
			],
		];

		$isUpdate = isset( $existing['id'] );
		$result   = $this->request(
			$isUpdate ? 'PUT' : 'POST',
			'/contacts' . ( $isUpdate ? '/' . (int) $existing['id'] : '' ),
			$payload
		);

		if ( null === $result ) {
			return null;
		}

		$saved = $result['payload']['contact'] ?? $result['payload'] ?? $result;

		return is_array( $saved ) ? $saved : null;
	}

	/**
	 * @param array<string, mixed> $attributes Extra conversation attributes.
	 * @return array<string, mixed>|null Chatwoot conversation.
	 */
	public function createConversation( int $contactId, array $attributes = [], string $inboxId = '' ): ?array {
		$inboxId = '' !== $inboxId ? $inboxId : $this->inboxId();

		if ( '' === $inboxId || $contactId <= 0 ) {
			return null;
		}

		$result = $this->request(
			'POST',
			'/conversations',
			[
				'inbox_id'              => $inboxId,
				'contact_id'            => $contactId,
				'additional_attributes' => $attributes,
			]
		);

		return isset( $result['id'] ) ? $result : null;
	}

	/**
	 * @param bool $private Private notes are visible to agents only.
	 */
	public function addMessage( int $conversationId, string $content, string $type = 'outgoing', bool $private = false ): bool {
		if ( $conversationId <= 0 || '' === trim( $content ) ) {
			return false;
		}

		return null !== $this->request(
			'POST',
			'/conversations/' . $conversationId . '/messages',
			[
				'content'      => mb_substr( $content, 0, 10000 ),
				'message_type' => 'incoming' === $type ? 'incoming' : 'outgoing',
				'private'      => $private,
			]
		);
	}

	public function assignConversation( int $conversationId, int $assigneeId = 0, int $teamId = 0 ): bool {
		$payload = [];

		if ( $assigneeId > 0 ) {
			$payload['assignee_id'] = $assigneeId;
		}

		if ( $teamId > 0 ) {
			$payload['team_id'] = $teamId;
		}

		if ( [] === $payload ) {
			return false;
		}

		return null !== $this->request( 'POST', '/conversations/' . $conversationId . '/assignments', $payload );
	}

	/** @param array<int, string> $labels Label slugs. */
	public function addLabels( int $conversationId, array $labels ): bool {
		$labels = array_values( array_filter( array_map( 'sanitize_title', $labels ) ) );

		if ( [] === $labels ) {
			return false;
		}

		return null !== $this->request( 'POST', '/conversations/' . $conversationId . '/labels', [ 'labels' => $labels ] );
	}
}
