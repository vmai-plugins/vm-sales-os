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
		add_action( 'template_redirect', [ $this, 'handleChatbotUrlRedirect' ] );

		add_filter( 'script_loader_tag', function ( $tag, $handle ) {
			if ( 'vmsos-widget' === $handle ) {
				return str_replace( ' src=', ' data-no-optimize="1" data-cfasync="false" src=', $tag );
			}
			return $tag;
		}, 10, 2 );

		$this->registerTools( $container->get( ToolRegistry::class ), $container );
		\VMStudio\SalesOS\AI\UniversalTools::register( $container->get( ToolRegistry::class ), $container );
	}

	public function handleChatbotUrlRedirect(): void {
		$uri = trim( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '/' );
		$parts = explode( '?', $uri );
		$path = $parts[0] ?? '';
		if ( 'chatbot' === $path || 'chat' === $path ) {
			wp_safe_redirect( home_url( '/?open_chat=1' ), 302 );
			exit;
		}
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
		$teaserText    = trim( (string) get_option( 'vmsos_widget_teaser_text', '' ) );
		if ( empty( $teaserText ) ) {
			$teaserText = __( 'Looking to scale your business with custom web & AI automation? Let us chat!', 'vm-sales-os' );
		}
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
				'formSubmit'     => esc_url_raw( rest_url( 'vmsos/v1/forms/submit' ) ),
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

		$agentName  = (string) get_option( 'vmsos_agent_name', 'Leo (Growth Strategist)' );
		$agentTitle = (string) get_option( 'vmsos_agent_title', __( 'Online • Live Sales Specialist', 'vm-sales-os' ) );
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

		$teaserText = trim( (string) get_option( 'vmsos_widget_teaser_text', '' ) );
		if ( empty( $teaserText ) ) {
			$teaserText = __( 'Looking to scale your business with custom web & AI automation? Let us chat!', 'vm-sales-os' );
		}
		$whatsapp   = (string) get_option( 'vmsos_widget_whatsapp', '' );
		$phone      = (string) get_option( 'vmsos_widget_phone', '' );
		$accent     = (string) get_option( 'vmsos_widget_accent', '#c9a227' );
		$position   = (string) get_option( 'vmsos_widget_position', 'right' );
		?>
		<div id="vmsos-widget-root" data-position="<?php echo esc_attr( $position ); ?>" style="--vmsos-accent: <?php echo esc_attr( $accent ); ?>;">
			<!-- Proactive Sales Outreach & Teaser Card -->
			<div id="vmsos-teaser" class="vmsos-teaser" style="display: none;" role="status">
				<div class="vmsos-teaser__head">
					<div class="vmsos-teaser__avatar-wrap">
						<div class="vmsos-teaser__avatar">
							<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a8 8 0 0 0-8 8c0 3.3 2 6.2 5 7.4V20a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2v-2.6c3-1.2 5-4.1 5-7.4a8 8 0 0 0-8-8z"></path><circle cx="9" cy="10" r="1.2" fill="currentColor"></circle><circle cx="15" cy="10" r="1.2" fill="currentColor"></circle></svg>
						</div>
						<span class="vmsos-teaser__dot"></span>
					</div>
					<div class="vmsos-teaser__meta">
						<div class="vmsos-teaser__name">
							<?php echo esc_html( $agentName ); ?>
							<span class="vmsos-teaser__name-badge" title="Verified AI Specialist">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="#10b981"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
							</span>
						</div>
						<div class="vmsos-teaser__sub"><span class="vmsos-teaser__sub-dot"></span><?php echo esc_html( $agentTitle ); ?></div>
					</div>
					<button type="button" class="vmsos-teaser__close" id="vmsos-teaser-close" aria-label="Dismiss">&times;</button>
				</div>
				<div class="vmsos-teaser__body" id="vmsos-teaser-text">
					<?php echo esc_html( $teaserText ); ?>
				</div>
				<div class="vmsos-teaser__chips">
					<?php foreach ( array_slice( $chips, 0, 3 ) as $chip ) : ?>
						<button type="button" class="vmsos-teaser__chip" data-chip="<?php echo esc_attr( $chip ); ?>">
							<?php echo esc_html( $chip ); ?>
						</button>
					<?php endforeach; ?>
				</div>
				<button type="button" class="vmsos-teaser__cta" id="vmsos-teaser-cta">
					<span class="vmsos-teaser__cta-label">💬 Chat with us</span>
					<span class="vmsos-teaser__cta-arrow">&rarr;</span>
				</button>
			</div>

			<!-- Floating Trigger FAB -->
			<button type="button" id="vmsos-launcher" class="vmsos-launcher" aria-label="<?php esc_attr_e( 'Chat with us', 'vm-sales-os' ); ?>">
				<span class="vmsos-launcher__pulse"></span>
				<span class="vmsos-launcher__icon-chat">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
				</span>
				<span class="vmsos-launcher__icon-close" style="display:none;">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
				</span>
				<span class="vmsos-launcher__label"><?php esc_html_e( 'Chat with us', 'vm-sales-os' ); ?></span>
			</button>

			<!-- Main Chat Window Panel -->
			<div id="vmsos-panel" class="vmsos-panel" role="dialog" aria-modal="true">
				<!-- Header -->
				<div class="vmsos-head">
					<div class="vmsos-head__left">
						<div class="vmsos-head__avatar-wrap">
							<div class="vmsos-head__avatar">
								<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a8 8 0 0 0-8 8c0 3.3 2 6.2 5 7.4V20a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2v-2.6c3-1.2 5-4.1 5-7.4a8 8 0 0 0-8-8z"></path><circle cx="9" cy="10" r="1.2" fill="currentColor"></circle><circle cx="15" cy="10" r="1.2" fill="currentColor"></circle></svg>
							</div>
							<span class="vmsos-head__dot"></span>
						</div>
						<div class="vmsos-head__meta">
							<div class="vmsos-head__name"><?php echo esc_html( $agentName ); ?></div>
							<div class="vmsos-head__sub"><?php echo esc_html( $agentTitle ); ?></div>
						</div>
					</div>
					<div class="vmsos-head__actions">
						<?php if ( ! empty( $whatsapp ) ) : ?>
							<a href="https://wa.me/<?php echo esc_attr( preg_replace( '/[^0-9]/', '', $whatsapp ) ); ?>" target="_blank" rel="noopener noreferrer" class="vmsos-head__btn is-wa" title="WhatsApp">
								<svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2zm.01 18.06c-1.48 0-2.93-.4-4.2-1.15l-.3-.18-3.12.82.83-3.04-.2-.31c-.82-1.31-1.26-2.83-1.26-4.38 0-4.54 3.7-8.24 8.24-8.24 4.54 0 8.24 3.7 8.24 8.24 0 4.54-3.7 8.24-8.24 8.24zm4.52-6.17c-.25-.12-1.47-.72-1.7-.81-.23-.08-.39-.12-.56.12-.17.25-.64.81-.79.97-.14.17-.29.19-.54.06-.25-.12-1.05-.39-2-1.23-.74-.66-1.24-1.47-1.39-1.72-.14-.25-.02-.38.11-.5.11-.11.25-.29.37-.43.12-.14.17-.25.25-.41.08-.17.04-.31-.02-.43s-.56-1.34-.76-1.84c-.2-.48-.41-.42-.56-.43h-.48c-.17 0-.43.06-.66.31-.23.25-.87.85-.87 2.07s.89 2.4 1.01 2.57c.12.17 1.75 2.67 4.24 3.75.59.26 1.05.41 1.41.53.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.68-1.18.21-.58.21-1.07.15-1.18-.06-.12-.22-.19-.47-.31z"/></svg>
							</a>
						<?php endif; ?>
						<?php if ( ! empty( $phone ) ) : ?>
							<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>" class="vmsos-head__btn is-phone" title="Call">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
							</a>
						<?php endif; ?>
						<button type="button" class="vmsos-head__btn is-sound" id="vmsos-sound-toggle" title="Toggle audio">
							<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path></svg>
						</button>
						<button type="button" class="vmsos-head__btn" id="vmsos-restart-btn" title="New conversation">
							<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"></path></svg>
						</button>
						<button type="button" class="vmsos-head__btn is-close" id="vmsos-panel-close" title="Close chat">
							<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
						</button>
					</div>
				</div>

				<!-- 3-Tab Navigation Bar -->
				<nav class="vmsos-tabs-nav" role="tablist">
					<button type="button" class="vmsos-tab-btn is-active" id="vmsos-tab-btn-chat" data-tab="chat">💬 Live AI Chat</button>
					<button type="button" class="vmsos-tab-btn" id="vmsos-tab-btn-form" data-tab="form">📋 Fast Inquiry</button>
					<button type="button" class="vmsos-tab-btn" id="vmsos-tab-btn-voice" data-tab="voice">🎙️ WebCall & Voice</button>
				</nav>

				<!-- Tab 1: Live AI Chat -->
				<div id="vmsos-view-chat" class="vmsos-tab-view is-chat-tab">
					<div id="vmsos-log" class="vmsos-log" aria-live="polite">
						<!-- Welcome Card -->
						<div class="vmsos-msg is-agent">
							<div class="vmsos-msg__bubble">
								<p><?php echo esc_html( $greeting ); ?></p>
								<?php if ( ! empty( $whatsapp ) ) : ?>
									<a href="https://wa.me/<?php echo esc_attr( preg_replace( '/[^0-9]/', '', $whatsapp ) ); ?>" target="_blank" rel="noopener noreferrer" class="vmsos-wa-card-btn">
										<span class="vmsos-wa-card-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2zm.01 18.06c-1.48 0-2.93-.4-4.2-1.15l-.3-.18-3.12.82.83-3.04-.2-.31c-.82-1.31-1.26-2.83-1.26-4.38 0-4.54 3.7-8.24 8.24-8.24 4.54 0 8.24 3.7 8.24 8.24 0 4.54-3.7 8.24-8.24 8.24z"/></svg></span>
										<span class="vmsos-wa-card-text">Chat directly on WhatsApp &rarr;</span>
									</a>
								<?php endif; ?>
							</div>
						</div>
						<!-- Starter Chips -->
						<div class="vmsos-chips-wrap">
							<?php foreach ( $chips as $chip ) : ?>
								<button type="button" class="vmsos-chip-btn" data-query="<?php echo esc_attr( $chip ); ?>">
									<?php echo esc_html( $chip ); ?>
								</button>
							<?php endforeach; ?>
						</div>
					</div>
					<!-- Chat Form (Only 1 text input, NO honeypot!) -->
					<form id="vmsos-chat-form" class="vmsos-chat-form" autocomplete="off">
						<input type="text" id="vmsos-chat-input" class="vmsos-input" placeholder="Ask anything or choose a topic above…" autocomplete="off" maxlength="1000" />
						<button type="button" id="vmsos-chat-mic" class="vmsos-mic" title="Voice dictation" aria-label="Voice input">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>
						</button>
						<button type="submit" id="vmsos-chat-send" class="vmsos-send" title="Send" aria-label="Send message">
							<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"></path></svg>
						</button>
					</form>
				</div>

				<!-- Tab 2: Fast Inquiry -->
				<div id="vmsos-view-form" class="vmsos-tab-view is-form-tab" style="display: none;">
					<form id="vmsos-inquiry-form" class="vmsos-inquiry-form" autocomplete="off">
						<div class="vmsos-q-heading">
							<h4>Fast Project Inquiry</h4>
							<p>Leave your details and requirements. Our growth team will get back to you within 15 minutes.</p>
						</div>
						<div class="vmsos-q-field">
							<label>Your Name *</label>
							<input type="text" name="name" required placeholder="e.g. Alex Smith" autocomplete="name" />
						</div>
						<div class="vmsos-q-field">
							<label>Email Address *</label>
							<input type="email" name="email" required placeholder="alex@company.com" autocomplete="email" />
						</div>
						<div class="vmsos-q-field">
							<label>Phone / WhatsApp</label>
							<input type="tel" name="phone" placeholder="+1 (555) 000-0000" autocomplete="tel" />
						</div>
						<div class="vmsos-q-field">
							<label>Project / Requirements</label>
							<textarea name="requirements" rows="3" placeholder="Tell us what you are looking for (e.g. Custom Web App, AI Automation, CRM)..."></textarea>
						</div>
						<!-- Honeypot strictly hidden only inside inquiry form -->
						<input type="text" name="company_website" class="vmsos-hp" tabindex="-1" style="display:none!important;" autocomplete="off" />
						<button type="submit" class="vmsos-q-submit">⚡ Submit Inquiry</button>
						<div class="vmsos-q-status" style="display:none;"></div>
					</form>
				</div>

				<!-- Tab 3: WebCall & Voice -->
				<div id="vmsos-view-voice" class="vmsos-tab-view is-voice-tab" style="display: none;">
					<div class="vmsos-voice-container">
						<div class="vmsos-voice-avatar-wrap">
							<div class="vmsos-voice-ring" id="vmsos-voice-ring">
								<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#c9a227" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a8 8 0 0 0-8 8c0 3.3 2 6.2 5 7.4V20a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2v-2.6c3-1.2 5-4.1 5-7.4a8 8 0 0 0-8-8z"></path><circle cx="9" cy="10" r="1.2" fill="#c9a227"></circle><circle cx="15" cy="10" r="1.2" fill="#c9a227"></circle></svg>
							</div>
						</div>
						<h4 id="vmsos-voice-title"><?php echo esc_html( $agentName ); ?></h4>
						<p id="vmsos-voice-status">Tap microphone to start live AI voice conversation</p>
						<canvas id="vmsos-voice-visualizer" width="280" height="60"></canvas>
						<div id="vmsos-voice-transcript" class="vmsos-voice-transcript">"Hello! Ask me about custom web development, AI automation, or book a discovery call..."</div>

						<div class="vmsos-voice-controls">
							<button type="button" id="vmsos-voice-toggle-btn" class="vmsos-voice-call-btn">
								<span class="vmsos-voice-btn-icon">🎙️</span>
								<span class="vmsos-voice-btn-label">Start Voice Call</span>
							</button>
						</div>

						<!-- Direct Mobile Call Me Box -->
						<div class="vmsos-voice-phone-box">
							<div class="vmsos-voice-phone-title">Prefer an immediate call on your mobile?</div>
							<div class="vmsos-voice-phone-input-row">
								<input type="tel" id="vmsos-direct-phone-input" placeholder="+1 (555) 000-0000" autocomplete="tel" />
								<button type="button" id="vmsos-btn-trigger-mobile-call" class="vmsos-btn-trigger-call">📞 Call Me</button>
							</div>
							<div id="vmsos-phone-call-status" class="vmsos-phone-call-status" style="display: none;"></div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/** @param array<string, mixed> $atts Shortcode attributes. */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts( [ 'height' => '520' ], (array) $atts, 'vmsos_chat' );
		return sprintf( '<div class="vmsos-inline-chat" data-height="%d"></div>', (int) $atts['height'] );
	}
}