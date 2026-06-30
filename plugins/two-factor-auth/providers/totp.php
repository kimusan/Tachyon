<?php

class TwoFactorAuthTotp implements TwoFactorAuthInterface
{
	public function Label() : string
	{
		return 'Two Factor Authenticator Code';
	}

	public function VerifyCode(string $sSecret, string $sCode) : bool
	{
		return \Tachyon\Util\TOTP::Verify($sSecret, $sCode);
	}

	public function CreateSecret() : string
	{
		return \Tachyon\Util\TOTP::CreateSecret();
	}

}
