<?php

// Run with: php test/http-dns-pinning.php
// With block_private_ips (image proxy, DAV sync) the host's addresses were
// checked first, and then the transport looked the name up again to connect.
// A DNS server answering a public address to the check and 127.0.0.1 to the
// connection (DNS rebinding) got past the check. The connection now goes to
// the addresses that were checked. Nothing is sent: the transport records.
define('APP_VERSION', 'test');
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

// A rebinding DNS server: a public address first, loopback after that
class RebindingRequest extends \Tachyon\Util\HTTP\Request
{
	public static array $answers = [];
	public static int $lookups = 0;
	public array $connectedTo = [];
	protected static function resolveHost(string $host) : array
	{
		++static::$lookups;
		return array_shift(static::$answers) ?? ['127.0.0.1'];
	}
	public function supportsSSL() : bool { return true; }
	protected function __doRequest(string &$method, string &$request_url, &$body, array $extra_headers) : \Tachyon\Util\HTTP\Response
	{
		// What a transport resolving on its own would connect to now
		$this->connectedTo[] = (new ReflectionProperty(\Tachyon\Util\HTTP\Request::class, 'pinned'))->getValue($this)['ips']
			?? static::resolveHost((string) parse_url($request_url, PHP_URL_HOST));
		return new \Tachyon\Util\HTTP\Response($request_url, 200, [], 'ok');
	}
}

check(property_exists(\Tachyon\Util\HTTP\Request::class, 'pinned'), 'no pinned addresses: the transport looks the name up again');
check(method_exists(\Tachyon\Util\HTTP\Request::class, 'resolveHost'), 'no resolveHost()');

$http = new RebindingRequest;
$http->block_private_ips = true;
RebindingRequest::$answers = [['8.8.8.8'], ['127.0.0.1']];
$http->doRequest('GET', 'https://rebind.example/latest/meta-data/');
check(['8.8.8.8'] === $http->connectedTo[0], 'the connection went to '.json_encode($http->connectedTo[0]).', not the checked address');
check(1 === RebindingRequest::$lookups, 'the name was looked up '.RebindingRequest::$lookups.' times');
$pinned = (new ReflectionProperty(\Tachyon\Util\HTTP\Request::class, 'pinned'))->getValue($http);
check(['host' => 'rebind.example', 'port' => 443, 'ips' => ['8.8.8.8']] === $pinned, 'pinned: '.json_encode($pinned));

// A private answer is still refused before anything is sent
RebindingRequest::$answers = [['10.0.0.5']];
$http->connectedTo = [];
set_error_handler(fn() => true, E_USER_WARNING);
try { $http->doRequest('GET', 'http://rebind.example/'); check(false, 'a private answer was fetched'); }
catch (\RuntimeException $e) { check([] === $http->connectedTo, 'a request was sent'); }
restore_error_handler();

// Without block_private_ips nothing is pinned (the transport resolves as before)
$open = new RebindingRequest;
RebindingRequest::$answers = [['10.0.0.5']];
$open->doRequest('GET', 'http://intranet.example/');
check(null === (new ReflectionProperty(\Tachyon\Util\HTTP\Request::class, 'pinned'))->getValue($open), 'pinned without block_private_ips');

// The real transports use the pinned addresses
$curl = file_get_contents(dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/tachyon_util/http/request/curl.php');
check(preg_match('/if \(\$this->pinned && !\$this->proxy\).*?CURLOPT_RESOLVE/s', $curl) === 1, 'the cURL transport does not use the pinned addresses');
$socket = file_get_contents(dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/tachyon_util/http/request/socket.php');
check(str_contains($socket, "\$this->pinned['ips'][0]") && str_contains($socket, "'peer_name', \$this->pinned['host']"),
	'the socket transport does not use the pinned address');

echo "http-dns-pinning: ok\n";
