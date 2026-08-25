<?php
namespace OCA\Tachyon\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class Section implements IIconSection
{
	private IL10N $l;
	private IURLGenerator $urlGenerator;

	public function __construct(IL10N $l, IURLGenerator $urlGenerator)
	{
		$this->l = $l;
		$this->urlGenerator = $urlGenerator;
	}

	public function getID()
	{
		return 'tachyon';
	}

	public function getName()
	{
		return $this->l->t('Tachyon Email');
	}

	/**
	 * Sections are sorted ascending and the value has to be between 0 and 99.
	 * "Additional settings", where these used to live, sits at 98.
	 */
	public function getPriority()
	{
		return 75;
	}

	public function getIcon()
	{
		// Black paths on transparent, which is what the settings navigation
		// expects. Nextcloud inverts it for dark mode.
		return $this->urlGenerator->imagePath('tachyon', 'favicon-mask.svg');
	}
}
