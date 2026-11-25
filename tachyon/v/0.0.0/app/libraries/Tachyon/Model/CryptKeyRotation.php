<?php

namespace Tachyon\Model;

use Tachyon\Providers\Storage\Enumerations\StorageType;
use Tachyon\Util\Crypt;

/**
 * Re-encrypts what a main account's CryptKey protects under a new key.
 *
 * CryptKeys made before Argon2id sealing were sha1(IMAP password . APP_SALT),
 * so everything they encrypt let anyone with the data folder test password
 * guesses at the cost of one SHA-1. MainAccount::CryptKey() replaces such a
 * key with a random one and has its data re-encrypted here:
 * - the additional accounts' passwords (CONFIG additionalaccounts);
 * - the CardDAV and CalDAV passwords (CONFIG contacts_sync, calendar_sync;
 *   additional accounts keep theirs in the same place);
 * - the PGP private key backups (PGP *.key);
 * - and through the 'main.cryptkey-rotate' hook whatever plugins keep
 *   (the OAuth2 tokens of OAuth2Accounts).
 *
 * Every step can run again: data already under the new key is left alone,
 * and so is data under neither key (e.g. left from before a password change
 * that was never resealed; it was unreadable already). A failure throws, and
 * the caller keeps the old key to try again on the next request.
 */
abstract class CryptKeyRotation
{
	public static function run(MainAccount $oMain, string $sOld, string $sNew) : void
	{
		$oActions = \Tachyon\Api::Actions();
		$oStorage = $oActions->StorageProvider();
		static::additionalAccounts($oStorage, $oMain, $sOld, $sNew);
		static::syncData($oStorage, $oMain, 'contacts_sync', $sOld, $sNew);
		static::syncData($oStorage, $oMain, 'calendar_sync', $sOld, $sNew);
		static::pgpBackups($oStorage, $oMain, $sOld, $sNew);
		$oActions->Plugins()->RunHook('main.cryptkey-rotate', [$oMain, $sOld, $sNew]);
	}

	/**
	 * Whether data with this HMAC still needs the new key: true when it is
	 * under the old one, false when under the new one or neither.
	 */
	public static function underOldKey(string $sData, ?string $sMac, string $sOld, string $sNew) : bool
	{
		return \is_string($sMac)
			&& !\hash_equals(\hash_hmac('sha1', $sData, $sNew), $sMac)
			&& \hash_equals(\hash_hmac('sha1', $sData, $sOld), $sMac);
	}

	/**
	 * A Crypt::EncryptToJSON() value under the old key, under the new one;
	 * null when the old key does not open it.
	 */
	public static function reencryptJSON(string $sData, string $sOld, string $sNew) : ?string
	{
		$mValue = Crypt::DecryptFromJSON($sData, $sOld);
		return null === $mValue ? null : Crypt::EncryptToJSON($mValue, $sNew);
	}

	private static function put($oStorage, MainAccount $oMain, int $iType, string $sKey, string $sValue) : void
	{
		if (!$oStorage->Put($oMain, $iType, $sKey, $sValue)) {
			throw new \RuntimeException("CryptKey rotation: could not write {$sKey}");
		}
	}

	private static function additionalAccounts($oStorage, MainAccount $oMain, string $sOld, string $sNew) : void
	{
		$sAccounts = $oStorage->Get($oMain, StorageType::CONFIG, 'additionalaccounts');
		$aAccounts = $sAccounts ? \json_decode($sAccounts, true) : null;
		if (!\is_array($aAccounts)) {
			return;
		}
		$bChanged = false;
		foreach ($aAccounts as $sEmail => $aAccount) {
			// See AdditionalAccount::asTokenArray(): the HMAC covers the password
			$sPass = \is_array($aAccount) ? (string) ($aAccount['pass'] ?? '') : '';
			if (!static::underOldKey($sPass, $aAccount['hmac'] ?? null, $sOld, $sNew)) {
				continue;
			}
			if (\strlen($sPass)) {
				$mPass = Crypt::DecryptUrlSafe($sPass, $sOld);
				if (!\is_string($mPass)) {
					throw new \RuntimeException("CryptKey rotation: password of {$sEmail} unreadable");
				}
				$aAccount['pass'] = Crypt::EncryptUrlSafe($mPass, $sNew);
			}
			if (!empty($aAccount['smtp']['pass'])) {
				$mPass = Crypt::DecryptUrlSafe($aAccount['smtp']['pass'], $sOld);
				// Not covered by the HMAC: leave what the old key does not open
				if (\is_string($mPass)) {
					$aAccount['smtp']['pass'] = Crypt::EncryptUrlSafe($mPass, $sNew);
				}
			}
			$aAccount['hmac'] = \hash_hmac('sha1', $aAccount['pass'], $sNew);
			$aAccounts[$sEmail] = $aAccount;
			$bChanged = true;
		}
		$bChanged && static::put($oStorage, $oMain, StorageType::CONFIG, 'additionalaccounts', \json_encode($aAccounts));
	}

	private static function syncData($oStorage, MainAccount $oMain, string $sKey, string $sOld, string $sNew) : void
	{
		$sData = $oStorage->Get($oMain, StorageType::CONFIG, $sKey);
		$aData = $sData ? \json_decode($sData, true) : null;
		if (!\is_array($aData) || empty($aData['Password'])
		 || !static::underOldKey($aData['Password'], $aData['PasswordHMAC'] ?? null, $sOld, $sNew)
		) {
			return;
		}
		$sPassword = static::reencryptJSON($aData['Password'], $sOld, $sNew);
		if (null === $sPassword) {
			throw new \RuntimeException("CryptKey rotation: {$sKey} password unreadable");
		}
		$aData['Password'] = $sPassword;
		$aData['PasswordHMAC'] = \hash_hmac('sha1', $sPassword, $sNew);
		static::put($oStorage, $oMain, StorageType::CONFIG, $sKey, \json_encode($aData));
	}

	private static function pgpBackups($oStorage, MainAccount $oMain, string $sOld, string $sNew) : void
	{
		$sDir = $oStorage->GenerateFilePath($oMain, StorageType::PGP);
		if (!$sDir || !\is_dir($sDir)) {
			return;
		}
		// See Util\PGP\Backup::PGPKey(): [algorithm, base64 nonce, base64 data, HMAC of that]
		foreach (\glob("{$sDir}*.key") ?: [] as $sFile) {
			$aKey = \json_decode((string) \file_get_contents($sFile), true);
			if (!\is_array($aKey) || 4 !== \count($aKey)
			 || !static::underOldKey((string) $aKey[2], $aKey[3], $sOld, $sNew)
			) {
				continue;
			}
			$mKey = Crypt::Decrypt([$aKey[0], \base64_decode($aKey[1]), \base64_decode($aKey[2])], $sOld);
			if (!\is_string($mKey)) {
				throw new \RuntimeException('CryptKey rotation: PGP backup '.\basename($sFile).' unreadable');
			}
			$aKey = Crypt::Encrypt($mKey, $sNew);
			$aKey[1] = \base64_encode($aKey[1]);
			$aKey[2] = \base64_encode($aKey[2]);
			$aKey[3] = \hash_hmac('sha1', $aKey[2], $sNew);
			\Tachyon\Utils::saveFile($sFile, \json_encode($aKey), true);
		}
	}
}
