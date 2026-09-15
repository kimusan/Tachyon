<?php

/**
 * Run with `php test/search-filters-login.php`.
 * Real plugin, token lookup and file-backed settings; only IMAP is simulated.
 * No application bootstrap, credentials, network or existing user data.
 */

spl_autoload_register(function (string $class): void {
	$base = dirname(__DIR__) . '/tachyon/v/0.0.0/app/libraries/';
	$file = str_starts_with($class, 'Tachyon\\Util\\')
		? $base . 'tachyon_util/' . strtolower(str_replace('\\', '/', substr($class, 13))) . '.php'
		: $base . str_replace('\\', '/', $class) . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});
require dirname(__DIR__) . '/plugins/search-filters/index.php';

class LoginTestActions extends \Tachyon\Actions
{
	// Skip application bootstrapping, retaining the real UserAuth token checks.
	public function __construct(private \Tachyon\Providers\Settings $settings) {}

	public function SettingsProvider(bool $bLocal = false): \Tachyon\Providers\Settings
	{
		return $this->settings;
	}
}

class LoginTestMainAccount extends \Tachyon\Model\MainAccount
{
	public function __construct(private string $email) {}
	public function Email(): string { return $this->email; }
}

class LoginTestAdditionalAccount extends \Tachyon\Model\AdditionalAccount
{
	public function Email(): string { return 'second@example.test'; }
	// Avoid the global Api singleton; this is the established main account.
	public function ParentEmail(): string { return 'main@example.test'; }
}

class LoginTestImap extends \MailSo\Imap\ImapClient
{
	public array $moves = [];
	public function Capability(): ?array { return []; }
	public function FolderSelect(string $sFolderName, bool $bForceReselect = false): \MailSo\Imap\FolderInformation
	{
		return new \MailSo\Imap\FolderInformation($sFolderName, true);
	}
	public function MessageSearch(string $sSearchCriterias, bool $bReturnUid = true): array
	{
		return [17];
	}
	public function MessageMove(string $sFromFolder, string $sToFolder, \MailSo\Imap\SequenceSet $oRange): void
	{
		$this->moves[] = [$sFromFolder, $sToFolder, (string) $oRange];
	}
}

$directory = sys_get_temp_dir() . '/tachyon-search-filters-' . bin2hex(random_bytes(8));
$storage = new \Tachyon\Providers\Storage(new \Tachyon\Providers\Storage\FileStorage($directory));
$settings = new \Tachyon\Providers\Settings(new \Tachyon\Providers\Settings\DefaultSettings($storage));
$main = new LoginTestMainAccount('main@example.test');
$other = new LoginTestMainAccount('other@example.test');
$additional = new LoginTestAdditionalAccount();
$rule = fn(string $folder) => ['searchQ' => 'subject=invoice', 'fFolder' => $folder];
$buckets = [
	'main@example.test' => [$rule('MainArchive')],
	'second@example.test' => [$rule('SecondArchive')],
];
$cases = [
	['fresh main login without cookies applies its own bucket', $main, null, $buckets, true, 'MainArchive'],
	['fresh main login applies legacy flat filters', $main, null, [$rule('LegacyArchive')], true, 'LegacyArchive'],
	['fresh main login without filters succeeds', $main, null, [], true, null],
	['additional login applies only its bucket in parent settings', $additional, $main, $buckets, true, 'SecondArchive'],
	['additional login does not inherit legacy main filters', $additional, $main, [$rule('LegacyArchive')], true, null],
	['supplied main account wins over a different current account', $main, $other, $buckets, true, 'MainArchive'],
	['failed IMAP login does not apply filters', $main, null, $buckets, false, null],
];
$failures = 0;

try {
	foreach ($cases as [$label, $account, $current, $filters, $success, $destination]) {
		$_COOKIE = [];
		$ownerSettings = $settings->Load($main);
		$ownerSettings->SetConf('Plugins', ['search-filters' => ['SFilters' => $filters]]);
		if (!$ownerSettings->save()) {
			throw new RuntimeException('Cannot seed disposable settings');
		}
		$otherSettings = $settings->Load($other);
		$otherSettings->SetConf('Plugins', ['search-filters' => ['SFilters' => [
			'main@example.test' => [$rule('WrongOwnerArchive')],
		]]]);
		$otherSettings->save();
		$actions = new LoginTestActions($settings);
		if ($current) {
			$actions->SetMainAuthAccount($current);
		}
		// Keep the real manager/settings lookup without loading all installed plugins.
		$manager = (new ReflectionClass(\Tachyon\Plugins\Manager::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty($manager, 'oActions'))->setValue($manager, $actions);
		$plugin = (new SearchFiltersPlugin())->SetName('search-filters')->SetPluginManager($manager);
		$imap = new LoginTestImap();
		// No TLS configuration is needed by the simulated IMAP connection.
		$imap->Settings = (new ReflectionClass(\MailSo\Imap\Settings::class))->newInstanceWithoutConstructor();
		$before = $settings->Load($main)->toArray();

		try {
			$plugin->ApplyFilters($account, $imap, $success, $imap->Settings);
			$expected = $destination ? [['INBOX', $destination, '17']] : [];
			if ($imap->moves !== $expected) {
				throw new RuntimeException('Wrong mailbox filter actions: ' . json_encode($imap->moves));
			}
			if ($settings->Load($main)->toArray() !== $before || $_COOKIE !== []) {
				throw new RuntimeException('Login hook changed stored settings or established a session');
			}
			echo "PASS  {$label}\n";
		} catch (Throwable $exception) {
			++$failures;
			echo "FAIL  {$label}: " . get_class($exception) . ' [' . $exception->getCode() . '] ' . $exception->getMessage() . "\n";
		}
	}
} finally {
	if (is_dir($directory)) {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($directory);
	}
}
exit($failures ? 1 : 0);
