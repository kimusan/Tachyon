<?php

namespace Tachyon;

class Utils
{
	const
		/**
		 * 30 days cookie
		 * Used by: ServiceProxyExternal, compileLogParams, GetCsrfToken
		 */
		CONNECTION_TOKEN = 'smtoken',

		/**
		 * Session cookie
		 * Used by: EncodeKeyValuesQ, DecodeKeyValuesQ
		 */
		SESSION_TOKEN = 'smsession';

	/**
	 * @param mixed $value
	 * @param int $flags Bitmask
	 */
	public static function jsonEncode($value, int $flags = \JSON_INVALID_UTF8_SUBSTITUTE) : string
	{
		try {
			/* Issue with \Tachyon\Util\HTTP\Stream
			if (Api::Config()->Get('debug', 'enable', false)) {
				$flags |= \JSON_PRETTY_PRINT;
			}
			*/
			return \json_encode($value, $flags | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
		} catch (\Throwable $e) {
			Api::Logger()->WriteException($e, \LOG_ERR, 'JSON');
		}
		return '';
	}

	public static function EncodeKeyValuesQ(array $aValues, string $sCustomKey = '') : string
	{
		return \Tachyon\Util\Crypt::EncryptUrlSafe(
			$aValues,
			\sha1(APP_SALT.$sCustomKey.'Q'.static::GetSessionToken())
		);
	}

	/**
	 * Only used for cookies (smadmin, the mailto token), so the token comes
	 * from the client: sodium only, where available (see Crypt::Decrypt()).
	 */
	public static function DecodeKeyValuesQ(string $sEncodedValues, string $sCustomKey = '') : array
	{
		return \Tachyon\Util\Crypt::DecryptUrlSafe(
			$sEncodedValues,
			\sha1(APP_SALT.$sCustomKey.'Q'.static::GetSessionToken(false)),
			true
		) ?: array();
	}

	public static function GetSessionToken(bool $generate = true) : ?string
	{
		$sToken = \Tachyon\Util\Cookies::get(self::SESSION_TOKEN);
		if (!$sToken) {
			if (!$generate) {
				return null;
			}
			\Tachyon\Util\Log::debug('TOKENS', 'New SESSION_TOKEN');
			$sToken = \MailSo\Base\Utils::Sha1Rand(APP_SALT);
			\Tachyon\Util\Cookies::set(self::SESSION_TOKEN, $sToken);
		}
		return \sha1('Session'.APP_SALT.$sToken.'Token'.APP_SALT);
	}

	/**
	 * Start a new session cookie, at login: the login cookies' key hangs on
	 * it (Cookies::setSecure()), so one that existed before the login, made by
	 * the mailto flow or planted, must not carry on into the session. The
	 * cookies encrypted with the session token are carried over: the mailto
	 * token, which is read after the login, and an admin panel session open
	 * in the same browser. The earlier session's server-side record is
	 * cleared, so the earlier session ends here too.
	 */
	public static function RotateSessionToken(\Tachyon\Providers\Storage $oStorage) : void
	{
		// A login while an earlier session is still valid ends that session
		// now. Its record would otherwise stay until GC, and FileStorage::Get()
		// touches it on every read, so a copy of the old cookies would keep it
		// alive. The old login cookie names the account the record lives
		// under, which need not be the account logging in now.
		$sOldToken = static::GetSessionToken(false);
		if ($sOldToken) {
			// Best effort: an unreadable old login cookie (a split cookie that
			// lost a part, say) must not stop the login, which replaces it anyway
			try {
				$aOld = \Tachyon\Util\Cookies::getSecure(Actions::AUTH_SPEC_TOKEN_KEY);
				$sOldEmail = \is_array($aOld) ? ($aOld['email'] ?? null) : null;
				if (\is_string($sOldEmail) && \strlen($sOldEmail)) {
					$oStorage->Clear($sOldEmail, \Tachyon\Providers\Storage\Enumerations\StorageType::SESSION, $sOldToken);
				}
			} catch (\Throwable $e) {
				\Tachyon\Util\Log::warning('TOKENS', 'Could not end the earlier session: ' . $e->getMessage());
			}
		}

		$aCarry = [];
		foreach ([Actions::AUTH_MAILTO_TOKEN_KEY, 'smadmin'] as $sName) {
			$sValue = \Tachyon\Util\Cookies::get($sName);
			if (null !== $sValue && ($aValues = static::DecodeKeyValuesQ($sValue))) {
				$aCarry[$sName] = $aValues;
			}
		}
		\Tachyon\Util\Log::debug('TOKENS', 'New SESSION_TOKEN (login)');
		\Tachyon\Util\Cookies::set(self::SESSION_TOKEN, \MailSo\Base\Utils::Sha1Rand(APP_SALT));
		foreach ($aCarry as $sName => $aValues) {
			\Tachyon\Util\Cookies::set($sName, static::EncodeKeyValuesQ($aValues));
		}
	}

	public static function GetConnectionToken() : string
	{
		$oActions = \Tachyon\Api::Actions();
		$oAccount = $oActions->getAccountFromToken(false);
//		$oAccount = $oActions->getMainAccountFromToken(false);
		if ($oAccount) {
			if ($oAccount instanceof \Tachyon\Model\AdditionalAccount) {
				return '2-' . \sha1(APP_SALT.$oAccount->Hash());
			}
			return '1-' . \sha1(APP_SALT.$oAccount->Hash());
		}
		return self::browserToken();
	}

	private static function browserToken() : string
	{
		$sToken = \Tachyon\Util\Cookies::get(self::CONNECTION_TOKEN);
		if (!$sToken) {
			$sToken = \MailSo\Base\Utils::Sha1Rand(APP_SALT);
			\Tachyon\Util\Cookies::set(self::CONNECTION_TOKEN, $sToken, \time() + 3600 * 24 * 30);
		}
		return '0-' . \sha1('Connection'.APP_SALT.$sToken.'Token'.APP_SALT);
	}

	/**
	 * The admin panel's token leaves out the mail account. It shares the cookies
	 * of a mail tab in the same browser, so logging in or out there, a session
	 * ending, or a switch to another account changed the admin panel's token
	 * under it, and its next request failed with InvalidToken (#212).
	 */
	public static function GetCsrfToken(bool $bAdmin = false) : string
	{
		return $bAdmin ? self::browserToken() : self::GetConnectionToken();
//		return \sha1('Csrf'.APP_SALT.self::GetConnectionToken().'Token'.APP_SALT);
	}

	public static function UpdateConnectionToken() : void
	{
		$sToken = \Tachyon\Util\Cookies::get(self::CONNECTION_TOKEN);
		if ($sToken) {
			\Tachyon\Util\Cookies::set(self::CONNECTION_TOKEN, $sToken, \time() + 3600 * 24 * 30);
		}
	}

	public static function ClearHtmlOutput(string $sHtml) : string
	{
//		return $sHtml;
		return \preg_replace('/>\\s+</', '> <', \preg_replace(
			['@\\s*/>@', '/\\s*&nbsp;/i', '/&nbsp;\\s*/i', '/[\\r\\n\\t]+/'],
			['>', "\xC2\xA0", "\xC2\xA0", ' '],
			\trim($sHtml)
		));
	}

	public static function WebPath() : string
	{
		static $sAppPath;
		if (!$sAppPath) {
			$sAppPath = \rtrim(Api::Config()->Get('webmail', 'app_path', '')
				?: \preg_replace('#index\\.php.*$#D', '', $_SERVER['SCRIPT_NAME']),
			'/') . '/';
		}
		return $sAppPath;
	}

	public static function WebVersionPath() : string
	{
		return self::WebPath() . 'tachyon/v/' . APP_VERSION . '/';
		/**
		 * TODO: solve this to support other paths.
		 * https://github.com/the-djmaze/snappymail/issues/685
		 */
//		return self::WebPath() . \str_replace(APP_INDEX_ROOT_PATH, '', APP_VERSION_ROOT_PATH);
	}

	public static function WebStaticPath(string $path = '') : string
	{
		return self::WebVersionPath() . 'static/' . $path;
	}

	public static function inOpenBasedir(string $name) : string
	{
		static $open_basedir;
		if (null === $open_basedir) {
			$open_basedir = \array_filter(\explode(PATH_SEPARATOR, \ini_get('open_basedir')));
		}
		if ($open_basedir) {
			foreach ($open_basedir as $dir) {
				if (\str_starts_with($name, $dir)) {
					return true;
				}
			}
//			\Tachyon\Util\Log::warning('OpenBasedir', "open_basedir restriction in effect. {$name} is not within the allowed path(s): " . \ini_get('open_basedir'));
			return false;
		}
		return true;
	}

	/**
	 * $atomic writes beside the file and renames it into place, so a reader never
	 * sees it half written: file_put_contents() empties the file first. A rename
	 * cannot replace a file mounted on its own, which is how Docker setups often
	 * provide application.ini, so when the rename is refused the file is written
	 * in place after all.
	 */
	public static function saveFile(string $filename, string $data, bool $atomic = false) : void
	{
		$dir = \dirname($filename);
		if (!\is_dir($dir) && !\mkdir($dir, 0700, true)) {
			throw new \RuntimeException('Failed to create directory "'.$dir.'"');
		}
		$target = $filename;
		if ($atomic) {
			$filename .= '.' . \bin2hex(\random_bytes(6)) . '.tmp';
		}
		if (false === \file_put_contents($filename, $data)) {
			\is_file($filename) && $atomic && \unlink($filename);
			throw new \RuntimeException('Failed to save file "'.$target.'"');
		}
		\clearstatcache();
		\chmod($filename, 0600);
		if ($atomic && !@\rename($filename, $target)) {
			\unlink($filename);
			if (false === \file_put_contents($target, $data)) {
				throw new \RuntimeException('Failed to save file "'.$target.'"');
			}
		}
/*
		try {
		} catch (\Throwable $oException) {
			throw new \RuntimeException($oException->getMessage() . ': ' . \error_get_last()['message']);
		}
*/
	}
}
