import ko from 'ko';

import { SettingsGet } from 'Common/Globals';
import { defaultOptionsAfterRender } from 'Common/Utils';
import { addObservablesTo } from 'External/ko';

import Remote from 'Remote/Admin/Fetch';
import { decorateKoCommands } from 'Knoin/Knoin';
import { AbstractViewSettings } from 'Knoin/AbstractViews';

export class AdminSettingsCalendar extends AbstractViewSettings {
	constructor() {
		super();
		this.defaultOptionsAfterRender = defaultOptionsAfterRender;

		this.addSetting('calendarPdoDsn');
		this.addSetting('calendarPdoUser');
		this.addSetting('calendarPdoPassword');
		this.addSetting('calendarPdoType', () => {
			this.testCalendarSuccess(false);
			this.testCalendarError(false);
			this.testCalendarErrorMessage('');
		});

		this.addSettings(['calendarEnable', 'calendarSync']);

		this.addSetting('calendarSyncInterval');

		this.addSetting('calendarMySQLSSLCA');
		this.addSetting('calendarMySQLSSLVerify');
		this.addSetting('calendarMySQLSSLCiphers');

		this.addSetting('calendarSQLiteGlobal');

		this.addSetting('calendarMaxRangeDays');
		this.addSetting('calendarMaxOccurrences');

		addObservablesTo(this, {
			testing: false,
			testCalendarSuccess: false,
			testCalendarError: false,
			testCalendarErrorMessage: ''
		});

		const supportedTypes = SettingsGet('supportedPdoDrivers') || [],
			types = [{
				id: 'sqlite',
				name: 'SQLite'
			},{
				id: 'mysql',
				name: 'MySQL'
			},{
				id: 'pgsql',
				name: 'PostgreSQL'
			}].filter(type => supportedTypes.includes(type.id));

		this.calendarSupported = 0 < types.length;

		this.calendarTypesOptions = types;

		this.mainCalendarType = ko
			.computed({
				read: this.calendarPdoType,
				write: value => {
					if (value !== this.calendarPdoType()) {
						if (supportedTypes.includes(value)) {
							this.calendarPdoType(value);
						} else if (types.length) {
							this.calendarPdoType('');
						}
					} else {
						this.calendarPdoType.valueHasMutated();
					}
				}
			})
			.extend({ notify: 'always' });

		decorateKoCommands(this, {
			testCalendarCommand: self => self.calendarPdoDsn() && self.calendarPdoUser()
		});
	}

	testCalendarCommand() {
		this.testCalendarSuccess(false);
		this.testCalendarError(false);
		this.testCalendarErrorMessage('');
		this.testing(true);

		Remote.request('AdminCalendarTest',
			(iError, data) => {
				this.testCalendarSuccess(false);
				this.testCalendarError(false);
				this.testCalendarErrorMessage('');

				if (!iError && data.Result.Result) {
					this.testCalendarSuccess(true);
				} else {
					this.testCalendarError(true);
					this.testCalendarErrorMessage(data?.Result?.Message || '');
				}

				this.testing(false);
			}, {
				PdoType: this.calendarPdoType(),
				PdoDsn: this.calendarPdoDsn(),
				PdoUser: this.calendarPdoUser(),
				PdoPassword: this.calendarPdoPassword(),
				MySQLSSLCA: this.calendarMySQLSSLCA(),
				MySQLSSLVerify: this.calendarMySQLSSLVerify(),
				MySQLSSLCiphers: this.calendarMySQLSSLCiphers(),
				SQLiteGlobal: this.calendarSQLiteGlobal()
			}
		);
	}

	onShow() {
		this.testCalendarSuccess(false);
		this.testCalendarError(false);
		this.testCalendarErrorMessage('');
	}
}
