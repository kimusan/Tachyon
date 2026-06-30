<div align="center">
  <h1>Tachyon</h1>
  <p><em>Named after the theoretical particle that moves faster than light.</em></p>
  <br>
  <p>
    Fast, secure, modern web-based email client.
  </p>
  <p>
    A fork of <a href="https://github.com/the-djmaze/snappymail">SnappyMail</a>,
    which itself forked <a href="https://github.com/RainLoop/rainloop-webmail">RainLoop Webmail Community edition</a>.
  </p>
  <p>
    Existing SnappyMail installations can upgrade directly to Tachyon.
  </p>
  <h2></h2>
  <br>
</div>

## Requirements

- PHP 8.2+
- PHP mbstring extension
- OpenSSL or Sodium extension
- No database required

## License

**Tachyon** is released under
**GNU AFFERO GENERAL PUBLIC LICENSE Version 3 (AGPL)**.
http://www.gnu.org/licenses/agpl-3.0.html

Copyright (c) 2025 - present Tachyon
Copyright (c) 2020 - 2024 SnappyMail
Copyright (c) 2013 - 2022 RainLoop

## What changed from SnappyMail

- Renamed to Tachyon
- PHP 8.2 minimum (dropped support for 7.4, 8.0, 8.1)
- Namespaces updated: `RainLoop\` and `SnappyMail\` merged into `Tachyon\`
- Build toolchain updated: rollup v4, ESLint v9, removed deprecated plugins
- Vendor libraries audited and updated to latest compatible versions
- CSP headers updated: `report-uri` replaced with `report-to`
- Dead code removal: `register_globals` ini_set, PHP 7.x compatibility shims


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
- Extended [IMAP RFC support](https://snappymail.eu/comparison#IMAP)
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

|js/min/*        |RainLoop  |SnappyMail|
|----------------|--------: |--------: |
|admin.min.js    |  256,831 |   41,719 |
|app.min.js      |  515,367 |  202,101 |
|boot.min.js     |   84,659 |    2,231 |
|libs.min.js     |  584,772 |  110,646 |
|sieve.min.js    |        0 |   45,504 |
|polyfills.min.js|   32,837 |        0 |

For a user, the payload is around 66% smaller than traditional RainLoop.

### PGP

RainLoop uses OpenPGP.js v2. Tachyon (via SnappyMail lineage) uses OpenPGP.js v5 with GnuPG
and Mailvelope support. ECDSA and EdDSA key generation included.

### HTML Editor

Squire is used in place of CKEditor.

|        | normal  | min     | gzip   | min gzip |
|--------|--------:|--------:|-------:|---------:|
|squire  | 122,321 |  41,906 | 31,867 |   14,330 |
|ckeditor|       ? | 520,035 |      ? |  155,916 |
