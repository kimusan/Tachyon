<?php

namespace Tachyon\Providers\Files;

interface IFiles
{
	public function GenerateLocalFullFileName(\Tachyon\Model\Account $oAccount, string $sKey) : string;

	public function PutFile(\Tachyon\Model\Account $oAccount, string $sKey, /*resource*/ $rSource) : bool;

	public function MoveUploadedFile(\Tachyon\Model\Account $oAccount, string $sKey, string $sSource) : bool;

	/**
	 * @return resource|bool
	 */
	public function GetFile(\Tachyon\Model\Account $oAccount, string $sKey, string $sOpenMode = 'rb');

	/**
	 * @return string|bool
	 */
	public function GetFileName(\Tachyon\Model\Account $oAccount, string $sKey);

	public function Clear(\Tachyon\Model\Account $oAccount, string $sKey) : bool;

	/**
	 * @return int | bool
	 */
	public function FileSize(\Tachyon\Model\Account $oAccount, string $sKey);

	public function FileExists(\Tachyon\Model\Account $oAccount, string $sKey) : bool;

	public function GC(int $iTimeToClearInHours = 24) : bool;
}
