<?php

// Run with: php test/admin-session-expiry.php
// An admin session record only had to exist: the time stored with it was
// never read, so a session lasted until the cache GC removed it (48 hours or
// more), and changing the admin password left other admin sessions running.
// Records now expire when idle or old, and are bound to the admin credentials.
define('APP_VERSION', 'test');
define('APP_SALT', 'salt-of-this-install');
define('APP_PRIVATE_DATA', sys_get_temp_dir().'/admin-session-expiry-'.getmypid().'/');
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

$actions = (new ReflectionClass(\Tachyon\ActionsAdmin::class))->newInstanceWithoutConstructor();
$config = new \Tachyon\Config\Application;
$config->Set('cache', 'path', APP_PRIVATE_DATA.'cache/');
$config->Set('security', 'admin_password', password_hash('old', PASSWORD_DEFAULT));
(new ReflectionProperty(\Tachyon\Actions::class, 'oConfig'))->setValue($actions, $config);
$cacher = $actions->Cacher(null, true);

check(method_exists($actions, 'adminSessionValid') && method_exists($actions, 'newAdminSession'),
	'no adminSessionValid()/newAdminSession(): an admin session is valid as long as its record exists');
$valid = fn(string $k) => (new ReflectionMethod($actions, 'adminSessionValid'))->invoke($actions, $k);
$new = fn() => (new ReflectionMethod($actions, 'newAdminSession'))->invoke($actions);
$key = fn(string $k) => \Tachyon\KeyPathHelper::SessionAdminKey($k);
$age = function (string $k, int $start, int $last) use ($cacher, $key): void {
	$a = json_decode($cacher->Get($key($k)), true);
	$a['start'] = time() - $start;
	$a['last'] = time() - $last;
	$cacher->Set($key($k), json_encode($a));
};

$k = $new();
check($valid($k), 'a new admin session is refused');
check(!$valid('unknown'), 'an unknown admin session is let in');

// Idle: 30 minutes by default
$age($k, 600, 29 * 60);
check($valid($k), 'refused before the idle limit');
check(time() - json_decode($cacher->Get($key($k)), true)['last'] <= 1, 'use does not refresh the idle clock');
$age($k, 600, 31 * 60);
check(!$valid($k), 'an admin session idle for 31 minutes is let in');
check(null === $cacher->Get($key($k)), 'the expired record is kept');

// Lifetime: 12 hours by default, however busy
$k = $new();
$age($k, 13 * 3600, 10);
check(!$valid($k), 'a 13 hour old admin session is let in');
$config->Set('security', 'admin_session_lifetime', 0);
$config->Set('security', 'admin_session_idle', 0);
$k = $new();
$age($k, 100 * 3600, 100 * 3600);
check($valid($k), '0 does not turn the limits off');
$config->Set('security', 'admin_session_lifetime', 720);
$config->Set('security', 'admin_session_idle', 30);

// New credentials end the sessions made with the old ones
$k = $new();
$config->Set('security', 'admin_password', password_hash('new', PASSWORD_DEFAULT));
check(!$valid($k), 'an admin session survives a password change');
$k = $new();
$config->Set('security', 'admin_totp', 'JBSWY3DPEHPK3PXP');
check(!$valid($k), 'an admin session survives enabling two factor');

// Records from before this change held only the login time
$cacher->Set($key('old'), (string) time());
check(!$valid('old'), 'an old-format record is let in');

// The password change re-issues the session of the admin making it
$src = file_get_contents(dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/Tachyon/ActionsAdmin.php');
check(preg_match('/function DoAdminPasswordUpdate\(\).*?setAdminAuthToken\(\).*?\n\t\}/s', $src) === 1,
	'a password change does not give the current admin a new session');

\MailSo\Base\Utils::RecRmDir(APP_PRIVATE_DATA);
echo "admin-session-expiry: ok\n";
