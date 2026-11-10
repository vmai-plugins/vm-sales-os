<?php
/**
 * Plugin Name:       VM Sales OS
 * Plugin URI:        https://vmstudio.digital/vm-sales-os
 * Description:       Universal Turn-key AI Sales OS & Multi-Agent CRM for WordPress. Includes multi-industry blueprints, omnichannel WhatsApp & Voice discovery telephony, lead capture tools, automated quoting, and hybrid knowledge retrieval.
 * Version:           1.1.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            VM Studio Creatives
 * Author URI:        https://vmstudio.digital
 * License:           GPL-2.0-or-later
 * Text Domain:       vm-sales-os
 * Network:           false
 *
 * @package VMStudio\SalesOS
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'VMSOS_VERSION', '1.1.1' );
define( 'VMSOS_FILE', __FILE__ );
define( 'VMSOS_DIR', plugin_dir_path( __FILE__ ) );
define( 'VMSOS_URL', set_url_scheme( plugin_dir_url( __FILE__ ), is_ssl() || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? 'https' : 'admin' ) );
define( 'VMSOS_BASENAME', plugin_basename( __FILE__ ) );
define( 'VMSOS_MIN_PHP', '8.1' );

if ( version_compare( PHP_VERSION, VMSOS_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'VM Sales OS needs PHP %1$s or newer. This server runs %2$s.', 'vm-sales-os' ),
						VMSOS_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

/**
 * PSR-4 autoloader. Composer is used for dev tooling only; runtime has zero
 * vendor dependencies so the plugin installs on any host.
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'VMStudio\\SalesOS\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = VMSOS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

if ( is_readable( VMSOS_DIR . 'vendor/autoload.php' ) ) {
	require_once VMSOS_DIR . 'vendor/autoload.php';
}

register_activation_hook( __FILE__, [ \VMStudio\SalesOS\Core\Lifecycle::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \VMStudio\SalesOS\Core\Lifecycle::class, 'deactivate' ] );

/**
 * Container accessor.
 *
 * @return \VMStudio\SalesOS\Core\Plugin
 */
function vmsos(): \VMStudio\SalesOS\Core\Plugin {
	return \VMStudio\SalesOS\Core\Plugin::instance();
}

add_action( 'plugins_loaded', static fn() => vmsos()->boot(), 5 );
