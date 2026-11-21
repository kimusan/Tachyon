<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MailSo\Imap\Exceptions;

/**
 * @category MailSo
 * @package Imap
 * @subpackage Exceptions
 */
class NegativeResponseException extends ResponseException
{

	public function getAlertFromStatus() : string
	{
		$sResult = '';

		$oResponse = $this->GetLastResponse();
		if ($oResponse && $oResponse->IsStatusResponse && !empty($oResponse->HumanReadable) &&
			'ALERT' === ($oResponse->OptionalResponse[0] ?? ''))
		{
			$sResult = $oResponse->HumanReadable;
		}

		return $sResult;
	}

	/**
	 * RFC 5530 codes that put a refused LOGIN down to the server, not to the
	 * credentials: it is overloaded or down, the mailbox is locked, or it hit a bug
	 */
	public function isTemporaryLoginFailure() : bool
	{
		$oResponse = $this->GetLastResponse();
		$sCode = $oResponse && \is_array($oResponse->OptionalResponse) && \is_string($oResponse->OptionalResponse[0] ?? null)
			? \strtoupper($oResponse->OptionalResponse[0])
			: '';
		return \in_array($sCode, array('UNAVAILABLE', 'INUSE', 'SERVERBUG'), true);
	}
}
