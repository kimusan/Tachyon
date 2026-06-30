<?php

namespace Tachyon\Providers;

use Tachyon\Model\Account;
use Tachyon\Providers\Settings\ISettings;

class Settings extends \Tachyon\Providers\AbstractProvider
{
	private ISettings $oDriver;

	public function __construct(ISettings $oDriver)
	{
		$this->oDriver = $oDriver;
	}

	public function Load(Account $oAccount) : \Tachyon\Settings
	{
		return new \Tachyon\Settings($this, $oAccount, $this->oDriver->Load($oAccount));
	}

	public function Save(Account $oAccount, \Tachyon\Settings $oSettings) : bool
	{
		return $this->oDriver->Save($oAccount, $oSettings);
	}

	public function IsActive() : bool
	{
		return true;
	}
}
