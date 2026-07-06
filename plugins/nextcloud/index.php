<?php

class NextcloudPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME = 'Nextcloud',
		// Keep upstream metadata if you prefer; this is not functional.
		VERSION = '2.38.2',
		RELEASE  = '2026-02-06',
		CATEGORY = 'Integrations',
		DESCRIPTION = 'Integrate with Nextcloud v20+',
		REQUIRED = '2.38.0';

	public function Init() : void
	{
		if (static::IsIntegrated()) {
			\Tachyon\Util\Log::debug('Nextcloud', 'integrated');
			$this->UseLangs(true);

			$this->addHook('main.fabrica', 'MainFabrica');
			$this->addHook('filter.app-data', 'FilterAppData');
			$this->addHook('filter.language', 'FilterLanguage');

			$this->addCss('style.css');

			$this->addJs('js/webdav.js');

			$this->addJs('js/message.js');
			$this->addHook('json.attachments', 'DoAttachmentsActions');
			$this->addJsonHook('NextcloudSaveMsg', 'NextcloudSaveMsg');

			$this->addJs('js/composer.js');
			$this->addJsonHook('NextcloudAttachFile', 'NextcloudAttachFile');

			$this->addJs('js/messagelist.js');

			$this->addTemplate('templates/PopupsNextcloudFiles.html');
			$this->addTemplate('templates/PopupsNextcloudCalendars.html');

			// $this->addHook('login.credentials.step-2', 'loginCredentials2');
			// $this->addHook('login.credentials', 'loginCredentials');
			$this->addHook('imap.before-login', 'beforeLogin');
			$this->addHook('smtp.before-login', 'beforeLogin');
			$this->addHook('sieve.before-login', 'beforeLogin');
		} else {
			\Tachyon\Util\Log::debug('Nextcloud', 'NOT integrated');
			$this->addHook('main.content-security-policy', 'ContentSecurityPolicy');
		}
	}

	public function ContentSecurityPolicy(\Tachyon\Util\HTTP\CSP $CSP)
	{
		if (\method_exists($CSP, 'add')) {
			$CSP->add('frame-ancestors', "'self'");
		}
	}

	public function Supported() : string
	{
		return static::IsIntegrated() ? '' : 'Nextcloud not found to use this plugin';
	}

	public static function IsIntegrated()
	{
		return \class_exists('OC') && isset(\OC::$server);
	}

	public static function IsLoggedIn()
	{
		return static::IsIntegrated() && \OC::$server->get(\OCP\IUserSession::class)->isLoggedIn();
	}

	public function loginCredentials(string &$sEmail, string &$sLogin, ?string &$sPassword = null) : void
	{
		// left intentionally as upstream (commented in upstream)
	}

	public function loginCredentials2(string &$sEmail, ?string &$sPassword = null) : void
	{
		$ocUser = \OC::$server->get(\OCP\IUserSession::class)->getUser();
		$sEmail = $ocUser->getEMailAddress() ?: $ocUser->getPrimaryEMailAddress() ?: $sEmail;
	}

	public function beforeLogin(\Tachyon\Model\Account $oAccount, \MailSo\Net\NetClient $oClient, \MailSo\Net\ConnectSettings $oSettings) : void
	{
		if ($oAccount instanceof \Tachyon\Model\MainAccount
			&& \OCA\Tachyon\Util\TachyonHelper::isOIDCLogin()
			&& \str_starts_with($oSettings->passphrase, 'oidc_login|')
		) {
			$oSettings->passphrase = (string) \OC::$server->getSession()->get('oidc_access_token');
			\array_unshift($oSettings->SASLMechanisms, 'OAUTHBEARER');
		}
	}

	/**
	 * Attach a Nextcloud file into Tachyon temp storage (for composing).
	 */
	public function NextcloudAttachFile() : array
	{
		$aResult = [
			'success' => false,
			'tempName' => ''
		];

		$sFile = (string) $this->jsonParam('file', '');

		try {
			$oActions = \Tachyon\Api::Actions();
			$oAccount = $oActions->getAccountFromToken();
			if (!$oAccount) {
				$aResult['error'] = 'no-account';
				return $this->jsonResponse(__FUNCTION__, $aResult);
			}

			$user = \OC::$server->get(\OCP\IUserSession::class)->getUser();
			if (!$user) {
				$aResult['error'] = 'no-user';
				return $this->jsonResponse(__FUNCTION__, $aResult);
			}

			/** @var \OCP\Files\IRootFolder $root */
			$root = \OC::$server->get(\OCP\Files\IRootFolder::class);
			$userFolder = $root->getUserFolder($user->getUID());

			// Tachyon sends "/Documents/app.svg" style paths; userFolder expects relative.
			$relPath = \ltrim($sFile, '/');

			if ($relPath === '') {
				$aResult['error'] = 'empty-path';
				return $this->jsonResponse(__FUNCTION__, $aResult);
			}

			$node = $userFolder->get($relPath);
			if (!($node instanceof \OCP\Files\File)) {
				$aResult['error'] = 'not-a-file';
				return $this->jsonResponse(__FUNCTION__, $aResult);
			}

			$fp = $node->fopen('rb');
			if (!$fp) {
				$aResult['error'] = 'open-failed';
				return $this->jsonResponse(__FUNCTION__, $aResult);
			}

			$sSavedName = 'nextcloud-file-' . \sha1($sFile . \microtime(true));
			$ok = $oActions->FilesProvider()->PutFile($oAccount, $sSavedName, $fp);
			@\fclose($fp);

			if (!$ok) {
				$aResult['error'] = 'failed';
			} else {
				$aResult['tempName'] = $sSavedName;
				$aResult['success'] = true;
			}
		} catch (\Throwable $e) {
			$aResult['error'] = 'exception';
			$aResult['errorMessage'] = $e->getMessage();
		}

		return $this->jsonResponse(__FUNCTION__, $aResult);
	}

	public function NextcloudSaveMsg() : array
	{
		$sSaveFolder = \ltrim($this->jsonParam('folder', ''), '/');
//		$aValues = \Tachyon\Api::Actions()->decodeRawKey($this->jsonParam('msgHash', ''));
		$msgHash = $this->jsonParam('msgHash', '');
		$aValues = \json_decode(\MailSo\Base\Utils::UrlSafeBase64Decode($msgHash), true);
		$aResult = [
			'folder' => '',
			'filename' => '',
			'success' => false
		];
		if ($sSaveFolder && !empty($aValues['folder']) && !empty($aValues['uid'])) {
			$oActions = \Tachyon\Api::Actions();
			$oMailClient = $oActions->MailClient();
			if (!$oMailClient->IsLoggined()) {
				$oAccount = $oActions->getAccountFromToken();
				$oAccount->ImapConnectAndLogin($oActions->Plugins(), $oMailClient->ImapClient(), $oActions->Config());
			}

			$sSaveFolder = $sSaveFolder ?: 'Emails';
			$oFiles = \OCP\Files::getStorage('files');
			if ($oFiles) {
				$oFiles->is_dir($sSaveFolder) || $oFiles->mkdir($sSaveFolder);
			}
			$aResult['folder'] = $sSaveFolder;
			$aResult['filename'] = \MailSo\Base\Utils::SecureFileName(
				\mb_substr($this->jsonParam('filename', '') ?: \date('YmdHis'), 0, 100)
			) . '.' . \md5($msgHash) . '.eml';


			$oMailClient->MessageMimeStream(
				function ($rResource) use ($oFiles, $aResult) {
					if (\is_resource($rResource)) {
						$aResult['success'] = $oFiles->file_put_contents("{$aResult['folder']}/{$aResult['filename']}", $rResource);
					}
				},
				(string) $aValues['folder'],
				(int) $aValues['uid'],
				isset($aValues['mimeIndex']) ? (string) $aValues['mimeIndex'] : ''
			);
		}

		return $this->jsonResponse(__FUNCTION__, $aResult);
	}

	public function DoAttachmentsActions(\Tachyon\Util\AttachmentsAction $data)
	{
		if (static::isLoggedIn() && 'nextcloud' === $data->action) {
			$oFiles = \OCP\Files::getStorage('files');
			if ($oFiles && \method_exists($oFiles, 'file_put_contents')) {
				$sSaveFolder = \ltrim($this->jsonParam('NcFolder', ''), '/');
				$sSaveFolder = $sSaveFolder ?: 'Attachments';
				$oFiles->is_dir($sSaveFolder) || $oFiles->mkdir($sSaveFolder);
				$data->result = true;
				foreach ($data->items as $aItem) {
					$sSavedFileName = empty($aItem['fileName']) ? 'file.dat' : $aItem['fileName'];
					if (!empty($aItem['data'])) {
						$sSavedFileNameFull = static::SmartFileExists($sSaveFolder.'/'.$sSavedFileName, $oFiles);
						if (!$oFiles->file_put_contents($sSavedFileNameFull, $aItem['data'])) {
							$data->result = false;
						}
					} else if (!empty($aItem['fileHash'])) {
						$fFile = $data->filesProvider->GetFile($data->account, $aItem['fileHash'], 'rb');
						if (\is_resource($fFile)) {
							$sSavedFileNameFull = static::SmartFileExists($sSaveFolder.'/'.$sSavedFileName, $oFiles);
							if (!$oFiles->file_put_contents($sSavedFileNameFull, $fFile)) {
								$data->result = false;
							}
							if (\is_resource($fFile)) {
								\fclose($fFile);
							}
						}
					}
				}
			}
		}
	}

	public function FilterAppData($bAdmin, &$aResult) : void
	{
		if (!$bAdmin && \is_array($aResult)) {
			$ocUser = \OC::$server->get(\OCP\IUserSession::class)->getUser();
			$sUID = $ocUser->getUID();
			$oUrlGen = \OC::$server->get(\OCP\IURLGenerator::class);
			$sWebDAV = $oUrlGen->getAbsoluteURL($oUrlGen->linkTo('', 'remote.php') . '/dav');
//			$sWebDAV = \OCP\Util::linkToRemote('dav');
			$aResult['Nextcloud'] = [
				'UID' => $sUID,
				'WebDAV' => $sWebDAV,
				'CalDAV' => $this->Config()->Get('plugin', 'calendar', false)
//				'WebDAV_files' => $sWebDAV . '/files/' . $sUID
			];
			if (empty($aResult['Auth'])) {
				$config = \OC::$server->get(\OCP\IConfig::class);
				$sEmail = '';
				// Only store the user's password in the current session if they have
				// enabled auto-login using Nextcloud username or email address.
				if ($config->getAppValue('snappymail', 'snappymail-autologin', false)) {
					$sEmail = $sUID;
				} else if ($config->getAppValue('snappymail', 'snappymail-autologin-with-email', false)) {
					$sEmail = $config->getUserValue($sUID, 'settings', 'email', '');
				} else {
					\Tachyon\Util\Log::debug('Nextcloud', 'snappymail-autologin is off');
				}
				// If the user has set credentials for Tachyon in their personal
				// settings, override everything before and use those instead.
				$sCustomEmail = $config->getUserValue($sUID, 'snappymail', 'snappymail-email', '');
				if ($sCustomEmail) {
					$sEmail = $sCustomEmail;
				}
				if (!$sEmail) {
					$sEmail = $ocUser->getEMailAddress();
//						?: $ocUser->getPrimaryEMailAddress();
				}
/*
				if ($config->getAppValue('snappymail', 'snappymail-autologin-oidc', false)) {
					if (\OC::$server->getSession()->get('is_oidc')) {
						$sEmail = "{$sUID}@nextcloud";
						$aResult['DevPassword'] = \OC::$server->getSession()->get('oidc_access_token');
					} else {
						\Tachyon\Util\Log::debug('Nextcloud', 'Not an OIDC login');
					}
				} else {
					\Tachyon\Util\Log::debug('Nextcloud', 'OIDC is off');
				}
*/
				$aResult['DevEmail'] = $sEmail ?: '';
			} else if (!empty($aResult['ContactsSync'])) {
				$bSave = false;
				if (empty($aResult['ContactsSync']['Url'])) {
					$aResult['ContactsSync']['Url'] = "{$sWebDAV}/addressbooks/users/{$sUID}/contacts/";
					$bSave = true;
				}
				if (empty($aResult['ContactsSync']['User'])) {
					$aResult['ContactsSync']['User'] = $sUID;
					$bSave = true;
				}
				$pass = \OC::$server->getSession()['snappymail-passphrase'];
				if ($pass/* && empty($aResult['ContactsSync']['Password'])*/) {
					$pass = \Tachyon\Util\Crypt::DecryptUrlSafe($pass, $sUID);
					if ($pass) {
						$aResult['ContactsSync']['Password'] = $pass;
						$bSave = true;
					}
				}
				if ($bSave) {
					$oActions = \Tachyon\Api::Actions();
					$oActions->setContactsSyncData(
						$oActions->getAccountFromToken(),
						array(
							'Mode' => $aResult['ContactsSync']['Mode'],
							'User' => $aResult['ContactsSync']['User'],
							'Password' => $aResult['ContactsSync']['Password'],
							'Url' => $aResult['ContactsSync']['Url']
						)
					);
				}
			}
		}
	}

	public function FilterLanguage(&$sLanguage, $bAdmin) : void
	{
		if (!\Tachyon\Api::Config()->Get('webmail', 'allow_languages_on_settings', true)) {
			$aResultLang = \Tachyon\Util\L10n::getLanguages($bAdmin);
			$userId = \OC::$server->get(\OCP\IUserSession::class)->getUser()->getUID();
			$userLang = \OC::$server->get(\OCP\IConfig::class)->getUserValue($userId, 'core', 'lang', 'en');
			$userLang = \strtr($userLang, '_', '-');
			$sLanguage = $this->determineLocale($userLang, $aResultLang);
			// Check if $sLanguage is null
			if (!$sLanguage) {
				$sLanguage = 'en'; // Assign 'en' if $sLanguage is null
			}
		}
	}

	/**
	 * Determine locale from user language.
	 *
	 * @param string $langCode The name of the input.
	 * @param array  $languagesArray The value of the array.
	 *
	 * @return string return locale
	 */
	private function determineLocale(string $langCode, array $languagesArray) : ?string
	{
		// Direct check for the language code
		if (\in_array($langCode, $languagesArray)) {
			return $langCode;
		}

		// Check without country code
		if (\str_contains($langCode, '-')) {
			$langCode = \explode('-', $langCode)[0];
			if (\in_array($langCode, $languagesArray)) {
				return $langCode;
			}
		}

		// Check with uppercase country code
		$langCodeWithUpperCase = $langCode . '-' . \strtoupper($langCode);
		if (\in_array($langCodeWithUpperCase, $languagesArray)) {
			return $langCodeWithUpperCase;
		}

		// If no match is found
		return null;
	}

	/**
	 * @param mixed $mResult
	 */
	public function MainFabrica(string $sName, &$mResult)
	{
		if (static::isLoggedIn()) {
			if ('suggestions' === $sName && $this->Config()->Get('plugin', 'suggestions', true)) {
				if (!\is_array($mResult)) {
					$mResult = array();
				}
				include_once __DIR__ . '/NextcloudContactsSuggestions.php';
				$mResult[] = new NextcloudContactsSuggestions(
					$this->Config()->Get('plugin', 'ignoreSystemAddressbook', true)
				);
			}
/*
			if ($this->Config()->Get('plugin', 'storage', false) && ('storage' === $sName || 'storage-local' === $sName)) {
				require_once __DIR__ . '/storage.php';
				$oDriver = new \NextcloudStorage(APP_PRIVATE_DATA.'storage', $sName === 'storage-local');
			}
*/
		}
	}

	protected function configMapping() : array
	{
		return array(
			\Tachyon\Plugins\Property::NewInstance('suggestions')->SetLabel('Suggestions')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(true),
			\Tachyon\Plugins\Property::NewInstance('ignoreSystemAddressbook')->SetLabel('Ignore system addressbook')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(true),
/*
			\Tachyon\Plugins\Property::NewInstance('storage')->SetLabel('Use Nextcloud user ID in config storage path')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(false)
*/
			\Tachyon\Plugins\Property::NewInstance('calendar')->SetLabel('Enable "Put ICS in calendar"')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(false)
		);
	}

	private static function SmartFileExists(string $sFilePath, $oFiles) : string
	{
		$sFilePath = \str_replace('\\', '/', \trim($sFilePath));

		if (!$oFiles->file_exists($sFilePath)) {
			return $sFilePath;
		}

		$aFileInfo = \pathinfo($sFilePath);

		$iIndex = 0;

		while (true) {
			++$iIndex;
			$sFilePathNew = $aFileInfo['dirname'].'/'.
				\preg_replace('/\(\d{1,2}\)$/', '', $aFileInfo['filename']).
				' ('.$iIndex.')'.
				(empty($aFileInfo['extension']) ? '' : '.'.$aFileInfo['extension'])
			;
			if (!$oFiles->file_exists($sFilePathNew)) {
				return $sFilePathNew;
			}
			if (10 < $iIndex) {
				break;
			}
		}
		return $sFilePath;
	}
}
