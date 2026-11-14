<?php
/**
 * Update the plugin from GitHub Releases (asset at-smtp.zip).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATSMTP_Updater {

	const CACHE = 'atsmtp_github_release';

	/** Release asset with the plugin. */
	const ASSET = 'at-smtp.zip';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'offer' ), 10, 4 );
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ), 99999 );
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject' ), 99999 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'source' ), 10, 4 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/**
	 * @return string owner/repo.
	 */
	public static function repo() {
		return ATSMTP_GITHUB_REPO;
	}

	/**
	 * Installed folder name, e.g. "at-smtp".
	 *
	 * @return string
	 */
	public static function slug() {
		return dirname( plugin_basename( ATSMTP_FILE ) );
	}

	public static function flush_cache() {
		delete_site_transient( self::CACHE );
		delete_site_transient( 'update_plugins' );
	}

	/**
	 * Upgrade URL for the Plugins screen action.
	 *
	 * @return string
	 */
	public static function upgrade_url() {
		$file = plugin_basename( ATSMTP_FILE );
		return wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $file ) ), 'upgrade-plugin_' . $file );
	}

	/**
	 * @return string
	 */
	public static function installed_version() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( ATSMTP_FILE, false, false );
		return ! empty( $data['Version'] ) ? (string) $data['Version'] : ATSMTP_VERSION;
	}

	/**
	 * @param array|false          $update      Update payload.
	 * @param array<string,string> $plugin_data Headers.
	 * @param string               $plugin_file Plugin file.
	 * @param string[]             $locales     Locales.
	 * @return array|false|object
	 */
	public static function offer( $update, $plugin_data, $plugin_file, $locales ) {
		if ( plugin_basename( ATSMTP_FILE ) !== $plugin_file ) {
			return $update;
		}
		$payload = self::payload();
		return $payload ? $payload : $update;
	}

	/**
	 * @param mixed $transient update_plugins transient.
	 * @return mixed
	 */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$plugin  = plugin_basename( ATSMTP_FILE );
		$payload = self::payload();

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		if ( $payload ) {
			unset( $transient->no_update[ $plugin ] );
			$transient->response[ $plugin ] = $payload;
		} else {
			unset( $transient->response[ $plugin ] );
			$transient->no_update[ $plugin ] = self::item( self::installed_version(), '' );
		}
		return $transient;
	}

	public static function admin_notice() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'settings_page_' . ATSMTP_Plugin::PAGE ), true ) ) {
			return;
		}
		$payload = self::payload();
		if ( ! $payload ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%1$s <a class="button button-primary" href="%2$s">%3$s</a></p></div>',
			/* translators: 1: current version, 2: new version */
			esc_html( sprintf( __( 'Доступно обновление AT SMTP: %1$s → %2$s.', 'at-smtp' ), self::installed_version(), $payload->new_version ) ),
			esc_url( self::upgrade_url() ),
			esc_html__( 'Обновить', 'at-smtp' )
		);
	}

	/**
	 * @return object|false
	 */
	public static function payload() {
		$remote = self::remote();
		if ( empty( $remote['version'] ) || empty( $remote['package'] ) ) {
			return false;
		}
		if ( version_compare( $remote['version'], self::installed_version(), '<=' ) ) {
			return false;
		}
		return self::item( $remote['version'], $remote['package'], $remote['url'] );
	}

	/**
	 * @param string $version Version.
	 * @param string $package Package URL.
	 * @param string $url     Page URL.
	 * @return object
	 */
	private static function item( $version, $package, $url = '' ) {
		return (object) array(
			'id'            => 'github.com/' . self::repo(),
			'slug'          => self::slug(),
			'plugin'        => plugin_basename( ATSMTP_FILE ),
			'version'       => $version,
			'new_version'   => $version,
			'url'           => '' !== $url ? $url : 'https://github.com/' . self::repo(),
			'package'       => $package,
			'icons'         => array(),
			'banners'       => array(),
			'banners_rtl'   => array(),
			'tested'        => '',
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'compatibility' => new stdClass(),
		);
	}

	/**
	 * "View details" popup on the Plugins screen.
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action Action.
	 * @param object             $args   Args.
	 * @return false|object|array
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}
		$remote = self::remote();
		if ( empty( $remote['version'] ) ) {
			return $result;
		}
		return (object) array(
			'name'          => 'AT SMTP',
			'slug'          => self::slug(),
			'version'       => $remote['version'],
			'author'        => 'AT',
			'homepage'      => $remote['url'],
			'download_link' => $remote['package'],
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'sections'      => array(
				'description' => esc_html__( 'Отправка почты всех сайтов через SMTP по общему файлу настроек и общая защита форм от спама.', 'at-smtp' ),
				'changelog'   => wpautop( esc_html( '' !== $remote['notes'] ? $remote['notes'] : __( 'Изменения описаны в релизе на GitHub.', 'at-smtp' ) ) ),
			),
		);
	}

	/**
	 * Install into the folder the site already uses, whatever the archive's root folder is called.
	 *
	 * @param string|WP_Error $source        Extracted path.
	 * @param string          $remote_source Temporary directory.
	 * @param WP_Upgrader     $upgrader      Upgrader.
	 * @param array           $hook_extra    Extra.
	 * @return string|WP_Error
	 */
	public static function source( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;

		if ( is_wp_error( $source ) || empty( $hook_extra['plugin'] ) || plugin_basename( ATSMTP_FILE ) !== $hook_extra['plugin'] ) {
			return $source;
		}

		$source = trailingslashit( $source );
		$found  = '';
		foreach ( array( '', 'at-smtp/', 'atsmtp-plugin/' ) as $sub ) {
			if ( file_exists( $source . $sub . 'at-smtp.php' ) ) {
				$found = $source . $sub;
				break;
			}
		}
		if ( '' === $found ) {
			return new WP_Error( 'atsmtp_package', __( 'В архиве обновления не найден файл at-smtp.php.', 'at-smtp' ) );
		}
		if ( basename( untrailingslashit( $found ) ) === self::slug() ) {
			return $found;
		}

		$target = trailingslashit( $remote_source ) . self::slug();
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $found ), $target, true ) ) {
			return trailingslashit( $target );
		}
		return new WP_Error( 'atsmtp_package', __( 'Не удалось подготовить папку обновления.', 'at-smtp' ) );
	}

	/**
	 * Latest GitHub release, cached.
	 *
	 * @param bool $fresh Skip cache.
	 * @return array{version:string,package:string,shared:string,url:string,notes:string,error:string}
	 */
	public static function remote( $fresh = false ) {
		$result = array(
			'version' => '',
			'package' => '',
			'shared'  => '',
			'url'     => 'https://github.com/' . self::repo() . '/releases',
			'notes'   => '',
			'error'   => '',
		);

		$cached = $fresh ? false : get_site_transient( self::CACHE );
		if ( is_array( $cached ) && array_key_exists( 'version', $cached ) ) {
			return array_merge( $result, $cached );
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'AT-SMTP/' . ATSMTP_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			set_site_transient( self::CACHE, $result, 15 * MINUTE_IN_SECONDS );
			return $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			$result['error'] = 404 === $code
				? __( 'Релиз на GitHub не найден.', 'at-smtp' )
				/* translators: %d: HTTP status */
				: sprintf( __( 'GitHub ответил кодом %d. Возможны лимит API или блокировка исходящих запросов на сервере.', 'at-smtp' ), $code );
			set_site_transient( self::CACHE, $result, 15 * MINUTE_IN_SECONDS );
			return $result;
		}

		foreach ( isset( $body['assets'] ) && is_array( $body['assets'] ) ? $body['assets'] : array() as $asset ) {
			if ( empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
				continue;
			}
			if ( self::ASSET === $asset['name'] ) {
				$result['package'] = (string) $asset['browser_download_url'];
			} elseif ( 'atsmtp-shared.zip' === $asset['name'] ) {
				$result['shared'] = (string) $asset['browser_download_url'];
			}
		}

		$result['version'] = ltrim( isset( $body['tag_name'] ) ? (string) $body['tag_name'] : '', 'vV' );
		$result['notes']   = isset( $body['body'] ) ? (string) $body['body'] : '';
		if ( ! empty( $body['html_url'] ) ) {
			$result['url'] = (string) $body['html_url'];
		}

		set_site_transient( self::CACHE, $result, HOUR_IN_SECONDS );
		return $result;
	}
}
