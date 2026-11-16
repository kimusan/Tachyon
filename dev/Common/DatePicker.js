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
 * The picker's locale for the interface language, built from Intl. The picker
 * ships names for a handful of languages only, so the other thirty-odd this
 * application is translated into showed an English calendar. Intl knows every
 * one of them, and its idea of the date format is the one "follow the
 * language" already shows on the settings screen.
 */
const localeFor = () => {
	const lang = document.documentElement.lang || undefined,
		upper = s => s.charAt(0).toLocaleUpperCase(lang) + s.slice(1),
		names = (options, date) => Array.from({ length: options.month ? 12 : 7 },
			(v, i) => upper(new Intl.DateTimeFormat(lang, options).format(date(i)))),
		// 2026-11-01 is a Sunday, the day the picker counts weeks from
		day = i => new Date(2026, 10, 1 + i),
		month = i => new Date(2026, i, 1),
		daysShort = names({ weekday: 'short' }, day),
		dateFormat = new Intl.DateTimeFormat(lang, { day: '2-digit', month: '2-digit', year: 'numeric' })
			.formatToParts(new Date(2026, 10, 1))
			.map(part => ({ day: 'dd', month: 'MM', year: 'yyyy' })[part.type] || part.value)
			.join('');
	return {
		days: names({ weekday: 'long' }, day),
		daysShort: daysShort,
		daysMin: [
			daysShort.map(name => name.replace(/\.$/, '').slice(0, 2)),
			names({ weekday: 'narrow' }, day),
			daysShort.map(name => name.replace(/\.$/, '').slice(0, 3))
		// Two letters tell the days apart in most languages, but not in all:
		// Arabic would show the article on every column, Latvian Pi twice
		].find(list => 7 === new Set(list).size) || daysShort,
		months: names({ month: 'long' }, month),
		monthsShort: names({ month: 'short' }, month).map(name => name.replace(/\.$/, '')),
		today: '',
		clear: '',
		dateFormat: dateFormat,
		timeFormat: 'HH:mm',
		firstDay: 0
	};
};

/**
 * The list for the settings screen. The first entry has to say what following
 * the language actually looks like, and it is built from Intl rather than from
 * the picker's locale data, because the settings screen is reachable long
 * before anything has opened a date field and fetched that bundle.
 */
export const dateFormatOptions = () => {
	const sample = new Date(2026, 9, 28),
		asLanguage = sample.toLocaleDateString(document.documentElement.lang || undefined);
	return [
		{ id: '', name: asLanguage },
		{ id: 'dd/MM/yyyy', name: '28/09/2026' },
		{ id: 'MM/dd/yyyy', name: '09/28/2026' },
		{ id: 'dd.MM.yyyy', name: '28.09.2026' },
		{ id: 'dd-MM-yyyy', name: '28-09-2026' },
		{ id: 'yyyy-MM-dd', name: '2026-09-28' }
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

		// A button, because the native input had one and its absence reads as a
		// dead field. It also gives the picker something to open from that is
		// unambiguously clickable.
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'b-date-open icon-calendar';
		button.tabIndex = -1;
		element.after(button);

		// The mobile layout dims the page with one overlay shared by every picker,
		// which the first picker creates in the library's container on body. With
		// a container of our own that one is never built, so creating the overlay
		// threw halfway through the first picker's constructor, before it listened
		// for focus: on a phone the first date field anywhere did nothing (#207).
		// Built here, and the overlay moved into this dialog before showing, since
		// on body it sits underneath the dialog's top layer and cannot be tapped.
		const container = element.closest('dialog'),
			adoptOverlay = () => {
				const overlay = document.querySelector('.air-datepicker-overlay');
				container && overlay && overlay.parentNode !== container && container.append(overlay);
			};
		element.addEventListener('focus', adoptOverlay);

		loadPicker().then(() => {
			const locale = localeFor(),
				chosen = SettingsUserStore.dateFormat(),
				AirDatepicker = window.AirDatepicker;
			document.getElementById(AirDatepicker.defaultGlobalContainerId)
				|| AirDatepicker.buildGlobalContainer(AirDatepicker.defaultGlobalContainerId);
			picker = new AirDatepicker(element, {
				locale: locale,
				dateFormat: chosen || (locale && locale.dateFormat),
				// Popups here are dialog elements opened with showModal, which puts
				// them in the browser's top layer. A picker left on document.body
				// is painted underneath that and cannot be clicked, so it looked
				// like the field itself was dead. Rendering inside the dialog keeps
				// it in the same layer.
				container: container || undefined,
				// The reading week starts on whichever day the calendar settings
				// say, rather than on the locale's own idea of it
				firstDay: parseInt(SettingsUserStore.calendarFirstDay(), 10) || 0,
				autoClose: true,
				keyboardNav: true,
				isMobile: 500 > innerWidth,
				onSelect: ({ date }) => observable(toISO(date))
			});
			// The icon goes through the field's focus, like a click on the field.
			// Calling show() directly opened a picker the field never knew about:
			// no blur ever closed it, so two could be open at once, and a click
			// outside left them standing. Keeping the focus on the field while the
			// icon is pressed lets a second press close the picker again.
			button.addEventListener('mousedown', event => event.preventDefault());
			button.addEventListener('click', () => {
				if (document.activeElement !== element) {
					element.focus();
				} else if (picker.visible) {
					picker.hide();
				} else {
					adoptOverlay();
					picker.show();
				}
			});
			// A picker still open when its dialog closed was open again, with the
			// dialog, the next time
			container?.addEventListener('close', () => picker.visible && picker.hide());
			render();
		});

		const sub = ko.computed(render);
		// ko.addDisposeCallback, not ko.utils.domNodeDisposal: this is a trimmed
		// Knockout build with no ko.utils at all, and reaching for it threw while
		// the binding was being applied, which took the whole editor down with it.
		ko.addDisposeCallback(element, () => {
			sub.dispose();
			button.remove();
			picker?.destroy();
		});
	}
};
