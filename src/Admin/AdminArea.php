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

		$capOperate = current_user_can( 'vmsos_operate' ) ? 'vmsos_operate' : 'manage_options';
		$capManage  = current_user_can( 'vmsos_manage' ) ? 'vmsos_manage' : 'manage_options';

		add_submenu_page( self::SLUG, __( 'Dashboard', 'vm-sales-os' ), __( 'Dashboard', 'vm-sales-os' ), $capOperate, self::SLUG, [ $this, 'render' ] );
		add_submenu_page( self::SLUG, __( 'Live Inbox', 'vm-sales-os' ), __( 'Inbox', 'vm-sales-os' ), $capOperate, self::SLUG . '&tab=inbox', [ $this, 'render' ] );
		add_submenu_page( self::SLUG, __( 'Leads & Contacts', 'vm-sales-os' ), __( 'Leads', 'vm-sales-os' ), $capOperate, self::SLUG . '&tab=leads', [ $this, 'render' ] );
		add_submenu_page( self::SLUG, __( 'Sales Pipeline & Deals', 'vm-sales-os' ), __( 'Deals', 'vm-sales-os' ), $capOperate, self::SLUG . '&tab=deals', [ $this, 'render' ] );
		add_submenu_page( self::SLUG, __( 'Settings', 'vm-sales-os' ), __( 'Settings', 'vm-sales-os' ), $capManage, self::SLUG . '-settings', [ $this, 'renderSettings' ] );
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
				<style id="vmsos-modern-settings-inline-css">
			/* Dark theme canvas override for settings screen */
			body.sales-os_page_vm-sales-os-settings,
			body[class*="sales-os-settings"] {
				background: #070d1e !important;
			}
			body.sales-os_page_vm-sales-os-settings #wpbody-content,
			body[class*="sales-os-settings"] #wpbody-content {
				background: #070d1e !important;
				color: #e2e8f0;
				padding-bottom: 60px;
			}
			.vmsos-settings-modern {
				--vmsos-ink: #0b1327;
				--vmsos-ink-2: #131c36;
				--vmsos-ink-3: #1c274c;
				--vmsos-line: rgba(255, 255, 255, 0.09);
				--vmsos-gold: #c9a227;
				--vmsos-cyan: #06b6d4;
				--vmsos-emerald: #10b981;
				--vmsos-purple: #8b5cf6;
				--vmsos-rose: #f43f5e;
				--vmsos-text: #f1f5f9;
				--vmsos-muted: #94a3b8;
				margin-top: 15px;
				margin-right: 20px;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
				color: #e2e8f0;
			}
			.vmsos-settings-hero {
				background: linear-gradient(135deg, #0b1327 0%, #152244 100%);
				border: 1px solid var(--vmsos-line);
				border-radius: 14px;
				padding: 24px 28px;
				margin-bottom: 24px;
				display: flex;
				flex-wrap: wrap;
				justify-content: space-between;
				align-items: center;
				gap: 16px;
				box-shadow: 0 4px 24px rgba(0, 0, 0, 0.2);
			}
			.vmsos-hero-title h1 {
				margin: 0 0 6px 0;
				font-size: 24px;
				font-weight: 700;
				color: #fff;
				display: flex;
				align-items: center;
				gap: 10px;
			}
			.vmsos-hero-title h1 span {
				font-weight: 400;
				color: var(--vmsos-gold);
				font-size: 16px;
				border-left: 1px solid rgba(255, 255, 255, 0.2);
				padding-left: 10px;
			}
			.vmsos-hero-title p {
				margin: 0;
				font-size: 13px;
				color: var(--vmsos-muted);
			}
			.vmsos-hero-status-pills {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
			}
			.vmsos-pill {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				padding: 6px 12px;
				border-radius: 20px;
				font-size: 11px;
				font-weight: 600;
				letter-spacing: 0.03em;
				background: rgba(255, 255, 255, 0.06);
				border: 1px solid rgba(255, 255, 255, 0.1);
				color: var(--vmsos-text);
			}
			.vmsos-pill-dot {
				width: 7px;
				height: 7px;
				border-radius: 50%;
				background: #94a3b8;
			}
			.vmsos-pill--emerald { background: rgba(16, 185, 129, 0.12); border-color: rgba(16, 185, 129, 0.35); color: #34d399; }
			.vmsos-pill--emerald .vmsos-pill-dot { background: #34d399; box-shadow: 0 0 8px #34d399; }
			.vmsos-pill--cyan { background: rgba(6, 182, 212, 0.12); border-color: rgba(6, 182, 212, 0.35); color: #22d3ee; }
			.vmsos-pill--cyan .vmsos-pill-dot { background: #22d3ee; box-shadow: 0 0 8px #22d3ee; }
			.vmsos-pill--gold { background: rgba(201, 162, 39, 0.12); border-color: rgba(201, 162, 39, 0.35); color: #fbbf24; }
			.vmsos-pill--gold .vmsos-pill-dot { background: #fbbf24; box-shadow: 0 0 8px #fbbf24; }
			.vmsos-tabs-nav {
				display: flex;
				gap: 6px;
				border-bottom: 2px solid var(--vmsos-line);
				margin-bottom: 24px;
				overflow-x: auto;
			}
			.vmsos-tab-btn {
				background: none;
				border: none;
				border-bottom: 2px solid transparent;
				margin-bottom: -2px;
				padding: 12px 18px;
				font-size: 13px;
				font-weight: 600;
				color: var(--vmsos-muted);
				cursor: pointer;
				display: inline-flex;
				align-items: center;
				gap: 8px;
				transition: all 0.2s ease;
			}
			.vmsos-tab-btn:hover {
				color: var(--vmsos-text);
				background: rgba(255, 255, 255, 0.03);
			}
			.vmsos-tab-btn.is-active {
				color: var(--vmsos-gold);
				border-bottom-color: var(--vmsos-gold);
			}
			.vmsos-tab-content {
				display: none;
			}
			.vmsos-tab-content.is-active {
				display: block;
				animation: vmsosFadeIn 0.25s ease;
			}
			@keyframes vmsosFadeIn {
				from { opacity: 0; transform: translateY(4px); }
				to { opacity: 1; transform: translateY(0); }
			}
			.vmsos-pipeline-visual {
				background: var(--vmsos-ink);
				border: 1px solid var(--vmsos-line);
				border-radius: 12px;
				padding: 20px 24px;
				margin-bottom: 20px;
			}
			.vmsos-pipeline-stages {
				display: flex !important;
				align-items: center !important;
				justify-content: space-between !important;
				gap: 12px !important;
				flex-wrap: wrap !important;
				margin-top: 14px !important;
			}
			.vmsos-stage-box {
				flex: 1 1 200px;
				min-width: 170px;
				background: var(--vmsos-ink-2);
				border: 1px solid var(--vmsos-line);
				border-radius: 10px;
				padding: 14px 16px;
				position: relative;
				transition: transform 0.2s ease;
			}
			.vmsos-stage-box.is-primary {
				border-color: rgba(6, 182, 212, 0.5);
				background: linear-gradient(180deg, rgba(6, 182, 212, 0.08) 0%, var(--vmsos-ink-2) 100%);
			}
			.vmsos-stage-box.is-fallback {
				border-color: rgba(201, 162, 39, 0.4);
				background: linear-gradient(180deg, rgba(201, 162, 39, 0.06) 0%, var(--vmsos-ink-2) 100%);
			}
			.vmsos-stage-tag {
				font-size: 9px;
				font-weight: 700;
				letter-spacing: 0.06em;
				text-transform: uppercase;
				padding: 3px 6px;
				border-radius: 4px;
				display: inline-block;
				margin-bottom: 6px;
			}
			.vmsos-stage-tag.tag-primary { background: rgba(6, 182, 212, 0.2); color: #22d3ee; }
			.vmsos-stage-tag.tag-fallback { background: rgba(201, 162, 39, 0.2); color: #fbbf24; }
			.vmsos-stage-tag.tag-sovereign { background: rgba(139, 92, 246, 0.2); color: #c084fc; }
			.vmsos-stage-tag.tag-anthropic { background: rgba(244, 63, 94, 0.2); color: #fb7185; }
			.vmsos-stage-title {
				font-size: 13px;
				font-weight: 700;
				color: #fff;
				margin-bottom: 3px;
			}
			.vmsos-stage-sub {
				font-size: 11px;
				color: var(--vmsos-muted);
			}
			.vmsos-stage-arrow {
				color: var(--vmsos-gold);
				font-size: 18px;
				font-weight: bold;
				user-select: none;
			}
			.vmsos-presets-row {
				display: flex;
				gap: 8px;
				margin-top: 14px;
				align-items: center;
				flex-wrap: wrap;
			}
			.vmsos-preset-btn {
				background: rgba(255, 255, 255, 0.05);
				border: 1px solid var(--vmsos-line);
				color: var(--vmsos-text);
				padding: 5px 12px;
				border-radius: 6px;
				font-size: 11px;
				font-weight: 600;
				cursor: pointer;
				transition: all 0.15s ease;
			}
			.vmsos-preset-btn:hover {
				background: rgba(201, 162, 39, 0.15);
				border-color: var(--vmsos-gold);
				color: var(--vmsos-gold);
			}
			.vmsos-ai-test-console {
				background: #060a14;
				border: 1px solid rgba(6, 182, 212, 0.25);
				border-radius: 10px;
				padding: 16px 20px;
				margin-top: 18px;
				margin-bottom: 24px;
			}
			.vmsos-ai-test-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				margin-bottom: 12px;
			}
			.vmsos-ai-test-header h4 {
				margin: 0;
				font-size: 13px;
				font-weight: 600;
				color: var(--vmsos-cyan);
				display: flex;
				align-items: center;
				gap: 6px;
			}
			.vmsos-ai-test-input-row {
				display: flex;
				gap: 10px;
				align-items: center;
			}
			.vmsos-ai-test-input {
				flex: 1;
				background: #0e172a !important;
				border: 1px solid #1e293b !important;
				color: #e2e8f0 !important;
				padding: 8px 12px !important;
				border-radius: 6px !important;
				font-size: 13px !important;
			}
			.vmsos-ai-test-btn {
				background: linear-gradient(135deg, #06b6d4 0%, #0284c7 100%) !important;
				color: #fff !important;
				border: none !important;
				padding: 8px 18px !important;
				border-radius: 6px !important;
				font-weight: 600 !important;
				font-size: 12px !important;
				cursor: pointer !important;
				display: inline-flex !important;
				align-items: center !important;
				gap: 6px !important;
				white-space: nowrap !important;
			}
			.vmsos-ai-test-result {
				margin-top: 12px;
				padding: 12px 14px;
				background: rgba(255, 255, 255, 0.03);
				border: 1px solid rgba(255, 255, 255, 0.08);
				border-radius: 6px;
				font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
				font-size: 12px;
				color: #cbd5e1;
				line-height: 1.5;
				display: none;
			}
			.vmsos-providers-grid {
				display: grid !important;
				grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)) !important;
				gap: 16px !important;
				margin-bottom: 24px !important;
			}
			.vmsos-provider-card {
				background: var(--vmsos-ink);
				border: 1px solid var(--vmsos-line);
				border-radius: 12px;
				padding: 20px;
				position: relative;
				display: flex;
				flex-direction: column;
				gap: 14px;
				box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
			}
			.vmsos-provider-card-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				border-bottom: 1px solid var(--vmsos-line);
				padding-bottom: 12px;
			}
			.vmsos-provider-info {
				display: flex;
				align-items: center;
				gap: 10px;
			}
			.vmsos-provider-info h3 {
				margin: 0;
				font-size: 15px;
				font-weight: 700;
				color: #fff;
			}
			.vmsos-form-group {
				display: flex;
				flex-direction: column;
				gap: 5px;
			}
			.vmsos-form-group label {
				font-size: 12px;
				font-weight: 600;
				color: var(--vmsos-muted);
			}
			.vmsos-form-row {
				display: flex;
				gap: 8px;
				align-items: center;
				flex-wrap: wrap;
			}
			.vmsos-input-modern {
				background: var(--vmsos-ink-2) !important;
				border: 1px solid var(--vmsos-line) !important;
				color: var(--vmsos-text) !important;
				padding: 8px 12px !important;
				border-radius: 6px !important;
				font-size: 13px !important;
				width: 100%;
				box-sizing: border-box;
				transition: border-color 0.2s ease;
			}
			.vmsos-input-modern:focus {
				border-color: var(--vmsos-gold) !important;
				box-shadow: 0 0 0 1px var(--vmsos-gold) !important;
				outline: none !important;
			}
			.vmsos-select-modern {
				background: var(--vmsos-ink-2) !important;
				border: 1px solid var(--vmsos-line) !important;
				color: var(--vmsos-text) !important;
				padding: 8px 12px !important;
				border-radius: 6px !important;
				font-size: 13px !important;
				cursor: pointer;
			}
			.vmsos-card {
				background: var(--vmsos-ink);
				border: 1px solid var(--vmsos-line);
				border-radius: 12px;
				padding: 22px 26px;
				margin-bottom: 20px;
				box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
			}
			.vmsos-card-header {
				margin-bottom: 18px;
				border-bottom: 1px solid var(--vmsos-line);
				padding-bottom: 14px;
				display: flex;
				justify-content: space-between;
				align-items: center;
			}
			.vmsos-card-header h2 {
				margin: 0 0 4px 0;
				font-size: 18px;
				font-weight: 700;
				color: #fff;
			}
			.vmsos-card-header p {
				margin: 0;
				font-size: 12px;
				color: var(--vmsos-muted);
			}
			.vmsos-leadgen-cron-banner {
				background: linear-gradient(135deg, rgba(16, 185, 129, 0.12) 0%, rgba(6, 182, 212, 0.08) 100%);
				border: 1px solid rgba(16, 185, 129, 0.3);
				border-radius: 10px;
				padding: 16px 20px;
				margin-bottom: 18px;
				display: flex;
				justify-content: space-between;
				align-items: center;
				flex-wrap: wrap;
				gap: 12px;
			}
			.vmsos-btn-action {
				background: rgba(255, 255, 255, 0.08);
				border: 1px solid var(--vmsos-line);
				color: #fff;
				padding: 7px 14px;
				border-radius: 6px;
				font-size: 12px;
				font-weight: 600;
				cursor: pointer;
				display: inline-flex;
				align-items: center;
				gap: 6px;
				transition: all 0.2s ease;
			}
			.vmsos-btn-action:hover {
				background: rgba(255, 255, 255, 0.15);
				border-color: rgba(255, 255, 255, 0.3);
			}
			.vmsos-sticky-save-bar {
				position: sticky;
				bottom: 20px;
				background: linear-gradient(135deg, #0b1327 0%, #17244a 100%);
				border: 1px solid var(--vmsos-gold);
				border-radius: 12px;
				padding: 14px 24px;
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-top: 30px;
				box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4);
				z-index: 50;
			}
			.vmsos-save-btn-modern {
				background: linear-gradient(135deg, #c9a227 0%, #dfb738 100%) !important;
				color: #0b1327 !important;
				border: none !important;
				font-weight: 700 !important;
				font-size: 14px !important;
				padding: 10px 28px !important;
				border-radius: 8px !important;
				cursor: pointer !important;
				box-shadow: 0 4px 14px rgba(201, 162, 39, 0.3) !important;
				transition: transform 0.15s ease !important;
			}
			.vmsos-save-btn-modern:hover {
				transform: translateY(-1px);
			}
			@keyframes vmsosRotate {
				from { transform: rotate(0deg); }
				to { transform: rotate(360deg); }
			}
		</style>
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
				<button type="button" class="vmsos-tab-btn" data-tab="tab-linkedin">
					<span class="dashicons dashicons-networking"></span>
					<?php esc_html_e( 'LinkedIn Page & Leads', 'vm-sales-os' ); ?>
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
								<?php
								$evoInstance = (string) get_option( 'vmsos_evolution_instance', 'vmstudio' );
								if ( in_array( $evoInstance, [ 'mynearby', 'vmstudi', '' ], true ) ) {
									$evoInstance = 'vmstudio';
								}
								?>
								<input name="vmsos_evolution_instance" id="vmsos_evolution_instance" class="vmsos-input-modern" value="<?php echo esc_attr( $evoInstance ); ?>" placeholder="vmstudio" />
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

				<!-- TAB: LinkedIn Page & Leads Hub -->
				<div class="vmsos-tab-content" id="tab-linkedin">
					<!-- Hero & Inbound Webhook Gateway -->
					<div class="vmsos-card">
						<?php
						$currentLiUrl = (string) get_option( 'vmsos_linkedin_page_url', 'https://www.linkedin.com/company/13230968/' );
						$currentLiOrgId = (string) get_option( 'vmsos_linkedin_org_id', '13230968' );
						$currentLiAdminUrl = 'https://www.linkedin.com/company/' . $currentLiOrgId . '/admin/dashboard/';
						?>
						<div class="vmsos-card-header">
							<div>
								<h2><?php esc_html_e( 'LinkedIn Company Page & Leads Gateway', 'vm-sales-os' ); ?></h2>
								<p><?php esc_html_e( 'Direct integration for your LinkedIn Company Page, Lead Gen Forms, webhooks, and Z-Agents mobile radar.', 'vm-sales-os' ); ?></p>
							</div>
							<div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
								<span class="vmsos-pill" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);">
									<span class="vmsos-pill-dot" style="background: #10b981;"></span>
									<?php echo esc_html( 'Company ID: #' . $currentLiOrgId ); ?>
								</span>
								<a href="<?php echo esc_url( $currentLiAdminUrl ); ?>" target="_blank" rel="noopener" class="button button-secondary" style="font-size: 11px;">
									<?php esc_html_e( 'Open LinkedIn Admin â†—', 'vm-sales-os' ); ?>
								</a>
							</div>
						</div>

						<div style="margin-bottom: 20px; padding: 16px; background: rgba(6, 182, 212, 0.08); border: 1px solid rgba(6, 182, 212, 0.25); border-radius: 8px;">
							<div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
								<div>
									<span style="font-size: 13px; font-weight: 700; color: #38bdf8; display: block; margin-bottom: 4px;">
										<?php esc_html_e( 'Your Live Inbound LinkedIn Webhook URL:', 'vm-sales-os' ); ?>
									</span>
									<code id="vmsos-linkedin-webhook-display" style="font-size: 13px; color: #fff; background: rgba(0,0,0,0.4); padding: 6px 10px; border-radius: 4px; display: inline-block;">
										<?php echo esc_html( rest_url( 'vmsos/v1/webhook/linkedin' ) ); ?>
									</code>
								</div>
								<button type="button" class="button button-secondary" id="vmsos-btn-copy-linkedin-webhook" onclick="vmsosCopyWebhook()">
									<span class="dashicons dashicons-clipboard" style="vertical-align: middle;"></span>
									<span id="vmsos-copy-status"><?php esc_html_e( 'Copy Webhook URL', 'vm-sales-os' ); ?></span>
								</button>
							</div>
							<p style="font-size: 12px; color: var(--vmsos-muted); margin: 8px 0 0 0;">
								<?php esc_html_e( 'Point your LinkedIn Lead Gen Form webhook, Zapier/Make scenario, or scraper to this URL. All incoming leads instantly ingest into CRM Contacts, create a Sales OS Deal, mirror to Fluent Forms, and trigger 1-tap call/WhatsApp inside the Z-Agents mobile app.', 'vm-sales-os' ); ?>
							</p>
						</div>

						<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
							<div class="vmsos-form-group">
								<label for="vmsos_linkedin_page_url"><?php esc_html_e( 'LinkedIn Company / Admin Page URL', 'vm-sales-os' ); ?></label>
								<input type="url" name="vmsos_linkedin_page_url" id="vmsos_linkedin_page_url" class="vmsos-input-modern" value="<?php echo esc_attr( $currentLiUrl ); ?>" placeholder="https://www.linkedin.com/company/13230968/admin/dashboard/" />
								<span style="font-size: 11px; color: var(--vmsos-muted); margin-top: 4px;">
									<?php esc_html_e( 'Official company URL or admin dashboard URL.', 'vm-sales-os' ); ?>
								</span>
							</div>
							<div class="vmsos-form-group">
								<label for="vmsos_linkedin_org_id"><?php esc_html_e( 'Company Organization ID (URN)', 'vm-sales-os' ); ?></label>
								<input type="text" name="vmsos_linkedin_org_id" id="vmsos_linkedin_org_id" class="vmsos-input-modern" value="<?php echo esc_attr( $currentLiOrgId ); ?>" placeholder="13230968" />
								<span style="font-size: 11px; color: var(--vmsos-muted); margin-top: 4px;">
									<?php esc_html_e( 'LinkedIn Organization ID (e.g. 13230968 for urn:li:organization:13230968)', 'vm-sales-os' ); ?>
								</span>
							</div>
							<div class="vmsos-form-group">
								<label for="vmsos_linkedin_webhook_secret"><?php esc_html_e( 'Webhook Security Secret / Token (Optional)', 'vm-sales-os' ); ?></label>
								<input type="text" name="vmsos_linkedin_webhook_secret" id="vmsos_linkedin_webhook_secret" class="vmsos-input-modern" value="<?php echo esc_attr( (string) get_option( 'vmsos_linkedin_webhook_secret', '' ) ); ?>" placeholder="Leave blank or set custom secret..." />
								<span style="font-size: 11px; color: var(--vmsos-muted); margin-top: 4px;">
									<?php esc_html_e( 'If set, incoming webhooks must provide header X-LinkedIn-Token: <secret> or ?token=<secret>', 'vm-sales-os' ); ?>
								</span>
							</div>
						</div>

						<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px;">
							<div style="background: rgba(255,255,255,0.03); border: 1px solid var(--vmsos-line); padding: 14px; border-radius: 8px;">
								<label style="display: flex; align-items: center; gap: 10px; cursor: pointer; color: var(--vmsos-text); font-weight: 600; margin-bottom: 6px;">
									<input type="checkbox" name="vmsos_linkedin_create_deal" value="1" <?php checked( (bool) get_option( 'vmsos_linkedin_create_deal', true ) ); ?> />
									<?php esc_html_e( 'Auto-create Deal in Sales OS Pipeline', 'vm-sales-os' ); ?>
								</label>
								<div class="vmsos-form-group" style="margin-top: 10px;">
									<label for="vmsos_linkedin_default_deal_value"><?php esc_html_e( 'Default Estimated Deal Value (₹ INR)', 'vm-sales-os' ); ?></label>
									<input type="number" name="vmsos_linkedin_default_deal_value" id="vmsos_linkedin_default_deal_value" class="vmsos-input-modern" value="<?php echo esc_attr( (string) get_option( 'vmsos_linkedin_default_deal_value', 25000 ) ); ?>" min="0" step="1000" />
								</div>
							</div>

							<div style="background: rgba(255,255,255,0.03); border: 1px solid var(--vmsos-line); padding: 14px; border-radius: 8px;">
								<label style="display: flex; align-items: center; gap: 10px; cursor: pointer; color: var(--vmsos-text); font-weight: 600; margin-bottom: 6px;">
									<input type="checkbox" name="vmsos_linkedin_sync_fluentform" value="1" <?php checked( (bool) get_option( 'vmsos_linkedin_sync_fluentform', true ) ); ?> />
									<?php esc_html_e( 'Mirror Inbound Leads to Fluent Forms Submissions', 'vm-sales-os' ); ?>
								</label>
								<div class="vmsos-form-group" style="margin-top: 10px;">
									<label for="vmsos_linkedin_target_form_id"><?php esc_html_e( 'Target Fluent Form to Mirror Into', 'vm-sales-os' ); ?></label>
									<?php
									global $wpdb;
									$ffForms = [];
									if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}fluentform_forms'" ) === "{$wpdb->prefix}fluentform_forms" ) {
										$ffForms = $wpdb->get_results( "SELECT id, title FROM {$wpdb->prefix}fluentform_forms", ARRAY_A ) ?: [];
									}
									$selectedFormId = (int) get_option( 'vmsos_linkedin_target_form_id', 1 );
									?>
									<select name="vmsos_linkedin_target_form_id" id="vmsos_linkedin_target_form_id" class="vmsos-select-modern">
										<?php if ( empty( $ffForms ) ) : ?>
											<option value="1">Form #1 (Default)</option>
										<?php else : ?>
											<?php foreach ( $ffForms as $f ) : ?>
												<option value="<?php echo esc_attr( (string) $f['id'] ); ?>" <?php selected( $selectedFormId, (int) $f['id'] ); ?>>
													Form #<?php echo esc_html( (string) $f['id'] ); ?>: <?php echo esc_html( (string) $f['title'] ); ?>
												</option>
											<?php endforeach; ?>
										<?php endif; ?>
									</select>
								</div>
							</div>
						</div>
					</div>

					<!-- Card 2: Interactive 1-Click LinkedIn Lead Importer -->
					<div class="vmsos-card">
						<div class="vmsos-card-header">
							<div>
								<h2><?php esc_html_e( 'Quick LinkedIn Lead Importer (1-Click Push to CRM & Z-Agents)', 'vm-sales-os' ); ?></h2>
								<p><?php esc_html_e( 'Quickly import a prospect from a LinkedIn connection, message, or lead gen response directly into your sales pipeline.', 'vm-sales-os' ); ?></p>
							</div>
						</div>

						<div id="vmsos-import-alert" style="display:none; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-weight: 600;"></div>

						<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px;">
							<div class="vmsos-form-group">
								<label for="li_import_name"><?php esc_html_e( 'Lead Full Name *', 'vm-sales-os' ); ?></label>
								<input type="text" id="li_import_name" class="vmsos-input-modern" placeholder="e.g. Vikram Sharma" required />
							</div>
							<div class="vmsos-form-group">
								<label for="li_import_phone"><?php esc_html_e( 'Phone / Mobile (Dialable) *', 'vm-sales-os' ); ?></label>
								<input type="text" id="li_import_phone" class="vmsos-input-modern" placeholder="+91 98110 00000" />
							</div>
							<div class="vmsos-form-group">
								<label for="li_import_email"><?php esc_html_e( 'Email Address', 'vm-sales-os' ); ?></label>
								<input type="email" id="li_import_email" class="vmsos-input-modern" placeholder="vikram@example.com" />
							</div>
						</div>

						<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-top: 14px;">
							<div class="vmsos-form-group">
								<label for="li_import_company"><?php esc_html_e( 'Company Name', 'vm-sales-os' ); ?></label>
								<input type="text" id="li_import_company" class="vmsos-input-modern" placeholder="e.g. TechCorp India" />
							</div>
							<div class="vmsos-form-group">
								<label for="li_import_headline"><?php esc_html_e( 'Headline / Designation', 'vm-sales-os' ); ?></label>
								<input type="text" id="li_import_headline" class="vmsos-input-modern" placeholder="e.g. VP of Marketing" />
							</div>
							<div class="vmsos-form-group">
								<label for="li_import_profile"><?php esc_html_e( 'LinkedIn Profile / Post URL', 'vm-sales-os' ); ?></label>
								<input type="url" id="li_import_profile" class="vmsos-input-modern" placeholder="https://linkedin.com/in/prospect" />
							</div>
						</div>

						<div class="vmsos-form-group" style="margin-top: 14px;">
							<label for="li_import_notes"><?php esc_html_e( 'Lead Requirement / Notes / Inquiry', 'vm-sales-os' ); ?></label>
							<textarea id="li_import_notes" rows="2" class="vmsos-textarea-modern" placeholder="e.g. Inquired about corporate event branding & web application development..."></textarea>
						</div>

						<div style="margin-top: 16px; display: flex; gap: 12px; align-items: center;">
							<button type="button" class="button button-primary" id="vmsos-btn-submit-import" onclick="vmsosSubmitLinkedInLead()">
								<span class="dashicons dashicons-upload" style="vertical-align: middle;"></span>
								<?php esc_html_e( '⚡ Import Lead to CRM & Push to Z-Agents', 'vm-sales-os' ); ?>
							</button>
							<span id="vmsos-import-spinner" class="spinner" style="float:none; margin:0;"></span>
						</div>
					</div>

					<!-- Card 3: Recent LinkedIn Leads Roster -->
					<div class="vmsos-card">
						<div class="vmsos-card-header">
							<div>
								<h2><?php esc_html_e( 'Recent Inbound LinkedIn Leads & Phone Directory', 'vm-sales-os' ); ?></h2>
								<p><?php esc_html_e( 'All leads synchronized across Sales OS CRM, Fluent Forms, and the Z-Agents mobile client with 1-tap call and WhatsApp.', 'vm-sales-os' ); ?></p>
							</div>
							<button type="button" class="button button-secondary" onclick="vmsosReloadLeadsTable()">
								<span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
								<?php esc_html_e( 'Refresh Leads', 'vm-sales-os' ); ?>
							</button>
						</div>

						<?php
						$repoInstance = $this->container->has( \VMStudio\SalesOS\Modules\LinkedInRadar\LinkedInRadarRepository::class )
							? $this->container->get( \VMStudio\SalesOS\Modules\LinkedInRadar\LinkedInRadarRepository::class )
							: null;
						$liLeads = $repoInstance ? $repoInstance->list() : [];
						?>

						<div style="overflow-x: auto;">
							<table class="wp-list-table widefat fixed striped" id="vmsos-linkedin-leads-table" style="background: rgba(11, 19, 39, 0.6); border: 1px solid var(--vmsos-line); color: #e2e8f0;">
								<thead>
									<tr style="background: #131c36;">
										<th style="color: #94a3b8; font-weight: 600; width: 22%;"><?php esc_html_e( 'Lead Prospect', 'vm-sales-os' ); ?></th>
										<th style="color: #94a3b8; font-weight: 600; width: 20%;"><?php esc_html_e( 'Company & Role', 'vm-sales-os' ); ?></th>
										<th style="color: #94a3b8; font-weight: 600; width: 25%;"><?php esc_html_e( 'Phone / WhatsApp / Email', 'vm-sales-os' ); ?></th>
										<th style="color: #94a3b8; font-weight: 600; width: 15%;"><?php esc_html_e( 'Status & Intent', 'vm-sales-os' ); ?></th>
										<th style="color: #94a3b8; font-weight: 600; width: 18%;"><?php esc_html_e( 'Mobile 1-Tap Action', 'vm-sales-os' ); ?></th>
									</tr>
								</thead>
								<tbody id="vmsos-linkedin-leads-tbody">
									<?php if ( empty( $liLeads ) ) : ?>
										<tr><td colspan="5" style="text-align: center; color: var(--vmsos-muted); padding: 24px;"><?php esc_html_e( 'No LinkedIn leads ingested yet. Send a test webhook or use the Quick Importer above.', 'vm-sales-os' ); ?></td></tr>
									<?php else : ?>
										<?php foreach ( $liLeads as $lead ) :
											$leadName    = esc_html( (string) ( $lead['poster_name'] ?? $lead['name'] ?? 'LinkedIn Lead' ) );
											$leadCompany = esc_html( (string) ( $lead['company'] ?? 'Direct Lead' ) );
											$leadTitle   = esc_html( (string) ( $lead['poster_headline'] ?? $lead['headline'] ?? '' ) );
											$leadPhone   = esc_attr( (string) ( $lead['phone'] ?? '' ) );
											$leadEmail   = esc_html( (string) ( $lead['email'] ?? '' ) );
											$leadUrl     = esc_url( (string) ( $lead['poster_profile_url'] ?? $lead['profile_url'] ?? '' ) );
											$intentScore = (int) ( $lead['intent_score'] ?? 85 );
											$cleanPhone  = preg_replace( '/[^0-9]/', '', $leadPhone );
											$waPhone     = ( strlen( $cleanPhone ) === 10 ) ? '91' . $cleanPhone : $cleanPhone;
										?>
										<tr>
											<td>
												<strong style="color: #fff; font-size: 14px;"><?php echo $leadName; ?></strong>
												<?php if ( $leadUrl ) : ?>
													<br><a href="<?php echo $leadUrl; ?>" target="_blank" rel="noopener" style="font-size: 11px; color: #38bdf8;">LinkedIn Profile ↗</a>
												<?php endif; ?>
											</td>
											<td>
												<strong style="color: #cbd5e1;"><?php echo $leadCompany; ?></strong>
												<?php if ( $leadTitle ) : ?>
													<br><span style="font-size: 11px; color: var(--vmsos-muted);"><?php echo $leadTitle; ?></span>
												<?php endif; ?>
											</td>
											<td>
												<?php if ( $leadPhone ) : ?>
													<div style="display: flex; gap: 6px; align-items: center; margin-bottom: 4px;">
														<span class="dashicons dashicons-phone" style="font-size: 14px; width: 14px; height: 14px; color: #10b981;"></span>
														<a href="tel:<?php echo $leadPhone; ?>" style="color: #10b981; font-weight: 600; font-size: 13px; text-decoration: none;"><?php echo esc_html( $leadPhone ); ?></a>
													</div>
												<?php endif; ?>
												<?php if ( $leadEmail ) : ?>
													<div style="display: flex; gap: 6px; align-items: center;">
														<span class="dashicons dashicons-email" style="font-size: 14px; width: 14px; height: 14px; color: #94a3b8;"></span>
														<a href="mailto:<?php echo esc_attr( $leadEmail ); ?>" style="color: #94a3b8; font-size: 12px;"><?php echo $leadEmail; ?></a>
													</div>
												<?php endif; ?>
											</td>
											<td>
												<span class="vmsos-pill vmsos-pill--green" style="font-size: 10px; margin-bottom: 4px; display: inline-block;">
													<?php echo esc_html( strtoupper( (string) ( $lead['status'] ?? 'NEW' ) ) ); ?>
												</span>
												<br><span style="font-size: 11px; color: var(--vmsos-muted);">Intent: <strong><?php echo $intentScore; ?>%</strong></span>
											</td>
											<td>
												<div style="display: flex; gap: 6px; flex-wrap: wrap;">
													<?php if ( $cleanPhone ) : ?>
														<a href="https://wa.me/<?php echo $waPhone; ?>" target="_blank" class="button button-small" style="background: #25D366; color: #fff; border-color: #25D366; display: inline-flex; align-items: center; gap: 4px;">
															<span class="dashicons dashicons-whatsapp" style="font-size: 13px; width: 13px; height: 13px;"></span> WA
														</a>
														<a href="tel:<?php echo $leadPhone; ?>" class="button button-small" style="background: #0ea5e9; color: #fff; border-color: #0ea5e9; display: inline-flex; align-items: center; gap: 4px;">
															<span class="dashicons dashicons-phone" style="font-size: 13px; width: 13px; height: 13px;"></span> Call
														</a>
													<?php endif; ?>
												</div>
											</td>
										</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>
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
		(function() {
			function initVmsosSettings() {
				// Tab Navigation Logic
				var tabBtns = document.querySelectorAll('#vmsos-settings-tabs .vmsos-tab-btn');
				var tabPanes = document.querySelectorAll('.vmsos-tab-content');

				tabBtns.forEach(function(btn) {
					btn.addEventListener('click', function(e) {
						e.preventDefault();
						tabBtns.forEach(function(b) { b.classList.remove('is-active'); });
						tabPanes.forEach(function(p) { p.classList.remove('is-active'); p.style.display = 'none'; });

						btn.classList.add('is-active');
						var targetId = btn.getAttribute('data-tab');
						var targetPane = document.getElementById(targetId);
						if (targetPane) {
							targetPane.classList.add('is-active');
							targetPane.style.display = 'block';
						}
					});
				});

				// LinkedIn Helper Handlers with Fallback for Insecure HTTP Contexts
				function vmsosFallbackCopy(text, cb) {
					var ta = document.createElement('textarea');
					ta.value = text;
					ta.style.position = 'fixed';
					ta.style.opacity = '0';
					document.body.appendChild(ta);
					ta.select();
					try {
						document.execCommand('copy');
						if (cb) cb();
					} catch (_) {}
					document.body.removeChild(ta);
				}

				window.vmsosCopyWebhook = function() {
					var code = document.getElementById('vmsos-linkedin-webhook-display');
					if (!code) return;
					var text = code.innerText.trim();
					function updateLabel() {
						var status = document.getElementById('vmsos-copy-status');
						if (status) {
							var orig = status.innerText;
							status.innerText = 'Copied to Clipboard!';
							setTimeout(function() { status.innerText = orig; }, 3000);
						}
					}
					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(text).then(updateLabel).catch(function() {
							vmsosFallbackCopy(text, updateLabel);
						});
					} else {
						vmsosFallbackCopy(text, updateLabel);
					}
				};

				window.vmsosSubmitLinkedInLead = function() {
					var name = document.getElementById('li_import_name').value.trim();
					var phone = document.getElementById('li_import_phone').value.trim();
					var email = document.getElementById('li_import_email').value.trim();
					var company = document.getElementById('li_import_company').value.trim();
					var headline = document.getElementById('li_import_headline').value.trim();
					var profile = document.getElementById('li_import_profile').value.trim();
					var notes = document.getElementById('li_import_notes').value.trim();

					var alertBox = document.getElementById('vmsos-import-alert');
					var spinner = document.getElementById('vmsos-import-spinner');
					var btn = document.getElementById('vmsos-btn-submit-import');

					if (!name) {
						alertBox.style.display = 'block';
						alertBox.style.background = 'rgba(239, 68, 68, 0.2)';
						alertBox.style.border = '1px solid #ef4444';
						alertBox.style.color = '#fca5a5';
						alertBox.innerText = 'Please enter at least the lead full name.';
						return;
					}

					btn.disabled = true;
					spinner.classList.add('is-active');

					fetch(vmsosAdmin.root + 'linkedin/import', {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': vmsosAdmin.nonce
						},
						body: JSON.stringify({
							name: name,
							phone: phone,
							email: email,
							company: company,
							headline: headline,
							profile_url: profile,
							notes: notes
						})
					})
					.then(function(res) { return res.json(); })
					.then(function(data) {
						btn.disabled = false;
						spinner.classList.remove('is-active');

						var payload = (data && data.data) ? data.data : data;
						if (payload && (payload.success || payload.lead || data.success || data.lead)) {
							alertBox.style.display = 'block';
							alertBox.style.background = 'rgba(16, 185, 129, 0.2)';
							alertBox.style.border = '1px solid #10b981';
							alertBox.style.color = '#6ee7b7';
							alertBox.innerText = '✓ Lead successfully imported! Pushed to CRM Contacts, Deal Pipeline, Fluent Forms, and Z-Agents mobile bridge.';

							document.getElementById('li_import_name').value = '';
							document.getElementById('li_import_phone').value = '';
							document.getElementById('li_import_email').value = '';
							document.getElementById('li_import_company').value = '';
							document.getElementById('li_import_headline').value = '';
							document.getElementById('li_import_profile').value = '';
							document.getElementById('li_import_notes').value = '';

							vmsosReloadLeadsTable();
						} else {
							alertBox.style.display = 'block';
							alertBox.style.background = 'rgba(239, 68, 68, 0.2)';
							alertBox.style.border = '1px solid #ef4444';
							alertBox.style.color = '#fca5a5';
							alertBox.innerText = (payload && payload.message) || data.message || 'Failed to import lead.';
						}
					})
					.catch(function(err) {
						btn.disabled = false;
						spinner.classList.remove('is-active');
						alertBox.style.display = 'block';
						alertBox.style.background = 'rgba(239, 68, 68, 0.2)';
						alertBox.style.border = '1px solid #ef4444';
						alertBox.style.color = '#fca5a5';
						alertBox.innerText = 'Network error importing lead: ' + err.message;
					});
				};

				window.vmsosReloadLeadsTable = function() {
					fetch(vmsosAdmin.root + 'linkedin/leads', {
						headers: { 'X-WP-Nonce': vmsosAdmin.nonce }
					})
					.then(function(res) { return res.json(); })
					.then(function(data) {
						var leads = (data && data.data && data.data.leads) ? data.data.leads : ((data && data.leads) ? data.leads : []);
						var tbody = document.getElementById('vmsos-linkedin-leads-tbody');
						if (!tbody) return;

						if (leads.length === 0) {
							tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--vmsos-muted); padding: 24px;">No LinkedIn leads found.</td></tr>';
							return;
						}

						function escapeHtml(text) {
							if (!text) return '';
							return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
						}

						var html = '';
						leads.forEach(function(lead) {
							var name = lead.name || lead.poster_name || 'LinkedIn Lead';
							var company = lead.company || 'Direct Lead';
							var headline = lead.headline || lead.poster_headline || '';
							var phone = lead.phone || '';
							var email = lead.email || '';
							var profile = lead.profile_url || lead.poster_profile_url || '';
							var score = lead.intent_score || 85;
							var status = (lead.status || 'new').toUpperCase();
							var cleanPhone = phone.replace(/[^0-9]/g, '');
							var waPhone = cleanPhone;
							if (waPhone.length === 10) { waPhone = '91' + waPhone; }

							html += '<tr>';
							html += '<td><strong style="color: #fff; font-size: 14px;">' + escapeHtml(name) + '</strong>';
							if (profile) {
								html += '<br><a href="' + escapeHtml(profile) + '" target="_blank" rel="noopener" style="font-size: 11px; color: #38bdf8;">LinkedIn Profile ↗</a>';
							}
							html += '</td>';

							html += '<td><strong style="color: #cbd5e1;">' + escapeHtml(company) + '</strong>';
							if (headline) {
								html += '<br><span style="font-size: 11px; color: var(--vmsos-muted);">' + escapeHtml(headline) + '</span>';
							}
							html += '</td>';

							html += '<td>';
							if (phone) {
								html += '<div style="display: flex; gap: 6px; align-items: center; margin-bottom: 4px;">';
								html += '<span class="dashicons dashicons-phone" style="font-size: 14px; width: 14px; height: 14px; color: #10b981;"></span>';
								html += '<a href="tel:' + escapeHtml(phone) + '" style="color: #10b981; font-weight: 600; font-size: 13px; text-decoration: none;">' + escapeHtml(phone) + '</a>';
								html += '</div>';
							}
							if (email) {
								html += '<div style="display: flex; gap: 6px; align-items: center;">';
								html += '<span class="dashicons dashicons-email" style="font-size: 14px; width: 14px; height: 14px; color: #94a3b8;"></span>';
								html += '<a href="mailto:' + escapeHtml(email) + '" style="color: #94a3b8; font-size: 12px;">' + escapeHtml(email) + '</a>';
								html += '</div>';
							}
							html += '</td>';

							html += '<td><span class="vmsos-pill vmsos-pill--green" style="font-size: 10px; margin-bottom: 4px; display: inline-block;">' + escapeHtml(status) + '</span>';
							html += '<br><span style="font-size: 11px; color: var(--vmsos-muted);">Intent: <strong>' + score + '%</strong></span></td>';

							html += '<td><div style="display: flex; gap: 6px; flex-wrap: wrap;">';
							if (cleanPhone) {
								html += '<a href="https://wa.me/' + waPhone + '" target="_blank" class="button button-small" style="background: #25D366; color: #fff; border-color: #25D366; display: inline-flex; align-items: center; gap: 4px;"><span class="dashicons dashicons-whatsapp" style="font-size: 13px; width: 13px; height: 13px;"></span> WA</a>';
								html += '<a href="tel:' + escapeHtml(phone) + '" class="button button-small" style="background: #0ea5e9; color: #fff; border-color: #0ea5e9; display: inline-flex; align-items: center; gap: 4px;"><span class="dashicons dashicons-phone" style="font-size: 13px; width: 13px; height: 13px;"></span> Call</a>';
							}
							html += '</div></td>';
							html += '</tr>';
						});

						tbody.innerHTML = html;
					});
				};

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
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', initVmsosSettings);
			} else {
				initVmsosSettings();
			}
		})();
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

		// LinkedIn Page & Leads Hub Settings.
		$rawLiUrl = trim( (string) ( $_POST['vmsos_linkedin_page_url'] ?? '' ) );
		$orgId    = sanitize_text_field( (string) wp_unslash( $_POST['vmsos_linkedin_org_id'] ?? '' ) );

		if ( preg_match( '#/company/([0-9a-zA-Z\-_]+)#i', $rawLiUrl, $m ) ) {
			$slug = $m[1];
			if ( ctype_digit( $slug ) && empty( $orgId ) ) {
				$orgId = $slug;
			}
			$cleanLiUrl = 'https://www.linkedin.com/company/' . $slug . '/';
		} else {
			$cleanLiUrl = esc_url_raw( $rawLiUrl );
		}

		if ( empty( $orgId ) && preg_match( '#\b(\d{7,10})\b#', $rawLiUrl, $m ) ) {
			$orgId = $m[1];
		}

		update_option( 'vmsos_linkedin_page_url', $cleanLiUrl ?: 'https://www.linkedin.com/company/13230968/', false );
		update_option( 'vmsos_linkedin_org_id', $orgId ?: '13230968', false );
		update_option( 'vmsos_linkedin_webhook_secret', sanitize_text_field( (string) wp_unslash( $_POST['vmsos_linkedin_webhook_secret'] ?? '' ) ), false );
		update_option( 'vmsos_linkedin_create_deal', ! empty( $_POST['vmsos_linkedin_create_deal'] ), false );
		update_option( 'vmsos_linkedin_default_deal_value', max( 0, (float) ( $_POST['vmsos_linkedin_default_deal_value'] ?? 25000 ) ), false );
		update_option( 'vmsos_linkedin_sync_fluentform', ! empty( $_POST['vmsos_linkedin_sync_fluentform'] ), false );
		update_option( 'vmsos_linkedin_target_form_id', max( 1, (int) ( $_POST['vmsos_linkedin_target_form_id'] ?? 1 ) ), false );

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
