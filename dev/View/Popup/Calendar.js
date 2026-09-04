import ko from 'ko';

import { addObservablesTo, addComputablesTo } from 'External/ko';
import { SettingsGet } from 'Common/Globals';
import { LanguageStore } from 'Stores/Language';
import { staticLink } from 'Common/Links';
import { i18n, getNotification } from 'Common/Translator';
import { CalendarUserStore } from 'Stores/User/Calendar';
import { SettingsUserStore } from 'Stores/User/Settings';

import Remote from 'Remote/User/Fetch';

import { decorateKoCommands } from 'Knoin/Knoin';
import { AbstractViewPopup } from 'Knoin/AbstractViews';

import { AskPopupView } from 'View/Popup/Ask';

const
	// The component is ~130KB, so it is only fetched when the calendar is first opened
	loadAssets = (() => {
		let promise = null;
		return () => promise || (promise = new Promise((resolve, reject) => {
			const jsUrl = SettingsGet('StaticLibsJs'),
				min = jsUrl.includes('/min/'),
				link = document.createElement('link');
			link.rel = 'stylesheet';
			link.href = staticLink('css/calendar' + (min ? '.min' : '') + '.css');
			document.head.append(link);
			rl.loadScript(jsUrl.replace('/libs.', '/calendar.')).then(resolve, reject);
		}));
	})(),

	// @event-calendar wants seconds since epoch as Date objects
	toDate = value => new Date(value * 1000),
	toStamp = value => Math.floor(value.getTime() / 1000),

	/**
	 * An all-day boundary is a date, not an instant. Stored as UTC midnight, it
	 * became 02:00 local east of Greenwich, so the grid carried every imported
	 * all-day event into the following day: a holiday on the 8th drew across the
	 * 8th and the 9th.
	 *
	 * Snapped to the nearest UTC midnight first, because events created here
	 * before this fix stored local midnight as a UTC stamp, which is 22:00 on the
	 * previous day for UTC+2. Snapping puts those on the day they were meant to
	 * be, and leaves a value already at midnight alone.
	 */
	toAllDayDate = value => {
		const utc = new Date(Math.round(value / 86400) * 86400000);
		return new Date(utc.getUTCFullYear(), utc.getUTCMonth(), utc.getUTCDate());
	},
	fromAllDayDate = date => Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()) / 1000,

	/**
	 * Calendar colours come from the CalDAV server, so nothing guarantees a
	 * fixed label colour stays readable on them. Pick per event from relative
	 * luminance (WCAG 2.x) instead of hoping.
	 */
	DEFAULT_EVENT_COLOR = '#31708f',

	readableOn = color => {
		const m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec((color || '').trim());
		if (!m) {
			return '#fff';
		}
		let hex = m[1];
		if (3 === hex.length) {
			hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
		}
		const channel = offset => {
				const c = parseInt(hex.substr(offset, 2), 16) / 255;
				return 0.03928 >= c ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
			},
			luminance = 0.2126 * channel(0) + 0.7152 * channel(2) + 0.0722 * channel(4);
		// Contrast against white vs against black, whichever is further away
		return (1.05 / (luminance + 0.05)) >= ((luminance + 0.05) / 0.05) ? '#fff' : '#000';
	};

export class CalendarPopupView extends AbstractViewPopup {
	constructor() {
		super('Calendar');

		this.calendarEl = null;
		this.ec = null;

		this.calendars = ko.observableArray();

		addObservablesTo(this, {
			loading: false,
			failed: '',

			currentView: 'dayGridMonth',
			title: '',

			// The inline editor, shown over the grid
			editorVisible: false,
			isSaving: false,

			editUid: '',
			editCalendar: '',
			editSummary: '',
			editLocation: '',
			editDescription: '',
			editStart: '',
			editEnd: '',
			editAllDay: false,
			editReadOnly: false,
			editRecurring: false,
			editError: ''
		});

		// Switching the inputs between date and datetime-local leaves the old
		// string in place, which the other input type cannot parse, so it falls
		// back to showing an empty placeholder and the date looks wiped
		this.editAllDay.subscribe(allDay => {
			this.editStart(this.reformatForAllDay(this.editStart(), allDay));
			this.editEnd(this.reformatForAllDay(this.editEnd(), allDay));
		});

		addComputablesTo(this, {
			hasWritableCalendar: () => this.calendars().some(cal => !cal.readOnly),

			// Only these can take a new event, so they are the only sensible choices
			writableCalendars: () => this.calendars().filter(cal => !cal.readOnly),

			// An existing event cannot move between calendars yet, so its calendar
			// is shown as text rather than as a control that refuses to work
			editCalendarName: () => {
				const uuid = this.editCalendar();
				return this.calendars().find(cal => cal.uuid === uuid)?.name || '';
			},

			visibleCalendarUuids: () => this.calendars().filter(cal => cal.visible()).map(cal => cal.uuid)
		});

		// Half hours, offered as a pick list so the field is not a bare box. The
		// native time control had a spinner but took its format from the browser
		// locale, which is what this whole change is about.
		this.timeSuggestions = ko.computed(() => {
			const pad = v => String(v).padStart(2, '0'),
				out = [];
			for (let h = 0; 24 > h; ++h) {
				out.push(this.displayTime(pad(h) + ':00'), this.displayTime(pad(h) + ':30'));
			}
			return out;
		});

		// editStart and editEnd stay the canonical "YYYY-MM-DDTHH:mm", so save and
		// validation are untouched; these only split it for the two controls.
		['Start', 'End'].forEach(which => {
			const field = this['edit' + which];
			this['edit' + which + 'Date'] = ko.computed({
				read: () => (field() || '').split('T')[0] || '',
				write: value => {
					const time = (field() || '').split('T')[1];
					field(value + (time ? 'T' + time : ''));
				}
			});
			this['edit' + which + 'Time'] = ko.computed({
				read: () => this.displayTime((field() || '').split('T')[1] || ''),
				write: value => {
					const parsed = this.parseTime(value),
						date = (field() || '').split('T')[0];
					// An unreadable entry keeps the old time rather than blanking it
					field(date + 'T' + (parsed || (field() || '').split('T')[1] || '00:00'));
					// Redraw even when the text is unchanged, so a rejected entry
					// snaps back instead of sitting there looking accepted
					field.valueHasMutated();
				}
			});
		});

		decorateKoCommands(this, {
			newEventCommand: self => self.hasWritableCalendar(),
			/**
			 * Deliberately not gated on the fields being valid. A command that
			 * cannot execute swallows the click silently, which reads as a dead
			 * button; validateEditor() says what is wrong instead.
			 */
			saveEventCommand: self => !self.isSaving() && !self.editReadOnly(),
			deleteEventCommand: self => !self.isSaving() && !self.editReadOnly() && !!self.editUid()
		});
	}

	/**
	 * The grid asks for a window, so this is the only place events are fetched.
	 */
	fetchEvents(info, successCallback, failureCallback) {
		const uuids = this.visibleCalendarUuids();
		if (!uuids.length) {
			successCallback([]);
			return;
		}

		this.loading(true);
		Remote.request('CalendarEvents',
			(iError, data) => {
				this.loading(false);
				if (iError) {
					this.failed(getNotification(iError));
					failureCallback();
				} else {
					this.failed('');
					successCallback((data.Result?.Events || []).map(event => ({
						id: event.id,
						title: event.title,
						start: event.allDay ? toAllDayDate(event.start) : toDate(event.start),
						end: event.allDay ? toAllDayDate(event.end) : toDate(event.end),
						allDay: !!event.allDay,
						editable: !event.readOnly && !event.recurring,
						backgroundColor: event.color || DEFAULT_EVENT_COLOR,
						textColor: readableOn(event.color || DEFAULT_EVENT_COLOR),
						extendedProps: {
							uid: event.uid,
							calendarUuid: event.calendarUuid,
							readOnly: !!event.readOnly,
							recurring: !!event.recurring,
							location: event.location || '',
							description: event.description || ''
						}
					})));
				}
			}, {
				Start: toStamp(info.start),
				End: toStamp(info.end),
				Calendars: uuids.join(',')
			}
		);
	}

	loadCalendars() {
		Remote.request('Calendars', (iError, data) => {
			if (iError) {
				this.failed(getNotification(iError));
				return;
			}
			this.calendars((data.Result?.Calendars || []).map(cal => ({
				uuid: cal.uuid,
				name: cal.name,
				color: cal.color || '',
				readOnly: !!cal.readOnly,
				visible: ko.observable(true)
			})));
			this.calendars().forEach(cal =>
				cal.visible.subscribe(() => this.ec?.refetchEvents())
			);
			// The grid is created before this response arrives, so the flag that
			// enables drag to create has to be set again now one is known
			this.ec?.setOption('selectable', this.hasWritableCalendar());
			this.ec?.refetchEvents();
		});
	}

	syncCalendars() {
		this.loading(true);
		Remote.request('CalendarSync', (iError, oData) => {
			this.loading(false);
			if (iError) {
				this.failed(getNotification(iError));
				return;
			}
			// A sync that could not store some events is not a failure, but it is
			// not a clean run either, and it used to be indistinguishable from one
			const skipped = oData?.Result?.Skipped || 0;
			this.failed(skipped ? i18n('CALENDAR/SYNC_SKIPPED', { COUNT: skipped }) : '');
			this.loadCalendars();
		});
	}

	/**
	 * .ec-dark and .ec-auto-dark are the component's own way of switching its
	 * greys and color-scheme. Drive them from the same attribute the theme
	 * switcher sets, so scrollbars and form controls inside the grid match.
	 */
	applyColorScheme() {
		const mode = document.documentElement.getAttribute('data-color-scheme'),
			list = this.calendarEl.classList;
		list.toggle('ec-dark', 'dark' === mode);
		// No explicit choice means follow the system, which is what ec-auto-dark does
		list.toggle('ec-auto-dark', !mode);
	}

	/**
	 * The component formats its own times, so it has to be told the same hour
	 * cycle the rest of the UI uses rather than falling back to the locale's.
	 */
	timeFormatOptions() {
		const cycle = LanguageStore.hourCycle(),
			options = { hour: 'numeric', minute: '2-digit' };
		if (cycle) {
			options.hourCycle = cycle;
			// hour12 otherwise wins over hourCycle in several engines
			options.hour12 = 'h11' === cycle || 'h12' === cycle;
		}
		return options;
	}

	createCalendar() {
		this.applyColorScheme();
		const el = document.documentElement;
		this.ec = EventCalendar.create(this.calendarEl, {
			locale: el.dataset.dateLang || el.lang || undefined,
			eventTimeFormat: this.timeFormatOptions(),
			slotLabelFormat: this.timeFormatOptions(),
			view: this.currentView(),
			headerToolbar: { start: '', center: '', end: '' },
			height: '100%',
			// Both are the user's, from Settings > Calendar. Monday and no week
			// numbers is what the grid did before they were configurable.
			firstDay: SettingsUserStore.calendarFirstDay(),
			weekNumbers: !!SettingsUserStore.calendarWeekNumbers(),
			nowIndicator: true,
			editable: true,
			selectable: this.hasWritableCalendar(),
			eventSources: [{ events: (info, ok, fail) => this.fetchEvents(info, ok, fail) }],
			datesSet: info => this.title(info.view.title),
			eventClick: info => this.openEditor(info.event),
			select: info => this.openNewEditor(info.start, info.end, info.allDay),
			eventDrop: info => this.persistMove(info),
			eventResize: info => this.persistMove(info)
		});
		this.title(this.ec.getView().title);

		// setOption rather than rebuilding, so changing either in settings while
		// the calendar is open redraws the grid in place
		this.calendarOptionSubs?.forEach(sub => sub.dispose());
		this.calendarOptionSubs = [
			SettingsUserStore.calendarFirstDay.subscribe(v =>
				this.ec?.setOption('firstDay', parseInt(v, 10) || 0)),
			SettingsUserStore.calendarWeekNumbers.subscribe(v =>
				this.ec?.setOption('weekNumbers', !!v))
		];
	}

	setView(view) {
		this.currentView(view);
		this.ec?.setOption('view', view);
	}

	navigate(direction) {
		if (!this.ec) {
			return;
		}
		direction ? this.ec.next() : this.ec.prev();
	}

	today() {
		this.ec?.setOption('date', new Date());
	}

	openNewEditor(start, end, allDay) {
		const writable = this.calendars().find(cal => !cal.readOnly);
		if (!writable) {
			return;
		}
		this.editUid('');
		this.editCalendar(writable.uuid);
		this.editSummary('');
		this.editLocation('');
		this.editDescription('');
		this.editAllDay(!!allDay);
		this.editStart(this.toInput(start, allDay));
		this.editEnd(this.toInput(end, allDay));
		this.editRecurring(false);
		this.editReadOnly(false);
		this.editError('');
		this.editorVisible(true);
	}

	/**
	 * The toolbar route, for when there is nothing to drag on. Starts at the next
	 * whole hour so the common case needs no editing of the times.
	 */
	newEventCommand() {
		const start = new Date();
		start.setMinutes(0, 0, 0);
		start.setHours(start.getHours() + 1);
		this.openNewEditor(start, new Date(start.getTime() + 3600000), false);
	}

	openEditor(event) {
		const props = event.extendedProps || {};
		this.editUid(props.uid || '');
		this.editCalendar(props.calendarUuid || '');
		this.editSummary(event.title || '');
		this.editLocation(props.location || '');
		this.editDescription(props.description || '');
		this.editAllDay(!!event.allDay);
		this.editStart(this.toInput(event.start, event.allDay));
		this.editEnd(this.toInput(event.end, event.allDay));
		this.editRecurring(!!props.recurring);
		// Saving rebuilds the VEVENT from the fields below, which carry no RRULE,
		// so editing a series here would quietly turn it into a single event
		this.editReadOnly(!!props.readOnly || !!props.recurring);
		this.editError('');
		this.editorVisible(true);
	}

	closeEditor() {
		this.editorVisible(false);
	}

	/**
	 * datetime-local and date inputs both want local time, not UTC
	 */
	/**
	 * A datalist filters its options against what the field already contains, and
	 * this field always contains a time, so the list could only ever offer the one
	 * value already in it. Emptying the box on focus lets the whole list show;
	 * typing then filters it as usual, and leaving without choosing anything falls
	 * through to the write handler below, which keeps the previous time when it
	 * cannot read what it was given.
	 */
	openTimeList(vm, event) {
		event.target.value = '';
	}

	/**
	 * A time typed by hand, read generously. Accepts 9, 930, 9:3, 9.30, 09:30,
	 * "9:30 pm" and "21:30", and returns "HH:mm" in 24 hour form or '' if it
	 * cannot make sense of it.
	 */
	parseTime(text) {
		const raw = String(text || '').trim().toLowerCase();
		if (!raw) {
			return '';
		}
		const pm = /p\.?m\.?$/.test(raw),
			am = /a\.?m\.?$/.test(raw),
			digits = raw.replace(/[^0-9]/g, '');
		let hour, minute;
		if (raw.includes(':') || raw.includes('.')) {
			const parts = raw.split(/[:.]/);
			hour = parseInt(parts[0], 10);
			minute = parseInt(parts[1], 10) || 0;
		} else if (3 > digits.length) {
			hour = parseInt(digits, 10);
			minute = 0;
		} else {
			// 930 and 0930 both mean half past nine
			hour = parseInt(digits.slice(0, digits.length - 2), 10);
			minute = parseInt(digits.slice(-2), 10);
		}
		if (!isFinite(hour) || !isFinite(minute) || 59 < minute) {
			return '';
		}
		if (pm && 12 > hour) {
			hour += 12;
		} else if (am && 12 === hour) {
			hour = 0;
		}
		if (0 > hour || 23 < hour) {
			return '';
		}
		const pad = v => String(v).padStart(2, '0');
		return pad(hour) + ':' + pad(minute);
	}

	/**
	 * "HH:mm" shown the way the rest of the interface shows times.
	 *
	 * This exists because a native datetime-local or time input picks its format
	 * from the browser's locale and ignores both the document language and this
	 * application's own setting, so an install set to 24 hour still rendered
	 * AM/PM. Fixing the grid's formats did nothing for the editor for that
	 * reason. The control is ours now, so the setting is actually obeyed.
	 */
	displayTime(value) {
		if (!value) {
			return '';
		}
		const [hour, minute] = value.split(':').map(Number),
			date = new Date(2000, 0, 1, hour, minute);
		return date.toLocaleTimeString(
			LanguageStore.hourCycle() ? undefined : navigator.language,
			this.timeFormatOptions()
		);
	}

	toInput(date, allDay) {
		const pad = value => String(value).padStart(2, '0'),
			ymd = date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
		return allDay ? ymd : ymd + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
	}

	fromInput(value, allDay) {
		// UTC midnight for an all-day date, which is what iCalendar means by a
		// DATE value and what every other client writes. Storing local midnight
		// here is what made events created in Tachyon disagree with imported ones.
		return allDay
			? fromAllDayDate(new Date(value + 'T00:00'))
			: toStamp(new Date(value));
	}

	/**
	 * date wants YYYY-MM-DD, datetime-local wants YYYY-MM-DDTHH:MM
	 */
	reformatForAllDay(value, allDay) {
		if (!value) {
			return value;
		}
		if (allDay) {
			return value.split('T')[0];
		}
		return value.includes('T') ? value : value + 'T09:00';
	}

	/**
	 * @returns {string} empty when the form can be sent
	 */
	validateEditor() {
		const allDay = this.editAllDay(),
			start = new Date(allDay ? this.editStart() + 'T00:00' : this.editStart()),
			end = new Date(allDay ? this.editEnd() + 'T00:00' : this.editEnd());
		if (!this.editSummary().trim()) {
			return i18n('CALENDAR/ERROR_NO_TITLE');
		}
		if (isNaN(start)) {
			return i18n('CALENDAR/ERROR_BAD_START');
		}
		if (isNaN(end)) {
			return i18n('CALENDAR/ERROR_BAD_END');
		}
		if (end < start) {
			return i18n('CALENDAR/ERROR_END_BEFORE_START');
		}
		if (!this.editCalendar()) {
			return i18n('CALENDAR/ERROR_NO_CALENDAR');
		}
		return '';
	}

	/**
	 * Drag and resize write straight through, so a failure has to put the event back
	 */
	persistMove(info) {
		const props = info.event.extendedProps || {};
		if (props.readOnly || props.recurring) {
			info.revert();
			return;
		}
		Remote.request('CalendarEventSave',
			iError => iError && (this.failed(getNotification(iError)), info.revert()),
			{
				Calendar: props.calendarUuid,
				Uid: props.uid,
				Summary: info.event.title,
				Start: toStamp(info.event.start),
				End: toStamp(info.event.end),
				AllDay: info.event.allDay ? 1 : 0
			}
		);
	}

	saveEventCommand() {
		const invalid = this.validateEditor();
		if (invalid) {
			this.editError(invalid);
			return;
		}
		this.editError('');
		this.isSaving(true);
		const allDay = this.editAllDay();
		Remote.request('CalendarEventSave',
			(iError, data) => {
				this.isSaving(false);
				if (iError) {
					this.editError(data?.messageAdditional || data?.message || getNotification(iError));
				} else {
					this.editorVisible(false);
					this.ec?.refetchEvents();
				}
			}, {
				Calendar: this.editCalendar(),
				Uid: this.editUid(),
				Summary: this.editSummary().trim(),
				Location: this.editLocation().trim(),
				Description: this.editDescription().trim(),
				Start: this.fromInput(this.editStart(), allDay),
				End: this.fromInput(this.editEnd(), allDay),
				AllDay: allDay ? 1 : 0
			}
		);
	}

	deleteEventCommand() {
		AskPopupView.showModal([
			i18n('CALENDAR/CONFIRM_DELETE_EVENT'),
			() => {
				this.isSaving(true);
				Remote.request('CalendarEventDelete',
					(iError, data) => {
						this.isSaving(false);
						if (iError) {
							this.editError(data?.messageAdditional || data?.message || getNotification(iError));
						} else {
							this.editorVisible(false);
							this.ec?.refetchEvents();
						}
					}, {
						Calendar: this.editCalendar(),
						Uid: this.editUid()
					}
				);
			}
		]);
	}

	onBuild(dom) {
		this.calendarEl = dom.querySelector('.b-calendar-grid');
	}

	onShow() {
		loadAssets().then(
			() => {
				this.ec ? this.applyColorScheme() : this.createCalendar();
				this.loadCalendars();
			},
			() => this.failed(i18n('CALENDAR/ERROR_LOAD_COMPONENT'))
		);
		// Redraw when a background sync brings something in, rather than leaving
		// the grid showing what the database held when it was opened
		this.syncedSubscription = this.syncedSubscription
			|| CalendarUserStore.synced.subscribe(() => this.modalVisible() && this.loadCalendars());
	}

	onHide() {
		// Dropped with the calendar they were driving. createCalendar runs again
		// on every open, so without this each cycle leaves another subscriber
		// holding a setOption call against a destroyed instance.
		this.calendarOptionSubs?.forEach(sub => sub.dispose());
		this.calendarOptionSubs = null;
		if (this.ec) {
			EventCalendar.destroy(this.ec);
			this.ec = null;
		}
		this.editorVisible(false);
		this.editRecurring(false);
		this.editError('');
		this.failed('');
		this.calendars([]);
	}
}
