<?php

namespace Tachyon\Providers\Domain;

interface DomainInterface
{
	public function Disable(string $sName, bool $bDisable) : bool;

	public function Load(string $sName, bool $bFindWithWildCard = false, bool $bCheckDisabled = true) : ?\Tachyon\Model\Domain;

	public function Save(\Tachyon\Model\Domain $oDomain) : bool;

	public function Delete(string $sName) : bool;

	public function GetList(bool $bIncludeAliases = true) : array;
}
