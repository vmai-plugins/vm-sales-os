<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Inbox;

use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Core\EventBus;
use VMStudio\SalesOS\Core\Module;
use VMStudio\SalesOS\Modules\Chat\ConversationRepository;
use VMStudio\SalesOS\REST\RestRouter;
use VMStudio\SalesOS\Support\Logger;

/**
 * Operator inbox: the screen where a human takes a thread off the assistant.
 */
final class InboxModule extends Module {

	private const PENDING = 'vmsos_inbox_pending';

	public static function slug(): string {
		return 'inbox';
	}

	public static function label(): string {
		return __( 'Inbox', 'vm-sales-os' );
	}

	public static function requires(): array {
		return [ 'chat' ];
	}

	public function register( Container $container ): void {
		$container->singleton(
			InboxService::class,
			static fn( Container $c ) => new InboxService(
				$c->get( ConversationRepository::class ),
				$c->get( EventBus::class ),
				$c->get( Logger::class )
			)
		);
	}

	public function boot( Container $container ): void {
		$container->get( RestRouter::class )->add( InboxController::class );

		$events = $container->get( EventBus::class );

		$events->listen( 'conversation.awaiting_human', [ $this, 'onWaiting' ] );
		$events->listen( 'conversation.taken_over', [ $this, 'clearBadge' ] );
		$events->listen( 'conversation.released', [ $this, 'clearBadge' ] );

		add_action( 'vmsos_handover_requested', [ $this, 'onHandoverRequested' ], 10, 2 );
	}

	/** @param array<string, mixed> $payload Event payload. */
	public function onWaiting( array $payload ): void {
		$this->bumpBadge();
		$this->notify(
			__( 'A visitor is waiting for a human reply', 'vm-sales-os' ),
			(string) ( $payload['message'] ?? '' ),
			(int) ( $payload['conversation_id'] ?? 0 )
		);
	}

	public function onHandoverRequested( int $conversationId, string $reason ): void {
		$this->bumpBadge();
		$this->notify( __( 'The assistant asked for a human', 'vm-sales-os' ), $reason, $conversationId );
	}

	/** @param array<string, mixed> $payload Event payload. */
	public function clearBadge( array $payload = [] ): void {
		delete_transient( self::PENDING );
	}

	private function bumpBadge(): void {
		set_transient( self::PENDING, (int) get_transient( self::PENDING ) + 1, DAY_IN_SECONDS );
	}

	public static function pending(): int {
		return (int) get_transient( self::PENDING );
	}

	/**
	 * One email per thread per hour. Handover notifications that arrive on
	 * every visitor turn are notifications people learn to ignore.
	 */
	private function notify( string $subject, string $body, int $conversationId ): void {
		if ( ! get_option( 'vmsos_notify_handover', true ) || $conversationId <= 0 ) {
			return;
		}

		$key = 'vmsos_notified_' . $conversationId;
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, HOUR_IN_SECONDS );

		$to = (string) get_option( 'vmsos_notify_email', get_option( 'admin_email' ) );
		if ( ! is_email( $to ) ) {
			return;
		}

		wp_mail(
			$to,
			'[' . get_bloginfo( 'name' ) . '] ' . $subject,
			sprintf(
				"%s\n\n%s\n\n%s",
				$subject,
				mb_substr( wp_strip_all_tags( $body ), 0, 500 ),
				admin_url( 'admin.php?page=vm-sales-os#inbox/' . $conversationId )
			)
		);
	}
}
