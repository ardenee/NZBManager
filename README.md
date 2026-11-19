# NZB Manager

A Linux-first PHP web application for inspecting and manipulating NZB metadata,
modernized from the recovered NZB 0.1 Synology package published in 2013.

**Status: early alpha.** The modern core is tested with PHP 8.5.12 on DEV;
production security, Linux deployment, and DSM installation are not certified.

## Working in the modern application

- Multi-file NZB uploads to a private local library; original NZB downloads
- Inspect subjects, file names, metadata, Usenet groups, segments and sizes
- Search/filter document lists and file entries
- Select individual entries and export a filtered, valid NZB
- Merge two or more NZBs and download the result
- Delete previously imported NZBs
- Responsive modern HTML/CSS/JS interface, without external front-end dependencies
- Required HTTP Basic authentication, CSRF protection and constrained XML parsing

These operations edit NZB metadata; no Usenet article content is downloaded.
Merging currently retains the first NZB's header metadata and all selected file
nodes. Metadata from additional NZBs is not yet merged.

## Requirements

- PHP 8.2+ with DOM, libxml, JSON and session extensions (PHP 8.5 target)
- Linux web server with PHP-FPM (nginx or Apache) and TLS
- Writable private storage directory outside the public web root
- No MySQL or Composer dependency for the modern core

## Quick start on DEV

Open PowerShell in the local checkout and configure a temporary development password:

    $env:NZB_ADMIN_PASSWORD = 'set-a-local-development-password'
    D:\php8.5\php.exe -S 127.0.0.1:8085 -t public

Open http://127.0.0.1:8085/ in a browser. The username is not significant;
the configured password is required. Use the built-in PHP server on localhost
only, never as a public deployment.

To run regression tests:

    D:\php8.5\php.exe tests/run.php
    powershell -File tests/smoke.ps1

## Linux installation

1. Place the application under /srv/nzb, with public/ and src/ as siblings.
2. Point the nginx/Apache document root ONLY at /srv/nzb/public.
3. Create /var/lib/nzb or another private writable directory. Set
   NZB_STORAGE_DIR to that path in the PHP-FPM environment.
4. Configure NZB_ADMIN_PASSWORD_HASH (recommended: the result of PHP
   password_hash()) or NZB_ADMIN_PASSWORD in PHP-FPM environment variables.
   Without either variable, the application refuses all requests.
5. Use HTTPS. Basic authentication must not be exposed over plaintext HTTP.
6. Adjust php.ini upload_max_filesize (32M) and post_max_size (large enough for
   multiple files). Each individual NZB is limited to 32 MiB.
7. Configure PHP-FPM to receive the authentication header and environment
   variables. See deploy/nginx.conf.example for an nginx starting point.

Never store passwords or private data in Git or under public/. The default
storage location is var/nzb relative to the repository and should be replaced
with an explicitly private production path.

## Layout

| Path | Purpose |
| --- | --- |
| public/ | Only web-served files |
| src/ | Modern NZB parser, storage, request security |
| tests/ | Standalone PHP regression tests |
| deploy/ | Example nginx configuration |
| packaging/synology/ | Optional Synology DSM 7 package staging |
| current/source/ | Historical PHP, **not safe to serve** |
| current/spk/ | Historical Synology package files |
| ARCHIVE-MANIFEST.json | Original package recovery manifest |
| AUDIT.md | Detailed legacy findings and migration roadmap |

## Optional Synology staging package

From Git Bash or Linux:

    bash packaging/synology/build.sh

This generates dist/NZB-0.2.0-0001-noarch.spk containing the modern core.
This is NOT a fully configured or DSM-certified package. It still needs
DSM 7 Web Station service/portal registration, supported PHP runtime, PHP-FPM
permissions and a secure method to set credentials. See
packaging/synology/README.md.

## Functions not yet ported

The historical application also had NZBPlanet search and remote NZB fetching,
Synology Download Station submission, PAR2 repair, archive unpacking and
filesystem operations to rename/merge/move downloads. Those features are
**not currently available** in the modern application. Old MySQL databases
are not automatically imported. See AUDIT.md for priorities and risks.

## Historical source and licensing

The recovered original is retained in current/ to preserve functionality
during the rewrite. Git archival recovery removed a legacy SQL password
from the historical code; rotate it if it was ever reused. The original
SPK is intentionally ignored by Git.

Historical posts:
- https://www.arden.ie/2013/01/04/nzb/
- https://www.arden.ie/2013/03/28/nzb-released/

No blanket licence is asserted for legacy or third-party source.
