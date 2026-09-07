# Stalwart DAV autoconfiguration

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
| `carddav_url` | CardDAV base URL. Empty leaves `contacts_sync` alone. |
| `caldav_url` | CalDAV base URL. Empty leaves `calendar_sync` alone. |

The URLs are **base** URLs, not per-user ones. Tachyon discovers the account's
own collection from them via `current-user-principal` and the home set, so one
constant serves every account. `test/dav-discovery.php` in this repository shows
what that discovery returns for a given server and credential.

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
