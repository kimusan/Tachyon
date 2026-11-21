<?php

// Run with: php test/rfc2231-parameters.php
// RFC 2231 lets a mailer cut a long parameter value into sections, name*0,
// name*1 ..., each either plain text or, with a star after the number, percent
// encoded with charset'language' in front of the first. The plain form, which
// mailers use for a long ASCII file name, was read wrongly in both places that
// assemble it: the IMAP BODYSTRUCTURE skipped it, so the attachment had no
// name, and the MIME parameter parser kept only the last section. Both also
// turned a + into a space, which percent encoding does not do.
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

$long = 'Quarterly report for the board meeting in November.pdf';
$cases = [
	'plain sections' => [
		['filename*0' => 'Quarterly report for the ', 'filename*1' => 'board meeting in November.pdf'],
		$long,
	],
	'plain sections out of order' => [
		['filename*1' => 'board meeting in November.pdf', 'filename*0' => 'Quarterly report for the '],
		$long,
	],
	'encoded sections' => [
		['filename*0*' => "utf-8''Gr%C3%BC%C3%9Fe%20an%20", 'filename*1*' => 'die%20Familie.pdf'],
		'Grüße an die Familie.pdf',
	],
	'encoded and plain sections mixed' => [
		['filename*0*' => "utf-8'de'%C3%84rger%20", 'filename*1' => '100% sicher.pdf'],
		'Ärger 100% sicher.pdf',
	],
	'more than 99 sections' => [
		array_combine(array_map(fn ($i) => "filename*{$i}", range(0, 104)), array_map(fn ($i) => chr(65 + $i % 26), range(0, 104))),
		implode(array_map(fn ($i) => chr(65 + $i % 26), range(0, 104))),
	],
	'a plus in an encoded value' => [
		['filename*' => "utf-8''C%2B%2B+notes.pdf"],
		'C+++notes.pdf',
	],
	'latin-1' => [
		['filename*' => "iso-8859-1''R%E9sum%E9.pdf"],
		'Résumé.pdf',
	],
];

// The IMAP path, from the parameters of a BODYSTRUCTURE
$decode = new ReflectionMethod(\MailSo\Imap\BodyStructure::class, 'decodeAttrParameter');
foreach ($cases as $case => [$params, $expected]) {
	$result = $decode->invoke(null, $params, 'filename', 'utf-8');
	check($expected === $result, "BODYSTRUCTURE, {$case}: '{$result}'");
}

// The MIME path, from a header's parameter string
foreach ($cases as $case => [$params, $expected]) {
	$raw = 'attachment';
	foreach ($params as $name => $value) {
		$raw .= str_ends_with($name, '*') ? "; {$name}={$value}" : "; {$name}=\"{$value}\"";
	}
	$result = (new \MailSo\Mime\ParameterCollection(substr($raw, strlen('attachment; '))))->ParameterValueByName('filename');
	check($expected === $result, "header, {$case}: '{$result}'");
}

// A parameter that is not cut into sections is left as it is
$plain = new \MailSo\Mime\ParameterCollection('charset="utf-8"; format=flowed');
check('utf-8' === $plain->ParameterValueByName('charset') && 'flowed' === $plain->ParameterValueByName('format'),
	'an ordinary parameter changed');

echo "rfc2231-parameters: ok\n";
