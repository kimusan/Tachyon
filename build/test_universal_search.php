<?php
require __DIR__.'/test_attachment_search.php';

class UniversalImap extends AttachmentSearchImap
{
	public array $listed = [];
	public bool $native = false;
	public bool $nativeFails = false;
	public bool $fail = false;
	public function FolderStatusAndSelect(string $folder): \MailSo\Imap\FolderInformation { return $this->FolderExamine($folder); }
	public function hasCapability(string $name): bool { return $name === 'MULTISEARCH' && $this->native; }
	public function MessageMultiSearchMailboxes(string $criteria, array $folders): array
	{
		if ($this->nativeFails) { throw new \MailSo\RuntimeException('Native search unavailable'); }
		$this->searched = $folders;
		return array_map('array_keys', array_intersect_key($this->folders, array_flip($folders)));
	}
	public array $searched = [];
	public array $dates = [];
	public string $selected = '';
	public function FolderList(string $sParent = '', string $sListPattern = '*', bool $bIsSubscribeList = false, bool $bUseListStatus = false): \MailSo\Imap\FolderCollection
	{
		$result = new \MailSo\Imap\FolderCollection;
		foreach ($this->listed as $folder) { $result->append($folder); }
		return $result;
	}
	public function FolderExamine(string $sFolderName, bool $bForceReselect = false): \MailSo\Imap\FolderInformation
	{
		if ($this->fail && $sFolderName === 'Archive') { throw new \MailSo\RuntimeException('Folder unavailable'); }
		$this->selected = $sFolderName;
		$info = parent::FolderExamine($sFolderName, $bForceReselect);
		$info->MESSAGES = 0;
		return $info;
	}
	public function MessageSearch(string $criteria, bool $uid = true): array
	{
		$this->searched[] = $this->selected;
		return parent::MessageSearch($criteria, $uid);
	}
	public function FetchIterate(array $items, string $range, bool $uid): iterable
	{
		if (!in_array('INTERNALDATE', $items)) { yield from parent::FetchIterate($items, $range, $uid); return; }
		$this->fetches[] = [$items, $range, $uid];
		foreach (\MailSo\Imap\SequenceSet::expand($range) as $id) {
			$response = new \MailSo\Imap\Response;
			$response->ResponseList = ['*', $id, 'FETCH', ['UID', $id, 'INTERNALDATE', $this->dates[$this->selected][$id]]];
			yield new \MailSo\Imap\FetchResponse($response);
		}
	}
}
$imap = new UniversalImap;
$imap->Settings = (new ReflectionClass(\MailSo\Imap\Settings::class))->newInstanceWithoutConstructor();
foreach (['INBOX', 'Archive', 'Spam', 'Spam/Old', 'Trash'] as $name) {
	$imap->listed[] = new \MailSo\Imap\Folder($name, '/', $name === 'Trash' ? ['\\Trash'] : []);
	$imap->folders[$name] = [1 => $text];
	$imap->dates[$name] = [1 => '01-Jan-2026 00:00:00 +0000'];
}
$imap->listed[] = new \MailSo\Imap\Folder('Container', '/', ['\\Noselect']);
$imap->dates['Archive'][1] = '02-Jan-2026 00:00:00 +0000';
$mail = new AttachmentSearchMail;
(new ReflectionProperty(\MailSo\Mail\MailClient::class, 'oImapClient'))->setValue($mail, $imap);
$params = new \MailSo\Mail\MessageListParams;
$params->sFolderName = 'INBOX';
$params->sSearch = 'in=all';
$params->iLimit = 10;
$params->aSearchExcludedFolders = ['Spam'];
$params->oAttachmentCacher = new AttachmentSearchCache;
$criteria = \MailSo\Imap\SearchCriterias::fromString($imap, 'INBOX', $params->sSearch, true);
check($criteria->sIn === 'all', 'Parser recognises account scope');
$search = new ReflectionMethod($mail, 'MessageListAccount');
$result = new \MailSo\Mail\MessageCollection;
$search->invoke($mail, $params, $result, $criteria);
check($imap->searched === ['INBOX', 'Archive'], 'Exclude configured spam, descendants, special-use trash and unselectable folders');
check($result->totalEmails === 2 && $mail->page === [['Archive', 1], ['INBOX', 1]], 'Globally order by date while retaining folder identity');
$imap->fetches = [];
$params->iOffset = 1;
$search->invoke($mail, $params, $result, $criteria);
check($mail->page === [['INBOX', 1]] && !$imap->fetches, 'Pagination reuses cached dates');
$criteria = \MailSo\Imap\SearchCriterias::fromString($imap, 'INBOX', 'in=all&include-spam-trash', true);
$imap->searched = [];
$search->invoke($mail, $params, $result, $criteria);
check(count($imap->searched) === 5, 'Checkbox includes Spam and Trash and their descendants');
$imap->native = true;
$params->iOffset = 0;
$criteria = \MailSo\Imap\SearchCriterias::fromString($imap, 'INBOX', 'in=all', true);
$search->invoke($mail, $params, $result, $criteria);
check($imap->searched === ['INBOX', 'Archive'] && $mail->page === [['Archive', 1], ['INBOX', 1]], 'Native search uses the same excluded scope and global order');
$imap->nativeFails = true;
$imap->searched = [];
$search->invoke($mail, $params, $result, $criteria);
check($imap->searched === ['INBOX', 'Archive'], 'Broken native search falls back across the full intended scope');
$imap->nativeFails = false;
$imap->fetches = [];
$imap->uidValidity++;
$search->invoke($mail, $params, $result, $criteria);
check((bool) $imap->fetches, 'Date cache is invalidated when UID validity changes');
$params->bAllowAccountSearch = true;
$params->sSearch = 'in=all';
$mail->MessageList($params);
check($mail->page === [['Archive', 1], ['INBOX', 1]], 'Public list routes account scope before empty-folder shortcuts');
$params->bAllowAccountSearch = false;
try { $mail->MessageList($params); throw new RuntimeException('Disabled scope was accepted'); }
catch (\MailSo\RuntimeException $expected) { check(str_contains($expected->getMessage(), 'disabled'), 'Administrator setting is enforced'); }
$imap->fail = true;
try { $search->invoke($mail, $params, $result, $criteria); throw new RuntimeException('Partial search was accepted'); }
catch (\MailSo\RuntimeException $expected) { check($expected->getMessage() === 'Folder unavailable', 'Folder failures abort account search'); }
$imap->fail = false;
$imap->Settings->message_all_headers = true;
$real = new \MailSo\Mail\MailClient;
(new ReflectionProperty($real, 'oImapClient'))->setValue($real, $imap);
$result->SearchScope = 'all';
(new ReflectionMethod($real, 'MessageListMultiFolderFetch'))->invoke($real, $result, [['Archive', 1], ['INBOX', 1], ['Archive', 1]]);
check(array_map(static fn ($msg) => $msg->sFolder, $result->getArrayCopy()) === ['Archive', 'INBOX', 'Archive'], 'Fetching grouped by mailbox restores global tuple order');
echo "Universal search checks passed.\n";
