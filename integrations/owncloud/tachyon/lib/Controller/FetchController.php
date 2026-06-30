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
	private $config;
	private $appManager;

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
			if (SnappyMail\Upgrade::core()) {
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
			} else {
				return new JSONResponse([
					'status' => 'error',
					'Message' => $this->l->t('Invalid argument(s)')
				]);
			}

			if (!empty($_POST['import-rainloop'])) {
				$result = TachyonHelper::importRainLoop();
				return new JSONResponse([
					'status' => 'success',
					'Message' => \implode("\n", $result)
				]);
			}

			TachyonHelper::loadApp();
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

			if (isset($_POST['appname'], $_POST['tachyon-password'], $_POST['tachyon-email']) && 'tachyon' === $_POST['appname']) {
				$sUser =  \OC::$server->getUserSession()->getUser()->getUID();

				$sPostEmail = $_POST['tachyon-email'];
				$this->config->setUserValue($sUser, 'tachyon', 'tachyon-email', $sPostEmail);

				$sPass = $_POST['tachyon-password'];
				if ('******' !== $sPass) {
					require_once $this->appManager->getAppPath('snappymail').'/lib/Util/TachyonHelper.php';

					$this->config->setUserValue($sUser, 'tachyon', 'tachyon-password',
						$sPass ? TachyonHelper::encodePassword($sPass, \md5($sPostEmail)) : '');
				}

				$sEmail = $this->config->getUserValue($sUser, 'snappymail', 'tachyon-email', '');
			} else {
				return new JSONResponse([
					'status' => 'error',
					'Message' => $this->l->t('Invalid argument(s)'),
					'Email' => $sEmail
				]);
			}

			return new JSONResponse([
				'status' => 'success',
				'Message' => $this->l->t('Saved successfully'),
				'Email' => $sEmail
			]);
		} catch (Exception $e) {
			return new JSONResponse([
				'status' => 'error',
				'Message' => $e->getMessage()
			]);
		}
	}
}

