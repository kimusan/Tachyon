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
			if ($sAdminKey && $this->Cacher(null, true)->Get(KeyPathHelper::SessionAdminKey($sAdminKey))) {
				return true;
			}
		}

		if ($bThrowExceptionOnFalse) {
			throw new ClientException(Notifications::AuthError);
		}

		return false;
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
