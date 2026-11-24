<?php

/**
 * VirusTotal checks for attachments and links.
 *
 * Without an API key the plugin only builds VirusTotal GUI links from a local
 * SHA-256 (or URL). With a free public API key it looks up existing reports
 * inline. Submitting a file for analysis is opt-in and never automatic.
 *
 * Create an account: https://www.virustotal.com/gui/join-us
 * Get an API key:    https://www.virustotal.com/gui/my-apikey
 * API overview:      https://docs.virustotal.com/docs/api-overview
 */

class VirustotalPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME     = 'VirusTotal',
		AUTHOR   = 'master3395',
		URL      = 'https://github.com/Control-Panel-Network/CPN-Plugins',
		VERSION  = '1.0.0',
		RELEASE  = '2026-11-24',
		REQUIRED = '4.7.0',
		CATEGORY = 'Security',
		LICENSE  = 'AGPL-3.0',
		DESCRIPTION = 'Check attachments and links on VirusTotal. Works without an API key via GUI links; optional free public API key for inline results.';

	const
		API_BASE = 'https://www.virustotal.com/api/v3',
		GUI_BASE = 'https://www.virustotal.com/gui',
		JOIN_URL = 'https://www.virustotal.com/gui/join-us',
		KEY_URL  = 'https://www.virustotal.com/gui/my-apikey',
		DOCS_URL = 'https://docs.virustotal.com/docs/api-overview',
		MAX_UPLOAD = 31457280;

	public function Init() : void
	{
		$this->UseLangs(true);
		$this->addJs('virustotal.js');
		$this->addJsonHook('VirusTotalFile', 'DoVirusTotalFile');
		$this->addJsonHook('VirusTotalUrl', 'DoVirusTotalUrl');
		$this->addJsonHook('VirusTotalSubmit', 'DoVirusTotalSubmit');
	}

	protected function configMapping() : array
	{
		return array(
			\Tachyon\Plugins\Property::NewInstance('api_key')
				->SetLabel('VirusTotal API key (optional)')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::PASSWORD)
				->SetEncrypted()
				->SetDescription(
					'Leave empty for free GUI-link mode (SHA-256 / URL open on VirusTotal). '
					. 'Paste a free public API key for inline detection counts. '
					. 'Create account: ' . static::JOIN_URL
					. ' | Get API key: ' . static::KEY_URL
					. ' | API overview: ' . static::DOCS_URL
				),
			\Tachyon\Plugins\Property::NewInstance('allow_submit')
				->SetLabel('Allow Submit to VirusTotal')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(false)
				->SetDescription(
					'When an API key is set, show a button that uploads an unknown attachment for analysis. '
					. 'Uploads are shared with the VirusTotal community. Off by default.'
				)
		);
	}

	/**
	 * Never expose the API key to the browser.
	 */
	public function FilterAppDataPluginSection(bool $bAdmin, bool $bAuth, array &$aConfig) : void
	{
		if (!$bAdmin && $bAuth) {
			$aConfig = array(
				'has_api_key' => \strlen($this->apiKey()) ? 1 : 0,
				'allow_submit' => ($this->Config()->Get('plugin', 'allow_submit', false) && \strlen($this->apiKey())) ? 1 : 0,
				'join_url' => static::JOIN_URL,
				'key_url' => static::KEY_URL,
				'docs_url' => static::DOCS_URL
			);
		} else {
			$aConfig = array(
				'join_url' => static::JOIN_URL,
				'key_url' => static::KEY_URL,
				'docs_url' => static::DOCS_URL
			);
		}
	}

	/**
	 * Look up an existing file report by SHA-256. Without a key, returns a GUI link only.
	 */
	public function DoVirusTotalFile()
	{
		$this->Manager()->Actions()->getAccountFromToken();

		$sHash = \strtolower(\trim((string) $this->jsonParam('sha256', '')));
		if (!\preg_match('/^[a-f0-9]{64}$/D', $sHash)) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'bad-hash'));
		}

		$sGui = static::GUI_BASE . '/file/' . $sHash;
		$sKey = $this->apiKey();
		if (!\strlen($sKey)) {
			return $this->jsonResponse(__FUNCTION__, array(
				'mode' => 'link',
				'sha256' => $sHash,
				'gui_url' => $sGui,
				'join_url' => static::JOIN_URL,
				'key_url' => static::KEY_URL,
				'docs_url' => static::DOCS_URL
			));
		}

		$mResult = $this->vtGet('/files/' . $sHash, $sKey);
		if ('not-found' === $mResult) {
			return $this->jsonResponse(__FUNCTION__, array(
				'mode' => 'api',
				'sha256' => $sHash,
				'gui_url' => $sGui,
				'known' => 0,
				'stats' => null
			));
		}
		if (\is_string($mResult)) {
			return $this->jsonResponse(__FUNCTION__, array('error' => $mResult, 'gui_url' => $sGui));
		}

		return $this->jsonResponse(__FUNCTION__, array(
			'mode' => 'api',
			'sha256' => $sHash,
			'gui_url' => $sGui,
			'known' => 1,
			'stats' => $this->statsFromReport($mResult)
		));
	}

	/**
	 * Look up an existing URL report. Without a key, returns a GUI link only.
	 */
	public function DoVirusTotalUrl()
	{
		$this->Manager()->Actions()->getAccountFromToken();

		$sUrl = \trim((string) $this->jsonParam('url', ''));
		if (!$this->isHttpUrl($sUrl)) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'bad-url'));
		}

		$sId = \rtrim(\strtr(\base64_encode($sUrl), '+/', '-_'), '=');
		$sGui = static::GUI_BASE . '/url/' . $sId;
		$sKey = $this->apiKey();
		if (!\strlen($sKey)) {
			return $this->jsonResponse(__FUNCTION__, array(
				'mode' => 'link',
				'url' => $sUrl,
				'gui_url' => $sGui,
				'join_url' => static::JOIN_URL,
				'key_url' => static::KEY_URL,
				'docs_url' => static::DOCS_URL
			));
		}

		$mResult = $this->vtGet('/urls/' . $sId, $sKey);
		if ('not-found' === $mResult) {
			return $this->jsonResponse(__FUNCTION__, array(
				'mode' => 'api',
				'url' => $sUrl,
				'gui_url' => $sGui,
				'known' => 0,
				'stats' => null
			));
		}
		if (\is_string($mResult)) {
			return $this->jsonResponse(__FUNCTION__, array('error' => $mResult, 'gui_url' => $sGui));
		}

		return $this->jsonResponse(__FUNCTION__, array(
			'mode' => 'api',
			'url' => $sUrl,
			'gui_url' => $sGui,
			'known' => 1,
			'stats' => $this->statsFromReport($mResult)
		));
	}

	/**
	 * Upload an attachment for analysis. Requires API key and allow_submit.
	 * The browser sends base64 content after an explicit confirm (never automatic).
	 */
	public function DoVirusTotalSubmit()
	{
		$this->Manager()->Actions()->getAccountFromToken();

		if (!$this->Config()->Get('plugin', 'allow_submit', false)) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'submit-disabled'));
		}
		$sKey = $this->apiKey();
		if (!\strlen($sKey)) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'no-key'));
		}

		$sExpected = \strtolower(\trim((string) $this->jsonParam('sha256', '')));
		$sB64 = (string) $this->jsonParam('content_b64', '');
		if (!\preg_match('/^[a-f0-9]{64}$/D', $sExpected) || !\strlen($sB64)) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'bad-upload'));
		}

		$sBinary = \base64_decode($sB64, true);
		if (false === $sBinary || !\strlen($sBinary)) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'bad-upload'));
		}
		if (\strlen($sBinary) > static::MAX_UPLOAD) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'too-large'));
		}

		$sHash = \hash('sha256', $sBinary);
		if ($sHash !== $sExpected) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'hash-mismatch'));
		}
		$sGui = static::GUI_BASE . '/file/' . $sHash;

		// Existing report first: avoids an unnecessary upload and counts as one lookup.
		$mExisting = $this->vtGet('/files/' . $sHash, $sKey);
		if (\is_array($mExisting)) {
			return $this->jsonResponse(__FUNCTION__, array(
				'mode' => 'api',
				'submitted' => 0,
				'sha256' => $sHash,
				'gui_url' => $sGui,
				'known' => 1,
				'stats' => $this->statsFromReport($mExisting)
			));
		}
		if (\is_string($mExisting) && 'not-found' !== $mExisting) {
			return $this->jsonResponse(__FUNCTION__, array('error' => $mExisting, 'gui_url' => $sGui, 'sha256' => $sHash));
		}

		$mUpload = $this->vtUploadFile($sBinary, $sKey);
		if (\is_string($mUpload)) {
			return $this->jsonResponse(__FUNCTION__, array('error' => $mUpload, 'gui_url' => $sGui, 'sha256' => $sHash));
		}

		return $this->jsonResponse(__FUNCTION__, array(
			'mode' => 'api',
			'submitted' => 1,
			'sha256' => $sHash,
			'gui_url' => $sGui,
			'analysis_id' => $mUpload['data']['id'] ?? '',
			'known' => 0,
			'stats' => null
		));
	}

	private function apiKey() : string
	{
		try {
			$sKey = \trim((string) $this->Config()->getDecrypted('plugin', 'api_key', ''));
		} catch (\Throwable $e) {
			$sKey = \trim((string) $this->Config()->Get('plugin', 'api_key', ''));
		}
		return $sKey;
	}

	private function isHttpUrl(string $sUrl) : bool
	{
		if (\strlen($sUrl) < 8 || \strlen($sUrl) > 2048) {
			return false;
		}
		$a = \parse_url($sUrl);
		if (!\is_array($a) || empty($a['scheme']) || empty($a['host'])) {
			return false;
		}
		$sScheme = \strtolower((string) $a['scheme']);
		return 'http' === $sScheme || 'https' === $sScheme;
	}

	/**
	 * @param array|mixed $mReport
	 */
	private function statsFromReport($mReport) : ?array
	{
		if (!\is_array($mReport)) {
			return null;
		}
		$aStats = $mReport['data']['attributes']['last_analysis_stats'] ?? null;
		if (!\is_array($aStats)) {
			return null;
		}
		return array(
			'malicious' => (int) ($aStats['malicious'] ?? 0),
			'suspicious' => (int) ($aStats['suspicious'] ?? 0),
			'undetected' => (int) ($aStats['undetected'] ?? 0),
			'harmless' => (int) ($aStats['harmless'] ?? 0),
			'timeout' => (int) ($aStats['timeout'] ?? 0)
		);
	}

	/**
	 * @return array|string array on success, error code string otherwise
	 */
	private function vtGet(string $sPath, string $sKey)
	{
		try {
			$oHTTP = \Tachyon\Util\HTTP\Request::factory();
			$oHTTP->timeout = 15;
			$oResponse = $oHTTP->doRequest('GET', static::API_BASE . $sPath, null, array(
				'x-apikey: ' . $sKey,
				'Accept: application/json'
			));
		} catch (\Throwable $e) {
			\Tachyon\Util\Log::warning('VirusTotal', $sPath . ' failed: ' . $e->getMessage());
			return 'unreachable';
		}

		if (!$oResponse) {
			return 'unreachable';
		}

		switch ((int) $oResponse->status) {
			case 200:
				$aResult = \json_decode((string) $oResponse->body, true);
				return \is_array($aResult) ? $aResult : 'bad-answer';
			case 404:
				return 'not-found';
			case 401:
			case 403:
				return 'bad-key';
			case 429:
				return 'rate-limit';
			default:
				\Tachyon\Util\Log::warning(
					'VirusTotal',
					$sPath . ' answered ' . $oResponse->status . ': '
					. \substr(\preg_replace('/\s+/', ' ', (string) $oResponse->body), 0, 200)
				);
				return 'failed';
		}
	}

	/**
	 * @return array|string
	 */
	private function vtUploadFile(string $sBinary, string $sKey)
	{
		$sBoundary = '----TachyonVT' . \bin2hex(\random_bytes(8));
		$sBody = '--' . $sBoundary . "\r\n"
			. 'Content-Disposition: form-data; name="file"; filename="attachment.bin"' . "\r\n"
			. 'Content-Type: application/octet-stream' . "\r\n\r\n"
			. $sBinary . "\r\n"
			. '--' . $sBoundary . "--\r\n";

		try {
			$oHTTP = \Tachyon\Util\HTTP\Request::factory();
			$oHTTP->timeout = 60;
			$oResponse = $oHTTP->doRequest('POST', static::API_BASE . '/files', $sBody, array(
				'x-apikey: ' . $sKey,
				'Accept: application/json',
				'Content-Type: multipart/form-data; boundary=' . $sBoundary
			));
		} catch (\Throwable $e) {
			\Tachyon\Util\Log::warning('VirusTotal', 'upload failed: ' . $e->getMessage());
			return 'unreachable';
		}

		if (!$oResponse) {
			return 'unreachable';
		}

		switch ((int) $oResponse->status) {
			case 200:
				$aResult = \json_decode((string) $oResponse->body, true);
				return \is_array($aResult) ? $aResult : 'bad-answer';
			case 401:
			case 403:
				return 'bad-key';
			case 429:
				return 'rate-limit';
			default:
				\Tachyon\Util\Log::warning(
					'VirusTotal',
					'upload answered ' . $oResponse->status . ': '
					. \substr(\preg_replace('/\s+/', ' ', (string) $oResponse->body), 0, 200)
				);
				return 'failed';
		}
	}

}
