<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\LeadGen;

use VMStudio\SalesOS\Admin\AdminArea;
use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Core\Module;
use VMStudio\SalesOS\Modules\CRM\ContactRepository;
use VMStudio\SalesOS\Modules\Sequences\SequenceRepository;
use VMStudio\SalesOS\Modules\Sequences\EnrollmentRepository;
use VMStudio\SalesOS\REST\RestRouter;
use VMStudio\SalesOS\Support\Vault;
use VMStudio\SalesOS\Support\Logger;

final class LeadGenModule extends Module {

	public static function slug(): string {
		return 'leadgen';
	}

	public static function label(): string {
		return __( 'Lead Finder & Google Business', 'vm-sales-os' );
	}

	public function register( Container $container ): void {
		$container->singleton( WebsiteEmailCrawler::class, static fn() => new WebsiteEmailCrawler() );

		$container->singleton(
			GooglePlacesClient::class,
			static fn( Container $c ) => new GooglePlacesClient(
				$c->get( Vault::class ),
				$c->get( WebsiteEmailCrawler::class )
			)
		);

		$container->singleton(
			FluentCrmBridge::class,
			static fn( Container $c ) => new FluentCrmBridge(
				$c->get( Logger::class )
			)
		);

		$container->singleton(
			LeadGenController::class,
			static fn( Container $c ) => new LeadGenController(
				$c->get( GooglePlacesClient::class ),
				$c->get( WebsiteEmailCrawler::class ),
				$c->get( FluentCrmBridge::class ),
				$c->get( ContactRepository::class ),
				$c->get( SequenceRepository::class ),
				$c->get( EnrollmentRepository::class ),
				$c->get( Vault::class )
			)
		);
	}

	public const DAILY_TICK = 'vmsos_leadgen_daily_tick';

	public function boot( Container $container ): void {
		$router = $container->get( RestRouter::class );
		$router->add( LeadGenController::class );

		// Register WordPress Admin AJAX handlers
		$container->get( LeadGenController::class )->bootAjax();

		// Schedule daily automated B2B lead generation via Google Places
		if ( ! wp_next_scheduled( self::DAILY_TICK ) ) {
			wp_schedule_event( time() + 300, 'daily', self::DAILY_TICK );
		}
		add_action( self::DAILY_TICK, fn() => $container->get( LeadGenController::class )->runScheduledDailyImport() );

		if ( is_admin() ) {
			add_action( 'admin_menu', [ $this, 'registerAdminMenu' ] );
		}
	}

	public function registerAdminMenu(): void {
		add_submenu_page(
			AdminArea::SLUG,
			__( 'Lead Finder (Google Maps)', 'vm-sales-os' ),
			__( 'Lead Finder', 'vm-sales-os' ),
			'vmsos_operate',
			'vmsos-lead-finder',
			[ $this, 'renderFinderPage' ],
			2
		);
	}

	public function renderFinderPage(): void {
		$container    = \VMStudio\SalesOS\Core\Plugin::instance()->container();
		$placesClient = $container->get( GooglePlacesClient::class );
		$fluentBridge = $container->get( FluentCrmBridge::class );
		$sequences    = $container->get( SequenceRepository::class );

		$isPlacesConfigured = $placesClient->isConfigured();
		$hasFluentCrm       = $fluentBridge->isActive();
		$fluentLists        = $fluentBridge->getLists();
		$fluentTags         = $fluentBridge->getTags();
		$allSequences       = $sequences->all();

		$defaultCategories = [
			'Dental Clinics & Dentists'     => 'dentist',
			'Real Estate Agencies & Brokers' => 'real_estate_agency',
			'Digital & Creative Agencies'    => 'marketing_agency',
			'Restaurants, Cafes & Bistros'  => 'restaurant',
			'Law Firms & Legal Advocates'   => 'lawyer',
			'Medical Clinics & Doctors'     => 'doctor',
			'Fitness Centers & Gyms'        => 'gym',
			'Accountants & Tax Consultants' => 'accounting',
			'Hotels & Luxury Resorts'       => 'hotel',
			'Beauty Salons & Spas'          => 'spa',
			'Software & Tech Companies'     => 'software_company',
			'Travel & Tour Operators'       => 'travel_agency',
		];

		$nonce = wp_create_nonce( 'vmsos_leadgen_nonce' );
		?>
		<div class="wrap vmsos-wrap vmsos-lead-finder">
			<header class="vmsos-finder-header">
				<div>
					<h1>
						<span class="dashicons dashicons-location-alt" style="font-size: 32px; width: 32px; height: 32px; vertical-align: middle; color: #3b82f6;"></span>
						<?php esc_html_e( 'Google Maps B2B Lead Finder & Marketing Sync', 'vm-sales-os' ); ?>
					</h1>
					<p class="description">
						<?php esc_html_e( 'Extract verified business contacts, phone numbers, ratings, websites, and emails from Google Maps — and enroll qualified leads instantly into FluentCRM marketing campaigns.', 'vm-sales-os' ); ?>
					</p>
				</div>
				<div class="vmsos-finder-status-badges">
					<?php if ( $hasFluentCrm ) : ?>
						<span class="vmsos-badge vmsos-badge-success">
							<span class="dashicons dashicons-yes-alt"></span> FluentCRM Active
						</span>
					<?php else : ?>
						<span class="vmsos-badge vmsos-badge-warning" title="<?php esc_attr_e( 'Install or activate FluentCRM for automated marketing campaigns', 'vm-sales-os' ); ?>">
							FluentCRM Inactive
						</span>
					<?php endif; ?>

					<?php if ( $isPlacesConfigured ) : ?>
						<span class="vmsos-badge vmsos-badge-success" id="vmsos-key-status-badge">
							<span class="dashicons dashicons-yes-alt"></span> Places API Connected
						</span>
					<?php else : ?>
						<span class="vmsos-badge vmsos-badge-danger" id="vmsos-key-status-badge">
							Places API Key Needed
						</span>
					<?php endif; ?>
				</div>
			</header>

			<!-- Quick API Key Configuration Banner (shown if missing or toggleable) -->
			<div class="vmsos-card vmsos-api-card" id="vmsos-api-card" style="<?php echo $isPlacesConfigured ? 'display:none;' : ''; ?>">
				<div class="vmsos-api-card-inner">
					<div style="flex: 1;">
						<h3 style="margin-top: 0; display: flex; align-items: center; gap: 6px;">
							<span class="dashicons dashicons-admin-network" style="color: #f59e0b;"></span>
							<?php esc_html_e( 'Google Places API Key Setup', 'vm-sales-os' ); ?>
						</h3>
						<p style="margin: 0 0 10px 0; font-size: 13px; color: #64748b;">
							<?php esc_html_e( 'Enter your Google Maps / Places API key below. Needs the "Places API (New)" enabled in Google Cloud Console with Text Search permissions.', 'vm-sales-os' ); ?>
						</p>
						<div style="display: flex; gap: 8px; max-width: 550px;">
							<input type="password" id="vmsos-places-api-key-input" class="regular-text" placeholder="AIzaSy..." style="flex: 1;" />
							<button type="button" class="button button-primary" id="vmsos-btn-save-places-key">
								<?php esc_html_e( 'Save Key', 'vm-sales-os' ); ?>
							</button>
						</div>
						<div id="vmsos-key-save-feedback" style="font-size: 12px; margin-top: 6px; font-weight: 500;"></div>
					</div>
					<div>
						<a href="https://console.cloud.google.com/apis/library/places.googleapis.com" target="_blank" rel="noopener" class="button button-secondary">
							<?php esc_html_e( 'Get Google API Key ↗', 'vm-sales-os' ); ?>
						</a>
					</div>
				</div>
			</div>

			<!-- Search & Control Panel -->
			<div class="vmsos-card vmsos-finder-controls">
				<form id="vmsos-lead-search-form" onsubmit="return false;">
					<div class="vmsos-grid-row">
						<!-- Category -->
						<div class="vmsos-col">
							<label for="vmsos-category-select"><strong><?php esc_html_e( 'Business Category', 'vm-sales-os' ); ?></strong></label>
							<select id="vmsos-category-select" class="widefat">
								<option value=""><?php esc_html_e( '-- Choose Industry Preset --', 'vm-sales-os' ); ?></option>
								<?php foreach ( $defaultCategories as $label => $val ) : ?>
									<option value="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<input type="text" id="vmsos-custom-category" class="widefat" placeholder="<?php esc_attr_e( 'Or custom category / niche keyword...', 'vm-sales-os' ); ?>" style="margin-top: 6px;" />
						</div>

						<!-- Location / City -->
						<div class="vmsos-col">
							<label for="vmsos-target-city"><strong><?php esc_html_e( 'Target City / Location', 'vm-sales-os' ); ?></strong></label>
							<input type="text" id="vmsos-target-city" class="widefat" placeholder="<?php esc_attr_e( 'e.g. Mumbai, London, New York, Dubai, Austin', 'vm-sales-os' ); ?>" value="Mumbai" required />
							<span class="description" style="font-size: 11px;"><?php esc_html_e( 'City, region, or neighborhood to prospect.', 'vm-sales-os' ); ?></span>
						</div>

						<!-- Volume & Enrichment -->
						<div class="vmsos-col">
							<label for="vmsos-max-results"><strong><?php esc_html_e( 'Max Leads', 'vm-sales-os' ); ?></strong></label>
							<select id="vmsos-max-results" class="widefat">
								<option value="10">10 <?php esc_html_e( 'Leads (Fastest)', 'vm-sales-os' ); ?></option>
								<option value="20" selected>20 <?php esc_html_e( 'Leads (Standard)', 'vm-sales-os' ); ?></option>
								<option value="40">40 <?php esc_html_e( 'Leads (2 Pages)', 'vm-sales-os' ); ?></option>
								<option value="60">60 <?php esc_html_e( 'Leads (Deep Sweep)', 'vm-sales-os' ); ?></option>
							</select>
							<label style="display: flex; align-items: center; gap: 6px; margin-top: 8px; font-size: 12px; cursor: pointer;">
								<input type="checkbox" id="vmsos-crawl-emails" value="1" checked />
								<span><?php esc_html_e( 'Crawl business websites for verified emails & social profiles', 'vm-sales-os' ); ?></span>
							</label>
						</div>

						<!-- Action -->
						<div class="vmsos-col vmsos-col-action" style="display: flex; flex-direction: column; justify-content: flex-end;">
							<button type="button" class="button button-primary button-hero" id="vmsos-btn-search-leads" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 6px;">
								<span class="dashicons dashicons-search"></span>
								<span class="btn-text"><?php esc_html_e( 'Find Leads on Maps', 'vm-sales-os' ); ?></span>
							</button>
							<div style="text-align: right; margin-top: 4px;">
								<a href="#" id="vmsos-toggle-key-settings" style="font-size: 11px; text-decoration: none; color: #64748b;">
									⚙ <?php esc_html_e( 'API Key Settings', 'vm-sales-os' ); ?>
								</a>
							</div>
						</div>
					</div>
				</form>
			</div>

			<!-- Campaign & Marketing Sync Bar -->
			<div class="vmsos-card vmsos-sync-bar" id="vmsos-sync-bar" style="display: none;">
				<div class="vmsos-sync-bar-grid">
					<div>
						<label for="vmsos-fluentcrm-list"><strong><?php esc_html_e( 'FluentCRM Target List', 'vm-sales-os' ); ?></strong></label>
						<div style="display: flex; gap: 6px; margin-top: 4px;">
							<select id="vmsos-fluentcrm-list" class="widefat" style="max-width: 250px;">
								<?php if ( empty( $fluentLists ) ) : ?>
									<option value="0"><?php esc_html_e( '-- No Lists Yet (Create One) --', 'vm-sales-os' ); ?></option>
								<?php else : ?>
									<option value="0"><?php esc_html_e( '-- Select Target List --', 'vm-sales-os' ); ?></option>
									<?php foreach ( $fluentLists as $l ) : ?>
										<option value="<?php echo esc_attr( (string) $l['id'] ); ?>"><?php echo esc_html( $l['title'] ); ?></option>
									<?php endforeach; ?>
								<?php endif; ?>
							</select>
							<button type="button" class="button" id="vmsos-btn-create-list" title="<?php esc_attr_e( 'Create new list in FluentCRM', 'vm-sales-os' ); ?>">
								+ <?php esc_html_e( 'New List', 'vm-sales-os' ); ?>
							</button>
						</div>
					</div>

					<div>
						<label for="vmsos-sync-tags"><strong><?php esc_html_e( 'CRM Tags (Comma separated)', 'vm-sales-os' ); ?></strong></label>
						<input type="text" id="vmsos-sync-tags" class="widefat" style="margin-top: 4px;" value="google-maps-lead, b2b-outreach" />
					</div>

					<div>
						<label for="vmsos-salesos-sequence"><strong><?php esc_html_e( 'VM Sales OS Outreach Sequence', 'vm-sales-os' ); ?></strong></label>
						<select id="vmsos-salesos-sequence" class="widefat" style="margin-top: 4px;">
							<option value="0"><?php esc_html_e( '-- None (Sync to CRM only) --', 'vm-sales-os' ); ?></option>
							<?php foreach ( $allSequences as $seq ) : ?>
								<option value="<?php echo esc_attr( (string) $seq['id'] ); ?>"><?php echo esc_html( $seq['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div style="display: flex; align-items: flex-end; gap: 8px;">
						<button type="button" class="button button-primary" id="vmsos-btn-import-selected">
							<span class="dashicons dashicons-upload" style="vertical-align: middle;"></span>
							<?php esc_html_e( 'Sync Selected Leads', 'vm-sales-os' ); ?> (<span id="vmsos-selected-count">0</span>)
						</button>
						<button type="button" class="button" id="vmsos-btn-export-csv" title="<?php esc_attr_e( 'Export all leads to CSV spreadsheet', 'vm-sales-os' ); ?>">
							<span class="dashicons dashicons-download" style="vertical-align: middle;"></span> CSV
						</button>
					</div>
				</div>
				<div id="vmsos-import-feedback" style="margin-top: 10px; font-size: 13px; font-weight: 500;"></div>
			</div>

			<!-- Live Results Container -->
			<div class="vmsos-card vmsos-results-card" id="vmsos-results-card" style="display: none;">
				<div class="vmsos-results-header">
					<div style="display: flex; align-items: center; gap: 12px;">
						<h2 style="margin: 0;">
							<?php esc_html_e( 'Discovered Businesses', 'vm-sales-os' ); ?>: <span id="vmsos-results-count" style="color: #3b82f6;">0</span>
						</h2>
						<span id="vmsos-results-sub" style="color: #64748b; font-size: 13px;"></span>
					</div>
					<div style="display: flex; gap: 10px; align-items: center;">
						<label style="font-size: 12px; cursor: pointer;">
							<input type="checkbox" id="vmsos-filter-only-emails" />
							<?php esc_html_e( 'Show only with email', 'vm-sales-os' ); ?>
						</label>
						<label style="font-size: 12px; cursor: pointer;">
							<input type="checkbox" id="vmsos-filter-only-phones" />
							<?php esc_html_e( 'Show only with phone', 'vm-sales-os' ); ?>
						</label>
					</div>
				</div>

				<div class="vmsos-table-responsive">
					<table class="wp-list-table widefat fixed striped vmsos-leads-table">
						<thead>
							<tr>
								<th scope="col" class="check-column" style="width: 32px; padding: 10px;">
									<input type="checkbox" id="vmsos-select-all" checked />
								</th>
								<th scope="col" style="width: 25%;"><?php esc_html_e( 'Business Name & Category', 'vm-sales-os' ); ?></th>
								<th scope="col" style="width: 14%;"><?php esc_html_e( 'Phone / WhatsApp', 'vm-sales-os' ); ?></th>
								<th scope="col" style="width: 22%;"><?php esc_html_e( 'Verified Email & Website', 'vm-sales-os' ); ?></th>
								<th scope="col" style="width: 11%;"><?php esc_html_e( 'Rating & Reviews', 'vm-sales-os' ); ?></th>
								<th scope="col" style="width: 18%;"><?php esc_html_e( 'Address / Location', 'vm-sales-os' ); ?></th>
								<th scope="col" style="width: 10%;"><?php esc_html_e( 'CRM Status', 'vm-sales-os' ); ?></th>
							</tr>
						</thead>
						<tbody id="vmsos-leads-tbody">
							<!-- Populated via JavaScript -->
						</tbody>
					</table>
				</div>

				<!-- Pagination & Load More -->
				<div class="vmsos-results-footer" id="vmsos-results-footer" style="display: none; padding: 16px; text-align: center; border-top: 1px solid #e2e8f0;">
					<button type="button" class="button" id="vmsos-btn-load-more">
						<?php esc_html_e( 'Load Next Page of Results from Google Maps', 'vm-sales-os' ); ?> ↓
					</button>
				</div>
			</div>

			<!-- Empty / Initial Placeholder -->
			<div class="vmsos-card vmsos-placeholder-card" id="vmsos-placeholder-card">
				<div style="text-align: center; padding: 48px 20px;">
					<span class="dashicons dashicons-location" style="font-size: 54px; width: 54px; height: 54px; color: #94a3b8;"></span>
					<h3 style="margin: 12px 0 6px 0; color: #1e293b;"><?php esc_html_e( 'Ready to Discover B2B Leads', 'vm-sales-os' ); ?></h3>
					<p style="max-width: 520px; margin: 0 auto 18px auto; color: #64748b; font-size: 14px;">
						<?php esc_html_e( 'Select an industry category and location above to fetch live businesses from Google Maps. The crawler extracts phone numbers, websites, public contact emails, and social media handles automatically.', 'vm-sales-os' ); ?>
					</p>
					<div class="vmsos-quick-pills">
						<button type="button" class="button button-small vmsos-quick-pill" data-cat="Dental Clinics & Dentists" data-city="Mumbai">Dental in Mumbai</button>
						<button type="button" class="button button-small vmsos-quick-pill" data-cat="Digital & Creative Agencies" data-city="London">Digital Agencies in London</button>
						<button type="button" class="button button-small vmsos-quick-pill" data-cat="Real Estate Agencies & Brokers" data-city="Dubai">Real Estate in Dubai</button>
						<button type="button" class="button button-small vmsos-quick-pill" data-cat="Restaurants, Cafes & Bistros" data-city="New York">Restaurants in New York</button>
					</div>
				</div>
			</div>
		</div>

		<!-- UI Styles -->
		<style>
		.vmsos-lead-finder {
			max-width: 1300px;
			margin: 20px 20px 40px 0;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
		}
		.vmsos-finder-header {
			display: flex;
			justify-content: space-between;
			align-items: flex-start;
			margin-bottom: 20px;
			flex-wrap: wrap;
			gap: 12px;
		}
		.vmsos-finder-header h1 {
			font-size: 24px;
			font-weight: 700;
			color: #0f172a;
			margin: 0 0 6px 0;
		}
		.vmsos-finder-status-badges {
			display: flex;
			gap: 8px;
			align-items: center;
		}
		.vmsos-badge {
			display: inline-flex;
			align-items: center;
			gap: 4px;
			padding: 4px 10px;
			border-radius: 9999px;
			font-size: 12px;
			font-weight: 600;
		}
		.vmsos-badge-success { background: #dcfce7; color: #15803d; }
		.vmsos-badge-warning { background: #fef3c7; color: #b45309; }
		.vmsos-badge-danger { background: #fee2e2; color: #b91c1c; }
		.vmsos-badge-info { background: #dbeafe; color: #1d4ed8; }

		.vmsos-card {
			background: #fff;
			border: 1px solid #e2e8f0;
			border-radius: 10px;
			padding: 20px;
			margin-bottom: 20px;
			box-shadow: 0 1px 3px rgba(0,0,0,0.04);
		}
		.vmsos-api-card {
			background: #fffbeb;
			border-color: #fde68a;
		}
		.vmsos-api-card-inner {
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 20px;
			flex-wrap: wrap;
		}
		.vmsos-grid-row {
			display: grid;
			grid-template-columns: 1.5fr 1.3fr 1.4fr 1.2fr;
			gap: 16px;
			align-items: flex-start;
		}
		@media (max-width: 960px) {
			.vmsos-grid-row { grid-template-columns: 1fr; }
		}
		.vmsos-sync-bar {
			background: #f8fafc;
			border-left: 4px solid #3b82f6;
		}
		.vmsos-sync-bar-grid {
			display: grid;
			grid-template-columns: 1.5fr 1.2fr 1.2fr auto;
			gap: 16px;
			align-items: flex-end;
		}
		@media (max-width: 960px) {
			.vmsos-sync-bar-grid { grid-template-columns: 1fr; }
		}
		.vmsos-table-responsive {
			overflow-x: auto;
		}
		.vmsos-leads-table td {
			vertical-align: middle;
			padding: 10px;
		}
		.vmsos-biz-title {
			font-weight: 600;
			color: #0f172a;
			font-size: 14px;
		}
		.vmsos-biz-cat {
			font-size: 11px;
			color: #64748b;
			margin-top: 2px;
		}
		.vmsos-star {
			color: #f59e0b;
			font-weight: bold;
		}
		.vmsos-email-pill {
			display: inline-block;
			background: #f1f5f9;
			padding: 2px 8px;
			border-radius: 4px;
			font-family: monospace;
			font-size: 12px;
			color: #0f172a;
			word-break: break-all;
		}
		.vmsos-quick-pills {
			display: flex;
			justify-content: center;
			gap: 8px;
			flex-wrap: wrap;
		}
		.vmsos-quick-pill {
			background: #f8fafc !important;
			border-color: #cbd5e1 !important;
			color: #334155 !important;
		}
		.vmsos-quick-pill:hover {
			background: #eff6ff !important;
			border-color: #3b82f6 !important;
			color: #1d4ed8 !important;
		}
		.vmsos-rotating {
			animation: vmsosSpin 1s linear infinite;
		}
		@keyframes vmsosSpin {
			from { transform: rotate(0deg); }
			to { transform: rotate(360deg); }
		}
		</style>

		<!-- Interactive Logic -->
		<script>
		(function() {
			var state = {
				leads: [],
				nextPageToken: '',
				query: '',
				max: 20,
				crawlEmail: true,
				nonce: '<?php echo esc_js( $nonce ); ?>',
				ajaxUrl: '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>'
			};

			document.addEventListener('DOMContentLoaded', function() {
				var searchBtn = document.getElementById('vmsos-btn-search-leads');
				var catSelect = document.getElementById('vmsos-category-select');
				var catCustom = document.getElementById('vmsos-custom-category');
				var cityInput = document.getElementById('vmsos-target-city');
				var maxSelect = document.getElementById('vmsos-max-results');
				var crawlCheck = document.getElementById('vmsos-crawl-emails');
				var selectAll = document.getElementById('vmsos-select-all');
				var tbody = document.getElementById('vmsos-leads-tbody');
				var resultsCard = document.getElementById('vmsos-results-card');
				var placeholderCard = document.getElementById('vmsos-placeholder-card');
				var syncBar = document.getElementById('vmsos-sync-bar');
				var resultsCount = document.getElementById('vmsos-results-count');
				var resultsSub = document.getElementById('vmsos-results-sub');
				var selectedCountEl = document.getElementById('vmsos-selected-count');
				var importBtn = document.getElementById('vmsos-btn-import-selected');
				var importFeedback = document.getElementById('vmsos-import-feedback');
				var exportCsvBtn = document.getElementById('vmsos-btn-export-csv');
				var loadMoreBtn = document.getElementById('vmsos-btn-load-more');
				var loadMoreFooter = document.getElementById('vmsos-results-footer');
				var toggleKeyLink = document.getElementById('vmsos-toggle-key-settings');
				var apiCard = document.getElementById('vmsos-api-card');
				var saveKeyBtn = document.getElementById('vmsos-btn-save-places-key');
				var keyInput = document.getElementById('vmsos-places-api-key-input');
				var keyFeedback = document.getElementById('vmsos-key-save-feedback');
				var createListBtn = document.getElementById('vmsos-btn-create-list');
				var fluentListSelect = document.getElementById('vmsos-fluentcrm-list');

				// Quick API Key Toggle
				if (toggleKeyLink && apiCard) {
					toggleKeyLink.addEventListener('click', function(e) {
						e.preventDefault();
						apiCard.style.display = (apiCard.style.display === 'none') ? 'block' : 'none';
					});
				}

				// Save API Key
				if (saveKeyBtn) {
					saveKeyBtn.addEventListener('click', function() {
						var key = keyInput.value.trim();
						if (!key) {
							alert('Please enter a valid API key.');
							return;
						}
						saveKeyBtn.disabled = true;
						keyFeedback.textContent = 'Saving key...';
						keyFeedback.style.color = '#64748b';

						var fd = new FormData();
						fd.append('action', 'vmsos_leadgen_save_key');
						fd.append('api_key', key);
						fd.append('_wpnonce', state.nonce);

						fetch(state.ajaxUrl, { method: 'POST', body: fd })
							.then(function(r) { return r.json(); })
							.then(function(res) {
								saveKeyBtn.disabled = false;
								if (res.success) {
									keyFeedback.textContent = '✓ ' + res.data.message;
									keyFeedback.style.color = '#10b981';
									var badge = document.getElementById('vmsos-key-status-badge');
									if (badge) {
										badge.className = 'vmsos-badge vmsos-badge-success';
										badge.innerHTML = '<span class="dashicons dashicons-yes-alt"></span> Places API Connected';
									}
									setTimeout(function() { apiCard.style.display = 'none'; }, 2000);
								} else {
									keyFeedback.textContent = (res.data && res.data.message) || 'Error saving key';
									keyFeedback.style.color = '#ef4444';
								}
							})
							.catch(function() {
								saveKeyBtn.disabled = false;
								keyFeedback.textContent = 'Network error saving key.';
								keyFeedback.style.color = '#ef4444';
							});
					});
				}

				// Create FluentCRM List On the Fly
				if (createListBtn) {
					createListBtn.addEventListener('click', function() {
						var title = prompt('Enter title for new FluentCRM list:', 'Google Maps Prospects');
						if (!title || !title.trim()) return;

						createListBtn.disabled = true;
						var fd = new FormData();
						fd.append('action', 'vmsos_leadgen_create_list');
						fd.append('title', title.trim());
						fd.append('_wpnonce', state.nonce);

						fetch(state.ajaxUrl, { method: 'POST', body: fd })
							.then(function(r) { return r.json(); })
							.then(function(res) {
								createListBtn.disabled = false;
								if (res.success && res.data && res.data.id) {
									var opt = document.createElement('option');
									opt.value = res.data.id;
									opt.textContent = res.data.title;
									opt.selected = true;
									fluentListSelect.appendChild(opt);
									alert('Created list "' + res.data.title + '" in FluentCRM!');
								} else {
									alert((res.data && res.data.message) || 'Failed to create list.');
								}
							})
							.catch(function() {
								createListBtn.disabled = false;
								alert('Network error communicating with server.');
							});
					});
				}

				// Quick Pill Click Handler
				document.querySelectorAll('.vmsos-quick-pill').forEach(function(pill) {
					pill.addEventListener('click', function() {
						var cat = pill.getAttribute('data-cat');
						var city = pill.getAttribute('data-city');
						if (catSelect) catSelect.value = cat;
						if (catCustom) catCustom.value = '';
						if (cityInput) cityInput.value = city;
						performSearch(false);
					});
				});

				// Perform Search Function
				function performSearch(isLoadMore) {
					var cat = catCustom.value.trim() || catSelect.value;
					var city = cityInput.value.trim();

					if (!cat && !city) {
						alert('Please select or type a business category and city.');
						return;
					}

					var fullQuery = (cat ? cat : 'Businesses') + (city ? ' in ' + city : '');
					state.query = fullQuery;
					state.max = parseInt(maxSelect.value, 10) || 20;
					state.crawlEmail = crawlCheck.checked;

					searchBtn.disabled = true;
					var icon = searchBtn.querySelector('.dashicons');
					var textSpan = searchBtn.querySelector('.btn-text');
					if (icon) icon.className = 'dashicons dashicons-update vmsos-rotating';
					if (textSpan) textSpan.textContent = 'Searching Maps & Crawling Websites...';

					var fd = new FormData();
					fd.append('action', 'vmsos_leadgen_search');
					fd.append('query', fullQuery);
					fd.append('max', state.max);
					fd.append('crawl_email', state.crawlEmail ? '1' : '0');
					if (isLoadMore && state.nextPageToken) {
						fd.append('page_token', state.nextPageToken);
					}
					fd.append('_wpnonce', state.nonce);

					fetch(state.ajaxUrl, { method: 'POST', body: fd })
						.then(function(r) { return r.json(); })
						.then(function(res) {
							searchBtn.disabled = false;
							if (icon) icon.className = 'dashicons dashicons-search';
							if (textSpan) textSpan.textContent = 'Find Leads on Maps';

							if (res.success && res.data && res.data.places) {
								if (isLoadMore) {
									state.leads = state.leads.concat(res.data.places);
								} else {
									state.leads = res.data.places;
								}
								state.nextPageToken = res.data.next_page_token || '';

								renderResults();
							} else {
								var msg = (res.data && res.data.message) || 'Search failed.';
								alert('Error: ' + msg);
								if (msg.indexOf('API key') !== -1 && apiCard) {
									apiCard.style.display = 'block';
								}
							}
						})
						.catch(function(err) {
							searchBtn.disabled = false;
							if (icon) icon.className = 'dashicons dashicons-search';
							if (textSpan) textSpan.textContent = 'Find Leads on Maps';
							alert('Network error while querying Google Places API.');
						});
				}

				if (searchBtn) {
					searchBtn.addEventListener('click', function() {
						state.nextPageToken = '';
						performSearch(false);
					});
				}

				if (loadMoreBtn) {
					loadMoreBtn.addEventListener('click', function() {
						performSearch(true);
					});
				}

				// Render Lead Rows
				function renderResults() {
					if (!state.leads.length) {
						if (placeholderCard) placeholderCard.style.display = 'block';
						if (resultsCard) resultsCard.style.display = 'none';
						if (syncBar) syncBar.style.display = 'none';
						return;
					}

					if (placeholderCard) placeholderCard.style.display = 'none';
					if (resultsCard) resultsCard.style.display = 'block';
					if (syncBar) syncBar.style.display = 'block';

					resultsCount.textContent = state.leads.length;
					resultsSub.textContent = 'Query: "' + state.query + '"';

					if (state.nextPageToken && loadMoreFooter) {
						loadMoreFooter.style.display = 'block';
					} else if (loadMoreFooter) {
						loadMoreFooter.style.display = 'none';
					}

					tbody.innerHTML = '';

					state.leads.forEach(function(lead, index) {
						var tr = document.createElement('tr');
						tr.setAttribute('data-index', index);

						var waLink = '';
						if (lead.phone) {
							var waDigits = lead.phone.replace(/\D/g, '');
							waLink = '<a href="https://wa.me/' + waDigits + '" target="_blank" rel="noopener" style="margin-left: 6px; color: #10b981; text-decoration: none;" title="Open WhatsApp chat">📱 WA</a>';
						}

						var callLink = lead.phone ? '<a href="tel:' + lead.phone + '" style="font-weight: 500; color: #0f172a; text-decoration: none;">' + (lead.phone_display || lead.phone) + '</a>' : '<span style="color:#94a3b8;">No phone</span>';

						var webLink = lead.website ? '<div style="margin-top: 4px;"><a href="' + lead.website + '" target="_blank" rel="noopener" style="font-size: 11px; color: #3b82f6;">🌐 ' + lead.website.replace(/^https?:\/\//i, '').replace(/\/$/, '').substring(0, 30) + '</a></div>' : '';

						var emailHtml = '';
						if (lead.email) {
							emailHtml = '<span class="vmsos-email-pill">✉ ' + lead.email + '</span>';
						} else if (lead.website) {
							emailHtml = '<button type="button" class="button button-small vmsos-btn-crawl-one" data-index="' + index + '" style="font-size: 11px;">🔍 Crawl Email</button>';
						} else {
							emailHtml = '<span style="color:#94a3b8; font-size: 11px;">No website</span>';
						}

						var ratingHtml = '—';
						if (lead.rating) {
							ratingHtml = '<span class="vmsos-star">★ ' + lead.rating.toFixed(1) + '</span> <span style="color:#64748b; font-size: 11px;">(' + (lead.reviews_count || 0) + ')</span>';
						}

						var statusBadge = '';
						if (lead.exists_in_fluentcrm) {
							statusBadge = '<span class="vmsos-badge vmsos-badge-success">In FluentCRM</span>';
						} else if (lead.exists_in_crm) {
							statusBadge = '<span class="vmsos-badge vmsos-badge-info">In Sales OS</span>';
						} else {
							statusBadge = '<span class="vmsos-badge" style="background:#f1f5f9; color:#64748b;">Ready</span>';
						}

						var mapsLink = lead.google_maps_url ? ' <a href="' + lead.google_maps_url + '" target="_blank" rel="noopener" style="color: #64748b; text-decoration: none;" title="Open in Google Maps">↗</a>' : '';

						tr.innerHTML = [
							'<th scope="row" class="check-column" style="padding: 10px;">',
								'<input type="checkbox" class="vmsos-lead-cb" data-index="' + index + '" checked />',
							'</th>',
							'<td>',
								'<div class="vmsos-biz-title">' + escapeHtml(lead.name) + mapsLink + '</div>',
								'<div class="vmsos-biz-cat">' + escapeHtml(lead.category || 'Business') + '</div>',
							'</td>',
							'<td>',
								callLink,
								waLink,
							'</td>',
							'<td>',
								emailHtml,
								webLink,
							'</td>',
							'<td>' + ratingHtml + '</td>',
							'<td><span style="font-size: 12px; color: #475569;">' + escapeHtml(lead.address || '—') + '</span></td>',
							'<td>' + statusBadge + '</td>'
						].join('');

						tbody.appendChild(tr);
					});

					bindTableEvents();
					updateSelectedCount();
				}

				function bindTableEvents() {
					// Single crawl button
					document.querySelectorAll('.vmsos-btn-crawl-one').forEach(function(btn) {
						btn.addEventListener('click', function() {
							var idx = parseInt(btn.getAttribute('data-index'), 10);
							var lead = state.leads[idx];
							if (!lead || !lead.website) return;

							btn.disabled = true;
							btn.textContent = 'Crawling...';

							var fd = new FormData();
							fd.append('action', 'vmsos_leadgen_crawl_email');
							fd.append('url', lead.website);
							fd.append('_wpnonce', state.nonce);

							fetch(state.ajaxUrl, { method: 'POST', body: fd })
								.then(function(r) { return r.json(); })
								.then(function(res) {
									if (res.success && res.data && res.data.primary_email) {
										lead.email = res.data.primary_email;
										lead.social = res.data.social;
										renderResults();
									} else {
										btn.textContent = 'No email found';
									}
								})
								.catch(function() {
									btn.disabled = false;
									btn.textContent = 'Retry Crawl';
								});
						});
					});

					// Checkbox change
					document.querySelectorAll('.vmsos-lead-cb').forEach(function(cb) {
						cb.addEventListener('change', updateSelectedCount);
					});
				}

				if (selectAll) {
					selectAll.addEventListener('change', function() {
						var checked = selectAll.checked;
						document.querySelectorAll('.vmsos-lead-cb').forEach(function(cb) {
							cb.checked = checked;
						});
						updateSelectedCount();
					});
				}

				function updateSelectedCount() {
					var checkedCount = document.querySelectorAll('.vmsos-lead-cb:checked').length;
					if (selectedCountEl) selectedCountEl.textContent = checkedCount;
					if (importBtn) importBtn.disabled = (checkedCount === 0);
				}

				// Import Selected Leads
				if (importBtn) {
					importBtn.addEventListener('click', function() {
						var selected = [];
						document.querySelectorAll('.vmsos-lead-cb:checked').forEach(function(cb) {
							var idx = parseInt(cb.getAttribute('data-index'), 10);
							if (state.leads[idx]) {
								selected.push(state.leads[idx]);
							}
						});

						if (!selected.length) {
							alert('Please select at least one lead to import.');
							return;
						}

						var listId = parseInt(fluentListSelect ? fluentListSelect.value : '0', 10);
						var tags = document.getElementById('vmsos-sync-tags') ? document.getElementById('vmsos-sync-tags').value : '';
						var seqId = parseInt(document.getElementById('vmsos-salesos-sequence') ? document.getElementById('vmsos-salesos-sequence').value : '0', 10);

						importBtn.disabled = true;
						importFeedback.textContent = 'Syncing ' + selected.length + ' leads to CRM & campaigns...';
						importFeedback.style.color = '#3b82f6';

						var fd = new FormData();
						fd.append('action', 'vmsos_leadgen_import');
						fd.append('leads', JSON.stringify(selected));
						if (listId > 0) {
							fd.append('list_ids[]', listId);
						}
						fd.append('tags', tags);
						if (seqId > 0) {
							fd.append('sequence_id', seqId);
						}
						fd.append('_wpnonce', state.nonce);

						fetch(state.ajaxUrl, { method: 'POST', body: fd })
							.then(function(r) { return r.json(); })
							.then(function(res) {
								importBtn.disabled = false;
								if (res.success && res.data) {
									var d = res.data;
									importFeedback.innerHTML = '✓ <strong>Import Successful!</strong> ' +
										d.imported_local + ' saved to Sales OS CRM, ' +
										d.synced_fluentcrm + ' synced to FluentCRM' +
										(d.enrolled_campaign > 0 ? (', ' + d.enrolled_campaign + ' enrolled in campaign') : '') + '.';
									importFeedback.style.color = '#10b981';

									// Mark imported in local state
									selected.forEach(function(s) {
										s.exists_in_crm = true;
										if (d.synced_fluentcrm > 0 && s.email) {
											s.exists_in_fluentcrm = true;
										}
									});
									renderResults();
								} else {
									importFeedback.textContent = (res.data && res.data.message) || 'Import failed.';
									importFeedback.style.color = '#ef4444';
								}
							})
							.catch(function() {
								importBtn.disabled = false;
								importFeedback.textContent = 'Network error during import.';
								importFeedback.style.color = '#ef4444';
							});
					});
				}

				// Export CSV
				if (exportCsvBtn) {
					exportCsvBtn.addEventListener('click', function() {
						if (!state.leads.length) {
							alert('No leads to export.');
							return;
						}

						var form = document.createElement('form');
						form.method = 'POST';
						form.action = state.ajaxUrl;
						form.target = '_blank';

						var actionInput = document.createElement('input');
						actionInput.type = 'hidden';
						actionInput.name = 'action';
						actionInput.value = 'vmsos_leadgen_export_csv';
						form.appendChild(actionInput);

						var nonceInput = document.createElement('input');
						nonceInput.type = 'hidden';
						nonceInput.name = '_wpnonce';
						nonceInput.value = state.nonce;
						form.appendChild(nonceInput);

						var leadsInput = document.createElement('input');
						leadsInput.type = 'hidden';
						leadsInput.name = 'leads';
						leadsInput.value = JSON.stringify(state.leads);
						form.appendChild(leadsInput);

						document.body.appendChild(form);
						form.submit();
						document.body.removeChild(form);
					});
				}

				// Filters (Emails / Phones)
				var filterEmail = document.getElementById('vmsos-filter-only-emails');
				var filterPhone = document.getElementById('vmsos-filter-only-phones');

				function applyFilters() {
					var rows = tbody.querySelectorAll('tr');
					rows.forEach(function(r) {
						var idx = parseInt(r.getAttribute('data-index'), 10);
						var lead = state.leads[idx];
						if (!lead) return;

						var show = true;
						if (filterEmail && filterEmail.checked && !lead.email) {
							show = false;
						}
						if (filterPhone && filterPhone.checked && !lead.phone) {
							show = false;
						}
						r.style.display = show ? '' : 'none';
					});
					updateSelectedCount();
				}

				if (filterEmail) filterEmail.addEventListener('change', applyFilters);
				if (filterPhone) filterPhone.addEventListener('change', applyFilters);

				function escapeHtml(str) {
					if (!str) return '';
					return String(str)
						.replace(/&/g, '&amp;')
						.replace(/</g, '&lt;')
						.replace(/>/g, '&gt;')
						.replace(/"/g, '&quot;')
						.replace(/'/g, '&#039;');
				}
			});
		})();
		</script>
		<?php
	}
}
