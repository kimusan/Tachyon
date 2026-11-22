<?php

// Run with: php test/crypt-client-tokens.php
// Tokens that come from the client (cookies) must be sodium tokens when sodium
// is available: the openssl ciphers are CBC without a MAC, so a modified
// ciphertext decrypts to modified data. Data the server stored itself may
// predate sodium and must stay readable.

// Same stand-ins as crypt-forgery.php: Log and Api need the whole application.
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
	define('APP_SALT', 'test-salt-'.bin2hex(random_bytes(8)));
	define('APP_VERSION', 'test');
	$_COOKIE['smsession'] = bin2hex(random_bytes(20));
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

	$Crypt = 'Tachyon\\Util\\Crypt';

	check(is_callable('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt'), 'test needs sodium');
	check($Crypt::openSSLUsable(), 'test needs a usable openssl cipher');

	$data = ['e' => 'user@example.com', 'pass' => 'secret'];
	$json = json_encode($data);

	// An openssl token as a server without sodium would have written it.
	$cipher = (new ReflectionProperty($Crypt, 'cipher'))->getValue();
	$iv = random_bytes(openssl_cipher_iv_length($cipher));
	$openssl = ['openssl', $iv, $Crypt::OpenSSLEncrypt($json, $iv, 'server-key')];

	// The reason for the change: CBC without a MAC accepts a modified token.
	$tampered = $openssl;
	$tampered[1][0] = chr(ord($tampered[1][0]) ^ 1);
	$plain = $Crypt::OpenSSLDecrypt($tampered[2], $tampered[1], 'server-key');
	check(is_string($plain) && $plain !== $json, 'openssl unexpectedly detected the modification');

	// Stored server-side: still readable.
	check($data === $Crypt::Decrypt($openssl, 'server-key'), 'stored openssl data no longer decrypts');

	// From the client: refused, through every entry point.
	check(null === $Crypt::Decrypt($openssl, 'server-key', true), 'openssl token from the client accepted');
	check(null === $Crypt::Decrypt(['OpenSSL', $iv, $openssl[2]], 'server-key', true), 'algorithm name case bypasses the check');
	$sJson = json_encode(array_map('base64_encode', $openssl));
	check($data === $Crypt::DecryptFromJSON($sJson, 'server-key'), 'stored openssl JSON no longer decrypts');
	check(null === $Crypt::DecryptFromJSON($sJson, 'server-key', true), 'openssl JSON token from the client accepted');
	$sUrl = implode('.', array_map('MailSo\\Base\\Utils::UrlSafeBase64Encode', $openssl));
	check(null === $Crypt::DecryptUrlSafe($sUrl, 'server-key', true), 'openssl URL-safe token from the client accepted');

	// Sodium tokens from the client keep working, only with the right key.
	check($data === $Crypt::Decrypt($Crypt::Encrypt($data, 'server-key'), 'server-key', true), 'sodium token from the client refused');
	check(null === $Crypt::Decrypt($Crypt::Encrypt($data, 'server-key'), 'other-key', true), 'sodium token decrypted with the wrong key');

	// Cookies: getSecure() treats the cookie as coming from the client.
	$Cookies = 'Tachyon\\Util\\Cookies';
	$_COOKIE['smtest'] = \MailSo\Base\Utils::UrlSafeBase64Encode($Crypt::EncryptToJSON($data));
	check($data === $Cookies::getSecure('smtest'), 'sodium cookie no longer decrypts');
	$iv = random_bytes(openssl_cipher_iv_length($cipher));
	$_COOKIE['smtest'] = \MailSo\Base\Utils::UrlSafeBase64Encode(json_encode(array_map('base64_encode',
		['openssl', $iv, $Crypt::OpenSSLEncrypt($json, $iv)]
	)));
	check(null === $Cookies::getSecure('smtest'), 'openssl cookie accepted');

	// Utils::DecodeKeyValuesQ(): the admin cookie and the mailto token
	$Utils = 'Tachyon\\Utils';
	$sQKey = sha1(APP_SALT.'Q'.$Utils::GetSessionToken(false));
	$aValues = ['token', 'abc'];
	check($aValues === $Utils::DecodeKeyValuesQ($Utils::EncodeKeyValuesQ($aValues)), 'sodium Q token no longer decodes');
	$iv = random_bytes(openssl_cipher_iv_length($cipher));
	$sQ = implode('.', array_map('MailSo\\Base\\Utils::UrlSafeBase64Encode',
		['openssl', $iv, $Crypt::OpenSSLEncrypt(json_encode($aValues), $iv, $sQKey)]));
	check($aValues === $Crypt::DecryptUrlSafe($sQ, $sQKey), 'test token is not a valid openssl Q token');
	check([] === $Utils::DecodeKeyValuesQ($sQ), 'openssl Q token (smadmin, mailto) from the client accepted');

	echo "crypt-client-tokens: ok\n";
}
