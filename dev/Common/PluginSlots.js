import ko from 'ko';
import { i18n, translateTrigger } from 'Common/Translator';

/**
 * Places in the interface that plugins fill. A core template marks one with
 *
 *   <!-- ko foreach: $root.pluginSlot('messageView.toolbar') -->
 *   <!-- ko template: { name: template, data: data } --><!-- /ko -->
 *   <!-- /ko -->
 *
 * and a plugin fills it with rl.addToSlot() or rl.addButton(). Each place used
 * to need its own rl.add...() function, list and view model property, so every
 * new place meant a core change in three files; now it is one template line.
 *
 * Slots in the user interface:
 *   messageView.toolbar         top toolbar of the message view
 *   messageView.menu            the message view's "more" menu
 *   messageView.beforeMessage   top of the scrolling message, under the pinned
 *                               subject line, above date and recipients
 *   messageView.beforeBody      between the header and the body
 *   messageView.afterMessage    below the body
 *   messageList.toolbar         message list toolbar
 *   messageList.menu            the message list's "more" menu
 *   folderList.footer           under the folder list
 *   compose.buttons             beside the attach button
 *   compose.headerRows          the compose header table; a template holds a <tr>
 */

// Where a slot takes buttons, the core markup they get there, so a plugin
// button looks like the buttons around it in every theme. The other slots
// take templates only.
const BUTTON_TEMPLATES = {
		'messageView.toolbar': 'PluginToolbarButton',
		'messageList.toolbar': 'PluginToolbarButton',
		'folderList.footer': 'PluginToolbarButton',
		'compose.buttons': 'PluginGroupButton',
		'messageView.menu': 'PluginMenuItem',
		'messageList.menu': 'PluginMenuItem'
	},
	// Observable, so a plugin that registers after a view has rendered still shows
	SLOTS = {},
	entriesOf = name => SLOTS[name] || (SLOTS[name] = ko.observableArray()),
	// Per view, what each entry renders with: built once, so the foreach keeps
	// its nodes and a plugin keeps its state across re-renders
	rendered = new WeakMap,
	read = (option, view, fallback) =>
		undefined === option ? fallback : ('function' === typeof option ? option(view) : ko.unwrap(option));

class PluginButton {
	constructor(button, view) {
		const icon = ko.computed(() => read(button.icon, view, ''));
		this.iconName = icon;
		this.iconClass = ko.computed(() => icon() ? 'icon-' + icon() : '');
		this.label = ko.computed(() => {
			translateTrigger();
			const key = read(button.label, view, '');
			return key ? i18n(key) : '';
		});
		this.visible = ko.computed(() => !!read(button.visible, view, true));
		this.enabled = ko.computed(() => !!read(button.enabled, view, true));
		this.click = () => this.enabled() && button.action?.(view);
	}
}

/**
 * @param {string} name
 * @param {string} template
 * @param {Function=} factory Called once per view with that view; what it
 *     returns is the template's data. Without it the template is bound to the
 *     view itself.
 */
export const addToSlot = (name, template, factory) => entriesOf(name).push({ template, factory });

/**
 * @param {string} name
 * @param {Object} button { label, icon, action, visible, enabled }. label is a
 *     translation key, icon a name from the icon set without "icon-". Each of
 *     label, icon, visible and enabled may be a function of the view, and is
 *     re-read when the observables it reads change. action is called with the
 *     view.
 */
export const addButton = (name, button) => {
	// Thrown rather than logged: the build drops console calls, and a button
	// that silently never appears is harder to track down than an error
	if (!BUTTON_TEMPLATES[name]) {
		throw new Error('Plugin slot "' + name + '" takes no buttons');
	}
	entriesOf(name).push({ template: BUTTON_TEMPLATES[name], button });
};

export const pluginSlot = (name, view) => {
	let cache = rendered.get(view);
	cache || rendered.set(view, cache = new Map);
	return entriesOf(name)().map(entry => {
		if (!cache.has(entry)) {
			cache.set(entry, {
				template: entry.template,
				data: entry.button
					? new PluginButton(entry.button, view)
					: (entry.factory ? entry.factory(view) : view)
			});
		}
		return cache.get(entry);
	});
};
