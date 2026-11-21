<?php

// Run with: php test/oauth2-cryptkey-rotate.php
// OAuth2Accounts keeps tokens encrypted with the main account's CryptKey: of
// additional accounts in CONFIG, of the main account in its session record.
// Its 'main.cryptkey-rotate' handler re-encrypts both under the new key.

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
		public static array $accounts = [];
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
				public function GetAccounts($oMain) : array { return Api::$accounts; }
				public function logMask(string $s) : void {}
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
		public function Put($mAccount, int $iType, string $sKey, string $sValue) : bool { $this->data["{$iType}/{$sKey}"] = $sValue; return true; }
		public function Get($mAccount, int $iType, string $sKey, $mDefault = false) { return $this->data["{$iType}/{$sKey}"] ?? $mDefault; }
	}
	\Tachyon\Api::$storage = $storage = new MemoryStorage;

	class TestOAuthPlugin
	{
		use \Tachyon\Plugins\OAuth2Accounts;
		protected function oauthPrefix() : string { return 'test-oauth'; }
		protected function oauthTokenUri() : string { return ''; }
		protected function oauthClient() : ?\OAuth2\Client { return null; }
		public function store(\Tachyon\Model\MainAccount $oMain, string $sEmail, array $aTokens) : void
		{
			$this->oauthStoreTokens($oMain, $sEmail, $aTokens);
		}
	}

	$Crypt = 'Tachyon\\Util\\Crypt';
	$CONFIG = \Tachyon\Providers\Storage\Enumerations\StorageType::CONFIG;
	$SESSION = \Tachyon\Providers\Storage\Enumerations\StorageType::SESSION;
	$K0 = random_bytes(20);
	$K1 = random_bytes(32);

	$oMain = (new ReflectionClass(\Tachyon\Model\MainAccount::class))->newInstanceWithoutConstructor();
	(new ReflectionProperty(\Tachyon\Model\Account::class, 'sEmail'))->setValue($oMain, 'user@example.com');
	(new ReflectionProperty(\Tachyon\Model\MainAccount::class, 'sCryptKey'))->setValue($oMain, new \Tachyon\Util\SensitiveString($K0));

	// Tokens as the trait stores them, under the old key
	\Tachyon\Api::$accounts = ['Gmail.User@Example.com' => [], 'plain@example.com' => []];
	$oPlugin = new TestOAuthPlugin;
	$oPlugin->store($oMain, 'Gmail.User@Example.com', ['access_token' => 'a1', 'refresh_token' => 'r1']);
	$sTokenKey = array_key_first($storage->data);
	$_COOKIE['smsession'] = 'session-cookie';
	$sSession = \Tachyon\Utils::GetSessionToken(false);
	$storage->Put($oMain, $SESSION, $sSession, $Crypt::EncryptToJSON(['access_token' => 'm1'], $K0));

	$oPlugin->oauthRotateCryptKey($oMain, $K0, $K1);

	check(['access_token' => 'a1', 'refresh_token' => 'r1'] === $Crypt::DecryptFromJSON($storage->data[$sTokenKey], $K1),
		'additional account tokens not re-encrypted');
	check(null === $Crypt::DecryptFromJSON($storage->data[$sTokenKey], $K0), 'additional account tokens still under the old key');
	check(['access_token' => 'm1'] === $Crypt::DecryptFromJSON($storage->data["{$SESSION}/{$sSession}"], $K1),
		'main account session tokens not re-encrypted');

	// Again: nothing changes; a plain session record (a timestamp) is left alone
	$aBefore = $storage->data;
	$oPlugin->oauthRotateCryptKey($oMain, $K0, $K1);
	check($aBefore === $storage->data, 'second run changed tokens');
	$storage->data["{$SESSION}/{$sSession}"] = (string) time();
	$oPlugin->oauthRotateCryptKey($oMain, $K0, $K1);
	check(is_numeric($storage->data["{$SESSION}/{$sSession}"]), 'plain session record touched');

	// The three plugins that use the trait register the handler
	foreach (['login-gmail', 'login-o365', 'login-oauth2'] as $sPlugin) {
		check(str_contains(file_get_contents(dirname(__DIR__)."/plugins/{$sPlugin}/index.php"),
			"addHook('main.cryptkey-rotate', 'oauthRotateCryptKey')"), "{$sPlugin} does not register the handler");
	}

	echo "oauth2-cryptkey-rotate: ok\n";
}
