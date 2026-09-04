import ko from 'ko';
import { SettingsGet } from 'Common/Globals';
import { staticLink } from 'Common/Links';
import { SettingsUserStore } from 'Stores/User/Settings';

/**
 * A native date input renders in the browser's own locale and offers no way to
 * change it: neither a lang attribute nor a pattern has any effect, and the
 * value it exposes is always ISO whatever it shows. So an interface set to one
 * language still displayed dates in the format of whatever the browser was
 * configured for. This replaces it with a picker the application can actually
 * configure.
 *
 * Fetched only when a date field is first used, so nobody reading mail pays for
 * a calendar widget they never open.
 */
const loadPicker = (() => {
	let promise = null;
	return () => promise || (promise = new Promise((resolve, reject) => {
		const jsUrl = SettingsGet('StaticLibsJs'),
			min = jsUrl.includes('/min/'),
			link = document.createElement('link');
		link.rel = 'stylesheet';
		link.href = staticLink('css/datepicker' + (min ? '.min' : '') + '.css');
		document.head.append(link);
		rl.loadScript(jsUrl.replace('/libs.', '/datepicker.')).then(resolve, reject);
	}));
})();

/**
 * The locale data shipped with the picker, for the interface language. Each one
 * carries its own dateFormat and firstDay, which is where "follow the language"
 * gets its answer from rather than a table maintained here.
 */
const localeFor = () => {
	const all = window.AirDatepickerLocales || {},
		lang = (document.documentElement.lang || 'en').toLowerCase();
	return all[lang] || all[lang.split('-')[0]] || all.en;
};

/**
 * The list for the settings screen. The first entry has to say what following
 * the language actually looks like, and it is built from Intl rather than from
 * the picker's locale data, because the settings screen is reachable long
 * before anything has opened a date field and fetched that bundle.
 */
export const dateFormatOptions = () => {
	const sample = new Date(2026, 8, 8),
		asLanguage = sample.toLocaleDateString(document.documentElement.lang || undefined);
	return [
		{ id: '', name: asLanguage },
		{ id: 'dd/MM/yyyy', name: '08/09/2026' },
		{ id: 'MM/dd/yyyy', name: '09/08/2026' },
		{ id: 'dd.MM.yyyy', name: '08.09.2026' },
		{ id: 'dd-MM-yyyy', name: '08-09-2026' },
		{ id: 'yyyy-MM-dd', name: '2026-09-08' }
	];
};

const pad = value => String(value).padStart(2, '0'),
	toISO = date => date
		? date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
		: '',
	// "YYYY-MM-DD" read as a local date. new Date() on that string reads UTC,
	// which lands on the previous day for anywhere west of Greenwich.
	fromISO = value => {
		const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(value || '');
		return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
	};

/**
 * value stays the ISO string the rest of the application already passes around,
 * so nothing downstream has to know the field looks different.
 */
ko.bindingHandlers.datePicker = {
	init: (element, fValueAccessor) => {
		const observable = fValueAccessor();
		element.type = 'text';
		element.autocomplete = 'off';
		// Read only on purpose. The field is filled by the picker, and the format
		// is now configurable, so parsing whatever someone types would mean
		// guessing which of 08/09 is the month. An editable box that silently
		// discarded what was typed would be worse than no typing at all.
		// keyboardNav below keeps it reachable without a mouse: focus opens the
		// picker and the arrow keys move through it.
		element.readOnly = true;

		let picker = null;
		const render = () => {
			const value = ko.unwrap(observable);
			if (picker) {
				const date = fromISO(value);
				date ? picker.selectDate(date, { silent: true }) : picker.clear({ silent: true });
			} else {
				element.value = value || '';
			}
		};

		loadPicker().then(() => {
			const locale = localeFor(),
				chosen = SettingsUserStore.dateFormat();
			picker = new window.AirDatepicker(element, {
				locale: locale,
				dateFormat: chosen || (locale && locale.dateFormat),
				// The reading week starts on whichever day the calendar settings
				// say, rather than on the locale's own idea of it
				firstDay: parseInt(SettingsUserStore.calendarFirstDay(), 10) || 0,
				autoClose: true,
				keyboardNav: true,
				isMobile: 500 > innerWidth,
				onSelect: ({ date }) => observable(toISO(date))
			});
			render();
		});

		const sub = ko.computed(render);
		// ko.addDisposeCallback, not ko.utils.domNodeDisposal: this is a trimmed
		// Knockout build with no ko.utils at all, and reaching for it threw while
		// the binding was being applied, which took the whole editor down with it.
		ko.addDisposeCallback(element, () => {
			sub.dispose();
			picker?.destroy();
		});
	}
};
