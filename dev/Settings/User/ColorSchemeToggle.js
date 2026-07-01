const STORAGE_KEY = 'tachyon_color_scheme';
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
	}
	watchThemeChanges();
}

export function setLightMode()  { applyColorScheme('light'); }
export function setDarkMode()   { applyColorScheme('dark'); }
export function setSystemMode() { applyColorScheme(''); }
