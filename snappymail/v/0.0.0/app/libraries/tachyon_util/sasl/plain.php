<?php

namespace Tachyon\Util\SASL;

class Plain extends \Tachyon\Util\SASL
{

	public function authenticate(string $username,
		#[\SensitiveParameter]
		string $passphrase,
		?string $authzid = null) : string
	{
		return $this->encode("{$authzid}\x00{$username}\x00{$passphrase}");
	}

	public static function isSupported(string $param) : bool
	{
		return true;
	}

}
