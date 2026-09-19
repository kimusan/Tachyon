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
2. Install node.js - `https://nodejs.org/download/`
3. Install yarn - `https://yarnpkg.com/en/docs/install`
4. Install gulp - `npm install gulp -g`
5. Fork Tachyon from https://github.com/kimusan/tachyon
6. Clone it - `git clone git@github.com:USERNAME/tachyon.git tachyon`
7. `cd tachyon`
8. Install all dependencies - `yarn install`
9. Run gulp - `gulp`

---

**Debugging JavaScript**

1. Edit data/\_data_/\_default_/configs/application.ini
2. Set 'use_app_debug_js' (and optionally 'use_app_debug_css') to 'On'

---

**Editing HTML Template Files**

1. Edit data/\_data_/\_default_/configs/application.ini
2. Set `[cache] system_data` to Off

**Release**

1. Install gzip
2. Install brotli
3. php release.php

Options:
* `php release.php --aur` = Build Arch Linux package
* `php release.php --docker` = Build Docker instance
* `php release.php --plugins` = Build plugins

---

If you have any questions, open an issue on GitHub.
