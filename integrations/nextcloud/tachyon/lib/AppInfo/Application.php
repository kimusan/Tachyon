<?php

namespace OCA\Tachyon\Util\AppInfo;

use OCA\Tachyon\Util\Util\TachyonHelper;
use OCA\Tachyon\Util\Controller\FetchController;
use OCA\Tachyon\Util\Controller\PageController;
use OCA\Tachyon\Util\Dashboard\UnreadMailWidget;
use OCA\Tachyon\Util\Search\Provider;
use OCA\Tachyon\Util\Listeners\AccessTokenUpdatedListener;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\IL10N;
use OCP\IUser;
use OCP\User\Events\PostLoginEvent;
use OCP\User\Events\BeforeUserLoggedOutEvent;
use OCA\OIDCLogin\Events\AccessTokenUpdatedEvent;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUserSession;
use OCP\INavigationManager;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'tachyon';

	public function __construct(array $urlParams = [])
	{
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void
	{
		/**
		 * Controllers
		 */
		$context->registerService(
			'PageController', function($c) {
				return new PageController(
					$c->query('AppName'),
					$c->query('Request'),
					$c->query(IConfig::class),
					$c->query(INavigationManager::class)
				);
			}
		);

		$context->registerService(
			'FetchController', function($c) {
				return new FetchController(
					$c->query('AppName'),
					$c->query('Request'),
					$c->query('ServerContainer')->getAppManager(),
					$c->query('ServerContainer')->getConfig(),
					$c->query(IL10N::class),
					$c->query(IUserSession::class)
				);
			}
		);

		/**
		 * Utils
		 */
		$context->registerService(
			'TachyonHelper', function($c) {
				return new TachyonHelper();
			}
		);

		$context->registerSearchProvider(Provider::class);
		$context->registerEventListener(AccessTokenUpdatedEvent::class, AccessTokenUpdatedListener::class);

		// TODO: Not working yet, needs a Vue UI
//		$context->registerDashboardWidget(UnreadMailWidget::class);
	}

	public function boot(IBootContext $context): void
	{
		$config = $context->getServerContainer()->getConfig();
		if (!\is_dir(\rtrim(\trim($config->getSystemValue('datadirectory', '')), '\\/') . '/appdata_tachyon')) {
			return;
		}

		$dispatcher = $context->getAppContainer()->query('OCP\EventDispatcher\IEventDispatcher');
		$dispatcher->addListener(PostLoginEvent::class, function (PostLoginEvent $Event) use ($context) {
/*
			$config = $context->getServerContainer()->getConfig();
			// Only store the user's password in the current session if they have
			// enabled auto-login using Nextcloud username or email address.
			if ($config->getAppValue('tachyon', 'tachyon-autologin', false)
			 || $config->getAppValue('tachyon', 'tachyon-autologin-with-email', false)) {
*/
				$sUID = $Event->getUser()->getUID();
				$session = $context->getServerContainer()->getSession();
				$session['tachyon-nc-uid'] = $sUID;
				$session['tachyon-passphrase'] = TachyonHelper::encodePassword($Event->getPassword(), $sUID);
/*
			}
*/
		});

		$dispatcher->addListener(BeforeUserLoggedOutEvent::class, function (BeforeUserLoggedOutEvent $Event) {
			// https://github.com/nextcloud/server/issues/36083#issuecomment-1387370634
//			\OC::$server->getSession()['tachyon-passphrase'] = '';
			TachyonHelper::loadApp();
//			\Tachyon\Api::Actions()->Logout(true);
			\Tachyon\Api::Actions()->DoLogout();
		});

		// https://github.com/nextcloud/impersonate/issues/179
		// https://github.com/nextcloud/impersonate/pull/180
		$class = 'OCA\Impersonate\Events\BeginImpersonateEvent';
		if (\class_exists($class)) {
			$dispatcher->addListener($class, function ($Event) use ($context) {
				$context->getServerContainer()->getSession()['tachyon-passphrase'] = '';
				TachyonHelper::loadApp();
				\Tachyon\Api::Actions()->Logout(true);
			});
			$dispatcher->addListener('OCA\Impersonate\Events\EndImpersonateEvent', function ($Event) use ($context) {
				$context->getServerContainer()->getSession()['tachyon-passphrase'] = '';
				TachyonHelper::loadApp();
				\Tachyon\Api::Actions()->Logout(true);
			});
		}
	}
}
