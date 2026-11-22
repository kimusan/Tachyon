<?php

namespace Tachyon\Actions;

use Tachyon\Enumerations\Capa;
use Tachyon\Exceptions\ClientException;

trait Calendar
{
	/** A calendar file is text; past this it is not one. */
	private const IMPORT_MAX_BYTES = 5242880;

	protected ?\Tachyon\Providers\Calendar $oCalendarProvider = null;

	public function CalendarProvider(?\Tachyon\Model\Account $oAccount = null): \Tachyon\Providers\Calendar
	{
		if (null === $this->oCalendarProvider) {
			$oDriver = null;
			try {
				if ($this->GetCapa(Capa::CALENDAR)) {
					// Goes through fabrica so a plugin can supply another backend
					$oDriver = $this->fabrica('calendar', $oAccount);
				}
				if ($oAccount && $oDriver) {
					$oDriver->SetEmail($this->GetMainEmail($oAccount));
					$oDriver->setDAVClientConfig($this->getCalendarSyncData($oAccount));
				}
			} catch (\Throwable $oException) {
				\Tachyon\Util\LOG::error('Calendar', $oException->getMessage()."\n".$oException->getTraceAsString());
				$oDriver = null;
			}
			$this->oCalendarProvider = new \Tachyon\Providers\Calendar($oDriver);
			$this->oCalendarProvider->SetLogger($this->oLogger);
		}

		return $this->oCalendarProvider;
	}

	public function DoCalendars() : array
	{
		$oAccount = $this->getAccountFromToken();
		$oProvider = $this->CalendarProvider($oAccount);
		if (!$oProvider->IsActive()) {
			return $this->FalseResponse();
		}
		$aCalendars = $oProvider->GetCalendars();

		// GetCalendars only reads what is stored, and Sync is what fetches from
		// the server, so without this the list is empty until the user happens to
		// press the sync button
		if (!$aCalendars) {
			try {
				$oProvider->Sync();
				$aCalendars = $oProvider->GetCalendars();
			} catch (\Throwable $oException) {
				\Tachyon\Util\LOG::error('Calendar', $oException->getMessage());
			}
		}

		return $this->DefaultResponse(array(
			'Calendars' => $aCalendars
		));
	}

	/**
	 * Occurrences overlapping a window, with recurrence already expanded.
	 * Shaped for @event-calendar, which wants id, title, start, end and allDay.
	 */
	public function DoCalendarEvents() : array
	{
		$oAccount = $this->getAccountFromToken();
		$oProvider = $this->CalendarProvider($oAccount);
		if (!$oProvider->IsActive()) {
			return $this->FalseResponse();
		}

		$iStart = (int) $this->GetActionParam('Start', 0);
		$iEnd = (int) $this->GetActionParam('End', 0);
		if (1 > $iStart || 1 > $iEnd || $iEnd <= $iStart) {
			throw new ClientException(\Tachyon\Notifications::InvalidInputArgument, null, 'Bad window');
		}

		// An unbounded window would expand every recurring event ever created
		$iMaxDays = \max(1, (int) $this->Config()->Get('calendar', 'max_range_days', 400));
		if ($iEnd - $iStart > $iMaxDays * 86400) {
			throw new ClientException(\Tachyon\Notifications::InvalidInputArgument, null, 'Window too large');
		}

		$aUuids = \array_filter(\array_map('\\trim',
			\explode(',', (string) $this->GetActionParam('Calendars', ''))
		));

		return $this->DefaultResponse(array(
			'Events' => $oProvider->GetOccurrences(\array_values($aUuids), $iStart, $iEnd)
		));
	}

	public function DoCalendarEventSave() : array
	{
		$oAccount = $this->getAccountFromToken();
		$oProvider = $this->CalendarProvider($oAccount);
		if (!$oProvider->IsActive()) {
			return $this->FalseResponse();
		}

		$sCalendarUuid = (string) $this->GetActionParam('Calendar', '');
		$sUid = \trim((string) $this->GetActionParam('Uid', ''));

		$oEvent = new \Tachyon\Providers\Calendar\Classes\Event;
		$oEvent->Uid = \strlen($sUid) ? $sUid : \Tachyon\Util\UUID::generate();

		$sIcal = (string) $this->GetActionParam('Ical', '');
		if (\strlen($sIcal)) {
			// The client sent a whole iCalendar body, so keep it verbatim
			$oEvent->setIcal($sIcal);
			// The body carries its own UID and everything downstream reads one or
			// the other: metaFromVCalendar prefers the body's, while the uid column
			// and the DAV filename come from this property. Minting a fresh one
			// here would store a row that disagrees with its own contents, so the
			// body wins whenever the caller did not name an event to overwrite.
			if (!\strlen($sUid)) {
				$sBodyUid = static::uidFromVCalendar($oEvent->VCalendar());
				if (\strlen($sBodyUid)) {
					$oEvent->Uid = $sBodyUid;
				}
			}
		} else {
			$oSplitOff = null;
			$oEvent->setVCalendar($this->vCalendarFromParams($oEvent->Uid, $sCalendarUuid, $oProvider, $oSplitOff));
		}

		try {
			// "This and following": the new series goes first, so a failure
			// leaves the old one as it was rather than cut short with no
			// continuation; and if cutting it short fails, the new one goes again
			if ($oSplitOff) {
				$oNewEvent = new \Tachyon\Providers\Calendar\Classes\Event;
				$oNewEvent->Uid = (string) \Tachyon\Providers\Calendar\EventEditor::master($oSplitOff)->UID;
				$oNewEvent->setVCalendar($oSplitOff);
				if (!$oProvider->EventSave($sCalendarUuid, $oNewEvent)) {
					throw new ClientException(\Tachyon\Notifications::CantSaveMessage, null,
						'The calendar server rejected the event');
				}
			}
			$bResult = $oProvider->EventSave($sCalendarUuid, $oEvent);
			if (!$bResult && $oSplitOff) {
				$oProvider->DeleteEvent($sCalendarUuid, $oNewEvent->Uid);
			}
		} catch (\ValueError $oException) {
			throw new ClientException(\Tachyon\Notifications::CantSaveMessage, $oException, $oException->getMessage());
		}

		// Reporting this inside the payload would read as success to the client,
		// because the transport only looks at the response's own Result
		if (!$bResult) {
			throw new ClientException(\Tachyon\Notifications::CantSaveMessage, null,
				'The calendar server rejected the event');
		}

		return $this->TrueResponse(array('Uid' => $oEvent->Uid));
	}

	/**
	 * A whole iCalendar file into one calendar. Separate from EventSave because
	 * importing is not saving one event: a file holds any number of them, the
	 * UID has to come from the body rather than the caller, and the caller wants
	 * to know how many of them landed.
	 */
	public function DoCalendarImport() : array
	{
		$oAccount = $this->getAccountFromToken();
		$oProvider = $this->CalendarProvider($oAccount);
		if (!$oProvider->IsActive()) {
			return $this->FalseResponse();
		}

		$sCalendarUuid = (string) $this->GetActionParam('Calendar', '');
		$sIcal = (string) $this->GetActionParam('Ical', '');

		if (!\strlen(\trim($sIcal))) {
			throw new ClientException(\Tachyon\Notifications::InvalidInputArgument, null, 'Empty iCalendar body');
		}
		// A calendar file is text. Anything of this size is not one, and parsing
		// it would cost real memory before finding that out.
		if (self::IMPORT_MAX_BYTES < \strlen($sIcal)) {
			throw new ClientException(\Tachyon\Notifications::InvalidInputArgument, null, 'iCalendar body too large');
		}

		try {
			$oParsed = \Sabre\VObject\Reader::read($sIcal, \Sabre\VObject\Reader::OPTION_FORGIVING);
		} catch (\Throwable $oException) {
			throw new ClientException(\Tachyon\Notifications::InvalidInputArgument, $oException, 'Unreadable iCalendar file');
		}
		if (!($oParsed instanceof \Sabre\VObject\Component\VCalendar)) {
			throw new ClientException(\Tachyon\Notifications::InvalidInputArgument, null, 'Not an iCalendar file');
		}

		$aEvents = static::splitVCalendar($oParsed);
		if (!$aEvents) {
			// Forgiving mode parses a file with no VEVENT in it quite happily, and
			// EventSave would then fail per event with something far less clear
			throw new ClientException(\Tachyon\Notifications::InvalidInputArgument, null,
				'The file contains no events');
		}

		$iImported = 0;
		$aFailed = array();
		foreach ($aEvents as $sUid => $oVCalendar) {
			$oEvent = new \Tachyon\Providers\Calendar\Classes\Event;
			$oEvent->Uid = $sUid;
			$oEvent->setVCalendar($oVCalendar);
			try {
				if ($oProvider->EventSave($sCalendarUuid, $oEvent)) {
					++$iImported;
				} else {
					$aFailed[] = $sUid;
				}
			} catch (\ValueError $oException) {
				// An unknown or read only calendar fails identically for every
				// event, so there is nothing to learn from trying the rest
				throw new ClientException(\Tachyon\Notifications::CantSaveMessage, $oException, $oException->getMessage());
			} catch (\Throwable $oException) {
				\Tachyon\Util\Log::warning('Calendar', "Import of {$sUid} failed: " . $oException->getMessage());
				$aFailed[] = $sUid;
			}
		}

		if (!$iImported) {
			throw new ClientException(\Tachyon\Notifications::CantSaveMessage, null,
				'No event could be imported');
		}

		return $this->TrueResponse(array(
			'Imported' => $iImported,
			'Failed' => \count($aFailed)
		));
	}

	/**
	 * The user's addresses, the order identities are listed in, so the one the
	 * user put first answers when an invitation went to more than one of them.
	 *
	 * @return array<string, \Tachyon\Model\Identity> keyed by address
	 */
	private function invitationIdentities(\Tachyon\Model\Account $oAccount) : array
	{
		$aResult = array();
		foreach ($this->GetIdentities($oAccount) as $oIdentity) {
			$sEmail = \trim($oIdentity->Email());
			if (\strlen($sEmail) && !isset($aResult[\strtolower($sEmail)])) {
				$aResult[\strtolower($sEmail)] = $oIdentity;
			}
		}
		return $aResult;
	}

	private function invitationFromParams() : \Tachyon\Providers\Calendar\Invitation
	{
		$oInvitation = \Tachyon\Providers\Calendar\Invitation::fromIcal((string) $this->GetActionParam('Ical', ''));
		if (!$oInvitation) {
			throw new ClientException(\Tachyon\Notifications::InvalidInputArgument, null, 'Not an iCalendar event');
		}
		return $oInvitation;
	}

	/**
	 * What an invitation in a mail is, and whether the user can answer it.
	 * Works without the calendar: answering is mail, not storage.
	 */
	public function DoCalendarInvitation() : array
	{
		$oAccount = $this->getAccountFromToken();
		$oInvitation = $this->invitationFromParams();
		$aEmails = \array_keys($this->invitationIdentities($oAccount));
		return $this->DefaultResponse($this->describeInvitation($oAccount, $oInvitation, $aEmails));
	}

	/**
	 * The invitation, plus what the user's calendar holds of it: the copy an
	 * earlier answer stored, and whether this mail is older than that copy.
	 */
	private function describeInvitation(\Tachyon\Model\Account $oAccount,
		\Tachyon\Providers\Calendar\Invitation $oInvitation, array $aEmails) : array
	{
		$aInfo = $oInvitation->Describe($aEmails);
		$oProvider = $this->CalendarProvider($oAccount);
		$aStored = null;
		$bOutdated = false;
		if ($oProvider->IsActive() && \strlen($aInfo['Uid'])) {
			try {
				$aFound = $oProvider->FindEventByUid($aInfo['Uid']);
			} catch (\Throwable $oException) {
				$aFound = null;
				\Tachyon\Util\Log::warning('Calendar', 'Invitation lookup failed: ' . $oException->getMessage());
			}
			$oStored = $aFound ? $aFound[1]->VCalendar() : null;
			$oStoredInvitation = $oStored ? \Tachyon\Providers\Calendar\Invitation::fromVCalendar($oStored) : null;
			if ($oStoredInvitation) {
				// For one date of a series, the answer on that date: declined,
				// it was taken out of the series
				$iDate = $oInvitation->RecurrenceIds()[0] ?? null;
				$aStored = array(
					'Uuid' => $aFound[0]->Uuid,
					'Name' => $aFound[0]->Name,
					'Partstat' => null !== $iDate && $oStoredInvitation->IsExcluded($iDate)
						? 'DECLINED' : $oStoredInvitation->PartstatOn($aEmails, $iDate),
					'Sequence' => $oStoredInvitation->SequenceNumber()
				);
				// An update for one date carries that date's SEQUENCE, which says
				// nothing against the series', so only a whole event is compared
				$bOutdated = $oInvitation->HasMaster() && $oInvitation->SequenceNumber() < $aStored['Sequence'];
			}
		}
		$aInfo['CanReply'] = $aInfo['CanReply'] && !$bOutdated;
		$aInfo['Calendar'] = array(
			'Active' => $oProvider->IsActive(),
			'Stored' => $aStored,
			'Outdated' => $bOutdated,
			// Changed dates alone can only go into a series that is stored already
			'CanStore' => $oInvitation->HasMaster() || null !== $aStored
		);
		return $aInfo;
	}

	/**
	 * After an answer went out: keep the event in the calendar with that
	 * answer. Accepting or a maybe stores it, in the calendar that already
	 * holds it, else the one the user chose; declining takes it out.
	 *
	 * @return string an error to show, '' when done or nothing was to be done
	 */
	private function storeInvitationAnswer(\Tachyon\Model\Account $oAccount,
		\Tachyon\Providers\Calendar\Invitation $oInvitation, string $sEmail, string $sPartstat) : string
	{
		$oProvider = $this->CalendarProvider($oAccount);
		if (!$oProvider->IsActive()) {
			return '';
		}
		try {
			$sUid = $oInvitation->Describe(array($sEmail))['Uid'];
			$aFound = $oProvider->FindEventByUid($sUid);
			$oStored = $aFound ? $aFound[1]->VCalendar() : null;

			if ('DECLINED' === $sPartstat) {
				if (!$aFound) {
					return '';
				}
				$oRest = $oStored ? $oInvitation->WithoutDates($oStored) : null;
				if ($oRest) {
					return $this->saveInvitationCopy($oProvider, $aFound[0]->Uuid, $sUid, $oRest);
				}
				return $oProvider->DeleteEvent($aFound[0]->Uuid, $sUid) ? '' : 'The calendar server refused to remove the event';
			}

			$sCalendarUuid = $aFound ? $aFound[0]->Uuid : (string) $this->GetActionParam('Calendar', '');
			if (!\strlen($sCalendarUuid)) {
				return '';
			}
			$oCopy = $oInvitation->StoredCopy($oStored, $sEmail, $sPartstat);
			return $oCopy ? $this->saveInvitationCopy($oProvider, $sCalendarUuid, $sUid, $oCopy) : '';
		} catch (\Throwable $oException) {
			\Tachyon\Util\Log::warning('Calendar', 'Storing the answer failed: ' . $oException->getMessage());
			return $oException->getMessage();
		}
	}

	private function saveInvitationCopy(\Tachyon\Providers\Calendar $oProvider, string $sCalendarUuid,
		string $sUid, \Sabre\VObject\Component\VCalendar $oVCalendar) : string
	{
		$oEvent = new \Tachyon\Providers\Calendar\Classes\Event;
		$oEvent->Uid = $sUid;
		$oEvent->setVCalendar($oVCalendar);
		return $oProvider->EventSave($sCalendarUuid, $oEvent) ? '' : 'The calendar server rejected the event';
	}

	/**
	 * Mail a METHOD:REPLY to the organizer from the identity that was invited
	 * (RFC 6047 2.4). Subject and text come from the client, in the user's
	 * language.
	 */
	private function sendInvitationReply(\Tachyon\Model\Account $oAccount, \Tachyon\Model\Identity $oIdentity,
		string $sOrganizerEmail, string $sOrganizerName, \Sabre\VObject\Component\VCalendar $oReply,
		string $sPartstat, string $sSummary) : void
	{
		$oMessage = new \MailSo\Mime\Message();
		if ($this->Config()->Get('security', 'hide_x_mailer_header', true)) {
			$oMessage->DoesNotAddDefaultXMailer();
		}
		$oMessage->SetFrom(new \MailSo\Mime\Email($oIdentity->Email(), $oIdentity->Name()));
		$oFrom = $oMessage->GetFrom();
		$oMessage->RegenerateMessageId($oFrom ? $oFrom->GetDomain() : '');
		$oMessage->SetTo(new \MailSo\Mime\EmailCollection(array(
			new \MailSo\Mime\Email($sOrganizerEmail, $sOrganizerName)
		)));

		$sSubject = \trim((string) $this->GetActionParam('Subject', ''));
		$oMessage->SetSubject(\strlen($sSubject) ? $sSubject : \ucfirst(\strtolower($sPartstat)) . ': ' . $sSummary);

		// RFC 6047 2.4: the calendar goes in text/calendar with the method
		// repeated in the Content-Type; the text is for people whose client
		// does not understand it
		$oAlternative = new \MailSo\Mime\Part;
		$oAlternative->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE, 'multipart/alternative');
		$oAlternative->addPlain((string) $this->GetActionParam('Plain', ''));

		$oCalendarPart = new \MailSo\Mime\Part;
		$oCalendarPart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE, 'text/calendar; charset=utf-8; method=REPLY');
		$oCalendarPart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TRANSFER_ENCODING, 'quoted-printable');
		$oCalendarPart->Body = \MailSo\Base\StreamWrappers\Binary::CreateStream(
			\MailSo\Base\ResourceRegistry::CreateMemoryResourceFromString($oReply->serialize()),
			'convert.quoted-printable-encode'
		);
		$oAlternative->SubParts->append($oCalendarPart);
		$oMessage->SubParts->append($oAlternative);

		$this->Plugins()->RunHook('filter.build-invitation-reply', array($oMessage, $oAccount, $oReply));

		$rMessageStream = \MailSo\Base\ResourceRegistry::CreateMemoryResource();
		$iMessageStreamSize = \MailSo\Base\Utils::WriteStream(
			$oMessage->ToStream(true), $rMessageStream, 8192, true, true
		);
		if (false === $iMessageStreamSize) {
			throw new ClientException(\Tachyon\Notifications::CantSendMessage);
		}
		try {
			$this->smtpSendMessage($oAccount, $oMessage, $rMessageStream, $iMessageStreamSize, false);

			// A copy goes to Sent as compose would put it: the identity's folder,
			// else the account's as the client knows it. The answer has already
			// gone out, so a failed copy is only logged
			$sSentFolder = (string) $oIdentity->sSentFolder ?: (string) $this->GetActionParam('SentFolder', '');
			if (\strlen($sSentFolder) && '__UNUSE__' !== $sSentFolder) {
				try {
					$this->initMailClientConnection();
					\rewind($rMessageStream);
					$this->Plugins()->RunHook('filter.send-message-stream',
						array($oAccount, &$rMessageStream, &$iMessageStreamSize));
					$this->ImapClient()->MessageAppendStream($sSentFolder, $rMessageStream, $iMessageStreamSize,
						array(\MailSo\Imap\Enumerations\MessageFlag::SEEN));
				} catch (\Throwable $oException) {
					$this->logException($oException, \LOG_ERR);
				}
			}
		} finally {
			\is_resource($rMessageStream) && \fclose($rMessageStream);
		}
	}

	/**
	 * For the calendar's editor: whether the user is invited to this event,
	 * and their answer on the date opened (RecurrenceId) or the series.
	 */
	public function DoCalendarEventInvitation() : array
	{
		$oAccount = $this->getAccountFromToken();
		[$oInvitation, $sEmail, $iRecurrenceId] = $this->storedInvitationFromParams($oAccount, false);
		return $this->DefaultResponse($oInvitation ? array(
			'Invited' => true,
			'Email' => $sEmail,
			'Partstat' => $oInvitation->PartstatOn(array($sEmail), $iRecurrenceId),
			'Organizer' => $oInvitation->OrganizerEmail()
		) : array('Invited' => false));
	}

	/**
	 * Answer, from the calendar, an invitation stored there: the series, or one
	 * date of it (RecurrenceId). The reply goes out first, then the stored copy
	 * is updated; declining one date takes it out of the calendar.
	 */
	public function DoCalendarEventAnswer() : array
	{
		$oAccount = $this->getAccountFromToken();
		$sPartstat = \strtoupper((string) $this->GetActionParam('Partstat', ''));
		if (!\in_array($sPartstat, \Tachyon\Providers\Calendar\Invitation::PARTSTATS, true)) {
			throw new ClientException(\Tachyon\Notifications::InvalidInputArgument, null, 'Unknown answer');
		}
		[$oInvitation, $sEmail, $iRecurrenceId, $oEvent, $oStored] = $this->storedInvitationFromParams($oAccount, true);

		$oReply = $oInvitation->Reply($sEmail, $sPartstat, $iRecurrenceId);
		if (!$oReply) {
			throw new ClientException(\Tachyon\Notifications::CantSendMessage, null, 'No reply could be made');
		}
		$oOrganizer = $oInvitation->Organizer();
		$oIdentity = $this->invitationIdentities($oAccount)[\strtolower($sEmail)];
		$this->sendInvitationReply($oAccount, $oIdentity, $oInvitation->OrganizerEmail(),
			isset($oOrganizer['CN']) ? (string) $oOrganizer['CN'] : '', $oReply, $sPartstat,
			(string) ($this->GetActionParam('Summary', '') ?: ($oInvitation->Event()->SUMMARY ?? '')));

		$sCalendarError = '';
		try {
			$oEvent->setVCalendar(\Tachyon\Providers\Calendar\Invitation::AnswerStored($oStored, $sEmail, $sPartstat, $iRecurrenceId));
			$this->CalendarProvider($oAccount)->EventSave((string) $this->GetActionParam('Calendar', ''), $oEvent)
				|| $sCalendarError = 'The calendar server rejected the event';
		} catch (\Throwable $oException) {
			$sCalendarError = $oException->getMessage();
		}
		return $this->TrueResponse(array('Partstat' => $sPartstat, 'CalendarError' => $sCalendarError));
	}

	/**
	 * The stored event the editor names (Calendar, Uid, RecurrenceId), read as
	 * an invitation to one of the user's addresses from someone else.
	 *
	 * @return array{0: ?\Tachyon\Providers\Calendar\Invitation, 1: string, 2: ?int, 3: ?\Tachyon\Providers\Calendar\Classes\Event, 4: ?\Sabre\VObject\Component\VCalendar}
	 */
	private function storedInvitationFromParams(\Tachyon\Model\Account $oAccount, bool $bRequired) : array
	{
		$oProvider = $this->CalendarProvider($oAccount);
		$iRecurrenceId = (int) $this->GetActionParam('RecurrenceId', 0) ?: null;
		$oEvent = $oProvider->IsActive() ? $oProvider->GetEventByUid(
			(string) $this->GetActionParam('Calendar', ''), (string) $this->GetActionParam('Uid', '')) : null;
		$oStored = $oEvent ? $oEvent->VCalendar() : null;
		$oInvitation = $oStored ? \Tachyon\Providers\Calendar\Invitation::fromVCalendar($oStored) : null;
		$aEmails = \array_keys($this->invitationIdentities($oAccount));
		$aMatch = $oInvitation ? $oInvitation->FindAttendee($aEmails) : null;
		if (!$aMatch || !\strlen($oInvitation->OrganizerEmail()) || $oInvitation->IsOrganizer($aEmails)) {
			if ($bRequired) {
				throw new ClientException(\Tachyon\Notifications::InvalidInputArgument, null, 'Not an invitation to you');
			}
			return array(null, '', null, null, null);
		}
		return array($oInvitation, $aMatch[1], $iRecurrenceId, $oEvent, $oStored);
	}

	/**
	 * A cancellation of an event the calendar holds: take it out, or only the
	 * cancelled dates of a series.
	 */
	public function DoCalendarInvitationRemove() : array
	{
		$oAccount = $this->getAccountFromToken();
		$oInvitation = $this->invitationFromParams();
		$aEmails = \array_keys($this->invitationIdentities($oAccount));
		if ('CANCEL' !== $oInvitation->Method()) {
			throw new ClientException(\Tachyon\Notifications::InvalidInputArgument, null, 'Not a cancellation');
		}
		$oProvider = $this->CalendarProvider($oAccount);
		$sUid = $oInvitation->Describe($aEmails)['Uid'];
		$aFound = $oProvider->IsActive() ? $oProvider->FindEventByUid($sUid) : null;
		if ($aFound) {
			$oStored = $aFound[1]->VCalendar();
			$oRest = $oStored ? $oInvitation->WithoutDates($oStored) : null;
			$sError = $oRest
				? $this->saveInvitationCopy($oProvider, $aFound[0]->Uuid, $sUid, $oRest)
				: ($oProvider->DeleteEvent($aFound[0]->Uuid, $sUid) ? '' : 'The calendar server refused to remove the event');
			if (\strlen($sError)) {
				throw new ClientException(\Tachyon\Notifications::CantSaveMessage, null, $sError);
			}
		}
		return $this->DefaultResponse($this->describeInvitation($oAccount, $oInvitation, $aEmails));
	}

	/**
	 * Answer an invitation: send the METHOD:REPLY to the organizer, from the
	 * identity it was sent to.
	 */
	public function DoCalendarInvitationReply() : array
	{
		$oAccount = $this->getAccountFromToken();
		$oInvitation = $this->invitationFromParams();
		$sPartstat = \strtoupper((string) $this->GetActionParam('Partstat', ''));
		if (!\in_array($sPartstat, \Tachyon\Providers\Calendar\Invitation::PARTSTATS, true)) {
			throw new ClientException(\Tachyon\Notifications::InvalidInputArgument, null, 'Unknown answer');
		}

		$aIdentities = $this->invitationIdentities($oAccount);
		$aInfo = $this->describeInvitation($oAccount, $oInvitation, \array_keys($aIdentities));
		if (empty($aInfo['CanReply'])) {
			throw new ClientException(\Tachyon\Notifications::InvalidInputArgument, null, 'This invitation cannot be answered');
		}
		$sEmail = $aInfo['Attendee']['Email'];
		$oIdentity = $aIdentities[\strtolower($sEmail)];

		$oReply = $oInvitation->Reply($sEmail, $sPartstat);
		if (!$oReply) {
			throw new ClientException(\Tachyon\Notifications::CantSendMessage, null, 'No reply could be made');
		}

		$this->sendInvitationReply($oAccount, $oIdentity, $aInfo['Organizer']['Email'], $aInfo['Organizer']['Name'],
			$oReply, $sPartstat, $aInfo['Summary']);

		// The answer has gone out whatever happens to the calendar copy, so a
		// failure there is reported beside the success rather than instead of it
		$sCalendarError = $this->storeInvitationAnswer($oAccount, $oInvitation, $sEmail, $sPartstat);

		return $this->TrueResponse(array(
			'Partstat' => $sPartstat,
			'CalendarError' => $sCalendarError,
			'Invitation' => $this->describeInvitation($oAccount, $oInvitation, \array_keys($aIdentities))
		));
	}

	/**
	 * One VCALENDAR per UID, keyed by it.
	 *
	 * A file can hold many unrelated events, and an event can be several
	 * components: a master plus one override per modified occurrence, which share
	 * a UID and have to stay together to mean anything. Each result carries the
	 * VTIMEZONEs its own components reference, because a DTSTART with a TZID
	 * whose definition was left behind in the original file resolves to the wrong
	 * moment rather than to an error.
	 */
	private static function splitVCalendar(\Sabre\VObject\Component\VCalendar $oSource) : array
	{
		$aTimezones = array();
		foreach ($oSource->VTIMEZONE ?? array() as $oTimezone) {
			if (isset($oTimezone->TZID)) {
				$aTimezones[(string) $oTimezone->TZID] = $oTimezone;
			}
		}

		$aByUid = array();
		foreach ($oSource->VEVENT ?? array() as $oVEvent) {
			$sUid = isset($oVEvent->UID) ? \trim((string) $oVEvent->UID) : '';
			if (!\strlen($sUid)) {
				continue;
			}
			$aByUid[$sUid][] = $oVEvent;
		}

		$aResult = array();
		foreach ($aByUid as $sUid => $aComponents) {
			// Built fresh rather than by pruning a copy of the source, which is
			// also how METHOD is dropped: REQUEST means "an invitation in flight"
			// and what gets stored is a calendar entry, one some CalDAV servers
			// refuse to accept while the method is still on it.
			$oTarget = new \Sabre\VObject\Component\VCalendar();
			$aNeeded = array();
			foreach ($aComponents as $oVEvent) {
				$oTarget->add(clone $oVEvent);
				foreach (static::timezoneIdsOf($oVEvent) as $sTzid) {
					$aNeeded[$sTzid] = true;
				}
			}
			foreach (\array_keys($aNeeded) as $sTzid) {
				if (isset($aTimezones[$sTzid])) {
					$oTarget->add(clone $aTimezones[$sTzid]);
				}
			}
			$aResult[$sUid] = $oTarget;
		}

		return $aResult;
	}

	/**
	 * Every TZID named by the date properties of one component.
	 */
	private static function timezoneIdsOf(\Sabre\VObject\Component $oComponent) : array
	{
		$aIds = array();
		foreach (array('DTSTART', 'DTEND', 'RECURRENCE-ID', 'EXDATE', 'RDATE') as $sName) {
			foreach ($oComponent->select($sName) as $oProperty) {
				$sTzid = (string) $oProperty['TZID'];
				if (\strlen($sTzid)) {
					$aIds[] = $sTzid;
				}
			}
		}
		return \array_unique($aIds);
	}

	/**
	 * The UID of the master VEVENT, the one without a RECURRENCE-ID.
	 */
	private static function uidFromVCalendar(?\Sabre\VObject\Component\VCalendar $oVCalendar) : string
	{
		foreach ($oVCalendar->VEVENT ?? array() as $oVEvent) {
			if (!isset($oVEvent->{'RECURRENCE-ID'}) && isset($oVEvent->UID)) {
				return \trim((string) $oVEvent->UID);
			}
		}
		return '';
	}

	public function DoCalendarEventDelete() : array
	{
		$oAccount = $this->getAccountFromToken();
		$oProvider = $this->CalendarProvider($oAccount);
		if (!$oProvider->IsActive()) {
			return $this->FalseResponse();
		}

		$sCalendarUuid = (string) $this->GetActionParam('Calendar', '');
		$sUid = (string) $this->GetActionParam('Uid', '');
		$iRecurrenceId = (int) $this->GetActionParam('RecurrenceId', 0);
		try {
			if (0 < $iRecurrenceId) {
				// One occurrence of a series: the series stays, without it
				$oEvent = $oProvider->GetEventByUid($sCalendarUuid, $sUid);
				$oVCalendar = $oEvent ? $oEvent->VCalendar() : null;
				if (!$oVCalendar) {
					throw new \ValueError('Unknown event');
				}
				$oVCalendar = clone $oVCalendar;
				$bWhole = false;
				try {
					if ('following' === $this->GetActionParam('Scope', '')) {
						// End the series before it; from the first on is all of it
						$bWhole = null === \Tachyon\Providers\Calendar\EventEditor::splitAt(
							$oVCalendar, $iRecurrenceId, \Tachyon\Util\UUID::generate());
					} else {
						\Tachyon\Providers\Calendar\EventEditor::excludeOccurrence($oVCalendar, $iRecurrenceId);
					}
				} catch (\InvalidArgumentException $oException) {
					throw new \ValueError($oException->getMessage());
				}
				if ($bWhole) {
					$bResult = $oProvider->DeleteEvent($sCalendarUuid, $sUid);
				} else {
					$oEvent->setVCalendar($oVCalendar);
					$bResult = $oProvider->EventSave($sCalendarUuid, $oEvent);
				}
			} else {
				$bResult = $oProvider->DeleteEvent($sCalendarUuid, $sUid);
			}
		} catch (\ValueError $oException) {
			throw new ClientException(\Tachyon\Notifications::CantDeleteMessage, $oException, $oException->getMessage());
		}

		if (!$bResult) {
			throw new ClientException(\Tachyon\Notifications::CantDeleteMessage, null,
				'The calendar server refused to delete the event');
		}

		return $this->TrueResponse();
	}

	public function DoCalendarSync() : array
	{
		$oAccount = $this->getAccountFromToken();
		$oProvider = $this->CalendarProvider($oAccount);
		if (!$oProvider->IsActive()) {
			return $this->FalseResponse();
		}
		// Reported rather than a bare true: a sync that lost events used to look
		// exactly like a clean one, so the only sign was the debug log.
		$bResult = $oProvider->Sync();
		return $this->DefaultResponse($bResult
			? ['Result' => true, 'Skipped' => $oProvider->SyncSkipped()]
			: false);
	}

	public function DoSaveCalendarSyncData() : array
	{
		$oAccount = $this->getAccountFromToken();
		$oProvider = $this->CalendarProvider($oAccount);
		if (!$oProvider->IsActive()) {
			return $this->FalseResponse();
		}

		$sPassword = $this->GetActionParam('Password', '');
		$mData = $this->getCalendarSyncData($oAccount);

		$iMode = \intval($this->GetActionParam('Mode', '0'));
		$sUrl = $this->GetActionParam('Url', '');

		$bResult = $this->setCalendarSyncData($oAccount, array(
			'Mode' => $iMode,
			'User' => $this->GetActionParam('User', ''),
			// The client never receives the stored password, only a placeholder
			'Password' => static::APP_DUMMY === $sPassword
				? (isset($mData['Password']) ? $mData['Password'] : '')
				: $sPassword,
			'Url' => $sUrl
		));

		/**
		 * Clearing the URL or switching sync off has to take the calendars with
		 * it. Sync() is what prunes what the server no longer offers, and it will
		 * never run again, so this is the last chance to remove them.
		 *
		 * Deliberately here and not wherever a disabled config is noticed: a
		 * password that can no longer be decrypted also reads as disabled, and
		 * that must not delete anything. This is an explicit save by the user.
		 */
		if ($bResult && (!$iMode || !\strlen(\trim($sUrl)))) {
			$oProvider->PurgeCalendars(array());
		}

		return $this->DefaultResponse($bResult);
	}

	public function DoTestCalendarSyncData() : array
	{
		$oAccount = $this->getAccountFromToken();
		$oProvider = $this->CalendarProvider($oAccount);
		if (!$oProvider->IsActive()) {
			throw new ClientException(\Tachyon\Notifications::CalendarSyncError, null,
				'The calendar backend is not available');
		}

		$sPassword = $this->GetActionParam('Password', '');
		$mData = $this->getCalendarSyncData($oAccount);

		$oProvider->setDAVClientConfig(array(
			'Mode' => \intval($this->GetActionParam('Mode', '0')) ?: 2,
			'User' => $this->GetActionParam('User', ''),
			'Password' => static::APP_DUMMY === $sPassword
				? (isset($mData['Password']) ? $mData['Password'] : '')
				: $sPassword,
			'Url' => $this->GetActionParam('Url', '')
		));

		// The outcome has to travel as the response's own Result. Returning it
		// inside the payload makes every answer look like success to the client,
		// because a non-empty array is truthy.
		$sError = $oProvider->Test();
		if ('' !== $sError) {
			throw new ClientException(\Tachyon\Notifications::CalendarSyncError, null, $sError);
		}

		return $this->TrueResponse();
	}

	/**
	 * Builds an iCalendar body from plain fields, for a client that does not want
	 * to compose one itself. An event that exists is edited in place, so what the
	 * editor does not show (attendees, alarms, the exceptions of a series)
	 * survives the save; see Providers\Calendar\EventEditor.
	 */
	private function vCalendarFromParams(string $sUid, string $sCalendarUuid,
		\Tachyon\Providers\Calendar $oProvider, ?\Sabre\VObject\Component\VCalendar &$oSplitOff = null) : \Sabre\VObject\Component\VCalendar
	{
		$iStart = (int) $this->GetActionParam('Start', 0);
		$iEnd = (int) $this->GetActionParam('End', 0);
		if (1 > $iStart) {
			throw new ClientException(\Tachyon\Notifications::InvalidInputArgument, null,
				'The start date is missing or could not be read');
		}
		if ($iEnd < $iStart) {
			throw new ClientException(\Tachyon\Notifications::InvalidInputArgument, null,
				'The event ends before it starts');
		}

		// The zone a new event is written in: the calendar's own, else the
		// browser's. Falling back to the server's (usually UTC) made a weekly
		// 09:00 move by an hour at every change to or from summer time.
		$oCalendar = $oProvider->GetCalendarByUuid($sCalendarUuid);
		$oTimeZone = null;
		foreach (array($oCalendar ? $oCalendar->Timezone : '', (string) $this->GetActionParam('Timezone', ''),
			\date_default_timezone_get()) as $sTimezone) {
			if (\strlen($sTimezone)) {
				try {
					$oTimeZone = new \DateTimeZone($sTimezone);
					break;
				} catch (\Throwable $oException) {
					// An unknown name, try the next
				}
			}
		}
		$oTimeZone = $oTimeZone ?: new \DateTimeZone('UTC');

		$oExisting = $oProvider->GetEventByUid($sCalendarUuid, $sUid);
		$oVCalendar = $oExisting ? $oExisting->VCalendar() : null;
		if ($oVCalendar) {
			$oVCalendar = clone $oVCalendar;
		} else {
			$oVCalendar = new \Sabre\VObject\Component\VCalendar();
			$oVCalendar->add('VEVENT', array('UID' => $sUid));
		}

		// Null leaves a field as stored: a drag sends only the times
		$fText = function (string $sName) : ?string {
			$mValue = $this->GetActionParam($sName, null);
			return null === $mValue ? null : (string) $mValue;
		};
		$aFields = array(
			'summary' => $fText('Summary'),
			'location' => $fText('Location'),
			'description' => $fText('Description'),
			'start' => $iStart,
			'end' => $iEnd,
			'allDay' => !empty($this->GetActionParam('AllDay', 0)),
			'rrule' => $fText('Rrule')
		);
		// One occurrence of a series, named by its original start
		$iRecurrenceId = (int) $this->GetActionParam('RecurrenceId', 0);
		try {
			if (0 < $iRecurrenceId && !$oExisting) {
				throw new \InvalidArgumentException('Unknown event');
			}
			$bFollowing = 0 < $iRecurrenceId && 'following' === $this->GetActionParam('Scope', '');
			// From the first occurrence on is the whole series
			$oSplitOff = $bFollowing ? \Tachyon\Providers\Calendar\EventEditor::splitAt(
				$oVCalendar, $iRecurrenceId, \Tachyon\Util\UUID::generate()) : null;
			if ($oSplitOff) {
				\Tachyon\Providers\Calendar\EventEditor::apply($oSplitOff, $aFields, $oTimeZone);
			} else if (0 < $iRecurrenceId && !$bFollowing) {
				unset($aFields['rrule']);
				\Tachyon\Providers\Calendar\EventEditor::applyOccurrence($oVCalendar, $iRecurrenceId, $aFields, $oTimeZone);
			} else {
				\Tachyon\Providers\Calendar\EventEditor::apply($oVCalendar, $aFields, $oTimeZone);
			}
		} catch (\InvalidArgumentException $oException) {
			throw new ClientException(\Tachyon\Notifications::InvalidInputArgument, $oException, $oException->getMessage());
		}

		return $oVCalendar;
	}

	public function setCalendarSyncData(\Tachyon\Model\Account $oAccount, array $aData) : bool
	{
		if (!isset($aData['Mode'])) {
			$aData['Mode'] = empty($aData['Enable']) ? 0 : 1;
		}
		$oMainAccount = $this->getMainAccountFromToken();
		if ($aData['Password']) {
			$aData['Password'] = \Tachyon\Util\Crypt::EncryptToJSON($aData['Password'], $oMainAccount->CryptKey());
		}
		$aData['PasswordHMAC'] = $aData['Password']
			? \hash_hmac('sha1', $aData['Password'], $oMainAccount->CryptKey())
			: null;

		return $this->StorageProvider()->Put(
			$oAccount,
			\Tachyon\Providers\Storage\Enumerations\StorageType::CONFIG,
			'calendar_sync',
			\json_encode($aData)
		);
	}

	protected function getCalendarSyncData(\Tachyon\Model\Account $oAccount) : ?array
	{
		$sData = $this->StorageProvider()->Get($oAccount,
			\Tachyon\Providers\Storage\Enumerations\StorageType::CONFIG,
			'calendar_sync'
		);
		if (empty($sData)) {
			return null;
		}

		$aData = \json_decode($sData, true);
		if (!$aData) {
			return null;
		}

		if (!empty($aData['Password'])) {
			$oMainAccount = $this->getMainAccountFromToken();
			// If the account password changed, the stored one can no longer be read
			if (($aData['PasswordHMAC'] ?? null) !== \hash_hmac('sha1', $aData['Password'], $oMainAccount->CryptKey())) {
				$aData['Password'] = null;
			} else {
				$aData['Password'] = \Tachyon\Util\Crypt::DecryptFromJSON(
					$aData['Password'],
					$oMainAccount->CryptKey()
				);
			}
		}

		return $aData;
	}
}
