<?php

// Run with: php test/command-line-breaks.php   (needs mbstring and intl, e.g. in the web container)
// IMAP, ManageSieve and SMTP are line based. A line break or NUL in a value
// written into a command ends the command early and the rest is read as a
// further command. Each place that writes such a value must refuse it first.
spl_autoload_register(static function (string $class): void {
	$base = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/';
	$file = str_starts_with($class, 'Tachyon\\Util\\')
		? $base.'tachyon_util/'.strtolower(str_replace('\\', '/', substr($class, 13))).'.php'
		: $base.str_replace('\\', '/', $class).'.php';
	if (is_file($file)) {
		require_once $file;
	}
});

$failed = 0;

function expectRefused(string $name, callable $fn): void
{
	global $failed;
	try {
		$fn();
		echo "FAIL $name: accepted\n";
		++$failed;
	} catch (\InvalidArgumentException $e) {
		// refused before anything is sent
	} catch (\Throwable $e) {
		echo "FAIL $name: wrong exception ".get_class($e).': '.$e->getMessage()."\n";
		++$failed;
	}
}

function expectNotRefused(string $name, callable $fn): void
{
	global $failed;
	try {
		$fn();
	} catch (\InvalidArgumentException $e) {
		echo "FAIL $name: refused: ".$e->getMessage()."\n";
		++$failed;
	} catch (\Throwable $e) {
		// Some other failure is fine here: no server is connected in this test
	}
}

$bad = ["a\r\nb", "a\nb", "a\rb", "a\x00b"];

// IMAP quoted strings (EscapeString)
class EscapeImap extends \MailSo\Imap\ImapClient
{
	public function hasCapability(string $sExtentionName): bool { return false; }
}
$imap = new EscapeImap;
$imap->Settings = (new ReflectionClass(\MailSo\Imap\Settings::class))->newInstanceWithoutConstructor();
foreach ($bad as $v) {
	expectRefused('EscapeString '.json_encode($v), fn() => $imap->EscapeString($v));
}
if ('"a \\"b\\" \\\\ c"' !== $imap->EscapeString('a "b" \\ c')) {
	echo "FAIL EscapeString quoting changed\n";
	++$failed;
}

// IMAP search terms, quoted and literal form (non-ASCII)
class LiteralImap extends EscapeImap
{
	public function hasCapability(string $sExtentionName): bool { return 'LITERAL+' === $sExtentionName; }
}
$literalImap = new LiteralImap;
$literalImap->Settings = $imap->Settings;
foreach (["x\r\nZ", "é\r\nZ"] as $v) {
	expectRefused('search '.json_encode($v), fn() => \MailSo\Imap\SearchCriterias::escapeSearchString($literalImap, $v));
}
$literal = \MailSo\Imap\SearchCriterias::escapeSearchString($literalImap, 'é');
if (!str_starts_with($literal, '{')) {
	echo "FAIL non-ASCII search without a line break is no longer sent as a literal: $literal\n";
	++$failed;
}

// IMAP flags and keywords are atoms
foreach (["a b", "a\r\nb", "a(b", "a*b", "a\"b"] as $v) {
	expectRefused('STORE keyword '.json_encode($v), fn() => $imap->MessageStoreFlag(
		new \MailSo\Imap\SequenceSet([1]), [$v], \MailSo\Imap\Enumerations\StoreAction::ADD_FLAGS_SILENT));
}
foreach (['\\Seen', '$Label', 'label-1'] as $v) {
	expectNotRefused('STORE flag '.$v, fn() => $imap->MessageStoreFlag(
		new \MailSo\Imap\SequenceSet([1]), [$v], \MailSo\Imap\Enumerations\StoreAction::ADD_FLAGS_SILENT));
}

// ManageSieve script names
$sieve = (new ReflectionClass(\MailSo\Sieve\SieveClient::class))->newInstanceWithoutConstructor();
$quote = new ReflectionMethod(\MailSo\Sieve\SieveClient::class, 'quoteName');
foreach ($bad as $v) {
	expectRefused('Sieve name '.json_encode($v), fn() => $quote->invoke(null, $v));
}
if ('"a\\"b\\\\c"' !== $quote->invoke(null, 'a"b\\c')) {
	echo "FAIL Sieve name quoting\n";
	++$failed;
}

// What the Sieve commands put on the wire: each name escaped exactly once
final class SieveSent extends \RuntimeException {}
class CapturingSieve extends \MailSo\Sieve\SieveClient
{
	public function IsConnected(bool $bThrowExceptionOnFalse = false) : bool { return true; }
	protected function sendRaw(string $sRaw, bool $bWriteToLog = true, string $sFakeRaw = '') : void
	{
		throw new SieveSent($sRaw);
	}
}
$capturing = (new ReflectionClass(CapturingSieve::class))->newInstanceWithoutConstructor();
$sent = static function (callable $fn): string {
	try {
		$fn();
	} catch (SieveSent $e) {
		return $e->getMessage();
	}
	return '(nothing sent)';
};
$expected = [
	'GETSCRIPT "a\\"b\\\\c"' => fn() => $capturing->GetScript('a"b\\c'),
	'PUTSCRIPT "a\\"b\\\\c" {5+}' => fn() => $capturing->PutScript('a"b\\c', 'keep;'),
	'SETACTIVE "a\\"b\\\\c"' => fn() => $capturing->SetActiveScript('a"b\\c'),
	'DELETESCRIPT "a\\"b\\\\c"' => fn() => $capturing->DeleteScript('a"b\\c'),
	'RENAMESCRIPT "a\\"b" "c\\\\d"' => fn() => $capturing->RenameScript('a"b', 'c\\d'),
];
foreach ($expected as $line => $fn) {
	$actual = $sent($fn);
	if ($line !== $actual) {
		echo 'FAIL Sieve command: expected '.json_encode($line).', got '.json_encode($actual)."\n";
		++$failed;
	}
}
foreach ($bad as $v) {
	expectRefused('SETACTIVE '.json_encode($v), fn() => $capturing->SetActiveScript($v));
	expectRefused('RENAMESCRIPT new name '.json_encode($v), fn() => $capturing->RenameScript('a', $v));
}

// SMTP addresses
$smtp = (new ReflectionClass(\MailSo\Smtp\SmtpClient::class))->newInstanceWithoutConstructor();
foreach ($bad as $v) {
	expectRefused('MAIL FROM '.json_encode($v), fn() => $smtp->MailFrom($v));
	expectRefused('RCPT TO '.json_encode($v), fn() => $smtp->MailTo($v));
	expectRefused('VRFY '.json_encode($v), fn() => $smtp->Vrfy($v));
}

// Identity address (saved from the client, later used as MAIL FROM)
foreach ($bad as $v) {
	$id = new \Tachyon\Model\Identity;
	if ($id->FromJSON(['Email' => 'bob@x.test'.$v], false)) {
		echo 'FAIL identity accepted '.json_encode($v)."\n";
		++$failed;
	}
}
$id = new \Tachyon\Model\Identity;
if (!$id->FromJSON(['Email' => 'bob@x.test'], false)) {
	echo "FAIL identity refused a normal address\n";
	++$failed;
}

// FETCH sections built from part numbers (MessageMimeStream: download links,
// attachments, PGP decrypt): only part numbers reach the command
final class ImapSent extends \RuntimeException {}
class CapturingImap extends EscapeImap
{
	public function IsConnected(bool $bThrowExceptionOnFalse = false) : bool { return true; }
	public function FolderExamine(string $sFolderName, bool $bForceReselect = false) : \MailSo\Imap\FolderInformation
	{
		return (new ReflectionClass(\MailSo\Imap\FolderInformation::class))->newInstanceWithoutConstructor();
	}
	protected function sendRaw(string $sRaw, bool $bWriteToLog = true, string $sFakeRaw = '') : void
	{
		throw new ImapSent($sRaw);
	}
}
$capImap = new CapturingImap;
$capImap->Settings = $imap->Settings;
$mail = (new ReflectionClass(\MailSo\Mail\MailClient::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(\MailSo\Mail\MailClient::class, 'oImapClient'))->setValue($mail, $capImap);
$mimeSent = static function (string $sMimeIndex) use ($mail) : string {
	try {
		$mail->MessageMimeStream(fn() => null, 'INBOX', 1, $sMimeIndex);
	} catch (ImapSent $e) {
		return $e->getMessage();
	}
	return '(nothing sent)';
};
foreach (["1\r\nX", "1 X", "1]", "1.MIME] X", "0", "1..2", "a", "TEXT"] as $v) {
	expectRefused('MIME part '.json_encode($v), fn() => $mail->MessageMimeStream(fn() => null, 'INBOX', 1, $v));
}
foreach (['1' => 'BODY.PEEK[1.MIME]', '1.2.3' => 'BODY.PEEK[1.2.3.MIME]', '' => 'BODY.PEEK[HEADER]'] as $v => $want) {
	$line = $mimeSent((string) $v);
	if (!str_contains($line, $want)) {
		echo 'FAIL MIME part '.json_encode((string) $v).': expected '.$want.' in '.json_encode($line)."\n";
		++$failed;
	}
}
foreach (['1', '2', '1.2.3', '10.1'] as $v) {
	if (!\MailSo\Imap\BodyStructure::IsPartId($v)) {
		echo "FAIL IsPartId refused $v\n";
		++$failed;
	}
}

// Any FETCH item with a line break is refused before it is sent
foreach ($bad as $v) {
	expectRefused('FETCH item '.json_encode($v), fn() => $capImap->Fetch(['BODY.PEEK['.$v.']'], '1', true));
}

if ($failed) {
	echo "$failed failed\n";
	exit(1);
}
echo "ok\n";
