(rl => {

	const
		cfg = () => rl.settings.get('Plugins')?.['virustotal'] || {},
		i18n = (key, replace) => rl.i18n('VIRUSTOTAL/' + key, replace),
		request = (action, params) => new Promise((resolve, reject) =>
			rl.pluginRemoteRequest(
				(iError, oData) => iError ? reject(iError) : resolve(oData?.Result),
				action,
				params
			)
		),
		errorText = result => {
			if (result && result.error) {
				return i18n('ERROR_' + String(result.error).toUpperCase().replace(/-/g, '_'))
					|| i18n('ERROR_FAILED');
			}
			return '';
		},
		openUrl = url => {
			if (url) {
				window.open(url, '_blank', 'noopener,noreferrer');
			}
		},
		formatStats = stats => {
			if (!stats) {
				return i18n('UNKNOWN');
			}
			return i18n('STATS', {
				MALICIOUS: stats.malicious || 0,
				SUSPICIOUS: stats.suspicious || 0,
				UNDETECTED: stats.undetected || 0
			});
		},
		hexFromBuffer = buffer =>
			Array.from(new Uint8Array(buffer))
				.map(b => b.toString(16).padStart(2, '0'))
				.join(''),
		sha256Blob = async blob => {
			const buf = await blob.arrayBuffer();
			const digest = await crypto.subtle.digest('SHA-256', buf);
			return hexFromBuffer(digest);
		},
		blobToBase64 = blob => new Promise((resolve, reject) => {
			const reader = new FileReader();
			reader.onload = () => {
				const s = String(reader.result || '');
				const i = s.indexOf(',');
				resolve(i >= 0 ? s.slice(i + 1) : s);
			};
			reader.onerror = () => reject(reader.error || new Error('read'));
			reader.readAsDataURL(blob);
		}),
		extractUrls = msg => {
			const text = [msg.html() || '', msg.plain() || ''].join('\n');
			const found = new Set();
			const re = /\bhttps?:\/\/[^\s<>"')\]]+/gi;
			let m;
			while ((m = re.exec(text))) {
				let u = m[0].replace(/[.,;:!?)]+$/, '');
				if (u.length >= 8 && u.length <= 2048) {
					found.add(u);
				}
				if (found.size >= 25) {
					break;
				}
			}
			return [...found];
		};

	const showResult = (view, title, body, guiUrl) => {
		view.vtTitle(title);
		view.vtBody(body);
		view.vtGuiUrl(guiUrl || '');
		view.vtError('');
		view.vtPanel(true);
	};

	const checkAttachment = async (view, attachment, submit) => {
		view.vtBusy(true);
		view.vtError('');
		try {
			const res = await rl.fetch(attachment.linkDownload());
			if (!res.ok) {
				throw new Error(i18n('ERROR_READ_FAILED'));
			}
			const blob = await res.blob();
			const sha256 = await sha256Blob(blob);

			if (submit) {
				if (!cfg().allow_submit) {
					throw new Error(i18n('ERROR_SUBMIT_DISABLED'));
				}
				if (!window.confirm(i18n('CONFIRM_SUBMIT'))) {
					return;
				}
				const content_b64 = await blobToBase64(blob);
				const result = await request('VirusTotalSubmit', {
					sha256,
					content_b64,
					file_name: attachment.fileName || 'attachment.bin'
				});
				const err = errorText(result);
				if (err) {
					showResult(view, attachment.fileName, err, result?.gui_url || '');
					return;
				}
				showResult(
					view,
					attachment.fileName,
					result.submitted
						? i18n('SUBMITTED', { HASH: sha256 })
						: formatStats(result.stats),
					result.gui_url
				);
				return;
			}

			const result = await request('VirusTotalFile', { sha256 });
			const err = errorText(result);
			if (err) {
				showResult(view, attachment.fileName, err, result?.gui_url || '');
				return;
			}
			if (result.mode === 'link' || !result.known) {
				showResult(
					view,
					attachment.fileName,
					result.mode === 'link'
						? i18n('LINK_MODE_HINT')
						: i18n('UNKNOWN'),
					result.gui_url
				);
				if (result.mode === 'link') {
					openUrl(result.gui_url);
				}
				return;
			}
			showResult(view, attachment.fileName, formatStats(result.stats), result.gui_url);
		} catch (e) {
			view.vtError(e.message || i18n('ERROR_FAILED'));
			view.vtPanel(true);
		} finally {
			view.vtBusy(false);
		}
	};

	const checkLinks = async view => {
		const msg = view.message();
		if (!msg) {
			return;
		}
		view.vtBusy(true);
		view.vtError('');
		try {
			const urls = extractUrls(msg);
			if (!urls.length) {
				showResult(view, i18n('LINKS'), i18n('NO_LINKS'), '');
				return;
			}
			const lines = [];
			let firstGui = '';
			for (const url of urls) {
				const result = await request('VirusTotalUrl', { url });
				const err = errorText(result);
				if (err) {
					lines.push(url + ' - ' + err);
					continue;
				}
				firstGui = firstGui || result.gui_url || '';
				if (result.mode === 'link') {
					lines.push(url + ' → ' + i18n('OPEN_GUI'));
					openUrl(result.gui_url);
				} else if (!result.known) {
					lines.push(url + ' → ' + i18n('UNKNOWN'));
				} else {
					lines.push(url + ' → ' + formatStats(result.stats));
				}
			}
			showResult(view, i18n('LINKS'), lines.join('\n'), firstGui);
		} catch (e) {
			view.vtError(e.message || i18n('ERROR_FAILED'));
			view.vtPanel(true);
		} finally {
			view.vtBusy(false);
		}
	};

	rl.addButton('messageView.toolbar', {
		label: 'VIRUSTOTAL/CHECK_LINKS',
		icon: view => view.vtBusy && view.vtBusy() ? 'spinner' : 'lock',
		visible: view => !!view.message && !!view.message(),
		action: view => checkLinks(view)
	});

	addEventListener('rl-view-model.create', e => {
		const view = e.detail;
		if ('MailMessageView' !== view.viewModelTemplateID) {
			return;
		}

		view.vtBusy = ko.observable(false);
		view.vtPanel = ko.observable(false);
		view.vtTitle = ko.observable('');
		view.vtBody = ko.observable('');
		view.vtGuiUrl = ko.observable('');
		view.vtError = ko.observable('');
		view.vtHasKey = !!cfg().has_api_key;
		view.vtAllowSubmit = !!cfg().allow_submit;
		view.vtJoinUrl = cfg().join_url || 'https://www.virustotal.com/gui/join-us';
		view.vtKeyUrl = cfg().key_url || 'https://www.virustotal.com/gui/my-apikey';
		view.vtDocsUrl = cfg().docs_url || 'https://docs.virustotal.com/docs/api-overview';
		view.vtOpenGui = () => openUrl(view.vtGuiUrl());
		view.vtClosePanel = () => view.vtPanel(false);

		view.message.subscribe(() => {
			view.vtPanel(false);
			view.vtError('');
			view.vtBody('');
			view.vtTitle('');
			view.vtGuiUrl('');
		});

		// Attachment context: decorate attachment list clicks after the view builds.
		const decorateAttachments = () => {
			const root = view.viewModelDom || document;
			root.querySelectorAll?.('.attachmentItem, .attachment-item, [data-attachment]').forEach(() => {});
		};
		view.vtCheckAttachment = attachment => checkAttachment(view, attachment, false);
		view.vtSubmitAttachment = attachment => checkAttachment(view, attachment, true);

		// Hook attachment toolbar via event delegation on message view.
		setTimeout(() => {
			const dom = view.viewModelDom;
			if (!dom || dom._vtBound) {
				return;
			}
			dom._vtBound = true;
			dom.addEventListener('click', ev => {
				const btn = ev.target.closest?.('[data-vt-action]');
				if (!btn) {
					return;
				}
				ev.preventDefault();
				ev.stopPropagation();
				const action = btn.getAttribute('data-vt-action');
				const row = btn.closest('.attachmentItem, .attachmentsPlace .attachment, li');
				const attachment = row && ko.dataFor(row);
				if (!attachment || !attachment.linkDownload) {
					return;
				}
				if ('check' === action) {
					checkAttachment(view, attachment, false);
				} else if ('submit' === action && view.vtAllowSubmit) {
					checkAttachment(view, attachment, true);
				}
			});
		}, 0);

		// Inject a small panel and per-attachment buttons when attachments render.
		const injectUi = () => {
			const msg = view.message();
			if (!msg) {
				return;
			}
			const place = view.viewModelDom?.querySelector?.('.attachmentsPlace, .messageAttachments, .attachmentList');
			if (!place || place._vtReady === msg.folder + '/' + msg.uid) {
				return;
			}
			place._vtReady = msg.folder + '/' + msg.uid;

			place.querySelectorAll('.attachmentItem, .attachmentsPlace > div, .attachment').forEach(row => {
				if (row.querySelector('[data-vt-action]')) {
					return;
				}
				const wrap = document.createElement('span');
				wrap.className = 'vt-attach-actions';
				wrap.style.marginLeft = '0.5em';
				const check = document.createElement('button');
				check.type = 'button';
				check.className = 'button-link';
				check.setAttribute('data-vt-action', 'check');
				check.textContent = i18n('CHECK');
				wrap.appendChild(check);
				if (view.vtAllowSubmit) {
					const submit = document.createElement('button');
					submit.type = 'button';
					submit.className = 'button-link';
					submit.setAttribute('data-vt-action', 'submit');
					submit.textContent = i18n('SUBMIT');
					wrap.appendChild(document.createTextNode(' '));
					wrap.appendChild(submit);
				}
				row.appendChild(wrap);
			});

			if (!view.viewModelDom.querySelector('.vt-panel')) {
				const panel = document.createElement('div');
				panel.className = 'vt-panel';
				panel.style.cssText = 'margin:0.75em 0;padding:0.75em;border:1px solid var(--border-color,#444);border-radius:6px;display:none;white-space:pre-wrap;';
				panel.innerHTML = [
					'<div style="display:flex;justify-content:space-between;gap:1em;align-items:center">',
					'<strong class="vt-panel-title"></strong>',
					'<button type="button" class="button-link vt-panel-close">×</button>',
					'</div>',
					'<div class="vt-panel-body" style="margin-top:0.5em"></div>',
					'<div class="vt-panel-actions" style="margin-top:0.5em"></div>',
					'<div class="vt-panel-keyhint" style="margin-top:0.5em;font-size:0.9em;opacity:0.85"></div>'
				].join('');
				const host = view.viewModelDom.querySelector('.messageView, .messageBody, .messagePane') || view.viewModelDom;
				host.insertBefore(panel, host.firstChild);

				const sync = () => {
					panel.style.display = view.vtPanel() ? 'block' : 'none';
					panel.querySelector('.vt-panel-title').textContent = view.vtTitle() || i18n('NAME');
					panel.querySelector('.vt-panel-body').textContent = view.vtError() || view.vtBody() || '';
					const actions = panel.querySelector('.vt-panel-actions');
					actions.innerHTML = '';
					if (view.vtGuiUrl()) {
						const a = document.createElement('a');
						a.href = view.vtGuiUrl();
						a.target = '_blank';
						a.rel = 'noopener noreferrer';
						a.textContent = i18n('OPEN_GUI');
						actions.appendChild(a);
					}
					const hint = panel.querySelector('.vt-panel-keyhint');
					if (!view.vtHasKey) {
						hint.innerHTML = '';
						hint.appendChild(document.createTextNode(i18n('OPTIONAL_KEY') + ' '));
						const a1 = document.createElement('a');
						a1.href = view.vtKeyUrl;
						a1.target = '_blank';
						a1.rel = 'noopener noreferrer';
						a1.textContent = i18n('GET_API_KEY');
						hint.appendChild(a1);
						hint.appendChild(document.createTextNode(' · '));
						const a2 = document.createElement('a');
						a2.href = view.vtJoinUrl;
						a2.target = '_blank';
						a2.rel = 'noopener noreferrer';
						a2.textContent = i18n('CREATE_ACCOUNT');
						hint.appendChild(a2);
						hint.appendChild(document.createTextNode(' · '));
						const a3 = document.createElement('a');
						a3.href = view.vtDocsUrl;
						a3.target = '_blank';
						a3.rel = 'noopener noreferrer';
						a3.textContent = i18n('API_DOCS');
						hint.appendChild(a3);
					} else {
						hint.textContent = '';
					}
				};
				panel.querySelector('.vt-panel-close').addEventListener('click', () => view.vtClosePanel());
				view.vtPanel.subscribe(sync);
				view.vtTitle.subscribe(sync);
				view.vtBody.subscribe(sync);
				view.vtError.subscribe(sync);
				view.vtGuiUrl.subscribe(sync);
			}
		};

		view.message.subscribe(() => setTimeout(injectUi, 50));
		setTimeout(injectUi, 100);
		decorateAttachments();
	});

})(window.rl);
