<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\LeadGen;

use VMStudio\SalesOS\Modules\CRM\ContactRepository;
use VMStudio\SalesOS\Modules\Sequences\SequenceRepository;
use VMStudio\SalesOS\Modules\Sequences\EnrollmentRepository;
use VMStudio\SalesOS\REST\AbstractController;
use VMStudio\SalesOS\Support\Vault;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Controller handling Google Places lead discovery, email crawling,
 * and bidirectional sync into FluentCRM and VM Sales OS.
 */
final class LeadGenController extends AbstractController {

	public function __construct(
		private GooglePlacesClient $placesClient,
		private WebsiteEmailCrawler $crawler,
		private FluentCrmBridge $fluentBridge,
		private ContactRepository $contacts,
		private SequenceRepository $sequences,
		private EnrollmentRepository $enrollments,
		private Vault $vault
	) {}

	public function register(): void {
		$auth = fn() => $this->canOperate();

		register_rest_route(
			self::NAMESPACE,
			'/leadgen/search',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'restSearch' ],
				'permission_callback' => $auth,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/leadgen/crawl',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'restCrawl' ],
				'permission_callback' => $auth,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/leadgen/import',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'restImport' ],
				'permission_callback' => $auth,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/leadgen/fluentcrm/lists',
			[
				[ 'methods' => 'GET', 'callback' => [ $this, 'restGetLists' ], 'permission_callback' => $auth ],
				[ 'methods' => 'POST', 'callback' => [ $this, 'restCreateList' ], 'permission_callback' => $auth ],
			]
		);
	}

	/**
	 * Register WordPress Admin AJAX handlers during module boot.
	 */
	public function bootAjax(): void {
		add_action( 'wp_ajax_vmsos_leadgen_search', [ $this, 'ajaxSearch' ] );
		add_action( 'wp_ajax_vmsos_leadgen_crawl_email', [ $this, 'ajaxCrawlEmail' ] );
		add_action( 'wp_ajax_vmsos_leadgen_import', [ $this, 'ajaxImport' ] );
		add_action( 'wp_ajax_vmsos_leadgen_create_list', [ $this, 'ajaxCreateList' ] );
		add_action( 'wp_ajax_vmsos_leadgen_save_key', [ $this, 'ajaxSaveKey' ] );
		add_action( 'wp_ajax_vmsos_leadgen_export_csv', [ $this, 'ajaxExportCsv' ] );
	}

	public function restSearch( WP_REST_Request $request ): WP_REST_Response {
		$query      = sanitize_text_field( (string) $request->get_param( 'query' ) );
		$max        = max( 1, min( 60, (int) ( $request->get_param( 'max' ) ?: 20 ) ) );
		$crawlEmail = ! empty( $request->get_param( 'crawl_email' ) );
		$pageToken  = sanitize_text_field( (string) $request->get_param( 'page_token' ) );

		$res = $this->placesClient->search( $query, $max, $crawlEmail, $pageToken );

		if ( isset( $res['error'] ) ) {
			return $this->error( (string) $res['error'], 400 );
		}

		$places = $this->enrichWithExistingStatus( (array) ( $res['places'] ?? [] ) );
		return $this->ok( [
			'places'          => $places,
			'next_page_token' => $res['next_page_token'] ?? '',
			'total'           => count( $places ),
		] );
	}

	public function restCrawl( WP_REST_Request $request ): WP_REST_Response {
		$url = esc_url_raw( (string) $request->get_param( 'url' ) );
		if ( '' === $url ) {
			return $this->error( __( 'Website URL is required.', 'vm-sales-os' ), 400 );
		}

		$res = $this->crawler->crawl( $url, 4 );
		return $this->ok( $res );
	}

	public function restImport( WP_REST_Request $request ): WP_REST_Response {
		$leads      = (array) $request->get_param( 'leads' );
		$listIds    = array_map( 'intval', (array) ( $request->get_param( 'list_ids' ) ?: [] ) );
		$tags       = array_filter( array_map( 'sanitize_text_field', (array) ( $request->get_param( 'tags' ) ?: [] ) ) );
		$sequenceId = (int) $request->get_param( 'sequence_id' );
		$status     = sanitize_key( (string) ( $request->get_param( 'status' ) ?: 'subscribed' ) );

		$result = $this->processImport( $leads, $listIds, $tags, $sequenceId, $status );
		return $this->ok( $result );
	}

	public function restGetLists(): WP_REST_Response {
		return $this->ok( [
			'active' => $this->fluentBridge->isActive(),
			'lists'  => $this->fluentBridge->getLists(),
			'tags'   => $this->fluentBridge->getTags(),
		] );
	}

	public function restCreateList( WP_REST_Request $request ): WP_REST_Response {
		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		if ( '' === $title ) {
			return $this->error( __( 'List title is required.', 'vm-sales-os' ), 400 );
		}

		$id = $this->fluentBridge->createList( $title );
		if ( false === $id ) {
			return $this->error( __( 'Could not create FluentCRM list. Ensure FluentCRM is installed.', 'vm-sales-os' ), 500 );
		}

		return $this->ok( [ 'id' => $id, 'title' => $title ] );
	}

	// --------------------------------------------------------------------------
	// AJAX Handlers for WP-Admin
	// --------------------------------------------------------------------------

	public function ajaxSearch(): void {
		$this->verifyAdminAjax();

		$query      = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['query'] ) ) : '';
		$max        = isset( $_POST['max'] ) ? max( 1, min( 60, (int) $_POST['max'] ) ) : 20;
		$crawlEmail = ! empty( $_POST['crawl_email'] );
		$pageToken  = isset( $_POST['page_token'] ) ? sanitize_text_field( (string) $_POST['page_token'] ) : '';

		$res = $this->placesClient->search( $query, $max, $crawlEmail, $pageToken );

		if ( isset( $res['error'] ) ) {
			wp_send_json_error( [ 'message' => (string) $res['error'] ], 400 );
		}

		$places = $this->enrichWithExistingStatus( (array) ( $res['places'] ?? [] ) );
		wp_send_json_success( [
			'places'          => $places,
			'next_page_token' => $res['next_page_token'] ?? '',
			'total'           => count( $places ),
		] );
	}

	public function ajaxCrawlEmail(): void {
		$this->verifyAdminAjax();

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['url'] ) ) : '';
		if ( '' === $url ) {
			wp_send_json_error( [ 'message' => __( 'Website URL is required.', 'vm-sales-os' ) ], 400 );
		}

		$data = $this->crawler->crawl( $url, 4 );
		wp_send_json_success( $data );
	}

	public function ajaxImport(): void {
		$this->verifyAdminAjax();

		$rawLeads   = isset( $_POST['leads'] ) ? wp_unslash( (string) $_POST['leads'] ) : '[]';
		$leads      = json_decode( $rawLeads, true );
		if ( ! is_array( $leads ) || empty( $leads ) ) {
			wp_send_json_error( [ 'message' => __( 'No leads provided for import.', 'vm-sales-os' ) ], 400 );
		}

		$listIds    = isset( $_POST['list_ids'] ) ? array_map( 'intval', (array) $_POST['list_ids'] ) : [];
		$tagsRaw    = isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['tags'] ) ) : '';
		$tags       = array_filter( array_map( 'trim', explode( ',', $tagsRaw ) ) );
		$sequenceId = isset( $_POST['sequence_id'] ) ? (int) $_POST['sequence_id'] : 0;
		$status     = isset( $_POST['status'] ) ? sanitize_key( (string) $_POST['status'] ) : 'subscribed';

		$res = $this->processImport( $leads, $listIds, $tags, $sequenceId, $status );
		wp_send_json_success( $res );
	}

	public function ajaxCreateList(): void {
		$this->verifyAdminAjax();

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '';
		if ( '' === $title ) {
			wp_send_json_error( [ 'message' => __( 'List title is required.', 'vm-sales-os' ) ], 400 );
		}

		$id = $this->fluentBridge->createList( $title );
		if ( false === $id ) {
			wp_send_json_error( [ 'message' => __( 'Failed to create list in FluentCRM.', 'vm-sales-os' ) ], 500 );
		}

		wp_send_json_success( [ 'id' => $id, 'title' => $title ] );
	}

	public function ajaxSaveKey(): void {
		$this->verifyAdminAjax();

		$key = isset( $_POST['api_key'] ) ? trim( (string) wp_unslash( $_POST['api_key'] ) ) : '';
		if ( '' !== $key ) {
			$this->vault->put( 'google_places_api_key', $key );
			update_option( 'vmsos_google_places_api_key', $key, false );
			wp_send_json_success( [ 'message' => __( 'Google Places API key saved to secure vault.', 'vm-sales-os' ) ] );
		}

		wp_send_json_error( [ 'message' => __( 'Key cannot be empty.', 'vm-sales-os' ) ], 400 );
	}

	public function ajaxExportCsv(): void {
		$this->verifyAdminAjax();

		$rawLeads = isset( $_POST['leads'] ) ? wp_unslash( (string) $_POST['leads'] ) : '[]';
		$leads    = json_decode( $rawLeads, true );
		if ( ! is_array( $leads ) || empty( $leads ) ) {
			wp_die( esc_html__( 'No leads to export.', 'vm-sales-os' ) );
		}

		$filename = 'vmsos-leads-' . gmdate( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		// BOM for UTF-8 Excel support
		fputs( $output, "\xEF\xBB\xBF" );

		// Header Row
		fputcsv( $output, [
			'Business Name',
			'Category',
			'Phone (E.164)',
			'Display Phone',
			'Email',
			'Website',
			'Rating',
			'Reviews Count',
			'Address',
			'Google Maps URL',
			'Place ID',
		] );

		foreach ( $leads as $lead ) {
			fputcsv( $output, [
				$lead['name'] ?? '',
				$lead['category'] ?? '',
				$lead['phone'] ?? '',
				$lead['phone_display'] ?? '',
				$lead['email'] ?? '',
				$lead['website'] ?? '',
				$lead['rating'] ?? '',
				$lead['reviews_count'] ?? '',
				$lead['address'] ?? '',
				$lead['google_maps_url'] ?? '',
				$lead['place_id'] ?? '',
			] );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Process batch lead import into local VM Sales OS CRM & FluentCRM.
	 *
	 * @param array<int, array<string, mixed>> $leads
	 * @param array<int, int> $listIds
	 * @param array<int, string> $tags
	 * @param int $sequenceId
	 * @param string $status
	 * @return array{imported_local: int, synced_fluentcrm: int, enrolled_campaign: int, total: int}
	 */
	public function processImport( array $leads, array $listIds, array $tags, int $sequenceId, string $status ): array {
		$importedLocal     = 0;
		$syncedFluentCrm   = 0;
		$enrolledCampaign  = 0;

		$hasFluent = $this->fluentBridge->isActive();

		foreach ( $leads as $lead ) {
			$name    = sanitize_text_field( (string) ( $lead['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}

			$phone   = sanitize_text_field( (string) ( $lead['phone'] ?? '' ) );
			$email   = sanitize_email( (string) ( $lead['email'] ?? '' ) );
			$website = esc_url_raw( (string) ( $lead['website'] ?? '' ) );
			$address = sanitize_text_field( (string) ( $lead['address'] ?? '' ) );
			$placeId = sanitize_text_field( (string) ( $lead['place_id'] ?? '' ) );
			$cat     = sanitize_text_field( (string) ( $lead['category'] ?? '' ) );
			$rating  = isset( $lead['rating'] ) ? (float) $lead['rating'] : null;

			// 1. Upsert into local VM Sales OS contacts
			$contactId = $this->contacts->upsert( [
				'name'         => $name,
				'email'        => $email,
				'phone'        => $phone,
				'source'       => 'google_maps',
				'requirements' => sprintf( "Google Maps Lead: %s\nAddress: %s\nRating: %s\nCategory: %s", $name, $address, $rating ? $rating . '★' : 'N/A', $cat ),
				'consent'      => 1,
				'meta'         => [
					'place_id'      => $placeId,
					'website'       => $website,
					'address'       => $address,
					'category'      => $cat,
					'rating'        => $rating,
					'reviews_count' => $lead['reviews_count'] ?? 0,
					'social'        => $lead['social'] ?? [],
				],
			] );

			if ( $contactId > 0 ) {
				$importedLocal++;

				// 2. Sync to FluentCRM if available and has valid email (or phone shadow enabled)
				if ( $hasFluent ) {
					// Dynamically add category and city tags
					$leadTags = $tags;
					$leadTags[] = 'google-maps-lead';
					if ( '' !== $cat ) {
						$leadTags[] = 'cat-' . sanitize_title( $cat );
					}

					$crmId = $this->fluentBridge->syncLead(
						[
							'name'     => $name,
							'email'    => $email,
							'phone'    => $phone,
							'address'  => $address,
							'city'     => $lead['city'] ?? '',
							'place_id' => $placeId,
							'website'  => $website,
							'rating'   => $rating,
							'category' => $cat,
						],
						$listIds,
						$leadTags,
						$status
					);

					if ( false !== $crmId && $crmId > 0 ) {
						$syncedFluentCrm++;
					}
				}

				// 3. Enroll into VM Sales OS Sequence if requested
				if ( $sequenceId > 0 ) {
					$sequence = $this->sequences->find( $sequenceId );
					if ( $sequence ) {
						$enrollmentId = $this->enrollments->enroll( $contactId, $sequenceId );
						if ( $enrollmentId > 0 ) {
							$enrolledCampaign++;
						}
					}
				}
			}
		}

		return [
			'imported_local'    => $importedLocal,
			'synced_fluentcrm'  => $syncedFluentCrm,
			'enrolled_campaign' => $enrolledCampaign,
			'total'             => count( $leads ),
		];
	}

	/**
	 * Check existing status of leads against CRM database.
	 *
	 * @param array<int, array<string, mixed>> $places
	 * @return array<int, array<string, mixed>>
	 */
	private function enrichWithExistingStatus( array $places ): array {
		foreach ( $places as &$p ) {
			$email = (string) ( $p['email'] ?? '' );
			$phone = (string) ( $p['phone'] ?? '' );

			$existsInLocal = false;
			if ( '' !== $email && $this->contacts->findByEmail( $email ) ) {
				$existsInLocal = true;
			} elseif ( '' !== $phone && $this->contacts->findByPhone( $phone ) ) {
				$existsInLocal = true;
			}

			$existsInFluent = false;
			if ( $this->fluentBridge->isActive() && '' !== $email ) {
				try {
					$fc = FluentCrmApi( 'contacts' )->getContact( $email );
					if ( $fc && isset( $fc->id ) ) {
						$existsInFluent = true;
					}
				} catch ( \Throwable ) {}
			}

			$p['exists_in_crm']       = $existsInLocal;
			$p['exists_in_fluentcrm'] = $existsInFluent;
		}

		return $places;
	}

	/**
	 * Automated daily scheduled B2B lead generation runner.
	 * Cycles through target cities and discovers new B2B partners via Google Places & Website Crawler.
	 */
	public function runScheduledDailyImport(): array {
		if ( ! $this->placesClient->isConfigured() ) {
			return [ 'status' => 'skipped', 'reason' => 'Places API key missing' ];
		}

		$cities = (string) get_option( 'vmsos_b2b_import_cities', '' );
		if ( '' === $cities ) {
			$cities = (string) get_option( 'tc_agents_b2b_import_cities', 'Varanasi, Delhi, Mumbai, Jaipur, Kolkata, Chennai, Bengaluru, Hyderabad, Ahmedabad, Lucknow' );
		}
		$cityList = array_values( array_filter( array_map( 'trim', explode( ',', $cities ) ) ) );
		if ( empty( $cityList ) ) {
			$cityList = [ 'Varanasi', 'Delhi', 'Mumbai', 'Jaipur', 'Bengaluru' ];
		}

		$index = (int) get_option( 'vmsos_b2b_city_index', get_option( 'tc_agents_b2b_city_index', 0 ) );
		if ( $index >= count( $cityList ) ) {
			$index = 0;
		}
		$currentCity = $cityList[ $index ];

		$query = 'travel agency in ' . $currentCity;
		$res   = $this->placesClient->search( $query, 20, true );

		if ( isset( $res['error'] ) || empty( $res['places'] ) ) {
			update_option( 'vmsos_b2b_city_index', ( $index + 1 ) % count( $cityList ), false );
			return [ 'status' => 'error', 'city' => $currentCity, 'error' => $res['error'] ?? 'No places returned' ];
		}

		// Import discovered places directly into contacts & FluentCRM
		$tags   = [ 'b2b-lead', 'city-' . sanitize_title( $currentCity ) ];
		$import = $this->processImport( (array) $res['places'], [], $tags, 0, 'subscribed' );

		// Advance city index and stamp last run
		update_option( 'vmsos_b2b_city_index', ( $index + 1 ) % count( $cityList ), false );
		update_option( 'vmsos_b2b_last_import', current_time( 'mysql' ), false );

		return [
			'status'  => 'success',
			'city'    => $currentCity,
			'found'   => count( $res['places'] ),
			'results' => $import,
		];
	}

	private function verifyAdminAjax(): void {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( (string) $_POST['_wpnonce'] ) : '';
		$hasCap = current_user_can( 'vmsos_operate' ) || current_user_can( 'manage_options' );
		if ( ! $hasCap || ! wp_verify_nonce( $nonce, 'vmsos_leadgen_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied or security token expired. Please refresh the page.', 'vm-sales-os' ) ], 403 );
		}
	}
}
