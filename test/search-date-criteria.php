<?php

// Run with: php test/search-date-criteria.php
spl_autoload_register(static function (string $class): void {
	$file = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/'.str_replace('\\', '/', $class).'.php';
	if (is_file($file)) {
		require_once $file;
	}
});

class DateSearchImap extends \MailSo\Imap\ImapClient
{
	public function hasCapability(string $sExtentionName): bool { return false; }
}

$imap = new DateSearchImap;
$imap->Settings = (new ReflectionClass(\MailSo\Imap\Settings::class))->newInstanceWithoutConstructor();
$criteria = static fn (string $search): string => (string) \MailSo\Imap\SearchCriterias::fromString($imap, 'INBOX', $search, false);

$cases = [
	// What the advanced search sends for a range, and for the same with the Date header
	'since=2026-01-01&before=2026-03-01' => 'BEFORE 1-Mar-2026 SINCE 1-Jan-2026',
	'sentsince=2026-01-01&sentbefore=2026-03-01' => 'SENTSINCE 1-Jan-2026 SENTBEFORE 1-Mar-2026',
	'senton=2026-02-14' => 'SENTON 14-Feb-2026',
	// date=from/to includes both ends; it used to be dropped
	'date=2026-01-01/2026-02-28' => 'BEFORE 1-Mar-2026 SINCE 1-Jan-2026',
	'date=/2026-02-28' => 'BEFORE 1-Mar-2026',
	// A lone date still means from that day on
	'date=2026-01-01' => 'SINCE 1-Jan-2026',
];
$failed = 0;
foreach ($cases as $search => $expected) {
	$actual = $criteria($search);
	if ($actual !== $expected) {
		echo "$search: expected '$expected', got '$actual'\n";
		++$failed;
	}
}
if ($failed) {
	exit(1);
}
echo "search-date-criteria: ok\n";
