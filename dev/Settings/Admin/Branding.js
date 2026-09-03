import { AbstractViewSettings } from 'Knoin/AbstractViews';
import { SettingsGet } from 'Common/Globals';
import { addObservablesTo, addComputablesTo, koComputable } from 'External/ko';
import { i18n, translateTrigger } from 'Common/Translator';
import Remote from 'Remote/Admin/Fetch';

export class AdminSettingsBranding extends AbstractViewSettings {
	constructor() {
		super();
		this.addSetting('title');
		this.addSetting('loadingDescription');
		this.addSetting('faviconUrl');
		this.addSetting('loginLogoMode');

		addObservablesTo(this, {
			logoFile: SettingsGet('logoFile') || '',
			logoFileDark: SettingsGet('logoFileDark') || '',
			logoUploading: false,
			logoError: ''
		});

		addComputablesTo(this, {
			logoUrl: () => this.logoFile() ? ('?/Logo/' + encodeURIComponent(this.logoFile())) : '',
			logoDarkUrl: () => this.logoFileDark() ? ('?/Logo/' + encodeURIComponent(this.logoFileDark())) : '',
			// The upload rows are only worth showing when the uploads are what
			// the login page is actually going to use
			logoCustom: () => 'custom' === this.loginLogoMode()
		});

		this.logoModeOptions = koComputable(() => {
			translateTrigger();
			return [
				{ id: 'default', name: i18n('TAB_BRANDING/LOGO_MODE_DEFAULT') },
				{ id: 'custom', name: i18n('TAB_BRANDING/LOGO_MODE_CUSTOM') },
				{ id: 'none', name: i18n('TAB_BRANDING/LOGO_MODE_NONE') }
			];
		});
	}

	/**
	 * @param {string} variant 'light' or 'dark', naming the background the
	 *                         artwork is meant to sit on, not its own colour
	 */
	upload(variant, event) {
		const file = event.target.files[0];
		if (!file) return;
		event.target.value = '';
		this.logoError('');
		this.logoUploading(true);
		const fd = new FormData();
		fd.append('logo', file);
		fd.append('variant', variant);
		Remote.request('AdminUploadLogo', (iError, data) => {
			this.logoUploading(false);
			if (iError || !data?.Result) {
				this.logoError(i18n('TAB_BRANDING/ERROR_LOGO_UPLOAD'));
			} else {
				('dark' === variant ? this.logoFileDark : this.logoFile)(data.Result);
			}
		}, fd);
	}

	remove(variant) {
		this.logoError('');
		Remote.request('AdminDeleteLogo', iError => {
			iError || ('dark' === variant ? this.logoFileDark : this.logoFile)('');
		}, { variant: variant });
	}

	uploadLogo(vm, event) { this.upload('light', event); }
	uploadLogoDark(vm, event) { this.upload('dark', event); }
	deleteLogo() { this.remove('light'); }
	deleteLogoDark() { this.remove('dark'); }
}
