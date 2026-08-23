<?php

namespace Tachyon\Providers\Calendar;

use Tachyon\Util\DAV\Client as DAVClient;

/**
 * DAV discovery and sync helpers for calendars, mirroring the CardDAV trait used
 * by the address book. The differences are the namespace, the .ics extension, the
 * text/calendar content type, and that an account holds several calendars rather
 * than a single collection.
 */
trait CalDAV
{
	private $aDAVConfig = ['Mode' => 0];

	public function setDAVClientConfig(?array $aConfig)
	{
		if (isset($aConfig['User'], $aConfig['Password'], $aConfig['Url']) && !empty($aConfig['Mode'])) {
			$this->aDAVConfig = $aConfig;
		} else {
			$this->aDAVConfig = ['Mode' => 0];
		}
	}

	protected function isDAVEnabled() : bool
	{
		return !empty($this->aDAVConfig['Mode']);
	}

	/**
	 * Mode 1 is read and write, mode 2 is read only. Anything the user mounts read
	 * only must never receive a PUT or DELETE.
	 */
	protected function isDAVReadWrite() : bool
	{
		return 1 == $this->aDAVConfig['Mode'];
	}

	/**
	 * Lists the .ics resources of one calendar collection with their etags, which
	 * is what makes a full calendar-query REPORT unnecessary: an etag per resource
	 * is enough to know what changed.
	 */
	protected function prepareDavSyncData(DAVClient $oClient, string $sPath)
	{
		$mResult = false;
		$aResponse = null;
		try
		{
			$aResponse = $oClient->propFind($sPath, array(
				'{DAV:}getlastmodified',
				'{DAV:}resourcetype',
				'{DAV:}getetag'
			), 1);
		}
		catch (\Throwable $oException)
		{
			$this->logException($oException);
		}

		if (\is_array($aResponse)) {
			$mResult = array();
			foreach ($aResponse as $sKey => $aItem) {
				$sKey = \rtrim(\trim($sKey), '\\/');
				if (!empty($sKey) && \is_array($aItem) && isset($aItem['{DAV:}getetag'])) {
					$aMatch = array();
					if (\preg_match('/\/([^\/?]+)$/', $sKey, $aMatch) && !empty($aMatch[1])
					 && !static::hasDAVCollection($aItem))
					{
						$sIcsFileName = \urldecode(\urldecode($aMatch[1]));
						$sKeyID = \preg_replace('/\.ics$/i', '', $sIcsFileName);

						$mResult[$sKeyID] = array(
							'deleted' => false,
							'uid' => $sKeyID,
							'ics' => $sIcsFileName,
							'etag' => \trim(\trim($aItem['{DAV:}getetag']), '"\''),
							'changed' => 0
						);

						if (isset($aItem['{DAV:}getlastmodified'])) {
							$mResult[$sKeyID]['changed'] = \MailSo\Base\DateTimeHelper::ParseRFC2822DateString(
								$aItem['{DAV:}getlastmodified']);
						} else {
							$mResult[$sKeyID]['changed'] = \MailSo\Base\DateTimeHelper::TryToParseSpecEtagFormat(
								$mResult[$sKeyID]['etag']);
						}
					}
				}
			}
		}

		return $mResult;
	}

	protected function davClientRequest(DAVClient $oClient, string $sCmd, string $sUrl, $mData = null) : ?\Tachyon\Util\HTTP\Response
	{
		\MailSo\Base\Utils::ResetTimeLimit();

		$sLogLine = $sCmd.' '.$sUrl.('PUT' === $sCmd && null !== $mData ? ' ('.\strlen($mData).')' : '');
		$this->logWrite($sLogLine, \LOG_INFO, 'DAV');

		try
		{
			if ('PUT' === $sCmd && null !== $mData) {
				return $oClient->request($sCmd, $sUrl, $mData, array(
					'Content-Type' => 'text/calendar; charset=utf-8'
				));
			}
			return $oClient->request($sCmd, $sUrl);
		}
		catch (\Throwable $oException)
		{
			$this->logWrite($sLogLine.' failed: '.$oException->getMessage(), \LOG_WARNING, 'DAV');
		}

		return null;
	}

	private function detectionPropFind(DAVClient $oClient, string $sPath) : ?array
	{
		try
		{
			return $oClient->propFind($sPath, array(
				'{DAV:}current-user-principal',
				'{DAV:}resourcetype',
				'{DAV:}displayname',
				'{urn:ietf:params:xml:ns:caldav}calendar-home-set'
			), 1);
		}
		catch (\Throwable $oException)
		{
			$this->logException($oException);
		}

		return null;
	}

	/**
	 * Walks .well-known/caldav to the principal, then to calendar-home-set, and
	 * returns every calendar collection found there.
	 *
	 * @return array path => ['name' => ..., 'color' => ..., 'timezone' => ..., 'readOnly' => bool]
	 */
	protected function getCalendarPaths(DAVClient $oClient, string $sPath, string $sUser,
		#[\SensitiveParameter]
		string $sPassword,
		string $sProxy = '') : array
	{
		$sCalendarHomeSet = '';

		$aResponse = $this->detectionPropFind($oClient, '/.well-known/caldav')
			?: $this->detectionPropFind($oClient, $sPath);

		$sCurrentUserPrincipal = '';
		if (\is_array($aResponse)) {
			foreach ($aResponse as $aItem) {
				if (empty($sCalendarHomeSet) && !empty($aItem['{urn:ietf:params:xml:ns:caldav}calendar-home-set']['{DAV:}href'])) {
					$sCalendarHomeSet = $aItem['{urn:ietf:params:xml:ns:caldav}calendar-home-set']['{DAV:}href'];
				}
				if (empty($sCurrentUserPrincipal) && !empty($aItem['{DAV:}current-user-principal']['{DAV:}href'])) {
					$sCurrentUserPrincipal = $aItem['{DAV:}current-user-principal']['{DAV:}href'];
				}
			}
		}

		// The home set is often only advertised on the principal, so follow it
		if (empty($sCalendarHomeSet) && !empty($sCurrentUserPrincipal)) {
			$aResponse = $this->detectionPropFind($oClient, $sCurrentUserPrincipal);
			if (\is_array($aResponse)) {
				foreach ($aResponse as $aItem) {
					if (!empty($aItem['{urn:ietf:params:xml:ns:caldav}calendar-home-set']['{DAV:}href'])) {
						$sCalendarHomeSet = $aItem['{urn:ietf:params:xml:ns:caldav}calendar-home-set']['{DAV:}href'];
						break;
					}
				}
			}
		}

		if (empty($sCalendarHomeSet)) {
			// Some servers point straight at a collection, so try the given path itself
			$sCalendarHomeSet = $sPath;
		}

		if (\preg_match('/^http[s]?:\/\//i', $sCalendarHomeSet)) {
			$oClient = $this->getDavClientFromUrl($sCalendarHomeSet, $sUser, $sPassword, $sProxy);
			$sCalendarHomeSet = $oClient->urlPath;
		}

		return $this->listCalendarCollections($oClient, $sCalendarHomeSet);
	}

	protected function listCalendarCollections(DAVClient $oClient, string $sHomeSet) : array
	{
		$aCalendars = array();

		try
		{
			$aResponse = $oClient->propFind($sHomeSet, array(
				'{DAV:}resourcetype',
				'{DAV:}displayname',
				'{DAV:}current-user-privilege-set',
				'{http://apple.com/ns/ical/}calendar-color',
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone',
				'{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'
			), 1);
		}
		catch (\Throwable $oException)
		{
			$this->logException($oException);
			return $aCalendars;
		}

		if (\is_array($aResponse)) {
			foreach ($aResponse as $sKey => $aItem) {
				if (empty($sKey) || !\is_array($aItem) || !static::isDAVCalendar($aItem)) {
					continue;
				}

				$sName = isset($aItem['{DAV:}displayname']) && \is_string($aItem['{DAV:}displayname'])
					? \trim($aItem['{DAV:}displayname'])
					: '';

				$aCalendars[\rtrim(\trim($sKey), '\\/') . '/'] = array(
					'name' => \strlen($sName) ? $sName : \basename(\rtrim($sKey, '\\/')),
					'color' => static::davCalendarColor($aItem),
					'timezone' => static::davCalendarTimezone($aItem),
					'readOnly' => !static::davCanWrite($aItem)
				);
			}
		}

		return $aCalendars;
	}

	private static function isDAVCalendar($aItem) : bool
	{
		return !empty($aItem['{DAV:}resourcetype'])
			&& \is_array($aItem['{DAV:}resourcetype'])
			&& \in_array('{urn:ietf:params:xml:ns:caldav}calendar', $aItem['{DAV:}resourcetype']);
	}

	private static function hasDAVCollection($aItem) : bool
	{
		return !empty($aItem['{DAV:}resourcetype'])
			&& \is_array($aItem['{DAV:}resourcetype'])
			&& \in_array('{DAV:}collection', $aItem['{DAV:}resourcetype']);
	}

	private static function davCalendarColor($aItem) : string
	{
		$mColor = $aItem['{http://apple.com/ns/ical/}calendar-color'] ?? '';
		if (\is_string($mColor) && \preg_match('/^#[0-9a-f]{3,8}$/i', \trim($mColor), $aMatch)) {
			// Apple appends an alpha pair that CSS before level 4 does not accept
			return \substr($aMatch[0], 0, 7);
		}
		return '';
	}

	private static function davCalendarTimezone($aItem) : string
	{
		$mTimezone = $aItem['{urn:ietf:params:xml:ns:caldav}calendar-timezone'] ?? '';
		if (\is_string($mTimezone) && \strlen($mTimezone)) {
			try {
				$oVCalendar = \Sabre\VObject\Reader::read($mTimezone, \Sabre\VObject\Reader::OPTION_FORGIVING);
				if (isset($oVCalendar->VTIMEZONE)) {
					return (string) $oVCalendar->VTIMEZONE->TZID;
				}
			} catch (\Throwable $oException) {
				// A calendar without a usable default timezone is not an error
			}
		}
		return '';
	}

	/**
	 * Absent privileges are treated as writable, since plenty of servers omit the
	 * property. The configured Mode is what actually protects a read only mount.
	 */
	/**
	 * Only rights that actually allow adding or changing an event count. Matching
	 * "write" loosely also accepts write-properties, which merely permits renaming
	 * or recolouring a calendar. Nextcloud grants exactly that on the generated
	 * birthday calendar, so a loose test offers the user an editable calendar the
	 * server then refuses every event on.
	 */
	private static function davCanWrite($aItem) : bool
	{
		$mPrivileges = $aItem['{DAV:}current-user-privilege-set'] ?? null;
		if (!\is_array($mPrivileges) || !\count($mPrivileges)) {
			// Nothing advertised, so assume writable and let the server refuse
			return true;
		}
		foreach ($mPrivileges as $mItem) {
			if (\is_string($mItem) && \in_array($mItem, array(
				'{DAV:}all',
				'{DAV:}write',
				'{DAV:}write-content',
				'{DAV:}bind'
			))) {
				return true;
			}
		}
		return false;
	}

	private function getDavClientFromUrl(string $sUrl, string $sUser,
		#[\SensitiveParameter]
		string $sPassword,
		string $sProxy = ''
	) : DAVClient
	{
		if (!\preg_match('/^http[s]?:\/\//i', $sUrl)) {
			$sUrl = 'https://'.$sUrl;
		}

		$aUrl = \parse_url($sUrl);
		if (!\is_array($aUrl)) {
			$aUrl = array();
		}

		$aUrl['scheme'] = $aUrl['scheme'] ?? 'http';
		$aUrl['host'] = $aUrl['host'] ?? 'localhost';
		$aUrl['port'] = $aUrl['port'] ?? 0;
		$aUrl['path'] = isset($aUrl['path']) ? \rtrim($aUrl['path'], '\\/').'/' : '/';

		$aSettings = array(
			'baseUri' => $aUrl['scheme'].'://'.$aUrl['host'].($aUrl['port'] ? ':'.$aUrl['port'] : ''),
			'userName' => $sUser,
			'password' => $sPassword
		);

		$this->logMask($sPassword);

		if (!empty($sProxy)) {
			$aSettings['proxy'] = $sProxy;
		}

		$oClient = new DAVClient($aSettings);
		$oClient->setVerifyPeer(false);
		$oClient->urlPath = $aUrl['path'];

		$this->logWrite('DavClient: User: '.$aSettings['userName'].', Url: '.$sUrl, \LOG_INFO, 'DAV');

		return $oClient;
	}

	public function getDavClient() : ?DAVClient
	{
		if (!$this->isDAVEnabled()) {
			return null;
		}
		return $this->getDavClientFromUrl(
			$this->aDAVConfig['Url'],
			$this->aDAVConfig['User'],
			$this->aDAVConfig['Password']
		);
	}
}
