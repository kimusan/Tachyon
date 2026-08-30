<?php

namespace Tachyon\Providers;

class Suggestions extends \Tachyon\Providers\AbstractProvider
{
	/**
	 * @var \Tachyon\Providers\Suggestions\ISuggestions[]
	 */
	private array $aDrivers = [];

	/**
	 * @param \Tachyon\Providers\Suggestions\ISuggestions[]|null $aDriver = null
	 */
	public function __construct(?array $aDriver = null)
	{
		if (\is_array($aDriver)) {
			$this->aDrivers = \array_filter($aDriver, function ($oDriver) {
				return $oDriver instanceof \Tachyon\Providers\Suggestions\ISuggestions;
			});
		}
	}

	public function Process(\Tachyon\Model\Account $oAccount, string $sQuery, int $iLimit = 20) : array
	{
		if (!\strlen($sQuery)) {
			return [];
		}

		$iLimit = \max(5, (int) $iLimit);
		$aResult = [];

		// Address Book — normal suggestions + group expansion
		try
		{
			$oAddressBookProvider = \Tachyon\Api::Actions()->AddressBookProvider($oAccount);
			if ($oAddressBookProvider && $oAddressBookProvider->IsActive()) {
				$aSuggestions = $oAddressBookProvider->GetSuggestions($sQuery, $iLimit);
				foreach ($aSuggestions as $aItem) {
					$sLine = \mb_strtolower($aItem[0]);
					if (!isset($aResult[$sLine])) {
						$aResult[$sLine] = $aItem;
					}
				}

				// Group chips: find all categories whose name contains the query.
				// Member emails are NOT expanded here — expansion happens client-side
				// just before the message is sent.
				foreach ($oAddressBookProvider->GetMatchingCategories($sQuery, 5) as [$sCatName, $iCount]) {
					$aResult['{group}' . $sCatName] = ['{group}' . $sCatName, (string) $iCount];
				}
			}
		}
		catch (\Throwable $oException)
		{
			$this->logException($oException);
		}

		// Extensions/Plugins — normal suggestions + group expansion
		foreach ($this->aDrivers as $oDriver) {
			if ($oDriver) try {
				// Groups a driver knows about. Only the local address book was
				// ever asked, so a Nextcloud group could be expanded once named
				// but was never offered, which put it out of reach of anyone
				// who keeps their contacts there rather than here.
				if (\method_exists($oDriver, 'GetMatchingCategories')) {
					foreach ($oDriver->GetMatchingCategories($sQuery, 5) as $aCategory) {
						$sCatName = (string) ($aCategory[0] ?? '');
						if (\strlen($sCatName) && !isset($aResult['{group}' . $sCatName])) {
							$aResult['{group}' . $sCatName] = [
								'{group}' . $sCatName,
								(string) ($aCategory[1] ?? 0)
							];
						}
					}
				}

				$aSuggestions = $oDriver->Process($oAccount, $sQuery, $iLimit);
				if ($aSuggestions) {
					foreach ($aSuggestions as $aItem) {
						$sLine = \mb_strtolower($aItem[0]);
						if (!isset($aResult[$sLine])) {
							$aResult[$sLine] = $aItem;
						}
					}
					if ($iLimit < \count($aResult)) {
						break;
					}
				}

				// Plugin group expansion deferred to client-side send; skip here.
			} catch (\Throwable $oException) {
				$this->logException($oException);
			}
		}

		return \array_slice(\array_values($aResult), 0, $iLimit);
	}

	/**
	 * Members of a named group, from every driver that knows the name.
	 *
	 * A group can exist in more than one place, so the drivers are merged and
	 * de-duplicated by address rather than stopping at the first to answer.
	 *
	 * @return array  Array of [string $email, string $name]
	 */
	public function GetGroup(string $sCategoryName, int $iLimit = 20) : array
	{
		$aResult = [];
		foreach ($this->aDrivers as $oDriver) {
			if ($oDriver instanceof \Tachyon\Providers\Suggestions\IGroupSuggestions) {
				try {
					foreach ($oDriver->GetGroup($sCategoryName, $iLimit) as $aItem) {
						$sEmail = \mb_strtolower(\trim((string) ($aItem[0] ?? '')));
						if (\strlen($sEmail) && !isset($aResult[$sEmail])) {
							$aResult[$sEmail] = $aItem;
						}
					}
				} catch (\Throwable $oException) {
					$this->logException($oException);
				}
				if ($iLimit <= \count($aResult)) {
					break;
				}
			}
		}

		return \array_slice(\array_values($aResult), 0, $iLimit);
	}

	public function IsActive() : bool
	{
		return \count($this->aDrivers);
	}
}
