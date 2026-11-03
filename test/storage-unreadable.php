<?php

// Run with: php test/storage-unreadable.php
// A stored file that exists but cannot be read comes back as '', not as the
// default a missing one gets, so two-factor-auth refuses the login instead of
// taking the password alone. Real FileStorage and the real plugin class.
define('APP_VERSION', 'test');
define('APP_SALT', 'salt-of-this-install');
define('APP_PRIVATE_DATA', sys_get_temp_dir().'/storage-unreadable-'.getmypid().'/');
define('APP_CONFIGURATION_NAME', 'application.ini');
spl_autoload_register(static function (string $class): void {
	$base = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/';
	$file = str_starts_with($class, 'Tachyon\\Util\\')
		? $base.'tachyon_util/'.strtolower(str_replace('\\', '/', substr($class, 13))).'.php'
		: $base.str_replace('\\', '/', $class).'.php';
	if (is_file($file)) {
		require_once $file;
	}
});
require dirname(__DIR__).'/plugins/two-factor-auth/index.php';

use Tachyon\Providers\Storage\Enumerations\StorageType;

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

if (function_exists('posix_geteuid') && 0 === posix_geteuid()) {
	echo "storage-unreadable: skipped, root reads any file\n";
	exit;
}

class TestTwoFactor extends TwoFactorAuthPlugin
{
	public \Tachyon\Providers\Storage\FileStorage $store;
	protected function StorageProvider() : \Tachyon\Providers\Storage { return new \Tachyon\Providers\Storage($this->store); }
	protected function logAuthFailure(\Tachyon\Model\MainAccount $oAccount) : void {}
	protected function Logger() : \MailSo\Log\Logger
	{
		return new class extends \MailSo\Log\Logger {
			public function __construct() { parent::__construct(false); }
			public function Write(string $sDesc, int $iType = \LOG_INFO, string $sName = '', bool $bDiplayCrLf = false) : bool { return true; }
		};
	}
}

$dir = APP_PRIVATE_DATA.'storage';
$storage = new \Tachyon\Providers\Storage\FileStorage($dir);
$account = (new ReflectionClass(\Tachyon\Model\MainAccount::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(\Tachyon\Model\Account::class, 'sEmail'))->setValue($account, 'user@example.com');

check(true === $storage->Put($account, StorageType::CONFIG, 'two_factor', '{"User":"user@example.com"}'), 'cannot write the record');
$file = $storage->GenerateFilePath($account, StorageType::CONFIG).'two_factor';
check(is_file($file), "the record is not at {$file}");
chmod($file, 0);
check('' === @$storage->Get($account, StorageType::CONFIG, 'two_factor'), 'an unreadable file reads as missing');
check('x' === $storage->Get($account, StorageType::CONFIG, 'other', 'x'), 'a missing file no longer gets the default');

$actions = (new ReflectionClass(\Tachyon\Actions::class))->newInstanceWithoutConstructor();
$manager = (new ReflectionClass(\Tachyon\Plugins\Manager::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(\Tachyon\Plugins\Manager::class, 'oActions'))->setValue($manager, $actions);
(new ReflectionProperty(\Tachyon\Actions::class, 'aCurrentActionParams'))->setValue($actions, ['totp_code' => '']);
$plugin = new TestTwoFactor();
(new ReflectionProperty(\Tachyon\Plugins\AbstractPlugin::class, 'oPluginManager'))->setValue($plugin, $manager);
$plugin->SetPluginConfig(new \Tachyon\Config\Plugin('two-factor-auth', $plugin->configMapping()));
$plugin->store = $storage;
try {
	@$plugin->DoLogin($account);
	$refused = false;
} catch (\Tachyon\Exceptions\ClientException $e) {
	$refused = \Tachyon\Notifications::AuthError === $e->getCode();
}
chmod($file, 0600);
check($refused, 'an unreadable 2FA record lets the password alone in');

echo "storage-unreadable: ok\n";
