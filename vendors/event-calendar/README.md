# EventCalendar

Vendored from the npm package [`@event-calendar/build`](https://www.npmjs.com/package/@event-calendar/build)
version **5.12.0**, upstream <https://github.com/vkurko/calendar>, MIT licensed.

`@event-calendar/build` is the standalone bundle: Svelte is compiled in and it has
no runtime dependencies, so it exposes a global `EventCalendar` and needs no module
loader. Do not swap it for `@event-calendar/core`, whose `dist/index.js` imports
`svelte`, `svelte/internal/client` and `svelte/reactivity` at runtime.

Both files are the upstream build output, copied unmodified. Tachyon loads them on
demand when the calendar screen is opened, not as part of `libs.js`.

To update, bump the version and verify the tarball against the registry checksum:

    npm pack @event-calendar/build@<version>
