import { AbstractModel } from 'Knoin/AbstractModel';
import { addObservablesTo, addComputablesTo } from 'External/ko';

import { JCard } from 'DAV/JCard';
//import { VCardProperty } from 'DAV/VCardProperty';

const nProps = [
	'surName',
	'givenName',
	'middleName',
	'namePrefix',
	'nameSuffix'
];

/*
const propertyMap = [
	// vCard 2.1 properties and up
	'N' => 'Text',
	'FN' => 'FlatText',
	'PHOTO' => 'Binary',
	'BDAY' => 'DateAndOrTime',
	'ADR' => 'Text',
	'TEL' => 'FlatText',
	'EMAIL' => 'FlatText',
	'GEO' => 'FlatText',
	'TITLE' => 'FlatText',
	'ROLE' => 'FlatText',
	'LOGO' => 'Binary',
	'ORG' => 'Text',
	'NOTE' => 'FlatText',
	'REV' => 'TimeStamp',
	'SOUND' => 'FlatText',
	'URL' => 'Uri',
	'UID' => 'FlatText',
	'VERSION' => 'FlatText',
	'KEY' => 'FlatText', // <uri>data:application/pgp-keys;base64,AZaz09==</uri>
	'TZ' => 'Text',

	// vCard 3.0 properties
	'CATEGORIES' => 'Text',
	'SORT-STRING' => 'FlatText',
	'PRODID' => 'FlatText',
	'NICKNAME' => 'Text',

	// rfc2739 properties
	'FBURL' => 'Uri',
	'CAPURI' => 'Uri',
	'CALURI' => 'Uri',
	'CALADRURI' => 'Uri',

	// rfc4770 properties
	'IMPP' => 'Uri',

	// vCard 4.0 properties
	'SOURCE' => 'Uri',
	'XML' => 'FlatText',
	'ANNIVERSARY' => 'DateAndOrTime',
	'CLIENTPIDMAP' => 'Text',
	'LANG' => 'LanguageTag',
	'GENDER' => 'Text',
	'KIND' => 'FlatText',
	'MEMBER' => 'Uri',
	'RELATED' => 'Uri',

	// rfc6474 properties
	'BIRTHPLACE' => 'FlatText',
	'DEATHPLACE' => 'FlatText',
	'DEATHDATE' => 'DateAndOrTime',

	// rfc6715 properties
	'EXPERTISE' => 'FlatText',
	'HOBBY' => 'FlatText',
	'INTEREST' => 'FlatText',
	'ORG-DIRECTORY' => 'FlatText
];
*/

export class ContactModel extends AbstractModel {
	constructor() {
		super();

		this.jCard = ['vcard',[]];

		addObservablesTo(this, {
			// Also used by Selector
			focused: false,
			selected: false,
			checked: false,
			// One address per contact. This is never persisted, so it resets on
			// every load, and defaulting it to true meant composing to anyone
			// with several addresses silently added all of them.
			sendToAll: false,

			deleted: false,
			readOnly: false,
			// true once the CardDAV server has an etag for this contact
			synced: false,

			id: 0,
			givenName:  '', // FirstName
			surName:    '', // LastName
			middleName: '', // MiddleName
			namePrefix: '', // NamePrefix
			nameSuffix: '',  // NameSuffix
			nickname: null,
			note: null,
			bday: null,
			photo: null,

			// Business
			org: '',
			department: '',
			title: '',

			// Crypto
			encryptpref: '',
			signpref: ''
		});
//		this.email = koArrayWithDestroy();
		this.email      = ko.observableArray();
		this.tel        = ko.observableArray();
		this.url        = ko.observableArray();
		this.impp       = ko.observableArray();
		this.adr        = ko.observableArray();
		this.categories = ko.observableArray();

		addComputablesTo(this, {
			fullName: () => [this.namePrefix(), this.givenName(), this.middleName(), this.surName()].join(' ').trim(),

			display: () => {
				let a = this.fullName(),
					b = this.email()[0]?.value(),
					c = this.nickname();
				return a || b || c;
			}
/*
			fullName: {
				read: () => this.givenName() + " " + this.surName(),
				write: value => {
					this.jCard.set('fn', value/*, params, group* /)
				}
			}
*/
		});
	}

	/**
	 * @static
	 * @param {jCard} json
	 * @returns {?ContactModel}
	 */
	static reviveFromJson(json) {
		const contact = super.reviveFromJson(json);
		if (contact) {
			let jCard = new JCard(json.jCard),
				props = jCard.getOne('n')?.value;
			props && props.forEach((value, index) =>
				value && contact[nProps[index]](value)
			);

			['nickname', 'note', 'title', 'bday'].forEach(field => {
				props = jCard.getOne(field);
				// A date property can arrive as the parts array, and a partial
				// date such as Apple's unknown-year birthday has empty leading
				// parts, so join rather than assume a plain string
				props && contact[field](Array.isArray(props.value)
					? props.value.filter(v => v).join('-')
					: props.value);
			});

			if ((props = jCard.getOne('org')?.value)) {
				contact.org(props[0]);
				contact.department(props[1] || '');
			}

			['email', 'tel', 'url', 'impp'].forEach(field => {
				props = jCard.get(field);
				props && props.forEach(prop => {
					contact[field].push({
						value: ko.observable(prop.value)
//						type: prop.params.type
					});
				});
			});

			props = jCard.get('adr');
			props && props.forEach(prop => {
				contact.adr.push({
					street: ko.observable(prop.value[2]),
					street_ext: ko.observable(prop.value[1]),
					locality: ko.observable(prop.value[3]),
					region: ko.observable(prop.value[4]),
					postcode: ko.observable(prop.value[5]),
					pobox: ko.observable(prop.value[0]),
					country: ko.observable(prop.value[6]),
					preferred: ko.observable(prop.params.pref),
					// vCard writes TYPE=HOME, and a card may carry more than one.
					// Kept as the lower case text the editor shows, rather than
					// forced into a fixed list a foreign server never agreed to.
					type: ko.observable(
						(Array.isArray(prop.params.type) ? prop.params.type[0] : prop.params.type || '')
							.toString().toLowerCase()
					)
				});
			});

			props = jCard.getOne('categories');
			if (props?.value) {
				(Array.isArray(props.value) ? props.value : [props.value])
					.filter(Boolean)
					.forEach(cat => contact.categories.push({ value: ko.observable(cat) }));
			}

			// A data: URI in vCard 4.0. A 3.0 card carries the base64 and a TYPE
			// instead, but the server converts to 4.0 before we ever see it.
			props = jCard.getOne('photo');
			props?.value && contact.photo(props.value);

			props = jCard.getOne('x-crypto');
			contact.signpref(props?.params.signpref || 'Ask');
			contact.encryptpref(props?.params.encryptpref || 'Ask');
//			contact.encryptpref(props?.params.allowed || 'PGP/INLINE,PGP/MIME,S/MIME,S/MIMEOpaque');

			contact.jCard = json.jCard;
		}
		return contact;
	}

	addEmail() {
		// home, work
		this.email.push({
			value: ko.observable('')
//			type: prop.params.type
		});

	}

	addTel() {
		// home, work, text, voice, fax, cell, video, pager, textphone, iana-token, x-name
		this.tel.push({
			value: ko.observable('')
//			type: prop.params.type
		});
	}

	addUrl() {
		// home, work
		this.url.push({
			value: ko.observable('')
//			type: prop.params.type
		});
	}

	addNickname() {
		// home, work
		this.nickname() || this.nickname('');
	}

	addNote() {
		this.note() || this.note('');
	}

	/**
	 * A tag is atomic: added whole, removed whole. Refuses a blank or one the
	 * contact already carries, comparing case insensitively because that is how
	 * the server matches them. Returns whether it was added.
	 */
	addCategoryValue(name) {
		name = (name || '').trim();
		const key = name.toLowerCase();
		if (!name || this.categories().some(c => c.value().trim().toLowerCase() === key)) {
			return false;
		}
		this.categories.push({ value: ko.observable(name) });
		return true;
	}

	addImpp() {
		this.impp.push({ value: ko.observable('') });
	}

	addBday() {
		this.bday() || this.bday('');
	}

	addAdr() {
		this.adr.push({
			street: ko.observable(''),
			street_ext: ko.observable(''),
			locality: ko.observable(''),
			region: ko.observable(''),
			postcode: ko.observable(''),
			pobox: ko.observable(''),
			country: ko.observable(''),
			preferred: ko.observable(''),
			type: ko.observable('home')
		});
	}

	removeEmail(item)    { this.email.remove(item); }
	removeTel(item)      { this.tel.remove(item); }
	removeUrl(item)      { this.url.remove(item); }
	removeImpp(item)     { this.impp.remove(item); }
	removeAdr(item)      { this.adr.remove(item); }
	removeBday()         { this.bday(null); }
	removePhoto()        { this.photo(null); }
	removeCategory(item) { this.categories.remove(item); }
	removeNickname()     { this.nickname(null); }
	removeNote()         { this.note(null); }

	hasChanges()
	{
		return this.email().filter(v => v.length).length && this.toJSON().jCard != JSON.stringify(this.jCard);
	}

	toJSON()
	{
		let jCard = new JCard(this.jCard);
		jCard.set('n', [
			this.surName(),
			this.givenName(),
			this.middleName(),
			this.namePrefix(),
			this.nameSuffix()
		]/*, params, group*/);
		jCard.parseFullName({set:true});

		['nickname', 'note', 'title', 'bday', 'photo'].forEach(field =>
			this[field]() ? jCard.set(field, this[field]()/*, params, group*/) : jCard.remove(field)
		);

		if (this.org()) {
			let org = [this.org()];
			if (this.department()) {
				org.push(this.department());
			}
			let prop = jCard.getOne('org');
			prop ? prop.value = org : jCard.set('org', org);
		} else {
			jCard.remove('');
		}

		['email', 'tel', 'url', 'impp'].forEach(field => {
			let values = this[field].map(item => item.value());
			jCard.get(field).forEach(prop => {
				let i = values.indexOf(prop.value);
				if (0 > i || !prop.value) {
					jCard.remove(prop);
				} else {
					values.splice(i, 1);
				}
			});
			values.forEach(value => value && jCard.add(field, value));
		});

		jCard.set('x-crypto', '', {
			allowed: 'PGP/INLINE,PGP/MIME,S/MIME,S/MIMEOpaque',
			signpref: this.signpref(),
			encryptpref: this.encryptpref()
		}, 'x-crypto');

		// Addresses were parsed but never written back, so the editor could not
		// change one. The component order is fixed by RFC 6350: post office box,
		// extended address, street, locality, region, postal code, country.
		jCard.remove('adr');
		this.adr().forEach(item => {
			const parts = [
				item.pobox(), item.street_ext(), item.street(), item.locality(),
				item.region(), item.postcode(), item.country()
			];
			if (parts.some(part => (part || '').trim())) {
				const params = {};
				item.type() && (params.type = item.type());
				item.preferred() && (params.pref = item.preferred());
				jCard.add('adr', parts.map(part => part || ''), params);
			}
		});

		// Deduplicated case insensitively, matching how the server compares them.
		// Picking from the suggestion list makes adding the same group twice easy,
		// and CATEGORIES:friends,friends is not something to sync to a server.
		const seen = new Set(),
			cats = this.categories.map(c => c.value().trim()).filter(cat => {
				const key = cat.toLowerCase();
				return cat && !seen.has(key) && seen.add(key);
			});
		cats.length ? jCard.set('categories', cats) : jCard.remove('categories');

		// Done by server
//		jCard.set('rev', '2022-05-21T10:59:52Z')

		return {
			uid: this.id,
			jCard: JSON.stringify(jCard)
		};
	}

	/**
	 * @return string
	 */
	lineAsCss() {
		return (this.selected() ? 'selected' : '')
			+ (this.deleted() ? ' deleted' : '')
			+ (this.checked() ? ' checked' : '')
			+ (this.focused() ? ' focused' : '');
	}

	// email is an observableArray, so .length was the function's own arity and
	// this always answered false
	sendToAllDisplayStatus() {
		return 1 < this.email().length;
	}

}
