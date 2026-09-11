<?php

require_once __DIR__ . '/Rules.php';

use Plugins\DavAutoconfig\Rules;
use Tachyon\Providers\Storage\Enumerations\StorageType;

class DavAutoconfigPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME = 'DAV autoconfiguration',
		AUTHOR = 'Clinically',
		URL = 'https://github.com/kimusan/Tachyon',
		VERSION = '1.1',
		RELEASE = '2026-09-11',
		REQUIRED = '4.2.2',
		CATEGORY = 'Contacts',
		LICENSE = 'MIT',
		DESCRIPTION = 'Points contacts and calendar sync at a CardDAV and CalDAV server at login, for accounts on an allow list.';

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
	 * The credentials are rewritten every login, because a stored password is
	 * discarded on read once its HMAC stops matching, and rewriting is what
	 * keeps sync alive across a password change.
	 *
	 * Mode is the exception. Turning sync off in Settings writes Mode 0 to this
	 * same file, so overwriting it unconditionally took that choice away again
	 * at the next login, with no way for the user to make it stick. An existing
	 * Mode is now kept and only a config that is not there yet gets Mode 1.
	 * Remove the account from the allow list to stop configuring it at all.
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

			// Contacts are pinned to one named collection; calendars are not.
			// See the URL settings below for why they differ.
			$this->writeSyncConfig($oAccount, 'contacts_sync',
				(string) $this->Config()->Get('plugin', 'carddav_url', ''), $sPassword);

			$this->writeSyncConfig($oAccount, 'calendar_sync',
				(string) $this->Config()->Get('plugin', 'caldav_url', ''), $sPassword);
		} catch (\Throwable $oException) {
			// Nothing about DAV may keep someone out of their mail.
			\Tachyon\Util\Log::error('dav-autoconfig', $oAccount->Email() . ': ' . $oException->getMessage());
			$this->Manager()->WriteException($oException, \LOG_ERR);
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

		$aData = Rules::payload($oAccount->Email(), $sPassword,
			Rules::collectionUrl($sUrl, $oAccount->Email()));

		// Whatever the user last chose wins over the seed default
		$mMode = $this->storedMode($oAccount, $sConfigKey);
		if (null !== $mMode) {
			$aData['Mode'] = $mMode;
		}

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

	/**
	 * The Mode already stored for this account, or null when nothing is stored.
	 *
	 * Only Mode is read back. The password cannot be checked from here without
	 * the session's CryptKey, and there is no need to: it is being rewritten.
	 */
	private function storedMode(\Tachyon\Model\MainAccount $oAccount, string $sConfigKey) : ?int
	{
		$sData = $this->Manager()->Actions()->StorageProvider()->Get(
			$oAccount,
			StorageType::CONFIG,
			$sConfigKey
		);

		if (empty($sData)) {
			return null;
		}

		$aData = \json_decode($sData, true);

		return (\is_array($aData) && isset($aData['Mode'])) ? (int) $aData['Mode'] : null;
	}

	protected function configMapping() : array
	{
		return array(
			\Tachyon\Plugins\Property::NewInstance('allow_list')
				->SetLabel('Allow list')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING_TEXT)
				->SetDescription('Addresses or domains to configure, separated by commas or whitespace. Empty configures nobody.')
				->SetDefaultValue(''),

			// Pinned to one collection on purpose. Given only a base URL, Tachyon
			// discovers the address book, and when none is named
			// contacts/default/addressbook/address book it takes whichever the
			// server listed first. An account with two address books can then
			// land on a different one each sync, and a sync landing on an empty
			// one deletes every local contact as deleted-elsewhere.
			\Tachyon\Plugins\Property::NewInstance('carddav_url')
				->SetLabel('CardDAV collection URL')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING)
				->SetDescription('Full collection URL, for example https://mail.example.com/dav/card/{email}/default/. {email} is replaced with the percent-encoded address. Naming the collection skips discovery, which is not deterministic when an account has more than one address book. Empty leaves contacts_sync alone.')
				->SetDefaultValue(''),

			// Deliberately NOT pinned. Calendar sync enumerates and syncs every
			// collection it finds rather than choosing one, so there is no
			// selection hazard -- and pinning would hide events that live in an
			// account's other calendar.
			\Tachyon\Plugins\Property::NewInstance('caldav_url')
				->SetLabel('CalDAV base URL')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING)
				->SetDescription('Base URL, for example https://mail.example.com/dav/cal. Calendar sync discovers and syncs every collection on the account, so this is not pinned. Empty leaves calendar_sync alone.')
				->SetDefaultValue('')
		);
	}
}
