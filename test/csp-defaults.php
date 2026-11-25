<?php

// Run with: php test/csp-defaults.php
// Turning on debug logging made the Content-Security-Policy report-only, so
// content shown on the webmail's origin (a proxied SVG opened on its own, say)
// could run script on any site that was being debugged. Report-only now has
// its own switch, and the default policy also limits where forms can post.
define('APP_VERSION', 'test');
define('APP_PRIVATE_DATA', sys_get_temp_dir().'/csp-defaults-'.getmypid().'/');
define('APP_CONFIGURATION_NAME', 'application.ini');
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

$api = file_get_contents(dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/Tachyon/Api.php');
check(!preg_match("/report_only\s*=.*'debug',\s*'enable'/", $api), 'debug.enable still makes the CSP report-only');
check(preg_match("/report_only\s*=.*'security',\s*'csp_report_only'/", $api) === 1, 'no security.csp_report_only switch');
check(false === (new \Tachyon\Config\Application)->Get('security', 'csp_report_only', null), 'csp_report_only is not off by default');

$csp = (string) new \Tachyon\Util\HTTP\CSP;
check(str_contains($csp, "form-action 'self'"), "the default policy lets forms post anywhere: {$csp}");
$csp = (string) new \Tachyon\Util\HTTP\CSP('form-action https://sso.example');
check(str_contains($csp, "form-action 'self' https://sso.example"), "an admin cannot add a form target: {$csp}");

echo "csp-defaults: ok\n";
