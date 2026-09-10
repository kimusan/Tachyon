<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2023 DJMaze
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MailSo\Log\Drivers;

/**
 * @category MailSo
 * @package Log
 * @subpackage Drivers
 */
class StderrStream extends \MailSo\Log\Driver
{
	/** @var resource */
	private $rStream;

	/**
	 * Whether fd 2 leads anywhere. A php-fpm worker inherits /dev/null on it
	 * unless the pool sets catch_workers_output, so a raw write there vanishes
	 * while every config key says logging is on. Decided once: the target
	 * cannot change during a request and the check costs two stat calls.
	 */
	private bool $bStreamIsSink;

	/**
	 * @param resource|null $rStream Defaults to STDERR; injectable for tests.
	 */
	function __construct($rStream = null)
	{
		parent::__construct();
		if (null === $rStream) {
			if (!\defined('STDERR')) {
				\define('STDERR', \fopen('php://stderr', 'wb'));
			}
			$rStream = STDERR;
		}
		$this->rStream = $rStream;
		$this->bStreamIsSink = static::isDevNull($rStream);
	}

	protected function writeImplementation($mDesc) : bool
	{
		if ($this->bStreamIsSink) {
			// Through the SAPI instead. Under FastCGI that is the stderr
			// stream the web server copies into its own error log, so the
			// line ends up where the operator who chose "stderr" will look.
			return \error_log($mDesc);
		}
		return 0 < \fwrite($this->rStream, $mDesc . "\n");
	}

	protected function clearImplementation() : bool
	{
		return true;
	}

	/**
	 * @param resource $rStream
	 */
	private static function isDevNull($rStream) : bool
	{
		$aStream = \is_resource($rStream) ? @\fstat($rStream) : false;
		$aNull = @\stat('/dev/null');
		return $aStream && $aNull
			&& $aStream['dev'] === $aNull['dev']
			&& $aStream['ino'] === $aNull['ino'];
	}
}
