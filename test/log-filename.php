<?php

// Run with: php test/log-filename.php
// The admin panel sets the log folder and file name, and the log holds request
// data. A name that a web server runs as code turned that into a web shell
// (GHSA-9cmw-3ghj-9q55), so whatever is configured ends up a .txt or .log
// with no other dot in the file name.

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$sData = sys_get_temp_dir() . '/log-filename-' . bin2hex(random_bytes(6));
mkdir($sData);
register_shutdown_function(static function () use ($sData) {
	exec('rm -rf ' . escapeshellarg($sData));
});
define('APP_DATA_FOLDER_PATH', $sData . '/');
$_ENV['TACHYON_INCLUDE_AS_API'] = true;
require __DIR__ . '/../tachyon/v/0.0.0/include.php';

$oActions = \Tachyon\Api::Actions();
$compile = (new ReflectionMethod($oActions, 'compileLogFileName'))->getClosure($oActions);

foreach ([
	// What people configure keeps working
	'tachyon-log.txt' => 'tachyon-log.txt',
	'log-{date:Y-m-d}.txt' => 'log-' . gmdate('Y-m-d') . '.txt',
	'fail2ban/auth-{date:Y-m-d}.txt' => 'fail2ban/auth-' . gmdate('Y-m-d') . '.txt',
	'mail.LOG' => 'mail.log',
	'tachyon' => 'tachyon.txt',
	'' => 'tachyon-log.txt',
	// Code and server config never come out
	'shell.php' => 'shell-php.txt',
	'shell.phtml' => 'shell-phtml.txt',
	'shell.phar' => 'shell-phar.txt',
	'shell.php.txt' => 'shell-php.txt',
	'shell.php.log' => 'shell-php.log',
	'shell.php.' => 'shell-php.txt',
	'.user.ini' => 'user-ini.txt',
	'.htaccess' => 'htaccess.txt',
	'logs/.htaccess' => 'logs/htaccess.txt',
	'../../index.php' => '././index-php.txt',
	'x.txt.php' => 'x-txt-php.txt',
	'.' => 'tachyon-log.txt',
	'/' => 'tachyon-log.txt',
] as $sIn => $sOut) {
	$sGot = $compile($sIn);
	check($sOut === $sGot, "'{$sIn}' became '{$sGot}', expected '{$sOut}'");
	check(1 === preg_match('/^[^.]*\.(txt|log)$/D', basename($sGot)), "'{$sIn}' gave the file name '{$sGot}'");
}

echo "log-filename: ok\n";
