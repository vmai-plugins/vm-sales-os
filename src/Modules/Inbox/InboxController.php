<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Inbox;

use VMStudio\SalesOS\Modules\Chat\ChatService;
use VMStudio\SalesOS\Modules\Chat\ConversationRepository;
use VMStudio\SalesOS\REST\AbstractController;
use WP_REST_Request;

final class InboxController extends AbstractController {

	public function __construct(
		private InboxService $inbox,
		private ConversationRepository $conversations,
		private ChatService $chat
	) {}

	public function register(): void {
		$auth = fn() => $this->canOperate();

		register_rest_route(
			self::NAMESPACE,
			'/inbox',
			[ 'methods' => 'GET', 'callback' => [ $this, 'index' ], 'permission_callback' => $auth ]
		);

		register_rest_route(
			self::NAMESPACE,
			'/inbox/(?P<id>\d+)',
			[ 'methods' => 'GET', 'callback' => [ $this, 'show' ], 'permission_callback' => $auth ]
		);

		foreach ( [ 'reply', 'note', 'takeover', 'release', 'assign', 'close', 'reopen' ] as $verb ) {
			register_rest_route(
				self::NAMESPACE,
				'/inbox/(?P<id>\d+)/' . $verb,
				[
					'methods'             => 'POST',
					'callback'            => [ $this, $verb ],
					'permission_callback' => $auth,
				]
			);
		}
	}

	public function index( WP_REST_Request $request ) {
		return $this->ok(
			$this->inbox->inbox(
				[
					'status'      => $this->param( $request, 'status' ),
					'channel'     => $this->param( $request, 'channel' ),
					'assigned_to' => (int) $request->get_param( 'assigned_to' ),
				],
				(int) ( $request->get_param( 'per_page' ) ?: 30 ),
				(int) ( $request->get_param( 'page' ) ?: 1 )
			)
		);
	}

	public function show( WP_REST_Request $request ) {
		$thread = $this->inbox->thread( (int) $request->get_param( 'id' ) );

		if ( ! $thread ) {
			return $this->fail( 'vmsos_not_found', __( 'Conversation not found.', 'vm-sales-os' ), 404 );
		}

		return $this->ok( $thread );
	}

	public function reply( WP_REST_Request $request ) {
		$message = $this->inbox->reply(
			(int) $request->get_param( 'id' ),
			$this->textParam( $request, 'body', 5000 ),
			get_current_user_id()
		);

		if ( ! $message ) {
			return $this->fail( 'vmsos_reply_failed', __( 'Write a reply first.', 'vm-sales-os' ) );
		}

		return $this->ok( [ 'message' => $message ], 201 );
	}

	public function note( WP_REST_Request $request ) {
		$id = $this->inbox->note(
			(int) $request->get_param( 'id' ),
			$this->textParam( $request, 'body', 5000 ),
			get_current_user_id()
		);

		if ( 0 === $id ) {
			return $this->fail( 'vmsos_note_failed', __( 'Write a note first.', 'vm-sales-os' ) );
		}

		return $this->ok( [ 'id' => $id ], 201 );
	}

	public function takeover( WP_REST_Request $request ) {
		return $this->toggle( $this->inbox->takeOver( (int) $request->get_param( 'id' ), get_current_user_id() ) );
	}

	public function release( WP_REST_Request $request ) {
		return $this->toggle( $this->inbox->release( (int) $request->get_param( 'id' ) ) );
	}

	public function assign( WP_REST_Request $request ) {
		$userId = (int) $request->get_param( 'user_id' );
		$userId = $userId > 0 ? $userId : get_current_user_id();

		return $this->toggle( $this->inbox->assign( (int) $request->get_param( 'id' ), $userId ) );
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

	public function reopen( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! $this->conversations->find( $id ) ) {
			return $this->fail( 'vmsos_not_found', __( 'Conversation not found.', 'vm-sales-os' ), 404 );
		}

		$this->conversations->reopen( $id );
		return $this->ok( [ 'reopened' => true ] );
	}

	private function toggle( bool $done ) {
		if ( ! $done ) {
			return $this->fail( 'vmsos_not_found', __( 'Conversation not found.', 'vm-sales-os' ), 404 );
		}

		return $this->ok( [ 'ok' => true ] );
	}
}
