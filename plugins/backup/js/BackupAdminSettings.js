(rl => { if (rl) {

	class BackupAdminSettings
	{
		constructor()
		{
			this.error = ko.observable('');
		}

		submitForm(form)
		{
			this.error('');
			form.reportValidity()
			&& rl.pluginRemoteRequest((iError, oData) => {
				if (iError || !oData?.Result) {
					this.error('Restore failed' + (oData?.message ? ': ' + oData.message : ''));
				}
			}, 'JsonAdminRestoreData', new FormData(form));
		}
	}

	rl.addSettingsViewModelForAdmin(BackupAdminSettings, 'BackupAdminSettingsTab',
		'Backup and Restore', 'Backup');

}})(window.rl);
