<?php

namespace Plugins\DavAutoconfig;

/**
 * The plugin's decisions, kept free of framework dependencies so they can be
 * exercised by test/dav-autoconfig-rules.php with no bootstrap.
 */
abstract class Rules
{
	/**
	 * Splits an admin setting into allow list entries. Commas and whitespace
	 * both separate, so a list can be pasted in either shape.
	 */
	public static function parseAllowList(string $sRaw) : array
	{
		$aParts = \preg_split('/[\s,]+/', $sRaw, -1, PREG_SPLIT_NO_EMPTY);

		return \is_array($aParts) ? \array_values($aParts) : array();
	}

	/**
	 * An entry matches either a whole address or a bare domain.
	 */
	public static function isAllowed(string $sEmail, array $aAllowList) : bool
	{
		$sEmail = \strtolower(\trim($sEmail));
		if ('' === $sEmail) {
			return false;
		}

		$sDomain = \substr((string) \strrchr($sEmail, '@'), 1);

		foreach ($aAllowList as $sEntry) {
			$sEntry = \strtolower(\trim((string) $sEntry));
			if ('' === $sEntry) {
				continue;
			}
			if ($sEntry === $sEmail) {
				return true;
			}
			if ('' !== $sDomain && $sEntry === $sDomain) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Expands a collection URL template for one account.
	 *
	 * `{email}` is replaced with the account's address, percent-encoded, so
	 * "https://host/dav/card/{email}/default/" becomes
	 * "https://host/dav/card/user%40example.com/default/".
	 *
	 * The point is to name the collection outright rather than let Tachyon
	 * discover it. Given only a base URL, getDavClient() runs discovery and,
	 * when no collection is named contacts/default/addressbook/address book,
	 * falls through to taking whichever the server listed first. An account
	 * with two address books can then land on a different one each sync, and
	 * a sync that lands on an empty one sees every local contact as
	 * deleted-elsewhere and removes it.
	 *
	 * A template with no placeholder is returned unchanged, so a literal URL
	 * still works.
	 */
	public static function collectionUrl(string $sTemplate, string $sEmail) : string
	{
		if (!\str_contains($sTemplate, '{email}')) {
			return $sTemplate;
		}

		return \str_replace('{email}', \rawurlencode($sEmail), $sTemplate);
	}

	/**
	 * The sync config as stored, before the password is encrypted.
	 *
	 * Mode 1 is read+write. That is deliberate: on a first sync every local
	 * contact has an empty etag, so PdoAddressBook::Sync() treats them all as
	 * new and uploads them, and deletes nothing.
	 */
	public static function payload(string $sEmail, #[\SensitiveParameter] string $sPassword, string $sUrl) : array
	{
		return array(
			'Mode' => 1,
			'User' => $sEmail,
			'Password' => $sPassword,
			'Url' => $sUrl,
		);
	}
}
