import { AbstractModel } from 'Knoin/AbstractModel';
import { addObservablesTo } from 'External/ko';
import Remote from 'Remote/User/Fetch';
import { SettingsUserStore } from 'Stores/User/Settings';
import { IdentityUserStore } from 'Stores/User/Identity';

export class AccountModel extends AbstractModel {
	/**
	 * @param {string} email
	 * @param {boolean=} canBeDelete = true
	 * @param {number=} count = 0
	 */
	constructor(email, name, isAdditional = true) {
		super();

		this.name = name;
		this.email = email;

		this.displayName = name ? name + ' <' + email + '>' : email;

		addObservablesTo(this, {
			unreadEmails: null,
			askDelete: false,
			isAdditional: isAdditional
		});

		// Load at random between 3 and 30 seconds
		SettingsUserStore.showUnreadCount() && isAdditional
		&& setTimeout(()=>this.fetchUnread(), (Math.ceil(Math.random() * 10)) * 3000);
	}

	label() {
		// Additional accounts are given a name when they are added, but the one
		// you log in with never had anywhere to put one, so it could only ever
		// show as a bare address. Its login identity already carries the display
		// name the user chose, so use that rather than asking for it twice.
		return this.name
			|| (this.isAdditional() ? '' : IdentityUserStore.main()?.name())
			|| IDN.toUnicode(this.email);
	}

	/**
	 * Get INBOX unread messages
	 */
	fetchUnread() {
		Remote.request('AccountUnread', (iError, oData) => {
			iError || this.unreadEmails(oData?.Result?.unreadEmails || null);
		}, {
			email: this.email
		});
	}

	/**
	 * Imports all mail to main account
	 *//*
	importAll(account) {
		Remote.streamPerLine(line => {
			try {
				line = JSON.parse(line);
				console.dir(line);
			} catch (e) {
				// OOPS
			}
		}, 'AccountImport', {
			Action: 'AccountImport',
			email: account.email
		});
	}
	*/

}
