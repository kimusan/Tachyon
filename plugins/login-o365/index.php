<?php
/**
 * Tachyon login-o365 plugin
 * You need to register an app in Azure portal and add
 * a secret, redirect URIs and the following API permissions:
 *     https://outlook.office.com/IMAP.AccessAsUser.All
 *     https://outlook.office.com/SMTP.Send
 *     openid offline_access email profile
 * Supported account types has to include personal Microsoft accounts for an
 * outlook.com, hotmail.com or live.com mailbox to be able to sign in at all.
 * https://learn.microsoft.com/en-us/entra/identity-platform/reply-url#query-parameter-support-in-redirect-uris
 * Query: redirect_uri=https://{DOMAIN}/?LoginO365
 * Path:  redirect_uri=https://{DOMAIN}/LoginO365
 * The two are not interchangeable. Microsoft allows a query parameter in the
 * redirect URI only for registrations that sign in work or school accounts, so
 * a registration that admits personal accounts must use the path form, which is
 * what the 'personal' setting leaves alone by defaulting to false. The name of
 * that setting reads the wrong way round for this reason; it is kept because
 * renaming it would silently reset the choice on every install that set it.
 *
 * If running behind nginx reverse proxy you might
 * need to add the following to your nginx config:
 * location = /LoginO365 {
 *     return 302 /?LoginO365&$args;
 * }
 */

class LoginO365Plugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME     = 'Office365/Outlook OAuth2',
		VERSION  = '0.7',
		RELEASE  = '2026-11-21',
		REQUIRED = '2.36.1',
		CATEGORY = 'Login',
		DESCRIPTION = 'Office365/Outlook IMAP, Sieve & SMTP login using RFC 7628 OAuth2';

	// v2 endpoints
	const
		AUTH_URI  = 'https://login.microsoftonline.com/{{tenant}}/oauth2/v2.0/authorize',
		TOKEN_URI = 'https://login.microsoftonline.com/{{tenant}}/oauth2/v2.0/token';

	use \Tachyon\Plugins\OAuth2Accounts;

	protected function oauthPrefix() : string
	{
		return 'login-o365';
	}

	protected function oauthTokenUri() : string
	{
		return \str_replace('{{tenant}}', $this->Config()->Get('plugin', 'tenant', 'common'), static::TOKEN_URI);
	}

	protected function oauthClient() : ?\OAuth2\Client
	{
		return $this->o365Connector();
	}

	public function Init() : void
	{
		$this->UseLangs(true);
		$this->addJs('LoginOAuth2.js');
		$this->addHook('imap.before-login', 'clientLogin');
		$this->addHook('smtp.before-login', 'clientLogin');
		$this->addHook('sieve.before-login', 'clientLogin');

		$this->addPartHook('LoginO365', 'ServiceLoginO365');
		// Used by JS to obtain an auth URL with signed state (for both login + add-account flows).
		$this->addJsonHook('LoginO365AuthUrl', 'DoLoginO365AuthUrl');
		// Collects the tokens the callback parked, from a request that still has
		// its cookies and so can reach the main account's CryptKey.
		$this->addJsonHook('LoginO365Claim', 'DoLoginO365Claim');

		// Prevent Disallowed Sec-Fetch Dest: document Mode: navigate Site: cross-site User: true
		$this->addHook('filter.http-paths', 'httpPaths');

		// Cleanup: when an additional account is removed, also remove its encrypted refresh token bundle.
		$this->addHook('json.after-AccountDelete', 'afterAccountDelete');
		$this->addHook('main.cryptkey-rotate', 'oauthRotateCryptKey');
	}

	public function httpPaths(array &$aPaths) : void
	{
		if (!empty($_SERVER['PATH_INFO']) && \str_ends_with($_SERVER['PATH_INFO'], 'LoginO365')) {
			$aPaths = ['LoginO365'];
		}

		if (!empty($aPaths[0]) && 'LoginO365' === $aPaths[0]) {
			$oConfig = \Tachyon\Api::Config();
			$oConfig->Set('security', 'secfetch_allow',
				\trim($oConfig->Get('security', 'secfetch_allow', '') . ';site=cross-site', ';')
			);
		}
	}

	public function ServiceLoginO365() : string
	{
		$oActions = \Tachyon\Api::Actions();
		$oHttp = $oActions->Http();
		$oHttp->ServerNoCache();

		try
		{
			if (isset($_GET['error'])) {
				$desc = $_GET['error_description'] ?? '';
				throw new \RuntimeException("{$_GET['error']}: {$desc}");
			}

			// Must have code + state
			if (!isset($_GET['code']) || empty($_GET['state'])) {
				$oActions->Location(\Tachyon\Utils::WebPath());
				exit;
			}

			$oO365 = $this->o365Connector();
			if (!$oO365) {
				$oActions->Location(\Tachyon\Utils::WebPath());
				exit;
			}

			$iNow = \time();

			$redirectUri = $this->redirectUri();

			$tenant = $this->Config()->Get('plugin', 'tenant', 'common');

			$state = (string) $_GET['state'];
			$statePayload = $this->oauthConsumeState($state);
			if (!$statePayload) {
				$oActions->Location(\Tachyon\Utils::WebPath());
				exit;
			}

			$aTokenWrap = $oO365->getAccessToken(
				\str_replace('{{tenant}}', $tenant, static::TOKEN_URI),
				'authorization_code',
				[
					'code' => $_GET['code'],
					'redirect_uri' => $redirectUri
				]
			);

			if (!\is_array($aTokenWrap) || !isset($aTokenWrap['code'])) {
				throw new \RuntimeException('Token request failed: ' . \json_encode($aTokenWrap));
			}
			if (200 !== (int)$aTokenWrap['code']) {
				$err = $aTokenWrap['result']['error'] ?? '';
				$desc = $aTokenWrap['result']['error_description'] ?? '';
				throw new \RuntimeException("Token HTTP {$aTokenWrap['code']}: {$err} / {$desc}");
			}

			$aToken = $aTokenWrap['result'] ?? [];
			$accessToken = $aToken['access_token'] ?? '';
			$refreshToken = $aToken['refresh_token'] ?? '';
			$expiresIn = (int)($aToken['expires_in'] ?? 0);
			$idToken = $aToken['id_token'] ?? '';

			if ($accessToken === '') {
				throw new \RuntimeException('access_token missing');
			}

			if ($refreshToken === '') {
				throw new \RuntimeException('refresh_token missing');
			}
			if ($idToken === '') {
				// We rely on id_token to get email/sub without Graph.
				throw new \RuntimeException('id_token missing (add openid email profile scopes)');
			}

			// Parse id_token (JWT) to get identity (sub + email)
			$claims = $this->decodeJwtPayload($idToken);
			if (!\is_array($claims)) {
				throw new \RuntimeException('Cannot decode id_token payload');
			}

			$email = $claims['email'] ?? ($claims['preferred_username'] ?? ($claims['upn'] ?? ''));
			$sub = $claims['sub'] ?? '';

			if ($sub === '') {
				throw new \RuntimeException('unknown id from id_token');
			}
			if ($email === '') {
				throw new \RuntimeException('unknown email address from id_token');
			}

			if (!$this->isSupportedEmail(\strtolower($email))) {
				throw new \RuntimeException('Unsupported email domain for this plugin');
			}

			$tokenBundle = [
				'access_token' => $accessToken,
				'refresh_token' => $refreshToken,
				'expires_in' => $expiresIn,
				'expires' => $iNow + $expiresIn
			];

			$op = $statePayload['op'] ?? 'login';
			if ('add' === $op) {
				// No cookies reach this request, so the main account cannot be
				// identified or unsealed here. Park the tokens and let an
				// authenticated request finish the job.
				$sSecret = $this->oauthPutPickup([
					'main'     => (string) ($statePayload['main'] ?? ''),
					'email'    => $email,
					'identity' => $sub,
					'name'     => (string) ($statePayload['name'] ?? ''),
					'tokens'   => $tokenBundle
				]);

				$returnHash = '#/settings/accounts';
				if (!empty($statePayload['return']) && \is_string($statePayload['return']) && \str_starts_with($statePayload['return'], '#')) {
					$returnHash = $statePayload['return'];
				}
				$sJoin = \str_contains($returnHash, '?') ? '&' : '?';
				$oActions->Location(\Tachyon\Utils::WebPath() . $returnHash . $sJoin . 'o365claim=' . \rawurlencode($sSecret));
				exit;
			}

			// Default: "login" flow (preserve existing behavior)
			$this->oauthSeedToken($email, $tokenBundle);

			// Tachyon uses password as opaque string; plugin injects XOAUTH2 later.
			$oPassword = new \Tachyon\Util\SensitiveString($sub);
			$oAccount = $oActions->LoginProcess($email, $oPassword);

			if ($oAccount) {
				$this->oauthSaveTokensFor($oAccount, $tokenBundle);
			}
		}
		catch (\Throwable $e) {
			$oActions->Logger()->WriteException($e, \LOG_ERR);
		}

		$oActions->Location(\Tachyon\Utils::WebPath());
		exit;
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
			\Tachyon\Plugins\Property::NewInstance('tenant')
				->SetLabel('Tenant')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::SELECTION)
				->SetDefaultValue(['common','consumers','organizations'])
				->SetAllowedInJs(),
			\Tachyon\Plugins\Property::NewInstance('personal')
				// When true: redirect URI uses query parameter form "/?LoginO365" (Azure supports it).
				// When false: redirect URI uses path form "/LoginO365" (useful behind reverse proxies).
				->SetLabel('Use "/?LoginO365" redirect URI')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(false)
				->SetAllowedInJs(),
			\Tachyon\Plugins\Property::NewInstance('allow_any_domain')
				->SetLabel('Allow any domain (not only outlook.com/hotmail.com/live.com)')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(false)
				->SetAllowedInJs()
		];
	}

	public function clientLogin(\Tachyon\Model\Account $oAccount, \MailSo\Net\NetClient $oClient, \MailSo\Net\ConnectSettings $oSettings) : void
	{
		if ($this->isSupportedEmail(\strtolower($oAccount->Email()))) {
			$this->oauthApplySettings($oAccount, $oSettings);
		}
	}

	/**
	 * Server-side cleanup hook: after a successful AccountDelete, remove stored token bundle for that email.
	 * This prevents leaving encrypted refresh tokens behind when an additional account is removed.
	 */
	public function afterAccountDelete(array &$aResponse) : void
	{
		if (empty($aResponse['Result'])) {
			return;
		}
		$oActions = \Tachyon\Api::Actions();
		$oMain = $oActions->getMainAccountFromToken(false);
		if (!$oMain) {
			return;
		}
		$email = $this->oauthNormalise((string) $oActions->GetActionParam('emailToDelete', ''));
		if ($email && $this->isSupportedEmail($email)) {
			$this->oauthClearTokens($oMain, $email);
		}
	}

	/**
	 * Finishes an add started before the cross-site redirect. Runs as an ordinary
	 * authenticated request, so the session cookie is present and the main
	 * account's CryptKey is reachable, neither of which is true in the callback.
	 */
	public function DoLoginO365Claim() : array
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

	protected function o365Connector() : ?\OAuth2\Client
	{
		$client_id = \trim($this->Config()->Get('plugin', 'client_id', ''));
		$client_secret = \trim($this->Config()->getDecrypted('plugin', 'client_secret', ''));

		if ($client_id && $client_secret) {
			try {
				$oO365 = new \OAuth2\Client($client_id, $client_secret);

				$oActions = \Tachyon\Api::Actions();
				$sProxy = $oActions->Config()->Get('labs', 'curl_proxy', '');
				if (\strlen($sProxy)) {
					$oO365->setCurlOption(CURLOPT_PROXY, $sProxy);
					$sProxyAuth = $oActions->Config()->Get('labs', 'curl_proxy_auth', '');
					if (\strlen($sProxyAuth)) {
						$oO365->setCurlOption(CURLOPT_PROXYUSERPWD, $sProxyAuth);
					}
				}

				return $oO365;
			} catch (\Throwable $e) {
				\Tachyon\Api::Actions()->Logger()->WriteException($e, \LOG_ERR);
			}
		}

		return null;
	}

	private function decodeJwtPayload(string $jwt) : ?array
	{
		$parts = \explode('.', $jwt);
		if (\count($parts) < 2) {
			return null;
		}
		$payload = $parts[1];
		$payload .= \str_repeat('=', (4 - (\strlen($payload) % 4)) % 4);
		$json = \base64_decode(\strtr($payload, '-_', '+/'));
		if ($json === false) {
			return null;
		}
		$data = \json_decode($json, true);
		return \is_array($data) ? $data : null;
	}

	/**
	 * JSON action called by JS to obtain an MS authorize URL with signed state.
	 * This avoids exposing any signing secret to JS and keeps redirect_uri consistent with server logic.
	 */
	public function DoLoginO365AuthUrl() : array
	{
		$oActions = \Tachyon\Api::Actions();

		$op = (string) $this->jsonParam('op', 'login');
		if (!\in_array($op, ['login', 'add'], true)) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$email = \strtolower(\trim((string) $this->jsonParam('email', '')));
		$name = \trim((string) $this->jsonParam('name', ''));
		$returnHash = (string) $this->jsonParam('return', '');

		if ($returnHash && !\str_starts_with($returnHash, '#')) {
			$returnHash = '';
		}

		// For add-account flow, require a logged-in main account (we must write to its additionalaccounts storage).
		$oMainAccount = null;
		if ('add' === $op) {
			$oMainAccount = $oActions->getMainAccountFromToken(false);
			if (!$oMainAccount) {
				return $this->jsonResponse(__FUNCTION__, false);
			}
		}

		// Optional server-side guard: only permit supported consumer domains unless configured otherwise.
		if ($email && !$this->isSupportedEmail($email)) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$oConfig = $this->Config();
		$client_id = \trim($oConfig->Get('plugin', 'client_id', ''));
		if (!$client_id) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		// Everything the callback needs is kept server side under a random nonce,
		// and only the nonce travels through the browser. The state used to carry
		// a CSRF token compared against Utils::GetCsrfToken() in the callback,
		// which cannot work: that value comes from a cookie the cross-site
		// redirect withholds, so the comparison was against a freshly minted one.
		$payload = ['v' => 1, 'op' => $op];
		if ('add' === $op && $oMainAccount) {
			$payload['main'] = $oMainAccount->Email();
			if ($name) {
				$payload['name'] = \substr($name, 0, 100);
			}
			if ($returnHash) {
				$payload['return'] = \substr($returnHash, 0, 200);
			}
		}

		$state = $this->oauthMintState($payload);
		$tenant = $oConfig->Get('plugin', 'tenant', 'common');
		$redirectUri = $this->redirectUri();

		$params = [
				'response_type' => 'code',
				'client_id' => $client_id,
				'redirect_uri' => $redirectUri,
				'scope' => \implode(' ', [
					'openid',
					'offline_access',
					'email',
					'profile',
					'https://outlook.office.com/IMAP.AccessAsUser.All',
					'https://outlook.office.com/SMTP.Send',
				]),
				'state' => $state,
				// Helps MS UI prefill, but does not change server-side validation.
		];
		if ($email) {
			$params['login_hint'] = $email;
		}
		$authUrl = \str_replace('{{tenant}}', $tenant, static::AUTH_URI)
			. '?'
			. \http_build_query($params, '', '&', PHP_QUERY_RFC3986);

		return $this->jsonResponse(__FUNCTION__, [
			'authUrl' => $authUrl
		]);
	}

	private function isSupportedEmail(string $email) : bool
	{
		if ((bool)$this->Config()->Get('plugin', 'allow_any_domain', false)) {
			return \str_contains($email, '@');
		}
		return \str_ends_with($email, '@hotmail.com')
			|| \str_ends_with($email, '@outlook.com')
			|| \str_ends_with($email, '@live.com');
	}

	/**
	 * Build absolute base URL (works behind nginx reverse proxy).
	 */
	private function baseUrl() : string
	{
		$scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
			? $_SERVER['HTTP_X_FORWARDED_PROTO']
			: ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');

		$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
		if (!$host) {
			throw new \RuntimeException('Cannot determine HTTP_HOST');
		}
		return $scheme . '://' . $host;
	}

	/**
	 * Redirect URI used for the Azure app registration.
	 * When plugin.personal=true -> "/?LoginO365"
	 * When plugin.personal=false -> "/LoginO365"
	 */
	private function redirectUri() : string
	{
		$base = \rtrim($this->baseUrl(), '/');
		$useQuery = (bool)$this->Config()->Get('plugin', 'personal', false);
		return $useQuery ? ($base . '/?LoginO365') : ($base . '/LoginO365');
	}

}
