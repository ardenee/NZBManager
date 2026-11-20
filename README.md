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
  preview and confirm safe same-filesystem file renames/moves, delete individual
  files with one click (rechecking identity before unlinking), create tracked folders, rename
  those folders (preserving contents) and delete them when empty.
  Pre-existing folders cannot be renamed, but **any empty ordinary folder** in
  the workspace can be deleted, including extraction-created folders. Hidden
  and Synology system folders are protected, and no recursive deletion occurs.
- Inspect and extract normal ZIP, raw-split `.zip.001`/`.zip.002`, TAR, TGZ and SPK files,
  including password-protected ZIPs, via independent PHP CLI workers. The
  browser prompts only when ZIP encryption is detected. Encrypted split ZIPs
  may first be detected by the background worker, which then offers Retry with
  password. Secrets use one-use private sidecars, never the job JSON.
  Extraction uses 64 GiB compressed / 128 GiB expanded safeguards and 10,000
  member limit. The archive page automatically refreshes after successful
  background extraction, showing the new folder. Output folders use the archive's base name (`release.zip` ->
  `release/`, then `release-1/` on collision), with no `-extracted` suffix.
  Finished jobs can be rerun/removed without deleting outputs. True multi-disk
  `.z01` ZIP and RAR/7z remain unsupported; native TAR/TGZ/TAR.GZ/SPK works.
- Verify complete PAR2 2.0 sets natively in PHP: every matching recovery volume's packet MD5, protected-file MD5, first-16 KiB MD5, and slice MD5/CRC32 checksums. Results detail the protected files, checked data slices, recovery volumes and recovery slices separately. A protected ZIP counts as one file even if it contains many entries. The independent PHP worker reports progress and supports cancellation.
  An integrity check that finds any damaged/missing protected file finishes at 100% but is marked **Failed — Repair required**; a clean check displays **Verified — No repair needed**. Old verification history without complete results requests a rerun. PAR2 and archive job actions and Refresh links retain the currently browsed workspace folder.
- Repair PAR2-protected files using PHP-only GF(2^16) Reed–Solomon arithmetic.
  Verified data takes the original protected filename so downstream extraction
  works immediately; an existing damaged original is retained as
  `filename.ext.damaged`. A pre-existing `.damaged` file is never overwritten.
  Rerunning after a successful repair verifies the restored filename; no duplicate
  is published. Finished jobs may be removed from the monitor without deleting files.
  File and recovery data use bounded streaming windows with no fixed total
  data, slice-size or 128-erasure application cap. RAM use is estimated against
  90% of currently available memory; larger GF16 matrices spill into private
  on-disk scratch files. Preflight disk checks cover every complete replacement
  file (damaged originals are preserved) plus matrix scratch. A 130-erasure
  synthetic disk-backed test passed. PAR2 2.0 permits up to 32,768 input
  slices, and the number of independent recovery slices always limits repair
  (39 recovery slices can reconstruct at most 39 damaged source slices). Failed Verify
  jobs with confirmed damaged/missing files now offer a Repair button that
  starts a separate PHP job; the worker rechecks current files before modifying
  them, so a stale Verify failure cannot unnecessarily rename a healthy file.
  Large jobs can be CPU- and disk-intensive.
- Search/filter document lists and file entries
- Select individual entries and export a filtered, valid NZB
- Merge two or more NZBs and download the result
- Rename and delete imported NZBs without altering their XML content
- Compact file listings in PAR2 Repair, Archive Extraction, File Organizer and Segment Viewer.
- Responsive modern HTML/CSS/JS interface, without external front-end dependencies.
  Push To and Download Station are grouped under Modules; the sidebar logo and
  browser favicon reuse the original official DSM SPK application icon.
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

## Optional DSM SPK — from the generic web-hosting application

**The generic web-hosting application remains primary.** The DSM 7.2+ SPK
builder packages the same modern `public/`, `src/`, `views/` and `bin/`
directories, adding Synology-only metadata, shell lifecycle scripts, Web
Station configuration and icon artwork from `packaging/dsm7/`.

No separate DSM PHP codebase exists. A clean checkout can build the SPK
without `current/` or the original 2013 PHP application.

From the repository root (Python 3.11+, no third-party packager):

```powershell
python packaging/dsm7/build.py
# To create an upgrade with a distinct DSM version:
python packaging/dsm7/build.py --version 0.4.5-0001
# Verify the built SPK against the generic web source:
python packaging/dsm7/build.py --verify dist/NZB-Manager-DSM7-0.4.4-0001-noauth-test.spk
# Test build reproducibility and a modern clean checkout:
python tests/spk-build.py
```

`packaging/dsm7/build-spk.ps1` is an optional Windows wrapper.
The default version in INFO is `0.4.4-0001`. The SPK goes to Git-ignored
`dist/` and is verified before release. Builds from identical source files
produce identical output hashes. No files in the generic hosting app are
changed by the builder.

**Building is not installing.** The generated SPK has not been installed on
the NAS or upgrade-certified. The last installed DSM package may be older
than the live web source. Manual installation is through DSM Package Center,
with Web Station and PHP 8.2.

The test package has **no application login or password wizard**. DSM
login does not protect public Web Station portals: keep the site on a
trusted network with suitable firewall/private reverse-proxy restrictions.

See `docs/DSM7-PACKAGING.md` for complete details.

## Functions still requiring work

The 2026 implementation includes native PHP PAR2 verification/repair with adaptive RAM/disk matrices, ZIP/AES and raw-split ZIP extraction, streaming TAR/TGZ/SPK extraction, file/folder operations, and independent progress-monitored jobs. Batch file moves and recursive folder-content merges can skip collisions, retain both names, or explicitly replace a reviewed target with rollback support. A configurable extracted-files destination can replace an output directory only with explicit user confirmation; the previous folder is saved as a .previous backup.

Automatic NZB inbox scanning/submission to Download Station and completed-download PAR2 scheduling are now available in Settings with 5-minute to daily intervals, **off by default**. Stable input checks and a conservative Download Station active-task gate prevent premature repair; finer-grained per-task file mapping remains uncertified. The detached PHP worker starts on enabling automation, and the next SPK carries service lifecycle startup so it can resume on reboot. The installed SPK is older than the live DSM web source.

Still outstanding: real-world multi-GiB PAR2 certification beyond synthetic 130-slice tests, complete multi-disk ZIP and RAR/7z decoders in PHP, untracked-folder renaming policy, authenticated live Download Station transfers, historical MySQL imports, and complete real-world automated-download certification. Torrent uploads are deliberately out of scope for this NZB-focused application.

## Historical source and licensing

The recovered original is retained in current/ to preserve functionality
during the rewrite. Git archival recovery removed a legacy SQL password
from the historical code; rotate it if it was ever reused. The original
SPK is intentionally ignored by Git.

Historical posts:
- https://www.arden.ie/2013/01/04/nzb/
- https://www.arden.ie/2013/03/28/nzb-released/

No blanket licence is asserted for legacy or third-party source.
