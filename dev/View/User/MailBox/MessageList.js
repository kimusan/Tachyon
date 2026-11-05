import ko from 'ko';
import { addObservablesTo, addComputablesTo } from 'External/ko';

import { UNUSED_OPTION_VALUE } from 'Common/Consts';
import { ScopeFolderList, ScopeMessageList, ScopeMessageView } from 'Common/Enums';
import { ComposeType, FolderType, MessageSetAction } from 'Common/EnumsUser';
import { doc,
	leftPanelDisabled, toggleLeftPanel,
	Settings, SettingsCapa,
	addEventsListeners, stopEvent,
	addShortcut, registerShortcut, formFieldFocused
} from 'Common/Globals';
import { arrayLength } from 'Common/Utils';
import { computedPaginatorHelper, showMessageComposer, populateMessageBody, downloadZip, moveAction } from 'Common/UtilsUser';
import { FileInfo, RFC822 } from 'Common/File';
import { isFullscreen, toggleFullscreen } from 'Common/Fullscreen';

import { mailBox } from 'Common/Links';
import { Selector } from 'Common/Selector';

import { i18n } from 'Common/Translator';

import { dropFilesInFolder } from 'Common/Folders';

import { getFolderFromCacheList } from 'Common/Cache';

import { AppUserStore } from 'Stores/User/App';
import { SettingsUserStore } from 'Stores/User/Settings';
import { FolderUserStore } from 'Stores/User/Folder';
import { LanguageStore } from 'Stores/Language';
import { MessageUserStore } from 'Stores/User/Message';
import { MessagelistUserStore } from 'Stores/User/Messagelist';
import { ThemeStore } from 'Stores/Theme';

import Remote from 'Remote/User/Fetch';

import { decorateKoCommands, showScreenPopup, arePopupsVisible } from 'Knoin/Knoin';
import { AbstractViewRight } from 'Knoin/AbstractViews';

import { FolderClearPopupView } from 'View/Popup/FolderClear';
import { AdvancedSearchPopupView } from 'View/Popup/AdvancedSearch';
import { ComposePopupView } from 'View/Popup/Compose';

import { MessageModel } from 'Model/Message';

import { LayoutSideView, ClientSideKeyNameMessageListSize } from 'Common/EnumsUser';
import { setLayoutResizer } from 'Common/UtilsUser';

const
	canBeMovedHelper = () => MessagelistUserStore.hasCheckedOrSelected(),

	// Forwarding and downloading work from the loaded messages themselves, so
	// they cannot reach the rest of a list selected beyond the page (#182)
	pageOnlyHelper = () => canBeMovedHelper() && !MessagelistUserStore.allMatching(),

	/**
	 * @param {string} sFolderFullName
	 * @param {number} iSetAction
	 * @param {Array=} aMessages = null
	 * @returns {void}
	 */
	listAction = (...args) => MessagelistUserStore.setAction(...args),

	// A search can span folders, so an action is issued once per folder
	perFolder = (messages, fn) => {
		const byFolder = new Map;
		(messages || MessagelistUserStore.listCheckedOrSelected()).forEach(message => {
			byFolder.has(message.folder) || byFolder.set(message.folder, []);
			byFolder.get(message.folder).push(message);
		});
		byFolder.forEach(fn);
	},

	listActionPerFolder = (iSetAction, messages) => {
		const all = MessagelistUserStore.allMatching();
		all
			? listAction(all.folder, iSetAction, MessagelistUserStore.listChecked(), all.uids)
			: perFolder(messages, (list, folder) => listAction(folder, iSetAction, list));
	},

	// The keywords Thunderbird's 1 to 5 keys set, named and coloured to match
	labelKeywords = [1, 2, 3, 4, 5].map(i => '$label' + i),

	setKeyword = (messages, keyword, bSet) => {
		const all = MessagelistUserStore.allMatching();
		perFolder(all ? MessagelistUserStore.listChecked() : messages, (list, folder) =>
			Remote.request('MessageSetKeyword', iError => {
				iError || list.forEach(message =>
					bSet
						? message.flags.includes(keyword) || message.flags.push(keyword)
						: message.flags.remove(keyword)
				);
			}, {
				folder: folder,
				uids: (all ? [...all.uids] : list.map(message => message.uid)).join(','),
				keyword: keyword,
				setAction: bSet ? 1 : 0
			})
		);
	},

	moveMessagesToFolderType = (toFolderType, bDelete) => {
		MessagelistUserStore.listCheckedOrSelectedByFolder().forEach((uids, folder) =>
			uids.size && rl.app.moveMessagesToFolderType(toFolderType, folder, uids, bDelete)
		);
	},

	pad2 = v => 10 > v ? '0' + v : '' + v,
	Ymd = dt => dt.getFullYear() + pad2(1 + dt.getMonth()) + pad2(dt.getDate()),

	/**
	 * Every way of showing a message arrives here: a click, the keyboard through
	 * the selector, a middle click, and the swipe in the message view. So the
	 * address bar is written here and nowhere else.
	 *
	 * This was written before and left commented out because the hash change
	 * made the core load the message a second time. onRoute() now recognises the
	 * message it is already showing and does nothing, so the write is free.
	 *
	 * How the address bar is written, from the caller:
	 *   false  push, for an open the reader asked for, so Back returns to the list
	 *   true   replace, for arrow keys stepping through the list: the URL has to
	 *          stay accurate without leaving an entry per message
	 *   null   leave it alone, for the preview pane filling itself at folder
	 *          load. Writing there would overwrite the list's own history entry
	 *          and Back would have nothing to return to.
	 */
	setMessage = (msg, replace) => {
		populateMessageBody(msg);
		// A message carries its folder's full name, and mailBox() wants the hash:
		// fullNameHash exists because a full name may hold anything, including the
		// delimiter. Writing the name here put "a/b/c" into the URL, where the
		// route read the tail as page and search, so the search box filled itself
		// and the message never opened (#153). It went unnoticed because a folder
		// named only [a-z0-9._-], INBOX for one, hashes to itself.
		const folderHash = null !== replace && getFolderFromCacheList(msg.folder)?.fullNameHash;
		if (folderHash) {
			const hash = mailBox(
				folderHash,
				MessagelistUserStore.page(),
				MessagelistUserStore.listSearch(),
				MessagelistUserStore.threadUid(),
				msg.uid
			);
			replace ? hasher.replaceHash(hash) : hasher.setHash(hash);
		}
	};


let
	sLastSearchValue = '';

export class MailMessageList extends AbstractViewRight {
	constructor() {
		super();

		this.allowDangerousActions = SettingsCapa('DangerousActions');

		this.messageList = MessagelistUserStore;
		this.archiveAllowed = MessagelistUserStore.archiveAllowed;
		this.canMarkAsSpam = MessagelistUserStore.canMarkAsSpam;
		this.isSpamFolder = MessagelistUserStore.isSpamFolder;

		this.composeInEdit = ComposePopupView.inEdit;

		this.isMobile = ThemeStore.isMobile; // Obsolete

		this.popupVisibility = arePopupsVisible;

		this.useCheckboxesInList = SettingsUserStore.useCheckboxesInList;

		this.userUsageProc = FolderUserStore.quotaPercentage;

		this.hideDeleted = SettingsUserStore.hideDeleted;

		addObservablesTo(this, {
			focusSearch: false
		});

		// append drag and drop
		this.dragOver = ko.observable(false).extend({ throttle: 1 });
		this.dragOverEnter = ko.observable(false).extend({ throttle: 1 });

		const attachmentsActions = Settings.app('attachmentsActions');
		this.attachmentsActions = ko.observableArray(arrayLength(attachmentsActions) ? attachmentsActions : []);

		addComputablesTo(this, {

			sortSupported: () => FolderUserStore.hasCapability('SORT') && !MessagelistUserStore.threadUid(),

			messageListSearchDesc: () => {
				const value = MessagelistUserStore().search;
				return value ? i18n('MESSAGE_LIST/SEARCH_RESULT_FOR', { SEARCH: value }) : ''
			},

			messageListPaginator: computedPaginatorHelper(MessagelistUserStore.page, MessagelistUserStore.pageCount),

			checkAll: {
				read: () => MessagelistUserStore.hasChecked(),
				write: (value) => {
					value = !!value;
					MessagelistUserStore.forEach(message => message.checked(value));
				}
			},

			inputSearch: {
				read: MessagelistUserStore.mainSearch,
				write: value => sLastSearchValue = value
			},

			// "All 50 on this page are selected", with the offer to take the rest
			// of the list. Not for a list across folders, or one thread (#182).
			allMatchingOffer: () => {
				const all = MessagelistUserStore.allMatching(),
					loaded = MessagelistUserStore().length;
				return all
					? i18n(all.search ? 'MESSAGE_LIST/ALL_MATCHING_SEARCH_SELECTED' : 'MESSAGE_LIST/ALL_MATCHING_SELECTED',
						{ COUNT: all.uids.size.toLocaleString() })
					: (loaded && loaded === MessagelistUserStore.listChecked().length
						&& MessagelistUserStore.count() > loaded
						&& !MessagelistUserStore.listSearchScope()
						&& !MessagelistUserStore.threadUid())
					? i18n('MESSAGE_LIST/PAGE_SELECTED', { COUNT: loaded.toLocaleString() })
					: '';
			},

			allMatchingAction: () => i18n(MessagelistUserStore.allMatching()
				? 'MESSAGE_LIST/CLEAR_SELECTION'
				: MessagelistUserStore.listSearch()
					? 'MESSAGE_LIST/SELECT_ALL_MATCHING_SEARCH'
					: 'MESSAGE_LIST/SELECT_ALL_MATCHING'),

			isIncompleteChecked: () => {
				const c = MessagelistUserStore.listChecked().length;
				return c && MessagelistUserStore().length > c;
			},

			listGrouped: () => {
				let uid = MessagelistUserStore.threadUid(),
					sort = FolderUserStore.sortMode() || 'DATE';
				if (uid) {
					return false;
				}
				// A list spanning folders is only readable when grouped, so ignore the setting
				if (MessagelistUserStore.listSearchScope()) {
					return true;
				}
				return SettingsUserStore.listGrouped() && (sort.includes('DATE') || sort.includes('FROM'));
			},

			groupedList: () => {
				let list = [], current, sort = FolderUserStore.sortMode() || 'DATE';
				if (MessagelistUserStore.listSearchScope()) {
					// The server returns the matches folder by folder, so a change of folder starts a group
					MessagelistUserStore.forEach(msg => {
						if (!current || msg.folder != current.id) {
							current = {
								id: msg.folder,
								label: msg.folder,
								search: '',
								messages: []
							};
							list.push(current);
						}
						current.messages.push(msg);
					});
				} else if (sort.includes('FROM')) {
					MessagelistUserStore.forEach(msg => {
						let email = msg.from[0]?.email;
						if (!current || email != current.id) {
							current = {
								id: email,
								label: msg.from[0]?.toLine(),
								search: 'from=' + email,
								messages: []
							};
							list.push(current);
						}
						current.messages.push(msg);
					});
				} else if (sort.includes('DATE')) {
					let today = Ymd(new Date()),
						rtf = Intl.RelativeTimeFormat
							? new Intl.RelativeTimeFormat(doc.documentElement.lang, { numeric: "auto" }) : 0;
					MessagelistUserStore.forEach(msg => {
						let dt = (new Date(msg.dateTimestamp() * 1000)),
							date,
							ymd = Ymd(dt);
						if (!current || ymd != current.id) {
							if (rtf && today == ymd) {
								date = rtf.format(0, 'day');
							} else if (rtf && today - 1 == ymd) {
								date = rtf.format(-1, 'day');
//							} else if (today - 7 < ymd) {
//								date = dt.format({weekday: 'long'});
//								date = dt.format({dateStyle: 'full'},0,LanguageStore.hourCycle());
							} else {
//								date = dt.format({dateStyle: 'medium'},0,LanguageStore.hourCycle());
								date = dt.format({dateStyle: 'full'},0,LanguageStore.hourCycle());
							}
							current = {
								id: ymd,
								label: date,
								search: 'on=' + dt.getFullYear() + '-' + pad2(1 + dt.getMonth()) + '-' + pad2(dt.getDate()),
								// The label carries the date, so a row needs only the time.
								// Any other group, a plugin's included, gets the full one (#150).
								timeFormat: 'LT',
								messages: []
							};
							list.push(current);
						}
						current.messages.push(msg);
					});
				}
				return list;
			},

			// Icon classes rather than glyphs, so the button matches the entries in
			// its own menu instead of rendering as emoji. Split into what is being
			// sorted and which way, because the arrow tucked into a combined icon
			// is too small to read at this size.
			sortIcon: () => {
				let mode = FolderUserStore.sortMode(),
					has = w => mode.includes(w);
				if (has('FROM')) {
					return 'icon-user';
				}
				if (has('SUBJECT')) {
					return 'icon-a-arrow-down';
				}
				if (has('SIZE')) {
					return 'icon-scale';
				}
				return has('ARRIVAL') ? 'icon-clock' : 'icon-calendar';
			},

			sortDirectionIcon: () => {
				let mode = FolderUserStore.sortMode();
				return ('' === mode || mode.includes('REVERSE')) ? 'icon-arrow-down' : 'icon-arrow-up';
			},

			downloadAsZipAllowed: () => this.attachmentsActions.includes('zip')
		});

		this.selector = new Selector(
			MessagelistUserStore,
			MessagelistUserStore.selectedMessage,
			MessagelistUserStore.focusedMessage,
			'.messageListItem',
			'.messageListItem .messageCheckbox'
		);

		// Lines of body snippet to show under the subject, 0 for none. The store
		// observable itself, so changing it in settings reflows the list at once.
		this.previewLines = SettingsUserStore.messageListPreviewLines;

		this.selector.on('ItemSelect', message => {
			if (message) {
				// A selection the list made itself is not a place the reader asked
				// to go, so it must not push: the one at folder load happens before
				// any click at all, and Chrome marks a gesture-less entry skippable
				// and walks straight past it on Back.
				const auto = this.selector.autoSelected === message,
					hashWrite = auto ? (this.selector.autoSelectedKeyboard || null) : false;
				this.selector.autoSelected = null;
//				setMessage(message.clone());
				setMessage(message, hashWrite);
			} else {
				MessageUserStore.message(null);
			}
		});

		// Back out of a message and the list has to forget which row was selected,
		// or clicking that same row again changes nothing in the selector and the
		// message never reopens. Nothing is checked at this point, so the
		// selector's own ItemSelect(null) does not fire and this cannot loop.
		MessageUserStore.message.subscribe(message =>
			message || this.selector.selectedItem(null)
		);

		this.selector.on('MiddleClick', message => populateMessageBody(message, true));

		this.selector.on('ItemGetUid', message => (message ? message.folder + '/' + message.uid : ''));

		this.selector.on('canSelect', () => MessagelistUserStore.canSelect());

		this.selector.on('click', (event, currentMessage) => {
			const el = event.target;
			if (el.closest('.flagParent')) {
				if (currentMessage) {
					const checked = MessagelistUserStore.listCheckedOrSelected();
					listAction(
						currentMessage.folder,
						currentMessage.isFlagged() ? MessageSetAction.UnsetFlag : MessageSetAction.SetFlag,
						checked.find(message => message.uid == currentMessage.uid) ? checked : [currentMessage]
					);
				}
			} else if (el.closest('.threads-len')) {
				this.gotoThread(currentMessage);
			} else {
				return 1;
			}
		});

		this.selector.on('UpOrDown', up => {
			if (!MessagelistUserStore.hasChecked()) {
				up = up ? -1 : 1;
				const page = MessagelistUserStore.page() + up;
				if (page > 0 && page <= MessagelistUserStore.pageCount()) {
					if (SettingsUserStore.usePreviewPane() || MessageUserStore.message()) {
						this.selector.iSelectNextHelper = up;
						// A message is open and another is about to replace it, so
						// the route must leave this one alone until it does.
						MessagelistUserStore.selectAfterReload = true;
					} else {
						this.selector.iFocusedNextHelper = up;
					}
					this.selector.unselect();
					this.gotoPage(page);
				}
			}
		});

		addEventListener('mailbox.message-list.selector.go-down',
			e => this.selector.newSelectPosition('ArrowDown', false, e.detail)
		);

		addEventListener('mailbox.message-list.selector.go-up',
			e => this.selector.newSelectPosition('ArrowUp', false, e.detail)
		);

		addEventListener('mailbox.message.show', e => {
			const sFolder = e.detail.folder, iUid = e.detail.uid;

			const message = MessagelistUserStore.find(
				item => sFolder === item?.folder && iUid == item?.uid
			);

			// Only go to the folder when the message is not already in front of
			// us. The folder link carries no page and no search, so navigating
			// threw away a filtered list, and this event is also how deleting a
			// message asks for the next one to be selected: deleting inside a
			// search dropped you back into the unfiltered folder every time.
			if (message) {
				this.selector.selectMessageItem(message);
			} else {
				// The hash, not the full name, for the reason given at setMessage()
				hasher.setHash(mailBox(getFolderFromCacheList(sFolder)?.fullNameHash));
				if (sFolder && iUid) {
					let message = new MessageModel;
					message.folder = sFolder;
					message.uid = iUid;
					// The folder hash just above pushed this navigation already.
					setMessage(message, true);
				} else {
					MessageUserStore.message(null);
				}
			}
		});

		MessagelistUserStore.endHash.subscribe((() =>
			this.selector.scrollToFocused()
		).throttle(50));

		decorateKoCommands(this, {
			downloadAttachCommand: pageOnlyHelper,
			downloadZipCommand: pageOnlyHelper,
			forwardCommand: pageOnlyHelper,
			deleteWithoutMoveCommand: canBeMovedHelper,
			deleteCommand: () => MessagelistUserStore.hasCheckedOrSelectedAndUndeleted(),
			undeleteCommand: () => MessagelistUserStore.hasCheckedOrSelectedAndDeleted(),
			archiveCommand: canBeMovedHelper,
			spamCommand: canBeMovedHelper,
			notSpamCommand: canBeMovedHelper,
			moveCommand: canBeMovedHelper,
			copyCommand: canBeMovedHelper
		});
	}

	changeSort(self, event) {
		FolderUserStore.sortMode(event.target.closest('li').dataset.sort);
		this.reload();
	}

	clearListIsVisible() {
		return (
			!this.messageListSearchDesc()
		 && !MessagelistUserStore.error()
		 && !MessagelistUserStore.endThreadUid()
		 && MessagelistUserStore().length
		 && (MessagelistUserStore.isSpamFolder() || MessagelistUserStore.isTrashFolder())
		 && SettingsCapa('DangerousActions')
		);
	}

	clear() {
		SettingsCapa('DangerousActions')
		&& showScreenPopup(FolderClearPopupView, [FolderUserStore.currentFolder()]);
	}

	toggleAllMatching() {
		MessagelistUserStore.allMatching()
			? MessagelistUserStore.forEach(message => message.checked(false))
			: MessagelistUserStore.selectAllMatching();
	}

	reload() {
		MessagelistUserStore.isLoading()
		|| MessagelistUserStore.reload(false, true);
	}

	forwardCommand() {
		showMessageComposer([
			ComposeType.ForwardAsAttachment,
			MessagelistUserStore.listCheckedOrSelected()
		]);
	}

	/**
	 * Download selected messages
	 */
	downloadZipCommand() {
		let hashes = []/*, uids = []*/;
//		MessagelistUserStore.forEach(message => message.checked() && uids.push(message.uid));
		MessagelistUserStore.forEach(message => message.checked() && hashes.push(message.requestHash));
		downloadZip(null, hashes, null, null, MessagelistUserStore().folder);
	}

	/**
	 * Download attachments of selected messages
	 */
	downloadAttachCommand() {
		let hashes = [];
		MessagelistUserStore.forEach(message => {
			if (message.checked()) {
				message.attachments.forEach(attachment => {
					if (!attachment.isLinked() && attachment.download) {
						hashes.push(attachment.download);
					}
				});
			}
		});
		downloadZip(null, hashes);
	}

	deleteWithoutMoveCommand() {
		SettingsCapa('DangerousActions')
		&& moveMessagesToFolderType(FolderType.Trash, true);
	}

	// User setting hideDeleted || immediatelyMoveToTrash ??
	deleteCommand() {
		/**
		 * When FolderUserStore.trashFolder is set to "Do not use",
		 * flag as \Deleted for removal by later EXPUNGE
		 */
		if (UNUSED_OPTION_VALUE === FolderUserStore.trashFolder()) {
			listActionPerFolder(MessageSetAction.SetDeleted);
		} else {
			moveMessagesToFolderType(FolderType.Trash);
		}
	}

	// User setting !hideDeleted && !immediatelyMoveToTrash ??
	undeleteCommand() {
		listActionPerFolder(MessageSetAction.UnsetDeleted);
	}

	archiveCommand() {
		moveMessagesToFolderType(FolderType.Archive);
	}

	spamCommand() {
		moveMessagesToFolderType(FolderType.Junk);
	}

	notSpamCommand() {
		moveMessagesToFolderType(FolderType.Inbox);
	}

	moveOrCopy(vm, event, mode) {
		if (canBeMovedHelper()) {
			if (vm && event?.preventDefault) {
				stopEvent(event);
			}

			let i = moveAction();
			AppUserStore.focusedState(i ? ScopeMessageList : ScopeFolderList);
			moveAction(i ? 0 : mode);
		}
	}

	moveCommand(vm, event) {
		this.moveOrCopy(vm, event, 1);
	}

	copyCommand(vm, event) {
		this.moveOrCopy(vm, event, 2);
	}

	composeClick() {
		showMessageComposer();
	}

	cancelSearch() {
		MessagelistUserStore.mainSearch('');
		this.focusSearch(false);
	}

	cancelThreadUid() {
		// history.go(-1) better?
		hasher.setHash(
			mailBox(
				FolderUserStore.currentFolderFullNameHash(),
				MessagelistUserStore.pageBeforeThread(),
				MessagelistUserStore.listSearch()
			)
		);
	}

	listSetSeen() {
		listActionPerFolder(MessageSetAction.SetSeen);
	}

	listSetAllSeen() {
		let sFolderFullName = FolderUserStore.currentFolderFullName(),
			iThreadUid = MessagelistUserStore.endThreadUid();
		if (sFolderFullName) {
			let cnt = 0;
			const uids = [];

			let folder = getFolderFromCacheList(sFolderFullName);
			if (folder) {
				MessagelistUserStore.forEach(message => {
					if (message.isUnseen()) {
						++cnt;
					}

					message.flags.push('\\seen');
//					message.flags.valueHasMutated();
					iThreadUid && uids.push(message.uid);
				});

				if (iThreadUid) {
					folder.unreadEmails(Math.max(0, folder.unreadEmails() - cnt));
				} else {
					folder.unreadEmails(0);
				}

				Remote.request('MessageSetSeenToAll', null, {
					folder: sFolderFullName,
					setAction: 1,
					threadUids: uids.join(',')
				});
			}
		}
	}

	listUnsetSeen() {
		listActionPerFolder(MessageSetAction.UnsetSeen);
	}

	listSetFlags() {
		listActionPerFolder(MessageSetAction.SetFlag);
	}

	listUnsetFlags() {
		listActionPerFolder(MessageSetAction.UnsetFlag);
	}

	seenMessagesFast(seen) {
		const checked = MessagelistUserStore.listCheckedOrSelected();
		if (checked.length) {
			listActionPerFolder(seen ? MessageSetAction.SetSeen : MessageSetAction.UnsetSeen, checked);
		}
	}

	gotoPage(page) {
		page && hasher.setHash(
			mailBox(
				FolderUserStore.currentFolderFullNameHash(),
				page,
				MessagelistUserStore.listSearch(),
				MessagelistUserStore.threadUid()
			)
		);
	}

	gotoThread(message) {
		if (message?.threadsLen()) {
			MessagelistUserStore.pageBeforeThread(MessagelistUserStore.page());

			hasher.setHash(
				mailBox(FolderUserStore.currentFolderFullNameHash(), 1, MessagelistUserStore.listSearch(), message.uid)
			);
		}
	}

	listEmptyMessage() {
		if (!this.dragOver()
		 && !MessagelistUserStore().length
		 && !MessagelistUserStore.isLoading()
		 && !MessagelistUserStore.error()) {
			 return i18n('MESSAGE_LIST/EMPTY_' + (MessagelistUserStore.listSearch() ? 'SEARCH_' : '') + 'LIST');
		}
		return '';
	}

	onBuild(dom) {
		const b_content = dom.querySelector('.b-content'),
			eqs = (ev, s) => ev.target.closestWithin(s, dom);

		setTimeout(() => {
			// initMailboxLayoutResizer
			const top = dom.querySelector('.messageList'),
				fToggle = () => {
					let layout = SettingsUserStore.usePreviewPane();
					setLayoutResizer(top, ClientSideKeyNameMessageListSize,
						layout ? (LayoutSideView === layout ? 'Width' : 'Height') : 0
					);
				};
			if (top) {
				fToggle();
				addEventListener('rl-layout', fToggle);
			}
		}, 1);

		this.selector.init(b_content, ScopeMessageList);

		addEventsListeners(dom, {
			click: event => {
				if (eqs(event, '.toggleLeft')) {
					toggleLeftPanel();
				} else {
					ThemeStore.isMobile() && leftPanelDisabled(true);

					if (eqs(event, '.messageList') && ScopeMessageView === AppUserStore.focusedState()) {
						AppUserStore.focusedState(ScopeMessageList);
					}

					let el = eqs(event, '.e-paginator a');
					el && this.gotoPage(ko.dataFor(el)?.value);

					eqs(event, '.checkboxCheckAll') && this.checkAll(!this.checkAll());
				}
			},
			dblclick: event => {
				let msg = ko.dataFor(eqs(event, '.messageListItem'));
				if (msg) {
					msg.threadsLen() ? this.gotoThread(msg) : toggleFullscreen();
				}
			}
		});

		// initUploaderForAppend

		if (Settings.app('allowAppendMessage')) {
			const dropZone = dom.querySelector('.listDragOver'),
				validFiles = oEvent => {
					for (const item of oEvent.dataTransfer.items) {
						if ('file' === item.kind && RFC822 === item.type) {
							return true;
						}
					}
				};
			addEventsListeners(dropZone, {
				dragover: oEvent => {
					if (validFiles(oEvent)) {
						oEvent.dataTransfer.dropEffect = 'copy';
						oEvent.preventDefault();
					}
				},
			});
			addEventsListeners(b_content, {
				dragenter: oEvent => {
					if (validFiles(oEvent)) {
						if (b_content.contains(oEvent.target)) {
							this.dragOver(true);
						}
						if (oEvent.target == dropZone) {
							oEvent.dataTransfer.dropEffect = 'copy';
							this.dragOverEnter(true);
						}
					}
				},
				dragleave: oEvent => {
					if (oEvent.target == dropZone) {
						this.dragOverEnter(false);
					}
					let related = oEvent.relatedTarget;
					if (!related || !b_content.contains(related)) {
						this.dragOver(false);
					}
				},
				drop: oEvent => {
					oEvent.preventDefault();
					if (oEvent.target == dropZone && validFiles(oEvent)) {
						MessagelistUserStore.loading(true);
						dropFilesInFolder(FolderUserStore.currentFolderFullName(), oEvent.dataTransfer.files);
					}
					this.dragOverEnter(false);
					this.dragOver(false);
				}
			});
		}

		// initShortcuts

		addShortcut('enter,open', '', ScopeMessageList, () => {
			if (formFieldFocused()) {
				MessagelistUserStore.mainSearch(sLastSearchValue);
				return false;
			}
			if (MessageUserStore.message() && MessagelistUserStore.canSelect()) {
				isFullscreen() || toggleFullscreen();
				return false;
			}
		});

		// archive (zip)
		registerShortcut('z', '', [ScopeMessageList, ScopeMessageView], () => {
			this.archiveCommand();
			return false;
		});

		// delete
		registerShortcut('delete', 'shift', ScopeMessageList, () => {
			MessagelistUserStore.listCheckedOrSelected().length && this.deleteWithoutMoveCommand();
			return false;
		});
//		registerShortcut('3', 'shift', ScopeMessageList, () => {
		registerShortcut('delete', '', ScopeMessageList, () => {
			MessagelistUserStore.listCheckedOrSelected().length && this.deleteCommand();
			return false;
		});

		// check mail
		addShortcut('r', 'meta', [ScopeFolderList, ScopeMessageList, ScopeMessageView], () => {
			this.reload();
			return false;
		});

		// check all
		registerShortcut('a', 'meta', ScopeMessageList, () => {
			this.checkAll(!(this.checkAll() && !this.isIncompleteChecked()));
			return false;
		});

		// write/compose (open compose popup)
		registerShortcut('w,c,new', '', [ScopeMessageList, ScopeMessageView], () => {
			showMessageComposer();
			return false;
		});

		// important - star/flag messages
		registerShortcut('i', '', [ScopeMessageList, ScopeMessageView], () => {
			const checked = MessagelistUserStore.listCheckedOrSelected();
			if (checked.length) {
				listAction(
					checked[0].folder,
					checked.every(message => message.isFlagged()) ? MessageSetAction.UnsetFlag : MessageSetAction.SetFlag,
					checked
				);
			}
			return false;
		});

		// Thunderbird labels: 1 to 5 toggle one on the selection, 0 takes them all off
		registerShortcut('0,1,2,3,4,5', '', [ScopeMessageList, ScopeMessageView], event => {
			const messages = MessagelistUserStore.listCheckedOrSelected();
			if (messages.length) {
				if ('0' === event.key) {
					labelKeywords.forEach(keyword =>
						messages.some(message => message.flags.includes(keyword))
							&& setKeyword(messages, keyword, false)
					);
				} else {
					const keyword = '$label' + event.key;
					setKeyword(messages, keyword,
						!messages.every(message => message.flags.includes(keyword)));
				}
			}
			return false;
		});

		registerShortcut('t', '', [ScopeMessageList], () => {
			let message = MessagelistUserStore.selectedMessage() || MessagelistUserStore.focusedMessage();
			if (0 < message?.threadsLen()) {
				this.gotoThread(message);
			}
			return false;
		});

		// move
		registerShortcut('insert', '', ScopeMessageList, () => {
			this.moveCommand();
			return false;
		});

		// read
		registerShortcut('q', '', [ScopeMessageList, ScopeMessageView], () => {
			this.seenMessagesFast(true);
			return false;
		});

		// unread
		registerShortcut('u', '', [ScopeMessageList, ScopeMessageView], () => {
			this.seenMessagesFast(false);
			return false;
		});

		registerShortcut('f,mailforward', 'shift', [ScopeMessageList, ScopeMessageView], () => {
			this.forwardCommand();
			return false;
		});

		if (SettingsCapa('Search')) {
			// search input focus
			addShortcut('/', '', [ScopeMessageList, ScopeMessageView], () => {
				this.focusSearch(true);
				return false;
			});
		}

		// cancel search
		addShortcut('escape', '', ScopeMessageList, () => {
			if (this.messageListSearchDesc()) {
				this.cancelSearch();
				return false;
			} else if (MessagelistUserStore.endThreadUid()) {
				this.cancelThreadUid();
				return false;
			}
		});

		// change focused state
		addShortcut('tab', 'shift', ScopeMessageList, () => {
			AppUserStore.focusedState(ScopeFolderList);
			return false;
		});
		addShortcut('arrowleft', '', ScopeMessageList, () => {
			AppUserStore.focusedState(ScopeFolderList);
			return false;
		});
		addShortcut('tab,arrowright', '', ScopeMessageList, () => {
			if (MessageUserStore.message()) {
				AppUserStore.focusedState(ScopeMessageView);
				return false;
			}
		});

		addShortcut('arrowleft', 'meta', ScopeMessageView, ()=>false);
		addShortcut('arrowright', 'meta', ScopeMessageView, ()=>false);

		addShortcut('f', 'meta', ScopeMessageList, this.advancedSearchClick);
	}

	advancedSearchClick() {
		showScreenPopup(AdvancedSearchPopupView, [MessagelistUserStore.mainSearch()]);
	}

	groupSearch(group) {
		group.search && MessagelistUserStore.mainSearch(group.search);
	}

	groupCheck(group) {
		group.messages.forEach(message => message.checked(!message.checked()));
	}

	quotaTooltip() {
		return i18n('MESSAGE_LIST/QUOTA_SIZE', {
			SIZE: FileInfo.friendlySize(FolderUserStore.quotaUsage()),
			PROC: FolderUserStore.quotaPercentage(),
			LIMIT: FileInfo.friendlySize(FolderUserStore.quotaLimit())
		}).replace(/<[^>]+>/g, '');
	}
}
