<?php

namespace Tachyon\Util;

/**
 * Checks a detached OpenPGP signature made by the Tachyon release key, before
 * a downloaded plugin or core archive is unpacked into the code tree.
 *
 * Only what the release key produces is accepted: a version 4 binary
 * signature (class 0x00), EdDSA over Ed25519, with SHA-256 or SHA-512. That
 * is little enough to verify here with sodium, so this works without gnupg.
 */
abstract class ReleaseSignature
{
	/**
	 * Fingerprint => raw Ed25519 public key. The primary key and its signing
	 * subkey, from tachyon-archive-keyring.asc. A new signing key goes here.
	 */
	const KEYS = [
		'2AF665D52CE22BDEFC606AD1DAD08242CFE59866' => '100cef8b08590e866cad8f93111c2a1665e2f6ac06dbf6f5fc47dc2df26d9e31',
		'31F68C0727FE2E3ABFD6607EF43DD733A6668F02' => 'dd631028546c7b78d9caf975772b18f9f0bb8da8114410fefaa6ca82b61775fc',
	];

	/**
	 * @param string $sSignature armored, or the base64 body as packages.json carries it
	 * @throws \Exception when the file is not signed by the release key
	 */
	public static function verifyFile(string $sFile, string $sSignature) : void
	{
		if (!\function_exists('sodium_crypto_sign_verify_detached')) {
			throw new \Exception('PHP sodium extension is needed to verify packages');
		}
		$aSig = static::parse(static::dearmor($sSignature));

		$sKey = null;
		foreach (static::KEYS as $sFpr => $sHex) {
			if ($aSig['fpr'] ? $aSig['fpr'] === $sFpr : $aSig['keyid'] === \substr($sFpr, -16)) {
				$sKey = \hex2bin($sHex);
				break;
			}
		}
		if (!$sKey) {
			throw new \Exception('Package is not signed by the Tachyon release key');
		}

		$rHash = \hash_init($aSig['hash']);
		if (!\hash_update_file($rHash, $sFile)) {
			throw new \Exception('Cannot read package for verification');
		}
		\hash_update($rHash, $aSig['hashed'] . "\x04\xFF" . \pack('N', \strlen($aSig['hashed'])));
		$sDigest = \hash_final($rHash, true);

		if (\substr($sDigest, 0, 2) !== $aSig['left16']
		 || !\sodium_crypto_sign_verify_detached($aSig['rs'], $sDigest, $sKey)
		) {
			throw new \Exception('Package signature does not match');
		}
	}

	private static function dearmor(string $sSignature) : string
	{
		$sSignature = \preg_replace('/-----(BEGIN|END) PGP SIGNATURE-----/', '', $sSignature);
		$aBody = [];
		foreach (\preg_split('/\R/', \trim($sSignature)) as $sLine) {
			$sLine = \trim($sLine);
			// Armor headers ("Comment: ...") and the CRC line ("=abcd") are not data
			if ('' === $sLine || '=' === $sLine[0] || \str_contains($sLine, ': ')) {
				continue;
			}
			$aBody[] = $sLine;
		}
		$sBin = \base64_decode(\implode('', $aBody), true);
		if (false === $sBin || '' === $sBin) {
			throw new \Exception('Package signature is not valid base64');
		}
		return $sBin;
	}

	private static function parse(string $sBin) : array
	{
		$iLen = \strlen($sBin);
		$c = \ord($sBin[0]);
		if (!($c & 0x80)) {
			throw new \Exception('Package signature is not an OpenPGP packet');
		}
		$i = 1;
		if ($c & 0x40) {
			$iTag = $c & 0x3F;
			$l = \ord($sBin[$i++] ?? "\0");
			if (192 <= $l && 224 > $l) {
				$l = (($l - 192) << 8) + \ord($sBin[$i++] ?? "\0") + 192;
			} else if (255 === $l) {
				$l = \unpack('N', \substr($sBin, $i, 4))[1];
				$i += 4;
			} else if (224 <= $l) {
				throw new \Exception('Package signature uses partial lengths');
			}
		} else {
			$iTag = ($c >> 2) & 0x0F;
			$n = [1, 2, 4, 0][$c & 3];
			if (!$n) {
				throw new \Exception('Package signature has an indeterminate length');
			}
			$l = 1 === $n ? \ord($sBin[$i] ?? "\0") : \unpack(2 === $n ? 'n' : 'N', \str_pad(\substr($sBin, $i, $n), $n, "\0"))[1];
			$i += $n;
		}
		$p = \substr($sBin, $i, $l);
		if (2 !== $iTag || \strlen($p) !== $l || 10 > $l) {
			throw new \Exception('Package signature is not a signature packet');
		}

		// version 4, binary document, EdDSA (22), SHA-256 (8) or SHA-512 (10)
		$aHash = [8 => 'sha256', 10 => 'sha512'];
		if ("\x04\x00\x16" !== \substr($p, 0, 3) || !isset($aHash[\ord($p[3])])) {
			throw new \Exception('Package signature is of an unsupported kind');
		}
		$iHashed = \unpack('n', \substr($p, 4, 2))[1];
		$sHashed = \substr($p, 0, 6 + $iHashed);
		$o = 6 + $iHashed;
		$iUnhashed = \unpack('n', \str_pad(\substr($p, $o, 2), 2, "\0"))[1];
		$sUnhashed = \substr($p, $o + 2, $iUnhashed);
		$o += 2 + $iUnhashed;
		$sLeft16 = \substr($p, $o, 2);
		$o += 2;

		// r and s, each an MPI of at most 256 bits, left-padded to 32 bytes
		$sRS = '';
		for ($k = 0; 2 > $k; ++$k) {
			$iBits = \unpack('n', \str_pad(\substr($p, $o, 2), 2, "\0"))[1];
			$iBytes = ($iBits + 7) >> 3;
			if (32 < $iBytes || $o + 2 + $iBytes > $l) {
				throw new \Exception('Package signature is malformed');
			}
			$sRS .= \str_pad(\substr($p, $o + 2, $iBytes), 32, "\0", STR_PAD_LEFT);
			$o += 2 + $iBytes;
		}

		// The issuer may be in either area; only the hashed one is signed, but
		// it only picks which trusted key to try, so either is fine
		$sFpr = $sKeyId = '';
		foreach ([\substr($sHashed, 6), $sUnhashed] as $sArea) {
			for ($j = 0, $n = \strlen($sArea); $j < $n;) {
				$sl = \ord($sArea[$j++]);
				if (192 <= $sl && 255 > $sl) {
					$sl = (($sl - 192) << 8) + \ord($sArea[$j++] ?? "\0") + 192;
				} else if (255 === $sl) {
					$sl = \unpack('N', \str_pad(\substr($sArea, $j, 4), 4, "\0"))[1];
					$j += 4;
				}
				$iType = \ord($sArea[$j] ?? "\0") & 0x7F;
				$sData = \substr($sArea, $j + 1, $sl - 1);
				if (33 === $iType && 21 === \strlen($sData) && "\x04" === $sData[0]) {
					$sFpr = $sFpr ?: \strtoupper(\bin2hex(\substr($sData, 1)));
				} else if (16 === $iType && 8 === \strlen($sData)) {
					$sKeyId = $sKeyId ?: \strtoupper(\bin2hex($sData));
				}
				$j += \max(1, $sl);
			}
		}

		return [
			'hash' => $aHash[\ord($p[3])],
			'hashed' => $sHashed,
			'left16' => $sLeft16,
			'rs' => $sRS,
			'fpr' => $sFpr,
			'keyid' => $sKeyId ?: \substr($sFpr, -16)
		];
	}
}
