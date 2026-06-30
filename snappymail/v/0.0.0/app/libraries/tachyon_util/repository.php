<?php

namespace Tachyon\Util;

abstract class Repository
{
	const PACKAGES_URL = 'https://raw.githubusercontent.com/kimusan/Tachyon/master/';
	const CORE_API_URL = 'https://api.github.com/repos/kimusan/Tachyon/releases/latest';

	private static function httpGet(string $url, array $headers = []) : ?string
	{
		$oHTTP = HTTP\Request::factory(/*'socket' or 'curl'*/);
		$oHTTP->proxy = \Tachyon\Api::Config()->Get('labs', 'curl_proxy', '');
		$oHTTP->proxy_auth = \Tachyon\Api::Config()->Get('labs', 'curl_proxy_auth', '');
		$oHTTP->max_response_kb = 0;
		$oHTTP->timeout = 15;
		$oResponse = $oHTTP->doRequest('GET', $url, null, $headers);
		if (!$oResponse || 200 !== $oResponse->status) {
			throw new \Exception('HTTP ' . ($oResponse ? $oResponse->status : '0') . ' from ' . $url);
		}
		return $oResponse->body;
	}

	private static function download(string $url) : string
	{
		$sTmp = APP_PRIVATE_DATA . \md5(\microtime(true) . $url) . \preg_replace('/^.*?(\\.[a-z\\.]+)$/Di', '$1', $url);
		$pDest = \fopen($sTmp, 'w+b');
		if (!$pDest) {
			throw new \Exception('Cannot create temp file: ' . $sTmp);
		}

		$oHTTP = HTTP\Request::factory(/*'socket' or 'curl'*/);
		$oHTTP->proxy = \Tachyon\Api::Config()->Get('labs', 'curl_proxy', '');
		$oHTTP->proxy_auth = \Tachyon\Api::Config()->Get('labs', 'curl_proxy_auth', '');
		$oHTTP->max_response_kb = 0;
		$oHTTP->max_redirects = 5;
		$oHTTP->timeout = 15;
		$oHTTP->streamBodyTo($pDest);
		\set_time_limit(120);
		$oResponse = $oHTTP->doRequest('GET', $url);
		\fclose($pDest);
		if (!$oResponse) {
			\unlink($sTmp);
			throw new \Exception('No HTTP response from ' . $url);
		}
		if (200 !== $oResponse->status) {
			$body = \file_get_contents($sTmp);
			\unlink($sTmp);
			throw new \Exception(static::body2plain($body), $oResponse->status);
		}
		return $sTmp;
	}

	private static function body2plain(string $body) : string
	{
		return \trim(\strip_tags(
			\preg_match('@<body[^>]*>(.*)</body@si', $body, $match) ? $match[1] : $body
		));
	}

	/**
	 * Fetch and decode a packages.json from a URL, with 1-hour cache.
	 * Returns decoded array or null on failure.
	 */
	private static function fetchPackages(string $sUrl, \MailSo\Cache\CacheClient $oCache) : ?array
	{
		$sCacheKey = '/RepositoryCache/Repo/' . \md5($sUrl);
		$sCached = $oCache->Get($sCacheKey);
		$iCachedAt = $sCached ? $oCache->GetTimer($sCacheKey) : 0;

		if (!$sCached || !$iCachedAt || \time() - 3600 > $iCachedAt) {
			try {
				$sBody = static::httpGet($sUrl);
			} catch (\Throwable $e) {
				\Tachyon\Util\Log::warning('REPOSITORY', "Failed to fetch {$sUrl}: " . $e->getMessage());
				return null;
			}
			$aData = $sBody ? \json_decode($sBody) : null;
			if (\is_array($aData)) {
				$oCache->Set($sCacheKey, $sBody);
				$oCache->SetTimer($sCacheKey);
				return $aData;
			}
			return null;
		}

		$aData = \json_decode($sCached, false, 10);
		return \is_array($aData) ? $aData : null;
	}

	private static function getRepositoryDataByUrl(bool &$bReal = false) : array
	{
		$bReal = false;
		$oCache = \Tachyon\Api::Actions()->Cacher();

		$aPackages = static::fetchPackages(static::PACKAGES_URL . 'packages.json', $oCache) ?? [];

		$aById = [];
		foreach ($aPackages as $oItem) {
			if ($oItem && !empty($oItem->id)) {
				$aById[$oItem->id] = $oItem;
			}
		}

		$bReal = !empty($aById);
		return \array_values($aById);
	}

	private static function getRepositoryData(bool &$bReal, string &$sError) : array
	{
		$aResult = array();
		try {
			foreach (static::getRepositoryDataByUrl($bReal) as $oItem) {
				if ($oItem
				 && isset($oItem->type, $oItem->id, $oItem->name, $oItem->version, $oItem->release, $oItem->file, $oItem->description)
				 && 'plugin' === $oItem->type
				 && (empty($aResult[$oItem->id]) || \version_compare($aResult[$oItem->id]['version'], $oItem->version, '<'))
				 && (TACHYON_DEV || empty($oItem->required) || \version_compare(APP_VERSION, $oItem->required, '>='))
				 && (TACHYON_DEV || empty($oItem->deprecated) || \version_compare(APP_VERSION, $oItem->deprecated, '<'))
				) {
					$aResult[$oItem->id] = array(
						'type' => $oItem->type,
						'id' => $oItem->id,
						'name' => $oItem->name,
						'installed' => '',
						'enabled' => true,
						'version' => $oItem->version,
						'file' => $oItem->file,
						'release' => $oItem->release,
						'desc' => $oItem->description,
						'canBeDeleted' => false,
						'canBeUpdated' => true
					);
				}
			}
		} catch (\Throwable $e) {
			\Tachyon\Util\Log::error('INSTALLER', "{$e->getCode()} {$e->getMessage()}");
		}
		return $aResult;
	}

	/**
	 * Fetches latest release info from GitHub Releases API.
	 * Returns object with ->version (string) and ->file (full download URL).
	 */
	public static function getLatestCoreInfo()
	{
		\Tachyon\Api::Actions()->IsAdminLoggined();
		try {
			$sBody = static::httpGet(static::CORE_API_URL, [
				'Accept: application/vnd.github+json',
				'User-Agent: Tachyon/' . APP_VERSION,
			]);
		} catch (\Throwable $e) {
			\Tachyon\Util\Log::error('UPDATER', $e->getMessage());
			return null;
		}
		if (!$sBody) {
			return null;
		}
		$data = \json_decode($sBody, false, 5);
		if (!$data || empty($data->tag_name)) {
			return null;
		}

		$downloadUrl = null;
		foreach ($data->assets ?? [] as $asset) {
			if (\str_ends_with($asset->name ?? '', '.tar.gz')) {
				$downloadUrl = $asset->browser_download_url;
				break;
			}
		}
		if (!$downloadUrl) {
			return null;
		}

		$info = new \stdClass();
		$info->version = \ltrim($data->tag_name, 'v');
		$info->file = $downloadUrl;
		$info->warnings = [];
		return $info;
	}

	public static function downloadCore() : ?string
	{
		$info = static::getLatestCoreInfo();
		return ($info && \version_compare(APP_VERSION, $info->version, '<'))
			? static::download($info->file)
			: null;
	}

	public static function canUpdateCore() : bool
	{
		return \version_compare(APP_VERSION, '2.0', '>')
			&& \is_writable(\dirname(APP_VERSION_ROOT_PATH))
			&& \is_writable(APP_INDEX_ROOT_PATH . 'index.php')
			&& \Tachyon\Api::Config()->Get('admin_panel', 'allow_update', false);
	}

	public static function getEnabledPackagesNames() : array
	{
		return \array_map('trim',
			\explode(',', \strtolower(\Tachyon\Api::Config()->Get('plugins', 'enabled_list', '')))
		);
	}

	public static function enablePackage(string $sName, bool $bEnable = true) : bool
	{
		if (!\strlen($sName)) {
			return false;
		}

		$oConfig = \Tachyon\Api::Config();

		$aEnabledPlugins = static::getEnabledPackagesNames();

		$aNewEnabledPlugins = array();
		if ($bEnable) {
			$aNewEnabledPlugins = $aEnabledPlugins;
			$aNewEnabledPlugins[] = $sName;
		} else {
			foreach ($aEnabledPlugins as $sPlugin) {
				if ($sName !== $sPlugin && \strlen($sPlugin)) {
					$aNewEnabledPlugins[] = $sPlugin;
				}
			}
		}

		$oConfig->Set('plugins', 'enabled_list', \trim(\implode(',', \array_unique($aNewEnabledPlugins)), ' ,'));

		return $oConfig->Save();
	}

	public static function getPackagesList() : array
	{
		empty($_ENV['TACHYON_INCLUDE_AS_API']) && \Tachyon\Api::Actions()->IsAdminLoggined();

		$bReal = false;
		$sError = '';
		$aList = static::getRepositoryData($bReal, $sError);

		$aEnabledPlugins = static::getEnabledPackagesNames();

		$aInstalled = \Tachyon\Api::Actions()->Plugins()->InstalledPlugins();
		foreach ($aInstalled as $aItem) {
			if ($aItem) {
				if (isset($aList[$aItem[0]])) {
					$aList[$aItem[0]]['installed'] = $aItem[1];
					$aList[$aItem[0]]['enabled'] = \in_array(\strtolower($aItem[0]), $aEnabledPlugins);
					$aList[$aItem[0]]['canBeDeleted'] = true;
					$aList[$aItem[0]]['canBeUpdated'] = \version_compare($aItem[1], $aList[$aItem[0]]['version'], '<');
				} else {
					\array_push($aList, array(
						'type' => 'plugin',
						'id' => $aItem[0],
						'name' => $aItem[2],
						'installed' => $aItem[1],
						'enabled' => \in_array(\strtolower($aItem[0]), $aEnabledPlugins),
						'version' => '',
						'file' => '',
						'release' => '',
						'desc' => $aItem[3],
						'canBeDeleted' => true,
						'canBeUpdated' => false
					));
				}
			}
		}

		return array(
			 'Real' => $bReal,
			 'List' => \array_values($aList),
			 'Error' => $sError
		);
	}

	public static function deletePackage(string $sId) : bool
	{
		\Tachyon\Api::Actions()->IsAdminLoggined();
		static::enablePackage($sId, false);
		return static::deletePackageDir($sId);
	}

	private static function deletePackageDir(string $sId) : bool
	{
		$sPath = APP_PLUGINS_PATH . $sId;
		return (!\is_dir($sPath) || \MailSo\Base\Utils::RecRmDir($sPath))
			&& (!\is_file("{$sPath}.phar") || \unlink("{$sPath}.phar"));
	}

	public static function installPackage(string $sType, string $sId, string $sFile = '') : bool
	{
		empty($_ENV['TACHYON_INCLUDE_AS_API']) && \Tachyon\Api::Actions()->IsAdminLoggined();

		\Tachyon\Util\Log::info('INSTALLER', 'Start package install: ' . $sId . ' (' . $sType . ')');

		$sRealFile = '';

		$bResult = false;
		$sTmp = null;
		try {
			if ('plugin' === $sType) {
				$bReal = false;
				$sError = '';
				$aList = static::getRepositoryData($bReal, $sError);
				if ($sError) {
					throw new \Exception($sError);
				}
				if (isset($aList[$sId]) && (!$sFile || $sFile === $aList[$sId]['file'])) {
					$sRealFile = $aList[$sId]['file'];
					$sTmp = static::download($aList[$sId]['file']);
				}
			}

			if ($sTmp) {
				if (!static::deletePackageDir($sId)) {
					throw new \Exception('Cannot remove previous plugin folder: ' . $sId);
				}
				if ('.phar' === \substr($sRealFile, -5)) {
					$bResult = \copy($sTmp, APP_PLUGINS_PATH . \basename($sRealFile));
				} else {
					if (\class_exists('PharData')) {
						$oArchive = new \PharData($sTmp, 0, $sRealFile);
					} else {
						$oArchive = new \Tachyon\Util\TAR($sTmp);
					}
					$bResult = $oArchive->extractTo(\rtrim(APP_PLUGINS_PATH, '\\/'));
				}
				if (!$bResult) {
					throw new \Exception('Cannot extract package files');
				}
			}
		} catch (\Throwable $e) {
			\Tachyon\Util\Log::error('INSTALLER', "Install package {$sRealFile} failed: {$e->getMessage()}");
			throw $e;
		} finally {
			$sTmp && \unlink($sTmp);
		}

		return $bResult;
	}

}
