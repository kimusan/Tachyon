<?php

// Run with: php test/session-idle-logout.php
// Issue #185. AutoLogout was only a timer in the browser, so closing the tab
// stopped it and the session stayed usable until FileStorage::GC() swept it,
// up to three hours later. getMainAccountFromToken() now refuses a session
// idle for longer than the user's AutoLogout. Driven through the real trait
// with the real cookies; storage, settings and the domain lookup are replaced.
define('APP_VERSION', 'test');
define('APP_SALT', 'salt-of-this-install');
define('APP_PRIVATE_DATA', sys_get_temp_dir().'/session-idle-logout-'.getmypid().'/');
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
	public function Put($mAccount, int $iStorageType, string $sKey, string $sValue) : bool { $this->data[$sKey] = $sValue; return true; }
	public function Get($mAccount, int $iStorageType, string $sKey, $mDefault = false) { return $this->data[$sKey] ?? $mDefault; }
	public function Clear($mAccount, int $iStorageType, string $sKey) : bool { unset($this->data[$sKey]); return true; }
	public function IsLocal() : bool { return true; }
}

class MemorySettings implements \Tachyon\Providers\Settings\ISettings
{
	public array $data = [];
	public function Load(\Tachyon\Model\Account $oAccount) : array { return $this->data; }
	public function Save(\Tachyon\Model\Account $oAccount, \Tachyon\Settings $oSettings) : bool { return true; }
	public function Delete(\Tachyon\Model\Account $oAccount) : bool { return true; }
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
$settings = new MemorySettings;
$set($actions, \Tachyon\Actions::class, 'oSettingsProvider', new \Tachyon\Providers\Settings($settings));
$domains = new class extends \Tachyon\Providers\Domain {
	public function __construct() {}
	public function getByEmailAddress(string $sEmail) : \Tachyon\Model\Domain { return new \Tachyon\Model\Domain('example.com'); }
};
$set($actions, \Tachyon\Actions::class, 'oDomainProvider', $domains);

$token = ['email' => 'user@example.com', 'login' => 'user@example.com', 'pass' => 'secret'];
$sessionCookie = 'cookie-value-of-this-browser';
$sessionKey = sha1('Session'.APP_SALT.$sessionCookie.'Token'.APP_SALT);
$cached = new ReflectionProperty(\Tachyon\Actions::class, 'oMainAuthAccount');

// One request: the browser's cookies, a session stored with this value, this
// AutoLogout. Returns whether the request was let in.
$request = function ($storedValue, ?int $autoLogout, bool $signMe = false) use (
	$actions, $storage, $settings, $token, $sessionCookie, $sessionKey, $cached): bool
{
	$cached->setValue($actions, false);
	$_COOKIE = [];
	$_COOKIE[\Tachyon\Actions::AUTH_SPEC_TOKEN_KEY] = \MailSo\Base\Utils::UrlSafeBase64Encode(\Tachyon\Util\Crypt::EncryptToJSON($token));
	$_COOKIE[\Tachyon\Utils::SESSION_TOKEN] = $sessionCookie;
	$signMe && $_COOKIE[\Tachyon\Actions::AUTH_SIGN_ME_TOKEN_KEY] = 'present';
	$storage->data = [$sessionKey => (string) $storedValue];
	$settings->data = null === $autoLogout ? [] : ['AutoLogout' => $autoLogout];
	return null !== $actions->getMainAccountFromToken(false);
};

// The setup itself: a fresh session gets in, an unknown one does not.
check($request(time(), 5), 'a session used a moment ago is refused');
$storage->data = [];
$cached->setValue($actions, false);
check(null === $actions->getMainAccountFromToken(false), 'a session with no server record is let in');

// The report: AutoLogout 5, tab closed, back an hour later.
check(!$request(time() - 3600, 5), 'an hour-old session gets in with AutoLogout at 5 minutes');
check(!isset($storage->data[$sessionKey]), 'the expired session record is not removed');

// The edges. Three minutes of grace past AutoLogout, for the keepalive's lag.
check($request(time() - 5 * 60, 5), 'refused at exactly AutoLogout, before the grace has run');
check($request(time() - 8 * 60 + 5, 5), 'refused inside the grace');
check(!$request(time() - 8 * 60 - 5, 5), 'let in past AutoLogout and its grace');

// "Never", and remember-me, which the client also exempts from the timer.
check($request(time() - 3600, 0), 'AutoLogout "never" logs out an idle session');
check($request(time() - 3600, 5, true), 'a remember-me login is logged out by AutoLogout');

// The admin default applies when the user never chose (application.ini: 30).
check($request(time() - 20 * 60, null), 'the 30 minute default refuses a 20 minute idle session');
check(!$request(time() - 40 * 60, null), 'the 30 minute default lets in a 40 minute idle session');

// A session from before this change holds 'true'; it starts counting now.
check($request('true', 5), 'a session stored before this change is refused');
check(is_numeric($storage->data[$sessionKey]) && abs(time() - (int) $storage->data[$sessionKey]) <= 1,
	'a session stored before this change is not given a time');

// Activity moves the clock, but no more than once a minute.
$request(time() - 120, 5);
check(abs(time() - (int) $storage->data[$sessionKey]) <= 1, 'a request does not record the activity');
$recent = time() - 30;
$request($recent, 5);
check((int) $storage->data[$sessionKey] === $recent, 'every request rewrites the session, not once a minute');

// The keepalive: Ping has to read the token, or typing alone never reaches the server.
$src = file_get_contents(dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/Tachyon/Actions.php');
check(preg_match('/function DoPing\(\).*?getMainAccountFromToken\(false\).*?\n\t\}/s', $src) === 1,
	'DoPing() no longer reads the session, so the client keepalive records nothing');
$js = file_get_contents(dirname(__DIR__).'/dev/Stores/User/Settings.js');
check(preg_match("/self\\.delayLogout = .*?Remote\\.request\\('Ping'\\).*?throttle\\(5000\\)/s", $js) === 1,
	'the client no longer pings while in use, so the server logs out someone typing a long message');

// New sessions are stored with a time, at login and from a remember-me cookie.
$auth = file_get_contents(dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/Tachyon/Actions/UserAuth.php');
check(!preg_match("/StorageType::SESSION,[^;]*'true'/s", $auth), 'a session is still stored as \'true\'');

echo "session-idle-logout: ok\n";
