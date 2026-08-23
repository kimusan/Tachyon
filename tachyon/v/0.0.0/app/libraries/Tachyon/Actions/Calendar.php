<?php

namespace Tachyon\Actions;

use Tachyon\Enumerations\Capa;
use Tachyon\Exceptions\ClientException;

trait Calendar
{
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
		} else {
			$oEvent->setVCalendar($this->vCalendarFromParams($oEvent->Uid, $sCalendarUuid, $oProvider));
		}

		try {
			$bResult = $oProvider->EventSave($sCalendarUuid, $oEvent);
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

	public function DoCalendarEventDelete() : array
	{
		$oAccount = $this->getAccountFromToken();
		$oProvider = $this->CalendarProvider($oAccount);
		if (!$oProvider->IsActive()) {
			return $this->FalseResponse();
		}

		try {
			$bResult = $oProvider->DeleteEvent(
				(string) $this->GetActionParam('Calendar', ''),
				(string) $this->GetActionParam('Uid', '')
			);
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
		return $this->DefaultResponse($oProvider->Sync());
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

		return $this->DefaultResponse($this->setCalendarSyncData($oAccount, array(
			'Mode' => \intval($this->GetActionParam('Mode', '0')),
			'User' => $this->GetActionParam('User', ''),
			// The client never receives the stored password, only a placeholder
			'Password' => static::APP_DUMMY === $sPassword
				? (isset($mData['Password']) ? $mData['Password'] : '')
				: $sPassword,
			'Url' => $this->GetActionParam('Url', '')
		)));
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
	 * to compose one itself.
	 */
	private function vCalendarFromParams(string $sUid, string $sCalendarUuid,
		\Tachyon\Providers\Calendar $oProvider) : \Sabre\VObject\Component\VCalendar
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

		$bAllDay = !empty($this->GetActionParam('AllDay', 0));

		$oCalendar = $oProvider->GetCalendarByUuid($sCalendarUuid);
		$sTimezone = $oCalendar && \strlen($oCalendar->Timezone)
			? $oCalendar->Timezone
			: (\date_default_timezone_get() ?: 'UTC');

		try {
			$oTimeZone = new \DateTimeZone($sTimezone);
		} catch (\Throwable $oException) {
			$oTimeZone = new \DateTimeZone('UTC');
		}

		$oVCalendar = new \Sabre\VObject\Component\VCalendar();
		$oVEvent = $oVCalendar->add('VEVENT', array(
			'UID' => $sUid,
			'SUMMARY' => (string) $this->GetActionParam('Summary', ''),
			'DTSTAMP' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
		));

		$sDescription = (string) $this->GetActionParam('Description', '');
		if (\strlen($sDescription)) {
			$oVEvent->add('DESCRIPTION', $sDescription);
		}
		$sLocation = (string) $this->GetActionParam('Location', '');
		if (\strlen($sLocation)) {
			$oVEvent->add('LOCATION', $sLocation);
		}

		$oStart = (new \DateTimeImmutable('@' . $iStart))->setTimezone($oTimeZone);
		$oEnd = (new \DateTimeImmutable('@' . $iEnd))->setTimezone($oTimeZone);

		if ($bAllDay) {
			// Date only, so the event cannot acquire a time through a conversion
			$oVEvent->add('DTSTART', $oStart, array('VALUE' => 'DATE'));
			$oVEvent->add('DTEND', $oEnd, array('VALUE' => 'DATE'));
		} else {
			$oVEvent->add('DTSTART', $oStart);
			$oVEvent->add('DTEND', $oEnd);
		}

		$sRrule = \trim((string) $this->GetActionParam('Rrule', ''));
		if (\strlen($sRrule)) {
			$oVEvent->add('RRULE', $sRrule);
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
