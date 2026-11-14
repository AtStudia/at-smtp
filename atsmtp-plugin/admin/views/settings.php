<?php
/**
 * Settings page.
 *
 * @var array<string, mixed>                                                      $settings
 * @var array{config: array<string, mixed>|null, error: string, site_override: bool} $loaded
 * @var array{time: int, message: string}|false                                   $last_error
 * @var array{sent: bool, to: string, error: string, log: string}|false           $test
 * @var string                                                                    $msg
 * @var array{site_enabled: bool, module: object|null, error: string, status: array<string, mixed>, log: array<int, array<string, mixed>>} $antispam
 * @var array{installed: string, remote: array<string, string>, available: bool, url: string} $updates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$atsmtp_messages = array(
	'saved'        => array( 'success', __( 'Путь к файлу настроек сохранён.', 'at-smtp' ) ),
	'bad_email'    => array( 'error', __( 'Укажите корректный адрес для тестового письма.', 'at-smtp' ) ),
	'config_error' => array( 'error', __( 'Тестовое письмо не отправлено: файл настроек не загружен.', 'at-smtp' ) ),
	'test_sent'    => array( 'success', __( 'Тестовое письмо отправлено.', 'at-smtp' ) ),
	'test_failed'  => array( 'error', __( 'Тестовое письмо не отправлено.', 'at-smtp' ) ),
	'antispam_on'      => array( 'success', __( 'Защита от спама на этом сайте включена.', 'at-smtp' ) ),
	'antispam_off'     => array( 'warning', __( 'Защита от спама на этом сайте выключена.', 'at-smtp' ) ),
	'antispam_cleared' => array( 'success', __( 'Журнал спама этого сайта очищен.', 'at-smtp' ) ),
	'updates_checked'  => array( 'success', __( 'Проверка обновлений выполнена.', 'at-smtp' ) ),
	'updates_error'    => array( 'error', __( 'Не удалось проверить обновления на GitHub.', 'at-smtp' ) ),
);
$atsmtp_config = $loaded['config'];
$atsmtp_path   = (string) $settings['config_path'];
$atsmtp_yes_no = static function ( $value ) {
	return $value ? __( 'да', 'at-smtp' ) : __( 'нет', 'at-smtp' );
};
?>
<div class="wrap atsmtp">
	<h1>AT SMTP</h1>

	<?php if ( isset( $atsmtp_messages[ $msg ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $atsmtp_messages[ $msg ][0] ); ?> is-dismissible">
			<p><?php echo esc_html( $atsmtp_messages[ $msg ][1] ); ?></p>
		</div>
	<?php endif; ?>

	<style>
		.atsmtp .atsmtp-status { display: inline-block; padding: 4px 12px; border-radius: 12px; font-weight: 600; margin-right: 12px; }
		.atsmtp .atsmtp-on { background: #d7f2dc; color: #1a6b2c; }
		.atsmtp .atsmtp-off { background: #f0f0f1; color: #50575e; }
		.atsmtp .atsmtp-card { background: #fff; border: 1px solid #c3c4c7; padding: 4px 20px 16px; margin: 16px 0; max-width: 900px; }
		.atsmtp .atsmtp-log { background: #1d2327; color: #e5e5e5; padding: 12px; max-height: 360px; overflow: auto; white-space: pre-wrap; font-size: 12px; }
		.atsmtp .atsmtp-ok { color: #1a6b2c; font-weight: 600; }
		.atsmtp .atsmtp-err { color: #b32d2e; font-weight: 600; }
	</style>

	<div class="atsmtp-card">
		<h2><?php esc_html_e( 'Отправка почты', 'at-smtp' ); ?></h2>
		<?php if ( null !== $atsmtp_config ) : ?>
			<p>
				<span class="atsmtp-status atsmtp-on"><?php esc_html_e( 'Через SMTP', 'at-smtp' ); ?></span>
				<?php
				/* translators: %s: SMTP host and port */
				printf( esc_html__( 'Вся почта сайта отправляется через %s.', 'at-smtp' ), '<code>' . esc_html( $atsmtp_config['host'] . ':' . $atsmtp_config['port'] ) . '</code>' );
				?>
			</p>
		<?php else : ?>
			<p>
				<span class="atsmtp-status atsmtp-off"><?php esc_html_e( 'Стандартно', 'at-smtp' ); ?></span>
				<?php esc_html_e( 'Файл настроек не загружен — письма отправляются стандартным способом WordPress.', 'at-smtp' ); ?>
			</p>
			<?php if ( '' === $atsmtp_path ) : ?>
				<p class="atsmtp-err"><?php esc_html_e( 'Укажите путь к файлу настроек ниже.', 'at-smtp' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="atsmtp-card">
		<h2><?php esc_html_e( 'Файл настроек', 'at-smtp' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="atsmtp_save">
			<?php wp_nonce_field( 'atsmtp_save' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="atsmtp-path"><?php esc_html_e( 'Путь к файлу', 'at-smtp' ); ?></label></th>
					<td>
						<input type="text" id="atsmtp-path" name="config_path" class="large-text code" value="<?php echo esc_attr( $atsmtp_path ); ?>" placeholder="/home/user/smtp/at-smtp-config.php">
						<p class="description">
							<?php esc_html_e( 'Абсолютный путь к файлу .php (возвращает массив) или .json. Образец — config-sample.php в папке плагина.', 'at-smtp' ); ?><br>
							<?php
							/* translators: %s: ABSPATH */
							printf( esc_html__( 'Папка этого сайта: %s', 'at-smtp' ), '<code>' . esc_html( ABSPATH ) . '</code>' );
							?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Сохранить путь', 'at-smtp' ) ); ?>
		</form>

		<?php if ( '' !== $atsmtp_path ) : ?>
			<h3><?php esc_html_e( 'Состояние файла', 'at-smtp' ); ?></h3>
			<?php if ( null === $atsmtp_config ) : ?>
				<p class="atsmtp-err"><?php echo esc_html( $loaded['error'] ); ?></p>
			<?php else : ?>
				<p class="atsmtp-ok"><?php esc_html_e( 'Файл загружен, параметры корректны.', 'at-smtp' ); ?></p>
				<?php if ( ATSMTP_Config::is_inside_site( $atsmtp_path ) ) : ?>
					<p class="atsmtp-err"><?php esc_html_e( 'Внимание: файл находится внутри папки сайта. Вынесите его за пределы сайта, чтобы пароль не был доступен через веб и файл был общим для всех сайтов.', 'at-smtp' ); ?></p>
				<?php endif; ?>
				<table class="widefat striped" style="max-width:600px">
					<tbody>
						<tr><td>SMTP host</td><td><code><?php echo esc_html( $atsmtp_config['host'] . ':' . $atsmtp_config['port'] ); ?></code></td></tr>
						<tr><td><?php esc_html_e( 'Шифрование', 'at-smtp' ); ?></td><td><?php echo esc_html( strtoupper( $atsmtp_config['encryption'] ) ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Авторизация', 'at-smtp' ); ?></td><td><?php echo esc_html( $atsmtp_config['auth'] ? $atsmtp_config['username'] : $atsmtp_yes_no( false ) ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Пароль', 'at-smtp' ); ?></td><td><?php echo '' !== $atsmtp_config['password'] ? esc_html__( 'задан', 'at-smtp' ) : esc_html__( 'не задан', 'at-smtp' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Отправитель', 'at-smtp' ); ?></td><td><?php echo esc_html( trim( $atsmtp_config['from_name'] . ' <' . $atsmtp_config['from_email'] . '>' ) ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Заменять отправителя у всех писем', 'at-smtp' ); ?></td><td><?php echo esc_html( $atsmtp_yes_no( $atsmtp_config['force_from'] ) ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Проверка SSL-сертификата', 'at-smtp' ); ?></td><td><?php echo esc_html( $atsmtp_yes_no( $atsmtp_config['verify_ssl'] ) ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Индивидуальные настройки для этого сайта', 'at-smtp' ); ?></td><td><?php echo esc_html( $atsmtp_yes_no( $loaded['site_override'] ) . ' (' . ATSMTP_Config::site_host() . ')' ); ?></td></tr>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="atsmtp-card">
		<h2><?php esc_html_e( 'Тестовое письмо', 'at-smtp' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Отправляется через SMTP из файла настроек. Показывает журнал SMTP-сессии — помогает найти причину, если почта не уходит.', 'at-smtp' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="atsmtp_test">
			<?php wp_nonce_field( 'atsmtp_test' ); ?>
			<p>
				<input type="email" name="test_to" class="regular-text" required value="<?php echo esc_attr( $test ? $test['to'] : wp_get_current_user()->user_email ); ?>">
				<?php submit_button( __( 'Отправить', 'at-smtp' ), 'secondary', 'submit', false ); ?>
			</p>
		</form>

		<?php if ( $test ) : ?>
			<?php if ( $test['sent'] ) : ?>
				<p class="atsmtp-ok">
					<?php
					/* translators: %s: email */
					printf( esc_html__( 'Письмо принято SMTP-сервером для %s.', 'at-smtp' ), esc_html( $test['to'] ) );
					?>
				</p>
			<?php else : ?>
				<p class="atsmtp-err"><?php echo esc_html( '' !== $test['error'] ? $test['error'] : __( 'Неизвестная ошибка.', 'at-smtp' ) ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $test['log'] ) : ?>
				<details <?php echo $test['sent'] ? '' : 'open'; ?>>
					<summary><?php esc_html_e( 'Журнал SMTP-сессии', 'at-smtp' ); ?></summary>
					<pre class="atsmtp-log"><?php echo esc_html( $test['log'] ); ?></pre>
				</details>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<?php
	$atsmtp_as_module = $antispam['module'];
	$atsmtp_as_status = $antispam['status'];
	?>
	<div class="atsmtp-card">
		<h2><?php esc_html_e( 'Защита от спама', 'at-smtp' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="atsmtp_antispam_toggle">
			<?php wp_nonce_field( 'atsmtp_antispam_toggle' ); ?>
			<span class="atsmtp-status <?php echo $antispam['site_enabled'] ? 'atsmtp-on' : 'atsmtp-off'; ?>">
				<?php echo $antispam['site_enabled'] ? esc_html__( 'Включена на этом сайте', 'at-smtp' ) : esc_html__( 'Выключена на этом сайте', 'at-smtp' ); ?>
			</span>
			<?php submit_button( $antispam['site_enabled'] ? __( 'Выключить', 'at-smtp' ) : __( 'Включить', 'at-smtp' ), 'secondary', 'submit', false ); ?>
		</form>

		<?php if ( null === $atsmtp_as_module ) : ?>
			<p class="atsmtp-err"><?php echo esc_html( $antispam['error'] ); ?></p>
		<?php else : ?>
			<?php if ( '' !== $antispam['error'] ) : ?>
				<p class="atsmtp-err"><?php echo esc_html( $antispam['error'] ); ?></p>
			<?php endif; ?>
			<table class="widefat striped" style="max-width:600px;margin-top:12px">
				<tbody>
					<tr><td><?php esc_html_e( 'Версия модуля', 'at-smtp' ); ?></td><td><?php echo esc_html( $atsmtp_as_status['version'] ); ?></td></tr>
					<tr>
						<td><?php esc_html_e( 'Режим (общий для всех сайтов)', 'at-smtp' ); ?></td>
						<td>
							<?php
							if ( ! $atsmtp_as_status['enabled'] ) {
								esc_html_e( 'выключено в общем файле настроек', 'at-smtp' );
							} elseif ( 'block' === $atsmtp_as_status['mode'] ) {
								esc_html_e( 'блокировка спама', 'at-smtp' );
							} else {
								esc_html_e( 'только журнал — письма не блокируются', 'at-smtp' );
							}
							?>
						</td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Журнал и лимит по IP', 'at-smtp' ); ?></td>
						<td>
							<?php if ( $atsmtp_as_status['shared'] ) : ?>
								<?php esc_html_e( 'общие для всех сайтов:', 'at-smtp' ); ?> <code><?php echo esc_html( $atsmtp_as_status['data_dir'] ); ?></code>
							<?php else : ?>
								<?php esc_html_e( 'только этот сайт — нет права записи в', 'at-smtp' ); ?> <code><?php echo esc_html( $atsmtp_as_status['data_dir'] ); ?></code>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Лимит отправок с одного IP', 'at-smtp' ); ?></td>
						<td>
							<?php
							echo $atsmtp_as_status['rate_limit']['max'] > 0
								/* translators: 1: count, 2: minutes */
								? esc_html( sprintf( __( '%1$d за %2$d мин', 'at-smtp' ), $atsmtp_as_status['rate_limit']['max'], $atsmtp_as_status['rate_limit']['minutes'] ) )
								: esc_html__( 'без ограничения', 'at-smtp' );
							?>
						</td>
					</tr>
					<tr>
						<td>Yandex SmartCaptcha</td>
						<td>
							<?php
							if ( ! $atsmtp_as_status['captcha'] ) {
								esc_html_e( 'выключена', 'at-smtp' );
							} elseif ( $atsmtp_as_status['captcha_ready'] ) {
								esc_html_e( 'включена', 'at-smtp' );
							} else {
								echo '<span class="atsmtp-err">' . esc_html__( 'включена, но не заданы client_key / server_key — не работает', 'at-smtp' ) . '</span>';
							}
							?>
						</td>
					</tr>
					<?php if ( is_array( $atsmtp_as_status['last_error'] ) && ! empty( $atsmtp_as_status['last_error']['message'] ) ) : ?>
						<tr>
							<td><?php esc_html_e( 'Последний сбой проверки', 'at-smtp' ); ?></td>
							<td class="atsmtp-err"><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $atsmtp_as_status['last_error']['time'] ) . ': ' . $atsmtp_as_status['last_error']['message'] ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Журнал спама этого сайта', 'at-smtp' ); ?></h3>
			<?php if ( ! $antispam['log'] ) : ?>
				<p><?php esc_html_e( 'Записей нет.', 'at-smtp' ); ?></p>
			<?php else : ?>
				<table class="widefat striped atsmtp-spam-log">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Время', 'at-smtp' ); ?></th>
							<th><?php esc_html_e( 'Форма', 'at-smtp' ); ?></th>
							<th>IP</th>
							<th><?php esc_html_e( 'Причина', 'at-smtp' ); ?></th>
							<th><?php esc_html_e( 'Действие', 'at-smtp' ); ?></th>
							<th><?php esc_html_e( 'Фрагмент', 'at-smtp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $antispam['log'] as $atsmtp_entry ) : ?>
							<tr>
								<td style="white-space:nowrap"><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $atsmtp_entry['time'] ) ); ?></td>
								<td><?php echo esc_html( $atsmtp_as_module->context_label( (string) $atsmtp_entry['context'] ) ); ?></td>
								<td><?php echo esc_html( (string) $atsmtp_entry['ip'] ); ?></td>
								<td>
									<?php echo esc_html( $atsmtp_as_module->reason_label( (string) $atsmtp_entry['reason'] ) ); ?>
									<?php if ( '' !== (string) $atsmtp_entry['detail'] ) : ?>
										<br><code><?php echo esc_html( (string) $atsmtp_entry['detail'] ); ?></code>
									<?php endif; ?>
								</td>
								<td><?php echo ! empty( $atsmtp_entry['blocked'] ) ? esc_html__( 'заблокировано', 'at-smtp' ) : esc_html__( 'только запись', 'at-smtp' ); ?></td>
								<td><?php echo esc_html( (string) $atsmtp_entry['excerpt'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
					<input type="hidden" name="action" value="atsmtp_antispam_clear">
					<?php wp_nonce_field( 'atsmtp_antispam_clear' ); ?>
					<?php submit_button( __( 'Очистить журнал', 'at-smtp' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<?php if ( is_array( $last_error ) && ! empty( $last_error['message'] ) ) : ?>
		<div class="atsmtp-card">
			<h2><?php esc_html_e( 'Последняя ошибка отправки', 'at-smtp' ); ?></h2>
			<p>
				<strong><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $last_error['time'] ) ); ?>:</strong>
				<?php echo esc_html( $last_error['message'] ); ?>
			</p>
		</div>
	<?php endif; ?>

	<div class="atsmtp-card">
		<h2><?php esc_html_e( 'Обновления', 'at-smtp' ); ?></h2>
		<table class="widefat striped" style="max-width:600px">
			<tbody>
				<tr><td><?php esc_html_e( 'Установлена версия плагина', 'at-smtp' ); ?></td><td><?php echo esc_html( $updates['installed'] ); ?></td></tr>
				<tr>
					<td><?php esc_html_e( 'Последний релиз на GitHub', 'at-smtp' ); ?></td>
					<td>
						<?php if ( '' !== $updates['remote']['error'] ) : ?>
							<span class="atsmtp-err"><?php echo esc_html( $updates['remote']['error'] ); ?></span>
						<?php else : ?>
							<a href="<?php echo esc_url( $updates['remote']['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $updates['remote']['version'] ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( '' !== $updates['remote']['shared'] ) : ?>
					<tr>
						<td><?php esc_html_e( 'Общий модуль антиспама', 'at-smtp' ); ?></td>
						<td>
							<a href="<?php echo esc_url( $updates['remote']['shared'] ); ?>">atsmtp-shared.zip</a>
							<p class="description"><?php esc_html_e( 'Обновляется вручную один раз для всех сайтов: распакуйте архив в общий каталог поверх папки antispam/. Свои списки храните в lists/ рядом с файлом настроек — они не затрагиваются.', 'at-smtp' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
			<input type="hidden" name="action" value="atsmtp_check_updates">
			<?php wp_nonce_field( 'atsmtp_check_updates' ); ?>
			<?php submit_button( __( 'Проверить обновления', 'at-smtp' ), 'secondary', 'submit', false ); ?>
			<?php if ( $updates['available'] ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( $updates['url'] ); ?>">
					<?php
					/* translators: %s: version */
					echo esc_html( sprintf( __( 'Обновить до %s', 'at-smtp' ), $updates['remote']['version'] ) );
					?>
				</a>
			<?php endif; ?>
		</form>
	</div>
</div>
