import ko from 'ko';
import { koComputable } from 'External/ko';

import { SettingsGet } from 'Common/Globals';
import { i18n, translateTrigger, getErrorMessage } from 'Common/Translator';
import { ContactUserStore } from 'Stores/User/Contact';
import Remote from 'Remote/User/Fetch';

export class UserSettingsContacts /*extends AbstractViewSettings*/ {
	constructor() {
		this.contactsAutosave = ko.observable(!!SettingsGet('ContactsAutosave'));
		this.contactsHideNoEmail = ko.observable(!!SettingsGet('ContactsHideNoEmail'));

		this.allowContactsSync = ContactUserStore.allowSync;
		this.syncMode = ContactUserStore.syncMode;
		this.syncUrl = ContactUserStore.syncUrl;
		this.syncUser = ContactUserStore.syncUser;
		this.syncPass = ContactUserStore.syncPass;
		this.syncError = ko.observable('');
		this.syncSuccess = ko.observable(false);
		this.testing = ko.observable(false);

		this.syncModeOptions = koComputable(() => {
			translateTrigger();
			return [
				{ id: 0, name: i18n('GLOBAL/NO') },
				{ id: 1, name: i18n('GLOBAL/YES') },
				{ id: 2, name: i18n('SETTINGS_CONTACTS/SYNC_READ') },
			];
		});

		this.saveTrigger = koComputable(() =>
				[
					ContactUserStore.syncMode(),
					ContactUserStore.syncUrl(),
					ContactUserStore.syncUser(),
					ContactUserStore.syncPass()
				].join('|')
			)
			.extend({ debounce: 500 });

		this.contactsAutosave.subscribe(value =>
			Remote.saveSettings(null, { ContactsAutosave: value })
		);

		this.contactsHideNoEmail.subscribe(value =>
			Remote.saveSettings(null, { ContactsHideNoEmail: value })
		);

		this.saveTrigger.subscribe(() =>
			Remote.request('SaveContactsSyncData', null, {
				Mode: ContactUserStore.syncMode(),
				Url: ContactUserStore.syncUrl(),
				User: ContactUserStore.syncUser(),
				Password: ContactUserStore.syncPass()
			})
		);
	}

	test() {
		this.syncError('');
		this.syncSuccess(false);
		this.testing(true);
		// Reporting only failures made a working connection look like a dead button
		Remote.request('TestContactsSyncData', (iError, data) => {
			this.testing(false);
			iError
				? this.syncError(data?.messageAdditional || data?.message || getErrorMessage(iError, data))
				: this.syncSuccess(true);
		}, {
			Url: ContactUserStore.syncUrl(),
			User: ContactUserStore.syncUser(),
			Password: ContactUserStore.syncPass()
		})
	}
}
