<?php

namespace Tachyon\Providers;

abstract class AbstractProvider
{
	use \MailSo\Log\Inherit;

	abstract public function IsActive() : bool;
}
