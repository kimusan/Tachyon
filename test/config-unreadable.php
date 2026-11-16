<?php

// Run with: php test/config-unreadable.php
// Issue #211. A configuration file that could not be read made Load() write the
// defaults over it. Saving application.ini emptied it before writing, so any
// request that read it in that moment reset the admin password, branding and
// DAV settings for good. Now the file is written beside and renamed into place,
// and a file that exists but cannot be read is never saved over.
if (!defined('APP_PRIVATE_DATA')) {
	define('APP_PRIVATE_DATA', sys_get_temp_dir().'/config-unreadable-'.getmypid().'/');
}
spl_autoload_register(static function (string $class): void {
	$base = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/';
	$file = str_starts_with($class, 'Tachyon\\Util\\')
		? $base.'tachyon_util/'.strtolower(str_replace('\\', '/', substr($class, 13))).'.php'
		: $base.str_replace('\\', '/', $class).'.php';
	if (is_file($file)) {
		require_once $file;
	}
});

final class TestConfig extends \Tachyon\Config\AbstractConfig
{
	protected function defaultValues() : array
	{
		return [
			'security' => ['admin_password' => ['', 'Admin password hash']],
			'webmail' => ['title' => ['Tachyon', 'Title']]
		];
	}
}

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$sDir = APP_PRIVATE_DATA.'configs/';
$sFile = $sDir.'test.ini';
$sHash = password_hash('secret', PASSWORD_BCRYPT);

// The writer: saves the configuration over and over, as the admin panel does
if ('writer' === ($argv[1] ?? '')) {
	$iEnd = microtime(true) + 1.5;
	while (microtime(true) < $iEnd) {
		$oConfig = new TestConfig('test.ini');
		$oConfig->Load();
		$oConfig->Set('webmail', 'title', 'Почта '.mt_rand());
		$oConfig->Save();
	}
	exit;
}

$fresh = static function () : TestConfig {
	return new TestConfig('test.ini');
};

try {
	// No file yet: created with the defaults, as before
	$oConfig = $fresh();
	check($oConfig->Load(), 'a missing file was not created');
	check(is_file($sFile), 'no file was written for a missing configuration');

	// A real configuration is read back, Cyrillic included
	$oConfig->Set('security', 'admin_password', $sHash);
	$oConfig->Set('webmail', 'title', 'Почта "Тачион"');
	check($oConfig->Save(), 'the configuration was not saved');
	$oConfig = $fresh();
	check($oConfig->Load(), 'a saved configuration did not load');
	check($sHash === $oConfig->Get('security', 'admin_password'), 'the admin password did not survive a save');
	check('Почта "Тачион"' === $oConfig->Get('webmail', 'title'), 'the title did not survive a save');
	$sGood = file_get_contents($sFile);

	// Empty, cut short, or not readable at all: run on defaults, never save them
	foreach ([
		'empty' => '',
		'cut short' => "[security]\nadmin_password = \"\$2y\$12\$abc",
	] as $sCase => $sContent) {
		file_put_contents($sFile, $sContent);
		$oConfig = $fresh();
		check(!$oConfig->Load(), "an {$sCase} file counted as loaded");
		check('' === $oConfig->Get('security', 'admin_password'), "an {$sCase} file did not leave the defaults");
		check(!$oConfig->Save(), "an {$sCase} file was saved over");
		$oConfig->Set('webmail', 'title', 'changed');
		check(!$oConfig->Save(), "an {$sCase} file was saved over after a change");
		check($sContent === file_get_contents($sFile), "the {$sCase} file was rewritten");
	}

	// The retry in Api::Config(): a second Load that succeeds may save again
	file_put_contents($sFile, '');
	$oConfig = $fresh();
	check(!$oConfig->Load(), 'an empty file counted as loaded');
	file_put_contents($sFile, $sGood);
	check($oConfig->Load(), 'the second Load did not read the restored file');
	check($oConfig->Save(), 'a successful second Load still refused to save');
	$oCheck = $fresh();
	$oCheck->Load();
	check($sHash === $oCheck->Get('security', 'admin_password'), 'the retry lost the admin password');

	// A plugin without settings is an empty JSON array, and that is readable
	file_put_contents($sDir.'plugin-empty.json', '[]');
	check((new TestConfig('plugin-empty.json'))->Load(), 'an empty JSON configuration counted as unreadable');

	// Saving while other requests read: none of them may see it half written
	$rWriter = proc_open([PHP_BINARY, __FILE__, 'writer'], [], $aPipes);
	$iReads = $iFailed = 0;
	while (proc_get_status($rWriter)['running']) {
		$oConfig = $fresh();
		++$iReads;
		if (!$oConfig->Load() || $sHash !== $oConfig->Get('security', 'admin_password')) {
			++$iFailed;
		}
	}
	proc_close($rWriter);
	check(100 < $iReads, "only {$iReads} reads, the race was not exercised");
	check(0 === $iFailed, "{$iFailed} of {$iReads} reads did not see the saved configuration");
	$oConfig = $fresh();
	$oConfig->Load();
	check($sHash === $oConfig->Get('security', 'admin_password'), 'the admin password was lost while saving');
	check(['plugin-empty.json', 'test.ini'] === array_values(array_diff(scandir($sDir), ['.', '..'])),
		'a temporary file was left behind');
} finally {
	\MailSo\Base\Utils::RecRmDir(APP_PRIVATE_DATA);
}

echo "config-unreadable: ok\n";
