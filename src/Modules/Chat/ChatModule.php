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

		$isHttps = is_ssl() || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) || ( isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'] );
		$scheme  = $isHttps ? 'https' : 'admin';
		$cssUrl  = set_url_scheme( VMSOS_URL . 'assets/css/widget.css', $scheme );
		$jsUrl   = set_url_scheme( VMSOS_URL . 'assets/js/widget.js', $scheme );
		$ver     = defined( 'VMSOS_DIR' ) && file_exists( VMSOS_DIR . 'assets/js/widget.js' ) ? (string) filemtime( VMSOS_DIR . 'assets/js/widget.js' ) : (string) time();

		wp_enqueue_style( 'vmsos-widget', $cssUrl, [], $ver );
		wp_enqueue_script( 'vmsos-widget', $jsUrl, [], $ver, true );

		$agentName  = (string) get_option( 'vmsos_agent_name', 'Ava' );
		$agentTitle = (string) get_option( 'vmsos_agent_title', __( 'Online • Live Specialist', 'vm-sales-os' ) );

		$greetingRaw = (string) get_option( 'vmsos_widget_greeting', '' );
		if ( '' === trim( $greetingRaw ) || str_contains( $greetingRaw, 'â' ) ) {
			$greeting = sprintf( __( 'Hello! I am %s, your AI Growth Strategist at VM Studio. How can we help scale your business today?', 'vm-sales-os' ), $agentName );
		} else {
			$greeting = $greetingRaw;
		}

		$chipsRaw = (string) get_option( 'vmsos_widget_starter_chips', '' );
		if ( '' !== trim( $chipsRaw ) ) {
			$configuredChips = array_values( array_filter( array_map( 'trim', explode( "\n", $chipsRaw ) ) ) );
		} else {
			$configuredChips = [];
		}

		$defaultChips = [
			__( '⚡ Get Instant Estimate', 'vm-sales-os' ),
			__( '📅 Book Discovery Call', 'vm-sales-os' ),
			__( 'Custom Web Development', 'vm-sales-os' ),
			__( 'AI Chatbots & Automation', 'vm-sales-os' ),
			__( 'Brand Identity & Design', 'vm-sales-os' ),
		];

		$chips = ! empty( $configuredChips ) ? array_merge( [ __( '⚡ Get Instant Estimate', 'vm-sales-os' ), __( '📅 Book Discovery Call', 'vm-sales-os' ) ], $configuredChips ) : $defaultChips;
		$chips = array_values( array_unique( $chips ) );

		$teaserEnabled = (bool) get_option( 'vmsos_widget_teaser_enabled', true );
		$teaserDelay   = max( 1, (int) get_option( 'vmsos_widget_teaser_delay', 3 ) );
		$teaserText    = (string) get_option( 'vmsos_widget_teaser_text', __( 'Looking to scale your business with custom web & AI automation? Let us chat!', 'vm-sales-os' ) );
		$whatsapp      = (string) get_option( 'vmsos_widget_whatsapp', '' );
		$phone         = (string) get_option( 'vmsos_widget_phone', '' );
		$accent        = (string) get_option( 'vmsos_widget_accent', '#c9a227' );
		$position      = (string) get_option( 'vmsos_widget_position', 'right' );
		$siteName      = get_bloginfo( 'name' );

		wp_localize_script(
			'vmsos-widget',
			'vmsosWidget',
			[
				'endpoint'       => esc_url_raw( rest_url( 'vmsos/v1/chat/message' ) ),
				'poll'           => esc_url_raw( rest_url( 'vmsos/v1/chat/poll' ) ),
				'agent'          => $agentName,
				'agentTitle'     => $agentTitle,
				'greeting'       => $greeting,
				'accent'         => $accent,
				'position'       => $position,
				'chips'          => $chips,
				'whatsapp'       => $whatsapp,
				'phone'          => $phone,
				'siteName'       => $siteName,
				'teaserEnabled'  => $teaserEnabled,
				'teaserDelay'    => $teaserDelay,
				'teaserText'     => $teaserText,
				'strings'        => [
					'placeholder' => __( 'Ask anything or choose a topic above…', 'vm-sales-os' ),
					'send'        => __( 'Send', 'vm-sales-os' ),
					'open'        => __( 'Chat with us', 'vm-sales-os' ),
					'teaserCta'   => __( 'Start Consultation', 'vm-sales-os' ),
					'close'       => __( 'Close chat', 'vm-sales-os' ),
					'error'       => __( 'Could not deliver message. Please retry or contact us directly.', 'vm-sales-os' ),
					'waiting'     => __( 'A specialist has been notified and is stepping in. Your reply will appear here shortly.', 'vm-sales-os' ),
					'chatTab'     => __( '💬 Live AI Chat', 'vm-sales-os' ),
					'quoteTab'    => __( '📋 Fast Inquiry', 'vm-sales-os' ),
					'restart'     => __( 'New conversation', 'vm-sales-os' ),
					'micTitle'    => __( 'Voice dictation', 'vm-sales-os' ),
					'whatsapp'    => __( 'Direct WhatsApp', 'vm-sales-os' ),
					'call'        => __( 'Call us', 'vm-sales-os' ),
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