import { addObservablesTo, addComputablesTo } from 'External/ko';
import { i18n, translateTrigger } from 'Common/Translator';
import { pString } from 'Common/Utils';
import { SettingsCapa } from 'Common/Globals';

import { MessagelistUserStore } from 'Stores/User/Messagelist';

import { AbstractViewPopup } from 'Knoin/AbstractViews';
import { FolderUserStore, isAllowedKeyword } from 'Stores/User/Folder';

const isoDate = value => /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : '',
	// IMAP BEFORE excludes its own date, the "To" field includes it
	shiftDay = (value, days) => {
		const [y, m, d] = value.split('-');
		return new Date(Date.UTC(y, m - 1, +d + days)).toISOString().split('T')[0];
	};

export class AdvancedSearchPopupView extends AbstractViewPopup {
	constructor() {
		super('AdvancedSearch');

		addObservablesTo(this, {
			from: '',
			to: '',
			subject: '',
			text: '',
			keyword: '',
			repliedValue: -1,
			selectedDateValue: 0,
			dateFrom: '',
			dateTo: '',
			// SENTSINCE and friends match the Date header. INTERNALDATE, which the
			// plain keys match, is when the server took the message, and for an
			// imported mailbox that is the day of the import.
			sentDate: false,
			selectedTreeValue: '',

			hasAttachment: false,
			starred: false,
			unseen: false,
			// On by default because that is what a To search has always done: the
			// criteria have always covered Cc, without the form ever saying so.
			alsoCc: true
		});

		addComputablesTo(this, {
			// Either the server does it, or the admin enabled the folder by folder fallback
			showMultisearch: () => FolderUserStore.hasCapability('MULTISEARCH') || SettingsCapa('SubtreeSearch'),

			// Almost the same as MessageModel.tagOptions
			keywords: () => {
				const keywords = [{value:'',label:''}];
				FolderUserStore.currentFolder().optionalTags().forEach(value => {
					let lower = value.toLowerCase();
					keywords.push({
						value: value,
						label: i18n('MESSAGE_TAGS/'+lower, 0, lower)
					});
				});
				return keywords
			},

			showKeywords: () => FolderUserStore.currentFolder().permanentFlags().some(isAllowedKeyword),

			repliedOptions: () => {
				translateTrigger();
				return [
					{ id: -1, name: '' },
					{ id: 1, name: i18n('GLOBAL/YES') },
					{ id: 0, name: i18n('GLOBAL/NO') }
				];
			},

			selectedDates: () => {
				translateTrigger();
				// We should think about migrating all SEARCH/DATE_ to either SEARCH/SINCE_DATE or SEARCH/BEFORE_DATE
				// and adjust the prefix accordingly
				// let prefix_since = 'SEARCH/SINCE_DATE_';
				let prefix = 'SEARCH/SINCE_';
				let prefix_before = 'SEARCH/BEFORE_';
				return [
					{ id: 0, name: i18n('SEARCH/DATE_ALL') },
					{ id: 'range', name: i18n('SEARCH/DATE_RANGE') },
					{ id: 3, name: i18n(prefix + '3_DAYS') },
					{ id: 7, name: i18n(prefix + '7_DAYS') },
					{ id: 30, name: i18n(prefix + 'MONTH') },
					{ id: 90, name: i18n(prefix + '3_MONTHS') },
					{ id: 180, name: i18n(prefix + '6_MONTHS') },
					{ id: 365, name: i18n(prefix + 'YEAR') },
					{ id: -3, name: i18n(prefix_before + '3_DAYS') },
					{ id: -7, name: i18n(prefix_before + '7_DAYS') },
					{ id: -30, name: i18n(prefix_before + 'MONTH') },
					{ id: -90, name: i18n(prefix_before + '3_MONTHS') },
					{ id: -180, name: i18n(prefix_before + '6_MONTHS') },
					{ id: -365, name: i18n(prefix_before + 'YEAR') }
				];
			},

			selectedTree: () => {
				translateTrigger();
				let prefix = 'SEARCH/SUBFOLDERS_';
				return [
					{ id: '', name: i18n(prefix + 'NONE') },
					{ id: 'subtree-one', name: i18n(prefix + 'SUBTREE_ONE') },
					{ id: 'subtree', name: i18n(prefix + 'SUBTREE') }
				];
			}
		});
	}

	submitForm() {
		const search = this.buildSearchString();
		if (search) {
			MessagelistUserStore.mainSearch(search);
		}

		this.close();
	}

	buildSearchString() {
		const
			self = this,
			data = new FormData(),
			append = (key, value) => value.length && data.append(key, value);

		append('from', self.from().trim());
		append('to', self.to().trim());
		append('subject', self.subject().trim());
		append('text', self.text().trim());
		append('keyword', self.keyword());
		append('in', self.selectedTreeValue());
		const dateKey = self.sentDate() ? 'sent' : '';
		if ('range' === self.selectedDateValue()) {
			let from = self.dateFrom(), to = self.dateTo();
			if (from && to && from > to) {
				[from, to] = [to, from];
			}
			from && append(dateKey + 'since', from);
			to && append(dateKey + 'before', shiftDay(to, 1));
		}
		else if (0 < self.selectedDateValue()) {
			let d = new Date();
			d.setDate(d.getDate() - self.selectedDateValue());
			append(dateKey + 'since', d.toISOString().split('T')[0]);
		}
		else if (-1 > self.selectedDateValue()) {
			let d = new Date();
			d.setDate(d.getDate() + self.selectedDateValue());
			append(dateKey + 'before', d.toISOString().split('T')[0]);
		}

		let result = decodeURIComponent(new URLSearchParams(data).toString());

		// The exception is recorded, not the rule, so a search saved before this
		// existed keeps meaning what it did
		if (self.to().trim() && !self.alsoCc()) {
			result += '&to-only';
		}
		if (self.hasAttachment()) {
			result += '&attachment';
		}
		if (self.unseen()) {
			result += '&unseen';
		}
		if (self.starred()) {
			result += '&flagged';
		}
		if (1 == self.repliedValue()) {
			result += '&answered';
		}
		if (0 == self.repliedValue()) {
			result += '&unanswered';
		}

		return result.replace(/^&+/, '');
	}

	onShow(search) {
		const self = this,
			params = new URLSearchParams('?'+search);
		self.from(pString(params.get('from')));
		self.to(pString(params.get('to')));
		self.subject(pString(params.get('subject')));
		self.text(pString(params.get('text')));
		self.keyword(pString(params.get('keyword')));
		self.selectedTreeValue(pString(params.get('in')));
		// Any date comes back as a range: a preset was relative to the day it ran,
		// so "up to 1 week old" cannot be told apart from the dates it produced
		const dateKey = ['sentsince', 'sentbefore', 'senton'].some(key => params.has(key)) ? 'sent' : '',
			on = isoDate(pString(params.get(dateKey + 'on'))),
			before = isoDate(pString(params.get(dateKey + 'before')));
		self.dateFrom(on || isoDate(pString(params.get(dateKey + 'since'))));
		self.dateTo(on || (before && shiftDay(before, -1)));
		self.selectedDateValue(self.dateFrom() || self.dateTo() ? 'range' : 0);
		self.sentDate(!!dateKey);
		self.alsoCc(!params.has('to-only'));
		self.hasAttachment(params.has('attachment'));
		self.starred(params.has('flagged'));
		self.unseen(params.has('unseen'));
		if (params.has('answered')) {
			self.repliedValue(1);
		} else if (params.has('unanswered')) {
			self.repliedValue(0);
		}
	}
}
