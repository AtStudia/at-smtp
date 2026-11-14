<?php
/**
 * Hooks into form plugins so spam is rejected before it is saved, plus a catch-all on wp_mail().
 */

namespace AtSmtp\Antispam;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- form plugins verify their own nonces.

class Adapters {

	/** @var Checker */
	private $checker;

	/** @var array<string, mixed> */
	private $s;

	/**
	 * @param Checker              $checker  Checker.
	 * @param array<string, mixed> $settings Normalized settings.
	 */
	public function __construct( Checker $checker, array $settings ) {
		$this->checker = $checker;
		$this->s       = $settings;
	}

	public function register() {
		add_action( 'elementor_pro/forms/validation', array( $this, 'elementor' ), 10, 2 );
		add_filter( 'wpcf7_spam', array( $this, 'cf7' ), 10, 2 );
		add_filter( 'wpforms_process_initial_errors', array( $this, 'wpforms' ), 10, 2 );
		// QSM has no validation filter: run before its own handler (priority 10) and answer in its JSON format.
		add_action( 'wp_ajax_nopriv_qmn_process_quiz', array( $this, 'qsm' ), 1 );
		add_filter( 'pre_comment_approved', array( $this, 'comments' ), 99, 2 );
		add_filter( 'woocommerce_process_registration_errors', array( $this, 'woo_register' ), 10, 4 );
		add_filter( 'registration_errors', array( $this, 'wp_register' ), 10, 3 );
		add_filter( 'pre_wp_mail', array( $this, 'mail' ), 10, 2 );
	}

	/**
	 * @param string $context Context code.
	 * @return bool
	 */
	private function blocked( $context ) {
		return $this->checker->check( $context, $_POST )['block'];
	}

	/**
	 * @return string
	 */
	private function message() {
		return (string) $this->s['block_message'];
	}

	/**
	 * @param mixed $record       Elementor form record.
	 * @param mixed $ajax_handler Elementor AJAX handler.
	 */
	public function elementor( $record, $ajax_handler ) {
		if ( ! $this->blocked( 'elementor' ) || ! is_object( $ajax_handler ) ) {
			return;
		}
		if ( method_exists( $ajax_handler, 'add_error' ) ) {
			$ajax_handler->add_error( 'atsmtp', $this->message() );
		}
		if ( method_exists( $ajax_handler, 'add_error_message' ) ) {
			$ajax_handler->add_error_message( $this->message() );
		}
	}

	/**
	 * @param bool  $spam       Already spam.
	 * @param mixed $submission WPCF7_Submission.
	 * @return bool
	 */
	public function cf7( $spam, $submission = null ) {
		if ( $spam ) {
			return $spam;
		}
		$verdict = $this->checker->check( 'cf7', $_POST );
		if ( ! $verdict['block'] ) {
			return $spam;
		}
		if ( is_object( $submission ) && method_exists( $submission, 'add_spam_log' ) ) {
			$submission->add_spam_log(
				array(
					'agent'  => 'atsmtp',
					'reason' => $verdict['reason'],
				)
			);
		}
		return true;
	}

	/**
	 * @param array<int|string, mixed> $errors    Errors by form ID.
	 * @param array<string, mixed>     $form_data Form data.
	 * @return array<int|string, mixed>
	 */
	public function wpforms( $errors, $form_data ) {
		if ( ! empty( $form_data['id'] ) && $this->blocked( 'wpforms' ) ) {
			$errors                               = is_array( $errors ) ? $errors : array();
			$errors[ $form_data['id'] ]['header'] = esc_html( $this->message() );
		}
		return $errors;
	}

	public function qsm() {
		if ( ! $this->blocked( 'qsm' ) ) {
			return;
		}
		echo wp_json_encode(
			array(
				'display'       => '<div class="qsm-result-page-warning">' . esc_html( $this->message() ) . '</div>',
				'redirect'      => false,
				'result_status' => array( 'save_response' => false ),
			)
		);
		wp_die();
	}

	/**
	 * @param int|string|\WP_Error $approved    Approval status.
	 * @param array<string, mixed> $commentdata Comment data.
	 * @return int|string|\WP_Error
	 */
	public function comments( $approved, $commentdata ) {
		$type = isset( $commentdata['comment_type'] ) ? (string) $commentdata['comment_type'] : '';
		if ( is_wp_error( $approved ) || 'spam' === $approved || 'trash' === $approved
			|| in_array( $type, array( 'pingback', 'trackback' ), true )
			|| ! isset( $_POST['comment_post_ID'] )
		) {
			return $approved;
		}
		return $this->blocked( 'comments' ) ? 'spam' : $approved;
	}

	/**
	 * @param \WP_Error $errors   Errors.
	 * @param string    $username Username.
	 * @param string    $password Password.
	 * @param string    $email    Email.
	 * @return \WP_Error
	 */
	public function woo_register( $errors, $username = '', $password = '', $email = '' ) {
		if ( is_wp_error( $errors ) && $this->blocked( 'woo_register' ) ) {
			$errors->add( 'atsmtp_spam', esc_html( $this->message() ) );
		}
		return $errors;
	}

	/**
	 * @param \WP_Error $errors Errors.
	 * @param string    $login  Login.
	 * @param string    $email  Email.
	 * @return \WP_Error
	 */
	public function wp_register( $errors, $login = '', $email = '' ) {
		if ( is_wp_error( $errors ) && isset( $_POST['user_login'] ) && $this->blocked( 'wp_register' ) ) {
			$errors->add( 'atsmtp_spam', esc_html( $this->message() ) );
		}
		return $errors;
	}

	/**
	 * Catch-all for forms without an adapter. Only requests from forms prepared by the front-end script are
	 * checked: system emails (orders, payment webhooks, password resets) never carry the marker fields.
	 *
	 * @param null|bool            $return Short-circuit value.
	 * @param array<string, mixed> $atts   wp_mail() arguments.
	 * @return null|bool
	 */
	public function mail( $return, $atts ) {
		if ( null !== $return || ( ! $this->checker->checked() && ! $this->checker->has_marker( $_POST ) ) ) {
			return $return;
		}
		// Pretend success so the bot gets no signal; the reason is in the log.
		return $this->blocked( 'other' ) ? true : $return;
	}
}
