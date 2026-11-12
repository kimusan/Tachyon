<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2022 DJMaze
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Messages in IMAP4rev1 are accessed by the use of numbers.
 * These numbers are either message sequence numbers or unique identifiers.
 * https://datatracker.ietf.org/doc/html/rfc3501#section-2.3.1
 */

namespace MailSo\Imap;

/**
 * @category MailSo
 * @package Mail
 */
class SequenceSet /*extends \SplFixedArray*/ implements \Countable
{
	/**
	 * By default the numbers are unique identifiers as this is more reliable.
	 */
	public bool $UID = true;

	private array $data = [];

	/**
	 * @param mixed $mItems Can be array, string or int
	 */
	public function __construct($mItems, bool $uid = true)
	{
		if (\is_array($mItems)) {
			// Anchored at both ends. Without the $ anything merely starting like a
			// range was kept whole, so "1:2 EVIL" reached the wire verbatim and
			// anything after it was read as more of the IMAP command. Nothing in
			// the tree feeds this anything but server-derived integers today, so
			// it was a sharp edge rather than a hole, but this is the one place
			// every FETCH, STORE and COPY sequence passes through.
			$this->data = \array_values($uid ? \array_filter(\array_map(function($id){
				return \preg_match('/^([0-9]+|\\*):([0-9]+|\\*)$/D', (string) $id) ? $id : \intval($id);
			}, $mItems)) : $mItems);
		} else if (\is_scalar($mItems)) {
			$this->data[] = $mItems;
		}
		$this->UID = $uid;
	}

	public function count(): int
	{
		return \count($this->data);
	}

	public function contains($value): bool
	{
		return \in_array($value, $this->data);
	}

	public function indexOf($value)/*: int|false*/
	{
		return \array_search($value, $this->data);
	}

	public function getArrayCopy(): array
	{
		return $this->data;
	}

	public function __toString(): string
	{
		return \implode(',', $this->parts());
	}

	/**
	 * The set as strings no longer than $iMaxLength, to send one command each.
	 * Servers cap the command line (Dovecot at 64k by default) and RFC 7162
	 * asks clients to stay under 8192 octets. Selecting every message in a big
	 * folder easily goes past that.
	 * Only for UIDs: a sequence number moves once an earlier MOVE or EXPUNGE
	 * removes messages, so a sequence set has to go in one command.
	 */
	public function chunks(int $iMaxLength = 8000): array
	{
		if (!$this->UID) {
			return [(string) $this];
		}
		$aResult = [];
		$sChunk = '';
		foreach ($this->parts() as $sPart) {
			if (\strlen($sChunk) && \strlen($sChunk) + 1 + \strlen($sPart) > $iMaxLength) {
				$aResult[] = $sChunk;
				$sChunk = '';
			}
			$sChunk .= (\strlen($sChunk) ? ',' : '') . $sPart;
		}
		\strlen($sChunk) && $aResult[] = $sChunk;
		return $aResult;
	}

	/**
	 * Runs of consecutive numbers become ranges. The numbers are sorted first,
	 * since a set has no order in IMAP and the client sends a message list
	 * newest first, which left nothing to merge: 11,000 messages went out as
	 * 11,000 numbers.
	 */
	private function parts(): array
	{
		$aResult = array();
		$aNumbers = array();
		foreach ($this->data as $mItem) {
			if (\is_int($mItem) || \ctype_digit((string) $mItem)) {
				$aNumbers[] = (int) $mItem;
			} else {
				$aResult[] = (string) $mItem;
			}
		}
		$aNumbers = \array_unique($aNumbers);
		\sort($aNumbers);

		$iStart = $iPrev = null;
		foreach ($aNumbers as $iItem) {
			if (null !== $iPrev && $iPrev + 1 === $iItem) {
				$iPrev = $iItem;
				continue;
			}
			null === $iStart || $aResult[] = $iStart === $iPrev ? (string) $iStart : $iStart.':'.$iPrev;
			$iStart = $iPrev = $iItem;
		}
		null === $iStart || $aResult[] = $iStart === $iPrev ? (string) $iStart : $iStart.':'.$iPrev;

		return $aResult;
	}

	/**
	 * Expands a sequence-set string into a list of numbers.
	 * The reverse of __toString(), used for the ESEARCH ALL return value.
	 * '*' is skipped as there is no folder context to resolve it against.
	 */
	public static function expand(string $sSequenceSet) : array
	{
		$aResult = [];
		foreach (\explode(',', $sSequenceSet) as $sPart) {
			$sPart = \trim($sPart);
			if (!\strlen($sPart) || \str_contains($sPart, '*')) {
				continue;
			}
			if (\str_contains($sPart, ':')) {
				[$iStart, $iEnd] = \array_map('\\intval', \explode(':', $sPart, 2));
				$aRange = \range(\min($iStart, $iEnd), \max($iStart, $iEnd));
				$aResult = \array_merge($aResult, $iStart > $iEnd ? \array_reverse($aRange) : $aRange);
			} else {
				$aResult[] = \intval($sPart);
			}
		}
		return \array_values(\array_unique(\array_filter($aResult)));
	}
}
