<?php

// Run with: php test/calendar-event-editor.php
//
// Providers\Calendar\EventEditor: what the calendar's event editor sends,
// applied to a stored event in place. Saving used to rebuild the VEVENT from
// the form, which dropped everything the form does not show (attendees,
// alarms, the RRULE and exceptions of a series), and a drag, which sends only
// the times, wiped the description and location.
define('APP_VERSION', 'test');
spl_autoload_register(static function (string $class): void {
	$base = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/';
	$file = str_starts_with($class, 'Tachyon\\Util\\')
		? $base.'tachyon_util/'.strtolower(str_replace('\\', '/', substr($class, 13))).'.php'
		: $base.str_replace('\\', '/', $class).'.php';
	if (is_file($file)) {
		require_once $file;
	}
});

use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
use Tachyon\Providers\Calendar\EventEditor;

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

const BERLIN = 'Europe/Berlin';

function at(string $local, string $zone = BERLIN): int
{
	return (new DateTimeImmutable($local, new DateTimeZone($zone)))->getTimestamp();
}

/** Occurrence starts, as local "m-d H:i", from a date on. */
function occurrences($vcal, string $from = '2026-10-01', int $max = 8): array
{
	$it = new EventIterator($vcal, (string) EventEditor::master($vcal)->UID, new DateTimeZone('UTC'));
	$it->fastForward(new DateTimeImmutable($from));
	$out = [];
	while ($it->valid() && count($out) < $max) {
		$out[] = $it->getDtStart()->setTimezone(new DateTimeZone(BERLIN))->format('m-d H:i');
		$it->next();
	}
	return $out;
}

function apply($vcal, array $fields, string $zone = BERLIN): void
{
	EventEditor::apply($vcal, $fields, new DateTimeZone($zone));
}

$single = static fn () => Reader::read(implode("\r\n", [
	'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//t//t//EN',
	'BEGIN:VEVENT', 'UID:one', 'DTSTAMP:20261001T000000Z', 'SEQUENCE:2',
	'DTSTART;TZID=Europe/Berlin:20261110T090000', 'DTEND;TZID=Europe/Berlin:20261110T100000',
	'SUMMARY:Review', 'LOCATION:Room 4', 'DESCRIPTION:Bring the numbers',
	'CATEGORIES:Work', 'ORGANIZER:mailto:o@example.com', 'ATTENDEE;PARTSTAT=ACCEPTED:mailto:a@example.com',
	'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER:-PT15M', 'DESCRIPTION:Soon', 'END:VALARM',
	'END:VEVENT', 'END:VCALENDAR', '']));

// 1. A save from the editor keeps what the editor does not show
$vcal = $single();
apply($vcal, ['summary' => 'Review v2', 'location' => 'Room 4', 'description' => 'Bring the numbers',
	'start' => at('2026-11-10 09:00'), 'end' => at('2026-11-10 10:00'), 'allDay' => false]);
$ev = $vcal->VEVENT;
check('Review v2' === (string) $ev->SUMMARY, 'summary not changed');
check(isset($ev->ATTENDEE, $ev->ORGANIZER, $ev->VALARM, $ev->CATEGORIES), 'attendee, organizer, alarm or categories lost');
check(2 === (int) $ev->SEQUENCE->getValue(), 'SEQUENCE raised for a change of title alone');
check('Europe/Berlin' === (string) $ev->DTSTART['TZID'], 'zone not kept');

// 2. A drag sends the times only: description and location stay
$vcal = $single();
apply($vcal, ['summary' => 'Review', 'start' => at('2026-11-11 14:00'), 'end' => at('2026-11-11 15:00'), 'allDay' => false]);
$ev = $vcal->VEVENT;
check('Room 4' === (string) $ev->LOCATION && 'Bring the numbers' === (string) $ev->DESCRIPTION, 'a drag wiped location or description');
check('20261111T140000' === $ev->DTSTART->getValue(), 'drag did not move the start');
check(3 === (int) $ev->SEQUENCE->getValue(), 'SEQUENCE not raised for a move');

// 3. An emptied field is removed
$vcal = $single();
apply($vcal, ['location' => '', 'start' => at('2026-11-10 09:00'), 'end' => at('2026-11-10 10:00'), 'allDay' => false]);
check(!isset($vcal->VEVENT->LOCATION), 'emptied location kept');

// 4. Rules: what the editor may write
check('FREQ=WEEKLY;BYDAY=MO,WE' === EventEditor::validRrule('FREQ=WEEKLY;BYDAY=MO,WE', new DateTimeImmutable('2026-11-09')), 'a plain weekly rule refused');
foreach (['FREQ=HOURLY', 'FREQ=MINUTELY;COUNT=5', 'nonsense', 'FREQ=WEEKLY;BYDAY=XX', ''] as $bad) {
	try {
		EventEditor::validRrule($bad, new DateTimeImmutable('2026-11-09'));
		check(false, "rule '$bad' accepted");
	} catch (InvalidArgumentException $e) {
	}
}
$vcal = $single();
apply($vcal, ['start' => at('2026-11-10 09:00'), 'end' => at('2026-11-10 10:00'), 'allDay' => false, 'rrule' => 'FREQ=DAILY;COUNT=3']);
check(['11-10 09:00', '11-11 09:00', '11-12 09:00'] === occurrences($vcal), 'a single event did not become a series');

// A weekly series on Mondays 09:00 Berlin, across the end of summer time on
// 25 October: the 26 Oct occurrence moved to 11:00, 2 Nov skipped.
$series = static fn () => Reader::read(implode("\r\n", [
	'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//t//t//EN',
	'BEGIN:VEVENT', 'UID:s1', 'DTSTAMP:20261001T000000Z',
	'DTSTART;TZID=Europe/Berlin:20261019T090000', 'DTEND;TZID=Europe/Berlin:20261019T093000',
	'RRULE:FREQ=WEEKLY;BYDAY=MO', 'EXDATE;TZID=Europe/Berlin:20261102T090000', 'SUMMARY:Standup',
	'ATTENDEE:mailto:a@example.com',
	'END:VEVENT',
	'BEGIN:VEVENT', 'UID:s1', 'DTSTAMP:20261001T000000Z', 'RECURRENCE-ID;TZID=Europe/Berlin:20261026T090000',
	'DTSTART;TZID=Europe/Berlin:20261026T110000', 'DTEND;TZID=Europe/Berlin:20261026T113000', 'SUMMARY:Standup, later',
	'END:VEVENT', 'END:VCALENDAR', '']));
check(['10-19 09:00', '10-26 11:00', '11-09 09:00', '11-16 09:00'] === array_slice(occurrences($series()), 0, 4), 'fixture series reads wrong');

// 5. Editing the title of a series keeps its rule and exceptions (no Rrule sent)
$vcal = $series();
apply($vcal, ['summary' => 'Daily standup', 'start' => at('2026-10-19 09:00'), 'end' => at('2026-10-19 09:30'), 'allDay' => false]);
check(['10-19 09:00', '10-26 11:00', '11-09 09:00', '11-16 09:00'] === array_slice(occurrences($vcal), 0, 4), 'a title edit changed the series');
check(2 === count($vcal->VEVENT) && isset(EventEditor::master($vcal)->ATTENDEE), 'override or attendee lost');

// 6. Moving the series a day and an hour later moves its exceptions along:
//    Tuesdays 10:00, the 27 Oct one still at its own 11:00, 3 Nov skipped,
//    and 10:00 stays 10:00 on both sides of the DST change
$vcal = $series();
apply($vcal, ['start' => at('2026-10-20 10:00'), 'end' => at('2026-10-20 10:30'), 'allDay' => false, 'rrule' => 'FREQ=WEEKLY;BYDAY=TU']);
check(['10-20 10:00', '10-26 11:00', '11-10 10:00', '11-17 10:00'] === array_slice(occurrences($vcal), 0, 4),
	'exceptions did not move with the series: '.implode(', ', occurrences($vcal)));
check('20261027T100000' === $vcal->VEVENT[1]->{'RECURRENCE-ID'}->getValue(), 'override RECURRENCE-ID not shifted');
check('20261103T100000' === EventEditor::master($vcal)->EXDATE->getValue(), 'EXDATE not shifted');

// 7. A rule that no longer has the excepted dates drops those exceptions:
//    the start stays on a Monday but the rule says Wednesdays
$vcal = $series();
apply($vcal, ['start' => at('2026-10-19 09:00'), 'end' => at('2026-10-19 09:30'), 'allDay' => false, 'rrule' => 'FREQ=WEEKLY;BYDAY=WE']);
check(1 === count($vcal->VEVENT) && !isset(EventEditor::master($vcal)->EXDATE), 'stale override or EXDATE kept');
// ... and a rule that still has them keeps them
$vcal = $series();
apply($vcal, ['start' => at('2026-10-19 09:00'), 'end' => at('2026-10-19 09:30'), 'allDay' => false, 'rrule' => 'FREQ=DAILY']);
check(2 === count($vcal->VEVENT) && isset(EventEditor::master($vcal)->EXDATE), 'a still valid exception dropped');

// 8. Ending the series drops its exceptions
$vcal = $series();
apply($vcal, ['start' => at('2026-10-19 09:00'), 'end' => at('2026-10-19 09:30'), 'allDay' => false, 'rrule' => '']);
$master = EventEditor::master($vcal);
check(1 === count($vcal->VEVENT) && !isset($master->RRULE) && !isset($master->EXDATE), 'series not ended cleanly');

// 9. Turning a timed series all-day drops exceptions written as times
$vcal = $series();
apply($vcal, ['start' => at('2026-10-19 00:00', 'UTC'), 'end' => at('2026-10-20 00:00', 'UTC'), 'allDay' => true]);
$master = EventEditor::master($vcal);
check(1 === count($vcal->VEVENT) && !isset($master->EXDATE) && isset($master->RRULE), 'all-day switch kept timed exceptions or lost the rule');
check('20261019' === $master->DTSTART->getValue() && 'DATE' === (string) $master->DTSTART['VALUE'], 'all-day start not a date');

// 10. An all-day date is the same date west of Greenwich: UTC midnight read in
//     New York is the evening before
$vcal = $single();
apply($vcal, ['start' => at('2026-11-10 00:00', 'UTC'), 'end' => at('2026-11-11 00:00', 'UTC'), 'allDay' => true], 'America/New_York');
check('20261110' === $vcal->VEVENT->DTSTART->getValue() && '20261111' === $vcal->VEVENT->DTEND->getValue(),
	'all-day date shifted: '.$vcal->VEVENT->DTSTART->getValue());

// 11. A new event: an empty VEVENT filled in
$vcal = new Sabre\VObject\Component\VCalendar();
$vcal->add('VEVENT', ['UID' => 'new']);
apply($vcal, ['summary' => 'Lunch', 'location' => '', 'description' => '', 'start' => at('2026-11-12 12:00'), 'end' => at('2026-11-12 13:00'),
	'allDay' => false, 'rrule' => 'FREQ=WEEKLY;INTERVAL=2;UNTIL=20261231T225959Z']);
$ev = $vcal->VEVENT;
check(isset($ev->DTSTAMP) && !isset($ev->SEQUENCE) && !isset($ev->LOCATION), 'new event built wrong');
check(['11-12 12:00', '11-26 12:00', '12-10 12:00', '12-24 12:00'] === occurrences($vcal), 'fortnightly rule with UNTIL wrong');
check(1 === preg_match('/^DTSTART;TZID=Europe\/Berlin:20261112T120000\r$/m', $vcal->serialize()), 'new event not in the calendar zone');

echo "calendar-event-editor: ok\n";
