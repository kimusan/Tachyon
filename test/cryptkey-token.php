<?php

// Run with: php test/cryptkey-token.php
// The Argon2id seal of .cryptkey is opened once per login: the login cookie
// carries the unsealed CryptKey (MainAccount::tokenArray()) and restoring the
// account from it (NewInstanceFromTokenArray()) needs no seal. The
// remember-me record (jsonSerialize(), stored on the server) does not get it.

namespace Tachyon\Util {
	abstract class Log
	{
		public static function __callStatic(string $name, array $arguments) : void {}
	}
}

namespace Tachyon {
	abstract class Api
	{
		public static ?object $storage = null;
		public static function Config() : object
		{
			return new class {
				public function Get(string $section, string $name, $default = null) { return $default; }
			};
		}
		public static function Actions() : object
		{
			return new class {
				public function StorageProvider() : object { return Api::$storage; }
				public function logMask(string $s) : void {}
				public function Plugins() : object
				{
					return new class {
						public function RunHook(string $sName, array $aArgs = []) : object { return $this; }
					};
				}
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

	class MemoryStorage
	{
		public array $data = [];
		public int $gets = 0;
		public function Put($mAccount, int $iType, string $sKey, string $sValue) : bool { $this->data[$sKey] = $sValue; return true; }
		public function Get($mAccount, int $iType, string $sKey, $mDefault = false) { ++$this->gets; return $this->data[$sKey] ?? $mDefault; }
		public function GenerateFilePath($mAccount, int $iType) : string { return ''; }
	}
	\Tachyon\Api::$storage = $storage = new MemoryStorage;

	// The real Actions for NewInstanceFromTokenArray(): plugins off, one domain
	$set = fn(object $o, string $class, string $prop, $value) => (new ReflectionProperty($class, $prop))->setValue($o, $value);
	$actions = (new ReflectionClass(\Tachyon\Actions::class))->newInstanceWithoutConstructor();
	$manager = (new ReflectionClass(\Tachyon\Plugins\Manager::class))->newInstanceWithoutConstructor();
	$set($manager, \Tachyon\Plugins\Manager::class, 'bIsEnabled', false);
	$set($actions, \Tachyon\Actions::class, 'oPlugins', $manager);
	$set($actions, \Tachyon\Actions::class, 'oDomainProvider', new class extends \Tachyon\Providers\Domain {
		public function __construct() {}
		public function getByEmailAddress(string $sEmail) : \Tachyon\Model\Domain { return new \Tachyon\Model\Domain('example.com'); }
	});

	$Main = \Tachyon\Model\MainAccount::class;
	$token = ['email' => 'user@example.com', 'login' => 'user@example.com', 'pass' => 'upass'];

	// Stronger cost now that it is paid once per login
	check(2 <= $Main::CRYPTKEY_OPSLIMIT && 64 * 1024 * 1024 <= $Main::CRYPTKEY_MEMLIMIT, 'cost not raised to the interactive level');

	// Login: the cookie gets the unsealed key
	$oLogin = $Main::NewInstanceFromTokenArray($actions, $token);
	$aCookie = $oLogin->tokenArray();
	$sKey = (string) $oLogin->CryptKey();
	check(($aCookie['cryptkey'] ?? '') === bin2hex($sKey), 'login cookie does not carry the CryptKey');
	check(!isset($oLogin->jsonSerialize()['cryptkey']), 'remember-me record (jsonSerialize) carries the CryptKey');

	// Later requests: restored from the cookie, the seal is not read
	$storage->gets = 0;
	$oRequest = $Main::NewInstanceFromTokenArray($actions, $aCookie);
	check($sKey === (string) $oRequest->CryptKey(), 'CryptKey from the cookie differs');
	check(0 === $storage->gets, 'seal read although the cookie has the key');

	// Without it (a session from before, a locked key) the seal is opened
	$oOld = $Main::NewInstanceFromTokenArray($actions, $token);
	check($sKey === (string) $oOld->CryptKey() && 0 < $storage->gets, 'cookie without key does not fall back to the seal');
	foreach (['', 'zz', str_repeat('a', 39), ['x'], str_repeat('g', 64)] as $mBad) {
		$o = $Main::NewInstanceFromTokenArray($actions, ['cryptkey' => $mBad] + $token);
		check($sKey === (string) $o->CryptKey(), 'malformed cryptkey in the cookie used: '.json_encode($mBad));
	}

	// Locked (sealed with another password): no key in the cookie, no error
	$aLocked = $Main::NewInstanceFromTokenArray($actions, ['pass' => 'changed'] + $token)->tokenArray();
	check(!isset($aLocked['cryptkey']) && 'changed' === $aLocked['pass'], 'locked CryptKey broke the login cookie');

	// A seal with the previous (OWASP minimum) cost is renewed at the login
	eval('class CheapMainAccount extends \Tachyon\Model\MainAccount { const CRYPTKEY_OPSLIMIT = 2, CRYPTKEY_MEMLIMIT = 19 * 1024 * 1024; }');
	$storage->data['.cryptkey'] = $Main::sealCryptKey(bin2hex($sKey), 'upass');
	$storage->data['.cryptkey'] = CheapMainAccount::sealCryptKey(bin2hex($sKey), 'upass');
	check(19 * 1024 * 1024 === json_decode($storage->data['.cryptkey'], true)['mem'], 'cheap seal not written');
	$Main::NewInstanceFromTokenArray($actions, $token)->tokenArray();
	check($Main::CRYPTKEY_MEMLIMIT === json_decode($storage->data['.cryptkey'], true)['mem'], 'cheap seal not renewed at login');

	// UserAuth writes tokenArray() into the cookie, and again after a reseal
	$sUserAuth = file_get_contents(dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/Tachyon/Actions/UserAuth.php');
	check(str_contains($sUserAuth, 'Cookies::setSecure(self::AUTH_SPEC_TOKEN_KEY, $oAccount->tokenArray());'), 'SetAuthToken() does not use tokenArray()');
	check(str_contains($sUserAuth, '$bResult && $this->SetAuthToken($oMainAccount);'), 'DoResealCryptKey() does not renew the login cookie');

	echo "cryptkey-token: ok\n";
}
