<?php

namespace Tachyon\Providers\Calendar;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Recur\RRuleIterator;

/**
 * Applies what the event editor sends to a stored iCalendar body, in place.
 *
 * The editor shows a few fields of the master VEVENT. Everything else it does
 * not show (attendees, alarms, categories, the overrides and exceptions of a
 * series) has to survive a save, so the body is changed rather than rebuilt.
 * A field left null is not touched, which is how a drag, that only sends the
 * times, leaves the description and location alone.
 */
class EventEditor
{
	/** The frequencies the editor offers. Anything finer is not made here. */
	public const FREQUENCIES = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];

	/**
	 * The RRULE parts the editor can build. A stored rule with any other part
	 * is shown as custom and sent back untouched.
	 */
	public const EDITABLE_PARTS = ['FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYDAY', 'WKST'];

	/**
	 * A rule from the editor, checked: one of FREQUENCIES, and one Sabre can
	 * iterate. Returns it in Sabre's canonical form.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function validRrule(string $sRrule, \DateTimeInterface $oStart) : string
	{
		$oCalendar = new VCalendar();
		try {
			$oRecur = $oCalendar->createProperty('RRULE', $sRrule);
			$aParts = $oRecur->getParts();
		} catch (\Throwable $oException) {
			throw new \InvalidArgumentException('Unreadable repeat rule');
		}
		if (!\in_array(\strtoupper((string) ($aParts['FREQ'] ?? '')), self::FREQUENCIES, true)) {
			throw new \InvalidArgumentException('Unsupported repeat frequency');
		}
		try {
			$oIterator = new RRuleIterator($aParts, $oStart);
			$oIterator->valid();
		} catch (\Throwable $oException) {
			throw new \InvalidArgumentException('Invalid repeat rule: ' . $oException->getMessage());
		}
		return $oRecur->getValue();
	}

	public static function master(VCalendar $oVCalendar) : ?VEvent
	{
		foreach ($oVCalendar->VEVENT ?? array() as $oVEvent) {
			if (!isset($oVEvent->{'RECURRENCE-ID'})) {
				return $oVEvent;
			}
		}
		return null;
	}

	/**
	 * @param array{summary?: ?string, location?: ?string, description?: ?string,
	 *   start: int, end: int, allDay: bool, rrule?: ?string} $aFields
	 *   start and end are timestamps; an all-day boundary is UTC midnight.
	 *   rrule: null keeps the stored rule, '' ends the series, a string replaces it.
	 * @param \DateTimeZone $oTimeZone for an event that has no zone of its own yet
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function apply(VCalendar $oVCalendar, array $aFields, \DateTimeZone $oTimeZone) : void
	{
		$oMaster = static::master($oVCalendar);
		if (!$oMaster) {
			throw new \InvalidArgumentException('No master VEVENT');
		}

		foreach (array('summary' => 'SUMMARY', 'location' => 'LOCATION', 'description' => 'DESCRIPTION') as $sKey => $sName) {
			if (isset($aFields[$sKey])) {
				$sValue = (string) $aFields[$sKey];
				unset($oMaster->{$sName});
				\strlen($sValue) && $oMaster->add($sName, $sValue);
			}
		}

		$bAllDay = !empty($aFields['allDay']);
		$oOldStart = isset($oMaster->DTSTART) ? $oMaster->DTSTART->getDateTime() : null;
		$bWasAllDay = isset($oMaster->DTSTART) && !$oMaster->DTSTART->hasTime();
		$sOldRule = isset($oMaster->RRULE) ? (string) $oMaster->RRULE : '';

		// A date has no zone, and read in a zone west of Greenwich, UTC midnight
		// is the evening before. A timed event keeps the zone it was written in.
		if ($bAllDay) {
			$oZone = new \DateTimeZone('UTC');
		} else if ($oOldStart && !$bWasAllDay && 'UTC' !== $oOldStart->getTimezone()->getName()) {
			$oZone = $oOldStart->getTimezone();
		} else {
			$oZone = $oTimeZone;
		}
		$oStart = (new \DateTimeImmutable('@' . (int) $aFields['start']))->setTimezone($oZone);
		$oEnd = (new \DateTimeImmutable('@' . (int) $aFields['end']))->setTimezone($oZone);

		unset($oMaster->DTSTART, $oMaster->DTEND, $oMaster->DURATION);
		if ($bAllDay) {
			$oMaster->add('DTSTART', $oStart, array('VALUE' => 'DATE'));
			$oMaster->add('DTEND', $oEnd, array('VALUE' => 'DATE'));
		} else {
			$oMaster->add('DTSTART', $oStart);
			$oMaster->add('DTEND', $oEnd);
		}

		$sRule = $sOldRule;
		if (isset($aFields['rrule'])) {
			$sRule = \strlen($aFields['rrule']) ? static::validRrule($aFields['rrule'], $oStart) : '';
			unset($oMaster->RRULE);
			\strlen($sRule) && $oMaster->add('RRULE', $sRule);
		}

		if (!\strlen($sRule)) {
			// Not a series any more: its exceptions and changed dates go with it
			unset($oMaster->EXDATE, $oMaster->RDATE);
			static::removeOverrides($oVCalendar, fn () => true);
		} else if ($bAllDay !== $bWasAllDay) {
			// The exceptions name dates, or moments, in the old form
			unset($oMaster->EXDATE);
			static::removeOverrides($oVCalendar, fn () => true);
		} else {
			if ($oOldStart && $oOldStart->getTimestamp() !== $oStart->getTimestamp()) {
				static::shiftExceptions($oVCalendar, $oMaster, $oOldStart->setTimezone($oZone), $oStart);
			}
			static::pruneExceptions($oVCalendar, $oMaster, $oStart, $bAllDay);
		}

		$oNow = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$bReschedule = !$oOldStart || $oOldStart->getTimestamp() !== $oStart->getTimestamp()
			|| $bWasAllDay !== $bAllDay || $sOldRule !== $sRule;
		if ($bReschedule && $oOldStart) {
			// RFC 5545 3.8.7.4: a change of time or rule is a new revision
			$oMaster->SEQUENCE = (isset($oMaster->SEQUENCE) ? (int) $oMaster->SEQUENCE->getValue() : 0) + 1;
		}
		$oMaster->DTSTAMP = $oNow;
		$oMaster->{'LAST-MODIFIED'} = $oNow;
	}

	private static function removeOverrides(VCalendar $oVCalendar, callable $fWhich) : void
	{
		foreach ($oVCalendar->select('VEVENT') as $oVEvent) {
			if (isset($oVEvent->{'RECURRENCE-ID'}) && $fWhich($oVEvent)) {
				$oVCalendar->remove($oVEvent);
			}
		}
	}

	/**
	 * Moving the series moves its exceptions with it: an override or EXDATE
	 * names an occurrence by its original start, and those starts all moved.
	 * Moved by the same number of days and the same change of clock time, in
	 * each value's own zone, so a move across a DST change keeps 09:00 at 09:00.
	 */
	private static function shiftExceptions(VCalendar $oVCalendar, VEvent $oMaster,
		\DateTimeImmutable $oOld, \DateTimeImmutable $oNew) : void
	{
		$iDays = (int) (new \DateTimeImmutable($oOld->format('Y-m-d'), new \DateTimeZone('UTC')))
			->diff(new \DateTimeImmutable($oNew->format('Y-m-d'), new \DateTimeZone('UTC')))->format('%r%a');
		$fClock = fn (\DateTimeImmutable $o) => 3600 * (int) $o->format('G') + 60 * (int) $o->format('i') + (int) $o->format('s');
		$iSeconds = $fClock($oNew) - $fClock($oOld);
		$fShift = fn (\DateTimeImmutable $o) => $o->modify("{$iDays} days")->modify("{$iSeconds} seconds");

		foreach ($oVCalendar->select('VEVENT') as $oVEvent) {
			if (isset($oVEvent->{'RECURRENCE-ID'})) {
				$oProperty = $oVEvent->{'RECURRENCE-ID'};
				$oProperty->setDateTime($fShift($oProperty->getDateTime()), $oProperty->isFloating());
			}
		}
		foreach ($oMaster->select('EXDATE') as $oExdate) {
			$oExdate->setDateTimes(\array_map($fShift, $oExdate->getDateTimes()), $oExdate->isFloating());
		}
	}

	/**
	 * An override or EXDATE that is not an occurrence of the rule as it now
	 * stands names nothing. Sabre would still show such an override, as an
	 * event of its own, so it goes.
	 */
	private static function pruneExceptions(VCalendar $oVCalendar, VEvent $oMaster,
		\DateTimeImmutable $oStart, bool $bAllDay) : void
	{
		$aRdates = array();
		foreach ($oMaster->select('RDATE') as $oRdate) {
			foreach ($oRdate->getDateTimes() as $oDate) {
				$aRdates[] = $oDate->getTimestamp();
			}
		}
		$sRule = (string) $oMaster->RRULE;
		$fIsOccurrence = function (\DateTimeInterface $oDate) use ($sRule, $oStart, $aRdates, $bAllDay) : bool {
			if (\in_array($oDate->getTimestamp(), $aRdates, true)) {
				return true;
			}
			try {
				$oIterator = new RRuleIterator($sRule, $oStart);
				$oIterator->fastForward($oDate);
				$oCurrent = $oIterator->valid() ? $oIterator->current() : null;
			} catch (\Throwable $oException) {
				return false;
			}
			return $oCurrent && ($bAllDay
				? $oCurrent->format('Ymd') === $oDate->format('Ymd')
				: $oCurrent->getTimestamp() === $oDate->getTimestamp());
		};

		static::removeOverrides($oVCalendar,
			fn (VEvent $oVEvent) => !$fIsOccurrence($oVEvent->{'RECURRENCE-ID'}->getDateTime()));

		foreach ($oMaster->select('EXDATE') as $oExdate) {
			$aKeep = \array_values(\array_filter($oExdate->getDateTimes(), $fIsOccurrence));
			if ($aKeep) {
				$oExdate->setDateTimes($aKeep, $oExdate->isFloating());
			} else {
				$oMaster->remove($oExdate);
			}
		}
	}
}
