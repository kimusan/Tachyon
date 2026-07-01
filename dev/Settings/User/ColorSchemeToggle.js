const STORAGE_KEY = 'tachyon_color_scheme';
const ATTR_NAME = 'data-color-scheme';

// ko is a global in this codebase — no import needed
export const colorSchemeMode = ko.observable('');

/**
 * Initialize color scheme toggle.
 * Applies stored preference on load and sets up click handlers.
 */
export function initColorSchemeToggle() {
	// Read stored preference on init
	const stored = localStorage.getItem(STORAGE_KEY);
	if (stored) {
		colorSchemeMode(stored);
		applyColorScheme(stored);
	}
}

/**
 * Apply a color scheme mode to the document.
 * @param {string} mode - 'light', 'dark', or '' for system
 */
function applyColorScheme(mode) {
	if (mode) {
		document.documentElement.setAttribute(ATTR_NAME, mode);
	} else {
		document.documentElement.removeAttribute(ATTR_NAME);
	}
	localStorage.setItem(STORAGE_KEY, mode);
	colorSchemeMode(mode);
}

/**
 * Toggle to light mode.
 */
export function setLightMode() {
	applyColorScheme('light');
}

/**
 * Toggle to dark mode.
 */
export function setDarkMode() {
	applyColorScheme('dark');
}

/**
 * Toggle to system mode (respects prefers-color-scheme).
 */
export function setSystemMode() {
	applyColorScheme('');
}

/**
 * Get the current mode.
 */
export function getCurrentMode() {
	return colorSchemeMode();
}
