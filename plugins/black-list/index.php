<?php

class BlackListPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME = 'Blacklist',
		VERSION = '2.2',
		RELEASE = '2024-03-04',
		REQUIRED = '2.5.0',
		CATEGORY = 'Login',
		DESCRIPTION = 'Simple blacklist extension (with wildcard and exceptions functionality).';

	public function Init() : void
	{
		$this->addHook('login.credentials.step-1', 'FilterLoginCredentials');
	}

	/**
	 * @throws \Tachyon\Exceptions\ClientException
	 */
	public function FilterLoginCredentials(string &$sEmail)
	{
		$sBlackList = \trim($this->Config()->Get('plugin', 'black_list', ''));
		if (\strlen($sBlackList) && \Tachyon\Plugins\Helper::ValidateWildcardValues($sEmail, $sBlackList)) {
			$sExceptions = \trim($this->Config()->Get('plugin', 'exceptions', ''));
			if (!\strlen($sExceptions) || !\Tachyon\Plugins\Helper::ValidateWildcardValues($sEmail, $sExceptions)) {
				throw new \Tachyon\Exceptions\ClientException(
					$this->Config()->Get('plugin', 'auth_error', false)
					? \Tachyon\Notifications::AuthError
					: \Tachyon\Notifications::AccountNotAllowed
				);
			}
		}
	}

	/**
	 * @return array
	 */
	protected function configMapping() : array
	{
		return array(
			\Tachyon\Plugins\Property::NewInstance('auth_error')->SetLabel('Auth Error')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDescription('Throw an authentication error instead of an access error.')
				->SetDefaultValue(false),
			\Tachyon\Plugins\Property::NewInstance('black_list')->SetLabel('Black List')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING_TEXT)
				->SetDescription('Emails black list, space as delimiter, wildcard supported.')
				->SetDefaultValue('*@domain1.com user@domain2.com'),
			\Tachyon\Plugins\Property::NewInstance('exceptions')->SetLabel('Exceptions')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING_TEXT)
				->SetDescription('Exceptions for black list, space as delimiter, wildcard supported.')
				->SetDefaultValue('demo@domain1.com *@domain2.com admin@*')
		);
	}
}
