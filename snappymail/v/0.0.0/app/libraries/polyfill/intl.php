<?php

if (!\function_exists('idn_to_ascii')) {
	function idn_to_ascii(string $domain) {
		return \Tachyon\Util\Intl\Idn::toAscii($domain);
	}
}

if (!\function_exists('idn_to_utf8')) {
	function idn_to_utf8(string $domain) {
		return \Tachyon\Util\Intl\Idn::toUtf8($domain);
	}
}
