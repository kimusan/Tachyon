import ko from 'ko';

import { addObservablesTo, addComputablesTo } from 'External/ko';
import { SettingsGet } from 'Common/Globals';
import { staticLink } from 'Common/Links';
import { i18n, getNotification } from 'Common/Translator';

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
			editRecurring: false
		});

		addComputablesTo(this, {
			hasWritableCalendar: () => this.calendars().some(cal => !cal.readOnly),

			visibleCalendarUuids: () => this.calendars().filter(cal => cal.visible()).map(cal => cal.uuid)
		});

		decorateKoCommands(this, {
			saveEventCommand: self => !self.isSaving() && !self.editReadOnly() && self.editSummary().trim(),
			deleteEventCommand: self => !self.isSaving() && !self.editReadOnly() && self.editUid()
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
						start: toDate(event.start),
						end: toDate(event.end),
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
			this.ec?.refetchEvents();
		});
	}

	syncCalendars() {
		this.loading(true);
		Remote.request('CalendarSync', (iError) => {
			this.loading(false);
			iError ? this.failed(getNotification(iError)) : this.loadCalendars();
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

	createCalendar() {
		this.applyColorScheme();
		this.ec = EventCalendar.create(this.calendarEl, {
			view: this.currentView(),
			headerToolbar: { start: '', center: '', end: '' },
			height: '100%',
			firstDay: 1,
			nowIndicator: true,
			editable: true,
			selectable: this.hasWritableCalendar(),
			eventSources: [{ events: (info, ok, fail) => this.fetchEvents(info, ok, fail) }],
			datesSet: info => this.title(info.view.title),
			eventClick: info => this.openEditor(info.event),
			select: info => this.openNewEditor(info),
			eventDrop: info => this.persistMove(info),
			eventResize: info => this.persistMove(info)
		});
		this.title(this.ec.getView().title);
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

	openNewEditor(info) {
		const writable = this.calendars().find(cal => !cal.readOnly);
		if (!writable) {
			return;
		}
		this.editUid('');
		this.editCalendar(writable.uuid);
		this.editSummary('');
		this.editLocation('');
		this.editDescription('');
		this.editAllDay(!!info.allDay);
		this.editStart(this.toInput(info.start, info.allDay));
		this.editEnd(this.toInput(info.end, info.allDay));
		this.editReadOnly(false);
		this.editorVisible(true);
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
		this.editorVisible(true);
	}

	closeEditor() {
		this.editorVisible(false);
	}

	/**
	 * datetime-local and date inputs both want local time, not UTC
	 */
	toInput(date, allDay) {
		const pad = value => String(value).padStart(2, '0'),
			ymd = date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
		return allDay ? ymd : ymd + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
	}

	fromInput(value, allDay) {
		return toStamp(new Date(allDay ? value + 'T00:00' : value));
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
		this.isSaving(true);
		const allDay = this.editAllDay();
		Remote.request('CalendarEventSave',
			(iError) => {
				this.isSaving(false);
				if (iError) {
					this.failed(getNotification(iError));
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
					(iError) => {
						this.isSaving(false);
						if (iError) {
							this.failed(getNotification(iError));
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
	}

	onHide() {
		if (this.ec) {
			EventCalendar.destroy(this.ec);
			this.ec = null;
		}
		this.editorVisible(false);
		this.editRecurring(false);
		this.failed('');
		this.calendars([]);
	}
}
