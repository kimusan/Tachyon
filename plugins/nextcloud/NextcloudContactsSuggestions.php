<?php

class NextcloudContactsSuggestions implements
	\Tachyon\Providers\Suggestions\ISuggestions,
	\Tachyon\Providers\Suggestions\IGroupSuggestions
{
	use \MailSo\Log\Inherit;

	private bool $ignoreSystemAddressbook;

	function __construct(bool $ignoreSystemAddressbook = true)
	{
		$this->ignoreSystemAddressbook = $ignoreSystemAddressbook;
	}

	/**
	 * The contacts manager, ready to search, or null when there is nothing to
	 * search. search() loads every address book the user has and skips the ones
	 * they have disabled, so the only filtering left to do here is the system
	 * address book, which is dropped unless the admin asked to keep it.
	 */
	private function manager()
	{
		// get(IManager::class), not getContactsManager(). Nextcloud 34 stripped
		// the legacy OC\Server::getXxx() convenience methods, leaving three of
		// them, so the old call is a fatal there and took every suggestion in
		// this class down with it. The container and the interface are both
		// unchanged, and the rest of the plugin has always fetched this way.
		$cm = \OC::$server->get(\OCP\Contacts\IManager::class);
		if (!$cm || !$cm->isEnabled()) {
			return null;
		}
		if ($this->ignoreSystemAddressbook) {
			foreach ($cm->getUserAddressBooks() as $addressBook) {
				if ($addressBook->isSystemAddressBook()) {
					$cm->unregisterAddressBook($addressBook);
				}
			}
		}
		return $cm;
	}

	public function Process(\Tachyon\Model\Account $oAccount, string $sQuery, int $iLimit = 20): array
	{
		try
		{
			$sQuery = \trim($sQuery);
			if ('' === $sQuery) {
				return [];
			}

			$cm = $this->manager();
			if (!$cm) {
				return [];
			}

			$aSearchResult = $cm->search($sQuery, array('FN', 'NICKNAME', 'TITLE', 'EMAIL'));

			//$this->oLogger->WriteDump($aSearchResult);

			if (\is_array($aSearchResult) && 0 < \count($aSearchResult)) {
				$iInputLimit = $iLimit;
				$aResult = array();
				foreach ($aSearchResult as $aContact) {
					if (0 >= $iLimit) {
						break;
					}
					if (!empty($aContact['UID'])) {
						$sUid = $aContact['UID'];
						$mEmails = isset($aContact['EMAIL']) ? $aContact['EMAIL'] : '';

						$sFullName = isset($aContact['FN']) ? \trim($aContact['FN']) : '';
						if (empty($sFullName) && isset($aContact['NICKNAME'])) {
							$sFullName = \trim($aContact['NICKNAME']);
						}

						if (!\is_array($mEmails)) {
							$mEmails = array($mEmails);
						}

						foreach ($mEmails as $sEmail) {
							$sHash = $sFullName.'|'.$sEmail;
							if (!isset($aResult[$sHash])) {
								$aResult[$sHash] = array($sEmail, $sFullName);
								--$iLimit;
							}
						}
					}
				}
				return \array_slice(\array_values($aResult), 0, $iInputLimit);
			}
		}
		catch (\Exception $oException)
		{
			$this->logException($oException);
		}

		return [];
	}

	/**
	 * Categories matching what has been typed so far, with how many contacts
	 * carry each, so the compose box can offer a Nextcloud group by name.
	 *
	 * CATEGORIES is one of Nextcloud's indexed properties, so this is a normal
	 * search rather than reading every card.
	 */
	public function GetMatchingCategories(string $sQuery, int $iLimit = 5) : array
	{
		try
		{
			$sQuery = \trim($sQuery);
			if ('' === $sQuery) {
				return [];
			}

			$cm = $this->manager();
			if (!$cm) {
				return [];
			}

			$aSearchResult = $cm->search($sQuery, ['CATEGORIES']);
			if (!\is_array($aSearchResult)) {
				return [];
			}

			// A search for "fri" matches a contact whose CATEGORIES holds
			// "friends", but the contact carries every category it belongs to,
			// so each has to be checked rather than taking the whole field.
			$aCounts = [];
			$aNames = [];
			foreach ($aSearchResult as $aContact) {
				$aCategories = isset($aContact['CATEGORIES'])
					? (\is_array($aContact['CATEGORIES'])
						? $aContact['CATEGORIES']
						: \explode(',', (string) $aContact['CATEGORIES']))
					: [];
				$aSeen = [];
				foreach ($aCategories as $sCat) {
					$sCat = \trim($sCat);
					if (!\strlen($sCat) || false === \mb_stripos($sCat, $sQuery)) {
						continue;
					}
					$sKey = \mb_strtolower($sCat);
					// One contact counts once per category however often it repeats
					if (isset($aSeen[$sKey])) {
						continue;
					}
					$aSeen[$sKey] = true;
					$aNames[$sKey] = $aNames[$sKey] ?? $sCat;
					$aCounts[$sKey] = ($aCounts[$sKey] ?? 0) + 1;
				}
			}

			\arsort($aCounts);
			$aResult = [];
			foreach ($aCounts as $sKey => $iCount) {
				if ($iLimit <= \count($aResult)) {
					break;
				}
				$aResult[] = [$aNames[$sKey], $iCount];
			}
			return $aResult;
		}
		catch (\Exception $oException)
		{
			$this->logException($oException);
		}

		return [];
	}

	public function GetGroup(string $sCategoryName, int $iLimit = 20) : array
	{
		try
		{
			$cm = $this->manager();
			if (!$cm) {
				return [];
			}

			$aSearchResult = $cm->search($sCategoryName, ['CATEGORIES']);
			if (!\is_array($aSearchResult) || !$aSearchResult) {
				return [];
			}

			$aResult = [];
			foreach ($aSearchResult as $aContact) {
				if ($iLimit <= \count($aResult)) {
					break;
				}
				// Only include contacts whose CATEGORIES field contains an exact match
				$aCategories = isset($aContact['CATEGORIES']) ? (array) $aContact['CATEGORIES'] : [];
				$bMatch = false;
				foreach ($aCategories as $sCat) {
					if (0 === \strcasecmp(\trim($sCat), $sCategoryName)) {
						$bMatch = true;
						break;
					}
				}
				if (!$bMatch) {
					continue;
				}

				$mEmails  = isset($aContact['EMAIL']) ? (array) $aContact['EMAIL'] : [];
				$sFullName = \trim($aContact['FN'] ?? ($aContact['NICKNAME'] ?? ''));
				foreach ($mEmails as $sEmail) {
					if ($iLimit <= \count($aResult)) {
						break;
					}
					$aResult[] = [$sEmail, $sFullName];
				}
			}
			return $aResult;
		}
		catch (\Exception $oException)
		{
			$this->logException($oException);
		}

		return [];
	}
}
