<?php

// Run with: php test/itip-reply.php
//
// Attendee side of iTIP (RFC 5546 §3.2) for #216, checked against real
// invitation mails from Google Calendar, Exchange/Outlook, iCloud, Horde and
// Nextcloud. test/fixtures/itip/README.md says where each one comes from and
// what it shows. The fixtures are whole messages: MIME tree, transfer
// encodings and header spelling are as received, address headers in one
// form (see the README); names, addresses, subjects, texts, images and IDs
// are masked.
define('APP_VERSION', 'test');
spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'Tachyon\\Util\\')) {
		$file = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/tachyon_util/'.strtolower(str_replace('\\', '/', substr($class, 13))).'.php';
	} else {
		$file = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/'.str_replace('\\', '/', $class).'.php';
	}
	if (is_file($file)) {
		require_once $file;
	}
});

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\ITip\Broker;
use Sabre\VObject\ITip\Message;
use Sabre\VObject\Reader;

const IDENTITY = 'attendee@example.com';
const ORGANIZER = 'mailto:organizer@example.com';

/** The leaf parts of a MIME message: lower-cased headers and the decoded body. */
function mimeParts(string $raw): array
{
	[$head, $body] = array_pad(preg_split('/\r?\n\r?\n/', $raw, 2), 2, '');
	$headers = [];
	foreach (preg_split('/\r?\n/', preg_replace('/\r?\n[ \t]+/', ' ', $head)) as $line) {
		if (str_contains($line, ':')) {
			[$name, $value] = explode(':', $line, 2);
			$headers[strtolower(trim($name))] = trim($value);
		}
	}
	$type = $headers['content-type'] ?? 'text/plain';
	if (preg_match('/^multipart\/[^;]+;.*boundary="?([^";]+)"?/i', $type, $m)) {
		$chunks = preg_split('/(?:^|\r?\n)--'.preg_quote($m[1], '/').'(?:--)?[ \t]*(?:\r?\n|$)/', $body);
		$parts = [];
		foreach (array_slice($chunks, 1, -1) as $chunk) {
			$parts = array_merge($parts, mimeParts($chunk));
		}
		return $parts;
	}
	$encoding = strtolower($headers['content-transfer-encoding'] ?? '');
	return [[
		'type' => strtolower(trim(explode(';', $type)[0])),
		'headers' => $headers,
		'body' => match ($encoding) {
			'base64' => (string) base64_decode($body),
			'quoted-printable' => quoted_printable_decode($body),
			default => $body,
		},
	]];
}

function fixtureMail(string $name): string
{
	$raw = file_get_contents(__DIR__.'/fixtures/itip/'.$name.'.eml');
	check(false !== $raw, "fixture $name missing");
	return $raw;
}

/** The calendar parts (text/calendar and application/ics) of a fixture. */
function calendarParts(string $name): array
{
	return array_values(array_filter(mimeParts(fixtureMail($name)), static fn (array $part): bool => in_array($part['type'], ['text/calendar', 'application/ics'], true)));
}

/** The text/calendar part as received, and the method= of its Content-Type. */
function calendar(string $name): array
{
	foreach (calendarParts($name) as $part) {
		if ('text/calendar' === $part['type']) {
			preg_match('/;\s*method="?(\w+)/i', $part['headers']['content-type'], $m);
			return [Reader::read($part['body']), strtoupper($m[1] ?? '')];
		}
	}
	throw new RuntimeException("$name: no text/calendar part");
}

// iCloud labels the follow-up of a split recurring series (RELATED-TO;
// RELTYPE=X-CALENDARSERVER-RECURRENCE-SET) method=CANCEL in the Content-Type
// while the body says METHOD:REQUEST. The body is what counts.
const CONTENT_TYPE_METHOD = ['icloud-email-param' => 'CANCEL'];

/** An iTIP fixture and its METHOD; method= in the Content-Type matches it unless listed above. */
function fixture(string $name): array
{
	[$vcal, $method] = calendar($name);
	check($method === (CONTENT_TYPE_METHOD[$name] ?? (string) $vcal->METHOD), "$name: Content-Type method= is $method");
	return [$vcal, (string) $vcal->METHOD];
}

/** Wrap a received iTIP calendar the way an incoming mail would be handed to the broker. */
function incoming(VCalendar $vcal, string $identity = IDENTITY): Message
{
	$msg = new Message();
	$msg->component = 'VEVENT';
	$msg->method = (string) $vcal->METHOD;
	$msg->uid = (string) $vcal->VEVENT->UID;
	$msg->sequence = sequence($vcal);
	$msg->sender = $vcal->VEVENT->ORGANIZER->getNormalizedValue();
	$msg->recipient = 'mailto:'.$identity;
	$msg->message = $vcal;
	return $msg;
}

/**
 * The ATTENDEE that belongs to an identity: either the calendar address is
 * mailto:<identity> in any case, or the address is something else (urn:uuid:…,
 * an iCloud principal path) and the identity is in the EMAIL= parameter
 * (RFC 7986 §6.2).
 */
function attendeeHref(VCalendar $vcal, string $identity): ?string
{
	foreach ($vcal->VEVENT->ATTENDEE ?? [] as $attendee) {
		$href = $attendee->getNormalizedValue();
		if (0 === strcasecmp($href, 'mailto:'.$identity)
			|| (isset($attendee['EMAIL']) && 0 === strcasecmp((string) $attendee['EMAIL'], $identity))) {
			return $href;
		}
	}
	return null;
}

function sequence(VCalendar $vcal): int
{
	return isset($vcal->VEVENT->SEQUENCE) ? (int) $vcal->VEVENT->SEQUENCE->getValue() : 0;
}

/** Our own ATTENDEE line. */
function ours(VCalendar $vcal, string $identity = IDENTITY): ?\Sabre\VObject\Property
{
	$href = attendeeHref($vcal, $identity);
	foreach ($vcal->VEVENT->ATTENDEE ?? [] as $attendee) {
		if ($attendee->getNormalizedValue() === $href) {
			return $attendee;
		}
	}
	return null;
}

/** A copy with our PARTSTAT set, as if we had answered earlier. */
function withPartstat(VCalendar $vcal, string $partstat, string $identity = IDENTITY): VCalendar
{
	$copy = clone $vcal;
	ours($copy, $identity)['PARTSTAT'] = $partstat;
	return $copy;
}

/** Set our PARTSTAT, as the Accept / Tentative / Decline buttons would, and return the iTIP messages. */
function answer(Broker $broker, VCalendar $stored, string $partstat, ?string $href = null, string $identity = IDENTITY): array
{
	$href ??= attendeeHref($stored, $identity);
	check(null !== $href, 'identity is not an attendee');
	$new = clone $stored;
	foreach ($new->VEVENT->ATTENDEE as $attendee) {
		if ($attendee->getNormalizedValue() === $href) {
			$attendee['PARTSTAT'] = $partstat;
			unset($attendee['RSVP']);
		}
	}
	return $broker->parseEvent($new, $href, $stored);
}

/** One REPLY, from us to the organizer, with UID and SEQUENCE of the request. */
function checkReply(string $case, array $messages, VCalendar $request, string $partstat, string $href): void
{
	check(1 === count($messages), "$case: expected one iTIP message, got ".count($messages));
	$reply = $messages[0];
	check('REPLY' === $reply->method, "$case: method is {$reply->method}, not REPLY");
	check('REPLY' === (string) $reply->message->METHOD, "$case: the calendar body has no METHOD:REPLY");
	check(ORGANIZER === strtolower($reply->recipient), "$case: reply goes to {$reply->recipient}, not the organizer");
	check($href === $reply->sender, "$case: reply is sent as {$reply->sender}");
	$event = $reply->message->VEVENT;
	check((string) $request->VEVENT->UID === (string) $event->UID, "$case: UID not taken over");
	$sequence = sequence($request);
	check($sequence === sequence($reply->message), "$case: SEQUENCE ".sequence($reply->message)." instead of $sequence");
	check(1 === count($event->ATTENDEE), "$case: a reply carries only our own ATTENDEE");
	check($href === $event->ATTENDEE->getNormalizedValue(), "$case: wrong ATTENDEE in the reply");
	check($partstat === (string) $event->ATTENDEE['PARTSTAT'], "$case: PARTSTAT ".$event->ATTENDEE['PARTSTAT']." instead of $partstat");
	check(strtolower((string) $request->VEVENT->ORGANIZER->getNormalizedValue()) === strtolower((string) $event->ORGANIZER->getNormalizedValue()), "$case: ORGANIZER not taken over");
}

$broker = new Broker();

// 0. The fixtures are whole mails as received: top-level type and the transfer
//    encoding of every calendar part are the senders' own, and the only
//    addresses are example.com ones.
$structure = [
	'google-request' => ['multipart/mixed', ['text/calendar' => 'quoted-printable', 'application/ics' => 'base64']],
	'google-cancel' => ['multipart/mixed', ['text/calendar' => '7bit', 'application/ics' => 'base64']],
	'google-series-request' => ['multipart/mixed', ['text/calendar' => 'quoted-printable', 'application/ics' => 'base64']],
	'google-series-update' => ['multipart/mixed', ['text/calendar' => 'quoted-printable', 'application/ics' => 'base64']],
	'outlook-request' => ['multipart/alternative', ['text/calendar' => 'base64']],
	'outlook-update' => ['multipart/alternative', ['text/calendar' => 'base64']],
	'outlook-cancel' => ['multipart/alternative', ['text/calendar' => 'base64']],
	'exchange-no-last-modified' => ['multipart/alternative', ['text/calendar' => 'base64']],
	'exchange-mailto-uppercase' => ['multipart/alternative', ['text/calendar' => 'base64']],
	'icloud-email-param' => ['multipart/mixed', ['text/calendar' => 'base64']],
	'horde-request' => ['multipart/mixed', ['text/calendar' => '', 'application/ics' => 'base64']],
	'no-organizer' => ['multipart/related', ['text/calendar' => '7bit']],
	'nextcloud-request' => ['multipart/mixed', ['text/calendar' => '']],
	'nextcloud-cancel' => ['multipart/mixed', ['text/calendar' => '']],
	'nextcloud35-request' => ['multipart/mixed', ['text/calendar' => '']],
	'nextcloud35-update' => ['multipart/mixed', ['text/calendar' => '']],
	'nextcloud35-cancel' => ['multipart/mixed', ['text/calendar' => '']],
];
$files = array_map(static fn (string $f): string => basename($f, '.eml'), glob(__DIR__.'/fixtures/itip/*.eml'));
sort($files);
$names = array_keys($structure);
sort($names);
check($files === $names, 'test/fixtures/itip/ and the list in this test differ');
foreach ($structure as $name => [$top, $encodings]) {
	$raw = fixtureMail($name);
	check((bool) preg_match('/^Content-Type:\s*'.preg_quote($top, '/').';/mi', $raw), "$name: not $top");
	$uids = [];
	foreach (calendarParts($name) as $part) {
		check(array_key_exists($part['type'], $encodings), "$name: unexpected {$part['type']} part");
		check($encodings[$part['type']] === strtolower($part['headers']['content-transfer-encoding'] ?? ''), "$name: {$part['type']} is not sent as '{$encodings[$part['type']]}'");
		$uids[] = (string) Reader::read($part['body'])->VEVENT->UID;
	}
	check(count($encodings) === count($uids) && 1 === count(array_unique($uids)), "$name: the calendar copies in the mail differ");
	$text = preg_replace('/\r?\n[ \t]/', '', implode("\n", array_merge([$raw], array_column(mimeParts($raw), 'body'))));
	preg_match_all('/@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', $text, $m);
	check([] === array_diff(array_map('strtolower', $m[1]), ['example.com']), "$name: an address outside example.com");
}

// 1. A first REQUEST from each kind of organizer, answered three ways.
foreach (['google-request', 'google-series-request', 'outlook-request', 'exchange-no-last-modified', 'exchange-mailto-uppercase', 'icloud-email-param', 'horde-request', 'nextcloud-request', 'nextcloud35-request'] as $name) {
	[$request, $method] = fixture($name);
	check('REQUEST' === $method, "$name: not a REQUEST");
	$stored = $broker->processMessage(incoming($request));
	check($stored instanceof VCalendar, "$name: the invitation could not be stored");
	check(null !== ours($stored), "$name: identity is not an attendee");
	// iCloud leaves PARTSTAT out, which means NEEDS-ACTION (RFC 5545 §3.2.12).
	check(in_array((string) (ours($stored)['PARTSTAT'] ?? 'NEEDS-ACTION'), ['NEEDS-ACTION'], true), "$name: our PARTSTAT is not NEEDS-ACTION before answering");
	$href = attendeeHref($stored, IDENTITY);
	foreach (['ACCEPTED', 'TENTATIVE', 'DECLINED'] as $partstat) {
		checkReply("$name/$partstat", answer($broker, $stored, $partstat), $request, $partstat, $href);
	}
	// Nothing changed, nothing to send.
	check([] === $broker->parseEvent(clone $stored, $href, $stored), "$name: a REPLY without a change");
}

// 2. Accept, then the organizer sends an update (same UID, higher SEQUENCE):
//    it replaces our copy, PARTSTAT is open again, and accepting it sends a second REPLY.
foreach (['google-series' => 1, 'outlook' => 2, 'nextcloud35' => 1] as $source => $sequence) {
	[$request] = fixture("$source-request");
	[$update, $method] = fixture("$source-update");
	check('REQUEST' === $method, "$source-update: not a REQUEST");
	check((string) $request->VEVENT->UID === (string) $update->VEVENT->UID, "$source-update: different UID");
	check($sequence === sequence($update), "$source-update: SEQUENCE is not $sequence");
	check(sequence($update) > sequence($request), "$source-update: SEQUENCE did not go up");

	$stored = $broker->processMessage(incoming($request));
	$first = answer($broker, $stored, 'ACCEPTED');
	checkReply("$source-update/first", $first, $request, 'ACCEPTED', 'mailto:'.IDENTITY);
	$accepted = withPartstat($stored, 'ACCEPTED');

	$stored = $broker->processMessage(incoming($update), $accepted);
	check('NEEDS-ACTION' === (string) ours($stored)['PARTSTAT'], "$source-update: the update did not reopen our answer");
	check($sequence === sequence($stored), "$source-update: the stored event kept the old SEQUENCE");
	check($update->VEVENT->DTSTART->getDateTime()->format('c') === $stored->VEVENT->DTSTART->getDateTime()->format('c'), "$source-update: the new time was not taken over");
	$second = answer($broker, $stored, 'ACCEPTED');
	checkReply("$source-update/second", $second, $update, 'ACCEPTED', 'mailto:'.IDENTITY);
	check($first[0]->sequence < $second[0]->sequence, "$source-update: the second REPLY does not carry the higher SEQUENCE");
}

// 3. CANCEL: our accepted copy becomes STATUS:CANCELLED, and nothing goes back.
//    Nextcloud keeps STATUS:CONFIRMED in the CANCEL body, and Nextcloud 35
//    does not raise SEQUENCE either; METHOD:CANCEL alone has to be enough.
foreach (['google' => 'google-request', 'outlook' => 'outlook-update', 'nextcloud' => 'nextcloud-request', 'nextcloud35' => 'nextcloud35-update'] as $source => $last) {
	[$request] = fixture($last);
	[$cancel, $method] = fixture("$source-cancel");
	check('CANCEL' === $method, "$source-cancel: not a CANCEL");
	check((string) $request->VEVENT->UID === (string) $cancel->VEVENT->UID, "$source-cancel: different UID");
	check(sequence($cancel) >= sequence($request), "$source-cancel: SEQUENCE went down");

	$accepted = withPartstat($broker->processMessage(incoming($request)), 'ACCEPTED');
	$cancelled = $broker->processMessage(incoming($cancel), clone $accepted);
	check($cancelled instanceof VCalendar, "$source-cancel: nothing to store");
	check('CANCELLED' === (string) $cancelled->VEVENT->STATUS, "$source-cancel: event is not STATUS:CANCELLED");
	check([] === $broker->parseEvent($cancelled, 'mailto:'.IDENTITY, $accepted), "$source-cancel: a CANCEL must not produce a REPLY");
	// Even pressing Decline on the cancelled event sends nothing.
	check([] === answer($broker, $cancelled, 'DECLINED'), "$source-cancel: a REPLY to a cancelled event");
}
check('CONFIRMED' === (string) fixture('nextcloud-cancel')[0]->VEVENT->STATUS, 'nextcloud-cancel: the body is no longer STATUS:CONFIRMED');
check(sequence(fixture('nextcloud35-cancel')[0]) === sequence(fixture('nextcloud35-update')[0]), 'nextcloud35-cancel: SEQUENCE is not the one of the last REQUEST');

// 4. Exchange sends no LAST-MODIFIED (none of the Exchange fixtures has one).
//    Answering must not depend on it: a changed PARTSTAT alone makes a REPLY,
//    the same PARTSTAT again makes none.
foreach (['exchange-no-last-modified', 'exchange-mailto-uppercase', 'outlook-request', 'outlook-update', 'outlook-cancel'] as $name) {
	check(!isset(fixture($name)[0]->VEVENT->{'LAST-MODIFIED'}), "$name: has LAST-MODIFIED");
}
[$request] = fixture('exchange-no-last-modified');
$stored = $broker->processMessage(incoming($request));
check(!isset($stored->VEVENT->{'LAST-MODIFIED'}), 'no LAST-MODIFIED: the stored copy got one');
checkReply('no LAST-MODIFIED/accept', answer($broker, $stored, 'ACCEPTED'), $request, 'ACCEPTED', 'mailto:'.IDENTITY);
$accepted = withPartstat($stored, 'ACCEPTED');
check([] === answer($broker, $accepted, 'ACCEPTED'), 'no LAST-MODIFIED: accepting twice sent a second REPLY');
checkReply('no LAST-MODIFIED/tentative', answer($broker, $accepted, 'TENTATIVE'), $request, 'TENTATIVE', 'mailto:'.IDENTITY);

// 5a. Exchange writes MAILTO: in capitals, and this one has our address in
//     mixed case. Sabre lower-cases mailto: addresses, so the identity in
//     lower case matches and the REPLY goes to the organizer.
$raw = preg_replace('/\r?\n[ \t]/', '', implode("\n", array_column(calendarParts('exchange-mailto-uppercase'), 'body')));
check(1 === preg_match('/^ATTENDEE;[^\r\n]*:MAILTO:Attendee@example\.com\r?$/m', $raw), 'MAILTO: the fixture lost its spelling');
check(1 === preg_match('/^ORGANIZER;[^\r\n]*:MAILTO:/m', $raw), 'MAILTO: the ORGANIZER is not MAILTO:');
[$request] = fixture('exchange-mailto-uppercase');
$stored = $broker->processMessage(incoming($request));
check('mailto:'.IDENTITY === attendeeHref($stored, IDENTITY), 'MAILTO: identity not found');
checkReply('MAILTO:', answer($broker, $stored, 'ACCEPTED'), $request, 'ACCEPTED', 'mailto:'.IDENTITY);

// 5b. iCloud: the attendee attendee4@example.com is a principal path, its mail
//     address is only in the EMAIL= parameter. Matching mailto:<identity>
//     finds nothing; the EMAIL= parameter has to be read. The ORGANIZER value
//     also differs from its own EMAIL= parameter; the REPLY goes to the value.
$other = 'attendee4@example.com';
[$request, $method] = fixture('icloud-email-param');
check('REQUEST' === $method && 'CANCEL' === calendar('icloud-email-param')[1], 'EMAIL=: the fixture lost its method=CANCEL / METHOD:REQUEST pair');
check(isset($request->VEVENT->{'RELATED-TO'}) && 'X-CALENDARSERVER-RECURRENCE-SET' === (string) $request->VEVENT->{'RELATED-TO'}['RELTYPE'], 'EMAIL=: not the follow-up of a split series');
$principal = attendeeHref($request, $other);
check(null !== $principal && 1 === preg_match('#^/[^/]+/principal/$#', $principal), 'EMAIL=: attendee4 is not a principal path');
check('other5@example.com' === strtolower((string) $request->VEVENT->ORGANIZER['EMAIL']), 'EMAIL=: the ORGANIZER has no separate EMAIL=');
$stored = $broker->processMessage(incoming($request, $other));
check([] === answer($broker, $stored, 'ACCEPTED', 'mailto:'.$other), 'EMAIL=: the identity matched the principal path without the EMAIL= parameter');
$stored = withPartstat($stored, 'NEEDS-ACTION', $other);
checkReply('EMAIL=', answer($broker, $stored, 'ACCEPTED', null, $other), $request, 'ACCEPTED', $principal);

// 5c. An event mail: the Content-Type says method=REQUEST, the body has
//     no METHOD, no ORGANIZER and no ATTENDEE. There is nobody to answer, so
//     the buttons must not appear and the broker has nothing to send.
[$vcal, $method] = calendar('no-organizer');
check('REQUEST' === $method, 'no ORGANIZER: Content-Type is not method=REQUEST');
check(!isset($vcal->METHOD), 'no ORGANIZER: the body has a METHOD');
check(!isset($vcal->VEVENT->ORGANIZER) && !isset($vcal->VEVENT->ATTENDEE), 'no ORGANIZER: the event has an ORGANIZER or ATTENDEE');
check(null === attendeeHref($vcal, IDENTITY), 'no ORGANIZER: identity found');
check([] === $broker->parseEvent(clone $vcal, 'mailto:'.IDENTITY, $vcal), 'no ORGANIZER: the broker produced a message');

echo "itip-reply: ok\n";
