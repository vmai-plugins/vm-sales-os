<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Admin;

use VMStudio\SalesOS\AI\ProviderManager;
use VMStudio\SalesOS\AI\PromptRegistry;
use VMStudio\SalesOS\Modules\Agents\IndustryPresets;
use VMStudio\SalesOS\Support\Vault;

/**
 * Admin shell. One page, one script; every screen is a tab in the app so the
 * whole UI shares one data layer instead of reloading WordPress each time.
 */
final class AdminArea {

	public const SLUG = 'vm-sales-os';

	public function __construct(
		private ProviderManager $providers,
		private PromptRegistry $prompts,
		private Vault $vault
	) {}

	public function boot(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_init', [ $this, 'ensureCapabilities' ] );
		add_action( 'admin_post_vmsos_save_settings', [ $this, 'saveSettings' ] );
		add_action( 'wp_ajax_vmsos_sync_models', [ $this, 'ajaxSyncModels' ] );
		add_action( 'wp_ajax_vmsos_test_ai', [ $this, 'ajaxTestAi' ] );
		add_action( 'wp_ajax_vmsos_run_daily_leadgen', [ $this, 'ajaxRunDailyLeadgen' ] );
		add_filter( 'plugin_action_links_' . VMSOS_BASENAME, [ $this, 'actionLinks' ] );
	}

	public function ensureCapabilities(): void {
		if ( get_option( 'vmsos_caps_version' ) === VMSOS_VERSION ) {
			return;
		}

		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( 'vmsos_manage' );
			$role->add_cap( 'vmsos_operate' );
		}

		$editor = get_role( 'editor' );
		if ( $editor ) {
			$editor->add_cap( 'vmsos_operate' );
		}

		update_option( 'vmsos_caps_version', VMSOS_VERSION, false );
	}

	public function menu(): void {
		add_menu_page(
			__( 'VM Sales OS', 'vm-sales-os' ),
			__( 'Sales OS', 'vm-sales-os' ),
			'vmsos_operate',
			self::SLUG,
			[ $this, 'render' ],
			'dashicons-superhero-alt',
			26
		);

		add_submenu_page( self::SLUG, __( 'Dashboard', 'vm-sales-os' ), __( 'Dashboard', 'vm-sales-os' ), 'vmsos_operate', self::SLUG, [ $this, 'render' ] );
		add_submenu_page( self::SLUG, __( 'Settings', 'vm-sales-os' ), __( 'Settings', 'vm-sales-os' ), 'vmsos_manage', self::SLUG . '-settings', [ $this, 'renderSettings' ] );
	}

	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'vmsos-admin', VMSOS_URL . 'assets/css/admin.css', [], VMSOS_VERSION );
		wp_enqueue_script( 'vmsos-admin', VMSOS_URL . 'assets/js/admin.js', [ 'wp-element' ], VMSOS_VERSION, true );

		wp_localize_script(
			'vmsos-admin',
			'vmsosAdmin',
			[
				'root'      => esc_url_raw( rest_url( 'vmsos/v1/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'version'   => VMSOS_VERSION,
				'canManage' => current_user_can( 'vmsos_manage' ),
			]
		);
	}

	public function render(): void {
		echo '<div class="wrap vmsos-wrap"><div id="vmsos-admin-root"></div></div>';
	}

	public function renderSettings(): void {
		$chain           = implode( ', ', $this->providers->chain() );
		$activeBlueprint = (string) get_option( 'vmsos_active_blueprint', 'creative_agency' );
		$blueprints      = IndustryPresets::all();
		$cronNext        = wp_next_scheduled( \VMStudio\SalesOS\Modules\LeadGen\LeadGenModule::DAILY_TICK );
		$cronNextHuman   = $cronNext ? human_time_diff( time(), $cronNext ) : 'Not scheduled';
		$placesKeyHint   = $this->vault->hint( 'google_places_api_key' );
		$primaryProvider = $this->providers->chain()[0] ?? 'aipuffer';
		?>
		<div class="wrap vmsos-wrap vmsos-settings-modern">
			<!-- Header Hero Banner -->
			<div class="vmsos-settings-hero">
				<div class="vmsos-hero-title">
					<h1>
						<span class="dashicons dashicons-admin-generic" style="font-size:26px; width:26px; height:26px; color:#c9a227;"></span>
						VM Sales OS
						<span>Intelligence Engine &amp; Gateway</span>
					</h1>
					<p><?php esc_html_e( 'Autonomous AI failover orchestration, multi-channel customer pipelines, automated lead finder, and mobile app sync.', 'vm-sales-os' ); ?></p>
				</div>
				<div class="vmsos-hero-status-pills">
					<span class="vmsos-pill vmsos-pill--cyan">
						<span class="vmsos-pill-dot"></span>
						<?php echo esc_html( sprintf( __( 'Primary Brain: %s', 'vm-sales-os' ), strtoupper( $primaryProvider ) ) ); ?>
					</span>
					<span class="vmsos-pill vmsos-pill--gold">
						<span class="vmsos-pill-dot"></span>
						<?php esc_html_e( 'Cloud Router: ai.vmstudio.digital', 'vm-sales-os' ); ?>
					</span>
					<span class="vmsos-pill vmsos-pill--emerald">
						<span class="vmsos-pill-dot"></span>
						<?php echo esc_html( sprintf( __( 'B2B Daily Cron: Active (in %s)', 'vm-sales-os' ), $cronNextHuman ) ); ?>
					</span>
				</div>
			</div>

			<?php if ( isset( $_GET['vmsos_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible" style="border-left-color: #10b981; background: #061912; color: #34d399; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px;">
					<p style="font-weight: 600; margin: 0; font-size: 14px;"><?php esc_html_e( '✓ VM Sales OS settings updated successfully.', 'vm-sales-os' ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Sub Navigation Tabs -->
			<div class="vmsos-tabs-nav" id="vmsos-settings-tabs">
				<button type="button" class="vmsos-tab-btn is-active" data-tab="tab-ai-brain">
					<span class="dashicons dashicons-rest-api"></span>
					<?php esc_html_e( 'AI Brain & Failover Pipeline', 'vm-sales-os' ); ?>
				</button>
				<button type="button" class="vmsos-tab-btn" data-tab="tab-lead-gen">
					<span class="dashicons dashicons-location"></span>
					<?php esc_html_e( 'B2B Maps Lead Finder', 'vm-sales-os' ); ?>
				</button>
				<button type="button" class="vmsos-tab-btn" data-tab="tab-blueprint">
					<span class="dashicons dashicons-art"></span>
					<?php esc_html_e( 'Blueprint & Sales Persona', 'vm-sales-os' ); ?>
				</button>
				<button type="button" class="vmsos-tab-btn" data-tab="tab-omnichannel">
					<span class="dashicons dashicons-format-chat"></span>
					<?php esc_html_e( 'WhatsApp & Voice Telephony', 'vm-sales-os' ); ?>
				</button>
				<button type="button" class="vmsos-tab-btn" data-tab="tab-mobile-sync">
					<span class="dashicons dashicons-smartphone"></span>
					<?php esc_html_e( 'Mobile Bridge & GitHub Updates', 'vm-sales-os' ); ?>
				</button>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="vmsos-settings-form">
				<input type="hidden" name="action" value="vmsos_save_settings" />
				<?php wp_nonce_field( 'vmsos_settings' ); ?>

				<!-- TAB 1: AI Brain & Failover Pipeline -->
				<div class="vmsos-tab-content is-active" id="tab-ai-brain">
					<!-- Visual Pipeline Card -->
					<div class="vmsos-pipeline-visual">
						<div style="display: flex; justify-content: space-between; align-items: center;">
							<div>
								<h3 style="margin: 0 0 4px; color: #fff; font-size: 16px; font-weight: 700;"><?php esc_html_e( 'Autonomous Multi-Provider Failover Circuit', 'vm-sales-os' ); ?></h3>
								<p style="margin: 0; color: var(--vmsos-muted); font-size: 12px;"><?php esc_html_e( 'Zero single-point-of-failure routing. Requests automatically waterfall through your chosen provider chain.', 'vm-sales-os' ); ?></p>
							</div>
							<span class="vmsos-pill vmsos-pill--emerald">
								<span class="vmsos-pill-dot"></span>
								<?php esc_html_e( 'Resilience Active', 'vm-sales-os' ); ?>
							</span>
						</div>

						<div class="vmsos-pipeline-stages">
							<div class="vmsos-stage-box is-primary">
								<span class="vmsos-stage-tag tag-primary"><?php esc_html_e( 'Primary Brain (Stage 1)', 'vm-sales-os' ); ?></span>
								<div class="vmsos-stage-title"><?php esc_html_e( 'AI Puffer Bots', 'vm-sales-os' ); ?></div>
								<div class="vmsos-stage-sub"><?php esc_html_e( 'Sales Agent (Bot 1169) • In-Database', 'vm-sales-os' ); ?></div>
							</div>
							<span class="vmsos-stage-arrow">➔</span>
							<div class="vmsos-stage-box is-fallback">
								<span class="vmsos-stage-tag tag-fallback"><?php esc_html_e( 'Cloud Router (Stage 2)', 'vm-sales-os' ); ?></span>
								<div class="vmsos-stage-title">ai.vmstudio.digital</div>
								<div class="vmsos-stage-sub"><?php esc_html_e( 'Auto Free/Best Router • High Speed', 'vm-sales-os' ); ?></div>
							</div>
							<span class="vmsos-stage-arrow">➔</span>
							<div class="vmsos-stage-box">
								<span class="vmsos-stage-tag tag-sovereign"><?php esc_html_e( 'Sovereign LLM (Stage 3)', 'vm-sales-os' ); ?></span>
								<div class="vmsos-stage-title"><?php esc_html_e( 'Google Gemini 2.5', 'vm-sales-os' ); ?></div>
								<div class="vmsos-stage-sub"><?php esc_html_e( 'gemini-2.5-flash • Multilingual', 'vm-sales-os' ); ?></div>
							</div>
							<span class="vmsos-stage-arrow">➔</span>
							<div class="vmsos-stage-box">
								<span class="vmsos-stage-tag tag-anthropic"><?php esc_html_e( 'Deep Reasoning (Stage 4)', 'vm-sales-os' ); ?></span>
								<div class="vmsos-stage-title"><?php esc_html_e( 'Anthropic Claude', 'vm-sales-os' ); ?></div>
								<div class="vmsos-stage-sub"><?php esc_html_e( 'claude-3-5-sonnet • Analytical', 'vm-sales-os' ); ?></div>
							</div>
						</div>

						<!-- Quick Presets -->
						<div class="vmsos-presets-row">
							<span style="font-size: 11px; font-weight: 600; color: var(--vmsos-muted);"><?php esc_html_e( 'Failover Presets:', 'vm-sales-os' ); ?></span>
							<button type="button" class="vmsos-preset-btn" data-chain="aipuffer, openai, gemini, anthropic"><?php esc_html_e( '⚡ AI Puffer Bots Primary (Recommended)', 'vm-sales-os' ); ?></button>
							<button type="button" class="vmsos-preset-btn" data-chain="openai, aipuffer, gemini, anthropic"><?php esc_html_e( '🌐 Cloud Router First (ai.vmstudio.digital)', 'vm-sales-os' ); ?></button>
							<button type="button" class="vmsos-preset-btn" data-chain="gemini, aipuffer, openai, anthropic"><?php esc_html_e( '💎 Direct Google Gemini Sovereign', 'vm-sales-os' ); ?></button>
						</div>

						<div style="margin-top: 14px;">
							<label for="vmsos_ai_chain" style="font-size: 11px; font-weight: 600; color: var(--vmsos-muted); text-transform: uppercase; letter-spacing: 0.05em;"><?php esc_html_e( 'Active Failover Order (Comma-separated)', 'vm-sales-os' ); ?></label>
							<input name="vmsos_ai_chain" id="vmsos_ai_chain" class="vmsos-input-modern" style="margin-top: 4px; max-width: 500px;" value="<?php echo esc_attr( $chain ); ?>" />
						</div>
					</div>

					<!-- Interactive AI Test & Ping Tool -->
					<div class="vmsos-ai-test-console">
						<div class="vmsos-ai-test-header">
							<h4>
								<span class="dashicons dashicons-admin-site-alt3" style="font-size: 18px; width: 18px; height: 18px;"></span>
								<?php esc_html_e( 'Real-Time AI Pipeline Health & Ping Console', 'vm-sales-os' ); ?>
							</h4>
							<span style="font-size: 11px; color: #64748b;"><?php esc_html_e( 'Tests end-to-end reasoning and reports the responding engine & latency', 'vm-sales-os' ); ?></span>
						</div>
						<div class="vmsos-ai-test-input-row">
							<input type="text" id="vmsos-ai-test-prompt" class="vmsos-ai-test-input" value="Say 'VM Sales OS Autonomous Brain is online and ready!' in under 12 words." placeholder="Enter custom sales discovery or reasoning test prompt..." />
							<button type="button" id="vmsos-btn-test-ai" class="vmsos-ai-test-btn">
								<span class="dashicons dashicons-controls-play" style="font-size: 14px; width: 14px; height: 14px;"></span>
								<?php esc_html_e( 'Ping Pipeline', 'vm-sales-os' ); ?>
							</button>
						</div>
						<div id="vmsos-ai-test-result" class="vmsos-ai-test-result"></div>
					</div>

					<!-- Provider Cards Grid -->
					<div class="vmsos-providers-grid">
						<?php foreach ( $this->providers->all() as $id => $provider ) :
							$cachedModels = (array) get_option( 'vmsos_ai_' . $id . '_models_cache', [] );
							$currentModel = (string) get_option( 'vmsos_ai_' . $id . '_model', '' );
							$isAiPuffer   = ( 'aipuffer' === $id );
							$isOpenAi     = ( 'openai' === $id );
							$isGemini     = ( 'gemini' === $id );
							$isAnthropic  = ( 'anthropic' === $id );
						?>
							<div class="vmsos-provider-card vmsos-provider-row" data-provider="<?php echo esc_attr( $id ); ?>">
								<div class="vmsos-provider-card-header">
									<div class="vmsos-provider-info">
										<span class="dashicons <?php echo $isAiPuffer ? 'dashicons-superhero-alt' : ( $isOpenAi ? 'dashicons-cloud' : ( $isGemini ? 'dashicons-star-filled' : 'dashicons-shield' ) ); ?>" style="color: <?php echo $isAiPuffer ? '#06b6d4' : ( $isOpenAi ? '#c9a227' : ( $isGemini ? '#a855f7' : '#f43f5e' ) ); ?>; font-size: 20px; width: 20px; height: 20px;"></span>
										<div>
											<h3><?php echo esc_html( $provider->label() ); ?></h3>
											<span style="font-size: 11px; color: var(--vmsos-muted);">
												<?php
												if ( $isAiPuffer ) {
													esc_html_e( 'Native AI Puffer Custom Bots', 'vm-sales-os' );
												} elseif ( $isOpenAi ) {
													esc_html_e( 'Cloud Router / OpenAI Compatible', 'vm-sales-os' );
												} elseif ( $isGemini ) {
													esc_html_e( 'Google Gemini Direct Flash/Pro', 'vm-sales-os' );
												} else {
													esc_html_e( 'Anthropic Claude Models', 'vm-sales-os' );
												}
												?>
											</span>
										</div>
									</div>
									<div class="vmsos-model-cached-badge" id="vmsos_badge_<?php echo esc_attr( $id ); ?>" style="font-size: 11px; color: #10b981; font-weight: 600; <?php echo empty( $cachedModels ) ? 'display:none;' : ''; ?>">
										✓ <span class="vmsos-count-val"><?php echo count( $cachedModels ); ?></span> <?php esc_html_e( 'models ready', 'vm-sales-os' ); ?>
									</div>
								</div>

								<?php if ( $isAiPuffer ) : ?>
									<div style="background: rgba(6, 182, 212, 0.08); border: 1px solid rgba(6, 182, 212, 0.2); border-radius: 8px; padding: 10px 12px; display: flex; justify-content: space-between; align-items: center;">
										<span style="font-size: 12px; color: #22d3ee;">
											<?php esc_html_e( 'Integrated with WP AI Puffer Bots Builder.', 'vm-sales-os' ); ?>
										</span>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpaicg' ) ); ?>" target="_blank" class="button button-small" style="background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.2); color: #fff;">
											<?php esc_html_e( 'Open Bot Builder ↗', 'vm-sales-os' ); ?>
										</a>
									</div>
								<?php endif; ?>

								<div class="vmsos-form-group">
									<label><?php esc_html_e( 'API Key / Secret Vault', 'vm-sales-os' ); ?></label>
									<input
										type="password"
										name="vmsos_key_<?php echo esc_attr( $id ); ?>"
										id="vmsos_key_<?php echo esc_attr( $id ); ?>"
										class="vmsos-input-modern vmsos-field-key"
										autocomplete="new-password"
										placeholder="<?php echo esc_attr( $this->vault->hint( 'ai.' . $id . '.key' ) ?: ( $isAiPuffer ? __( 'Inherited from AI Puffer (internal)', 'vm-sales-os' ) : __( 'Enter API key...', 'vm-sales-os' ) ) ); ?>"
									/>
								</div>

								<div class="vmsos-form-group">
									<label><?php esc_html_e( 'Base URL / Endpoint Gateway', 'vm-sales-os' ); ?></label>
									<input
										type="url"
										name="vmsos_base_<?php echo esc_attr( $id ); ?>"
										id="vmsos_base_<?php echo esc_attr( $id ); ?>"
										class="vmsos-input-modern vmsos-field-base"
										value="<?php echo esc_attr( (string) get_option( 'vmsos_ai_' . $id . '_base_url', $isOpenAi ? 'https://ai.vmstudio.digital/v1' : '' ) ); ?>"
										placeholder="<?php echo esc_attr( $isOpenAi ? 'https://ai.vmstudio.digital/v1' : __( 'Default provider URL', 'vm-sales-os' ) ); ?>"
									/>
								</div>

								<div class="vmsos-form-group">
									<label><?php echo esc_html( $isAiPuffer ? __( 'Active Bot ID', 'vm-sales-os' ) : __( 'Model ID', 'vm-sales-os' ) ); ?></label>
									<div class="vmsos-form-row">
										<input
											type="text"
											name="vmsos_model_<?php echo esc_attr( $id ); ?>"
											id="vmsos_model_<?php echo esc_attr( $id ); ?>"
											class="vmsos-input-modern vmsos-field-model"
											list="vmsos_list_<?php echo esc_attr( $id ); ?>"
											value="<?php echo esc_attr( $currentModel ); ?>"
											placeholder="<?php echo esc_attr( $isAiPuffer ? '1169' : ( $isOpenAi ? 'auto/best-free' : ( $isGemini ? 'gemini-2.5-flash' : 'claude-3-5-sonnet-20241022' ) ) ); ?>"
											style="flex: 1;"
										/>
										<datalist id="vmsos_list_<?php echo esc_attr( $id ); ?>">
											<?php foreach ( $cachedModels as $m ) : ?>
												<option value="<?php echo esc_attr( $m ); ?>">
											<?php endforeach; ?>
										</datalist>

										<select class="vmsos-select-modern vmsos-select-model" id="vmsos_select_<?php echo esc_attr( $id ); ?>" data-target="vmsos_model_<?php echo esc_attr( $id ); ?>" style="<?php echo empty( $cachedModels ) ? 'display:none;' : ''; ?>">
											<option value="">-- Choose --</option>
											<?php foreach ( $cachedModels as $m ) :
												$mLabel = $m;
												if ( $isAiPuffer && is_numeric( $m ) ) {
													$botPost = get_post( (int) $m );
													if ( $botPost ) {
														$mLabel = $m . ' (' . $botPost->post_title . ')';
													}
												}
											?>
												<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $currentModel, $m ); ?>>
													<?php echo esc_html( $mLabel ); ?>
												</option>
											<?php endforeach; ?>
										</select>

										<button
											type="button"
											class="button vmsos-btn-sync-model"
											data-provider="<?php echo esc_attr( $id ); ?>"
											title="<?php esc_attr_e( 'Sync live available models/bots from this endpoint', 'vm-sales-os' ); ?>"
											style="display: inline-flex; align-items: center; gap: 4px; padding: 0 12px; height: 35px;"
										>
											<span class="dashicons dashicons-update" style="font-size: 15px; width: 15px; height: 15px; line-height: 15px;"></span>
											<?php echo esc_html( $isAiPuffer ? __( 'Sync Bots', 'vm-sales-os' ) : __( 'Sync Models', 'vm-sales-os' ) ); ?>
										</button>
									</div>
									<span class="vmsos-sync-feedback" id="vmsos_feedback_<?php echo esc_attr( $id ); ?>" style="font-size: 12px; margin-top: 4px;"></span>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- TAB 2: B2B Maps Lead Finder -->
				<div class="vmsos-tab-content" id="tab-lead-gen">
					<div class="vmsos-card">
						<div class="vmsos-card-header">
							<div>
								<h2><?php esc_html_e( 'Google Maps Lead Finder & Automated Prospecting', 'vm-sales-os' ); ?></h2>
								<p><?php esc_html_e( 'Continuous automated prospecting discovering local B2B businesses via Google Places API (New), crawling business websites for decision-maker emails, and syncing to sales sequences.', 'vm-sales-os' ); ?></p>
							</div>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=vm-sales-os-leadgen' ) ); ?>" class="button button-secondary">
								<?php esc_html_e( 'Open Lead Finder Dashboard ↗', 'vm-sales-os' ); ?>
							</a>
						</div>

						<!-- Recurring Cron Status Box -->
						<div class="vmsos-leadgen-cron-banner">
							<div>
								<div style="font-size: 13px; font-weight: 700; color: #10b981; display: flex; align-items: center; gap: 8px;">
									<span class="vmsos-pill-dot" style="background:#10b981; box-shadow:0 0 8px #10b981;"></span>
									<?php esc_html_e( 'Automated Daily Google Maps Import: ACTIVE', 'vm-sales-os' ); ?>
								</div>
								<div style="font-size: 12px; color: var(--vmsos-muted); margin-top: 4px;">
									<?php echo esc_html( sprintf( __( 'Scheduled Hook: vmsos_leadgen_daily_tick • Next automatic run in %s • Cycles through rotating cities.', 'vm-sales-os' ), $cronNextHuman ) ); ?>
								</div>
							</div>
							<button type="button" id="vmsos-btn-run-leadgen-batch" class="button button-primary" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border: none; font-weight: 600;">
								<span class="dashicons dashicons-controls-forward" style="font-size: 15px; width: 15px; height: 15px; line-height: 15px;"></span>
								<?php esc_html_e( 'Run Daily Import Batch Now', 'vm-sales-os' ); ?>
							</button>
						</div>
						<div id="vmsos-leadgen-batch-status" style="margin-bottom: 16px; font-size: 12px; font-family: monospace; display: none; padding: 10px; background: rgba(0,0,0,0.3); border-radius: 6px;"></div>

						<div class="vmsos-form-group" style="margin-bottom: 16px;">
							<label for="vmsos_google_places_api_key"><?php esc_html_e( 'Google Places API Key (New)', 'vm-sales-os' ); ?></label>
							<input
								type="password"
								name="vmsos_google_places_api_key"
								id="vmsos_google_places_api_key"
								class="vmsos-input-modern"
								value=""
								autocomplete="new-password"
								placeholder="<?php echo esc_attr( $placesKeyHint ?: 'AIzaSy...' ); ?>"
								style="max-width: 500px;"
							/>
							<span style="font-size: 11px; color: var(--vmsos-muted); margin-top: 4px;">
								<?php esc_html_e( 'Requires "Places API (New)" enabled in Google Cloud Console with billing enabled.', 'vm-sales-os' ); ?>
							</span>
						</div>

						<div class="vmsos-form-group" style="margin-bottom: 16px;">
							<label><?php esc_html_e( 'Rotating Cities for Daily Prospecting', 'vm-sales-os' ); ?></label>
							<input
								type="text"
								name="vmsos_b2b_import_cities"
								id="vmsos_b2b_import_cities"
								class="vmsos-input-modern"
								value="<?php echo esc_attr( (string) get_option( 'vmsos_b2b_import_cities', get_option( 'tc_agents_b2b_import_cities', 'Dubai, Abu Dhabi, London, New York, Singapore, Mumbai, Riyadh' ) ) ); ?>"
								placeholder="Dubai, London, New York, Singapore..."
							/>
							<span style="font-size: 11px; color: var(--vmsos-muted); margin-top: 4px;">
								<?php esc_html_e( 'Each daily run advances to the next city in this list, discovering 20 fresh local B2B prospects per day.', 'vm-sales-os' ); ?>
							</span>
						</div>

						<div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--vmsos-line);">
							<label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: var(--vmsos-text); font-size: 13px;">
								<input type="checkbox" name="vmsos_leadgen_shadow_email_sync" value="1" <?php checked( (bool) get_option( 'vmsos_leadgen_shadow_email_sync', false ) ); ?> />
								<?php esc_html_e( 'Sync phone-only leads into FluentCRM using local shadow email (lead+phone@site.com)', 'vm-sales-os' ); ?>
							</label>
							<p style="margin: 4px 0 0 24px; font-size: 11px; color: var(--vmsos-muted);">
								<?php esc_html_e( 'If unchecked, phone-only businesses are saved in Sales OS CRM for WhatsApp and outbound telephony, and only verified email leads enter FluentCRM.', 'vm-sales-os' ); ?>
							</p>
						</div>
					</div>
				</div>

				<!-- TAB 3: Blueprint & Sales Persona -->
				<div class="vmsos-tab-content" id="tab-blueprint">
					<div class="vmsos-card">
						<div class="vmsos-card-header">
							<div>
								<h2><?php esc_html_e( 'Universal Industry Blueprint & Sales Persona', 'vm-sales-os' ); ?></h2>
								<p><?php esc_html_e( 'Pre-configures AI sales discovery behavior, qualifying criteria, knowledge search, and catalog quoting for your exact domain.', 'vm-sales-os' ); ?></p>
							</div>
						</div>

						<div class="vmsos-form-group" style="margin-bottom: 20px;">
							<label for="vmsos_apply_blueprint"><?php esc_html_e( 'Active Archetype Blueprint', 'vm-sales-os' ); ?></label>
							<select name="vmsos_apply_blueprint" id="vmsos_apply_blueprint" class="vmsos-select-modern" style="max-width: 600px;">
								<option value="none"><?php esc_html_e( '-- Keep Current Prompts & Settings --', 'vm-sales-os' ); ?></option>
								<?php foreach ( $blueprints as $slug => $bp ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $activeBlueprint, $slug ); ?>>
										<?php echo esc_html( ( $bp['label'] ?? $slug ) . ' — ' . ( $bp['description'] ?? '' ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
							<div class="vmsos-form-group">
								<label for="vmsos_agent_name"><?php esc_html_e( 'Sales Assistant Name', 'vm-sales-os' ); ?></label>
								<input name="vmsos_agent_name" id="vmsos_agent_name" class="vmsos-input-modern" value="<?php echo esc_attr( (string) get_option( 'vmsos_agent_name', 'Ava' ) ); ?>" />
							</div>
							<div class="vmsos-form-group">
								<label for="vmsos_agent_title"><?php esc_html_e( 'Assistant Subtitle / Status', 'vm-sales-os' ); ?></label>
								<input name="vmsos_agent_title" id="vmsos_agent_title" class="vmsos-input-modern" value="<?php echo esc_attr( (string) get_option( 'vmsos_agent_title', 'Online • Live Sales Specialist' ) ); ?>" />
							</div>
						</div>

						<div class="vmsos-form-group" style="margin-bottom: 20px;">
							<label for="vmsos_business_context"><?php esc_html_e( 'Business Context & Pricing Knowledge', 'vm-sales-os' ); ?></label>
							<textarea name="vmsos_business_context" id="vmsos_business_context" rows="5" class="vmsos-input-modern"><?php echo esc_textarea( (string) get_option( 'vmsos_business_context', '' ) ); ?></textarea>
							<span style="font-size: 11px; color: var(--vmsos-muted); margin-top: 4px;">
								<?php esc_html_e( 'Core services, pricing rules, turnaround times, and delivery SLAs the AI must strictly respect.', 'vm-sales-os' ); ?>
							</span>
						</div>

						<h3 style="color: #fff; font-size: 15px; margin: 24px 0 12px; border-top: 1px solid var(--vmsos-line); padding-top: 16px;"><?php esc_html_e( 'Website Live Chat Widget', 'vm-sales-os' ); ?></h3>
						<div style="display: flex; flex-direction: column; gap: 12px;">
							<label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: var(--vmsos-text);">
								<input type="checkbox" name="vmsos_widget_enabled" value="1" <?php checked( (bool) get_option( 'vmsos_widget_enabled', true ) ); ?> />
								<?php esc_html_e( 'Show live sales chat widget on website pages', 'vm-sales-os' ); ?>
							</label>

							<label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: var(--vmsos-text);">
								<input type="checkbox" name="vmsos_widget_teaser_enabled" value="1" <?php checked( (bool) get_option( 'vmsos_widget_teaser_enabled', true ) ); ?> />
								<?php esc_html_e( 'Pop up proactive sales teaser bubble automatically', 'vm-sales-os' ); ?>
							</label>
						</div>

						<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px;">
							<div class="vmsos-form-group">
								<label for="vmsos_widget_whatsapp"><?php esc_html_e( 'Direct WhatsApp Number (Header Action)', 'vm-sales-os' ); ?></label>
								<input name="vmsos_widget_whatsapp" id="vmsos_widget_whatsapp" class="vmsos-input-modern" value="<?php echo esc_attr( (string) get_option( 'vmsos_widget_whatsapp', '' ) ); ?>" placeholder="e.g. 919876543210 (country code + number)" />
							</div>
							<div class="vmsos-form-group">
								<label for="vmsos_widget_phone"><?php esc_html_e( 'Direct Phone Number (Header Action)', 'vm-sales-os' ); ?></label>
								<input name="vmsos_widget_phone" id="vmsos_widget_phone" class="vmsos-input-modern" value="<?php echo esc_attr( (string) get_option( 'vmsos_widget_phone', '' ) ); ?>" placeholder="e.g. +91 98765 43210" />
							</div>
						</div>
					</div>
				</div>

				<!-- TAB 4: WhatsApp & Voice Telephony -->
				<div class="vmsos-tab-content" id="tab-omnichannel">
					<!-- WhatsApp Suite -->
					<div class="vmsos-card">
						<div class="vmsos-card-header">
							<div>
								<h2><?php esc_html_e( 'WhatsApp Conversational Commerce Engine', 'vm-sales-os' ); ?></h2>
								<p><?php esc_html_e( 'Choose between direct Meta WhatsApp Cloud API or local Evolution API running on your VPS.', 'vm-sales-os' ); ?></p>
							</div>
							<?php $waProvider = (string) get_option( 'vmsos_whatsapp_provider', 'meta' ); ?>
							<select name="vmsos_whatsapp_provider" id="vmsos_whatsapp_provider" class="vmsos-select-modern">
								<option value="meta" <?php selected( $waProvider, 'meta' ); ?>><?php esc_html_e( 'Meta WhatsApp Cloud API', 'vm-sales-os' ); ?></option>
								<option value="evolution" <?php selected( $waProvider, 'evolution' ); ?>><?php esc_html_e( 'Evolution API (Self-Hosted on VPS)', 'vm-sales-os' ); ?></option>
							</select>
						</div>

						<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
							<div class="vmsos-form-group">
								<label for="vmsos_evolution_api_url"><?php esc_html_e( 'Evolution API URL', 'vm-sales-os' ); ?></label>
								<input type="url" name="vmsos_evolution_api_url" id="vmsos_evolution_api_url" class="vmsos-input-modern" value="<?php echo esc_attr( (string) get_option( 'vmsos_evolution_api_url', 'http://127.0.0.1:18080' ) ); ?>" placeholder="http://127.0.0.1:18080 or https://wa.vmstudio.digital" />
							</div>
							<div class="vmsos-form-group">
								<label for="vmsos_evolution_instance"><?php esc_html_e( 'Evolution Instance Name', 'vm-sales-os' ); ?></label>
								<input name="vmsos_evolution_instance" id="vmsos_evolution_instance" class="vmsos-input-modern" value="<?php echo esc_attr( (string) get_option( 'vmsos_evolution_instance', 'mynearby' ) ); ?>" placeholder="mynearby" />
							</div>
						</div>

						<div class="vmsos-form-group" style="margin-top: 14px;">
							<label for="vmsos_evolution_api_key"><?php esc_html_e( 'Evolution Global API Key', 'vm-sales-os' ); ?></label>
							<input type="password" name="vmsos_evolution_api_key" id="vmsos_evolution_api_key" class="vmsos-input-modern" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $this->vault->hint( 'evolution_api_key' ) ?: 'AUTHENTICATION_API_KEY' ); ?>" />
							<span style="font-size: 11px; color: var(--vmsos-muted); margin-top: 4px;">
								<?php esc_html_e( 'Evolution Webhook URL:', 'vm-sales-os' ); ?>
								<code><?php echo esc_html( rest_url( 'vmsos/v1/channels/evolution' ) ); ?></code>
							</span>
						</div>
					</div>

					<!-- Voice Telephony -->
					<div class="vmsos-card">
						<div class="vmsos-card-header">
							<div>
								<h2><?php esc_html_e( 'Autonomous AI Voice Telephony (Vapi / Twilio)', 'vm-sales-os' ); ?></h2>
								<p><?php esc_html_e( 'Direct voice calling agent conducting qualifying discovery calls with prospect leads and syncing call transcripts.', 'vm-sales-os' ); ?></p>
							</div>
							<label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: var(--vmsos-text); font-weight: 600;">
								<input type="checkbox" name="vmsos_voice_enabled" value="1" <?php checked( (bool) get_option( 'vmsos_voice_enabled', false ) ); ?> />
								<?php esc_html_e( 'Enable Voice Calls', 'vm-sales-os' ); ?>
							</label>
						</div>

						<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
							<div class="vmsos-form-group">
								<label for="vmsos_voice_api_key"><?php esc_html_e( 'Vapi API Key', 'vm-sales-os' ); ?></label>
								<input type="password" name="vmsos_voice_api_key" id="vmsos_voice_api_key" class="vmsos-input-modern" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $this->vault->hint( 'voice_api_key' ) ); ?>" />
							</div>
							<div class="vmsos-form-group">
								<label for="vmsos_voice_assistant_id"><?php esc_html_e( 'Voice Assistant ID', 'vm-sales-os' ); ?></label>
								<input name="vmsos_voice_assistant_id" id="vmsos_voice_assistant_id" class="vmsos-input-modern" value="<?php echo esc_attr( (string) get_option( 'vmsos_voice_assistant_id', '' ) ); ?>" placeholder="550e8400-e29b-41d4-a716-446655440000" />
							</div>
						</div>
					</div>

					<!-- Send Governor & Quiet Hours -->
					<div class="vmsos-card">
						<div class="vmsos-card-header">
							<div>
								<h2><?php esc_html_e( 'Send Governor & Delivery Safety Rules', 'vm-sales-os' ); ?></h2>
								<p><?php esc_html_e( 'Protects sender reputation and compliance across email, WhatsApp, and voice calling.', 'vm-sales-os' ); ?></p>
							</div>
						</div>

						<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
							<div class="vmsos-form-group">
								<label for="vmsos_daily_cap_email"><?php esc_html_e( 'Daily Email Sending Cap', 'vm-sales-os' ); ?></label>
								<input type="number" min="0" name="vmsos_daily_cap_email" id="vmsos_daily_cap_email" class="vmsos-input-modern" value="<?php echo esc_attr( (string) get_option( 'vmsos_daily_cap_email', 500 ) ); ?>" />
							</div>
							<div class="vmsos-form-group">
								<label for="vmsos_daily_cap_whatsapp"><?php esc_html_e( 'Daily WhatsApp Sending Cap', 'vm-sales-os' ); ?></label>
								<input type="number" min="0" name="vmsos_daily_cap_whatsapp" id="vmsos_daily_cap_whatsapp" class="vmsos-input-modern" value="<?php echo esc_attr( (string) get_option( 'vmsos_daily_cap_whatsapp', 200 ) ); ?>" />
							</div>
						</div>

						<div class="vmsos-form-group">
							<label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: var(--vmsos-text);">
								<input type="checkbox" name="vmsos_send_window_enabled" value="1" <?php checked( (bool) get_option( 'vmsos_send_window_enabled', false ) ); ?> />
								<?php esc_html_e( 'Enforce Quiet Hours Window (marketing sequences only)', 'vm-sales-os' ); ?>
							</label>
							<input name="vmsos_send_window" class="vmsos-input-modern" style="margin-top: 6px; max-width: 250px;" value="<?php echo esc_attr( (string) get_option( 'vmsos_send_window', '09:00-21:00' ) ); ?>" placeholder="09:00-21:00" />
						</div>
					</div>
				</div>

				<!-- TAB 5: Mobile Bridge & Updates -->
				<div class="vmsos-tab-content" id="tab-mobile-sync">
					<div class="vmsos-card">
						<div class="vmsos-card-header">
							<div>
								<h2><?php esc_html_e( 'Mobile App Bridge (Z-Agents / Superfone)', 'vm-sales-os' ); ?></h2>
								<p><?php esc_html_e( 'Native REST gateway connecting Z-Agents Flutter mobile and web apps to VM Sales OS.', 'vm-sales-os' ); ?></p>
							</div>
						</div>

						<div class="vmsos-form-group">
							<label for="vmsos_mobile_api_key"><?php esc_html_e( 'Shared Mobile Gateway API Key', 'vm-sales-os' ); ?></label>
							<input type="password" name="vmsos_mobile_api_key" id="vmsos_mobile_api_key" class="vmsos-input-modern" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $this->vault->hint( 'mobile.api_key' ) ?: 'Set shared mobile API key...' ); ?>" style="max-width: 500px;" />
							<span style="font-size: 11px; color: var(--vmsos-muted); margin-top: 4px;">
								<?php esc_html_e( 'Validated by ApiKeyGuard via request header: X-VMAI-API-Key: <key>', 'vm-sales-os' ); ?>
							</span>
						</div>

						<div style="margin-top: 16px; padding: 12px; background: rgba(0,0,0,0.2); border-radius: 8px;">
							<span style="font-size: 12px; font-weight: 600; color: #fff;"><?php esc_html_e( 'Active Mobile REST Endpoints:', 'vm-sales-os' ); ?></span>
							<code style="display: block; margin-top: 6px; font-size: 11px; color: #38bdf8;"><?php echo esc_html( rest_url( 'vmai/v1' ) ); ?> (/leads, /dashboard, /inbox/conversations, /inbox/reply, /call/log, /call/summary)</code>
						</div>
					</div>

					<!-- GitHub In-Place Updates -->
					<div class="vmsos-card">
						<div class="vmsos-card-header">
							<div>
								<h2><?php esc_html_e( 'GitHub Updates & In-Place Code Sync', 'vm-sales-os' ); ?></h2>
								<p><?php esc_html_e( 'Direct two-way deployment connecting to GitHub repository for zero-downtime hot updates.', 'vm-sales-os' ); ?></p>
							</div>
							<span class="vmsos-pill vmsos-pill--cyan">
								v<?php echo esc_html( VMSOS_VERSION ); ?>
							</span>
						</div>

						<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px; margin-bottom: 16px;">
							<div class="vmsos-form-group">
								<label for="vmsos_github_repo"><?php esc_html_e( 'GitHub Repository', 'vm-sales-os' ); ?></label>
								<input name="vmsos_github_repo" id="vmsos_github_repo" class="vmsos-input-modern" value="<?php echo esc_attr( (string) get_option( 'vmsos_github_repo', 'vmai-plugins/vm-sales-os' ) ); ?>" />
							</div>
							<div class="vmsos-form-group">
								<label for="vmsos_github_branch"><?php esc_html_e( 'Git Branch', 'vm-sales-os' ); ?></label>
								<input name="vmsos_github_branch" id="vmsos_github_branch" class="vmsos-input-modern" value="<?php echo esc_attr( (string) get_option( 'vmsos_github_branch', 'main' ) ); ?>" />
							</div>
						</div>

						<div style="display: flex; gap: 10px; align-items: center;">
							<button type="button" class="button button-secondary" id="vmsos-btn-check-gh-update"><?php esc_html_e( 'Check for GitHub Updates', 'vm-sales-os' ); ?></button>
							<button type="button" class="button button-primary" id="vmsos-btn-perform-gh-update" style="display:none;"><?php esc_html_e( 'Update Plugin Now', 'vm-sales-os' ); ?></button>
							<span id="vmsos-gh-update-status" style="margin-left: 10px; font-weight: 500; font-size: 13px;"></span>
						</div>
					</div>
				</div>

				<!-- Sticky Floating Save Bar -->
				<div class="vmsos-sticky-save-bar">
					<div style="display: flex; align-items: center; gap: 10px;">
						<span class="vmsos-pill-dot" style="background: #c9a227; box-shadow: 0 0 8px #c9a227;"></span>
						<span style="font-size: 13px; font-weight: 600; color: #fff;"><?php esc_html_e( 'VM Sales OS Configuration Governor', 'vm-sales-os' ); ?></span>
					</div>
					<button type="submit" class="vmsos-save-btn-modern">
						<?php esc_html_e( 'Save All Settings', 'vm-sales-os' ); ?>
					</button>
				</div>
			</form>
		</div>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			// Tab Navigation Logic
			var tabBtns = document.querySelectorAll('#vmsos-settings-tabs .vmsos-tab-btn');
			var tabPanes = document.querySelectorAll('.vmsos-tab-content');

			tabBtns.forEach(function(btn) {
				btn.addEventListener('click', function() {
					tabBtns.forEach(function(b) { b.classList.remove('is-active'); });
					tabPanes.forEach(function(p) { p.classList.remove('is-active'); });

					btn.classList.add('is-active');
					var targetId = btn.getAttribute('data-tab');
					var targetPane = document.getElementById(targetId);
					if (targetPane) targetPane.classList.add('is-active');
				});
			});

			// Failover Preset Buttons
			document.querySelectorAll('.vmsos-preset-btn').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var chainInput = document.getElementById('vmsos_ai_chain');
					if (chainInput) {
						chainInput.value = btn.getAttribute('data-chain');
						chainInput.style.borderColor = '#c9a227';
						setTimeout(function() { chainInput.style.borderColor = ''; }, 1200);
					}
				});
			});

			// AI Pipeline Live Test Ping
			var testAiBtn = document.getElementById('vmsos-btn-test-ai');
			var testPromptInput = document.getElementById('vmsos-ai-test-prompt');
			var testResultBox = document.getElementById('vmsos-ai-test-result');

			if (testAiBtn && testPromptInput && testResultBox) {
				testAiBtn.addEventListener('click', function() {
					testAiBtn.disabled = true;
					testAiBtn.innerHTML = '<span class="dashicons dashicons-update" style="animation:vmsosRotate 1s linear infinite;"></span> Testing...';
					testResultBox.style.display = 'block';
					testResultBox.innerHTML = '<span style="color:#64748b;">⚡ Sending prompt through failover pipeline...</span>';

					var data = new FormData();
					data.append('action', 'vmsos_test_ai');
					data.append('prompt', testPromptInput.value.trim());
					data.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'vmsos_settings' ) ); ?>');

					fetch(ajaxurl, { method: 'POST', body: data })
						.then(function(r) { return r.json(); })
						.then(function(res) {
							testAiBtn.disabled = false;
							testAiBtn.innerHTML = '<span class="dashicons dashicons-controls-play"></span> Ping Pipeline';
							if (res.success && res.data) {
								testResultBox.innerHTML = '<div style="color:#10b981; font-weight:bold; margin-bottom:4px;">✓ ' + res.data.message + '</div>' +
									'<div style="color:#f1f5f9; background:#0b1327; padding:8px 10px; border-radius:4px; margin-top:6px; border:1px solid rgba(255,255,255,0.1);">' + res.data.reply + '</div>';
							} else {
								testResultBox.innerHTML = '<div style="color:#ef4444; font-weight:bold;">✗ ' + ((res.data && res.data.message) || 'Pipeline ping failed') + '</div>';
							}
						})
						.catch(function(err) {
							testAiBtn.disabled = false;
							testAiBtn.innerHTML = '<span class="dashicons dashicons-controls-play"></span> Ping Pipeline';
							testResultBox.innerHTML = '<div style="color:#ef4444;">Network connection error during test.</div>';
						});
				});
			}

			// B2B Lead Gen Daily Batch Trigger
			var runLeadgenBtn = document.getElementById('vmsos-btn-run-leadgen-batch');
			var leadgenStatusBox = document.getElementById('vmsos-leadgen-batch-status');

			if (runLeadgenBtn && leadgenStatusBox) {
				runLeadgenBtn.addEventListener('click', function() {
					runLeadgenBtn.disabled = true;
					runLeadgenBtn.innerHTML = '<span class="dashicons dashicons-update" style="animation:vmsosRotate 1s linear infinite;"></span> Running...';
					leadgenStatusBox.style.display = 'block';
					leadgenStatusBox.innerHTML = '<span style="color:#10b981;">Starting scheduled daily B2B import batch...</span>';

					var data = new FormData();
					data.append('action', 'vmsos_run_daily_leadgen');
					data.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'vmsos_settings' ) ); ?>');

					fetch(ajaxurl, { method: 'POST', body: data })
						.then(function(r) { return r.json(); })
						.then(function(res) {
							runLeadgenBtn.disabled = false;
							runLeadgenBtn.innerHTML = '<span class="dashicons dashicons-controls-forward"></span> Run Daily Import Batch Now';
							if (res.success) {
								leadgenStatusBox.innerHTML = '<span style="color:#10b981; font-weight:bold;">✓ ' + res.data.message + '</span>';
							} else {
								leadgenStatusBox.innerHTML = '<span style="color:#ef4444; font-weight:bold;">✗ ' + ((res.data && res.data.message) || 'Batch run failed') + '</span>';
							}
						})
						.catch(function(err) {
							runLeadgenBtn.disabled = false;
							runLeadgenBtn.innerHTML = '<span class="dashicons dashicons-controls-forward"></span> Run Daily Import Batch Now';
							leadgenStatusBox.innerHTML = '<span style="color:#ef4444;">Connection failed.</span>';
						});
				});
			}

			// GitHub Update Checkers
			var checkBtn = document.getElementById('vmsos-btn-check-gh-update');
			var updateBtn = document.getElementById('vmsos-btn-perform-gh-update');
			var statusEl = document.getElementById('vmsos-gh-update-status');

			if (checkBtn && updateBtn && statusEl) {
				checkBtn.addEventListener('click', function() {
					checkBtn.disabled = true;
					statusEl.textContent = 'Checking GitHub...';
					statusEl.style.color = '';

					var data = new FormData();
					data.append('action', 'vmsos_check_github_update');
					data.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'vmsos_settings' ) ); ?>');

					fetch(ajaxurl, { method: 'POST', body: data })
						.then(function(r) { return r.json(); })
						.then(function(res) {
							checkBtn.disabled = false;
							if (res.success) {
								statusEl.textContent = res.data.message;
								if (res.data.has_update) {
									statusEl.style.color = '#10b981';
									updateBtn.style.display = 'inline-block';
								} else {
									statusEl.style.color = '#64748b';
								}
							} else {
								statusEl.textContent = (res.data && res.data.message) || 'Check failed';
								statusEl.style.color = '#ef4444';
							}
						})
						.catch(function(err) {
							checkBtn.disabled = false;
							statusEl.textContent = 'Could not reach server';
							statusEl.style.color = '#ef4444';
						});
				});

				updateBtn.addEventListener('click', function() {
					if (!confirm('Update VM Sales OS from GitHub now?')) return;
					updateBtn.disabled = true;
					statusEl.textContent = 'Downloading and applying GitHub update...';
					statusEl.style.color = '#10b981';

					var data = new FormData();
					data.append('action', 'vmsos_perform_github_update');
					data.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'vmsos_settings' ) ); ?>');

					fetch(ajaxurl, { method: 'POST', body: data })
						.then(function(r) { return r.json(); })
						.then(function(res) {
							if (res.success) {
								statusEl.textContent = res.data.message || 'Updated!';
								setTimeout(function() { window.location.reload(); }, 1500);
							} else {
								updateBtn.disabled = false;
								statusEl.textContent = (res.data && res.data.message) || 'Update failed';
								statusEl.style.color = '#ef4444';
							}
						})
						.catch(function(err) {
							updateBtn.disabled = false;
							statusEl.textContent = 'Update request failed';
							statusEl.style.color = '#ef4444';
						});
				});
			}

			// Live Model Sync Handler
			document.querySelectorAll('.vmsos-btn-sync-model').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var providerId = btn.getAttribute('data-provider');
					var row = btn.closest('.vmsos-provider-row');
					var keyInput = row.querySelector('.vmsos-field-key');
					var baseInput = row.querySelector('.vmsos-field-base');
					var modelInput = row.querySelector('.vmsos-field-model');
					var datalist = row.querySelector('datalist');
					var select = row.querySelector('.vmsos-select-model');
					var badge = document.getElementById('vmsos_badge_' + providerId);
					var feedback = document.getElementById('vmsos_feedback_' + providerId);

					btn.disabled = true;
					var icon = btn.querySelector('.dashicons');
					if (icon) icon.style.animation = 'vmsosRotate 1s linear infinite';
					feedback.textContent = 'Syncing...';
					feedback.style.color = '#64748b';

					var formData = new FormData();
					formData.append('action', 'vmsos_sync_models');
					formData.append('provider', providerId);
					if (keyInput && keyInput.value.trim()) {
						formData.append('api_key', keyInput.value.trim());
					}
					if (baseInput && baseInput.value.trim()) {
						formData.append('base_url', baseInput.value.trim());
					}
					formData.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'vmsos_settings' ) ); ?>');

					fetch(ajaxurl, { method: 'POST', body: formData })
						.then(function(res) { return res.json(); })
						.then(function(res) {
							btn.disabled = false;
							if (icon) icon.style.animation = '';
							if (res.success && res.data && res.data.models) {
								var models = res.data.models;
								feedback.textContent = '✓ ' + res.data.message;
								feedback.style.color = '#10b981';

								// Update datalist & select
								if (datalist) datalist.innerHTML = '';
								if (select) {
									select.innerHTML = '<option value="">-- Choose --</option>';
									models.forEach(function(m) {
										var optList = document.createElement('option');
										optList.value = m;
										if (datalist) datalist.appendChild(optList);

										var optSelect = document.createElement('option');
										optSelect.value = m;
										optSelect.textContent = m;
										if (modelInput && modelInput.value === m) {
											optSelect.selected = true;
										}
										select.appendChild(optSelect);
									});
									select.style.display = 'inline-block';
								}

								if (badge) {
									var countSpan = badge.querySelector('.vmsos-count-val');
									if (countSpan) countSpan.textContent = models.length;
									badge.style.display = 'block';
								}
							} else {
								feedback.textContent = (res.data && res.data.message) || 'Sync failed';
								feedback.style.color = '#ef4444';
							}
						})
						.catch(function(err) {
							btn.disabled = false;
							if (icon) icon.style.animation = '';
							feedback.textContent = 'Connection error';
							feedback.style.color = '#ef4444';
						});
				});
			});

			// Auto-fill model text input when select dropdown changes
			document.querySelectorAll('.vmsos-select-model').forEach(function(sel) {
				sel.addEventListener('change', function() {
					var targetId = sel.getAttribute('data-target');
					var targetInput = document.getElementById(targetId);
					if (targetInput && sel.value) {
						targetInput.value = sel.value;
					}
				});
			});
		});
		</script>
		<?php
	}

	public function ajaxSyncModels(): void {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( (string) $_POST['_wpnonce'] ) : '';
		if ( ! current_user_can( 'vmsos_manage' ) || ! wp_verify_nonce( $nonce, 'vmsos_settings' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied or expired session.', 'vm-sales-os' ) ], 403 );
		}

		$providerId = isset( $_POST['provider'] ) ? sanitize_key( (string) $_POST['provider'] ) : '';
		$provider   = $this->providers->get( $providerId );

		if ( ! $provider ) {
			wp_send_json_error( [ 'message' => sprintf( __( 'Unknown provider: %s', 'vm-sales-os' ), $providerId ) ], 400 );
		}

		$keyOverride  = isset( $_POST['api_key'] ) && '' !== trim( (string) $_POST['api_key'] ) ? trim( (string) wp_unslash( $_POST['api_key'] ) ) : null;
		$baseOverride = isset( $_POST['base_url'] ) && '' !== trim( (string) $_POST['base_url'] ) ? esc_url_raw( (string) wp_unslash( $_POST['base_url'] ) ) : null;

		try {
			$models = $provider->getModels( true, $keyOverride, $baseOverride );
			wp_send_json_success( [
				'provider' => $providerId,
				'models'   => $models,
				'count'    => count( $models ),
				'message'  => sprintf( __( 'Synced %d models live.', 'vm-sales-os' ), count( $models ) ),
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [
				'provider' => $providerId,
				'message'  => $e->getMessage(),
			], 500 );
		}
	}

	public function ajaxTestAi(): void {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( (string) $_POST['_wpnonce'] ) : '';
		if ( ! current_user_can( 'vmsos_manage' ) || ! wp_verify_nonce( $nonce, 'vmsos_settings' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied or expired session.', 'vm-sales-os' ) ], 403 );
		}

		$prompt = isset( $_POST['prompt'] ) && '' !== trim( (string) $_POST['prompt'] ) 
			? sanitize_text_field( wp_unslash( $_POST['prompt'] ) ) 
			: 'Say "VM Sales OS Autonomous Brain is online and ready!" in under 12 words.';

		$start = microtime( true );
		try {
			$response = $this->providers->complete( [
				[ 'role' => 'user', 'content' => $prompt ],
			] );
			$latencyMs = round( ( microtime( true ) - $start ) * 1000 );

			wp_send_json_success( [
				'provider'   => $response['provider'] ?? 'unknown',
				'model'      => $response['model'] ?? 'unknown',
				'reply'      => $response['text'] ?? '',
				'latency_ms' => $latencyMs,
				'message'    => sprintf( __( 'Responded via %s (%s) in %dms', 'vm-sales-os' ), $response['provider'] ?? 'unknown', $response['model'] ?? 'unknown', $latencyMs ),
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [
				'message'    => $e->getMessage(),
				'latency_ms' => round( ( microtime( true ) - $start ) * 1000 ),
			], 500 );
		}
	}

	public function ajaxRunDailyLeadgen(): void {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( (string) $_POST['_wpnonce'] ) : '';
		if ( ! current_user_can( 'vmsos_manage' ) || ! wp_verify_nonce( $nonce, 'vmsos_settings' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied or expired session.', 'vm-sales-os' ) ], 403 );
		}

		try {
			ob_start();
			do_action( \VMStudio\SalesOS\Modules\LeadGen\LeadGenModule::DAILY_TICK );
			$output = ob_get_clean();

			wp_send_json_success( [
				'message' => __( 'Scheduled daily B2B lead generation executed successfully.', 'vm-sales-os' ),
				'output'  => $output,
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [
				'message' => $e->getMessage(),
			], 500 );
		}
	}

	public function saveSettings(): void {
		if ( ! current_user_can( 'vmsos_manage' ) || ! check_admin_referer( 'vmsos_settings' ) ) {
			wp_die( esc_html__( 'You cannot change these settings.', 'vm-sales-os' ), 403 );
		}

		// Apply industry blueprint if requested.
		$blueprintToApply = isset( $_POST['vmsos_apply_blueprint'] ) ? sanitize_key( (string) $_POST['vmsos_apply_blueprint'] ) : 'none';
		if ( 'none' !== $blueprintToApply && isset( IndustryPresets::all()[ $blueprintToApply ] ) ) {
			IndustryPresets::apply( $blueprintToApply );
			update_option( 'vmsos_active_blueprint', $blueprintToApply, false );
		}

		$chain = array_values(
			array_filter(
				array_map( 'sanitize_key', explode( ',', (string) ( $_POST['vmsos_ai_chain'] ?? '' ) ) ),
				fn( string $id ) => null !== $this->providers->get( $id )
			)
		);

		if ( $chain ) {
			update_option( 'vmsos_ai_chain', $chain, false );
		}

		foreach ( array_keys( $this->providers->all() ) as $id ) {
			$key = isset( $_POST[ 'vmsos_key_' . $id ] ) ? trim( (string) wp_unslash( $_POST[ 'vmsos_key_' . $id ] ) ) : '';
			if ( '' !== $key ) {
				$this->vault->put( 'ai.' . $id . '.key', $key );
			}

			update_option( 'vmsos_ai_' . $id . '_base_url', esc_url_raw( (string) ( $_POST[ 'vmsos_base_' . $id ] ?? '' ) ), false );
			update_option( 'vmsos_ai_' . $id . '_model', sanitize_text_field( (string) wp_unslash( $_POST[ 'vmsos_model_' . $id ] ?? '' ) ), false );
		}

		update_option( 'vmsos_agent_name', sanitize_text_field( (string) wp_unslash( $_POST['vmsos_agent_name'] ?? 'Ava' ) ), false );
		update_option( 'vmsos_agent_title', sanitize_text_field( (string) wp_unslash( $_POST['vmsos_agent_title'] ?? 'Online â€¢ Live Sales Specialist' ) ), false );
		update_option( 'vmsos_business_context', sanitize_textarea_field( (string) wp_unslash( $_POST['vmsos_business_context'] ?? '' ) ), false );
		update_option( 'vmsos_widget_enabled', ! empty( $_POST['vmsos_widget_enabled'] ), false );
		update_option( 'vmsos_widget_teaser_enabled', ! empty( $_POST['vmsos_widget_teaser_enabled'] ), false );
		update_option( 'vmsos_widget_teaser_delay', max( 1, min( 60, (int) ( $_POST['vmsos_widget_teaser_delay'] ?? 3 ) ) ), false );
		update_option( 'vmsos_widget_teaser_text', sanitize_textarea_field( (string) wp_unslash( $_POST['vmsos_widget_teaser_text'] ?? '' ) ), false );
		update_option( 'vmsos_widget_starter_chips', sanitize_textarea_field( (string) wp_unslash( $_POST['vmsos_widget_starter_chips'] ?? '' ) ), false );
		update_option( 'vmsos_widget_whatsapp', sanitize_text_field( (string) wp_unslash( $_POST['vmsos_widget_whatsapp'] ?? '' ) ), false );
		update_option( 'vmsos_widget_phone', sanitize_text_field( (string) wp_unslash( $_POST['vmsos_widget_phone'] ?? '' ) ), false );
		update_option( 'vmsos_widget_accent', sanitize_hex_color( (string) wp_unslash( $_POST['vmsos_widget_accent'] ?? '#c9a227' ) ) ?: '#c9a227', false );
		update_option( 'vmsos_widget_position', in_array( $_POST['vmsos_widget_position'] ?? '', [ 'left', 'right' ], true ) ? (string) $_POST['vmsos_widget_position'] : 'right', false );

		update_option( 'vmsos_sender_name', sanitize_text_field( (string) wp_unslash( $_POST['vmsos_sender_name'] ?? '' ) ), false );
		update_option( 'vmsos_sender_email', sanitize_email( (string) wp_unslash( $_POST['vmsos_sender_email'] ?? '' ) ), false );
		update_option( 'vmsos_notify_email', sanitize_email( (string) wp_unslash( $_POST['vmsos_notify_email'] ?? '' ) ), false );
		update_option( 'vmsos_require_consent', ! empty( $_POST['vmsos_require_consent'] ), false );
		update_option( 'vmsos_track_opens', ! empty( $_POST['vmsos_track_opens'] ), false );
		update_option( 'vmsos_track_clicks', ! empty( $_POST['vmsos_track_clicks'] ), false );

		update_option( 'vmsos_send_window_enabled', ! empty( $_POST['vmsos_send_window_enabled'] ), false );
		update_option( 'vmsos_send_window', sanitize_text_field( (string) wp_unslash( $_POST['vmsos_send_window'] ?? '09:00-21:00' ) ), false );
		update_option( 'vmsos_daily_cap_email', max( 0, (int) ( $_POST['vmsos_daily_cap_email'] ?? 500 ) ), false );
		update_option( 'vmsos_daily_cap_whatsapp', max( 0, (int) ( $_POST['vmsos_daily_cap_whatsapp'] ?? 200 ) ), false );
		update_option( 'vmsos_memory_every', max( 2, min( 20, (int) ( $_POST['vmsos_memory_every'] ?? 4 ) ) ), false );

		// Mobile Bridge Key.
		$mobileKey = isset( $_POST['vmsos_mobile_api_key'] ) ? trim( (string) wp_unslash( $_POST['vmsos_mobile_api_key'] ) ) : '';
		if ( '' !== $mobileKey ) {
			$this->vault->put( 'mobile.api_key', $mobileKey );
		}

		// WhatsApp & Evolution API Settings.
		update_option( 'vmsos_whatsapp_provider', sanitize_key( (string) wp_unslash( $_POST['vmsos_whatsapp_provider'] ?? 'meta' ) ), false );
		update_option( 'vmsos_evolution_api_url', esc_url_raw( (string) ( $_POST['vmsos_evolution_api_url'] ?? 'http://127.0.0.1:18080' ) ), false );
		update_option( 'vmsos_evolution_instance', sanitize_text_field( (string) wp_unslash( $_POST['vmsos_evolution_instance'] ?? '' ) ), false );

		$evolutionKey = isset( $_POST['vmsos_evolution_api_key'] ) ? trim( (string) wp_unslash( $_POST['vmsos_evolution_api_key'] ) ) : '';
		if ( '' !== $evolutionKey ) {
			$this->vault->put( 'evolution_api_key', $evolutionKey );
		}

		update_option( 'vmsos_whatsapp_phone_id', sanitize_text_field( (string) wp_unslash( $_POST['vmsos_whatsapp_phone_id'] ?? '' ) ), false );

		// Voice Telephony Options.
		update_option( 'vmsos_voice_enabled', ! empty( $_POST['vmsos_voice_enabled'] ), false );
		update_option( 'vmsos_voice_assistant_id', sanitize_text_field( (string) wp_unslash( $_POST['vmsos_voice_assistant_id'] ?? '' ) ), false );
		update_option( 'vmsos_voice_phone_number_id', sanitize_text_field( (string) wp_unslash( $_POST['vmsos_voice_phone_number_id'] ?? '' ) ), false );
		update_option( 'vmsos_voice_webhook_secret', sanitize_text_field( (string) wp_unslash( $_POST['vmsos_voice_webhook_secret'] ?? '' ) ), false );
		update_option( 'vmsos_voice_disclosure', sanitize_textarea_field( (string) wp_unslash( $_POST['vmsos_voice_disclosure'] ?? '' ) ), false );

		// Secrets are only written when a new value is typed, so the saved
		// credential survives a save from a form showing only a hint.
		foreach ( [ 'whatsapp_token', 'whatsapp_verify_token', 'whatsapp_app_secret', 'voice_api_key', 'google_places_api_key' ] as $secret ) {
			$value = isset( $_POST[ 'vmsos_' . $secret ] ) ? trim( (string) wp_unslash( $_POST[ 'vmsos_' . $secret ] ) ) : '';
			if ( '' !== $value ) {
				$this->vault->put( $secret, $value );
			}
		}

		update_option( 'vmsos_leadgen_shadow_email_sync', ! empty( $_POST['vmsos_leadgen_shadow_email_sync'] ), false );

		// GitHub Repository & Update Settings.
		update_option( 'vmsos_github_repo', sanitize_text_field( (string) wp_unslash( $_POST['vmsos_github_repo'] ?? 'vmai-plugins/vm-sales-os' ) ), false );
		update_option( 'vmsos_github_branch', sanitize_text_field( (string) wp_unslash( $_POST['vmsos_github_branch'] ?? 'main' ) ), false );
		$ghToken = isset( $_POST['vmsos_github_token'] ) ? trim( (string) wp_unslash( $_POST['vmsos_github_token'] ) ) : '';
		if ( '' !== $ghToken ) {
			$this->vault->put( 'github_token', $ghToken );
		}

		wp_safe_redirect( add_query_arg( 'vmsos_saved', '1', admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ) );
		exit;
	}

	/** @param array<int, string> $links Existing links. */
	public function actionLinks( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ),
				esc_html__( 'Settings', 'vm-sales-os' )
			)
		);
		return $links;
	}
}
