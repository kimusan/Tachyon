<?php
/**
 * You may store your own custom domain icons in `data/_data_/_default_/avatars/`
 * Like: `data/_data_/_default_/avatars/snappymail.eu.svg`
 */

class AvatarsPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME     = 'Avatars',
		AUTHOR   = 'Tachyon',
		URL      = 'https://github.com/kimusan/Tachyon',
		VERSION  = '1.29',
		RELEASE  = '2026-11-08',
		REQUIRED = '2.33.0',
		CATEGORY = 'Contacts',
		LICENSE  = 'MIT',
		DESCRIPTION = 'Show graphic of sender in message and messages list (supports contact photos, BIMI, Gravatar, favicon and identicon)';

	public function Init() : void
	{
		$this->addCss('style.css');
		$this->addJs('avatars.js');
		$this->addJsonHook('Avatar', 'DoAvatar');
		$this->addPartHook('Avatar', 'ServiceAvatar');
		$identicon = $this->Config()->Get('plugin', 'identicon', '');
		if ($identicon && \is_file(__DIR__ . "/{$identicon}.js")) {
			$this->addJs("{$identicon}.js");
		}
		// https://github.com/the-djmaze/snappymail/issues/714
		if ($this->Config()->Get('plugin', 'service', true)
//		 || !$this->Config()->Get('plugin', 'delay', true)
		 || $this->Config()->Get('plugin', 'contacts', true)
		 || $this->Config()->Get('plugin', 'gravatar', false)
		 || $this->Config()->Get('plugin', 'bimi', false)
		 || $this->Config()->Get('plugin', 'favicon', false)
		) {
			$this->addHook('json.after-message', 'JsonMessage');
			$this->addHook('json.after-messagelist', 'JsonMessageList');
		}
		// https://www.ietf.org/archive/id/draft-brand-indicators-for-message-identification-04.html#bimi-selector
		if ($this->Config()->Get('plugin', 'bimi', false)) {
			$this->addHook('imap.message-headers', 'ImapMessageHeaders');
		}
	}

	public function ImapMessageHeaders(array &$aHeaders)
	{
		// \MailSo\Mime\Enumerations\Header::BIMI_SELECTOR
		$aHeaders[] = 'BIMI-Selector';
	}

	public function JsonMessage(array &$aResponse)
	{
		if ($icon = $this->JsonAvatar($aResponse['Result'])) {
			$aResponse['Result']['avatar'] = $icon;
		}
	}

	public function JsonMessageList(array &$aResponse)
	{
		if (!empty($aResponse['Result']['@Collection'])) {
			foreach ($aResponse['Result']['@Collection'] as &$message) {
				if ($icon = $this->JsonAvatar($message)) {
					$message['avatar'] = $icon;
				}
			}
		}
	}

	private function JsonAvatar($message) : ?string
	{
		$mFrom = empty($message['from'][0]) ? null : $message['from'][0];
		if ($mFrom instanceof \MailSo\Mime\Email) {
			$mFrom = $mFrom->jsonSerialize();
		}
		if (\is_array($mFrom)) {
			// A contact photo is fetched by the same request as the rest, so leaving
			// contacts out here meant the one lookup that needs no external service
			// was the one that never ran.
			if (/*!$this->Config()->Get('plugin', 'delay', true)
			 && */($this->Config()->Get('plugin', 'contacts', true)
				|| $this->Config()->Get('plugin', 'gravatar', false)
				|| ($this->Config()->Get('plugin', 'bimi', false) && 'pass' == $mFrom['dkimStatus'])
				|| ($this->Config()->Get('plugin', 'favicon', false) && 'pass' == $mFrom['dkimStatus'])
			 )
			) {
				return 'remote';
			}
			if ('pass' == $mFrom['dkimStatus'] && $this->Config()->Get('plugin', 'service', true)) {
				// 'data:image/png;base64,[a-zA-Z0-9+/=]'
				return static::getServiceIcon($mFrom['email']);
			}
		}
		return null;
	}

	/**
	 * POST method handling
	 */
	public function DoAvatar() : array
	{
		// Same reachability as ServiceAvatar below: a JSON token is minted for
		// guests too, so this needs the account check of its own.
		if (!\Tachyon\Api::Actions()->getAccountFromToken(false)) {
			return $this->jsonResponse(__FUNCTION__, false);
		}
		$bBimi = !empty($this->jsonParam('bimi'));
		$sBimiSelector = $this->jsonParam('bimiSelector') ?: '';
		$sEmail = $this->jsonParam('email');
		$aResult = $this->getAvatar($sEmail, $bBimi, $sBimiSelector);
		if ($aResult) {
			$aResult = [
				'type' => $aResult[0],
				'data' => \base64_encode($aResult[1])
			];
		}
		return $this->jsonResponse(__FUNCTION__, $aResult);
	}

	/**
	 * GET /?Avatar/${bimi}/Encoded(${from.email})
	 * Nextcloud Mail uses insecure unencoded 'index.php/apps/mail/api/avatars/url/local%40example.com'
	 */
//	public function ServiceAvatar(...$aParts)
	public function ServiceAvatar(string $sServiceName, string $sBimi, string $sEncodedEmail)
	{
		// Short, because a contact photo added or changed after a lookup only
		// reaches the browser once this expires. The lookup behind it is cheap to
		// repeat: a hit is a file read and a miss is remembered in getAvatar().
		$maxAge = 300;
		// Anyone who can reach the login page could call this. With BIMI on it made
		// the server fetch a URL out of a DNS record for a domain of the caller's
		// choosing, and wrote whatever came back into the shared avatar cache under
		// any address. Reported privately by Fathi Ben Nasr (GHSA-2r3w-3qmp-ppf2).
		if (!\Tachyon\Api::Actions()->getAccountFromToken(false)) {
			\MailSo\Base\Http::StatusHeader(401, 'Unauthorized');
			exit;
		}
		$sEmail = \MailSo\Base\Utils::UrlSafeBase64Decode($sEncodedEmail);
		$aBimi = \explode('-', $sBimi, 2);
		$sBimiSelector = isset($aBimi[1]) ? $aBimi[1] : 'default';
		// The selector reaches a DNS query and a cache key, so hold it to one label.
		if (!\preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D', $sBimiSelector)) {
			$sBimiSelector = 'default';
		}
//		$sEmail && \MailSo\Base\Http::setETag("{$sBimiSelector}-{$sEncodedEmail}");
		$aResult = $sEmail ? $this->getAvatar($sEmail, !empty($aBimi[0]), $sBimiSelector) : null;
		// A miss is cached like a hit, because it was the one answer carrying no
		// cache headers at all, so every list render asked again and every ask
		// redid the gravatar, favicon and BIMI lookups behind it. Sent before the
		// ETag is checked, so a 304 carries them too.
		\header("Cache-Control: max-age={$maxAge}, private");
		\header('Expires: '.\gmdate('D, j M Y H:i:s', $maxAge + \time()).' UTC');
		// The ETag has to describe the picture. It used to be the sha1 of the
		// address, which never changes, so checkETag() answered 304 to every
		// revalidation and the first answer for a sender was the last one: a
		// contact edited afterwards could not show up until the browser cache was
		// cleared by hand.
		$sEmail && \MailSo\Base\Http::setETag(
			\sha1($sBimi . $sEncodedEmail . ($aResult ? \md5($aResult[1]) : 'none'))
		);
		if ($aResult) {
			if ('text/uri-list' === $aResult[0]) {
				// The image lives elsewhere. Point the browser at it instead of
				// fetching it here, so the server never makes a request to an
				// address that came out of contact data.
				\header('Location: '.$aResult[1], true, 302);
			} else {
				\header('Content-Type: '.$aResult[0]);
				echo $aResult[1];
			}
		} else {
			// 204 rather than 404. The <img> fails either way and the client draws
			// its identicon from the error, but a 404 also writes a line to the
			// browser console for every sender without a picture, which on a busy
			// list is most of them. Measured: 404, 204 and an empty 200 all fire
			// onerror, and only the 404 is logged.
			\MailSo\Base\Http::StatusHeader(204, 'No Content');
		}
		exit;
	}

	protected function configMapping() : array
	{
		$group = new \Tachyon\Plugins\PropertyCollection('Lookup');
		$group->exchangeArray([
			\Tachyon\Plugins\Property::NewInstance('delay')->SetLabel('Delay lookup')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetAllowedInJs(true)
				->SetDefaultValue(true),
			\Tachyon\Plugins\Property::NewInstance('contacts')->SetLabel('Contacts')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(true)
				->SetDescription('Use the photo on the matching contact, before any external source'),
			\Tachyon\Plugins\Property::NewInstance('bimi')->SetLabel('BIMI')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(false)
				->SetDescription('https://bimigroup.org/ (DKIM header must be valid)'),
			\Tachyon\Plugins\Property::NewInstance('favicon')->SetLabel('Favicon')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(false)
				->SetDescription('Fetch favicon from domain (DKIM header must be valid)'),
			\Tachyon\Plugins\Property::NewInstance('gravatar')->SetLabel('Gravatar')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(false)
				->SetDescription('https://wikipedia.org/wiki/Gravatar'),
		]);
		$aResult = array(
			defined('Tachyon\\Enumerations\\PluginPropertyType::SELECT')
				? \Tachyon\Plugins\Property::NewInstance('identicon')->SetLabel('Identicon')
					->SetType(\Tachyon\Enumerations\PluginPropertyType::SELECT)
					->SetDefaultValue([
						['id' => '', 'name' => 'Name characters else silhouette'],
						['id' => 'identicon', 'name' => 'Name characters else squares'],
						['id' => 'jdenticon', 'name' => 'Triangles shape']
					])
					->SetDescription('https://wikipedia.org/wiki/Identicon')
				: \Tachyon\Plugins\Property::NewInstance('identicon')->SetLabel('Identicon')
					->SetType(\Tachyon\Enumerations\PluginPropertyType::SELECTION)
					->SetDefaultValue(['','identicon','jdenticon'])
					->SetDescription('empty = default, identicon = squares, jdenticon = Triangles shape')
				,
			\Tachyon\Plugins\Property::NewInstance('service')->SetLabel('Preload valid domain icons')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
				->SetAllowedInJs(true)
				->SetDefaultValue(true)
				->SetDescription('DKIM header must be valid and icon is found in avatars/images/services directory'),
			$group
		);
/*
		if (\class_exists('OC') && isset(\OC::$server)) {
			$aResult[] = \Tachyon\Plugins\Property::NewInstance('nextcloud')->SetLabel('Lookup Nextcloud Contacts')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::BOOL)
//				->SetAllowedInJs(true)
				->SetDefaultValue(false);
		}
*/
		return $aResult;
	}

	private static function getServicePng(string $sDomain) : ?string
	{
		$aServices = [
			"services/{$sDomain}",
			'services/' . static::serviceDomain($sDomain)
		];
		foreach ($aServices as $service) {
			$file = __DIR__ . "/images/{$service}.png";
			if (\file_exists($file)) {
				return $file;
			}
		}
		return null;
	}

	// Only allow service icon when DKIM is valid. $bBimi is true when DKIM is valid.
	private static function getServiceIcon(string $sEmail) : ?string
	{
		$aParts = \explode('@', $sEmail);
		$file = static::getServicePng(\array_pop($aParts));
		if ($file) {
			return 'data:image/png;base64,' . \base64_encode(\file_get_contents($file));
		}

		$aResult = static::getCustomImage($sEmail) ?: static::getCachedImage($sEmail);
		if ($aResult) {
			return 'data:'.$aResult[0].';base64,' . \base64_encode($aResult[1]);
		}

		return null;
	}

	private function getAvatar(string $sEmail, bool $bBimi, string $sBimiSelector = '') : ?array
	{
		if (!\strpos($sEmail, '@')) {
			return null;
		}

		$sAsciiEmail = \mb_strtolower(\Tachyon\Util\IDN::emailToAscii($sEmail));
		$sEmailId = \sha1($sAsciiEmail);
		$sDomain = \explode('@', $sAsciiEmail);
		$sDomain = \array_pop($sDomain);
		// The domain goes into glob() patterns, file names, DNS queries and URLs below
		if (!static::isPlainHost($sDomain)) {
			return null;
		}

		\header('Cache-Control: private');
//		\header('Expires: '.\gmdate('D, j M Y H:i:s', \time() + 86400).' UTC');

		// An icon the admin put in APP_PRIVATE_DATA is their decision, so it stays
		// on top.
		$aResult = static::getCustomImage($sAsciiEmail);
		if ($aResult) {
			return $aResult;
		}

		// A photo the user put on the contact themselves outranks anything fetched
		// from a third party, so this sits above BIMI, Gravatar and favicon, and
		// above what an earlier lookup of those left in the cache. Below that cache
		// it was never reached again once anything had been stored for the address,
		// or, by way of the favicon, for the whole domain.
		if ($this->Config()->Get('plugin', 'contacts', true)) {
			$aResult = $this->getContactPhoto($sEmail);
			if ($aResult) {
				return $aResult;
			}
		}

		$aResult = static::getCachedImage($sAsciiEmail);
		if ($aResult) {
			return $aResult;
		}

		// A sender with no picture is the common case, and finding that out costs a
		// DNS query and two web requests, so the answer is kept for a day. The DKIM
		// verdict is part of the name because it decides whether BIMI and favicon
		// are tried at all.
		$sMissFile = \APP_PRIVATE_DATA . 'avatars/' . $sEmailId . ($bBimi ? '-none-dkim' : '-none');
		$bKnownMiss = \is_file($sMissFile) && \filemtime($sMissFile) > \time() - 86400;

		if (!$bKnownMiss) {
			$aUrls = [];

			if ($this->Config()->Get('plugin', 'bimi', false)) {
				$BIMI = $bBimi ? \Tachyon\Util\DNS::BIMI($sDomain, $sBimiSelector) : null;
				if ($BIMI) {
					$aUrls[] = $BIMI;
//					$aResult = ['text/uri-list', $BIMI];
					\Tachyon\Util\Log::debug('Avatar', "BIMI {$sDomain}: {$BIMI}");
				} else {
					\Tachyon\Util\Log::notice('Avatar', "BIMI 404 for {$sDomain}");
				}
			}

			if ($this->Config()->Get('plugin', 'gravatar', false)) {
				$aUrls[] = 'https://gravatar.com/avatar/'.\hash('sha256', \strtolower($sAsciiEmail)).'?s=80&d=404';
			}

			foreach ($aUrls as $sUrl) {
				if ($aResult = static::getUrl($sUrl)) {
					break;
				}
			}

			if ($aResult) {
				static::cacheImage($sAsciiEmail, $aResult);
			}
		}

		// Only allow service icon when DKIM is valid. $bBimi is true when DKIM is valid.
		if ($bBimi && !$aResult) {
			$file = static::getServicePng($sDomain);
			if ($file) {
				\MailSo\Base\Http::setLastModified(\filemtime($file));
				$aResult = [
					'image/png',
					\file_get_contents($file)
				];
			}

			if (!$aResult && !$bKnownMiss && $this->Config()->Get('plugin', 'favicon', false)) {
				$aResult = static::getFavicon($sDomain);
			}
		}

		if (!$aResult && !$bKnownMiss) {
			\is_dir(\APP_PRIVATE_DATA . 'avatars') || \mkdir(\APP_PRIVATE_DATA . 'avatars', 0700);
			\touch($sMissFile);
		}

		return $aResult;
	}

	private static function serviceDomain(string $sDomain) : string
	{
		$sDomain = \preg_replace('/^(.+\\.)?(paypal\\.[a-z][a-z])$/D', 'paypal.com', $sDomain);
		$sDomain = \preg_replace('/^facebookmail.com$/D', 'facebook.com', $sDomain);
		$sDomain = \preg_replace('/^dhlparcel.nl$/D', 'dhl.com', $sDomain);
		$sDomain = \preg_replace('/^amazon.nl$/D', 'amazon.com', $sDomain);
		$sDomain = \preg_replace('/^.+\\.([^.]+\\.[^.]+)$/D', '$1', $sDomain);
		return $sDomain;
	}

	private static function cacheImage(string $sEmail, array $aResult) : void
	{
		if (!\is_dir(\APP_PRIVATE_DATA . 'avatars')) {
			\mkdir(\APP_PRIVATE_DATA . 'avatars', 0700);
		}
		$sEmailId = \mb_strtolower(\Tachyon\Util\IDN::emailToAscii($sEmail));
		if (\str_contains($sEmail, '@')) {
			$sEmailId = \sha1($sEmailId);
		}
		\file_put_contents(
			\APP_PRIVATE_DATA . 'avatars/' . $sEmailId . \Tachyon\Util\File\MimeType::toExtension($aResult[0]),
			$aResult[1]
		);
		\MailSo\Base\Http::setLastModified(\time());
	}

	/**
	 * The PHOTO of the contact this address belongs to, if there is one.
	 *
	 * Three shapes turn up. A card written here, or a 3.0 card converted on
	 * import, holds a data: URI. A 4.0 card that kept the 3.0 ENCODING=b
	 * parameter, which is what several servers still write, holds bare base64.
	 * And a card may point at a URL instead of embedding anything at all, which
	 * is what Nextcloud does. Only the first was handled, so a synced photo
	 * showed in the contact editor, where the browser loads the value itself,
	 * and 404'd in the message list.
	 */
	private function getContactPhoto(string $sEmail) : ?array
	{
		try
		{
			$oActions = \Tachyon\Api::Actions();
			// false: this runs from a part hook, and a missing token should be a
			// quiet miss rather than an exception caught below
			$oAccount = $oActions->getAccountFromToken(false);
			if (!$oAccount) {
				\Tachyon\Util\Log::debug('Avatar', 'contact photo: no account from token');
				return null;
			}
			$oAddressBook = $oActions->AddressBookProvider($oAccount);
			if (!$oAddressBook || !$oAddressBook->IsActive()) {
				\Tachyon\Util\Log::debug('Avatar', 'contact photo: no active address book');
				return null;
			}
			$oContact = $oAddressBook->GetContactByEmail($sEmail);
			if (!$oContact) {
				\Tachyon\Util\Log::debug('Avatar', "contact photo: no contact for {$sEmail}");
				return null;
			}
			$oVCard = $oContact->vCard;
			if (!$oVCard) {
				\Tachyon\Util\Log::debug('Avatar', "contact photo: contact for {$sEmail} has no vCard");
				return null;
			}
			if (!isset($oVCard->PHOTO)) {
				\Tachyon\Util\Log::debug('Avatar', "contact photo: no PHOTO on the contact for {$sEmail}");
				return null;
			}
			$sPhoto = \trim((string) $oVCard->PHOTO);

			// Embedded, as a data: URI
			if (\preg_match('#^data:(image/[a-z.+-]+);base64,(.+)$#i', $sPhoto, $aMatch)) {
				$sBinary = \base64_decode($aMatch[2], true);
				if ($sBinary) {
					return [$aMatch[1], $sBinary];
				}
			}

			// Embedded, as bare base64 with the type in a parameter
			if (\preg_match('#^[A-Za-z0-9+/=\s]+$#', $sPhoto)) {
				$sBinary = \base64_decode(\preg_replace('#\s+#', '', $sPhoto), true);
				if ($sBinary) {
					$sMime = \Tachyon\Util\File\MimeType::fromString($sBinary);
					if ($sMime && \str_starts_with($sMime, 'image/')) {
						return [$sMime, $sBinary];
					}
				}
			}

			// Somewhere else entirely, which is what Nextcloud writes. Handed back
			// as a URL for the browser to load rather than fetched here.
			//
			// Fetching it would make this service a way to make the server issue
			// requests to any address a contact names, and blocking private
			// addresses to prevent that would break the common case, since a self
			// hosted Nextcloud usually is on a private address. The browser has
			// to be able to reach it anyway, or the contact editor could not show
			// it either.
			if (\preg_match('#^https?://#i', $sPhoto)) {
				return ['text/uri-list', $sPhoto];
			}

			\Tachyon\Util\Log::debug('Avatar',
				'contact photo: PHOTO is not an image this can serve, starts: ' . \substr($sPhoto, 0, 40));
		}
		catch (\Throwable $oException)
		{
			// An avatar is decoration. Never let it break the message list.
			\Tachyon\Util\Log::warning('Avatar', 'Contact photo lookup failed: ' . $oException->getMessage());
		}
		return null;
	}

	/**
	 * An image the admin dropped in APP_PRIVATE_DATA, named after the address or
	 * its domain, as the top of this file describes.
	 */
	private static function getCustomImage(string $sEmail) : ?array
	{
		$sEmail = \mb_strtolower(\Tachyon\Util\IDN::emailToAscii($sEmail));
		$aFiles = static::isPlainAddress($sEmail) ? \glob(\APP_PRIVATE_DATA . "avatars/{$sEmail}.*") : [];
		if (!$aFiles && \str_contains($sEmail, '@')) {
			$sDomain = \explode('@', $sEmail);
			$sDomain = \array_pop($sDomain);
			$aFiles = static::isPlainHost($sDomain) ? \glob(\APP_PRIVATE_DATA . "avatars/{$sDomain}.*") : [];
		}
		return $aFiles ? static::readImage($aFiles[0]) : null;
	}

	/**
	 * What an earlier lookup fetched, written by cacheImage() under the sha1 of
	 * the address or, for a site icon, favicon-<domain>. Those names are nothing
	 * an admin would choose, which keeps the two apart: a favicon cached for the
	 * domain used to be served for every address at it, ahead of the contact
	 * photo and of anything the admin had put there by hand.
	 */
	private static function getCachedImage(string $sEmail) : ?array
	{
		$sEmail = \mb_strtolower(\Tachyon\Util\IDN::emailToAscii($sEmail));
		$aFiles = [];
		if (\str_contains($sEmail, '@')) {
			$sEmailId = \sha1($sEmail);
			$aFiles = \glob(\APP_PRIVATE_DATA . "avatars/{$sEmailId}.*");
			if (!$aFiles) {
				$sDomain = \explode('@', $sEmail);
				$sDomain = \array_pop($sDomain);
				$aFiles = static::isPlainHost($sDomain) ? \glob(\APP_PRIVATE_DATA . 'avatars/favicon-' . $sDomain . '.*') : [];
			}
		}
		return $aFiles ? static::readImage($aFiles[0]) : null;
	}

	/**
	 * Only a plain host name, or a plain address at one, is put into a glob()
	 * pattern or a file name. Glob characters and path separators passed
	 * IDN::emailToAscii() untouched, so "x@../configs/application" was answered
	 * with application.ini.
	 */
	private static function isPlainHost(string $sDomain) : bool
	{
		return 1 === \preg_match('/^[a-z0-9_-]+(?:\.[a-z0-9_-]+)*$/D', $sDomain);
	}

	private static function isPlainAddress(string $sEmail) : bool
	{
		$aParts = \explode('@', $sEmail);
		return 2 === \count($aParts)
			&& 1 === \preg_match('/^[a-z0-9._+-]+$/D', $aParts[0])
			&& static::isPlainHost($aParts[1]);
	}

	private static function readImage(string $sFile) : array
	{
		return [
			\Tachyon\Util\File\MimeType::fromFile($sFile),
			\file_get_contents($sFile)
		];
	}

	private static function getFavicon(string $sDomain) : ?array
	{
		$aResult = static::getUrl('https://' . $sDomain . '/favicon.ico')
			?: static::getUrl('https://' . static::serviceDomain($sDomain) . '/favicon.ico')
			?: static::getUrl('https://www.' . static::serviceDomain($sDomain) . '/favicon.ico')
			?: static::getUrl("https://www.google.com/s2/favicons?sz=48&domain_url={$sDomain}")
			?: static::getUrl("https://api.faviconkit.com/{$sDomain}/48")
//			?: static::getUrl("https://api.statvoo.com/favicon/{$sDomain}")
		;
/*
		Also detect the following?

		<link sizes="16x16" rel="shortcut icon" type="image/x-icon" href="/..." />
		<link sizes="16x16" rel="shortcut icon" type="image/png" href="/..." />
		<link sizes="32x32" rel="shortcut icon" type="image/png" href="/..." />
		<link sizes="96x96" rel="shortcut icon" type="image/png" href="/..." />

		<link sizes="36x36" rel="icon" type="image/png" href="/..." />
		<link sizes="48x48" rel="icon" type="image/png" href="/..." />
		<link sizes="72x72" rel="icon" type="image/png" href="/..." />
		<link sizes="96x96" rel="icon" type="image/png" href="/..." />
		<link sizes="144x144" rel="icon" type="image/png" href="/..." />
		<link sizes="192x192" rel="icon" type="image/png" href="/..." />

		<link sizes="57x57" rel="apple-touch-icon" type="image/png" href="/..." />
		<link sizes="60x60" rel="apple-touch-icon" type="image/png" href="/..." />
		<link sizes="72x72" rel="apple-touch-icon" type="image/png" href="/..." />
		<link sizes="76x76" rel="apple-touch-icon" type="image/png" href="/..." />
		<link sizes="114x114" rel="apple-touch-icon" type="image/png" href="/..." />
		<link sizes="120x120" rel="apple-touch-icon" type="image/png" href="/..." />
		<link sizes="144x144" rel="apple-touch-icon" type="image/png" href="/..." />
		<link sizes="152x152" rel="apple-touch-icon" type="image/png" href="/" />
		<link sizes="180x180" rel="apple-touch-icon" type="image/png" href="/..." />
		<link sizes="192x192" rel="apple-touch-icon" type="image/png" href="/..." />
*/
		if ($aResult) {
			static::cacheImage('favicon-' . $sDomain, $aResult);
		}
		return $aResult;
	}

	private static function getUrl(string $sUrl) : ?array
	{
		// With BIMI on, this URL comes from a DNS record belonging to the domain in
		// the address being looked up, so the caller chooses it. Everything fetched
		// here is https by construction, and DNS::BIMI does not check what it pulls
		// out of the record, so refuse anything else rather than trust it.
		if (!\str_starts_with(\strtolower($sUrl), 'https://')) {
			\Tachyon\Util\Log::notice('Avatar', "refused non-https {$sUrl}");
			return null;
		}
		$oHTTP = \Tachyon\Util\HTTP\Request::factory(/*'socket' or 'curl'*/);
		// Request defaults this off and only the image proxy switched it on, so the
		// gate added in 4.4.0 did not cover this path at all.
		$oHTTP->block_private_ips = true;
		$oHTTP->proxy = \Tachyon\Api::Config()->Get('labs', 'curl_proxy', '');
		$oHTTP->proxy_auth = \Tachyon\Api::Config()->Get('labs', 'curl_proxy_auth', '');
		$oHTTP->max_response_kb = 0;
		$oHTTP->timeout = 15; // timeout in seconds.
		try {
			$oResponse = $oHTTP->doRequest('GET', $sUrl);
			if ($oResponse) {
				if (200 === $oResponse->status && \str_starts_with($oResponse->getHeader('content-type'), 'image/')) {
					return [
						$oResponse->getHeader('content-type'),
						$oResponse->body
					];
				}
				\Tachyon\Util\Log::notice('Avatar', "error {$oResponse->status} for {$sUrl}");
			} else {
				\Tachyon\Util\Log::warning('Avatar', "failed for {$sUrl}");
			}
		} catch (\Throwable $e) {
			\Tachyon\Util\Log::notice('Avatar', "error {$e->getMessage()}");
		}
		return null;
	}
}
