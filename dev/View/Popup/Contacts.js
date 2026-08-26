import { addObservablesTo, addComputablesTo } from 'External/ko';
import { ComposeType } from 'Common/EnumsUser';
import { registerShortcut, SettingsGet, elementById } from 'Common/Globals';
import { arrayLength, pInt } from 'Common/Utils';
import { download, computedPaginatorHelper, showMessageComposer } from 'Common/UtilsUser';

import { Selector } from 'Common/Selector';
import { serverRequestRaw, serverRequest } from 'Common/Links';
import { i18n, getNotification } from 'Common/Translator';

import { SettingsUserStore } from 'Stores/User/Settings';
import { ContactUserStore } from 'Stores/User/Contact';

import Remote from 'Remote/User/Fetch';

import { EmailModel } from 'Model/Email';
import { ContactModel } from 'Model/Contact';

import { decorateKoCommands } from 'Knoin/Knoin';
import { AbstractViewPopup } from 'Knoin/AbstractViews';

import { AskPopupView } from 'View/Popup/Ask';

const
	CONTACTS_PER_PAGE = 50,
	ScopeContacts = 'Contacts';

let
	bOpenCompose = false,
	sComposeRecipientsField = '';

export class ContactsPopupView extends AbstractViewPopup {
	constructor() {
		super('Contacts');

		addObservablesTo(this, {
			search: '',
			categoryFilter: '',
			contactsCount: 0,

			selectorContact: null,

			importButton: null,

			contactsPage: 1,

			isSaving: false,

			contact: null,

			// Text typed into the tag box but not yet committed to a chip
			newCategory: ''
		});

		this.availableCategories = ko.observableArray();

		/**
		 * Checked state cannot live on the contact objects, because the store is
		 * replaced wholesale on every page load and paging away would drop it.
		 * The uids are the selection; the details are kept alongside so a message
		 * can be composed to contacts whose page is no longer loaded.
		 */
		this.checkedUids = ko.observableArray();
		this.checkedDetails = new Map();
		this.allSelected = ko.observable(false);

		this.contacts = ContactUserStore;

		this.useCheckboxesInList = SettingsUserStore.useCheckboxesInList;

		this.selector = new Selector(
			ContactUserStore,
			this.selectorContact,
			null,
			'.e-contact-item',
			'.e-contact-item .checkboxItem'
		);

		this.selector.on('ItemSelect', contact => this.populateViewContact(contact));

		this.selector.on('ItemGetUid', contact => contact ? contact.id() : '');

		addComputablesTo(this, {
			// Offering a tag the contact already carries would be a no-op, since
			// adding it is refused, so drop those from the list
			categorySuggestions: () => {
				const taken = new Set(
					(this.contact()?.categories() || []).map(c => c.value().trim().toLowerCase())
				);
				return this.availableCategories().filter(cat => !taken.has(cat.trim().toLowerCase()));
			},

			contactsPaginator: computedPaginatorHelper(
				this.contactsPage,
				() => Math.max(1, Math.ceil(this.contactsCount() / CONTACTS_PER_PAGE))
			),

			contactsCheckedOrSelected: () => {
				const checked = ContactUserStore.filter(item => item.checked()),
					selected = this.selectorContact();
				return checked.length ? checked : (selected ? [selected] : []);
			},

			// Counts the whole selection, not just the part currently on screen
			selectionCount: () => this.checkedUids().length || (this.selectorContact() ? 1 : 0),

			hasSelection: () => 0 < this.selectionCount(),

			selectPageLabel: () => i18n('CONTACTS/SELECT_PAGE') + ' (' + ContactUserStore().length + ')',

			selectAllLabel: () => i18n('CONTACTS/SELECT_ALL_MATCHING') + ' (' + this.contactsCount() + ')',

			selectionLabel: () => this.allSelected()
				? i18n('CONTACTS/SELECTED_ALL', { COUNT: this.selectionCount() })
				: i18n('CONTACTS/SELECTED_SOME', { COUNT: this.selectionCount() }),

			pageAllChecked: () => {
				const page = ContactUserStore();
				return page.length && page.every(contact => contact.checked());
			},

			contactsSyncEnabled: () => ContactUserStore.allowSync() && ContactUserStore.syncMode(),

			isBusy: () => ContactUserStore.syncing() | ContactUserStore.importing() | ContactUserStore.loading()
				| this.isSaving()
		});

		this.search.subscribe(() => { this.clearSelection(); this.reloadContactList(); });
		this.categoryFilter.subscribe(() => { this.clearSelection(); this.reloadContactList(true); });

		this.saveCommand = this.saveCommand.bind(this);

		decorateKoCommands(this, {
			deleteCommand: self => !self.isBusy() && self.hasSelection(),
			newMessageCommand: self => !self.isBusy() && self.hasSelection(),
			composeToCommand: self => !self.isBusy() && self.hasSelection(),
			composeCcCommand: self => !self.isBusy() && self.hasSelection(),
			composeBccCommand: self => !self.isBusy() && self.hasSelection(),
			selectAllCommand: self => !self.isBusy() && 0 < self.contactsCount(),
			saveCommand: self => !self.isBusy(),
			syncCommand: self => !self.isBusy(),
			clearLocalCommand: self => !self.isBusy()
		});
	}

	newContact() {
		this.populateViewContact(new ContactModel);
		this.selectorContact(null);
	}

	/**
	 * @returns {Array} uids of the whole selection, which may reach past the
	 * page on screen, falling back to the highlighted contact
	 */
	selectedUids() {
		const uids = this.checkedUids();
		if (uids.length) {
			return uids.slice();
		}
		const selected = this.selectorContact();
		return selected ? [selected.id()] : [];
	}

	deleteCommand() {
		const uids = this.selectedUids();
		if (uids.length) {
			let selectorContact = this.selectorContact(),
				count = uids.length;
			if (selectorContact && uids.includes(selectorContact.id())) {
				this.selectorContact(selectorContact = null);
			}
			ContactUserStore.forEach(contact =>
				uids.includes(contact.id()) && contact.deleted(true)
			);
			Remote.request('ContactsDelete',
				(iError, oData) => {
					if (iError) {
						alert(oData?.message || getNotification(iError));
					} else {
						const page = this.contactsPage();
						if (page > Math.max(1, Math.ceil((this.contactsCount() - count) / CONTACTS_PER_PAGE))) {
							this.contactsPage(page - 1);
						}
//						contacts.forEach(contact => ContactUserStore.remove(contact));
					}
					this.clearSelection();
					this.reloadContactList();
				}, {
					uids: uids.join(',')
				}
			);
		}
	}

	/**
	 * Deletes only contacts the CardDAV server has never seen. Synced ones are left
	 * alone on purpose: removing them here would delete them from the server on the
	 * next read-write sync.
	 */
	clearLocalCommand() {
		AskPopupView.showModal([
			i18n('CONTACTS/CONFIRM_DELETE_LOCAL'),
			() => Remote.request('ContactsClear',
				(iError, oData) => {
					iError && alert(oData?.message || getNotification(iError));
					this.contactsPage(1);
					this.reloadContactList(true);
				}, {
					scope: 'local'
				}
			)
		]);
	}

	composeLimit() {
		return Math.max(1, pInt(SettingsGet('contactsComposeLimit')) || 100);
	}

	bccRecommendLimit() {
		return Math.max(1, pInt(SettingsGet('contactsBccLimit')) || 20);
	}

	newMessageCommand() {
		this.composeWithField(sComposeRecipientsField || 'to');
	}

	composeToCommand() {
		this.composeWithField('to');
	}

	composeCcCommand() {
		this.composeWithField('cc');
	}

	composeBccCommand() {
		this.composeWithField('bcc');
	}

	composeWithField(field) {
		const uids = this.selectedUids(),
			limit = this.composeLimit();

		if (limit < uids.length) {
			alert(i18n('CONTACTS/ERROR_COMPOSE_LIMIT', { LIMIT: limit, COUNT: uids.length }));
			return;
		}

		/**
		 * Putting a crowd in To hands every address to every recipient, so past a
		 * threshold offer Bcc instead. A recommendation rather than a rule,
		 * because a large To is legitimate for a team.
		 */
		if ('to' === field && this.bccRecommendLimit() < uids.length) {
			AskPopupView.showModal([
				i18n('CONTACTS/ASK_USE_BCC', { COUNT: uids.length }),
				() => this.resolveAndCompose(uids, 'bcc'),
				() => this.resolveAndCompose(uids, 'to')
			]);
			return;
		}

		this.resolveAndCompose(uids, field);
	}

	resolveAndCompose(uids, field) {
		const limit = this.composeLimit();

		// Details are kept for contacts checked by hand, but Select all only ever
		// had uids, so those have to be fetched before a message can be addressed
		const missing = uids.filter(uid => !this.checkedDetails.has(uid));
		if (missing.length) {
			Remote.request('Contacts',
				(iError, data) => {
					if (iError) {
						alert(data?.message || getNotification(iError));
						return;
					}
					(data.Result?.List || []).forEach(item => {
						const contact = ContactModel.reviveFromJson(item);
						contact && uids.includes(contact.id()) && this.checkedDetails.set(contact.id(), {
							name: (contact.givenName() + ' ' + contact.surName()).trim(),
							addresses: contact.email().map(address => address.value()),
							sendToAll: contact.sendToAll()
						});
					});
					this.composeToSelection(uids, field);
				}, {
					Offset: 0,
					Limit: limit,
					Search: this.search(),
					Category: this.categoryFilter()
				}
			);
		} else {
			this.composeToSelection(uids, field);
		}
	}

	composeToSelection(uids, field) {
		let aE = [],
			skipped = 0,
			recipients = {to:null,cc:null,bcc:null};

		uids.forEach(uid => {
			const details = this.checkedDetails.get(uid),
				before = aE.length;
			if (details) {
				let email,
					addresses = details.sendToAll ? details.addresses : details.addresses.slice(0, 1);
				addresses.forEach(address => {
					email = new EmailModel(address, details.name);
					email.valid() && aE.push(email);
				});
/*
		//		oContact.jCard.getOne('fn')?.notEmpty() ||
				oContact.jCard.parseFullName({set:true});
		//		let name = oContact.jCard.getOne('nickname'),
				let name = oContact.jCard.getOne('fn'),
					email = [oContact.jCard.getOne('email')];
*/
			}
			// Nothing usable: no details, no address, or an address that did not
			// parse. Counted per contact, since that is the number the user picked.
			aE.length === before && ++skipped;
		});

		if (arrayLength(aE)) {
			// Silently addressing fewer contacts than were selected is how a
			// large send quietly misses people
			skipped && alert(i18n('CONTACTS/COMPOSE_SKIPPED', {
				USED: uids.length - skipped,
				TOTAL: uids.length
			}));
			bOpenCompose = false;
			this.close();
			recipients[field] = aE;
			showMessageComposer([ComposeType.Empty, null, recipients.to, recipients.cc, recipients.bcc])
		} else {
			alert(i18n('CONTACTS/COMPOSE_NONE_USABLE', { TOTAL: uids.length }));
		}
	}

	clearSearch() {
		this.search('');
		this.categoryFilter('');
	}

	addCategoryFromInput() {
		const contact = this.contact();
		// Silent when it is a blank or a duplicate: the chip that would have been
		// created is already sitting there, so there is nothing to explain
		contact?.addCategoryValue(this.newCategory());
		this.newCategory('');
	}

	onCategoryKeydown(event) {
		if ('Enter' === event.key) {
			this.addCategoryFromInput();
			return false;
		}
		// Knockout swallows the event unless the handler returns true, which
		// would stop the box accepting any text at all
		return true;
	}

	focusCategoryInput() {
		setTimeout(() => elementById('contact-tag-input')?.focus(), 100);
	}

	loadCategories() {
		Remote.request('ContactsCategories', (iError, data) => {
			if (!iError && data?.Result?.List) {
				this.availableCategories(data.Result.List);
			}
		});
	}

	saveCommand() {
		this.saveContact(this.contact());
	}

	saveContact(contact) {
		const data = contact.toJSON();
		if (data.jCard != JSON.stringify(contact.jCard)) {
			this.isSaving(true);
			Remote.request('ContactSave',
				(iError, oData) => {
					if (iError) {
						alert(oData?.message || getNotification(iError));
					} else if (oData.Result.ResultID) {
						if (contact.id()) {
							contact.id(oData.Result.ResultID);
							contact.jCard = JSON.parse(data.jCard);
						} else {
							this.reloadContactList(); // TODO: remove when e-contact-foreach is dynamic
						}
						// A group invented here should be suggested on the next
						// contact, not only after the popup is reopened
						this.loadCategories();
					}
					this.isSaving(false);
				}, data
			);
		}
	}

	syncCommand() {
		ContactUserStore.sync(iError => {
			iError && alert(getNotification(iError));
			this.reloadContactList(true);
		});
	}

	exportVcf() {
		download(serverRequestRaw('ContactsVcf'), 'contacts.vcf');
	}

	exportCsv() {
		download(serverRequestRaw('ContactsCsv'), 'contacts.csv');
	}

	/**
	 * @param {?ContactModel} contact
	 */
	populateViewContact(contact) {
		const oldContact = this.contact(),
			// Half typed tag belongs to the contact being left, not the next one
			fn = () => { this.newCategory(''); this.contact(contact); };
		if (oldContact?.hasChanges()) {
			AskPopupView.showModal([
				i18n('GLOBAL/SAVE_CHANGES'),
				() => this.saveContact(oldContact) | fn(),
				fn
			]);
		} else fn();
	}

	/**
	 * @param {boolean=} dropPagePosition = false
	 */
	/**
	 * Reinstates checked state on a page that has just been fetched, and keeps
	 * following it, since these are new objects each time.
	 */
	applySelectionTo(list) {
		const uids = this.checkedUids();
		list.forEach(contact => {
			const uid = contact.id();
			contact.checked(uids.includes(uid));
			contact.checked.subscribe(value => this.setChecked(contact, value));
		});
	}

	setChecked(contact, checked) {
		const uid = contact.id();
		if (checked) {
			this.checkedUids.includes(uid) || this.checkedUids.push(uid);
			this.checkedDetails.set(uid, {
				name: (contact.givenName() + ' ' + contact.surName()).trim(),
				addresses: contact.email().map(address => address.value()),
				sendToAll: contact.sendToAll()
			});
		} else {
			this.checkedUids.remove(uid);
			this.checkedDetails.delete(uid);
			this.allSelected(false);
		}
	}

	selectPage() {
		ContactUserStore.forEach(contact => contact.checked(true));
	}

	/**
	 * Everything the current search and category match, not just this page, so
	 * the count has to come from the server.
	 */
	selectAllCommand() {
		Remote.request('ContactsUids',
			(iError, data) => {
				if (iError) {
					alert(data?.message || getNotification(iError));
					return;
				}
				this.checkedUids(data.Result?.Uids || []);
				this.allSelected(true);
				ContactUserStore.forEach(contact => contact.checked(true));
			}, {
				Search: this.search(),
				Category: this.categoryFilter()
			}
		);
	}

	clearSelection() {
		this.checkedUids([]);
		this.checkedDetails.clear();
		this.allSelected(false);
		ContactUserStore.forEach(contact => contact.checked(false));
	}

	reloadContactList(dropPagePosition = false) {
		let offset = (this.contactsPage() - 1) * CONTACTS_PER_PAGE;

		if (dropPagePosition) {
			this.contactsPage(1);
			offset = 0;
		}

		ContactUserStore.loading(true);
		Remote.abort('Contacts').request('Contacts',
			(iError, data) => {
				let count = 0,
					list = [];

				if (iError) {
//					console.error(data);
					alert(data?.message || getNotification(iError));
				} else if (arrayLength(data.Result.List)) {
					data.Result.List.forEach(item => {
						item = ContactModel.reviveFromJson(item);
						item && list.push(item);
					});
					count = pInt(data.Result.Count);
				}

				this.contactsCount(0 < count ? count : 0);

				this.applySelectionTo(list);

				ContactUserStore(list);

				ContactUserStore.loading(false);
			},
			{
				Offset: offset,
				Limit: CONTACTS_PER_PAGE,
				Search: this.search(),
				Category: this.categoryFilter()
			}
		);
	}

	onBuild(dom) {
		this.selector.init(dom.querySelector('.b-list-content'), ScopeContacts);

		registerShortcut('delete', '', ScopeContacts, () => {
			this.deleteCommand();
			return false;
		});

		registerShortcut('c,w', '', ScopeContacts, () => {
			this.newMessageCommand();
			return false;
		});

		const self = this;

		dom.addEventListener('click', event => {
			let el = event.target.closestWithin('.e-paginator a', dom);
			if (el && (el = pInt(ko.dataFor(el)?.value))) {
				self.contactsPage(el);
				self.reloadContactList();
			}
		});

		// initUploader

		if (this.importButton()) {
			const j = new Jua({
				action: serverRequest('UploadContacts'),
				limit: 1,
				clickElement: this.importButton()
			});

			if (j) {
				j.on('onStart', () => {
					ContactUserStore.importing(true);
				}).on('onComplete', (id, result, data) => {
					ContactUserStore.importing(false);
					this.reloadContactList();
					if (!id || !result || !data || !data.Result) {
						alert(i18n('CONTACTS/ERROR_IMPORT_FILE'));
					}
				});
			}
		}
	}

	onClose() {
		const contact = this.contact();
		if (AskPopupView.hidden() && contact?.hasChanges()) {
			AskPopupView.showModal([
				i18n('GLOBAL/SAVE_CHANGES'),
				() => this.close() | this.saveContact(contact),
				() => this.close()
			]);
			return false;
		}
	}

	onShow(bBackToCompose, sRecipientsField) {
		bOpenCompose = !!bBackToCompose;
		sComposeRecipientsField = ['to','cc','bcc'].includes(sRecipientsField) ? sRecipientsField : 'to';
		this.loadCategories();
		this.reloadContactList(true);
	}

	onHide() {
		this.clearSelection();
		this.newCategory('');
		this.contact(null);
		this.selectorContact(null);
		this.search('');
		this.categoryFilter('');
		this.availableCategories([]);
		this.contactsCount(0);

		ContactUserStore([]);

		bOpenCompose && showMessageComposer();
	}
}
