<?php

// Run with: php test/client-ip-trusted-proxies.php
// With labs.http_client_ip_check_proxy (and in the set-remote-addr plugin)
// the client's address was taken from Client-IP or X-Forwarded-For, whoever
// sent them. A password guesser could pick a new address per request (no
// fail2ban ban) or someone else's (getting them banned). Now only a trusted
// proxy's X-Forwarded-For counts, and only the entries it vouches for.
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

$ip = function (string $remote, ?string $xff, ?string $clientIp = null, string $trusted = '127.0.0.1/8 ::1 10.0.0.0/8'): string {
	$_SERVER = ['REMOTE_ADDR' => $remote];
	null !== $xff && $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
	null !== $clientIp && $_SERVER['HTTP_CLIENT_IP'] = $clientIp;
	$oHttp = \MailSo\Base\Http::SingletonInstance();
	return (new ReflectionMethod($oHttp, 'GetClientIp'))->getNumberOfParameters() > 1
		? $oHttp->GetClientIp(true, $trusted)
		: $oHttp->GetClientIp(true);
};

// A client talking to Tachyon directly
check('198.51.100.7' === $ip('198.51.100.7', '192.0.2.1'), 'a direct client chose its address with X-Forwarded-For');
check('198.51.100.7' === $ip('198.51.100.7', null, '192.0.2.1'), 'a direct client chose its address with Client-IP');

// Behind a trusted proxy: the address it appended, not what the client wrote
check('198.51.100.7' === $ip('127.0.0.1', '198.51.100.7'), 'the proxy\'s client address is not used');
check('198.51.100.7' === $ip('127.0.0.1', '192.0.2.1, 198.51.100.7'), 'an address the client wrote in front wins');
check('198.51.100.7' === $ip('127.0.0.1', '198.51.100.7, 10.1.2.3'), 'a second trusted proxy in the chain is taken as the client');
check('198.51.100.7' === $ip('127.0.0.1', 'garbage, 198.51.100.7'), 'a malformed entry to the left changes the result');
check('127.0.0.1' === $ip('127.0.0.1', 'garbage'), 'a malformed entry is taken as the address');
check('2001:db8::7' === $ip('::1', '2001:db8::7'), 'IPv6 behind a trusted proxy');

// The range check
check(\MailSo\Base\Http::IpInList('10.200.3.4', '10.0.0.0/8'), '10.0.0.0/8 does not contain 10.200.3.4');
check(!\MailSo\Base\Http::IpInList('11.0.0.1', '10.0.0.0/8'), '10.0.0.0/8 contains 11.0.0.1');
check(\MailSo\Base\Http::IpInList('192.168.1.130', '192.168.1.128/25'), '/25 boundary');
check(!\MailSo\Base\Http::IpInList('192.168.1.127', '192.168.1.128/25'), '/25 boundary (outside)');
check(\MailSo\Base\Http::IpInList('2001:db8::1', '2001:db8::/32'), 'IPv6 range');
check(!\MailSo\Base\Http::IpInList('::ffff:10.0.0.1', '10.0.0.0/8'), 'IPv4-mapped IPv6 matched an IPv4 range');
check(!\MailSo\Base\Http::IpInList('not-an-ip', '0.0.0.0/0'), 'garbage matched');

// The plugin uses the same rule
$src = file_get_contents(dirname(__DIR__).'/plugins/set-remote-addr/index.php');
check(str_contains($src, 'ForwardedClientIp(') && !str_contains($src, "\$_SERVER['HTTP_CLIENT_IP']"),
	'the set-remote-addr plugin still trusts any client');

$src = file_get_contents(dirname(__DIR__).'/plugins/add-x-originating-ip-header/index.php');
check(str_contains($src, "Get('plugin', 'trusted_proxies'"), 'the X-Originating-IP plugin has no trusted proxies setting');

echo "client-ip-trusted-proxies: ok\n";
