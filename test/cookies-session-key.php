<?php

// Run with: php test/cookies-session-key.php
// The encrypted login cookies (smaccount, smadditional) are keyed with
// APP_SALT and the session cookie (smsession), no longer with the
// browser-made smctoken: a new session means a new key, and a cookie from
// another session, or keyed the old way, does not decrypt.

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

	$Cookies = 'Tachyon\\Util\\Cookies';
	$Crypt = 'Tachyon\\Util\\Crypt';
	$data = ['email' => 'user@example.com', 'login' => 'user', 'pass' => 'secret'];

	// No session yet (first login): setSecure() starts one and keys to it
	$_COOKIE = [];
	$Cookies::setSecure('smaccount', $data);
	$sSession = $_COOKIE['smsession'] ?? '';
	check(40 === strlen($sSession), 'setSecure() did not create the session cookie');
	check($data === $Cookies::getSecure('smaccount'), 'round trip in the same session broke');

	// The same cookie under another session, or without one, is unreadable
	$sCookie = $_COOKIE['smaccount'];
	$_COOKIE = ['smsession' => sha1('other'), 'smaccount' => $sCookie];
	check(null === $Cookies::getSecure('smaccount'), 'cookie decrypted under another session');
	$_COOKIE = ['smaccount' => $sCookie];
	check(null === $Cookies::getSecure('smaccount'), 'cookie decrypted without a session');
	check(!isset($_COOKIE['smsession']), 'reading a cookie created a session');

	// Back in its own session it still works
	$_COOKIE = ['smsession' => $sSession, 'smaccount' => $sCookie];
	check($data === $Cookies::getSecure('smaccount'), 'cookie unreadable in its own session');

	// Keyed the old way, with smctoken: refused, and nothing creates smctoken
	$sSmctoken = base64_encode(random_bytes(16));
	$_COOKIE = ['smsession' => $sSession, 'smctoken' => $sSmctoken];
	$_COOKIE['smaccount'] = \MailSo\Base\Utils::UrlSafeBase64Encode(
		$Crypt::EncryptToJSON($data, $sSmctoken . APP_VERSION)
	);
	check(null === $Cookies::getSecure('smaccount'), 'smctoken-keyed cookie still decrypts');
	unset($_COOKIE['smctoken']);
	$Cookies::setSecure('smaccount', $data);
	check(!isset($_COOKIE['smctoken']), 'smctoken created again');

	// The key does not depend on smctoken any more, but on APP_SALT
	check(!str_contains(file_get_contents(dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/tachyon_util/crypt.php'), "_COOKIE['smctoken']"),
		'crypt.php still reads smctoken');

	echo "cookies-session-key: ok\n";
}
