<?php

use Tachyon\Util\SensitiveString;

class ChangePasswordHestiaDriver
{
	const
		NAME        = 'Hestia',
		DESCRIPTION = 'Change passwords in Hestia.';

	/**
	 * @var \Tachyon\Config\Plugin
	 */
	private $oConfig = null;

	/**
	 * @var \MailSo\Log\Logger
	 */
	protected $oLogger = null;

	function __construct(\Tachyon\Config\Plugin $oConfig, \MailSo\Log\Logger $oLogger)
	{
		$this->oConfig = $oConfig;
		$this->oLogger = $oLogger;
	}

	public static function isSupported() : bool
	{
		return true;
	}

	public static function configMapping() : array
	{
		return array(
			\Tachyon\Plugins\Property::NewInstance('hestia_host')->SetLabel('Hestia Host')
				->SetDefaultValue('')
				->SetDescription('Ex: localhost or domain.com'),
			\Tachyon\Plugins\Property::NewInstance('hestia_port')->SetLabel('Hestia Port')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::INT)
				->SetDefaultValue(8083)
		);
	}

	public function ChangePassword(\Tachyon\Model\Account $oAccount, SensitiveString $oPrevPassword, SensitiveString $oNewPassword) : bool
	{
		if (!\Tachyon\Plugins\Helper::ValidateWildcardValues($oAccount->Email(), $this->oConfig->Get('plugin', 'hestia_allowed_emails', ''))) {
			return false;
		}

		$this->oLogger->Write("Hestia: Try to change password for {$oAccount->Email()}");

		$sHost = $this->oConfig->Get('plugin', 'hestia_host');
		$sPort = $this->oConfig->Get('plugin', 'hestia_port');

		$HTTP = \Tachyon\Util\HTTP\Request::factory();
		$postvars = array(
			'email'    => $oAccount->Email(),
			'password' => (string) $oPrevPassword,
			'new'      => (string) $oNewPassword,
		);
		$response = $HTTP->doRequest('POST', 'https://'.$sHost.':'.$sPort.'/reset/mail/', \http_build_query($postvars));
		if (!$response) {
			$this->oLogger->Write("Hestia[Error]: Response failed");
			return false;
		}
		if ('==ok==' != $response->body) {
			$this->oLogger->Write("Hestia[Error]: Response: {$response->status} {$response->body}");
			return false;
		}
		return true;
	}
}
