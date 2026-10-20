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
		?>
		<div class="wrap vmsos-wrap vmsos-settings">
			<h1><?php esc_html_e( 'VM Sales OS settings', 'vm-sales-os' ); ?></h1>

			<?php if ( isset( $_GET['vmsos_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings updated successfully.', 'vm-sales-os' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vmsos_save_settings" />
				<?php wp_nonce_field( 'vmsos_settings' ); ?>

				<h2><?php esc_html_e( 'Universal Industry Blueprint', 'vm-sales-os' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Select an industry archetype to instantly pre-configure AI sales discovery behavior, qualifying criteria, knowledge search, and catalog quoting for your exact domain.', 'vm-sales-os' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vmsos_apply_blueprint"><?php esc_html_e( 'Active Blueprint', 'vm-sales-os' ); ?></label></th>
						<td>
							<select name="vmsos_apply_blueprint" id="vmsos_apply_blueprint" style="max-width: 600px;">
								<option value="none"><?php esc_html_e( '-- Keep Current Prompts & Settings --', 'vm-sales-os' ); ?></option>
								<?php foreach ( $blueprints as $slug => $bp ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $activeBlueprint, $slug ); ?>>
										<?php echo esc_html( ( $bp['label'] ?? $slug ) . ' — ' . ( $bp['description'] ?? '' ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Selecting a blueprint and clicking "Save changes" loads optimal sales prompts, tone rules, and business context.', 'vm-sales-os' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'AI providers', 'vm-sales-os' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Calls run through this chain in order. If one fails, the next takes over.', 'vm-sales-os' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vmsos_ai_chain"><?php esc_html_e( 'Failover order', 'vm-sales-os' ); ?></label></th>
						<td>
							<input name="vmsos_ai_chain" id="vmsos_ai_chain" class="regular-text" value="<?php echo esc_attr( $chain ); ?>" />
							<p class="description"><?php esc_html_e( 'Comma separated: aipuffer, openai, anthropic, gemini', 'vm-sales-os' ); ?></p>
						</td>
					</tr>
					<?php foreach ( $this->providers->all() as $id => $provider ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $provider->label() ); ?></th>
							<td>
								<input
									type="password"
									name="vmsos_key_<?php echo esc_attr( $id ); ?>"
									class="regular-text"
									autocomplete="new-password"
									placeholder="<?php echo esc_attr( $this->vault->hint( 'ai.' . $id . '.key' ) ?: __( 'API key', 'vm-sales-os' ) ); ?>"
								/>
								<input
									type="url"
									name="vmsos_base_<?php echo esc_attr( $id ); ?>"
									class="regular-text"
									value="<?php echo esc_attr( (string) get_option( 'vmsos_ai_' . $id . '_base_url', '' ) ); ?>"
									placeholder="<?php esc_attr_e( 'Base URL (optional)', 'vm-sales-os' ); ?>"
								/>
								<input
									type="text"
									name="vmsos_model_<?php echo esc_attr( $id ); ?>"
									class="regular-text"
									value="<?php echo esc_attr( (string) get_option( 'vmsos_ai_' . $id . '_model', '' ) ); ?>"
									placeholder="<?php esc_attr_e( 'Default model (optional)', 'vm-sales-os' ); ?>"
								/>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2><?php esc_html_e( 'Assistant', 'vm-sales-os' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vmsos_agent_name"><?php esc_html_e( 'Assistant name', 'vm-sales-os' ); ?></label></th>
						<td><input name="vmsos_agent_name" id="vmsos_agent_name" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'vmsos_agent_name', 'Ava' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="vmsos_business_context"><?php esc_html_e( 'What the business does', 'vm-sales-os' ); ?></label></th>
						<td>
							<textarea name="vmsos_business_context" id="vmsos_business_context" rows="5" class="large-text"><?php echo esc_textarea( (string) get_option( 'vmsos_business_context', '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Services, pricing rules, delivery times, anything the assistant must never guess at.', 'vm-sales-os' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Chat widget', 'vm-sales-os' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="vmsos_widget_enabled" value="1" <?php checked( (bool) get_option( 'vmsos_widget_enabled', true ) ); ?> />
								<?php esc_html_e( 'Show the widget on the site', 'vm-sales-os' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Email and sequences', 'vm-sales-os' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vmsos_sender_name"><?php esc_html_e( 'Sender name', 'vm-sales-os' ); ?></label></th>
						<td><input name="vmsos_sender_name" id="vmsos_sender_name" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'vmsos_sender_name', get_bloginfo( 'name' ) ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="vmsos_sender_email"><?php esc_html_e( 'Sender email', 'vm-sales-os' ); ?></label></th>
						<td>
							<input type="email" name="vmsos_sender_email" id="vmsos_sender_email" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'vmsos_sender_email', get_option( 'admin_email' ) ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Use an address on your own domain with SPF and DKIM set up, or sequence mail will land in spam.', 'vm-sales-os' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vmsos_notify_email"><?php esc_html_e( 'Handover alerts to', 'vm-sales-os' ); ?></label></th>
						<td><input type="email" name="vmsos_notify_email" id="vmsos_notify_email" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'vmsos_notify_email', get_option( 'admin_email' ) ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sending rules', 'vm-sales-os' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="vmsos_require_consent" value="1" <?php checked( (bool) get_option( 'vmsos_require_consent', true ) ); ?> />
								<?php esc_html_e( 'Only email contacts who ticked a consent box', 'vm-sales-os' ); ?>
							</label><br />
							<label>
								<input type="checkbox" name="vmsos_track_opens" value="1" <?php checked( (bool) get_option( 'vmsos_track_opens', true ) ); ?> />
								<?php esc_html_e( 'Track opens', 'vm-sales-os' ); ?>
							</label><br />
							<label>
								<input type="checkbox" name="vmsos_track_clicks" value="1" <?php checked( (bool) get_option( 'vmsos_track_clicks', true ) ); ?> />
								<?php esc_html_e( 'Track link clicks', 'vm-sales-os' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Send governor', 'vm-sales-os' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Quiet hours', 'vm-sales-os' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="vmsos_send_window_enabled" value="1" <?php checked( (bool) get_option( 'vmsos_send_window_enabled', false ) ); ?> />
								<?php esc_html_e( 'Only send marketing messages inside a time window', 'vm-sales-os' ); ?>
							</label>
							<p>
								<input name="vmsos_send_window" class="small-text" value="<?php echo esc_attr( (string) get_option( 'vmsos_send_window', '09:00-21:00' ) ); ?>" />
								<span class="description"><?php esc_html_e( 'HH:MM-HH:MM in site time. Overnight windows like 20:00-06:00 work.', 'vm-sales-os' ); ?></span>
							</p>
							<p class="description"><?php esc_html_e( 'Conversational replies are never delayed by this — only sequences and campaigns.', 'vm-sales-os' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Daily caps', 'vm-sales-os' ); ?></th>
						<td>
							<label>
								<?php esc_html_e( 'Email', 'vm-sales-os' ); ?>
								<input type="number" min="0" name="vmsos_daily_cap_email" class="small-text" value="<?php echo esc_attr( (string) get_option( 'vmsos_daily_cap_email', 500 ) ); ?>" />
							</label>
							&nbsp;
							<label>
								<?php esc_html_e( 'WhatsApp', 'vm-sales-os' ); ?>
								<input type="number" min="0" name="vmsos_daily_cap_whatsapp" class="small-text" value="<?php echo esc_attr( (string) get_option( 'vmsos_daily_cap_whatsapp', 200 ) ); ?>" />
							</label>
							<p class="description"><?php esc_html_e( '0 means no limit. Caps protect your sending reputation more than they limit your reach.', 'vm-sales-os' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vmsos_memory_every"><?php esc_html_e( 'Refresh lead memory every', 'vm-sales-os' ); ?></label></th>
						<td>
							<input type="number" min="2" max="20" name="vmsos_memory_every" id="vmsos_memory_every" class="small-text" value="<?php echo esc_attr( (string) get_option( 'vmsos_memory_every', 4 ) ); ?>" />
							<span class="description"><?php esc_html_e( 'assistant replies. Lower is fresher and more expensive.', 'vm-sales-os' ); ?></span>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'WhatsApp', 'vm-sales-os' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vmsos_whatsapp_provider"><?php esc_html_e( 'WhatsApp Engine', 'vm-sales-os' ); ?></label></th>
						<td>
							<?php $waProvider = (string) get_option( 'vmsos_whatsapp_provider', 'meta' ); ?>
							<select name="vmsos_whatsapp_provider" id="vmsos_whatsapp_provider">
								<option value="meta" <?php selected( $waProvider, 'meta' ); ?>><?php esc_html_e( 'Meta WhatsApp Cloud API', 'vm-sales-os' ); ?></option>
								<option value="evolution" <?php selected( $waProvider, 'evolution' ); ?>><?php esc_html_e( 'Evolution API (Self-Hosted on VPS)', 'vm-sales-os' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Choose between direct Meta Graph API or local Evolution API instance.', 'vm-sales-os' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vmsos_evolution_api_url"><?php esc_html_e( 'Evolution API URL', 'vm-sales-os' ); ?></label></th>
						<td>
							<input type="url" name="vmsos_evolution_api_url" id="vmsos_evolution_api_url" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'vmsos_evolution_api_url', 'http://127.0.0.1:18080' ) ); ?>" placeholder="http://127.0.0.1:18080 or https://wa.vmstudio.digital" />
							<p class="description"><?php esc_html_e( 'Base URL for your self-hosted Evolution API server.', 'vm-sales-os' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vmsos_evolution_instance"><?php esc_html_e( 'Evolution Instance Name', 'vm-sales-os' ); ?></label></th>
						<td>
							<input name="vmsos_evolution_instance" id="vmsos_evolution_instance" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'vmsos_evolution_instance', 'mynearby' ) ); ?>" placeholder="e.g. mynearby, TripCosmos.co" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vmsos_evolution_api_key"><?php esc_html_e( 'Evolution Global API Key', 'vm-sales-os' ); ?></label></th>
						<td>
							<input type="password" name="vmsos_evolution_api_key" id="vmsos_evolution_api_key" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $this->vault->hint( 'evolution_api_key' ) ?: 'AUTHENTICATION_API_KEY' ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Evolution Webhook URL:', 'vm-sales-os' ); ?>
								<code><?php echo esc_html( rest_url( 'vmsos/v1/channels/evolution' ) ); ?></code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vmsos_whatsapp_phone_id"><?php esc_html_e( 'Phone number ID (Meta)', 'vm-sales-os' ); ?></label></th>
						<td><input name="vmsos_whatsapp_phone_id" id="vmsos_whatsapp_phone_id" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'vmsos_whatsapp_phone_id', '' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="vmsos_whatsapp_token"><?php esc_html_e( 'Access token (Meta)', 'vm-sales-os' ); ?></label></th>
						<td>
							<input type="password" name="vmsos_whatsapp_token" id="vmsos_whatsapp_token" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $this->vault->hint( 'whatsapp_token' ) ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vmsos_whatsapp_verify_token"><?php esc_html_e( 'Webhook verify token (Meta)', 'vm-sales-os' ); ?></label></th>
						<td>
							<input type="password" name="vmsos_whatsapp_verify_token" id="vmsos_whatsapp_verify_token" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $this->vault->hint( 'whatsapp_verify_token' ) ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vmsos_whatsapp_app_secret"><?php esc_html_e( 'App secret (Meta)', 'vm-sales-os' ); ?></label></th>
						<td>
							<input type="password" name="vmsos_whatsapp_app_secret" id="vmsos_whatsapp_app_secret" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $this->vault->hint( 'whatsapp_app_secret' ) ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Meta Webhook URL:', 'vm-sales-os' ); ?>
								<code><?php echo esc_html( rest_url( 'vmsos/v1/channels/whatsapp' ) ); ?></code>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Voice Telephony (Vapi / Twilio)', 'vm-sales-os' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Voice calls', 'vm-sales-os' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="vmsos_voice_enabled" value="1" <?php checked( (bool) get_option( 'vmsos_voice_enabled', false ) ); ?> />
								<?php esc_html_e( 'Enable AI Voice Outbound Discovery & Live Telephony', 'vm-sales-os' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vmsos_voice_api_key"><?php esc_html_e( 'Vapi API key', 'vm-sales-os' ); ?></label></th>
						<td>
							<input type="password" name="vmsos_voice_api_key" id="vmsos_voice_api_key" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $this->vault->hint( 'voice_api_key' ) ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vmsos_voice_assistant_id"><?php esc_html_e( 'Assistant ID', 'vm-sales-os' ); ?></label></th>
						<td><input name="vmsos_voice_assistant_id" id="vmsos_voice_assistant_id" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'vmsos_voice_assistant_id', '' ) ); ?>" placeholder="e.g. 550e8400-e29b-41d4-a716-446655440000" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="vmsos_voice_phone_number_id"><?php esc_html_e( 'Phone number ID', 'vm-sales-os' ); ?></label></th>
						<td><input name="vmsos_voice_phone_number_id" id="vmsos_voice_phone_number_id" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'vmsos_voice_phone_number_id', '' ) ); ?>" placeholder="e.g. 883c0722-1d54-469b-8919-4a0b2d49925e" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="vmsos_voice_webhook_secret"><?php esc_html_e( 'Webhook Secret', 'vm-sales-os' ); ?></label></th>
						<td>
							<input type="password" name="vmsos_voice_webhook_secret" id="vmsos_voice_webhook_secret" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'vmsos_voice_webhook_secret', '' ) ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Webhook URL for Vapi/Retell end-of-call status & transcripts:', 'vm-sales-os' ); ?>
								<code><?php echo esc_html( rest_url( 'vmsos/v1/channels/voice/webhook' ) ); ?></code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vmsos_voice_disclosure"><?php esc_html_e( 'First Message / Disclosure', 'vm-sales-os' ); ?></label></th>
						<td>
							<textarea name="vmsos_voice_disclosure" id="vmsos_voice_disclosure" rows="3" class="large-text"><?php echo esc_textarea( (string) get_option( 'vmsos_voice_disclosure', 'Hello, this is an automated sales discovery call regarding your recent inquiry.' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'AI consent notice spoken as the opening greeting on outbound calls.', 'vm-sales-os' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Mobile App Bridge (Z-Agents / Superfone)', 'vm-sales-os' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Provides backward compatibility and native REST connectivity for the Superfone and Z-Agents mobile apps.', 'vm-sales-os' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vmsos_mobile_api_key"><?php esc_html_e( 'Mobile Bridge API Key', 'vm-sales-os' ); ?></label></th>
						<td>
							<input type="password" name="vmsos_mobile_api_key" id="vmsos_mobile_api_key" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $this->vault->hint( 'mobile.api_key' ) ?: __( 'Set shared mobile API key', 'vm-sales-os' ) ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Shared key checked by ApiKeyGuard via header:', 'vm-sales-os' ); ?>
								<code>X-VMAI-API-Key: &lt;key&gt;</code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Endpoint Base URL', 'vm-sales-os' ); ?></th>
						<td>
							<code><?php echo esc_html( rest_url( 'vmai/v1' ) ); ?></code>
							<p class="description">
								<?php esc_html_e( 'Active endpoints: /leads, /dashboard, /inbox/conversations, /inbox/reply, /call/log, /call/summary, /call/ghost-notes, /followups', 'vm-sales-os' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save changes', 'vm-sales-os' ) ); ?>
			</form>
		</div>
		<?php
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
		update_option( 'vmsos_business_context', sanitize_textarea_field( (string) wp_unslash( $_POST['vmsos_business_context'] ?? '' ) ), false );
		update_option( 'vmsos_widget_enabled', ! empty( $_POST['vmsos_widget_enabled'] ), false );

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
		foreach ( [ 'whatsapp_token', 'whatsapp_verify_token', 'whatsapp_app_secret', 'voice_api_key' ] as $secret ) {
			$value = isset( $_POST[ 'vmsos_' . $secret ] ) ? trim( (string) wp_unslash( $_POST[ 'vmsos_' . $secret ] ) ) : '';
			if ( '' !== $value ) {
				$this->vault->put( $secret, $value );
			}
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
