<?php

namespace Tachyon\Providers;

class AddressBook extends AbstractProvider
{
	private ?AddressBook\AddressBookInterface $oDriver;

	public function __construct(?AddressBook\AddressBookInterface $oDriver)
	{
		$this->oDriver = $oDriver;
	}

	public function Test() : string
	{
		\sleep(1);
		return $this->oDriver ? $this->oDriver->Test() : 'Personal address book driver is not allowed';
	}

	public function IsActive() : bool
	{
		return $this->oDriver && $this->oDriver->IsSupported();
	}

	public function Sync() : bool
	{
		return $this->IsActive() ? $this->oDriver->Sync() : false;
	}

	public function Export(string $sType = 'vcf') : bool
	{
		return $this->IsActive() ? $this->oDriver->Export($sType) : false;
	}

	public function ContactSave(AddressBook\Classes\Contact $oContact) : bool
	{
		return $this->IsActive() ? $this->oDriver->ContactSave($oContact) : false;
	}

	public function DeleteContacts(array $aContactIds) : bool
	{
		return $this->IsActive() ? $this->oDriver->DeleteContacts($aContactIds) : false;
	}

	/**
	 * Not on AddressBookInterface: whether a contact is local or synced is a
	 * PDO address book notion, the Kolab driver stores contacts as IMAP messages.
	 */
	public function DeleteContactsByScope(string $sScope) : bool
	{
		return $this->IsActive() && \method_exists($this->oDriver, 'DeleteContactsByScope')
			? $this->oDriver->DeleteContactsByScope($sScope)
			: false;
	}

	public function DeleteAllContacts(string $sEmail) : bool
	{
		return $this->IsActive() ? $this->oDriver->DeleteAllContacts($sEmail) : false;
	}

	public function GetContacts(int $iOffset = 0, int $iLimit = 20, string $sSearch = '', int &$iResultCount = 0, string $sCategory = '') : array
	{
		return $this->IsActive() ? $this->oDriver->GetContacts(
			\max(0, $iOffset),
			0 < $iLimit ? $iLimit : 20,
			\trim($sSearch),
			$iResultCount,
			\trim($sCategory)
		) : array();
	}

	/**
	 * Not on AddressBookInterface: plugins supply their own drivers, and a
	 * driver written before this existed must keep working.
	 */
	public function GetContactUids(string $sSearch = '', string $sCategory = '') : array
	{
		return ($this->oDriver && \method_exists($this->oDriver, 'GetContactUids'))
			? $this->oDriver->GetContactUids($sSearch, $sCategory)
			: array();
	}

	public function GetCategories() : array
	{
		return $this->IsActive() ? $this->oDriver->GetCategories() : [];
	}

	public function GetMatchingCategories(string $sQuery, int $iLimit = 5) : array
	{
		if ($this->IsActive() && \method_exists($this->oDriver, 'GetMatchingCategories')) {
			return $this->oDriver->GetMatchingCategories($sQuery, $iLimit);
		}
		return [];
	}

	public function GetGroup(string $sCategoryName, int $iLimit = 20) : array
	{
		if ($this->IsActive() && $this->oDriver instanceof \Tachyon\Providers\Suggestions\IGroupSuggestions) {
			return $this->oDriver->GetGroup($sCategoryName, $iLimit);
		}
		return [];
	}

	public function GetContactByEmail(string $sEmail) : ?AddressBook\Classes\Contact
	{
		return $this->IsActive() ? $this->oDriver->GetContactByEmail($sEmail) : null;
	}

	public function GetContactByID($mID, bool $bIsStrID = false) : ?AddressBook\Classes\Contact
	{
		return $this->IsActive() ? $this->oDriver->GetContactByID($mID, $bIsStrID) : null;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function GetSuggestions(string $sSearch, int $iLimit = 20) : array
	{
		return $this->IsActive() ? $this->oDriver->GetSuggestions($sSearch, $iLimit) : array();
	}

	public function IncFrec(array $aEmails, bool $bCreateAuto = true) : bool
	{
		return $this->IsActive() ? $this->oDriver->IncFrec($aEmails, $bCreateAuto) : false;
	}
}
