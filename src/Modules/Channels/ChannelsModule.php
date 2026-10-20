<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Channels;

use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Core\Module;
use VMStudio\SalesOS\REST\RestRouter;
use VMStudio\SalesOS\Support\IntegrationLog;
use VMStudio\SalesOS\Support\Logger;
use VMStudio\SalesOS\Support\Vault;

/**
 * Multi-Channel Telephony & Messaging.
 *
 * Connects WhatsApp and AI Voice Telephony (Vapi/Retell) directly into
 * the channel-agnostic conversation engine, operator inbox, and lead scoring.
 */
final class ChannelsModule extends Module {

	public static function slug(): string {
		return 'channels';
	}

	public static function label(): string {
		return __( 'Channels (WhatsApp & Voice)', 'vm-sales-os' );
	}

	public static function requires(): array {
		return [ 'chat' ];
	}

	public function register( Container $container ): void {
		$container->singleton(
			EvolutionClient::class,
			static fn( Container $c ) => new EvolutionClient(
				$c->get( Vault::class ),
				$c->get( IntegrationLog::class ),
				$c->get( Logger::class )
			)
		);

		$container->singleton(
			WhatsAppClient::class,
			static fn( Container $c ) => new WhatsAppClient(
				$c->get( Vault::class ),
				$c->get( IntegrationLog::class ),
				$c->get( Logger::class ),
				$c->get( EvolutionClient::class )
			)
		);

		$container->singleton(
			VoiceClient::class,
			static fn( Container $c ) => new VoiceClient(
				$c->get( Vault::class ),
				$c->get( IntegrationLog::class ),
				$c->get( Logger::class )
			)
		);
	}

	public function boot( Container $container ): void {
		$container->get( RestRouter::class )->add( WhatsAppWebhookController::class );
		$container->get( RestRouter::class )->add( EvolutionWebhookController::class );
		$container->get( RestRouter::class )->add( VoiceWebhookController::class );

		// Deliver human replies from the inbox back to WhatsApp.
		add_action(
			'vmsos_outbound_message',
			static function ( array $conversation, string $body ) use ( $container ): void {
				if ( 'whatsapp' !== ( $conversation['channel'] ?? '' ) ) {
					return;
				}

				$meta  = json_decode( (string) ( $conversation['meta'] ?? '' ), true );
				$phone = is_array( $meta ) ? (string) ( $meta['phone'] ?? '' ) : '';

				if ( '' === $phone ) {
					return;
				}

				$container->get( WhatsAppClient::class )->send( $phone, $body );
			},
			10,
			2
		);
	}
}
