<?php

class SearchFiltersPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	public const
		NAME = 'Search Filters',
		AUTHOR = 'AbdoBnHesham',
		URL    = 'https://github.com/the-djmaze/snappymail/pull/1673',
		VERSION = '0.3',
		RELEASE = '2026-09-08',
		REQUIRED = '2.36.3',
		CATEGORY = 'General',
		LICENSE = 'MIT',
		DESCRIPTION = 'Add filters to search queries';

	public function Init(): void
	{
		$this->UseLangs(true);

		$this->addHook('imap.after-login', 'ApplyFilters');

		$this->addTemplate('templates/STabSearchFilters.html');

		$this->addTemplate('templates/PopupsSearchFilters.html');
		$this->addTemplate('templates/PopupsSTabAdvancedSearch.html');
		$this->addJs('js/SearchFilters.js');

		$this->addJsonHook('SGetFilters', 'GetFilters');
		$this->addJsonHook('SAddEditFilter', 'AddEditFilter');
		$this->addJsonHook('SUpdateSearchQ', 'UpdateSearchQ');
		$this->addJsonHook('SDeleteFilter', 'DeleteFilter');
	}


	/**
	 * Filters belong to one mailbox.
	 *
	 * They used to be a single flat list. Plugin user settings resolve through
	 * FileStorage, which maps an additional account to its ParentEmail, so that
	 * one list was the same list for every account and every one of them ran all
	 * of it on login. A rule filing mail into a folder that exists in one mailbox
	 * fails in the others, and because the hook runs during login, the failure
	 * surfaced as a login failure rather than as a broken rule.
	 *
	 * Now keyed by address. A list left over from before belongs to the account
	 * that owns the settings file, which is the main one, and is moved into its
	 * bucket the first time that account writes.
	 */
	private function allFilters() : array
	{
		$aSettings = $this->getUserSettings();
		$aAll = $aSettings['SFilters'] ?? [];
		return \is_array($aAll) ? $aAll : [];
	}

	private function filtersFor(string $sEmail) : array
	{
		$aAll = $this->allFilters();
		if ($aAll && \array_is_list($aAll)) {
			// Legacy flat list: only the settings owner ever had it
			return $sEmail === $this->ownerEmail() ? $aAll : [];
		}
		return isset($aAll[$sEmail]) && \is_array($aAll[$sEmail]) ? $aAll[$sEmail] : [];
	}

	private function saveFiltersFor(string $sEmail, array $aFilters) : bool
	{
		$aAll = $this->allFilters();
		if ($aAll && \array_is_list($aAll)) {
			// Rewrite the legacy list as the owner's, then apply this change on top
			$aAll = [$this->ownerEmail() => $aAll];
		}
		$aAll[$sEmail] = \array_values($aFilters);

		$aSettings = $this->getUserSettings();
		$aSettings['SFilters'] = $aAll;
		return $this->saveUserSettings($aSettings);
	}

	/** The account the settings file belongs to. */
	private function ownerEmail() : string
	{
		$oMain = $this->Manager()->Actions()->getMainAccountFromToken(false);
		return $oMain ? $oMain->Email() : '';
	}

	/** The account acting right now, which is the one a filter is being edited for. */
	private function currentEmail() : string
	{
		$oAccount = $this->Manager()->Actions()->getAccountFromToken(false);
		return $oAccount ? $oAccount->Email() : '';
	}

	public function ApplyFilters(
		\Tachyon\Model\Account $oAccount,
		\MailSo\Imap\ImapClient $oImapClient,
		bool $bSuccess,
		\MailSo\Imap\Settings $oSettings
	) {
		if (!$bSuccess) {
			return;
		}

		$Filters = $this->filtersFor($oAccount->Email());
		if (!$Filters) {
			return;
		}

		foreach ($Filters as $filter) {
			if (empty($filter['searchQ'])) {
				continue;
			}
			$searchQ = $filter['searchQ'];

			try {
			// keyword rules look in every top level folder
			if (\stripos($searchQ, 'keyword=') !== false) {
				$oMailClient = $this->Manager()->Actions()->MailClient();

				// Pobieramy wszystkie foldery top-level
				$folders = $oMailClient->Folders('', false, '');
				foreach ($folders as $f) {
					$folder = $f->FullName ?? null;
					if (!$folder) continue;

					$uids = $this->searchMessages($oImapClient, $searchQ, $folder);
					if (!empty($uids)) {
						$this->applyActions($oImapClient, $filter, $uids, $folder);
					}
				}

				continue;
			}

			$uids = $this->searchMessages($oImapClient, $searchQ, 'INBOX');
			if (!empty($uids)) {
				$this->applyActions($oImapClient, $filter, $uids, 'INBOX');
			}
			} catch (\Throwable $oException) {
				// This runs inside imap.after-login. Letting it out reports the
				// whole login as failed, which is how a rule naming a folder that
				// does not exist in this mailbox turned into "authentication
				// failed" for an account that had authenticated perfectly well.
				$this->Manager()->logWrite(
					'SearchFilters rule "' . $searchQ . '" failed for '
					. $oAccount->Email() . ': ' . $oException->getMessage(),
					\LOG_ERR
				);
			}
		}
	}

	private function searchMessages(
		\MailSo\Imap\ImapClient $imapClient,
		string $search,
		string $folder = "INBOX"
	): array {
		try {
			$bUseCache = false;
			$oSearchCriterias = \MailSo\Imap\SearchCriterias::fromString(
				$imapClient,
				$folder,
				$search,
				true,
				$bUseCache
			);

			$imapClient->FolderSelect($folder);
			return $imapClient->MessageSearch($oSearchCriterias, true);

		} catch (\Throwable $e) {
			$this->Manager()->logWrite(
				'SearchFilters IMAP error in folder "' . $folder .
				'" for search "' . $search . '": ' . $e->getMessage(),
				LOG_ERR
			);
			return [];
		}
	}

	private function applyActions(
		\MailSo\Imap\ImapClient $imapClient,
		array $filter,
		array $uids,
		string $folder
	) {
		// Mark as read/seen
		if (!empty($filter['fSeen'])) {
			foreach ($uids as $uid) {
				$oRange = new \MailSo\Imap\SequenceSet([$uid]);
				$this->Manager()->Actions()->MailClient()->MessageSetFlag(
					$folder,
					$oRange,
					\MailSo\Imap\Enumerations\MessageFlag::SEEN
				);
			}
		}

		// Flag/Star message
		if (!empty($filter['fFlag'])) {
			foreach ($uids as $uid) {
				$oRange = new \MailSo\Imap\SequenceSet([$uid]);
				$this->Manager()->Actions()->MailClient()->MessageSetFlag(
					$folder,
					$oRange,
					\MailSo\Imap\Enumerations\MessageFlag::FLAGGED
				);
			}
		}

		// Move to folder
		if (!empty($filter['fFolder']) && $filter['fFolder'] !== -1) {
			foreach ($uids as $uid) {
				$oRange = new \MailSo\Imap\SequenceSet([$uid]);
				// The folder it was found in. Hardcoding INBOX moved whatever
				// happened to hold that uid there instead, for keyword rules that
				// search every folder.
				$imapClient->MessageMove($folder, $filter['fFolder'], $oRange);
			}
		}
	}

	public function GetFilters()
	{
		$Filters = $this->filtersFor($this->currentEmail());

		$Search = $this->jsonParam('SSearchQ');
		if (!$Search) {
			return $this->jsonResponse(__FUNCTION__, ['SFilters' => $Filters]);
		}

		$Filter = null;
		foreach ($Filters as $filter) {
			if ($filter['searchQ'] == $Search) {
				$Filter = $filter;
			}
		}

		return $this->jsonResponse(__FUNCTION__, ['SFilter' => $Filter]);
	}

	public function AddEditFilter()
	{
		$SFilter = $this->jsonParam('SFilter');
		$newFilter = [
			'searchQ' => $SFilter['searchQ'],
			'priority' => $SFilter['priority'] ?? 1,
			'fFolder' => $SFilter['fFolder'],
			'fSeen' => $SFilter['fSeen'],
			'fFlag' => $SFilter['fFlag'],
		];

		$sEmail = $this->currentEmail();
		$aFilters = $this->filtersFor($sEmail);

		$foundIndex = null;
		foreach ($aFilters as $index => $filter) {
			if ($filter['searchQ'] == $SFilter['searchQ']) {
				if ($filter['priority'] != $SFilter['priority']) {
					\array_splice($aFilters, $index, 1);
				} else {
					$foundIndex = $index;
				}
			}
		}

		if ($foundIndex === null) {
			$insertIndex = 0;
			foreach ($aFilters as $index => $filter)
				if ($filter['priority'] >= $newFilter['priority'])
					$insertIndex = $index + 1;
				else
					break;

			\array_splice($aFilters, $insertIndex, 0, [$newFilter]);
		} else {
			$aFilters[$foundIndex] = $newFilter;
		}

		return $this->jsonResponse(__FUNCTION__, $this->saveFiltersFor($sEmail, $aFilters));
	}

	public function UpdateSearchQ()
	{
		$SFilter = $this->jsonParam('SFilter');

		$sEmail = $this->currentEmail();
		$aFilters = $this->filtersFor($sEmail);

		foreach ($aFilters as $index => $filter) {
			if ($filter['searchQ'] == $SFilter['oldSearchQ']) {
				$filter['searchQ'] = $SFilter['searchQ'];
				$aFilters[$index] = $filter;
				break;
			}
		}

		return $this->jsonResponse(__FUNCTION__, $this->saveFiltersFor($sEmail, $aFilters));
	}

	public function DeleteFilter()
	{
		$Search = $this->jsonParam('SSearchQ');

		$sEmail = $this->currentEmail();
		$aFilters = $this->filtersFor($sEmail);

		foreach ($aFilters as $index => $filter) {
			if ($filter['searchQ'] == $Search) {
				\array_splice($aFilters, $index, 1);
			}
		}

		return $this->jsonResponse(__FUNCTION__, $this->saveFiltersFor($sEmail, $aFilters));
	}
}
