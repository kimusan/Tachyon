<?php

// Run with: php test/cryptkey-seal.php
// .cryptkey is sealed with Argon2id instead of sha1(password . APP_SALT), new
// CryptKeys are random instead of derived from the password, and old seals
// are opened and their (password-derived) key is replaced by a random one.
// What the keys encrypt is covered by test/cryptkey-rotation.php.

namespace Tachyon\Util {
	abstract class Log
	{
		public static function __callStatic(string $name, array $arguments) : void {}
	}
}

namespace Tachyon {
	abstract class Api
	{
		public static array $config = [];
		public static ?object $storage = null;
		public static array $hooks = [];
		public static function Config() : object
		{
			return new class {
				public function Get(string $section, string $name, $default = null) {
					return Api::$config["{$section}.{$name}"] ?? $default;
				}
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
						public function RunHook(string $sName, array $aArgs = []) : object { Api::$hooks[] = $sName; return $this; }
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

	// Per account .cryptkey; $race makes the first Get after a Put return
	// what "another request" wrote meanwhile.
	class MemoryStorage
	{
		public array $data = [];
		public ?string $race = null;
		public int $puts = 0;
		public function Put($mAccount, int $iType, string $sKey, string $sValue) : bool
		{
			++$this->puts;
			$this->data[$this->k($mAccount, $sKey)] = $sValue;
			if (null !== $this->race) {
				$this->data[$this->k($mAccount, $sKey)] = $this->race;
				$this->race = null;
			}
			return true;
		}
		public function GenerateFilePath($mAccount, int $iType) : string { return ''; }
		public function Get($mAccount, int $iType, string $sKey, $mDefault = false)
		{
			return $this->data[$this->k($mAccount, $sKey)] ?? $mDefault;
		}
		private function k($mAccount, string $sKey) : string
		{
			return (\is_string($mAccount) ? $mAccount : $mAccount->Email()) . "/{$sKey}";
		}
	}
	\Tachyon\Api::$storage = $storage = new MemoryStorage;

	function account(string $sEmail, string $sPass, string $sClass = \Tachyon\Model\MainAccount::class) : \Tachyon\Model\MainAccount
	{
		$o = (new ReflectionClass($sClass))->newInstanceWithoutConstructor();
		(new ReflectionProperty(\Tachyon\Model\Account::class, 'sEmail'))->setValue($o, $sEmail);
		(new ReflectionProperty(\Tachyon\Model\Account::class, 'oImapPass'))->setValue($o, new \Tachyon\Util\SensitiveString($sPass));
		return $o;
	}
	function refused(callable $f) : bool
	{
		try { $f(); } catch (\Tachyon\Exceptions\ClientException $e) { return true; }
		return false;
	}
	function seal(string $sEmail) : array
	{
		return json_decode(\Tachyon\Api::$storage->data["{$sEmail}/.cryptkey"], true);
	}

	check(is_callable('sodium_crypto_pwhash'), 'test needs sodium');
	$Main = \Tachyon\Model\MainAccount::class;

	// A new CryptKey is random and sealed with Argon2id
	$sKey = (string) account('a@example.com', 'apass')->CryptKey();
	check(32 === strlen($sKey), 'new CryptKey is not 32 random bytes');
	check($sKey !== hex2bin(sha1('apass'.APP_SALT)), 'new CryptKey still derived from the password');
	check($sKey !== (string) account('b@example.com', 'apass')->CryptKey(), 'two accounts got the same key');
	$a = seal('a@example.com');
	check(2 === $a['v'] && 'argon2id13' === $a['kdf'] && $Main::CRYPTKEY_OPSLIMIT === $a['ops']
		&& $Main::CRYPTKEY_MEMLIMIT === $a['mem'] && SODIUM_CRYPTO_PWHASH_SALTBYTES === strlen(base64_decode($a['salt'])),
		'new seal is not Argon2id with the current cost');

	// Opened again with the password, and left alone
	$iPuts = $storage->puts;
	check($sKey === (string) account('a@example.com', 'apass')->CryptKey(), 'seal does not open again');
	check($iPuts === $storage->puts, 'a current seal was written again');
	check(refused(fn() => account('a@example.com', 'wrong')->CryptKey()), 'wrong password opened the seal');

	// Old seal, sha1(password . APP_SALT): opens, and its password-derived key
	// is replaced by a random one once the data is re-encrypted
	$sOld = sha1('cpass'.APP_SALT);
	$storage->data['c@example.com/.cryptkey'] = \Tachyon\Util\Crypt::EncryptToJSON($sOld, 'cpass');
	\Tachyon\Api::$hooks = [];
	$sKeyC = (string) account('c@example.com', 'cpass')->CryptKey();
	check(32 === strlen($sKeyC) && hex2bin($sOld) !== $sKeyC, 'key of an old seal not replaced');
	check(['main.cryptkey-rotate'] === \Tachyon\Api::$hooks, 'plugins not asked to re-encrypt');
	check(2 === (seal('c@example.com')['v'] ?? 0), 'old seal not renewed');
	check($sKeyC === hex2bin($Main::unsealCryptKey($storage->data['c@example.com/.cryptkey'], 'cpass', $b, $aOld)) && [] === $aOld,
		'old key still pending after the rotation');
	check($sKeyC === (string) account('c@example.com', 'cpass')->CryptKey(), 'renewed seal lost the key');
	check(null === $Main::unsealCryptKey($storage->data['c@example.com/.cryptkey'], 'wrong'), 'renewed seal opens with a wrong password');

	// Password changed: refused until resealed with the old one
	$sKeyD = (string) account('d@example.com', 'old')->CryptKey();
	check(refused(fn() => account('d@example.com', 'new')->CryptKey()), 'seal opened with the new password before the reseal');
	check(refused(fn() => account('d@example.com', 'new')->resealCryptKey(new \Tachyon\Util\SensitiveString('wrong'))), 'reseal with a wrong old password');
	check(account('d@example.com', 'new')->resealCryptKey(new \Tachyon\Util\SensitiveString('old')), 'reseal failed');
	check($sKeyD === (string) account('d@example.com', 'new')->CryptKey(), 'reseal changed the key');
	// ... also from an old-format seal: its key is replaced as well
	$storage->data['e@example.com/.cryptkey'] = \Tachyon\Util\Crypt::EncryptToJSON(sha1('eold'.APP_SALT), 'eold');
	check(account('e@example.com', 'enew')->resealCryptKey(new \Tachyon\Util\SensitiveString('eold')), 'reseal of an old seal failed');
	check(null !== $Main::unsealCryptKey($storage->data['e@example.com/.cryptkey'], 'enew', $b, $aOld) && [sha1('eold'.APP_SALT)] === $aOld,
		'reseal of an old seal did not keep the old key for re-encryption');
	$sKeyE = (string) account('e@example.com', 'enew')->CryptKey();
	check(32 === strlen($sKeyE) && hex2bin(sha1('eold'.APP_SALT)) !== $sKeyE, 'reseal of an old seal kept the password-derived key');
	check(null !== $Main::unsealCryptKey($storage->data['e@example.com/.cryptkey'], 'enew', $b, $aOld) && [] === $aOld,
		'old key still pending after the rotation');

	// A seal with a lower cost is renewed with the current one
	eval('class CheapMainAccount extends \Tachyon\Model\MainAccount { const CRYPTKEY_OPSLIMIT = 1, CRYPTKEY_MEMLIMIT = 8192 * 1024; }');
	$sKeyF = (string) account('f@example.com', 'fpass', 'CheapMainAccount')->CryptKey();
	check(1 === seal('f@example.com')['ops'], 'cheap seal not written');
	check($sKeyF === (string) account('f@example.com', 'fpass')->CryptKey(), 'cheap seal does not open');
	check($Main::CRYPTKEY_OPSLIMIT === seal('f@example.com')['ops'], 'cheap seal not renewed');

	// A damaged seal cannot ask for absurd work
	$g = seal('f@example.com');
	$g['mem'] = 4 * 1024 * 1024 * 1024;
	check(null === $Main::unsealCryptKey(json_encode($g), 'fpass'), 'absurd memory cost accepted');
	$g['mem'] = $Main::CRYPTKEY_MEMLIMIT;
	$g['v'] = 3;
	check(null === $Main::unsealCryptKey(json_encode($g), 'fpass'), 'unknown format version accepted');

	// Two first requests at once: the stored key wins
	$sOther = $Main::sealCryptKey(bin2hex(random_bytes(32)), 'hpass');
	$storage->race = $sOther;
	$sKeyH = (string) account('h@example.com', 'hpass')->CryptKey();
	check($sKeyH === hex2bin($Main::unsealCryptKey($sOther, 'hpass')), 'key of the losing request used');

	// security.insecure_cryptkey seals with the email address
	\Tachyon\Api::$config['security.insecure_cryptkey'] = true;
	$sKeyI = (string) account('i@example.com', 'ipass')->CryptKey();
	check($sKeyI === (string) account('i@example.com', 'other')->CryptKey(), 'insecure_cryptkey does not use the email address');
	// ... so a password change has nothing to reseal (change-password calls
	// resealCryptKey() with the old password, which never opened this seal)
	$sSealI = $storage->data['i@example.com/.cryptkey'];
	check(account('i@example.com', 'inew')->resealCryptKey(new \Tachyon\Util\SensitiveString('ipass')),
		'reseal failed with insecure_cryptkey after a password change');
	check($sSealI === $storage->data['i@example.com/.cryptkey'], 'reseal rewrote a seal that needed nothing');
	check($sKeyI === (string) account('i@example.com', 'inew')->CryptKey(), 'reseal with insecure_cryptkey lost the key');
	\Tachyon\Api::$config = [];

	// Already resealed (or a password change that kept the password): the
	// current password opens the seal, so there is nothing to do, whatever
	// the old password given
	$sSealD = $storage->data['d@example.com/.cryptkey'];
	check(account('d@example.com', 'new')->resealCryptKey(new \Tachyon\Util\SensitiveString('anything')),
		'reseal of a seal the current password opens failed');
	check($sSealD === $storage->data['d@example.com/.cryptkey'], 'reseal rewrote a current seal');

	echo "cryptkey-seal: ok\n";
}
