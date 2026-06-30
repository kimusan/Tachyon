<?php

class LoginRegisterPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME     = 'Register and Forgot',
		VERSION  = '2.2',
		RELEASE  = '2024-03-29',
		REQUIRED = '2.36.0',
		CATEGORY = 'Login',
		DESCRIPTION = 'Links on login screen for registration and forgotten password';

	public function Init() : void
	{
		$this->UseLangs(true);
		$this->addJs('LoginRegister.js');
		$this->addHook('filter.app-data', 'FilterAppData');
	}

	public function configMapping() : array
	{
		return [
			\Tachyon\Plugins\Property::NewInstance("forgot_password_link_url")
//				->SetLabel('TAB_LOGIN/LABEL_FORGOT_PASSWORD_LINK_URL')
				->SetLabel('Forgot password url')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::URL),
			\Tachyon\Plugins\Property::NewInstance("registration_link_url")
//				->SetLabel('TAB_LOGIN/LABEL_REGISTRATION_LINK_URL')
				->SetLabel('Register url')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::URL),
		];
	}

	public function FilterAppData($bAdmin, &$aResult)
	{
		if (!$bAdmin && \is_array($aResult) && empty($aResult['Auth'])) {
			$aResult['forgotPasswordLinkUrl'] = \trim($this->Config()->Get('plugin', 'forgot_password_link_url', ''));
			$aResult['registrationLinkUrl'] = \trim($this->Config()->Get('plugin', 'registration_link_url', ''));
		}
	}

}
