<?php

// Run with: php test/smtp-user-follows-domain.php
// #194: LoginProcess() stored the SMTP user it derived from the domain's
// settings in the account, and the account goes into the session and the
// "remember me" token as is. Turning "Use short login" off for SMTP then kept
// sending the bare local part until the next login, which a renewed remember-me
// token puts off for good. Only a user a plugin mapped is stored now; the rest
// is derived from the domain on every send.
//
// Verified in the Nextcloud test env against a logging SMTP listener: logged in
// with short login on, turned it off, sent again in the same session. Before,
// both sends authenticated as "alice"; after, the second one as
// "alice@test.local".

$_ENV['TACHYON_INCLUDE_AS_API'] = true;
require __DIR__ . '/../index.php';

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$aDomain = json_decode(file_get_contents(__DIR__.'/../tachyon/v/0.0.0/app/domains/default.json'), true);
$aDomain['SMTP']['shortLogin'] = true;
$oDomain = \Tachyon\Model\Domain::fromArray('example.com', $aDomain);

$oAccount = new \Tachyon\Model\MainAccount;
$oAccount->setCredentials($oDomain, 'alice@example.com', 'alice@example.com',
	new \Tachyon\Util\SensitiveString('x'), '');

check('alice' === $oAccount->SmtpUser(), 'short login on: '.$oAccount->SmtpUser());
$oDomain->SmtpSettings()->shortLogin = false;
check('alice@example.com' === $oAccount->SmtpUser(), 'short login off: '.$oAccount->SmtpUser());
check(!isset($oAccount->jsonSerialize()['smtp']['user']),
	'a derived SMTP user ends up in the session token');

$oAccount->setSmtpUser('mapped');
check('mapped' === $oAccount->SmtpUser(), 'a mapped SMTP user is not used');
check('mapped' === ($oAccount->jsonSerialize()['smtp']['user'] ?? null),
	'a mapped SMTP user is lost from the session token');

// LoginProcess() must drop the derived user before it reaches the account.
$sSrc = file_get_contents(__DIR__.'/../tachyon/v/0.0.0/app/libraries/Tachyon/Actions/UserAuth.php');
preg_match('/function LoginProcess\(.*?\n\t\}/s', $sSrc, $m);
check(!empty($m[0]), 'could not read LoginProcess()');
$iClear = strpos($m[0], "\$aCredentials['smtpUser'] = '';");
check(false !== $iClear, 'LoginProcess() keeps the SMTP user derived from the domain');
check($iClear < strpos($m[0], '->setCredentials('), 'the derived SMTP user is dropped after it was stored');
check(str_contains($m[0], "\$aCredentials['smtpUser'] === \$aCredentials['domain']->SmtpSettings()->fixUsername(\$aCredentials['email'])"),
	'LoginProcess() drops the SMTP user without checking it is the derived one');

echo "smtp-user-follows-domain: ok\n";
