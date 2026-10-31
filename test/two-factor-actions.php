<?php

// Run with: php test/two-factor-actions.php
// The plugin's actions and login hook, through the real plugin class and the real
// Tachyon classes; only storage, logger, session account and auth log are replaced.
define('APP_VERSION', 'test');
define('APP_SALT', 'salt-of-this-install');
define('APP_PRIVATE_DATA', sys_get_temp_dir().'/two-factor-actions-'.getmypid().'/');
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

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

class MemoryStorage implements \Tachyon\Providers\Storage\IStorage
{
	public array $data = [];
	public function Put($mAccount, int $iStorageType, string $sKey, string $sValue) : bool { $this->data[$mAccount->Email()] = $sValue; return true; }
	public function Get($mAccount, int $iStorageType, string $sKey, $mDefault = false) { return $this->data[$mAccount->Email()] ?? $mDefault; }
	public function Clear($mAccount, int $iStorageType, string $sKey) : bool { unset($this->data[$mAccount->Email()]); return true; }
	public function IsLocal() : bool { return true; }
}

class TestTwoFactor extends TwoFactorAuthPlugin
{
	public MemoryStorage $store;
	public \Tachyon\Model\MainAccount $account;
	public array $log = [];
	public int $authFailures = 0;
	protected function StorageProvider() : \Tachyon\Providers\Storage { return new \Tachyon\Providers\Storage($this->store); }
	protected function getMainAccountFromToken() : \Tachyon\Model\MainAccount { return $this->account; }
	protected function logAuthFailure(\Tachyon\Model\MainAccount $oAccount) : void { ++$this->authFailures; }
	protected function Logger() : \MailSo\Log\Logger
	{
		return new class ($this->log) extends \MailSo\Log\Logger {
			public function __construct(private array &$lines) { parent::__construct(false); }
			public function Write(string $sDesc, int $iType = \LOG_INFO, string $sName = '', bool $bDiplayCrLf = false) : bool { $this->lines[] = $sDesc; return true; }
		};
	}
}

$account = (new ReflectionClass(\Tachyon\Model\MainAccount::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(\Tachyon\Model\Account::class, 'sEmail'))->setValue($account, 'user@example.com');

$actions = (new ReflectionClass(\Tachyon\Actions::class))->newInstanceWithoutConstructor();
$manager = (new ReflectionClass(\Tachyon\Plugins\Manager::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(\Tachyon\Plugins\Manager::class, 'oActions'))->setValue($manager, $actions);
$params = new ReflectionProperty(\Tachyon\Actions::class, 'aCurrentActionParams');

$plugin = new TestTwoFactor();
(new ReflectionProperty(\Tachyon\Plugins\AbstractPlugin::class, 'oPluginManager'))->setValue($plugin, $manager);
$config = new \Tachyon\Config\Plugin('two-factor-auth', $plugin->configMapping());
$config->Set('plugin', 'otp_issuer', 'Webmail');
$plugin->SetPluginConfig($config);
$plugin->store = new MemoryStorage;
$plugin->account = $account;

// Calls an action with these parameters: its Result, or 'refused' when it throws.
$call = function (string $action, array $values = []) use ($plugin, $actions, $params) {
	$params->setValue($actions, $values);
	try {
		return $plugin->{$action}()['Result'];
	} catch (\Tachyon\Exceptions\ClientException $e) {
		return 'refused';
	}
};
// Logs in with this code: '' when accepted, else the exception's additional message.
$login = function (string $code) use ($plugin, $actions, $params, $account) {
	$params->setValue($actions, ['totp_code' => $code]);
	try {
		$plugin->DoLogin($account);
		return true;
	} catch (\Tachyon\Exceptions\ClientException $e) {
		check(\Tachyon\Notifications::AuthError === $e->getCode(), 'a refused login is not an AuthError');
		return $e->getAdditionalMessage() ?: 'refused';
	}
};
$code = fn (string $secret, int $offset = 0) => TwoFactorAuthTotpSlice::code($secret, intdiv(time(), 30) + $offset);

// Enrolment.
$created = $call('DoCreateTwoFactorSecret');
$secret = $created['Secret'];
$backup = explode(' ', $created['BackupCodes']);
check(strlen($secret) >= 16, 'no secret on creation');
check(8 === count($backup), 'not eight backup codes on creation');
check(str_starts_with($created['QRCode'], 'data:image/svg+xml;base64,'), 'the QR code is not an image');
check(str_contains(base64_decode(substr($created['QRCode'], 26)), '<svg'), 'the QR code is not an SVG');
$stored = $plugin->store->data['user@example.com'];
check(!str_contains($stored, $secret), 'the secret is stored in clear');
check(!str_contains($stored, $backup[0]), 'a backup code is stored in clear');
check(['User', 'IsSet', 'Enable', 'Tested'] === array_keys($call('DoGetTwoFactorInfo')), 'GetTwoFactorInfo returns more than the state');

check(false === $call('DoEnableTwoFactor', ['Enable' => '1']), 'enabled before a code was tested');
check(false === $call('DoVerifyTwoFactorCode', ['Code' => '000000']), 'a wrong test code is accepted');
check(true === $call('DoVerifyTwoFactorCode', ['Code' => $code($secret, -1)]), 'a valid test code is refused');
check(true === $call('DoEnableTwoFactor', ['Enable' => '1']), 'not enabled after a tested code');
check(true === $call('DoGetTwoFactorInfo')['Enable'], 'not shown as enabled');

// While enabled, changes need a current code (4ac77a337), now replay-guarded.
check('refused' === $call('DoEnableTwoFactor', ['Enable' => '0']), 'switched off without a code');
check('refused' === $call('DoClearTwoFactorInfo'), 'cleared without a code');
check('refused' === $call('DoShowTwoFactorSecret'), 'secret shown without a code');
check('refused' === $call('DoCreateTwoFactorSecret'), 'secret replaced without a code');
check('refused' === $call('DoShowTwoFactorSecret', ['Code' => $code($secret, -1)]), 'secret shown for the code already used to test');
check(true === $call('DoGetTwoFactorInfo')['Enable'], 'no longer enabled after refused changes');

// Login.
check(TwoFactorAuthPlugin::CODE_REQUIRED === $login(''), 'a missing code is not named');
check('refused' === $login('000000'), 'a wrong code logs in, or is named a missing one');
check(true === $login($code($secret)), 'a valid code is refused at login');
check(in_array('TFA: Code verified for user@example.com', $plugin->log, true), 'a verified login is not logged');
check('refused' === $login($code($secret)), 'the same code logs in twice');
check(in_array('TFA: replay code for user@example.com', $plugin->log, true), 'the replay is not named in the log');
check(2 === $plugin->authFailures, 'the wrong code and the replay did not both reach the auth log');
check(true === $login($backup[0]), 'a backup code is refused at login');
check('refused' === $login($backup[0]), 'a backup code logs in twice');

// Disabling with a code works, and spends it.
check(true === $call('DoEnableTwoFactor', ['Enable' => '0', 'Code' => $code($secret, 1)]), 'not switched off with a valid code');
check(false === $call('DoGetTwoFactorInfo')['Enable'], 'still enabled');
check(true === $login(''), 'a code is still required once switched off');

// Lockout: five failures, then even a valid backup code is refused.
check(true === $call('DoEnableTwoFactor', ['Enable' => '1']), 'cannot switch back on');
for ($i = 0; $i < 5; ++$i) {
	$login('12345'.$i);
}
check('refused' === $login($backup[1]), 'a valid backup code goes through the lock');
check(in_array('TFA: locked code for user@example.com', $plugin->log, true), 'the lock is not named in the log');

// A record stored by 2.19.1, in clear JSON, still logs in and is sealed on first read.
$plugin->store->data['user@example.com'] = json_encode(['User' => 'user@example.com', 'Enable' => true,
	'Secret' => 'JBSWY3DPEHPK3PXPJBSWY3DP', 'BackupCodes' => '123456789 987654321']);
check(true === $call('DoGetTwoFactorInfo')['Enable'], 'an old enabled record reads as disabled');
$stored = $plugin->store->data['user@example.com'];
check(!str_contains($stored, 'JBSWY3DPEHPK3PXPJBSWY3DP') && !str_contains($stored, '987654321'), 'an old record is not sealed on first read');
check(true === $login($code('JBSWY3DPEHPK3PXPJBSWY3DP')), 'an old secret no longer logs in');
check(true === $login('987654321'), 'an old backup code no longer logs in');

// Older still: xxtea-wrapped serialized data is no longer read at all, and is
// refused rather than taken for "no second factor".
$plugin->store->data['user@example.com'] = \MailSo\Base\Utils::UrlSafeBase64Encode(\MailSo\Base\Xxtea::encrypt(
	serialize(['User' => 'user@example.com', 'Enable' => true, 'Secret' => 'JBSWY3DPEHPK3PXPJBSWY3DP', 'BackupCodes' => '']), md5(APP_SALT)));
check('refused' === $call('DoGetTwoFactorInfo'), 'a serialized record is not refused');
check(true !== $login(''), 'a serialized record let the login through without a code');
check((bool) preg_grep('/unreadable record .*CONFIG key two_factor/', $plugin->log), 'the refusal does not say where the record lives');
// With 2FA forced and a session already open, the app data still loads.
$config->Set('plugin', 'force_two_factor_auth', true);
$appData = ['Auth' => true];
$plugin->FilterAppData(false, $appData);
check(true === $appData['RequireTwoFactor'] && false === $appData['SetupTwoFactor'], 'app data with an unreadable record');
$config->Set('plugin', 'force_two_factor_auth', false);
// Switched off, but damaged: still refused, since nothing says it was off.
$plugin->store->data['user@example.com'] = 'damaged';
check(true !== $login(''), 'a damaged record let the login through');
// Present but unusable in other ways: empty (a truncated file), '0', no secret, another user's.
foreach (['', '0', json_encode(['User' => 'user@example.com', 'Enable' => true]),
	json_encode(['User' => 'other@example.com', 'Enable' => true, 'Secret' => 'JBSWY3DPEHPK3PXPJBSWY3DP'])] as $sRecord) {
	$plugin->store->data['user@example.com'] = $sRecord;
	check(true !== $login(''), "record '$sRecord' let the login through");
	check('refused' === $call('DoClearTwoFactorInfo'), "record '$sRecord' was cleared without a code");
}
class WakeProbe { public static bool $woken = false; public function __wakeup() { self::$woken = true; } }
$plugin->store->data['user@example.com'] = \MailSo\Base\Utils::UrlSafeBase64Encode(\MailSo\Base\Xxtea::encrypt(
	serialize(['User' => 'user@example.com', 'Enable' => true, 'Secret' => 'JBSWY3DPEHPK3PXPJBSWY3DP', 'x' => new WakeProbe]), md5(APP_SALT)));
$call('DoGetTwoFactorInfo');
check(!WakeProbe::$woken, 'an object in stored data is instantiated');

// Clearing with a code removes it.
$plugin->store->data = [];
$created = $call('DoCreateTwoFactorSecret');
$call('DoVerifyTwoFactorCode', ['Code' => $code($created['Secret'], -1)]);
$call('DoEnableTwoFactor', ['Enable' => '1']);
check('refused' === $call('DoClearTwoFactorInfo', ['Code' => $code($created['Secret'], -1)]), 'cleared with the code already used');
check(false === $call('DoClearTwoFactorInfo', ['Code' => $code($created['Secret'])])['IsSet'], 'not cleared with a valid code');

echo "two-factor-actions: ok\n";
