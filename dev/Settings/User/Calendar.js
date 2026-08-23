import ko from 'ko';
import { koComputable } from 'External/ko';

import { SettingsGet } from 'Common/Globals';
import { i18n, translateTrigger, getErrorMessage } from 'Common/Translator';
import Remote from 'Remote/User/Fetch';

export class UserSettingsCalendar /*extends AbstractViewSettings*/ {
	constructor() {
		const config = SettingsGet('CalendarSync') || {};

		this.allowCalendarSync = ko.observable(!!SettingsGet('CalendarSync'));
		this.syncMode = ko.observable(config.Mode || 0);
		this.syncUrl = ko.observable(config.Url || '');
		this.syncUser = ko.observable(config.User || '');
		this.syncPass = ko.observable(config.Password || '');
		this.syncError = ko.observable('');

		this.syncModeOptions = koComputable(() => {
			translateTrigger();
			return [
				{ id: 0, name: i18n('GLOBAL/NO') },
				{ id: 1, name: i18n('GLOBAL/YES') },
				{ id: 2, name: i18n('SETTINGS_CALENDAR/SYNC_READ') },
			];
		});

		this.saveTrigger = koComputable(() =>
				[
					this.syncMode(),
					this.syncUrl(),
					this.syncUser(),
					this.syncPass()
				].join('|')
			)
			.extend({ debounce: 500 });

		this.saveTrigger.subscribe(() =>
			Remote.request('SaveCalendarSyncData', null, {
				Mode: this.syncMode(),
				Url: this.syncUrl(),
				User: this.syncUser(),
				Password: this.syncPass()
			})
		);
	}

	test() {
		this.syncError('');
		Remote.request('TestCalendarSyncData', (iError, data) => {
			iError && this.syncError(data.messageAdditional || data.message || getErrorMessage(iError, data));
		}, {
			Mode: this.syncMode(),
			Url: this.syncUrl(),
			User: this.syncUser(),
			Password: this.syncPass()
		})
	}
}
