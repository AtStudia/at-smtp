<?php
/**
 * Spam checks for a form submission. One verdict per request: the first adapter that checks wins.
 */

namespace AtSmtp\Antispam;

defined( 'ABSPATH' ) || exit;

class Checker {

	const ERROR_OPTION = 'atsmtp_antispam_error';
	const CAPTCHA_URL  = 'https://smartcaptcha.cloud.yandex.ru/validate';

	/** Keys never treated as visitor text. */
	const SKIP_KEYS = array(
		'action',
		'referrer',
		'referer_title',
		'queried_id',
		'post_id',
		'form_id',
		'page_url',
		'page_title',
		'url_referer',
		'redirect_to',
		'comment_post_id',
		'comment_parent',
		'qmn_quiz_id',
		'complete_quiz',
		'currentusertime',
		'currentusertimezone',
		'timer',
		'timer_ms',
		'total_questions',
		'quiz_start_date',
	);

	/** @var array<string, mixed> */
	private $s;

	/** @var Storage */
	private $storage;

	/** @var string[] */
	private $lists_dirs;

	/** @var array<string, mixed>|null */
	private $verdict = null;

	/** @var array<string, string[]> */
	private $lists = array();

	/**
	 * @param array<string, mixed> $settings  Normalized settings.
	 * @param Storage              $storage   Storage.
	 * @param string[]             $lists_dirs Directories with *.txt lists, merged.
	 */
	public function __construct( array $settings, Storage $storage, array $lists_dirs ) {
		$this->s          = $settings;
		$this->storage    = $storage;
		$this->lists_dirs = $lists_dirs;
	}

	/**
	 * Per-site key embedded in the front-end token. Static per site, so it survives full-page caching.
	 *
	 * @return string
	 */
	public static function site_key() {
		return substr( hash_hmac( 'sha256', 'atsmtp-antispam|' . home_url(), wp_salt( 'nonce' ) ), 0, 16 );
	}

	/**
	 * Whether the submitted data came from a form prepared by the front-end script.
	 *
	 * @param array<string, mixed> $data Request data.
	 * @return bool
	 */
	public function has_marker( array $data ) {
		return isset( $data[ Module::FIELD_TOKEN ] ) || isset( $data[ Module::FIELD_HP ] );
	}

	/**
	 * @return bool
	 */
	public function checked() {
		return null !== $this->verdict;
	}

	/**
	 * @param string               $context Form context code.
	 * @param array<string, mixed> $data    Raw (slashed) request data, usually $_POST.
	 * @return array{spam: bool, block: bool, reason: string, detail: string, context: string}
	 */
	public function check( $context, array $data ) {
		if ( null !== $this->verdict ) {
			return $this->verdict;
		}
		$verdict = array(
			'spam'    => false,
			'block'   => false,
			'reason'  => '',
			'detail'  => '',
			'context' => (string) $context,
		);
		if ( is_user_logged_in() ) {
			$this->verdict = $verdict;
			return $verdict;
		}

		$data = wp_unslash( $data );
		$text = '';
		$hit  = null;
		try {
			$text = $this->text( $data );
			$hit  = $this->run( (string) $context, $data, $text );
		} catch ( \Throwable $e ) {
			update_option(
				self::ERROR_OPTION,
				array(
					'time'    => time(),
					'message' => $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')',
				),
				false
			);
		}

		if ( $hit ) {
			$verdict['spam']   = true;
			$verdict['block']  = 'block' === $this->s['mode'];
			$verdict['reason'] = $hit[0];
			$verdict['detail'] = isset( $hit[1] ) ? (string) $hit[1] : '';
			$this->storage->log(
				array(
					'time'    => time(),
					'host'    => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
					'context' => $verdict['context'],
					'ip'      => $this->client_ip(),
					'reason'  => $verdict['reason'],
					'detail'  => $verdict['detail'],
					'blocked' => $verdict['block'],
					'excerpt' => self::cut( $text, 200 ),
					'agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? self::cut( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 150 ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				)
			);
		}

		$this->verdict = $verdict;
		return $verdict;
	}

	/**
	 * @param string               $context Context.
	 * @param array<string, mixed> $data    Unslashed data.
	 * @param string               $text    Visitor text.
	 * @return array{0: string, 1?: string}|null Reason and detail, or null when clean.
	 */
	private function run( $context, array $data, $text ) {
		$hp = isset( $data[ Module::FIELD_HP ] ) ? $data[ Module::FIELD_HP ] : '';
		if ( is_string( $hp ) && '' !== trim( $hp ) ) {
			return array( 'honeypot', self::cut( $hp, 50 ) );
		}

		$token = isset( $data[ Module::FIELD_TOKEN ] ) && is_string( $data[ Module::FIELD_TOKEN ] ) ? $data[ Module::FIELD_TOKEN ] : '';
		if ( '' === $token ) {
			return array( 'no_js' );
		}
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) || ! hash_equals( self::site_key(), $parts[0] ) ) {
			return array( 'bad_token' );
		}
		if ( (int) $parts[1] < $this->s['min_seconds'] ) {
			return array( 'too_fast', (int) $parts[1] . ' с' );
		}
		if ( (int) $parts[2] < 1 ) {
			return array( 'no_interaction' );
		}

		$ip = $this->client_ip();
		if ( '' !== $ip ) {
			foreach ( $this->read_list( 'blacklist-ip' ) as $range ) {
				if ( self::ip_matches( $ip, $range ) ) {
					return array( 'ip_blacklist', $range );
				}
			}
		}

		$listed = $this->listed_email( $text );
		if ( '' !== $listed ) {
			return array( 'email_blacklist', $listed );
		}

		$content = $this->check_content( $text );
		if ( $content ) {
			return $content;
		}

		$rate = $this->s['rate_limit'];
		if ( '' !== $ip && $rate['max'] > 0 ) {
			$count = $this->storage->hit( 'ip:' . $ip, $rate['minutes'] * 60 );
			if ( $count > $rate['max'] ) {
				return array( 'rate_limit', $count . ' за ' . $rate['minutes'] . ' мин' );
			}
		}

		if ( $this->s['captcha']['active'] && in_array( $context, $this->s['captcha']['forms'], true ) ) {
			$captcha = isset( $data['smart-token'] ) && is_string( $data['smart-token'] ) ? $data['smart-token'] : '';
			if ( '' === $captcha ) {
				return array( 'captcha_missing' );
			}
			if ( ! $this->captcha_passed( $captcha, $ip ) ) {
				return array( 'captcha_failed' );
			}
		}

		return null;
	}

	/**
	 * @param string $text Visitor text.
	 * @return array{0: string, 1?: string}|null
	 */
	private function check_content( $text ) {
		if ( '' === $text ) {
			return null;
		}
		if ( preg_match( '~\[(url|link)[=\]]~i', $text ) ) {
			return array( 'bbcode' );
		}

		$own   = self::bare_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$links = 0;
		if ( preg_match_all( '~(?:https?://|www\.)([^\s/"\'<>?#:]+)~i', $text, $m ) ) {
			foreach ( $m[1] as $host ) {
				if ( self::bare_host( $host ) !== $own ) {
					++$links;
				}
			}
		}
		if ( $links > $this->s['max_links'] ) {
			return array( 'links', (string) $links );
		}

		foreach ( $this->read_list( 'stopwords' ) as $word ) {
			$found = function_exists( 'mb_stripos' ) ? mb_stripos( $text, $word ) : stripos( $text, $word );
			if ( false !== $found ) {
				return array( 'stopword', $word );
			}
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $this->s['require_cyrillic'] && $length >= 20 && ! preg_match( '/\p{Cyrillic}/u', $text ) ) {
			return array( 'no_cyrillic' );
		}
		return null;
	}

	/**
	 * @param string $text Visitor text.
	 * @return string Matched list entry or empty string.
	 */
	private function listed_email( $text ) {
		$list = $this->read_list( 'blacklist-email' );
		if ( ! $list || ! preg_match_all( '/[A-Z0-9._%+\-]+@([A-Z0-9.\-]+\.[A-Z]{2,})/i', $text, $m, PREG_SET_ORDER ) ) {
			return '';
		}
		foreach ( $m as $match ) {
			$email  = strtolower( $match[0] );
			$domain = strtolower( $match[1] );
			foreach ( $list as $entry ) {
				$entry = strtolower( $entry );
				if ( false !== strpos( $entry, '@' ) && '@' !== $entry[0] ) {
					if ( $email === $entry ) {
						return $entry;
					}
					continue;
				}
				$entry = ltrim( $entry, '@' );
				if ( $domain === $entry || substr( $domain, -strlen( '.' . $entry ) ) === '.' . $entry ) {
					return $entry;
				}
			}
		}
		return '';
	}

	/**
	 * Yandex SmartCaptcha server-side validation. Transport errors count as passed, as Yandex recommends.
	 *
	 * @param string $token Token from the "smart-token" field.
	 * @param string $ip    Visitor IP.
	 * @return bool
	 */
	private function captcha_passed( $token, $ip ) {
		$response = wp_remote_post(
			self::CAPTCHA_URL,
			array(
				'timeout' => 5,
				'body'    => array(
					'secret' => $this->s['captcha']['server_key'],
					'token'  => $token,
					'ip'     => $ip,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return true;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return ! is_array( $body ) || ! isset( $body['status'] ) || 'failed' !== $body['status'];
	}

	/**
	 * All visitor-entered values joined into one string.
	 *
	 * @param array<string, mixed> $data Unslashed data.
	 * @return string
	 */
	private function text( array $data ) {
		$parts = array();
		array_walk_recursive(
			$data,
			static function ( $value, $key ) use ( &$parts ) {
				$key = strtolower( (string) $key );
				if ( ! is_scalar( $value ) || '' === $key || '_' === $key[0]
					|| in_array( $key, self::SKIP_KEYS, true )
					|| preg_match( '/nonce|token|captcha|atsmtp|unique_key|pass|pwd/', $key )
				) {
					return;
				}
				$value = trim( (string) $value );
				if ( '' !== $value ) {
					$parts[] = $value;
				}
			}
		);
		return implode( "\n", $parts );
	}

	/**
	 * @return string
	 */
	private function client_ip() {
		$candidates = array();
		if ( '' !== $this->s['ip_header'] && ! empty( $_SERVER[ $this->s['ip_header'] ] ) ) {
			$candidates = explode( ',', (string) wp_unslash( $_SERVER[ $this->s['ip_header'] ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidates[] = (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		foreach ( $candidates as $ip ) {
			$ip = trim( $ip );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return '';
	}

	/**
	 * Exact IP or CIDR range, IPv4 and IPv6.
	 *
	 * @param string $ip    IP.
	 * @param string $range "1.2.3.4" or "1.2.3.0/24".
	 * @return bool
	 */
	private static function ip_matches( $ip, $range ) {
		$bits = null;
		if ( false !== strpos( $range, '/' ) ) {
			list( $range, $bits ) = explode( '/', $range, 2 );
			$bits                 = (int) $bits;
		}
		$a = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$b = @inet_pton( trim( $range ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
			return false;
		}
		if ( null === $bits ) {
			return $a === $b;
		}
		$bits  = max( 0, min( strlen( $a ) * 8, $bits ) );
		$bytes = intdiv( $bits, 8 );
		if ( substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
	}

	/**
	 * Non-empty, non-comment lines of {name}.txt from every lists directory.
	 *
	 * @param string $name List name.
	 * @return string[]
	 */
	private function read_list( $name ) {
		if ( ! isset( $this->lists[ $name ] ) ) {
			$list = array();
			foreach ( $this->lists_dirs as $dir ) {
				$lines = @file( $dir . '/' . $name . '.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore
				foreach ( (array) $lines as $line ) {
					$line = trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $line ) );
					if ( '' !== $line && '#' !== $line[0] ) {
						$list[] = $line;
					}
				}
			}
			$this->lists[ $name ] = array_values( array_unique( $list ) );
		}
		return $this->lists[ $name ];
	}

	/**
	 * @param string $host Host.
	 * @return string
	 */
	private static function bare_host( $host ) {
		$host = strtolower( $host );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * @param string $text Text.
	 * @param int    $len  Max length.
	 * @return string
	 */
	private static function cut( $text, $len ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $len ) : substr( $text, 0, $len );
	}
}
