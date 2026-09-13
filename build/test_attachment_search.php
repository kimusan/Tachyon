<?php

// Run with: php build/test_attachment_search.php
spl_autoload_register(static function (string $class): void {
	$file = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/'.str_replace('\\', '/', $class).'.php';
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

class AttachmentSearchImap extends \MailSo\Imap\ImapClient
{
	public array $bodies = [];
	public array $fetches = [];
	public array $folders = [];
	public function hasCapability(string $sExtentionName): bool { return $sExtentionName === 'MULTISEARCH' && (bool) $this->folders; }
	public function Hash(): string { return 'test-account'; }
	public function FolderExamine(string $sFolderName, bool $bForceReselect = false): \MailSo\Imap\FolderInformation
	{
		if ($this->folders) {
			$this->bodies = $this->folders[$sFolderName];
		}
		return new \MailSo\Imap\FolderInformation($sFolderName, false);
	}
	public function FolderSelect(string $sFolderName, bool $bForceReselect = false): \MailSo\Imap\FolderInformation
	{
		return $this->FolderExamine($sFolderName, $bForceReselect);
	}
	public function MessageMultiSearch(string $sSearchCriterias, string $sIn, string $sBaseFolder, bool $bReturnUid = true): array
	{
		return array_map('array_keys', $this->folders);
	}
	public function MessageSearch(string $sSearchCriterias, bool $bReturnUid = true): array
	{
		check(!str_contains($sSearchCriterias, 'HEADER Content-Type'), 'Attachment search must not discard candidates by top-level MIME type');
		return array_reverse(array_keys($this->bodies));
	}
	public function FetchIterate(array $aInputFetchItems, string $sIndexRange, bool $bIndexIsUid): iterable
	{
		$this->fetches[] = [$aInputFetchItems, $sIndexRange, $bIndexIsUid];
		$ids = \MailSo\Imap\SequenceSet::expand($sIndexRange);
		foreach ($this->bodies as $uid => $body) {
			if (in_array($uid, $ids)) {
				$response = new \MailSo\Imap\Response;
				$response->ResponseList = ['*', $uid, 'FETCH', ['UID', $uid, 'BODYSTRUCTURE', $body]];
				yield new \MailSo\Imap\FetchResponse($response);
			}
		}
	}
}

$imap = new AttachmentSearchImap;
$imap->Settings = (new ReflectionClass(\MailSo\Imap\Settings::class))->newInstanceWithoutConstructor();
$text = ['TEXT', 'PLAIN', ['CHARSET', 'UTF-8'], null, null, '7BIT', 20, 1];
$file = ['TEXT', 'PLAIN', ['CHARSET', 'UTF-8'], null, null, '7BIT', 20, 1, null, ['ATTACHMENT', ['FILENAME', 'notes.txt']]];
$image = ['IMAGE', 'PNG', null, null, null, 'BASE64', 20, null, ['ATTACHMENT', ['FILENAME', 'photo.png']]];
$imap->bodies = [1 => $text, 2 => [$text, $text, 'MIXED'], 3 => $file, 4 => [$text, $image, 'RELATED']];
$client = new \MailSo\Mail\MailClient;
(new ReflectionProperty($client, 'oImapClient'))->setValue($client, $imap);
$params = new \MailSo\Mail\MessageListParams;
$params->sFolderName = 'INBOX';
$params->sSearch = 'attachment';
$info = new \MailSo\Imap\FolderInformation('INBOX', false);
$getUids = new ReflectionMethod($client, 'GetUids');
$uids = $getUids->invoke($client, $params, $info);
check($uids === [4, 3], 'Keep actual attachments, reject multipart text, and preserve search order');
check($imap->fetches[0][0] === ['UID', 'BODYSTRUCTURE'], 'Fetch metadata only');

$imap->fetches = [];
$params->sSearch = '';
check($getUids->invoke($client, $params, $info) === [4, 3, 2, 1], 'Ordinary searches are unchanged');
check(!$imap->fetches, 'Ordinary searches need no attachment fetches');

$params->sSearch = 'has:attachment';
check($getUids->invoke($client, $params, $info) === [4, 3], 'Text search syntax also filters attachments');

$inline = ['IMAGE', 'PNG', null, '<logo>', null, 'BASE64', 20, null, ['INLINE', ['FILENAME', 'logo.png']]];
$cidOnly = ['IMAGE', 'PNG', null, '<logo>', null, 'BASE64', 20];
$explicitWithCid = $image;
$explicitWithCid[3] = '<attached-photo>';
$imap->bodies = [
	1 => [$text, $inline, 'RELATED'],
	2 => [$text, $cidOnly, 'RELATED'],
	3 => [$text, $inline, $file, 'MIXED'],
	4 => [$text, $explicitWithCid, 'MIXED'],
];
check($getUids->invoke($client, $params, $info) === [4, 3], 'Exclude inline-only mail, retain real attachments even with a Content-ID');
$body = \MailSo\Imap\BodyStructure::NewInstance([$text, $inline, 'RELATED']);
check($body->SearchAttachmentsParts()->valid(), 'Message rendering must retain inline resources');

$imap->bodies = array_fill(1, 501, $file);
$imap->fetches = [];
$params->sSearch = 'attachment';
check(count($getUids->invoke($client, $params, $info)) === 501, 'Large searches retain every attachment');
check(count($imap->fetches) > 1, 'Metadata fetches are batched');

class AttachmentSearchCache extends \MailSo\Cache\CacheClient
{
	private array $values = [];
	public function IsInited(): bool { return true; }
	public function Set(string $sKey, string $sValue): bool { $this->values[$sKey] = $sValue; return true; }
	public function Get(string $sKey, bool $bClearAfterGet = false): ?string { return $this->values[$sKey] ?? null; }
}
$params->oCacher = new AttachmentSearchCache;
$info->MESSAGES = 501;
$info->UIDNEXT = 502;
$info->UIDVALIDITY = 1;
$info->etag = 'before-append';
$getUids->invoke($client, $params, $info);
$imap->fetches = [];
check(count($getUids->invoke($client, $params, $info)) === 501 && !$imap->fetches, 'Unchanged folder reuses filtered cache');
$params->sSearch = '';
$imap->bodies[502] = $text;
check(count($getUids->invoke($client, $params, $info)) === 502, 'Attachment cache cannot contaminate an ordinary search');
$params->sSearch = 'attachment';
$info->UIDNEXT = 503;
$info->etag = 'after-append';
$getUids->invoke($client, $params, $info);
check((bool) $imap->fetches, 'Folder changes invalidate attachment results');

class AttachmentSearchMail extends \MailSo\Mail\MailClient
{
	public array $page = [];
	protected function MessageListMultiFolderFetch(\MailSo\Mail\MessageCollection $collection, array $tuples): void
	{
		$this->page = $tuples;
	}
}
$multi = new AttachmentSearchMail;
(new ReflectionProperty(\MailSo\Mail\MailClient::class, 'oImapClient'))->setValue($multi, $imap);
$imap->folders = ['INBOX' => [1 => $text, 2 => $file], 'INBOX/Sub' => [1 => $file, 2 => $text]];
$params->iOffset = 1;
$params->iLimit = 1;
$criteria = \MailSo\Imap\SearchCriterias::fromString($imap, 'INBOX', 'attachment&in=subtree', true);
$collection = new \MailSo\Mail\MessageCollection;
(new ReflectionMethod($multi, 'MessageListMultiFolder'))->invoke($multi, $params, $collection, $criteria);
check($collection->totalEmails === 2, 'Multi-folder total counts attachments before pagination');
check($multi->page === [['INBOX/Sub', 1]], 'Pagination preserves folder identity when UIDs overlap');

$imap->folders = [];
$imap->bodies = [1 => $text, 2 => $file];
require_once dirname(__DIR__).'/plugins/search-filters/index.php';
$plugin = (new ReflectionClass(SearchFiltersPlugin::class))->newInstanceWithoutConstructor();
$pluginSearch = new ReflectionMethod($plugin, 'searchMessages');
check($pluginSearch->invoke($plugin, $imap, 'attachment', 'INBOX') === [2], 'Automatic attachment rules must not match ordinary messages');
$imap->bodies = [1 => null];
$params->oCacher = null;
try {
	$getUids->invoke($client, $params, $info);
	throw new RuntimeException('Missing metadata must not silently count as no attachment');
} catch (\MailSo\RuntimeException $expected) {
	check(str_contains($expected->getMessage(), 'BODYSTRUCTURE'), 'Missing metadata reports the failed search');
}
echo "Attachment search regression checks passed.\n";
