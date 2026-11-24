<?php

use Tachyon\Enumerations\PluginPropertyType;
use Tachyon\Plugins\AbstractPlugin;
use Tachyon\Plugins\Property;

class LDAPLoginMappingPlugin extends AbstractPlugin
{
	const
		NAME     = 'LDAP login mapping',
		VERSION  = '2.5',
		AUTHOR   = 'RainLoop Team, Ludovic Pouzenc<ludovic@pouzenc.fr>, ZephOne<zephone@protonmail.com>',
		RELEASE  = '2026-11-24',
		REQUIRED = '2.36.1',
		CATEGORY = 'Login',
		DESCRIPTION = 'Enable custom mapping using ldap field';
	/**
	 * @var array
	 */
	private $aDomains = array();

	/**
	 * @var string
	 */
	private $sSearchDomain = '';

	/**
	 * @var string
	 */
	private $sHostName = '127.0.0.1';

	/**
	 * @var int
	 */
	private $iHostPort = 389;

	/**
	 * @var string
	 */
	private $sUsersDn = '';

	/**
	 * @var string
	 */
	private $sObjectClass = 'inetOrgPerson';

	/**
	 * @var string
	 */
	private $sLoginField = 'uid';

	/**
	 * @var string
	 */
	private $sEmailField = 'mail';

	/**
	 * @var \MailSo\Log\Logger
	 */
	private $oLogger = null;

	public function Init(): void
	{
		$this->addHook('login.credentials', 'FilterLoginСredentials');
	}

	/**
	 * @return string
	 */
	public function Supported(): string
	{
		if (!\function_exists('ldap_connect'))
		{
			return 'The LDAP PHP extension must be installed to use this plugin';
		}

		return '';
	}

	/**
	 * @param string $sEmail
	 * @param string $sImapUser
	 * @param string $sPassword
	 * @param string $sSmtpUser
	 *
	 * @throws \Tachyon\Exceptions\ClientException
	 */
	public function FilterLoginСredentials(string &$sEmail, string &$sImapUser, string &$sPassword, string &$sSmtpUser)
	{
		$this->oLogger = \Tachyon\Api::Logger();

		$this->aDomains = explode(',', $this->Config()->Get('plugin', 'domains', ''));
		$this->sSearchDomain = trim($this->Config()->Get('plugin', 'search_domain', ''));
		$this->sHostName = trim($this->Config()->Get('plugin', 'hostname', ''));
		$this->iHostPort = (int) $this->Config()->Get('plugin', 'port', 389);
		$this->sUsersDn = trim($this->Config()->Get('plugin', 'users_dn', ''));
		$this->sObjectClass = trim($this->Config()->Get('plugin', 'object_class', ''));
		$this->sLoginField = trim($this->Config()->Get('plugin', 'login_field', ''));
		$this->sEmailField = trim($this->Config()->Get('plugin', 'mail_field', ''));

		if (0 < \strlen($this->sObjectClass) && 0 < \strlen($this->sEmailField))
		{
			$sResult = $this->ldapSearch($sEmail);
			if ( is_array($sResult) ) {
				$sImapUser = $sResult['login'];
				$sSmtpUser = $sResult['login'];
				$sEmail = $sResult['email'];
				$this->oLogger->Write("mapped to {$sImapUser}/{$sEmail}", \LOG_INFO, 'LDAP');
			}
		}
	}

	/**
	 * @return array
	 */
	public function configMapping(): array
	{
		return [
			Property::NewInstance('domains')
				->SetLabel('LDAP enabled domains')
				->SetDefaultValue('example1.com,example2.com'),

			Property::NewInstance('search_domain')
				->SetLabel('Forced domain')
				->SetDescription('Force this domain email for LDAP search')
				->SetDefaultValue('example.com'),

			Property::NewInstance('hostname')
				->SetLabel('LDAP hostname')
				->SetDefaultValue('127.0.0.1'),

			Property::NewInstance('port')
				->SetLabel('LDAP port')
				->SetType(PluginPropertyType::INT)
				->SetDefaultValue(389),

			Property::NewInstance('users_dn')
				->SetLabel('Search base DN')
				->SetDescription('LDAP users search base DN. No tokens.')
				->SetDefaultValue('ou=People,dc=domain,dc=com'),

			Property::NewInstance('object_class')
				->SetLabel('objectClass value')
				->SetDefaultValue('inetOrgPerson'),

			Property::NewInstance('login_field')
				->SetLabel('Login field')
				->SetDefaultValue('uid'),

			Property::NewInstance('mail_field')
				->SetLabel('Mail field')
				->SetDefaultValue('mail'),
		];
	}

	/**
	 * @return array|false ['login' => ..., 'email' => ...] or false
	 */
	private function ldapSearch($sEmail)
	{
		$bFound = FALSE;
		foreach ( $this->aDomains as $sDomain ) {
			$sRegex = '/^[a-z0-9._-]+@' . preg_quote(trim($sDomain), '/') . '$/Di';
			$this->oLogger->Write('DEBUG regex ' . $sRegex, \LOG_INFO, 'LDAP');
			if ( preg_match($sRegex, $sEmail) === 1) {
				$bFound = TRUE;
				break;
			}
		}
		if ( !$bFound ) {
			$this->oLogger->Write(
				'preg_match: no match in "' . $sEmail . '" for /^[a-z0-9._-]+@{configured-domains}$/i',
				\LOG_INFO,
				'LDAP');
			return FALSE;
		}
		$sLogin = \MailSo\Base\Utils::getEmailAddressLocalPart($sEmail);

		$this->oLogger->Write('ldap_connect: trying...', \LOG_INFO, 'LDAP');

		$oCon = @\ldap_connect($this->sHostName, $this->iHostPort);
		if (!$oCon) return FALSE;

		$this->oLogger->Write('ldap_connect: connected', \LOG_INFO, 'LDAP');

		@\ldap_set_option($oCon, LDAP_OPT_PROTOCOL_VERSION, 3);

		if (!@\ldap_bind($oCon)) {
			$this->logLdapError($oCon, 'ldap_bind');
			return FALSE;
		}
		$sSearchDn = $this->sUsersDn;
		$aItems = array($this->sLoginField, $this->sEmailField);
		$sFilter = $this->searchFilter($sEmail, $sLogin);
		$this->oLogger->Write('ldap_search: start: '.$sSearchDn.' / '.$sFilter, \LOG_INFO, 'LDAP');
		$oS = @\ldap_search($oCon, $sSearchDn, $sFilter, $aItems, 0, 30, 30);
		if (!$oS) {
			$this->logLdapError($oCon, 'ldap_search');
			return FALSE;
		}
		$aEntries = @\ldap_get_entries($oCon, $oS);
		if (!is_array($aEntries)) {
			$this->logLdapError($oCon, 'ldap_get_entries');
			return FALSE;
		}
		if (!isset($aEntries[0])) {
			$this->logLdapError($oCon, 'ldap_get_entries (no result)');
			return FALSE;
		}
		if (!isset($aEntries[0][$this->sLoginField][0])) {
			$this->logLdapError($oCon, 'ldap_get_entries (no login)');
			return FALSE;
		}
		if (!isset($aEntries[0][$this->sEmailField][0])) {
			$this->logLdapError($oCon, 'ldap_get_entries (no mail)');
			return FALSE;
		}
		$sLogin = $aEntries[0][$this->sLoginField][0];
		$sEmail = $aEntries[0][$this->sEmailField][0];
		$this->oLogger->Write('ldap_search: found "' . $this->sLoginField . ': '.$sLogin . '" and "' . $this->sEmailField . ': '.$sEmail . '"');

		return array(
			'login' => $sLogin,
			'email' => $sEmail,
		);
	}

	/**
	 * The search filter for a login. The values are escaped even though
	 * ldapSearch() only lets simple addresses through: the filter must not
	 * depend on that check staying as strict as it is.
	 */
	private function searchFilter(string $sEmail, string $sLogin) : string
	{
		$sMail = 0 < \strlen($this->sSearchDomain) ? $sLogin.'@'.$this->sSearchDomain : $sEmail;
		return '(&(objectclass='.$this->sObjectClass.')(|('
			.$this->sEmailField.'='.\ldap_escape($sMail, '', LDAP_ESCAPE_FILTER).')('
			.$this->sLoginField.'='.\ldap_escape($sLogin, '', LDAP_ESCAPE_FILTER).')))';
	}

	/**
	 * @param mixed $oCon
	 * @param string $sCmd
	 *
	 * @return string
	 */
	private function logLdapError($oCon, $sCmd)
	{
		if ($this->oLogger)
		{
			$sError = $oCon ? @\ldap_error($oCon) : '';
			$iErrno = $oCon ? @\ldap_errno($oCon) : 0;

			$this->oLogger->Write($sCmd.' error: '.$sError.' ('.$iErrno.')',
				\LOG_WARNING, 'LDAP');
		}
	}

}
