import ko from 'ko';

import { addObservablesTo, addSubscribablesTo, addComputablesTo } from 'External/ko';

import { SaveSettingStatus } from 'Common/Enums';
import { SettingsAdmin, SettingsGet, SettingsCapa } from 'Common/Globals';
import { translatorReload, convertLangName } from 'Common/Translator';

import { AbstractViewSettings } from 'Knoin/AbstractViews';
import { showScreenPopup } from 'Knoin/Knoin';

import Remote from 'Remote/Admin/Fetch';

import { ThemeStore, convertThemeName, changeTheme } from 'Stores/Theme';
import { LanguageStore } from 'Stores/Language';
import { LanguagesPopupView } from 'View/Popup/Languages';

export class AdminSettingsGeneral extends AbstractViewSettings {
	constructor() {
		super();

		this.language = LanguageStore.language;
		this.languageAdmin = ko.observable(SettingsAdmin('language'));

		this.theme = ThemeStore.theme;
		this.themes = ThemeStore.themes;

		this.addSettings(['allowLanguagesOnSettings']);

		addObservablesTo(this, {
			capaThemes: SettingsCapa('Themes'),
			capaUserBackground: SettingsCapa('UserBackground'),
			capaAdditionalAccounts: SettingsCapa('AdditionalAccounts'),
			capaIdentities: SettingsCapa('Identities'),
			capaAttachmentThumbnails: SettingsCapa('AttachmentThumbnails'),
			dataFolderAccess: false
		});

		this.weakPassword = rl.app.weakPassword;

		/** https://github.com/RainLoop/rainloop-webmail/issues/1924
		if (this.weakPassword) {
			fetch('./data/VERSION?' + Math.random()).then(response => this.dataFolderAccess(response.ok));
		}
		*/

		this.attachmentLimit = ko
			.observable(SettingsGet('attachmentLimit') / (1024 * 1024))
			.extend({ debounce: 500 });

		/**
		 * Not addSetting(), because these two are also editable as raw keys on
		 * the Config tab and so have to be re-read when this tab is shown. Its
		 * subscription saves unconditionally and its callback cannot veto that,
		 * so refreshing through it would write the value straight back.
		 */
		this.refreshing = false;
		this.messagesPerPage = this.perPageSetting('messagesPerPage', 20);
		this.messagesPerPageMax = this.perPageSetting('messagesPerPageMax', 100);

		this.addSetting('language');
		this.addSetting('attachmentLimit');

		this.addSetting('Theme', value => changeTheme(value, this.themeTrigger));

		this.uploadData = SettingsGet('phpUploadSizes');
		this.uploadDataDesc =
			(this.uploadData?.upload_max_filesize || this.uploadData?.post_max_size)
				? [
						this.uploadData.upload_max_filesize
							? 'upload_max_filesize = ' + this.uploadData.upload_max_filesize + '; '
							: '',
						this.uploadData.post_max_size ? 'post_max_size = ' + this.uploadData.post_max_size : ''
				  ].join('')
				: '';

		addComputablesTo(this, {
			themesOptions: () => this.themes.map(theme => ({ optValue: theme, optText: convertThemeName(theme) })),

			languageFullName: () => convertLangName(this.language()),
			languageAdminFullName: () => convertLangName(this.languageAdmin())
		});

		this.languageAdminTrigger = ko.observable(SaveSettingStatus.Idle).extend({ debounce: 100 });

		const fReloadLanguageHelper = (saveSettingsStep) => () => {
				this.languageAdminTrigger(saveSettingsStep);
				setTimeout(() => this.languageAdminTrigger(SaveSettingStatus.Idle), 1000);
			},
			fSaveHelper = key => value => Remote.saveSetting(key, value);

		addSubscribablesTo(this, {
			languageAdmin: value => {
				this.languageAdminTrigger(SaveSettingStatus.Saving);
				translatorReload(value, 1)
					.then(fReloadLanguageHelper(SaveSettingStatus.Success), fReloadLanguageHelper(SaveSettingStatus.Failed))
					.then(() => Remote.saveSetting('languageAdmin', value));
			},

			capaAdditionalAccounts: fSaveHelper('CapaAdditionalAccounts'),

			capaIdentities: fSaveHelper('CapaIdentities'),

			capaAttachmentThumbnails: fSaveHelper('CapaAttachmentThumbnails'),

			capaThemes: fSaveHelper('CapaThemes'),

			capaUserBackground: fSaveHelper('CapaUserBackground')
		});
	}

	/**
	 * An admin setting that saves on change, except while being refreshed.
	 */
	perPageSetting(sName, iFallback) {
		const trigger = ko.observable(SaveSettingStatus.Idle),
			value = ko.observable(SettingsGet(sName) || iFallback).extend({ debounce: 500 });
		this[sName + 'Trigger'] = trigger;
		value.subscribe(v => {
			if (this.refreshing) {
				return;
			}
			trigger(SaveSettingStatus.Saving);
			Remote.saveSetting(sName, v, iError => {
				trigger(iError ? SaveSettingStatus.Failed : SaveSettingStatus.Success);
				setTimeout(() => trigger(SaveSettingStatus.Idle), 1000);
			});
		});
		return value;
	}

	/**
	 * The Config tab reloads itself every time it is shown; this one did not, so
	 * a value changed over there still showed its page-load copy here, and
	 * saving from here would have put the stale number back.
	 */
	beforeShow() {
		Remote.request('AdminSettingsGet', (iError, data) => {
			const webmail = data?.Result?.webmail;
			if (iError || !webmail) {
				return;
			}
			this.refreshing = true;
			this.messagesPerPage(webmail.messages_per_page?.[0] ?? this.messagesPerPage());
			this.messagesPerPageMax(webmail.messages_per_page_max?.[0] ?? this.messagesPerPageMax());
			// The observables debounce, so the guard has to outlast that window
			setTimeout(() => this.refreshing = false, 800);
		});
	}

	selectLanguage() {
		showScreenPopup(LanguagesPopupView, [
			this.language,
			LanguageStore.languages,
			LanguageStore.userLanguage()
		]);
	}

	selectLanguageAdmin() {
		showScreenPopup(LanguagesPopupView, [
			this.languageAdmin,
			SettingsAdmin('languages'),
			SettingsAdmin('clientLanguage')
		]);
	}
}
