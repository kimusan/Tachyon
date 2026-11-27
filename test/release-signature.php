<?php

// Run with: php test/release-signature.php (needs sodium)
// Plugin and core archives are unpacked into the code tree, so the repository
// client only keeps one the Tachyon release key signed. The fixture is a real
// plugin archive from the 4.8.0 release with its published signature.

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

require __DIR__ . '/../tachyon/v/0.0.0/app/libraries/tachyon_util/releasesignature.php';

$Sig = 'Tachyon\\Util\\ReleaseSignature';
$sDir = __DIR__ . '/fixtures/release-signature';
$sFile = "{$sDir}/login-http-1.0.tgz";
$sArmored = file_get_contents("{$sFile}.asc");

$refused = static function (string $sFile, string $sSignature) use ($Sig) : ?string {
	try {
		$Sig::verifyFile($sFile, $sSignature);
		return null;
	} catch (\Exception $e) {
		return $e->getMessage();
	}
};

check(null === $refused($sFile, $sArmored), 'the published signature was refused');

// packages.json carries the body without the armor lines
$sBody = trim(preg_replace('/-----(BEGIN|END) PGP SIGNATURE-----/', '', $sArmored));
check(null === $refused($sFile, $sBody), 'the bare signature body was refused');

// One byte more and it is another file
$sTampered = tempnam(sys_get_temp_dir(), 'sig');
register_shutdown_function(static fn() => @unlink($sTampered));
file_put_contents($sTampered, file_get_contents($sFile) . "\0");
check(null !== $refused($sTampered, $sArmored), 'a modified archive was accepted');

// Signed by the release key, but for another file
check(null !== $refused($sFile, file_get_contents("{$sDir}/other-file.asc")),
	"another file's signature was accepted");

// A valid signature from some other key
$sStranger = file_get_contents("{$sFile}.stranger.asc");
check(null !== $refused($sFile, $sStranger), "a stranger's signature was accepted");

// The same, claiming to be from the release key: the issuer only picks which
// trusted key to try, so the signature still has to verify against it
$sBin = base64_decode(implode('', array_filter(
	preg_split('/\R/', trim(preg_replace('/-----(BEGIN|END) PGP SIGNATURE-----/', '', $sStranger))),
	static fn($l) => '' !== trim($l) && '=' !== $l[0]
)));
$iPos = strpos($sBin, "\x21\x04");
check(false !== $iPos, 'test setup: no issuer fingerprint in the stranger signature');
$sForged = substr_replace($sBin, hex2bin('31F68C0727FE2E3ABFD6607EF43DD733A6668F02'), $iPos + 2, 20);
check(null !== $refused($sFile, base64_encode($sForged)), 'a forged issuer was accepted');

// Nothing usable
foreach (['', 'not base64 at all!', base64_encode('random bytes'), base64_encode("\x88\x02\x04")] as $sJunk) {
	check(null !== $refused($sFile, $sJunk), 'junk accepted as a signature: ' . bin2hex($sJunk));
}

echo "release-signature: ok\n";
