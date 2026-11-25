<?php

// Run with: php test/external-sso-key.php
// login-external-sso compared the posted key with ==: not constant-time, and
// two numeric strings compare as numbers, so "1e3" matched a key of "1000".
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
require dirname(__DIR__).'/plugins/login-external-sso/index.php';

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$src = file_get_contents(dirname(__DIR__).'/plugins/login-external-sso/index.php');
check(!preg_match("/\\\$_POST\['SsoKey'\]\s*==/", $src), 'the SSO key is still compared with ==');
check(method_exists(LoginExternalSsoPlugin::class, 'keyMatches'), 'no keyMatches()');
$matches = fn(string $key, $given) => (new ReflectionMethod(LoginExternalSsoPlugin::class, 'keyMatches'))->invoke(null, $key, $given);

check($matches('s3cret-key', 's3cret-key'), 'the right key is refused');
check(!$matches('s3cret-key', 's3cret-kez'), 'a wrong key is accepted');
check(!$matches('1000', '1e3'), '"1e3" matches a key of "1000"');
check(!$matches('0123', '123'), '"123" matches a key of "0123"');
check(!$matches('s3cret-key', ['s3cret-key']), 'an array is accepted');
check(!$matches('', ''), 'an empty key matches when none is configured');

// A wrong key waits like a failed login
check(preg_match("/wrong key'\\);\s*static::faultDelay\(/", $src) === 1, 'a wrong key gets no delay');
$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
$t = microtime(true);
(new ReflectionMethod(LoginExternalSsoPlugin::class, 'faultDelay'))->invoke(null, 1);
check(microtime(true) - $t >= 0.9, 'faultDelay(1) did not wait a second');

echo "external-sso-key: ok\n";
