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

## Upgrade path

Existing SnappyMail installations (2.x) can upgrade directly to Tachyon 4.x. The on-disk data format is backward compatible. PHP 8.2+ is required.
