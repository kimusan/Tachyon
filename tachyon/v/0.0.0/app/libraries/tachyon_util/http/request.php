<?php

namespace Tachyon\Util\HTTP;

abstract class Request
{
	const
		/**
		 * Authentication
		 * These are bitwise options
		 */
		AUTH_BASIC = 1,
		AUTH_DIGEST = 2,
		AUTH_BEARER = 4;

	public
		$timeout = 5, // timeout in seconds.
		$max_response_kb = 1024,
		$user_agent,
		$max_redirects = 0,
		$verify_peer = false,
		$proxy = null,
		$proxy_auth = null,
		// When true, refuse to fetch URLs whose host resolves to a
		// private, reserved, loopback or link-local IP. Enable for
		// request paths where the URL is attacker-influenced.
		$block_private_ips = false;

	protected
		$auth = [
			'type' => 0,
			'user' => '',
			'pass' => ''
		],
		$stream = null,
		$headers = array(),
		$ca_bundle = null;

	protected static $scheme_ports = array(
		'http'  => 80,
		'https' => 443
	);

	public static function factory(string $type = 'curl')
	{
		if ('curl' === $type && \function_exists('curl_init')) {
			return new Request\CURL();
		}
		return new Request\Socket();
	}

	function __construct()
	{
		$this->user_agent = 'Tachyon/' . APP_VERSION;
	}

	public function setAuth(int $type, string $user,
		#[\SensitiveParameter]
		string $pass
	) : void
	{
		$this->auth = [
			'type' => $type,
			'user' => $user,
			'pass' => $pass
		];
	}

	public function addHeader($header)
	{
		$this->headers[] = $header;
		return $this;
	}

	public function streamBodyTo($stream)
	{
		if (!\is_resource($stream)) {
			throw new \Exception('Invalid body target');
		}
		$this->stream = $stream;
	}

	public function setCABundleFile($file)
	{
		$this->ca_bundle = $file;
	}

	/**
	 * Return whether a URI can be fetched.  Returns false if the URI scheme is not allowed
	 * or is not supported by this fetcher implementation; returns true otherwise.
	 *
	 * @return bool
	 */
	public function canFetchURI($uri)
	{
		if ('https:' === \substr($uri, 0, 6) && !$this->supportsSSL()) {
			\trigger_error('HTTPS URI unsupported fetching '.$uri, E_USER_WARNING);
			return false;
		}
		if (!self::URIHasAllowedScheme($uri)) {
			\trigger_error('URI fetching not allowed for '.$uri, E_USER_WARNING);
			return false;
		}
		if ($this->block_private_ips && !self::URIHasPublicHost($uri)) {
			\trigger_error('URI host is not a public IP for '.$uri, E_USER_WARNING);
			return false;
		}
		return true;
	}

	/**
	 * Whether the URI host resolves exclusively to public IPs.
	 * Blocks literal private/reserved/loopback/link-local IPs (including
	 * non-standard numeric forms like http://2130706433/, http://017700000001/,
	 * http://0x7f000001/, http://127.1/ and bracketed IPv6 like
	 * http://[::1]/) as well as
	 * hostnames that resolve to one. IPv6 literals with an embedded
	 * IPv4 address (::ffff:127.0.0.1 and friends) are unwrapped first.
	 * Fails closed when the host cannot be resolved. Note: this is a
	 * pre-request
	 * check; a hostile DNS that rebinds between check and connect (TOCTOU)
	 * is not covered.
	 */
	public static function URIHasPublicHost(string $uri) : bool
	{
		$host = \parse_url($uri, PHP_URL_HOST);
		if (!\is_string($host) || '' === $host) {
			return false;
		}
		// Bracketed IPv6 literals (http://[::1]/): parse_url keeps the
		// brackets, which filter_var rejects, so strip them first.
		if (\str_starts_with($host, '[') && \str_ends_with($host, ']')) {
			$host = \substr($host, 1, -1);
		}
		// IPv6 literals with an embedded IPv4 address (::ffff:127.0.0.1,
		// ::ffff:a00:5, 64:ff9b::7f00:1): FILTER_FLAG_NO_RES_RANGE
		// does not treat these ranges as reserved, but they reach IPv4
		// addresses in practice, so unwrap the low 32 bits and validate
		// those as IPv4.
		$sUnwrapped = self::UnwrapEmbeddedIPv4($host);
		if (null !== $sUnwrapped) {
			$host = $sUnwrapped;
		}
		// Numeric IPv4 literals in non-standard forms (http://2130706433/,
		// http://017700000001/, http://0x7f000001/, http://127.1/,
		// http://1.2.3.04/): the HTTP client parses these as IPs directly
		// (verified: curl connects to 127.0.0.1 for the hex forms with no
		// DNS involved) while dns_get_record() below would query them as
		// hostnames, so normalize to dotted decimal before validation.
		$sNormalized = self::NormalizeNumericIPv4($host);
		if (null !== $sNormalized) {
			$host = $sNormalized;
		}
		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		if (\filter_var($host, FILTER_VALIDATE_IP, $flags)) {
			return true;
		}
		if (\filter_var($host, FILTER_VALIDATE_IP)) {
			return false; // literal non-public IP
		}
		$ips = array();
		foreach (\dns_get_record($host, DNS_A | DNS_AAAA) ?: array() as $record) {
			if (!empty($record['ip'])) {
				$ips[] = $record['ip'];
			}
			if (!empty($record['ipv6'])) {
				$ips[] = $record['ipv6'];
			}
		}
		if (!$ips) {
			$ips = \gethostbynamel($host) ?: array();
		}
		if (!$ips) {
			return false; // fail closed on unresolvable hosts
		}
		foreach ($ips as $ip) {
			$sUnwrapped = self::UnwrapEmbeddedIPv4($ip);
			if (null !== $sUnwrapped) {
				$ip = $sUnwrapped;
			}
			if (!\filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Normalize a numeric IPv4 literal in non-standard form to dotted
	 * decimal, following inet_aton semantics: 1-4 dot-separated parts,
	 * each decimal, octal (leading zero) or hex (0x prefix); the last
	 * part takes all remaining bits (32/24/16/8). Returns null when the
	 * host is not such a literal. A part like "08" is not parsed as
	 * numeric by the resolver, so it is left for the hostname path below.
	 */
	private static function NormalizeNumericIPv4(string $host) : ?string
	{
		$aParts = \explode('.', $host);
		$iCount = \count($aParts);
		if ($iCount < 1 || $iCount > 4) {
			return null;
		}
		$aNums = array();
		foreach ($aParts as $sPart) {
			if ('' === $sPart
				|| !\preg_match('/^(0[xX][0-9a-fA-F]+|[0-9]+)$/', $sPart)) {
				return null;
			}
			if (\preg_match('/^0[xX]/', $sPart)) {
				if (10 < \strlen($sPart)) {
					return null; // longer than 0x + 8 digits: not a 32-bit value
				}
				$aNums[] = \hexdec($sPart);
				continue;
			}
			if (1 < \strlen($sPart) && '0' === $sPart[0]
				&& !\preg_match('/^0[0-7]+$/', $sPart)) {
				return null;
			}
			if (12 < \strlen($sPart)) {
				return null; // longer than 037777777777: not a 32-bit value
			}
			$aNums[] = 1 < \strlen($sPart) && '0' === $sPart[0]
				? \octdec($sPart) : (int) $sPart;
		}
		$iMax = array(4294967295, 16777215, 65535, 255);
		for ($i = 0; $i < $iCount - 1; $i++) {
			if ($aNums[$i] > 255) {
				return null;
			}
		}
		if ($aNums[$iCount - 1] > $iMax[$iCount - 1]) {
			return null;
		}
		switch ($iCount) {
			case 1:
				$long = $aNums[0];
				break;
			case 2:
				$long = ($aNums[0] << 24) | $aNums[1];
				break;
			case 3:
				$long = ($aNums[0] << 24) | ($aNums[1] << 16) | $aNums[2];
				break;
			default:
				$long = ($aNums[0] << 24) | ($aNums[1] << 16) | ($aNums[2] << 8) | $aNums[3];
				break;
		}
		return \long2ip($long);
	}

	/**
	 * Unwrap an IPv6 literal with an embedded IPv4 address to dotted
	 * decimal, or null when the host is not such a literal. Covers the
	 * IPv4-mapped range ::ffff:0:0/96 (dotted or hex tail), the NAT64
	 * well-known prefix 64:ff9b::/96, and the deprecated
	 * IPv4-compatible form ::a.b.c.d (textual dotted-quad tail only,
	 * so ::1 stays loopback and is never mistaken for ::0.0.0.1).
	 * Needed because FILTER_FLAG_NO_RES_RANGE does not treat these
	 * ranges as reserved even though they reach IPv4 addresses in
	 * practice.
	 */
	private static function UnwrapEmbeddedIPv4(string $host) : ?string
	{
		$sBin = \inet_pton($host);
		if (false === $sBin || 16 !== \strlen($sBin)) {
			return null; // not an IPv6 literal
		}
		$sPrefix = \substr($sBin, 0, 12);
		if ("\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" === $sPrefix // ::ffff:0:0/96
			|| "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00" === $sPrefix) { // 64:ff9b::/96
			return (string) \inet_ntop(\substr($sBin, 12, 4));
		}
		$sLower = \strtolower($host);
		if (\str_starts_with($sLower, '::')
			&& false !== \filter_var(\substr($sLower, 2), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			return \substr($sLower, 2); // deprecated ::a.b.c.d form
		}
		return null;
	}

	/**
	 * Does this fetcher implementation (and runtime) support fetching HTTPS URIs?
	 * May inspect the runtime environment.
	 *
	 * @return bool $support True if this fetcher supports HTTPS
	 * fetching; false if not.
	 */
	abstract public function supportsSSL() : bool;

	abstract protected function __doRequest(string &$method, string &$request_url, &$body, array $extra_headers) : Response;

	public function doRequest($method, $request_url, /*string|array*/$body = null, array $extra_headers = array()) : ?Response
	{
		$method = \strtoupper($method);
		$url    = $request_url;
		$etime  = \time() + $this->timeout;
		$redirects = \max(0, $this->max_redirects);
		if (\is_array($body)) {
			$body = \http_build_query($body, '', '&');
		}
		if ($body && 'GET' === $method) {
			$url .= (\strpos($url, '?') ? '&' : '?') . $body;
			$body = null;
		}
		do
		{
			if (!$this->canFetchURI($url)) {
				throw new \RuntimeException("Can't fetch URL: {$url}");
			}

			if (!self::URIHasAllowedScheme($url)) {
				throw new \RuntimeException("Fetching URL not allowed: {$url}");
			}

			$this->stream && \rewind($this->stream);
			$result = $this->__doRequest($method, $url, $body, \array_merge($this->headers, $extra_headers));

			// http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html#sec10.3
			// In response to a request other than GET or HEAD, the user agent MUST NOT
			// automatically redirect the request unless it can be confirmed by the user
			if ($redirects-- && \in_array($result->status, array(301, 302, 303, 307)) && \in_array($method, ['GET','HEAD'])) {
				$url = $result->getRedirectLocation();
			} else {
				$result->final_uri = $url;
				$result->request_uri = $request_url;
				return $result;
			}

		} while ($etime-time() > 0);

		return null;
	}

	/**
	 * Return whether a URI should be allowed. Override this method to conform to your local policy.
	 * By default, will attempt to fetch any http or https URI.
	 */
	public static function URIHasAllowedScheme($uri) : bool
	{
		return (bool) \preg_match('#^https?://#i', $uri);
	}

	public static function getSchemePort($scheme) : int
	{
		return self::$scheme_ports[$scheme] ?? 0;
	}
}
