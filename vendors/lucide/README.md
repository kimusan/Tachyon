# Lucide

Icons taken from the npm package [`lucide-static`](https://www.npmjs.com/package/lucide-static)
version **1.33.0**, upstream <https://github.com/lucide-icons/lucide>, ISC licensed.

Only the icons Tachyon actually uses are vendored, not all 2034. `icons.css` is
generated from them by `tasks/icons.js` and is rebuilt on every `gulp build`.

The icons are drawn with strokes rather than fills. They are applied as CSS
masks tinted with `background-color: currentColor`, so they follow the active
theme; `stroke="currentColor"` is baked to a solid colour in the generated CSS
because `currentColor` does not resolve inside a mask.

To add one, copy its `.svg` here from the package above and reference it by
file name as `data-icon="…"` or `class="icon-…"`.
