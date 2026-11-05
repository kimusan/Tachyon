<?php

/**
 * Translation through DeepL, on demand only.
 *
 * Every call to DeepL happens here, never in the browser, so the auth key stays
 * on the server. The browser sends text and gets text back.
 *
 * Free and Pro keys use different hosts and the key itself says which: a Free
 * key ends in ":fx".
 */
class DeeplTranslatePlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME     = 'DeepL Translate',
		AUTHOR   = 'Tachyon',
		URL      = 'https://github.com/kimusan/Tachyon',
		VERSION  = '0.3',
		RELEASE  = '2026-10-28',
		REQUIRED = '4.5.0',
		CATEGORY = 'General',
		LICENSE  = 'MIT',
		DESCRIPTION = 'Translate a received message in place, or a draft before sending, using DeepL';

	const
		API_FREE = 'https://api-free.deepl.com',
		API_PRO  = 'https://api.deepl.com',
		// DeepL answers 413 above 128 KiB. The client splits a body to fit, this
		// is the backstop for anything that arrives here anyway.
		MAX_BODY = 120000;

	public function Init() : void
	{
		$this->UseLangs(true);

		$this->addJs('js/DeeplTranslate.js');
		$this->addTemplate('templates/MessageViewToolbarDeeplTranslate.html');
		$this->addTemplate('templates/PopupsDeeplTranslate.html');
		$this->addTemplate('templates/ComposeDeeplTranslate.html');
		$this->addTemplate('templates/STabDeeplTranslate.html');

		$this->addJsonHook('DeeplTranslate', 'DoDeeplTranslate');
		$this->addJsonHook('DeeplUsage', 'DoDeeplUsage');
		$this->addJsonHook('DeeplLanguages', 'DoDeeplLanguages');
		$this->addJsonHook('DeeplSettings', 'DoDeeplSettings');
	}

	protected function configMapping() : array
	{
		return array(
			\Tachyon\Plugins\Property::NewInstance('auth_key')
				->SetLabel('DeepL auth key')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::PASSWORD)
				->SetEncrypted()
				->SetDescription('Install wide key. A Free key ends in ":fx" and is sent to api-free.deepl.com, anything else to api.deepl.com.'),
			\Tachyon\Plugins\Property::NewInstance('target_lang')
				->SetLabel('Default language to read in')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING)
				->SetDefaultValue('EN-GB')
				->SetDescription('DeepL target language code, used until a user picks their own'),
			\Tachyon\Plugins\Property::NewInstance('formality')
				->SetLabel('Formality')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::SELECTION)
				->SetDefaultValue('default')
				->SetOptions(array('default', 'prefer_more', 'prefer_less'))
				->SetDescription('Only the "prefer" values are used, they fall back instead of failing on languages without formality support'),
			\Tachyon\Plugins\Property::NewInstance('allow_user_key')
				->SetLabel('Users may use their own key')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(true)
				->SetDescription('A key a user saves is used instead of the one above, for every account listed under their login'),
			\Tachyon\Plugins\Property::NewInstance('cache_days')
				->SetLabel('Remember translations for (days)')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::INT)
				->SetDefaultValue(30)
				->SetDescription('Reopening a translated message costs no quota until this expires. 0 to never remember.')
		);
	}

	/**
	 * Only what the interface needs to decide whether to offer the controls.
	 * The key is never among it.
	 */
	public function FilterAppDataPluginSection(bool $bAdmin, bool $bAuth, array &$aConfig) : void
	{
		if (!$bAdmin && $bAuth) {
			$aSettings = $this->getUserSettings();
			$aConfig = array(
				'configured' => \strlen($this->resolveKey()) ? 1 : 0,
				'allow_user_key' => $this->Config()->Get('plugin', 'allow_user_key', true) ? 1 : 0,
				'has_user_key' => !empty($aSettings['auth_key']) ? 1 : 0,
				'target_lang' => $this->targetLang()
			);
		} else {
			$aConfig = array();
		}
	}

	/**
	 * texts come back translated in the order they were sent, which is what lets
	 * the caller split a message body and glue it together again.
	 */
	public function DoDeeplTranslate()
	{
		$aTexts = $this->jsonParam('texts', array());
		if (!\is_array($aTexts) || !$aTexts) {
			return $this->jsonResponse(__FUNCTION__, false);
		}
		foreach ($aTexts as $mText) {
			if (!\is_string($mText)) {
				return $this->jsonResponse(__FUNCTION__, false);
			}
		}

		$aBody = array(
			'text' => \array_values($aTexts),
			'target_lang' => \strtoupper(\trim((string) $this->jsonParam('target', '') ?: $this->targetLang()))
		);
		if ($this->jsonParam('html', false)) {
			$aBody['tag_handling'] = 'html';
		}
		$sFormality = (string) $this->Config()->Get('plugin', 'formality', 'default');
		if ('prefer_more' === $sFormality || 'prefer_less' === $sFormality) {
			$aBody['formality'] = $sFormality;
		}

		$aResult = $this->call('POST', '/v2/translate', $aBody);
		if (!\is_array($aResult) || empty($aResult['translations'])) {
			return $this->jsonResponse(__FUNCTION__, $aResult);
		}

		return $this->jsonResponse(__FUNCTION__, array(
			'texts' => \array_column($aResult['translations'], 'text'),
			'detected' => $aResult['translations'][0]['detected_source_language'] ?? ''
		));
	}

	public function DoDeeplUsage()
	{
		return $this->jsonResponse(__FUNCTION__, $this->call('GET', '/v2/usage'));
	}

	/**
	 * Cached for a day. The list changes a few times a year and every user of the
	 * install opens the settings tab.
	 */
	public function DoDeeplLanguages()
	{
		$oCacher = $this->Manager()->Actions()->Cacher();
		// The key names the shape, so an install holding the plain list of codes
		// this used to cache is not served it as the objects below
		$sKey = 'Plugin/DeeplTranslate/languages/named';
		$sCached = $oCacher->Get($sKey);
		if ($sCached) {
			$aCached = \json_decode($sCached, true);
			if (\is_array($aCached) && ($aCached['t'] ?? 0) > \time() - 86400) {
				return $this->jsonResponse(__FUNCTION__, $aCached['languages']);
			}
		}

		$aResult = $this->call('GET', '/v3/languages?resource=translate_text');
		if (!\is_array($aResult)) {
			// An error code, which the settings tab turns into one sentence
			return $this->jsonResponse(__FUNCTION__, $aResult);
		}

		/**
		 * A flat array of language objects. The code is 'lang' in BCP 47 casing
		 * and 'usable_as_target' says whether a reader may pick it, which is the
		 * only list this plugin offers. Uppercased because that is the casing
		 * target_lang is stored and validated in, and DeepL takes it either way.
		 */
		$aLanguages = array();
		foreach ($aResult as $aLanguage) {
			if (\is_array($aLanguage) && !empty($aLanguage['usable_as_target']) && !empty($aLanguage['lang'])) {
				$sCode = \strtoupper((string) $aLanguage['lang']);
				// The name DeepL gives travels with the code, because a list of
				// codes is not a list a reader can choose from. Keyed by code so a
				// language listed twice is offered once.
				$aLanguages[$sCode] = array(
					'code' => $sCode,
					'name' => \trim((string) ($aLanguage['name'] ?? '')) ?: $sCode
				);
			}
		}
		$aLanguages = \array_values($aLanguages);
		// By name, because that is what the lists show
		\usort($aLanguages, function ($a, $b) {
			return \strcmp($a['name'], $b['name']);
		});

		if ($aLanguages) {
			$oCacher->Set($sKey, \json_encode(array('t' => \time(), 'languages' => $aLanguages)));
		}

		return $this->jsonResponse(__FUNCTION__, $aLanguages);
	}

	/**
	 * The settings live with the main account, so they are one set for the person
	 * and every account listed under their login sees the same key.
	 */
	public function DoDeeplSettings()
	{
		$aSettings = $this->getUserSettings();

		if ($this->jsonParam('save', false)) {
			$sTarget = \strtoupper(\trim((string) $this->jsonParam('target_lang', '')));
			if (\preg_match('/^[A-Z]{2}(-[A-Z]{2,4})?$/D', $sTarget)) {
				$aSettings['target_lang'] = $sTarget;
			}
			if ($this->Config()->Get('plugin', 'allow_user_key', true)) {
				$sKey = \trim((string) $this->jsonParam('auth_key', ''));
				if (\strlen($sKey)) {
					$aSettings['auth_key'] = \Tachyon\Util\Crypt::EncryptToJSON($sKey, \APP_SALT);
				} else if ($this->jsonParam('clear_key', false)) {
					unset($aSettings['auth_key']);
				}
			}
			$this->saveUserSettings($aSettings);
		}

		return $this->jsonResponse(__FUNCTION__, array(
			'target_lang' => $this->targetLang(),
			'allow_user_key' => $this->Config()->Get('plugin', 'allow_user_key', true) ? 1 : 0,
			'has_user_key' => !empty($aSettings['auth_key']) ? 1 : 0,
			'configured' => \strlen($this->resolveKey()) ? 1 : 0
		));
	}

	private function targetLang() : string
	{
		$aSettings = $this->getUserSettings();
		$sTarget = \trim((string) ($aSettings['target_lang'] ?? ''));
		return $sTarget ?: (\trim((string) $this->Config()->Get('plugin', 'target_lang', 'EN-GB')) ?: 'EN-GB');
	}

	/**
	 * The user's own key first, then the environment, then the admin's. The
	 * environment is there so the key can be handed to the service from a
	 * password manager instead of living in the data directory.
	 */
	private function resolveKey() : string
	{
		if ($this->Config()->Get('plugin', 'allow_user_key', true)) {
			$aSettings = $this->getUserSettings();
			if (!empty($aSettings['auth_key'])) {
				try {
					$sKey = \Tachyon\Util\Crypt::DecryptFromJSON($aSettings['auth_key'], \APP_SALT);
					if (\is_string($sKey) && \strlen(\trim($sKey))) {
						return \trim($sKey);
					}
				} catch (\Throwable $e) {
					// Falls through to the install wide key
				}
			}
		}

		$sKey = \trim((string) ($_ENV['TACHYON_DEEPL_AUTH_KEY'] ?? \getenv('TACHYON_DEEPL_AUTH_KEY') ?: ''));
		return $sKey ?: \trim((string) $this->Config()->getDecrypted('plugin', 'auth_key', ''));
	}

	/**
	 * @return array|string an array on success, else an error code the interface
	 * turns into one sentence
	 */
	private function call(string $sMethod, string $sPath, ?array $aBody = null)
	{
		$sKey = $this->resolveKey();
		if (!\strlen($sKey)) {
			return 'no-key';
		}

		$sJson = null;
		if (null !== $aBody) {
			$sJson = \json_encode($aBody);
			if (\strlen($sJson) > static::MAX_BODY) {
				return 'too-large';
			}
		}

		$sBase = \str_ends_with($sKey, ':fx') ? static::API_FREE : static::API_PRO;

		try {
			$oHTTP = \Tachyon\Util\HTTP\Request::factory();
			$oResponse = $oHTTP->doRequest($sMethod, $sBase . $sPath, $sJson, array(
				'Authorization: DeepL-Auth-Key ' . $sKey,
				'Content-Type: application/json'
			));
		} catch (\Throwable $e) {
			// doRequest throws rather than answering when the host is refused or
			// the transport fails, and the key must not reach a stack trace.
			\Tachyon\Util\Log::warning('DeepL', $sPath . ' failed: ' . $e->getMessage());
			return 'unreachable';
		}

		if (!$oResponse) {
			return 'unreachable';
		}

		switch ($oResponse->status) {
			case 200:
				$aResult = \json_decode((string) $oResponse->body, true);
				return \is_array($aResult) ? $aResult : 'bad-answer';
			case 403:
				return 'bad-key';
			case 413:
			case 414:
				return 'too-large';
			case 429:
				return 'too-many';
			case 456:
				return 'quota';
			default:
				// With the body, because the one sentence the interface shows cannot
				// say which of DeepL's rules the request broke
				\Tachyon\Util\Log::warning('DeepL', "{$sPath} answered {$oResponse->status}: "
					. \substr(\preg_replace('/\s+/', ' ', (string) $oResponse->body), 0, 200));
				return 'failed';
		}
	}
}
