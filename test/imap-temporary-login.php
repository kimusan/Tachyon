<?php

// Run with: php test/imap-temporary-login.php
// A server that refuses LOGIN with an RFC 5530 code saying the fault is its own
// (UNAVAILABLE, INUSE, SERVERBUG) was reported as a wrong password. Only a
// refusal without such a code is bad credentials now.
spl_autoload_register(static function (string $class): void {
	$base = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/';
	$file = str_starts_with($class, 'Tachyon\\Util\\')
		? $base.'tachyon_util/'.strtolower(str_replace('\\', '/', substr($class, 13))).'.php'
		: $base.str_replace('\\', '/', $class).'.php';
	if (is_file($file)) {
		require_once $file;
	}
});

// Only what SSLContext and SensitiveString ask of it: configuration
// defaults, and somewhere for the password mask to go
eval('namespace Tachyon; final class Api {
	public static function Config() { return new class { public function Get($s, $n, $d = null) { return $d; } }; }
	public static function Actions() { return new class { public function logMask($v) {} }; }
}');

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

// The server: a greeting, CAPABILITY, and the given answer to LOGIN
if ('serve' === ($argv[1] ?? '')) {
	$server = stream_socket_server('tcp://127.0.0.1:0');
	echo stream_socket_get_name($server, false), "\n";
	fflush(STDOUT);
	$conn = stream_socket_accept($server, 10);
	fwrite($conn, "* OK [CAPABILITY IMAP4rev1] ready\r\n");
	while (false !== ($line = fgets($conn))) {
		[$tag, $command] = explode(' ', trim($line), 3) + ['', ''];
		if ('CAPABILITY' === strtoupper($command)) {
			fwrite($conn, "* CAPABILITY IMAP4rev1\r\n{$tag} OK done\r\n");
		} else if ('LOGIN' === strtoupper($command)) {
			fwrite($conn, "{$tag} NO {$argv[2]}\r\n");
		} else {
			fwrite($conn, "{$tag} BAD unexpected\r\n");
		}
	}
	exit;
}

$login = static function (string $answer) : \Throwable {
	$server = proc_open([PHP_BINARY, __FILE__, 'serve', $answer], [1 => ['pipe', 'w']], $pipes);
	[$host, $port] = explode(':', trim(fgets($pipes[1])));
	$imap = new \MailSo\Imap\ImapClient;
	$settings = new \MailSo\Imap\Settings;
	$settings->host = $host;
	$settings->port = (int) $port;
	$settings->type = \MailSo\Net\Enumerations\ConnectionSecurityType::NONE;
	$settings->SASLMechanisms = ['LOGIN'];
	$settings->username = 'user@example.com';
	$settings->passphrase = new \Tachyon\Util\SensitiveString('secret');
	try {
		$imap->Connect($settings);
		$imap->Login($settings);
		throw new RuntimeException("LOGIN answered with '{$answer}' succeeded");
	} catch (\MailSo\Imap\Exceptions\ResponseException $e) {
		return $e;
	} finally {
		$imap->Disconnect();
		proc_close($server);
	}
};

foreach (['[UNAVAILABLE] Try again later', '[INUSE] Mailbox locked', '[serverbug] Internal error'] as $answer) {
	$e = $login($answer);
	check(!$e instanceof \MailSo\Imap\Exceptions\LoginBadCredentialsException, "'{$answer}' was reported as bad credentials");
	check($e instanceof \MailSo\Imap\Exceptions\NegativeResponseException && $e->isTemporaryLoginFailure(),
		"'{$answer}' was not reported as a temporary failure");
}

foreach (['[AUTHENTICATIONFAILED] Invalid credentials', 'Login failed', '[ALERT] Account disabled'] as $answer) {
	check($login($answer) instanceof \MailSo\Imap\Exceptions\LoginBadCredentialsException,
		"'{$answer}' was not reported as bad credentials");
}

echo "imap-temporary-login: ok\n";
