import ko from 'ko';
import { koComputable, addObservablesTo, addSubscribablesTo, addComputablesTo } from 'External/ko';

import { FolderType } from 'Common/EnumsUser';
import { UNUSED_OPTION_VALUE } from 'Common/Consts';
import { forEachObjectEntry } from 'Common/Utils';
import { getFolderInboxName, getFolderFromCacheList } from 'Common/Cache';
import { Settings, SettingsCapa } from 'Common/Globals';
//import Remote from 'Remote/User/Fetch'; // Circular dependency

export const

/**
 * RFC 5788 reserves the $ prefix for registered keywords, which is also where
 * clients park their private flags: $has_cal, $HighImportance, $labelimportant
 * and whatever the next client invents. Listing those one by one never keeps up,
 * so a keyword in that namespace is offered only when we have a name and a
 * colour for it. $todo has both, but $label4 already shows as "To Do", so it
 * stays hidden as it was before.
 */
shownKeywords = [
	// Thunderbird
	'$label1',
	'$label2',
	'$label3',
	'$label4',
	'$label5',
	// KMail
	'$important'
],

/**
 * Keywords that are not the user's own and do not carry the $ prefix, so the
 * rule above cannot catch them.
 */
ignoredKeywords = [
	// Mailo
	'sent',
	// GMail
	'junk',
	'nonjunk',
	// Others
	'loadremoteimages',
	'redirected'
],

isAllowedKeyword = value => {
	const lower = value.toLowerCase();
	return '\\' != value[0]
		&& ('$' == value[0] ? shownKeywords.includes(lower) : !ignoredKeywords.includes(lower));
},

FolderUserStore = new class {
	constructor() {
		const self = this;
		addObservablesTo(self, {
			/**
			 * To use "checkable" option in /#/settings/folders
			 * When true, getNextFolderNames only lists system and "checkable" folders
			 * and affects the update of unseen count
			 * Auto set to true when amount of folders > folderSpecLimit to prevent requests overload,
			 * see application.ini [labs] folders_spec_limit
			 */
			displaySpecSetting: false,

			sortMode: '',

			quotaLimit: 0,
			quotaUsage: 0,

			sentFolder: '',
			draftsFolder: '',
			spamFolder: '',
			trashFolder: '',
			archiveFolder: '',

			optimized: false,
			error: '',

			foldersLoading: false,
			foldersCreating: false,
			foldersDeleting: false,
			foldersRenaming: false,

			foldersInboxUnreadCount: 0
		});

		self.namespace = '';

		self.folderList = ko.observableArray(/*new FolderCollectionModel*/);

		self.capabilities = ko.observableArray();

		// What the server offers but the administrator switched off. Kept apart
		// from capabilities so a feature can tell "turned off here" from "your
		// server cannot do this", which look the same in capabilities alone.
		self.disabledCapabilities = ko.observableArray();

		self.currentFolder = ko.observable(null).extend({ toggleSubscribeProperty: [self, 'selected'] });

		addComputablesTo(self, {

			draftsFolderNotEnabled: () => !self.draftsFolder() || UNUSED_OPTION_VALUE === self.draftsFolder(),

			currentFolderFullName: () => (self.currentFolder() ? self.currentFolder().fullName : ''),
			currentFolderFullNameHash: () => (self.currentFolder() ? self.currentFolder().fullNameHash : ''),

			foldersChanging: () =>
				self.foldersLoading() | self.foldersCreating() | self.foldersDeleting() | self.foldersRenaming(),

			systemFoldersNames: () => {
				const list = [getFolderInboxName()],
				others = [self.sentFolder(), self.draftsFolder(), self.spamFolder(), self.trashFolder(), self.archiveFolder()];

				self.folderList().length &&
					others.forEach(name => name && UNUSED_OPTION_VALUE !== name && list.push(name));

				return list;
			},

			systemFolders: () =>
				self.systemFoldersNames().map(name => getFolderFromCacheList(name)).filter(v => v),

			/**
			 * Every message the server listed a folder for, Trash, Spam and
			 * unsubscribed folders included, so it can be held against the count of a
			 * backup or an import. Null without LIST-STATUS: the folder list then
			 * arrives with no counts at all and only the folders that happen to have
			 * been opened since carry one, which sums to a number indistinguishable
			 * from a complete one.
			 * https://github.com/kimusan/Tachyon/discussions/181
			 */
			totalEmailsInMailbox: () => {
				if (!self.hasCapability('LIST-STATUS')) {
					return null;
				}
				let total = 0;
				// subFolders() rather than .length, which reads past the dependency
				// tracker, so a folder appearing later would not update the sum.
				const walk = folders => folders.forEach(folder => {
					total += folder.totalEmails();
					walk(folder.subFolders());
				});
				walk(self.folderList());
				return total;
			}
		});

		const
			subscribeRemoveSystemFolder = observable => {
				observable.subscribe(() => getFolderFromCacheList(observable())?.type(0), self, 'beforeChange');
			},
			fSetSystemFolderType = type => value => getFolderFromCacheList(value)?.type(type);

		subscribeRemoveSystemFolder(self.sentFolder);
		subscribeRemoveSystemFolder(self.draftsFolder);
		subscribeRemoveSystemFolder(self.spamFolder);
		subscribeRemoveSystemFolder(self.trashFolder);
		subscribeRemoveSystemFolder(self.archiveFolder);

		addSubscribablesTo(self, {
			sentFolder: fSetSystemFolderType(FolderType.Sent),
			draftsFolder: fSetSystemFolderType(FolderType.Drafts),
			spamFolder: fSetSystemFolderType(FolderType.Junk),
			trashFolder: fSetSystemFolderType(FolderType.Trash),
			archiveFolder: fSetSystemFolderType(FolderType.Archive)
		});

		self.quotaPercentage = koComputable(() => {
			const quota = self.quotaLimit(), usage = self.quotaUsage();
			return 0 < quota ? Math.ceil((usage / quota) * 100) : 0;
		});
	}

	/**
	 * If the IMAP server supports SORT, METADATA
	 */
	hasCapability(name) {
		return this.capabilities().includes(name);
	}

	capabilityDisabled(name) {
		return this.disabledCapabilities().includes(name);
	}

	allowKolab() {
		return FolderUserStore.hasCapability('METADATA') && SettingsCapa('Kolab');
	}

	/**
	 * @returns {Array}
	 */
	getNextFolderNames(ttl) {
		const result = [],
			limit = 10,
			utc = Date.now(),
			timeout = utc - ttl,
			timeouts = [],
			bDisplaySpecSetting = this.displaySpecSetting(),
			fSearchFunction = (list) => {
				list.forEach(folder => {
					if (
						folder?.selectable() &&
						folder.exists &&
						timeout > folder.expires &&
						(folder.isSystemFolder() || (folder.isSubscribed() && (folder.checkable() || !bDisplaySpecSetting)))
					) {
						timeouts.push([folder.expires, folder.fullName]);
					}

					if (folder?.subFolders.length) {
						fSearchFunction(folder.subFolders());
					}
				});
			};

		fSearchFunction(this.folderList());

		timeouts.sort((a, b) => (a[0] < b[0]) ? -1 : (a[0] > b[0] ? 1 : 0));

		timeouts.find(aItem => {
			const folder = getFolderFromCacheList(aItem[1]);
			if (folder) {
				folder.expires = utc;
//				result.indexOf(aItem[1]) ||
				result.push(aItem[1]);
			}

			return limit <= result.length;
		});

		return result;
	}

	saveSystemFolders(folders) {
		folders = folders || {
			sent: FolderUserStore.sentFolder(),
			drafts: FolderUserStore.draftsFolder(),
			junk: FolderUserStore.spamFolder(),
			trash: FolderUserStore.trashFolder(),
			archive: FolderUserStore.archiveFolder()
		};
		forEachObjectEntry(folders, (k,v)=>Settings.set(k+'Folder',v));
		rl.app.Remote.request('SystemFoldersUpdate', null, folders);
	}
};
