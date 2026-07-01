<?php
namespace OCA\Tachyon\Util\Settings;

use OCA\Tachyon\Util\Util\TachyonHelper;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\App\IAppManager;
use OCP\Util;

class AdminSettings implements ISettings
{
	private IConfig $config;
	private IUserSession $userSession;
	private IGroupManager $groupManager;
	private IURLGenerator $urlGenerator;
	private IAppManager $appManager;

	public function __construct(
		IConfig $config,
		IUserSession $userSession,
		IGroupManager $groupManager,
		IURLGenerator $urlGenerator,
		IAppManager $appManager
	) {
		$this->config = $config;
		$this->userSession = $userSession;
		$this->groupManager = $groupManager;
		$this->urlGenerator = $urlGenerator;
		$this->appManager = $appManager;
	}

	public function getForm()
	{
		\OCA\Tachyon\Util\Util\TachyonHelper::loadApp();

		$keys = [
			'tachyon-autologin',
			'tachyon-autologin-with-email',
			'tachyon-no-embed',
			'tachyon-autologin-oidc'
		];
		$parameters = [];
		foreach ($keys as $k) {
			$v = $this->config->getAppValue('tachyon', $k);
			$parameters[$k] = $v;
		}

		$user = $this->userSession->getUser();
		$uid = $user ? $user->getUID() : null;

		if ($uid && $this->groupManager->isAdmin($uid)) {
//			$parameters['snappymail-admin-panel-link'] = TachyonHelper::getAppUrl().'?admin';
			TachyonHelper::loadApp();
			$parameters['snappymail-admin-panel-link'] =
				$this->urlGenerator->linkToRoute('snappymail.page.index')
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
			\rtrim(\trim($this->config->getSystemValue('datadirectory', '')), '\\/')
			. '/rainloop-storage'
		);

		$parameters['tachyon-debug'] = $oConfig->Get('debug', 'enable', false);

		// Check for nextcloud plugin update, if so then update
		foreach (\Tachyon\Util\Repository::getPackagesList()['List'] as $plugin) {
			if ('nextcloud' == $plugin['id'] && $plugin['canBeUpdated']) {
				\Tachyon\Util\Repository::installPackage('plugin', 'nextcloud');
			}
		}

		// Prevent "Failed loading /nextcloud/snappymail/v/2.N.N/static/js/min/libs.min.js"
		$app_path = $oConfig->Get('webmail', 'app_path');
		if (!$app_path) {
			$app_path = $this->appManager->getAppWebPath('tachyon') . '/app/';
			$oConfig->Set('webmail', 'app_path', $app_path);
			$oConfig->Set('webmail', 'theme', 'NextcloudV25+');
			$oConfig->Save();
		}
		$parameters['tachyon-app_path'] = $oConfig->Get('webmail', 'app_path', false);
		$parameters['tachyon-nc-lang'] = !$oConfig->Get('webmail', 'allow_languages_on_settings', true);

		return new TemplateResponse('tachyon', 'admin-local', $parameters);
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
