# Tachyon Webmail - Project Roadmap

**Tachyon** is a fork of SnappyMail (itself a fork of RainLoop), renamed to reflect its new focus on extreme speed,
modern UX, and hardened security. The name references the theoretical particle that moves faster than light.

> Project forked from SnappyMail v2.38.2. Existing SnappyMail installations must be upgradeable to Tachyon.

---

## Status Legend
- [ ] Not started
- [~] In progress
- [x] Done

---

## Phase 0: Project Audit and Setup (completed: 2026-06-30)

- [x] Read all documentation and understand project structure
- [x] Map namespaces: `SnappyMail\`, `RainLoop\`, `MailSo\`
- [x] Identify PHP version floor: currently 7.4 (EOL Dec 2022), polyfill for PHP 8 present
- [x] Identify JS build toolchain: Gulp + Rollup, LESS for CSS
- [x] Map vendor libraries: KnockoutJS 3.5.1-sm, Squire2, OpenPGP.js v5, Sabre VObject/XML, marked, turndown
- [x] Map integrations: Nextcloud, Cloudron, cPanel, CyberPanel, HestiaCP, OwnCloud, Virtualmin
- [x] Map plugin ecosystem: 60+ plugins in /plugins/
- [x] Note deprecated PHP functions already caught in git log (e.g., openssl_pkey_free)

---

## Phase 1: Project Rename (Tachyon branding)

### 1.1 Repository & metadata (done: 2026-06-30)
- [x] Update `package.json`: name, title, description, homepage, author, bugs URL, repository URL — version bumped to 3.0.0
- [x] Update `README.md`: full rewrite with Tachyon branding, particle name note, updated browser targets
- [x] Update `SECURITY.md`: removed SnappyMail PGP key, updated version table
- [x] Update `CONTRIBUTING.md`: updated all references and URLs
- [x] Update `.github/ISSUE_TEMPLATE/bug_report.md`: updated version field and browser examples
- [x] Update `CHANGELOG.md`: added 3.0.0 entry at top
- [x] Update `_include.php`: renamed `SNAPPYMAIL_UPDATE_PLUGINS` -> `TACHYON_UPDATE_PLUGINS` in comments
- [x] Update `.browserslistrc`: raised browser targets (Chrome/Edge 90+, FF 115+, Safari 15.4+)

### 1.2 PHP directory structure (done: 2026-06-30)
The main app directory is `snappymail/v/<version>/`. Strategy:
- Keep `snappymail/` directory name for now to preserve upgrade paths from SnappyMail installations
- All internal references will be updated but the disk layout stays compatible
- A future breaking version bump can rename the directory; document this decision here

**Key rename targets in PHP:**
- Namespace `SnappyMail\` -> `Tachyon\` (145 files)
- Namespace `RainLoop\` -> consider keeping as internal namespace or renaming to `Tachyon\Core\`
  Decision: rename `RainLoop\` -> `Tachyon\` as a merged namespace (same 102 files overlap)
- Constants `SNAPPYMAIL_*` -> `TACHYON_*` (check backward compat for plugins)
- String literals "SnappyMail" in user-facing text -> "Tachyon"
- Config keys must stay backward-compatible (config files are persistent on disk)
- Session/cookie names: check if changing breaks active sessions (acceptable for major release)

**Tools:** `sed`-based batch rename, then grep verify. Do PHP files in passes:
  1. Namespace declarations
  2. `use` statements
  3. String literals (UI text only, not config keys)
  4. Constants

### 1.3 JavaScript rename (done: 2026-06-30)
- [x] 12 JS files and 8 LESS files in `dev/` updated
- [x] `snappymail/v/0.0.0/static/manifest.json` updated
- [x] `jsconfig.json` updated

### 1.4 Configuration and data path
- [ ] Decide on config directory naming: keep `data/` as-is (upgrade safe)
- [ ] Add migration shim in `upgrade.php` for any config key changes
- [ ] Verify `APP_PRIVATE_DATA`, `SNAPPYMAIL_LIBRARIES_PATH` const renames don't break plugins

### 1.4b Build scripts (done: 2026-06-30, see Phase 3)
- [x] `gulpfile.js` and `tasks/*.js` copyright headers updated to Tachyon
- [x] `tasks/js.js:89` regex with `snappymail/v/` path kept as-is (disk dir preserved)
- [x] `rollup.config.js` legacy standalone — still present but superseded by Phase 3 toolchain upgrade; can be deleted
- [x] `tasks/config.js` verified: no snappymail branding references

### 1.5 Integration packages (done: 2026-06-30)
- [x] Updated Nextcloud integration package: `integrations/nextcloud/snappymail/` → `integrations/nextcloud/tachyon/`, all OCA\SnappyMail → OCA\Tachyon, SnappyMailHelper → TachyonHelper, all Nextcloud config/session keys updated, app ID 'snappymail' → 'tachyon'
- [x] Updated OwnCloud integration: same rename pattern as Nextcloud
- [x] Updated plugins/nextcloud/index.php: OCA\Tachyon\Util\TachyonHelper reference
- [x] Updated Cloudron: Dockerfile (PHP 7.4 → 8.2, session paths, apache conf ref), DESCRIPTION.md
- [x] Updated cPanel: YAML renamed to webmail_tachyon.yaml, display name and URL paths updated
- [x] Updated HestiaCP: bin script renamed v-add-sys-tachyon, install dir renamed to `deb/tachyon/`, user-visible strings updated
- [x] Updated CyberPanel: PHP namespaces (RainLoop→Tachyon, SnappyMail→Tachyon\Util), env vars updated
- [x] Updated Virtualmin: script renamed tachyon.pl, all function names → script_tachyon_*, user-visible strings updated, PHP version 7→8
- [x] Note: Cloudron/HestiaCP download URLs still reference upstream — will need updating when Tachyon CI/CD publishes release tarballs (TODO in both files)
- [ ] Update Docker image names and labels in `docker-compose.yml` and `.docker/` (if present)

---

## Phase 2: PHP Modernization

### 2.1 Raise minimum PHP version (done: 2026-06-30)
- Current floor: PHP 7.4 (EOL Dec 2022)
- PHP 8.0 EOL: Nov 2023
- PHP 8.1 EOL: Dec 2025 (past)
- PHP 8.2 EOL: Dec 2026 (current recommendation)
- **Target: PHP 8.2 minimum, test on 8.3 and 8.4**

Tasks:
- [x] Updated `tachyon_util/integrity.php` (line 93): "7.4.0" → "8.2.0"
- [x] PHP 8 polyfill guard in `include.php` restored to `< 80000` (polyfill is for PHP 7.x only, inner guard makes it safe)
- [x] `polyfill/ctype.php` and `intl.php` kept (valid for environments without the extensions)
- [x] README updated with PHP 8.2 requirement

### 2.2 Remove PHP 7.x compatibility code (done: 2026-06-30)
- [x] `/* PHP7.4: ?self */` comment in `Tachyon/Model/AdditionalAccount.php` - type hint applied
- [x] `ini_set('register_globals', '0')` dead code removed from `include.php`
- [ ] Full audit: null-coalescing workarounds, remaining `array()` long-form, typed property coverage

### 2.3 PHP 8.x deprecated/removed functions (partial: 2026-06-30)
- [x] `openssl_pkey_free()` - confirmed resolved in prior commit `dea7f4d1d`
- [x] `register_globals` ini_set removed
- [x] `FILTER_SANITIZE_STRING` — audit completed 2026-06-30, zero usages found
- [x] Implicit null param audit — completed 2026-06-30, all params already use `?type` or untyped `$mDefault`
- [ ] `declare(strict_types=1)` audit for new and modified files

### 2.4 Modern PHP typing
- [ ] Add return type declarations to key public API methods
- [ ] Add union types where needed (PHP 8.0+)
- [ ] Use named arguments where clarity improves (PHP 8.0+)
- [ ] Use match expressions instead of complex switch blocks (PHP 8.0+)
- [ ] Use constructor property promotion where it simplifies code (PHP 8.0+)
- [ ] Use Fibers for any future async work (PHP 8.1+)
- [x] Use enum for state constants (PHP 8.1+) — done 2026-06-30 (Phase 5: ResponseType, StoreAction, MessagePriority, SignMeType, Layout)
- [ ] Use readonly properties (PHP 8.1+)

### 2.5 Security hardening in PHP
- [ ] Review all `eval()` usage (none expected, but verify)
- [ ] Audit file path handling for directory traversal
- [ ] Ensure all user-supplied data goes through sanitization before filesystem operations
- [ ] Verify CSP headers in `snappymail/http/csp.php` are up to date with modern CSP spec
  (comment in code notes `report-uri` is deprecated - switch to `report-to`)
- [ ] Audit all IMAP/SMTP credential handling
- [ ] Review session fixation protections

---

## Phase 3: JavaScript / Frontend Modernization

### 3.1 Build toolchain update (done: 2026-06-30)
- [x] Rollup upgraded from v2 → v4.28.1
- [x] `gulp-rollup-2` upgraded to v2.1.0 (supports rollup v4)
- [x] `eslint` upgraded to v9.17.0; `gulp-eslint` replaced with `gulp-eslint-new@2.6.2`
- [x] `eslint.config.js` created (ESLint v9 flat config format)
- [x] `@rollup/plugin-node-resolve` → v15.2.3, `@rollup/plugin-replace` → v5.0.5
- [x] Removed deprecated `rollup-plugin-terser`; replaced with `@rollup/plugin-terser`
- [x] Removed deprecated `babel-eslint`
- [x] `del@6` kept (v7+ is ESM-only, incompatible with CommonJS gulp tasks)
- [x] `gulp-filter@7` kept (v9 uses ESM default export incompatible with CommonJS)
- [x] Build verified working: produces admin.min.js (41kB), app.min.js (203kB), libs.min.js (110kB), openpgp.min.js (545kB), CSS files
- [x] 4 ESLint lint errors fixed in HtmlEditor.js and Storage/Client.js (unused catch params → ES2019 `catch {}`)
- [ ] `rollup.config.js` legacy file still present (root-level, not used by gulp pipeline) — delete or remove

### 3.2 Vendor library updates
- [ ] **KnockoutJS**: currently 3.5.1-sm (custom fork) - evaluate upgrade or migration path
- [ ] **OpenPGP.js**: currently v5 (custom fork) - check if upstream v6 has been released
- [ ] **Squire2**: custom fork of Squire HTML editor - check upstream for security fixes
- [ ] **marked**: check version and update (used for markdown rendering)
- [ ] **turndown**: check version (HTML to Markdown conversion)
- [ ] **normalize.css**: check current version
- [ ] **Sabre VObject/XML**: bundled PHP library - compare with upstream sabre/vobject

### 3.3 Modern JavaScript features
- [ ] Audit for ES2022+ features that can simplify code (at() method, Object.hasOwn, etc.)
- [ ] Review KnockoutJS dependency - long-term candidate for migration to reactive signals or Preact
  (KO is functional but dates from 2010; not blocking for v1 of Tachyon)
- [ ] Add TypeScript definitions/JSDoc types for better IDE support

### 3.4 Performance improvements
- [ ] Audit bundle sizes: current app.min.js ~202KB, libs.min.js ~110KB
- [ ] Add HTTP/2 Server Push hints for critical assets
- [ ] Review service worker (`serviceworker.js`) for offline capability improvements
- [ ] Implement proper caching strategy in service worker (currently just notifications)
- [ ] Add resource hints (preconnect, prefetch) for IMAP server connections
- [ ] Audit Critical Rendering Path - ensure boot.js and boot.css are minimal

---

## Phase 4: Review Upstream SnappyMail PRs

> Check open and recently-closed PRs on https://github.com/the-djmaze/snappymail for work to integrate.

- [ ] Pull the PR list (requires web access or gh CLI)
- [ ] Evaluate each PR for: correctness, security, compatibility with PHP 8.2+ target
- [ ] Categories to look for:
  - Bug fixes (high priority)
  - Language/translation updates (merge selectively)
  - Feature additions (evaluate individually)
  - Security fixes (always integrate)
  - Performance improvements (always evaluate)
- [ ] Document integration decisions in this roadmap under a "Integrated PRs" subsection

**Reviewed: 2026-06-30** — see findings below.

### Upstream PR Findings (reviewed 2026-06-30)

**Integrate immediately (security + critical bugs):**
- [x] PR #2039 — FIX non-compliant Autocrypt Header (removes spaces from armored PGP keys)
- [x] PR #2037 — Fix Mime/Parser header detection (PGP inline decrypt) + `Mime/Utils.js` fix
- [x] PR #2007 — Fix OIDC login: SensitiveString type mismatch causing SSO regression
- [x] PR #2024 — Fix login-remote plugin password handling (SensitiveString compat)
- [x] PR #2019 — Fix Search Filters plugin crash on keyword filters (IMAP error handling)
- [x] PR #2012 — Fix typo in imapsync.php: covered by our namespace rename
- [x] PR #2011 — Fix typo in SSLContext.php: covered by our namespace rename
- [x] PR #1981 — Fix JS error when forwarding emails as attachments (`t.decrypt undefined`)
- [x] PR #1974 — Fix docker command syntax in cli/release.php (N/A: we use our own Docker setup)
- [x] PR #1973 — Add pdo_sqlite to Docker image (deferred: update when CI/CD Docker image is built)
- [x] PR #1922 — Fix nginx IPv6 listening in IPv4-only environments (containerized deployments)

**Evaluate and integrate:**
- [x] PR #2035 — Add HTTP-based SSO plugin for Apache Basic Auth integration (added `plugins/login-http/`)
- [x] PR #1882 — LDAP login mapping now sets `$sSmtpUser` alongside `$sImapUser`
- [x] PR #2052 — Basque language update (translation)

**Skip for now:**
- PR #2034 — Nextcloud webDAV API update (review after Phase 1.5 is stable)
- PR #2021 — Office365/Outlook OAuth2 refactor (needs careful testing)
- PR #2001/#1999 — Nextcloud 32+ compatibility (revisit when supporting NC32+)
- PR #1963 — PGP improvements (passphrase caching, key deletion) — complex, potential conflicts
- PR #1879 — S/MIME intermediate certificates — low priority
- PR #1227 — Nextcloud address book integration — 2+ years old, likely conflicts

---

## Phase 5: Review Upstream Branches (done: 2026-06-30)

**Reviewed: 2026-06-30**

**Worth integrating:**
- [x] **`php81` branch** (7 commits ahead): PHP 8.1 modernization converting state constants to PHP enums (SignMeType, ResponseType, StoreAction, MessagePriority, etc.). Applied 2026-06-30 in commit `165d74d29`.

**Skip:**
- **`sieve-gui`** (20 commits ahead): Experimental Sieve GUI, WIP as of Sept 2024. Wait for completion upstream or build our own in Phase 6.
- **`plugin-hooks`** (5 commits): Very old (2021-2022), heavy merge conflicts, likely superseded.
- **`UserMailTemplates`** (3 commits): 2021, conflicts likely.
- **`contacts-screen`**, **`feature/popupmessage`**, **`gmail-additionalaccount`**, **`messagelist-infinite-scroll`**: minimal work, stale.

---

## Phase 6: Feature Additions (Modern UX Focus)

### 6.1 UI/UX modernization
- [ ] **Responsive layout**: full mobile-first redesign of the 3-panel layout
  - Current mobile handling uses CSS @media but panel switching is basic
  - Target: swipeable panels, bottom navigation bar on mobile
- [ ] **Modern theme**: new default theme (light + dark) using CSS custom properties
  - Currently uses LESS variables compiled to static values
  - Switch to CSS custom properties for runtime theming without recompile
- [ ] **Virtual scrolling**: for large mailboxes (thousands of messages)
  - Current: paged loading; target: infinite scroll with DOM recycling
- [ ] **Conversation threading**: group related emails by subject/references
  - Major missing feature vs Gmail/Fastmail UX
- [ ] **Preview pane resize**: draggable split pane between list and preview
- [ ] **Keyboard navigation improvements**: full keyboard-driven workflow
- [ ] **Command palette**: Cmd+K / Ctrl+K quick action launcher
- [ ] **Undo send**: delay sending with cancel option (5-30 seconds configurable)

### 6.2 Email rendering
- [ ] **Better HTML email sanitization**: review DOMPurify integration or equivalent
- [ ] **Email proxy for remote images**: privacy-preserving image proxy to avoid tracking pixels
  (currently has image blocking but no proxy)
- [ ] **Improved attachment previews**: inline PDF, image gallery
- [ ] **Better plain-text rendering**: auto-link URLs, quoted-text collapsing

### 6.3 Search and filtering
- [ ] **Full-text search UI**: expose IMAP SEARCH capabilities better
- [ ] **Saved searches / smart folders**: persist common search queries
- [ ] **Advanced filter rules**: UI for Sieve scripts (partially present, needs polish)

### 6.4 Contacts and calendar
- [ ] **CardDAV**: improve contact management UI
- [ ] **CalDAV**: basic calendar event handling from email invites (ICS)
- [ ] **Autocomplete improvements**: faster contact lookup, fuzzy matching

### 6.5 Security features
- [ ] **2FA/TOTP**: per-user TOTP for login (infrastructure partially exists: `snappymail/totp.php`)
- [ ] **Password strength indicator**: on change-password plugin
- [ ] **Login audit log**: per-user login history visible in settings
- [ ] **S/MIME support**: alongside existing PGP
- [ ] **Biometric login**: WebAuthn/Passkey support for supporting browsers

### 6.6 Performance features
- [ ] **IMAP IDLE push**: real-time new mail notification without polling
  (check existing serviceworker push implementation)
- [ ] **Prefetching**: prefetch next page of messages on scroll
- [ ] **Selective sync**: choose which folders to sync/display
- [ ] **WebP/AVIF avatar support**: use modern image formats

### 6.7 Unified inbox (investigated 2026-06-30: NO-GO)

> **Decision: No-go without major architectural overhaul. Estimated 3+ months. Too risky for v1.**

Investigation findings (2026-06-30):

**Architecture today:** Only one IMAP connection active per session via a shared `MailClient()` singleton. The `ImapClient` is bound to a single account's credentials. Switching accounts disconnects and reconnects — concurrent multi-account fetching is not possible in the current design.

**Critical blockers:**
1. Single-connection constraint: `initMailClientConnection()` logs into one account at a time. Parallel IMAP requires pooling `ImapClient` instances (major refactor).
2. Message identity coupled to current account context: `encodeRawKey` bakes the account hash into every message reference. Frontend `MessagelistUserStore` is a single observable array, not per-account.
3. All message operations (flag, delete, move) assume single active account — would need per-message account routing.

**Alternative for v1:** A "quick-switch" UI showing unread counts across all accounts with one-click switching. Far less work than true unified inbox, and useful immediately.

### 6.8 Admin improvements
- [ ] **Admin dashboard**: usage stats, storage per user, active sessions
- [ ] **Plugin marketplace UI**: better in-app plugin discovery (framework exists in repository.php)
- [ ] **Domain configuration wizard**: guided setup for new mail domains
- [ ] **Health check endpoint**: `/health` JSON endpoint for monitoring systems

---

## Phase 7: Security Hardening

- [x] Fix CSP `report-to` implementation (was buggy — removed then re-added `report-uri`; now sends both `Reporting-Endpoints` + `report-to` directive and `report-uri` fallback)
- [x] Add `Permissions-Policy` header: deny camera, microphone, geolocation, payment, usb
- [x] Implement Subresource Integrity (SRI) hashes for all static assets (gulp `sri` task → `static/sri.json`; PHP injects into CSS `<link>` and AppData JSON; boot.js applies to dynamically loaded JS)
- [ ] Audit CORS settings
- [ ] Add rate limiting hooks for auth endpoints
- [ ] Review and document threat model for public deployments

---

## Phase 8: Developer Experience

- [x] Add GitHub Actions CI pipeline: `ci.yml` (PHP syntax check + JS/CSS lint on every push/PR)
- [x] Update Docker workflows: action versions v4/v5/v6, gha cache, remove DockerHub push, Tachyon labels
- [ ] Add `composer.json` for proper PHP dependency management (currently manual bundling)
- [ ] Add `.devcontainer` configuration for VS Code
- [ ] Update `.editorconfig` for PHP 8 style
- [ ] Add PHPStan or Psalm for static analysis
- [ ] Add Rector for automated PHP upgrade refactoring (especially for `declare(strict_types=1)` and constructor property promotion batches)

---

## Decisions Log

| Date | Decision | Reason |
|------|----------|--------|
| 2026-06-30 | Keep `snappymail/` directory name on disk for v1 of Tachyon | SnappyMail upgrade path compatibility |
| 2026-06-30 | Rename `RainLoop\` namespace to `Tachyon\`, `SnappyMail\` to `Tachyon\Util\` | Cleaner branding; no public API contract to maintain |
| 2026-06-30 | `snappymail/` library dir renamed to `tachyon_util/` (lowercase, for autoloader compat) | Avoids directory merge conflicts; default spl_autoload handles lowercase lookup |
| 2026-06-30 | `SNAPPYMAIL_INCLUDE_AS_API` / `SNAPPYMAIL_UPDATE_PLUGINS` kept as fallbacks | External tools (Nextcloud, cPanel) may still set the old env var names |
| 2026-06-30 | PHP 8.2 minimum | 8.1 reached EOL Dec 2025; 8.2 is LTS until Dec 2026 |
| 2026-06-30 | Version bump to 3.0.0 | Breaking: PHP 8.2 req + namespace rename; clean major version signal |
| 2026-06-30 | Keep KnockoutJS for now | Migration is a major effort; not blocking for v1 |
| 2026-06-30 | Unified inbox: no-go for v1 | Single IMAP connection per session, message IDs tied to account, frontend stores single-account; full impl ~3 months |

---

## Integrated PRs from Upstream

Applied 2026-06-30 in commit `3e1886903` (and `ab337c05a`):
- #2039 Autocrypt header fix
- #2037 MIME parser fix + Mime/Utils.js headRaw check
- #2007 OIDC SensitiveString fix
- #2024 login-remote SensitiveString fix
- #2019 Search Filters crash fix
- #1981 JS forward-as-attachment fix
- #1922 nginx IPv6 fix
- #2052 Basque (eu) translations
- #1882 LDAP mapping now covers SMTP user
- #2035 New `plugins/login-http/` Apache HTTP Basic Auth SSO

---

## Integrated Branch Content from Upstream

Applied 2026-06-30 in commit `165d74d29`:
- `php81` branch: 5 enum conversions (ResponseType, StoreAction, MessagePriority, SignMeType, Layout) + 9 caller files updated

---

## Next Actions (for next agent session)

### Priority 1 — Phase 3.2 Vendor library updates (remaining)
- **turndown**: v7.2.0 ES2020 fork. Audited: only local change is ES2020 conversion; v7.2.1–7.2.4 are incremental patch fixes with no changelog access. **Skip backporting** — no known breakage, cost exceeds benefit.
- **OpenPGP.js**: Updated to v5.11.3 (done). V6.x is a major API change — defer.
- **Squire2**: Audited against upstream squire-rte@2.4.8 for security issues. Our fork's `linkRegExp` already restricts paste-detected URLs to `https?://`, `ftps?://`, `www.` prefixes, and email patterns — `javascript:` injection via paste is not possible. False positives noted; no action needed. Full sync to upstream would lose SnappyMail-specific patches and requires 1–2 days — defer.

### Priority 2 — Phase 6 features
- **6.1 Multi-account unread badge** (done): total unread count now shows on account switcher button; individual account unread counts in dropdown menu were already showing.
- **6.1 Responsive mobile layout**: `@maxMobileWidth: 799px` is the breakpoint. Currently only dialog sizing is mobile-specific; the main 3-panel layout likely breaks on small screens. Audit and document.
- **6.6 IMAP IDLE / push notifications**: check `serviceworker.js` for push notification infrastructure; scope if true IMAP IDLE or SSE-based check can be added.
- **6.5 Command palette** (Cmd+K / Ctrl+K): high-value feature for power users.

### Priority 3 — Phase 2.4 remaining PHP modernization
- **`declare(strict_types=1)`**: defer to batch effort via Rector (Phase 8) with actual test coverage.
- **Additional enums**: `MessageFlag` (complex: some callers pass arbitrary strings), `Capa` (complex: plugin-extensible string keys), `PluginPropertyType` (134 usages). All have blockers. Skip until there's a test suite.

### Priority 4 — Phase 7/8 remaining
- **Phase 7**: SRI hashes done. Remaining: CORS audit, rate limiting hooks for auth endpoints.
- **Phase 8**: `.devcontainer` for VS Code, PHPStan integration.

### Git log (commits on master as of 2026-06-30)
1. `fa4093cba` rebrand: rename release artifact prefix snappymail- → tachyon-
2. `fa6b7cfc5` rebrand: update FUNDING.yml to Tachyon maintainer
3. `69d4f9af0` ci: add code quality workflow, update Docker workflows for Tachyon
4. `5705d9232` feat: show total unread count badge on account switcher dropdown
5. `b8dec2ce2` security: fix CSP report-to implementation and add Permissions-Policy header
6. `74579b64b` vendor: remove unused marked.js library
7. `aefe85f05` roadmap: unified inbox NO-GO decision
8. `7d5d8f9b3` php: convert DkimStatus abstract class to PHP 8.1 string-backed enum
9. `8fcece61b` rebrand: docker-compose.yml and Dockerfile label
10. `d7135a175` vendor: update OpenPGP.js v5.11.1 → v5.11.3
