<?php

/**
 * Exercises Tachyon's CardDAV and CalDAV discovery against a real server, with
 * no application bootstrap. Answers one question: given only a base URL, does
 * discovery find the account's own collections?
 *
 * Usage: php test/dav-discovery.php <card|cal> <base-url> <user> <password>
 *        DAV_DISCOVERY_VERBOSE=1 php test/dav-discovery.php ...
 *
 * Replaces test/carddav.php, which had rotted against the RainLoop namespace.
 */

define('APP_VERSION', '0.0.0');

$sLib = \dirname(__DIR__) . '/tachyon/v/0.0.0/app/libraries';

// Before the DAV client, so its Log calls resolve to the stub rather than to
// the real class, which needs the whole bootstrap.
require __DIR__ . '/dav-discovery-log-stub.php';

require $sLib . '/tachyon_util/dav/client.php';
require $sLib . '/tachyon_util/http/exception.php';
require $sLib . '/tachyon_util/http/response.php';
require $sLib . '/tachyon_util/http/request.php';
require $sLib . '/tachyon_util/http/request/curl.php';
require $sLib . '/tachyon_util/http/request/socket.php';
require $sLib . '/Tachyon/Providers/AddressBook/CardDAV.php';
require $sLib . '/Tachyon/Providers/Calendar/CalDAV.php';

/**
 * The three logging methods both DAV traits call live on the Pdo* provider
 * classes rather than in the traits themselves, so a probe must supply them.
 */
trait ProbeLogging
{
	public function logWrite(string $sDesc, int $iType = LOG_INFO, string $sName = '') : void
	{
		if (\getenv('DAV_DISCOVERY_VERBOSE')) {
			\fwrite(STDERR, "[{$sName}] {$sDesc}\n");
		}
	}

	public function logMask(string $sSecret) : void
	{
	}

	public function logException(\Throwable $oException, int $iType = LOG_ERR, string $sName = '') : void
	{
		// Never silent: swallowing this made the first run of this harness
		// report "no collections" for what was really a missing class.
		\fwrite(STDERR, '[EXCEPTION] ' . \get_class($oException) . ': ' . $oException->getMessage() . "\n");
	}
}

/**
 * Two probes rather than one, because CardDAV and CalDAV both declare
 * setDAVClientConfig, isDAVReadWrite, detectionPropFind and
 * getDavClientFromUrl -- a single class cannot use both traits.
 *
 * The discovery methods are private or protected, which is no obstacle: a
 * trait's members belong to the composing class.
 */
class CardDavProbe
{
	use \Tachyon\Providers\AddressBook\CardDAV;
	use ProbeLogging;

	public function discover(string $sUrl, string $sUser, string $sPassword) : array
	{
		// getDavClientFromUrl() keeps only scheme/host/port for the base URI
		// and puts the path on ->urlPath, which is where discovery starts.
		$oClient = $this->getDavClientFromUrl($sUrl, $sUser, $sPassword);

		return $this->getContactsPaths($oClient, $oClient->urlPath, $sUser, $sPassword);
	}
}

class CalDavProbe
{
	use \Tachyon\Providers\Calendar\CalDAV;
	use ProbeLogging;

	public function discover(string $sUrl, string $sUser, string $sPassword) : array
	{
		$oClient = $this->getDavClientFromUrl($sUrl, $sUser, $sPassword);

		return $this->getCalendarPaths($oClient, $oClient->urlPath, $sUser, $sPassword);
	}
}

if ($argc < 5 || !\in_array($argv[1], array('card', 'cal'), true)) {
	\fwrite(STDERR, "usage: php test/dav-discovery.php <card|cal> <base-url> <user> <password>\n");
	exit(2);
}

// Discovery probes /.well-known/{carddav,caldav} first. Stalwart answers those
// with a 307, which Tachyon's client raises rather than follows -- it handles
// 301 only. The exception line on stderr is therefore expected and harmless:
// discovery falls back to the supplied path and succeeds from there.
$oProbe = 'card' === $argv[1] ? new CardDavProbe() : new CalDavProbe();
$aPaths = $oProbe->discover($argv[2], $argv[3], $argv[4]);

if (!$aPaths) {
	echo "NO COLLECTIONS DISCOVERED\n";
	exit(1);
}

foreach ($aPaths as $sPath => $mDetail) {
	// CardDAV yields a display name string; CalDAV yields an array of
	// collection properties. Render whichever came back.
	echo $sPath . "\t" . (\is_array($mDetail)
		? \json_encode($mDetail, JSON_UNESCAPED_SLASHES)
		: $mDetail) . "\n";
}
