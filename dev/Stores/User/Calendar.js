import ko from 'ko';
import { SettingsGet } from 'Common/Globals';
import Remote from 'Remote/User/Fetch';

export const CalendarUserStore = {};

CalendarUserStore.syncing = ko.observable(false).extend({ debounce: 200 });

/**
 * Fires whenever a background sync actually brought something in, so an open
 * calendar can redraw without polling the database on a timer of its own.
 */
CalendarUserStore.synced = ko.observable(0);

/**
 * A CalDAV sync in the background. The server has always been able to do this
 * on demand; nothing was asking it to, so a change made on the server stayed
 * invisible until someone pressed Sync by hand.
 */
CalendarUserStore.sync = () => {
	if (!CalendarUserStore.syncing()) {
		CalendarUserStore.syncing(true);
		Remote.request('CalendarSync', iError => {
			CalendarUserStore.syncing(false);
			iError || CalendarUserStore.synced(Date.now());
		});
	}
};

CalendarUserStore.init = () => {
	const config = SettingsGet('CalendarSync');
	// Mode 0 is "no server connected", so there is nothing to poll for
	if (config && config.Mode) {
		// Offset from the contacts sync, which starts at 10s, so a fresh session
		// does not open two DAV conversations at once
		setTimeout(CalendarUserStore.sync, 20000);
		setInterval(CalendarUserStore.sync, config.Interval * 60000 + 15000);
	}
};
