import { SettingsGet } from 'Common/Globals';

const STORAGE_KEY = 'tachyon_color_scheme';
const MIGRATED_KEY = 'tachyon_color_scheme_migrated';
const ATTR_NAME = 'data-color-scheme';

export const colorSchemeMode = ko.observable('');

function applyColorScheme(mode) {
	if (mode) {
		document.documentElement.setAttribute(ATTR_NAME, mode);
	} else {
		document.documentElement.removeAttribute(ATTR_NAME);
	}
	localStorage.setItem(STORAGE_KEY, mode);
	colorSchemeMode(mode);
}

// When the theme CSS changes dynamically (user switches theme in Settings),
// re-set the attribute to force the browser to re-evaluate [data-color-scheme]
// selectors in the newly loaded theme stylesheet.
function watchThemeChanges() {
	const themeStyle = document.getElementById('app-theme-style');
	if (!themeStyle) return;
	new MutationObserver(() => {
		const mode = colorSchemeMode();
		if (mode) {
			document.documentElement.removeAttribute(ATTR_NAME);
			requestAnimationFrame(() =>
				document.documentElement.setAttribute(ATTR_NAME, mode)
			);
		}
	}).observe(themeStyle, { childList: true, characterData: true, subtree: true });
}

export function initColorSchemeToggle() {
	const stored = localStorage.getItem(STORAGE_KEY);
	if (stored) {
		colorSchemeMode(stored);
		applyColorScheme(stored);
	} else if (SettingsGet('ThemeWasDark') && !localStorage.getItem(MIGRATED_KEY)) {
		// The theme this account had saved was the dark build of a design that
		// is now one theme covering both. Server side it resolves to the merged
		// name; the mode is only ever stored here, so without this the account
		// would open in the light half of the theme it had deliberately picked
		// the dark half of. Marked with its own key rather than inferred from
		// STORAGE_KEY being empty, because choosing System stores an empty
		// string and that choice has to survive too.
		localStorage.setItem(MIGRATED_KEY, '1');
		applyColorScheme('dark');
	}
	watchThemeChanges();
}

export function setLightMode()  { applyColorScheme('light'); }
export function setDarkMode()   { applyColorScheme('dark'); }
export function setSystemMode() { applyColorScheme(''); }
