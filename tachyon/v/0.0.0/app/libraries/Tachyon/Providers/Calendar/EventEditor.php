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

		// A changed occurrence copied the series' title, place and description
		// when it was made; where it still has them, it follows the series
		$aOld = array();
		foreach (array('SUMMARY', 'LOCATION', 'DESCRIPTION') as $sName) {
			$aOld[$sName] = isset($oMaster->{$sName}) ? (string) $oMaster->{$sName} : '';
		}
		static::setText($oMaster, $aFields);
		foreach ($oVCalendar->select('VEVENT') as $oVEvent) {
			if (isset($oVEvent->{'RECURRENCE-ID'})) {
				$aSame = array();
				foreach (array('summary' => 'SUMMARY', 'location' => 'LOCATION', 'description' => 'DESCRIPTION') as $sKey => $sName) {
					$sValue = isset($oVEvent->{$sName}) ? (string) $oVEvent->{$sName} : '';
					if (isset($aFields[$sKey]) && $sValue === $aOld[$sName]) {
						$aSame[$sKey] = $aFields[$sKey];
					}
				}
				$aSame && static::setText($oVEvent, $aSame);
			}
		}

		$bAllDay = !empty($aFields['allDay']);
		$oOldStart = isset($oMaster->DTSTART) ? $oMaster->DTSTART->getDateTime() : null;
		$bWasAllDay = isset($oMaster->DTSTART) && !$oMaster->DTSTART->hasTime();
		$sOldRule = isset($oMaster->RRULE) ? (string) $oMaster->RRULE : '';

		[$oStart, $oZone] = static::setTimes($oMaster, $aFields, $oTimeZone);

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
			static::pruneExceptions($oVCalendar, $oMaster);
		}

		$bReschedule = !$oOldStart || $oOldStart->getTimestamp() !== $oStart->getTimestamp()
			|| $bWasAllDay !== $bAllDay || $sOldRule !== $sRule;
		static::touch($oMaster, $bReschedule && $oOldStart);
	}

	/**
	 * Change one occurrence of a series on its own: an override VEVENT,
	 * named by the occurrence's original start ($iRecurrenceId), made from the
	 * series the first time and edited in place after that.
	 *
	 * @param array $aFields as for apply(), without rrule
	 * @throws \InvalidArgumentException
	 */
	public static function applyOccurrence(VCalendar $oVCalendar, int $iRecurrenceId, array $aFields,
		\DateTimeZone $oTimeZone) : void
	{
		$oOverride = static::ensureOverride($oVCalendar, $iRecurrenceId);

		static::setText($oOverride, $aFields);
		$iOldStart = isset($oOverride->DTSTART) ? $oOverride->DTSTART->getDateTime()->getTimestamp() : 0;
		$iOldEnd = isset($oOverride->DTEND) ? $oOverride->DTEND->getDateTime()->getTimestamp() : 0;
		static::setTimes($oOverride, $aFields, $oTimeZone);
		static::touch($oOverride, $iOldStart !== (int) $aFields['start'] || $iOldEnd !== (int) $aFields['end']);
	}

	/**
	 * The override for the occurrence originally at $iRecurrenceId, made from
	 * the series, at that occurrence's time, when it has none yet.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function ensureOverride(VCalendar $oVCalendar, int $iRecurrenceId) : VEvent
	{
		$oMaster = static::seriesMaster($oVCalendar);
		$oOverride = static::occurrence($oVCalendar, $oMaster, $iRecurrenceId);
		if (!$oOverride) {
			$oRecurrenceId = static::recurrenceDate($oMaster, $iRecurrenceId);
			$oDuration = $oMaster->DTSTART->getDateTime()->diff(static::endOf($oMaster));
			$aDate = $oMaster->DTSTART->hasTime() ? array() : array('VALUE' => 'DATE');
			$oOverride = clone $oMaster;
			unset($oOverride->RRULE, $oOverride->RDATE, $oOverride->EXDATE, $oOverride->EXRULE, $oOverride->SEQUENCE,
				$oOverride->DTSTART, $oOverride->DTEND, $oOverride->DURATION);
			$oOverride->add('DTSTART', $oRecurrenceId, $aDate);
			$oOverride->add('DTEND', $oRecurrenceId->add($oDuration), $aDate);
			$oOverride->add('RECURRENCE-ID', $oRecurrenceId, $aDate);
			$oVCalendar->add($oOverride);
		}
		return $oOverride;
	}

	/**
	 * Take one occurrence out of a series: an EXDATE for its original start,
	 * and its override, if it had one, goes.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function excludeOccurrence(VCalendar $oVCalendar, int $iRecurrenceId) : void
	{
		$oMaster = static::seriesMaster($oVCalendar);
		$oOverride = static::occurrence($oVCalendar, $oMaster, $iRecurrenceId);
		$oRecurrenceId = static::recurrenceDate($oMaster, $iRecurrenceId);
		$oOverride && $oVCalendar->remove($oOverride);
		$oMaster->add('EXDATE', $oRecurrenceId, $oMaster->DTSTART->hasTime() ? array() : array('VALUE' => 'DATE'));
		static::touch($oMaster, true);
	}

	/**
	 * "This and following": end the series before the occurrence originally at
	 * $iRecurrenceId, and return a new series, under $sNewUid, that starts with
	 * it. Changed and skipped dates go with the part they fall in. The new
	 * series starts at the occurrence's original time and keeps the rule, with
	 * a COUNT reduced by the occurrences left behind, so applying the editor's
	 * fields to it with apply() moves its exceptions as any series move does.
	 *
	 * Null for the first occurrence: that is the whole series, left untouched.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function splitAt(VCalendar $oVCalendar, int $iRecurrenceId, string $sNewUid) : ?VCalendar
	{
		$oMaster = static::seriesMaster($oVCalendar);
		static::occurrence($oVCalendar, $oMaster, $iRecurrenceId);
		$bAllDay = !$oMaster->DTSTART->hasTime();
		$oSplit = static::recurrenceDate($oMaster, $iRecurrenceId);
		$oStart = $oMaster->DTSTART->getDateTime($bAllDay ? new \DateTimeZone('UTC') : null);
		$fBefore = fn (\DateTimeInterface $oDate) => $bAllDay
			? $oDate->format('Ymd') < $oSplit->format('Ymd')
			: $oDate->getTimestamp() < $oSplit->getTimestamp();
		if (!$fBefore($oStart)) {
			return null;
		}

		$aParts = isset($oMaster->RRULE) ? $oMaster->RRULE->getParts() : array();
		$iBefore = 0;
		if (isset($aParts['COUNT'])) {
			$oIterator = new RRuleIterator($aParts, $oStart);
			while ($oIterator->valid() && $fBefore($oIterator->current())) {
				++$iBefore;
				$oIterator->next();
			}
		}

		// The new series: what is at or after the split
		$oNew = clone $oVCalendar;
		$oNewMaster = static::master($oNew);
		foreach ($oNew->select('VEVENT') as $oVEvent) {
			$oVEvent->UID = $sNewUid;
		}
		static::removeOverrides($oNew, fn (VEvent $oVEvent) => $fBefore($oVEvent->{'RECURRENCE-ID'}->getDateTime(new \DateTimeZone('UTC'))));
		static::filterDates($oNewMaster, fn (\DateTimeInterface $oDate) => !$fBefore($oDate));
		$oDuration = $oMaster->DTSTART->getDateTime()->diff(static::endOf($oMaster));
		unset($oNewMaster->DTSTART, $oNewMaster->DTEND, $oNewMaster->DURATION, $oNewMaster->SEQUENCE);
		$aDate = $bAllDay ? array('VALUE' => 'DATE') : array();
		$oNewMaster->add('DTSTART', $oSplit, $aDate);
		$oNewMaster->add('DTEND', $oSplit->add($oDuration), $aDate);
		if (isset($aParts['COUNT'])) {
			$aNewParts = $aParts;
			$aNewParts['COUNT'] = \max(1, (int) $aParts['COUNT'] - $iBefore);
			$oNewMaster->RRULE->setParts($aNewParts);
		}

		// The old series: what is before it, ending the moment before
		static::removeOverrides($oVCalendar, fn (VEvent $oVEvent) => !$fBefore($oVEvent->{'RECURRENCE-ID'}->getDateTime(new \DateTimeZone('UTC'))));
		static::filterDates($oMaster, $fBefore);
		if (isset($oMaster->RRULE)) {
			unset($aParts['COUNT']);
			$aParts['UNTIL'] = $bAllDay
				? $oSplit->modify('-1 day')->format('Ymd')
				: $oSplit->modify('-1 second')->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z');
			$oMaster->RRULE->setParts($aParts);
		}
		static::touch($oMaster, true);

		return $oNew;
	}

	/**
	 * Keep only the EXDATE and RDATE values $fKeep accepts.
	 */
	private static function filterDates(VEvent $oVEvent, callable $fKeep) : void
	{
		foreach (array('EXDATE', 'RDATE') as $sName) {
			foreach ($oVEvent->select($sName) as $oProperty) {
				$aKeep = \array_values(\array_filter($oProperty->getDateTimes(new \DateTimeZone('UTC')), $fKeep));
				if ($aKeep) {
					$oProperty->setDateTimes(\array_map(
						fn ($oDate) => $oDate->setTimezone($oProperty->getDateTime()->getTimezone()), $aKeep), $oProperty->isFloating());
				} else {
					$oVEvent->remove($oProperty);
				}
			}
		}
	}

	private static function endOf(VEvent $oVEvent) : \DateTimeImmutable
	{
		$oStart = $oVEvent->DTSTART->getDateTime();
		if (isset($oVEvent->DTEND)) {
			return $oVEvent->DTEND->getDateTime();
		}
		if (isset($oVEvent->DURATION)) {
			return $oStart->add($oVEvent->DURATION->getDateInterval());
		}
		return $oVEvent->DTSTART->hasTime() ? $oStart : $oStart->modify('+1 day');
	}

	private static function seriesMaster(VCalendar $oVCalendar) : VEvent
	{
		$oMaster = static::master($oVCalendar);
		if (!$oMaster || !isset($oMaster->DTSTART) || (!isset($oMaster->RRULE) && !isset($oMaster->RDATE))) {
			throw new \InvalidArgumentException('Not a repeating event');
		}
		return $oMaster;
	}

	/**
	 * The override for the occurrence originally at $iRecurrenceId, or null when
	 * it has none yet. Throws when the series has no such occurrence.
	 */
	private static function occurrence(VCalendar $oVCalendar, VEvent $oMaster, int $iRecurrenceId) : ?VEvent
	{
		$oRecurrenceId = static::recurrenceDate($oMaster, $iRecurrenceId);
		$bAllDay = !$oMaster->DTSTART->hasTime();
		foreach ($oVCalendar->select('VEVENT') as $oVEvent) {
			if (isset($oVEvent->{'RECURRENCE-ID'})) {
				$oDate = $oVEvent->{'RECURRENCE-ID'}->getDateTime(new \DateTimeZone('UTC'));
				if ($bAllDay ? $oDate->format('Ymd') === $oRecurrenceId->format('Ymd')
					: $oDate->getTimestamp() === $oRecurrenceId->getTimestamp()) {
					return $oVEvent;
				}
			}
		}
		if (!static::isOccurrence($oMaster, $oRecurrenceId)) {
			throw new \InvalidArgumentException('Not an occurrence of this event');
		}
		return null;
	}

	/**
	 * An occurrence's original start, in the form the series' DTSTART has:
	 * a date for an all-day series, else a time in the series' zone.
	 */
	private static function recurrenceDate(VEvent $oMaster, int $iTimestamp) : \DateTimeImmutable
	{
		$oDate = new \DateTimeImmutable('@' . $iTimestamp);
		if (!$oMaster->DTSTART->hasTime()) {
			$oUtc = $oDate->setTimezone(new \DateTimeZone('UTC'));
			return new \DateTimeImmutable($oUtc->format('Y-m-d'), new \DateTimeZone('UTC'));
		}
		return $oDate->setTimezone($oMaster->DTSTART->getDateTime()->getTimezone());
	}

	/**
	 * Whether the series' rule, or its RDATEs, put an occurrence at $oDate.
	 */
	public static function isOccurrence(VEvent $oMaster, \DateTimeInterface $oDate) : bool
	{
		$bAllDay = !$oMaster->DTSTART->hasTime();
		$fSame = fn (\DateTimeInterface $a) => $bAllDay
			? $a->format('Ymd') === $oDate->format('Ymd')
			: $a->getTimestamp() === $oDate->getTimestamp();
		foreach ($oMaster->select('RDATE') as $oRdate) {
			foreach ($oRdate->getDateTimes(new \DateTimeZone('UTC')) as $oRdateDate) {
				if ($fSame($oRdateDate)) {
					return true;
				}
			}
		}
		if (!isset($oMaster->RRULE)) {
			return false;
		}
		try {
			$oIterator = new RRuleIterator((string) $oMaster->RRULE,
				$oMaster->DTSTART->getDateTime($bAllDay ? new \DateTimeZone('UTC') : null));
			$oIterator->fastForward($bAllDay ? new \DateTimeImmutable($oDate->format('Y-m-d'), new \DateTimeZone('UTC')) : $oDate);
			return $oIterator->valid() && $fSame($oIterator->current());
		} catch (\Throwable $oException) {
			return false;
		}
	}

	/**
	 * SUMMARY, LOCATION and DESCRIPTION from the fields; null leaves one alone.
	 */
	private static function setText(VEvent $oVEvent, array $aFields) : void
	{
		foreach (array('summary' => 'SUMMARY', 'location' => 'LOCATION', 'description' => 'DESCRIPTION') as $sKey => $sName) {
			if (isset($aFields[$sKey])) {
				$sValue = (string) $aFields[$sKey];
				unset($oVEvent->{$sName});
				\strlen($sValue) && $oVEvent->add($sName, $sValue);
			}
		}
	}

	/**
	 * DTSTART and DTEND from the fields.
	 *
	 * @return array{0: \DateTimeImmutable, 1: \DateTimeZone} the start and the zone written in
	 */
	private static function setTimes(VEvent $oVEvent, array $aFields, \DateTimeZone $oTimeZone) : array
	{
		$bAllDay = !empty($aFields['allDay']);
		$oOldStart = isset($oVEvent->DTSTART) ? $oVEvent->DTSTART->getDateTime() : null;
		$bWasAllDay = isset($oVEvent->DTSTART) && !$oVEvent->DTSTART->hasTime();

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

		unset($oVEvent->DTSTART, $oVEvent->DTEND, $oVEvent->DURATION);
		if ($bAllDay) {
			$oVEvent->add('DTSTART', $oStart, array('VALUE' => 'DATE'));
			$oVEvent->add('DTEND', $oEnd, array('VALUE' => 'DATE'));
		} else {
			$oVEvent->add('DTSTART', $oStart);
			$oVEvent->add('DTEND', $oEnd);
		}
		return array($oStart, $oZone);
	}

	/**
	 * DTSTAMP and LAST-MODIFIED now, and SEQUENCE up for a change of time or
	 * rule (RFC 5545 3.8.7.4).
	 */
	private static function touch(VEvent $oVEvent, bool $bReschedule) : void
	{
		$oNow = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		if ($bReschedule) {
			$oVEvent->SEQUENCE = (isset($oVEvent->SEQUENCE) ? (int) $oVEvent->SEQUENCE->getValue() : 0) + 1;
		}
		$oVEvent->DTSTAMP = $oNow;
		$oVEvent->{'LAST-MODIFIED'} = $oNow;
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
	private static function pruneExceptions(VCalendar $oVCalendar, VEvent $oMaster) : void
	{
		$fIsOccurrence = fn (\DateTimeInterface $oDate) => static::isOccurrence($oMaster, $oDate);

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
