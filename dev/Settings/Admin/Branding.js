import { AbstractViewSettings } from 'Knoin/AbstractViews';
import { SettingsGet } from 'Common/Globals';
import { addObservablesTo, addComputablesTo } from 'External/ko';
import Remote from 'Remote/Admin/Fetch';

export class AdminSettingsBranding extends AbstractViewSettings {
	constructor() {
		super();
		this.addSetting('title');
		this.addSetting('loadingDescription');
		this.addSetting('faviconUrl');

		addObservablesTo(this, {
			logoFile: SettingsGet('logoFile') || '',
			logoUploading: false,
			logoError: ''
		});

		addComputablesTo(this, {
			logoUrl: () => this.logoFile() ? ('?/Logo&_=' + encodeURIComponent(this.logoFile())) : ''
		});
	}

	uploadLogo(vm, event) {
		const file = event.target.files[0];
		if (!file) return;
		event.target.value = '';
		this.logoError('');
		this.logoUploading(true);
		const fd = new FormData();
		fd.append('logo', file);
		Remote.request('AdminUploadLogo', (iError, data) => {
			this.logoUploading(false);
			if (iError || !data?.Result) {
				this.logoError('Upload failed. Only PNG, JPG, GIF, SVG and WebP are accepted.');
			} else {
				this.logoFile(data.Result);
			}
		}, fd);
	}

	deleteLogo() {
		this.logoError('');
		Remote.request('AdminDeleteLogo', (iError) => {
			if (!iError) {
				this.logoFile('');
			}
		});
	}
}
