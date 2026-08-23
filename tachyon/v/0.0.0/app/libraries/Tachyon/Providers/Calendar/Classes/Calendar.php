<?php

namespace Tachyon\Providers\Calendar\Classes;

class Calendar implements \JsonSerializable
{
	public string $id = '';

	/**
	 * Stable identifier. For CalDAV this is derived from the collection path,
	 * so it survives a rename of the calendar's display name.
	 */
	public string $Uuid = '';

	public string $Name = '';

	public string $Color = '';

	public string $Description = '';

	/**
	 * Olson name from CALDAV:calendar-timezone, used when a floating event has
	 * no timezone of its own.
	 */
	public string $Timezone = '';

	public bool $ReadOnly = false;

	public int $Changed;

	/**
	 * Collection path on the DAV server. Empty for a calendar that is not synced.
	 */
	public string $DavPath = '';

	public string $Etag = '';

	function __construct()
	{
		$this->Changed = \time();
	}

	public function jsonSerialize()
	{
		return array(
			'@Object' => 'Object/Calendar',
			'id' => $this->id,
			'uuid' => $this->Uuid,
			'name' => $this->Name,
			'color' => $this->Color,
			'description' => $this->Description,
			'timezone' => $this->Timezone,
			'readOnly' => $this->ReadOnly,
			'synced' => '' !== $this->DavPath
		);
	}
}
