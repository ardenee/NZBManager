# NZB Manager

A Linux-first PHP web application for inspecting and manipulating NZB metadata,
modernized from the recovered NZB 0.1 Synology package published in 2013.

**Status: early alpha.** The modern core is tested with PHP 8.5.12 on DEV;
production security, Linux deployment, and DSM installation are not certified.

## Working in the modern application

- Multi-file NZB uploads to a private local library; byte-preserving original downloads
- Inspect subjects, file names, metadata, Usenet groups, segments and sizes
- Read historical NZB external-DTD declarations without remote DTD requests
- Search multiple configured HTTPS Newznab indexers using provider profiles
  (including NZBPlanet, NZBGeek, DrunkenSlug, NZBFinder, and others) and
  import selected NZBs; fixture-tested, live-provider testing pending
- Search Binsearch.info independently of Newznab and create/download or import
  an NZB from selected results; search and generation tested against live site
- Search/filter document lists and file entries
- Select individual entries and export a filtered, valid NZB
- Merge two or more NZBs and download the result
- Rename and delete imported NZBs without altering their XML content
- Responsive modern HTML/CSS/JS interface, without external front-end dependencies
- Linux HTTP Basic authentication (optional on other deployments), CSRF protection and constrained XML parsing
- DSM 7.2+ test package runs **without an application password**; restrict its web portal to a trusted network

These operations edit NZB metadata; no Usenet article content is downloaded.
Merging currently retains the first NZB's header metadata and all selected file
nodes. Metadata from additional NZBs is not yet merged.

## Requirements

- PHP 8.2+ with cURL, DOM, libxml, JSON, mbstring and session extensions
  (PHP 8.5 target), and a trusted TLS certificate-authority bundle
- Linux web server with PHP-FPM (nginx or Apache) and TLS
- Writable private storage directory outside the public web root
- No MySQL or Composer dependency for the modern core

## Quick start on DEV

Open PowerShell in the local checkout and configure a temporary development password:

    $env:NZB_ADMIN_PASSWORD = 'set-a-local-development-password'
    D:\php8.5\php.exe -S 127.0.0.1:8085 -t public

Open http://127.0.0.1:8085/ in a browser. The username is **admin**
(or NZB_ADMIN_USERNAME if configured); the configured password is required. Use the built-in PHP server on localhost
only, never as a public deployment.

To run regression tests:

    D:\php8.5\php.exe tests/run.php
    powershell -File tests/smoke.ps1
    powershell -File tests/indexer-http.ps1

Optional live Binsearch test (makes network requests, requires TLS CA trust):

    D:\php8.5\php.exe -d "curl.cainfo=C:\Program Files\Git\usr\ssl\cert.pem" tests/binsearch-live.php

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

## Newznab provider profiles

The Indexer Search module supports preset provider profiles and a generic
Newznab endpoint. Existing single-indexer deployments still use these
server-side PHP-FPM environment variables:

    NZB_INDEXER_URL=https://your-indexer.example/api
    NZB_INDEXER_API_KEY=your-secret-api-key

Alternatively, set NZB_INDEXER_PROVIDER to a preset ID, for example nzbgeek,
with NZB_INDEXER_API_KEY, and omit NZB_INDEXER_URL. Multiple indexers can be
configured with NZB_INDEXERS_JSON (see docs/INDEXERS.md), each using a distinct
ID, provider and server-side API key.

Use the complete HTTPS API endpoint, not the website homepage. NZBPlanet is
Newznab-compatible, but a real account/API key is needed to test its live API.
The key is never requested by or rendered in the browser. Configure it in the
PHP runtime environment (for example a protected PHP-FPM pool or service
configuration); avoid storing it in source files or PHP's public web root.

The indexer uses standard Newznab t=search and t=get requests. It never follows
arbitrary download URLs supplied by RSS results. Some providers with unusual
GUID formats or download redirects may require a provider-specific adapter.
The code has fixture and HTTP configuration tests, **not** a successful live
search/import certification. See docs/INDEXERS.md.

## Binsearch

The Binsearch module is available without an API key. It searches the current
Binsearch.info HTML interface, then submits selected collection IDs to the
fixed provider /nzb URL to generate a valid NZB. Generated NZBs can be saved
in the library or downloaded. A live search and NZB generation succeeded on
DEV on 19 November 2026. The module does not fetch Usenet article payloads.
The HTML interface can change; see docs/BINSEARCH.md for limits and testing.

## Layout

| Path | Purpose |
| --- | --- |
| public/ | Only web-served files |
| src/ | Parser, storage, security, module registry and request handlers |
| views/ | Shared layout and modular feature templates |
| tests/ | PHP regression tests and HTTP smoke tests |
| deploy/ | Linux web-server example |
| docs/ | Architecture, parity matrix, DSM 7 test-package instructions |
| current/source/ | Historical PHP, **not safe to serve** |
| current/spk/ | Historical Synology package files |
| ARCHIVE-MANIFEST.json | Original package recovery manifest |
| AUDIT.md | Detailed legacy findings and migration roadmap |

## Synology DSM 7.2+ test package

A password-free **test SPK** can now be built from this same application source:

    python packaging/dsm7/build.py

Install `dist/NZB-Manager-DSM7-0.2.1-0002-noauth-test.spk` through Package
Center > Manual Install. Web Station and PHP 8.2 must be available. The
application registers an alias portal at `/nzbmanager/` through Web Station.
There is **no installation wizard, application password or login prompt**.
The package retains its library and session data under the package-private
`var` directory. It does not contain the original 2013 PHP application.

**Security:** Synology Web Station does not require DSM login for arbitrary web
sites. Anyone who can reach this portal can use NZB Manager. Keep it on a
trusted local network using NAS firewall/reverse-proxy access controls; do not
publish this password-free test instance to the internet.

The SPK has passed structural checks but **has not been installed or upgraded
on a real DSM NAS**. It is an early alpha with important legacy workflows still
missing, not a production-certified release. See docs/DSM7-PACKAGING.md.

## Functions not yet ported

The historical application also had torrent uploads, filesystem scans,
PAR2 repair, archive unpacking and filesystem operations to rename/merge/move
downloads. Those features are **not currently available** in the modern
application. Optional Download Station submission has a mocked API-tested
adapter, but still requires connection settings and actual DSM validation. Indexer search and
import are implemented but require live-provider verification. Old MySQL databases
are not automatically imported. See docs/FEATURE-MATRIX.md for the
complete parity checklist, docs/ARCHITECTURE.md for extension points,
docs/DOWNLOAD-STATION.md for the optional DSM service activation policy,
and AUDIT.md for the security risks in the archived PHP.

## Historical source and licensing

The recovered original is retained in current/ to preserve functionality
during the rewrite. Git archival recovery removed a legacy SQL password
from the historical code; rotate it if it was ever reused. The original
SPK is intentionally ignored by Git.

Historical posts:
- https://www.arden.ie/2013/01/04/nzb/
- https://www.arden.ie/2013/03/28/nzb-released/

No blanket licence is asserted for legacy or third-party source.
