<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Core;

/**
 * Domain event bus. Every event is also mirrored to a WordPress action so
 * third-party code can listen without touching the container.
 */
final class EventBus {

	/** @var array<string, array<int, array<int, callable>>> */
	private array $listeners = [];

	public function listen( string $event, callable $listener, int $priority = 10 ): void {
		$this->listeners[ $event ][ $priority ][] = $listener;
	}

	/**
	 * @param array<string, mixed> $payload Event payload.
	 */
	public function dispatch( string $event, array $payload = [] ): void {
		$payload = apply_filters( 'vmsos_event_payload', $payload, $event );

		if ( isset( $this->listeners[ $event ] ) ) {
			$groups = $this->listeners[ $event ];
			ksort( $groups );
			foreach ( $groups as $listeners ) {
				foreach ( $listeners as $listener ) {
					try {
						$listener( $payload, $event );
					} catch ( \Throwable $e ) {
						do_action( 'vmsos_listener_failed', $event, $e );
					}
				}
			}
		}

		do_action( 'vmsos_event', $event, $payload );
		do_action( 'vmsos_event_' . $event, $payload );
	}
}
