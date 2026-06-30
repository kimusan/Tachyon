<?php

class OverrideSmtpCredentialsPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME = 'Override SMTP Credentials',
		VERSION = '2.5',
		RELEASE = '2024-03-12',
		REQUIRED = '2.35.3',
		CATEGORY = 'Filters',
		DESCRIPTION = 'Override SMTP credentials for specific users.';

	public function Init() : void
	{
		$this->addHook('smtp.before-connect', 'FilterSmtpConnect');
		$this->addHook('smtp.before-login', 'FilterSmtpCredentials');
	}

	/**
	 * @param \Tachyon\Model\Account $oAccount
	 * @param \MailSo\Smtp\SmtpClient $oSmtpClient
	 * @param \MailSo\Smtp\Settings $oSettings
	 */
	public function FilterSmtpConnect(\Tachyon\Model\Account $oAccount, \MailSo\Smtp\SmtpClient $oSmtpClient, \MailSo\Smtp\Settings $oSettings)
	{
		$sEmail = $oAccount->Email();
		$sWhiteList = \trim($this->Config()->Get('plugin', 'override_users', ''));
		$sFoundValue = '';
		if (\strlen($sWhiteList) && \Tachyon\Plugins\Helper::ValidateWildcardValues($sEmail, $sWhiteList, $sFoundValue)) {
			\Tachyon\Util\LOG::debug('SMTP Override', "{$sEmail} matched {$sFoundValue}");
			$oSettings->usePhpMail = false;
			$sHost = \trim($this->Config()->Get('plugin', 'smtp_host', ''));
			if (\strlen($sHost)) {
				$oSettings->host = $sHost;
				$oSettings->port = (int) $this->Config()->Get('plugin', 'smtp_port', 25);
				$sSecure = \trim($this->Config()->Get('plugin', 'smtp_secure', 'None'));
				switch ($sSecure)
				{
					case 'SSL':
						$oSettings->type = MailSo\Net\Enumerations\ConnectionSecurityType::SSL;
						break;
					case 'TLS':
					case 'STARTTLS':
						$oSettings->type = MailSo\Net\Enumerations\ConnectionSecurityType::STARTTLS;
						break;
					case 'Detect':
						$oSettings->type = MailSo\Net\Enumerations\ConnectionSecurityType::AUTO_DETECT;
						break;
					default:
						$oSettings->type = MailSo\Net\Enumerations\ConnectionSecurityType::NONE;
						break;
				}
			}
		} else {
			\Tachyon\Util\LOG::debug('SMTP Override', "{$sEmail} no match");
		}
	}

	/**
	 * @param \Tachyon\Model\Account $oAccount
	 * @param \MailSo\Smtp\SmtpClient $oSmtpClient
	 * @param \MailSo\Smtp\Settings $oSettings
	 */
	public function FilterSmtpCredentials(\Tachyon\Model\Account $oAccount, \MailSo\Smtp\SmtpClient $oSmtpClient, \MailSo\Smtp\Settings $oSettings)
	{
		$sWhiteList = \trim($this->Config()->Get('plugin', 'override_users', ''));
		$sFoundValue = '';
		if (\strlen($sWhiteList) && \Tachyon\Plugins\Helper::ValidateWildcardValues($oAccount->Email(), $sWhiteList, $sFoundValue)) {
			$oSettings->useAuth = (bool) $this->Config()->Get('plugin', 'smtp_auth', true);
			$oSettings->username = \trim($this->Config()->Get('plugin', 'smtp_user', ''));
			$oSettings->passphrase = (string) $this->Config()->Get('plugin', 'smtp_password', '');
		}
	}

	/**
	 * @return array
	 */
	protected function configMapping() : array
	{
		return array(
			\Tachyon\Plugins\Property::NewInstance('smtp_host')->SetLabel('SMTP Host')
				->SetDefaultValue(''),
			\Tachyon\Plugins\Property::NewInstance('smtp_port')->SetLabel('SMTP Port')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::INT)
				->SetDefaultValue(25),
			\Tachyon\Plugins\Property::NewInstance('smtp_secure')->SetLabel('SMTP Secure')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::SELECTION)
				->SetDefaultValue(array('None', 'Detect', 'SSL', 'STARTTLS')),
			\Tachyon\Plugins\Property::NewInstance('smtp_auth')->SetLabel('Use auth')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(true),
			\Tachyon\Plugins\Property::NewInstance('smtp_user')->SetLabel('SMTP User')
				->SetDefaultValue(''),
			\Tachyon\Plugins\Property::NewInstance('smtp_password')->SetLabel('SMTP Password')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::PASSWORD)
				->SetDefaultValue(''),
			\Tachyon\Plugins\Property::NewInstance('override_users')->SetLabel('Override users')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING_TEXT)
				->SetDescription('space as delimiter, wildcard supported.')
				->SetDefaultValue('user@example.com *@example2.com')
		);
	}
}
