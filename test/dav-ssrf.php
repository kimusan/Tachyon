<?php

// Run with: php test/dav-ssrf.php
// CardDAV/CalDAV sync URLs are chosen by the user, and the DAV client sent its
// requests wherever they pointed: loopback, the LAN, link-local (cloud
// metadata). The CardDAV test then handed a non-XML answer back in its error
// message, so a user could read what internal services answered. Now private
// hosts are refused unless the admin lists them in security.dav_allowed_hosts,
// answers stay in the debug log, and allow_sync is enforced by the actions.
//
// Nothing is sent anywhere: the transport records requests and answers from a
// queue, while the real Request::doRequest() (and its private-address guard)
// runs in front of it.
namespace Tachyon {
	abstract class Api
	{
		public static ?Config\Application $config = null;
		public static function Config() : Config\Application { return static::$config; }
		public static array $pluginHosts = [];
		public static function Actions() : object
		{
			return new class {
				public function Plugins() : object
				{
					return new class {
						public function RunHook(string $sName, array $aArgs = []) : void
						{
							if ('dav.allowed-hosts' === $sName) {
								foreach (Api::$pluginHosts as $sHost) {
									$aArgs[0][] = $sHost;
								}
							}
						}
					};
				}
			};
		}
		public static function Logger() : object
		{
			return new class {
				public function IsEnabled() : bool { return false; }
				public function Write(...$a) : void {}
			};
		}
	}
}

namespace {
define('APP_VERSION', 'test');
define('APP_SALT', 'salt-of-this-install');
define('APP_PRIVATE_DATA', sys_get_temp_dir().'/dav-ssrf-'.getmypid().'/');
define('APP_CONFIGURATION_NAME', 'application.ini');
spl_autoload_register(static function (string $class): void {
	$base = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/';
	$file = str_starts_with($class, 'Tachyon\\Util\\')
		? $base.'tachyon_util/'.strtolower(str_replace('\\', '/', substr($class, 13))).'.php'
		: $base.str_replace('\\', '/', $class).'.php';
	if (is_file($file)) {
		require_once $file;
	}
});

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

class RecordingRequest extends \Tachyon\Util\HTTP\Request
{
	public array $sent = [];
	public array $answers = [];
	public function supportsSSL() : bool { return true; }
	protected function __doRequest(string &$method, string &$request_url, &$body, array $extra_headers) : \Tachyon\Util\HTTP\Response
	{
		$this->sent[] = "{$method} {$request_url}";
		[$status, $text] = array_shift($this->answers) ?? [207, ''];
		return new \Tachyon\Util\HTTP\Response($request_url, $status, ['Content-Type: text/plain'], $text);
	}
}

\Tachyon\Api::$config = $config = new \Tachyon\Config\Application;
set_error_handler(fn() => true, E_USER_WARNING); // canFetchURI() reports refusals as warnings

$multistatus = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/dav/</d:href>'
	. '<d:propstat><d:prop><d:getetag>"1"</d:getetag></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>'
	. '</d:response></d:multistatus>';

// A PROPFIND as the CardDAV test sends it. Returns [what was sent, the error message or null]
$propfind = function (string $url, array $answers = []): array {
	$client = new \Tachyon\Util\DAV\Client(['baseUri' => $url, 'userName' => 'u', 'password' => 'p']);
	$http = new RecordingRequest;
	$http->answers = $answers;
	(new ReflectionProperty(\Tachyon\Util\DAV\Client::class, 'HTTP'))->setValue($client, $http);
	try {
		$client->propFind('', ['{DAV:}getetag'], 1);
		return [$http->sent, null];
	} catch (\Throwable $e) {
		return [$http->sent, $e->getMessage()];
	}
};

// 1. Internal addresses are refused before anything is sent
foreach (['http://127.0.0.1:8080/', 'http://10.0.0.5/dav/', 'http://169.254.169.254/latest/',
	'http://[::1]/', 'http://2130706433/', 'http://192.168.1.10:5232/'] as $url) {
	[$sent, $error] = $propfind($url, [[207, $multistatus]]);
	check([] === $sent, "a request went to {$url}");
	check(null !== $error, "no error for {$url}");
}

// 2. A public server works as before
[$sent, $error] = $propfind('https://8.8.8.8/dav/', [[207, $multistatus]]);
check(['PROPFIND https://8.8.8.8/dav/'] === $sent && null === $error, 'a public DAV server is refused: '.$error);

// 3. The admin can allow internal DAV servers by name, address or range
$config->Set('security', 'dav_allowed_hosts', '10.0.0.0/8 radicale.internal 192.168.1.10');
foreach (['http://10.0.0.5/dav/', 'http://192.168.1.10:5232/'] as $url) {
	[$sent, $error] = $propfind($url, [[207, $multistatus]]);
	check(1 === count($sent) && null === $error, "an allowed internal host is refused: {$url} {$error}");
}
[$sent] = $propfind('http://127.0.0.1:8080/', [[207, $multistatus]]);
check([] === $sent, 'the allowlist lets other internal hosts through');
check(\Tachyon\Util\DAV\Client::isAllowedHost('http://RADICALE.internal:5232/'), 'host names are not matched case-insensitively');
check(!\Tachyon\Util\DAV\Client::isAllowedHost('http://radicale.internal.evil.example/'), 'a longer name matched the allowlist');
check(!\Tachyon\Util\DAV\Client::isAllowedHost('http://11.0.0.1/'), '10.0.0.0/8 matched 11.0.0.1');

// 3b. Plugins can allow hosts: dav-autoconfig allows the servers the admin
// configured in it
\Tachyon\Api::$pluginHosts = ['dav.internal'];
check(\Tachyon\Util\DAV\Client::isAllowedHost('http://dav.internal:5232/'), 'a host added by a plugin is refused');
\Tachyon\Api::$pluginHosts = [];
check(!\Tachyon\Util\DAV\Client::isAllowedHost('http://dav.internal:5232/'), 'the setup: dav.internal allowed without the plugin');
require_once dirname(__DIR__).'/plugins/dav-autoconfig/index.php';
$autoconfig = (new ReflectionClass(DavAutoconfigPlugin::class))->newInstanceWithoutConstructor();
$pluginConfig = new \Tachyon\Config\Plugin('dav-autoconfig', $autoconfig->ConfigMap(true));
$pluginConfig->Set('plugin', 'carddav_url', 'https://card.internal/dav/card/{email}/default/');
$pluginConfig->Set('plugin', 'caldav_url', 'http://[fd00::5]:5232/cal');
(new ReflectionProperty(\Tachyon\Plugins\AbstractPlugin::class, 'oPluginConfig'))->setValue($autoconfig, $pluginConfig);
check(method_exists($autoconfig, 'AllowedHosts'), 'dav-autoconfig does not allow its own servers');
$hosts = [];
$autoconfig->AllowedHosts($hosts);
check(['card.internal', 'fd00::5'] === $hosts, 'dav-autoconfig allows '.json_encode($hosts));

// 4. What an (allowed) server answers does not come back in the error
[$sent, $error] = $propfind('http://10.0.0.5/dav/', [[200, 'secret-marker']]);
check(null !== $error && !str_contains($error, 'secret-marker'), 'the answer is in the error message: '.$error);
$config->Set('security', 'dav_allowed_hosts', '');

// 5. The DAV actions check allow_sync, and show the user no server answers
$src = fn(string $f) => file_get_contents(dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/Tachyon/'.$f);
foreach (['Actions/Contacts.php' => ['DoSaveContactsSyncData', 'DoTestContactsSyncData', 'DoContactsSync'],
	'Actions/Calendar.php' => ['DoSaveCalendarSyncData', 'DoTestCalendarSyncData', 'DoCalendarSync']] as $file => $actions) {
	foreach ($actions as $action) {
		check(preg_match("/function {$action}\(\).*?requireDavSync\('(contacts|calendar)'\).*?\n\t\}/s", $src($file)) === 1,
			"{$action}() does not check allow_sync");
	}
}
check(preg_match('/function DoTestContactsSyncData\(\).*?catch \(\\\\Throwable \$oException\).*?\n\t\}/s', $src('Actions/Contacts.php')) === 1,
	'DoTestContactsSyncData() passes DAV errors to the user as they are');
check(!preg_match('/return \$oException->getMessage\(\);/', $src('Providers/Calendar/PdoCalendar.php')),
	'the CalDAV test passes DAV errors to the user as they are');

$actions = (new ReflectionClass(\Tachyon\Actions::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(\Tachyon\Actions::class, 'oConfig'))->setValue($actions, $config);
$require = new ReflectionMethod(\Tachyon\Actions::class, 'requireDavSync');
$refused = function (string $section) use ($require, $actions): bool {
	try { $require->invoke($actions, $section); return false; }
	catch (\Tachyon\Exceptions\ClientException $e) { return true; }
};
$config->Set('contacts', 'allow_sync', false);
$config->Set('calendar', 'allow_sync', false);
check($refused('contacts') && $refused('calendar'), 'sync turned off is not refused');
$config->Set('contacts', 'allow_sync', true);
$config->Set('calendar', 'allow_sync', true);
check(!$refused('contacts') && !$refused('calendar'), 'sync turned on is refused');

echo "dav-ssrf: ok\n";
}
