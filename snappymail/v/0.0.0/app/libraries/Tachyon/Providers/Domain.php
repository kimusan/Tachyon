<?php

namespace Tachyon\Providers;

use Tachyon\Notifications;
use Tachyon\Exceptions\ClientException;

class Domain extends AbstractProvider
{
	private Domain\DomainInterface $oDriver;

	private \Tachyon\Plugins\Manager $oPlugins;

	public function __construct(Domain\DomainInterface $oDriver, \Tachyon\Plugins\Manager $oPlugins)
	{
		$this->oDriver = $oDriver;
		$this->oPlugins = $oPlugins;
	}

	public function Load(string $sName, bool $bFindWithWildCard = false, bool $bCheckDisabled = true, bool $bCheckAliases = true) : ?\Tachyon\Model\Domain
	{
		$oDomain = $this->oDriver->Load($sName, $bFindWithWildCard, $bCheckDisabled, $bCheckAliases);
		$oDomain && $this->oPlugins->RunHook('filter.domain', array($oDomain));
		return $oDomain;
	}

	public function Save(\Tachyon\Model\Domain $oDomain) : bool
	{
		return $this->oDriver->Save($oDomain);
	}

	public function SaveAlias(string $sName, string $sAlias) : bool
	{
		if ($this->Load($sName, false, false)) {
			throw new ClientException(\Tachyon\Notifications::DomainAlreadyExists);
		}
		return $this->oDriver->SaveAlias($sName, $sAlias);
	}

	public function Delete(string $sName) : bool
	{
		return $this->oDriver->Delete($sName);
	}

	public function Disable(string $sName, bool $bDisabled) : bool
	{
		return $this->oDriver->Disable($sName, $bDisabled);
	}

	public function GetList(bool $bIncludeAliases = true) : array
	{
		return $this->oDriver->GetList($bIncludeAliases);
	}

	public function LoadOrCreateNewFromAction(\Tachyon\Actions $oActions, ?string $sNameForTest = null) : ?\Tachyon\Model\Domain
	{
		$sName = \mb_strtolower((string) $oActions->GetActionParam('name', ''));
		if (\strlen($sName) && $sNameForTest && !\str_contains($sName, '*')) {
			$sNameForTest = null;
		}
		if (\strlen($sName) || $sNameForTest) {
			if (!$sNameForTest && !empty($oActions->GetActionParam('create', 0)) && $this->Load($sName)) {
				throw new ClientException(\Tachyon\Notifications::DomainAlreadyExists);
			}
			return \Tachyon\Model\Domain::fromArray($sNameForTest ?: $sName, [
				'IMAP' => $oActions->GetActionParam('IMAP'),
				'SMTP' => $oActions->GetActionParam('SMTP'),
				'Sieve' => $oActions->GetActionParam('Sieve'),
				'whiteList' => $oActions->GetActionParam('whiteList')
			]);
		}
		return null;
	}

	public function IsActive() : bool
	{
		return $this->oDriver instanceof Domain\DomainInterface;
	}

	public function getByEmailAddress(string $sEmail) : \Tachyon\Model\Domain
	{
		$oDomain = $this->Load(\MailSo\Base\Utils::getEmailAddressDomain($sEmail), true);
		if (!$oDomain) {
			throw new ClientException(Notifications::DomainNotAllowed, null, "{$sEmail} has no domain configuration");
		}
		if (!$oDomain->ValidateWhiteList($sEmail)) {
			throw new ClientException(Notifications::AccountNotAllowed, null, "{$sEmail} not whitelisted");
		}
		return $oDomain;
	}
}
