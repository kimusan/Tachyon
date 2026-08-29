<?php

namespace Tachyon\Providers\Calendar;

abstract class PdoSchema
{
	public static function mysql() : string
	{
		return <<<MYSQLINITIAL

CREATE TABLE IF NOT EXISTS tachyon_cal_calendars (

	id_calendar    bigint UNSIGNED  NOT NULL AUTO_INCREMENT,
	id_user        int UNSIGNED     NOT NULL,
	uuid           varchar(255)     NOT NULL DEFAULT '',
	name           varchar(255)     NOT NULL DEFAULT '',
	color          varchar(32)      CHARACTER SET ascii NOT NULL DEFAULT '',
	description    text             NOT NULL,
	timezone       varchar(64)      CHARACTER SET ascii NOT NULL DEFAULT '',
	dav_path       varchar(512)     NOT NULL DEFAULT '',
	read_only      tinyint UNSIGNED NOT NULL DEFAULT 0,
	changed        int UNSIGNED     NOT NULL DEFAULT 0,
	deleted        tinyint UNSIGNED NOT NULL DEFAULT 0,
	etag           varchar(128)     CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '',

	PRIMARY KEY(id_calendar),
	INDEX id_user_tachyon_cal_calendars_index (id_user)

) ENGINE=INNODB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tachyon_cal_events (

	id_event       bigint UNSIGNED  NOT NULL AUTO_INCREMENT,
	id_calendar    bigint UNSIGNED  NOT NULL,
	id_user        int UNSIGNED     NOT NULL,
	uid            varchar(255)     NOT NULL DEFAULT '',
	summary        varchar(255)     NOT NULL DEFAULT '',
	description    text             NOT NULL,
	location       text             NOT NULL,
	dtstart        bigint           NOT NULL DEFAULT 0,
	dtend          bigint           NOT NULL DEFAULT 0,
	all_day        tinyint UNSIGNED NOT NULL DEFAULT 0,
	rrule          varchar(512)     NOT NULL DEFAULT '',
	recur_until    bigint           DEFAULT NULL,
	timezone       varchar(64)      CHARACTER SET ascii NOT NULL DEFAULT '',
	ical           mediumtext       NOT NULL,
	dav_path       varchar(512)     NOT NULL DEFAULT '',
	changed        int UNSIGNED     NOT NULL DEFAULT 0,
	deleted        tinyint UNSIGNED NOT NULL DEFAULT 0,
	etag           varchar(128)     CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '',

	PRIMARY KEY(id_event),
	INDEX id_user_tachyon_cal_events_index (id_user),
	INDEX id_calendar_tachyon_cal_events_index (id_calendar),
	INDEX range_tachyon_cal_events_index (id_calendar, dtstart, dtend),
	INDEX recur_tachyon_cal_events_index (id_calendar, recur_until)

) ENGINE=INNODB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

MYSQLINITIAL;
	}

	public static function pgsql() : string
	{
		return <<<POSTGRESINITIAL

CREATE TABLE IF NOT EXISTS tachyon_cal_calendars (
	id_calendar  bigserial PRIMARY KEY,
	id_user      integer NOT NULL,
	uuid         varchar(255) NOT NULL DEFAULT '',
	name         varchar(255) NOT NULL DEFAULT '',
	color        varchar(32) NOT NULL DEFAULT '',
	description  text NOT NULL DEFAULT '',
	timezone     varchar(64) NOT NULL DEFAULT '',
	dav_path     varchar(512) NOT NULL DEFAULT '',
	read_only    integer NOT NULL DEFAULT 0,
	changed      integer NOT NULL DEFAULT 0,
	deleted      integer NOT NULL DEFAULT 0,
	etag         varchar(128) NOT NULL DEFAULT ''
);

CREATE INDEX id_user_tachyon_cal_calendars_index ON tachyon_cal_calendars (id_user);

CREATE TABLE IF NOT EXISTS tachyon_cal_events (
	id_event     bigserial PRIMARY KEY,
	id_calendar  bigint NOT NULL,
	id_user      integer NOT NULL,
	uid          varchar(255) NOT NULL DEFAULT '',
	summary      varchar(255) NOT NULL DEFAULT '',
	description  text NOT NULL DEFAULT '',
	location     text NOT NULL DEFAULT '',
	dtstart      bigint NOT NULL DEFAULT 0,
	dtend        bigint NOT NULL DEFAULT 0,
	all_day      integer NOT NULL DEFAULT 0,
	rrule        varchar(512) NOT NULL DEFAULT '',
	recur_until  bigint DEFAULT NULL,
	timezone     varchar(64) NOT NULL DEFAULT '',
	ical         text NOT NULL DEFAULT '',
	dav_path     varchar(512) NOT NULL DEFAULT '',
	changed      integer NOT NULL DEFAULT 0,
	deleted      integer NOT NULL DEFAULT 0,
	etag         varchar(128) NOT NULL DEFAULT ''
);

CREATE INDEX id_user_tachyon_cal_events_index ON tachyon_cal_events (id_user);
CREATE INDEX id_calendar_tachyon_cal_events_index ON tachyon_cal_events (id_calendar);
CREATE INDEX range_tachyon_cal_events_index ON tachyon_cal_events (id_calendar, dtstart, dtend);
CREATE INDEX recur_tachyon_cal_events_index ON tachyon_cal_events (id_calendar, recur_until);

POSTGRESINITIAL;
	}

	public static function sqlite() : string
	{
		return <<<SQLITEINITIAL

CREATE TABLE IF NOT EXISTS tachyon_cal_calendars (
	id_calendar  INTEGER NOT NULL PRIMARY KEY,
	id_user      INTEGER NOT NULL,
	uuid         TEXT NOT NULL DEFAULT '',
	name         TEXT NOT NULL DEFAULT '',
	color        TEXT NOT NULL DEFAULT '',
	description  TEXT NOT NULL DEFAULT '',
	timezone     TEXT NOT NULL DEFAULT '',
	dav_path     TEXT NOT NULL DEFAULT '',
	read_only    INTEGER NOT NULL DEFAULT 0,
	changed      INTEGER NOT NULL DEFAULT 0,
	deleted      INTEGER NOT NULL DEFAULT 0,
	etag         TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS id_user_tachyon_cal_calendars_index ON tachyon_cal_calendars (id_user);

CREATE TABLE IF NOT EXISTS tachyon_cal_events (
	id_event     INTEGER NOT NULL PRIMARY KEY,
	id_calendar  INTEGER NOT NULL,
	id_user      INTEGER NOT NULL,
	uid          TEXT NOT NULL DEFAULT '',
	summary      TEXT NOT NULL DEFAULT '',
	description  TEXT NOT NULL DEFAULT '',
	location     TEXT NOT NULL DEFAULT '',
	dtstart      INTEGER NOT NULL DEFAULT 0,
	dtend        INTEGER NOT NULL DEFAULT 0,
	all_day      INTEGER NOT NULL DEFAULT 0,
	rrule        TEXT NOT NULL DEFAULT '',
	recur_until  INTEGER DEFAULT NULL,
	timezone     TEXT NOT NULL DEFAULT '',
	ical         TEXT NOT NULL DEFAULT '',
	dav_path     TEXT NOT NULL DEFAULT '',
	changed      INTEGER NOT NULL DEFAULT 0,
	deleted      INTEGER NOT NULL DEFAULT 0,
	etag         TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS id_user_tachyon_cal_events_index ON tachyon_cal_events (id_user);
CREATE INDEX IF NOT EXISTS id_calendar_tachyon_cal_events_index ON tachyon_cal_events (id_calendar);
CREATE INDEX IF NOT EXISTS range_tachyon_cal_events_index ON tachyon_cal_events (id_calendar, dtstart, dtend);
CREATE INDEX IF NOT EXISTS recur_tachyon_cal_events_index ON tachyon_cal_events (id_calendar, recur_until);

SQLITEINITIAL;
	}

	/**
	 * Version 1 is the CREATE above. Later versions are incremental ALTERs,
	 * applied by \Tachyon\Pdo\Base::dataBaseUpgrade().
	 */
	public static function getForDbType(string $sDbType) : array
	{
		$aVersions = [];
		switch ($sDbType)
		{
			// Version 2 widens what version 1 got wrong. A LOCATION longer than
			// 255 characters, or a date past 2038 in a 4 byte timestamp, made the
			// write fail and took the whole sync down with it. Legacy calendars
			// encode "repeats forever" as 99991231T235959, so this is ordinary
			// data rather than a corner case. SQLite types are dynamic and need
			// nothing, but it keeps a version 2 so the numbering matches.
			case 'mysql':
				$aVersions = [
					1 => [],
					2 => [
						'ALTER TABLE tachyon_cal_events MODIFY location text NOT NULL;',
						'ALTER TABLE tachyon_cal_events MODIFY dtstart bigint NOT NULL DEFAULT 0;',
						'ALTER TABLE tachyon_cal_events MODIFY dtend bigint NOT NULL DEFAULT 0;',
						'ALTER TABLE tachyon_cal_events MODIFY recur_until bigint DEFAULT NULL;'
					]
				];
				break;

			case 'pgsql':
				$aVersions = [
					1 => [],
					2 => [
						'ALTER TABLE tachyon_cal_events ALTER COLUMN location TYPE text;',
						'ALTER TABLE tachyon_cal_events ALTER COLUMN dtstart TYPE bigint;',
						'ALTER TABLE tachyon_cal_events ALTER COLUMN dtend TYPE bigint;',
						'ALTER TABLE tachyon_cal_events ALTER COLUMN recur_until TYPE bigint;'
					]
				];
				break;

			case 'sqlite':
				$aVersions = [
					1 => [],
					2 => []
				];
				break;
		}

		// Version 1 is the CREATE above, split into statements the same way the
		// address book schema does it. Without this the tables are never created.
		if ($aVersions) {
			foreach (\explode(';', \trim(static::{$sDbType}())) as $sQuery) {
				$sQuery = \trim($sQuery);
				if (\strlen($sQuery)) {
					$aVersions[1][] = $sQuery;
				}
			}
		}

		return $aVersions;
	}
}
