<?php

namespace Plugins\StalwartDavAutoconfig;

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
	 * The sync config as stored, before the password is encrypted.
	 *
	 * Mode 1 is read+write. That is deliberate and is what performs the
	 * migration: on a first sync every local contact has an empty etag, so
	 * PdoAddressBook::Sync() treats them all as new and uploads them, and
	 * deletes nothing.
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
