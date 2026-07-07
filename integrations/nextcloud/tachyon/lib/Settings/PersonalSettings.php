<?php
namespace OCA\Tachyon\Settings;

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

	public function getForm()
	{
		$uid = \OC::$server->getUserSession()->getUser()->getUID();
		$sEmail = $this->config->getUserValue($uid, 'tachyon', 'tachyon-email');
		if ($sPass = $this->config->getUserValue($uid, 'tachyon', 'tachyon-password')) {
			$this->config->deleteUserValue($uid, 'tachyon', 'tachyon-password');
			$this->config->setUserValue($uid, 'tachyon', 'passphrase', $sPass);
		}
		$parameters = [
			'tachyon-email' => $sEmail,
			'tachyon-password' => $this->config->getUserValue($uid, 'tachyon', 'passphrase') ? '******' : ''
		];
		\OCP\Util::addScript('tachyon', 'tachyon');
		return new TemplateResponse('tachyon', 'personal_settings', $parameters, '');
	}

	public function getSection()
	{
		return 'additional';
	}

	public function getPriority()
	{
		return 50;
	}
}
