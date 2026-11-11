<?php

// Run with: php test/dav-sync-needs-url.php
// Issue #199. Switching CalDAV or CardDAV sync on with the URL left empty still
// synced: the empty URL became https://, the host defaulted to localhost, and
// every sync logged a 403 from whatever answered there. Without a URL the sync
// configuration now counts as off, for calendars and contacts alike.
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

$aProviders = [
	'CalDAV' => new class { use \Tachyon\Providers\Calendar\CalDAV; },
	'CardDAV' => new class { use \Tachyon\Providers\AddressBook\CardDAV; }
];

foreach ($aProviders as $sName => $oProvider) {
	$oConfig = new ReflectionProperty($oProvider, 'aDAVConfig');
	$mode = function (array $aConfig) use ($oProvider, $oConfig): int {
		$oProvider->setDAVClientConfig($aConfig);
		return (int) $oConfig->getValue($oProvider)['Mode'];
	};

	// The report: sync set to Yes, everything else as it was, empty
	check(0 === $mode(['Mode' => 1, 'User' => '', 'Password' => '', 'Url' => '']), "{$sName}: syncs with no URL");
	check(0 === $mode(['Mode' => 2, 'User' => 'u', 'Password' => 'p', 'Url' => '  ']), "{$sName}: syncs with a blank URL");

	// A URL is enough, credentials stay optional for servers that want none
	check(1 === $mode(['Mode' => 1, 'User' => 'u', 'Password' => 'p', 'Url' => 'dav.example.com']), "{$sName}: a configured sync is off");
	check(2 === $mode(['Mode' => 2, 'User' => '', 'Password' => '', 'Url' => 'https://dav.example.com/']), "{$sName}: a sync without credentials is off");

	check(0 === $mode(['Mode' => 0, 'User' => 'u', 'Password' => 'p', 'Url' => 'dav.example.com']), "{$sName}: syncs while switched off");
}

echo "dav-sync-needs-url: ok\n";
