<?php

// Run with: php test/auth-results-trust.php
// Authentication-Results is a header anyone can write, and AuthStatuses() read
// every copy of it. A sender could therefore add
// "dkim=pass header.d=<its own From domain>", which Message.php then stamps onto
// the From address as a verified signature, and which satisfies a "+dkim" rule in
// the remote-image whitelist, so tracking images load without the consent prompt.
// Reported in GHSA-vqwv-r925-cmrq, inherited from SnappyMail.
//
// A result now has to come from an authserv-id the install trusts. Nothing is
// configured by default, so the topmost header names the local server: that is
// the last one to handle the message, it keeps the separate headers opendkim and
// opendmarc each add, and a header the sender appended below it is ignored.
define('APP_VERSION', 'test');
spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'Tachyon\\Util\\')) {
		$file = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/tachyon_util/'.strtolower(str_replace('\\', '/', substr($class, 13))).'.php';
	} else {
		$file = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/'.str_replace('\\', '/', $class).'.php';
	}
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

/** @param string[] $aLines */
function auth(array $aLines, string $sTrusted = ''): array
{
	$sRaw = '';
	foreach ($aLines as $sLine) {
		$sRaw .= $sLine."\r\n";
	}
	$oHeaders = new \MailSo\Mime\HeaderCollection($sRaw."X-Next: 1\r\n");
	return $oHeaders->AuthStatuses($sTrusted);
}

$sLocal  = 'Authentication-Results: mx.example.com; dkim=pass header.d=real.example';
$sNone   = 'Authentication-Results: mx.example.com; dkim=none';
$sForged = 'Authentication-Results: spoof.invalid; dkim=pass header.d=bank.example';

// The ordering the collection reports has to be the order the headers arrived in,
// or "topmost" means nothing.
$aAll = (new \MailSo\Mime\HeaderCollection($sLocal."\r\n".$sForged."\r\n"))
	->ValuesByName(\MailSo\Mime\Enumerations\Header::AUTHENTICATION_RESULTS);
check(2 === count($aAll), 'both Authentication-Results headers are not kept');
check(str_contains($aAll[0], 'mx.example.com'), 'ValuesByName does not return the topmost header first');

// The local result is read as before.
$aAuth = auth([$sLocal]);
check(1 === count($aAuth['dkim']) && 'pass' === $aAuth['dkim'][0][0] && 'real.example' === $aAuth['dkim'][0][1],
	'a local dkim result is no longer read');

// A header the sender appended under the local one is ignored, including when the
// local server reported nothing it could use (an unsigned message gets
// "dkim=none" with no identity, which AuthStatuses drops, so before this change
// the sender's line was the only dkim result left and became dkim[0]).
check(1 === count(auth([$sLocal, $sForged])['dkim']), 'a forged result is read next to the local one');
check([] === auth([$sNone, $sForged])['dkim'], 'a forged result is read when the local server reported dkim=none');
check([] === auth([$sForged], 'mx.example.com')['dkim'], 'a forged result is read although its authserv-id is not trusted');

// opendkim and opendmarc each write their own header with the same authserv-id.
$aAuth = auth([
	'Authentication-Results: mx.example.com; dkim=pass header.d=real.example',
	'Authentication-Results: mx.example.com; dmarc=pass header.from=real.example',
]);
check('pass' === ($aAuth['dkim'][0][0] ?? ''), 'the dkim header of a two-header setup is lost');
check('pass' === ($aAuth['dmarc'][0][0] ?? ''), 'the dmarc header of a two-header setup is lost');

// An authserv-id may carry a version number or a comment. Held against a named id,
// since a lone header is its own topmost and would match whatever it parsed to.
check('pass' === (auth(['Authentication-Results: mx.example.com 1; dkim=pass header.d=real.example'], 'mx.example.com')['dkim'][0][0] ?? ''),
	'an authserv-id with a version number is not recognised');
check('pass' === (auth(['Authentication-Results: mx.example.com (sorting hat); dkim=pass header.d=real.example'], 'mx.example.com')['dkim'][0][0] ?? ''),
	'an authserv-id with a comment is not recognised');

// Naming the ids reads them wherever they sit, and only them.
check('pass' === (auth([$sForged, $sLocal], 'mx.example.com')['dkim'][0][0] ?? ''),
	'a trusted result below an untrusted one is not read');
check([] === auth([$sForged, $sLocal], 'other.example')['dkim'], 'an unlisted authserv-id is read');
check('pass' === (auth([$sLocal], 'MX.EXAMPLE.COM, other.example')['dkim'][0][0] ?? ''),
	'the trusted list is case sensitive or does not split on commas');

// X-DKIM-Authentication-Results has no authserv-id at all, so there is nothing to
// tie it to: the topmost is read as a fallback, and naming trusted ids skips it.
$sX = 'X-DKIM-Authentication-Results: signer="real.example" status="pass"';
check('pass' === (auth([$sX])['dkim'][0][0] ?? ''), 'the X-DKIM fallback no longer works');
check(1 === count(auth([$sX, 'X-DKIM-Authentication-Results: signer="bank.example" status="pass"'])['dkim']),
	'a second X-DKIM header is still read');
check([] === auth([$sX], 'mx.example.com')['dkim'], 'the X-DKIM fallback is read although trusted ids are named');

// What is still open, and why the setting exists: a server that adds no results
// of its own leaves the sender's header as the topmost one.
check('pass' === (auth([$sForged])['dkim'][0][0] ?? ''),
	'the residual case changed; update GHSA-vqwv-r925-cmrq and the config comment');

// The caller passes the setting through.
$sSrc = file_get_contents(dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/MailSo/Mail/Message.php');
check(str_contains($sSrc, "AuthStatuses(\\Tachyon\\Api::Config()->Get('security', 'trusted_authserv_ids', ''))"),
	'Message.php does not pass trusted_authserv_ids to AuthStatuses()');
$sSrc = file_get_contents(dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/Tachyon/Config/Application.php');
check(str_contains($sSrc, "'trusted_authserv_ids'"), 'trusted_authserv_ids is not in the config schema');

echo "auth-results-trust: ok\n";
