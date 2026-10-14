/* back-navigation — the browser's Back button closes the opened message and returns to the list.
 *
 * The core reads the route "#/mailbox/<folder>/m<uid>(/<search>)" (MailBoxUserScreen.routes, app.js: it opens that
 * message) but never writes it: opening a message from the list leaves the hash untouched, so Back leaves the webmail.
 * This script writes it:
 *   - message opened from the list  → push "#/mailbox/<folder>/m<uid>[/<search>]" (core routing paused while writing,
 *     otherwise the core would fetch the message and reload the list a second time);
 *   - browser Back (hash loses its "m<uid>" segment while a message is shown) → close the message. The core routes
 *     the new hash itself (and reloads the list, as it does for any hash change);
 *   - close button (×), message closed while the hash still names it → history.back() with the core routing paused
 *     until the hashchange, so the close is exactly the core's (no list reload) and the history stays clean;
 *     when the message was opened by the route itself (direct link, F5, Forward) nothing was pushed: the hash is
 *     replaced instead, without a history entry.
 * Relies on: the view model MailMessageView (rl-view-model event, observable `message`, method `closeMessage`),
 * `hasher` (libs.js, the hash router) and `rl.route.off()/on()`, and the folder hash rule of FolderModel.fullNameHash.
 * Re-check them after every core upgrade. The hash names a uid: it dies when the message is moved (uids belong to a
 * folder); a Message-ID based route would be the durable alternative, out of scope here.
 */
(() => {
	const ROUTE = /^#\/mailbox\/([^/]+)\/m([1-9]\d*)(?:\/|$)/,
		// FolderModel.fullNameHash (app.js): every run of characters outside [a-z0-9._-] is base64-url of its JSON string
		b64 = s => btoa(unescape(encodeURIComponent(JSON.stringify(s)))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''),
		folderHash = name => name.replace(/[^a-z0-9._-]+/giu, b64),
		hash = () => { try { return decodeURIComponent(location.hash); } catch (e) { return location.hash; } },
		uidInHash = () => (ROUTE.exec(hash()) || [])[2] || '',
		// hasher works on decoded paths without the leading "#/", and encodes them itself
		path = h => h.replace(/^#\/?/, ''),
		paused = fn => { rl.route.off(); try { fn(); } finally { rl.route.on(); } },
		// "#/mailbox/<folder>[~thread][/p<n>][/<search>]" → "mailbox/<message folder>[~thread]/m<uid>[/<search>]"
		// (the message route has no page; the thread suffix is kept only when the message sits in the shown folder)
		messageHash = msg => {
			const parts = path(hash()).split('/'), shown = parts[0] === 'mailbox' ? parts[1] || '' : '',
				folder = folderHash(msg.folder), rest = shown ? parts.slice(2).filter(p => !/^p[1-9]\d*$/.test(p)) : [];
			return 'mailbox/' + (shown.startsWith(folder + '~') ? shown : folder) + '/m' + msg.uid
				+ (rest.length ? '/' + rest.join('/') : '');
		};
	let vm = null, pushed = false, closing = 0;

	addEventListener('rl-view-model', e => {
		const v = e.detail;
		if ('MailMessageView' !== v?.viewModelTemplateID || !window.ko?.isObservable(v.message) || !window.hasher) { return; }
		vm = v;
		v.message.subscribe(msg => {
			if (msg) {
				// opened by the route itself (direct link, F5, Forward): nothing to push
				if (uidInHash() === String(msg.uid)) { pushed = false; return; }
				paused(() => hasher.setHash(messageHash(msg)));
				pushed = true;
			} else if (uidInHash()) {
				// closed (×, or by the core) while the hash still names the message
				if (pushed) {
					rl.route.off();
					closing = setTimeout(() => { closing = 0; rl.route.on(); }, 1000);   // no hashchange came: never stay paused
					history.back();
				} else {
					paused(() => hasher.replaceHash(path(hash()).replace(/\/m[1-9]\d*(?=\/|$)/, '')));
				}
				pushed = false;
			}
		});
	});

	addEventListener('hashchange', () => {
		if (closing) { clearTimeout(closing); closing = 0; rl.route.on(); return; }   // our own history.back()
		// Back / Forward / any route: a message is shown but the hash no longer names it → close it
		const msg = vm?.message?.();
		if (msg && uidInHash() !== String(msg.uid)) { pushed = false; vm.closeMessage(); }
	});
})();
