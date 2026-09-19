**Thanks for contributing to Tachyon Webmail!**

1. Fork the repo, do work in a feature branch.
2. Issue a pull request.

---

**Translations**

Translating needs none of the setup below. Tachyon is translated on
[Hosted Weblate](https://hosted.weblate.org/engage/tachyon-webmail/): pick a
language, translate the strings you recognise, and Weblate opens the pull
request for you. No fork, no clone, no toolchain.

The interface, the admin panel and the plugins that ship their own strings are
all there. Partial work is welcome, since anything left untranslated falls back
to English rather than breaking.

Please do not edit the files under `tachyon/v/0.0.0/app/localization/` by hand.
Weblate owns them, and a manual change there will be overwritten or will
conflict with the next sync. English is the exception: new strings are added to
the `en/` files with the code that uses them, and Weblate picks them up from
there.

---

**Getting started**

1. Install PHP 8.2+
2. Install node.js - `https://nodejs.org/download/` (CI builds on Node 20)
3. Install yarn - `https://yarnpkg.com/en/docs/install`
4. Fork Tachyon from https://github.com/kimusan/Tachyon
5. Clone it - `git clone git@github.com:USERNAME/Tachyon.git Tachyon`
6. `cd Tachyon`
7. Install all dependencies - `yarn install`
8. Build - `npx gulp`

Gulp is a project dependency, so `npx gulp` uses the pinned version. There is no
need to install it globally, and a global copy of a different major version will
fight the local one.

Useful targets: `npx gulp lint` for the checks CI runs, `npx gulp build` for
everything, `npx gulp i18n` to see what each translation is missing.

---

**Debugging JavaScript**

Edit `data/_data_/_default_/configs/application.ini` and set, under `[debug]`:

```ini
javascript = On
css = On
```

The old `use_app_debug_js` and `use_app_debug_css` names still work, but they
are quietly rewritten to the two above, so it is worth using the current ones.

---

**Editing HTML Template Files**

Edit `data/_data_/_default_/configs/application.ini` and set `system_data = Off`
under `[cache]`, otherwise compiled templates are served from cache and your
edits will not appear.

**Release**

1. Install gzip
2. Install brotli
3. `php release.php`

Plugins are always built, so there is no switch for them. The rest are opt in:

* `--skip-gulp` = reuse the current build output instead of rebuilding the assets
* `--sign` = sign the artifacts with the release key
* `--debian` = Debian package and apt repository metadata
* `--aur` = Arch Linux package
* `--docker` = Docker image
* `--nextcloud`, `--owncloud`, `--cpanel` = the matching integration archives

Releases are normally cut by the GitHub Actions workflow rather than by hand;
see `.github/workflows/release.yml`.

---

If you have any questions, open an issue on GitHub.
