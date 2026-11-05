(rl => {
//	if (rl.settings.get('Nextcloud'))

	addEventListener('rl-view-model.create', e => {
		if ('MailMessageList' === e.detail.viewModelTemplateID) {
			let view = e.detail;
			// Saves the loaded messages, so not while the whole list is selected (#182)
			view.nextcloudSaveMsgs = () => {
				view.messageList.hasChecked() && !view.messageList.allMatching()
				 && rl.nextcloud.saveFolder().then(folder => {
					folder && view.messageList.forEach(msg => {
						msg.checked() && rl.pluginRemoteRequest(
							(iError, data) => {
								console.dir({
									iError:iError,
									data:data
								});
							},
							'NextcloudSaveMsg',
							{
								'msgHash': msg.requestHash,
								'folder': folder,
								'filename': msg.subject()
							}
						);
					});
				});
			};
		}
	});

	const msgMenu = document.getElementById('MailMessageList')
		.content.querySelector('#more-list-dropdown-id + menu [data-bind*="forwardCommand"]');
	if (msgMenu) {
		msgMenu.after(Element.fromHTML(`<li role="presentation" data-bind="css:{disabled:!messageList.hasChecked() || messageList.allMatching()}">
			<a href="#" tabindex="-1" data-icon="cloud-upload" data-bind="click: nextcloudSaveMsgs" data-i18n="NEXTCLOUD/SAVE_EML"></a>
		</li>`));
	}

})(window.rl);
