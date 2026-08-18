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

	public function Process(\Tachyon\Model\Account $oAccount, string $sQuery, int $iLimit = 20): array
	{
		try
		{
			$sQuery = \trim($sQuery);
			if ('' === $sQuery) {
				return [];
			}

			$cm = \OC::$server->getContactsManager();
			if (!$cm || !$cm->isEnabled()) {
				return [];
			}

			// Unregister system addressbook so as to return only contacts in user's addressbooks
			if ($this->ignoreSystemAddressbook) {
				foreach($cm->getUserAddressBooks() as $addressBook) {
					if($addressBook->isSystemAddressBook()) {
						 $cm->unregisterAddressBook($addressBook);
					}
				}
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

	public function GetGroup(string $sCategoryName, int $iLimit = 20) : array
	{
		try
		{
			$cm = \OC::$server->getContactsManager();
			if (!$cm || !$cm->isEnabled()) {
				return [];
			}

			if ($this->ignoreSystemAddressbook) {
				foreach ($cm->getUserAddressBooks() as $addressBook) {
					if ($addressBook->isSystemAddressBook()) {
						$cm->unregisterAddressBook($addressBook);
					}
				}
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
