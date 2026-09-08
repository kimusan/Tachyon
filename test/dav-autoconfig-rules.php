<?php

/**
 * Exercises the pure decision logic of the stalwart-dav-autoconfig plugin.
 * No bootstrap, no framework: run it with `php test/dav-autoconfig-rules.php`.
 */

require \dirname(__DIR__) . '/plugins/stalwart-dav-autoconfig/Rules.php';

use Plugins\StalwartDavAutoconfig\Rules;

$iFailures = 0;

function check(string $sLabel, $mActual, $mExpected) : void
{
	global $iFailures;
	if ($mActual === $mExpected) {
		echo "PASS  {$sLabel}\n";
		return;
	}
	++$iFailures;
	echo "FAIL  {$sLabel}\n";
	echo '      expected: ' . \var_export($mExpected, true) . "\n";
	echo '      actual:   ' . \var_export($mActual, true) . "\n";
}

check('parses a comma separated list',
	Rules::parseAllowList('a@x.com, b@y.com'), array('a@x.com', 'b@y.com'));
check('parses a whitespace separated list',
	Rules::parseAllowList("a@x.com\n b@y.com "), array('a@x.com', 'b@y.com'));
check('an empty setting parses to no entries',
	Rules::parseAllowList('   '), array());

check('matches a full address',
	Rules::isAllowed('wojt@hunterblood.au', array('wojt@hunterblood.au')), true);
check('matches a bare domain',
	Rules::isAllowed('kate@hunterblood.au', array('hunterblood.au')), true);
check('is case insensitive on both sides',
	Rules::isAllowed('Wojt@HunterBlood.AU', array('wojt@hunterblood.au')), true);
check('does not match a different domain',
	Rules::isAllowed('kate@clinically.com.au', array('hunterblood.au')), false);
check('does not match a different address in an allowed-looking list',
	Rules::isAllowed('jo@hunterblood.au', array('wojt@hunterblood.au')), false);
check('an empty allow list matches nobody',
	Rules::isAllowed('wojt@hunterblood.au', array()), false);
check('an empty address matches nothing',
	Rules::isAllowed('', array('hunterblood.au')), false);
check('a bare domain string matches its own entry',
	Rules::isAllowed('hunterblood.au', array('hunterblood.au')), true);

check('expands {email} into a percent-encoded collection URL',
	Rules::collectionUrl('https://mail.example.com/dav/card/{email}/default/', 'wojt@hunterblood.au'),
	'https://mail.example.com/dav/card/wojt%40hunterblood.au/default/');
check('encodes the @ so the path segment stays one segment',
	Rules::collectionUrl('https://h/dav/card/{email}/default/', 'a@b.com'),
	'https://h/dav/card/a%40b.com/default/');
check('a template without the placeholder is returned unchanged',
	Rules::collectionUrl('https://mail.example.com/dav/card', 'wojt@hunterblood.au'),
	'https://mail.example.com/dav/card');
check('an empty template stays empty, so an operator can disable one half',
	Rules::collectionUrl('', 'wojt@hunterblood.au'), '');

check('payload is read+write and carries the address as the DAV user',
	Rules::payload('wojt@hunterblood.au', 'secret', 'https://mail.clinically.com.au/dav/card'),
	array(
		'Mode' => 1,
		'User' => 'wojt@hunterblood.au',
		'Password' => 'secret',
		'Url' => 'https://mail.clinically.com.au/dav/card',
	));

echo $iFailures ? "\n{$iFailures} failure(s)\n" : "\nall passed\n";
exit($iFailures ? 1 : 0);
