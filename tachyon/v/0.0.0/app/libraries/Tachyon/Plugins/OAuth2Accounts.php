<?php

namespace Tachyon\Plugins;

use Tachyon\Model\Account;
use Tachyon\Model\AdditionalAccount;
use Tachyon\Model\MainAccount;
use Tachyon\Providers\Storage\Enumerations\StorageType;

/**
 * Shared machinery for OAuth2 login plugins that support additional accounts.
 *
 * The provider redirect back to us is a cross-site top-level navigation, so a
 * SameSite=Strict cookie (the default) is withheld. That leaves the callback
 * unable to say who is logged in, and unable to reach the main account's
 * CryptKey to seal anything. It could not create the account regardless:
 * LoginProcess() performs a real IMAP login, whose before-login hook resolves
 * the main account by cookie to find the token it needs.
 *
 * So the work is split in three, and only the middle step is cross-site:
 *
 *   mint    authenticated XHR. Stores what the flow needs server side under a
 *           random nonce and hands back a signed state carrying only the nonce.
 *   callback  cross-site, no cookies. Verifies the state, reads its own record,
 *           swaps the code for tokens, and for an add leaves them in a
 *           single-use pickup record.
 *   claim   authenticated XHR. Seals the tokens with the main account's
 *           CryptKey and creates the additional account.
 *
 * Each server-side record is encrypted with the secret that names it, so the
 * file on disk is useless to anyone who cannot already present that secret.
 */
trait OAuth2Accounts
{
	/**
	 * Distinguishes one plugin's storage keys from another's, e.g. 'login-gmail'.
	 */
	abstract protected function oauthPrefix() : string;

	abstract protected function oauthTokenUri() : string;

	abstract protected function oauthClient() : ?\OAuth2\Client;

	/**
	 * In-request cache of decrypted bundles, keyed by lowercase email. A trait's
	 * static property is per-using-class, so plugins do not share this.
	 */
	private static array $oauthTokens = [];

	private const OAUTH_TTL = 900;

	public function oauthSeedToken(string $sEmail, array $aTokens) : void
	{
		static::$oauthTokens[\strtolower($sEmail)] = $aTokens;
	}

	/**
	 * Apply OAuth credentials to an imap/smtp/sieve connection, refreshing first
	 * when the access token is spent. Silent when this account has no tokens:
	 * the account may legitimately be using a password.
	 */
	protected function oauthApplySettings(Account $oAccount, \MailSo\Net\ConnectSettings $oSettings) : void
	{
		$aData = $this->oauthTokensFor($oAccount);
		if (empty($aData['access_token'])) {
			return;
		}

		if (!empty($aData['refresh_token']) && \time() >= ((int) ($aData['expires'] ?? 0) - 30)) {
			$aFresh = $this->oauthRefresh($aData['refresh_token']);
			if ($aFresh) {
				$aData = \array_merge($aData, $aFresh);
				$this->oauthSaveTokensFor($oAccount, $aData);
			}
		}

		$this->oauthSeedToken($oAccount->Email(), $aData);
		$oSettings->passphrase = $aData['access_token'];
		\array_unshift($oSettings->SASLMechanisms, 'OAUTHBEARER', 'XOAUTH2');
	}

	protected function oauthRefresh(string $sRefreshToken) : ?array
	{
		$oClient = $this->oauthClient();
		if (!$oClient) {
			return null;
		}
		$aWrap = $oClient->getAccessToken($this->oauthTokenUri(), 'refresh_token', [
			'refresh_token' => $sRefreshToken
		]);
		$aResult = \is_array($aWrap) ? ($aWrap['result'] ?? []) : [];
		if (empty($aResult['access_token'])) {
			return null;
		}
		$aData = ['access_token' => $aResult['access_token']];
		// Providers may rotate the refresh token; keep the new one when offered.
		if (!empty($aResult['refresh_token'])) {
			$aData['refresh_token'] = $aResult['refresh_token'];
		}
		$iExpiresIn = (int) ($aResult['expires_in'] ?? 0);
		if (0 < $iExpiresIn) {
			$aData['expires_in'] = $iExpiresIn;
			$aData['expires'] = \time() + $iExpiresIn;
		}
		return $aData;
	}

	/**
	 * The main account keeps its bundle in session storage; an additional account
	 * keeps one per address under the main account, since that is where the
	 * CryptKey to open it comes from.
	 *
	 * The main account's key is the same one LoginProcess writes 'true' to as its
	 * session marker, so the bundle replaces that value. This is fine, and is what
	 * the plugins have always done: the check only tests that something truthy is
	 * there (Actions/UserAuth.php:279), never what it says.
	 */
	protected function oauthTokensFor(Account $oAccount) : ?array
	{
		$sEmail = \strtolower($oAccount->Email());
		if (isset(static::$oauthTokens[$sEmail])) {
			return static::$oauthTokens[$sEmail];
		}

		$oActions = \Tachyon\Api::Actions();
		try {
			if ($oAccount instanceof MainAccount) {
				$sBlob = $oActions->StorageProvider()->Get($oAccount, StorageType::SESSION,
					\Tachyon\Utils::GetSessionToken());
				return $sBlob ? \Tachyon\Util\Crypt::DecryptFromJSON($sBlob, $oAccount->CryptKey()) : null;
			}
			if ($oAccount instanceof AdditionalAccount) {
				$oMain = $oActions->getMainAccountFromToken(false);
				if (!$oMain) {
					return null;
				}
				$sBlob = $oActions->StorageProvider()->Get($oMain, StorageType::CONFIG,
					$this->oauthTokenKey($sEmail));
				return $sBlob ? \Tachyon\Util\Crypt::DecryptFromJSON($sBlob, $oMain->CryptKey()) : null;
			}
		} catch (\Throwable $oException) {
			return null;
		}
		return null;
	}

	protected function oauthSaveTokensFor(Account $oAccount, array $aTokens) : void
	{
		$oActions = \Tachyon\Api::Actions();
		if ($oAccount instanceof MainAccount) {
			$oActions->StorageProvider()->Put($oAccount, StorageType::SESSION,
				\Tachyon\Utils::GetSessionToken(),
				\Tachyon\Util\Crypt::EncryptToJSON($aTokens, $oAccount->CryptKey()));
		} else if ($oAccount instanceof AdditionalAccount) {
			$oMain = $oActions->getMainAccountFromToken(false);
			if ($oMain) {
				$this->oauthStoreTokens($oMain, $oAccount->Email(), $aTokens);
			}
		}
	}

	protected function oauthStoreTokens(MainAccount $oMain, string $sEmail, array $aTokens) : void
	{
		\Tachyon\Api::Actions()->StorageProvider()->Put($oMain, StorageType::CONFIG,
			$this->oauthTokenKey($this->oauthNormalise($sEmail)),
			\Tachyon\Util\Crypt::EncryptToJSON($aTokens, $oMain->CryptKey()));
	}

	/**
	 * Hook 'main.cryptkey-rotate' (register it in Init()): the tokens are
	 * encrypted with the main account's CryptKey, which MainAccount replaces
	 * when it was derived from the password; re-encrypt them under the new one.
	 * Those of this session's main account are in its session record; other
	 * sessions' tokens are not reached and are fetched anew when they fail.
	 */
	public function oauthRotateCryptKey(MainAccount $oMain, string $sOld, string $sNew) : void
	{
		$oActions = \Tachyon\Api::Actions();
		foreach (\array_keys($oActions->GetAccounts($oMain)) as $sEmail) {
			$this->oauthReencrypt($oMain, StorageType::CONFIG,
				$this->oauthTokenKey($this->oauthNormalise((string) $sEmail)), $sOld, $sNew);
		}
		$sSession = \Tachyon\Utils::GetSessionToken(false);
		$sSession && $this->oauthReencrypt($oMain, StorageType::SESSION, $sSession, $sOld, $sNew);
	}

	private function oauthReencrypt(MainAccount $oMain, int $iType, string $sKey, string $sOld, string $sNew) : void
	{
		$oStorage = \Tachyon\Api::Actions()->StorageProvider();
		$sBlob = $oStorage->Get($oMain, $iType, $sKey);
		if (!$sBlob || null !== \Tachyon\Util\Crypt::DecryptFromJSON($sBlob, $sNew)) {
			return;
		}
		$sBlob = \Tachyon\Model\CryptKeyRotation::reencryptJSON($sBlob, $sOld, $sNew);
		if (null !== $sBlob && !$oStorage->Put($oMain, $iType, $sKey, $sBlob)) {
			throw new \RuntimeException('OAuth2 tokens: could not write the re-encrypted tokens');
		}
	}

	protected function oauthClearTokens(MainAccount $oMain, string $sEmail) : void
	{
		\Tachyon\Api::Actions()->StorageProvider()->Clear($oMain, StorageType::CONFIG,
			$this->oauthTokenKey($this->oauthNormalise($sEmail)));
	}

	protected function oauthNormalise(string $sEmail) : string
	{
		return \strtolower(\Tachyon\Util\IDN::emailToAscii(\trim($sEmail)));
	}

	private function oauthTokenKey(string $sEmailLower) : string
	{
		// Hashed so the address never reaches a storage path.
		return $this->oauthPrefix() . '.tokens.' . \sha1($sEmailLower);
	}

	/**
	 * Record what the callback will need, and return the signed state to send
	 * with the authorize request. Only the nonce travels through the browser.
	 */
	protected function oauthMintState(array $aPayload) : string
	{
		$this->oauthPrune();

		$sNonce = $this->oauthB64url(\random_bytes(16));
		$aPayload['nonce'] = $sNonce;
		$aPayload['ts'] = \time();

		$this->oauthPutRecord('pending.' . $sNonce, $sNonce, $aPayload);

		return $this->oauthSign(['v' => 1, 'nonce' => $sNonce, 'ts' => $aPayload['ts']]);
	}

	/**
	 * Verify the signature, then read and consume the matching record. Null on
	 * any failure, so callers can treat it as one decision.
	 */
	protected function oauthConsumeState(string $sState) : ?array
	{
		$aOuter = $this->oauthVerify($sState);
		if (!$aOuter || empty($aOuter['nonce'])) {
			return null;
		}
		return $this->oauthTakeRecord('pending.' . $aOuter['nonce'], (string) $aOuter['nonce']);
	}

	/**
	 * Park tokens for an authenticated request to collect. The secret names the
	 * record and is also the key it is encrypted with.
	 */
	protected function oauthPutPickup(array $aPayload) : string
	{
		$sSecret = $this->oauthB64url(\random_bytes(32));
		$aPayload['ts'] = \time();
		$this->oauthPutRecord('pickup.' . \sha1($sSecret), $sSecret, $aPayload);
		return $sSecret;
	}

	protected function oauthTakePickup(string $sSecret) : ?array
	{
		return $this->oauthTakeRecord('pickup.' . \sha1($sSecret), $sSecret);
	}

	/**
	 * The privileged half of an add, run where the session cookie is present.
	 *
	 * The tokens must be stored before LoginProcess, because that performs a
	 * real IMAP login and the before-login hook has to find them already there.
	 */
	protected function oauthAddAccount(MainAccount $oMain, string $sEmail, array $aTokens,
		string $sIdentity, string $sName = '') : AdditionalAccount
	{
		$oActions = \Tachyon\Api::Actions();
		$sEmail = $this->oauthNormalise($sEmail);

		$aAccounts = $oActions->GetAccounts($oMain);
		$this->oauthRefuseDuplicate($oMain, $aAccounts, $sEmail);

		$this->oauthStoreTokens($oMain, $sEmail, $aTokens);
		$this->oauthSeedToken($sEmail, $aTokens);

		$oAccount = null;
		try {
			// A stable opaque id from the provider stands in for the password, and
			// never authenticates anything: before-login swaps in the token. An
			// access token would be the wrong thing to keep here, being both a
			// live credential and one that expires within the hour.
			$oAccount = $oActions->LoginProcess($sEmail,
				new \Tachyon\Util\SensitiveString($sIdentity), false);
			if (!$oAccount instanceof AdditionalAccount) {
				throw new \RuntimeException('Expected an additional account');
			}
		} catch (\Throwable $oException) {
			$this->oauthClearTokens($oMain, $sEmail);
			throw $oException;
		}

		// Login resolves the address, so key on what it settled on rather than
		// what was asked for, and re-check now that it is known.
		$sResolved = $this->oauthNormalise($oAccount->Email());
		if ($sResolved !== $sEmail) {
			$this->oauthRefuseDuplicate($oMain, $aAccounts, $sResolved);
			$this->oauthClearTokens($oMain, $sEmail);
			$this->oauthStoreTokens($oMain, $sResolved, $aTokens);
		}

		$aEntry = $oAccount->asTokenArray($oMain);
		if (\strlen($sName)) {
			$aEntry['name'] = $sName;
		}
		$aAccounts[$sResolved] = $aEntry;
		$oActions->SetAccounts($oMain, $aAccounts);

		return $oAccount;
	}

	private function oauthRefuseDuplicate(MainAccount $oMain, array $aAccounts, string $sEmail) : void
	{
		if ($this->oauthNormalise($oMain->Email()) === $sEmail || isset($aAccounts[$sEmail])) {
			// DoAccountSetup guards this and we do not go through it, so an
			// unchecked add would overwrite the entry and orphan its tokens.
			throw new \Tachyon\Exceptions\ClientException(\Tachyon\Notifications::AccountAlreadyExists);
		}
	}

	private function oauthPutRecord(string $sName, string $sSecret, array $aPayload) : void
	{
		$oActions = \Tachyon\Api::Actions();
		$sKey = $this->oauthPrefix() . '.' . $sName;
		$oActions->StorageProvider()->Put(null, StorageType::NOBODY, $sKey,
			\Tachyon\Util\Crypt::EncryptToJSON($aPayload, $sSecret));

		$aIndex = $this->oauthIndex();
		$aIndex[$sName] = \time();
		$this->oauthSetIndex($aIndex);
	}

	private function oauthTakeRecord(string $sName, string $sSecret) : ?array
	{
		$oActions = \Tachyon\Api::Actions();
		$sKey = $this->oauthPrefix() . '.' . $sName;
		$sBlob = $oActions->StorageProvider()->Get(null, StorageType::NOBODY, $sKey);

		// Single use, so drop it whether or not it turns out to be usable.
		$oActions->StorageProvider()->Clear(null, StorageType::NOBODY, $sKey);
		$aIndex = $this->oauthIndex();
		unset($aIndex[$sName]);
		$this->oauthSetIndex($aIndex);

		if (!$sBlob) {
			return null;
		}
		try {
			$aPayload = \Tachyon\Util\Crypt::DecryptFromJSON($sBlob, $sSecret);
		} catch (\Throwable $oException) {
			return null;
		}
		if (!\is_array($aPayload)) {
			return null;
		}
		if (static::OAUTH_TTL < \abs(\time() - (int) ($aPayload['ts'] ?? 0))) {
			return null;
		}
		return $aPayload;
	}

	/**
	 * NOBODY filenames are a hash of the key, so records cannot be listed back.
	 * An index is the only way to find expired ones. Nothing else sweeps that
	 * directory: FileStorage::GC() covers .sign_me and .sessions only, and must
	 * not be pointed at __nobody__, which holds its own scheduling record.
	 */
	private function oauthPrune() : void
	{
		$oActions = \Tachyon\Api::Actions();
		$aIndex = $this->oauthIndex();
		$iCutoff = \time() - static::OAUTH_TTL;
		$bChanged = false;
		foreach ($aIndex as $sName => $iTime) {
			if ((int) $iTime < $iCutoff) {
				$oActions->StorageProvider()->Clear(null, StorageType::NOBODY,
					$this->oauthPrefix() . '.' . $sName);
				unset($aIndex[$sName]);
				$bChanged = true;
			}
		}
		$bChanged && $this->oauthSetIndex($aIndex);
	}

	private function oauthIndex() : array
	{
		$sBlob = \Tachyon\Api::Actions()->StorageProvider()->Get(null, StorageType::NOBODY,
			$this->oauthPrefix() . '.pending-index');
		$aIndex = $sBlob ? \json_decode($sBlob, true) : [];
		return \is_array($aIndex) ? $aIndex : [];
	}

	private function oauthSetIndex(array $aIndex) : void
	{
		// Last write wins. A lost race leaks one small record until the next
		// prune notices it, which is no worse than having no index at all.
		\Tachyon\Api::Actions()->StorageProvider()->Put(null, StorageType::NOBODY,
			$this->oauthPrefix() . '.pending-index', \json_encode($aIndex));
	}

	private function oauthHmacKey() : string
	{
		// Server-side only, so the state cannot be forged by a caller.
		return \trim($this->Config()->getDecrypted('plugin', 'client_secret', '')) ?: $this->oauthPrefix();
	}

	private function oauthSign(array $aPayload) : string
	{
		$sPayload = $this->oauthB64url(\json_encode($aPayload) ?: '{}');
		return $sPayload . '.' . $this->oauthB64url(
			\hash_hmac('sha256', $sPayload, $this->oauthHmacKey(), true));
	}

	private function oauthVerify(string $sState) : ?array
	{
		$aParts = \explode('.', $sState, 2);
		if (2 !== \count($aParts)) {
			return null;
		}
		$sSig = $this->oauthB64urlDecode($aParts[1]);
		if (false === $sSig) {
			return null;
		}
		$sExpect = \hash_hmac('sha256', $aParts[0], $this->oauthHmacKey(), true);
		if (!\hash_equals($sExpect, $sSig)) {
			return null;
		}
		$sJson = $this->oauthB64urlDecode($aParts[0]);
		if (false === $sJson) {
			return null;
		}
		$aPayload = \json_decode($sJson, true);
		return \is_array($aPayload) ? $aPayload : null;
	}

	private function oauthB64url(string $sBin) : string
	{
		return \rtrim(\strtr(\base64_encode($sBin), '+/', '-_'), '=');
	}

	private function oauthB64urlDecode(string $sValue) /*: string|false*/
	{
		$iPad = (4 - (\strlen($sValue) % 4)) % 4;
		return \base64_decode(\strtr($sValue . \str_repeat('=', $iPad), '-_', '+/'), true);
	}
}
