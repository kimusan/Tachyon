<?php

// Run with: php test/raw-download-type.php
// An attachment download kept the type its sender declared, with only
// Content-Disposition: attachment added. That header does not stop the URL
// being loaded as a same-origin <script src> (or stylesheet), so an
// attachment declared as text/javascript was a script the CSP's 'self'
// allowed. Downloads are application/octet-stream now.
spl_autoload_register(static function (string $class): void {
	$file = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/'.str_replace('\\', '/', $class).'.php';
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

class RawDownloadType
{
	use \Tachyon\Actions\Raw;
}

check(method_exists(RawDownloadType::class, 'servedContentType'),
	'no servedContentType(): a download is served with the type its sender declared');

foreach (['text/javascript', 'application/javascript', 'text/css', 'text/html', 'image/png', 'image/svg+xml'] as $type) {
	$download = true;
	check('application/octet-stream' === RawDownloadType::servedContentType($type, $download) && $download,
		"a download declared {$type} is served as such");
}

// Viewing inline is unchanged: allowed types as they are, the rest a download
$download = false;
check('image/png' === RawDownloadType::servedContentType('image/png; name="a.png"', $download) && !$download, 'inline PNG changed');
$download = false;
check('text/plain' === RawDownloadType::servedContentType('text/html', $download) && !$download, 'inline HTML is not shown as text');
$download = false;
check('text/plain' === RawDownloadType::servedContentType('text/javascript', $download) && !$download,
	'inline JavaScript is not shown as text');
$download = false;
check('application/octet-stream' === RawDownloadType::servedContentType('application/x-msdownload', $download) && $download,
	'an inline type outside the list is not made a download');

echo "raw-download-type: ok\n";
