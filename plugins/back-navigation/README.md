# back-navigation

Tachyon (SnappyMail fork) plugin: the browser's **Back button closes the opened message** and returns to the list,
like Gmail. Opening a message pushes `#/mailbox/<folder>/m<uid>` into the history; Back, or the close button, pops it.
Side effect: that hash is a working link while the message stays in its folder (F5, or pasted by a colleague who can
open the same account). Client-side only, no server action, no core template overridden.
French: [README.fr.md](README.fr.md).

- **What the core already does**: the route `#/mailbox/<folder>/m<uid>(/<search>)` is parsed by `MailBoxUserScreen`
  (`routes()` in `app.js`) and opens that message — but nothing ever writes it: opening a message from the list leaves
  the hash untouched, so Back leaves the webmail.
- **Client** (`back-navigation.js`), on `rl-view-model` for `MailMessageView`, subscribes to its `message` observable:
  - message opened from the list → `hasher.setHash('mailbox/<folder>/m<uid>[/<search>]')` with the core routing paused
    (`rl.route.off()/on()`), otherwise the core would fetch the message and reload the list a second time. `<folder>`
    is the message's own folder (same rule as `FolderModel.fullNameHash`), not the shown one: a global-search result
    from another folder gets an exact hash. The page segment (`p<n>`) is dropped (the message route has none); the
    thread suffix (`~<uid>`) is kept when the message sits in the shown folder.
  - `hashchange` while a message is shown and the hash no longer names it (Back, Forward, any route) → `closeMessage()`.
    The core routes the new hash itself, and reloads the list as it does for every hash change.
  - message closed (close button, or by the core) while the hash still names it → `history.back()` with the routing
    paused until the `hashchange` (one-second safety timer), so the close is exactly the core's, without list reload,
    and the history stays clean. When the message was opened by the route itself (direct link, F5, Forward) nothing was
    pushed: the hash is replaced (`hasher.replaceHash`) without a history entry.
- **Requirements**: Tachyon 4.x. Relies on core internals — the `MailMessageView` view model (`message`,
  `closeMessage`), `hasher` (libs.js) and `rl.route`, the `fullNameHash` rule — re-check them after every core upgrade.
- **Limits**: the hash names an IMAP uid, which belongs to a folder: a link dies once the message is moved or archived.
  A durable link would need a Message-ID route resolved server-side (IMAP `SEARCH HEADER Message-ID` or a search
  index): out of scope. Coming back to the list by Back reloads it (core behaviour on any route); the close button
  does not.
- **Install**: copy the folder to the plugins directory, enable `back-navigation` in the admin panel (or
  `plugins.enabled_list`). No setting.
- **Test**: checked by hand and by an end-to-end browser script kept in our own repository (open, Back, close
  button then Forward, direct link, no JS error); no PHP test, the plugin has no server side.
- **License**: AGPL-3.0 (see [LICENSE](LICENSE)).
