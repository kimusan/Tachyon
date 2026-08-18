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

				// Group expansion: try exact category name match and expand members.
				foreach ($oAddressBookProvider->GetGroup($sQuery, $iLimit) as $aItem) {
					$sLine = \mb_strtolower($aItem[0]);
					if (!isset($aResult[$sLine])) {
						$aResult[$sLine] = $aItem;
					}
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

				if ($oDriver instanceof \Tachyon\Providers\Suggestions\IGroupSuggestions) {
					$aGroupMembers = $oDriver->GetGroup($sQuery, $iLimit);
					foreach ($aGroupMembers as $aItem) {
						$sLine = \mb_strtolower($aItem[0]);
						if (!isset($aResult[$sLine])) {
							$aResult[$sLine] = $aItem;
						}
					}
				}
			} catch (\Throwable $oException) {
				$this->logException($oException);
			}
		}

		return \array_slice(\array_values($aResult), 0, $iLimit);
	}

	public function IsActive() : bool
	{
		return \count($this->aDrivers);
	}
}
