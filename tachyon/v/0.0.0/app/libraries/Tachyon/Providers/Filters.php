<?php

namespace Tachyon\Providers;

class Filters extends \Tachyon\Providers\AbstractProvider
{
	/**
	 * @var \Tachyon\Providers\Filters\FiltersInterface
	 */
	private $oDriver;

	public function __construct(\Tachyon\Providers\Filters\FiltersInterface $oDriver)
	{
		$this->oDriver = $oDriver;
	}

	private static function handleException(\Throwable $oException, int $defNotification) : void
	{
		if ($oException instanceof \MailSo\Net\Exceptions\SocketCanNotConnectToHostException) {
			throw new \Tachyon\Exceptions\ClientException(\Tachyon\Notifications::ConnectionError, $oException);
		}

		if ($oException instanceof \MailSo\Sieve\Exceptions\NegativeResponseException) {
			throw new \Tachyon\Exceptions\ClientException(
				\Tachyon\Notifications::ClientViewError, $oException, \implode("\r\n", $oException->GetResponses())
			);
		}

		throw new \Tachyon\Exceptions\ClientException($defNotification, $oException);
	}

	public function Load(\Tachyon\Model\Account $oAccount) : array
	{
		try
		{
			return $this->IsActive() ? $this->oDriver->Load($oAccount) : array();
		}
		catch (\Throwable $oException)
		{
			static::handleException($oException, \Tachyon\Notifications::CantGetFilters);
		}
	}

	public function Save(\Tachyon\Model\Account $oAccount, string $sScriptName, string $sRaw) : bool
	{
		try
		{
			return $this->IsActive()
				? $this->oDriver->Save($oAccount, $sScriptName, $sRaw)
				: false;
		}
		catch (\Throwable $oException)
		{
			static::handleException($oException, \Tachyon\Notifications::CantSaveFilters);
		}
	}

	public function ActivateScript(\Tachyon\Model\Account $oAccount, string $sScriptName)
	{
		try
		{
			return $this->IsActive()
				? $this->oDriver->Activate($oAccount, $sScriptName)
				: false;
		}
		catch (\Throwable $oException)
		{
			static::handleException($oException, \Tachyon\Notifications::CantActivateFiltersScript);
		}
	}

	public function DeleteScript(\Tachyon\Model\Account $oAccount, string $sScriptName)
	{
		try
		{
			return $this->IsActive()
				? $this->oDriver->Delete($oAccount, $sScriptName)
				: false;
		}
		catch (\Throwable $oException)
		{
			static::handleException($oException, \Tachyon\Notifications::CantDeleteFiltersScript);
		}
	}

	public function IsActive() : bool
	{
		return $this->oDriver instanceof \Tachyon\Providers\Filters\FiltersInterface;
	}
}
