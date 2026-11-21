<?php

// Run with: php test/cookies-chunks.php
// Cookies::set() splits values longer than ~4 KB into "name", "name~1", ...
// Cookies::get() joined them sorted as strings, so with ten or more parts
// "name~10" came before "name~2" and the value came back scrambled.

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

	// Round trip through set(): twelve parts
	$sValue = '';
	for ($i = 0; strlen($sValue) < 12 * 4000; ++$i) {
		$sValue .= sprintf('%08d', $i);
	}
	$Cookies::set('smbig', $sValue);
	check(isset($_COOKIE['smbig~11']), 'value was not split into twelve parts');
	check($sValue === $Cookies::get('smbig'), 'twelve-part value came back scrambled');

	// Parts in any order in the request
	$_COOKIE = ['x~10' => 'K', 'x~2' => 'C', 'x' => 'A', 'x~1' => 'B'];
	foreach (range(3, 9) as $i) {
		$_COOKIE["x~{$i}"] = chr(ord('A') + $i);
	}
	check('ABCDEFGHIJK' === $Cookies::get('x'), 'parts not joined in order: '.$Cookies::get('x'));

	check(null === $Cookies::get('y'), 'missing cookie not null');

	echo "cookies-chunks: ok\n";
}
