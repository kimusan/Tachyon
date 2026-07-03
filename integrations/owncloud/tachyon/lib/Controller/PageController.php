<?php

namespace OCA\Tachyon\Util\Controller;

use OCA\Tachyon\Util\Util\TachyonHelper;
use OCA\Tachyon\Util\ContentSecurityPolicy;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;

class PageController extends Controller
{
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index()
	{
		$config = \OC::$server->getConfig();

		$bAdmin = false;
		if (!empty($_SERVER['QUERY_STRING'])) {
			TachyonHelper::loadApp();
			$bAdmin = \Tachyon\Api::Config()->Get('security', 'admin_panel_key', 'admin') == $_SERVER['QUERY_STRING'];
			if (!$bAdmin) {
				TachyonHelper::startApp(true);
			}
		}

		if (!$bAdmin && $config->getAppValue('tachyon', 'tachyon-no-embed')) {
			\OC::$server->getNavigationManager()->setActiveEntry('tachyon');
			\OCP\Util::addScript('tachyon', 'tachyon');
			\OCP\Util::addStyle('tachyon', 'style');
			TachyonHelper::startApp();
			$response = new TemplateResponse('tachyon', 'index', [
				'tachyon-iframe-url' => TachyonHelper::normalizeUrl(TachyonHelper::getAppUrl())
					. (empty($_GET['target']) ? '' : "#{$_GET['target']}")
			]);
			$csp = new ContentSecurityPolicy();
			$csp->addAllowedFrameDomain("'self'");
			$response->setContentSecurityPolicy($csp);
			return $response;
		}

		\OC::$server->getNavigationManager()->setActiveEntry('tachyon');

		\OCP\Util::addStyle('tachyon', 'embed');

		TachyonHelper::startApp();
		$oConfig = \Tachyon\Api::Config();
		$oActions = $bAdmin ? new \Tachyon\ActionsAdmin() : \Tachyon\Api::Actions();
		$oHttp = \MailSo\Base\Http::SingletonInstance();
		$oServiceActions = new \Tachyon\ServiceActions($oHttp, $oActions);
		$sAppJsMin = $oConfig->Get('debug', 'javascript', false) ? '' : '.min';
		$sAppCssMin = $oConfig->Get('debug', 'css', false) ? '' : '.min';
		$sLanguage = $oActions->GetLanguage(false);

		$csp = new ContentSecurityPolicy();
		$sNonce = $csp->getTachyonNonce();

		$params = [
			'Admin' => $bAdmin ? 1 : 0,
			'LoadingDescriptionEsc' => \htmlspecialchars($oConfig->Get('webmail', 'loading_description', 'Tachyon'), ENT_QUOTES|ENT_IGNORE, 'UTF-8'),
			'BaseTemplates' => \Tachyon\Utils::ClearHtmlOutput($oServiceActions->compileTemplates($bAdmin)),
			'BaseAppBootScript' => \file_get_contents(APP_VERSION_ROOT_PATH.'static/js'.($sAppJsMin ? '/min' : '').'/boot'.$sAppJsMin.'.js'),
			'BaseAppBootScriptNonce' => $sNonce,
			'BaseLanguage' => $oActions->compileLanguage($sLanguage, $bAdmin),
			'BaseAppBootCss' => \file_get_contents(APP_VERSION_ROOT_PATH.'static/css/boot'.$sAppCssMin.'.css'),
			'BaseAppThemeCssLink' => $oActions->ThemeLink($bAdmin),
			'BaseAppThemeCss' => \preg_replace(
				'/\\s*([:;{},]+)\\s*/s',
				'$1',
				$oActions->compileCss($oActions->GetTheme($bAdmin), $bAdmin)
			)
		];

//		\OCP\Util::addScript('tachyon', '../app/snappymail/v/'.APP_VERSION.'/static/js'.($sAppJsMin ? '/min' : '').'/boot'.$sAppJsMin);

		// ownCloud html encodes, so addHeader('style') is not possible
//		\OCP\Util::addHeader('style', ['id'=>'app-boot-css'], \file_get_contents(APP_VERSION_ROOT_PATH.'static/css/boot'.$sAppCssMin.'.css'));
		\OCP\Util::addHeader('link', ['type'=>'text/css','rel'=>'stylesheet','href'=>\Tachyon\Utils::WebStaticPath('css/'.($bAdmin?'admin':'app').$sAppCssMin.'.css')], '');
//		\OCP\Util::addHeader('style', ['id'=>'app-theme-style','data-href'=>$params['BaseAppThemeCssLink']], $params['BaseAppThemeCss']);

		$response = new TemplateResponse('tachyon', 'index_embed', $params);

		$response->setContentSecurityPolicy($csp);

		return $response;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function appGet()
	{
		TachyonHelper::startApp(true);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function appPost()
	{
		TachyonHelper::startApp(true);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function indexPost()
	{
		TachyonHelper::startApp(true);
	}
}
