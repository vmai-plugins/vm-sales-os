<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Chat;

use VMStudio\SalesOS\REST\AbstractController;
use WP_REST_Request;

final class ChatController extends AbstractController {

	public function __construct( private ChatService $chat, private ConversationRepository $conversations ) {}

	public function register(): void {
		// Public: the widget talks to this.
		register_rest_route(
			self::NAMESPACE,
			'/chat/message',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'message' ],
				'permission_callback' => fn( WP_REST_Request $request ) => $this->guardPublic( $request ),
				'args'                => [
					'message' => [ 'required' => true, 'type' => 'string' ],
					'uuid'    => [ 'type' => 'string' ],
				],
			]
		);

		// Public: the widget streams token-by-token using SSE.
		register_rest_route(
			self::NAMESPACE,
			'/chat/stream',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'stream' ],
				'permission_callback' => fn( WP_REST_Request $request ) => $this->guardPublic( $request ),
				'args'                => [
					'message' => [ 'required' => true, 'type' => 'string' ],
					'uuid'    => [ 'type' => 'string' ],
				],
			]
		);

		// Public: the widget polls this so a human reply lands without a refresh.
		register_rest_route(
			self::NAMESPACE,
			'/chat/poll',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'poll' ],
				'permission_callback' => fn( WP_REST_Request $request ) => $this->guardPoll( $request ),
				'args'                => [
					'uuid'  => [ 'required' => true, 'type' => 'string' ],
					'after' => [ 'type' => 'integer' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/chat/conversations',
			[ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => fn() => $this->canOperate() ]
		);

		register_rest_route(
			self::NAMESPACE,
			'/chat/conversations/(?P<id>\d+)',
			[ 'methods' => 'GET', 'callback' => [ $this, 'show' ], 'permission_callback' => fn() => $this->canOperate() ]
		);

		register_rest_route(
			self::NAMESPACE,
			'/chat/conversations/(?P<id>\d+)/close',
			[ 'methods' => 'POST', 'callback' => [ $this, 'close' ], 'permission_callback' => fn() => $this->canOperate() ]
		);
	}

	/** Anonymous visitors are allowed, but throttled and origin-checked. */
	private function guardPublic( WP_REST_Request $request ) {
		if ( ! get_option( 'vmsos_widget_enabled', true ) ) {
			return $this->fail( 'vmsos_widget_off', __( 'Chat is turned off.', 'vm-sales-os' ), 403 );
		}

		// Honeypot: real widgets never fill this.
		if ( '' !== (string) $request->get_param( 'company_website' ) ) {
			return $this->fail( 'vmsos_rejected', __( 'Rejected.', 'vm-sales-os' ), 403 );
		}

		$throttle = $this->throttle( 'chat', (int) get_option( 'vmsos_chat_rate_limit', 20 ), MINUTE_IN_SECONDS * 5 );
		if ( true !== $throttle ) {
			return $throttle;
		}

		return true;
	}

	public function message( WP_REST_Request $request ) {
		$message = $this->textParam( $request, 'message', 4000 );

		if ( '' === trim( $message ) ) {
			return $this->fail( 'vmsos_empty_message', __( 'Type a message first.', 'vm-sales-os' ) );
		}

		$attribution = $request->get_param( 'attribution' );
		$context     = [
			'channel'      => 'web',
			'visitor_hash' => $this->visitorHash( $request ),
			'page_url'     => esc_url_raw( (string) $request->get_param( 'page_url' ) ),
		];
		if ( is_array( $attribution ) ) {
			$context['attribution'] = $attribution;
		}

		$result = $this->chat->reply(
			$this->param( $request, 'uuid' ),
			$message,
			$context
		);

		return $this->ok( $result );
	}

	public function stream( WP_REST_Request $request ) {
		$message = $this->textParam( $request, 'message', 4000 );

		if ( '' === trim( $message ) ) {
			return $this->fail( 'vmsos_empty_message', __( 'Type a message first.', 'vm-sales-os' ) );
		}

		$attribution = $request->get_param( 'attribution' );
		$context     = [
			'channel'      => 'web',
			'visitor_hash' => $this->visitorHash( $request ),
			'page_url'     => esc_url_raw( (string) $request->get_param( 'page_url' ) ),
		];
		if ( is_array( $attribution ) ) {
			$context['attribution'] = $attribution;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-transform' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		echo ": ping\n\n";
		flush();

		$result = $this->chat->streamReply(
			$this->param( $request, 'uuid' ),
			$message,
			$context,
			static function( string $token ) {
				echo 'data: ' . wp_json_encode( [ 'token' => $token ] ) . "\n\n";
				flush();
			}
		);

		echo 'data: ' . wp_json_encode( [
			'done'     => true,
			'uuid'     => $result['uuid'],
			'reply'    => $result['reply'],
			'handover' => $result['handover'],
		] ) . "\n\n";
		flush();
		exit;
	}

	public function index( WP_REST_Request $request ) {
		return $this->ok(
			[ 'conversations' => $this->conversations->recent( 25, $this->param( $request, 'status' ) ) ]
		);
	}

	public function show( WP_REST_Request $request ) {
		$id           = (int) $request->get_param( 'id' );
		$conversation = $this->conversations->find( $id );

		if ( ! $conversation ) {
			return $this->fail( 'vmsos_not_found', __( 'Conversation not found.', 'vm-sales-os' ), 404 );
		}

		return $this->ok( [ 'conversation' => $conversation, 'messages' => $this->conversations->history( $id, 60 ) ] );
	}

	public function close( WP_REST_Request $request ) {
		$id           = (int) $request->get_param( 'id' );
		$conversation = $this->conversations->find( $id );

		if ( ! $conversation ) {
			return $this->fail( 'vmsos_not_found', __( 'Conversation not found.', 'vm-sales-os' ), 404 );
		}

		$this->chat->close( $id, (int) $conversation['contact_id'] );
		return $this->ok( [ 'closed' => true ] );
	}

	/** Polling is cheap but not free: same widget gate, looser throttle. */
	private function guardPoll( WP_REST_Request $request ) {
		if ( ! get_option( 'vmsos_widget_enabled', true ) ) {
			return $this->fail( 'vmsos_widget_off', __( 'Chat is turned off.', 'vm-sales-os' ), 403 );
		}
		return $this->throttle( 'chat_poll', 120, MINUTE_IN_SECONDS * 5 );
	}

	/**
	 * Return turns the visitor has not seen yet.
	 *
	 * The visitor hash must match the one the thread was opened with, so a
	 * guessed uuid alone does not expose a transcript.
	 */
	public function poll( WP_REST_Request $request ) {
		$conversation = $this->conversations->findByUuid( $this->param( $request, 'uuid' ) );

		if ( ! $conversation ) {
			return $this->fail( 'vmsos_not_found', __( 'Conversation not found.', 'vm-sales-os' ), 404 );
		}

		$expected = (string) $conversation['visitor_hash'];
		if ( '' !== $expected && ! hash_equals( $expected, $this->visitorHash( $request ) ) ) {
			return $this->fail( 'vmsos_forbidden', __( 'Not your conversation.', 'vm-sales-os' ), 403 );
		}

		$messages = $this->conversations->messagesSince(
			(int) $conversation['id'],
			(int) $request->get_param( 'after' ),
			20
		);

		return $this->ok(
			[
				'messages' => array_values(
					array_filter(
						$messages,
						static fn( array $row ): bool => 'user' !== $row['role']
					)
				),
				'status'   => (string) $conversation['status'],
				'handover' => (bool) $conversation['handover'],
			]
		);
	}

	private function visitorHash( WP_REST_Request $request ): string {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$agent = $request->get_header( 'user_agent' ) ?? '';
		return substr( hash_hmac( 'sha256', $ip . '|' . $agent, wp_salt( 'auth' ) ), 0, 48 );
	}
}
