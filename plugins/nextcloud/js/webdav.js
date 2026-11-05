(rl => {

// Where the last folder saved into is remembered, per browser.
const LAST_FOLDER_KEY = 'tachyon-nextcloud-last-folder';

const
	nsDAV = 'DAV:',
	nsNC = 'http://nextcloud.org/ns',
	nsOC = 'http://owncloud.org/ns',
	nsOCS = 'http://open-collaboration-services.org/ns',
	nsCalDAV = 'urn:ietf:params:xml:ns:caldav',

	OC = () => parent.OC,

	// Nextcloud 19 deprecated generateUrl, but screw `import { generateUrl } from "@nextcloud/router"`
	shareUrl = () => OC().webroot + '/ocs/v2.php/apps/files_sharing/api/v1/shares',
	generateUrl = path => OC().webroot + (OC().config.modRewriteWorking ? '' : '/index.php') + path,
	generateRemoteUrl = path => location.protocol + '//' + location.host + generateUrl(path),

//	shareTypes = {0 = user, 1 = group, 3 = public link}

	propfindFiles = `<?xml version="1.0"?>
<propfind xmlns="DAV:" xmlns:oc="${nsOC}" xmlns:ocs="${nsOCS}" xmlns:nc="${nsNC}">
	<prop>
		<oc:fileid/>
		<oc:size/>
		<resourcetype/>
		<getcontentlength/>
		<ocs:share-permissions/>
		<oc:share-types/>
		<nc:contained-folder-count/>
		<nc:contained-file-count/>
	</prop>
</propfind>`,

	propfindCal = `<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:" xmlns:x1="http://apple.com/ns/ical/">
	<d:prop>
		<d:resourcetype/>
		<d:current-user-privilege-set/>
		<d:displayname/>
		<x1:calendar-color/>
	</d:prop>
</d:propfind>`,

	xmlParser = new DOMParser(),
	pathRegex = /.*\/remote.php\/dav\/[^/]+\/[^/]+/g,

	getElementsByTagName = (parent, namespace, localName) => parent.getElementsByTagNameNS(namespace, localName),
	getDavElementsByTagName = (parent, localName) => getElementsByTagName(parent, nsDAV, localName),
	getDavElementByTagName = (parent, localName) => getDavElementsByTagName(parent, localName)?.item(0),
	getElementByTagName = (parent, localName) => +parent.getElementsByTagName(localName)?.item(0),

	ncFetch = (path, options) => {
		if (!OC().requestToken) {
			return Promise.reject(new Error('OC.requestToken missing'));
		}
		options = Object.assign({
			mode: 'same-origin',
			cache: 'no-cache',
			redirect: 'error',
			credentials: 'same-origin',
			headers: {}
		}, options);
		options.headers.requesttoken = OC().requestToken;
		return fetch(path, options);
	},

	davFetch = (mode, path, options) => {
		let cfg = rl.settings.get('Nextcloud');
//		cfg.UID = document.head.dataset.user
		return ncFetch(cfg.WebDAV + '/' + mode + '/' + cfg.UID + path, options);
	},

	davFetchFiles = (path, options) => davFetch('files', path, options),

	createDirectory = path => davFetchFiles(path, { method: 'MKCOL' }),

	fetchFiles = path => {
		if (!OC().requestToken) {
			return Promise.reject(new Error('OC.requestToken missing'));
		}
		return davFetchFiles(path, {
			method: 'PROPFIND',
			headers: {
				'Content-Type': 'application/xml; charset=utf-8'
			},
			body: propfindFiles
		})
		.then(response => (response.status < 400) ? response.text() : Promise.reject(new Error({ response })))
		.then(text => {
			const
				elemList = [],
				responseList = getDavElementsByTagName(
					xmlParser.parseFromString(text, 'application/xml').documentElement,
					'response'
				);
			path = path.replace(/\/$/, '');
			for (let i = 0; i < responseList.length; ++i) {
				const
					e = responseList.item(i),
					elem = {
						name: decodeURIComponent(getDavElementByTagName(e, 'href').textContent
							.replace(pathRegex, '').replace(/\/$/, '')),
						isFile: false
					};
				if (getDavElementsByTagName(getDavElementByTagName(e, 'resourcetype'), 'collection').length) {
					// skip current directory
					if (elem.name === path) {
						continue;
					}
					// What is inside, so a folder with nothing to open shows nothing to
					// open it with. Nextcloud before 28 does not answer these, and
					// undefined then means "unknown", which keeps the arrow (#117).
					const count = name => {
						const node = e.getElementsByTagNameNS(nsNC, name)?.item(0);
						return node && '' !== node.textContent ? +node.textContent : undefined;
					};
					elem.folders = count('contained-folder-count');
					elem.files = count('contained-file-count');
				} else {
					elem.isFile = true;
					elem.id = e.getElementsByTagNameNS(nsOC, 'fileid')?.item(0)?.textContent;
					elem.size = getDavElementByTagName(e, 'getcontentlength')?.textContent
						|| getElementByTagName(e, 'oc:size')?.textContent;
					elem.shared = [...e.getElementsByTagNameNS(nsOC, 'share-type')].some(node => '3' == node.textContent);
				}
				elemList.push(elem);
			}
			// https://github.com/the-djmaze/snappymail/issues/1177
//			elemList.sort((a, b) => a.isFile != b.isFile ? (a.isFile ? 1 : -1) : a.name.localeCompare(b.name));
			return Promise.resolve(elemList);
		});
	},

	// A plus on the row it creates into, so where a new folder will appear is not
	// in question. The pair of fields at the end of every open level could not say
	// that, and closed the dialog the moment it was used (#117).
	createButton = (view, path) => {
		let btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'button-vue';
		btn.textContent = '+';
		btn.title = rl.i18n('NEXTCLOUD/CREATE_SUBFOLDER');
		btn.addEventListener('click', event => {
			// The row this sits on is a summary, which would otherwise fold shut
			event.stopPropagation();
			event.preventDefault();
			view.createFolderIn(path, btn);
		});
		return btn;
	},

	buildTree = (view, parent, items) => {
		if (items.length) {
			try {
				// https://github.com/the-djmaze/snappymail/issues/1109
				let collator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});
				items.sort((a, b) => collator.compare(a.name, b.name));
			} catch (e) {
				console.error(e);
			}
			items.forEach(item => {
				if (!item.isFile) {
					let li = document.createElement('li'),
						details = document.createElement('details'),
						summary = document.createElement('summary'),
						ul = document.createElement('ul');
					// Kept on the element so revealFolder() can load a level before
					// looking inside it. It has to be callable, not just awaitable:
					// the toggle event does not fire until after details.open is set,
					// so revealFolder() reached an empty list and stopped, which left
					// a remembered subfolder unticked while a top level one worked
					// (#117).
					details.load = () => details.loaded = details.loaded
						|| (ul.children.length
							? Promise.resolve()
							: fetchFiles(item.name).then(items => {
								buildTree(view, ul, items);
								// Older servers give no counts, so learn it on opening
								ul.children.length || details.classList.add('nc-leaf');
							}));
					details.addEventListener('toggle', () => details.load());
					// Our own arrow rather than the native marker: the Nextcloud theme
					// lays the summary out as a flex row, which drops the marker, and
					// a folder with nothing inside should have no arrow at all (#117).
					const twisty = document.createElement('span');
					twisty.className = 'nc-twisty';
					summary.append(twisty);
					if (0 === item.folders && (!view.files() || 0 === item.files)) {
						details.classList.add('nc-leaf');
					}
					summary.addEventListener('click', event => {
						details.classList.contains('nc-leaf') && event.preventDefault();
					});
					summary.dataset.icon = 'folder';
					if (view.files()) {
						summary.append(item.name.replace(/^.*\/([^/]+)$/, '$1'));
					} else {
						// One radio for the whole tree, so choosing a folder deeper in
						// unchooses the one above without any bookkeeping here.
						let radio = document.createElement('input'),
							name = document.createElement('span');
						radio.type = 'radio';
						radio.name = 'nc-folder';
						radio.item_name = item.name;
						// A summary toggles its details on click, so without this
						// picking a folder would also open or close it.
						radio.addEventListener('click', event => event.stopPropagation());
						name.textContent = item.name.replace(/^.*\/([^/]+)$/, '$1');
						summary.append(radio);
						summary.append(name);
						summary.append(createButton(view, item.name));
					}
					details.append(summary);
					details.append(ul);
//					a.append('- ' + item.name.replace(/^\/+/, ''));
					li.append(details);
					parent.append(li);
				}
			});
			if (view.files()) {
				items.forEach(item => {
					if (item.isFile) {
						let li = document.createElement('li'),
							label = document.createElement('label'),
							cb = document.createElement('input');

						li.item = item;
						li.dataset.icon = 'file';

						cb.type = 'checkbox';
						// In a label, so the name is a hit target too rather than the
						// box being the only thing that can be clicked.
						label.append(cb);
						label.append(item.name.replace(/^.*\/([^/]+)$/, '$1'));
						li.append(label);

						parent.append(li);
					}
				});
			}
		}
	};

class NextcloudFilesPopupView extends rl.pluginPopupView {
	constructor() {
		super('NextcloudFiles');
		this.addObservables({
			files: false,
			// The folder the radio chose, so the footer can say whether there is
			// one yet rather than closing with nothing.
			folder: ''
		});
	}

	attach() {
		this.select = [];
		this.tree.querySelectorAll('input[type="checkbox"]').forEach(input =>
			input.checked && this.select.push(input.closest('li').item)
		);
		this.close();
	}

	shareInternal() {
		this.select = [];
		this.tree.querySelectorAll('input[type="checkbox"]').forEach(input =>
			input.checked && this.select.push({url:generateRemoteUrl(`/f/${input.closest('li').item.id}`)})
		);
		this.close();
	}

	sharePublic() {
		const inputs = [...this.tree.querySelectorAll('input[type="checkbox"]')],
			loop = () => {
				if (!inputs.length) {
					this.close();
					return;
				}
				const input = inputs.pop();
				if (!input.checked) {
					loop();
				} else {
					const item = input.closest('li').item;
					if (item.shared) {
						ncFetch(
							shareUrl() + `?format=json&path=${encodeURIComponent(item.name)}&reshares=true`
						)
						.then(response => (response.status < 400) ? response.json() : Promise.reject(new Error({ response })))
						.then(json => {
							this.select.push({url:json.ocs.data[0].url});
							loop();
//							json.data[0].password
						});
					} else {
						ncFetch(
							shareUrl(),
							{
								method:'POST',
								headers: {
									Accept: 'application/json',
									'Content-Type': 'application/json'
								},
								body: JSON.stringify({
									path:item.name,
									shareType:3,
									attributes:"[]"
								})
							}
						)
						.then(response => (response.status < 400) ? response.json() : Promise.reject(new Error({ response })))
						.then(json => {
//							PUT /ocs/v2.php/apps/files_sharing/api/v1/shares/2 {"password":"ABC09"}
							this.select.push({url:json.ocs.data.url});
							loop();
						});
					}
				}
			};

		this.select = [];
		loop();
	}

	onBuild(dom) {
		this.tree = dom.querySelector('#sm-nc-files-tree');
		this.tree.addEventListener('change', event => {
			event.target.matches('input[type="radio"]') && this.folder(event.target.item_name);
		});
	}

	/**
	 * Asks for a name for a new folder inside sPath, in a row where the folder
	 * itself will appear, so it is clear which level is being added to. Creating
	 * one leaves the dialog open with the new folder ticked, rather than closing
	 * on the spot the way the old create button did (#117).
	 */
	createFolderIn(sPath, oButton) {
		const bRoot = '/' === sPath,
			oDetails = bRoot ? null : oButton.closest('details'),
			oList = bRoot ? this.tree : oDetails?.querySelector(':scope > ul');
		if (!oList) {
			return;
		}
		if (oDetails) {
			// Opening it loads the level, so the new folder arrives in a list that
			// already shows what is there
			oDetails.open = true;
			oDetails.load?.();
		}

		let oRow = oList.querySelector(':scope > li.nc-create');
		if (oRow) {
			oRow.querySelector('input').focus();
			return;
		}

		oRow = document.createElement('li');
		oRow.className = 'nc-create';
		// The + that opened this row does nothing more while it is open, and a
		// second one under the row read as a button that did not work (#117)
		oButton.hidden = true;
		const fClose = () => {
			oRow.remove();
			oButton.hidden = false;
		};
		const oInput = document.createElement('input'),
			oCreate = document.createElement('button'),
			oCancel = document.createElement('button'),
			fCreate = () => {
				// Whatever reaches this becomes part of a DAV url
				const sName = oInput.value.replace(/[|\\?*<":>+\[\]\/&]/g, '').replace(/^\.+/, '').trim();
				if (!sName.length) {
					oInput.focus();
					return;
				}
				const sFull = (bRoot ? '' : sPath) + '/' + sName;
				oCreate.disabled = true;
				// Wait for the level's own listing: one still in flight would arrive
				// afterwards and add a second row for the same folder
				Promise.resolve(oDetails?.loaded).then(() => createDirectory(sFull)).then(response => {
					fClose();
					if (201 === response.status) {
						oDetails?.classList.remove('nc-leaf');
						buildTree(this, oList, [{name: sFull, isFile: false}]);
						const oRadio = [...oList.querySelectorAll(':scope > li > details > summary > input[type="radio"]')]
							.find(oInp => oInp.item_name === sFull);
						if (oRadio) {
							oRadio.checked = true;
							this.folder(sFull);
							oRadio.scrollIntoView({block: 'nearest'});
						}
					}
				}).catch(fClose);
			};

		oInput.placeholder = rl.i18n('NEXTCLOUD/FOLDER_NAME');
		oInput.addEventListener('keydown', event => {
			if ('Enter' === event.key) {
				event.preventDefault();
				fCreate();
			} else if ('Escape' === event.key) {
				// Only the row: left to bubble, it closed the whole dialog too
				event.preventDefault();
				event.stopPropagation();
				fClose();
			}
		});
		oCreate.type = 'button';
		oCreate.className = 'button-vue';
		oCreate.textContent = rl.i18n('NEXTCLOUD/FOLDER_CREATE');
		oCreate.addEventListener('click', fCreate);
		oCancel.type = 'button';
		oCancel.className = 'button-vue';
		oCancel.textContent = rl.i18n('GLOBAL/CANCEL');
		oCancel.addEventListener('click', fClose);
		oRow.append(oInput, oCreate, oCancel);
		oList.prepend(oRow);
		oInput.focus();
	}

	/** Folder mode: take the folder the radio chose. */
	selectFolder() {
		this.select = this.folder();
		// Remembered so the next save opens on the same folder rather than at the
		// root. It is only ever pre-selected, never used without being shown, so
		// there is nothing here a user can switch on and then not find again.
		try {
			this.select
				? localStorage.setItem(LAST_FOLDER_KEY, this.select)
				: localStorage.removeItem(LAST_FOLDER_KEY);
		} catch (e) {
			// private mode, quota, a browser that refuses: not worth failing a save
		}
		this.close();
	}

	/**
	 * Ticks the remembered folder, opening each level on the way down to it.
	 * Anything unexpected, a folder since deleted or renamed included, simply
	 * leaves the dialog as it would have been.
	 */
	async revealFolder(sPath) {
		if ('/' === sPath) {
			const oRadio = this.tree.querySelector('.nc-create-root input[type="radio"]');
			if (oRadio) {
				oRadio.checked = true;
				this.folder('/');
			}
			return;
		}
		let oScope = this.tree,
			sAcc = '';
		for (const sPart of sPath.split('/').filter(Boolean)) {
			sAcc += '/' + sPart;
			const oRadio = [...oScope.children]
				.map(li => li.querySelector(':scope > details > summary > input[type="radio"]'))
				.find(r => r && r.item_name === sAcc);
			if (!oRadio) {
				return;
			}
			if (sAcc === sPath) {
				oRadio.checked = true;
				this.folder(sAcc);
				oRadio.scrollIntoView({block: 'nearest'});
				return;
			}
			const oDetails = oRadio.closest('details');
			oDetails.open = true;
			await oDetails.load();
			oScope = oDetails.querySelector('ul');
			if (!oScope) {
				return;
			}
		}
	}

	// Happens after showModal()
	beforeShow(files, fResolve) {
		this.select = '';
		this.folder('');
		this.files(!!files);
		this.fResolve = fResolve;

		this.tree.innerHTML = '';
		fetchFiles('/').then(items => {
			buildTree(this, this.tree, items);
			if (!this.files()) {
				// Without one at the top there is no way to make a folder at the root,
				// and without its own radio no way back to it once a subfolder has
				// been remembered (#117)
				const oRootRow = document.createElement('li'),
					oTwisty = document.createElement('span'),
					oRadio = document.createElement('input'),
					oName = document.createElement('span');
				oRootRow.className = 'nc-create-root';
				// An arrow that never shows, so the root lines up with the folders
				oTwisty.className = 'nc-twisty';
				oRadio.type = 'radio';
				oRadio.name = 'nc-folder';
				oRadio.item_name = '/';
				oName.textContent = rl.i18n('NEXTCLOUD/ROOT_FOLDER');
				oRootRow.dataset.icon = 'folder';
				oRootRow.append(oTwisty, oRadio, oName, createButton(this, '/'));
				this.tree.prepend(oRootRow);
			}
			if (!this.files()) {
				let sLast = '';
				try {
					sLast = localStorage.getItem(LAST_FOLDER_KEY) || '';
				} catch (e) {
					// no storage, no memory, no harm
				}
				sLast = sLast || rl.settings.get('Nextcloud')?.SaveFolder || '';
				sLast && this.revealFolder(sLast).catch(err => console.error(err));
			}
		}).catch(err => console.error(err))
	}

	onHide() {
		this.fResolve(this.select);
	}
/*
beforeShow() {} // Happens before showModal()
onShow() {}     // Happens after  showModal()
afterShow() {}  // Happens after  showModal() animation transitionend
onHide() {}     // Happens before animation transitionend
afterHide() {}  // Happens after  animation transitionend
close() {}
*/
}

class NextcloudCalendarsPopupView extends rl.pluginPopupView {
	constructor() {
		super('NextcloudCalendars');
	}

	onBuild(dom) {
		this.tree = dom.querySelector('#sm-nc-calendars');
		this.tree.addEventListener('click', event => {
			let el = event.target;
			if (el.matches('button')) {
				this.select = el.href;
				this.close();
			}
		});
	}
	createCalendarListItem(calendarData, treeElement) {
		const {
			displayName,
			href,
			calendarColor
		} = calendarData;

		const li = document.createElement('li');
		li.style.display = 'flex';

		const span = document.createElement('span');
		span.setAttribute('role', 'img');
		span.className = 'material-design-icon checkbox-blank-circle-icon';
		span.style.fill = calendarColor;
		span.style.width = '20px';
		span.style.height = '20px';

		const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('width', '20');
		svg.setAttribute('height', '20');
		svg.setAttribute('viewBox', '0 0 24 24');

		const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
		path.setAttribute('d', 'M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2Z');
		svg.appendChild(path);

		span.appendChild(svg);

		const button = document.createElement('button');
		button.className = 'button-vue';
		button.style.backgroundColor = 'transparent';
		button.style.border = '0';
		button.style.fontSize = 'large';
		button.style.padding = '0';
		button.style.cursor = 'pointer';
		button.style.marginLeft = '5px';
		button.href = href.replace(pathRegex, '').replace(/\/$/, '');
		button.textContent = displayName;
		button.style.color = '#1968DF';

		li.appendChild(span);
		li.appendChild(button);

		treeElement.appendChild(li);
	}
	// Happens after showModal()
	beforeShow(fResolve) {
		this.select = '';
		this.fResolve = fResolve;
		this.tree.innerHTML = '';
		davFetch('calendars', '/', {
			method: 'PROPFIND',
			headers: {
				'Content-Type': 'application/xml; charset=utf-8'
			},
			body: propfindCal
		})
		.then(response => (response.status < 400) ? response.text() : Promise.reject(new Error({ response })))
		.then(text => {
			// Parse the XML text
			const xmlDoc = xmlParser.parseFromString(text, 'application/xml').documentElement;
			const responseList = getElementsInNamespaces(xmlDoc, 'response');
			for (let i = 0; i < responseList.length; ++i) {
				const e = responseList[i];
				if (getDavElementByTagName(e, 'resourcetype').getElementsByTagNameNS(nsCalDAV, 'calendar').length) {
					const displayNameElement = getElementsInNamespaces(e, 'displayname')[0];
					const displayName = displayNameElement ? displayNameElement.textContent.trim() : '';

					const hrefElement = getElementsInNamespaces(e, 'href')[0];
					const href = hrefElement ? hrefElement.textContent.trim() : '';

					const calendarColorElement = getElementsInNamespaces(e, 'calendar-color')[0];
					const calendarColor = calendarColorElement ? calendarColorElement.textContent.trim() : '#000000';

					// Create an object to hold calendar data
					const calendarData = {
						displayName,
						href,
						calendarColor
					};

					// Call the function to create and append the list item
					this.createCalendarListItem(calendarData, this.tree);
				}
			}
		})
		.catch(err => console.error(err));
	}

	onHide() {
		this.fResolve(this.select);
	}
/*
beforeShow() {} // Happens before showModal()
onShow() {}     // Happens after  showModal()
afterShow() {}  // Happens after  showModal() animation transitionend
onHide() {}     // Happens before animation transitionend
afterHide() {}  // Happens after  animation transitionend
close() {}
*/
}

rl.nextcloud = {
	selectCalendar: () =>
		new Promise(resolve => {
			NextcloudCalendarsPopupView.showModal([
				href => resolve(href),
			]);
		}),

	calendarPut: (path, event) => {
		davFetch('calendars', path + '/' + event.UID + '.ics', {
			method: 'PUT',
			headers: {
				'Content-Type': 'text/calendar'
			},
			// Validation error in iCalendar: A calendar object on a CalDAV server MUST NOT have a METHOD property.
			body: event.rawText
				.replace('METHOD:', 'X-METHOD:')
				// https://github.com/nextcloud/calendar/issues/4684
				.replace('ATTENDEE:', 'X-ATTENDEE:')
				.replace('ORGANIZER:', 'X-ORGANIZER:')
				.replace(/RSVP=TRUE/g, 'RSVP=FALSE')
				.replace(/\r?\n/g, '\r\n')
		})
		.then(response => {
			if (201 == response.status) {
				// Created
			} else if (204 == response.status) {
				// Not modified
			} else {
//				response.text().then(text => console.error({status:response.status, body:text}));
				Promise.reject(new Error({ response }));
			}
		});
	},

	selectFolder: () =>
		new Promise(resolve => {
			NextcloudFilesPopupView.showModal([
				false,
				folder => resolve(folder),
			]);
		}),

	selectFiles: () =>
		new Promise(resolve => {
			NextcloudFilesPopupView.showModal([
				true,
				files => resolve(files),
			]);
		}),

	// Where to save: the folder from the settings when the user said not to ask,
	// otherwise the dialog (#117)
	saveFolder: () => {
		const cfg = rl.settings.get('Nextcloud') || {};
		return (false === cfg.SaveAsk && cfg.SaveFolder)
			? Promise.resolve(cfg.SaveFolder)
			: rl.nextcloud.selectFolder();
	}
};

class NextcloudSettingsTab {
	constructor() {
		const cfg = rl.settings.get('Nextcloud') || {};
		this.folder = ko.observable(cfg.SaveFolder || '/Attachments');
		this.ask = ko.observable(false !== cfg.SaveAsk);
		this.saved = ko.observable(false).extend({ falseTimeout: 3000 });
		this.error = ko.observable(false);
		this.folderName = ko.computed(() => '/' === this.folder() ? rl.i18n('NEXTCLOUD/ROOT_FOLDER') : this.folder());
		this.ask.subscribe(() => this.save());
	}

	choose() {
		rl.nextcloud.selectFolder().then(folder => {
			if (folder) {
				this.folder(folder);
				this.save();
			}
		});
	}

	save() {
		this.error(false);
		rl.pluginRemoteRequest((iError, data) => {
			const result = !iError && data?.Result;
			if (result) {
				const cfg = rl.settings.get('Nextcloud');
				cfg.SaveFolder = result.save_folder;
				cfg.SaveAsk = result.save_ask;
				this.folder(result.save_folder);
				this.saved(true);
			} else {
				this.error(true);
			}
		}, 'NextcloudSettings', {folder: this.folder(), ask: this.ask() ? 1 : 0});
	}
}

rl.addSettingsViewModel(NextcloudSettingsTab, 'STabNextcloud', 'Nextcloud', 'nextcloud');

})(window.rl);

function getElementsInNamespaces(xmlDocument, tagName) {
	const namespaces = {
		d: 'DAV:',
		x1: 'http://apple.com/ns/ical/',
	};
	const results = [];
	for (const prefix in namespaces) {
		const namespaceURI = namespaces[prefix];
		const elements = xmlDocument.getElementsByTagNameNS(namespaceURI, tagName);
		for (const element of elements) {
			results.push(element);
		}
	}
	return results;
}
