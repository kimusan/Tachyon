import { SettingsGet } from 'Common/Globals';

/**
 * The logo shown on a login screen, resolved from the admin's branding
 * settings. Shared because the admin panel has its own login page and it
 * should not look like a different product.
 */
export function loginLogo() {
	const mode = SettingsGet('loginLogoMode') || 'default',
		url = file => file ? ('?/Logo/' + encodeURIComponent(file)) : '',
		light = url(SettingsGet('logoFile')),
		dark = url(SettingsGet('logoFileDark')),
		custom = 'custom' === mode;
	return {
		logoDefault: 'default' === mode,
		// Either variant stands in for a missing one, so a single upload keeps
		// working the way it did before there were two
		logoLightUrl: custom ? (light || dark) : '',
		logoDarkUrl: custom ? (dark || light) : '',
		hasLogo: 'default' === mode || !!(custom && (light || dark))
	};
}

/**
 * The footer split into plain runs and links.
 *
 * Deliberately not HTML. This sits on the page where people type their
 * password, so nothing an admin writes is ever interpreted as markup: the text
 * is rendered through text bindings and only http(s) URLs, matched here,
 * become anchors. That also rules out a javascript: href.
 */
export function loginFooter() {
	const text = SettingsGet('loginFooter') || '';
	return text
		? text.split(/(https?:\/\/[^\s<>"']+)/).filter(part => part.length)
			.map(part => /^https?:\/\//.test(part)
				? { text: part, url: part }
				: { text: part, url: '' })
		: [];
}
