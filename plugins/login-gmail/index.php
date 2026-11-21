<?php

/**
 * https://developers.google.com/gmail/imap/imap-smtp
 * https://developers.google.com/gmail/imap/xoauth2-protocol
 * https://console.cloud.google.com/apis/dashboard
 */

class LoginGMailPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME     = 'Login GMail OAuth2',
		VERSION  = '2.40',
		RELEASE  = '2026-11-21',
		REQUIRED = '2.36.1',
		CATEGORY = 'Login',
		DESCRIPTION = 'GMail IMAP, Sieve & SMTP login using RFC 7628 OAuth2';

	const
		LOGIN_URI = 'https://accounts.google.com/o/oauth2/auth',
		TOKEN_URI = 'https://accounts.google.com/o/oauth2/token';

	use \Tachyon\Plugins\OAuth2Accounts;

	protected function oauthPrefix() : string
	{
		return 'login-gmail';
	}

	protected function oauthTokenUri() : string
	{
		return static::TOKEN_URI;
	}

	protected function oauthClient() : ?\OAuth2\Client
	{
		return $this->gmailConnector();
	}

	public function Init() : void
	{
		$this->UseLangs(true);
		$this->addJs('LoginOAuth2.js');
		$this->addHook('imap.before-login', 'clientLogin');
		$this->addHook('smtp.before-login', 'clientLogin');
		$this->addHook('sieve.before-login', 'clientLogin');

		$this->addPartHook('LoginGMail', 'ServiceLoginGMail');
		// The authorize URL is built server side so the state can be signed and the
		// redirect_uri cannot drift from what is registered with Google.
		$this->addJsonHook('LoginGMailAuthUrl', 'DoLoginGMailAuthUrl');
		// Collects the tokens the callback parked, from a request that still has its
		// cookies and so can reach the main account's CryptKey.
		$this->addJsonHook('LoginGMailClaim', 'DoLoginGMailClaim');

		// Prevent Disallowed Sec-Fetch Dest: document Mode: navigate Site: cross-site User: true
		$this->addHook('filter.http-paths', 'httpPaths');

		$this->addHook('json.after-AccountDelete', 'afterAccountDelete');
		$this->addHook('main.cryptkey-rotate', 'oauthRotateCryptKey');
	}

	public function afterAccountDelete(array &$aResponse) : void
	{
		if (empty($aResponse['Result'])) {
			return;
		}
		$oActions = \Tachyon\Api::Actions();
		$oMain = $oActions->getMainAccountFromToken(false);
		if ($oMain) {
			$sEmail = $this->oauthNormalise((string) $oActions->GetActionParam('emailToDelete', ''));
			if ($sEmail && \str_ends_with($sEmail, '@gmail.com')) {
				$this->oauthClearTokens($oMain, $sEmail);
			}
		}
	}

	private function redirectUri() : string
	{
		return \Tachyon\Api::Actions()->Http()->GetFullUrl() . '?LoginGMail';
	}

	public function DoLoginGMailAuthUrl() : array
	{
		$sOp = (string) $this->jsonParam('op', 'login');
		if (!\in_array($sOp, ['login', 'add'], true)) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$sClientId = \trim($this->Config()->Get('plugin', 'client_id', ''));
		if (!$sClientId) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$sEmail = $this->oauthNormalise((string) $this->jsonParam('email', ''));
		if ($sEmail && !\str_ends_with($sEmail, '@gmail.com')) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$aPayload = ['v' => 1, 'op' => $sOp];
		if ('add' === $sOp) {
			// Only a logged in account can gain one, and we need its address now,
			// while the cookie is still with us.
			$oMain = \Tachyon\Api::Actions()->getMainAccountFromToken(false);
			if (!$oMain) {
				return $this->jsonResponse(__FUNCTION__, false);
			}
			$aPayload['main'] = $oMain->Email();
			$sName = \trim((string) $this->jsonParam('name', ''));
			if ($sName) {
				$aPayload['name'] = \substr($sName, 0, 100);
			}
			$sReturn = (string) $this->jsonParam('return', '');
			if ($sReturn && \str_starts_with($sReturn, '#')) {
				$aPayload['return'] = \substr($sReturn, 0, 200);
			}
		}

		$aParams = [
			'response_type' => 'code',
			'client_id' => $sClientId,
			'redirect_uri' => $this->redirectUri(),
			'scope' => \implode(' ', [
				// Primary Google Account email address
				'https://www.googleapis.com/auth/userinfo.email',
				// Personal info
				'https://www.googleapis.com/auth/userinfo.profile',
				// Associate personal info
				'openid',
				// Access IMAP and SMTP through OAUTH
				'https://mail.google.com/'
			]),
			'state' => $this->oauthMintState($aPayload),
			// Force authorize screen, so we always get a refresh_token
			'access_type' => 'offline',
			'prompt' => 'consent'
		];
		if ($sEmail) {
			$aParams['login_hint'] = $sEmail;
		}

		return $this->jsonResponse(__FUNCTION__, [
			'authUrl' => static::LOGIN_URI . '?' . \http_build_query($aParams, '', '&', \PHP_QUERY_RFC3986)
		]);
	}

	/**
	 * Finishes an add started before the cross-site redirect. Runs as an ordinary
	 * authenticated request, so the session cookie is present and the main
	 * account's CryptKey is reachable, neither of which is true in the callback.
	 */
	public function DoLoginGMailClaim() : array
	{
		$sSecret = (string) $this->jsonParam('pickup', '');
		if (!\strlen($sSecret)) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$oMain = \Tachyon\Api::Actions()->getMainAccountFromToken(false);
		if (!$oMain) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$aPickup = $this->oauthTakePickup($sSecret);
		if (!$aPickup || empty($aPickup['email']) || empty($aPickup['tokens']['access_token'])) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		// The flow was started by one specific account; nobody else may finish it,
		// even while authenticated.
		if (!empty($aPickup['main']) && $aPickup['main'] !== $oMain->Email()) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$this->oauthAddAccount($oMain, (string) $aPickup['email'], $aPickup['tokens'],
			(string) ($aPickup['identity'] ?? ''), (string) ($aPickup['name'] ?? ''));

		return $this->jsonResponse(__FUNCTION__, true);
	}

	public function httpPaths(array $aPaths) : void
	{
		if (!empty($aPaths[0]) && 'LoginGMail' === $aPaths[0]) {
			$oConfig = \Tachyon\Api::Config();
			$oConfig->Set('security', 'secfetch_allow',
				\trim($oConfig->Get('security', 'secfetch_allow', '') . ';site=cross-site', ';')
			);
		}
	}

	public function ServiceLoginGMail() : string
	{
		$oActions = \Tachyon\Api::Actions();
		$oHttp = $oActions->Http();
		$oHttp->ServerNoCache();

		$uri = \preg_replace('/.LoginGMail.*$/D', '', $_SERVER['REQUEST_URI']);

		try
		{
			if (isset($_GET['error'])) {
				throw new \RuntimeException($_GET['error']);
			}
			// The state used to be the fixed string 'gmail', which proved nothing.
			// It is now signed with the client secret and names a single-use record
			// holding what this flow is for.
			$aState = empty($_GET['state']) ? null : $this->oauthConsumeState((string) $_GET['state']);
			if (isset($_GET['code']) && $aState) {
				$oGMail = $this->gmailConnector();
			}
			if (empty($oGMail)) {
				$oActions->Location($uri);
				exit;
			}

			$iExpires = \time();
			$aResponse = $oGMail->getAccessToken(
				static::TOKEN_URI,
				'authorization_code',
				array(
					'code' => $_GET['code'],
					'redirect_uri' => $this->redirectUri()
				)
			);
			if (200 != $aResponse['code']) {
				if (isset($aResponse['result']['error'])) {
					throw new \RuntimeException(
						$aResponse['code']
						. ': '
						. $aResponse['result']['error']
						. ' / '
						. $aResponse['result']['error_description']
					);
				}
				throw new \RuntimeException("HTTP: {$aResponse['code']}");
			}
			$aResponse = $aResponse['result'];
			if (empty($aResponse['access_token'])) {
				throw new \RuntimeException('access_token missing');
			}
			if (empty($aResponse['refresh_token'])) {
				throw new \RuntimeException('refresh_token missing');
			}

			$sAccessToken = $aResponse['access_token'];
			$iExpires += $aResponse['expires_in'];

			$oGMail->setAccessToken($sAccessToken);
			$aUserInfo = $oGMail->fetch('https://www.googleapis.com/oauth2/v2/userinfo');
			if (200 != $aUserInfo['code']) {
				throw new \RuntimeException("HTTP: {$aResponse['code']}");
			}
			$aUserInfo = $aUserInfo['result'];
			if (empty($aUserInfo['id'])) {
				throw new \RuntimeException('unknown id');
			}
			if (empty($aUserInfo['email'])) {
				throw new \RuntimeException('unknown email address');
			}

			$aTokens = [
				'access_token' => $sAccessToken,
				'refresh_token' => $aResponse['refresh_token'],
				'expires_in' => $aResponse['expires_in'],
				'expires' => $iExpires
			];
			if ('add' === ($aState['op'] ?? 'login')) {
				// No cookies reach this request, so the main account cannot be
				// identified or unsealed here. Park the tokens and let an
				// authenticated request finish the job.
				$sSecret = $this->oauthPutPickup([
					'main'     => (string) ($aState['main'] ?? ''),
					'email'    => $aUserInfo['email'],
					'identity' => (string) $aUserInfo['id'],
					'name'     => (string) ($aState['name'] ?? ''),
					'tokens'   => $aTokens
				]);

				$sReturn = '#/settings/accounts';
				if (!empty($aState['return']) && \is_string($aState['return']) && \str_starts_with($aState['return'], '#')) {
					$sReturn = $aState['return'];
				}
				$sJoin = \str_contains($sReturn, '?') ? '&' : '?';
				$oActions->Location($uri . $sReturn . $sJoin . 'gmailclaim=' . \rawurlencode($sSecret));
				exit;
			}

			$this->oauthSeedToken($aUserInfo['email'], $aTokens);

			$oPassword = new \Tachyon\Util\SensitiveString($aUserInfo['id']);
			$oAccount = $oActions->LoginProcess($aUserInfo['email'], $oPassword);
//			$oAccount = MainAccount::NewInstanceFromCredentials($oActions, $aUserInfo['email'], $aUserInfo['email'], $oPassword, true);
			if ($oAccount) {
//				$oActions->SetMainAuthAccount($oAccount);
//				$oActions->SetAuthToken($oAccount);
				$this->oauthSaveTokensFor($oAccount, $aTokens);
			}
		}
		catch (\Exception $oException)
		{
			$oActions->Logger()->WriteException($oException, \LOG_ERR);
		}
		$oActions->Location($uri);
		exit;
	}

	public function configMapping() : array
	{
		return [
			\Tachyon\Plugins\Property::NewInstance('client_id')
				->SetLabel('Client ID')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING)
				->SetAllowedInJs()
				->SetDescription('https://github.com/the-djmaze/snappymail/wiki/FAQ#gmail'),
			\Tachyon\Plugins\Property::NewInstance('client_secret')
				->SetLabel('Client Secret')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING)
				->SetEncrypted()
		];
	}

	public function clientLogin(\Tachyon\Model\Account $oAccount, \MailSo\Net\NetClient $oClient, \MailSo\Net\ConnectSettings $oSettings) : void
	{
		// Additional accounts used to be excluded here, which left a Gmail account
		// added alongside the login one with no way to authenticate but an app
		// password, and so 2-step verification turned on to get one.
		if (\str_ends_with(\strtolower($oAccount->Email()), '@gmail.com')) {
			$this->oauthApplySettings($oAccount, $oSettings);
		}
	}

	protected function gmailConnector() : ?\OAuth2\Client
	{
		$client_id = \trim($this->Config()->Get('plugin', 'client_id', ''));
		$client_secret = \trim($this->Config()->getDecrypted('plugin', 'client_secret', ''));
		if ($client_id && $client_secret) {
			try
			{
				$oGMail = new \OAuth2\Client($client_id, $client_secret);
				$oActions = \Tachyon\Api::Actions();
				$sProxy = $oActions->Config()->Get('labs', 'curl_proxy', '');
				if (\strlen($sProxy)) {
					$oGMail->setCurlOption(CURLOPT_PROXY, $sProxy);
					$sProxyAuth = $oActions->Config()->Get('labs', 'curl_proxy_auth', '');
					if (\strlen($sProxyAuth)) {
						$oGMail->setCurlOption(CURLOPT_PROXYUSERPWD, $sProxyAuth);
					}
				}
				return $oGMail;
			}
			catch (\Exception $oException)
			{
				$oActions->Logger()->WriteException($oException, \LOG_ERR);
			}
		}
		return null;
	}
}
