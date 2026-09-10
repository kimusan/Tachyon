<?php

/**
 * The "stderr" log driver must reach a log wherever the request runs.
 *
 * Under php-fpm a worker inherits /dev/null on fd 2 unless the pool sets
 * catch_workers_output, so a raw write to STDERR disappears while every
 * config key says logging is on. This checks the driver notices that and
 * routes through the SAPI (error_log) instead, and that a real stderr is
 * still written to directly.
 *
 * Usage: php test/log-stderr-driver.php
 */

$sLib = \dirname(__DIR__) . '/tachyon/v/0.0.0/app/libraries';
require $sLib . '/MailSo/Log/Driver.php';
require $sLib . '/MailSo/Log/Drivers/StderrStream.php';

$iFailed = 0;
$check = function (string $sName, bool $bOk) use (&$iFailed) : void {
	echo ($bOk ? 'ok   ' : 'FAIL ') . $sName . "\n";
	$bOk || ++$iFailed;
};

$bare = fn(\MailSo\Log\Driver $o) => $o->DisableGuidPrefix()->DisableTimePrefix()->DisableTypedPrefix();

// A usable stream is written to directly.
$rMemory = \fopen('php://memory', 'w+b');
$oDriver = $bare(new \MailSo\Log\Drivers\StderrStream($rMemory));
$check('write to a live stream returns true', $oDriver->Write('direct line'));
\rewind($rMemory);
$check('the line lands on the stream', "direct line\n" === \stream_get_contents($rMemory));

// A stream on /dev/null is a black hole, so the line goes via error_log().
$sErrorLog = \tempnam(\sys_get_temp_dir(), 'tachyon-log-');
$sPrevious = \ini_get('error_log');
\ini_set('error_log', $sErrorLog);
$rNull = \fopen('/dev/null', 'wb');
$oDriver = $bare(new \MailSo\Log\Drivers\StderrStream($rNull));
$check('write to /dev/null still returns true', $oDriver->Write('fallback line'));
\ini_set('error_log', $sPrevious);
$sLogged = (string) \file_get_contents($sErrorLog);
\unlink($sErrorLog);
$check('the line reaches the SAPI error log', \str_contains($sLogged, 'fallback line'));

// Nothing stays in the null stream (sanity: it really was /dev/null).
\fclose($rNull);

// The default construction still works on the CLI.
$oDriver = $bare(new \MailSo\Log\Drivers\StderrStream());
$check('default driver constructs', $oDriver instanceof \MailSo\Log\Driver);

// The real thing: a PHP whose fd 2 is /dev/null, as a php-fpm worker's is.
if ('\\' !== \DIRECTORY_SEPARATOR) {
	$sErrorLog = \tempnam(\sys_get_temp_dir(), 'tachyon-log-');
	$sCode = 'require ' . \var_export($sLib . '/MailSo/Log/Driver.php', true) . ';'
		. 'require ' . \var_export($sLib . '/MailSo/Log/Drivers/StderrStream.php', true) . ';'
		. '(new \MailSo\Log\Drivers\StderrStream)->DisableGuidPrefix()->DisableTimePrefix()->DisableTypedPrefix()->Write("worker line");';
	\shell_exec(\escapeshellarg(\PHP_BINARY) . ' -d error_log=' . \escapeshellarg($sErrorLog)
		. ' -r ' . \escapeshellarg($sCode) . ' 2>/dev/null');
	$sLogged = (string) \file_get_contents($sErrorLog);
	\unlink($sErrorLog);
	$check('a process with /dev/null on fd 2 still logs', \str_contains($sLogged, 'worker line'));
}

echo $iFailed ? "\n{$iFailed} failed\n" : "\nall passed\n";
exit($iFailed ? 1 : 0);
