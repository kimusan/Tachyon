<?php

// Run with: php test/contacts-csv-columns.php
// The CSV export filled the "Title" column (the name prefix in the Outlook
// layout it writes) with the display name, and left "Job Title" empty. On
// import "Title" became the vCard TITLE, the job title: exporting and
// importing gave every contact its own name as job title.
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

use Sabre\VObject\Component\VCard;
use Tachyon\Providers\AddressBook\Utils;

$card = new VCard;
$card->FN = 'Dr. Alice Example';
$card->N = ['Example', 'Alice', '', 'Dr.', ''];
$card->TITLE = 'Head of Research';
$card->add('EMAIL', 'alice@example.com');

$csv = function (VCard $card) : array {
	$r = fopen('php://memory', 'w+');
	Utils::VCardToCsv($r, $card, true);
	rewind($r);
	$head = fgetcsv($r, 0, ',', '"', '');
	$row = fgetcsv($r, 0, ',', '"', '');
	return array_combine($head, $row);
};
$out = $csv($card);
check('Dr.' === $out['Title'], "\"Title\" holds \"{$out['Title']}\", not the name prefix");
check('Dr. Alice Example' === $out['Display Name'], 'the display name moved');
check('Head of Research' === $out['Job Title'], "\"Job Title\" holds \"{$out['Job Title']}\", not the job title");

// Back in: the same contact
$r = fopen('php://memory', 'w+');
Utils::VCardToCsv($r, $card, true);
rewind($r);
$in = iterator_to_array(Utils::CsvStreamToContacts($r, ','))[0]->vCard;
check('Head of Research' === (string) $in->TITLE, 'after a round trip the job title is "'.$in->TITLE.'"');
check('Dr.' === ($in->N->getParts()[3] ?? null), 'after a round trip the name prefix is lost');
check('Dr. Alice Example' === (string) $in->FN, 'after a round trip the display name is "'.$in->FN.'"');

echo "contacts-csv-columns: ok\n";
