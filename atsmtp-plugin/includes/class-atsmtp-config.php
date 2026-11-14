<?php
/**
 * Loading and validation of the shared SMTP config file (outside the site folder).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATSMTP_Config {

	/** @var array<string, array<string, mixed>> */
	private static $cache = array();

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'host'              => '',
			'port'              => 0,
			'encryption'        => 'ssl',
			'auth'              => true,
			'username'          => '',
			'password'          => '',
			'from_email'        => '',
			'from_name'         => '{site_name}',
			'force_from'        => true,
			'reply_to_original' => true,
			'timeout'           => 15,
			'auto_tls'          => true,
			'verify_ssl'        => true,
		);
	}

	/**
	 * Load config file once per request.
	 *
	 * @param string $path Absolute path to .php or .json file.
	 * @return array{config: array<string, mixed>|null, error: string, site_override: bool}
	 */
	public static function load( $path ) {
		$path = trim( (string) $path );
		if ( ! isset( self::$cache[ $path ] ) ) {
			self::$cache[ $path ] = self::read( $path );
		}
		return self::$cache[ $path ];
	}

	/**
	 * Whether the path points inside the current WordPress installation.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function is_inside_site( $path ) {
		$real = @realpath( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- open_basedir may emit warnings.
		$file = wp_normalize_path( $real ? $real : $path );
		$root = wp_normalize_path( untrailingslashit( ABSPATH ) );
		return '' !== $root && 0 === stripos( $file, $root . '/' );
	}

	/**
	 * Current site host without "www.".
	 *
	 * @return string
	 */
	public static function site_host() {
		return self::normalize_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * @param string $host Host.
	 * @return string
	 */
	private static function normalize_host( $host ) {
		$host = strtolower( trim( $host ) );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * @param string $message Error.
	 * @return array{config: null, error: string, site_override: bool}
	 */
	private static function fail( $message ) {
		return array(
			'config'        => null,
			'error'         => $message,
			'site_override' => false,
		);
	}

	/**
	 * @param string $path Path.
	 * @return array{config: array<string, mixed>|null, error: string, site_override: bool}
	 */
	private static function read( $path ) {
		if ( '' === $path ) {
			return self::fail( __( 'Путь к файлу настроек не указан.', 'at-smtp' ) );
		}
		// phpcs:disable WordPress.PHP.NoSilencedErrors -- open_basedir restrictions emit warnings instead of returning false.
		if ( ! @is_file( $path ) ) {
			/* translators: %s: file path */
			return self::fail( sprintf( __( 'Файл не найден или недоступен (проверьте путь и ограничение open_basedir): %s', 'at-smtp' ), $path ) );
		}
		if ( ! @is_readable( $path ) ) {
			/* translators: %s: file path */
			return self::fail( sprintf( __( 'Нет прав на чтение файла: %s', 'at-smtp' ), $path ) );
		}
		// phpcs:enable

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		try {
			if ( 'php' === $ext ) {
				$data = ( static function ( $file ) {
					return include $file;
				} )( $path );
			} elseif ( 'json' === $ext ) {
				$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					/* translators: %s: JSON error */
					return self::fail( sprintf( __( 'Ошибка разбора JSON: %s', 'at-smtp' ), json_last_error_msg() ) );
				}
			} else {
				return self::fail( __( 'Поддерживаются только файлы .php (возвращающие массив) и .json.', 'at-smtp' ) );
			}
		} catch ( \Throwable $e ) {
			/* translators: %s: error message */
			return self::fail( sprintf( __( 'Ошибка в файле настроек: %s', 'at-smtp' ), $e->getMessage() ) );
		}

		if ( ! is_array( $data ) ) {
			return self::fail( __( 'Файл настроек должен возвращать массив параметров.', 'at-smtp' ) );
		}
		return self::normalize( $data );
	}

	/**
	 * Site override of the antispam section: nested "captcha" and "rate_limit" are merged key by key.
	 *
	 * @param array<string, mixed> $base     Shared section.
	 * @param array<string, mixed> $override Site section.
	 * @return array<string, mixed>
	 */
	private static function merge_antispam( array $base, array $override ) {
		foreach ( array( 'captcha', 'rate_limit' ) as $key ) {
			if ( isset( $override[ $key ] ) && is_array( $override[ $key ] ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$override[ $key ] = array_merge( $base[ $key ], $override[ $key ] );
			}
		}
		return array_merge( $base, $override );
	}

	/**
	 * Merge defaults and per-site overrides, resolve placeholders, validate.
	 *
	 * @param array<string, mixed> $data Raw config.
	 * @return array{config: array<string, mixed>|null, error: string, site_override: bool}
	 */
	private static function normalize( array $data ) {
		$host     = self::site_host();
		$override = false;
		$sites    = isset( $data['sites'] ) && is_array( $data['sites'] ) ? $data['sites'] : array();
		$antispam = isset( $data['antispam'] ) && is_array( $data['antispam'] ) ? $data['antispam'] : array();
		unset( $data['sites'], $data['antispam'] );

		foreach ( $sites as $site => $site_config ) {
			if ( is_array( $site_config ) && self::normalize_host( (string) $site ) === $host ) {
				if ( isset( $site_config['antispam'] ) && is_array( $site_config['antispam'] ) ) {
					$antispam = self::merge_antispam( $antispam, $site_config['antispam'] );
				}
				unset( $site_config['antispam'] );
				$data     = array_merge( $data, $site_config );
				$override = true;
				break;
			}
		}

		$c             = array_merge( self::defaults(), array_intersect_key( $data, self::defaults() ) );
		$c['antispam'] = $antispam;

		$c['host']       = trim( (string) $c['host'] );
		$c['encryption'] = strtolower( trim( (string) $c['encryption'] ) );
		if ( '' === $c['encryption'] ) {
			$c['encryption'] = 'none';
		}
		$c['port']              = (int) $c['port'];
		$c['auth']              = (bool) $c['auth'];
		$c['username']          = trim( (string) $c['username'] );
		$c['password']          = (string) $c['password'];
		$c['force_from']        = (bool) $c['force_from'];
		$c['reply_to_original'] = (bool) $c['reply_to_original'];
		$c['auto_tls']          = (bool) $c['auto_tls'];
		$c['verify_ssl']        = (bool) $c['verify_ssl'];
		$c['timeout']           = max( 1, min( 300, (int) $c['timeout'] ) );

		$replace         = array(
			'{site_name}' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'{domain}'    => $host,
		);
		$c['from_email'] = trim( strtr( (string) $c['from_email'], $replace ) );
		$c['from_name']  = trim( strtr( (string) $c['from_name'], $replace ) );

		if ( '' === $c['from_email'] && is_email( $c['username'] ) ) {
			$c['from_email'] = $c['username'];
		}

		if ( '' === $c['host'] ) {
			return self::fail( __( 'В файле настроек не задан параметр host.', 'at-smtp' ) );
		}
		if ( ! in_array( $c['encryption'], array( 'ssl', 'tls', 'none' ), true ) ) {
			return self::fail( __( 'Параметр encryption должен быть ssl, tls или none.', 'at-smtp' ) );
		}
		if ( $c['port'] <= 0 ) {
			$ports     = array(
				'ssl'  => 465,
				'tls'  => 587,
				'none' => 25,
			);
			$c['port'] = $ports[ $c['encryption'] ];
		}
		if ( $c['port'] > 65535 ) {
			return self::fail( __( 'Некорректный порт в параметре port.', 'at-smtp' ) );
		}
		if ( $c['auth'] && '' === $c['username'] ) {
			return self::fail( __( 'Включена авторизация (auth), но не задан username.', 'at-smtp' ) );
		}
		if ( '' !== $c['from_email'] && ! is_email( $c['from_email'] ) ) {
			/* translators: %s: email */
			return self::fail( sprintf( __( 'Некорректный адрес from_email: %s', 'at-smtp' ), $c['from_email'] ) );
		}

		return array(
			'config'        => $c,
			'error'         => '',
			'site_override' => $override,
		);
	}
}
