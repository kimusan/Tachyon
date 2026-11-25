<?php

// Run with: php test/oauth2-session-bundle.php
// OAuth2 login plugins (login-gmail, login-o365, login-oauth2) kept the main
// account's token bundle under the session token, the record that since #185
// holds the time of the session's last request for AutoLogout. The next
// request after the login wrote the time over the bundle, so the account had
// no token left to log in to IMAP, SMTP or Sieve with. Driven through the
// real UserAuth and OAuth2Accounts traits with the real cookies; storage,
// settings, the domain lookup and Api are replaced.
namespace Tachyon {
	abstract class Api
	{
		public static ?Actions $actions = null;
		public static function Actions() : Actions { return static::$actions; }
		public static function Config() : Config\Application { return static::$actions->Config(); }
		public static function Logger() : object
		{
			return new class {
				public function IsEnabled() : bool { return false; }
				public function Write(...$a) : void {}
			};
		}
	}
}

namespace {
define('APP_VERSION', 'test');
define('APP_SALT', 'salt-of-this-install');
define('APP_PRIVATE_DATA', sys_get_temp_dir().'/oauth2-session-bundle-'.getmypid().'/');
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

class MemoryStorage implements \Tachyon\Providers\Storage\IStorage
{
	public array $data = [];
	public function Put($mAccount, int $iStorageType, string $sKey, string $sValue) : bool { $this->data["{$iStorageType}/{$sKey}"] = $sValue; return true; }
	public function Get($mAccount, int $iStorageType, string $sKey, $mDefault = false) { return $this->data["{$iStorageType}/{$sKey}"] ?? $mDefault; }
	public function Clear($mAccount, int $iStorageType, string $sKey) : bool { unset($this->data["{$iStorageType}/{$sKey}"]); return true; }
	public function IsLocal() : bool { return true; }
}

class MemorySettings implements \Tachyon\Providers\Settings\ISettings
{
	public function Load(\Tachyon\Model\Account $oAccount) : array { return ['AutoLogout' => 30]; }
	public function Save(\Tachyon\Model\Account $oAccount, \Tachyon\Settings $oSettings) : bool { return true; }
	public function Delete(\Tachyon\Model\Account $oAccount) : bool { return true; }
}

class OAuthPlugin
{
	use \Tachyon\Plugins\OAuth2Accounts;
	protected function oauthPrefix() : string { return 'test-oauth'; }
	protected function oauthTokenUri() : string { return ''; }
	protected function oauthClient() : ?\OAuth2\Client { return null; }
	public function save(\Tachyon\Model\Account $a, array $t) : void { $this->oauthSaveTokensFor($a, $t); }
	public function read(\Tachyon\Model\Account $a) : ?array { return $this->oauthTokensFor($a); }
}

$set = function (object $o, string $class, string $prop, $value): void {
	(new ReflectionProperty($class, $prop))->setValue($o, $value);
};

$actions = (new ReflectionClass(\Tachyon\Actions::class))->newInstanceWithoutConstructor();
$manager = (new ReflectionClass(\Tachyon\Plugins\Manager::class))->newInstanceWithoutConstructor();
$set($manager, \Tachyon\Plugins\Manager::class, 'bIsEnabled', false);
$set($actions, \Tachyon\Actions::class, 'oPlugins', $manager);
$set($actions, \Tachyon\Actions::class, 'oConfig', new \Tachyon\Config\Application);
$storage = new MemoryStorage;
$set($actions, \Tachyon\Actions::class, 'oStorageProvider', new \Tachyon\Providers\Storage($storage));
$set($actions, \Tachyon\Actions::class, 'oSettingsProvider', new \Tachyon\Providers\Settings(new MemorySettings));
$domains = new class extends \Tachyon\Providers\Domain {
	public function __construct() {}
	public function getByEmailAddress(string $sEmail) : \Tachyon\Model\Domain { return new \Tachyon\Model\Domain('gmail.com'); }
};
$set($actions, \Tachyon\Actions::class, 'oDomainProvider', $domains);
\Tachyon\Api::$actions = $actions;

$token = ['email' => 'user@gmail.com', 'login' => 'user@gmail.com', 'pass' => '1234567890'];
$sessionCookie = 'cookie-value-of-this-browser';
$sessionKey = \Tachyon\Providers\Storage\Enumerations\StorageType::SESSION.'/'.sha1('Session'.APP_SALT.$sessionCookie.'Token'.APP_SALT);
$cached = new ReflectionProperty(\Tachyon\Actions::class, 'oMainAuthAccount');
$inRequest = new ReflectionProperty(OAuthPlugin::class, 'oauthTokens');

// One request from the browser: the main account, as the app restores it
$request = function () use ($actions, $token, $sessionCookie, $cached, $inRequest): ?\Tachyon\Model\MainAccount {
	$cached->setValue($actions, false);
	$inRequest->setValue(null, []);
	$_COOKIE = [];
	$_COOKIE[\Tachyon\Utils::SESSION_TOKEN] = $sessionCookie;
	$_COOKIE[\Tachyon\Actions::AUTH_SPEC_TOKEN_KEY] = \MailSo\Base\Utils::UrlSafeBase64Encode(\Tachyon\Util\Crypt::EncryptToJSON($token));
	return $actions->getMainAccountFromToken(false);
};

$plugin = new OAuthPlugin;
$tokens = ['access_token' => 'ya29.access', 'refresh_token' => '1//refresh', 'expires' => time() + 3600];

// The login: LoginProcess() starts the session record with the time, then the
// plugin saves the bundle (here in the first request that has the account)
$storage->data[$sessionKey] = (string) time();
$account = $request();
check(null !== $account, 'the setup: the session is refused');
$plugin->save($account, $tokens);
check('ya29.access' === ($plugin->read($account)['access_token'] ?? null), 'the bundle does not read back in the same request');

// The next request, a minute and more later (the session clock moves once a minute)
$storage->data[$sessionKey] = (string) (time() - 90);
$account = $request();
check(null !== $account, 'the next request is refused');
check(is_numeric($storage->data[$sessionKey]), 'the session record no longer holds the time of the last request');
check('ya29.access' === ($plugin->read($account)['access_token'] ?? null),
	'the next request lost the access token: IMAP, SMTP and Sieve get no XOAUTH2');

// A refreshed bundle survives the same way
$tokens['access_token'] = 'ya29.refreshed';
$plugin->save($account, $tokens);
$storage->data[$sessionKey] = (string) (time() - 90);
$account = $request();
check('ya29.refreshed' === ($plugin->read($account)['access_token'] ?? null), 'a refreshed token is lost on the next request');

// When the session ends, its tokens go with it: here the AutoLogout refusal
// (logout and a new login end it through the same Utils::ClearSession())
$bundleKeys = fn() => array_filter(array_keys($storage->data), fn($k) => str_contains($k, 'oauth2-'));
check([] !== $bundleKeys(), 'the setup: no bundle stored');
$storage->data[$sessionKey] = (string) (time() - 3600);
try { $request(); } catch (\Throwable $e) {}
check(!isset($storage->data[$sessionKey]), 'the setup: the idle session was not ended');
check([] === $bundleKeys(), 'the tokens of an ended session are left behind');
$src = file_get_contents(dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/Tachyon/Actions/UserAuth.php');
check(2 <= substr_count($src, 'Utils::ClearSession('), 'logout or the AutoLogout refusal clears the record alone');
$src = file_get_contents(dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/Tachyon/Utils.php');
check(preg_match('/function RotateSessionToken\(.*?static::ClearSession\(/s', $src) === 1, 'a new login leaves the earlier session\'s tokens behind');

echo "oauth2-session-bundle: ok\n";
}
