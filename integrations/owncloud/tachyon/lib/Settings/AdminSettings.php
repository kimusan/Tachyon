<?php
namespace OCA\Tachyon\Util\Settings;

use OCA\Tachyon\Util\Util\TachyonHelper;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings
{
	private $config;

	public function __construct(IConfig $config)
	{
		$this->config = $config;
	}

	public function getPanel()
	{
		\OCA\Tachyon\Util\Util\TachyonHelper::loadApp();

		$keys = [
			'tachyon-autologin',
			'tachyon-autologin-with-email',
			'tachyon-no-embed'
		];
		$parameters = [];
		foreach ($keys as $k) {
			$v = $this->config->getAppValue('tachyon', $k);
			$parameters[$k] = $v;
		}
		$uid = \OC::$server->getUserSession()->getUser()->getUID();
		if (\OC_User::isAdminUser($uid)) {
//			$parameters['snappymail-admin-panel-link'] = TachyonHelper::getAppUrl().'?admin';
			TachyonHelper::loadApp();
			$parameters['snappymail-admin-panel-link'] =
				\OC::$server->getURLGenerator()->linkToRoute('snappymail.page.index')
				. '?' . \Tachyon\Api::Config()->Get('security', 'admin_panel_key', 'admin');
		}

		$oConfig = \Tachyon\Api::Config();
		$passfile = APP_PRIVATE_DATA . 'admin_password.txt';
		$sPassword = '';
		if (\is_file($passfile)) {
			$sPassword = \file_get_contents($passfile);
			$parameters['snappymail-admin-panel-link'] .= '#/security';
		}
		$parameters['snappymail-admin-password'] = $sPassword;

		$parameters['can-import-rainloop'] = $sPassword && \is_dir(
			\rtrim(\trim(\OC::$server->getSystemConfig()->getValue('datadirectory', '')), '\\/')
			. '/rainloop-storage'
		);

		$parameters['tachyon-debug'] = $oConfig->Get('debug', 'enable', false);

		// Check for owncloud plugin update, if so then update
		foreach (\Tachyon\Util\Repository::getPackagesList()['List'] as $plugin) {
			if ('owncloud' == $plugin['id'] && $plugin['canBeUpdated']) {
				\Tachyon\Util\Repository::installPackage('plugin', 'owncloud');
			}
		}

		\OCP\Util::addScript('tachyon', 'tachyon');
		return new TemplateResponse('tachyon', 'admin-local', $parameters);
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
