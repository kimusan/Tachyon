<?php

namespace Tachyon\Providers\Calendar;

/**
 * Implemented by every calendar backend. A plugin can supply its own through the
 * main.fabrica hook, the way plugins/kolab does for address books, so keep this
 * free of anything specific to CalDAV or to PDO storage.
 */
interface CalendarInterface
{
	public function IsSupported() : bool;

	public function SetEmail(string $sEmail) : bool;

	/**
	 * Pull remote changes and push local ones. A backend without a remote may
	 * return true without doing anything.
	 */
	public function Sync() : bool;

	/**
	 * @return Classes\Calendar[]
	 */
	public function GetCalendars() : array;

	public function GetCalendarByUuid(string $sUuid) : ?Classes\Calendar;

	/**
	 * Occurrences overlapping [$iStart, $iEnd], with recurring events already
	 * expanded. Implementations must include recurring events whose first
	 * occurrence is outside the window.
	 *
	 * @param string[] $aCalendarUuids Empty means every calendar.
	 * @return array Each entry has uid, calendarUuid, start, end, allDay, title,
	 *               description, location and recurring.
	 */
	public function GetOccurrences(array $aCalendarUuids, int $iStart, int $iEnd) : array;

	public function GetEventByUid(string $sCalendarUuid, string $sUid) : ?Classes\Event;

	public function EventSave(string $sCalendarUuid, Classes\Event $oEvent) : bool;

	public function DeleteEvent(string $sCalendarUuid, string $sUid) : bool;

	public function Test() : string;
}
