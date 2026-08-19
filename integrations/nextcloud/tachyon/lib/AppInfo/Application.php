<?php

namespace OCA\Tachyon\AppInfo;

use OCA\Tachyon\Util\TachyonHelper;
use OCA\Tachyon\Dashboard\UnreadMailWidget;
use OCA\Tachyon\Search\Provider;
use OCA\Tachyon\Listeners\AccessTokenUpdatedListener;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\User\Events\PostLoginEvent;
use OCP\User\Events\BeforeUserLoggedOutEvent;
use OCP\IConfig;
use OCP\ISession;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'tachyon';

	public function __construct(array $urlParams = [])
	{
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void
	{
		// Controllers are autowired by NC's DI container from their typed constructors.

		$context->registerSearchProvider(Provider::class);

		// Only register OIDC token listener when the oidc_login app is present.
		if (\class_exists('OCA\OIDCLogin\Events\AccessTokenUpdatedEvent')) {
			$context->registerEventListener(
				\OCA\OIDCLogin\Events\AccessTokenUpdatedEvent::class,
				AccessTokenUpdatedListener::class
			);
		}

		// TODO: Not working yet, needs a Vue UI
//		$context->registerDashboardWidget(UnreadMailWidget::class);
	}

	public function boot(IBootContext $context): void
	{
		$config = $context->getServerContainer()->get(\OCP\IConfig::class);
		$_dataDir = \rtrim(\trim($config->getSystemValue('datadirectory', '')), '\\/');
		if (!\is_dir($_dataDir . '/appdata_tachyon') && !\is_dir($_dataDir . '/appdata_snappymail')) {
			return;
		}
		unset($_dataDir);

		$dispatcher = $context->getServerContainer()->get(IEventDispatcher::class);
		$dispatcher->addListener(PostLoginEvent::class, function (PostLoginEvent $Event) use ($context) {
/*
			$config = $context->getServerContainer()->get(\OCP\IConfig::class);
			// Only store the user's password in the current session if they have
			// enabled auto-login using Nextcloud username or email address.
			if ($config->getAppValue('tachyon', 'tachyon-autologin', false)
			 || $config->getAppValue('tachyon', 'tachyon-autologin-with-email', false)) {
*/
				$sUID = $Event->getUser()->getUID();
				$session = $context->getServerContainer()->get(\OCP\ISession::class);
				$session->set('tachyon-nc-uid', $sUID);
				$session->set('tachyon-passphrase', TachyonHelper::encodePassword($Event->getPassword(), $sUID));
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
				$context->getServerContainer()->get(\OCP\ISession::class)->set('tachyon-passphrase', '');
				TachyonHelper::loadApp();
				\Tachyon\Api::Actions()->Logout(true);
			});
			$dispatcher->addListener('OCA\Impersonate\Events\EndImpersonateEvent', function ($Event) use ($context) {
				$context->getServerContainer()->get(\OCP\ISession::class)->set('tachyon-passphrase', '');
				TachyonHelper::loadApp();
				\Tachyon\Api::Actions()->Logout(true);
			});
		}
	}
}
