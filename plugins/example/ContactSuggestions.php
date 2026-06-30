<?php

namespace Plugins\Example;

class ContactSuggestions implements \Tachyon\Providers\Suggestions\ISuggestions
{
//	use \MailSo\Log\Inherit;

	public function Process(\Tachyon\Model\Account $oAccount, string $sQuery, int $iLimit = 20) : array
	{
		return array(
			array($oAccount->Email(), ''),
			array('email@domain.com', 'name')
		);
	}
}
