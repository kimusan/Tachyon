<?php

namespace Tachyon\Providers;

class Calendar extends AbstractProvider
{
	private ?Calendar\CalendarInterface $oDriver;

	public function __construct(?Calendar\CalendarInterface $oDriver)
	{
		$this->oDriver = $oDriver;
	}

	public function IsActive() : bool
	{
		return $this->oDriver && $this->oDriver->IsSupported();
	}

	public function Test() : string
	{
		return $this->oDriver ? $this->oDriver->Test() : 'Calendar driver is not allowed';
	}

	public function Sync() : bool
	{
		return $this->IsActive() ? $this->oDriver->Sync() : false;
	}

	public function GetCalendars() : array
	{
		return $this->IsActive() ? $this->oDriver->GetCalendars() : array();
	}

	public function GetCalendarByUuid(string $sUuid) : ?Calendar\Classes\Calendar
	{
		return $this->IsActive() ? $this->oDriver->GetCalendarByUuid($sUuid) : null;
	}

	public function GetOccurrences(array $aCalendarUuids, int $iStart, int $iEnd) : array
	{
		return $this->IsActive() ? $this->oDriver->GetOccurrences($aCalendarUuids, $iStart, $iEnd) : array();
	}

	public function GetEventByUid(string $sCalendarUuid, string $sUid) : ?Calendar\Classes\Event
	{
		return $this->IsActive() ? $this->oDriver->GetEventByUid($sCalendarUuid, $sUid) : null;
	}

	public function EventSave(string $sCalendarUuid, Calendar\Classes\Event $oEvent) : bool
	{
		return $this->IsActive() ? $this->oDriver->EventSave($sCalendarUuid, $oEvent) : false;
	}

	public function DeleteEvent(string $sCalendarUuid, string $sUid) : bool
	{
		return $this->IsActive() ? $this->oDriver->DeleteEvent($sCalendarUuid, $sUid) : false;
	}

	/**
	 * Not on CalendarInterface: only a DAV backed driver has a connection to
	 * configure, so a plugin driver may legitimately lack it.
	 */
	public function setDAVClientConfig(?array $aConfig) : void
	{
		if ($this->oDriver && \method_exists($this->oDriver, 'setDAVClientConfig')) {
			$this->oDriver->setDAVClientConfig($aConfig);
		}
	}
}
