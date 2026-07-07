# tachyon-nextcloud

Tachyon is a plugin for Nextcloud to use the Tachyon webmail (https://github.com/kimusan/Tachyon).

Thank you to all contributors to SnappyMail for Nextcloud, from which this integration was derived:
- RainLoop Team, who initiated it
- [pierre-alain-b](https://github.com/pierre-alain-b/rainloop-nextcloud)
- Tab Fitts (@tabp0le)
- Nextgen Networks (@nextgen-networks)
- [All testers of issue 96](https://github.com/the-djmaze/snappymail/issues/96)

## How to Install

Start within Nextcloud as user with administrator rights and click on the "+ Apps" button in the upper-right corner dropdown menu:

![Image1](https://raw.githubusercontent.com/kimusan/Tachyon/master/integrations/nextcloud/screenshots/help_a1.png)

Then, enable the Tachyon plugin that you will find in the "Social & communication" section:

![Image2](https://raw.githubusercontent.com/kimusan/Tachyon/master/integrations/nextcloud/screenshots/help_a2.png)

After a quick wait, Tachyon is installed. Now you should configure it before use: open the Nextcloud admin panel (upper-right corner dropdown menu -> Settings) and go to "Additional settings" under the "Administration" section. There, click on the "Go to Tachyon Webmail admin panel" link.

![Image3](https://raw.githubusercontent.com/kimusan/Tachyon/master/integrations/nextcloud/screenshots/nextcloud-admin.png)

To enter the Tachyon admin area, you must be a Nextcloud admin (so you get logged in automatically) or else use the admin login credentials.
The default login is "admin" and the default password will be generated in `[nextcloud-data]/appdata_tachyon/_data_/_default_/admin_password.txt`. Don't forget to change it once in the admin panel!

If you are migrating from SnappyMail, the data folder will remain at `appdata_snappymail/` — Tachyon detects and uses it automatically.

From that point, all instance-wide Tachyon settings can be tweaked as you wish. One important point is the "Domains" section where you should set up the IMAP/SMTP parameters that will be associated with the email addresses of your users. Basically, if a user of the Nextcloud instance starts Tachyon and puts "firstname@domain.tld" as an email address, then Tachyon should know how to connect to the IMAP & SMTP of domain.tld. You can fill in this information in the "Domains" section of the Tachyon admin settings. For more information on how to configure automatic login for your Nextcloud users see [How to auto-connect to Tachyon?](#how-to-auto-connect-to-tachyon)

## App Integrations
### Contacts
Tachyon automatically connects with the Nextcloud contacts app. Download and install the [contacts app](https://apps.nextcloud.com/apps/contacts) for Tachyon to obtain access to all registered users on the Nextcloud system, as well as users' personal contacts saved in here.

## Tachyon Settings, Where Are They?

Tachyon for Nextcloud is highly configurable. But settings are available in multiple places and this can be misleading for first-time users.

### Tachyon admin settings
Tachyon admin settings can be reached only by the Nextcloud administrator. Open the Nextcloud admin panel ("Admin" in the upper-right corner dropdown menu) and go to "Additional settings". There, click on the "Go to Tachyon Webmail admin panel" link. Alternatively, you may use the following link: https://path.to.nextcloud/index.php/apps/tachyon/?admin.

Tachyon admin settings include all settings that will apply to all Tachyon users (default login rules, branding, management of plugins, security rules and domains).

### Tachyon user settings
Each user of Tachyon can also change user-specific behaviors in the Tachyon user settings. Tachyon user settings are found within Tachyon by clicking on the user button (in the upper-right corner of Tachyon) and then choosing "Settings" in the dropdown menu.

Tachyon user settings include management of contacts, email accounts, folders, appearance and OpenPGP.

### The specificity of Tachyon user accounts
The plugin passes the login information of the user to the Tachyon app which then creates and manages the user accounts. Accounts in Tachyon are based solely on the authenticated email accounts, and do not take into account the Nextcloud user which created them in the first place. If two or more Nextcloud users have the same email account in additional settings, they will in fact share the same 'email account' in Tachyon including any additional email accounts that they may have added subsequently to their main account.
This is to be kept in mind for the use case where multiple users shall have the same email account but may be also tempted to add additional accounts to their Tachyon.

## How to auto-connect to Tachyon?

### Default Domain
Tachyon uses the domain part (@example.com) to choose the IMAP/SMTP server to use. If in the following settings the username passed to Tachyon does not contain a domain, the "default domain" is added to this username. In this way Tachyon can look up the "Domain" configuration to use (IMAP, SMTP, SIEVE server etc.).
Example: if the username `john` is passed to Tachyon, the "default domain" `example.com` would be added based on your configuration. So Tachyon would try to login the user with the username `john@example.com`.

You can configure the "default domain" and connected settings in the Tachyon Admin Panel under the menu "Login".

### Auto-connect options
The Nextcloud administrator can choose how Tachyon tries to automatically login when a user clicks on the Tachyon icon within Nextcloud. There are different options that can be found in the Nextcloud "Settings -> Administration -> Additional settings":

#### Option 1: Users will login manually, or define credentials in their personal settings for automatic logins.
If the user sets his credentials for the mailbox in his personal account under "Settings -> Additional settings", these credentials are used by Tachyon to login.
If no personal credentials are defined the user is prompted by Tachyon to insert his credentials every time he tries to open the Tachyon App within Nextcloud.

#### Option 2: Attempt to automatically login users with their Nextcloud username and password, or user-defined credentials, if set.
If the user sets his credentials for the mailbox in his personal account under "Settings -> Additional settings", these credentials are used by Tachyon to login.
If no personal credentials are defined the Nextcloud username and password is used by Tachyon to login (eventually adding the [default domain](#default-domain)).

If your IMAP server only accepts usernames without a domain (for example the ldap username of your user) the automatic addition of the "default domain" would block your users from logging in to your IMAP server - but on the other side it is needed by Tachyon to determine the server settings to use. In such a case you must configure Tachyon to strip off the domain part before sending the credentials to your IMAP server. This is done by entering to the Tachyon Admin Panel -> Domains -> clicking on your default domain -> flagging the checkbox "Use short login" under IMAP and SMTP.

#### Option 3: Attempt to automatically login users with their Nextcloud email and password, or user-defined credentials, if set.
If the user sets his credentials for the mailbox in his personal account under "Settings -> Additional settings", these credentials are used by Tachyon to login.
If no personal credentials are defined the mail address of the Nextcloud user and his password are used by Tachyon to login. Tachyon will look up the "Domain" settings for a configuration that meets the domain part of the mail address passed as username.

#### Option 4: Attempt to automatically login with OIDC when active

### Auto-connection for all Nextcloud users
If your Nextcloud users base is synchronized with an email system, then it is possible that Nextcloud credentials could be used right away to access the centralized email system. In the Tachyon admin settings, the Nextcloud administrator can then tick the "Automatically login with Nextcloud user credentials" checkbox.

Beware, if you tick this box, all Nextcloud users will *not* be able to override it with the setting below.

### Auto-connection for one user at a time
Except if the above setting is activated, any Nextcloud user can have Nextcloud and Tachyon keep in mind the default email/password to connect to Tachyon. There, logging in to Nextcloud is sufficient to then access Tachyon within Nextcloud.

To fill in the default email address and password to use, each Nextcloud user should go in the personal settings: choose "Settings" in the upper-right corner dropdown menu. Under "Personal" select the "Additional settings" section where you can find the "Tachyon Webmail" settings. You can also use this direct link: https://path.to.nextcloud/settings/user/additional.


## How to Activate Tachyon Logging and then Find Logs

You can activate Tachyon logging here: `/path/to/nextcloud/data/appdata_tachyon/_data_/_default_/configs/application.ini`
```
[logs]
enable = On
```
Logs are then available in `/path/to/nextcloud/data/appdata_tachyon/_data_/_default_/logs/`

If you migrated from SnappyMail, the path will be `appdata_snappymail/` instead.
