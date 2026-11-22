<?php

namespace Tachyon\Util;

class Cookies
{
	static $DefaultPath = '';

	static $Secure = null;

	static $SameSite = 'Strict';

	/**
	 * "__Host-" names: the browser accepts such a cookie only over HTTPS, with
	 * Secure, Path=/ and no Domain, so a sibling subdomain or a plain-HTTP
	 * man in the middle cannot plant one (session fixation, login CSRF).
	 */
	static $HostPrefix = false;

	private static function init() : bool
	{
		static $bOne = false;
		if (!$bOne) {
			$oConfig = \Tachyon\Api::Config();
			static::$DefaultPath = $oConfig->Get('labs', 'cookie_default_path', '');
			static::$SameSite = $oConfig->Get('security', 'cookie_samesite', 'Strict');
			// IsSecure() also honours X-Forwarded-Proto/-Ssl, so cookies keep the
			// Secure flag behind a TLS-terminating proxy. Trusting the header is
			// safe here: a client that fakes it only gets Secure cookies over a
			// plain connection, which its own browser then does not send back.
			$bTls = \MailSo\Base\Http::SingletonInstance()->IsSecure();
			static::$Secure = $bTls
				|| 'None' == static::$SameSite
				|| !!$oConfig->Get('labs', 'cookie_default_secure', false);
			// Only where the browser will take them: over HTTPS, and with the
			// whole site as path (labs.cookie_default_path keeps plain names)
			static::$HostPrefix = $bTls && \in_array(static::$DefaultPath, ['', '/'], true);
			$bOne = true;
		}
		return $bOne;
	}

	/**
	 * The name the cookie $sName has in the browser.
	 */
	public static function name(string $sName) : string
	{
		static::init();
		return static::$HostPrefix ? "__Host-{$sName}" : $sName;
	}

	public static function has(string $sName) : bool
	{
		return isset($_COOKIE[static::name($sName)]);
	}

	public static function get(string $sName) : ?string
	{
		$sName = static::name($sName);
		if (isset($_COOKIE[$sName])) {
			$aParts = [];
			foreach (\array_keys($_COOKIE) as $sCookieName) {
				if (\strtok($sCookieName, '~') === $sName) {
					$aParts[$sCookieName] = $_COOKIE[$sCookieName];
				}
			}
			// Naturally: sorted as strings, "name~10" came before "name~2"
			\ksort($aParts, \SORT_NATURAL);
			return \implode('', $aParts);
		}
		return null;
	}

	public static function getSecure(string $sName)
	{
		$sValue = static::get($sName);
		return null === $sValue
			? null
			: Crypt::DecryptFromJSON(\MailSo\Base\Utils::UrlSafeBase64Decode($sValue), null, true);
	}

	public static function setSecure(string $sName, $data): void
	{
		if (\is_null($data)) {
			static::clear($sName);
		} else {
			// The key is bound to the session cookie (Crypt::Passphrase)
			\Tachyon\Utils::GetSessionToken();
			static::set(
				$sName,
				\MailSo\Base\Utils::UrlSafeBase64Encode(Crypt::EncryptToJSON($data))
			);
		}
	}

	private static function _set(string $sName, string $sValue, int $iExpire, bool $httponly = true) : bool
	{
		$sPath = static::$DefaultPath;
		$sPath = $sPath && \strlen($sPath) ? $sPath : '/';
/*
		if (\strlen($sValue) > 4000 - \strlen($sPath . $sName)) {
			throw new \Exception("Cookie '{$sName}' value too long");
		}
*/
		if (\strlen($sValue)) {
			$_COOKIE[$sName] = $sValue;
		} else {
			if (!isset($_COOKIE[$sName])) {
				return true;
			}
			unset($_COOKIE[$sName]);
			$iExpire = \time() - 3600 * 24 * 30;
		}

		// Cookie "$sName" has been rejected because it is already expired.
		// Happens when \setcookie() sends multiple with the same name (and one is deleted)
		// So when previously set, we must delete all 'Set-Cookie' headers and start over
		$cookies = [];
		$cookie_remove = false;
		foreach (\headers_list() as $header) {
			if (\preg_match("/Set-Cookie:([^=]+)=/i", $header, $match)) {
				if (\trim($match[1]) == $sName) {
					$cookie_remove = true;
				} else {
					$cookies[] = $header;
				}
			}
		}
		if ($cookie_remove) {
			\header_remove('Set-Cookie');
			foreach ($cookies as $cookie) {
				\header($cookie,false);
			}
		}

		return \setcookie($sName, $sValue, array(
			'expires' => $iExpire,
			'path' => $sPath,
//			'domain' => null,
			'secure' => static::$Secure,
			'httponly' => $httponly,
			'samesite' => static::$SameSite
		));
	}

	/**
	 * Firefox: Cookie "$sName" has been rejected because it is already expired.
	 * \header_remove("set-cookie: {$sName}");
	 */
	public static function set(string $sName, string $sValue, int $iExpire = 0, bool $httponly = true) : void
	{
		static::dropPlain($sName);
		$sName = static::name($sName);
		$sPath = static::$DefaultPath;
		$sPath = $sPath && \strlen($sPath) ? $sPath : '/';
		// https://github.com/the-djmaze/snappymail/issues/451
		// The 4K browser limit is for the entire cookie, including name, value, expiry date etc.
		$iMaxSize = 4000 - \strlen($sPath . $sName);
/*
		if ($iMaxSize < \strlen($sValue)) {
			throw new \Exception("Cookie '{$sName}' value too long");
		}
*/
		// Set the new 4K split cookie
		foreach (\str_split($sValue, $iMaxSize) as $i => $sPart) {
			$sCookieName = $i ? "{$sName}~{$i}" : $sName;
			Log::debug('COOKIE', "set {$sCookieName}");
			static::_set($sCookieName, $sPart, $iExpire, $httponly);
		}
		// Delete unused old 4K split cookie parts
		foreach (\array_keys($_COOKIE) as $sCookieName) {
			$aSplit = \explode('~', $sCookieName);
			if (isset($aSplit[1]) && $aSplit[0] == $sName && $aSplit[1] > $i) {
				Log::debug('COOKIE', "unset {$sCookieName}");
				static::_set($sCookieName, '', 0, $httponly);
			}
		}
	}

	public static function clear(string $sName) : void
	{
		static::dropPlain($sName);
		static::clearNamed(static::name($sName));
	}

	/**
	 * With "__Host-" names, a cookie of the plain name is left over from
	 * before (or planted): expire it.
	 */
	private static function dropPlain(string $sName) : void
	{
		if (static::name($sName) !== $sName && isset($_COOKIE[$sName])) {
			static::clearNamed($sName);
		}
	}

	private static function clearNamed(string $sName) : void
	{
		static::_set($sName, '', 0);
		// Delete 4K split cookie parts
		foreach (\array_keys($_COOKIE) as $sCookieName) {
			if (\strtok($sCookieName, '~') === $sName) {
				static::_set($sCookieName, '', 0);
			}
		}
	}
}
