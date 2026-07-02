<?php

// cPanel https://github.com/the-djmaze/snappymail/issues/697
if (defined('APP_PLUGINS_PATH') && !empty($_ENV['CPANEL']) && !is_dir(APP_PLUGINS_PATH.'login-cpanel')) {
	$asApi = !empty($_ENV['TACHYON_INCLUDE_AS_API']);
	$_ENV['TACHYON_INCLUDE_AS_API'] = true;

	$oConfig = \Tachyon\Api::Config();
	$oConfig->Set('plugins', 'enable', true);
	$oConfig->Set('login', 'default_domain', 'cpanel');
	$oConfig->Set('logs', 'path', $_ENV['HOME'] . '/logs/snappymail');
	$oConfig->Set('cache', 'path', $_ENV['TMPDIR'] . '/snappymail');

	\Tachyon\Util\Repository::installPackage('plugin', 'login-cpanel');
	\Tachyon\Util\Repository::enablePackage('login-cpanel');

	$sFile = APP_PRIVATE_DATA.'domains/cpanel.json';
	if (!file_exists($sFile)) {
		$config = json_decode(file_get_contents(__DIR__ . '/app/domains/default.json'), true);
		$config['IMAP']['shortLogin'] = true;
		$config['SMTP']['shortLogin'] = true;
		file_put_contents($sFile, json_encode($config, JSON_PRETTY_PRINT));
	}

//	\Tachyon\Api::Actions()->Plugins()->loadPlugin('login-cpanel');
	if (!isset($_GET['installed'])) {
		\header('Location: ?cPanelAutoLogin&installed');
		exit;
	}

	$_ENV['TACHYON_INCLUDE_AS_API'] = $asApi;
}
