# iTIP fixtures for #216

Real invitation mails as sent to an attendee, for `test/itip-reply.php`:
fourteen from one mailbox, three (`nextcloud35-*`) from a
Nextcloud 35.0.2 test calendar sending to a test address. All are masked.

## What was kept, what was masked

Kept as received:

- the MIME tree: part order, `multipart/*` nesting, `Content-Type` parameters
  (except file names, `boundary=` and `start=`, which are masked, see below)
  and the transfer encoding of every part (`base64`, `quoted-printable`,
  `7bit`, `8bit` or none), including the sender's spelling (`Base64`,
  `QUOTED-PRINTABLE`)
- in the calendar parts: every property and parameter, `SEQUENCE`,
  `LAST-MODIFIED` (or its absence), `CREATED`, `DTSTAMP`, times, `TZID` with
  `VTIMEZONE`, `RRULE`, `PRODID`, `X-MICROSOFT-*`, `X-GOOGLE-*`, and the case
  of `MAILTO:` and of parameter names such as `EMAIL=`

Masked:

- addresses: the organizer is `organizer@example.com`, the attendee the test
  answers as is `attendee@example.com`, everybody else is
  `attendeeN@example.com` or `otherN@example.com`, and `CN` follows the role.
  A second address of the same person, such as the organizer's own
  `EMAIL=` in the iCloud mail, is an `otherN@example.com` as well.
  The numbers N are running numbers of one masking run (`other5@` was added
  later, when the iCloud fixture was corrected); the same number in
  fixtures of different events does not mean the same person. (The mails of
  one event, request, update and cancel, are masked alike.)
  The address headers (`From`, `To`, `Cc`, `Reply-To`, `Sender`) are written
  in one form: `"Name" <address>`, comma-separated on one line, duplicates
  removed.
  The case of the original address is kept in a simplified form: all lower
  case, an initial capital (`Organizer@`, `Attendee@`) or all capitals
  (`ORGANIZER@`). A mixed-case original that starts with a capital becomes
  an initial capital; one that starts in lower case becomes all lower case.
- file names in `name=` and `filename=` parameters: generic ones stay as
  sent (`invite.ics`, `event.ics`, `event-invitation.ics`); iCloud's
  `iCal-NNNNNNN.ics` keeps its form with new digits; image files are named
  `imageN.png` or `imageN.jpg`.
- telephone numbers, such as a dial-in `tel:` URI: the form stays (`+` and
  the number of digits), the digits are replaced.
- `SUMMARY`, `LOCATION`, `DESCRIPTION`, `COMMENT`, subjects and all URLs. The
  text and HTML parts are replaced by "Example Meeting / Example
  description.", images by a 1×1 image of the same type, each in its original
  encoding.
- IDs (`UID`, `RELATED-TO`, `X-MICROSOFT-CDO-OWNERAPPTID`, Message-ID,
  Content-ID, MIME boundaries, iCloud principal paths, Teams and conference
  IDs): the local part keeps its length and character classes, with new
  characters; a mail domain becomes `@example.com`, while the `@thread.v2` of a
  Teams conversation ID stays. Boundary and
  Message-ID strings are replaced as a whole, so vendor markers in them
  (`_Part_` from JavaMail, `.Horde.`) are replaced too.
  `X-MICROSOFT-CDO-OWNERAPPTID` stays a signed 32-bit number. A `cid:`
  reference in an HTML part and the `start=` parameter of
  `multipart/related` carry the same new value as the `Content-ID` they
  point to. Generic Content-IDs (`img0`, `img1` in `no-organizer.eml`) stay
  as they are. In Exchange UIDs
  the class ID, the instance date, the reserved field and the length field
  stay; the timestamp and the data are masked.
  Masked IDs and timestamps keep their form, but they are not plausible
  values: a timestamp inside a UID, UUID version bits or a base32hex
  alphabet no longer mean anything.
- headers: at the top only `From`, `To`, `Cc`, `Reply-To`, `Sender`,
  `Subject`, `Date`, `Message-ID`, `MIME-Version`, `Content-Type`,
  `Content-Transfer-Encoding`, `Content-Language`, `Thread-Topic`,
  `Content-Class`, `User-Agent` and `X-Mailer` are kept, in the parts only
  `Content-*`; all others, among them the transport headers (`Received`,
  DKIM/ARC, `Authentication-Results`, `Thread-Index`, `X-MS-*`), are removed.

The only line that is not from the sender is the first header,
`X-Fixture:`, which says what the file is. `PRODID`s are left as sent, with
two exceptions: in `no-organizer.eml` the site-specific first field is masked
(same length and character classes), and in the `nextcloud35-*` mails the
`PRODID` is replaced by `-//Example//itip-fixture//EN`. Those three events
were written over CalDAV by a test script, and Nextcloud 35.0.2 sent them
with the script's own `PRODID`, `CREATED` and `DTSTAMP` (`20261120T220000Z`,
`20261120T220500Z`), so their `DTSTAMP` lies after the `Date` header. Sabre's
broker builds a new `VCalendar` for every iTIP message, which carries Sabre's
`PRODID`, and sets `DTSTAMP` (`ITip/Broker.php` and `Component/VCalendar.php`
in the bundled Sabre VObject 4.5.2); the two
older `nextcloud-*` mails, sent with Nextcloud's Sabre VObject 4.5.6, show that
(`-//Sabre//Sabre VObject 4.5.6//EN`, `DTSTAMP` within a second of `Date`). Two `PRODID`s name their vendor with a domain
(`caldav.icloud.com`, `kigkonsult.se`); those are kept.

## The fixtures

| File | Sender | METHOD, SEQUENCE | What it shows | Cases in the test (0 applies to all) |
|---|---|---|---|---|
| `google-request.eml` | Google Calendar | REQUEST, 0 | four attendees, calendar part `quoted-printable` plus a `base64` `application/ics` copy | 0, 1, 3; 1: one REPLY per PARTSTAT |
| `google-cancel.eml` | Google Calendar | CANCEL, 1 | same UID | 0, 3; 3: `STATUS:CANCELLED`, no REPLY |
| `google-series-request.eml` | Google Calendar | REQUEST, 0 | a second Google event, two attendees | 0, 1, 2 |
| `google-series-update.eml` | Google Calendar | REQUEST, 1 | same UID, new time | 0, 2; 2: answer reopened, second REPLY with SEQUENCE 1 |
| `outlook-request.eml` | PRODID says Exchange 2010 | REQUEST, 1 | calendar part `base64`, no `LAST-MODIFIED` | 0, 1, 2, 4 |
| `outlook-update.eml` | PRODID says Exchange 2010 | REQUEST, 2 | same UID, new time | 0, 2, 3, 4; 2: answer reopened, second REPLY with SEQUENCE 2 |
| `outlook-cancel.eml` | PRODID says Exchange 2010 | CANCEL, 3 | same UID | 0, 3, 4 |
| `exchange-no-last-modified.eml` | PRODID says Exchange 2010 | REQUEST, 0 | Teams meeting, no `LAST-MODIFIED` | 0, 1, 4; 4: a changed PARTSTAT alone makes a REPLY, the same PARTSTAT again makes none |
| `exchange-mailto-uppercase.eml` | PRODID says Exchange 2010 | REQUEST, 0 | `MAILTO:` in capitals, our address in mixed case (`Attendee@example.com`) | 0, 1, 4, 5a; 5a: found with the lower-case identity, REPLY to the organizer |
| `icloud-email-param.eml` | iCloud | REQUEST, 1 | `attendee4@example.com` is a principal path with the address only in `EMAIL=`; the ORGANIZER value differs from its own `EMAIL=`; Content-Type says `method=CANCEL` while the body says `METHOD:REQUEST` (follow-up of a split series) | 0, 1, 5b; 1 (as `attendee@`), 5b: `mailto:attendee4@…` finds nothing, the `EMAIL=` lookup finds the principal and the REPLY goes out as that principal |
| `horde-request.eml` | Horde Kronolith | REQUEST, none | no `SEQUENCE` at all, Exchange-style UID, calendar part without a transfer encoding header plus a `base64` copy, HTML part, the image as an attachment (`Content-Disposition: attachment`) | 0, 1; 1: the REPLY carries an empty `SEQUENCE`, which the test reads as 0 |
| `no-organizer.eml` | iCalcreator | none, none | Content-Type `method=REQUEST`, but no `METHOD`, no `ORGANIZER`, no `ATTENDEE` | 0, 5c; 5c: no identity, the broker sends nothing |
| `nextcloud-request.eml` | Nextcloud (Sabre VObject 4.5.6) | REQUEST, 2 | sent through the Nextcloud Mail app, hence `User-Agent: Horde Application Framework 5` | 0, 1, 3 |
| `nextcloud-cancel.eml` | Nextcloud (Sabre VObject 4.5.6) | CANCEL, 3 | same UID, body still `STATUS:CONFIRMED` | 0, 3 |
| `nextcloud35-request.eml` | Nextcloud 35.0.2 | REQUEST, 0 | event written over CalDAV by a test script; `PRODID`, `CREATED` and `DTSTAMP` are the script's (see above) | 0, 1, 2 |
| `nextcloud35-update.eml` | Nextcloud 35.0.2 | REQUEST, 1 | same UID, new time | 0, 2, 3 |
| `nextcloud35-cancel.eml` | Nextcloud 35.0.2 | CANCEL, 1 | SEQUENCE not raised, `STATUS:CONFIRMED` | 0, 3; 3: `METHOD:CANCEL` alone has to be enough |

## The test

```
php test/itip-reply.php
```

It prints `itip-reply: ok` and exits with 0. A failing check throws a
`RuntimeException`. Each check names the fixture or, in cases 4 to 5c,
the case (`no LAST-MODIFIED:`, `MAILTO:`,
`EMAIL=:`, `no ORGANIZER:`); only the comparison of the fixture list with
the directory and `answer()` when the identity is not an attendee name the
check alone. A broken fixture fails as well: a truncated `base64` calendar
copy in Sabre's own `ParseException`, a missing closing boundary drops the
last part of its multipart; where that part is a calendar copy, as in the
counter-check on `nextcloud-request.eml`, the check that all calendar copies
of a mail are the same fails. It uses only the
Sabre VObject that ships in `tachyon/v/0.0.0/app/libraries/Sabre`: no
configuration, no network, no database, so it does not depend on
`[calendar] enable`.

The identity is `attendee@example.com`. Answering works like the
Accept / Tentative / Decline buttons would: set our PARTSTAT on a copy of the
stored event and let `Broker::parseEvent()` build the iTIP message.

- **0** The fixtures are whole mails: top-level type and the transfer
  encoding of each calendar part are as listed in the test, all calendar
  copies in a mail are the same event, and there is no address outside
  `example.com`.
- **1** For each first REQUEST, `Broker::processMessage()` stores the event
  with our PARTSTAT open, and ACCEPTED, TENTATIVE and DECLINED each give
  exactly one `METHOD:REPLY`: to the organizer, from us, with UID, SEQUENCE
  and ORGANIZER of the request, only our ATTENDEE, the chosen PARTSTAT.
  Without a change there is no REPLY.
- **2** An update replaces the accepted copy: new SEQUENCE and time, PARTSTAT
  back to NEEDS-ACTION, and accepting again gives a second REPLY with the
  higher SEQUENCE.
- **3** A CANCEL turns the accepted copy into `STATUS:CANCELLED` and gives no
  REPLY, not even after Decline, also when the CANCEL body still says
  `STATUS:CONFIRMED` or keeps the SEQUENCE.
- **4** Without `LAST-MODIFIED`, a changed PARTSTAT is still detected.
- **5a** `MAILTO:` in capitals and a mixed-case address match the identity.
- **5b** An address only in `EMAIL=` is not found as `mailto:`; the lookup
  has to read `EMAIL=` (`attendeeHref()` in the test is a reference for
  that).
- **5c** Without ORGANIZER there is nothing to answer, even if the
  Content-Type says `method=REQUEST`.
