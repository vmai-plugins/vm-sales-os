<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Chat;

use VMStudio\SalesOS\AI\ToolRegistry;
use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Core\Module;
use VMStudio\SalesOS\REST\RestRouter;

final class ChatModule extends Module {

	public static function slug(): string {
		return 'chat';
	}

	public static function label(): string {
		return __( 'Chat', 'vm-sales-os' );
	}

	public function register( Container $container ): void {
		$container->singleton( ConversationRepository::class, static fn() => new ConversationRepository() );
		$container->singleton( SpamGuard::class, static fn() => new SpamGuard() );
	}

	public function boot( Container $container ): void {
		$container->get( RestRouter::class )->add( ChatController::class );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueueWidget' ] );
		add_action( 'wp_footer', [ $this, 'renderWidgetRoot' ] );
		add_shortcode( 'vmsos_chat', [ $this, 'shortcode' ] );

		$this->registerTools( $container->get( ToolRegistry::class ), $container );
		\VMStudio\SalesOS\AI\UniversalTools::register( $container->get( ToolRegistry::class ), $container );
	}

	private function registerTools( ToolRegistry $tools, Container $container ): void {
		$tools->register(
			[
				'name'        => 'request_human',
				'description' => 'Hand the conversation to a human when the visitor asks for a person, is unhappy, or asks something you cannot answer.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'reason' => [ 'type' => 'string', 'description' => 'Why a human is needed.' ],
					],
					'required'   => [ 'reason' ],
				],
			],
			static function ( array $args, array $context ) use ( $container ): array {
				$conversationId = (int) ( $context['conversation_id'] ?? 0 );
				if ( $conversationId <= 0 ) {
					return [ 'error' => 'No conversation in context.' ];
				}

				$container->get( ConversationRepository::class )->requestHandover( $conversationId );
				do_action( 'vmsos_handover_requested', $conversationId, (string) ( $args['reason'] ?? '' ) );

				return [ 'ok' => true, 'say' => 'A colleague has been notified and will pick this up.' ];
			},
			'public'
		);
	}

	public function enqueueWidget(): void {
		if ( ! get_option( 'vmsos_widget_enabled', true ) || is_admin() ) {
			return;
		}

		$chips = get_option( 'vmsos_widget_starter_chips' );
		if ( empty( $chips ) ) {
			$chips = get_option( 'vmsos_interests', [
				'Explore Services & Pricing',
				'Book a Discovery Consultation',
				'Speak with our Specialist Team',
			] );
		}
		if ( is_string( $chips ) ) {
			$chips = array_filter( array_map( 'trim', explode( "\n", $chips ) ) );
		}

		$greeting = (string) ( get_option( 'vmsos_greeting' ) ?: get_option( 'vmsos_widget_greeting', __( 'Hi — what are you looking for today?', 'vm-sales-os' ) ) );

		$teaserText = (string) get_option( 'vmsos_widget_teaser_text', '' );
		if ( '' === $teaserText ) {
			$teaserText = $greeting;
		}

		$wa = (string) get_option( 'vmsos_widget_whatsapp', '' );
		if ( '' === $wa ) {
			$wa = (string) get_option( 'vmsos_whatsapp_phone_id', '' );
		}
		$wa = preg_replace( '/[^0-9]/', '', $wa ) ?? '';

		$phone = (string) get_option( 'vmsos_widget_phone', '' );

		wp_enqueue_style( 'vmsos-widget', VMSOS_URL . 'assets/css/widget.css', [], VMSOS_VERSION );
		wp_enqueue_script( 'vmsos-widget', VMSOS_URL . 'assets/js/widget.js', [], VMSOS_VERSION, true );

		wp_localize_script(
			'vmsos-widget',
			'vmsosWidget',
			[
				'endpoint'      => esc_url_raw( rest_url( 'vmsos/v1/chat/message' ) ),
				'poll'          => esc_url_raw( rest_url( 'vmsos/v1/chat/poll' ) ),
				'formSubmit'    => esc_url_raw( rest_url( 'vmsos/v1/forms/submit' ) ),
				'agent'         => (string) get_option( 'vmsos_agent_name', 'Ava' ),
				'agentTitle'    => (string) get_option( 'vmsos_agent_title', __( 'Online • Live Sales Specialist', 'vm-sales-os' ) ),
				'greeting'      => $greeting,
				'accent'        => (string) get_option( 'vmsos_widget_accent', '#c9a227' ),
				'position'      => (string) get_option( 'vmsos_widget_position', 'right' ),
				'teaserEnabled' => (bool) get_option( 'vmsos_widget_teaser_enabled', true ),
				'teaserText'    => $teaserText,
				'teaserDelay'   => max( 1, (int) get_option( 'vmsos_widget_teaser_delay', 3 ) ),
				'chips'         => array_values( (array) $chips ),
				'whatsapp'      => $wa,
				'phone'         => $phone,
				'siteName'      => get_bloginfo( 'name' ),
				'strings'       => [
					'placeholder' => __( 'Type your message or tap mic…', 'vm-sales-os' ),
					'send'        => __( 'Send', 'vm-sales-os' ),
					'open'        => __( 'Chat with us', 'vm-sales-os' ),
					'close'       => __( 'Close chat', 'vm-sales-os' ),
					'error'       => __( 'That did not go through. Try again.', 'vm-sales-os' ),
					'waiting'     => __( 'A colleague is picking this up — stay on the page and their reply will appear here.', 'vm-sales-os' ),
					'quoteTab'    => __( '📋 Fast Inquiry', 'vm-sales-os' ),
					'chatTab'     => __( '💬 Live Chat', 'vm-sales-os' ),
					'restart'     => __( 'New conversation', 'vm-sales-os' ),
					'whatsapp'    => __( 'Chat on WhatsApp', 'vm-sales-os' ),
					'call'        => __( 'Call our team', 'vm-sales-os' ),
					'micTitle'    => __( 'Voice input (Speech to Text)', 'vm-sales-os' ),
					'formSuccess' => __( '✓ Inquiry received! Our team will contact you shortly.', 'vm-sales-os' ),
				],
			]
		);
	}

	public function renderWidgetRoot(): void {
		if ( ! get_option( 'vmsos_widget_enabled', true ) ) {
			return;
		}
		echo '<div id="vmsos-widget-root"></div>';
	}

	/** @param array<string, mixed> $atts Shortcode attributes. */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts( [ 'height' => '520' ], (array) $atts, 'vmsos_chat' );
		return sprintf( '<div class="vmsos-inline-chat" data-height="%d"></div>', (int) $atts['height'] );
	}
}