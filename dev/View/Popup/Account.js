import { addObservablesTo } from 'External/ko';
import { getNotification } from 'Common/Translator';
import { loadAccountsAndIdentities } from 'Common/UtilsUser';
import { AccountUserStore } from 'Stores/User/Account';
import { Settings, SettingsGet } from 'Common/Globals';
import { addComputablesTo } from 'External/ko';

import Remote from 'Remote/User/Fetch';

import { AbstractViewPopup } from 'Knoin/AbstractViews';

export class AccountPopupView extends AbstractViewPopup {
	constructor() {
		super('Account');

		addObservablesTo(this, {
			isNew: true,
			// The account you log in with. Its address and password belong to the
			// login, so only the name can be changed here.
			isMain: false,

			name: '',
			email: '',
			password: '',

			submitRequest: false,
			submitError: '',
			submitErrorAdditional: ''
		});

		addComputablesTo(this, {
			// Shown as the placeholder, so it is obvious that clearing the field
			// falls back to this rather than to nothing. Comes from the server
			// rather than IdentityUserStore, which holds the identities of the
			// account you are currently in, not the main one.
			defaultName: () => (this.isMain() && SettingsGet('mainIdentityName')) || this.email()
		});
	}

	hideError() {
		this.submitError('');
	}

	submitForm(form) {
		if (this.isMain()) {
			// Not an account in the accounts list, so there is nothing for
			// AccountSetup to update. The name is a setting of its own.
			const name = this.name().trim();
			Remote.saveSettings(null, { MainAccountName: name });
			// SettingsGet reads a snapshot taken at page load, and
			// loadAccountsAndIdentities rebuilds the main account from it. Without
			// this the old name came back the next time anything reloaded the
			// list, which made clearing the field look like it had not worked.
			Settings.set('mainAccountName', name);
			const label = name || SettingsGet('mainIdentityName') || '';
			AccountUserStore.forEach(item =>
				item && !item.isAdditional() && (item.name = label)
			);
			AccountUserStore.valueHasMutated();
			this.close();
			return;
		}
		if (!this.submitRequest() && form.reportValidity()) {
			const data = new FormData(form);
			data.set('new', this.isNew() ? 1 : 0);
			this.submitRequest(true);
			Remote.request('AccountSetup', (iError, data) => {
					this.submitRequest(false);
					if (iError) {
						this.submitError(getNotification(iError));
						this.submitErrorAdditional(data?.messageAdditional);
					} else {
						loadAccountsAndIdentities();
						this.close();
					}
				}, data
			);
		}
	}

	onHide() {
		this.password('');
		this.submitRequest(false);
		this.submitError('');
		this.submitErrorAdditional('');
	}

	onShow(account) {
		// account with isAdditional false is the login account, not a new one.
		// Reading it as "not editable" made clicking it offer to add an account.
		const main = !!account && !account.isAdditional(),
			edit = !!account?.isAdditional();
		this.isMain(main);
		this.isNew(!edit && !main);
		// The explicit setting for the main account, not account.name, which has
		// the identity fallback folded into it already. Putting that in the
		// field would turn a default into a stored choice the moment you saved,
		// and the placeholder would never be seen.
		this.name(main ? (SettingsGet('mainAccountName') || '') : (edit ? account.name : ''));
		this.email(account && (edit || main) ? account.email : '');
		this.password('');
		this.submitError('');
	}
}
