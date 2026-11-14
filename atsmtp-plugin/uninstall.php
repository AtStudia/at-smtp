<?php
/**
 * Cleanup on uninstall. The shared config file outside the site is never touched.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'atsmtp_settings' );
delete_option( 'atsmtp_last_error' );
delete_option( 'atsmtp_antispam_site' );
delete_option( 'atsmtp_antispam_error' );
delete_option( 'atsmtp_spam_log' );
delete_site_transient( 'atsmtp_github_release' );
