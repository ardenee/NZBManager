# NZB Manager

A Linux-first PHP web application for inspecting and manipulating NZB metadata,
modernized from the recovered NZB 0.1 Synology package published in 2013.

**Status: early alpha.** The modern core is tested with PHP 8.5 on DEV and
running on DSM 7 / PHP 8.2.35 at `192.168.0.67`. Functional smoke tests
have passed on that NAS; production security and cross-device compatibility
are not yet certified.

## Working in the modern application

- Multi-file NZB uploads to a private local library; byte-preserving original downloads
- Inspect subjects, file names, metadata, Usenet groups, segments and sizes
- Read historical NZB external-DTD declarations without remote DTD requests
- Search multiple configured HTTPS Newznab indexers using provider profiles
  (including NZBPlanet, NZBGeek, DrunkenSlug, NZBFinder, and others) and
  import selected NZBs; fixture-tested, live-provider testing pending
- Search Binsearch.info independently of Newznab and create/download or import
  an NZB from selected results; search and generation tested against live site
- Search, globally sort and paginate documents; sort the other result tables
- Configure Binsearch page size, library size and per-page file/segment counts
- Select configured NAS folders through a directory-only Settings picker that
  reports PHP service-account read/write permissions and rejects inaccessible paths.
  The picker uses same-origin requests permitted by the application's CSP; CSS
  and JavaScript asset URLs use content hashes to prevent stale cached scripts.
- Configure Newznab providers and API keys from Settings (stored in private data)
- Import NZBs from a configured DSM folder without altering source files
- Suppress duplicate NZBs across all import methods using SHA-256 content hashes
- Push up to 25 NZBs to a monitored download folder or to a restricted HTTPS upload endpoint
- Inspect suspect segment numbering; add, edit or delete NZB references into a new document;
  segment Article bytes are displayed as exact integer values
- Browse a configured downloads workspace with the File Organizer module;
  preview and confirm safe same-filesystem file renames/moves, create tracked
  folders, rename those folders (preserving contents) and delete them when empty.
  Pre-existing folders cannot be renamed or deleted through this module.
- Inspect small ZIP archives and extract their validated members into new
  directories via background PHP workers with progress and cancellation
- Verify PAR2 2.0 packet integrity and protected-file/slice checksums natively
  in PHP, with an independent worker, live job monitor, progress and cancellation
- Repair PAR2-protected files using PHP-only GF(2^16) Reed–Solomon arithmetic,
  writing fully checksum-verified `.repaired` outputs without overwriting originals.
  File and recovery data are streamed in 64 KiB windows with no 64 MiB data
  ceiling or 1 MiB slice restriction. PAR2 2.0 input slices are limited by
  the format to 32,768; this PHP implementation currently solves up to 32
  missing/damaged slices per job. Large jobs can be CPU- and disk-intensive.
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

- PHP 8.2+ with cURL, DOM, libxml, JSON, mbstring, session, hash, ZIP/zlib,
  and TLS CA trust. The DSM installer declares cURL, DOM, fileinfo, mbstring,
  OpenSSL, SimpleXML, XML, XMLReader, XMLWriter, ZIP and zlib in its PHP profile.
- Independent background work requires PHP CLI plus `proc_open` in Web Station
  and `pcntl_fork` in Linux PHP CLI (these are not PAR2 executable dependencies).
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

The Binsearch module is available without an API key. It searches multiple
pages of the current Binsearch.info HTML interface, then submits selected collection IDs to the
fixed provider /nzb URL to generate a valid NZB. Generated NZBs can be saved
in the library or downloaded. A live search and NZB generation succeeded on
DEV on 19 November 2026. The module does not fetch Usenet article payloads.
The HTML interface can change; see docs/BINSEARCH.md for limits and testing.

## Layout

| Path | Purpose |
| --- | --- |
| public/ | Only web-served files |
| src/ | Parser, storage, security, module registry and request handlers |
| bin/ | Independent PHP CLI background worker for native PAR2 verify/repair and ZIP extraction; never public |
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

Install `dist/NZB-Manager-DSM7-0.4.3-0001-noauth-test.spk` through Package
Center > Manual Install. Web Station and PHP 8.2 must be available. The
application registers an alias portal at `/nzbmanager/` through Web Station.
There is **no installation wizard, application password or login prompt**.
The package retains its library, saved indexer profiles, settings and session
data under the package-private `var` directory. It does not contain the original 2013 PHP application.

**Security:** Synology Web Station does not require DSM login for arbitrary web
sites. Anyone who can reach this portal can use NZB Manager. Keep it on a
trusted local network using NAS firewall/reverse-proxy access controls; do not
publish this password-free test instance to the internet.

An earlier 0.4.3 test SPK was installed and its PHP profile tested on the NAS,
but **the current source is newer than that SPK**. The live DSM website now
includes the PHP-only Reed–Solomon repair engine, independent job monitors,
Download Station task controls and the expanded PHP extension-status screen.
These changes have not been packaged again, by request. Build a new SPK only
when installer settings need testing or a release is requested. Real-world
large PAR2 recovery and portability to other NAS models are not certified.
The application remains early alpha; see docs/DSM7-PACKAGING.md.

## Functions not yet ported

The historical app included torrent uploads, automatic folder monitoring,
PAR2 repair, archive unpacking and moving/merging downloaded files.
The modern application now supports bounded ZIP background extraction, safe
file/folder organization, native PHP PAR2 verification and bounded native PHP
Reed–Solomon repair. Full-size PAR2 datasets, RAR/7z/TAR extraction, batch
payload operations, torrent imports and continuous monitoring remain open.
Download Station supports native task submission, listing, progress,
pause/resume and removal through a mocked, tested API adapter. Its package
is detected on DSM, but authenticated operations require a configured
Download Station account and have not been live-certified. Third-party
Newznab integration also requires live-provider verification.

Old MySQL databases are not automatically imported. See
docs/FEATURE-MATRIX.md for the detailed parity matrix, docs/MODULES.md
for background-worker and recovery safety limits, docs/DOWNLOAD-STATION.md
for native DSM task API setup, and AUDIT.md for legacy security findings.

## Historical source and licensing

The recovered original is retained in current/ to preserve functionality
during the rewrite. Git archival recovery removed a legacy SQL password
from the historical code; rotate it if it was ever reused. The original
SPK is intentionally ignored by Git.

Historical posts:
- https://www.arden.ie/2013/01/04/nzb/
- https://www.arden.ie/2013/03/28/nzb-released/

No blanket licence is asserted for legacy or third-party source.
