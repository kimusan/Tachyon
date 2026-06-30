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
- [ ] Audit for `FILTER_SANITIZE_STRING` usage (deprecated PHP 8.1)
- [ ] Audit for implicit null param passing to non-nullable typed params (deprecated PHP 8.1)
- [ ] `declare(strict_types=1)` audit for new and modified files

### 2.4 Modern PHP typing
- [ ] Add return type declarations to key public API methods
- [ ] Add union types where needed (PHP 8.0+)
- [ ] Use named arguments where clarity improves (PHP 8.0+)
- [ ] Use match expressions instead of complex switch blocks (PHP 8.0+)
- [ ] Use constructor property promotion where it simplifies code (PHP 8.0+)
- [ ] Use Fibers for any future async work (PHP 8.1+)
- [ ] Use enum for state constants (PHP 8.1+)
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
- [ ] PR #2039 — FIX non-compliant Autocrypt Header (removes spaces from armored PGP keys)
- [ ] PR #2037 — Fix Mime/Parser header detection (PGP inline decrypt for plain-text messages)
- [ ] PR #2007 — Fix OIDC login: SensitiveString type mismatch causing SSO regression
- [ ] PR #2024 — Fix login-remote plugin password handling (SensitiveString compat)
- [ ] PR #2019 — Fix Search Filters plugin crash on keyword filters (IMAP error handling)
- [ ] PR #2012 — Fix typo in imapsync.php: `RainLoop\API` → `RainLoop\Api` (already fixed in our rename)
- [ ] PR #2011 — Fix typo in SSLContext.php: same class reference typo (check if our rename covers it)
- [ ] PR #1981 — Fix JS error when forwarding emails as attachments (`t.decrypt undefined`)
- [ ] PR #1974 — Fix docker command syntax in cli/release.php
- [ ] PR #1973 — Add pdo_sqlite to Docker image
- [ ] PR #1922 — Fix nginx IPv6 listening in IPv4-only environments (containerized deployments)

**Evaluate and integrate:**
- [ ] PR #2035 — Add HTTP-based SSO plugin for Apache Basic Auth integration
- [ ] PR #1882 — Use LDAP login mapping for SMTP too (after IMAP mapping was added)
- [ ] PR #2052 — Basque language update (translation)

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
- [ ] **`php81` branch** (7 commits ahead): PHP 8.1 modernization converting state constants to PHP enums (SignMeType, ResponseType, StoreAction, MessagePriority, etc.). Last activity Feb 2024. Cherry-pick enum conversions that don't conflict with our Phase 2 work.

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

### 6.7 Unified inbox (investigate feasibility)

> **Complexity: potentially very high. May not be feasible without major architectural changes.**

SnappyMail already supports multiple signed-in accounts. The idea is a virtual "All Inboxes" view that
aggregates messages from all of them in a single chronological feed.

- [ ] Audit how multi-account is currently handled in `RainLoop\Model\AdditionalAccount` and storage
- [ ] Determine if IMAP connections for all accounts can be held open concurrently per session
- [ ] Assess KnockoutJS store/view model changes needed to merge multiple account message lists
- [ ] Assess whether the folder tree and message actions (reply, move, delete) can route back
  to the correct account transparently
- [ ] Consider a read-only unified view first (display only, no compose/move from unified view)
- [ ] Document decision: go/no-go with rationale once investigation is complete

Blockers to watch for: session/storage model is per-primary-account, IMAP connection pooling
is not currently abstracted, and the JS store model assumes a single active account context.

### 6.8 Admin improvements
- [ ] **Admin dashboard**: usage stats, storage per user, active sessions
- [ ] **Plugin marketplace UI**: better in-app plugin discovery (framework exists in repository.php)
- [ ] **Domain configuration wizard**: guided setup for new mail domains
- [ ] **Health check endpoint**: `/health` JSON endpoint for monitoring systems

---

## Phase 7: Security Hardening

- [ ] Add `Content-Security-Policy` header with strict-dynamic
- [ ] Switch from `report-uri` to `report-to` in CSP (noted as deprecated in codebase)
- [ ] Implement Subresource Integrity (SRI) hashes for all static assets
- [ ] Review `Permissions-Policy` headers (camera, microphone, geolocation)
- [ ] Audit CORS settings
- [ ] Add rate limiting hooks for auth endpoints
- [ ] Review and document threat model for public deployments

---

## Phase 8: Developer Experience

- [ ] Add `composer.json` for proper PHP dependency management (currently manual bundling)
- [ ] Add GitHub Actions CI pipeline: PHP lint, JS lint, PHPUnit, build check
- [ ] Add `.devcontainer` configuration for VS Code
- [ ] Update `.editorconfig` for PHP 8 style
- [ ] Improve `docker-compose.yml` dev setup documentation
- [ ] Add PHPStan or Psalm for static analysis
- [ ] Add Rector for automated PHP upgrade refactoring
- [ ] Update `.browserslistrc`: drop Firefox 78+ (ESR is now 115+), raise Chrome to 90+

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

---

## Integrated PRs from Upstream

_Populated during Phase 4_

---

## Integrated Branch Content from Upstream

_Populated during Phase 5_

---

## Next Actions (for next agent session)

### Priority 1 — Integrate critical upstream bug fixes (Phase 4)
The upstream PR review identified 11 security/critical bug PRs to integrate. Start with the ones that touch
actual bugs (not just typos our namespace rename already fixed):
- PR #2039: Autocrypt header fix (`MailSo/Mime/Header/Autocrypt.php` — remove spaces from armored keys)
- PR #2037: MIME parser header detection fix (PGP inline decrypt)
- PR #2007: OIDC SensitiveString type fix
- PR #2024: login-remote plugin SensitiveString fix
- PR #2019: Search Filters plugin crash
- PR #1981: JS forward-as-attachment error (`t.decrypt undefined`)
- PR #1922: nginx IPv6 fix

For each: `gh pr view N --repo the-djmaze/snappymail` to get the diff, then apply manually.

### Priority 2 — Cherry-pick php81 branch enums (Phase 5)
The `php81` branch has PHP enum conversions for state constants. Review with:
`gh api repos/the-djmaze/snappymail/compare/master...php81 --jq '.commits[].commit.message'`
Then cherry-pick relevant enum files.

### Priority 3 — Phase 2 remaining PHP modernization
- `grep -rn "FILTER_SANITIZE_STRING" snappymail/` (deprecated PHP 8.1)
- Implicit null param audit
- Start adding `declare(strict_types=1)` to new files

### Priority 4 — Delete legacy rollup.config.js
The root-level `rollup.config.js` uses deprecated plugins and is not used by the build pipeline.
Safe to delete: `git rm rollup.config.js`

### Git log (commits on master)
1. `8750ac7` rebrand: rename project to Tachyon (metadata and docs)
2. `854e850` rebrand: rename SnappyMail -> Tachyon in JS, LESS, and static JSON
3. `02ac9e0` rebrand: rename PHP namespaces and modernize PHP floor
4. `9536bde` roadmap: update status after Phase 1 and 2 initial work
5. `835c079` rebrand: rename integration packages to Tachyon (Phase 1.5)
6. `b1081f9` rebrand: fix Nextcloud/OwnCloud app ID, session keys, and file rename
7. `435399f` build: upgrade to Rollup v4, ESLint v9 flat config, fix JS lint errors
