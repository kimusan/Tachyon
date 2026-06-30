<?php

class LdapContactsSuggestionsPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME     = 'Contacts suggestions (LDAP)',
		VERSION  = '2.14',
		RELEASE  = '2024-03-12',
		REQUIRED = '2.35.3',
		CATEGORY = 'Contacts',
		DESCRIPTION = 'Get contacts suggestions from LDAP.';

	public function Init() : void
	{
		$this->addHook('main.fabrica', 'MainFabrica');
	}

	public function Supported() : string
	{
		if (!\function_exists('ldap_connect'))
		{
			return 'The LDAP PHP extension must be installed to use this extension';
		}

		return '';
	}

	/**
	 * @param string $sName
	 * @param mixed $mResult
	 */
	public function MainFabrica($sName, &$mResult)
	{
		if ('suggestions' == $sName) {
			if (!\is_array($mResult)) {
				$mResult = array();
			}

			$sLdapUri = \trim($this->Config()->Get('plugin', 'ldap_uri', ''));
			$sBaseDn = \trim($this->Config()->Get('plugin', 'base_dn', ''));
			$sObjectClasses = \trim($this->Config()->Get('plugin', 'object_classes', ''));
			$sEmailAttributes = \trim($this->Config()->Get('plugin', 'mail_attributes', ''));

			if (\strlen($sLdapUri) && \strlen($sBaseDn) && \strlen($sObjectClasses) && \strlen($sEmailAttributes)) {
				require_once __DIR__.'/LdapContactsSuggestions.php';

				$oProvider = new LdapContactsSuggestions();
				$oProvider->SetConfig(
					$sLdapUri,
					(bool) $this->Config()->Get('plugin', 'use_start_tls', true),
					\trim($this->Config()->Get('plugin', 'bind_dn', '')),
					\trim($this->Config()->Get('plugin', 'bind_password', '')),
					$sBaseDn,
					$sObjectClasses,
					\trim($this->Config()->Get('plugin', 'uid_attributes', '')),
					\trim($this->Config()->Get('plugin', 'name_attributes', '')),
					$sEmailAttributes,
					\trim($this->Config()->Get('plugin', 'allowed_emails', ''))
				);

				$mResult[] = $oProvider;
			}
		}
	}

	protected function configMapping() : array
	{
		return array(
			\Tachyon\Plugins\Property::NewInstance('ldap_uri')->SetLabel('LDAP URI')
				->SetDescription('LDAP server URI(s), space separated')
				->SetDefaultValue('ldap://localhost:389'),
			\Tachyon\Plugins\Property::NewInstance('use_start_tls')->SetLabel('Use StartTLS')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(True),
			\Tachyon\Plugins\Property::NewInstance('bind_dn')->SetLabel('Bind DN')
				->SetDescription('DN to bind (login) with. If left blank, anonymous bind will be tried and the password will be ignored')
				->SetDefaultValue(''),
			\Tachyon\Plugins\Property::NewInstance('bind_password')->SetLabel('Bind password')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::PASSWORD)
				->SetDefaultValue(''),
			\Tachyon\Plugins\Property::NewInstance('base_dn')->SetLabel('Search base DN')
				->SetDescription('DN to use as the search base. Supported tokens: {domain}, {domain:dc}, {email}, {email:user}, {email:domain}, {login}, {imap:login}, {imap:host}, {imap:port}')
				->SetDefaultValue('ou=People,dc=example,dc=com'),
			\Tachyon\Plugins\Property::NewInstance('object_classes')->SetLabel('objectClasses')
				->SetDescription('LDAP objectClasses to search for, comma separated list')
				->SetDefaultValue('inetOrgPerson'),
			\Tachyon\Plugins\Property::NewInstance('uid_attributes')->SetLabel('uid attributes')
				->SetDescription('LDAP attributes for userids, comma separated list in order of preference')
				->SetDefaultValue('uid'),
			\Tachyon\Plugins\Property::NewInstance('name_attributes')->SetLabel('Name attributes')
				->SetDescription('LDAP attributes for user names, comma separated list in order of preference')
				->SetDefaultValue('displayName,cn,givenName,sn'),
			\Tachyon\Plugins\Property::NewInstance('mail_attributes')->SetLabel('Mail attributes')
				->SetDescription('LDAP attributes for user email addresses, comma separated list in order of preference')
				->SetDefaultValue('mailAddress,mail,mailAlternateAddress,mailAlias'),
			\Tachyon\Plugins\Property::NewInstance('allowed_emails')->SetLabel('Allowed emails')
				->SetDescription('Email addresses of users which should be allowed to do LDAP lookups, space as delimiter, wildcard supported. Example: user1@domain1.net user2@domain1.net *@domain2.net')
				->SetDefaultValue('*')
		);
	}
}
