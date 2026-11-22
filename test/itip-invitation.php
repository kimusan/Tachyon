<?php

// Run with: php test/itip-invitation.php
//
// Providers\Calendar\Invitation against the real invitation mails in
// test/fixtures/itip/ (see the README there): what the message view is told
// about each one, and the REPLY that answers it. test/itip-reply.php covers
// Sabre's broker itself; this covers how Tachyon uses it.
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

use Tachyon\Providers\Calendar\Invitation;

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

/** The first text/calendar part of a fixture, decoded, the way the client downloads it. */
function ical(string $name): string
{
	$raw = file_get_contents(__DIR__.'/fixtures/itip/'.$name.'.eml');
	check(false !== $raw, "fixture $name missing");
	$parse = static function (string $raw) use (&$parse): ?string {
		[$head, $body] = array_pad(preg_split('/\r?\n\r?\n/', $raw, 2), 2, '');
		$head = preg_replace('/\r?\n[ \t]+/', ' ', $head);
		preg_match('/^Content-Type:\s*([^;\r\n]+)(.*)$/mi', $head, $type);
		$mime = strtolower(trim($type[1] ?? 'text/plain'));
		if (str_starts_with($mime, 'multipart/') && preg_match('/boundary="?([^";\r\n]+)"?/i', $type[2], $b)) {
			foreach (array_slice(preg_split('/(?:^|\r?\n)--'.preg_quote($b[1], '/').'(?:--)?[ \t]*(?:\r?\n|$)/', $body), 1, -1) as $chunk) {
				if (null !== ($found = $parse($chunk))) {
					return $found;
				}
			}
			return null;
		}
		if ('text/calendar' !== $mime) {
			return null;
		}
		preg_match('/^Content-Transfer-Encoding:\s*(\S+)/mi', $head, $enc);
		return match (strtolower($enc[1] ?? '')) {
			'base64' => base64_decode($body),
			'quoted-printable' => quoted_printable_decode($body),
			default => $body,
		};
	};
	$ical = $parse($raw);
	check(null !== $ical, "$name: no text/calendar part");
	return $ical;
}

const ME = ['attendee@example.com'];

// 1. Every first REQUEST can be answered, as attendee@, and each answer gives a
//    REPLY that serializes, to the organizer, with our PARTSTAT and the
//    invitation's UID and SEQUENCE.
$requests = ['google-request', 'google-series-request', 'google-series-update', 'outlook-request', 'outlook-update',
	'exchange-no-last-modified', 'exchange-mailto-uppercase', 'horde-request', 'nextcloud-request',
	'nextcloud35-request', 'nextcloud35-update'];
foreach ($requests as $name) {
	$invitation = Invitation::fromIcal(ical($name));
	check(null !== $invitation, "$name: not read");
	$info = $invitation->Describe(ME);
	check('REQUEST' === $info['Method'], "$name: method {$info['Method']}");
	check(true === $info['CanReply'], "$name: cannot be answered");
	check('attendee@example.com' === strtolower($info['Attendee']['Email']), "$name: wrong attendee");
	check('organizer@example.com' === strtolower($info['Organizer']['Email']), "$name: organizer {$info['Organizer']['Email']}");
	check(0 < $info['Start'] && $info['End'] >= $info['Start'], "$name: no time");
	check('' !== $info['Summary'], "$name: no summary");
	foreach (Invitation::PARTSTATS as $partstat) {
		$reply = $invitation->Reply('attendee@example.com', $partstat);
		check(null !== $reply, "$name/$partstat: no reply");
		// Long values, such as Exchange UIDs, are folded at 75 octets
		$text = str_replace("\r\n ", "", $reply->serialize());
		check(str_contains($text, "METHOD:REPLY\r\n"), "$name/$partstat: not METHOD:REPLY");
		check(1 === preg_match('/^ATTENDEE;[^\r\n]*PARTSTAT='.$partstat.'[;:]/m', $text), "$name/$partstat: PARTSTAT missing");
		check(1 === substr_count($text, 'ATTENDEE'), "$name/$partstat: a reply carries only our ATTENDEE");
		check(1 === preg_match('/^UID:'.preg_quote($info['Uid'], '/').'\r$/m', $text), "$name/$partstat: UID not kept");
		check(1 === preg_match('/^SEQUENCE:'.$info['Sequence'].'\r$/m', $text), "$name/$partstat: SEQUENCE not {$info['Sequence']}");
		check(!str_contains($text, 'RSVP='), "$name/$partstat: RSVP left in the reply");
		check(str_contains($text, "PRODID:-//Tachyon//Tachyon test//EN\r\n"), "$name/$partstat: not our PRODID");
	}
}

// 2. Horde sends no SEQUENCE: the reply says SEQUENCE:0 rather than failing
//    to serialize (Sabre adds an empty SEQUENCE)
$horde = Invitation::fromIcal(ical('horde-request'));
check(0 === $horde->Describe(ME)['Sequence'], 'horde: SEQUENCE not 0');
check(str_contains($horde->Reply('attendee@example.com', 'ACCEPTED')->serialize(), "SEQUENCE:0\r\n"), 'horde: no SEQUENCE:0');

// 3. An update that already lists us as ACCEPTED can still be answered
$google = ical('google-request');
$accepted = preg_replace('/(ATTENDEE;[^\r\n]*?)PARTSTAT=NEEDS-ACTION([^\r\n]*?mailto:attendee@example\.com)/', '$1PARTSTAT=ACCEPTED$2', str_replace("\r\n ", '', $google), 1, $count);
check(1 === $count, 'test setup: no attendee line to change');
check(null !== Invitation::fromIcal($accepted)->Reply('attendee@example.com', 'ACCEPTED'), 'an answered update cannot be answered again');

// 4. Identities: the address matches in any case and through MAILTO:, the
//    first identity in the user's order wins, and someone else's invitation
//    offers no answer
check('Attendee@Example.com' === Invitation::fromIcal(ical('exchange-mailto-uppercase'))->Describe(['Attendee@Example.com'])['Attendee']['Email'],
	'mixed-case identity did not match');
$both = Invitation::fromIcal(ical('google-series-request'));
check('attendee2@example.com' === $both->Describe(['attendee2@example.com', 'attendee@example.com'])['Attendee']['Email'], 'identity order ignored');
$info = Invitation::fromIcal(ical('google-request'))->Describe(['nobody@example.com']);
check(null === $info['Attendee'] && false === $info['CanReply'], 'an invitation to someone else can be answered');

// 5. iCloud: the attendee's address is only in EMAIL=, the value a principal path
$icloud = Invitation::fromIcal(ical('icloud-email-param'));
$info = $icloud->Describe(['attendee4@example.com']);
check(true === $info['CanReply'] && 'attendee4@example.com' === $info['Attendee']['Email'], 'iCloud: EMAIL= not read');
check('organizer@example.com' === strtolower($info['Organizer']['Email']), 'iCloud: the reply must go to the ORGANIZER value, not its EMAIL=');
check(null !== $icloud->Reply('attendee4@example.com', 'DECLINED'), 'iCloud: no reply');

// 6. Cancellations and mails without an organizer cannot be answered
foreach (['google-cancel', 'outlook-cancel', 'nextcloud-cancel', 'nextcloud35-cancel'] as $name) {
	$info = Invitation::fromIcal(ical($name))->Describe(ME);
	check('CANCEL' === $info['Method'] && true === $info['Cancelled'] && false === $info['CanReply'], "$name: a cancel can be answered");
}
$info = Invitation::fromIcal(ical('no-organizer'))->Describe(ME);
check(false === $info['CanReply'] && null === $info['Organizer'], 'no-organizer: can be answered');

// 7. The organizer's own copy (a sent invitation) offers no answer
check(false === Invitation::fromIcal(ical('google-request'))->Describe(['organizer@example.com', 'attendee@example.com'])['CanReply'],
	'the organizer can answer their own invitation');

// 8. Junk is refused, and an unknown answer gives no reply
check(null === Invitation::fromIcal(''), 'empty ical read');
check(null === Invitation::fromIcal("BEGIN:VCARD\r\nVERSION:4.0\r\nFN:x\r\nEND:VCARD\r\n"), 'a vCard read as an invitation');
check(null === Invitation::fromIcal(str_repeat('x', Invitation::MAX_BYTES + 1)), 'an oversized body read');
check(null === Invitation::fromIcal(ical('google-request'))->Reply('attendee@example.com', 'NEEDS-ACTION'), 'NEEDS-ACTION sent as an answer');
check(null === Invitation::fromIcal(ical('google-request'))->Reply('nobody@example.com', 'ACCEPTED'), 'a reply for someone not invited');

// 9. The copy kept in the calendar after answering: no METHOD, our answer,
//    and SCHEDULE-AGENT=CLIENT on the organizer so a CalDAV server that
//    schedules (Sabre, as in Nextcloud) does not send a second REPLY
foreach (['google-request', 'outlook-request', 'icloud-email-param', 'horde-request'] as $name) {
	$me = 'icloud-email-param' === $name ? 'attendee4@example.com' : 'attendee@example.com';
	$invitation = Invitation::fromIcal(ical($name));
	$copy = $invitation->StoredCopy(null, $me, 'ACCEPTED');
	check(null !== $copy && !isset($copy->METHOD), "$name: stored copy has a METHOD");
	check('ACCEPTED' === Invitation::fromVCalendar($copy)->Partstat([$me]), "$name: answer not in the stored copy");
	check('CLIENT' === (string) $copy->VEVENT->ORGANIZER['SCHEDULE-AGENT'], "$name: no SCHEDULE-AGENT=CLIENT");
	$copy->serialize();

	// What the CalDAV server's broker makes of the change from the invitation
	// as received to our answered copy
	$before = $invitation->StoredCopy(null, $me, 'NEEDS-ACTION');
	$href = (string) Invitation::fromVCalendar($copy)->FindAttendee([$me])[0]->getNormalizedValue();
	check([] === (new Sabre\VObject\ITip\Broker)->parseEvent(clone $copy, $href, $before),
		"$name: the server would send its own REPLY");
	// ... and without the parameter it would, which is the second reply prevented
	foreach ([$copy, $before] as $c) {
		foreach ($c->VEVENT as $e) {
			unset($e->ORGANIZER['SCHEDULE-AGENT']);
		}
	}
	check(1 === count((new Sabre\VObject\ITip\Broker)->parseEvent(clone $copy, $href, $before)),
		"$name: control failed, the server would not reply anyway");
}

// 10. Changed dates of a series: merged into the stored series; a cancel or
//     a decline of one date leaves the series without it
$series = Invitation::fromIcal("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\nUID:s1\r\nDTSTAMP:20261101T000000Z\r\n"
	."DTSTART:20261102T090000Z\r\nDTEND:20261102T093000Z\r\nRRULE:FREQ=WEEKLY\r\nSUMMARY:Standup\r\nORGANIZER:mailto:organizer@example.com\r\n"
	."ATTENDEE;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:attendee@example.com\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
$stored = $series->StoredCopy(null, 'attendee@example.com', 'ACCEPTED');
$one = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:%s\r\nBEGIN:VEVENT\r\nUID:s1\r\nDTSTAMP:20261101T000000Z\r\nSEQUENCE:1\r\n"
	."RECURRENCE-ID:20261109T090000Z\r\nDTSTART:20261109T110000Z\r\nDTEND:20261109T113000Z\r\nSUMMARY:Standup, later\r\n"
	."ORGANIZER:mailto:organizer@example.com\r\nATTENDEE;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:attendee@example.com\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$moved = Invitation::fromIcal(sprintf($one, 'REQUEST'));
check(!$moved->HasMaster() && null === $moved->StoredCopy(null, 'attendee@example.com', 'ACCEPTED'), 'a changed date stored without its series');
$merged = $moved->StoredCopy($stored, 'attendee@example.com', 'TENTATIVE');
check(2 === count($merged->VEVENT) && isset($merged->VEVENT[0]->RRULE), 'changed date not merged into the series');
$merged2 = $moved->StoredCopy($merged, 'attendee@example.com', 'ACCEPTED');
check(2 === count($merged2->VEVENT), 'the same changed date added twice');
$cancelled = Invitation::fromIcal(sprintf($one, 'CANCEL'))->WithoutDates($merged2);
check(1 === count($cancelled->VEVENT) && '20261109T090000Z' === $cancelled->VEVENT->EXDATE->getValue(), 'cancelled date not excluded');
check(null === Invitation::fromIcal(ical('google-cancel'))->WithoutDates($stored), 'a whole cancel kept part of the event');

// 11. Answering one date of a stored series: a REPLY for that date alone,
//     with its RECURRENCE-ID, and the answer kept in that date's override;
//     declining one date takes it out of the calendar
$accepted = $series->StoredCopy(null, 'attendee@example.com', 'ACCEPTED');
$date = (new DateTimeImmutable('2026-11-16 09:00', new DateTimeZone('UTC')))->getTimestamp();
$reply = Invitation::fromVCalendar($accepted)->Reply('attendee@example.com', 'TENTATIVE', $date);
check(null !== $reply && 1 === count($reply->VEVENT), 'no single-date reply');
check('20261116T090000Z' === $reply->VEVENT->{'RECURRENCE-ID'}->getValue()
	&& 'TENTATIVE' === (string) $reply->VEVENT->ATTENDEE['PARTSTAT'], 'single-date reply wrong: '.$reply->serialize());
// the same answer as the series still goes out
check(null !== Invitation::fromVCalendar($accepted)->Reply('attendee@example.com', 'ACCEPTED', $date), 'a date answered like the series sends nothing');
check(null === Invitation::fromVCalendar($accepted)->Reply('attendee@example.com', 'ACCEPTED', $date + 3600), 'a reply for a date that is not one');

$maybe = Invitation::AnswerStored($accepted, 'attendee@example.com', 'TENTATIVE', $date);
$stored = Invitation::fromVCalendar($maybe);
check('TENTATIVE' === $stored->PartstatOn(['attendee@example.com'], $date)
	&& 'ACCEPTED' === $stored->PartstatOn(['attendee@example.com'], $date + 7 * 86400)
	&& 'ACCEPTED' === $stored->PartstatOn(['attendee@example.com'], null), 'per-date answer not kept');
check('CLIENT' === (string) $maybe->VEVENT[1]->ORGANIZER['SCHEDULE-AGENT'], 'the new override lets the server reply');
$declined = Invitation::AnswerStored($accepted, 'attendee@example.com', 'DECLINED', $date);
check(1 === count($declined->VEVENT) && '20261116T090000Z' === $declined->VEVENT->EXDATE->getValue(), 'declined date not taken out');
// the whole series at once
$all = Invitation::AnswerStored($maybe, 'attendee@example.com', 'DECLINED', null);
check('DECLINED' === Invitation::fromVCalendar($all)->PartstatOn(['attendee@example.com'], $date), 'series answer missed an override');

// 12. A change to a date declined earlier: the card says declined, and
//     answering the change puts the date back
$moved9 = Invitation::fromIcal(sprintf($one, 'REQUEST'));
$nine = $moved9->RecurrenceIds()[0];
$without = Invitation::AnswerStored($accepted, 'attendee@example.com', 'DECLINED', $nine);
check(Invitation::fromVCalendar($without)->IsExcluded($nine) && !Invitation::fromVCalendar($accepted)->IsExcluded($nine), 'IsExcluded wrong');
$back = $moved9->StoredCopy($without, 'attendee@example.com', 'ACCEPTED');
check(!Invitation::fromVCalendar($back)->IsExcluded($nine) && 2 === count($back->VEVENT), 'a re-accepted date stays excluded');

echo "itip-invitation: ok\n";
