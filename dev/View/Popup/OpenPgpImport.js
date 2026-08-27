import { addObservablesTo } from 'External/ko';
import { GnuPGUserStore } from 'Stores/User/GnuPG';
import { PgpUserStore } from 'Stores/User/Pgp';

import { AbstractViewPopup } from 'Knoin/AbstractViews';

import Remote from 'Remote/User/Fetch';
import { i18n } from 'Common/Translator';

export class OpenPgpImportPopupView extends AbstractViewPopup {
	constructor() {
		super('OpenPgpImport');

		addObservablesTo(this, {
			search: '',

			key: '',
			keyError: false,
			keyErrorMessage: '',

			saveGnuPG: true,
			saveServer: true,

			// Which keyserver is being tried, and which one answered
			searchStatus: '',
			searching: false
		});

		this.canGnuPG = GnuPGUserStore.isSupported();

		this.key.subscribe(() => {
			this.keyError(false);
			this.keyErrorMessage('');
		});
	}

	/**
	 * Walks the keyservers one at a time so the box underneath can name the one
	 * being tried and, at the end, the one the key came from. The server used to
	 * loop internally and answer once, which could only ever say "found" or
	 * "not found" after a silence as long as every host put together.
	 *
	 * keys.openpgp.org is asked by the browser first, as it was before: it is
	 * CORS enabled, so the key never has to travel through this server.
	 */
	async searchPGP() {
		const query = this.search().trim();
		if (!query || this.searching()) {
			return;
		}

		const found = (host, key) => {
			this.key(key);
			this.searchStatus(i18n('OPENPGP/SEARCH_FOUND_ON', { HOST: host }));
			this.searching(false);
		};

		this.searching(true);
		this.key('');

		const direct = 'https://keys.openpgp.org';
		this.searchStatus(i18n('OPENPGP/SEARCH_TRYING', { HOST: direct.replace('https://', '') }));
		try {
			const response = await fetch(
				`${direct}/pks/lookup?op=get&options=mr&search=${encodeURIComponent(query)}`,
				{ method: 'GET', mode: 'cors', cache: 'no-cache', redirect: 'error',
				  referrerPolicy: 'no-referrer', credentials: 'omit' }
			);
			if ('application/pgp-keys' == response.headers.get('Content-Type')) {
				found(direct.replace('https://', ''), await response.text());
				return;
			}
		} catch {
			// CORS, offline, or simply no key there. Fall through to the rest.
		}

		const hosts = await new Promise(resolve =>
			Remote.request('PgpKeyservers', (iError, oData) =>
				resolve((!iError && oData?.Result) || [])
			)
		);

		for (const host of hosts) {
			if (host === direct) {
				continue;
			}
			const label = host.replace(/^https?:\/\//, '');
			this.searchStatus(i18n('OPENPGP/SEARCH_TRYING', { HOST: label }));
			const hit = await new Promise(resolve =>
				Remote.request('PgpSearchKey', (iError, oData) =>
					resolve((!iError && oData?.Result?.key) ? oData.Result : null),
					{ query: query, host: host }
				)
			);
			if (hit) {
				found(label, hit.key);
				return;
			}
		}

		this.searchStatus(i18n('OPENPGP/SEARCH_NOT_FOUND', { QUERY: query }));
		this.searching(false);
	}

	submitForm() {
		let keyTrimmed = this.key().trim();

		if (/\n/.test(keyTrimmed)) {
			keyTrimmed = keyTrimmed.replace(/\r+/g, '').replace(/\n{2,}/g, '\n\n');
		}

		this.keyError(!keyTrimmed);
		this.keyErrorMessage('');

		if (keyTrimmed) {
			let match = null,
				count = 30,
				done = false;
			const GnuPG = this.saveGnuPG() && GnuPGUserStore.isSupported(),
				backup = this.saveServer(),
				// eslint-disable-next-line max-len
				reg = /[-]{3,6}BEGIN[\s]PGP[\s](PRIVATE|PUBLIC)[\s]KEY[\s]BLOCK[-]{3,6}[\s\S]+?[-]{3,6}END[\s]PGP[\s](PRIVATE|PUBLIC)[\s]KEY[\s]BLOCK[-]{3,6}/gi;

			do {
				match = reg.exec(keyTrimmed);
				if (match && 0 < count) {
					if (match[0] && match[1] && match[2] && match[1] === match[2]) {
						PgpUserStore.importKey(this.key(), GnuPG, backup);
					}
					--count;
					done = false;
				} else {
					done = true;
				}
			} while (!done);

			this.close();
		}
	}

	onShow(key) {
		this.key(key || '');
		this.keyError(false);
		this.keyErrorMessage('');
	}
}
