<?php

namespace Tachyon\Providers\Calendar;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\ITip\Broker;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

/**
 * The attendee side of iTIP (RFC 5546) for an invitation that came by mail
 * (iMIP, RFC 6047): what the invitation is, which of the user's addresses it
 * was sent to, and the REPLY that answers it. No mail is sent and no calendar
 * is touched here.
 */
class Invitation
{
	/** A calendar file is text; past this it is not one. */
	public const MAX_BYTES = 5242880;

	public const PARTSTATS = ['ACCEPTED', 'TENTATIVE', 'DECLINED'];

	private VCalendar $oVCalendar;

	private function __construct(VCalendar $oVCalendar)
	{
		$this->oVCalendar = $oVCalendar;
	}

	/**
	 * A stored copy read the same way, to find our answer in it.
	 */
	public static function fromVCalendar(VCalendar $oVCalendar) : ?self
	{
		return isset($oVCalendar->VEVENT) ? new self($oVCalendar) : null;
	}

	public static function fromIcal(string $sIcal) : ?self
	{
		if (!\strlen(\trim($sIcal)) || self::MAX_BYTES < \strlen($sIcal)) {
			return null;
		}
		try {
			$oParsed = Reader::read($sIcal, Reader::OPTION_FORGIVING);
		} catch (\Throwable $oException) {
			return null;
		}
		return $oParsed instanceof VCalendar && isset($oParsed->VEVENT) ? new self($oParsed) : null;
	}

	public function Method() : string
	{
		return isset($this->oVCalendar->METHOD) ? \strtoupper((string) $this->oVCalendar->METHOD) : '';
	}

	/**
	 * The event the invitation is about: the master, or the one occurrence an
	 * invitation for a single date carries.
	 */
	public function Event() : VEvent
	{
		foreach ($this->oVCalendar->VEVENT as $oVEvent) {
			if (!isset($oVEvent->{'RECURRENCE-ID'})) {
				return $oVEvent;
			}
		}
		return $this->oVCalendar->VEVENT;
	}

	/**
	 * The address an iTIP message is exchanged under, lower case for mailto:
	 * as Sabre normalizes it.
	 */
	private static function href(Property $oAddress) : string
	{
		return (string) $oAddress->getNormalizedValue();
	}

	/**
	 * The mail addresses a calendar address stands for: the mailto: value, and
	 * the EMAIL= parameter (RFC 7986 6.2), which is all there is when the value
	 * is something else, such as an iCloud principal path.
	 */
	private static function mailAddresses(Property $oAddress) : array
	{
		$aResult = array();
		$sHref = static::href($oAddress);
		if (0 === \stripos($sHref, 'mailto:')) {
			$aResult[] = static::normalizeEmail(\substr($sHref, 7));
		}
		if (isset($oAddress['EMAIL'])) {
			$aResult[] = static::normalizeEmail((string) $oAddress['EMAIL']);
		}
		return \array_values(\array_unique(\array_filter($aResult)));
	}

	private static function normalizeEmail(string $sEmail) : string
	{
		return \strtolower(\Tachyon\Util\IDN::emailToAscii(\trim(\rawurldecode($sEmail))));
	}

	public function Organizer() : ?Property
	{
		$oVEvent = $this->Event();
		return isset($oVEvent->ORGANIZER) ? $oVEvent->ORGANIZER : null;
	}

	/**
	 * The organizer's mail address, where the REPLY goes.
	 */
	public function OrganizerEmail() : string
	{
		$oOrganizer = $this->Organizer();
		if (!$oOrganizer) {
			return '';
		}
		// The value is what iTIP answers to, so it comes before EMAIL=, which
		// iCloud sets to another address of the same person
		$sHref = static::href($oOrganizer);
		if (0 === \stripos($sHref, 'mailto:')) {
			return \trim(\rawurldecode(\substr($sHref, 7)));
		}
		return isset($oOrganizer['EMAIL']) ? \trim((string) $oOrganizer['EMAIL']) : '';
	}

	/**
	 * The ATTENDEE that is one of the given addresses, in the order they are
	 * given, so the caller decides which identity wins when several are invited.
	 *
	 * @param string[] $aEmails
	 * @return array{0: Property, 1: string}|null the attendee and the address it matched
	 */
	public function FindAttendee(array $aEmails) : ?array
	{
		$aAttendees = array();
		foreach ($this->oVCalendar->VEVENT as $oVEvent) {
			foreach ($oVEvent->ATTENDEE ?? array() as $oAttendee) {
				$aAttendees[] = $oAttendee;
			}
		}
		foreach ($aEmails as $sEmail) {
			$sWanted = static::normalizeEmail($sEmail);
			foreach ($aAttendees as $oAttendee) {
				if (\in_array($sWanted, static::mailAddresses($oAttendee), true)) {
					return array($oAttendee, $sEmail);
				}
			}
		}
		return null;
	}

	/**
	 * @param string[] $aEmails
	 */
	public function IsOrganizer(array $aEmails) : bool
	{
		$oOrganizer = $this->Organizer();
		if (!$oOrganizer) {
			return false;
		}
		$aOrganizer = static::mailAddresses($oOrganizer);
		foreach ($aEmails as $sEmail) {
			if (\in_array(static::normalizeEmail($sEmail), $aOrganizer, true)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What the message view needs to show the invitation.
	 *
	 * @param string[] $aEmails the user's addresses, preferred first
	 */
	public function Describe(array $aEmails) : array
	{
		$oVEvent = $this->Event();
		$oOrganizer = $this->Organizer();
		$aMatch = $this->FindAttendee($aEmails);

		$oStart = isset($oVEvent->DTSTART) ? $oVEvent->DTSTART : null;
		$bAllDay = $oStart && !$oStart->hasTime();
		$iStart = $oStart ? $oStart->getDateTime()->getTimestamp() : 0;
		$iEnd = 0;
		if (isset($oVEvent->DTEND)) {
			$iEnd = $oVEvent->DTEND->getDateTime()->getTimestamp();
		} else if ($oStart && isset($oVEvent->DURATION)) {
			$iEnd = $oStart->getDateTime()->add($oVEvent->DURATION->getDateInterval())->getTimestamp();
		}

		$sMethod = $this->Method();
		$sStatus = isset($oVEvent->STATUS) ? \strtoupper((string) $oVEvent->STATUS) : '';
		$bCancelled = 'CANCEL' === $sMethod || 'CANCELLED' === $sStatus;

		return array(
			'Method' => $sMethod,
			'Uid' => isset($oVEvent->UID) ? (string) $oVEvent->UID : '',
			'Sequence' => static::sequence($oVEvent),
			'Summary' => isset($oVEvent->SUMMARY) ? (string) $oVEvent->SUMMARY : '',
			'Location' => isset($oVEvent->LOCATION) ? (string) $oVEvent->LOCATION : '',
			'Start' => $iStart,
			'End' => $iEnd,
			'AllDay' => $bAllDay,
			'Recurring' => isset($oVEvent->RRULE) || isset($oVEvent->RDATE),
			'Cancelled' => $bCancelled,
			'Organizer' => $oOrganizer ? array(
				'Email' => $this->OrganizerEmail(),
				'Name' => isset($oOrganizer['CN']) ? (string) $oOrganizer['CN'] : ''
			) : null,
			'Attendee' => $aMatch ? array(
				'Email' => $aMatch[1],
				'Partstat' => isset($aMatch[0]['PARTSTAT']) ? \strtoupper((string) $aMatch[0]['PARTSTAT']) : 'NEEDS-ACTION'
			) : null,
			// Answering needs a REQUEST from someone else, sent to one of our
			// addresses, that has not been called off
			'CanReply' => 'REQUEST' === $sMethod && $aMatch && $oOrganizer
				&& \strlen($this->OrganizerEmail()) && !$bCancelled && !$this->IsOrganizer($aEmails)
		);
	}

	private static function sequence(VEvent $oVEvent) : int
	{
		return isset($oVEvent->SEQUENCE) ? (int) $oVEvent->SEQUENCE->getValue() : 0;
	}

	public function SequenceNumber() : int
	{
		return static::sequence($this->Event());
	}

	/**
	 * Whether the invitation carries the whole event, rather than only changed
	 * dates of a series (a VEVENT with RECURRENCE-ID and no master), which
	 * only mean something next to the series they belong to.
	 */
	public function HasMaster() : bool
	{
		foreach ($this->oVCalendar->VEVENT as $oVEvent) {
			if (!isset($oVEvent->{'RECURRENCE-ID'})) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Our PARTSTAT in this calendar, as the attendee at one of $aEmails.
	 */
	public function Partstat(array $aEmails) : string
	{
		$aMatch = $this->FindAttendee($aEmails);
		return $aMatch && isset($aMatch[0]['PARTSTAT']) ? \strtoupper((string) $aMatch[0]['PARTSTAT']) : '';
	}

	private static function recurrenceKey(VEvent $oVEvent) : ?int
	{
		return isset($oVEvent->{'RECURRENCE-ID'}) ? $oVEvent->{'RECURRENCE-ID'}->getDateTime()->getTimestamp() : null;
	}

	/**
	 * The copy to keep in the user's calendar after answering as $sEmail.
	 *
	 * A whole invitation replaces what was stored, as an update from the
	 * organizer does. Changed dates alone go into the stored series in place of
	 * the ones they change; without a stored series there is nothing to put them
	 * in, and null is returned.
	 *
	 * The organizer gets SCHEDULE-AGENT=CLIENT (RFC 6638 7.1): the reply was
	 * sent by mail already, and a CalDAV server that schedules on its own
	 * (Nextcloud, through Sabre) would otherwise send a second one when it sees
	 * our PARTSTAT change.
	 */
	public function StoredCopy(?VCalendar $oStored, string $sEmail, string $sPartstat) : ?VCalendar
	{
		if ($this->HasMaster()) {
			$oCopy = new VCalendar();
			foreach ($this->oVCalendar->getComponents() as $oComponent) {
				$oCopy->add(clone $oComponent);
			}
		} else if ($oStored) {
			$oCopy = clone $oStored;
			foreach ($this->oVCalendar->VEVENT as $oVEvent) {
				$iKey = static::recurrenceKey($oVEvent);
				foreach ($oCopy->select('VEVENT') as $oOld) {
					if (null !== $iKey && static::recurrenceKey($oOld) === $iKey) {
						$oCopy->remove($oOld);
					}
				}
				$oCopy->add(clone $oVEvent);
			}
			foreach ($this->oVCalendar->select('VTIMEZONE') as $oTimezone) {
				$sTzid = (string) $oTimezone->TZID;
				$bHave = false;
				foreach ($oCopy->select('VTIMEZONE') as $oHave) {
					$bHave = $bHave || (string) $oHave->TZID === $sTzid;
				}
				$bHave || $oCopy->add(clone $oTimezone);
			}
		} else {
			return null;
		}

		$aMatch = (new self($oCopy))->FindAttendee(array($sEmail));
		$sHref = $aMatch ? static::href($aMatch[0]) : '';
		foreach ($oCopy->VEVENT as $oVEvent) {
			foreach ($oVEvent->ATTENDEE ?? array() as $oAttendee) {
				if (static::href($oAttendee) === $sHref) {
					$oAttendee['PARTSTAT'] = \strtoupper($sPartstat);
					unset($oAttendee['RSVP']);
				}
			}
			if (isset($oVEvent->ORGANIZER)) {
				$oVEvent->ORGANIZER['SCHEDULE-AGENT'] = 'CLIENT';
			}
		}
		unset($oCopy->METHOD);
		return $oCopy;
	}

	/**
	 * The stored series without the dates this message names, for a
	 * cancellation or a decline of single dates: each becomes an EXDATE, and
	 * its changed copy, if there was one, goes. Null when the message is about
	 * the whole event, which then goes as a whole.
	 */
	public function WithoutDates(VCalendar $oStored) : ?VCalendar
	{
		if ($this->HasMaster()) {
			return null;
		}
		$oCopy = clone $oStored;
		$oMaster = null;
		foreach ($oCopy->VEVENT as $oVEvent) {
			if (!isset($oVEvent->{'RECURRENCE-ID'})) {
				$oMaster = $oVEvent;
			}
		}
		if (!$oMaster) {
			return null;
		}
		foreach ($this->oVCalendar->VEVENT as $oVEvent) {
			$oRecurrenceId = $oVEvent->{'RECURRENCE-ID'};
			$iKey = static::recurrenceKey($oVEvent);
			foreach ($oCopy->select('VEVENT') as $oOld) {
				if (static::recurrenceKey($oOld) === $iKey) {
					$oCopy->remove($oOld);
				}
			}
			$oMaster->add('EXDATE', $oRecurrenceId->getDateTime(),
				$oRecurrenceId->hasTime() ? array() : array('VALUE' => 'DATE'));
		}
		return $oCopy;
	}

	/**
	 * The METHOD:REPLY that answers the invitation as the attendee at $sEmail.
	 *
	 * Sabre's broker builds it, from the invitation as the old state and a copy
	 * with our PARTSTAT changed as the new one. It only answers a change, so the
	 * old state is taken as NEEDS-ACTION: an organizer may send an update that
	 * already says ACCEPTED for us, and answering it again must still go out.
	 */
	public function Reply(string $sEmail, string $sPartstat) : ?VCalendar
	{
		$sPartstat = \strtoupper($sPartstat);
		if (!\in_array($sPartstat, self::PARTSTATS, true)) {
			return null;
		}

		$oOld = clone $this->oVCalendar;
		$aMatch = (new self($oOld))->FindAttendee(array($sEmail));
		if (!$aMatch) {
			return null;
		}
		$sHref = static::href($aMatch[0]);
		foreach ($oOld->VEVENT as $oVEvent) {
			foreach ($oVEvent->ATTENDEE ?? array() as $oAttendee) {
				if (static::href($oAttendee) === $sHref) {
					$oAttendee['PARTSTAT'] = 'NEEDS-ACTION';
				}
			}
		}

		$oNew = clone $oOld;
		foreach ($oNew->VEVENT as $oVEvent) {
			foreach ($oVEvent->ATTENDEE ?? array() as $oAttendee) {
				if (static::href($oAttendee) === $sHref) {
					$oAttendee['PARTSTAT'] = $sPartstat;
					unset($oAttendee['RSVP']);
				}
			}
		}

		try {
			$aMessages = (new Broker)->parseEvent($oNew, $sHref, $oOld);
		} catch (\Throwable $oException) {
			\Tachyon\Util\Log::warning('Calendar', 'iTIP reply failed: ' . $oException->getMessage());
			return null;
		}

		foreach ($aMessages as $oMessage) {
			if ('REPLY' === $oMessage->method && $oMessage->message instanceof VCalendar) {
				$oReply = $oMessage->message;
				$oReply->PRODID = '-//Tachyon//Tachyon ' . APP_VERSION . '//EN';
				foreach ($oReply->VEVENT as $oVEvent) {
					// The broker copies the invitation's SEQUENCE, and adds an
					// empty one when the invitation had none (Horde), which cannot
					// be written out. No SEQUENCE means 0 (RFC 5545 3.8.7.4).
					if (isset($oVEvent->SEQUENCE) && null === $oVEvent->SEQUENCE->getValue()) {
						$oVEvent->SEQUENCE = 0;
					}
				}
				return $oReply;
			}
		}
		return null;
	}
}
