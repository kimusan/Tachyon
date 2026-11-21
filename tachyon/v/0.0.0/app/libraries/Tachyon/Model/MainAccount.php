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
	 * Argon2id cost for sealing .cryptkey: libsodium's "interactive" level
	 * (64 MiB, two passes; about 75 ms here). The seal is opened once per
	 * login, later requests take the key from the login cookie (tokenArray()).
	 * The values are stored with each seal; cheaper seals are renewed.
	 */
	const
		CRYPTKEY_OPSLIMIT = 2,
		CRYPTKEY_MEMLIMIT = 64 * 1024 * 1024;

	private ?SensitiveString $sCryptKey = null;

	/**
	 * The login cookie's content (UserAuth::SetAuthToken()): the account and
	 * its unsealed CryptKey, so that later requests of the session need not
	 * open the Argon2id seal. The cookie already carries the IMAP password,
	 * which opens the seal, so this adds little: unlike the password, the key
	 * still works after a password change, for whoever kept an old cookie and
	 * later gets the data folder (which they need to read the cookie at all).
	 * Not jsonSerialize(): that is also the remember-me record stored on the
	 * server, and restoring from it is a login, which opens the seal anyway.
	 */
	public function tokenArray() : array
	{
		$aToken = $this->jsonSerialize();
		try {
			$aToken['cryptkey'] = \bin2hex((string) $this->CryptKey());
		} catch (ClientException $e) {
			// Locked, e.g. still sealed with a previous password: asked for
			// (ResealCryptKey) when something needs it
		}
		return $aToken;
	}

	public static function NewInstanceFromTokenArray(
		\Tachyon\Actions $oActions,
		array $aAccountHash,
		bool $bThrowExceptionOnFalse = false): ?self
	{
		$oAccount = parent::NewInstanceFromTokenArray($oActions, $aAccountHash, $bThrowExceptionOnFalse);
		$sKey = $aAccountHash['cryptkey'] ?? null;
		if ($oAccount && \is_string($sKey) && 40 <= \strlen($sKey) && !(\strlen($sKey) % 2) && \ctype_xdigit($sKey)) {
			$oAccount->sCryptKey = new SensitiveString(\hex2bin($sKey));
		}
		return $oAccount;
	}

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
	 * Seal the hex encoded CryptKey with a secret. $aOld are keys it replaces
	 * whose data is not re-encrypted yet (see CryptKeyRotation).
	 *
	 * The key derived from the secret used to be sha1(secret . APP_SALT): with
	 * a copy of the data folder (which holds SALT.php) every guess at a user's
	 * mail password cost one SHA-1. Now it is Argon2id with a random salt per
	 * seal. Without sodium (it ships with PHP, but can be left out) the old
	 * seal is kept.
	 */
	public static function sealCryptKey(string $sKey, string $sSecret, array $aOld = []) : string
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
			'data' => \Tachyon\Util\Crypt::EncryptToJSON($aOld ? ['key' => $sKey, 'old' => \array_values($aOld)] : $sKey,
				static::sealKey($sSecret, $sSalt, $iOps, $iMem))
		]);
	}

	/**
	 * The hex encoded CryptKey, or null when $sSecret does not open the seal.
	 * $bCurrent tells whether the seal is in the current format with the
	 * current cost, i.e. does not need to be sealed again. $aOld are the keys
	 * it replaces whose data still needs re-encrypting; for a seal in the old
	 * format, whose key was derived from the password, $bLegacy is set.
	 */
	public static function unsealCryptKey(string $sSealed, string $sSecret, ?bool &$bCurrent = null,
		?array &$aOld = null, ?bool &$bLegacy = null) : ?string
	{
		$bCurrent = $bLegacy = false;
		$aOld = [];
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
			if (\is_array($sKey)) {
				$aOld = \array_values(\array_filter((array) ($sKey['old'] ?? []),
					fn($s) => \is_string($s) && \ctype_xdigit($s)));
				$sKey = $sKey['key'] ?? null;
			}
			$bCurrent = $iOps >= static::CRYPTKEY_OPSLIMIT && $iMem >= static::CRYPTKEY_MEMLIMIT;
		} else {
			// Sealed with sha1(secret . APP_SALT)
			$sKey = \Tachyon\Util\Crypt::DecryptFromJSON($sSealed, $sSecret);
			$bCurrent = !\is_callable('sodium_crypto_pwhash');
			$bLegacy = !$bCurrent;
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
			$sKey = static::unsealCryptKey($sSealed, (string) $oOldPass, $bCurrent, $aOld, $bLegacy);
			$sSecret = $this->cryptKeySecrets()[0] ?? '';
			if (null === $sKey || !\strlen($sSecret)) {
				throw new ClientException(Notifications::CryptKeyError);
			}
			if ($bLegacy) {
				// Derived from the password: replace it, CryptKey() re-encrypts
				$aOld[] = $sKey;
				$sKey = \bin2hex(\random_bytes(32));
			}
			$this->sCryptKey = null;
			if ($oStorage->Put($this, StorageType::ROOT, '.cryptkey', static::sealCryptKey($sKey, $sSecret, $aOld))) {
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
				$sKey = static::unsealCryptKey($sSealed, $sSecret, $bCurrent, $aOld, $bLegacy);
				if (null !== $sKey) {
					if ($bLegacy) {
						// Derived from the password: replace it with a random key
						// and re-encrypt what the old one protects (below). The
						// old key stays in the seal until that is done.
						$aOld[] = $sKey;
						$sKey = \bin2hex(\random_bytes(32));
					}
					if ($i || !$bCurrent || $bLegacy) {
						// Old format or cost, or an older secret: seal it anew
						if (!$oStorage->Put($this, StorageType::ROOT, '.cryptkey',
							static::sealCryptKey($sKey, $aSecrets[0], $aOld))
						) {
							throw new ClientException(Notifications::CryptKeyError);
						}
					}
					break;
				}
			}
			if (null === $sKey) {
				throw new ClientException(Notifications::CryptKeyError);
			}
			$this->sCryptKey = new SensitiveString(\hex2bin($sKey));
			if ($aOld) {
				$this->rotateCryptKey($aOld, $sKey, $aSecrets[0]);
			}
		}
		return $this->sCryptKey;
	}

	/**
	 * Re-encrypt what the old keys protect under the current one, and drop
	 * them from the seal once that worked. On failure they stay, and the next
	 * request tries again; data the step did not reach meanwhile reads as if
	 * encrypted with an unknown key (e.g. a sync password to enter again).
	 */
	private function rotateCryptKey(array $aOld, string $sKey, string $sSecret) : void
	{
		try {
			foreach ($aOld as $sOld) {
				CryptKeyRotation::run($this, \hex2bin($sOld), \hex2bin($sKey));
			}
			\Tachyon\Api::Actions()->StorageProvider()->Put($this, StorageType::ROOT, '.cryptkey',
				static::sealCryptKey($sKey, $sSecret));
		} catch (\Throwable $e) {
			\Tachyon\Util\Log::warning('CryptKey', 'rotation incomplete: ' . $e->getMessage());
		}
	}

/*
	// Stores settings in MainAccount
	public function settings() : \Tachyon\Settings
	{
		return \Tachyon\Api::Actions()->SettingsProvider()->Load($this);
	}
*/
}
