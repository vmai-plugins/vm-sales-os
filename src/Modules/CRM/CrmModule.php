<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\CRM;

use VMStudio\SalesOS\AI\ToolRegistry;
use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Core\EventBus;
use VMStudio\SalesOS\Core\Module;
use VMStudio\SalesOS\REST\RestRouter;

final class CrmModule extends Module {

	public static function slug(): string {
		return 'crm';
	}

	public static function label(): string {
		return __( 'CRM', 'vm-sales-os' );
	}

	public function register( Container $container ): void {
		$container->singleton( ContactRepository::class, static fn() => new ContactRepository() );
		$container->singleton( ActivityRepository::class, static fn() => new ActivityRepository() );
		$container->singleton( DealRepository::class, static fn() => new DealRepository() );
		$container->singleton( PipelineRepository::class, static fn() => new PipelineRepository() );
		$container->singleton( LeadForm::class, static fn() => new LeadForm() );
		$container->singleton( FluentFormSync::class, static fn( Container $c ) => new FluentFormSync(
			$c->get( ContactRepository::class ),
			$c->get( \VMStudio\SalesOS\Modules\Chat\ConversationRepository::class ),
			$c->get( ActivityRepository::class ),
			$c->get( \VMStudio\SalesOS\Support\IntegrationLog::class )
		) );
		$container->singleton( LeadScoreEngine::class, static fn( Container $c ) => new LeadScoreEngine(
			$c->get( \VMStudio\SalesOS\AI\ProviderManager::class ),
			$c->get( \VMStudio\SalesOS\Modules\Chat\ConversationRepository::class ),
			$c->get( ContactRepository::class ),
			$c->get( \VMStudio\SalesOS\Modules\Channels\WhatsAppClient::class ),
			$c->get( \VMStudio\SalesOS\Modules\Channels\EvolutionClient::class ),
			$c->get( \VMStudio\SalesOS\Support\Logger::class )
		) );
	}

	public function boot( Container $container ): void {
		$router = $container->get( RestRouter::class );
		$router->add( CrmController::class );
		$router->add( DealsController::class );
		$router->add( FormController::class );

		$container->get( LeadForm::class )->boot();
		$container->get( FluentFormSync::class )->boot();
		$this->registerTools( $container );

		$events = $container->get( EventBus::class );

		// Autonomous live BANT scoring on every reply turn
		$events->listen(
			'chat.replied',
			static function ( array $payload ) use ( $container ): void {
				$conversationId = (int) ( $payload['conversation_id'] ?? 0 );
				if ( $conversationId > 0 ) {
					$container->get( LeadScoreEngine::class )->evaluate( $conversationId );
				}
			}
		);

		// A finished conversation is the moment we know most about a lead.
		$events->listen(
			'conversation.closed',
			static function ( array $payload ) use ( $container ): void {
				$contactId = (int) ( $payload['contact_id'] ?? 0 );
				if ( $contactId > 0 ) {
					$container->get( LeadScorer::class )->score( $contactId, (string) ( $payload['transcript'] ?? '' ) );
				}
			}
		);

		$events->listen(
			'contact.captured',
			static function ( array $payload ) use ( $container ): void {
				$container->get( ActivityRepository::class )->log(
					'contact',
					(int) ( $payload['contact_id'] ?? 0 ),
					'capture',
					sprintf( 'Captured from %s', (string) ( $payload['source'] ?? 'web' ) ),
					'ai'
				);
			}
		);
	}

	/** Tools the chat agent can call to capture a lead. */
	private function registerTools( Container $container ): void {
		$container->get( ToolRegistry::class )->register(
			[
				'name'        => 'update_contact',
				'description' => 'Save or update what you know about the visitor. Call this as soon as you learn a name, email, phone number, or what they need.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'first_name'   => [ 'type' => 'string' ],
						'last_name'    => [ 'type' => 'string' ],
						'email'        => [ 'type' => 'string' ],
						'phone'        => [ 'type' => 'string' ],
						'requirements' => [ 'type' => 'string', 'description' => 'What the visitor asked for, in their words.' ],
						'consent'      => [ 'type' => 'boolean', 'description' => 'True only if they agreed to be contacted.' ],
					],
					'required'   => [],
				],
			],
			static function ( array $args, array $context ) use ( $container ): array {
				$contacts = $container->get( ContactRepository::class );

				$id = $contacts->upsert(
					[
						'first_name'   => (string) ( $args['first_name'] ?? '' ),
						'last_name'    => (string) ( $args['last_name'] ?? '' ),
						'email'        => (string) ( $args['email'] ?? '' ),
						'phone'        => (string) ( $args['phone'] ?? '' ),
						'requirements' => (string) ( $args['requirements'] ?? '' ),
						'consent'      => ! empty( $args['consent'] ),
						'source'       => 'chat',
					]
				);

				if ( 0 === $id ) {
					return [ 'error' => 'Could not save the contact.' ];
				}

				$contacts->touch( $id );
				do_action( 'vmsos_contact_captured_in_chat', $id, (int) ( $context['conversation_id'] ?? 0 ) );

				$container->get( EventBus::class )->dispatch(
					'contact.captured',
					[ 'contact_id' => $id, 'source' => 'chat', 'conversation_id' => (int) ( $context['conversation_id'] ?? 0 ) ]
				);

				return [ 'ok' => true, 'contact_id' => $id ];
			},
			'public'
		);
	}
}
