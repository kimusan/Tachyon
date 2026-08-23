<?php

namespace Tachyon\Providers\Calendar\Classes;

use Sabre\VObject\Component\VCalendar;

class Event
{
	public string $id = '';

	public string $CalendarId = '';

	/**
	 * The iCalendar UID, which is what identifies the event on the server.
	 */
	public string $Uid = '';

	public string $Summary = '';

	public string $Description = '';

	public string $Location = '';

	/**
	 * Unix timestamps of the first occurrence. These index the range query only,
	 * the iCalendar blob remains the source of truth.
	 */
	public int $DtStart = 0;

	public int $DtEnd = 0;

	public bool $AllDay = false;

	/**
	 * The raw RRULE, empty when the event does not recur.
	 */
	public string $Rrule = '';

	/**
	 * Timestamp of the last possible occurrence, or null when the rule never ends.
	 * Lets the range query exclude recurring events that finished long ago.
	 */
	public ?int $RecurUntil = null;

	/**
	 * Timezone the event was authored in, needed to expand recurrence correctly.
	 */
	public string $Timezone = '';

	public int $Changed;

	public string $Etag = '';

	/**
	 * Resource path on the DAV server, relative to the calendar collection.
	 */
	public string $DavPath = '';

	/**
	 * The iCalendar source. Kept verbatim so an event written back to the server
	 * keeps every property Tachyon does not understand.
	 */
	protected string $sIcal = '';

	protected ?VCalendar $vCalendar = null;

	function __construct()
	{
		$this->Changed = \time();
	}

	public function Ical() : string
	{
		return $this->sIcal;
	}

	public function VCalendar() : ?VCalendar
	{
		if (null === $this->vCalendar && \strlen($this->sIcal)) {
			try {
				$oParsed = \Sabre\VObject\Reader::read($this->sIcal, \Sabre\VObject\Reader::OPTION_FORGIVING);
				if ($oParsed instanceof VCalendar) {
					$this->vCalendar = $oParsed;
				}
			} catch (\Throwable $oException) {
				\Tachyon\Util\Log::warning('Calendar', "Unparsable iCalendar for {$this->Uid}: " . $oException->getMessage());
			}
		}
		return $this->vCalendar;
	}

	/**
	 * The first VEVENT that is not a recurrence override.
	 */
	public function VEvent() : ?\Sabre\VObject\Component\VEvent
	{
		$oVCalendar = $this->VCalendar();
		if ($oVCalendar && isset($oVCalendar->VEVENT)) {
			foreach ($oVCalendar->VEVENT as $oVEvent) {
				if (!isset($oVEvent->{'RECURRENCE-ID'})) {
					return $oVEvent;
				}
			}
		}
		return null;
	}

	public function setIcal(string $sIcal) : void
	{
		$this->sIcal = $sIcal;
		$this->vCalendar = null;
	}

	public function setVCalendar(VCalendar $oVCalendar) : void
	{
		$this->vCalendar = $oVCalendar;
		$this->sIcal = $oVCalendar->serialize();
	}
}
