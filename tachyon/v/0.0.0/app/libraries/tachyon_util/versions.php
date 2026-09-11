<?php

namespace Tachyon\Util;

/**
 * Application directories left behind by upgrades.
 *
 * Upgrade::core() extracts each release beside the previous one and rewrites
 * index.php, which is the only thing naming the running version, so every
 * earlier tree stays on disk indefinitely. An install that came through
 * RainLoop and SnappyMail carries those lineages as well.
 */
abstract class Versions
{
	/**
	 * Directory names one level under the install root that hold versioned
	 * trees. A product name only means anything in that exact position: an
	 * install can sit in a directory called rainloop and still be a current
	 * Tachyon (github.com/kimusan/Tachyon/issues/68), so the name alone is
	 * never evidence of a leftover.
	 */
	public const PRODUCTS = array('tachyon', 'snappymail', 'rainloop');

	/**
	 * Every <root>/<product>/v/<version> directory, the running one included so
	 * an admin can see why it cannot be selected.
	 */
	public static function installed() : array
	{
		$aResult = array();

		foreach (static::discover() as $aItem) {
			$aResult[] = array(
				'product' => $aItem['product'],
				'version' => $aItem['version'],
				'size' => static::size($aItem['path']),
				'current' => static::isCurrent($aItem['product'], $aItem['version']),
				/**
				 * Only the tree and its parent, which is two stats rather than a
				 * walk. It catches the ordinary case, a whole tree left behind by
				 * an older install and owned by another user. A single unwritable
				 * directory buried inside still gets caught by remove().
				 */
				'removable' => \is_writable($aItem['path']) && \is_writable(\dirname($aItem['path']))
			);
		}

		return $aResult;
	}

	/**
	 * What is on disk, without measuring any of it.
	 *
	 * Sizing walks every file of every tree, which on an install with a long
	 * upgrade history is the slowest thing here, so anything that only needs to
	 * know which versions exist must not pay for it.
	 */
	private static function discover() : array
	{
		$aResult = array();

		foreach (static::PRODUCTS as $sProduct) {
			$sBase = static::root() . $sProduct . '/v';
			if (!\is_dir($sBase)) {
				continue;
			}
			foreach (\scandir($sBase) ?: array() as $sVersion) {
				$sPath = static::resolve($sProduct, $sVersion);
				if ($sPath) {
					$aResult[] = array(
						'product' => $sProduct,
						'version' => $sVersion,
						'path' => $sPath
					);
				}
			}
		}

		return $aResult;
	}

	/**
	 * Deletes one dormant tree, or throws saying why it will not.
	 */
	public static function remove(string $sProduct, string $sVersion) : void
	{
		if (static::isCurrent($sProduct, $sVersion)) {
			throw new \Exception('Refusing to delete the running version');
		}

		$sPath = static::resolve($sProduct, $sVersion);

		// The filesystem decides what exists, never the request
		if (!$sPath || !static::isListed($sProduct, $sVersion)) {
			throw new \Exception('No such version directory');
		}

		/**
		 * APP_DATA_FOLDER_PATH is overridable from a root include.php through
		 * __get_custom_data_full_path(), so the data folder is not reliably
		 * <root>/data and cannot be excluded by name.
		 */
		if (static::touchesDataFolder($sPath)) {
			throw new \Exception('Refusing to delete a directory holding the data folder');
		}

		/**
		 * Check first, because RecRmDir() removes everything it can and only then
		 * reports failure, which leaves a half deleted tree behind. Refusing up
		 * front keeps the directory intact and gives the admin a path to fix.
		 *
		 * Removing an entry needs write permission on the directory holding it,
		 * not on the entry itself, so that is what gets tested.
		 */
		$sBlocked = static::firstUnremovable($sPath);
		if (null !== $sBlocked) {
			throw new \Exception('Not writable, nothing was deleted: ' . $sBlocked);
		}

		if (!\MailSo\Base\Utils::RecRmDir($sPath)) {
			throw new \Exception('Delete failed after starting, some files may remain in ' . $sPath);
		}
	}

	/**
	 * The first directory in the tree the web server cannot delete out of, or
	 * null when the whole tree can go. Also covers the parent, since removing
	 * the version directory itself writes to <product>/v.
	 */
	private static function firstUnremovable(string $sPath) : ?string
	{
		if (!\is_writable(\dirname($sPath))) {
			return \dirname($sPath);
		}
		if (!\is_writable($sPath)) {
			return $sPath;
		}

		try {
			$oDirs = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($sPath, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ($oDirs as $oItem) {
				if ($oItem->isDir() && !$oItem->isLink() && !\is_writable($oItem->getPathname())) {
					return $oItem->getPathname();
				}
			}
		} catch (\Throwable $oException) {
			return $sPath . ' (' . $oException->getMessage() . ')';
		}

		return null;
	}

	public static function isCurrent(string $sProduct, string $sVersion) : bool
	{
		return 'tachyon' === $sProduct && \defined('APP_VERSION') && APP_VERSION === $sVersion;
	}

	/**
	 * Rebuilds the path from two tokens and proves it is inside the install
	 * root. Nothing resembling a path is ever accepted from the caller.
	 */
	private static function resolve(string $sProduct, string $sVersion) : ?string
	{
		if (!\in_array($sProduct, static::PRODUCTS, true) || !static::validVersion($sVersion)) {
			return null;
		}

		$sPath = \realpath(static::root() . $sProduct . '/v/' . $sVersion);
		$sRoot = \realpath(static::root());

		if (!$sPath || !$sRoot || !\is_dir($sPath)) {
			return null;
		}

		// A symlinked version directory is not a way out of the install root
		return \str_starts_with(
			\rtrim($sPath, '\\/') . \DIRECTORY_SEPARATOR,
			\rtrim($sRoot, '\\/') . \DIRECTORY_SEPARATOR
		) ? $sPath : null;
	}

	/** Digits and dots only, so no separator and no traversal can be expressed */
	private static function validVersion(string $sVersion) : bool
	{
		return (bool) \preg_match('/^[0-9][0-9.]*$/', $sVersion) && !\str_contains($sVersion, '..');
	}

	private static function isListed(string $sProduct, string $sVersion) : bool
	{
		foreach (static::discover() as $aItem) {
			if ($aItem['product'] === $sProduct && $aItem['version'] === $sVersion) {
				return true;
			}
		}
		return false;
	}

	/** Whether the tree is, sits inside, or contains the data folder */
	private static function touchesDataFolder(string $sPath) : bool
	{
		$sData = \defined('APP_DATA_FOLDER_PATH') ? \realpath(APP_DATA_FOLDER_PATH) : false;
		if (!$sData) {
			// Unknown rather than absent, so the safe answer is to refuse
			return true;
		}

		$sPath = \rtrim($sPath, '\\/') . \DIRECTORY_SEPARATOR;
		$sData = \rtrim($sData, '\\/') . \DIRECTORY_SEPARATOR;

		return \str_starts_with($sData, $sPath) || \str_starts_with($sPath, $sData);
	}

	private static function size(string $sPath) : int
	{
		$iSize = 0;
		try {
			$oFiles = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($sPath, \FilesystemIterator::SKIP_DOTS)
			);
			foreach ($oFiles as $oFile) {
				if ($oFile->isFile() && !$oFile->isLink()) {
					$iSize += $oFile->getSize();
				}
			}
		} catch (\Throwable $oException) {
			// A directory we cannot walk still deserves a row, just without a size
		}
		return $iSize;
	}

	private static function root() : string
	{
		return \rtrim(APP_INDEX_ROOT_PATH, '\\/') . '/';
	}
}
