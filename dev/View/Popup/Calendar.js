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
	},

	// RRULE weekday codes, indexed like Date.getDay()
	WEEKDAYS = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'],

	// The RRULE parts the repeat controls can show. A rule with any other part
	// is kept as it is and not sent back, so the server leaves it alone.
	EDITABLE_RULE_PARTS = ['FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYDAY', 'WKST'],

	// The local date of an editor value, "YYYY-MM-DD" or "YYYY-MM-DDTHH:mm"
	startDateOf = value => new Date((value || '').split('T')[0] + 'T00:00'),

	// Which of its weekday in the month a date is: 1 to 4, or -1 for a fifth,
	// which is the last one
	weekdayOrdinal = date => {
		const n = Math.ceil(date.getDate() / 7);
		return 4 < n ? -1 : n;
	},

	// "2TU" for the second Tuesday, "-1FR" for the last Friday
	monthlyByDay = date => weekdayOrdinal(date) + WEEKDAYS[date.getDay()];

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
			editError: '',

			// Repeat: '' for none, DAILY, WEEKLY, MONTHLY (on the day),
			// MONTHLY_BYDAY (on the nth weekday), YEARLY, or CUSTOM for a stored
			// rule these controls cannot show
			editRepeat: '',
			// For a series: edit the occurrence that was opened, or all of them.
			// The occurrence is named by its original start (RECURRENCE-ID).
			editScope: 'occurrence',
			editRecurrenceId: 0,
			editCanSplit: false,
			editInterval: 1,
			editEnds: 'never',
			editUntil: '',
			editCount: 10
		});

		this.editWeekdays = ko.observableArray();
		// Kept so a rule read with a week start writes it back
		this.editWkst = '';

		// The fields hold the occurrence's own values or the series', whichever
		// is being edited
		this.editScope.subscribe(scope => {
			// "This and following" starts from the occurrence, like "This occurrence"
			const values = 'series' === scope ? this.editSeriesValues : this.editOccurrenceValues;
			values && this.fillEditor(values);
			// "After N times" counts from the start of what is edited: from this
			// occurrence on, the ones before it are not part of the new series
			if (this.editSeriesCount && 'count' === this.editEnds()) {
				this.editCount('following' === scope && 1 < this.editSeriesIndex
					? Math.max(1, this.editSeriesCount - this.editSeriesIndex + 1)
					: this.editSeriesCount);
			}
		});

		// Weekly needs a day, and the start's own day is the one meant
		this.editRepeat.subscribe(repeat => {
			if ('WEEKLY' === repeat && !this.editWeekdays().length) {
				const start = startDateOf(this.editStart());
				isNaN(start) || this.editWeekdays([WEEKDAYS[start.getDay()]]);
			}
		});

		// Switching the inputs between date and datetime-local leaves the old
		// string in place, which the other input type cannot parse, so it falls
		// back to showing an empty placeholder and the date looks wiped
		this.editAllDay.subscribe(allDay => {
			this.editStart(this.reformatForAllDay(this.editStart(), allDay));
			this.editEnd(this.reformatForAllDay(this.editEnd(), allDay));
		});

		// Moving the start past the end drags the end along, rather than leaving
		// a form that only complains once it is submitted. An end at or after the
		// start is left alone, so a deliberate duration survives editing the start.
		//
		// Only when the two are the same shape. The all day subscribe above
		// rewrites them one after the other, and mid way through, a date only
		// start sits beside a date and time end; comparing those would clamp on
		// the strings rather than on the moment they represent. The pass finishes
		// consistently a line later, so skipping that instant costs nothing.
		this.editStart.subscribe(start => {
			const end = this.editEnd();
			if (start && end && start.includes('T') === end.includes('T') && end < start) {
				this.editEnd(start);
			}
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

			visibleCalendarUuids: () => this.calendars().filter(cal => cal.visible()).map(cal => cal.uuid),

			editRuleCustom: () => 'CUSTOM' === this.editRepeat(),

			// The monthly choices name the start's day, so they follow it
			repeatOptions: () => {
				const start = startDateOf(this.editStart()),
					valid = !isNaN(start),
					n = valid ? weekdayOrdinal(start) : 1,
					options = [
						{ id: '', name: i18n('CALENDAR/REPEAT_NONE') },
						{ id: 'DAILY', name: i18n('CALENDAR/REPEAT_DAILY') },
						{ id: 'WEEKLY', name: i18n('CALENDAR/REPEAT_WEEKLY') },
						{ id: 'MONTHLY', name: i18n('CALENDAR/REPEAT_MONTHLY_DAY', { DAY: valid ? start.getDate() : '' }) },
						{ id: 'MONTHLY_BYDAY', name: i18n('CALENDAR/REPEAT_MONTHLY_WEEKDAY', {
							NTH: i18n('CALENDAR/ORDINAL_' + (0 > n ? 'LAST' : n)),
							WEEKDAY: valid ? start.format({ weekday: 'long' }) : ''
						}) },
						{ id: 'YEARLY', name: i18n('CALENDAR/REPEAT_YEARLY') }
					];
				this.editRuleCustom() && options.push({ id: 'CUSTOM', name: i18n('CALENDAR/REPEAT_CUSTOM') });
				return options;
			},

			repeatUnit: () => {
				const repeat = this.editRepeat();
				return repeat && !this.editRuleCustom()
					? i18n('CALENDAR/REPEAT_UNIT_' + ('MONTHLY_BYDAY' === repeat ? 'MONTHLY' : repeat))
					: '';
			},

			// In the user's week order, from Settings > Calendar
			weekdayChoices: () => {
				const first = parseInt(SettingsUserStore.calendarFirstDay(), 10) || 0;
				return WEEKDAYS.map((code, i) => WEEKDAYS[(i + first) % 7]).map(code => ({
					code: code,
					// 4 January 2026 was a Sunday
					label: new Date(2026, 0, 4 + WEEKDAYS.indexOf(code)).format({ weekday: 'short' })
				}));
			},

			repeatEndOptions: () => [
				{ id: 'never', name: i18n('CALENDAR/REPEAT_ENDS_NEVER') },
				{ id: 'until', name: i18n('CALENDAR/REPEAT_ENDS_UNTIL') },
				{ id: 'count', name: i18n('CALENDAR/REPEAT_ENDS_COUNT') }
			]
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
						// Dragging an occurrence of a series moves that occurrence alone
						editable: !event.readOnly,
						backgroundColor: event.color || DEFAULT_EVENT_COLOR,
						textColor: readableOn(event.color || DEFAULT_EVENT_COLOR),
						extendedProps: {
							uid: event.uid,
							calendarUuid: event.calendarUuid,
							readOnly: !!event.readOnly,
							recurring: !!event.recurring,
							rrule: event.rrule || '',
							recurrenceId: event.recurrenceId || 0,
							seriesIndex: event.seriesIndex || 0,
							seriesStart: event.seriesStart || 0,
							seriesEnd: event.seriesEnd || 0,
							seriesTitle: event.seriesTitle || '',
							seriesLocation: event.seriesLocation || '',
							seriesDescription: event.seriesDescription || '',
							seriesAllDay: !!event.seriesAllDay,
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
		this.editRecurrenceId(0);
		this.editSeriesValues = this.editOccurrenceValues = null;
		this.editSeriesCount = 0;
		this.editScope('series');
		this.editCanSplit(false);
		this.setRepeat('');
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
		this.editOccurrenceValues = {
			summary: event.title || '',
			location: props.location || '',
			description: props.description || '',
			allDay: !!event.allDay,
			start: this.toInput(event.start, event.allDay),
			end: this.toInput(event.end, event.allDay)
		};
		// The series as a whole shows its own first occurrence, whichever one
		// was clicked
		const series = !!props.recurring && 0 < props.seriesStart,
			toWhen = value => props.seriesAllDay ? toAllDayDate(value) : toDate(value);
		this.editSeriesValues = series ? {
			summary: props.seriesTitle || '',
			location: props.seriesLocation || '',
			description: props.seriesDescription || '',
			allDay: !!props.seriesAllDay,
			start: this.toInput(toWhen(props.seriesStart), props.seriesAllDay),
			end: this.toInput(toWhen(props.seriesEnd), props.seriesAllDay)
		} : null;
		this.editRecurring(!!props.recurring);
		this.editRecurrenceId(series ? props.recurrenceId || 0 : 0);
		this.fillEditor(this.editOccurrenceValues);
		// valueHasMutated so the fields refill even when the scope is unchanged
		this.editScope(series ? 'occurrence' : 'series');
		this.editScope.valueHasMutated();
		// Read against the series' start, not the occurrence on screen: a
		// weekly rule without BYDAY means the series' weekday
		this.setRepeat(series ? props.rrule : '', series ? this.editSeriesValues.start : '');
		// "This and following" sends the rule only when it was changed: left as
		// it was, the server keeps it with a COUNT reduced by the occurrences
		// that stay in the old series
		this.editRuleSnapshot = this.editRuleCustom() ? '' : this.buildRrule();
		this.editSeriesIndex = series ? props.seriesIndex || 0 : 0;
		this.editSeriesCount = series && 'count' === this.editEnds() ? parseInt(this.editCount(), 10) : 0;
		// From the first occurrence, "this and following" is the whole series
		this.editCanSplit(series && props.recurrenceId !== props.seriesStart);
		this.editReadOnly(!!props.readOnly);
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
		const repeat = this.editRepeat();
		// One occurrence is saved without the rule, so the rule is not checked
		if (repeat && !this.editRuleCustom() && !this.editingOccurrence()) {
			if (!(1 <= parseInt(this.editInterval(), 10))) {
				return i18n('CALENDAR/REPEAT_ERROR_INTERVAL');
			}
			if ('WEEKLY' === repeat && !this.editWeekdays().length) {
				return i18n('CALENDAR/REPEAT_ERROR_NO_DAYS');
			}
			if ('count' === this.editEnds() && !(1 <= parseInt(this.editCount(), 10))) {
				return i18n('CALENDAR/REPEAT_ERROR_COUNT');
			}
			if ('until' === this.editEnds()
			 && (!this.editUntil() || startDateOf(this.editUntil()) < startDateOf(this.editStart()))) {
				return i18n('CALENDAR/REPEAT_ERROR_UNTIL');
			}
		}
		return '';
	}

	/**
	 * Drag and resize write straight through, so a failure has to put the event back
	 */
	persistMove(info) {
		const props = info.event.extendedProps || {},
			allDay = info.event.allDay;
		if (props.readOnly) {
			info.revert();
			return;
		}
		Remote.request('CalendarEventSave',
			// An occurrence keeps its RECURRENCE-ID, so the grid has to fetch
			// again rather than trust the moved copy it holds
			iError => {
				if (iError) {
					this.failed(getNotification(iError));
					info.revert();
				} else if (props.recurring) {
					this.ec?.refetchEvents();
				}
			},
			{
				Calendar: props.calendarUuid,
				Uid: props.uid,
				// An all-day boundary is a date, sent as UTC midnight like the editor does
				Start: allDay ? fromAllDayDate(info.event.start) : toStamp(info.event.start),
				End: allDay ? fromAllDayDate(info.event.end) : toStamp(info.event.end),
				AllDay: allDay ? 1 : 0,
				Timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
				// A drag of an occurrence moves that occurrence alone
				...(props.recurring && props.recurrenceId ? { RecurrenceId: props.recurrenceId } : {})
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
				AllDay: allDay ? 1 : 0,
				// For an event that has no zone yet and a calendar that names none
				Timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
				// One occurrence carries no rule; a custom rule is left out so the
				// server keeps it as stored
				...this.saveScopeParams()
			}
		);
	}

	fillEditor(values) {
		this.editSummary(values.summary);
		this.editLocation(values.location);
		this.editDescription(values.description);
		this.editAllDay(values.allDay);
		this.editStart(values.start);
		this.editEnd(values.end);
	}

	/**
	 * Fill the repeat controls from a stored RRULE, or mark it custom when they
	 * cannot show it.
	 */
	setRepeat(rule, startValue) {
		const start = startDateOf(startValue || this.editStart()),
			state = this.parseRrule(rule || '', isNaN(start) ? new Date() : start);
		this.editRepeat(state.repeat);
		this.editInterval(state.interval || 1);
		this.editWeekdays(state.weekdays || []);
		this.editEnds(state.ends || 'never');
		this.editUntil(state.until || '');
		this.editCount(state.count || 10);
		this.editWkst = state.wkst || '';
	}

	parseRrule(rule, start) {
		const custom = { repeat: 'CUSTOM' },
			parts = {};
		if (!rule) {
			return { repeat: '' };
		}
		for (const part of rule.split(';')) {
			const [key, value] = part.split('=');
			if (!key || undefined === value) {
				return custom;
			}
			parts[key.toUpperCase()] = value.toUpperCase();
		}
		if (Object.keys(parts).some(key => !EDITABLE_RULE_PARTS.includes(key)) || (parts.COUNT && parts.UNTIL)) {
			return custom;
		}
		const state = {
				repeat: parts.FREQ,
				interval: parseInt(parts.INTERVAL || '1', 10),
				weekdays: [],
				ends: 'never',
				wkst: parts.WKST || ''
			},
			byDay = parts.BYDAY ? parts.BYDAY.split(',') : [];
		if (!(1 <= state.interval)) {
			return custom;
		}
		switch (parts.FREQ) {
			case 'DAILY':
			case 'YEARLY':
				if (byDay.length) {
					return custom;
				}
				break;
			case 'WEEKLY':
				if (byDay.some(day => !WEEKDAYS.includes(day))) {
					return custom;
				}
				state.weekdays = byDay.length ? byDay : [WEEKDAYS[start.getDay()]];
				break;
			case 'MONTHLY':
				if (byDay.length) {
					// Only the nth weekday the start itself falls on
					if (1 < byDay.length || byDay[0] !== monthlyByDay(start)) {
						return custom;
					}
					state.repeat = 'MONTHLY_BYDAY';
				}
				break;
			default:
				return custom;
		}
		if (parts.COUNT) {
			state.ends = 'count';
			state.count = parseInt(parts.COUNT, 10);
			if (!(1 <= state.count)) {
				return custom;
			}
		} else if (parts.UNTIL) {
			const m = /^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})(Z?))?$/.exec(parts.UNTIL);
			if (!m) {
				return custom;
			}
			// A UTC moment is shown as the local date it falls on
			const until = m[4] && m[7]
				? new Date(Date.UTC(+m[1], m[2] - 1, +m[3], +m[4], +m[5], +m[6]))
				: new Date(+m[1], m[2] - 1, +m[3]);
			state.ends = 'until';
			state.until = this.toInput(until, true);
		}
		return state;
	}

	/**
	 * The RRULE for the repeat controls, '' for none.
	 */
	buildRrule() {
		const repeat = this.editRepeat();
		if (!repeat || this.editRuleCustom()) {
			return '';
		}
		const start = startDateOf(this.editStart()),
			interval = parseInt(this.editInterval(), 10),
			pad = value => String(value).padStart(2, '0');
		let rule = 'FREQ=' + ('MONTHLY_BYDAY' === repeat ? 'MONTHLY' : repeat);
		if (1 < interval) {
			rule += ';INTERVAL=' + interval;
		}
		if ('WEEKLY' === repeat) {
			rule += ';BYDAY=' + WEEKDAYS.filter(day => this.editWeekdays().includes(day)).join(',');
		} else if ('MONTHLY_BYDAY' === repeat) {
			rule += ';BYDAY=' + monthlyByDay(start);
		}
		if (this.editWkst) {
			rule += ';WKST=' + this.editWkst;
		}
		if ('count' === this.editEnds()) {
			rule += ';COUNT=' + parseInt(this.editCount(), 10);
		} else if ('until' === this.editEnds()) {
			if (this.editAllDay()) {
				rule += ';UNTIL=' + this.editUntil().replace(/-/g, '');
			} else {
				// RFC 5545 3.3.10: with a zoned start, UNTIL is in UTC. The end of
				// the chosen day, so an occurrence on it is still included.
				const until = new Date(this.editUntil() + 'T23:59:59');
				rule += ';UNTIL=' + until.getUTCFullYear() + pad(until.getUTCMonth() + 1) + pad(until.getUTCDate())
					+ 'T' + pad(until.getUTCHours()) + pad(until.getUTCMinutes()) + pad(until.getUTCSeconds()) + 'Z';
			}
		}
		return rule;
	}

	toggleWeekday(code) {
		if (!this.editReadOnly()) {
			this.editWeekdays().includes(code) ? this.editWeekdays.remove(code) : this.editWeekdays.push(code);
		}
	}

	/**
	 * Whether the editor works on one occurrence of a series
	 */
	editingOccurrence() {
		return this.editRecurring() && 'occurrence' === this.editScope() && 0 < this.editRecurrenceId();
	}

	editingFollowing() {
		return this.editRecurring() && 'following' === this.editScope() && 0 < this.editRecurrenceId();
	}

	/**
	 * What says which occurrences a save is for. One occurrence carries no
	 * rule; a custom rule is left out so the server keeps it as stored.
	 */
	saveScopeParams() {
		if (this.editingOccurrence()) {
			return { RecurrenceId: this.editRecurrenceId() };
		}
		const rule = this.editRuleCustom() ? null : this.buildRrule();
		if (this.editingFollowing()) {
			return Object.assign({ RecurrenceId: this.editRecurrenceId(), Scope: 'following' },
				null !== rule && rule !== this.editRuleSnapshot ? { Rrule: rule } : {});
		}
		return null === rule ? {} : { Rrule: rule };
	}

	deleteEventCommand() {
		const occurrence = this.editingOccurrence(),
			following = this.editingFollowing();
		AskPopupView.showModal([
			i18n(occurrence ? 'CALENDAR/CONFIRM_DELETE_OCCURRENCE'
				: (following ? 'CALENDAR/CONFIRM_DELETE_FOLLOWING'
				: (this.editRecurring() ? 'CALENDAR/CONFIRM_DELETE_SERIES' : 'CALENDAR/CONFIRM_DELETE_EVENT'))),
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
						Uid: this.editUid(),
						...(occurrence || following ? { RecurrenceId: this.editRecurrenceId() } : {}),
						...(following ? { Scope: 'following' } : {})
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
				this.ec || this.createCalendar();
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
