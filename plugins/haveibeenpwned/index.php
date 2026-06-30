<?php
/**
 * https://haveibeenpwned.com/API/v3
 */

use Tachyon\Util\Hibp;
use Tachyon\Util\SensitiveString;

class HaveibeenpwnedPlugin extends \Tachyon\Plugins\AbstractPlugin
{
//	use \MailSo\Log\Inherit;

	const
		NAME     = 'Have i been pwned',
		AUTHOR   = 'Tachyon',
		URL      = 'https://snappymail.eu/',
		VERSION  = '0.1',
		RELEASE  = '2024-04-22',
		REQUIRED = '2.36.1',
		CATEGORY = 'General',
		LICENSE  = 'MIT',
		DESCRIPTION = 'Check if your passphrase or email address is in a data breach';

	public function Init() : void
	{
//		$this->UseLangs(true);
		$this->addJs('hibp.js');
		$this->addJsonHook('HibpCheck');
	}

	public function HibpCheck()
	{
//		$oAccount = $this->Manager()->Actions()->GetAccount();
		$oAccount = $this->Manager()->Actions()->getAccountFromToken();
//		$oAccount = \Tachyon\Api::Actions()->getAccountFromToken();

		$api_key = \trim($this->Config()->Get('plugin', 'hibp-api-key', ''));
		$breaches = $api_key ? Hibp::account($api_key, $oAccount->Email()) : null;

		$pwned = Hibp::password(new SensitiveString($oAccount->ImapPass()));

		return $this->jsonResponse(__FUNCTION__, array(
			'pwned' => $pwned,
			'breaches' => $breaches
		));
	}

	public function configMapping() : array
	{
		return [
			\Tachyon\Plugins\Property::NewInstance("hibp-api-key")
				->SetLabel('API key')
				->SetDescription('https://haveibeenpwned.com/API/Key')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING)
		];
	}
}
