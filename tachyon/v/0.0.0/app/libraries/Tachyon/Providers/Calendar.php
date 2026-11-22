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

	/**
	 * How much the last Sync() could not store. Not on CalendarInterface, so a
	 * driver written before this keeps working and simply reports nothing.
	 */
	public function SyncSkipped() : int
	{
		return ($this->oDriver && \method_exists($this->oDriver, 'SyncSkipped'))
			? $this->oDriver->SyncSkipped() : 0;
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

	/**
	 * The event with this UID in whichever of the user's calendars holds it,
	 * for an invitation that may have been answered before. Built on
	 * GetEventByUid() rather than added to CalendarInterface, so a backend
	 * from a plugin does not have to implement it.
	 *
	 * @return array{0: Calendar\Classes\Calendar, 1: Calendar\Classes\Event}|null
	 */
	public function FindEventByUid(string $sUid) : ?array
	{
		if (!\strlen($sUid)) {
			return null;
		}
		foreach ($this->GetCalendars() as $oCalendar) {
			$oEvent = $this->GetEventByUid($oCalendar->Uuid, $sUid);
			if ($oEvent) {
				return array($oCalendar, $oEvent);
			}
		}
		return null;
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
	 * Removes stored calendars outside $aKeepDavPaths. Not on CalendarInterface,
	 * since only a DAV backed driver has paths to compare against.
	 *
	 * @param string[] $aKeepDavPaths Empty removes all.
	 */
	public function PurgeCalendars(array $aKeepDavPaths) : int
	{
		return ($this->IsActive() && \method_exists($this->oDriver, 'PurgeCalendars'))
			? $this->oDriver->PurgeCalendars($aKeepDavPaths) : 0;
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
