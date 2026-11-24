<?php

class AddXOriginatingIpHeaderPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME = 'X-Originating-IP',
		VERSION = '2.1',
		RELEASE = '2026-11-24',
		DESCRIPTION = 'Adds X-Originating-IP header to outgoing message, containing sender\'s IP address.';

	public function Init() : void
	{
		$this->addHook('filter.build-message', 'FilterBuildMessage');
	}

	/**
	 * @param \MailSo\Mime\Message $oMessage
	 */
	public function FilterBuildMessage(&$oMessage)
	{
		if ($oMessage instanceof \MailSo\Mime\Message)
		{
			$sIP = $this->Manager()->Actions()->Http()->GetClientIp(
				!!$this->Config()->Get('plugin', 'check_proxy', false),
				(string) $this->Config()->Get('plugin', 'trusted_proxies', '127.0.0.1/8 ::1'));

			$oMessage->SetCustomHeader(
				\MailSo\Mime\Enumerations\Header::X_ORIGINATING_IP,
				$this->Manager()->Actions()->Http()->IsLocalhost($sIP) ? '127.0.0.1' : $sIP
			);
		}
	}

	/**
	 * @return array
	 */
	protected function configMapping() : array
	{
		return array(
			\Tachyon\Plugins\Property::NewInstance('check_proxy')
				->SetLabel('Сheck User Proxy')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDescription('Take the address from X-Forwarded-For, for requests from the trusted proxies below')
				->SetDefaultValue(false),
			\Tachyon\Plugins\Property::NewInstance('trusted_proxies')
				->SetLabel('Trusted proxies')
				->SetDescription('Addresses or CIDR ranges of the reverse proxies in front of Tachyon, separated by spaces')
				->SetDefaultValue('127.0.0.1/8 ::1')
		);
	}
}
