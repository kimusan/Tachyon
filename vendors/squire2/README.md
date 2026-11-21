# Squire

`dist/squire-raw.js` is built, not edited. Change `tachyon.patch` and rebuild.

Source: the `snappymail` branch of https://github.com/the-djmaze/Squire at
2704574, with https://github.com/fastmail/Squire master merged in at a2dee68
(v2.4.11). In the merge, `source/Clean.ts` takes fastmail's side, including
`<a>` as an allowed block, which the snappymail branch had lost.

`tachyon.patch` holds Tachyon's own fixes on top, each explained where it sits:

- `Clean.ts` cleanupBRs: a BR that is a fragment's last child threw (#117)
- `range/InsertDelete.ts`: inserting beside a link left an empty link behind
- `keyboard/Space.ts`: "1. " and "* " list detection lost the typed text (#5)
- `Editor.ts`: Enter with no block, and Shift+Enter at the end of a line,
  made no line in Firefox (#165)

To rebuild:

    git clone -b snappymail https://github.com/the-djmaze/Squire.git && cd Squire
    git checkout 2704574
    git fetch https://github.com/fastmail/Squire.git master
    git merge a2dee68   # resolve Clean.ts as above
    git apply /path/to/tachyon.patch
    npm ci && node build.js
    cp dist/squire-raw.js /path/to/tachyon/vendors/squire2/dist/

`plugins/compact-composer/js/squire-raw.js` is a separate, plain fastmail
build exposed as `window.Squire2`, and is not built from this.
