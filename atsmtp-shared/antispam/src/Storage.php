<?php
/**
 * Rate-limit counters and spam log: shared data/ directory when writable, otherwise the site's database.
 */

namespace AtSmtp\Antispam;

defined( 'ABSPATH' ) || exit;

class Storage {

	const LOG_OPTION = 'atsmtp_spam_log';

	/** @var string */
	private $dir;

	/** @var bool Write spam-YYYY-MM.log into data/. */
	private $file_log;

	/** @var int Max entries in the site's WordPress option. 0 — do not store in the database. */
	private $log_max;

	/** @var bool|null */
	private $shared = null;

	/**
	 * @param string               $dir  Shared data directory.
	 * @param array<string, mixed> $opts file_log, log_max.
	 */
	public function __construct( $dir, array $opts = array() ) {
		$this->dir      = '' !== $dir ? untrailingslashit( wp_normalize_path( $dir ) ) : '';
		$this->file_log = ! array_key_exists( 'file_log', $opts ) || (bool) $opts['file_log'];
		$this->log_max  = array_key_exists( 'log_max', $opts ) ? max( 0, (int) $opts['log_max'] ) : 200;
	}

	/**
	 * @return string
	 */
	public function data_dir() {
		return $this->dir;
	}

	/**
	 * @return bool
	 */
	public function file_log_enabled() {
		return $this->file_log;
	}

	/**
	 * @return int
	 */
	public function log_max() {
		return $this->log_max;
	}

	/**
	 * Whether all sites can share counters (and optionally the file log) through the data directory.
	 *
	 * @return bool
	 */
	public function is_shared() {
		if ( null === $this->shared ) {
			// phpcs:disable WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			$this->shared = '' !== $this->dir
				&& ( @is_dir( $this->dir ) || @mkdir( $this->dir, 0775, true ) )
				&& @is_writable( $this->dir );
			// phpcs:enable
		}
		return $this->shared;
	}

	/**
	 * Register one event for the key and return the number of events within the window.
	 *
	 * @param string $key    Counter key (e.g. IP).
	 * @param int    $window Window in seconds.
	 * @return int
	 */
	public function hit( $key, $window ) {
		$hash = sha1( $key );
		$now  = time();
		if ( $this->is_shared() ) {
			$count = $this->hit_shared( $hash, $window, $now );
			if ( null !== $count ) {
				return $count;
			}
		}
		return $this->hit_local( $hash, $window, $now );
	}

	/**
	 * @return int|null Null when the file cannot be used.
	 */
	private function hit_shared( $hash, $window, $now ) {
		// phpcs:disable WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		$file = $this->dir . '/ratelimit.json';
		$fh   = @fopen( $file, 'c+' );
		if ( ! $fh ) {
			return null;
		}
		if ( ! flock( $fh, LOCK_EX ) ) {
			fclose( $fh );
			return null;
		}
		$data = json_decode( (string) stream_get_contents( $fh ), true );
		$data = is_array( $data ) ? $data : array();

		$from = $now - $window;
		foreach ( $data as $k => $times ) {
			$data[ $k ] = array_values(
				array_filter(
					(array) $times,
					static function ( $t ) use ( $from ) {
						return (int) $t > $from;
					}
				)
			);
			if ( ! $data[ $k ] ) {
				unset( $data[ $k ] );
			}
		}
		$data[ $hash ][] = $now;

		ftruncate( $fh, 0 );
		rewind( $fh );
		fwrite( $fh, (string) wp_json_encode( $data ) );
		fflush( $fh );
		flock( $fh, LOCK_UN );
		fclose( $fh );
		@chmod( $file, 0664 );
		// phpcs:enable
		return count( $data[ $hash ] );
	}

	/**
	 * @return int
	 */
	private function hit_local( $hash, $window, $now ) {
		$name  = 'atsmtp_rl_' . substr( $hash, 0, 32 );
		$times = get_transient( $name );
		$times = is_array( $times ) ? $times : array();
		$from  = $now - $window;
		$times = array_values(
			array_filter(
				$times,
				static function ( $t ) use ( $from ) {
					return (int) $t > $from;
				}
			)
		);
		$times[] = $now;
		set_transient( $name, $times, $window );
		return count( $times );
	}

	/**
	 * @param array<string, mixed> $entry Log entry.
	 */
	public function log( array $entry ) {
		if ( $this->log_max > 0 ) {
			$log = $this->local_log();
			array_unshift( $log, $entry );
			update_option( self::LOG_OPTION, array_slice( $log, 0, $this->log_max ), false );
		} else {
			delete_option( self::LOG_OPTION );
		}

		if ( $this->file_log && $this->is_shared() ) {
			$file = $this->dir . '/spam-' . gmdate( 'Y-m' ) . '.log';
			// phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			if ( false !== @file_put_contents( $file, wp_json_encode( $entry, JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND | LOCK_EX ) ) {
				@chmod( $file, 0664 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function local_log() {
		if ( $this->log_max <= 0 ) {
			return array();
		}
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}
		if ( count( $log ) > $this->log_max ) {
			$log = array_slice( $log, 0, $this->log_max );
			update_option( self::LOG_OPTION, $log, false );
		}
		return $log;
	}

	public function clear_local_log() {
		delete_option( self::LOG_OPTION );
	}
}
