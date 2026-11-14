<?php
/**
 * Module entry: settings, front-end script, status and log for the plugin's settings page.
 */

namespace AtSmtp\Antispam;

defined( 'ABSPATH' ) || exit;

class Module {

	/** Contract version checked by the AT SMTP plugin. */
	const API = 1;

	const VERSION = '1.1.0';

	const FIELD_HP    = 'atsmtp_hp';
	const FIELD_TOKEN = 'atsmtp_t';

	/** @var array<string, mixed> */
	private $settings;

	/** @var string */
	private $dir;

	/** @var Storage */
	private $storage;

	/** @var Checker */
	private $checker;

	/** @var bool */
	private $booted = false;

	/**
	 * @param array<string, mixed> $settings Section "antispam" of the shared config.
	 * @param array<string, mixed> $env      data_dir: writable shared directory.
	 */
	public function __construct( array $settings, array $env ) {
		$this->settings = self::normalize( $settings );
		$this->dir      = dirname( __DIR__ );
		$this->storage  = new Storage( isset( $env['data_dir'] ) ? (string) $env['data_dir'] : '' );
		// Shipped defaults are replaced on module update; own lists live next to the config file and are kept.
		$this->checker = new Checker( $this->settings, $this->storage, array( $this->dir . '/lists', dirname( $this->dir ) . '/lists' ) );
	}

	/**
	 * @return int
	 */
	public function api() {
		return self::API;
	}

	/**
	 * @return string
	 */
	public function version() {
		return self::VERSION;
	}

	/**
	 * Context => front-end selectors. "click" is set for forms submitted by a button handler instead of the
	 * submit event; "scope" locates the form when that button is rendered outside of it.
	 *
	 * @return array<string, array{form: string, click?: string, scope?: string}>
	 */
	public static function contexts() {
		return array(
			'elementor'    => array( 'form' => 'form.elementor-form' ),
			'cf7'          => array( 'form' => 'form.wpcf7-form' ),
			'wpforms'      => array( 'form' => 'form.wpforms-form' ),
			'qsm'          => array(
				'form'  => 'form.qsm-quiz-form',
				'click' => '.qsm-submit-btn',
				'scope' => '.qmn_quiz_container',
			),
			'comments'     => array( 'form' => 'form#commentform, form.comment-form' ),
			'woo_register' => array( 'form' => 'form.woocommerce-form-register' ),
			'wp_register'  => array( 'form' => 'form#registerform' ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'          => true,
			'mode'             => 'log',
			'min_seconds'      => 3,
			'max_links'        => 2,
			'require_cyrillic' => false,
			'rate_limit'       => array(
				'max'     => 5,
				'minutes' => 10,
			),
			'ip_header'        => '',
			'block_message'    => 'Сообщение не отправлено. Обновите страницу и попробуйте ещё раз.',
			'captcha'          => array(
				'enabled'    => false,
				'client_key' => '',
				'server_key' => '',
				'forms'      => array_keys( self::contexts() ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $s Raw settings.
	 * @return array<string, mixed>
	 */
	private static function normalize( array $s ) {
		$d   = self::defaults();
		$out = array_merge( $d, $s );

		$out['enabled']          = (bool) $out['enabled'];
		$out['mode']             = 'block' === $out['mode'] ? 'block' : 'log';
		$out['min_seconds']      = max( 0, (int) $out['min_seconds'] );
		$out['max_links']        = max( 0, (int) $out['max_links'] );
		$out['require_cyrillic'] = (bool) $out['require_cyrillic'];
		$out['ip_header']        = strtoupper( trim( (string) $out['ip_header'] ) );
		$out['block_message']    = '' !== trim( (string) $out['block_message'] ) ? (string) $out['block_message'] : $d['block_message'];

		$rate              = is_array( $out['rate_limit'] ) ? array_merge( $d['rate_limit'], $out['rate_limit'] ) : $d['rate_limit'];
		$out['rate_limit'] = array(
			'max'     => max( 0, (int) $rate['max'] ),
			'minutes' => max( 1, (int) $rate['minutes'] ),
		);

		$captcha        = is_array( $out['captcha'] ) ? array_merge( $d['captcha'], $out['captcha'] ) : $d['captcha'];
		$out['captcha'] = array(
			'enabled'    => (bool) $captcha['enabled'],
			'client_key' => trim( (string) $captcha['client_key'] ),
			'server_key' => trim( (string) $captcha['server_key'] ),
			'forms'      => array_values( array_intersect( (array) $captcha['forms'], array_keys( self::contexts() ) ) ),
		);
		$out['captcha']['active'] = $out['captcha']['enabled'] && '' !== $out['captcha']['client_key'] && '' !== $out['captcha']['server_key'];

		return $out;
	}

	public function boot() {
		if ( $this->booted || ! $this->settings['enabled'] ) {
			return;
		}
		$this->booted = true;
		add_action( 'wp_footer', array( $this, 'print_script' ), 99 );
		add_action( 'login_footer', array( $this, 'print_script' ), 99 );
		( new Adapters( $this->checker, $this->settings ) )->register();
	}

	public function print_script() {
		if ( is_user_logged_in() ) {
			return;
		}
		$js = @file_get_contents( $this->dir . '/assets/antispam.js' ); // phpcs:ignore
		if ( false === $js ) {
			return;
		}

		$captcha = array();
		if ( $this->settings['captcha']['active'] ) {
			foreach ( $this->settings['captcha']['forms'] as $context ) {
				$captcha[] = self::contexts()[ $context ];
			}
		}
		$protected = array();
		foreach ( self::contexts() as $selectors ) {
			$protected[] = $selectors['form'];
		}

		$cfg = array(
			'key'       => Checker::site_key(),
			'hp'        => self::FIELD_HP,
			'token'     => self::FIELD_TOKEN,
			'protected' => implode( ', ', $protected ),
			'exclude'   => 'form.checkout, form.woocommerce-checkout, form.cart, form.woocommerce-cart-form, form#loginform, form.woocommerce-form-login, form#lostpasswordform, form.lost_reset_password, form.woocommerce-ResetPassword, form[role="search"], form.search-form',
			'captcha'   => $captcha ? array(
				'sitekey' => $this->settings['captcha']['client_key'],
				'forms'   => $captcha,
			) : null,
		);

		echo '<script id="atsmtp-antispam">window.atsmtpAntispam=' . wp_json_encode( $cfg ) . ";\n" . $js . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * @return array<string, mixed>
	 */
	public function status() {
		return array(
			'version'       => self::VERSION,
			'enabled'       => $this->settings['enabled'],
			'mode'          => $this->settings['mode'],
			'shared'        => $this->storage->is_shared(),
			'data_dir'      => $this->storage->data_dir(),
			'captcha'       => $this->settings['captcha']['enabled'],
			'captcha_ready' => $this->settings['captcha']['active'],
			'rate_limit'    => $this->settings['rate_limit'],
			'last_error'    => get_option( Checker::ERROR_OPTION ),
		);
	}

	/**
	 * Latest spam entries of the current site, newest first.
	 *
	 * @param int $limit Max entries.
	 * @return array<int, array<string, mixed>>
	 */
	public function log( $limit = 50 ) {
		return array_slice( $this->storage->local_log(), 0, max( 1, (int) $limit ) );
	}

	public function clear_log() {
		$this->storage->clear_local_log();
		delete_option( Checker::ERROR_OPTION );
	}

	/**
	 * @param string $code Reason code.
	 * @return string
	 */
	public function reason_label( $code ) {
		$labels = array(
			'honeypot'        => 'Заполнено скрытое поле-ловушка',
			'no_js'           => 'Отправлено без JavaScript (нет метки формы)',
			'bad_token'       => 'Поддельная метка формы',
			'too_fast'        => 'Слишком быстрая отправка',
			'no_interaction'  => 'Нет действий пользователя на странице',
			'ip_blacklist'    => 'IP в чёрном списке',
			'email_blacklist' => 'Email в чёрном списке',
			'bbcode'          => 'BBCode-ссылки в тексте',
			'links'           => 'Слишком много ссылок',
			'stopword'        => 'Стоп-слово',
			'no_cyrillic'     => 'Текст без кириллицы',
			'rate_limit'      => 'Превышен лимит отправок с IP',
			'captcha_missing' => 'Капча не пройдена (нет токена)',
			'captcha_failed'  => 'Капча не пройдена',
		);
		return isset( $labels[ $code ] ) ? $labels[ $code ] : $code;
	}

	/**
	 * @param string $context Context code.
	 * @return string
	 */
	public function context_label( $context ) {
		$labels = array(
			'elementor'    => 'Elementor',
			'cf7'          => 'Contact Form 7',
			'wpforms'      => 'WPForms',
			'qsm'          => 'Quiz And Survey Master',
			'comments'     => 'Комментарии / отзывы',
			'woo_register' => 'Регистрация WooCommerce',
			'wp_register'  => 'Регистрация WordPress',
			'other'        => 'Другая форма',
		);
		return isset( $labels[ $context ] ) ? $labels[ $context ] : $context;
	}
}
