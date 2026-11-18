<?php
/**
 * Plugin Name: AT SMTP
 * Plugin URI:  https://github.com/AtStudia/at-smtp
 * Description: Перехватывает всю почту сайта (wp_mail) и отправляет её через SMTP по единому файлу настроек, расположенному вне папки сайта. Один файл — для всех сайтов хостинга. Общая защита форм от спама.
 * Version:     1.4.0
 * Author:      AT
 * Text Domain: at-smtp
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Update URI:  https://github.com/AtStudia/at-smtp
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ATSMTP_VERSION', '1.4.0' );

if ( ! defined( 'ATSMTP_GITHUB_REPO' ) ) {
	define( 'ATSMTP_GITHUB_REPO', 'AtStudia/at-smtp' );
}
define( 'ATSMTP_FILE', __FILE__ );
define( 'ATSMTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ATSMTP_OPTION', 'atsmtp_settings' );
define( 'ATSMTP_LAST_ERROR', 'atsmtp_last_error' );

require_once ATSMTP_DIR . 'includes/class-atsmtp-config.php';
require_once ATSMTP_DIR . 'includes/class-atsmtp-plugin.php';
require_once ATSMTP_DIR . 'includes/class-atsmtp-antispam.php';
require_once ATSMTP_DIR . 'includes/class-atsmtp-updater.php';

register_activation_hook( __FILE__, array( 'ATSMTP_Plugin', 'activate' ) );

ATSMTP_Plugin::instance();
ATSMTP_Antispam::init();
ATSMTP_Updater::init();
