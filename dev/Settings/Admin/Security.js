import { SettingsGet, SettingsCapa } from 'Common/Globals';
import { addObservablesTo, addSubscribablesTo, addComputablesTo } from 'External/ko';

import Remote from 'Remote/Admin/Fetch';

import { decorateKoCommands } from 'Knoin/Knoin';
import { AbstractViewSettings } from 'Knoin/AbstractViews';

export class AdminSettingsSecurity extends AbstractViewSettings {
	constructor() {
		super();

		this.addSettings(['proxyExternalImages', 'autoVerifySignatures']);

		this.weakPassword = rl.app.weakPassword;

		addObservablesTo(this, {
			adminLogin: SettingsGet('adminLogin'),
			adminLoginError: false,
			adminPassword: '',
			adminPasswordNew: '',
			adminPasswordNew2: '',
			adminPasswordNewError: false,
			adminTOTP: '',
			adminTOTPCode: '',
			adminTOTPSaved: '',

			saveError: false,
			saveSuccess: false,

			viewQRCode: '',

			capaGnuPG: SettingsCapa('GnuPG'),
			capaOpenPGP: SettingsCapa('OpenPGP')
		});

		this.gnuPGversion = SettingsGet('gnupg') ? 'GnuPG v' + SettingsGet('gnupg') : 'GnuPG';
		this.gnupgAvailable = ko.observable(!!SettingsGet('gnupg'));

		const reset = () => {
			this.saveError(false);
			this.saveSuccess(false);
			this.adminPasswordNewError(false);
		};

		addComputablesTo(this, {
			// Only a real change to the secret needs confirming. Saving a password
			// with two factor already on, or already off, asks for nothing.
			totpChanged: () => this.adminTOTP() !== this.adminTOTPSaved() && !!this.adminTOTP()
		});

		addSubscribablesTo(this, {
			adminPassword: () => {
				this.saveError(false);
				this.saveSuccess(false);
			},

			adminLogin: () => this.adminLoginError(false),

			adminTOTP: value => {
				if (/[A-Z2-7]{16,}/.test(value) && 0 == value.length * 5 % 8) {
					Remote.request('AdminQRCode', (iError, data) => {
						if (!iError) {
							console.dir({data:data});
							this.viewQRCode(data.Result);
						}
					}, {
						'username': this.adminLogin(),
						'TOTP': this.adminTOTP()
					});
				} else {
					this.viewQRCode('');
				}
			},

			adminPasswordNew: reset,

			adminPasswordNew2: reset,

			capaGnuPG: value => Remote.saveSetting('capaGnuPG', value),
			capaOpenPGP: value => Remote.saveSetting('capaOpenPGP', value)
		});

		this.adminTOTP(SettingsGet('adminTOTP'));
		// What is actually stored, so the confirmation is asked for only when the
		// secret is really being changed rather than on every save
		this.adminTOTPSaved(SettingsGet('adminTOTP'));

		decorateKoCommands(this, {
			saveAdminUserCommand: self => self.adminLogin().trim() && self.adminPassword()
		});
	}

	generateTOTP() {
		// getRandomValues, not Math.random: this is a shared secret guarding the
		// admin panel, and Math.random is seeded predictably and is not meant to
		// be unguessable. 32 divides 256 exactly, so the modulo is unbiased.
		const CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',
			bytes = new Uint8Array(16);
		crypto.getRandomValues(bytes);
		this.adminTOTP([...bytes].map(b => CHARS[b % 32]).join(''));
	}

	clearTOTP() {
		// Generate only shows itself when the field is empty, so without this
		// there was no way to turn two factor off or rotate to a new secret
		// short of selecting the field by hand. Clearing takes effect on save,
		// and the server asks only for the current password to do it: a code
		// cannot be required from someone who has lost their authenticator.
		this.adminTOTP('');
		this.adminTOTPCode('');
	}

	saveAdminUserCommand() {
		if (!this.adminLogin().trim()) {
			this.adminLoginError(true);
			return false;
		}

		if (this.adminPasswordNew() !== this.adminPasswordNew2()) {
			this.adminPasswordNewError(true);
			return false;
		}

		this.saveError(false);
		this.saveSuccess(false);

		Remote.request('AdminPasswordUpdate', (iError, data) => {
			if (iError) {
				this.saveError(true);
			} else {
				this.adminPassword('');
				this.adminPasswordNew('');
				this.adminPasswordNew2('');

				this.saveSuccess(true);

				this.weakPassword(!!data.Result.Weak);
			}
		}, {
			Login: this.adminLogin(),
			Password: this.adminPassword(),
			newPassword: this.adminPasswordNew(),
			TOTP: this.adminTOTP(),
			// Proves the secret was scanned. The server refuses to switch two
			// factor on without it, so nobody locks themselves out by pressing
			// Generate and then saving something else.
			TOTPCode: this.adminTOTPCode()
		});

		return true;
	}

	onHide() {
		this.adminPassword('');
		this.adminPasswordNew('');
		this.adminPasswordNew2('');
	}
}
