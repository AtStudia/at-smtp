<?php
/**
 * Rate-limit counters and spam log: shared data/ directory when writable, otherwise the site's database.
 */

namespace AtSmtp\Antispam;

defined( 'ABSPATH' ) || exit;

class Storage {

	const LOG_OPTION = 'atsmtp_spam_log';
	const LOG_MAX    = 200;

	/** @var string */
	private $dir;

	/** @var bool|null */
	private $shared = null;

	/**
	 * @param string $dir Shared data directory.
	 */
	public function __construct( $dir ) {
		$this->dir = '' !== $dir ? untrailingslashit( wp_normalize_path( $dir ) ) : '';
	}

	/**
	 * @return string
	 */
	public function data_dir() {
		return $this->dir;
	}

	/**
	 * Whether all sites can share counters and the log through the data directory.
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
		$log = $this->local_log();
		array_unshift( $log, $entry );
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_MAX ), false );

		if ( $this->is_shared() ) {
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
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	public function clear_local_log() {
		delete_option( self::LOG_OPTION );
	}
}
