# DAV autoconfiguration

Writes `contacts_sync` and `calendar_sync` at `login.success` for accounts on an
allow list, so DAV sync can be configured for everyone rather than by hand per
user.

It exists because those files cannot be written from outside a session: the
password is sealed with the account's `CryptKey`, which is unsealed by the login
password and so exists only inside an authenticated session.

## Settings

| Key | Meaning |
|---|---|
| `allow_list` | Addresses or domains, comma or whitespace separated. Empty configures nobody. |
| `carddav_url` | Full collection URL. `{email}` becomes the percent-encoded address. Empty leaves `contacts_sync` alone. |
| `caldav_url` | CalDAV **base** URL. Empty leaves `calendar_sync` alone. |

For example, against Stalwart:

```
carddav_url = https://mail.example.com/dav/card/{email}/default/
caldav_url  = https://mail.example.com/dav/cal
```

The two are deliberately different shapes.

**Contacts are pinned to a named collection.** Given only a base URL, Tachyon
discovers the address book, and when no collection is named `contacts`,
`default`, `addressbook` or `address book`, it falls through to taking whichever
the server listed first. An account with two address books, neither of which
matches, can then land on a different one each sync, and the order is not
guaranteed stable. A sync landing on the empty one sees every local contact as
deleted-elsewhere and removes it. Naming the collection outright skips discovery
entirely.

**Calendars are not pinned.** Calendar sync enumerates and syncs *every*
collection on the account rather than choosing one, so there is no selection
hazard, and pinning would hide events living in an account's other calendar.

`test/dav-discovery.php` shows what discovery returns for a given server and
credential, which is how to check what an account actually has.

## Behaviour worth knowing

- It writes on **every** login, which is what re-seals the password after a
  password change. A user who disables sync in Settings has it re-enabled at
  their next login; remove them from `allow_list` to opt them out.
- It never throws into the login path. A failure is logged and the login
  proceeds.
- `Mode` is always `1` (read + write). On a first sync that uploads every local
  contact and deletes none, because deletion is guarded on an etag that only
  exists after a successful upload.
- Sync itself runs on a browser timer, not a daemon. Nothing synchronises for a
  user who does not open webmail.

## Do not repoint a live account's URL

Once a first sync has stamped etags, a later sync against a different, empty
collection will delete that user's local contacts. Get the URL right before the
first sync.
