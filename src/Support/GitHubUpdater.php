<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Support;

use stdClass;
use WP_Error;

/**
 * Native GitHub Plugin Updater & Two-Way Synchronizer for VM Sales OS.
 *
 * Automatically checks https://github.com/vmai-plugins/vm-sales-os releases
 * and integrates with WordPress core update API.
 */
final class GitHubUpdater {

	private const CACHE_KEY    = 'vmsos_gh_release_info';
	private const DEFAULT_REPO = 'vmai-plugins/vm-sales-os';
	private static bool $isUpgrading = false;

	public function __construct( private Vault $vault ) {}

	public function boot(): void {
		add_filter( 'site_transient_update_plugins', [ $this, 'injectUpdate' ] );
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'injectUpdate' ] );
		add_filter( 'plugins_api', [ $this, 'pluginInfo' ], 20, 3 );
		add_filter( 'upgrader_pre_download', [ $this, 'flagUpgrade' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fixSourceDir' ], 10, 3 );
		add_filter( 'http_request_args', [ $this, 'attachToken' ], 10, 2 );
		add_action( 'upgrader_process_complete', [ $this, 'clearCache' ], 10, 2 );

		// AJAX endpoints for Settings page updater
		add_action( 'wp_ajax_vmsos_check_github_update', [ $this, 'ajaxCheckUpdate' ] );
		add_action( 'wp_ajax_vmsos_perform_github_update', [ $this, 'ajaxPerformUpdate' ] );
	}

	public function repo(): string {
		$r = (string) get_option( 'vmsos_github_repo', self::DEFAULT_REPO );
		return '' !== trim( $r ) ? trim( $r ) : self::DEFAULT_REPO;
	}

	public function branch(): string {
		$b = (string) get_option( 'vmsos_github_branch', 'main' );
		return '' !== trim( $b ) ? trim( $b ) : 'main';
	}

	public function token(): string {
		$t = $this->vault->get( 'github_token' );
		if ( '' !== $t ) {
			return $t;
		}
		return (string) get_option( 'vmsos_github_token', '' );
	}

	public function headers(): array {
		$headers = [
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'VM-Sales-OS-Updater; ' . home_url( '/' ),
		];
		$token = $this->token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . trim( $token );
		}
		return $headers;
	}

	public function getRelease( bool $force = false ): ?array {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : null;
			}
		}

		$repo = $this->repo();
		$latestRelease = null;

		// 1. Fetch latest formal GitHub Release
		$res = wp_remote_get(
			"https://api.github.com/repos/{$repo}/releases/latest",
			[
				'timeout' => 15,
				'headers' => $this->headers(),
			]
		);

		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( is_array( $data ) && ! empty( $data['tag_name'] ) ) {
				$latestRelease = $data;
			}
		}

		// 2. Fetch latest Git Tags to see if a newer tag was pushed directly
		$tagsRes = wp_remote_get(
			"https://api.github.com/repos/{$repo}/tags",
			[
				'timeout' => 15,
				'headers' => $this->headers(),
			]
		);

		if ( ! is_wp_error( $tagsRes ) && 200 === (int) wp_remote_retrieve_response_code( $tagsRes ) ) {
			$tags = json_decode( (string) wp_remote_retrieve_body( $tagsRes ), true );
			if ( is_array( $tags ) && ! empty( $tags[0]['name'] ) ) {
				$latestTag = $tags[0];
				$tagVer    = self::tagToVersion( (string) $latestTag['name'] );
				$relVer    = $latestRelease ? self::tagToVersion( (string) $latestRelease['tag_name'] ) : '0.0.0';

				// If tag is newer than formal release (or no formal release exists), prioritize the tag
				if ( version_compare( $tagVer, $relVer, '>' ) ) {
					$tagData = [
						'tag_name'     => $latestTag['name'],
						'name'         => 'Version ' . $latestTag['name'],
						'body'         => 'Latest release from GitHub tag ' . $latestTag['name'],
						'zipball_url'  => $latestTag['zipball_url'] ?? "https://api.github.com/repos/{$repo}/zipball/{$latestTag['name']}",
						'published_at' => current_time( 'mysql' ),
					];
					set_transient( self::CACHE_KEY, $tagData, 2 * HOUR_IN_SECONDS );
					return $tagData;
				}
			}
		}

		if ( $latestRelease ) {
			set_transient( self::CACHE_KEY, $latestRelease, 6 * HOUR_IN_SECONDS );
			return $latestRelease;
		}

		// 3. Final fallback: use branch zip
		$branch = $this->branch();
		$branchData = [
			'tag_name'     => 'v' . VMSOS_VERSION,
			'name'         => 'Branch ' . $branch,
			'body'         => 'Live branch ' . $branch,
			'zipball_url'  => "https://github.com/{$repo}/archive/refs/heads/{$branch}.zip",
			'published_at' => current_time( 'mysql' ),
		];
		set_transient( self::CACHE_KEY, $branchData, 15 * MINUTE_IN_SECONDS );
		return $branchData;
	}

	public static function tagToVersion( string $tag ): string {
		return ltrim( $tag, 'vV' );
	}

	public function zipUrl( ?array $release ): ?string {
		if ( ! $release ) {
			return sprintf( 'https://github.com/%s/archive/refs/heads/%s.zip', $this->repo(), $this->branch() );
		}

		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( str_ends_with( strtolower( (string) ( $asset['name'] ?? '' ) ), '.zip' ) && ! empty( $asset['browser_download_url'] ) ) {
					return (string) $asset['browser_download_url'];
				}
			}
		}

		return $release['zipball_url'] ?? sprintf( 'https://github.com/%s/archive/refs/tags/%s.zip', $this->repo(), $release['tag_name'] ?? 'main' );
	}

	public function injectUpdate( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		$release = $this->getRelease();
		if ( ! $release ) {
			return $transient;
		}

		$remoteVer = self::tagToVersion( (string) ( $release['tag_name'] ?? '' ) );
		$item = (object) [
			'slug'          => 'vm-sales-os',
			'plugin'        => VMSOS_BASENAME,
			'new_version'   => $remoteVer,
			'url'           => 'https://github.com/' . $this->repo(),
			'package'       => $this->zipUrl( $release ),
			'icons'         => [],
			'banners'       => [],
			'tested'        => '6.8',
			'requires_php'  => '8.1',
		];

		if ( version_compare( $remoteVer, VMSOS_VERSION, '>' ) ) {
			$transient->response[ VMSOS_BASENAME ] = $item;
			unset( $transient->no_update[ VMSOS_BASENAME ] );
		} else {
			$transient->no_update[ VMSOS_BASENAME ] = $item;
			unset( $transient->response[ VMSOS_BASENAME ] );
		}

		return $transient;
	}

	public function pluginInfo( $res, string $action, $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== 'vm-sales-os' ) {
			return $res;
		}

		$release   = $this->getRelease();
		$remoteVer = $release ? self::tagToVersion( (string) ( $release['tag_name'] ?? '' ) ) : VMSOS_VERSION;

		$info = new stdClass();
		$info->name          = 'VM Sales OS';
		$info->slug          = 'vm-sales-os';
		$info->version       = $remoteVer;
		$info->author        = '<a href="https://vmstudio.digital">VM Studio</a>';
		$info->homepage      = 'https://github.com/' . $this->repo();
		$info->requires      = '6.4';
		$info->tested        = '6.8';
		$info->requires_php  = '8.1';
		$info->download_link = $this->zipUrl( $release );
		$info->sections      = [
			'description' => 'Universal Turn-key AI Sales OS & Multi-Agent CRM for WordPress.',
			'changelog'   => nl2br( esc_html( (string) ( $release['body'] ?? 'Latest updates from GitHub.' ) ) ),
		];

		return $info;
	}

	public function flagUpgrade( $reply, string $package, $upgrader ) {
		if ( str_contains( $package, $this->repo() ) || str_contains( $package, 'vm-sales-os' ) ) {
			self::$isUpgrading = true;
		}
		return $reply;
	}

	public function fixSourceDir( string $source, string $remoteSource, $upgrader ): string {
		$sourceBasename = basename( untrailingslashit( $source ) );
		$isTarget       = self::$isUpgrading || str_contains( $sourceBasename, 'vm-sales-os' );

		if ( ! $isTarget ) {
			return $source;
		}

		global $wp_filesystem;
		$target = trailingslashit( $remoteSource ) . 'vm-sales-os/';

		if ( trailingslashit( $source ) === $target ) {
			return $source;
		}

		$wp_filesystem->move( $source, $target );
		return $target;
	}

	public function attachToken( array $args, string $url ): array {
		if ( ! str_contains( $url, 'github.com' ) ) {
			return $args;
		}

		$token = $this->token();
		if ( '' !== $token && ! isset( $args['headers']['Authorization'] ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . trim( $token );
		}

		return $args;
	}

	public function clearCache(): void {
		delete_transient( self::CACHE_KEY );
		self::$isUpgrading = false;
	}

	public function ajaxCheckUpdate(): void {
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? (string) $_REQUEST['_wpnonce'] : (string) ( $_REQUEST['nonce'] ?? '' );
		if ( ! wp_verify_nonce( $nonce, 'vmsos_settings' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security token expired. Please refresh the page.', 'vm-sales-os' ) ], 403 );
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'vm-sales-os' ) ], 403 );
		}

		$release   = $this->getRelease( true );
		$remoteVer = $release ? self::tagToVersion( (string) ( $release['tag_name'] ?? '' ) ) : VMSOS_VERSION;
		$hasUpdate = version_compare( $remoteVer, VMSOS_VERSION, '>' );

		wp_send_json_success( [
			'local_version'  => VMSOS_VERSION,
			'remote_version' => $remoteVer,
			'has_update'     => $hasUpdate,
			'repo'           => $this->repo(),
			'branch'         => $this->branch(),
			'message'        => $hasUpdate
				? sprintf( __( 'Update available: v%s (Current: v%s)', 'vm-sales-os' ), $remoteVer, VMSOS_VERSION )
				: sprintf( __( 'You are up to date with GitHub (v%s).', 'vm-sales-os' ), VMSOS_VERSION ),
		] );
	}

	public function ajaxPerformUpdate(): void {
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? (string) $_REQUEST['_wpnonce'] : (string) ( $_REQUEST['nonce'] ?? '' );
		if ( ! wp_verify_nonce( $nonce, 'vmsos_settings' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security token expired. Please refresh the page.', 'vm-sales-os' ) ], 403 );
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'vm-sales-os' ) ], 403 );
		}

		$release = $this->getRelease( true );
		$package = $this->zipUrl( $release );

		if ( empty( $package ) ) {
			wp_send_json_error( [ 'message' => __( 'No download package found on GitHub.', 'vm-sales-os' ) ], 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		WP_Filesystem();

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$plugin   = VMSOS_BASENAME;

		self::$isUpgrading = true;
		add_filter( 'upgrader_source_selection', [ $this, 'fixSourceDir' ], 10, 3 );

		// Use install with overwrite_package = true to guarantee flawless in-place upgrade from GitHub
		$result = $upgrader->install( $package, [ 'overwrite_package' => true ] );
		$this->clearCache();

		if ( is_wp_error( $result ) || false === $result ) {
			$errors = $skin->get_errors();
			$errMsg = ! empty( $errors ) && is_wp_error( $errors ) ? $errors->get_error_message() : __( 'Failed to apply update package from GitHub.', 'vm-sales-os' );
			wp_send_json_error( [ 'message' => $errMsg ] );
		}

		if ( ! is_plugin_active( $plugin ) ) {
			activate_plugin( $plugin );
		}

		wp_send_json_success( [
			'message' => __( 'Plugin successfully updated from GitHub! Reloading...', 'vm-sales-os' ),
		] );
	}
}
