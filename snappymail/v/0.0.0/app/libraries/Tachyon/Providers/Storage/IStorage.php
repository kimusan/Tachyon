<?php

namespace Tachyon\Providers\Storage;

interface IStorage
{
	/**
	 * @param \Tachyon\Model\Account|null $mAccount
	 */
	public function Put($mAccount, int $iStorageType, string $sKey, string $sValue) : bool;

	/**
	 * @param \Tachyon\Model\Account|null $mAccount
	 * @param mixed $mDefault = false
	 *
	 * @return mixed
	 */
	public function Get($mAccount, int $iStorageType, string $sKey, $mDefault = false);

	/**
	 * @param \Tachyon\Model\Account|null $mAccount
	 */
	public function Clear($mAccount, int $iStorageType, string $sKey) : bool;

	public function IsLocal() : bool;
}
