# NZB Manager

A Linux-first PHP web application for inspecting and manipulating NZB metadata,
modernized from the recovered NZB 0.1 Synology package published in 2013.

**Status: early alpha.** The modern core is tested with PHP 8.5.12 on DEV;
production security, Linux deployment, and DSM installation are not certified.

## Working in the modern application

- Multi-file NZB uploads to a private local library; byte-preserving original downloads
- Inspect subjects, file names, metadata, Usenet groups, segments and sizes
- Read historical NZB external-DTD declarations without remote DTD requests
- Search a configured HTTPS Newznab indexer (including NZBPlanet) and import
  selected NZBs; implemented and fixture-tested, live-provider testing pending
- Search/filter document lists and file entries
- Select individual entries and export a filtered, valid NZB
- Merge two or more NZBs and download the result
- Rename and delete imported NZBs without altering their XML content
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
    powershell -File tests/indexer-http.ps1

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

## Optional Newznab indexer

The indexer module is available at **Indexer Search**. It needs both of these
server-side PHP-FPM environment variables:

    NZB_INDEXER_URL=https://your-indexer.example/api
    NZB_INDEXER_API_KEY=your-secret-api-key

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

## Layout

| Path | Purpose |
| --- | --- |
| public/ | Only web-served files |
| src/ | Parser, storage, security, module registry and request handlers |
| views/ | Shared layout and modular feature templates |
| tests/ | PHP regression tests and HTTP smoke tests |
| deploy/ | Linux web-server example |
| docs/ | Architecture, parity matrix and future DSM 7 plan |
| current/source/ | Historical PHP, **not safe to serve** |
| current/spk/ | Historical Synology package files |
| ARCHIVE-MANIFEST.json | Original package recovery manifest |
| AUDIT.md | Detailed legacy findings and migration roadmap |

## Future Synology deployment

**Do not build an SPK yet.** The previous provisional package builder has
been removed from active source, along with its generated test package. After
the complete Linux application's feature and security acceptance tests pass,
a release-gated automated workflow will produce a DSM 7 SPK from the same
code. No duplicate Synology fork is planned. See docs/DSM7-PACKAGING.md and
Synology's official developer guide for the requirements.

## Functions not yet ported

The historical application also had torrent uploads, filesystem scans,
Synology Download Station submission, PAR2 repair, archive unpacking and
filesystem operations to rename/merge/move downloads. Those features are
**not currently available** in the modern application. Indexer search and
import are implemented but require live-provider verification. Old MySQL databases
are not automatically imported. See docs/FEATURE-MATRIX.md for the
complete parity checklist, docs/ARCHITECTURE.md for extension points, and
AUDIT.md for the security risks in the archived PHP.

## Historical source and licensing

The recovered original is retained in current/ to preserve functionality
during the rewrite. Git archival recovery removed a legacy SQL password
from the historical code; rotate it if it was ever reused. The original
SPK is intentionally ignored by Git.

Historical posts:
- https://www.arden.ie/2013/01/04/nzb/
- https://www.arden.ie/2013/03/28/nzb-released/

No blanket licence is asserted for legacy or third-party source.
