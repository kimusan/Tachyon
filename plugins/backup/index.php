<?php

class BackupPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME     = 'Backup',
		AUTHOR   = 'Tachyon',
		URL      = 'https://github.com/kimusan/Tachyon',
		VERSION  = '1.2',
		RELEASE  = '2024-03-18',
		REQUIRED = '2.30.0',
		CATEGORY = 'General',
		LICENSE  = 'MIT',
		DESCRIPTION = '';

	public function Init() : void
	{
		// Admin Settings tab
		$this->addJs('js/BackupAdminSettings.js', true); // add js file
		$this->addJsonHook('JsonAdminBackupData');
		$this->addJsonHook('JsonAdminRestoreData');
		$this->addTemplate('templates/BackupAdminSettingsTab.html', true);
	}

	public function JsonAdminBackupData()
	{
		if (!($this->Manager()->Actions() instanceof \Tachyon\ActionsAdmin)
		 || !$this->Manager()->Actions()->IsAdminLoggined()
		) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$sFileName = APP_PRIVATE_DATA . \MailSo\Base\Utils::Sha1Rand() . '.zip';
		$sType = 'application/zip';

		$oArchive = new \Tachyon\Util\Stream\ZIP($sFileName);

		foreach (['configs', 'domains', 'plugins', 'storage'] as $dir) {
			$sDir = APP_PRIVATE_DATA . $dir;
			if (\is_dir($sDir)) {
				$oArchive->addRecursive($sDir, $dir);
			}
		}
		if (\is_readable(APP_PRIVATE_DATA.'AddressBook.sqlite')) {
			$oArchive->addFile(APP_PRIVATE_DATA.'AddressBook.sqlite');
		}
		$oArchive->close();

		$data = \base64_encode(\file_get_contents($sFileName));
		\unlink($sFileName);

		return $this->jsonResponse(__FUNCTION__, array(
			'name' => \basename($sFileName),
			'data' => "data:{$sType};base64,{$data}"
		));
	}

	public function JsonAdminRestoreData()
	{
		if (!($this->Manager()->Actions() instanceof \Tachyon\ActionsAdmin)
		 || empty($_FILES['backup'])
		 || 'application/zip' !== $_FILES['backup']['type']
		 || !\is_uploaded_file($_FILES['backup']['tmp_name'])
		) {
			return $this->jsonResponse(__FUNCTION__, false);
		}

		$result = false;
		if (\class_exists('ZipArchive')) {
			$oArchive = new \ZipArchive();
			$oArchive->open($_FILES['backup']['tmp_name'], \ZIPARCHIVE::CREATE);
			$result = $oArchive->extractTo(APP_PRIVATE_DATA);
		} else if (\class_exists('PharData')) {
			$oArchive = new \PharData($sTmp, 0, null, \Phar::GZ);
			$result = $oArchive->extractTo(APP_PRIVATE_DATA);
		}

		return $this->jsonResponse(__FUNCTION__, $result);
	}

}
