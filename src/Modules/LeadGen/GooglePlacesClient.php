<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\LeadGen;

use VMStudio\SalesOS\Support\Vault;

/**
 * Client for Google Places API (New) Text Search.
 * Powers automated business intelligence and B2B lead generation.
 */
final class GooglePlacesClient {

	private const API_ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

	public function __construct(
		private Vault $vault,
		private WebsiteEmailCrawler $crawler
	) {}

	/**
	 * Retrieve Google Places API key.
	 */
	public function getApiKey(): string {
		$key = $this->vault->get( 'google_places_api_key' );
		if ( '' !== $key ) {
			return $key;
		}

		// Fallback to option or tripcosmos vault
		$opt = (string) get_option( 'vmsos_google_places_api_key', '' );
		if ( '' !== $opt ) {
			return $opt;
		}

		// Backward compatibility with tc_agents_vault
		$tcVault = get_option( 'tc_agents_vault' );
		if ( is_array( $tcVault ) && ! empty( $tcVault['google_places_api_key'] ) ) {
			return (string) $tcVault['google_places_api_key'];
		}

		return (string) get_option( 'tc_agents_google_places_api_key', '' );
	}

	public function isConfigured(): bool {
		return '' !== $this->getApiKey();
	}

	/**
	 * Search businesses using Google Places API (New) Text Search.
	 *
	 * @param string $query e.g. "Dental Clinics in Mumbai"
	 * @param int $max 1 to 60 (New Text Search allows up to 20 per request)
	 * @param bool $crawlEmail Whether to crawl target websites for emails
	 * @param string $pageToken Next page token for pagination
	 * @return array{places: array<int, array<string, mixed>>, next_page_token: string, total: int}|array{error: string}
	 */
	public function search( string $query, int $max = 20, bool $crawlEmail = true, string $pageToken = '' ): array {
		$apiKey = $this->getApiKey();
		if ( '' === $apiKey ) {
			return [
				'error' => __( 'Google Places API key is missing. Please save your API key in settings.', 'vm-sales-os' ),
			];
		}

		$query = trim( $query );
		if ( '' === $query ) {
			return [
				'error' => __( 'Search query cannot be empty.', 'vm-sales-os' ),
			];
		}

		$fieldMask = 'places.id,places.displayName,places.formattedAddress,places.internationalPhoneNumber,places.nationalPhoneNumber,places.rating,places.userRatingCount,places.websiteUri,places.location,places.primaryType,places.types,places.googleMapsUri,nextPageToken';

		$payload = [
			'textQuery'      => $query,
			'maxResultCount' => min( 20, max( 1, $max ) ),
		];

		if ( '' !== $pageToken ) {
			$payload['pageToken'] = $pageToken;
		}

		$response = wp_remote_post(
			self::API_ENDPOINT,
			[
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => [
					'Content-Type'     => 'application/json',
					'X-Goog-Api-Key'   => $apiKey,
					'X-Goog-FieldMask' => $fieldMask,
				],
				'body'      => wp_json_encode( $payload ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'error' => $response->get_error_message(),
			];
		}

		$statusCode = (int) wp_remote_retrieve_response_code( $response );
		$bodyRaw    = (string) wp_remote_retrieve_body( $response );
		$body       = json_decode( $bodyRaw, true );

		if ( $statusCode >= 400 || ! is_array( $body ) ) {
			$errorMessage = $body['error']['message'] ?? $body['message'] ?? ( 'Places API Error (HTTP ' . $statusCode . ')' );
			return [
				'error' => $errorMessage,
			];
		}

		$rawPlaces     = (array) ( $body['places'] ?? [] );
		$nextPageToken = (string) ( $body['nextPageToken'] ?? '' );
		$processed     = [];
		$crawledCount  = 0;

		foreach ( $rawPlaces as $p ) {
			$placeId     = (string) ( $p['id'] ?? '' );
			$name        = (string) ( $p['displayName']['text'] ?? '' );
			$address     = (string) ( $p['formattedAddress'] ?? '' );
			$intlPhone   = (string) ( $p['internationalPhoneNumber'] ?? '' );
			$natPhone    = (string) ( $p['nationalPhoneNumber'] ?? '' );
			$phone       = '' !== $intlPhone ? $intlPhone : $natPhone;
			$website     = (string) ( $p['websiteUri'] ?? '' );
			$rating      = isset( $p['rating'] ) ? (float) $p['rating'] : null;
			$reviewCount = isset( $p['userRatingCount'] ) ? (int) $p['userRatingCount'] : 0;
			$mapsUri     = (string) ( $p['googleMapsUri'] ?? '' );
			$primaryType = (string) ( $p['primaryType'] ?? '' );
			$types       = (array) ( $p['types'] ?? [] );
			$lat         = isset( $p['location']['latitude'] ) ? (float) $p['location']['latitude'] : null;
			$lng         = isset( $p['location']['longitude'] ) ? (float) $p['location']['longitude'] : null;

			$normPhone = self::normalizePhone( $phone );

			// Crawl website for public business email (limit to 5 inline to guarantee <5s response)
			$emailData = [
				'emails'        => [],
				'primary_email' => '',
				'social'        => [],
			];

			if ( $crawlEmail && '' !== $website && $crawledCount < 5 ) {
				$emailData = $this->crawler->crawl( $website, 2 );
				$crawledCount++;
			}

			// Clean primary category label
			$categoryLabel = '' !== $primaryType ? ucwords( str_replace( '_', ' ', $primaryType ) ) : '';
			if ( '' === $categoryLabel && ! empty( $types ) ) {
				$categoryLabel = ucwords( str_replace( '_', ' ', (string) $types[0] ) );
			}

			$processed[] = [
				'place_id'       => $placeId,
				'name'           => $name,
				'category'       => $categoryLabel,
				'types'          => $types,
				'address'        => $address,
				'phone'          => $normPhone,
				'phone_display'  => $phone,
				'website'        => $website,
				'email'          => $emailData['primary_email'],
				'all_emails'     => $emailData['emails'],
				'social'         => $emailData['social'],
				'rating'         => $rating,
				'reviews_count'  => $reviewCount,
				'google_maps_url'=> $mapsUri,
				'latitude'       => $lat,
				'longitude'      => $lng,
			];
		}

		return [
			'places'          => $processed,
			'next_page_token' => $nextPageToken,
			'total'           => count( $processed ),
		];
	}

	/**
	 * Normalize phone numbers to international standard format.
	 */
	public static function normalizePhone( string $phone ): string {
		$clean = preg_replace( '/[^\d+]/', '', trim( $phone ) ) ?? '';
		if ( '' === $clean ) {
			return '';
		}

		// Already has leading +
		if ( str_starts_with( $clean, '+' ) ) {
			return $clean;
		}

		$digits = preg_replace( '/\D/', '', $clean ) ?? '';

		// India 10-digit mobile
		if ( 10 === strlen( $digits ) && in_array( substr( $digits, 0, 1 ), [ '6', '7', '8', '9' ], true ) ) {
			return '+91' . $digits;
		}

		// India 11-digit with leading 0
		if ( 11 === strlen( $digits ) && '0' === substr( $digits, 0, 1 ) ) {
			return '+91' . substr( $digits, 1 );
		}

		// India 12-digit with 91 prefix
		if ( 12 === strlen( $digits ) && '91' === substr( $digits, 0, 2 ) ) {
			return '+' . $digits;
		}

		// US/Canada 10-digit
		if ( 10 === strlen( $digits ) ) {
			return '+1' . $digits;
		}

		// US/Canada 11-digit starting with 1
		if ( 11 === strlen( $digits ) && '1' === substr( $digits, 0, 1 ) ) {
			return '+' . $digits;
		}

		// Default prepend +
		return '+' . $digits;
	}
}
