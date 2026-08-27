import { koComputable } from 'External/ko';

import { SettingsCapa } from 'Common/Globals';
import { i18n, translateTrigger, relativeTime, getNotification } from 'Common/Translator';

import { AbstractViewSettings } from 'Knoin/AbstractViews';

import { SettingsUserStore } from 'Stores/User/Settings';

import { GnuPGUserStore } from 'Stores/User/GnuPG';
import { OpenPGPUserStore } from 'Stores/User/OpenPGP';

import { showScreenPopup } from 'Knoin/Knoin';

import { OpenPgpImportPopupView } from 'View/Popup/OpenPgpImport';
import { OpenPgpGeneratePopupView } from 'View/Popup/OpenPgpGenerate';

import Remote from 'Remote/User/Fetch';

import { SMimeUserStore } from 'Stores/User/SMime';
import { SMimeImportPopupView } from 'View/Popup/SMimeImport';

export class UserSettingsSecurity extends AbstractViewSettings {
	constructor() {
		super();

		this.autoLogout = SettingsUserStore.autoLogout;
		this.autoLogoutOptions = koComputable(() => {
			translateTrigger();
			return [
				{ id: 0, name: i18n('SETTINGS_SECURITY/NEVER') },
				{ id: 5, name: relativeTime(300) },
				{ id: 15, name: relativeTime(900) },
				{ id: 30, name: relativeTime(1800) },
				{ id: 60, name: relativeTime(3600) },
				{ id: 120, name: relativeTime(7200) },
				{ id: 300, name: relativeTime(18000) },
				{ id: 600, name: relativeTime(36000) }
			];
		});
		this.addSetting('AutoLogout');

		this.keyPassForget = SettingsUserStore.keyPassForget;
		this.addSetting('keyPassForget');

		this.gnupgPublicKeys = GnuPGUserStore.publicKeys;
		this.gnupgPrivateKeys = GnuPGUserStore.privateKeys;

		this.openpgpkeysPublic = OpenPGPUserStore.publicKeys;
		this.openpgpkeysPrivate = OpenPGPUserStore.privateKeys;

		this.smimeCertificates = SMimeUserStore;

		this.canOpenPGP = SettingsCapa('OpenPGP');
		this.canGnuPG = GnuPGUserStore.isSupported();
		this.canMailvelope = !!window.mailvelope;
	}

	addOpenPgpKey() {
		showScreenPopup(OpenPgpImportPopupView);
	}

	generateOpenPgpKey() {
		showScreenPopup(OpenPgpGeneratePopupView);
	}

	importToOpenPGP() {
		// Not OpenPGPUserStore.loadBackupKeys(): that one is also run at startup
		// and has to stay quiet. Pressing a button and getting nothing back, which
		// is what it did here, is indistinguishable from a broken button.
		const count = () => OpenPGPUserStore.privateKeys().length + OpenPGPUserStore.publicKeys().length,
			before = count();

		Remote.request('GetPGPKeys', async (iError, oData) => {
			if (iError) {
				alert(getNotification(iError, oData?.message));
				return;
			}

			const keys = oData?.Result || [];
			if (!keys.length) {
				alert(i18n('SETTINGS_OPENPGP/IMPORT_NONE_FOUND'));
				return;
			}

			await OpenPGPUserStore.importKeys(keys);
			const added = count() - before;
			alert(added
				? i18n('SETTINGS_OPENPGP/IMPORT_ADDED', { COUNT: added })
				: i18n('SETTINGS_OPENPGP/IMPORT_ALL_KNOWN', { COUNT: keys.length }));
		});
	}

	importToSMime() {
		showScreenPopup(SMimeImportPopupView);
	}

	onBuild() {
		/**
		 * Create an iframe to display the Mailvelope keyring settings.
		 * The iframe will be injected into the container identified by selector.
		 */
		window.mailvelope && mailvelope.createSettingsContainer('#mailvelope-settings'/*[, keyring], options*/);
		/**
		 * https://github.com/the-djmaze/tachyon/issues/973
		Remote.request('GetStoredPGPKeys', (iError, data) => {
			console.dir([iError, data]);
		});
		*/
	}
}
