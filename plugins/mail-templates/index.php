<?php

/**
 * Templates are messages in a mailbox folder, named "Templates" by default, the
 * same place and the same shape Thunderbird uses. So they are in the mailbox
 * backup, they travel with the mailbox, and another client sees them too.
 *
 * Saving one goes through the core SaveMessage action rather than anything here,
 * which is what keeps the MIME building, the identity and the attachment
 * handling in one place. This plugin answers where to put it, lists what is
 * there, reads one back and deletes one.
 */
class MailTemplatesPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME     = 'Mail Templates',
		AUTHOR   = 'Tachyon',
		URL      = 'https://github.com/kimusan/Tachyon',
		VERSION  = '0.3',
		RELEASE  = '2026-10-28',
		REQUIRED = '4.5.0',
		CATEGORY = 'General',
		LICENSE  = 'MIT',
		DESCRIPTION = 'Save a message as a template and reuse it, stored as messages in a mailbox folder';

	public function Init() : void
	{
		$this->UseLangs(true);

		$this->addJs('js/MailTemplates.js');
		$this->addTemplate('templates/ComposeMailTemplates.html');
		$this->addTemplate('templates/PopupsMailTemplates.html');

		$this->addJsonHook('MailTemplatesFolder', 'DoMailTemplatesFolder');
		$this->addJsonHook('MailTemplatesList', 'DoMailTemplatesList');
		$this->addJsonHook('MailTemplateGet', 'DoMailTemplateGet');
		$this->addJsonHook('MailTemplateDelete', 'DoMailTemplateDelete');
	}

	protected function configMapping() : array
	{
		return array(
			\Tachyon\Plugins\Property::NewInstance('folder')
				->SetLabel('Folder')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::STRING)
				->SetDefaultValue('Templates')
				->SetDescription('Mailbox folder holding the templates. "Templates" is what Thunderbird uses.'),
			\Tachyon\Plugins\Property::NewInstance('limit')
				->SetLabel('Maximum templates')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::INT)
				->SetDefaultValue(200)
				->SetDescription('How many messages of the folder are offered')
		);
	}

	/**
	 * The folder, created when it is not there yet. Only called when a template
	 * is about to be written, so opening the menu never adds a folder to the
	 * mailbox of someone who then changes their mind.
	 */
	public function DoMailTemplatesFolder()
	{
		$oMailClient = $this->mailClient();
		$sFolder = $this->folderName();

		if (!$this->folderExists($oMailClient, $sFolder)) {
			$oFolder = $oMailClient->FolderCreate($sFolder);
			if (!$oFolder) {
				return $this->jsonResponse(__FUNCTION__, false);
			}
			$sFolder = $oFolder->FullName;
		}

		return $this->jsonResponse(__FUNCTION__, $sFolder);
	}

	/**
	 * The subject is the name, because that is the one field of a message every
	 * other client shows in a folder listing.
	 */
	public function DoMailTemplatesList()
	{
		$oMailClient = $this->mailClient();
		$sFolder = $this->folderName();

		$aTemplates = array();
		if ($this->folderExists($oMailClient, $sFolder)) {
			$oParams = new \MailSo\Mail\MessageListParams;
			$oParams->sFolderName = $sFolder;
			$oParams->iLimit = \max(1, (int) $this->Config()->Get('plugin', 'limit', 200));
			$oParams->bUseThreads = false;
			foreach ($oMailClient->MessageList($oParams) as $oMessage) {
				$aTemplates[] = array(
					'uid' => $oMessage->Uid(),
					'subject' => $oMessage->Subject()
				);
			}
		}

		return $this->jsonResponse(__FUNCTION__, array(
			'folder' => $sFolder,
			'templates' => $aTemplates
		));
	}

	/**
	 * The body carries the ':HTML:' marker the editor's setHtmlOrPlain() reads,
	 * so a template written in either mode comes back as what it was.
	 */
	public function DoMailTemplateGet()
	{
		$iUid = (int) $this->jsonParam('uid', 0);
		if (1 > $iUid) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$oMailClient = $this->mailClient();
		$sFolder = $this->folderName();
		if (!$this->folderExists($oMailClient, $sFolder)) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$oMessage = $oMailClient->Message($sFolder, $iUid);
		if (!$oMessage) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$sHtml = (string) $oMessage->sHtml;

		return $this->jsonResponse(__FUNCTION__, array(
			'uid' => $iUid,
			'subject' => $oMessage->Subject(),
			'body' => \strlen($sHtml) ? ':HTML:' . $sHtml : (string) $oMessage->sPlain
		));
	}

	public function DoMailTemplateDelete()
	{
		$iUid = (int) $this->jsonParam('uid', 0);
		if (1 > $iUid) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$oMailClient = $this->mailClient();
		$sFolder = $this->folderName();
		if (!$this->folderExists($oMailClient, $sFolder)) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		// Selects the folder and expunges on its own
		$oMailClient->MessageDelete($sFolder, new \MailSo\Imap\SequenceSet($iUid));

		return $this->jsonResponse(__FUNCTION__, true);
	}

	private function folderName() : string
	{
		return \trim($this->Config()->Get('plugin', 'folder', 'Templates')) ?: 'Templates';
	}

	private function folderExists(\MailSo\Mail\MailClient $oMailClient, string $sFolder) : bool
	{
		$oFolders = $oMailClient->Folders('', $sFolder, false);
		if ($oFolders) {
			foreach ($oFolders as $oFolder) {
				if ($oFolder->FullName === $sFolder) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * A json hook can be the first thing to need IMAP in the request, so the
	 * connection is not established yet, the same way the nextcloud plugin has
	 * to do it.
	 */
	private function mailClient() : \MailSo\Mail\MailClient
	{
		$oActions = \Tachyon\Api::Actions();
		$oMailClient = $oActions->MailClient();
		if (!$oMailClient->IsLoggined()) {
			$oAccount = $oActions->getAccountFromToken();
			$oAccount->ImapConnectAndLogin($oActions->Plugins(), $oMailClient->ImapClient(), $oActions->Config());
		}
		return $oMailClient;
	}
}
