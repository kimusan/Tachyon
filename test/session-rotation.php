<?php

// Run with: php test/session-rotation.php
// A login starts a new session cookie (smsession): the login cookies' key
// hangs on it, so one that existed before the login (made by the mailto flow,
// or planted) must not carry on. The cookies encrypted with the session token
// (the mailto token, smadmin) must survive the change.

namespace Tachyon\Util {
	abstract class Log
	{
		public static function __callStatic(string $name, array $arguments) : void {}
	}
}

namespace Tachyon {
	abstract class Api
	{
		public static function Config() : object
		{
			return new class {
				public function Get(string $section, string $name, $default = null) { return $default; }
			};
		}
	}
}

namespace {
	define('APP_SALT', 'test-salt-'.bin2hex(random_bytes(8)));
	define('APP_VERSION', 'test');
	spl_autoload_register(static function (string $class): void {
		$base = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/';
		$file = str_starts_with($class, 'Tachyon\\Util\\')
			? $base.'tachyon_util/'.strtolower(str_replace('\\', '/', substr($class, 13))).'.php'
			: $base.str_replace('\\', '/', $class).'.php';
		if (is_file($file)) {
			require_once $file;
		}
	});

	function check(bool $condition, string $message): void
	{
		if (!$condition) {
			throw new RuntimeException($message);
		}
	}

	$Utils = 'Tachyon\\Utils';
	$Cookies = 'Tachyon\\Util\\Cookies';

	// A session that existed before the login, e.g. from the mailto flow
	$_COOKIE['smsession'] = 'planted-'.bin2hex(random_bytes(8));
	$sOldToken = $Utils::GetSessionToken(false);
	$aMailTo = ['Time' => 1.5, 'MailTo' => 'MailTo', 'To' => 'mailto:bob@example.com'];
	$aAdmin = ['token', 'abc'];
	$_COOKIE['smmailtoauth'] = $Utils::EncodeKeyValuesQ($aMailTo);
	$_COOKIE['smadmin'] = $Utils::EncodeKeyValuesQ($aAdmin);
	$sOldMailTo = $_COOKIE['smmailtoauth'];

	$Utils::RotateSessionToken();

	check(!str_starts_with($_COOKIE['smsession'], 'planted-'), 'session cookie kept across the login');
	check($sOldToken !== $Utils::GetSessionToken(false), 'session token unchanged');
	check($sOldMailTo !== $_COOKIE['smmailtoauth'], 'mailto token not re-encrypted');
	check($aMailTo === $Utils::DecodeKeyValuesQ($Cookies::get('smmailtoauth')), 'mailto token lost by the rotation');
	check($aAdmin === $Utils::DecodeKeyValuesQ($Cookies::get('smadmin')), 'admin session lost by the rotation');

	// Without such cookies it just starts a session
	unset($_COOKIE['smsession'], $_COOKIE['smmailtoauth'], $_COOKIE['smadmin']);
	$Utils::RotateSessionToken();
	check(!empty($_COOKIE['smsession']), 'no session cookie after the rotation');
	check(!isset($_COOKIE['smmailtoauth']) && !isset($_COOKIE['smadmin']), 'cookies made up by the rotation');

	// Each login a different session
	$sFirst = $_COOKIE['smsession'];
	$Utils::RotateSessionToken();
	check($sFirst !== $_COOKIE['smsession'], 'two logins share a session cookie');

	echo "session-rotation: ok\n";
}
