<?php
/**
 * Main plugin: PHPMailer interception, settings page, test email.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATSMTP_Plugin {

	const PAGE = 'at-smtp';

	/** @var self|null */
	private static $instance = null;

	/** @var bool Apply SMTP even when disabled (test email from settings page). */
	private $testing = false;

	/** @var string */
	private $debug_log = '';

	/** @var string[] */
	private $secrets = array();

	/** @var string|null Sender requested by the caller of wp_mail(), before replacement. */
	private $original_from = null;

	/** @var string|null */
	private $original_name = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// wp_mail() validates From before phpmailer_init, so an invalid sender must be replaced here.
		add_filter( 'wp_mail_from', array( $this, 'filter_from' ), PHP_INT_MAX );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), PHP_INT_MAX );
		add_action( 'phpmailer_init', array( $this, 'configure_mailer' ), PHP_INT_MAX );
		add_action( 'wp_mail_failed', array( $this, 'log_failure' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_action( 'admin_notices', array( $this, 'admin_notices' ) );
			add_action( 'admin_post_atsmtp_save', array( $this, 'handle_save' ) );
			add_action( 'admin_post_atsmtp_toggle', array( $this, 'handle_toggle' ) );
			add_action( 'admin_post_atsmtp_test', array( $this, 'handle_test' ) );
			add_action( 'admin_post_atsmtp_antispam_toggle', array( $this, 'handle_antispam_toggle' ) );
			add_action( 'admin_post_atsmtp_antispam_clear', array( $this, 'handle_antispam_clear' ) );
			add_action( 'admin_post_atsmtp_check_updates', array( $this, 'handle_check_updates' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( ATSMTP_FILE ), array( $this, 'action_links' ) );
		}
	}

	public static function activate() {
		if ( false === get_option( ATSMTP_OPTION ) ) {
			add_option( ATSMTP_OPTION, self::defaults() );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'     => 0,
			'config_path' => '',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function settings() {
		$saved = get_option( ATSMTP_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$out                = array_merge( self::defaults(), $saved );
		$out['enabled']     = empty( $out['enabled'] ) ? 0 : 1;
		$out['config_path'] = (string) $out['config_path'];
		return $out;
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private static function save_settings( array $settings ) {
		update_option( ATSMTP_OPTION, array_merge( self::settings(), $settings ) );
	}

	/**
	 * @return array{config: array<string, mixed>|null, error: string, site_override: bool}
	 */
	public static function current_config() {
		return ATSMTP_Config::load( self::settings()['config_path'] );
	}

	/**
	 * Config to apply to the current email, or null when interception is off or the file is invalid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function active_config() {
		if ( ! $this->testing && ! self::settings()['enabled'] ) {
			return null;
		}
		return self::current_config()['config'];
	}

	/**
	 * @param string $from_email Sender passed to wp_mail().
	 * @return string
	 */
	public function filter_from( $from_email ) {
		$c = $this->active_config();
		if ( null === $c || '' === $c['from_email'] ) {
			return $from_email;
		}
		$this->original_from = (string) $from_email;
		if ( $c['force_from'] || ! is_email( $from_email ) ) {
			return $c['from_email'];
		}
		return $from_email;
	}

	/**
	 * @param string $from_name Sender name passed to wp_mail().
	 * @return string
	 */
	public function filter_from_name( $from_name ) {
		$c = $this->active_config();
		if ( null === $c || '' === $c['from_email'] ) {
			return $from_name;
		}
		$this->original_name = (string) $from_name;
		if ( $c['force_from'] && '' !== $c['from_name'] ) {
			return $c['from_name'];
		}
		return $from_name;
	}

	/**
	 * Switch PHPMailer to SMTP using the shared config.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer (passed by reference by WP).
	 */
	public function configure_mailer( $phpmailer ) {
		$c = $this->active_config();
		if ( null === $c ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host        = $c['host'];
		$phpmailer->Port        = $c['port'];
		$phpmailer->SMTPSecure  = 'none' === $c['encryption'] ? '' : $c['encryption'];
		$phpmailer->SMTPAutoTLS = $c['auto_tls'];
		$phpmailer->SMTPAuth    = $c['auth'];
		$phpmailer->Username    = $c['auth'] ? $c['username'] : '';
		$phpmailer->Password    = $c['auth'] ? $c['password'] : '';
		$phpmailer->Timeout     = $c['timeout'];
		$phpmailer->SMTPOptions = $c['verify_ssl'] ? array() : array(
			'ssl' => array(
				'verify_peer'       => false,
				'verify_peer_name'  => false,
				'allow_self_signed' => true,
			),
		);

		$this->apply_from( $phpmailer, $c );

		if ( $this->testing ) {
			$this->secrets = array_filter(
				array(
					$c['password'],
					base64_encode( $c['password'] ),
					base64_encode( "\0" . $c['username'] . "\0" . $c['password'] ),
				)
			);
			$phpmailer->SMTPDebug   = 2;
			$phpmailer->Debugoutput = function ( $str ) {
				$this->debug_log .= rtrim( (string) $str ) . "\n";
			};
		} else {
			$phpmailer->SMTPDebug = 0;
		}
	}

	/**
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer.
	 * @param array<string, mixed>           $c         Config.
	 */
	private function apply_from( $phpmailer, array $c ) {
		if ( '' === $c['from_email'] ) {
			return;
		}
		$orig_email          = null !== $this->original_from ? $this->original_from : (string) $phpmailer->From;
		$orig_name           = null !== $this->original_name ? $this->original_name : (string) $phpmailer->FromName;
		$this->original_from = null;
		$this->original_name = null;

		$phpmailer->Sender = $c['from_email'];
		if ( ! $c['force_from'] ) {
			return;
		}

		$phpmailer->setFrom( $c['from_email'], '' !== $c['from_name'] ? $c['from_name'] : $orig_name, false );

		// Keep the original sender reachable (e.g. visitor address from a contact form).
		if ( $c['reply_to_original']
			&& is_email( $orig_email )
			&& 0 !== strcasecmp( $orig_email, $c['from_email'] )
			&& 0 !== stripos( $orig_email, 'wordpress@' )
			&& ! $phpmailer->getReplyToAddresses()
		) {
			$phpmailer->addReplyTo( $orig_email, $orig_name );
		}
	}

	/**
	 * @param \WP_Error $error Error.
	 */
	public function log_failure( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return;
		}
		update_option(
			ATSMTP_LAST_ERROR,
			array(
				'time'    => time(),
				'message' => $error->get_error_message(),
			),
			false
		);
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------- */

	public function admin_menu() {
		add_options_page( 'AT SMTP', 'AT SMTP', 'manage_options', self::PAGE, array( $this, 'render_page' ) );
	}

	/**
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Настройки', 'at-smtp' ) . '</a>' );
		return $links;
	}

	/**
	 * @param array<string, string> $args Query args.
	 * @return string
	 */
	private static function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'options-general.php' ) );
	}

	public function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && 'settings_page_' . self::PAGE === $screen->id ) {
			return;
		}
		if ( ! self::settings()['enabled'] ) {
			return;
		}
		$loaded = self::current_config();
		if ( null !== $loaded['config'] ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>AT SMTP:</strong> %1$s %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html( $loaded['error'] ),
			esc_html__( 'Письма отправляются стандартным способом WordPress.', 'at-smtp' ),
			esc_url( self::page_url() ),
			esc_html__( 'Открыть настройки', 'at-smtp' )
		);
	}

	private function verify_request( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'at-smtp' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * @param string $msg Message code.
	 */
	private function redirect( $msg ) {
		wp_safe_redirect( self::page_url( array( 'atsmtp_msg' => $msg ) ) );
		exit;
	}

	public function handle_save() {
		$this->verify_request( 'atsmtp_save' );
		$path = isset( $_POST['config_path'] ) ? sanitize_text_field( wp_unslash( $_POST['config_path'] ) ) : '';
		self::save_settings( array( 'config_path' => $path ) );
		$this->redirect( 'saved' );
	}

	public function handle_toggle() {
		$this->verify_request( 'atsmtp_toggle' );
		$enable = ! self::settings()['enabled'];
		self::save_settings( array( 'enabled' => $enable ? 1 : 0 ) );
		$this->redirect( $enable ? 'enabled' : 'disabled' );
	}

	public function handle_antispam_toggle() {
		$this->verify_request( 'atsmtp_antispam_toggle' );
		$enable = ! ATSMTP_Antispam::site_enabled();
		ATSMTP_Antispam::set_site_enabled( $enable );
		$this->redirect( $enable ? 'antispam_on' : 'antispam_off' );
	}

	public function handle_antispam_clear() {
		$this->verify_request( 'atsmtp_antispam_clear' );
		$module = ATSMTP_Antispam::module();
		if ( $module ) {
			$module->clear_log();
		}
		$this->redirect( 'antispam_cleared' );
	}

	public function handle_check_updates() {
		$this->verify_request( 'atsmtp_check_updates' );
		ATSMTP_Updater::flush_cache();
		$remote = ATSMTP_Updater::remote( true );
		$this->redirect( '' !== $remote['error'] ? 'updates_error' : 'updates_checked' );
	}

	public function handle_test() {
		$this->verify_request( 'atsmtp_test' );
		$to = isset( $_POST['test_to'] ) ? sanitize_email( wp_unslash( $_POST['test_to'] ) ) : '';
		if ( ! is_email( $to ) ) {
			$this->redirect( 'bad_email' );
		}
		if ( null === self::current_config()['config'] ) {
			$this->redirect( 'config_error' );
		}

		$error = '';
		$catch = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error->get_error_message();
		};
		add_action( 'wp_mail_failed', $catch );

		$this->testing   = true;
		$this->debug_log = '';
		$host            = ATSMTP_Config::site_host();
		$sent            = wp_mail(
			$to,
			/* translators: %s: site host */
			sprintf( __( 'AT SMTP: тестовое письмо с %s', 'at-smtp' ), $host ),
			/* translators: 1: site url, 2: date */
			sprintf( __( "Это тестовое письмо от плагина AT SMTP.\n\nСайт: %1\$s\nВремя: %2\$s", 'at-smtp' ), home_url(), wp_date( 'Y-m-d H:i:s' ) )
		);
		$this->testing = false;
		remove_action( 'wp_mail_failed', $catch );

		if ( $sent ) {
			delete_option( ATSMTP_LAST_ERROR );
		}

		set_transient(
			'atsmtp_test_' . get_current_user_id(),
			array(
				'sent'  => (bool) $sent,
				'to'    => $to,
				'error' => $error,
				'log'   => str_replace( $this->secrets, '***', $this->debug_log ),
			),
			5 * MINUTE_IN_SECONDS
		);
		$this->redirect( $sent ? 'test_sent' : 'test_failed' );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings   = self::settings();
		$loaded     = self::current_config();
		$last_error = get_option( ATSMTP_LAST_ERROR );
		$test       = get_transient( 'atsmtp_test_' . get_current_user_id() );
		if ( false !== $test ) {
			delete_transient( 'atsmtp_test_' . get_current_user_id() );
		}
		$msg = isset( $_GET['atsmtp_msg'] ) ? sanitize_key( wp_unslash( $_GET['atsmtp_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$antispam_module = ATSMTP_Antispam::module();
		$antispam        = array(
			'site_enabled' => ATSMTP_Antispam::site_enabled(),
			'module'       => $antispam_module,
			'error'        => ATSMTP_Antispam::error(),
			'status'       => $antispam_module ? $antispam_module->status() : array(),
			'log'          => $antispam_module ? $antispam_module->log( 50 ) : array(),
		);
		$updates = array(
			'installed' => ATSMTP_Updater::installed_version(),
			'remote'    => ATSMTP_Updater::remote(),
			'available' => (bool) ATSMTP_Updater::payload(),
			'url'       => ATSMTP_Updater::upgrade_url(),
		);

		require ATSMTP_DIR . 'admin/views/settings.php';
	}
}
