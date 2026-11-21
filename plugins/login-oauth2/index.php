<?php

/**
 * Generic OAuth2 login for any provider that supports RFC 7628 (XOAUTH2 /
 * OAUTHBEARER) on IMAP, SMTP and Sieve, and OpenID Connect style userinfo.
 *
 * Unlike login-gmail and login-o365 nothing here is tied to one provider: the
 * endpoints, scopes and the domains it answers for are all configuration. Point
 * it at Fastmail, Zoho, a corporate identity provider, or anything else that
 * issues refresh tokens.
 */

class LoginOAuth2Plugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME     = 'OAuth2',
		VERSION  = '2.2',
		RELEASE  = '2026-11-21',
		REQUIRED = '2.36.1',
		CATEGORY = 'Login',
		DESCRIPTION = 'IMAP, Sieve & SMTP login using RFC 7628 OAuth2, for any provider';

	use \Tachyon\Plugins\OAuth2Accounts;

	protected function oauthPrefix() : string
	{
		return 'login-oauth2';
	}

	protected function oauthTokenUri() : string
	{
		return \trim($this->Config()->Get('plugin', 'token_uri', ''));
	}

	protected function oauthClient() : ?\OAuth2\Client
	{
		$sId = \trim($this->Config()->Get('plugin', 'client_id', ''));
		$sSecret = \trim($this->Config()->getDecrypted('plugin', 'client_secret', ''));
		if (!$sId || !$sSecret) {
			return null;
		}
		$oActions = \Tachyon\Api::Actions();
		try {
			$oClient = new \OAuth2\Client($sId, $sSecret);
			$sProxy = $oActions->Config()->Get('labs', 'curl_proxy', '');
			if (\strlen($sProxy)) {
				$oClient->setCurlOption(\CURLOPT_PROXY, $sProxy);
				$sProxyAuth = $oActions->Config()->Get('labs', 'curl_proxy_auth', '');
				if (\strlen($sProxyAuth)) {
					$oClient->setCurlOption(\CURLOPT_PROXYUSERPWD, $sProxyAuth);
				}
			}
			return $oClient;
		} catch (\Throwable $oException) {
			$oActions->Logger()->WriteException($oException, \LOG_ERR);
		}
		return null;
	}

	public function Init() : void
	{
		$this->UseLangs(true);
		$this->addJs('LoginOAuth2.js');
		$this->addHook('imap.before-login', 'clientLogin');
		$this->addHook('smtp.before-login', 'clientLogin');
		$this->addHook('sieve.before-login', 'clientLogin');

		$this->addPartHook('LoginOAuth2', 'ServiceLoginOAuth2');
		$this->addJsonHook('LoginOAuth2AuthUrl', 'DoLoginOAuth2AuthUrl');
		$this->addJsonHook('LoginOAuth2Claim', 'DoLoginOAuth2Claim');

		// Prevent Disallowed Sec-Fetch Dest: document Mode: navigate Site: cross-site User: true
		$this->addHook('filter.http-paths', 'httpPaths');

		$this->addHook('json.after-AccountDelete', 'afterAccountDelete');
		$this->addHook('main.cryptkey-rotate', 'oauthRotateCryptKey');
	}

	public function configMapping() : array
	{
		return [
			\Tachyon\Plugins\Property::NewInstance('client_id')
				->SetLabel('Client ID')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING)
				->SetAllowedInJs(),
			\Tachyon\Plugins\Property::NewInstance('client_secret')
				->SetLabel('Client Secret')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING)
				->SetEncrypted(),
			\Tachyon\Plugins\Property::NewInstance('auth_uri')
				->SetLabel('Authorization endpoint')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::URL)
				->SetPlaceholder('https://example.com/oauth2/authorize'),
			\Tachyon\Plugins\Property::NewInstance('token_uri')
				->SetLabel('Token endpoint')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::URL)
				->SetPlaceholder('https://example.com/oauth2/token'),
			\Tachyon\Plugins\Property::NewInstance('userinfo_uri')
				->SetLabel('Userinfo endpoint')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::URL)
				->SetDescription('Queried with the access token to learn the address that was authorized.')
				->SetPlaceholder('https://example.com/oauth2/userinfo'),
			\Tachyon\Plugins\Property::NewInstance('scopes')
				->SetLabel('Scopes')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING_TEXT)
				->SetDescription('Space separated. Must cover the mail access the provider requires, plus whatever its userinfo endpoint needs to return an address.')
				->SetDefaultValue('openid email profile'),
			\Tachyon\Plugins\Property::NewInstance('domains')
				->SetLabel('Email domains')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING)
				->SetDescription('Space separated, e.g. "example.com example.net". Only addresses in these domains use OAuth2; everything else logs in normally. Leave empty to apply to every address.')
				->SetAllowedInJs()
		];
	}

	public function httpPaths(array $aPaths) : void
	{
		if (!empty($aPaths[0]) && 'LoginOAuth2' === $aPaths[0]) {
			$oConfig = \Tachyon\Api::Config();
			$oConfig->Set('security', 'secfetch_allow',
				\trim($oConfig->Get('security', 'secfetch_allow', '') . ';site=cross-site', ';')
			);
		}
	}

	public function clientLogin(\Tachyon\Model\Account $oAccount, \MailSo\Net\NetClient $oClient, \MailSo\Net\ConnectSettings $oSettings) : void
	{
		if ($this->isSupportedEmail($oAccount->Email())) {
			$this->oauthApplySettings($oAccount, $oSettings);
		}
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
			if ($sEmail && $this->isSupportedEmail($sEmail)) {
				$this->oauthClearTokens($oMain, $sEmail);
			}
		}
	}

	public function isSupportedEmail(string $sEmail) : bool
	{
		$sEmail = \strtolower(\trim($sEmail));
		if (!\str_contains($sEmail, '@')) {
			return false;
		}
		$aDomains = \preg_split('/[\s,;]+/', \strtolower(\trim($this->Config()->Get('plugin', 'domains', ''))), -1, \PREG_SPLIT_NO_EMPTY);
		if (!$aDomains) {
			// Unconfigured means every address, which is what a single-provider
			// install wants and is the only sensible reading of an empty list.
			return true;
		}
		$sDomain = \substr($sEmail, \strrpos($sEmail, '@') + 1);
		return \in_array($sDomain, $aDomains, true);
	}

	private function redirectUri() : string
	{
		return \Tachyon\Api::Actions()->Http()->GetFullUrl() . '?LoginOAuth2';
	}

	public function DoLoginOAuth2AuthUrl() : array
	{
		$sOp = (string) $this->jsonParam('op', 'login');
		if (!\in_array($sOp, ['login', 'add'], true)) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$sClientId = \trim($this->Config()->Get('plugin', 'client_id', ''));
		$sAuthUri = \trim($this->Config()->Get('plugin', 'auth_uri', ''));
		if (!$sClientId || !$sAuthUri || !$this->oauthTokenUri()) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$sEmail = $this->oauthNormalise((string) $this->jsonParam('email', ''));
		if ($sEmail && !$this->isSupportedEmail($sEmail)) {
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
			'scope' => \implode(' ', \preg_split('/\s+/',
				\trim($this->Config()->Get('plugin', 'scopes', '')), -1, \PREG_SPLIT_NO_EMPTY)),
			'state' => $this->oauthMintState($aPayload),
			// Ask to be shown the consent screen, since without it many providers
			// return no refresh token on a repeat authorization.
			'access_type' => 'offline',
			'prompt' => 'consent'
		];
		if ($sEmail) {
			$aParams['login_hint'] = $sEmail;
		}

		return $this->jsonResponse(__FUNCTION__, [
			'authUrl' => $sAuthUri . '?' . \http_build_query($aParams, '', '&', \PHP_QUERY_RFC3986)
		]);
	}

	public function ServiceLoginOAuth2() : string
	{
		$oActions = \Tachyon\Api::Actions();
		$oActions->Http()->ServerNoCache();

		$sUri = \preg_replace('/.LoginOAuth2.*$/D', '', $_SERVER['REQUEST_URI']);

		try
		{
			if (isset($_GET['error'])) {
				throw new \RuntimeException((string) $_GET['error'] . ': ' . ($_GET['error_description'] ?? ''));
			}

			$aState = empty($_GET['state']) ? null : $this->oauthConsumeState((string) $_GET['state']);
			$oClient = (isset($_GET['code']) && $aState) ? $this->oauthClient() : null;
			if (!$oClient) {
				$oActions->Location($sUri);
				exit;
			}

			$iExpires = \time();
			$aResponse = $oClient->getAccessToken($this->oauthTokenUri(), 'authorization_code', [
				'code' => $_GET['code'],
				'redirect_uri' => $this->redirectUri()
			]);
			if (200 != $aResponse['code']) {
				throw new \RuntimeException("Token HTTP {$aResponse['code']}: "
					. ($aResponse['result']['error'] ?? '') . ' / ' . ($aResponse['result']['error_description'] ?? ''));
			}
			$aResponse = $aResponse['result'];
			if (empty($aResponse['access_token'])) {
				throw new \RuntimeException('access_token missing');
			}
			if (empty($aResponse['refresh_token'])) {
				// Without one every session would need the consent screen again.
				throw new \RuntimeException('refresh_token missing, check that the provider was asked for offline access');
			}
			$iExpires += (int) ($aResponse['expires_in'] ?? 0);

			$aIdentity = $this->fetchIdentity($oClient, $aResponse['access_token']);
			if (!$this->isSupportedEmail($aIdentity['email'])) {
				throw new \RuntimeException('Address is outside the configured domains');
			}

			$aTokens = [
				'access_token' => $aResponse['access_token'],
				'refresh_token' => $aResponse['refresh_token'],
				'expires_in' => (int) ($aResponse['expires_in'] ?? 0),
				'expires' => $iExpires
			];

			if ('add' === ($aState['op'] ?? 'login')) {
				// No cookies reach this request, so the main account cannot be
				// identified or unsealed here. Park the tokens and let an
				// authenticated request finish the job.
				$sSecret = $this->oauthPutPickup([
					'main'     => (string) ($aState['main'] ?? ''),
					'email'    => $aIdentity['email'],
					'identity' => $aIdentity['id'],
					'name'     => (string) ($aState['name'] ?? ''),
					'tokens'   => $aTokens
				]);

				$sReturn = '#/settings/accounts';
				if (!empty($aState['return']) && \is_string($aState['return']) && \str_starts_with($aState['return'], '#')) {
					$sReturn = $aState['return'];
				}
				$sJoin = \str_contains($sReturn, '?') ? '&' : '?';
				$oActions->Location($sUri . $sReturn . $sJoin . 'oauth2claim=' . \rawurlencode($sSecret));
				exit;
			}

			$this->oauthSeedToken($aIdentity['email'], $aTokens);
			$oAccount = $oActions->LoginProcess($aIdentity['email'],
				new \Tachyon\Util\SensitiveString($aIdentity['id']));
			if ($oAccount) {
				$this->oauthSaveTokensFor($oAccount, $aTokens);
			}
		}
		catch (\Throwable $oException)
		{
			$oActions->Logger()->WriteException($oException, \LOG_ERR);
		}
		$oActions->Location($sUri);
		exit;
	}

	/**
	 * Finishes an add started before the cross-site redirect. Runs as an ordinary
	 * authenticated request, so the session cookie is present and the main
	 * account's CryptKey is reachable, neither of which is true in the callback.
	 */
	public function DoLoginOAuth2Claim() : array
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

	/**
	 * Providers disagree on what the address claim is called, so try the ones in
	 * use rather than making the admin tell us.
	 *
	 * @return array{email:string,id:string}
	 */
	private function fetchIdentity(\OAuth2\Client $oClient, string $sAccessToken) : array
	{
		$sUri = \trim($this->Config()->Get('plugin', 'userinfo_uri', ''));
		if (!$sUri) {
			throw new \RuntimeException('No userinfo endpoint is configured, so the authorized address cannot be established');
		}
		$oClient->setAccessToken($sAccessToken);
		$aInfo = $oClient->fetch($sUri);
		if (200 != $aInfo['code']) {
			throw new \RuntimeException("Userinfo HTTP {$aInfo['code']}");
		}
		$aInfo = $aInfo['result'];
		if (!\is_array($aInfo)) {
			throw new \RuntimeException('Userinfo did not return an object');
		}

		$sEmail = '';
		foreach (['email', 'preferred_username', 'upn', 'mail', 'username'] as $sKey) {
			if (!empty($aInfo[$sKey]) && \is_string($aInfo[$sKey]) && \str_contains($aInfo[$sKey], '@')) {
				$sEmail = $aInfo[$sKey];
				break;
			}
		}
		if (!$sEmail) {
			throw new \RuntimeException('Userinfo carried no email address');
		}

		// Stands in for the password and authenticates nothing, but it should be
		// stable for the account rather than change on every authorization.
		$sId = '';
		foreach (['sub', 'id', 'oid', 'user_id'] as $sKey) {
			if (!empty($aInfo[$sKey]) && (\is_string($aInfo[$sKey]) || \is_int($aInfo[$sKey]))) {
				$sId = (string) $aInfo[$sKey];
				break;
			}
		}

		return ['email' => $sEmail, 'id' => $sId ?: $sEmail];
	}
}
