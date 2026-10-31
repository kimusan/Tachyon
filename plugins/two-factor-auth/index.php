<?php

require_once __DIR__ . '/providers/record.php';

use \Tachyon\Exceptions\ClientException;
use \Tachyon\Model\Account;
use \Tachyon\Model\MainAccount;

class TwoFactorAuthPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME     = 'Two Factor Authentication',
		VERSION  = '2.20.0',
		RELEASE  = '2026-10-07',
		REQUIRED = '2.36.0',
		CATEGORY = 'Login',
		DESCRIPTION = 'Provides support for TOTP 2FA',
		// The additional message of the login refusal when the code is missing.
		CODE_REQUIRED = 'TwoFactorCodeRequired';

	public function Init() : void
	{
		$this->UseLangs(true);

		$this->addJs('js/TwoFactorAuthLogin.js');
		$this->addJs('js/TwoFactorAuthSettings.js');

		$this->addHook('login.success', 'DoLogin');
		$this->addHook('filter.app-data', 'FilterAppData');

		$this->addJsonHook('GetTwoFactorInfo', 'DoGetTwoFactorInfo');
		$this->addJsonHook('CreateTwoFactorSecret', 'DoCreateTwoFactorSecret');
		$this->addJsonHook('ShowTwoFactorSecret', 'DoShowTwoFactorSecret');
		$this->addJsonHook('EnableTwoFactor', 'DoEnableTwoFactor');
		$this->addJsonHook('VerifyTwoFactorCode', 'DoVerifyTwoFactorCode');
		$this->addJsonHook('ClearTwoFactorInfo', 'DoClearTwoFactorInfo');

		$this->addTemplate('templates/TwoFactorAuthSettings.html');
		$this->addTemplate('templates/PopupsTwoFactorAuthTest.html');
	}

	public function configMapping() : array
	{
		return [
			\Tachyon\Plugins\Property::NewInstance("force_two_factor_auth")
//				->SetLabel('PLUGIN_TWO_FACTOR/LABEL_FORCE')
				->SetLabel('Enforce 2-Step Verification')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL),
			\Tachyon\Plugins\Property::NewInstance("otp_issuer")
				->SetLabel('Service name shown in the authenticator')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING)
				->SetDescription('Names this service next to the account in the authenticator app. Empty: the webmail title.')
				->SetDefaultValue(''),
		];
	}

	public function FilterAppData($bAdmin, &$aResult)
	{
		if (!$bAdmin && \is_array($aResult)/* && isset($aResult['Auth']) && !$aResult['Auth']*/) {
			$aResult['RequireTwoFactor'] = (bool) $this->Config()->Get('plugin', 'force_two_factor_auth', false);

			$aResult['SetupTwoFactor'] = false;
			if ($aResult['RequireTwoFactor'] && !empty($aResult['Auth'])) {
				try {
					$aData = $this->getTwoFactorInfo($this->getMainAccountFromToken());
					$aResult['SetupTwoFactor'] = empty($aData['IsSet']) || empty($aData['Enable']);
				} catch (ClientException $oException) {
					// An unreadable record refuses the next login; thrown from here it
					// would only turn the already open session's app data into a 500.
				}
			}
		}
	}

	public function DoLogin(MainAccount $oAccount)
	{
		if (!$this->TwoFactorAuthProvider($oAccount)) {
			return;
		}
		$aRecord = $this->loadRecord($oAccount);
		if (!$aRecord || empty($aRecord['Enable'])) {
			return;
		}
		$sCode = \trim($this->jsonParam('totp_code', ''));
		if (empty($sCode)) {
			$this->Logger()->Write("TFA: Code required for {$oAccount->Email()}");
			// Named, so the login screen asks for the code instead of saying
			// "authentication failed": the password was right, and a person
			// told otherwise resets a good password. Saying so after a correct
			// password is what every 2FA login does.
			throw new ClientException(\Tachyon\Notifications::AuthError, null, self::CODE_REQUIRED);
		}
		$sOutcome = $this->checkCode($oAccount, $aRecord, $sCode);
		if ('ok' !== $sOutcome) {
			// Also to the auth log, which fail2ban reads.
			$this->logAuthFailure($oAccount);
			throw new ClientException(\Tachyon\Notifications::AuthError);
		}
		$this->Logger()->Write("TFA: Code verified for {$oAccount->Email()}");
	}

	/**
	 * Turning 2-step verification off, wiping it, replacing its secret or showing
	 * the secret all used to need only the session. Whoever held a session (a
	 * stolen cookie, an unlocked machine) could quietly drop the second factor or
	 * copy it. While it is enabled, these need a current code or backup code,
	 * checked as at login: counted towards the lockout, refused as a replay if
	 * already used, and a backup code used here is spent.
	 */
	private function requireCurrentCode(MainAccount $oAccount) : void
	{
		$aRecord = $this->loadRecord($oAccount);
		if (!$aRecord || empty($aRecord['Enable'])) {
			return;
		}
		$sCode = \trim((string) $this->jsonParam('Code', ''));
		if (\strlen($sCode) && 'ok' === $this->checkCode($oAccount, $aRecord, $sCode)) {
			return;
		}
		$this->Logger()->Write("TFA: change refused for {$oAccount->Email()}, no valid current code");
		throw new ClientException(\Tachyon\Notifications::AuthError);
	}

	public function DoGetTwoFactorInfo() : array
	{
		$oAccount = $this->getMainAccountFromToken();

		if (!$this->TwoFactorAuthProvider($oAccount)) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		return $this->jsonResponse(__FUNCTION__, $this->getTwoFactorInfo($oAccount, true));
	}

	public function DoCreateTwoFactorSecret() : array
	{
		$oAccount = $this->getMainAccountFromToken();

		if (!$this->TwoFactorAuthProvider($oAccount)) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		// Overwrites the stored secret with Enable false, i.e. also switches it off
		$this->requireCurrentCode($oAccount);

		$sSecret = $this->TwoFactorAuthProvider($oAccount)->CreateSecret();
		// Backup codes are a second factor; random_int(), never rand()
		$aCodes = TwoFactorRecord::newBackupCodes();
		$this->saveRecord($oAccount, TwoFactorRecord::create($oAccount->Email(), $sSecret, $aCodes, $this->recordKey()));

		// The only time the backup codes are ever shown: only their hashes are kept.
		return $this->jsonResponse(__FUNCTION__, array(
			'User' => $oAccount->Email(),
			'IsSet' => true,
			'Enable' => false,
			'Tested' => false,
			'Secret' => $sSecret,
			'QRCode' => $this->getQRCode($oAccount, $sSecret),
			'BackupCodes' => \implode(' ', $aCodes)
		));
	}

	private function getQRCode(MainAccount $oAccount, string $secret) : string
	{
		$issuer = \trim((string) $this->Config()->Get('plugin', 'otp_issuer', ''))
			?: \trim((string) \Tachyon\Api::Config()->Get('webmail', 'title', ''));
		$QR = \Tachyon\Util\QRCode::getMinimumQRCode(
			TwoFactorRecord::uri($oAccount->Email(), $secret, $issuer),
			\Tachyon\Util\QRCode::ERROR_CORRECT_LEVEL_M
		);
		return TwoFactorRecord::svg($QR->getModuleCount(), fn (int $r, int $c) => $QR->isDark($r, $c));
	}

	public function DoShowTwoFactorSecret() : array
	{
		$oAccount = $this->getMainAccountFromToken();

		if (!$this->TwoFactorAuthProvider($oAccount)) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$this->requireCurrentCode($oAccount);

		$aRecord = $this->loadRecord($oAccount);
		$sSecret = $aRecord ? (string) TwoFactorRecord::unseal((string) $aRecord['SecretBox'], $this->recordKey()) : '';
		if ('' === $sSecret) {
			return $this->jsonResponse(__FUNCTION__, false);
		}
		return $this->jsonResponse(__FUNCTION__, array(
			'User' => $oAccount->Email(),
			'Secret' => $sSecret,
			'QRCode' => $this->getQRCode($oAccount, $sSecret)
		));
	}

	/**
	 * On: only once a code from the authenticator has been accepted (`Tested`);
	 * otherwise a mistyped enrolment locks the person out at the next login.
	 * Off: a current code is required, see requireCurrentCode().
	 */
	public function DoEnableTwoFactor() : array
	{
		$oAccount = $this->getMainAccountFromToken();

		if (!$this->TwoFactorAuthProvider($oAccount)) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$bEnable = '1' === \trim($this->jsonParam('Enable', '0'));
		if (!$bEnable) {
			$this->requireCurrentCode($oAccount);
		}

		$aRecord = $this->loadRecord($oAccount);
		if (!$aRecord || ($bEnable && empty($aRecord['Tested']))) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$oActions = $this->Manager()->Actions();
		if ($oActions->HasActionParam('EnableTwoFactor')) {
			$sValue = $oActions->GetActionParam('EnableTwoFactor', '');
			$oActions->SettingsProvider()->Load($oAccount)->SetConf('EnableTwoFactor', !empty($sValue));
		}

		$aRecord['Enable'] = $bEnable;
		return $this->jsonResponse(__FUNCTION__, $this->saveRecord($oAccount, $aRecord));
	}

	/** The test of the authenticator: counted, rate-limited and replay-guarded like a login. */
	public function DoVerifyTwoFactorCode() : array
	{
		$oAccount = $this->getMainAccountFromToken();
		$aRecord = $this->loadRecord($oAccount);
		if (!$this->TwoFactorAuthProvider($oAccount) || !$aRecord) {
			return $this->jsonResponse(__FUNCTION__, false);
		}
		$sCode = \trim((string) $this->jsonParam('Code', ''));
		return $this->jsonResponse(__FUNCTION__, 'ok' === $this->checkCode($oAccount, $aRecord, $sCode, true));
	}

	public function DoClearTwoFactorInfo() : array
	{
		$oAccount = $this->getMainAccountFromToken();

		if (!$this->TwoFactorAuthProvider($oAccount)) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$this->requireCurrentCode($oAccount);

		$this->StorageProvider()->Clear($oAccount,
			\Tachyon\Providers\Storage\Enumerations\StorageType::CONFIG,
			'two_factor'
		);
		$this->Logger()->Write("TFA: Second factor removed for {$oAccount->Email()}");

		return $this->jsonResponse(__FUNCTION__, $this->getTwoFactorInfo($oAccount, true));
	}

	/**
	 * A wrong, replayed or locked code at login, in the auth log (and syslog)
	 * fail2ban reads, in the format the core uses for a wrong password. A log
	 * that cannot be written must not turn the refusal into another error.
	 */
	protected function logAuthFailure(MainAccount $oAccount) : void
	{
		try {
			$this->Manager()->Actions()->LoggerAuthHelper($oAccount, $oAccount->Email());
		} catch (\Throwable $oError) {
			$this->Logger()->Write('TFA: auth log not written: ' . $oError->getMessage());
		}
	}

	/* ---- the record ---- */

	protected function recordKey() : string
	{
		return TwoFactorRecord::key(\APP_SALT);
	}

	/**
	 * The stored record in its current shape, or null when there is none for
	 * this account. A record still in the clear format of 2.19.1 and earlier is
	 * sealed and written back here, the first time it is read.
	 */
	protected function loadRecord(MainAccount $oAccount) : ?array
	{
		$sData = $this->StorageProvider()->Get($oAccount,
			\Tachyon\Providers\Storage\Enumerations\StorageType::CONFIG,
			'two_factor'
		);
		if (false === $sData) {
			return null;
		}
		$mData = static::DecodeKeyValues($sData);
		if (null === $mData || empty($mData['User']) || $oAccount->Email() !== $mData['User']
			|| (empty($mData['Secret']) && empty($mData['SecretBox']))) {
			// A record is there but unusable (an old serialized one, empty,
			// damaged, or not this account's). Treating it as "no second factor"
			// would let the password alone in, so refuse until an admin clears it.
			$this->Logger()->Write("TFA: unreadable record for {$oAccount->Email()}, refusing;"
				. " delete storage CONFIG key two_factor of this main account to let it in again", \LOG_ERR);
			throw new ClientException(\Tachyon\Notifications::AuthError);
		}
		$aRecord = TwoFactorRecord::normalise($mData, $this->recordKey());
		if (TwoFactorRecord::isLegacy($mData)) {
			$this->saveRecord($oAccount, $aRecord);
		}
		return $aRecord;
	}

	protected function saveRecord(MainAccount $oAccount, array $aRecord) : bool
	{
		return $this->StorageProvider()->Put($oAccount,
			\Tachyon\Providers\Storage\Enumerations\StorageType::CONFIG,
			'two_factor',
			\json_encode($aRecord)
		);
	}

	/**
	 * One code, checked and recorded: failures counted, spent backup codes and
	 * the last time step stored, whatever the outcome.
	 */
	protected function checkCode(MainAccount $oAccount, array $aRecord, string $sCode, bool $bMarkTested = false) : string
	{
		$oProvider = $this->TwoFactorAuthProvider($oAccount);
		// A provider that only answers yes or no is taken to match the current step.
		$fSlice = \method_exists($oProvider, 'MatchingSlice')
			? fn (string $sSecret, string $s) => $oProvider->MatchingSlice($sSecret, $s)
			: fn (string $sSecret, string $s) => $oProvider->VerifyCode($sSecret, $s) ? \intdiv(\time(), 30) : null;
		[$sOutcome, $aNew] = TwoFactorRecord::check($aRecord, $sCode, $this->recordKey(), \time(), $fSlice);
		if ('ok' === $sOutcome && $bMarkTested) {
			$aNew['Tested'] = true;
		}
		$this->saveRecord($oAccount, $aNew);
		// "replay" and "locked" are not "wrong": a log that merges them loses
		// the one line worth reading.
		$this->Logger()->Write("TFA: {$sOutcome} code for {$oAccount->Email()}");
		return $sOutcome;
	}

	protected function Logger() : \MailSo\Log\Logger
	{
		return $this->Manager()->Actions()->Logger();
	}
	protected function getMainAccountFromToken() : MainAccount
	{
		return $this->Manager()->Actions()->getMainAccountFromToken();
	}
	protected function StorageProvider() : \Tachyon\Providers\Storage
	{
		return $this->Manager()->Actions()->StorageProvider();
	}

	private $oTwoFactorAuthProvider = null;
	protected function TwoFactorAuthProvider(MainAccount $oAccount) : ?TwoFactorAuthInterface
	{
		if (!$this->oTwoFactorAuthProvider) {
			require_once __DIR__ . '/providers/interface.php';
			require_once __DIR__ . '/providers/totp.php';
			$this->oTwoFactorAuthProvider = new TwoFactorAuthTotp();
		}
		return $this->oTwoFactorAuthProvider;
	}

	/**
	 * What the settings screen may know: never the secret, the backup codes or
	 * the QR code (which encodes the secret).
	 */
	protected function getTwoFactorInfo(MainAccount $oAccount, bool $bRemoveSecret = false) : array
	{
		$aRecord = $this->loadRecord($oAccount);
		return array(
			'User' => $oAccount->Email(),
			'IsSet' => null !== $aRecord,
			'Enable' => $aRecord ? !empty($aRecord['Enable']) : false,
			'Tested' => $aRecord ? !empty($aRecord['Tested']) : false
		);
	}

	/**
	 * The stored record as an array, or null when it is there but cannot be
	 * read. JSON only: records of very old versions were serialized, and
	 * unserialize() is not run on stored data any more.
	 */
	private static function DecodeKeyValues(string $sData) : ?array
	{
		if (!\str_contains($sData, 'User')) {
			$sData = \MailSo\Base\Utils::UrlSafeBase64Decode($sData);
			if (!\strlen($sData)) {
				return null;
			}
			$sKey = \md5(APP_SALT);
			$sData = \is_callable('xxtea_decrypt')
				? \xxtea_decrypt($sData, $sKey)
				: \MailSo\Base\Xxtea::decrypt($sData, $sKey);
		}
		$mData = \json_decode((string) $sData, true);
		return \is_array($mData) ? $mData : null;
	}
}
