import { ScopeMessageList } from 'Common/Enums';
import { doc, createElement, Settings, useLeftPanelOf } from 'Common/Globals';
import { pString, pInt } from 'Common/Utils';
import { moveAction } from 'Common/UtilsUser';
import { getFolderFromHashMap, getFolderInboxName } from 'Common/Cache';
import { i18n, initOnStartOrLangChange } from 'Common/Translator';

import { AppUserStore } from 'Stores/User/App';
import { AccountUserStore } from 'Stores/User/Account';
import { FolderUserStore } from 'Stores/User/Folder';
import { MessagelistUserStore } from 'Stores/User/Messagelist';
import { MessageUserStore } from 'Stores/User/Message';

import { SystemDropDownUserView } from 'View/User/SystemDropDown';
import { MailFolderList } from 'View/User/MailBox/FolderList';
import { MailMessageList } from 'View/User/MailBox/MessageList';
import { MailMessageView } from 'View/User/MailBox/MessageView';

import { AbstractScreen } from 'Knoin/AbstractScreen';

import { MessageModel } from 'Model/Message';
import { populateMessageBody } from 'Common/UtilsUser';

export class MailBoxUserScreen extends AbstractScreen {
	constructor() {
		var styleSheet = createElement('style');
		doc.head.appendChild(styleSheet);
		initOnStartOrLangChange(() =>
			styleSheet.innerText = '.subjectParent:empty::after,.subjectParent .subject:empty::after'
			+'{content:"'+i18n('MESSAGE/EMPTY_SUBJECT_TEXT')+'"}'
		);
		super('mailbox', [
			SystemDropDownUserView,
			MailFolderList,
			MailMessageList,
			MailMessageView
		]);
	}

	/**
	 * @returns {void}
	 */
	updateWindowTitle() {
		const count = Settings.app('listPermanentFiltered') ? 0 : FolderUserStore.foldersInboxUnreadCount(),
			email = AccountUserStore.email();

		rl.setTitle(
			(email
				? '' + (0 < count ? '(' + count + ') ' : ' ') + email + ' - '
				: ''
			) + i18n('TITLES/MAILBOX')
		);
	}

	/**
	 * @returns {void}
	 */
	onShow() {
		useLeftPanelOf(false);
		this.updateWindowTitle();
		AppUserStore.focusedState('none');
		AppUserStore.focusedState(ScopeMessageList);
	}

	/**
	 * @param {string} folderHash
	 * @param {number} page
	 * @param {string} search
	 * @returns {void}
	 */
	onRoute(folderHash, page, search, messageUid) {
		// Only works when FolderUserStore.folderList() is loaded
		const folder = getFolderFromHashMap(folderHash.replace(/~([\d]+)$/, ''));
		if (folder) {
			page = 1 > page ? 1 : page;
			// A message link has nowhere to put the page, so the route reports 1 for
			// every one of them. Taking that at face value threw the reader back to
			// the first page the moment they opened anything from page 2 or later,
			// and left the arrows walking a list that no longer held the open
			// message (#157). Keep the page we are on; a link opened cold still
			// gets 1, because that is what the store holds then.
			if (messageUid && 1 === page) {
				page = MessagelistUserStore.page();
			}
			// The thread uid travels inside the folder part, as "folder~uid", so it
			// has to be read before deciding whether the list moved. Entering or
			// leaving a thread changes neither the folder, the page nor the search,
			// so without it the reload was skipped and the whole list stayed on
			// screen while the view had switched to the thread, and Back to list
			// did not switch back either (#156).
			const sThreadUid = folderHash.replace(/^.+~(\d+)$/, '$1'),
				threadUid = folderHash === sThreadUid ? 0 : pInt(sThreadUid);

			// Opening a message writes the address bar now, so this runs on every
			// message click. Reload the list only when the list itself moved, or
			// each click would refetch the list sitting behind the message.
			const listUnchanged = FolderUserStore.currentFolder() === folder
				&& MessagelistUserStore.page() === page
				&& MessagelistUserStore.listSearch() === search
				&& MessagelistUserStore.threadUid() === threadUid;

			// Every piece of list state the hash carries, set in one place. Setting
			// the thread uid in the no-message branch only meant a reload while
			// reading a message inside a thread came back with the whole folder
			// behind it, the hash saying one thing and the store another.
			FolderUserStore.currentFolder(folder);
			MessagelistUserStore.page(page);
			MessagelistUserStore.listSearch(search);
			MessagelistUserStore.threadUid(threadUid);
			if (messageUid) {
				const current = MessageUserStore.message();
				// A message carries its folder's full name, never the hash, so both
				// the comparison and the new message take folder.fullName. Taking
				// the hash compared a name against a hash, so it never matched and
				// then asked for a message in a folder that does not exist, which
				// Remote.message() dropped without a word (#155). As with #153 it
				// hid wherever a folder name hashes to itself, INBOX included.
				if (!current || current.uid != messageUid || current.folder !== folder.fullName) {
					let message = new MessageModel;
					message.folder = folder.fullName;
					message.uid = messageUid;
					populateMessageBody(message);
				}
			} else {
				// No message in the hash: Back out of one, or any move to the plain
				// folder. Close whatever is open, which is what the Back button is
				// expected to do. Unless the selector is about to open another,
				// which is how next and previous cross a page boundary.
				if (MessagelistUserStore.selectAfterReload) {
					MessagelistUserStore.selectAfterReload = false;
				} else {
					MessageUserStore.message(null);
				}
			}
			listUnchanged || MessagelistUserStore.reload();
		}
	}

	/**
	 * @returns {void}
	 */
	onStart() {
		super.onStart();

		addEventListener('mailbox.inbox-unread-count', e => {
			FolderUserStore.foldersInboxUnreadCount(e.detail);
			const email = AccountUserStore.email();
			AccountUserStore.forEach(item =>
				email === item?.email && item?.unreadEmails(e.detail || null)
			);
			this.updateWindowTitle();
		});
	}

	/**
	 * @returns {void}
	 */
	onBuild() {
		doc.addEventListener('click', event =>
			event.target.closest('#rl-right') && moveAction(0)
		);
	}

	/**
	 * Parse link as generated by mailBox()
	 * @returns {Array}
	 */
	routes() {
		const
			folder = (request, vals) => request ? pString(vals[0]) : getFolderInboxName(),
			fNormS = (request, vals) => [folder(request, vals), request ? pInt(vals[1]) : 1, decodeURI(pString(vals[2]))];

		return [
			// Folder: INBOX | INBOX.sub | Sent | fullNameHash
			[/^([^/]*)$/, { normalize_: fNormS }],
			// Search: {folder}/{string}
			[/^([a-zA-Z0-9.~_-]+)\/(.+)\/?$/, { normalize_: (request, vals) =>
				[folder(request, vals), 1, decodeURI(pString(vals[1]))]
			}],
			// Message: {folder}/m{uid}(/{search})?
			[/^([a-zA-Z0-9.~_-]+)\/m([1-9][0-9]*)(?:\/(.+))?$/, { normalize_: (request, vals) =>
				[folder(request, vals), 1, pString(vals[2]), pString(vals[1])]
			}],
			// Page: {folder}/p{int}(/{search})?
			[/^([a-zA-Z0-9.~_-]+)\/p([1-9][0-9]*)(?:\/(.+))?$/, { normalize_: fNormS }]
		];
	}
}
