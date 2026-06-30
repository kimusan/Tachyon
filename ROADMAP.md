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

### 1.4b Build scripts (discovered 2026-06-30)
- [ ] `gulpfile.js` and `tasks/*.js` still have `/* RainLoop Webmail (c) RainLoop Team */` header comment
- [ ] `tasks/js.js:89` has a `replace("snappymail/v/...")` regex tied to the static JS path — keep as-is while `snappymail/` directory is preserved; revisit when directory is eventually renamed
- [ ] `rollup.config.js` is a legacy standalone config (not used by current gulp pipeline) — uses deprecated `rollup-plugin-babel`, `rollup-plugin-terser`; either remove or align with tasks/rollup.js in Phase 3
- [ ] `tasks/config.js` likely still references snappymail paths — audit

### 1.5 Integration packages
- [ ] Update Nextcloud integration package: rename `integrations/nextcloud/snappymail/` -> `integrations/nextcloud/tachyon/` with appid change
- [ ] Update Cloudron, cPanel, HestiaCP, CyberPanel, OwnCloud, Virtualmin metadata files
- [ ] Update Docker image names and labels in `docker-compose.yml` and `.docker/`

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

### 3.1 Build toolchain update
- [ ] Audit `package.json` dependencies for outdated versions (current: rollup ^2.56.3, gulp 5, eslint 7)
- [ ] Update rollup to v4.x (current latest)
- [ ] Update `@rollup/plugin-node-resolve` to v15.x
- [ ] Update `@rollup/plugin-replace` to v5.x
- [ ] Update `eslint` to v9.x (major API change - flat config required)
- [ ] Update `gulp-terser` to latest
- [ ] Update `gulp-clean-css` to latest
- [ ] Update `gulp-less` to latest
- [ ] Remove `babel-eslint` (deprecated - replaced by `@babel/eslint-parser` but may not be needed with ESLint 9)
- [ ] Update `rollup-plugin-terser` (deprecated - use `@rollup/plugin-terser` instead)

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

**How to check:** `gh pr list --repo the-djmaze/snappymail --state open --limit 50`

---

## Phase 5: Review Upstream Branches

> Check non-master branches on https://github.com/the-djmaze/snappymail for ahead-of-master work.

- [ ] List all remote branches: `gh api repos/the-djmaze/snappymail/branches --paginate`
- [ ] Compare each branch against master for commit count and content
- [ ] Focus areas:
  - Dev/experimental features
  - Platform-specific branches (nextcloud, etc.)
  - Feature branches with completed but unmerged work
- [ ] Document findings here

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

### Immediate (Phase 1.5 — Integration packages)
The `integrations/` directory contains Nextcloud, Cloudron, cPanel, HestiaCP, OwnCloud, Virtualmin packages.
The Nextcloud integration (`integrations/nextcloud/snappymail/`) still uses `OCA\SnappyMail` namespace internally
(left intact intentionally - requires coordinated rename of appid, directory name, l10n files, app manifest).

Steps:
1. Rename `integrations/nextcloud/snappymail/` dir to `integrations/nextcloud/tachyon/`
2. Update `appinfo/info.xml`: `<id>snappymail</id>` → `<id>tachyon</id>`, name, description
3. Update all `OCA\SnappyMail\` → `OCA\Tachyon\` inside the app
4. Update `plugins/nextcloud/index.php` line 96: revert `OCA\SnappyMail\Util\SnappyMailHelper` to `OCA\Tachyon\Util\TachyonHelper` (after renaming the helper class too)
5. Update other integration packages similarly

### Phase 3 — Build toolchain update
- Update `package.json` deps (already updated version numbers, but yarn.lock needs refresh: `yarn upgrade`)
- `rollup.config.js` is stale/legacy — either align with `tasks/rollup.js` or remove
- `gulpfile.js` and `tasks/*.js` still have "RainLoop Webmail" copyright headers
- `tasks/js.js:89` has `snappymail/v/` path string — acceptable while disk path is preserved
- Run `yarn install` then `gulp build` to verify the build still works after namespace changes

### Phase 4 — Review upstream PRs
- `gh pr list --repo the-djmaze/snappymail --state open --limit 50`

### Phase 5 — Review upstream branches
- `gh api repos/the-djmaze/snappymail/branches --paginate`

### Phase 2 remaining items
- FILTER_SANITIZE_STRING audit
- Implicit null param audit
- strict_types coverage

### Git log (commits so far)
1. `8750ac7` rebrand: rename project to Tachyon (metadata and docs)
2. `854e850` rebrand: rename SnappyMail -> Tachyon in JS, LESS, and static JSON
3. `02ac9e0` rebrand: rename PHP namespaces and modernize PHP floor
