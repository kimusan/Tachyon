<?php

namespace Tachyon\Providers\Calendar;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Recur\EventIterator;
use Tachyon\Providers\Calendar\Classes\Calendar;
use Tachyon\Providers\Calendar\Classes\Event;

class PdoCalendar
	extends \Tachyon\Pdo\Base
	implements CalendarInterface
{
	use CalDAV;

	/** Events and calendars the last Sync() could not store */
	private int $iSyncSkipped = 0;

	private int $iUserID = 0;

	private \Tachyon\Pdo\Settings $settings;

	private int $iMaxOccurrences = 1000;

	public function __construct()
	{
		$oConfig = \Tachyon\Api::Config();
		$oSettings = new \Tachyon\Pdo\Settings;
		$oSettings->driver = static::validPdoType($oConfig->Get('calendar', 'type', 'sqlite'));

		if ('sqlite' === $oSettings->driver) {
			$sDsn = 'sqlite:' . APP_PRIVATE_DATA . 'Calendar.sqlite';
			if (!$oConfig->Get('calendar', 'sqlite_global', false)) {
				$oAccount = \Tachyon\Api::Actions()->getMainAccountFromToken(false);
				if ($oAccount) {
					$sHomeDir = \Tachyon\Api::Actions()->StorageProvider()->GenerateFilePath(
						$oAccount,
						\Tachyon\Providers\Storage\Enumerations\StorageType::ROOT
					);
					$sDsn = 'sqlite:' . $sHomeDir . 'Calendar.sqlite';
				}
			}
		} else {
			$sDsn = \trim($oConfig->Get('calendar', 'pdo_dsn', ''));
			$oSettings->user = \trim($oConfig->Get('calendar', 'pdo_user', ''));
			$oSettings->password = (string) $oConfig->Get('calendar', 'pdo_password', '');
			$sDsn = $oSettings->driver . ':' . \preg_replace('/^[a-z]+:/', '', $sDsn);
			if ('mysql' === $oSettings->driver) {
				$oSettings->sslCa = \trim($oConfig->Get('calendar', 'mysql_ssl_ca', ''));
				$oSettings->sslVerify = !!$oConfig->Get('calendar', 'mysql_ssl_verify', true);
				$oSettings->sslCiphers = \trim($oConfig->Get('calendar', 'mysql_ssl_ciphers', ''));
			}
		}

		$oSettings->dsn = $sDsn;
		$this->settings = $oSettings;
		$this->iMaxOccurrences = \max(1, (int) $oConfig->Get('calendar', 'max_occurrences', 1000));
	}

	public static function validPdoType(string $sType) : string
	{
		$sType = \trim($sType);
		return \in_array($sType, static::getAvailableDrivers()) ? $sType : 'sqlite';
	}

	public function IsSupported() : bool
	{
		$aDrivers = static::getAvailableDrivers();
		return \is_array($aDrivers) && \in_array($this->settings->driver, $aDrivers);
	}

	public function SetEmail(string $sEmail) : bool
	{
		$this->iUserID = $this->getUserId($sEmail);
		return 0 < $this->iUserID;
	}

	public function Test() : string
	{
		try {
			$this->SyncDatabase();
			if (!$this->isDAVEnabled()) {
				return '';
			}
			$oClient = $this->getDavClient();
			if (!$oClient) {
				return 'No DAV client';
			}
			$aCalendars = $this->getCalendarPaths(
				$oClient,
				$oClient->urlPath,
				$this->aDAVConfig['User'],
				$this->aDAVConfig['Password']
			);
			return \count($aCalendars) ? '' : 'No calendars found at this address';
		} catch (\Throwable $oException) {
			return $oException->getMessage();
		}
	}

	/* ----------------------------------------------------------------- read */

	public function GetCalendars() : array
	{
		$this->SyncDatabase();

		$aResult = array();
		$oStmt = $this->prepareAndExecute(
			'SELECT id_calendar, uuid, name, color, description, timezone, dav_path, read_only'
			. ' FROM tachyon_cal_calendars WHERE id_user = :id_user AND deleted = 0 ORDER BY name ASC',
			array(':id_user' => array($this->iUserID, \PDO::PARAM_INT))
		);

		if ($oStmt) {
			foreach ($oStmt->fetchAll(\PDO::FETCH_ASSOC) as $aRow) {
				$aResult[] = static::calendarFromRow($aRow);
			}
		}

		return $aResult;
	}

	public function GetCalendarByUuid(string $sUuid) : ?Calendar
	{
		$this->SyncDatabase();

		$oStmt = $this->prepareAndExecute(
			'SELECT id_calendar, uuid, name, color, description, timezone, dav_path, read_only'
			. ' FROM tachyon_cal_calendars WHERE id_user = :id_user AND deleted = 0 AND uuid = :uuid',
			array(
				':id_user' => array($this->iUserID, \PDO::PARAM_INT),
				':uuid' => array($sUuid, \PDO::PARAM_STR)
			)
		);

		$aRow = $oStmt ? $oStmt->fetch(\PDO::FETCH_ASSOC) : null;
		return $aRow ? static::calendarFromRow($aRow) : null;
	}

	/**
	 * The range query that recurrence makes awkward. A weekly event that started
	 * two years ago still has occurrences in the window, so filtering on dtstart
	 * alone silently loses it. Non recurring events are filtered by overlap;
	 * recurring ones are taken whenever they could still be running, which is what
	 * recur_until is for, and then expanded and filtered in PHP.
	 */
	public function GetOccurrences(array $aCalendarUuids, int $iStart, int $iEnd) : array
	{
		$this->SyncDatabase();

		$aCalendars = array();
		foreach ($this->GetCalendars() as $oCalendar) {
			if (!$aCalendarUuids || \in_array($oCalendar->Uuid, $aCalendarUuids)) {
				$aCalendars[$oCalendar->id] = $oCalendar;
			}
		}

		if (!$aCalendars) {
			return array();
		}

		$aIds = \array_map('\\intval', \array_keys($aCalendars));

		$oStmt = $this->prepareAndExecute(
			'SELECT id_event, id_calendar, uid, summary, description, location,'
			. ' dtstart, dtend, all_day, rrule, recur_until, timezone, ical'
			. ' FROM tachyon_cal_events'
			. ' WHERE id_user = :id_user AND deleted = 0'
			. ' AND id_calendar IN (' . \implode(',', $aIds) . ')'
			. " AND ( (rrule = '' AND dtend >= :start AND dtstart <= :end)"
			. "    OR (rrule <> '' AND (recur_until IS NULL OR recur_until >= :start) AND dtstart <= :end) )",
			array(
				':id_user' => array($this->iUserID, \PDO::PARAM_INT),
				':start' => array($iStart, \PDO::PARAM_INT),
				':end' => array($iEnd, \PDO::PARAM_INT)
			)
		);

		$aResult = array();
		if ($oStmt) {
			foreach ($oStmt->fetchAll(\PDO::FETCH_ASSOC) as $aRow) {
				$oCalendar = $aCalendars[(string) $aRow['id_calendar']] ?? null;
				if (!$oCalendar) {
					continue;
				}
				foreach ($this->expandRow($aRow, $oCalendar, $iStart, $iEnd) as $aOccurrence) {
					$aResult[] = $aOccurrence;
				}
			}
		}

		return $aResult;
	}

	/**
	 * @return array occurrences of one stored event overlapping the window
	 */
	private function expandRow(array $aRow, Calendar $oCalendar, int $iStart, int $iEnd) : array
	{
		$aBase = array(
			'uid' => (string) $aRow['uid'],
			'calendarUuid' => $oCalendar->Uuid,
			'title' => (string) $aRow['summary'],
			'description' => (string) $aRow['description'],
			'location' => (string) $aRow['location'],
			'allDay' => !empty($aRow['all_day']),
			'color' => $oCalendar->Color,
			'readOnly' => $oCalendar->ReadOnly,
			'recurring' => '' !== (string) $aRow['rrule']
		);

		if ('' === (string) $aRow['rrule']) {
			return array($aBase + array(
				'id' => $aRow['uid'] . '@' . (int) $aRow['dtstart'],
				'start' => (int) $aRow['dtstart'],
				'end' => (int) $aRow['dtend']
			));
		}

		$oEvent = new Event;
		$oEvent->Uid = (string) $aRow['uid'];
		$oEvent->setIcal((string) $aRow['ical']);

		$oVCalendar = $oEvent->VCalendar();
		if (!$oVCalendar) {
			return array();
		}

		$aResult = array();
		try {
			/**
			 * Passing the whole VCALENDAR plus the UID lets Sabre apply
			 * RECURRENCE-ID overrides, which a single VEVENT cannot express.
			 * The timezone argument only affects floating times.
			 */
			$oIterator = new EventIterator($oVCalendar, $oEvent->Uid, static::timezoneFor($aRow, $oCalendar));

			$oWindowStart = (new \DateTimeImmutable)->setTimestamp($iStart);
			$oWindowEnd = (new \DateTimeImmutable)->setTimestamp($iEnd);

			$oIterator->fastForward($oWindowStart);

			$iCount = 0;
			while ($oIterator->valid() && $iCount < $this->iMaxOccurrences) {
				$oOccStart = $oIterator->getDtStart();
				// Nullable per Sabre's signature, and a series with no start is unusable
				if (!$oOccStart || $oOccStart > $oWindowEnd) {
					break;
				}
				// Ask the iterator for the end rather than reusing a stored duration,
				// since an overridden instance may be longer or shorter than the rest.
				$oOccEnd = $oIterator->getDtEnd();
				$aResult[] = $aBase + array(
					'id' => $oEvent->Uid . '@' . $oOccStart->getTimestamp(),
					'start' => $oOccStart->getTimestamp(),
					'end' => $oOccEnd ? $oOccEnd->getTimestamp() : $oOccStart->getTimestamp()
				);
				++$iCount;
				$oIterator->next();
			}

			if ($iCount >= $this->iMaxOccurrences) {
				$this->logWrite("Occurrence cap hit for {$oEvent->Uid}", \LOG_WARNING, 'Calendar');
			}
		} catch (\Throwable $oException) {
			$this->logWrite("Cannot expand {$oEvent->Uid}: " . $oException->getMessage(), \LOG_WARNING, 'Calendar');
		}

		return $aResult;
	}

	public function GetEventByUid(string $sCalendarUuid, string $sUid) : ?Event
	{
		$this->SyncDatabase();

		$oCalendar = $this->GetCalendarByUuid($sCalendarUuid);
		if (!$oCalendar) {
			return null;
		}

		$oStmt = $this->prepareAndExecute(
			'SELECT id_event, id_calendar, uid, summary, description, location, dtstart, dtend,'
			. ' all_day, rrule, recur_until, timezone, ical, dav_path, etag, changed'
			. ' FROM tachyon_cal_events WHERE id_user = :id_user AND deleted = 0'
			. ' AND id_calendar = :id_calendar AND uid = :uid',
			array(
				':id_user' => array($this->iUserID, \PDO::PARAM_INT),
				':id_calendar' => array((int) $oCalendar->id, \PDO::PARAM_INT),
				':uid' => array($sUid, \PDO::PARAM_STR)
			)
		);

		$aRow = $oStmt ? $oStmt->fetch(\PDO::FETCH_ASSOC) : null;
		return $aRow ? static::eventFromRow($aRow) : null;
	}

	/* ---------------------------------------------------------------- write */

	public function EventSave(string $sCalendarUuid, Event $oEvent) : bool
	{
		$this->SyncDatabase();

		$oCalendar = $this->GetCalendarByUuid($sCalendarUuid);
		if (!$oCalendar) {
			throw new \ValueError('Unknown calendar');
		}
		if ($oCalendar->ReadOnly) {
			throw new \ValueError('Calendar is read only');
		}

		$oVCalendar = $oEvent->VCalendar();
		if (!$oVCalendar) {
			throw new \ValueError('Event has no iCalendar body');
		}

		$aMeta = $this->metaFromVCalendar($oVCalendar, $oEvent->Uid);
		$oEvent->Changed = \time();

		$oExisting = $this->GetEventByUid($sCalendarUuid, $oEvent->Uid);

		// Push before storing, so a rejection by the server leaves nothing behind
		if ($oCalendar->DavPath && $this->isDAVReadWrite()) {
			$sDavPath = $oExisting && $oExisting->DavPath ? $oExisting->DavPath : $oEvent->Uid . '.ics';
			$sEtag = $this->davPutEvent($oCalendar, $sDavPath, $oEvent->Ical());
			if (null === $sEtag) {
				return false;
			}
			$oEvent->DavPath = $sDavPath;
			$oEvent->Etag = $sEtag;
		}

		return $this->storeEvent((int) $oCalendar->id, $oEvent, $aMeta, $oExisting);
	}

	public function DeleteEvent(string $sCalendarUuid, string $sUid) : bool
	{
		$this->SyncDatabase();

		$oCalendar = $this->GetCalendarByUuid($sCalendarUuid);
		if (!$oCalendar) {
			return false;
		}
		if ($oCalendar->ReadOnly) {
			throw new \ValueError('Calendar is read only');
		}

		$oEvent = $this->GetEventByUid($sCalendarUuid, $sUid);
		if (!$oEvent) {
			return false;
		}

		if ($oCalendar->DavPath && $this->isDAVReadWrite() && $oEvent->DavPath) {
			$oClient = $this->getDavClient();
			if ($oClient) {
				$this->davClientRequest($oClient, 'DELETE', $oCalendar->DavPath . $oEvent->DavPath);
			}
		}

		// Soft delete, so a sync that has not run yet still knows to tell the server
		return !!$this->prepareAndExecute(
			'UPDATE tachyon_cal_events SET deleted = 1, changed = :changed'
			. ' WHERE id_user = :id_user AND id_event = :id_event',
			array(
				':id_user' => array($this->iUserID, \PDO::PARAM_INT),
				':id_event' => array((int) $oEvent->id, \PDO::PARAM_INT),
				':changed' => array(\time(), \PDO::PARAM_INT)
			)
		);
	}

	/* ----------------------------------------------------------------- sync */

	/**
	 * Discovers the calendars, then syncs each one by etag. Mirrors
	 * PdoAddressBook::Sync(), with the difference that an account holds several
	 * collections rather than one.
	 */
	public function Sync() : bool
	{
		$this->SyncDatabase();

		if (!$this->isDAVEnabled()) {
			return true;
		}

		$oClient = $this->getDavClient();
		if (!$oClient) {
			return false;
		}

		$aRemoteCalendars = $this->getCalendarPaths(
			$oClient,
			$oClient->urlPath,
			$this->aDAVConfig['User'],
			$this->aDAVConfig['Password']
		);

		if (!$aRemoteCalendars) {
			\Tachyon\Util\Log::warning('Calendar', 'Sync() found no calendars');
			return false;
		}

		$this->iSyncSkipped = 0;

		foreach ($aRemoteCalendars as $sPath => $aInfo) {
			try {
				$oCalendar = $this->storeCalendar($sPath, $aInfo);
				$this->syncCalendar($oClient, $oCalendar);
			} catch (\Throwable $oException) {
				// One unreadable calendar must not abandon the rest
				++$this->iSyncSkipped;
				$this->logWrite("Sync failed for {$sPath}: " . $oException->getMessage(), \LOG_WARNING, 'Calendar');
			}
		}

		if ($this->iSyncSkipped) {
			$this->logWrite("Sync finished with {$this->iSyncSkipped} skipped", \LOG_WARNING, 'Calendar');
		}

		return true;
	}

	/**
	 * Events and calendars this pass could not store. Reported so a sync that
	 * quietly lost something does not look identical to a clean one.
	 */
	public function SyncSkipped() : int
	{
		return $this->iSyncSkipped;
	}

	private function syncCalendar(\Tachyon\Util\DAV\Client $oClient, Calendar $oCalendar) : void
	{
		$aRemote = $this->prepareDavSyncData($oClient, $oCalendar->DavPath);
		if (!\is_array($aRemote)) {
			throw new \RuntimeException('No listing returned');
		}

		$aLocal = $this->localSyncData((int) $oCalendar->id);
		$bReadWrite = $this->isDAVReadWrite() && !$oCalendar->ReadOnly;

		// Local deletions go out first, so a later download cannot resurrect them
		if ($bReadWrite) {
			foreach ($aLocal as $sUid => $aData) {
				if ($aData['deleted']) {
					if (isset($aRemote[$sUid])) {
						$this->davClientRequest($oClient, 'DELETE', $oCalendar->DavPath . $aRemote[$sUid]['ics']);
					}
					unset($aLocal[$sUid], $aRemote[$sUid]);
				}
			}
		}

		// Gone from the server, and we had synced it before, so drop it here too
		foreach ($aLocal as $sUid => $aData) {
			if (!$aData['deleted'] && \strlen($aData['etag']) && !isset($aRemote[$sUid])) {
				$this->purgeEvent((int) $aData['id_event']);
				unset($aLocal[$sUid]);
			}
		}

		// Never seen here, or changed on the server since we last looked
		foreach ($aRemote as $sUid => $aData) {
			$bKnown = isset($aLocal[$sUid]);
			if ($bKnown && $aLocal[$sUid]['etag'] === $aData['etag']) {
				continue;
			}
			if ($bKnown && $bReadWrite && !\strlen($aLocal[$sUid]['etag'])) {
				// Local copy has never been uploaded, so it wins
				continue;
			}
			$oResponse = $this->davClientRequest($oClient, 'GET', $oCalendar->DavPath . $aData['ics']);
			if (!$oResponse || 200 !== $oResponse->status) {
				continue;
			}
			try {
				$this->storeIcal($oCalendar, $sUid, $oResponse->body, $aData['ics'], $aData['etag']);
			} catch (\Throwable $oException) {
				// One event that will not store must not abandon the calendar.
				// It used to: the exception reached the per calendar catch, so a
				// single oversized LOCATION or a date past 2038 meant that
				// calendar never finished syncing, on this pass or any later one.
				++$this->iSyncSkipped;
				$this->logWrite(
					"Skipped event {$sUid} in {$oCalendar->DavPath}: " . $oException->getMessage(),
					\LOG_WARNING, 'Calendar'
				);
			}
		}

		// Created here and not yet on the server
		if ($bReadWrite) {
			foreach ($aLocal as $sUid => $aData) {
				if (!$aData['deleted'] && !\strlen($aData['etag']) && !isset($aRemote[$sUid])) {
					$oEvent = $this->GetEventByUid($oCalendar->Uuid, $sUid);
					if ($oEvent) {
						$sDavPath = $sUid . '.ics';
						$sEtag = $this->davPutEvent($oCalendar, $sDavPath, $oEvent->Ical());
						if (null !== $sEtag) {
							$this->prepareAndExecute(
								'UPDATE tachyon_cal_events SET etag = :etag, dav_path = :dav_path'
								. ' WHERE id_user = :id_user AND id_event = :id_event',
								array(
									':id_user' => array($this->iUserID, \PDO::PARAM_INT),
									':id_event' => array((int) $aData['id_event'], \PDO::PARAM_INT),
									':etag' => array($sEtag, \PDO::PARAM_STR),
									':dav_path' => array($sDavPath, \PDO::PARAM_STR)
								)
							);
						}
					}
				}
			}
		}

		$this->flushDeletedEvents((int) $oCalendar->id);
	}

	private function davPutEvent(Calendar $oCalendar, string $sDavPath, string $sIcal) : ?string
	{
		$oClient = $this->getDavClient();
		if (!$oClient) {
			return null;
		}
		$oResponse = $this->davClientRequest($oClient, 'PUT', $oCalendar->DavPath . $sDavPath, $sIcal);
		if (!$oResponse || 200 > $oResponse->status || 300 <= $oResponse->status) {
			$this->logWrite('PUT rejected for ' . $sDavPath, \LOG_WARNING, 'Calendar');
			return null;
		}
		// Servers may omit the etag on PUT, in which case the next sync picks it up
		return \trim(\trim((string) $oResponse->getHeader('etag')), '"\'');
	}

	private function localSyncData(int $iCalendarId) : array
	{
		$aResult = array();
		$oStmt = $this->prepareAndExecute(
			'SELECT id_event, uid, etag, changed, deleted FROM tachyon_cal_events'
			. ' WHERE id_user = :id_user AND id_calendar = :id_calendar',
			array(
				':id_user' => array($this->iUserID, \PDO::PARAM_INT),
				':id_calendar' => array($iCalendarId, \PDO::PARAM_INT)
			)
		);
		if ($oStmt) {
			foreach ($oStmt->fetchAll(\PDO::FETCH_ASSOC) as $aRow) {
				$aResult[(string) $aRow['uid']] = array(
					'id_event' => (int) $aRow['id_event'],
					'etag' => (string) $aRow['etag'],
					'changed' => (int) $aRow['changed'],
					'deleted' => !empty($aRow['deleted'])
				);
			}
		}
		return $aResult;
	}

	/* -------------------------------------------------------------- storage */

	private function storeCalendar(string $sDavPath, array $aInfo) : Calendar
	{
		// The collection path is the stable identity; the display name can change
		$sUuid = \sha1($sDavPath);
		$oCalendar = $this->GetCalendarByUuid($sUuid);

		$aParams = array(
			':id_user' => array($this->iUserID, \PDO::PARAM_INT),
			':uuid' => array($sUuid, \PDO::PARAM_STR),
			':name' => array((string) $aInfo['name'], \PDO::PARAM_STR),
			':color' => array((string) $aInfo['color'], \PDO::PARAM_STR),
			':timezone' => array((string) $aInfo['timezone'], \PDO::PARAM_STR),
			':dav_path' => array($sDavPath, \PDO::PARAM_STR),
			':read_only' => array(empty($aInfo['readOnly']) ? 0 : 1, \PDO::PARAM_INT),
			':changed' => array(\time(), \PDO::PARAM_INT)
		);

		if ($oCalendar) {
			$this->prepareAndExecute(
				'UPDATE tachyon_cal_calendars SET name = :name, color = :color, timezone = :timezone,'
				. ' dav_path = :dav_path, read_only = :read_only, changed = :changed'
				. ' WHERE id_user = :id_user AND uuid = :uuid',
				$aParams
			);
		} else {
			$this->prepareAndExecute(
				'INSERT INTO tachyon_cal_calendars'
				. ' (id_user, uuid, name, color, description, timezone, dav_path, read_only, changed)'
				. " VALUES (:id_user, :uuid, :name, :color, '', :timezone, :dav_path, :read_only, :changed)",
				$aParams
			);
		}

		$oCalendar = $this->GetCalendarByUuid($sUuid);
		if (!$oCalendar) {
			throw new \RuntimeException('Could not store calendar ' . $sDavPath);
		}
		return $oCalendar;
	}

	private function storeIcal(Calendar $oCalendar, string $sUid, string $sIcal, string $sDavPath, string $sEtag) : void
	{
		$oEvent = new Event;
		$oEvent->setIcal($sIcal);

		$oVCalendar = $oEvent->VCalendar();
		if (!$oVCalendar || !$oEvent->VEvent()) {
			// VTODO and VJOURNAL collections are legitimate, just not ours
			return;
		}

		$aMeta = $this->metaFromVCalendar($oVCalendar, $sUid);
		$oEvent->Uid = $aMeta['uid'];
		$oEvent->DavPath = $sDavPath;
		$oEvent->Etag = $sEtag;
		$oEvent->Changed = \time();

		$this->storeEvent((int) $oCalendar->id, $oEvent, $aMeta, $this->GetEventByUid($oCalendar->Uuid, $oEvent->Uid));
	}

	private function storeEvent(int $iCalendarId, Event $oEvent, array $aMeta, ?Event $oExisting) : bool
	{
		$aParams = array(
			':id_user' => array($this->iUserID, \PDO::PARAM_INT),
			':id_calendar' => array($iCalendarId, \PDO::PARAM_INT),
			':uid' => array($oEvent->Uid, \PDO::PARAM_STR),
			':summary' => array($aMeta['summary'], \PDO::PARAM_STR),
			':description' => array($aMeta['description'], \PDO::PARAM_STR),
			':location' => array($aMeta['location'], \PDO::PARAM_STR),
			':dtstart' => array($aMeta['dtstart'], \PDO::PARAM_INT),
			':dtend' => array($aMeta['dtend'], \PDO::PARAM_INT),
			':all_day' => array($aMeta['allDay'] ? 1 : 0, \PDO::PARAM_INT),
			':rrule' => array($aMeta['rrule'], \PDO::PARAM_STR),
			':timezone' => array($aMeta['timezone'], \PDO::PARAM_STR),
			':ical' => array($oEvent->Ical(), \PDO::PARAM_STR),
			':dav_path' => array($oEvent->DavPath, \PDO::PARAM_STR),
			':etag' => array($oEvent->Etag, \PDO::PARAM_STR),
			':changed' => array($oEvent->Changed, \PDO::PARAM_INT)
		);
		$aParams[':recur_until'] = null === $aMeta['recurUntil']
			? array(null, \PDO::PARAM_NULL)
			: array($aMeta['recurUntil'], \PDO::PARAM_INT);

		if ($oExisting) {
			$aParams[':id_event'] = array((int) $oExisting->id, \PDO::PARAM_INT);
			return !!$this->prepareAndExecute(
				'UPDATE tachyon_cal_events SET summary = :summary, description = :description,'
				. ' location = :location, dtstart = :dtstart, dtend = :dtend, all_day = :all_day,'
				. ' rrule = :rrule, recur_until = :recur_until, timezone = :timezone, ical = :ical,'
				. ' dav_path = :dav_path, etag = :etag, changed = :changed, deleted = 0,'
				. ' id_calendar = :id_calendar, uid = :uid'
				. ' WHERE id_user = :id_user AND id_event = :id_event',
				$aParams
			);
		}

		return !!$this->prepareAndExecute(
			'INSERT INTO tachyon_cal_events'
			. ' (id_user, id_calendar, uid, summary, description, location, dtstart, dtend,'
			. '  all_day, rrule, recur_until, timezone, ical, dav_path, etag, changed)'
			. ' VALUES (:id_user, :id_calendar, :uid, :summary, :description, :location, :dtstart, :dtend,'
			. '  :all_day, :rrule, :recur_until, :timezone, :ical, :dav_path, :etag, :changed)',
			$aParams
		);
	}

	private function purgeEvent(int $iEventId) : void
	{
		$this->prepareAndExecute(
			'DELETE FROM tachyon_cal_events WHERE id_user = :id_user AND id_event = :id_event',
			array(
				':id_user' => array($this->iUserID, \PDO::PARAM_INT),
				':id_event' => array($iEventId, \PDO::PARAM_INT)
			)
		);
	}

	private function flushDeletedEvents(int $iCalendarId) : void
	{
		$this->prepareAndExecute(
			'DELETE FROM tachyon_cal_events WHERE id_user = :id_user AND id_calendar = :id_calendar AND deleted = 1',
			array(
				':id_user' => array($this->iUserID, \PDO::PARAM_INT),
				':id_calendar' => array($iCalendarId, \PDO::PARAM_INT)
			)
		);
	}

	/* -------------------------------------------------------------- helpers */

	/**
	 * Pulls the columns that only exist to make querying possible. The iCalendar
	 * body remains authoritative, so anything wrong here is a bad index rather
	 * than lost data.
	 */
	private function metaFromVCalendar(VCalendar $oVCalendar, string $sFallbackUid) : array
	{
		$oVEvent = null;
		foreach ($oVCalendar->VEVENT ?? array() as $oCandidate) {
			if (!isset($oCandidate->{'RECURRENCE-ID'})) {
				$oVEvent = $oCandidate;
				break;
			}
		}
		if (!$oVEvent) {
			throw new \ValueError('No master VEVENT');
		}

		$sUid = isset($oVEvent->UID) ? (string) $oVEvent->UID : $sFallbackUid;
		$bAllDay = isset($oVEvent->DTSTART) && !$oVEvent->DTSTART->hasTime();

		$iDtStart = isset($oVEvent->DTSTART) ? $oVEvent->DTSTART->getDateTime()->getTimestamp() : 0;
		$iDtEnd = $iDtStart;
		if (isset($oVEvent->DTEND)) {
			$iDtEnd = $oVEvent->DTEND->getDateTime()->getTimestamp();
		} else if (isset($oVEvent->DURATION)) {
			$oEnd = $oVEvent->DTSTART->getDateTime()->add(
				\Sabre\VObject\DateTimeParser::parseDuration((string) $oVEvent->DURATION)
			);
			$iDtEnd = $oEnd->getTimestamp();
		} else if ($bAllDay) {
			$iDtEnd = $iDtStart + 86400;
		}

		$sTimezone = '';
		if (isset($oVEvent->DTSTART)) {
			$oTz = $oVEvent->DTSTART->getDateTime()->getTimezone();
			$sTimezone = $oTz ? $oTz->getName() : '';
		}

		$sRrule = isset($oVEvent->RRULE) ? (string) $oVEvent->RRULE : '';

		return array(
			'uid' => $sUid,
			'summary' => isset($oVEvent->SUMMARY) ? (string) $oVEvent->SUMMARY : '',
			'description' => isset($oVEvent->DESCRIPTION) ? (string) $oVEvent->DESCRIPTION : '',
			'location' => isset($oVEvent->LOCATION) ? (string) $oVEvent->LOCATION : '',
			'dtstart' => $iDtStart,
			'dtend' => $iDtEnd,
			'allDay' => $bAllDay,
			'rrule' => $sRrule,
			'timezone' => $sTimezone,
			'recurUntil' => $sRrule ? $this->lastOccurrence($oVCalendar, $sUid) : null
		);
	}

	/**
	 * Timestamp of the final occurrence, or null when the rule never ends. Lets
	 * the range query skip series that finished before the window.
	 */
	private function lastOccurrence(VCalendar $oVCalendar, string $sUid) : ?int
	{
		try {
			$oIterator = new EventIterator($oVCalendar, $sUid);
			if ($oIterator->isInfinite()) {
				return null;
			}
			$iLast = null;
			$iCount = 0;
			while ($oIterator->valid() && $iCount < $this->iMaxOccurrences) {
				$oOccEnd = $oIterator->getDtEnd() ?: $oIterator->getDtStart();
				if (!$oOccEnd) {
					break;
				}
				$iLast = $oOccEnd->getTimestamp();
				++$iCount;
				$oIterator->next();
			}
			// Hitting the cap means we cannot prove where it ends, so treat it as endless
			return $iCount >= $this->iMaxOccurrences ? null : $iLast;
		} catch (\Throwable $oException) {
			$this->logWrite("Cannot bound recurrence for {$sUid}: " . $oException->getMessage(), \LOG_WARNING, 'Calendar');
			return null;
		}
	}

	private static function timezoneFor(array $aRow, Calendar $oCalendar) : \DateTimeZone
	{
		foreach (array((string) $aRow['timezone'], $oCalendar->Timezone) as $sName) {
			if (\strlen($sName)) {
				try {
					return new \DateTimeZone($sName);
				} catch (\Throwable $oException) {
					// Fall through to the next candidate
				}
			}
		}
		return new \DateTimeZone(\date_default_timezone_get() ?: 'UTC');
	}

	private static function calendarFromRow(array $aRow) : Calendar
	{
		$oCalendar = new Calendar;
		$oCalendar->id = (string) $aRow['id_calendar'];
		$oCalendar->Uuid = (string) $aRow['uuid'];
		$oCalendar->Name = (string) $aRow['name'];
		$oCalendar->Color = (string) $aRow['color'];
		$oCalendar->Description = (string) ($aRow['description'] ?? '');
		$oCalendar->Timezone = (string) $aRow['timezone'];
		$oCalendar->DavPath = (string) $aRow['dav_path'];
		$oCalendar->ReadOnly = !empty($aRow['read_only']);
		return $oCalendar;
	}

	private static function eventFromRow(array $aRow) : Event
	{
		$oEvent = new Event;
		$oEvent->id = (string) $aRow['id_event'];
		$oEvent->CalendarId = (string) $aRow['id_calendar'];
		$oEvent->Uid = (string) $aRow['uid'];
		$oEvent->Summary = (string) $aRow['summary'];
		$oEvent->Description = (string) $aRow['description'];
		$oEvent->Location = (string) $aRow['location'];
		$oEvent->DtStart = (int) $aRow['dtstart'];
		$oEvent->DtEnd = (int) $aRow['dtend'];
		$oEvent->AllDay = !empty($aRow['all_day']);
		$oEvent->Rrule = (string) $aRow['rrule'];
		$oEvent->RecurUntil = null === $aRow['recur_until'] ? null : (int) $aRow['recur_until'];
		$oEvent->Timezone = (string) $aRow['timezone'];
		$oEvent->DavPath = (string) ($aRow['dav_path'] ?? '');
		$oEvent->Etag = (string) ($aRow['etag'] ?? '');
		$oEvent->Changed = (int) ($aRow['changed'] ?? 0);
		$oEvent->setIcal((string) $aRow['ical']);
		return $oEvent;
	}

	protected function getUserId(string $sEmail, bool $bSkipInsert = false) : int
	{
		$sEmail = \Tachyon\Util\IDN::emailToAscii(\trim($sEmail));
		if (empty($sEmail)) {
			throw new \ValueError('Empty Email argument');
		}

		$this->SyncDatabase();

		$oStmt = $this->prepareAndExecute('SELECT id_user FROM rainloop_users WHERE rl_email = :rl_email',
			array(':rl_email' => array($sEmail, \PDO::PARAM_STR))
		);

		$mRow = $oStmt->fetch(\PDO::FETCH_ASSOC);
		if ($mRow && isset($mRow['id_user']) && \is_numeric($mRow['id_user'])) {
			return (int) $mRow['id_user'];
		}

		if (!$bSkipInsert) {
			$oStmt->closeCursor();
			$this->prepareAndExecute('INSERT INTO rainloop_users (rl_email) VALUES (:rl_email)',
				array(':rl_email' => array($sEmail, \PDO::PARAM_STR))
			);
			return $this->getUserId($sEmail, true);
		}

		return 0;
	}

	private function SyncDatabase() : bool
	{
		static $mCache = null;
		if (null !== $mCache) {
			return $mCache;
		}

		$mCache = false;
		switch ($this->settings->driver) {
			case 'mysql':
			case 'pgsql':
			case 'sqlite':
				$mCache = $this->dataBaseUpgrade(
					$this->settings->driver . '-cal-version',
					PdoSchema::getForDbType($this->settings->driver)
				);
				break;
		}

		return $mCache;
	}

	protected function getPdoSettings() : \Tachyon\Pdo\Settings
	{
		return $this->settings;
	}
}
