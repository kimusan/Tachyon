<?php

// Run with: php test/cookies-host-prefix.php
// Over HTTPS with the whole site as cookie path, cookies get "__Host-" names,
// which a sibling subdomain or a plain-HTTP man in the middle cannot set.
// Planted or left-over cookies of the plain name are ignored and expired.
// Cookies::init() caches, so every case runs in its own process.

namespace Tachyon\Util {
	abstract class Log
	{
		public static function __callStatic(string $name, array $arguments) : void {}
	}
}

namespace Tachyon {
	abstract class Api
	{
		public static array $config = [];
		public static function Config() : object
		{
			return new class {
				public function Get(string $section, string $name, $default = null) {
					return Api::$config["{$section}.{$name}"] ?? $default;
				}
			};
		}
	}
}

namespace {
	function check(bool $condition, string $message): void
	{
		if (!$condition) {
			throw new RuntimeException($message);
		}
	}

	if (isset($argv[1])) {
		// Child: run one case
		[$sCase, $aServer, $aConfig] = json_decode($argv[1], true);
		$_SERVER = $aServer + $_SERVER;
		\Tachyon\Api::$config = $aConfig;
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
		$Cookies = 'Tachyon\\Util\\Cookies';

		if ('https' === $sCase) {
			check('__Host-smaccount' === $Cookies::name('smaccount'), 'no __Host- name over HTTPS');
			check($Cookies::$Secure, '__Host- cookie without Secure');

			// Planted plain-name cookies are not read, and are expired on write
			$_COOKIE = ['smsession' => 'planted', 'smaccount' => 'planted', 'smaccount~1' => 'planted'];
			check(null === $Cookies::get('smsession') && !$Cookies::has('smsession'), 'plain-name cookie read over HTTPS');
			$data = ['email' => 'user@example.com', 'pass' => 'secret'];
			$Cookies::setSecure('smaccount', $data);
			check(isset($_COOKIE['__Host-smaccount'], $_COOKIE['__Host-smsession']), 'cookies not set under __Host- names');
			check(!isset($_COOKIE['smaccount']) && !isset($_COOKIE['smaccount~1']), 'plain-name cookie not expired on write');
			check('planted' !== $_COOKIE['__Host-smsession'], 'planted session value taken over');
			check($data === $Cookies::getSecure('smaccount'), 'round trip under __Host- names broke');

			// Split values keep the prefix on every part
			$sValue = str_repeat('0123456789', 1000);
			$Cookies::set('smbig', $sValue);
			check(isset($_COOKIE['__Host-smbig~2']) && $sValue === $Cookies::get('smbig'), 'split value under __Host- names broke');
			$Cookies::clear('smbig');
			check(!isset($_COOKIE['__Host-smbig']) && !isset($_COOKIE['__Host-smbig~1']), 'clear() left parts behind');
		} else if ('http' === $sCase) {
			check('smaccount' === $Cookies::name('smaccount'), '__Host- name over plain HTTP');
			$_COOKIE = ['smsession' => 'abc'];
			check('abc' === $Cookies::get('smsession'), 'plain cookie not read over HTTP');
		} else if ('subpath' === $sCase) {
			check('smaccount' === $Cookies::name('smaccount'), '__Host- name with a cookie path other than /');
			check($Cookies::$Secure, 'Secure lost with a cookie path');
		} else if ('slash' === $sCase) {
			check('__Host-smaccount' === $Cookies::name('smaccount'), 'cookie path "/" should keep __Host- names');
		}
		echo 'ok';
		exit;
	}

	$aCases = [
		['https', ['HTTPS' => 'on'], []],
		['https', ['HTTP_X_FORWARDED_PROTO' => 'https'], []],
		['http', [], []],
		['subpath', ['HTTPS' => 'on'], ['labs.cookie_default_path' => '/mail/']],
		['slash', ['HTTPS' => 'on'], ['labs.cookie_default_path' => '/']],
	];
	foreach ($aCases as $aCase) {
		$sResult = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg(json_encode($aCase)).' 2>&1');
		check('ok' === $sResult, "{$aCase[0]} ".json_encode($aCase[1]).': '.trim((string) $sResult));
	}

	echo "cookies-host-prefix: ok\n";
}
