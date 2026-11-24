<?php

// Run with: php test/session-rotation.php
// A login starts a new session cookie (smsession): the login cookies' key
// hangs on it, so one that existed before the login (made by the mailto flow,
// or planted) must not carry on. The cookies encrypted with the session token
// (the mailto token, smadmin) must survive the change.
// The earlier session's server-side record must go too: a copy of the old
// cookies would otherwise keep it alive (FileStorage::Get() touches it).

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

	$Utils = 'Tachyon\\Utils';
	$Cookies = 'Tachyon\\Util\\Cookies';
	$Type = 'Tachyon\\Providers\\Storage\\Enumerations\\StorageType';
	$sData = sys_get_temp_dir().'/session-rotation-'.bin2hex(random_bytes(6));
	mkdir($sData);
	register_shutdown_function(static function () use ($sData) {
		exec('rm -rf '.escapeshellarg($sData));
	});
	$oStorage = new \Tachyon\Providers\Storage(new \Tachyon\Providers\Storage\FileStorage($sData));

	// A session that existed before the login, e.g. from the mailto flow
	$_COOKIE['smsession'] = 'planted-'.bin2hex(random_bytes(8));
	$sOldToken = $Utils::GetSessionToken(false);
	$aMailTo = ['Time' => 1.5, 'MailTo' => 'MailTo', 'To' => 'mailto:bob@example.com'];
	$aAdmin = ['token', 'abc'];
	$_COOKIE['smmailtoauth'] = $Utils::EncodeKeyValuesQ($aMailTo);
	$_COOKIE['smadmin'] = $Utils::EncodeKeyValuesQ($aAdmin);
	$sOldMailTo = $_COOKIE['smmailtoauth'];

	$Utils::RotateSessionToken($oStorage);

	check(!str_starts_with($_COOKIE['smsession'], 'planted-'), 'session cookie kept across the login');
	check($sOldToken !== $Utils::GetSessionToken(false), 'session token unchanged');
	check($sOldMailTo !== $_COOKIE['smmailtoauth'], 'mailto token not re-encrypted');
	check($aMailTo === $Utils::DecodeKeyValuesQ($Cookies::get('smmailtoauth')), 'mailto token lost by the rotation');
	check($aAdmin === $Utils::DecodeKeyValuesQ($Cookies::get('smadmin')), 'admin session lost by the rotation');

	// Without such cookies it just starts a session
	unset($_COOKIE['smsession'], $_COOKIE['smmailtoauth'], $_COOKIE['smadmin']);
	$Utils::RotateSessionToken($oStorage);
	check(!empty($_COOKIE['smsession']), 'no session cookie after the rotation');
	check(!isset($_COOKIE['smmailtoauth']) && !isset($_COOKIE['smadmin']), 'cookies made up by the rotation');

	// Each login a different session
	$sFirst = $_COOKIE['smsession'];
	$Utils::RotateSessionToken($oStorage);
	check($sFirst !== $_COOKIE['smsession'], 'two logins share a session cookie');

	// A login while an earlier session is valid ends that session on the server
	$login = static function (string $sEmail) use ($Utils, $Cookies, $Type, $oStorage) : string {
		$Utils::RotateSessionToken($oStorage);
		$sToken = $Utils::GetSessionToken(false);
		$oStorage->Put($sEmail, $Type::SESSION, $sToken, (string) time());
		$Cookies::setSecure('smaccount', ['email' => $sEmail, 'login' => $sEmail]);
		return $sToken;
	};
	$sAlice1 = $login('alice@example.test');
	check('' !== (string) $oStorage->Get('alice@example.test', $Type::SESSION, $sAlice1), 'test setup: no session record');
	$sAlice2 = $login('alice@example.test');
	check($sAlice1 !== $sAlice2, 'test setup: same token twice');
	check(false === $oStorage->Get('alice@example.test', $Type::SESSION, $sAlice1),
		're-login left the earlier session record in place');
	check(false !== $oStorage->Get('alice@example.test', $Type::SESSION, $sAlice2), 'the new session record is gone');

	// Also when somebody else logs in in the same browser: the earlier session
	// is unusable here from now on, so only a copy of its cookies could use it
	$sBob = $login('bob@example.test');
	check(false === $oStorage->Get('alice@example.test', $Type::SESSION, $sAlice2),
		"another user's login left the earlier session record in place");
	check(false !== $oStorage->Get('bob@example.test', $Type::SESSION, $sBob), "the new user's session record is gone");

	// Other sessions of the same user (other browsers) are left alone
	$sElsewhere = 'elsewhere-'.bin2hex(random_bytes(4));
	$oStorage->Put('bob@example.test', $Type::SESSION, $sElsewhere, (string) time());
	$login('bob@example.test');
	check(false !== $oStorage->Get('bob@example.test', $Type::SESSION, $sElsewhere),
		"a login ended the user's session in another browser");

	// A session cookie without a login cookie (first login, or the mailto
	// flow): nothing to clear, and no error
	unset($_COOKIE['smaccount']);
	$_COOKIE['smsession'] = 'mailto-'.bin2hex(random_bytes(8));
	$Utils::RotateSessionToken($oStorage);

	// A garbled login cookie (a split cookie that lost a part, say) must not
	// stop the login: the rotation carries on and the login replaces it
	$_COOKIE['smaccount'] = 'garbage';
	$_COOKIE['smsession'] = 'garbled-'.bin2hex(random_bytes(8));
	$Utils::RotateSessionToken($oStorage);
	check(!str_starts_with($_COOKIE['smsession'], 'garbled-'), 'no new session cookie after a garbled login cookie');

	echo "session-rotation: ok\n";
}
