import ko from 'ko';
import { Settings, SettingsGet } from 'Common/Globals';
import { addObservablesTo } from 'External/ko';
import Remote from 'Remote/Admin/Fetch';

import { i18n, translateTrigger } from 'Common/Translator';

export class AdminSettingsAbout /*extends AbstractViewSettings*/ {
	constructor() {
		this.version = Settings.app('version');
		this.phpextensions = ko.observableArray();
		// Why the update cannot run. It was already being sent and never shown,
		// which left an admin with a missing button and no way to find out why.
		this.coreWarnings = ko.observableArray();

		this.allowUpdate = ko.observable(!!SettingsGet('allowUpdate'));
		this.allowUpdate.subscribe(value => {
			Remote.saveSetting('allowUpdate', value);
			// updatable is decided server side and this is one of its inputs, so
			// the button appears on the answer rather than on the click
			this.checkVersion();
		});

		addObservablesTo(this, {
			coreReal: true,
			coreUpdatable: true,
			coreVersion: '',
			coreVersionCompare: -2,
			php64: true,
			load1: 0,
			load5: 0,
			load15: 0,
			errorDesc: ''
		});
		this.coreChecking = ko.observable(false).extend({ throttle: 100 });
		this.coreUpdating = ko.observable(false).extend({ throttle: 100 });

		this.coreVersionHtmlDesc = ko.computed(() => {
			translateTrigger();
			return i18n('TAB_ABOUT/HTML_NEW_VERSION', { 'VERSION': this.coreVersion() });
		});

		this.statusType = ko.computed(() => {
			let type = '';
			const versionToCompare = this.coreVersionCompare(),
				isChecking = this.coreChecking(),
				isUpdating = this.coreUpdating(),
				isReal = this.coreReal();

			if (isChecking) {
				type = 'checking';
			} else if (isUpdating) {
				type = 'updating';
			} else if (!isReal) {
				type = 'error';
				this.errorDesc('Cannot access the repository at the moment.');
			} else if (0 === versionToCompare) {
				type = 'up-to-date';
			} else if (-1 === versionToCompare) {
				type = 'available';
			}

			return type;
		});
	}

	onBuild() {
//	beforeShow() {
		this.checkVersion();
	}

	checkVersion() {
		this.coreChecking(true);
		Remote.request('AdminInfo', (iError, data) => {
			this.coreChecking(false);
			data = data?.Result;
			if (!iError && data) {
				this.load1(data.system.load?.[0]);
				this.load5(data.system.load?.[1]);
				this.load15(data.system.load?.[2]);
				this.phpextensions(data.php);
				this.coreReal(true);
				this.coreUpdatable(!!data.core.updatable);
				this.coreWarnings(data.core.warnings || []);
				this.coreVersion(data.core.version || '');
				this.coreVersionCompare(data.core.versionCompare);
				this.php64(data.php[1].loaded);
			} else {
				this.coreReal(false);
				this.coreWarnings([]);
				this.coreVersion('');
				this.coreVersionCompare(-2);
			}
		});
	}

	clearCache() {
		Remote.request('AdminClearCache');
	}

	updateCoreData() {
		if (!this.coreUpdating()) {
			this.coreUpdating(true);
			Remote.request('AdminUpgradeCore', (iError, data) => {
				this.coreUpdating(false);
				this.coreVersion('');
				this.coreVersionCompare(-2);
				if (!iError && data?.Result) {
					this.coreReal(true);
					window.location.reload();
				} else {
					this.coreReal(false);
				}
			}, {}, 90000);
		}
	}
}
