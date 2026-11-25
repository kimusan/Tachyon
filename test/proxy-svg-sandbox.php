<?php

// Run with: php test/proxy-svg-sandbox.php
// The image proxy passed SVG through like any other image. Opened directly,
// an SVG is a document on this origin, which an SVG attachment is not allowed
// to be (Actions\Raw adds a sandbox policy). The proxy now adds the same one.
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

check(method_exists(\Tachyon\ServiceActions::class, 'proxyHeaders'),
	'no ServiceActions::proxyHeaders(): proxied images get no per-type headers');
$headers = fn(string $type) => \Tachyon\ServiceActions::proxyHeaders($type);

foreach (['image/svg+xml', 'IMAGE/SVG+XML', 'image/svg+xml; charset=utf-8'] as $type) {
	check(in_array('Content-Security-Policy: sandbox', $headers($type), true), "proxied {$type} is not sandboxed");
}
foreach (['image/png', 'image/jpeg', 'image/gif'] as $type) {
	check(!in_array('Content-Security-Policy: sandbox', $headers($type), true), "{$type} sandboxed for no reason");
	check(in_array("Content-Type: {$type}", $headers($type), true), "{$type} lost its Content-Type");
}
check(in_array('Cache-Control: private', $headers('image/png'), true), 'a response that needs a login is publicly cacheable');

echo "proxy-svg-sandbox: ok\n";
