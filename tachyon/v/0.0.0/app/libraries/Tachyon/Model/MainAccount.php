<?php

namespace Tachyon\Model;

use Tachyon\Utils;
use Tachyon\Exceptions\ClientException;
use Tachyon\Notifications;
use Tachyon\Providers\Storage\Enumerations\StorageType;
use Tachyon\Util\SensitiveString;

class MainAccount extends Account
{
	private ?SensitiveString $sCryptKey = null;

	public function resealCryptKey(SensitiveString $oOldPass) : bool
	{
		$oStorage = \Tachyon\Api::Actions()->StorageProvider();
		$sKey = $oStorage->Get($this, StorageType::ROOT, '.cryptkey');
		if ($sKey) {
			$sKey = \Tachyon\Util\Crypt::DecryptFromJSON($sKey, $oOldPass);
			if (!$sKey) {
				throw new ClientException(Notifications::CryptKeyError);
			}
			$sPass = \Tachyon\Api::Config()->Get('security', 'insecure_cryptkey', false)
				? $this->Email()
				: $this->ImapPass();
			$sKey = \Tachyon\Util\Crypt::EncryptToJSON($sKey, $sPass);
			if ($sKey) {
				$this->sCryptKey = null;
				if (\Tachyon\Api::Actions()->StorageProvider()->Put($this, StorageType::ROOT, '.cryptkey', $sKey)) {
					return true;
				}
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
			$sKey = $oStorage->Get($this, StorageType::ROOT, '.cryptkey');
			$sPass = \Tachyon\Api::Config()->Get('security', 'insecure_cryptkey', false)
				? $this->Email()
				: $this->ImapPass();
			if (!$sKey) {
				$sKey = \Tachyon\Util\Crypt::EncryptToJSON(
					\sha1($this->ImapPass() . APP_SALT),
					$sPass
				);
				$oStorage->Put($this, StorageType::ROOT, '.cryptkey', $sKey);
			}
			$sKey = \Tachyon\Util\Crypt::DecryptFromJSON($sKey, $sPass);
			if (!$sKey) {
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
