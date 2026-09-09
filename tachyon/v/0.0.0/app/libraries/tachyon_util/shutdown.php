<?php

namespace Tachyon\Util;

abstract class Shutdown
{
	private static
		$actions = [],
		$running = false;

	final public static function run() : void
	{
		if (!static::$running && \count(static::$actions)) {
			static::$running = true;
			\ini_set('display_errors', 0);
			\ignore_user_abort(true);

			# Flush the output buffers this application opened, so the response is
			# on its way before the actions below run.
			#
			# Not PHP's own. With zlib.output_compression on, PHP installs a
			# compression buffer around everything, and ending that one here fails
			# and writes "Failed to send buffer of zlib output compression" to the
			# log on every single request. It is only a notice, and the response is
			# sent correctly regardless, because PHP flushes that buffer itself
			# moments later. Ending it here achieved nothing and cost a log line.
			#
			# By name rather than by nesting depth: it is the outermost buffer on
			# FPM but not under every SAPI, and Service.php nests one or two of its
			# own inside it.
			while (\ob_get_level()) {
				$aStatus = \ob_get_status();
				if ('zlib output compression' === ($aStatus['name'] ?? '')) {
					break;
				}
				if (!\ob_end_flush()) {
					break;
				}
			}
			\flush();

			if (\is_callable('fastcgi_finish_request')) {
				// Special FPM/FastCGI (fpm-fcgi) function to finish request and
				// flush all data while continuing to do something time-consuming.
				\fastcgi_finish_request();
			}

			foreach (static::$actions as $action) {
				try {
					\call_user_func_array($action[0], $action[1]);
				} catch (\Throwable $e) { } # skip
			}
		}
	}

	final public static function add(callable $function, array $args = []) : void
	{
		if (!\count(static::$actions)) {
			\register_shutdown_function('\\Tachyon\Util\\Shutdown::run');
		}
		static::$actions[] = [$function, $args];
	}

	final public static function count() : int
	{
		return \count(static::$actions);
	}
}
