import ko from 'ko';
import { Settings, SettingsGet } from 'Common/Globals';
import { addObservablesTo } from 'External/ko';
import Remote from 'Remote/Admin/Fetch';

import { i18n, translateTrigger } from 'Common/Translator';
import { FileInfo } from 'Common/File';
import { showScreenPopup } from 'Knoin/Knoin';
import { AskPopupView } from 'View/Popup/Ask';

export class AdminSettingsAbout /*extends AbstractViewSettings*/ {
	constructor() {
		this.version = Settings.app('version');
		// Read at render rather than written into the template, which is how it
		// came to still say 2025 in 2026
		this.year = new Date().getFullYear();
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
		// Dormant application directories from earlier upgrades. Nothing has ever
		// removed these: each release is extracted beside the last one.
		this.versions = ko.observableArray();
		// Sizing walks every file of every version, which on an install with a
		// long upgrade history takes long enough to look like nothing happened
		this.versionsLoading = ko.observable(false).extend({ throttle: 100 });
		this.versionsBusy = ko.observable(false);
		// Deleting runs one directory per request rather than all of them in one.
		// A batch covering several gigabytes outlived the request timeout, and the
		// client then gave up while the server was still working, which left the
		// list showing trees that were already gone.
		this.deleteProgress = ko.observable('');
		this.versionsError = ko.observable('');

		this.selectedVersions = ko.computed(() => this.versions().filter(item => item.selected()));
		this.selectedSize = ko.computed(() =>
			FileInfo.friendlySize(this.selectedVersions().reduce((sum, item) => sum + item.size, 0))
		);

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
		this.loadVersions();
	}

	loadVersions() {
		this.versionsLoading(true);
		Remote.request('AdminVersionsList', (iError, data) => {
			this.versionsLoading(false);
			this.setVersions(iError ? [] : data?.Result?.Versions);
		}, {}, 120000);
	}

	setVersions(list) {
		this.versions((list || []).map(item => ({
			...item,
			sizeText: FileInfo.friendlySize(item.size),
			selected: ko.observable(false)
		})));
	}

	deleteSelected() {
		const chosen = this.selectedVersions();
		if (this.versionsBusy() || !chosen.length) {
			return;
		}
		showScreenPopup(AskPopupView, [
			i18n('TAB_ABOUT/CONFIRM_DELETE_VERSIONS', { 'COUNT': chosen.length }),
			() => this.runDelete(chosen)
		]);
	}

	runDelete(chosen) {
		this.versionsBusy(true);
		this.versionsError('');
		this.deleteOne(chosen.slice(), 0, chosen.length, []);
	}

	deleteOne(queue, done, total, errors) {
		const item = queue.shift();
		if (!item) {
			this.deleteProgress('');
			this.versionsBusy(false);
			this.versionsError(errors.join('\n'));
			// Re-read from the filesystem rather than trusting the running tally
			this.loadVersions();
			return;
		}

		this.deleteProgress(i18n('TAB_ABOUT/LABEL_CLEANUP_DELETING', {
			'CURRENT': done + 1,
			'COUNT': total
		}));

		Remote.request('AdminVersionsDelete', (iError, data) => {
			const result = data?.Result;
			if (iError || !result) {
				errors.push(item.product + ' ' + item.version + ': '
					+ i18n('TAB_ABOUT/ERROR_DELETE_VERSIONS'));
			} else {
				// A tree the web server cannot remove is the ordinary failure, and
				// the admin needs the reason rather than a count that came up short
				errors.push(...(result.Errors || []));
			}
			this.deleteOne(queue, done + 1, total, errors);
		}, {
			Versions: [{ product: item.product, version: item.version }]
		}, 300000);
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
