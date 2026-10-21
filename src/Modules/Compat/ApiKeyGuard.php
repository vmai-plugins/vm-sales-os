<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Compat;

use VMStudio\SalesOS\Support\Vault;
use WP_Error;
use WP_REST_Request;

/**
 * Authenticates the Superfone mobile app.
 *
 * The rest of this plugin gates on WordPress capabilities, which assumes a
 * cookie and a nonce. The mobile app has neither — it holds a long-lived shared
 * key and sends it as `X-VMAI-API-Key`, the scheme the legacy vmai-sales-agent
 * plugin used. This guard is the only place that scheme is honoured, so the
 * native vmsos/v1 surface keeps its capability checks untouched.
 *
 * Two kinds of key are accepted:
 *   - the account-wide key in the vault under `mobile.api_key`
 *   - a per-brand key stored as `mobile_api_key` in that brand's settings JSON
 *
 * A brand key additionally pins BrandContext to that brand for the whole
 * request, so an agency's app session can only ever see its own tenant.
 */
final class ApiKeyGuard {

	public const HEADER    = 'X-VMAI-API-Key';
	public const VAULT_KEY = 'mobile.api_key';

	/** Brand resolved from a per-brand key, or 0 for an account-wide key. */
	private int $brandId = 0;

	private bool $filterAttached = false;

	public function __construct( private Vault $vault ) {}

	/**
	 * Permission callback. Returns true, or a WP_Error carrying the right status
	 * so the app can tell "no key" (401) from "wrong key" (403).
	 */
	public function check( WP_REST_Request $request ): bool|WP_Error {
		// An administrator hitting these routes from a logged-in browser session
		// is useful for debugging and costs nothing to allow.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$presented = (string) $request->get_header( self::HEADER );

		if ( '' === $presented ) {
			$authHeader = (string) $request->get_header( 'authorization' );
			if ( str_starts_with( strtolower( $authHeader ), 'bearer ' ) ) {
				$presented = trim( substr( $authHeader, 7 ) );
			}
		}

		if ( '' === $presented ) {
			return new WP_Error(
				'vmsos_missing_api_key',
				__( 'Missing X-VMAI-API-Key or Authorization Bearer header.', 'vm-sales-os' ),
				[ 'status' => 401 ]
			);
		}

		if ( $this->matchesAccountKey( $presented ) ) {
			$this->brandId = 0;
			return true;
		}

		$brandId = $this->matchBrandKey( $presented );

		if ( $brandId > 0 ) {
			$this->pinBrand( $brandId );
			return true;
		}

		return new WP_Error(
			'vmsos_invalid_api_key',
			__( 'That API key is not valid for this site.', 'vm-sales-os' ),
			[ 'status' => 403 ]
		);
	}

	/** Brand this request is pinned to, or 0 when the key is account-wide. */
	public function brandId(): int {
		return $this->brandId;
	}

	/** True when the caller holds the account-wide key rather than a brand key. */
	public function isAccountWide(): bool {
		return 0 === $this->brandId;
	}

	private function matchesAccountKey( string $presented ): bool {
		$stored = $this->vault->get( self::VAULT_KEY );

		// An unconfigured key must never authenticate an empty header.
		if ( '' === $stored ) {
			return false;
		}

		return hash_equals( $stored, $presented );
	}

	/**
	 * Scans brand settings for a matching key. Compared with hash_equals against
	 * every candidate rather than short-circuiting, so a wrong key costs the
	 * same time regardless of how far down the list the real one sits.
	 */
	private function matchBrandKey( string $presented ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'vmsos_brands';
		$rows  = $wpdb->get_results(
			"SELECT id, settings FROM {$table} WHERE status = 'active'",
			ARRAY_A
		);

		$match = 0;

		foreach ( (array) $rows as $row ) {
			$settings = json_decode( (string) ( $row['settings'] ?? '' ), true );
			$key      = is_array( $settings ) ? (string) ( $settings['mobile_api_key'] ?? '' ) : '';

			if ( '' !== $key && hash_equals( $key, $presented ) ) {
				$match = (int) $row['id'];
			}
		}

		return $match;
	}

	/**
	 * Pins BrandContext for the remainder of the request. Registered at a high
	 * priority so it wins over the default-brand option.
	 */
	private function pinBrand( int $brandId ): void {
		$this->brandId = $brandId;

		if ( $this->filterAttached ) {
			return;
		}

		$this->filterAttached = true;

		add_filter(
			'vmsos_current_brand_id',
			fn(): int => $this->brandId,
			99
		);
	}
}
