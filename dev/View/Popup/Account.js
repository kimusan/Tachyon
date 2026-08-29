import { addObservablesTo } from 'External/ko';
import { getNotification } from 'Common/Translator';
import { loadAccountsAndIdentities } from 'Common/UtilsUser';
import { AccountUserStore } from 'Stores/User/Account';

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
	}

	hideError() {
		this.submitError('');
	}

	submitForm(form) {
		if (this.isMain()) {
			// Not an account in the accounts list, so there is nothing for
			// AccountSetup to update. The name is a setting of its own.
			Remote.saveSettings(null, { MainAccountName: this.name().trim() });
			AccountUserStore.forEach(item =>
				item && !item.isAdditional() && (item.name = this.name().trim())
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
		this.name(account && (edit || main) ? account.name : '');
		this.email(account && (edit || main) ? account.email : '');
		this.password('');
		this.submitError('');
	}
}
