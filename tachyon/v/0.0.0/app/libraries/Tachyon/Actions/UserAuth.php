<?php

namespace Tachyon\Actions;

use Tachyon\Enumerations\Capa;
use Tachyon\Notifications;
use Tachyon\Utils;
use Tachyon\Model\Account;
use Tachyon\Model\MainAccount;
use Tachyon\Model\AdditionalAccount;
use Tachyon\Providers\Storage\Enumerations\StorageType;
use Tachyon\Exceptions\ClientException;
use Tachyon\Util\Cookies;
use Tachyon\Util\SensitiveString;

trait UserAuth
{
	/**
	 * @var bool | null | Account
	 */
	private $oAdditionalAuthAccount = false;
	private $oMainAuthAccount = false;

	public function DoResealCryptKey() : array
	{
		return $this->DefaultResponse(
			$this->getMainAccountFromToken()->resealCryptKey(
				new SensitiveString($this->GetActionParam('passphrase', ''))
			)
		);
	}

	/**
	 * @throws \Tachyon\Exceptions\ClientException
	 */
	protected function resolveLoginCredentials(string $sEmail, SensitiveString $oPassword): array
	{
		$sEmail = \Tachyon\Util\IDN::emailToAscii(\MailSo\Base\Utils::Trim($sEmail));

		$sNewEmail = $sEmail;
		$this->Plugins()->RunHook('login.credentials.step-1', array(&$sNewEmail));
		if ($sNewEmail) {
			$sEmail = $sNewEmail;
		}

		$oDomain = null;
		$oDomainProvider = $this->DomainProvider();

		// When email address is missing the domain, try to add it
		if (!\str_contains($sEmail, '@')) {
			$this->logWrite("The email address '{$sEmail}' is incomplete", \LOG_INFO, 'LOGIN');
			if ($this->Config()->Get('login', 'determine_user_domain', false)) {
				$sUserHost = \Tachyon\Util\IDN::toAscii($this->Http()->GetHost(false, true));
				$this->logWrite("Determined user domain: {$sUserHost}", \LOG_INFO, 'LOGIN');

				// Determine without wildcard
				$aDomainParts = \explode('.', $sUserHost);
				$iLimit = \min(\count($aDomainParts), 14);
				while (0 < $iLimit--) {
					$sDomain = \implode('.', $aDomainParts);
					$oDomain = $oDomainProvider->Load($sDomain, false);
					if ($oDomain) {
						$sEmail .= '@' . $sDomain;
						$this->logWrite("Check '{$sDomain}': OK", \LOG_INFO, 'LOGIN');
						break;
					} else {
						$this->logWrite("Check '{$sDomain}': NO", \LOG_INFO, 'LOGIN');
					}
					\array_shift($aDomainParts);
				}

				// Else determine with wildcard
				if (!$oDomain) {
					$oDomain = $oDomainProvider->Load($sUserHost, true);
					if ($oDomain) {
						$sEmail .= '@' . $sUserHost;
						$this->logWrite("Check '{$sUserHost}' with wildcard: OK", \LOG_INFO, 'LOGIN');
					} else {
						$this->logWrite("Check '{$sUserHost}' with wildcard: NO", \LOG_INFO, 'LOGIN');
					}
				}

				if (!$oDomain) {
					$this->logWrite("Domain '{$sUserHost}' was not determined!", \LOG_INFO, 'LOGIN');
				}
			}

			// Else try default domain
			if (!$oDomain) {
				$sDefDomain = \trim($this->Config()->Get('login', 'default_domain', ''));
				if (\strlen($sDefDomain)) {
					if ('HTTP_HOST' === $sDefDomain || 'SERVER_NAME' === $sDefDomain) {
						$sDefDomain = \preg_replace('/:[0-9]+$/D', '', $_SERVER[$sDefDomain]);
					} else if ('gethostname' === $sDefDomain) {
						$sDefDomain = \gethostname();
					}
					$sEmail .= '@' . $sDefDomain;
					$this->logWrite("Default domain '{$sDefDomain}' will be used.", \LOG_INFO, 'LOGIN');
				} else {
					$this->logWrite('Default domain not configured.', \LOG_INFO, 'LOGIN');
				}
			}
		}

		$sNewEmail = $sEmail;
		$sPassword = $oPassword->getValue();
		$this->Plugins()->RunHook('login.credentials.step-2', array(&$sNewEmail, &$sPassword));
		$this->logMask($sPassword);
		if ($sNewEmail) {
			$sEmail = $sNewEmail;
		}

		$sImapUser = $sEmail;
		$sSmtpUser = $sEmail;
		if (\str_contains($sEmail, '@')
		 && ($oDomain || ($oDomain = $oDomainProvider->Load(\MailSo\Base\Utils::getEmailAddressDomain($sEmail), true)))
		) {
			$sEmail = $oDomain->ImapSettings()->fixUsername($sEmail, false);
			$sImapUser = $oDomain->ImapSettings()->fixUsername($sImapUser);
			$sSmtpUser = $oDomain->SmtpSettings()->fixUsername($sSmtpUser);
		}

		$sNewEmail = $sEmail;
		$sNewImapUser = $sImapUser;
		$sNewSmtpUser = $sSmtpUser;
		$this->Plugins()->RunHook('login.credentials', array(&$sNewEmail, &$sNewImapUser, &$sPassword, &$sNewSmtpUser));

		$oPassword->setValue($sPassword);

		return [
			'email' => $sNewEmail ?: $sEmail,
			'domain' => $oDomain,
			'imapUser' => $sNewImapUser ?: $sImapUser,
			'smtpUser' => $sNewSmtpUser ?: $sSmtpUser,
			'pass' => $oPassword
		];
	}

	/**
	 * @throws \Tachyon\Exceptions\ClientException
	 */
	public function LoginProcess(string $sEmail, SensitiveString $oPassword, bool $bMainAccount = true): Account
	{
		$aCredentials = $this->resolveLoginCredentials($sEmail, $oPassword);

		if (!\str_contains($aCredentials['email'], '@') || !\strlen($oPassword)) {
			throw new ClientException(Notifications::InvalidInputArgument);
		}

		$oDomain = $this->DomainProvider()->getByEmailAddress($aCredentials['email']);

		// Keep only an SMTP user a plugin mapped. The one the domain's settings give is
		// worked out again on every send, so a change to "Use short login" reaches an
		// open session instead of waiting for the next login (#194).
		if ($aCredentials['domain']
		 && $aCredentials['smtpUser'] === $aCredentials['domain']->SmtpSettings()->fixUsername($aCredentials['email'])
		) {
			$aCredentials['smtpUser'] = '';
		}

		$oAccount = null;
		try {
			$oAccount = $bMainAccount ? new MainAccount : new AdditionalAccount;
			$oAccount->setCredentials(
				$aCredentials['domain'],
				$aCredentials['email'],
				$aCredentials['imapUser'],
				$oPassword,
				$aCredentials['smtpUser']
//				,new SensitiveString($oPassword)
			);
			$this->Plugins()->RunHook('filter.account', array($oAccount));
			if (!$oAccount) {
				throw new ClientException(Notifications::AccountFilterError);
			}
		} catch (\Throwable $oException) {
			$this->LoggerAuthHelper($oAccount, $sEmail);
			throw $oException;
		}

		$this->imapConnect($oAccount, true);

		// Notice, so it lands one level below the default and can be had without
		// the per-request info noise. There was no successful-login line at all
		// before, which left level 5 showing nothing and level 6 showing
		// everything (#163).
		$this->logWrite($this->compileLogParams(
			'Auth OK: ip={request:ip} user={imap:login}', $oAccount
		), \LOG_NOTICE, 'LOGIN');

		if ($bMainAccount) {
			$this->StorageProvider()->Put($oAccount, StorageType::SESSION, Utils::GetSessionToken(), (string) \time());

			// Must be here due to bug #1241
			$this->SetMainAuthAccount($oAccount);
			$this->Plugins()->RunHook('login.success', array($oAccount));

			$this->SetAuthToken($oAccount);
			$this->SetAdditionalAuthToken(null);
		}

		return $oAccount;
	}

	public function switchAccount(string $sEmail) : bool
	{
		$this->Http()->ServerNoCache();
		$oMainAccount = $this->getMainAccountFromToken(false);
		if ($sEmail && $oMainAccount && $this->GetCapa(Capa::ADDITIONAL_ACCOUNTS)) {
			$oAccount = null;
			if ($oMainAccount->Email() !== $sEmail) {
				$sEmail = \Tachyon\Util\IDN::emailToAscii($sEmail);
				$aAccounts = $this->GetAccounts($oMainAccount);
				if (!isset($aAccounts[$sEmail])) {
					throw new ClientException(Notifications::AccountDoesNotExist);
				}
				try {
					$oAccount = AdditionalAccount::NewInstanceFromTokenArray(
						$this, $aAccounts[$sEmail], true
					);
				} catch (\Throwable $e) {
					throw new ClientException(Notifications::AccountSwitchFailed, $e);
				}
				if (!$oAccount) {
					throw new ClientException(Notifications::AccountSwitchFailed);
				}

				// Test the login
				$oImapClient = new \MailSo\Imap\ImapClient;
				$oImapClient->SetLogger($this->Logger());
				$this->imapConnect($oAccount, false, $oImapClient);
			}
			$this->SetAdditionalAuthToken($oAccount);
			return true;
		}
		return false;
	}

	/**
	 * Returns Tachyon\Model\AdditionalAccount when it exists,
	 * else returns Tachyon\Model\MainAccount when it exists,
	 * else null
	 *
	 * @throws \Tachyon\Exceptions\ClientException
	 */
	public function getAccountFromToken(bool $bThrowExceptionOnFalse = true): ?Account
	{
		$this->getMainAccountFromToken($bThrowExceptionOnFalse);

		if (false === $this->oAdditionalAuthAccount && isset($_COOKIE[self::AUTH_ADDITIONAL_TOKEN_KEY])) {
			$aData = Cookies::getSecure(self::AUTH_ADDITIONAL_TOKEN_KEY);
			if ($aData) {
				$this->oAdditionalAuthAccount = AdditionalAccount::NewInstanceFromTokenArray(
					$this,
					$aData,
					$bThrowExceptionOnFalse
				);
			}
			if (!$this->oAdditionalAuthAccount) {
				$this->oAdditionalAuthAccount = null;
				Cookies::clear(self::AUTH_ADDITIONAL_TOKEN_KEY);
			}
		}

		return $this->oAdditionalAuthAccount ?: $this->oMainAuthAccount;
	}

	/**
	 * @throws \Tachyon\Exceptions\ClientException
	 */
	public function getMainAccountFromToken(bool $bThrowExceptionOnFalse = true): ?MainAccount
	{
		if (false === $this->oMainAuthAccount) try {
			$this->oMainAuthAccount = null;

			$aData = Cookies::getSecure(self::AUTH_SPEC_TOKEN_KEY);
			if ($aData) {
				/**
				 * Server side control/kickout of logged in sessions
				 * https://github.com/the-djmaze/snappymail/issues/151
				 */
				$sToken = Utils::GetSessionToken(false);
				if (!$sToken) {
//					\MailSo\Base\Http::StatusHeader(401);
					if (isset($_COOKIE[Utils::SESSION_TOKEN])) {
						\Tachyon\Util\Log::notice('TOKENS', 'SESSION_TOKEN invalid');
					} else {
						\Tachyon\Util\Log::notice('TOKENS', 'SESSION_TOKEN not set');
					}
				} else {
					$oMainAuthAccount = MainAccount::NewInstanceFromTokenArray(
						$this,
						$aData,
						$bThrowExceptionOnFalse
					);
					if ($oMainAuthAccount) {
						$sTokenValue = $this->StorageProvider()->Get($oMainAuthAccount, StorageType::SESSION, $sToken);
						// AutoLogout used to exist only as a timer in the browser, which closing
						// the tab stops, so the session lived on for the 3 hours GC() allows
						// (#185). The value is the time of the last request, written at most once
						// a minute; 'true' is a session from before this and starts counting now.
						$iIdle = \is_numeric($sTokenValue) ? \time() - (int) $sTokenValue : 60;
						if ($sTokenValue && 60 <= $iIdle) {
							$iAutoLogout = isset($_COOKIE[self::AUTH_SIGN_ME_TOKEN_KEY]) ? 0
								: (int) $this->SettingsProvider()->Load($oMainAuthAccount)->GetConf('AutoLogout',
									$this->Config()->Get('defaults', 'autologout', 30));
							// Three minutes of grace: the stored time trails the last request by up
							// to a minute, and the client's keepalive trails input by another.
							if ($iAutoLogout && $iIdle > 60 * ($iAutoLogout + 3)) {
								\Tachyon\Util\Log::notice('TOKENS', "SESSION_TOKEN idle {$iIdle}s, AutoLogout {$iAutoLogout}m");
								$sTokenValue = false;
							} else {
								$this->StorageProvider()->Put($oMainAuthAccount, StorageType::SESSION, $sToken, (string) \time());
							}
						}
						if ($sTokenValue) {
							$this->oMainAuthAccount = $oMainAuthAccount;
						} else {
							$this->StorageProvider()->Clear($oMainAuthAccount, StorageType::SESSION, $sToken);
							\Tachyon\Util\Log::notice('TOKENS', 'SESSION_TOKEN value invalid: ' . \get_debug_type($sTokenValue));
						}
					} else {
						\Tachyon\Util\Log::notice('TOKENS', 'AUTH_SPEC_TOKEN_KEY invalid');
					}
				}
				if (!$this->oMainAuthAccount) {
					Cookies::clear(Utils::SESSION_TOKEN);
//					\MailSo\Base\Http::StatusHeader(401);
					$this->Logout(true);
//					$sAdditionalMessage = $this->StaticI18N('SESSION_GONE');
					throw new ClientException(Notifications::InvalidToken, null, 'Session gone');
				}
			} else {
				$oAccount = $this->GetAccountFromSignMeToken();
				if ($oAccount) {
					$this->StorageProvider()->Put(
						$oAccount,
						StorageType::SESSION,
						Utils::GetSessionToken(),
						(string) \time()
					);
					$this->SetAuthToken($oAccount);
				}
			}

			if (!$this->oMainAuthAccount) {
				throw new ClientException(Notifications::InvalidToken, null, 'Account undefined');
			}
		} catch (\Throwable $e) {
			if ($bThrowExceptionOnFalse) {
				throw $e;
			}
		}

		return $this->oMainAuthAccount;
	}

	public function SetMainAuthAccount(MainAccount $oAccount): void
	{
		$this->oAdditionalAuthAccount = false;
		$this->oMainAuthAccount = $oAccount;
	}

	public function SetAuthToken(MainAccount $oAccount): void
	{
		$this->SetMainAuthAccount($oAccount);
		Cookies::setSecure(self::AUTH_SPEC_TOKEN_KEY, $oAccount);
	}

	public function SetAdditionalAuthToken(?AdditionalAccount $oAccount): void
	{
		$this->oAdditionalAuthAccount = $oAccount ?: false;
		Cookies::setSecure(self::AUTH_ADDITIONAL_TOKEN_KEY, $oAccount);
	}

	/**
	 * SignMe methods used for the "remember me" cookie
	 */

	private static function GetSignMeToken(): ?array
	{
		$sSignMeToken = Cookies::get(self::AUTH_SIGN_ME_TOKEN_KEY);
		if ($sSignMeToken) {
			\Tachyon\Util\Log::notice(self::AUTH_SIGN_ME_TOKEN_KEY, 'decrypt');
			$aResult = \Tachyon\Util\Crypt::DecryptUrlSafe($sSignMeToken, 'signme');
			if (isset($aResult['e'], $aResult['u']) && \Tachyon\Util\UUID::isValid($aResult['u'])) {
				if (!isset($aResult['c'])) {
					$aResult['c'] = \array_key_last($aResult);
					$aResult['d'] = \end($aResult);
				}
				return $aResult;
			}
			\Tachyon\Util\Log::notice(self::AUTH_SIGN_ME_TOKEN_KEY, 'invalid');
			Cookies::clear(self::AUTH_SIGN_ME_TOKEN_KEY);
		}
		return null;
	}

	public function SetSignMeToken(MainAccount $oAccount): void
	{
		$this->ClearSignMeData();
		$uuid = \Tachyon\Util\UUID::generate();
		$data = \Tachyon\Util\Crypt::Encrypt($oAccount, 'signme');
		Cookies::set(
			self::AUTH_SIGN_ME_TOKEN_KEY,
			\Tachyon\Util\Crypt::EncryptUrlSafe([
				'e' => $oAccount->Email(),
				'u' => $uuid,
				'c' => $data[0],
				'd' => \base64_encode($data[1])
			], 'signme'),
			\time() + 3600 * 24 * 30 // 30 days
		);
		$this->StorageProvider()->Put($oAccount, StorageType::SIGN_ME, $uuid, $data[2]);
	}

	public function GetAccountFromSignMeToken(): ?MainAccount
	{
		$aTokenData = static::GetSignMeToken();
		if ($aTokenData) {
			try
			{
				$sAuthToken = $this->StorageProvider()->Get(
					$aTokenData['e'],
					StorageType::SIGN_ME,
					$aTokenData['u']
				);
				if (!$sAuthToken) {
					throw new \RuntimeException("server token not found for {$aTokenData['e']}/.sign_me/{$aTokenData['u']}");
				}
				$aAccountHash = \Tachyon\Util\Crypt::Decrypt([
					$aTokenData['c'],
					\base64_decode($aTokenData['d']),
					$sAuthToken
				], 'signme');
				if (!\is_array($aAccountHash)) {
					throw new \RuntimeException('token decrypt failed');
				}
				$oAccount = MainAccount::NewInstanceFromTokenArray($this, $aAccountHash);
				if (!$oAccount) {
					throw new \RuntimeException('token has no account');
				}
				$this->imapConnect($oAccount);
				// Update lifetime
				$this->SetSignMeToken($oAccount);
				return $oAccount;
			}
			catch (\Throwable $oException)
			{
				\Tachyon\Util\Log::warning(self::AUTH_SIGN_ME_TOKEN_KEY, $oException->getMessage());
				$this->ClearSignMeData();
			}
		}
		return null;
	}

	protected function ClearSignMeData() : void
	{
		$aTokenData = static::GetSignMeToken();
		if ($aTokenData) {
			$this->StorageProvider()->Clear($aTokenData['e'], StorageType::SIGN_ME, $aTokenData['u']);
		}
		Cookies::clear(self::AUTH_SIGN_ME_TOKEN_KEY);
	}

	/**
	 * Logout methods
	 */

	public function Logout(bool $bMain) : void
	{
		if ($bMain) {
			// Telling the browser to drop its cookies is not the session ending.
			// The record getMainAccountFromToken() checks lives on the server, and
			// FileStorage::Get() touches it on every read, so a copied cookie never
			// ages out of the three hour sweep for as long as somebody keeps using
			// it: logging out left a stolen session working. Reported by Fathi Ben
			// Nasr (GHSA-gc87-g2gf-xj6q).
			//
			// Resolved before the cookies go, since that is what the lookup reads.
			// Reached re-entrantly from getMainAccountFromToken() itself, where the
			// account is null and there is no session to destroy, which the guard
			// below covers.
			$sSessionToken = Utils::GetSessionToken(false);
			$oMainAccount = $this->getMainAccountFromToken(false);
			if ($sSessionToken && $oMainAccount) {
				$this->StorageProvider()->Clear($oMainAccount, StorageType::SESSION, $sSessionToken);
			}
			Cookies::clear(Utils::SESSION_TOKEN);
		}
		Cookies::clear(self::AUTH_ADDITIONAL_TOKEN_KEY);
		$bMain && Cookies::clear(self::AUTH_SPEC_TOKEN_KEY);
		// Only for the main account: logging out of an additional one must leave
		// the session it is attached to alone.
	}

	/**
	 * @throws \Tachyon\Exceptions\ClientException
	 */
	protected function imapConnect(Account $oAccount, bool $bAuthLog = false, ?\MailSo\Imap\ImapClient $oImapClient = null): void
	{
		try {
			if (!$oImapClient) {
				$oImapClient = $this->ImapClient();
			}
			$oAccount->ImapConnectAndLogin($this->Plugins(), $oImapClient, $this->Config());
		} catch (ClientException $oException) {
			throw $oException;
		} catch (\MailSo\Net\Exceptions\ConnectionException $oException) {
			throw new ClientException(Notifications::ConnectionError, $oException);
		} catch (\MailSo\Imap\Exceptions\LoginBadCredentialsException $oException) {
			if ($bAuthLog) {
				$this->LoggerAuthHelper($oAccount);
			}

			if ($this->Config()->Get('imap', 'show_login_alert', true)) {
				throw new ClientException(Notifications::AuthError, $oException, $oException->getAlertFromStatus());
			} else {
				throw new ClientException(Notifications::AuthError, $oException);
			}
		} catch (\Throwable $oException) {
			throw new ClientException(Notifications::AuthError, $oException);
		}
	}

}
