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

	/**
	 * Contacts the last Sync() could not import. Not on AddressBookInterface, so
	 * a plugin driver written before this keeps working and reports nothing.
	 */
	public function SyncSkipped() : int
	{
		return ($this->oDriver && \method_exists($this->oDriver, 'SyncSkipped'))
			? $this->oDriver->SyncSkipped() : 0;
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

	/**
	 * $bWithEmailOnly is deliberately not on AddressBookInterface: adding a
	 * parameter there would fatal every plugin driver that implements the
	 * current signature. PHP ignores surplus arguments to userland methods, so
	 * a driver that predates the filter simply does not apply it.
	 */
	public function GetContacts(int $iOffset = 0, int $iLimit = 20, string $sSearch = '', int &$iResultCount = 0, string $sCategory = '', bool $bWithEmailOnly = false) : array
	{
		return $this->IsActive() ? $this->oDriver->GetContacts(
			\max(0, $iOffset),
			0 < $iLimit ? $iLimit : 20,
			\trim($sSearch),
			$iResultCount,
			\trim($sCategory),
			$bWithEmailOnly
		) : array();
	}

	/**
	 * Not on AddressBookInterface: plugins supply their own drivers, and a
	 * driver written before this existed must keep working.
	 */
	public function GetContactUids(string $sSearch = '', string $sCategory = '', bool $bWithEmailOnly = false) : array
	{
		return ($this->oDriver && \method_exists($this->oDriver, 'GetContactUids'))
			? $this->oDriver->GetContactUids($sSearch, $sCategory, $bWithEmailOnly)
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
