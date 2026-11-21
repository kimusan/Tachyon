<?php

// Run with: php test/smtp-ehlo-host.php
// The EHLO name was SERVER_NAME taken as it came, so nginx's catch-all
// `server_name _;` made Tachyon greet with "EHLO _", which a strict SMTP server
// refuses (RFC 5321 4.1.1.1 wants a domain name or an address literal). Each
// candidate is now checked, and the first valid one is used.
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

$ehlo = static function (string $serverName, string $httpHost = '') : string {
	$_SERVER['SERVER_NAME'] = $serverName;
	$_SERVER['HTTP_HOST'] = $httpHost;
	return \MailSo\Smtp\SmtpClient::EhloHelper();
};

foreach ([
	['mail.example.com', '', 'mail.example.com'],
	['_', 'mail.example.com', 'mail.example.com'],
	['_', 'mail.example.com:8443', 'mail.example.com'],
	['192.0.2.10', '', '[192.0.2.10]'],
	['_', '192.0.2.10:8080', '[192.0.2.10]'],
	['_', '[2001:db8::1]:8080', '[IPv6:2001:db8::1]'],
	['2001:db8::1', '', '[IPv6:2001:db8::1]'],
	['bücher.example', '', 'xn--bcher-kva.example'],
	['', 'Webmail.Example.COM', 'webmail.example.com'],
] as [$serverName, $httpHost, $expected]) {
	$result = $ehlo($serverName, $httpHost);
	check($expected === $result, "SERVER_NAME '{$serverName}', Host '{$httpHost}': EHLO {$result}, not {$expected}");
}

// Nothing valid in the request: the machine's name, or localhost
foreach (['_', '*.example.com', 'host name', 'bad_host', 'a..b', '-lead.example'] as $bad) {
	$result = $ehlo($bad, $bad);
	check($result !== $bad && !preg_match('/[_ *]|\.\.|^-/', $result),
		"'{$bad}' got through as EHLO {$result}");
}

echo "smtp-ehlo-host: ok\n";
