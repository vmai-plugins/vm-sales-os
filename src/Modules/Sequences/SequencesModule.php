<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Sequences;

use VMStudio\SalesOS\AI\ProviderManager;
use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Core\EventBus;
use VMStudio\SalesOS\Core\Module;
use VMStudio\SalesOS\Modules\CRM\ContactRepository;
use VMStudio\SalesOS\Queue\QueueManager;
use VMStudio\SalesOS\REST\RestRouter;
use VMStudio\SalesOS\Support\IntegrationLog;
use VMStudio\SalesOS\Support\Logger;

/**
 * Email sequences: triggered, timed, tracked outreach.
 */
final class SequencesModule extends Module {

	public const TICK = 'vmsos_sequences_tick';

	public static function slug(): string {
		return 'sequences';
	}

	public static function label(): string {
		return __( 'Sequences', 'vm-sales-os' );
	}

	public static function requires(): array {
		return [ 'crm' ];
	}

	public function register( Container $container ): void {
		$container->singleton( SequenceRepository::class, static fn() => new SequenceRepository() );
		$container->singleton( EnrollmentRepository::class, static fn() => new EnrollmentRepository() );

		$container->singleton(
			Personalizer::class,
			static fn( Container $c ) => new Personalizer( $c->get( ProviderManager::class ), $c->get( Logger::class ) )
		);

		$container->singleton(
			Mailer::class,
			static fn( Container $c ) => new Mailer(
				$c->get( EnrollmentRepository::class ),
				$c->get( Personalizer::class ),
				$c->get( IntegrationLog::class ),
				$c->get( Logger::class )
			)
		);

		$container->singleton(
			SequenceRunner::class,
			static fn( Container $c ) => new SequenceRunner(
				$c->get( SequenceRepository::class ),
				$c->get( EnrollmentRepository::class ),
				$c->get( ContactRepository::class ),
				$c->get( QueueManager::class ),
				$c->get( EventBus::class ),
				$c->get( Logger::class )
			)
		);
	}

	public function boot( Container $container ): void {
		$router = $container->get( RestRouter::class );
		$router->add( SequencesController::class );
		$router->add( TrackingController::class );

		$container->get( QueueManager::class )->registerHandler( SendStepJob::class );

		add_action( self::TICK, static fn() => $container->get( SequenceRunner::class )->tick() );

		if ( ! wp_next_scheduled( self::TICK ) ) {
			wp_schedule_event( time() + 90, 'vmsos_minute', self::TICK );
		}

		$this->listenForTriggers( $container );
	}

	private function listenForTriggers( Container $container ): void {
		$events = $container->get( EventBus::class );

		$triggerEvents = (array) apply_filters(
			'vmsos_sequence_trigger_events',
			[ 'contact.captured', 'contact.hot', 'conversation.closed', 'deal.stage_changed' ]
		);

		foreach ( $triggerEvents as $event ) {
			$events->listen(
				(string) $event,
				static function ( array $payload ) use ( $container, $event ): void {
					$container->get( SequenceRunner::class )->enrollFromEvent(
						(string) $event,
						(int) ( $payload['contact_id'] ?? 0 )
					);
				}
			);
		}

		// Someone who engages should stop receiving the chase.
		foreach ( [ 'conversation.awaiting_human', 'conversation.human_replied', 'contact.hot' ] as $signal ) {
			$events->listen(
				$signal,
				static function ( array $payload ) use ( $container ): void {
					$contactId = (int) ( $payload['contact_id'] ?? 0 );
					if ( $contactId > 0 ) {
						$container->get( EnrollmentRepository::class )->stopOnEngagement( $contactId );
					}
				}
			);
		}

		/**
		 * Stop every sequence for a contact from anywhere.
		 *
		 * @param int $contactId Contact id.
		 */
		add_action(
			'vmsos_stop_sequences',
			static function ( int $contactId, string $reason = 'manual' ) use ( $container ): void {
				$container->get( EnrollmentRepository::class )->stopAllForContact( $contactId, $reason );
			},
			10,
			2
		);
	}
}
