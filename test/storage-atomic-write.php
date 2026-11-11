<?php

// Run with: php test/storage-atomic-write.php
// Issue #198. The session record is rewritten about once a minute while the
// browser sends requests in parallel. file_put_contents() empties the file
// before writing, so a request reading it in that moment got '' and ended the
// session. FileStorage::Put() now writes beside the file and renames it into
// place. A child process rewrites a real session record while this one reads.
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

use Tachyon\Providers\Storage\Enumerations\StorageType;

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$sDir = sys_get_temp_dir().'/storage-atomic-write-'.getmypid();
$oStorage = new \Tachyon\Providers\Storage\FileStorage($sDir);
$sAccount = 'user@example.com';
$sToken = 'session-token';

// The writer: rewrites the record as getMainAccountFromToken() does
if ('writer' === ($argv[1] ?? '')) {
	$oStorage = new \Tachyon\Providers\Storage\FileStorage($argv[2]);
	$iEnd = microtime(true) + 1.5;
	while (microtime(true) < $iEnd) {
		$oStorage->Put($sAccount, StorageType::SESSION, $sToken, (string) time());
	}
	exit;
}

try {
	check($oStorage->Put($sAccount, StorageType::SESSION, $sToken, (string) time()), 'the session record was not stored');
	$sFile = $oStorage->GenerateFilePath($sAccount, StorageType::SESSION).$sToken;
	check(is_file($sFile), 'the record is not where FileStorage reads it');
	check(0600 === (fileperms($sFile) & 0777), 'the record is readable by others: '.decoct(fileperms($sFile) & 0777));

	$rWriter = proc_open([PHP_BINARY, __FILE__, 'writer', $sDir], [], $aPipes);
	$iReads = $iEmpty = 0;
	while (proc_get_status($rWriter)['running']) {
		$mValue = $oStorage->Get($sAccount, StorageType::SESSION, $sToken);
		++$iReads;
		if (!is_string($mValue) || !is_numeric($mValue)) {
			++$iEmpty;
		}
	}
	proc_close($rWriter);

	check(1000 < $iReads, "only {$iReads} reads, the race was not exercised");
	check(0 === $iEmpty, "{$iEmpty} of {$iReads} reads found the session record empty");
	check([basename($sFile)] === array_values(array_diff(scandir(dirname($sFile)), ['.', '..'])),
		'a temporary file was left behind');
} finally {
	\MailSo\Base\Utils::RecRmDir($sDir);
}

echo "storage-atomic-write: ok\n";
