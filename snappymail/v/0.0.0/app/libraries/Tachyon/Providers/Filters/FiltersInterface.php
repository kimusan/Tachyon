<?php

namespace Tachyon\Providers\Filters;

interface FiltersInterface
{
	public function Load(\Tachyon\Model\Account $oAccount) : array;

	public function Save(\Tachyon\Model\Account $oAccount, string $sScriptName, string $sRaw) : bool;

	public function Activate(\Tachyon\Model\Account $oAccount, string $sScriptName) : bool;

	public function Delete(\Tachyon\Model\Account $oAccount, string $sScriptName) : bool;
}
