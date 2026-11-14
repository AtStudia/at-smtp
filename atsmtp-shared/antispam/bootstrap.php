<?php
/**
 * AT SMTP shared antispam module. Included by the AT SMTP plugin on every site.
 *
 * Returns a factory: function ( array $settings, array $env ): Module.
 */

namespace AtSmtp\Antispam;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\Module', false ) ) {
	require __DIR__ . '/src/Storage.php';
	require __DIR__ . '/src/Checker.php';
	require __DIR__ . '/src/Adapters.php';
	require __DIR__ . '/src/Module.php';
}

return static function ( array $settings, array $env ) {
	return new Module( $settings, $env );
};
