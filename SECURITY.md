# Security Policy

## Supported Versions

Only the latest release receives security updates.

| Version  | Supported |
| -------- | --------- |
| 4.x      | ✔         |
| < 4.0.0  | ❌         |

## Reporting a Vulnerability

Use [GitHub's private vulnerability reporting](https://github.com/kimusan/Tachyon/security/advisories/new).
It is enabled on this repository, so the report stays between you and the
maintainers until there is a fix, and no key exchange is needed to get started.

Please do not open a public issue for a security problem.

A useful report has clear steps to reproduce, the Tachyon version, and what an
attacker gains. If you have a view on severity, say so, but do not let working
that out delay telling us.

Reports will be analyzed and fixed as fast as possible. Disclosure is planned together with the reporter. Credits are granted and can be included in all public communication if desired.

## Deployment

- Serve Tachyon over HTTPS.
- Give it a hostname of its own (for example `mail.example.com`), served at
  `/`, with nothing else on that origin. Every application on the same host
  shares Tachyon's origin, whatever its path: one with a cross-site scripting
  hole can run script in Tachyon's pages and set cookies for it. A cookie
  path is not a security boundary.
- Over HTTPS, Tachyon names its cookies with the `__Host-` prefix. Browsers
  accept such cookies only from this exact host over HTTPS, so a sibling
  subdomain, or a man in the middle on a plain HTTP connection, cannot plant
  a session (session fixation, login CSRF).
- Behind a reverse proxy that terminates TLS, have the proxy send
  `X-Forwarded-Proto: https` (or `X-Forwarded-Ssl: on`). Without it Tachyon
  sees a plain connection, and its cookies get neither the `Secure` flag nor
  the `__Host-` prefix.
- `labs.cookie_default_path` is only for running several instances under one
  hostname, which would otherwise overwrite each other's cookies. Such
  instances keep plain cookie names, without the `__Host-` protection; prefer
  one hostname per instance.
- The Nextcloud integration runs inside Nextcloud's origin by design, so this
  advice applies to the Nextcloud installation.

## Upgrade path

Existing SnappyMail installations (2.x) can upgrade directly to Tachyon 4.x. The on-disk data format is backward compatible. PHP 8.2+ is required.
