(rl => { if (rl) {

	class BackupAdminSettings
	{
		constructor()
		{
			this.error = ko.observable('');
		}

		backup()
		{
			this.error('');
			// Direct URL download — avoids async user-gesture expiry and base64 memory overhead
			const a = document.createElement('a');
			a.href = '?/admin/Backup';
			document.body.append(a);
			a.click();
			a.remove();
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
