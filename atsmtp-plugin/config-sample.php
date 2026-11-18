<?php
/**
 * Образец общего файла настроек AT SMTP.
 *
 * Скопируйте файл в папку ВНЕ сайтов (например, /home/user/smtp/at-smtp-config.php),
 * заполните параметры и укажите этот путь в «Настройки → AT SMTP» на каждом сайте.
 * Файл должен быть доступен на чтение пользователю, от имени которого работает PHP.
 *
 * В from_email и from_name можно использовать подстановки:
 *   {site_name} — название сайта, {domain} — домен сайта без www.
 *
 * Антиспам: рядом с этим файлом положите каталог antispam/ (из комплекта atsmtp-shared/).
 * Если рядом есть каталог data/ с правом записи для всех сайтов, журнал и лимит по IP будут общими;
 * иначе каждый сайт хранит их в своей базе.
 *   /home/user/atsmtp-shared/config.php
 *   /home/user/atsmtp-shared/antispam/
 *   /home/user/atsmtp-shared/lists/      — свои стоп-слова и чёрные списки (не затираются обновлением)
 *   /home/user/atsmtp-shared/data/
 */

defined( 'ABSPATH' ) || exit;

return array(
	'host'              => 'smtp.yandex.ru',
	'port'              => 465,           // 0 — по умолчанию: ssl 465, tls 587, none 25.
	'encryption'        => 'ssl',         // ssl | tls | none.
	'auth'              => true,
	'username'          => 'noreply@example.com',
	'password'          => 'app-password',
	'from_email'        => 'noreply@example.com', // Пусто — используется username.
	'from_name'         => '{site_name}',
	'force_from'        => true,          // Подменять отправителя у всех писем (большинство SMTP-серверов требуют совпадения с username).
	'reply_to_original' => true,          // Исходного отправителя (например, посетителя из формы) ставить в Reply-To.
	'timeout'           => 15,
	'auto_tls'          => true,
	'verify_ssl'        => true,

	// Защита от спама для форм: Elementor Pro, Contact Form 7, WPForms, Quiz And Survey Master,
	// комментарии и отзывы WooCommerce, регистрация WooCommerce и WordPress.
	'antispam'          => array(
		'enabled'          => true,
		'mode'             => 'log',  // log — только записывать в журнал; block — блокировать.
		'min_seconds'      => 3,      // Быстрее — считается ботом.
		'max_links'        => 2,      // Больше ссылок на чужие сайты — спам.
		'require_cyrillic' => false,  // true — текст без единой русской буквы считается спамом.
		'file_log'         => true,   // false — не писать data/spam-ГГГГ-ММ.log (лимит по IP в data/ всё равно работает).
		'log_max'          => 200,    // Сколько записей спама хранить в базе WordPress сайта. 0 — не хранить.
		'rate_limit'       => array(
			'max'     => 5,          // Не больше 5 отправок с одного IP...
			'minutes' => 10,         // ...за 10 минут. 0 в max — без ограничения.
		),
		'ip_header'        => '',     // Сайт за прокси/CDN: 'HTTP_X_FORWARDED_FOR' или 'HTTP_CF_CONNECTING_IP'.
		'block_message'    => 'Сообщение не отправлено. Обновите страницу и попробуйте ещё раз.',

		// Yandex SmartCaptcha (невидимая). Включайте, если базовой защиты недостаточно.
		// Ключи: console.yandex.cloud → SmartCaptcha; укажите в капче домены всех сайтов.
		'captcha'          => array(
			'enabled'    => false,
			'client_key' => '',
			'server_key' => '',
			// Где проверять. Для тестов QSM с таймером или автоотправкой уберите 'qsm':
			// такие тесты отправляются без нажатия кнопки, и капча не успевает пройти.
			'forms'      => array( 'elementor', 'cf7', 'wpforms', 'qsm', 'comments', 'woo_register', 'wp_register' ),
		),
	),

	// Необязательно: индивидуальные параметры для отдельных сайтов (ключ — домен без www).
	// Секцию antispam тоже можно переопределить, например 'antispam' => array( 'mode' => 'block' ).
	'sites'             => array(
		// 'shop.example.org' => array(
		//     'username'   => 'shop@example.org',
		//     'password'   => 'other-password',
		//     'from_email' => 'shop@example.org',
		// ),
	),
);
