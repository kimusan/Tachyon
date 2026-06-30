<?php

namespace Tachyon\Providers\Filters;

class SieveStorage implements FiltersInterface
{
	use \MailSo\Log\Inherit;

	const SIEVE_FILE_NAME = 'rainloop.user';

	/**
	 * @var \Tachyon\Plugins\Manager
	 */
	private $oPlugins;

	/**
	 * @var \Tachyon\Config\Application
	 */
	private $oConfig;

	public function __construct($oPlugins, $oConfig)
	{
		$this->oPlugins = $oPlugins;
		$this->oConfig = $oConfig;
	}

	protected function getConnection(\Tachyon\Model\Account $oAccount) : ?\MailSo\Sieve\SieveClient
	{
		$oSieveClient = new \MailSo\Sieve\SieveClient();
		$oSieveClient->SetLogger($this->oLogger);
		return $oAccount->SieveConnectAndLogin($this->oPlugins, $oSieveClient, $this->oConfig)
			 ? $oSieveClient
			 : null;
	}

	public function Load(\Tachyon\Model\Account $oAccount) : array
	{
		$aModules = array();
		$aScripts = array();

		$oSieveClient = $this->getConnection($oAccount);
		if ($oSieveClient) {
			$aModules = $oSieveClient->Modules();
			\sort($aModules);

			$aList = $oSieveClient->ListScripts();

			foreach ($aList as $name => $active) {
				$aScripts[$name] = array(
					'@Object' => 'Object/SieveScript',
					'name' => $name,
					'active' => $active,
					'body' => $oSieveClient->GetScript($name)
				);
			}

			$oSieveClient->Disconnect();

			if (!isset($aList[self::SIEVE_FILE_NAME])) {
				$aScripts[self::SIEVE_FILE_NAME] = array(
					'@Object' => 'Object/SieveScript',
					'name' => self::SIEVE_FILE_NAME,
					'active' => false,
					'body' => ''
				);
			}
		}

		\ksort($aScripts);

		return array(
			'Capa' => $aModules,
			'Scripts' => $aScripts
		);
	}

	public function Save(\Tachyon\Model\Account $oAccount, string $sScriptName, string $sRaw) : bool
	{
		$oSieveClient = $this->getConnection($oAccount);
		if ($oSieveClient) {
			$oSieveClient->PutScript($sScriptName, $sRaw);
			return true;
		}
		return false;
	}

	/**
	 * If $sScriptName is the empty string (i.e., ""), then any active script is disabled.
	 */
	public function Activate(\Tachyon\Model\Account $oAccount, string $sScriptName) : bool
	{
		$oSieveClient = $this->getConnection($oAccount);
		if ($oSieveClient) {
			$oSieveClient->SetActiveScript(\trim($sScriptName));
			return true;
		}
		return false;
	}
/*
	public function Check(\Tachyon\Model\Account $oAccount, string $sScript) : bool
	{
		$oSieveClient = $this->getConnection($oAccount);
		if ($oSieveClient) {
			$oSieveClient->CheckScript($sScript);
			return true;
		}
		return false;
	}
*/
	public function Delete(\Tachyon\Model\Account $oAccount, string $sScriptName) : bool
	{
		$oSieveClient = $this->getConnection($oAccount);
		if ($oSieveClient) {
			if (isset($oSieveClient->ListScripts()[$sScriptName])) {
				$oSieveClient->DeleteScript(\trim($sScriptName));
			}
			return true;
		}
		return false;
	}
}
