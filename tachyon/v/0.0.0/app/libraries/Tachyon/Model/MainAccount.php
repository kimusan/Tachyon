<?php

namespace Tachyon\Model;

use Tachyon\Utils;
use Tachyon\Exceptions\ClientException;
use Tachyon\Notifications;
use Tachyon\Providers\Storage\Enumerations\StorageType;
use Tachyon\Util\SensitiveString;

class MainAccount extends Account
{
	/**
	 * Argon2id cost for sealing .cryptkey: the OWASP minimum (19 MiB, two
	 * passes). The cost is paid by every request that opens the seal, and the
	 * values are stored with each seal, so they can be raised later.
	 */
	const
		CRYPTKEY_OPSLIMIT = 2,
		CRYPTKEY_MEMLIMIT = 19 * 1024 * 1024;

	private ?SensitiveString $sCryptKey = null;

	/**
	 * The secrets .cryptkey may be sealed with, preferred first: the IMAP
	 * password, or with security.insecure_cryptkey the email address. A key
	 * found under a later entry is re-sealed with the first.
	 */
	protected function cryptKeySecrets() : array
	{
		$aSecrets = [
			\Tachyon\Api::Config()->Get('security', 'insecure_cryptkey', false)
				? $this->Email()
				: $this->ImapPass()
		];
		return \array_values(\array_filter(\array_map('strval', $aSecrets), 'strlen'));
	}

	/**
	 * Seal the hex encoded CryptKey with a secret.
	 *
	 * The key derived from the secret used to be sha1(secret . APP_SALT): with
	 * a copy of the data folder (which holds SALT.php) every guess at a user's
	 * mail password cost one SHA-1. Now it is Argon2id with a random salt per
	 * seal. Without sodium (it ships with PHP, but can be left out) the old
	 * seal is kept.
	 */
	public static function sealCryptKey(string $sKey, string $sSecret) : string
	{
		if (!\is_callable('sodium_crypto_pwhash')) {
			return \Tachyon\Util\Crypt::EncryptToJSON($sKey, $sSecret);
		}
		$sSalt = \random_bytes(\SODIUM_CRYPTO_PWHASH_SALTBYTES);
		$iOps = static::CRYPTKEY_OPSLIMIT;
		$iMem = static::CRYPTKEY_MEMLIMIT;
		return \json_encode([
			'v' => 2,
			'kdf' => 'argon2id13',
			'ops' => $iOps,
			'mem' => $iMem,
			'salt' => \base64_encode($sSalt),
			'data' => \Tachyon\Util\Crypt::EncryptToJSON($sKey, static::sealKey($sSecret, $sSalt, $iOps, $iMem))
		]);
	}

	/**
	 * The hex encoded CryptKey, or null when $sSecret does not open the seal.
	 * $bCurrent tells whether the seal is in the current format with the
	 * current cost, i.e. does not need to be sealed again.
	 */
	public static function unsealCryptKey(string $sSealed, string $sSecret, ?bool &$bCurrent = null) : ?string
	{
		$bCurrent = false;
		$aSealed = \json_decode($sSealed, true);
		if (isset($aSealed['v'])) {
			if (2 !== $aSealed['v'] || 'argon2id13' !== ($aSealed['kdf'] ?? '')
			 || !\is_callable('sodium_crypto_pwhash')
			) {
				return null;
			}
			$iOps = (int) ($aSealed['ops'] ?? 0);
			$iMem = (int) ($aSealed['mem'] ?? 0);
			$sSalt = \base64_decode($aSealed['salt'] ?? '', true);
			// Bounds, so a damaged file cannot ask for absurd work
			if ($iOps < 1 || $iOps > 16 || $iMem < 8192 || $iMem > 1024 * 1024 * 1024
			 || !$sSalt || \SODIUM_CRYPTO_PWHASH_SALTBYTES !== \strlen($sSalt)
			) {
				return null;
			}
			$sKey = \Tachyon\Util\Crypt::DecryptFromJSON((string) ($aSealed['data'] ?? ''),
				static::sealKey($sSecret, $sSalt, $iOps, $iMem));
			$bCurrent = $iOps >= static::CRYPTKEY_OPSLIMIT && $iMem >= static::CRYPTKEY_MEMLIMIT;
		} else {
			// Sealed with sha1(secret . APP_SALT)
			$sKey = \Tachyon\Util\Crypt::DecryptFromJSON($sSealed, $sSecret);
			$bCurrent = !\is_callable('sodium_crypto_pwhash');
		}
		return \is_string($sKey) && \strlen($sKey) && \ctype_xdigit($sKey) ? $sKey : null;
	}

	private static function sealKey(string $sSecret, string $sSalt, int $iOps, int $iMem) : string
	{
		return \sodium_crypto_pwhash(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $sSecret, $sSalt,
			$iOps, $iMem, \SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
	}

	public function resealCryptKey(SensitiveString $oOldPass) : bool
	{
		$oStorage = \Tachyon\Api::Actions()->StorageProvider();
		$sSealed = $oStorage->Get($this, StorageType::ROOT, '.cryptkey');
		if ($sSealed) {
			$sKey = static::unsealCryptKey($sSealed, (string) $oOldPass);
			$sSecret = $this->cryptKeySecrets()[0] ?? '';
			if (null === $sKey || !\strlen($sSecret)) {
				throw new ClientException(Notifications::CryptKeyError);
			}
			$this->sCryptKey = null;
			if ($oStorage->Put($this, StorageType::ROOT, '.cryptkey', static::sealCryptKey($sKey, $sSecret))) {
				return true;
			}
		}
		return false;
	}

	public function CryptKey() : string
	{
		if (!$this->sCryptKey) {
			// Seal the cryptkey so that people who change their login password
			// can use the old password to re-seal the cryptkey
			$oStorage = \Tachyon\Api::Actions()->StorageProvider();
			$sSealed = $oStorage->Get($this, StorageType::ROOT, '.cryptkey');
			$aSecrets = $this->cryptKeySecrets();
			if (!$aSecrets) {
				throw new ClientException(Notifications::CryptKeyError);
			}
			if (!$sSealed) {
				// A random key: one derived from the password, as before, would
				// let everything it encrypts test password guesses cheaply.
				$sNew = static::sealCryptKey(\bin2hex(\random_bytes(32)), $aSecrets[0]);
				$oStorage->Put($this, StorageType::ROOT, '.cryptkey', $sNew);
				// Two first requests at once each make a key; only the one that
				// is stored counts, so read it back.
				$sSealed = $oStorage->Get($this, StorageType::ROOT, '.cryptkey') ?: $sNew;
			}
			$sKey = null;
			foreach ($aSecrets as $i => $sSecret) {
				$sKey = static::unsealCryptKey($sSealed, $sSecret, $bCurrent);
				if (null !== $sKey) {
					if ($i || !$bCurrent) {
						// Old format or cost, or an older secret: seal it anew
						$oStorage->Put($this, StorageType::ROOT, '.cryptkey',
							static::sealCryptKey($sKey, $aSecrets[0]));
					}
					break;
				}
			}
			if (null === $sKey) {
				throw new ClientException(Notifications::CryptKeyError);
			}
			$this->sCryptKey = new SensitiveString(\hex2bin($sKey));
		}
		return $this->sCryptKey;
	}

/*
	// Stores settings in MainAccount
	public function settings() : \Tachyon\Settings
	{
		return \Tachyon\Api::Actions()->SettingsProvider()->Load($this);
	}
*/
}
