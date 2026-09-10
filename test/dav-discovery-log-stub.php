<?php

namespace Tachyon\Util;

/**
 * Stands in for the real Tachyon\Util\Log, whose log() reaches for
 * Tachyon\Api and therefore the whole application bootstrap.
 *
 * Tachyon\Util\DAV\Client logs unconditionally, so without this a standalone
 * harness dies inside a try/catch and reports "no collections" for what is
 * really a missing class. Required before the DAV client so that PHP resolves
 * to this declaration.
 *
 * Set DAV_DISCOVERY_VERBOSE=1 to see the traffic.
 */
abstract class Log
{
	public static function debug(string $prefix, string $msg) { self::emit('DEBUG', $prefix, $msg); }
	public static function info(string $prefix, string $msg) { self::emit('INFO', $prefix, $msg); }
	public static function notice(string $prefix, string $msg) { self::emit('NOTICE', $prefix, $msg); }
	public static function warning(string $prefix, string $msg) { self::emit('WARNING', $prefix, $msg); }
	public static function error(string $prefix, string $msg) { self::emit('ERROR', $prefix, $msg); }
	public static function critical(string $prefix, string $msg) { self::emit('CRITICAL', $prefix, $msg); }
	public static function alert(string $prefix, string $msg) { self::emit('ALERT', $prefix, $msg); }
	public static function emergency(string $prefix, string $msg) { self::emit('EMERGENCY', $prefix, $msg); }

	private static function emit(string $sLevel, string $sPrefix, string $sMsg) : void
	{
		if (\getenv('DAV_DISCOVERY_VERBOSE')) {
			\fwrite(STDERR, "[{$sLevel}] [{$sPrefix}] {$sMsg}\n");
		}
	}
}
