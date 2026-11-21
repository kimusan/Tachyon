<?php

// Run with: php test/cryptkey-rotation.php
// A CryptKey from before Argon2id sealing is sha1(IMAP password . APP_SALT),
// so whatever it encrypts lets anyone with the data folder test password
// guesses cheaply. Opening such a seal replaces the key with a random one and
// re-encrypts everything under it; afterwards nothing matches the old key.
// The stored formats below are the ones core writes (see the comments).

namespace Tachyon\Util {
	abstract class Log
	{
		public static array $lines = [];
		public static function __callStatic(string $name, array $arguments) : void { static::$lines[] = $arguments; }
	}
}

namespace Tachyon {
	abstract class Api
	{
		public static ?object $storage = null;
		public static array $hooks = [];
		public static function Config() : object
		{
			return new class {
				public function Get(string $section, string $name, $default = null) { return $default; }
			};
		}
		public static function Actions() : object
		{
			return new class {
				public function StorageProvider() : object { return Api::$storage; }
				public function logMask(string $s) : void {}
				public function Plugins() : object
				{
					return new class {
						public function RunHook(string $sName, array $aArgs = []) : object { Api::$hooks[] = [$sName, $aArgs]; return $this; }
					};
				}
			};
		}
	}
}

namespace {
	define('APP_SALT', 'test-salt-'.bin2hex(random_bytes(8)));
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

	function check(bool $condition, string $message): void
	{
		if (!$condition) {
			throw new RuntimeException($message);
		}
	}

	class MemoryStorage
	{
		public array $data = [];
		public ?string $fail = null;
		public string $pgp;
		public function Put($mAccount, int $iType, string $sKey, string $sValue) : bool
		{
			if ($sKey === $this->fail) {
				return false;
			}
			$this->data[$sKey] = $sValue;
			return true;
		}
		public function Get($mAccount, int $iType, string $sKey, $mDefault = false) { return $this->data[$sKey] ?? $mDefault; }
		public function GenerateFilePath($mAccount, int $iType) : string { return $this->pgp; }
	}
	\Tachyon\Api::$storage = $storage = new MemoryStorage;
	$storage->pgp = sys_get_temp_dir().'/cryptkey-rotation-'.getmypid().'/';
	mkdir($storage->pgp, 0700, true);
	register_shutdown_function(fn() => array_map('unlink', glob($storage->pgp.'*') ?: []) && rmdir($storage->pgp));

	function account() : \Tachyon\Model\MainAccount
	{
		$o = (new ReflectionClass(\Tachyon\Model\MainAccount::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(\Tachyon\Model\Account::class, 'sEmail'))->setValue($o, 'user@example.com');
		(new ReflectionProperty(\Tachyon\Model\Account::class, 'oImapPass'))->setValue($o, new \Tachyon\Util\SensitiveString('upass'));
		return $o;
	}

	$Crypt = 'Tachyon\\Util\\Crypt';
	$hmac = fn(string $s, string $k) => hash_hmac('sha1', $s, $k);

	// The user's data as an install before this change left it
	$K0 = hex2bin($sK0 = sha1('upass'.APP_SALT));
	$storage->data['.cryptkey'] = $Crypt::EncryptToJSON($sK0, 'upass');
	// AdditionalAccount::asTokenArray()
	$sPass = $Crypt::EncryptUrlSafe('other-imap', $K0);
	$sSmtp = $Crypt::EncryptUrlSafe('other-smtp', $K0);
	$sStale = $Crypt::EncryptUrlSafe('stale', 'some-earlier-key');
	$storage->data['additionalaccounts'] = json_encode([
		'other@example.com' => ['email' => 'other@example.com', 'login' => 'other', 'pass' => $sPass,
			'smtp' => ['user' => 'o', 'pass' => $sSmtp], 'hmac' => $hmac($sPass, $K0)],
		// Upgrade::ConvertInsecureAccounts() without a token: empty password
		'empty@example.com' => ['email' => 'empty@example.com', 'login' => 'empty@example.com', 'pass' => '', 'hmac' => $hmac('', $K0)],
		// Under a key from before a password change that was never resealed
		'stale@example.com' => ['email' => 'stale@example.com', 'login' => 'stale', 'pass' => $sStale, 'hmac' => $hmac($sStale, 'some-earlier-key')],
	]);
	// Actions\Contacts::setContactsSyncData() and Actions\Calendar::setCalendarSyncData()
	foreach (['contacts_sync' => 'carddav-pass', 'calendar_sync' => 'caldav-pass'] as $sKey => $sSecret) {
		$sEnc = $Crypt::EncryptToJSON($sSecret, $K0);
		$storage->data[$sKey] = json_encode(['Mode' => 1, 'Url' => 'https://dav.example.com/', 'User' => 'user',
			'Password' => $sEnc, 'PasswordHMAC' => $hmac($sEnc, $K0)]);
	}
	// Util\PGP\Backup::PGPKey()
	$aKey = $Crypt::Encrypt('-----BEGIN PGP PRIVATE KEY BLOCK-----', $K0);
	$aKey[1] = base64_encode($aKey[1]);
	$aKey[2] = base64_encode($aKey[2]);
	$aKey[3] = $hmac($aKey[2], $K0);
	file_put_contents($storage->pgp.'0xABCD.key', json_encode($aKey));
	file_put_contents($storage->pgp.'0xABCD_public.asc', 'public');

	// A failure on the way: the new key is in use, the old one stays pending
	$storage->fail = 'calendar_sync';
	$K1 = (string) account()->CryptKey();
	check(32 === strlen($K1) && $K0 !== $K1, 'password-derived key not replaced');
	\Tachyon\Model\MainAccount::unsealCryptKey($storage->data['.cryptkey'], 'upass', $b, $aOld);
	check([$sK0] === $aOld, 'old key dropped although the rotation failed');
	check(!empty(\Tachyon\Util\Log::$lines), 'failed rotation not logged');

	// The next request finishes it, with the same new key
	$storage->fail = null;
	\Tachyon\Api::$hooks = [];
	check($K1 === (string) account()->CryptKey(), 'retry made yet another key');
	\Tachyon\Model\MainAccount::unsealCryptKey($storage->data['.cryptkey'], 'upass', $b, $aOld);
	check([] === $aOld, 'old key still pending after a complete rotation');
	check(1 === count(\Tachyon\Api::$hooks) && 'main.cryptkey-rotate' === \Tachyon\Api::$hooks[0][0]
		&& $K0 === \Tachyon\Api::$hooks[0][1][1] && $K1 === \Tachyon\Api::$hooks[0][1][2],
		'plugins not given the old and new key');

	// Everything reads with the new key, and nothing matches the old one
	$aAccounts = json_decode($storage->data['additionalaccounts'], true);
	$a = $aAccounts['other@example.com'];
	check($a['hmac'] === $hmac($a['pass'], $K1) && 'other-imap' === $Crypt::DecryptUrlSafe($a['pass'], $K1), 'additional account password not re-encrypted');
	check('other-smtp' === $Crypt::DecryptUrlSafe($a['smtp']['pass'], $K1), 'additional account SMTP password not re-encrypted');
	check(null === $Crypt::DecryptUrlSafe($a['pass'], $K0), 'additional account password still under the old key');
	check($aAccounts['empty@example.com']['hmac'] === $hmac('', $K1), 'empty password HMAC not renewed');
	check($sStale === $aAccounts['stale@example.com']['pass'], 'data under neither key was touched');
	foreach (['contacts_sync' => 'carddav-pass', 'calendar_sync' => 'caldav-pass'] as $sKey => $sSecret) {
		$aData = json_decode($storage->data[$sKey], true);
		check($aData['PasswordHMAC'] === $hmac($aData['Password'], $K1) && $sSecret === $Crypt::DecryptFromJSON($aData['Password'], $K1),
			"{$sKey} not re-encrypted");
		check($aData['PasswordHMAC'] !== $hmac($aData['Password'], $K0), "{$sKey} still matches the old key");
		check('https://dav.example.com/' === $aData['Url'], "{$sKey} lost its other settings");
	}
	$aKey = json_decode(file_get_contents($storage->pgp.'0xABCD.key'));
	$mac = array_pop($aKey);
	check($mac === $hmac($aKey[2], $K1), 'PGP backup HMAC not renewed');
	check('-----BEGIN PGP PRIVATE KEY BLOCK-----' === $Crypt::Decrypt([$aKey[0], base64_decode($aKey[1]), base64_decode($aKey[2])], $K1),
		'PGP backup not re-encrypted');
	check('public' === file_get_contents($storage->pgp.'0xABCD_public.asc'), 'public key touched');

	// Running it again changes nothing
	$aBefore = $storage->data;
	$sPgp = file_get_contents($storage->pgp.'0xABCD.key');
	\Tachyon\Model\CryptKeyRotation::run(account(), $K0, $K1);
	check($aBefore === $storage->data && $sPgp === file_get_contents($storage->pgp.'0xABCD.key'), 'second run changed data');

	echo "cryptkey-rotation: ok\n";
}
