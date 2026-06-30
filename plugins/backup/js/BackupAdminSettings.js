(rl => { if (rl) {

	class BackupAdminSettings
	{
		constructor()
		{
			this.loading = ko.observable(false);
			this.error = ko.observable('');
		}

		backup()
		{
			this.error('');
			this.loading(true);
			rl.pluginRemoteRequest((iError, oData) => {

				this.loading(false);

				if (iError || !oData?.Result?.data) {
					this.error((oData?.message) || 'Backup failed (error ' + iError + '). Check server logs.');
					return;
				}

				try {
					const b64 = oData.Result.data.split(',')[1];
					const binary = atob(b64);
					const bytes = new Uint8Array(binary.length);
					for (let i = 0; i < binary.length; i++) {
						bytes[i] = binary.charCodeAt(i);
					}
					const blob = new Blob([bytes], {type: 'application/zip'});
					const url = URL.createObjectURL(blob);
					const link = document.createElement('a');
					link.download = oData.Result.name || 'tachyon-backup.zip';
					link.href = url;
					document.body.append(link);
					link.click();
					link.remove();
					setTimeout(() => URL.revokeObjectURL(url), 1000);
				} catch (e) {
					this.error('Download failed: ' + e.message);
				}

			}, 'JsonAdminBackupData');
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
