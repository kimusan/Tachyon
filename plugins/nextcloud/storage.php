<?php

use Tachyon\Providers\Storage\Enumerations\StorageType;

class NextcloudStorage extends \Tachyon\Providers\Storage\FileStorage
{
	/**
	 * @param \Tachyon\Model\Account|string|null $mAccount
	 */
	public function GenerateFilePath($mAccount, int $iStorageType, bool $bMkDir = false) : string
	{
		$sDataPath = parent::GenerateFilePath($mAccount, $iStorageType, $bMkDir);
		if (StorageType::CONFIG === $iStorageType) {
			$sUID = \OC::$server->get(\OCP\IUserSession::class)->getUser()->getUID();
			$sDataPath .= ".config/{$sUID}/";
			if ($bMkDir && !\is_dir($sDataPath) && !\mkdir($sDataPath, 0700, true)) {
				throw new \Tachyon\Exceptions\Exception('Can\'t make storage directory "'.$sDataPath.'"');
			}
		}
		return $sDataPath;
	}
}
