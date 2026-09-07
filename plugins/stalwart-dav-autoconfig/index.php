<?php

require_once __DIR__ . '/Rules.php';

use Plugins\StalwartDavAutoconfig\Rules;
use Tachyon\Providers\Storage\Enumerations\StorageType;

class StalwartDavAutoconfigPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME = 'Stalwart DAV autoconfiguration',
		AUTHOR = 'Clinically',
		VERSION = '1.0',
		RELEASE = '2026-09-08',
		CATEGORY = 'Contacts',
		LICENSE = 'MIT',
		DESCRIPTION = 'Points contacts and calendar sync at Stalwart CardDAV and CalDAV at login, for accounts on an allow list.';

	public function Init() : void
	{
		$this->addHook('login.success', 'LoginSuccess');
	}

	/**
	 * Writes both sync configs on every login for an allow-listed account.
	 *
	 * This is the only point at which they can be written at all: the stored
	 * password is sealed with the account's CryptKey, which is unsealed by the
	 * login password and so exists only inside an authenticated session.
	 *
	 * Unconditional by design. A stored password is discarded on read if its
	 * HMAC no longer matches, so rewriting each login is what keeps sync alive
	 * across a password change. The configs hold no sync state -- etags live in
	 * the contacts database -- so a rewrite costs nothing.
	 *
	 * The consequence, which is intended: a user who turns sync off in Settings
	 * has it turned back on at their next login. Removing them from the allow
	 * list is the way to opt an account out.
	 */
	public function LoginSuccess(\Tachyon\Model\MainAccount $oAccount) : void
	{
		try {
			$aAllowList = Rules::parseAllowList((string) $this->Config()->Get('plugin', 'allow_list', ''));

			if (!Rules::isAllowed($oAccount->Email(), $aAllowList)) {
				return;
			}

			$sPassword = $oAccount->IncPassword();
			if ('' === $sPassword) {
				return;
			}

			$this->writeSyncConfig($oAccount, 'contacts_sync',
				(string) $this->Config()->Get('plugin', 'carddav_url', ''), $sPassword);

			$this->writeSyncConfig($oAccount, 'calendar_sync',
				(string) $this->Config()->Get('plugin', 'caldav_url', ''), $sPassword);
		} catch (\Throwable $oException) {
			// Nothing about DAV may keep someone out of their mail.
			$this->Manager()->WriteException(
				'stalwart-dav-autoconfig: ' . $oException->getMessage(), \LOG_ERR);
		}
	}

	private function writeSyncConfig(\Tachyon\Model\MainAccount $oAccount, string $sConfigKey, string $sUrl,
		#[\SensitiveParameter] string $sPassword) : void
	{
		// An empty URL is how an operator disables one half without touching
		// the allow list.
		if ('' === $sUrl) {
			return;
		}

		$aData = Rules::payload($oAccount->Email(), $sPassword, $sUrl);

		$sCryptKey = $oAccount->CryptKey();
		$aData['Password'] = \Tachyon\Util\Crypt::EncryptToJSON($aData['Password'], $sCryptKey);
		$aData['PasswordHMAC'] = \hash_hmac('sha1', $aData['Password'], $sCryptKey);

		$this->Manager()->Actions()->StorageProvider()->Put(
			$oAccount,
			StorageType::CONFIG,
			$sConfigKey,
			// Throws rather than returning false, so a failure arrives in the
			// catch as itself instead of as a TypeError from Put()'s string
			// parameter.
			\json_encode($aData, JSON_THROW_ON_ERROR)
		);
	}

	protected function configMapping() : array
	{
		return array(
			\Tachyon\Plugins\Property::NewInstance('allow_list')
				->SetLabel('Allow list')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING_TEXT)
				->SetDescription('Addresses or domains to configure, separated by commas or whitespace. Empty configures nobody.')
				->SetDefaultValue(''),

			\Tachyon\Plugins\Property::NewInstance('carddav_url')
				->SetLabel('CardDAV base URL')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING)
				->SetDescription('Discovery starts here; the per-user collection is found from it. Empty leaves contacts_sync alone.')
				->SetDefaultValue('https://mail.clinically.com.au/dav/card'),

			\Tachyon\Plugins\Property::NewInstance('caldav_url')
				->SetLabel('CalDAV base URL')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING)
				->SetDescription('Discovery starts here; the per-user collection is found from it. Empty leaves calendar_sync alone.')
				->SetDefaultValue('https://mail.clinically.com.au/dav/cal')
		);
	}
}
