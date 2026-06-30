<?php
if (defined('APP_VERSION_ROOT_PATH')) {
	return;
}

// PHP 8 polyfill for servers still on PHP 7.x (will fail integrity check, but shows a readable error)
if (PHP_VERSION_ID < 80000) {
	require __DIR__ . '/app/libraries/polyfill/php8.php';
}

if (!extension_loaded('ctype')) {
	require __DIR__ . '/app/libraries/polyfill/ctype.php';
}

if (!extension_loaded('intl')) {
	require __DIR__ . '/app/libraries/polyfill/intl.php';
}

if (!defined('APP_VERSION')) {
	define('APP_VERSION', basename(__DIR__));
}
if (!defined('TACHYON_DEV')) {
	define('TACHYON_DEV', '0.0.0' === APP_VERSION);
}

if (!defined('APP_INDEX_ROOT_PATH')) {
	define('APP_INDEX_ROOT_PATH', dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR);
}

// revoke permissions
umask(0077);

ini_set('xdebug.max_nesting_level', '500');

define('APP_VERSION_ROOT_PATH', __DIR__ . DIRECTORY_SEPARATOR);

date_default_timezone_set('UTC');

$sCustomDataPath = '';
$sCustomConfiguration = '';

if (is_file(APP_INDEX_ROOT_PATH.'include.php')) {
	include_once APP_INDEX_ROOT_PATH.'include.php';
}

$sPrivateDataFolderInternalName = '';
if (defined('MULTIDOMAIN')) {
	$sPrivateDataFolderInternalName = strtolower(trim(empty($_SERVER['HTTP_HOST']) ? (empty($_SERVER['SERVER_NAME']) ? '' : $_SERVER['SERVER_NAME']) : $_SERVER['HTTP_HOST']));
	$sPrivateDataFolderInternalName = 'www.' === substr($sPrivateDataFolderInternalName, 0, 4) ? substr($sPrivateDataFolderInternalName, 4) : $sPrivateDataFolderInternalName;
	$sPrivateDataFolderInternalName = preg_replace('/^.+@/', '', preg_replace('/(.+\\..+):[\d]+$/', '$1', $sPrivateDataFolderInternalName));
	$sPrivateDataFolderInternalName = in_array($sPrivateDataFolderInternalName, array('', '127.0.0.1', '::1')) ? 'localhost' : $sPrivateDataFolderInternalName;
}
define('APP_PRIVATE_DATA_NAME', $sPrivateDataFolderInternalName ?: '_default_');
unset($sPrivateDataFolderInternalName);

if (!defined('APP_DATA_FOLDER_PATH')) {
	$sCustomDataPath = rtrim(trim(function_exists('__get_custom_data_full_path') ? __get_custom_data_full_path() : $sCustomDataPath), '\\/');
	define('APP_DATA_FOLDER_PATH', strlen($sCustomDataPath) ? $sCustomDataPath.'/' : APP_INDEX_ROOT_PATH.'data/');
}
unset($sCustomDataPath);

if (!defined('APP_CONFIGURATION_NAME')) {
	define('APP_CONFIGURATION_NAME', function_exists('__get_additional_configuration_name')
		? trim(__get_additional_configuration_name()) : $sCustomConfiguration);
}
unset($sCustomConfiguration);

//$sData = is_file(APP_DATA_FOLDER_PATH.'DATA.php') ? file_get_contents(APP_DATA_FOLDER_PATH.'DATA.php') : '';
//define('APP_PRIVATE_DATA', APP_DATA_FOLDER_PATH.'_data_'.($sData ? md5($sData) : '').'/'.APP_PRIVATE_DATA_NAME.'/');
define('APP_PRIVATE_DATA', APP_DATA_FOLDER_PATH.'_data_/'.APP_PRIVATE_DATA_NAME.'/');
define('APP_PLUGINS_PATH', APP_PRIVATE_DATA.'plugins/');

ini_set('default_charset', 'UTF-8');
ini_set('internal_encoding', 'UTF-8');

if (!defined('TACHYON_LIBRARIES_PATH')) {
	define('TACHYON_LIBRARIES_PATH', rtrim(realpath(__DIR__), '\\/').'/app/libraries/');

	if (false === set_include_path(TACHYON_LIBRARIES_PATH . PATH_SEPARATOR . get_include_path())) {
		exit('set_include_path() failed. Probably due to Apache config using php_admin_value instead of php_value');
	}

	spl_autoload_extensions('.php');
	/** custom autoloader */
	spl_autoload_register(function($sClassName){
		if (strpos($sClassName, 'Tachyon\\Util\\') === 0) {
			// Tachyon\Util\ -> lowercase mapping to tachyon_util/
			$file = TACHYON_LIBRARIES_PATH . 'tachyon_util' . DIRECTORY_SEPARATOR . strtolower(str_replace('\\', DIRECTORY_SEPARATOR, substr($sClassName, 13))) . '.php';
		} else {
			// Everything else -> case-sensitive
			$file = TACHYON_LIBRARIES_PATH . strtr($sClassName, '\\', DIRECTORY_SEPARATOR) . '.php';
		}
		if (is_file($file)) {
			include_once $file;
			return;
		}
		// Compatibility shims for user-installed plugins that use legacy namespaces.
		// RainLoop\ -> Tachyon\, SnappyMail\ -> Tachyon\Util\
		if (strpos($sClassName, 'RainLoop\\') === 0) {
			$alias = 'Tachyon\\' . substr($sClassName, 9);
			if (class_exists($alias) || interface_exists($alias)) {
				class_alias($alias, $sClassName);
			}
		} elseif (strpos($sClassName, 'SnappyMail\\') === 0) {
			$alias = 'Tachyon\\Util\\' . substr($sClassName, 11);
			if (class_exists($alias) || interface_exists($alias)) {
				class_alias($alias, $sClassName);
			}
		}
	});
}

// installation checking data folder
if (APP_VERSION !== (is_file(APP_DATA_FOLDER_PATH.'INSTALLED') ? file_get_contents(APP_DATA_FOLDER_PATH.'INSTALLED') : '')
 || !is_dir(APP_PRIVATE_DATA))
{
	include __DIR__ . '/setup.php';
}

mb_internal_encoding('UTF-8');
mb_language('uni');

$sSalt = is_file(APP_DATA_FOLDER_PATH.'SALT.php') ? trim(file_get_contents(APP_DATA_FOLDER_PATH.'SALT.php')) : '';
if (!$sSalt) {
	// random salt
	$sSalt = '<'.'?php //'.bin2hex(random_bytes(48));
	file_put_contents(APP_DATA_FOLDER_PATH.'SALT.php', $sSalt);
}
define('APP_SALT', md5($sSalt.APP_PRIVATE_DATA_NAME.$sSalt));

unset($sSalt, $sData);

if (!isset($_SERVER['HTTP_USER_AGENT'])) {
	$_SERVER['HTTP_USER_AGENT'] = '';
}

if (empty($_SERVER['HTTPS']) || 'off' === $_SERVER['HTTPS']) {
	unset($_SERVER['HTTPS']);
}
if (isset($_SERVER['REQUEST_SCHEME']) && 'https' === $_SERVER['REQUEST_SCHEME']) {
	$_SERVER['HTTPS'] = 'on';
}
if (isset($_SERVER['HTTPS']) && !headers_sent()) {
	header('Strict-Transport-Security: max-age=31536000');
}

// Accept both old and new env var names for upgrade compatibility
if (empty($_ENV['TACHYON_INCLUDE_AS_API']) && !empty($_ENV['SNAPPYMAIL_INCLUDE_AS_API'])) {
	$_ENV['TACHYON_INCLUDE_AS_API'] = $_ENV['SNAPPYMAIL_INCLUDE_AS_API'];
}
if (empty($_ENV['TACHYON_UPDATE_PLUGINS']) && !empty($_ENV['SNAPPYMAIL_UPDATE_PLUGINS'])) {
	$_ENV['TACHYON_UPDATE_PLUGINS'] = $_ENV['SNAPPYMAIL_UPDATE_PLUGINS'];
}

// cPanel https://github.com/the-djmaze/snappymail/issues/697
if (!empty($_ENV['CPANEL']) && !is_dir(APP_PLUGINS_PATH.'login-remote')) {
	require __DIR__ . '/cpanel.php';
}

if (empty($_ENV['TACHYON_INCLUDE_AS_API'])) {
	Tachyon\Service::Handle();
}
