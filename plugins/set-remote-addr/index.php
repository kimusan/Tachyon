<?php
/**
 * Can be used for proxies, like Nginx  with:
 *     proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
 * It's an alternative to application.ini http_client_ip_check_proxy.
 * The web server can do the same: nginx set_real_ip_from + real_ip_header,
 * Apache mod_remoteip.
 */

class SetRemoteAddrPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME = 'Set REMOTE_ADDR',
		VERSION = '2.1',
		RELEASE  = '2026-11-24',
		DESCRIPTION = 'Sets the $_SERVER[\'REMOTE_ADDR\'] value from X-Forwarded-For, for requests from trusted proxies';

	public function Init() : void
	{
		// Only a trusted proxy's X-Forwarded-For says anything: any client can
		// send the header, and Client-IP, with an address of its choosing
		$_SERVER['REMOTE_ADDR'] = \MailSo\Base\Http::ForwardedClientIp(
			(string) $this->Config()->Get('plugin', 'trusted_proxies', '127.0.0.1/8 ::1'));
	}

	protected function configMapping() : array
	{
		return array(
			\Tachyon\Plugins\Property::NewInstance('trusted_proxies')
				->SetLabel('Trusted proxies')
				->SetDescription('Addresses or CIDR ranges of the reverse proxies in front of Tachyon, separated by spaces')
				->SetDefaultValue('127.0.0.1/8 ::1')
		);
	}
}
