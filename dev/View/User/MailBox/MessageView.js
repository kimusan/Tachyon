import ko from 'ko';
import { addObservablesTo, addComputablesTo, addSubscribablesTo } from 'External/ko';

import { UNUSED_OPTION_VALUE } from 'Common/Consts';
import { ScopeFolderList, ScopeMessageList, ScopeMessageView } from 'Common/Enums';

import {
	ComposeType,
	ClientSideKeyNameMessageHeaderFullInfo,
	ClientSideKeyNameMessageAttachmentControls,
	FolderType,
	MessageSetAction
} from 'Common/EnumsUser';
import { RFC822 } from 'Common/File';
import { encodeHtml } from 'Common/Html';
import {
	elementById,
	keyScopeReal,
	Settings,
	SettingsCapa,
	fireEvent,
	stopEvent,
	addShortcut,
	registerShortcut,
	formFieldFocused
} from 'Common/Globals';

import { arrayLength } from 'Common/Utils';
import { download, downloadZip, mailToHelper, showMessageComposer, moveAction,
	isStandalone, isRawLink, openRawInNewContext } from 'Common/UtilsUser';
import { isFullscreen, exitFullscreen, toggleFullscreen } from 'Common/Fullscreen';

import { SMAudio } from 'Common/Audio';

import { i18n, getNotification } from 'Common/Translator';

import { AppUserStore } from 'Stores/User/App';
import { SettingsUserStore } from 'Stores/User/Settings';
import { AccountUserStore } from 'Stores/User/Account';
import { FolderUserStore, isAllowedKeyword } from 'Stores/User/Folder';
import { MessageUserStore } from 'Stores/User/Message';
import { CalendarUserStore } from 'Stores/User/Calendar';
import { MessagelistUserStore } from 'Stores/User/Messagelist';
import { mailBox } from 'Common/Links';

import * as Local from 'Storage/Client';

import Remote from 'Remote/User/Fetch';

import { decorateKoCommands } from 'Knoin/Knoin';
import { AbstractViewRight } from 'Knoin/AbstractViews';

import { PgpUserStore } from 'Stores/User/Pgp';

import { MimeToMessage } from 'Mime/Utils';

import { ThemeStore } from 'Stores/Theme';
import { horizontalSwipe } from 'Common/Swipe';

import { MessageModel } from 'Model/Message';

import { messageViewMenuItems, messageViewToolbarButtons } from 'Common/Plugins';
import { showScreenPopup } from 'Knoin/Knoin';
import { OpenPgpImportPopupView } from 'View/Popup/OpenPgpImport';
import { GnuPGUserStore } from 'Stores/User/GnuPG';
import { OpenPGPUserStore } from 'Stores/User/OpenPGP';

const
	oMessageScrollerDom = () => elementById('messageItem') || {},

	currentMessage = MessageUserStore.message,

	setAction = action => {
		const message = currentMessage();
		message && MessagelistUserStore.setAction(message.folder, action, [message]);
	},

	fetchRaw = url => rl.fetch(url).then(response => response.ok && response.text()),

	/*
	 * The smime.p7m is the envelope the message arrived in rather than something
	 * somebody attached, so listing it is noise, and before decryption it is the
	 * only thing in the list. Conditional on the message being encrypted, so a
	 * .p7m genuinely attached to ordinary mail is still shown.
	 */
	attachmentListed = item =>
		(SettingsUserStore.listInlineAttachments() || !item.isLinked())
		&& !(currentMessage()?.smimeEncrypted() && (item.mimeType || '').toLowerCase().includes('pkcs7'));

export class MailMessageView extends AbstractViewRight {
	constructor() {
		super();

		const
			/**
			 * @param {Function} fExecute
			 * @param {Function} fCanExecute = true
			 * @returns {Function}
			 */
			createCommand = (fExecute, fCanExecute) => {
				let fResult = () => {
						fCanExecute() && fExecute.call(null);
						return false;
					};
				fResult.canExecute = fCanExecute;
				return fResult;
			},

			createCommandReplyHelper = type =>
				createCommand(() => this.replyOrforward(type), this.canBeRepliedOrForwarded),

			createCommandActionHelper = (folderType, bDelete) =>
				createCommand(() => {
					const message = currentMessage();
					if (message) {
						currentMessage(null);
						rl.app.moveMessagesToFolderType(folderType, message.folder, new Set([message.uid]), bDelete);
					}
				}, this.messageVisible),
			createCommandSetHelper = action =>
				createCommand(() => setAction(action), this.messageVisible),
			createCommandDeleteHelper = () =>
				createCommand(() => {
					/**
					 * When FolderUserStore.trashFolder is set to "Do not use",
					 * flag as \Deleted for removal by later EXPUNGE
					 */
					if (UNUSED_OPTION_VALUE === FolderUserStore.trashFolder()) {
						setAction(MessageSetAction.SetDeleted);
					} else {
						const message = currentMessage();
						if (message) {
							currentMessage(null);
							rl.app.moveMessagesToFolderType(FolderType.Trash, message.folder, new Set([message.uid]));
						}
					}
				}, this.messageVisible);

		this.msgDefaultAction = SettingsUserStore.msgDefaultAction;
		this.simpleAttachmentsList = SettingsUserStore.simpleAttachmentsList;

		addObservablesTo(this, {
			showAttachmentControls: !!Local.get(ClientSideKeyNameMessageAttachmentControls),
			downloadAsZipLoading: false,
			showFullInfo: '1' === Local.get(ClientSideKeyNameMessageHeaderFullInfo),
			// bootstrap dropdown
			actionsMenu: null,
			// viewer
			viewFromShort: '',
			dkimData: ['none', '', ''],
			spfData: ['none', '', ''],
			dmarcData: ['none', '', ''],
			nowTracking: false,
			// Adding an invitation to a calendar. The attachment is held so the
			// picker knows what it is about to import once a calendar is chosen.
			calendarImportAttachment: null,
			calendarImportBusy: false,
			calendarImportMessage: '',
			calendarImportFailed: false
		});

		this.calendarImportChoices = ko.observableArray();
		this.capaCalendar = SettingsCapa('Calendar');

		this.moveAction = moveAction;

		const attachmentsActions = Settings.app('attachmentsActions');
		this.attachmentsActions = ko.observableArray(arrayLength(attachmentsActions) ? attachmentsActions : []);

		this.hasCheckedMessages = MessagelistUserStore.hasChecked;
		this.archiveAllowed = MessagelistUserStore.archiveAllowed;
		this.canMarkAsSpam = MessagelistUserStore.canMarkAsSpam;
		this.isDraftFolder = MessagelistUserStore.isDraftFolder;
		this.isSpamFolder = MessagelistUserStore.isSpamFolder;

		this.message = currentMessage;
		// Where a plugin puts its own menu item, see rl.addMessageViewMenuItem()
		this.pluginMenuItems = messageViewMenuItems;
		// And its own toolbar button, see rl.addMessageViewToolbarButton()
		this.pluginToolbarButtons = messageViewToolbarButtons;
		this.messageLoadingThrottle = MessageUserStore.loading;
		this.messageError = MessageUserStore.error;

		this.fullScreenMode = isFullscreen;
		this.toggleFullScreen = toggleFullscreen;

		this.downloadAsZipError = ko.observable(false).extend({ falseTimeout: 7000 });

		this.messageDomFocused = ko.observable(false).extend({ rateLimit: 0 });

		// viewer
		this.viewHash = '';

		addComputablesTo(this, {
			allowAttachmentControls: () => arrayLength(attachmentsActions) && SettingsCapa('AttachmentsActions'),

			downloadAsZipAllowed: () => this.attachmentsActions.includes('zip')
				&& (currentMessage()?.attachments || [])
					.filter(item => item?.checked() && item?.download /*&& !item?.isLinked()*/)
					.length,

			tagsAllowed: () => FolderUserStore.currentFolder()?.tagsAllowed(),

			messageVisible: () => !MessageUserStore.loading() && !!currentMessage(),

			// A keyword is the sender's as soon as a Sieve rule or a shared folder can set
			// one, and only the leading $ namespace is allow-listed, so it is escaped here:
			// an IMAP atom may hold < and >, and this goes into an html: binding.
			tagsToHTML: () => currentMessage()?.flags().map(value =>
					isAllowedKeyword(value)
					? '<span class="focused msgflag-'+encodeHtml(value)+'">'
						+ encodeHtml(i18n('MESSAGE_TAGS/'+value,0,value)) + '</span>'
					: ''
				).join(' '),

			askReadReceipt: () => currentMessage()?.readReceipt
				&& !(MessagelistUserStore.isDraftFolder() || MessagelistUserStore.isSentFolder())
				&& !currentMessage()?.flags().includes('$mdnsent')
				&& !currentMessage()?.flags().includes('\\answered'),

			listAttachments: () => currentMessage()?.attachments().filter(attachmentListed),
//			hasAttachments: () => currentMessage()?.attachments()?.length,
			hasAttachments: () => currentMessage()?.attachments().some(attachmentListed),
//			listInline: () => currentMessage()?.attachments().filter(item => item.isLinked()),
//			hasInline: () => currentMessage()?.attachments().some(item => item.isLinked()),

			canBeRepliedOrForwarded: () => !MessagelistUserStore.isDraftFolder() && this.messageVisible(),

			dkimIconClass: () => {
				switch (this.dkimData()[0]) {
					case 'none':
						return 'icon-ban';
					case 'pass':
						return 'icon-check iconcolor-green';
					default:
						return 'icon-x iconcolor-red';
				}
			},
			dkimTitle:() => {
				const dkim = this.dkimData();
				return dkim[0] ? dkim[2] || 'DKIM: ' + dkim[0] : '';
			},

			spfIconClass: () => {
				switch (this.spfData()[0]) {
					case 'none':
						return 'icon-ban';
					case 'pass':
						return 'icon-check iconcolor-green';
					default:
						return 'icon-x iconcolor-red';
				}
			},
			spfTitle:() => {
				const spf = this.spfData();
				return spf[0] ? spf[2] || 'SPF: ' + spf[0] : '';
			},

			dmarcIconClass: () => {
				switch (this.dmarcData()[0]) {
					case 'none':
						return 'icon-ban';
					case 'pass':
						return 'icon-check iconcolor-green';
					default:
						return 'icon-x iconcolor-red';
				}
			},
			dmarcTitle:() => {
				const dmarc = this.dmarcData();
				return dmarc[0] ? dmarc[2] || 'DMARC: ' + dmarc[0] : '';
			},

			showWhitelistOptions: () => 'match' === SettingsUserStore.viewImages(),

			firstUnsubsribeLink: () => currentMessage()?.unsubsribeLinks()[0] || '',

			pgpSupported: () => currentMessage() && PgpUserStore.isSupported(),

			canBeUndeleted: () => currentMessage()?.isDeleted(),

			messageListOrViewLoading:
				() => MessagelistUserStore.isLoading() | MessageUserStore.loading()
		});

		addSubscribablesTo(this, {
			message: message => {
				if (message) {
					if (this.viewHash !== message.hash) {
						this.scrollMessageToTop();
					}
					this.viewHash = message.hash;
					// TODO: make first param a user setting #683
					this.viewFromShort(message.from.toString(false, true));
					this.dkimData(message.dkim[0] || ['none', '', '']);
					this.spfData(message.spf[0] || ['none', '', '']);
					this.dmarcData(message.dmarc[0] || ['none', '', '']);
					this.nowTracking(false);
				} else {
					MessagelistUserStore.selectedMessage(null);

					this.viewHash = '';

					this.scrollMessageToTop();
				}
			},

			showFullInfo: value => Local.set(ClientSideKeyNameMessageHeaderFullInfo, value ? '1' : '0')
		});

		// commands
		this.replyCommand = createCommandReplyHelper(ComposeType.Reply);
		this.replyAllCommand = createCommandReplyHelper(ComposeType.ReplyAll);
		this.forwardCommand = createCommandReplyHelper(ComposeType.Forward);
		this.forwardAsAttachmentCommand = createCommandReplyHelper(ComposeType.ForwardAsAttachment);
		this.editAsNewCommand = createCommandReplyHelper(ComposeType.EditAsNew);

//		this.deleteCommand = createCommandActionHelper(FolderType.Trash);
		// User setting hideDeleted || immediatelyMoveToTrash ??
		this.deleteCommand = createCommandDeleteHelper();
		// User setting !hideDeleted && !immediatelyMoveToTrash ??
		this.undeleteCommand = createCommandSetHelper(MessageSetAction.UnsetDeleted);
		this.deleteWithoutMoveCommand = createCommandActionHelper(FolderType.Trash, true);
		this.archiveCommand = createCommandActionHelper(FolderType.Archive);
		this.spamCommand = createCommandActionHelper(FolderType.Junk);
		this.notSpamCommand = createCommandActionHelper(FolderType.Inbox);

		decorateKoCommands(this, {
			editCommand: self => self.messageVisible(),
			moveCommand: self => self.messageVisible(),
			copyCommand: self => self.messageVisible(),
			goUpCommand: self => !self.messageListOrViewLoading(),
			goDownCommand: self => !self.messageListOrViewLoading()
		});
	}

	toggleFullInfo() {
		this.showFullInfo(!this.showFullInfo());
	}

	closeMessage() {
		currentMessage(null);
		// The address bar still named the message that just closed, so a reload
		// or a shared link would have reopened it. Replace rather than push: the
		// entry this is undoing was pushed when the message opened, so Back still
		// goes back to wherever the reader came from.
		const folder = FolderUserStore.currentFolder();
		folder && hasher.replaceHash(mailBox(
			folder.fullNameHash,
			MessagelistUserStore.page(),
			MessagelistUserStore.listSearch(),
			MessagelistUserStore.threadUid()
		));
	}

	editCommand() {
		currentMessage() && showMessageComposer([ComposeType.Draft, currentMessage()]);
	}

	moveOrCopy(vm, event, mode) {
		if (vm && event?.preventDefault) {
			stopEvent(event);
		}
		this.actionsMenu().ddBtn.hide();
		AppUserStore.focusedState(ScopeFolderList);
		moveAction(mode);
	}

	moveCommand(vm, event) {
		this.moveOrCopy(vm, event, 1);
	}

	copyCommand(vm, event) {
		this.moveOrCopy(vm, event, 2);
	}

	setUnseen() {
		setAction(MessageSetAction.UnsetSeen);
		currentMessage(null);
	}

	goUpCommand() {
		fireEvent('mailbox.message-list.selector.go-up',
			!!currentMessage() // bForceSelect
		);
	}

	goDownCommand() {
		fireEvent('mailbox.message-list.selector.go-down',
			!!currentMessage() // bForceSelect
		);
	}

	/**
	 * Swipe sideways to reach the next or previous message, which on a phone is
	 * the only comfortable way: the list is hidden while a message is open, so
	 * otherwise you close the message, find your place again, and tap the next one.
	 * Same two commands as the chevrons in the toolbar.
	 */
	initSwipeNavigation(dom) {
		const scroller = dom.querySelector('#messageItem');
		if (!scroller) {
			return;
		}

		const view = dom.querySelector('.messageView'),
			// Shared so the cue cannot appear at a distance the gesture will not act on.
			threshold = 60,
			// -1 heads towards the next message, 1 towards the previous one.
			atEdge = direction => (0 > direction
				? scroller.scrollLeft + scroller.clientWidth >= scroller.scrollWidth - 1
				: 1 > scroller.scrollLeft);

		horizontalSwipe(scroller, {
			threshold: threshold,

			enabled: () => ThemeStore.isMobile() && !!currentMessage() && !this.messageListOrViewLoading(),

			// A wide message scrolls sideways of its own accord. While it still has
			// somewhere to go in that direction the gesture is the content's, not ours.
			canStart: atEdge,

			onMove: dx => {
				if (!dx) {
					// Dropping the class hands the snap back to the stylesheet, which
					// is also where honouring reduced motion belongs.
					scroller.classList.remove('swiping');
					scroller.style.transform = '';
					view?.removeAttribute('data-swipe');
					return;
				}
				// No transition while the finger is down, or it lags behind it.
				scroller.classList.add('swiping');
				// Resistance rather than a hard stop, so the end of the travel is
				// felt instead of hit.
				const max = scroller.clientWidth / 4;
				scroller.style.transform = 'translateX(' + (max * Math.tanh(dx / max)).toFixed(1) + 'px)';
				view?.setAttribute('data-swipe', threshold <= Math.abs(dx) ? (0 > dx ? 'next' : 'prev') : '');
			},

			onCommit: direction => (0 > direction ? this.goDownCommand() : this.goUpCommand())
		});
	}

	/**
	 * @param {string} sType
	 * @returns {void}
	 */
	replyOrforward(sType) {
		showMessageComposer([sType, currentMessage()]);
	}

	onBuild(dom) {
		this.initSwipeNavigation(dom);

		const eqs = (ev, s) => ev.target.closestWithin(s, dom);
		dom.addEventListener('click', event => {
			let el = eqs(event, 'a');
			if (el && 0 === event.button && mailToHelper(el.href)) {
				stopEvent(event);
				return;
			}

			// View original and Download original point at the Raw endpoints and
			// open in a tab, which inside an installed app means a context that
			// carries no session cookie. Fetch it here instead, where it does.
			if (el && 0 === event.button && isStandalone() && isRawLink(el.href)) {
				stopEvent(event);
				openRawInNewContext(el.href, el.download || '');
				return;
			}

			el = eqs(event, '.attachmentsPlace .showPreview');
			if (el) {
				const attachment = ko.dataFor(el), url = attachment?.linkDownload();
//				if (url && FileType.Eml === attachment.fileType) {
				if (url && RFC822 == attachment.mimeType) {
					stopEvent(event);
					fetchRaw(url).then(text => {
						const oMessage = new MessageModel();
						MimeToMessage(text, oMessage);
						// cleanHTML
						oMessage.popupMessage();
					});
				}
				return;
			}

			el = eqs(event, '.attachmentsPlace .showPreplay');
			if (el) {
				stopEvent(event);
				const attachment = ko.dataFor(el);
				if (attachment && SMAudio.supported) {
					switch (true) {
						case SMAudio.supportedMp3 && attachment.isMp3():
							SMAudio.playMp3(attachment.linkDownload(), attachment.fileName);
							break;
						case SMAudio.supportedOgg && attachment.isOgg():
							SMAudio.playOgg(attachment.linkDownload(), attachment.fileName);
							break;
						case SMAudio.supportedWav && attachment.isWav():
							SMAudio.playWav(attachment.linkDownload(), attachment.fileName);
							break;
						// no default
					}
				}
				return;
			}

			// Controls drawn on top of an attachment row, whose own click hands the
			// file to the browser. Knockout's click binding suppresses the default
			// action but not the bubble, so without this, adding an invitation to a
			// calendar also saved it to disk.
			if (eqs(event, '.attachmentCalendar')) {
				return;
			}

			el = eqs(event, '.attachmentItem');
			if (el) {
				const attachment = ko.dataFor(el), url = attachment?.linkDownload();
				if (url) {
					if ('application/pgp-keys' == attachment.mimeType
					 && (OpenPGPUserStore.isSupported() || GnuPGUserStore.isSupported())) {
						fetchRaw(url).then(text =>
							showScreenPopup(OpenPgpImportPopupView, [text])
						);
					} else {
						download(url, attachment.fileName);
					}
				}
			}

			if (eqs(event, '.messageItemHeader .subjectParent .flagParent')) {
				setAction(currentMessage()?.isFlagged() ? MessageSetAction.UnsetFlag : MessageSetAction.SetFlag);
			}
		});

		keyScopeReal.subscribe(value => this.messageDomFocused(ScopeMessageView === value));

		// initShortcuts

		// exit fullscreen, back
		addShortcut('escape', '', ScopeMessageView, () => {
			if (!this.viewModelDom.hidden && currentMessage()) {
				const preview = SettingsUserStore.usePreviewPane();
				if (isFullscreen()) {
					exitFullscreen();
					if (preview) {
						AppUserStore.focusedState(ScopeMessageList);
					}
				} else if (!preview) {
					currentMessage(null);
				} else {
					AppUserStore.focusedState(ScopeMessageList);
				}

				return false;
			}
		});

		// fullscreen
		addShortcut('enter,open', '', ScopeMessageView, () => {
			isFullscreen() || toggleFullscreen();
			return false;
		});

		// reply
		registerShortcut('r,mailreply', '', [ScopeMessageList, ScopeMessageView], () => {
			if (currentMessage()) {
				this.replyCommand();
				return false;
			}
			return true;
		});

		// replyAll
		registerShortcut('a', '', [ScopeMessageList, ScopeMessageView], () => {
			if (currentMessage()) {
				this.replyAllCommand();
				return false;
			}
		});
		registerShortcut('mailreply', 'shift', [ScopeMessageList, ScopeMessageView], () => {
			if (currentMessage()) {
				this.replyAllCommand();
				return false;
			}
		});

		// forward
		registerShortcut('f,mailforward', '', [ScopeMessageList, ScopeMessageView], () => {
			if (currentMessage()) {
				this.forwardCommand();
				return false;
			}
		});

		// message information
		registerShortcut('i', 'meta', [ScopeMessageList, ScopeMessageView], () => {
			currentMessage() && this.toggleFullInfo();
			return false;
		});

		// toggle message blockquotes
		registerShortcut('b', '', [ScopeMessageList, ScopeMessageView], () => {
			const message = currentMessage();
			if (message?.body) {
				message.body.querySelectorAll('details').forEach(node => node.open = !node.open);
				return false;
			}
		});

		addShortcut('b', 'shift', [ScopeMessageList, ScopeMessageView], () => {
			if (!formFieldFocused()) {
				currentMessage()?.swapColors?.();
				return false;
			}
		});

		addShortcut('arrowup,arrowleft', 'meta', [ScopeMessageList, ScopeMessageView], () => {
			this.goUpCommand();
			return false;
		});

		addShortcut('arrowdown,arrowright', 'meta', [ScopeMessageList, ScopeMessageView], () => {
			this.goDownCommand();
			return false;
		});

		// delete
		addShortcut('delete', '', ScopeMessageView, () => {
			this.deleteCommand();
			return false;
		});
		addShortcut('delete', 'shift', ScopeMessageView, () => {
			this.deleteWithoutMoveCommand();
			return false;
		});

		// change focused state
		addShortcut('arrowleft', '', ScopeMessageView, () => {
			if (!isFullscreen() && currentMessage() && SettingsUserStore.usePreviewPane()
			 && !oMessageScrollerDom().scrollLeft) {
				AppUserStore.focusedState(ScopeMessageList);
				return false;
			}
		});
		addShortcut('tab', 'shift', ScopeMessageView, () => {
			if (!isFullscreen() && currentMessage() && SettingsUserStore.usePreviewPane()) {
				AppUserStore.focusedState(ScopeMessageList);
			}
			return false;
		});

		MessageUserStore.bodiesDom(dom.querySelector('.bodyText'));
	}

	scrollMessageToTop() {
		oMessageScrollerDom().scrollTop = 0;
	}

	scrollMessageToLeft() {
		oMessageScrollerDom().scrollLeft = 0;
	}

	toggleAttachmentControls() {
		const b = !this.showAttachmentControls();
		this.showAttachmentControls(b);
		Local.set(ClientSideKeyNameMessageAttachmentControls, b);
	}

	downloadAsZip() {
		const hashes = (currentMessage()?.attachments || [])
			.map(item => item?.checked() /*&& !item?.isLinked()*/ ? item.download : '')
			.filter(v => v);
		downloadZip(
			currentMessage().subject(),
			hashes,
			() => this.downloadAsZipError(true),
			this.downloadAsZipLoading
		);
	}

	/**
	 * Put the events in a calendar attachment onto a calendar.
	 *
	 * The file is handed to the server as it arrived rather than parsed here:
	 * the server already reads iCalendar to store an event at all, and a second
	 * parser in the browser is a second thing to disagree with the first.
	 */
	addToCalendar(attachment) {
		if (this.calendarImportBusy()) {
			return;
		}
		this.calendarImportBusy(true);
		this.calendarImportFailed(false);
		this.calendarImportMessage('');
		this.calendarImportAttachment(null);
		this.calendarImportChoices([]);

		CalendarUserStore.fetchCalendars((iError, calendars) => {
			if (iError) {
				this.calendarImportBusy(false);
				this.calendarImportError(getNotification(iError));
				return;
			}
			// Saving into one of these throws server side, so they are not offered
			const writable = calendars.filter(cal => !cal.readOnly);
			if (!writable.length) {
				this.calendarImportBusy(false);
				this.calendarImportError(i18n('CALENDAR/ERROR_NO_WRITABLE_CALENDAR'));
			} else if (1 === writable.length) {
				this.importIcalInto(attachment, writable[0].uuid);
			} else {
				// Asking is only worth it when there is a choice to make
				this.calendarImportBusy(false);
				this.calendarImportAttachment(attachment);
				this.calendarImportChoices(writable);
			}
		});
	}

	/**
	 * Called from the picker, so the calendar is the bound row and the
	 * attachment is whichever one opened it.
	 */
	importIcalIntoChoice(calendar) {
		const attachment = this.calendarImportAttachment();
		this.calendarImportChoices([]);
		this.calendarImportAttachment(null);
		attachment && this.importIcalInto(attachment, calendar.uuid);
	}

	importIcalInto(attachment, uuid) {
		this.calendarImportBusy(true);
		rl.fetch(attachment.linkDownload())
			.then(response => response.ok ? response.text() : Promise.reject(new Error('download')))
			.then(ical => new Promise((resolve, reject) =>
				Remote.request('CalendarImport', (iError, data) =>
					// Imported sits beside Result rather than inside it: TrueResponse
					// merges its extras into the top level of the payload
					iError ? reject(iError) : resolve(data),
					{ Calendar: uuid, Ical: ical }
				)
			))
			.then(result => {
				this.calendarImportBusy(false);
				this.calendarImportFailed(false);
				this.calendarImportMessage(
					i18n('CALENDAR/IMPORT_SUCCEEDED', { COUNT: result?.Imported || 0 })
				);
				// So an open calendar shows it without waiting for the next sync
				CalendarUserStore.synced(Date.now());
			})
			.catch(err => {
				this.calendarImportBusy(false);
				this.calendarImportError(
					Number.isInteger(err) ? getNotification(err) : i18n('CALENDAR/ERROR_IMPORT_FAILED')
				);
			});
	}

	calendarImportError(text) {
		this.calendarImportFailed(true);
		this.calendarImportMessage(text);
	}

	/**
	 * @param {MessageModel} oMessage
	 * @returns {void}
	 */
	showImages() {
		currentMessage().showExternalImages();
	}

	showTracking() {
		const msg = currentMessage(), body = msg?.body;
		if (body && msg.hasTracking()) {
			let attr = 'data-x-href-tracking';
			body.querySelectorAll('a['+attr+']').forEach(node => node.href = node.getAttribute(attr));
//			attr = 'data-x-src-tracking';
//			body.querySelectorAll('img['+attr+']').forEach(node => node.src = node.getAttribute(attr));
			this.nowTracking(true);
		}
	}

	whitelistText(txt) {
/*
		if ('pass' === currentMessage().spf[0]?.[0]) txt += '+spf';
		if ('pass' === currentMessage().dkim[0]?.[0]) txt += '+dkim';
		if ('pass' === currentMessage().dmarc[0]?.[0]) txt += '+dmarc';
*/
		// showExternalImages below matches against the store as it stands, so the
		// entry has to be in it now rather than a round trip later
		SettingsUserStore.viewImagesWhitelist(
			(SettingsUserStore.viewImagesWhitelist().trim() + '\n' + txt).trim()
		);
		// Only the new sender goes to the server. Sending the whole list meant
		// replaying a snapshot taken at login, which silently dropped whatever a
		// session on another machine had added since, so entries kept going
		// missing. The server merges and answers with what it stored, and this
		// session adopts that, so the local guess above is corrected rather than
		// carried forward.
		Remote.saveSetting('ViewImagesWhitelistAdd', txt, (iError, data) =>
			iError || SettingsUserStore.viewImagesWhitelist(data?.ViewImagesWhitelist || '')
		);
		currentMessage().showExternalImages(1);
	}

	/**
	 * @returns {string}
	 */
	printableCheckedMessageCount() {
		const cnt = MessagelistUserStore.listCheckedOrSelectedUidsWithSubMails().size;
		return 0 < cnt ? (100 > cnt ? cnt : '99+') : '';
	}

	/**
	 * @param {MessageModel} oMessage
	 * @returns {void}
	 */
	readReceipt() {
		let oMessage = currentMessage();
		if (oMessage.readReceipt) {
			oMessage.flags.push('$mdnsent');
			Remote.request('SendReadReceiptMessage',
				iError => iError && oMessage.flags.remove('$mdnsent'),
				{
					messageFolder: oMessage.folder,
					messageUid: oMessage.uid,
					readReceipt: oMessage.readReceipt,
					subject: i18n('READ_RECEIPT/SUBJECT', { SUBJECT: oMessage.subject() }),
					plain: i18n('READ_RECEIPT/BODY', { 'READ-RECEIPT': AccountUserStore.email() })
				}
			);
		}
	}

	newTag() {
		let message = currentMessage();
		if (message) {
			// A tag typed here is the user's own, and the $ namespace belongs to the
			// keywords clients agree on, so the prefix is dropped rather than left to
			// be refused by isAllowedKeyword() without a word
			let keyword = prompt(i18n('MESSAGE/NEW_TAG'), '')?.replace(/[\s\\]+/g, '').replace(/^\$+/, '');
			if (keyword?.length && isAllowedKeyword(keyword)) {
				message.toggleTag(keyword);
				FolderUserStore.currentFolder().permanentFlags.push(keyword);
			}
		}
	}

	pgpDecrypt() {
		currentMessage().decrypt();
	}

	pgpVerify(/*self, event*/) {
		currentMessage().pgpVerify();
	}

	async smimeDecrypt() {
		currentMessage().decrypt();
	}

	smimeVerify(/*self, event*/) {
		currentMessage().smimeVerify();
	}

}
