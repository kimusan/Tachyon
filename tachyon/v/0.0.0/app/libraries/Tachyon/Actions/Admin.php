<?php

namespace Tachyon\Actions;

use Tachyon\Exceptions\ClientException;
use Tachyon\KeyPathHelper;
use Tachyon\Notifications;
use Tachyon\Utils;

trait Admin
{
	protected static string $AUTH_ADMIN_TOKEN_KEY = 'smadmin';

	public function IsAdminLoggined(bool $bThrowExceptionOnFalse = true) : bool
	{
		if ($this->Config()->Get('security', 'allow_admin_panel', true)) {
			$sAdminKey = $this->getAdminAuthKey();
			if ($sAdminKey && $this->adminSessionValid($sAdminKey)) {
				return true;
			}
		}

		if ($bThrowExceptionOnFalse) {
			throw new ClientException(Notifications::AuthError);
		}

		return false;
	}

	/**
	 * Starts an admin session record and returns its key. The record holds when
	 * the session started, when it was last used, and a fingerprint of the
	 * admin credentials, so a changed password or two factor secret ends every
	 * session made with the old ones.
	 */
	protected function newAdminSession() : string
	{
		$sRand = \MailSo\Base\Utils::Sha1Rand();
		$iNow = \time();
		if (!$this->Cacher(null, true)->Set(KeyPathHelper::SessionAdminKey($sRand),
			\json_encode(['start' => $iNow, 'last' => $iNow, 'fp' => $this->adminFingerprint()]))
		) {
			throw new \RuntimeException('Failed to store admin token');
		}
		return $sRand;
	}

	/**
	 * Whether the admin session record is still good: not idle for longer than
	 * security.admin_session_idle minutes, not older than
	 * security.admin_session_lifetime minutes (0 turns either off), and made
	 * with the current admin credentials. A record that fails is removed; one
	 * in use has its time of last use refreshed, at most once a minute.
	 */
	protected function adminSessionValid(string $sAdminKey) : bool
	{
		$oCacher = $this->Cacher(null, true);
		$sKey = KeyPathHelper::SessionAdminKey($sAdminKey);
		$mValue = $oCacher->Get($sKey);
		$aRecord = \is_string($mValue) ? \json_decode($mValue, true) : null;
		$iNow = \time();
		$iIdle = 60 * (int) $this->Config()->Get('security', 'admin_session_idle', 30);
		$iLifetime = 60 * (int) $this->Config()->Get('security', 'admin_session_lifetime', 720);
		$bValid = \is_array($aRecord)
			&& isset($aRecord['start'], $aRecord['last'], $aRecord['fp'])
			&& \is_int($aRecord['start']) && \is_int($aRecord['last']) && \is_string($aRecord['fp'])
			&& (!$iIdle || $iNow - $aRecord['last'] <= $iIdle)
			&& (!$iLifetime || $iNow - $aRecord['start'] <= $iLifetime)
			&& \hash_equals($this->adminFingerprint(), $aRecord['fp']);
		if (!$bValid) {
			$mValue && $oCacher->Delete($sKey);
			return false;
		}
		if (60 <= $iNow - $aRecord['last']) {
			$aRecord['last'] = $iNow;
			$oCacher->Set($sKey, \json_encode($aRecord));
		}
		return true;
	}

	private function adminFingerprint() : string
	{
		$oConfig = $this->Config();
		return \hash_hmac('sha256', \implode("\0", [
			$oConfig->Get('security', 'admin_login', ''),
			$oConfig->Get('security', 'admin_password', ''),
			$oConfig->Get('security', 'admin_totp', '')
		]), APP_SALT);
	}

	protected function getAdminAuthKey() : string
	{
		$cookie = \Tachyon\Util\Cookies::get(static::$AUTH_ADMIN_TOKEN_KEY);
		if ($cookie) {
			$aAdminHash = Utils::DecodeKeyValuesQ($cookie);
			if (!empty($aAdminHash[1]) && 'token' === $aAdminHash[0]) {
				return $aAdminHash[1];
			}
			\Tachyon\Util\Cookies::clear(static::$AUTH_ADMIN_TOKEN_KEY);
		}
		return '';
	}
}
