<?php

// Run with: php test/upload-csrf-token.php
// The upload endpoints (compose attachments, contact import, background)
// checked no CSRF token, unlike every JSON action. SameSite cookies and the
// Sec-Fetch check stopped cross-site posts with the defaults, but loosening
// both (for embedding) opened them. Uploads now need the token too.
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

$src = file_get_contents(dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/Tachyon/ServiceActions.php');
check(preg_match('/function privateUpload\(.*?hasCsrfToken\(Utils::GetCsrfToken\(/s', $src) === 1,
	'privateUpload() checks no CSRF token');
check(method_exists(\Tachyon\ServiceActions::class, 'hasCsrfToken'), 'no ServiceActions::hasCsrfToken()');

$has = function (?string $header, $post, string $token = 'the-token'): bool {
	$_SERVER = [];
	$_POST = [];
	null !== $header && $_SERVER['HTTP_X_SM_TOKEN'] = $header;
	null !== $post && $_POST['XToken'] = $post;
	return \Tachyon\ServiceActions::hasCsrfToken($token);
};
check($has('the-token', null), 'the header is not accepted');
check($has(null, 'the-token'), 'the form field is not accepted');
check(!$has(null, null), 'an upload without a token is accepted');
check(!$has('other', null) && !$has(null, 'other'), 'a wrong token is accepted');
check(!$has('other', 'the-token'), 'a wrong header is overridden by a right field');
check(!$has(null, ['the-token']), 'an array token is accepted');
check(!$has('', '', ''), 'an empty token matches an empty server token');

// The uploader sends it
$js = file_get_contents(dirname(__DIR__).'/vendors/jua/jua.js');
check(preg_match("/setRequestHeader\('X-SM-Token'/", $js) === 1, 'the uploader (vendors/jua/jua.js) sends no token');

echo "upload-csrf-token: ok\n";
