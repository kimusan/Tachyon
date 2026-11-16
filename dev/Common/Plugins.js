import { settingsAddViewModel } from 'Screen/AbstractSettings';
import { SettingsGet } from 'Common/Globals';
import { AbstractViewPopup } from 'Knoin/AbstractViews';
import { addToSlot, addButton } from 'Common/PluginSlots';

const USER_VIEW_MODELS_HOOKS = [],
	ADMIN_VIEW_MODELS_HOOKS = [];

/**
 * @param {Function} callback
 * @param {string} action
 * @param {Object=} parameters
 * @param {?number=} timeout
 */
rl.pluginRemoteRequest = (callback, action, parameters, timeout) => {
	rl.app.Remote.request('Plugin' + action, callback, parameters, timeout);
};

/**
 * @param {Function} SettingsViewModelClass
 * @param {string} labelName
 * @param {string} template
 * @param {string} route
 */
rl.addSettingsViewModel = (SettingsViewModelClass, template, labelName, route) => {
	USER_VIEW_MODELS_HOOKS.push([SettingsViewModelClass, template, labelName, route]);
};

/**
 * @param {Function} SettingsViewModelClass
 * @param {string} labelName
 * @param {string} template
 * @param {string} route
 */
rl.addSettingsViewModelForAdmin = (SettingsViewModelClass, template, labelName, route) => {
	ADMIN_VIEW_MODELS_HOOKS.push([SettingsViewModelClass, template, labelName, route]);
};

/**
 * @param {boolean} admin
 */
export function runSettingsViewModelHooks(admin) {
	(admin ? ADMIN_VIEW_MODELS_HOOKS : USER_VIEW_MODELS_HOOKS).forEach(view =>
		settingsAddViewModel(...view)
	);
}

/**
 * @param {string} pluginSection
 * @param {string} name
 * @returns {?}
 */
rl.pluginSettingsGet = (pluginSection, name) =>
	SettingsGet('Plugins')?.[pluginSection]?.[name];

rl.pluginPopupView = AbstractViewPopup;

/**
 * Fills a named place in the interface with a template, see Common/PluginSlots
 * for the list. Without a factory the template is bound to the view it sits in.
 * @param {string} slot
 * @param {string} template
 * @param {Function=} factory Receives the view, returns the template's data
 */
rl.addToSlot = addToSlot;

/**
 * Adds a button to a toolbar or menu slot, drawn with the core markup of that
 * place, so a plugin needs no template or styles for it.
 * @param {string} slot
 * @param {Object} button { label, icon, action, visible, enabled }
 */
rl.addButton = addButton;

// The first slots had a function each; they stay for the plugins using them

/**
 * Renders a plugin template as a row of the compose header table.
 * The template holds a <tr> and is bound to the ComposePopupView.
 * @param {string} template
 */
rl.addComposeHeaderRow = template => addToSlot('compose.headerRows', template);

/**
 * Renders a plugin template beside the attach button of the compose window.
 * The template is bound to the ComposePopupView.
 * @param {string} template
 */
rl.addComposeButton = template => addToSlot('compose.buttons', template);

/**
 * Renders a plugin template in the message view's menu.
 * The template holds an <li> and is bound to the MessageViewUserView, so the
 * message itself is $data.message().
 * @param {string} template
 */
rl.addMessageViewMenuItem = template => addToSlot('messageView.menu', template);

/**
 * Renders a plugin template in the message view's top toolbar, after the
 * previous/next buttons.
 * The template holds a <div class="btn-group"> and is bound to the
 * MessageViewUserView, so the message itself is $data.message().
 * @param {string} template
 */
rl.addMessageViewToolbarButton = template => addToSlot('messageView.toolbar', template);
