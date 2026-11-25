<?php

class LoginExternalSsoPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME     = 'Login External SSO',
		AUTHOR   = 'Tachyon',
		URL      = 'https://github.com/kimusan/Tachyon',
		VERSION  = '1.1',
		RELEASE  = '2026-11-24',
		REQUIRED = '2.21.0',
		CATEGORY = 'Login',
		LICENSE  = 'MIT',
		DESCRIPTION = 'Login with $_POST "Email", "Password" and "SsoKey". It returns an SSO hash to use with "?Sso&hash="';

	public function Init() : void
	{
		$this->addPartHook('ExternalSso', 'ServiceExternalSso');
	}

	public function ServiceExternalSso() : string
	{
		$oActions = \Tachyon\Api::Actions();
		$oActions->Http()->ServerNoCache();
		$sKey = $this->Config()->Get('plugin', 'key', '');
		$sEmail = isset($_POST['Email']) ? $_POST['Email'] : '';
		$sPassword = isset($_POST['Password']) ? $_POST['Password'] : '';
		if ($sEmail && $sPassword && static::keyMatches($sKey, $_POST['SsoKey'] ?? null)) {
			$sResult = \Tachyon\Api::CreateUserSsoHash($sEmail, $sPassword);
			if (isset($_POST['Output']) && 'json' === \strtolower($_POST['Output'])) {
				\header('Content-Type: application/json; charset=utf-8');
				echo \json_encode(array(
					'Action' => 'ExternalSso',
					'Result' => $sResult
				));
			} else {
				\header('Content-Type: text/plain');
				echo $sResult;
			}
		} else if (isset($_POST['SsoKey']) && !static::keyMatches($sKey, $_POST['SsoKey'])) {
			\Tachyon\Util\Log::warning('SSO', 'ExternalSso with a wrong key');
			static::faultDelay((int) \Tachyon\Api::Config()->Get('login', 'fault_delay', 0));
		}
		return true;
	}

	/**
	 * A wrong key waits like a failed login (login.fault_delay seconds from
	 * the start of the request), so the key can't be guessed at full speed.
	 */
	private static function faultDelay(int $iDelay) : void
	{
		$fSeconds = $iDelay - (\microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? \microtime(true)));
		if (0 < $fSeconds) {
			\usleep(\intval($fSeconds * 1000000));
		}
	}

	/**
	 * A constant-time, exact comparison: == between two numeric strings
	 * compares their numbers, so "1e3" matched a key of "1000".
	 */
	private static function keyMatches(string $sKey, $mGiven) : bool
	{
		return '' !== $sKey && \is_string($mGiven) && \hash_equals($sKey, $mGiven);
	}

	/**
	 * @return array
	 */
	public function configMapping() : array
	{
		return array(
			// Was application.ini external_sso_key
			\Tachyon\Plugins\Property::NewInstance('key')->SetLabel('SSO key')
				->SetDefaultValue(''),
		);
	}

}
