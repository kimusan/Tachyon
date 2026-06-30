<?php
namespace OCA\Tachyon\Util\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings
{
	private $config;

	public function __construct(IConfig $config)
	{
		$this->config = $config;
	}

	public function getPanel()
	{
		$uid = \OC::$server->getUserSession()->getUser()->getUID();
		$parameters = [
			'tachyon-email' => $this->config->getUserValue($uid, 'tachyon', 'tachyon-email'),
			'tachyon-password' => $this->config->getUserValue($uid, 'tachyon', 'tachyon-password') ? '******' : ''
		];
		\OCP\Util::addScript('tachyon', 'tachyon');
		return new TemplateResponse('tachyon', 'personal_settings', $parameters, '');
	}

	public function getSectionID()
	{
		return 'additional';
	}

	public function getPriority()
	{
		return 50;
	}
}
