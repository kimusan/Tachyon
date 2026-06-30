<?php

namespace Tachyon\Providers\Settings;

interface ISettings
{
	public function Load(\Tachyon\Model\Account $oAccount) : array;

	public function Save(\Tachyon\Model\Account $oAccount, \Tachyon\Settings $oSettings) : bool;

	public function Delete(\Tachyon\Model\Account $oAccount) : bool;
}
