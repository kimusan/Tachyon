# Fail2ban Instructions

Tachyon can report failed logins either to the system log or to a file of its
own. There is a filter and a jail here for each.

If you use ports other than http, https and 2096, change them in `/jail.d/*.conf`.

## Systemd journal PHP-FPM

Enable auth logging to syslog in
`/PATH-TO-TACHYON-DATA/_data_/_default_/configs/application.ini`:

```
[logs]
auth_syslog = On
```

Copy the following to `/etc/fail2ban/`:

- `/filter.d/tachyon-fpm-journal.conf`
- `/jail.d/tachyon-fpm-journal.conf`

Add to `/etc/fail2ban/jail.local`:

```
[tachyon-fpm-journal]
enabled = true
```

If more than one Tachyon runs on the host, give each its own `syslog_ident`
in `[logs]` and match on it, using the commented `journalmatch` line in the
filter. Entries are otherwise indistinguishable. The default identifier is
`tachyon`.

## Default log (not recommended)

Modify `/PATH-TO-TACHYON-DATA/_data_/_default_/configs/application.ini`:

```
[logs]
auth_logging = On
auth_logging_filename = "fail2ban/auth-fail.log"
auth_logging_format = "[{date:Y-m-d H:i:s T}] Auth failed: ip={request:ip} user={imap:login} host={imap:host} port={imap:port}"
```

A fixed filename on purpose. The shipped default is
`fail2ban/auth-{date:Y-m-d}.txt`, which starts a new file every day and would
need the jail's `logpath` to be a glob.

Change the path in `/jail.d/tachyon-log.conf` to match your installation.

Copy the following to `/etc/fail2ban/`:

- `/filter.d/tachyon-log.conf`
- `/jail.d/tachyon-log.conf`

Add to `/etc/fail2ban/jail.local`:

```
[tachyon-log]
enabled = true
```
