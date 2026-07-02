<?php

class BackupPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME     = 'Backup',
		AUTHOR   = 'Tachyon',
		URL      = 'https://github.com/kimusan/Tachyon',
		VERSION  = '1.3',
		RELEASE  = '2024-03-18',
		REQUIRED = '2.30.0',
		CATEGORY = 'General',
		LICENSE  = 'MIT',
		DESCRIPTION = '';

	public function Init() : void
	{
		// Admin Settings tab
		$this->addJs('js/BackupAdminSettings.js', true); // add js file
		$this->addJsonHook('JsonAdminRestoreData');
		$this->addTemplate('templates/BackupAdminSettingsTab.html', true);
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
			if (\ZipArchive::ER_OK === $oArchive->open($_FILES['backup']['tmp_name'])) {
				$result = $oArchive->extractTo(APP_PRIVATE_DATA);
				$oArchive->close();
			}
		}

		return $this->jsonResponse(__FUNCTION__, $result);
	}

}
