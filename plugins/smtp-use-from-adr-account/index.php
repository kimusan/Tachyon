<?php

class SmtpUseFromAdrAccountPlugin extends \Tachyon\Plugins\AbstractPlugin
{

	const
		NAME = 'Use From-Address-Account for smtp',
		AUTHOR   = 'attike',
		URL      = 'https://github.com/attike',
		VERSION  = '1.1',
		RELEASE  = '2024-03-12',
		REQUIRED = '2.35.3',
		CATEGORY = 'Filters',
		DESCRIPTION = 'Set smpt-config and -credentials based on selected from-address-account';

	public $aFromAccount = array();

	public function Init() : void
	{
		$this->addHook('filter.smtp-from', 'FilterDetectFrom');
		$this->addHook('smtp.before-connect', 'FilterSmtpConnect');
		$this->addHook('smtp.before-login', 'FilterSmtpCredentials');
	}

	/**
	 * \Tachyon\Model\Account $oAccount
	 * \MailSo\Mime\Message $oMessage
	 * string &$sFrom
	 */
	public function FilterDetectFrom(\Tachyon\Model\Account $oAccount, \MailSo\Mime\Message $oMessage, string &$sFrom)
	{
		$sWhiteList = \trim($this->Config()->Get('plugin', 'from_adress_pattern', ''));
		$sFoundValue = '';
		if (\strlen($sWhiteList) && \Tachyon\Plugins\Helper::ValidateWildcardValues($sFrom, $sWhiteList, $sFoundValue) && $sFrom != $oAccount->Email()) {
			\Tachyon\Util\LOG::info(get_class($this) ,'From address different from account recognized: '. $oAccount->Email().' -> '.$sFrom . '(~ '.$sFoundValue.')');
			$oMainAccount;
			$oFromAccount;
			if ($oAccount instanceof \Tachyon\Model\MainAccount ) {
				$oMainAccount=$oAccount;
			} else {
				$oMainAccount=$this->Manager()->Actions()->getMainAccountFromToken();
				if ($oMainAccount->Email() == $sFrom) {
					$this->aFromAccount[$oAccount->Email()]=$oMainAccount;
					return;
				}
			}
			$aAccounts = $this->Manager()->Actions()->getAccounts($oMainAccount);
			foreach ($aAccounts as &$value) {
					$oValue=\Tachyon\Model\AdditionalAccount::NewInstanceFromTokenArray($this->Manager()->Actions(), $value);
					if ($oValue->Email()==$sFrom) {
							$oFromAccount = $oValue;
							break;
					}
			}
			if (is_null($oFromAccount)){
				\Tachyon\Util\LOG::info(get_class($this),'No Account found for '. $sFrom);
				if ($this->Config()->Get('plugin', 'throw_notfound_exception', true)) {
					throw new \Tachyon\Exceptions\ClientException(\Tachyon\Notifications::AccountDoesNotExist);
				}
				return;
			}
			$this->aFromAccount[$oAccount->Email()]=$oFromAccount;
		}
	}
	/**
	 * @param \Tachyon\Model\Account $oAccount
	 * @param \MailSo\Smtp\SmtpClient $oSmtpClient
	 * @param \MailSo\Smtp\Settings $oSettings
	 */
	public function FilterSmtpConnect(\Tachyon\Model\Account $oAccount, \MailSo\Smtp\SmtpClient $oSmtpClient, \MailSo\Smtp\Settings $oSettings)
	{
		if ( isset($this->aFromAccount[$oAccount->Email()]) ) {
			$oFromAccount = $this->aFromAccount[$oAccount->Email()];
			$oSettings->host = $oFromAccount->Domain()->SmtpSettings()->host;
			$oSettings->port = (int) $oFromAccount->Domain()->SmtpSettings()->port;
			$oSettings->type = $oFromAccount->Domain()->SmtpSettings()->type;
			\Tachyon\Util\LOG::info(get_class($this),'Smtp config rewrite: '. $oSettings->host);
		}
	}

	/**
	 * @param \Tachyon\Model\Account $oAccount
	 * @param \MailSo\Smtp\SmtpClient $oSmtpClient
	 * @param \MailSo\Smtp\Settings $oSettings
	 */
	public function FilterSmtpCredentials(\Tachyon\Model\Account $oAccount, \MailSo\Smtp\SmtpClient $oSmtpClient, \MailSo\Smtp\Settings $oSettings)
	{
		if ( isset($this->aFromAccount[$oAccount->Email()]) ) {
			$oFromAccount = $this->aFromAccount[$oAccount->Email()];
			unset($this->aFromAccount[$oAccount->Email()]);
			$oSettings->useAuth = $oFromAccount->Domain()->SmtpSettings()->useAuth;
			$oSettings->username = $oFromAccount->OutLogin();
			$oSettings->passphrase = $oFromAccount->IncPassword();
			\Tachyon\Util\LOG::info(get_class($this),'user/pwd rewrite: '. $oFromAccount->Email());
		}
	}

	/**
	 * @return array
	 */
	protected function configMapping() : array
	{
		return array(
			\Tachyon\Plugins\Property::NewInstance('from_adress_pattern')->SetLabel('From-Address pattern')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING_TEXT)
				->SetDescription('space as delimiter, wildcard supported.')
				->SetDefaultValue('user@example.com *@example2.com'),
			\Tachyon\Plugins\Property::NewInstance('throw_notfound_exception')->SetLabel('Throw Exception, if from-adr is not found as account')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDescription('it is not possible to send eMails in this case, regardless of whether the smtp-server would do it')
				->SetDefaultValue(true)
		);
	}

}
