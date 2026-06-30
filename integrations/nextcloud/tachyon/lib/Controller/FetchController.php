<?php

namespace OCA\Tachyon\Util\Controller;

use OCA\Tachyon\Util\Util\TachyonHelper;

use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;

class FetchController extends Controller {
	private IConfig $config;
	private IAppManager $appManager;
	private IL10N $l;

	public function __construct(string $appName, IRequest $request, IAppManager $appManager, IConfig $config, IL10N $l) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->appManager = $appManager;
		$this->l = $l;
	}

	public function upgrade(): JSONResponse {
		$error = 'Upgrade failed';
		try {
			TachyonHelper::loadApp();
			if (\Tachyon\Util\Upgrade::core()) {
				return new JSONResponse([
					'status' => 'success',
					'Message' => $this->l->t('Upgraded successfully')
				]);
			}
		} catch (Exception $e) {
			$error .= ': ' . $e->getMessage();
		}
		return new JSONResponse([
			'status' => 'error',
			'Message' => $error
		]);
	}

	public function setAdmin(): JSONResponse {
		try {
			$sUrl = '';
			$sPath = '';

			if (isset($_POST['appname']) && 'tachyon' === $_POST['appname']) {
				$this->config->setAppValue('tachyon', 'tachyon-autologin',
					isset($_POST['tachyon-autologin']) ? '1' === $_POST['tachyon-autologin'] : false);
				$this->config->setAppValue('tachyon', 'tachyon-autologin-with-email',
					isset($_POST['tachyon-autologin']) ? '2' === $_POST['tachyon-autologin'] : false);
				$this->config->setAppValue('tachyon', 'tachyon-no-embed', isset($_POST['tachyon-no-embed']));
				$this->config->setAppValue('tachyon', 'tachyon-autologin-oidc', isset($_POST['tachyon-autologin-oidc']));
			} else {
				return new JSONResponse([
					'status' => 'error',
					'Message' => $this->l->t('Invalid argument(s)')
				]);
			}

			TachyonHelper::loadApp();

			$oConfig = \Tachyon\Api::Config();
			if (!empty($_POST['tachyon-app_path'])) {
				$oConfig->Set('webmail', 'app_path', $_POST['tachyon-app_path']);
			}
			$oConfig->Set('webmail', 'allow_languages_on_settings', empty($_POST['tachyon-nc-lang']));
			$oConfig->Set('login', 'allow_languages_on_login', empty($_POST['tachyon-nc-lang']));
			$oConfig->Save();

			if (!empty($_POST['import-rainloop'])) {
				return new JSONResponse([
					'status' => 'success',
					'Message' => \implode("\n", \OCA\Tachyon\Util\Util\RainLoop::import())
				]);
			}

			$debug = !empty($_POST['tachyon-debug']);
			$oConfig = \Tachyon\Api::Config();
			if ($debug != $oConfig->Get('debug', 'enable', false)) {
				$oConfig->Set('debug', 'enable', $debug);
				$oConfig->Save();
			}

			return new JSONResponse([
				'status' => 'success',
				'Message' => $this->l->t('Saved successfully')
			]);
		} catch (Exception $e) {
			return new JSONResponse([
				'status' => 'error',
				'Message' => $e->getMessage()
			]);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function setPersonal(): JSONResponse {
		try {
			$sEmail = '';
			if (isset($_POST['appname'], $_POST['tachyon-password'], $_POST['tachyon-email']) && 'tachyon' === $_POST['appname']) {
				$sUser =  \OC::$server->getUserSession()->getUser()->getUID();

				$sEmail = $_POST['tachyon-email'];
				$this->config->setUserValue($sUser, 'tachyon', 'tachyon-email', $sEmail);

				$sPass = $_POST['tachyon-password'];
				if ('******' !== $sPass) {
					$this->config->setUserValue($sUser, 'tachyon', 'passphrase',
						$sPass ? TachyonHelper::encodePassword($sPass, \md5($sEmail)) : '');
				}
			} else {
				return new JSONResponse([
					'status' => 'error',
					'Message' => $this->l->t('Invalid argument(s)'),
					'Email' => $sEmail
				]);
			}

			// Logout as the credentials have changed
			TachyonHelper::loadApp();
			\Tachyon\Api::Actions()->DoLogout();

			return new JSONResponse([
				'status' => 'success',
				'Message' => $this->l->t('Saved successfully'),
				'Email' => $sEmail
			]);
		} catch (Exception $e) {
			// Logout as the credentials might have changed, as exception could be in one attribute
			// TODO: Handle both exceptions separately?
			TachyonHelper::loadApp();
			\Tachyon\Api::Actions()->DoLogout();

			return new JSONResponse([
				'status' => 'error',
				'Message' => $e->getMessage()
			]);
		}
	}
}

