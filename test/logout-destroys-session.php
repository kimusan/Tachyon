<?php

// Run with: php test/logout-destroys-session.php
// Covers GHSA-gc87-g2gf-xj6q. Logout only asked the browser to drop its
// cookies; the server-side session record stayed, and FileStorage::Get()
// touches it on read, so a copied cookie kept working afterwards for as long
// as it was used.

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$sDir = __DIR__.'/../tachyon/v/0.0.0/app/libraries/Tachyon/';
$sSrc = file_get_contents($sDir.'Actions/UserAuth.php');

preg_match('/function Logout\(bool \$bMain\) : void\s*\{(.*?)\n\t\}/s', $sSrc, $m);
check(!empty($m[1]), 'could not read Logout()');
$sBody = $m[1];

// Directly, or through Utils::ClearSession(), which also removes what is kept
// with the record (the OAuth2 tokens)
$bHelper = str_contains($sBody, 'Utils::ClearSession(');
check($bHelper || str_contains($sBody, 'StorageType::SESSION'),
	'Logout() does not touch the server-side session record');
check($bHelper || preg_match('/StorageProvider\(\)->Clear\([^)]*StorageType::SESSION/', $sBody) === 1,
	'the session record is read but not cleared');
if ($bHelper) {
	$sUtils = file_get_contents($sDir.'Utils.php');
	check(preg_match('/function ClearSession\(.*?->Clear\(\$mAccount, [^,]*StorageType::SESSION, \$sToken\)/s', $sUtils) === 1,
		'Utils::ClearSession() does not clear the session record');
}
check(!preg_match('#^\s*//\s*Cookies::clear\(Utils::SESSION_TOKEN\)#m', $sBody),
	'the SESSION_TOKEN cookie clear is commented out again');
check(str_contains($sBody, 'Cookies::clear(Utils::SESSION_TOKEN)'),
	'the SESSION_TOKEN cookie is not cleared');

// Order: the account has to be resolved while the cookies are still there.
$iResolve = strpos($sBody, 'getMainAccountFromToken(false)');
$iClear = strpos($sBody, 'Cookies::clear(Utils::SESSION_TOKEN)');
check(false !== $iResolve, 'Logout() never resolves the account');
check($iResolve < $iClear, 'the account is resolved after its cookie has been cleared');
$iDestroy = $bHelper ? strpos($sBody, 'Utils::ClearSession(') : strpos($sBody, 'StorageProvider()->Clear(');
check($iDestroy < $iClear, 'the session is destroyed after the cookie is gone, so the token is unknown');

// Logging out of an additional account must not end the main session.
check(preg_match('/if \(\$bMain\) \{/', $sBody) === 1,
	'the session is destroyed regardless of which account is logging out');
$iGuard = strpos($sBody, 'if ($bMain) {');
check($iGuard < $iDestroy, 'the destruction is not inside the main-account guard');

// GetSessionToken(false) must not mint a token while logging out.
check(str_contains($sBody, 'GetSessionToken(false)'),
	'Logout() calls GetSessionToken() in a way that generates a new token');

// The claim the fix rests on: reading a session file touches it, so an idle
// sweep never reaches a session somebody is replaying.
$sStorage = file_get_contents($sDir.'Providers/Storage/FileStorage.php');
check(preg_match('/function Get\(.*?\\\\touch\(\$sFileName\)/s', $sStorage) === 1,
	'FileStorage::Get() no longer touches on read; re-check whether the sweep alone would expire a stolen session');

echo "logout-destroys-session: ok\n";
