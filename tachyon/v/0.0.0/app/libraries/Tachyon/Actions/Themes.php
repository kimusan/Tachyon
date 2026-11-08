<?php

namespace Tachyon\Actions;

use Tachyon\Enumerations\Capa;

trait Themes
{
	/**
	 * Themes that were a light and a dark build of one design, now merged into a
	 * single theme that carries both. Retiring a name would otherwise drop
	 * everyone using it onto Default, because ValidateTheme falls back rather
	 * than failing. The value is the theme that replaced it.
	 */
	public const MERGED_THEMES = array(
		'StripesDark' => 'Stripes',
		'LoveDark'    => 'Love',
		'SnowDarkV1'  => 'Snow',
		'BlurredDark' => 'Blurred',
		'SquaresDark' => 'Squares',
		'BlackWood'   => 'Wood',
		'NightShine'  => 'Shine',
		'DarkShine'   => 'Shine'
	);

	/**
	 * Themes that became a palette of another theme, as [theme, palette]. Modern
	 * was seven separate themes, installed as custom ones, before it shipped as
	 * one theme with palettes. Like MERGED_THEMES this only applies once the old
	 * folder is gone.
	 */
	public const MERGED_THEME_ACCENTS = array(
		'Modern@custom'          => ['Modern', 'modern'],
		'ModernEvergreen@custom' => ['Modern', 'evergreen'],
		'ModernMidnight@custom'  => ['Modern', 'midnight'],
		'ModernClay@custom'      => ['Modern', 'clay'],
		'ModernGraphite@custom'  => ['Modern', 'graphite'],
		'ModernInk@custom'       => ['Modern', 'ink'],
		'ModernPlum@custom'      => ['Modern', 'plum']
	);

	/**
	 * True when the theme this account had saved was the dark half of such a
	 * pair, so the client can put it into dark mode once and keep the look it
	 * chose rather than dropping it into the light half.
	 */
	private bool $bThemeWasDark = false;

	/**
	 * The palette the saved theme stood for, when it was one of
	 * MERGED_THEME_ACCENTS
	 */
	private string $sMergedThemeAccent = '';

	public function ThemeWasDark(): bool
	{
		return $this->bThemeWasDark;
	}

	public function GetTheme(bool $bAdmin): string
	{
		static $sTheme;
		if (!$sTheme) {
			$sTheme = $this->Config()->Get('webmail', 'theme', 'Default');
			if (!$bAdmin
			 && ($oAccount = $this->getAccountFromToken(false))
			 && $this->GetCapa(Capa::THEMES)
			 && ($oSettingsLocal = $this->SettingsProvider(true)->Load($oAccount))) {
				$sTheme = (string) $oSettingsLocal->GetConf('Theme', $sTheme);
			}
			$sTheme = $this->ValidateTheme($sTheme);
		}
		return $sTheme;
	}

	/**
	 * @staticvar array $aCache
	 */
	public function GetThemes(): array
	{
		static $aCache = array();
		if ($aCache) {
			return $aCache;
		}

		$bClear = false;
		$bDefault = false;
		$aCache = array();
		$sDir = APP_VERSION_ROOT_PATH . 'themes';
		if (\is_dir($sDir)) {
			$rDirH = \opendir($sDir);
			if ($rDirH) {
				while (($sFile = \readdir($rDirH)) !== false) {
					if ('.' !== $sFile[0] && \is_dir($sDir . '/' . $sFile)
					 && (\file_exists("{$sDir}/{$sFile}/styles.css") || \file_exists("{$sDir}/{$sFile}/styles.less"))) {
						if ('Default' === $sFile) {
							$bDefault = true;
						} else if ('Clear' === $sFile) {
							$bClear = true;
						} else {
							$aCache[] = $sFile;
						}
					}
				}
				closedir($rDirH);
			}
		}

		$sDir = APP_INDEX_ROOT_PATH . 'themes'; // custom user themes
		if (\is_dir($sDir)) {
			if ($rDirH = \opendir($sDir)) {
				while (($sFile = \readdir($rDirH)) !== false) {
					if ('.' !== $sFile[0] && \is_dir($sDir . '/' . $sFile)
					 && (\file_exists("{$sDir}/{$sFile}/styles.css") || \file_exists("{$sDir}/{$sFile}/styles.less"))) {
						$aCache[] = $sFile . '@custom';
					}
				}
				\closedir($rDirH);
			} else {
				$this->logWrite("{$sDir} not readable", \LOG_DEBUG, 'Themes');
			}
		}

		if (\class_exists('OC', false)) {
			$sDir = \OC::$SERVERROOT . '/themes'; // custom user themes
			if (\is_dir($sDir) && ($rDirH = \opendir($sDir))) {
				while (($sFile = \readdir($rDirH)) !== false) {
					if ('.' !== $sFile[0] && \is_dir("{$sDir}/{$sFile}") && \file_exists("{$sDir}/{$sFile}/snappymail/style.css")) {
						$aCache[] = $sFile . '@nextcloud';
					}
				}
				\closedir($rDirH);
			}
		}

		$aCache = \array_unique($aCache);
		\sort($aCache);

		if ($bDefault) {
			\array_unshift($aCache, 'Default');
		}

		if ($bClear) {
			\array_push($aCache, 'Clear');
		}

		return $aCache;
	}

	/**
	 * The palette in use: the account's own choice, else the admin's, else the
	 * theme's default. Empty for a theme without palettes.
	 */
	public function GetThemeAccent(bool $bAdmin): string
	{
		static $sAccent;
		if (null === $sAccent) {
			$sTheme = $this->GetTheme($bAdmin);
			$sAccent = $this->sMergedThemeAccent ?: (string) $this->Config()->Get('webmail', 'theme_accent', '');
			if (!$bAdmin
			 && ($oAccount = $this->getAccountFromToken(false))
			 && $this->GetCapa(Capa::THEMES)
			 && ($oSettingsLocal = $this->SettingsProvider(true)->Load($oAccount))) {
				$sAccent = (string) $oSettingsLocal->GetConf('ThemeAccent', '') ?: $sAccent;
			}
			$sAccent = $this->ValidateThemeAccent($sTheme, $sAccent) ?: ($this->GetThemeAccents($sTheme)['default'] ?? '');
		}
		return $sAccent;
	}

	/**
	 * A palette id the theme declares, or '' for the theme's default
	 */
	public function ValidateThemeAccent(string $sTheme, string $sAccent): string
	{
		$aAccents = $this->GetThemeAccents($sTheme);
		return ($aAccents && isset($aAccents['accents'][$sAccent])) ? $sAccent : '';
	}

	/**
	 * The palettes a theme declares in the theme.json next to its styles.css:
	 * {"default": "id", "accents": {"id": {"name": "Name", "swatch": "#rrggbb"}}}
	 * The id goes into the data-accent attribute on <html> and the swatch into
	 * a style, so an entry that is not a plain id, a short name and a hex colour
	 * is dropped. Returns [] for a theme without any.
	 */
	public function GetThemeAccents(string $sTheme): array
	{
		static $aCache = array();
		if (isset($aCache[$sTheme])) {
			return $aCache[$sTheme];
		}
		$aCache[$sTheme] = array();
		// Only an installed theme name ever reaches a path
		if (\str_ends_with($sTheme, '@nextcloud') || !\in_array($sTheme, $this->GetThemes(), true)) {
			return $aCache[$sTheme];
		}
		$sFile = \str_ends_with($sTheme, '@custom')
			? APP_INDEX_ROOT_PATH . 'themes/' . \substr($sTheme, 0, -7) . '/theme.json'
			: APP_VERSION_ROOT_PATH . 'themes/' . $sTheme . '/theme.json';
		$aJson = \is_file($sFile) ? \json_decode(\file_get_contents($sFile), true) : null;
		if (!\is_array($aJson) || !\is_array($aJson['accents'] ?? null)) {
			return $aCache[$sTheme];
		}
		$aAccents = array();
		foreach ($aJson['accents'] as $sId => $aAccent) {
			$sId = (string) $sId;
			$sName = \is_array($aAccent) && \is_string($aAccent['name'] ?? null) ? \trim($aAccent['name']) : '';
			$sSwatch = \is_array($aAccent) && \is_string($aAccent['swatch'] ?? null) ? $aAccent['swatch'] : '';
			if (\preg_match('/^[a-z0-9-]{1,32}$/D', $sId)
			 && \preg_match('/^[^\x00-\x1F\x7F<>]{1,40}$/Du', $sName)
			 && \preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/D', $sSwatch)) {
				$aAccents[$sId] = array('name' => $sName, 'swatch' => $sSwatch);
			} else {
				$this->logWrite("{$sTheme}: palette '{$sId}' in theme.json dropped", \LOG_WARNING, 'Themes');
			}
		}
		if ($aAccents) {
			$sDefault = (string) ($aJson['default'] ?? '');
			$aCache[$sTheme] = array(
				'default' => isset($aAccents[$sDefault]) ? $sDefault : (string) \array_key_first($aAccents),
				'accents' => $aAccents
			);
		}
		return $aCache[$sTheme];
	}

	public function ValidateTheme(string $sTheme): string
	{
		if (isset(static::MERGED_THEME_ACCENTS[$sTheme]) && !\in_array($sTheme, $this->GetThemes())) {
			[$sTheme, $this->sMergedThemeAccent] = static::MERGED_THEME_ACCENTS[$sTheme];
		}
		if (isset(static::MERGED_THEMES[$sTheme]) && !\in_array($sTheme, $this->GetThemes())) {
			// 'DarkShine' was the light half despite the name, so only the ones
			// that really were dark ask the client to switch mode
			$this->bThemeWasDark = 'DarkShine' !== $sTheme;
			$sTheme = static::MERGED_THEMES[$sTheme];
		}
		if (!\in_array($sTheme, $this->GetThemes())) {
			$sTheme = $this->Config()->Get('webmail', 'theme', 'Default');
			if (!\in_array($sTheme, $this->GetThemes())) {
				$sTheme = 'Default';
			}
		}
		return $sTheme;
	}

	public function compileCss(string $sTheme, bool $bAdmin, bool $bMinified = false) : string
	{
		$mResult = array();
		$bLess = false;

		if ('@nextcloud' === \substr($sTheme, -10)) {
			$sBase = \OC::$WEBROOT . '/';
			$sThemeCSSFile = \OC::$SERVERROOT . '/themes/' . \str_replace('@nextcloud', '/snappymail/style.css', $sTheme);
		} else {
			$bCustomTheme = '@custom' === \substr($sTheme, -7);
			if ($bCustomTheme) {
				$sTheme = \substr($sTheme, 0, -7);
				$sBase = \Tachyon\Utils::WebPath();
			} else {
				$sBase = \Tachyon\Utils::WebVersionPath();
			}
			$sBase .= "themes/{$sTheme}/";
			$sThemeCSSFile = ($bCustomTheme ? APP_INDEX_ROOT_PATH : APP_VERSION_ROOT_PATH).'themes/'.$sTheme.'/styles.css';
			if (!\is_file($sThemeCSSFile)) {
				$sThemeCSSFile = \str_replace('styles.css', 'styles.less', $sThemeCSSFile);
				if (\is_file($sThemeCSSFile)) {
					$bLess = true;
					$mResult[] = "@base: \"{$sBase}\";";
					$mResult[] = \file_get_contents($sThemeCSSFile);
				}
			}
		}
		if (\is_file($sThemeCSSFile)) {
			$mResult[] = \file_get_contents($sThemeCSSFile);
		}

		$mResult[] = $this->Plugins()->CompileCss($bAdmin, $bLess, $bMinified);

		$mResult = \preg_replace('@(url\(["\']?)(\\./)?([a-z]+[^:a-z])@',
			"\$1{$sBase}\$3",
			\str_replace('@{base}', $sBase, \implode("\n", $mResult)));

		return $bLess ? (new \LessPHP\lessc())->compile($mResult) : $mResult;
//			: \str_replace(';}', '}', \preg_replace('/\\s*([:;{},])\\s*/', '\1', \preg_replace('/\\s+/', ' ', \preg_replace('#/\\*.*?\\*/#s', '', $mResult))));
	}

	public function UploadBackground(?array $aFile, int $iError): array
	{
		$oAccount = $this->getAccountFromToken();

		if (!$this->GetCapa(Capa::USER_BACKGROUND)) {
			return $this->FalseResponse();
		}

		$sName = '';
		$sHash = '';

		if ($oAccount && UPLOAD_ERR_OK === $iError && \is_array($aFile)) {
			$sMimeType = \Tachyon\Util\File\MimeType::fromFile($aFile['tmp_name'], $aFile['name'])
				?: \Tachyon\Util\File\MimeType::fromFilename($aFile['name'])
				?: $aFile['type'];
			if (\in_array($sMimeType, array('image/png', 'image/jpg', 'image/jpeg', 'image/webp'))) {
				$sSavedName = 'upload-post-' . \md5($aFile['name'] . $aFile['tmp_name'])
					. \Tachyon\Util\File\MimeType::toExtension($sMimeType);
				if (!$this->FilesProvider()->MoveUploadedFile($oAccount, $sSavedName, $aFile['tmp_name'])) {
					$iError = \Tachyon\Enumerations\UploadError::ON_SAVING;
				} else {
					$rData = $this->FilesProvider()->GetFile($oAccount, $sSavedName);
					if (\is_resource($rData)) {
						$sData = \stream_get_contents($rData);
						if (!empty($sData) && \strlen($sData)) {
							$sName = $aFile['name'];
							if (empty($sName)) {
								$sName = '_';
							}

							if ($this->StorageProvider()->Put($oAccount,
								\Tachyon\Providers\Storage\Enumerations\StorageType::CONFIG,
								'background',
								// Used by RawUserBackground()
								\Tachyon\Utils::jsonEncode(array(
									'ContentType' => $sMimeType,
									'Raw' => \base64_encode($sData)
								))
							)) {
								$oSettings = $this->SettingsProvider()->Load($oAccount);
								if ($oSettings) {
									$sHash = \MailSo\Base\Utils::Sha1Rand($sName . APP_VERSION . APP_SALT);

									$oSettings->SetConf('UserBackgroundName', $sName);
									$oSettings->SetConf('UserBackgroundHash', $sHash);
									$oSettings->save();
								}
							}
						}

						unset($sData);
					}

					if (\is_resource($rData)) {
						\fclose($rData);
					}

					unset($rData);
				}

				$this->FilesProvider()->Clear($oAccount, $sSavedName);
			} else {
				$iError = \Tachyon\Enumerations\UploadError::FILE_TYPE;
			}
		}

		if (UPLOAD_ERR_OK !== $iError) {
			$iClientError = 0;
			$sError = \Tachyon\Enumerations\UploadError::getUserMessage($iError, $iClientError);
			if (!empty($sError)) {
				return $this->FalseResponse($iClientError, $sError);
			}
		}

		return $this->DefaultResponse(!empty($sName) && !empty($sHash) ? array(
			'name' => $sName,
			'hash' => $sHash
		) : false);
	}
}
