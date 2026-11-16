Also see https://github.com/the-djmaze/snappymail/tree/master/plugins/example

PHP
```php
class Plugin extends \RainLoop\Plugins\AbstractPlugin
{
	public function __construct();

	/** Returns static::NAME */
	public function Name(): string;

	/** Returns /README file contents or static::DESCRIPTION */
	public function Description(): string;

	/** When $bLangs is boolean it sets the value, else returns current value */
	public function UseLangs(?bool $bLangs = null): bool;

	/** When true the result is empty string, else the error message */
	public function Supported(): string;

	/** Initialize settings */
	public function Init(): void;

	public function FilterAppDataPluginSection(bool $bAdmin, bool $bAuth, array &$aConfig): void;

	/** Returns array of all plugin Property options for use in Admin -> Extensions -> Plugin cog wheel */
	protected function configMapping(): array;

	/** With this function you hook to an event
	 * $sHookName see chapter "Hooks" below for available names
	 * $sFunctionName the name of a function in this class
	 */
	final protected function addHook(string $sHookName, string $sFunctionName): self;

	final protected function addCss(string $sFile, bool $bAdminScope = false): self;

	final protected function addJs(string $sFile, bool $bAdminScope = false): self;

	final protected function addTemplate(string $sFile, bool $bAdminScope = false): self;

	final protected function addJsonHook(string $sActionName, string $sFunctionName): self;

	/**
	 * You may register your own service actions.
	 * Url is like /?{actionname}/etc.
	 * Predefined actions of \RainLoop\ServiceActions that can't be registered are:
	 * - admin
	 * - AdminAppData
	 * - AppData
	 * - Append
	 * - Backup
	 * - BadBrowser
	 * - CspReport
	 * - Css
	 * - Json
	 * - Lang
	 * - Mailto
	 * - NoCookie
	 * - NoScript
	 * - Ping
	 * - Plugins
	 * - ProxyExternal
	 * - Raw
	 * - Sso
	 * - Upload
	 * - UploadBackground
	 * - UploadContacts
	 */
	final protected function addPartHook(string $sActionName, string $sFunctionName): self

	final public function Config(): \RainLoop\Config\Plugin;
	final public function Manager(): \RainLoop\Plugins\Manager;
	final public function Path(): string;
	final public function ConfigMap(bool $flatten = false): array;

	/**
	 * Returns result of Actions->DefaultResponse($sFunctionName, $mData) or json_encode($mData)
	 */
	final protected function jsonResponse(string $sFunctionName, $mData): mixed;

	final public function jsonParam(string $sKey, $mDefault = null): mixed;

	final public function getUserSettings(): array;

	final public function saveUserSettings(array $aSettings): bool;
}
```

JavaScript
```javascript
class PluginPopupView extends rl.pluginPopupView
{
	// Happens when DOM is created
	onBuild(dom) {}

	// Happens before showModal()
	beforeShow(...params) {}
	// Happens after showModal()
	onShow(...params) {}
	// Happens after showModal() animation transitionend
	afterShow() {}

	// Happens when user hits Escape or Close key
	// return false to prevent closing, use close() manually
	onClose() {}
	// Happens before animation transitionend
	onHide() {}
	// Happens after animation transitionend
	afterHide() {}
}

PluginPopupView.showModal();
```

# Hooks

```php
$Plugin->addHook('hook.name', 'functionName');
```

## Login

### login.credentials.step-1
	params:
		string &$sEmail

	Happens in resolveLoginCredentials($sEmail) BEFORE resolving domain name.
	This is the pure text from the login screen (DoLogin) or the SSO feature (ServiceSso) received by LoginProcess().
	- DoLogin() -> LoginProcess() -> resolveLoginCredentials($sEmail)
	- ServiceSso() -> LoginProcess() -> resolveLoginCredentials($sEmail)
	So $sEmail can just have the value `test` without a domain.

### login.credentials.step-2
	params:
		string &$sEmail
		string &$sPassword

	Happens in resolveLoginCredentials($sEmail) AFTER resolving domain name.
	So $sEmail always has a domain (for example `test` is now `test@example.com`).

### login.credentials
	params:
		string &$sEmail
		string &$sImapUser
		string &$sPassword
		string &$sSmtpUser

		$sEmail is the domain imap->fixUsername() without shortening.
		$sImapUser is the domain imap->fixUsername() for login into IMAP.
		$sSmtpUser is the domain smtp->fixUsername() for login into SMTP.

### login.success
	params:
		\RainLoop\Model\MainAccount $oAccount

## IMAP

### imap.before-connect
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Imap\ImapClient $oImapClient
		\MailSo\Imap\Settings $oSettings

### imap.after-connect
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Imap\ImapClient $oImapClient
		\MailSo\Imap\Settings $oSettings

### imap.before-login
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Imap\ImapClient $oImapClient
		\MailSo\Imap\Settings $oSettings

### imap.after-login
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Imap\ImapClient $oImapClient
		bool $bSuccess
		\MailSo\Imap\Settings $oSettings

### imap.message-headers
	params:
		array &$aHeaders

	Allows you to fetch more MIME headers for messages.

## Sieve

### sieve.before-connect
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Sieve\SieveClient $oSieveClient
		\MailSo\Sieve\Settings $oSettings

### sieve.after-connect
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Sieve\SieveClient $oSieveClient
		\MailSo\Sieve\Settings $oSettings

### sieve.before-login
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Sieve\SieveClient $oSieveClient
		\MailSo\Sieve\Settings $oSettings

### sieve.after-login
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Sieve\SieveClient $oSieveClient
		bool $bSuccess
		\MailSo\Sieve\Settings $oSettings

## SMTP

### smtp.before-connect
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Smtp\SmtpClient $oSmtpClient
		\MailSo\Smtp\Settings $oSettings

### smtp.after-connect
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Smtp\SmtpClient $oSmtpClient
		\MailSo\Smtp\Settings $oSettings

### smtp.before-login
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Smtp\SmtpClient $oSmtpClient
		\MailSo\Smtp\Settings $oSettings

### smtp.after-login
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Smtp\SmtpClient $oSmtpClient
		bool $bSuccess
		\MailSo\Smtp\Settings $oSettings

## Json service actions
Called by RainLoop\ServiceActions::ServiceJson()
{actionname} is one of the RainLoop\Actions::Do{ActionName}(),
or an extension action as "Plugin{ActionName}" added with Plugin::addJsonHook()
and called in JavaScript using rl.pluginRemoteRequest().

### json.before-{actionname}
	params: none

### json.after-{actionname}
	params:
		array &$aResponse

### json.action-post-call
	Obsolete, use json.after-{actionname}

### json.action-pre-call
	Obsolete, use json.before-{actionname}

### filter.json-response
	Obsolete, use json.after-{actionname}

## Others

### filter.account
	params:
		\RainLoop\Model\Account $oAccount

### filter.action-params
	params:
		string $sMethodName
		array &$aCurrentActionParams

### filter.app-data
	params:
		bool $bAdmin
		array &$aAppData

### filter.application-config
	params:
		\RainLoop\Config\Application $oConfig

### filter.build-message
	params:
		\MailSo\Mime\Message $oMessage

	Happens before send/save message

### filter.build-read-receipt-message
	params:
		\MailSo\Mime\Message $oMessage
		\RainLoop\Model\Account $oAccount

### filter.domain
	params:
		\RainLoop\Model\Domain $oDomain

### filter.fabrica
	params:
		string $sName
		mixed &$mResult
		\RainLoop\Model\Account $oAccount

### filter.http-paths
	params:
		array &$aPaths

### filter.language
	params:
		string &$sLanguage
		bool $bAdmin

	Allows you to set a different language

### filter.message-html
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Mime\Message $oMessage
		string &$sTextConverted

	Happens before send/save message

### filter.message-plain
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Mime\Message $oMessage
		string &$sTextConverted

	Happens before send/save message

### filter.message-rcpt
	Called by DoSendMessage and DoSendReadReceiptMessage
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Mime\EmailCollection $oRcpt

### filter.read-receipt-message-plain
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Mime\Message $oMessage
		string &$sText

### filter.result-message
	params:
		\MailSo\Mime\Message $oMessage

	Happens when reading message

### filter.save-message
	params:
		\MailSo\Mime\Message $oMessage

	Happens before save message

### filter.send-message
	params:
		\MailSo\Mime\Message $oMessage

	Happens before send message

### filter.send-message-stream
	params:
		\RainLoop\Model\Account $oAccount
		resource &$rMessageStream
		int &$iMessageStreamSize

### filter.send-read-receipt-message
	params:
		\MailSo\Mime\Message $oMessage
		\RainLoop\Model\Account $oAccount

### filter.smtp-from
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Mime\Message $oMessage
		string &$sFrom

### filter.smtp-hidden-rcpt
	params:
		\RainLoop\Model\Account $oAccount
		\MailSo\Mime\Message $oMessage
		array &$aHiddenRcpt

### filter.smtp-message-stream
	Called by DoSendMessage and DoSendReadReceiptMessage
	params:
		\RainLoop\Model\Account $oAccount
		resource &$rMessageStream
		int &$iMessageStreamSize

### filter.upload-response
	params:
		array &$aResponse

### json.attachments
	params:
		\SnappyMail\AttachmentsAction $oData

### json.suggestions-input-parameters
	params:
		string &$sQuery
		int &$iLimit
		\RainLoop\Model\Account $oAccount

### main.content-security-policy
	params:
		\SnappyMail\HTTP\CSP $oCSP

	Allows you to edit the policy, like:
	`$oCSP->script[] = "'strict-dynamic'";`

### main.default-response
	Obsolete, use json.after-{actionname}

### main.default-response-data
	Obsolete, use json.after-{actionname}

### main.default-response-error-data
	Obsolete, use json.after-{actionname}

### main.fabrica
	params:
		string $sName
		mixed &$mResult

# JavaScript Events

## mailbox
### mailbox.inbox-unread-count
### mailbox.message-list.selector.go-up
### mailbox.message-list.selector.go-down

### mailbox.message.show
	Use to show a specific message.
``` JavaScript
	dispatchEvent(
		new CustomEvent(
			'mailbox.message.show',
			{
				detail: {
					folder: 'INBOX',
					uid: 1
				},
				cancelable: false
			}
		)
	);
```

## audio
### audio.start
### audio.stop
### audio.api.stop
## Misc
### rl-layout
	event.detail value is one of:
	0. NoPreview
	1. SidePreview
	2. BottomPreview

### rl-view-model.create
	event.detail = the ViewModel class
	Happens immediately after the ViewModel constructor.
	See accessible properties as https://github.com/the-djmaze/snappymail/blob/master/dev/Knoin/AbstractViews.js

### rl-view-model
	event.detail = the ViewModel class
	Happens after the full build (vm.onBuild()) and contains viewModelDom

### rl-vm-visible
	event.detail = the ViewModel class
	Happens after the model is made visible (vm.afterShow())

### sm-admin-login
	event.detail = FormData
	cancelable using preventDefault()
### sm-admin-login-response
	event.detail = { error: int, data: {JSON response} }
### sm-user-login
	event.detail = FormData
	cancelable using preventDefault()
### sm-user-login-response
	event.detail = { error: int, data: {JSON response} }

### sm-show-screen
	event.detail = 'screenname'
	cancelable using preventDefault()

### squire-toolbar
	event.detail = { squire: SquireUI, actions: object }
	`actions` is the toolbar structure.
	```javascript
	block-of-buttons: {
		button-name: {
			select: ['selectbox options'],
			html: 'button text',
			cmd: () => `command to execute`,
			key: 'keyboard shortcut',
			matches: 'HTML elements that match'
		}
	}
	```
	See [SquireUI.js](https://github.com/the-djmaze/snappymail/blob/master/dev/External/SquireUI.js)
	for all default toolbar actions.

# JavaScript `rl` object

## rl.Enums.StorageResultType
### rl.Enums.StorageResultType.Abort
### rl.Enums.StorageResultType.Error
### rl.Enums.StorageResultType.Success

## rl.​Utils.htmlToPlain(html)
Converts HTML to text

## rl.Utils.plainToHtml(plain)
Converts text to HTML

## rl.addButton(slot, button)​
Adds a button to a toolbar or menu, drawn with the same markup as the core
buttons around it, so it needs no template and no styles and follows every
theme.

```js
rl.addButton('messageView.toolbar', {
	label: 'MY_PLUGIN/ACTION',       // translation key, also the tooltip
	icon: 'globe',                   // icon name without "icon-"
	action: view => …,               // called with the view the button sits in
	visible: view => !!view.message(),
	enabled: view => …
});
```

`label`, `icon`, `visible` and `enabled` may each be a value or a function of
the view, and a function is re-read when the observables it reads change: an
icon can turn into a spinner while the plugin works. Buttons are taken by
`messageView.toolbar`, `messageView.menu`, `messageList.toolbar`,
`messageList.menu`, `folderList.footer` and `compose.buttons`; any other slot
throws. Example in
* ./deepl-translate/js/DeeplTranslate.js

## rl.addToSlot(slot, template, factory)​
Renders a plugin template in a named place of the interface. Register the
template with `addTemplate()`; the name here is its filename without `.html`.
Without `factory` the template is bound to the view it sits in. With it,
`factory(view)` is called once per view and what it returns is the template's
data, so the plugin keeps its own state instead of adding it to the core view
model.

| Slot | Where |
|---|---|
| `messageView.toolbar` | top toolbar of the message view, after previous/next; template holds a `<div class="btn-group">` |
| `messageView.menu` | the message view's menu beside Reply; template holds an `<li>` |
| `messageView.beforeMessage` | top of the scrolling message, under the pinned subject line, above date and recipients |
| `messageView.beforeBody` | between the message header and the body |
| `messageView.afterMessage` | below the body |
| `messageList.toolbar` | message list toolbar, after the More menu |
| `messageList.menu` | the message list's More menu; template holds an `<li>` |
| `folderList.footer` | under the folder list, beside its buttons |
| `compose.buttons` | beside the attach button; template holds an `<a class="btn">` |
| `compose.headerRows` | the compose header table, under Subject; template holds a `<tr>` |

A slot is one line in a core template, so asking for a new place is a small
change. Slots are listed in `dev/Common/PluginSlots.js`.

The four functions below came first and stay as shorthands for
`rl.addToSlot()` with the view as the template's data.

## rl.addComposeButton(template)​
Renders a plugin template beside the attach button of the compose window.
The template is bound to the ComposePopupView, so a `click:` binding names a
method the plugin added to the view model on `rl-view-model.create`.
Register the template with `addTemplate()` and the name here is its filename
without `.html`.
Example in
* ./nextcloud/js/composer.js

## rl.addComposeHeaderRow(template)​
Renders a plugin template as a row of the compose header table, under Subject.
The template holds a `<tr>` and is bound to the ComposePopupView.
Example in
* ./send-save-in/savein.js

## rl.addMessageViewMenuItem(template)​
Renders a plugin template in the message view's menu, the one behind the
hamburger beside Reply. The template holds an `<li>` and is bound to the
message view model, so `message()` is the open message and a `click:` binding
names a method the plugin added on `rl-view-model.create`.

## rl.addMessageViewToolbarButton(template)​
Renders a plugin template in the message view's top toolbar, after the
previous/next buttons. The template holds a `<div class="btn-group">` and is
bound to the same view model as the menu item above.

## rl.addSettingsViewModel(SettingsViewModelClass, template, labelName, route)​
Examples in
* ./change-password/js/ChangePasswordUserSettings.js
* ./example/js/ExampleUserSettings.js
* ./kolab/js/settings.js
* ./two-factor-auth/js/TwoFactorAuthSettings.js

## rl.addSettingsViewModelForAdmin(SettingsViewModelClass, template, labelName, route)​
Examples in
* ./example/js/ExampleAdminSettings.js:34:	rl.addSettingsViewModelForAdmin(ExampleAdminSettings, 'ExampleAdminSettingsTab',

## rl.adminArea()​
Returns true or false when in '?admin' area

## rl.app.Remote.abort(action)​​​​​

## rl.app.Remote.get(action, url)​​​​​

## rl.app.Remote.getPublicKey(fCallback)​​​​​

## rl.app.Remote.post(action, fTrigger, params, timeOut)​​​​​

## rl.app.Remote.request(action, fCallback, params, iTimeout, sGetAdd)​​​​​

## rl.app.Remote.setTrigger(trigger, value)​​​​​

## rl.app.Remote.streamPerLine(fCallback, sGetAdd, postData)

## rl.app.folderList
A knockout observable array of all folders/mailboxes

## rl.fetch(resource, init, postData)​

## rl.fetchJSON(resource, init, postData)​

## rl.i18n(key, valueList, defaulValue)​

## rl.loadScript(src)​

## rl.logoutReload(url)​

## rl.pluginPopupView
class AbstractViewPopup

## rl.pluginRemoteRequest(callback, action, parameters, timeout)​

## rl.pluginSettingsGet(pluginSection, name)​

## rl.registerWYSIWYG(name, construct)​

## rl.route.root()

## rl.route.reload()

## rl.route.off()

## rl.setTitle(title)​

## rl.settings.get(name)

## rl.settings.set(name, value)

## rl.settings.app(name)
