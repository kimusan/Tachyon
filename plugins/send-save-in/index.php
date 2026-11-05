<?php

class SendSaveInPlugin extends \Tachyon\Plugins\AbstractPlugin
{
//	use \MailSo\Log\Inherit;

	const
		NAME     = 'Send Save In',
		AUTHOR   = 'Tachyon',
		URL      = 'https://github.com/kimusan/Tachyon',
		VERSION  = '0.2',
		RELEASE  = '2026-10-28',
		REQUIRED = '4.5.0',
		CATEGORY = 'General',
		LICENSE  = 'MIT',
		DESCRIPTION = 'When composing a message, select the save folder';

	public function Init() : void
	{
//		$this->UseLangs(true); // start use langs folder
		$this->addJs('savein.js');
		$this->addTemplate('templates/ComposeSendSaveIn.html');
	}
}
