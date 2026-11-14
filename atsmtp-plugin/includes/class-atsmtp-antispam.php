<?php
/**
 * Loads the shared antispam module from the "antispam" directory next to the shared config file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATSMTP_Antispam {

	/** Module contract version this plugin understands. */
	const API = 1;

	/** Per-site switch: "1" (default) or "0". */
	const OPTION = 'atsmtp_antispam_site';

	/** @var object|null */
	private static $module = null;

	/** @var bool */
	private static $loaded = false;

	/** @var string */
	private static $error = '';

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'boot' ), 5 );
	}

	/**
	 * @return bool
	 */
	public static function site_enabled() {
		return '0' !== (string) get_option( self::OPTION, '1' );
	}

	/**
	 * @param bool $enabled Enabled.
	 */
	public static function set_site_enabled( $enabled ) {
		update_option( self::OPTION, $enabled ? '1' : '0' );
	}

	/**
	 * @return array{bootstrap: string, data: string}|null
	 */
	public static function paths() {
		$config = trim( ATSMTP_Plugin::settings()['config_path'] );
		if ( '' === $config ) {
			return null;
		}
		$dir = untrailingslashit( wp_normalize_path( dirname( $config ) ) );
		return array(
			'bootstrap' => $dir . '/antispam/bootstrap.php',
			'data'      => $dir . '/data',
		);
	}

	/**
	 * @return string
	 */
	public static function error() {
		return self::$error;
	}

	/**
	 * Load the module once per request. A broken module never breaks the site: the error is kept for the settings page.
	 *
	 * @return object|null
	 */
	public static function module() {
		if ( self::$loaded ) {
			return self::$module;
		}
		self::$loaded = true;

		$loaded = ATSMTP_Plugin::current_config();
		$paths  = self::paths();
		if ( null === $loaded['config'] || null === $paths ) {
			self::$error = __( 'Файл настроек не загружен — антиспам не работает.', 'at-smtp' );
			return null;
		}
		if ( ! @is_file( $paths['bootstrap'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			/* translators: %s: path */
			self::$error = sprintf( __( 'Модуль антиспама не найден: %s', 'at-smtp' ), $paths['bootstrap'] );
			return null;
		}

		try {
			$factory = ( static function ( $file ) {
				return include $file;
			} )( $paths['bootstrap'] );
			if ( ! is_callable( $factory ) ) {
				throw new RuntimeException( 'bootstrap.php должен возвращать функцию.' );
			}
			$module = $factory(
				$loaded['config']['antispam'],
				array(
					'data_dir'       => $paths['data'],
					'plugin_version' => ATSMTP_VERSION,
				)
			);
			if ( ! is_object( $module ) || ! method_exists( $module, 'api' ) || self::API !== (int) $module->api() ) {
				throw new RuntimeException( 'несовместимая версия модуля, обновите плагин или каталог antispam.' );
			}
			self::$module = $module;
		} catch ( \Throwable $e ) {
			/* translators: %s: error */
			self::$error = sprintf( __( 'Ошибка модуля антиспама: %s', 'at-smtp' ), $e->getMessage() );
		}
		return self::$module;
	}

	public static function boot() {
		if ( ! self::site_enabled() ) {
			return;
		}
		$module = self::module();
		if ( null === $module ) {
			return;
		}
		try {
			$module->boot();
		} catch ( \Throwable $e ) {
			/* translators: %s: error */
			self::$error = sprintf( __( 'Ошибка модуля антиспама: %s', 'at-smtp' ), $e->getMessage() );
		}
	}
}
