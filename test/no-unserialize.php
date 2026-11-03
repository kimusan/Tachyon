<?php

// Run with: php test/no-unserialize.php
define('APP_VERSION', 'test');
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
require_once dirname(__DIR__).'/plugins/two-factor-auth/providers/interface.php';

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

// Stored data is not unserialized anywhere any more.
foreach (['plugins/two-factor-auth/index.php', 'tachyon/v/0.0.0/app/libraries/tachyon_util/upgrade.php'] as $path) {
	check(!preg_match('/unserialize\s*\(\s*\$/', file_get_contents(dirname(__DIR__).'/'.$path)), "unserialize() still called in {$path}");
}

class MemoryStorage implements \Tachyon\Providers\Storage\IStorage
{
	public array $data = [];
	public function Put($mAccount, int $iStorageType, string $sKey, string $sValue) : bool { $this->data[$sKey] = $sValue; return true; }
	public function Get($mAccount, int $iStorageType, string $sKey, $mDefault = false) { return $this->data[$sKey] ?? $mDefault; }
	public function Clear($mAccount, int $iStorageType, string $sKey) : bool { unset($this->data[$sKey]); return true; }
	public function IsLocal() : bool { return true; }
}
class TestTwoFactor extends TwoFactorAuthPlugin
{
	public MemoryStorage $store;
	protected function StorageProvider() : \Tachyon\Providers\Storage { return new \Tachyon\Providers\Storage($this->store); }
	protected function TwoFactorAuthProvider(\Tachyon\Model\MainAccount $oAccount) : ?\TwoFactorAuthInterface
	{
		return new class implements \TwoFactorAuthInterface {
			public function Label() : string { return 'test'; }
			public function VerifyCode(string $sSecret, string $sCode) : bool { return 'SECRET' === $sSecret && '123456' === $sCode; }
			public function CreateSecret() : string { return 'SECRET'; }
		};
	}
	protected function Logger() : \MailSo\Log\Logger { return new \MailSo\Log\Logger(false); }
}

$plugin = (new ReflectionClass(TestTwoFactor::class))->newInstanceWithoutConstructor();
$plugin->store = new MemoryStorage;
$actions = (new ReflectionClass(\Tachyon\Actions::class))->newInstanceWithoutConstructor();
$manager = (new ReflectionClass(\Tachyon\Plugins\Manager::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(\Tachyon\Plugins\Manager::class, 'oActions'))->setValue($manager, $actions);
(new ReflectionProperty(\Tachyon\Plugins\AbstractPlugin::class, 'oPluginManager'))->setValue($plugin, $manager);
(new ReflectionProperty(\Tachyon\Actions::class, 'aCurrentActionParams'))->setValue($actions, []);
$account = (new ReflectionClass(\Tachyon\Model\MainAccount::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(\Tachyon\Model\Account::class, 'sEmail'))->setValue($account, 'user@example.com');
define('APP_SALT', 'test-salt');

// Whether a login with the right password but no code gets through.
$passes = function () use ($plugin, $account) : bool {
	try {
		$plugin->DoLogin($account);
		return true;
	} catch (\Tachyon\Exceptions\ClientException $e) {
		return false;
	}
};

$record = ['User' => 'user@example.com', 'IsSet' => true, 'Enable' => true, 'Secret' => 'SECRET'];

// No record: nothing to ask for.
$plugin->store->data = [];
check($passes(), 'login refused although no second factor is set up');

// A readable record: the code is required.
$plugin->store->data = ['two_factor' => json_encode($record)];
check(!$passes(), 'login without a code allowed for an enrolled account');

// An old serialized record is no longer read, and must not count as "no second
// factor": that would let the password alone in.
$plugin->store->data = ['two_factor' => serialize($record)];
check(!$passes(), 'unreadable (serialized) record let the login through without a code');

// Damaged data too.
$plugin->store->data = ['two_factor' => 'User: garbage'];
check(!$passes(), 'unreadable (damaged) record let the login through without a code');

// upgrade.php: empty input is an empty array, not a TypeError from return ''.
$decode = new ReflectionMethod(\Tachyon\Util\Upgrade::class, 'DecodeKeyValues');
check([] === $decode->invoke(null, ''), 'Upgrade::DecodeKeyValues(\'\') is not an empty array');

echo "no-unserialize: ok\n";
