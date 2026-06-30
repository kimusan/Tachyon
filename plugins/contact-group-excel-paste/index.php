<?php

class ContactGroupExcelPastePlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME     = '',
		CATEGORY = 'General',
		DESCRIPTION = '';

	public function Init() : void
	{
		$this->addJs('js/excel_contact_group.js');
	}
}
