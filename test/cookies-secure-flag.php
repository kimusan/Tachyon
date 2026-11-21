<?php

// Run with: php test/cookies-secure-flag.php
// Cookies get the Secure flag on HTTPS, also behind a TLS-terminating proxy
// that says so with X-Forwarded-Proto, and not when HTTPS is "off" (IIS).
// Cookies::init() caches its result, so every case runs in its own process.

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
	function check(bool $condition, string $message): void
	{
		if (!$condition) {
			throw new RuntimeException($message);
		}
	}

	if (isset($argv[1])) {
		// Child: apply the server variables, report Cookies::$Secure
		foreach (json_decode($argv[1], true) as $sKey => $sValue) {
			$_SERVER[$sKey] = $sValue;
		}
		spl_autoload_register(static function (string $class): void {
			$base = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/';
			$file = str_starts_with($class, 'Tachyon\\Util\\')
				? $base.'tachyon_util/'.strtolower(str_replace('\\', '/', substr($class, 13))).'.php'
				: $base.str_replace('\\', '/', $class).'.php';
			if (is_file($file)) {
				require_once $file;
			}
		});
		(new ReflectionMethod('Tachyon\\Util\\Cookies', 'init'))->invoke(null);
		echo \Tachyon\Util\Cookies::$Secure ? 'secure' : 'plain';
		exit;
	}

	$aCases = [
		'plain HTTP'                => [[], 'plain'],
		'HTTPS=on'                  => [['HTTPS' => 'on'], 'secure'],
		'HTTPS=off (IIS)'           => [['HTTPS' => 'off'], 'plain'],
		'X-Forwarded-Proto: https'  => [['HTTP_X_FORWARDED_PROTO' => 'https'], 'secure'],
		'X-Forwarded-Proto: http'   => [['HTTP_X_FORWARDED_PROTO' => 'http'], 'plain'],
		'X-Forwarded-Ssl: on'       => [['HTTP_X_FORWARDED_SSL' => 'on'], 'secure'],
	];
	foreach ($aCases as $sCase => [$aServer, $sExpected]) {
		$sResult = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg(json_encode($aServer ?: new stdClass)));
		check($sExpected === $sResult, "{$sCase}: expected {$sExpected}, got ".var_export($sResult, true));
	}

	echo "cookies-secure-flag: ok\n";
}
