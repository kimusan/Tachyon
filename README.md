<div align="center">
  <img src="docs/logo.png" alt="Tachyon" width="480">
  <p><em>Named after the theoretical particle that moves faster than light.</em></p>
  <p>Fast, secure, modern web-based email client.</p>
  <p>
    A fork of <a href="https://github.com/the-djmaze/snappymail">SnappyMail</a>,
    which itself forked <a href="https://github.com/RainLoop/rainloop-webmail">RainLoop Webmail Community edition</a>.
  </p>
  <p>Existing SnappyMail installations can upgrade directly to Tachyon.</p>
  <p><strong><a href="https://tachyonmail.app/">tachyonmail.app</a></strong></p>
  <br>
</div>

## What Tachyon adds

**Calendar**
- CalDAV support: month, week, day and list views, drag to move, and an editor for creating and changing events
- Several calendars per account, each with the colour and read-only state the server reports
- Recurring events expand correctly across timezones and DST, and all-day events stay date-only
- Off by default; enable `[calendar] enable` and point it at a server in Settings, Calendar

**Contacts**
- Contact groups using the standard vCard `CATEGORIES` field, so they survive a CardDAV round trip. Typing a group name while composing inserts a chip that expands to its members when the mail is sent
- Bulk operations: select a page or everything matching the current filter, and keep that selection while paging
- Writing to a selection can target To, Cc or Bcc, and suggests Bcc past a configurable threshold so a large To does not hand every address to every recipient
- Three DAV bugs fixed that affected calendars and address books alike: credentials are now sent with the first request rather than only after a 401, permissions are read correctly, and the connection test no longer authenticates with an encrypted copy of the password

**Mail**
- Subfolder search across a whole folder subtree, using IMAP MULTISEARCH where the server has it and falling back to searching each folder in turn where it does not
- Undo send, with a configurable delay before SMTP delivery
- Unread count badge per account on the account switcher

**Interface**
- Vector icons throughout, drawn in the current text colour so they follow the active theme. The interface previously drew most of its icons as emoji, which ignored theming entirely
- Dracula theme, with the Alucard light variant and a toggle that applies without reloading
- Tables in the HTML editor: insert, add and remove rows and columns

**Nextcloud**
- Published on the [Nextcloud App Store](https://apps.nextcloud.com/apps/tachyon), supporting Nextcloud 26 through 35
- Migrates an existing SnappyMail install in place, reusing its data directory

**Compatibility**
- Existing SnappyMail installations upgrade in place, data directory and config unchanged
- Plugins written against the `RainLoop\` or `SnappyMail\` namespaces keep working through compatibility shims

**PHP**
- PHP 8.2 minimum, dropping 7.4, 8.0 and 8.1
- Namespaces: `RainLoop\` to `Tachyon\`, `SnappyMail\` to `Tachyon\Util\`
- PHP 8.1 enums replacing abstract constants: `ResponseType`, `StoreAction`, `MessagePriority`, `SignMeType`, `Layout`, `DkimStatus`

**Security**
- Content-Security-Policy: fixed `report-to` with a `Reporting-Endpoints` header, `report-uri` kept as fallback
- `Permissions-Policy` denying camera, microphone, geolocation, payment and USB
- Subresource Integrity hashes for all static JS and CSS
- S/MIME signing fixed for identities whose private key has no passphrase

**Build and toolchain**
- Rollup v4, ESLint v9 flat config
- GitHub Actions CI: PHP syntax check and JS/CSS lint on every push and pull request
- `gulp i18n` reports what each translation is missing and where, so contributors do not have to diff by hand
- Backup and restore produce ordinary ZIP files and download directly
- OpenPGP.js v5.11.3

## Integrations

- **Nextcloud** — install directly from the [Nextcloud App Store](https://apps.nextcloud.com/apps/tachyon), or see `integrations/nextcloud/`
- **ownCloud** — see `integrations/owncloud/`
- **Cloudron** — see `integrations/cloudron/`
- **Docker** — see `examples/docker/`

## Requirements

- PHP 8.2+
- PHP mbstring extension
- OpenSSL or Sodium extension
- No database required

## Documentation

- [Installation instructions](https://github.com/kimusan/Tachyon/wiki/Installation-instructions)
- [Admin manual](https://github.com/kimusan/Tachyon/wiki/Admin-Manual) — every setting, and what it does
- [IMAP capabilities](https://github.com/kimusan/Tachyon/wiki/IMAP-capabilities) — which extensions Tachyon uses and what happens without them
- [Sieve filters](https://github.com/kimusan/Tachyon/wiki/Filters---Sieve)
- [Contributing](CONTRIBUTING.md)

## License

**Tachyon** is released under
**GNU AFFERO GENERAL PUBLIC LICENSE Version 3 (AGPL)**.
http://www.gnu.org/licenses/agpl-3.0.html

Copyright (c) 2025 - present Tachyon
Copyright (c) 2020 - 2024 SnappyMail
Copyright (c) 2013 - 2022 RainLoop

## What SnappyMail changed from RainLoop

- Privacy/GDPR friendly (no: Social, Gravatar, Facebook, Google, Twitter, DropBox, X-Mailer)
- Admin uses password_hash/password_verify
- Auth failed attempts written to syslog
- Fail2ban support
- ES2020
- PHP mbstring extension required
- Replaced pclZip with PharData and ZipArchive
- Dark mode with option to strip background/font colors from messages
- Removed BackwardCapability (class \RainLoop\Account)
- Removed ChangePassword (re-implemented as plugin)
- Removed POP3 support
- Removed background video support
- Removed Sentry error tracking
- Removed Spyc yaml
- Removed OwnCloud bundling
- CRLF => LF line endings
- Embedded boot.js and boot.css into index.html
- Removal of legacy JavaScript (native APIs used throughout)
- Added modified [Squire](https://github.com/the-djmaze/Squire/tree/snappymail) HTML editor replacing CKEditor
- Updated [Sabre/VObject](https://github.com/sabre-io/vobject)
- Split Admin / User / Sieve JavaScript bundles
- Better memory garbage collection
- Service worker for push notifications
- Advanced Sieve scripts editor
- Replaced webpack with rollup
- No user-agent detection (use device width)
- Plugin loading as .phar supported
- AddressBook contacts support MySQL/MariaDB utf8mb4
- [Fetch Metadata Request Headers](https://www.w3.org/TR/fetch-metadata/) checks
- Reduced DOM size
- Kolab groupware support
- Extended IMAP RFC support (CONDSTORE, QRESYNC, METADATA, NOTIFY, and more)
- Sodium and OpenSSL encryption support
- PGP: OpenPGP.js v5, GnuPG, Mailvelope; ECDSA and EDDSA key support


### Supported browsers

No Internet Explorer. No Edge Legacy.

- Chrome 90+
- Edge 90+
- Firefox 115+ (ESR)
- Opera 76+
- Safari 15.4+


### JavaScript size comparison (RainLoop 1.17 vs SnappyMail vs Tachyon)

|js/min/*        |RainLoop  |SnappyMail|  Tachyon | Tachyon gz |
|----------------|--------: |--------: |--------: |----------: |
|admin.min.js    |  256,831 |   41,719 |   42,225 |     14,182 |
|app.min.js      |  515,367 |  202,101 |  221,355 |     74,075 |
|boot.min.js     |   84,659 |    2,231 |    2,273 |      1,295 |
|libs.min.js     |  584,772 |  110,646 |  113,032 |     40,243 |
|sieve.min.js    |        0 |   45,504 |   45,377 |     11,092 |
|calendar.min.js |        0 |        0 |  129,224 |     41,429 |
|polyfills.min.js|   32,837 |        0 |        0 |          0 |

What a browser fetches on load is `boot`, `libs` and `app`, so about 337 KB
minified or 116 KB over gzip, about 70% less than RainLoop.

`sieve.min.js` loads only when the filters screen is opened, and
`calendar.min.js` only when the calendar is, so neither is part of that figure.
The calendar component is the single largest file in the project and is
deliberately kept out of the initial payload for the many installations that
will never enable it.

### PGP

RainLoop uses OpenPGP.js v2. Tachyon (via SnappyMail lineage) uses OpenPGP.js v5 with GnuPG
and Mailvelope support. ECDSA and EdDSA key generation included.

### HTML Editor

Squire is used in place of CKEditor.

|        | normal  | min     | gzip   | min gzip |
|--------|--------:|--------:|-------:|---------:|
|squire  | 115,520 |  41,906 | 23,387 |   14,330 |
|ckeditor|       ? | 520,035 |      ? |  155,916 |
