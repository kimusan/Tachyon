<?php

// Run with: php test/avatars-glob-address.php
// The avatars plugin put the address it was asked about straight into glob()
// patterns under APP_PRIVATE_DATA. IDN::emailToAscii() leaves glob characters
// and path separators alone, so any logged-in user could ask for
// "x@../configs/application" and get application.ini back as the avatar.
//
// Verified in the Nextcloud test env through the PluginAvatar JSON action:
// before, that address and "x@../configs/*" both returned application.ini as
// text/plain; after, both return nothing, while a custom icon for test.local
// and one for bob.smith+tag@test.local are still served.
//
// The plugin is loaded for real and its two checks called through reflection;
// the source checks pin that every glob() sits behind one of them.

$_ENV['TACHYON_INCLUDE_AS_API'] = true;
require __DIR__ . '/../index.php';
require __DIR__ . '/../plugins/avatars/index.php';

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$call = fn(string $name, string $value) => (new ReflectionMethod('AvatarsPlugin', $name))->invoke(null, $value);

foreach (['example.com', 'xn--bcher-kva.example', 'mail-1.example.co.uk', 'localhost'] as $s) {
	check($call('isPlainHost', $s), "{$s} is refused as a host");
}
foreach (['../configs/application', '..', 'example.com/..', 'a..b', '.example.com', 'example.com.',
	'*', 'ex?mple.com', '[a-z]*', '{a,b}', 'a\\b', 'example.com ', "example.com\n", ''] as $s) {
	check(!$call('isPlainHost', $s), json_encode($s).' is accepted as a host');
}

foreach (['alice@example.com', 'bob.smith+tag@test.local', 'o_k-1@example.com'] as $s) {
	check($call('isPlainAddress', $s), "{$s} is refused as an address");
}
foreach (['x@../configs/application', 'x*@example.com', '../x@example.com', 'a/b@example.com',
	'a@b@example.com', 'example.com', '@example.com', "o'brien@example.com"] as $s) {
	check(!$call('isPlainAddress', $s), json_encode($s).' is accepted as an address');
}

$src = file_get_contents(__DIR__ . '/../plugins/avatars/index.php');
$lines = array_filter(explode("\n", $src), fn($l) => str_contains($l, '\\glob(') && !preg_match('#^\s*//#', $l));
check(4 === count($lines), 'expected four glob() calls, found '.count($lines));
foreach ($lines as $l) {
	// The cache lookup by sha1 of the address is hex, so it needs no check
	check(str_contains($l, '"avatars/{$sEmailId}.*"')
		|| 1 === preg_match('/static::isPlain(Host|Address)\(\$\w+\) \? \\\\glob\(/', $l),
		'glob() without an address check: '.trim($l));
}

// getAvatar() itself stops at a domain that is not a plain host, before any of
// the DNS, URL and file name uses of it.
preg_match('/function getAvatar\(.*?\n\t\}/s', $src, $m);
check(!empty($m[0]), 'could not read getAvatar()');
$iCheck = strpos($m[0], 'if (!static::isPlainHost($sDomain))');
check(false !== $iCheck, 'getAvatar() does not check the domain');
check($iCheck < strpos($m[0], 'getCustomImage('), 'getAvatar() checks the domain after using it');

echo "avatars-glob-address: ok\n";
